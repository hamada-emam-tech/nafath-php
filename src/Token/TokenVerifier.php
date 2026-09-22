<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Token;

use HamadaEmam\Nafath\Exception\TokenException;
use HamadaEmam\Nafath\Http\NafathClient;
use HamadaEmam\Nafath\Identity\Claims;
use HamadaEmam\Nafath\Identity\UserType;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\SimpleCache\CacheInterface;

/**
 * Turns Nafath's signed token into claims you can trust — and refuses to
 * produce anything you cannot.
 *
 * There is no "skip verification" option, and that is deliberate. Everything
 * downstream of this class — a national ID, a verified name, the decision to
 * let somebody into an account — is exactly as trustworthy as this signature.
 * A flag to bypass it would be used in a hurry one afternoon and never removed.
 */
final class TokenVerifier
{
    private const CACHE_KEY = 'nafath.jwk.';

    public function __construct(
        private readonly NafathClient $client,
        private readonly ?CacheInterface $cache = null,
        /** Cache the key set this long. Nafath rotates rarely and unannounced. */
        private readonly int $cacheTtl = 86400,
        /**
         * The service provider name registered on Rabet, which appears as `aud`.
         *
         * Note this is a NAME, not a URL to match against your own domain —
         * even though it is usually written to look like one. Set it and every
         * token is checked against it, which is what stops a token minted for
         * another tenant being replayed against you.
         */
        private readonly ?string $expectedAudience = null,
    ) {}

    /**
     * Verify a token and normalise its claims.
     *
     * @throws TokenException when the signature, expiry or audience does not hold
     */
    public function verify(string $token, string $userIp): Claims
    {
        $payload = $this->decode($token, $userIp);

        if ($this->expectedAudience !== null) {
            $aud = isset($payload['aud']) ? (string) $payload['aud'] : null;
            if ($aud !== $this->expectedAudience) {
                throw TokenException::audienceMismatch($this->expectedAudience, $aud);
            }
        }

        return $this->toClaims($payload);
    }

    /** @return array<string,mixed> */
    private function decode(string $token, string $userIp): array
    {
        try {
            $decoded = JWT::decode($token, $this->keys($userIp, refresh: false));
        } catch (\Firebase\JWT\ExpiredException) {
            throw TokenException::expired();
        } catch (\Firebase\JWT\SignatureInvalidException) {
            throw TokenException::signatureInvalid();
        } catch (\UnexpectedValueException $e) {
            // Most often an unknown `kid`, which means Nafath rotated keys.
            // Refresh once and retry before giving up — rotation should be
            // transparent, not an outage.
            if (! str_contains($e->getMessage(), 'kid')) {
                throw TokenException::signatureInvalid();
            }

            try {
                $decoded = JWT::decode($token, $this->keys($userIp, refresh: true));
            } catch (\Throwable) {
                throw TokenException::unknownKeyId($this->keyIdOf($token) ?? 'unknown');
            }
        }

        return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,Key> */
    private function keys(string $userIp, bool $refresh): array
    {
        $cacheKey = self::CACHE_KEY . md5($this->client->credentials()->baseUrl());

        if (! $refresh && $this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && $cached !== []) {
                return JWK::parseKeySet($cached);
            }
        }

        $set = $this->client->jwkSet($userIp);
        $this->cache?->set($cacheKey, $set, $this->cacheTtl);

        return JWK::parseKeySet($set);
    }

    private function keyIdOf(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')) ?: '', true);

        return is_array($header) && isset($header['kid']) ? (string) $header['kid'] : null;
    }

    /**
     * Normalise across the four document types.
     *
     * Nafath returns entirely different field names for a citizen, a resident,
     * a visitor and a border entry — `nin` vs `iqamaNumber` vs `visaNumber`, and
     * different name fields with each. A mapper written against only the
     * national-ID shape returns nulls for everyone else, and you find out when
     * the first resident tries to sign up.
     *
     * @param array<string,mixed> $p
     */
    private function toClaims(array $p): Claims
    {
        $identifier = (string) ($p['nin'] ?? $p['iqamaNumber'] ?? $p['visaNumber'] ?? $p['borderNumber'] ?? '');

        if ($identifier === '') {
            throw TokenException::missingIdentifier();
        }

        $ar = $this->join($p, ['firstName', 'fatherName', 'secondName', 'grandFatherName', 'thirdName', 'familyName', 'lastName']);
        $en = $this->join($p, ['englishFirstName', 'englishSecondName', 'englishThirdName', 'englishLastName'])
            ?: $this->join($p, ['translatedFirstName', 'translatedSecondName', 'translatedThirdName', 'translatedLastName']);

        return new Claims(
            identifier:     $identifier,
            userType:       UserType::fromIdentifier($identifier),
            fullNameAr:     $ar,
            fullNameEn:     $en,
            nationality:    isset($p['nationality']) ? (string) $p['nationality'] : (isset($p['nationalityCode']) ? (string) $p['nationalityCode'] : null),
            dateOfBirth:    isset($p['dateOfBirthG']) ? (string) $p['dateOfBirthG'] : (isset($p['birthgDate']) ? (string) $p['birthgDate'] : null),
            gender:         isset($p['gender']) ? (string) $p['gender'] : null,
            documentExpiry: isset($p['idExpiryDateG']) ? (string) $p['idExpiryDateG'] : (isset($p['iqamaExpiryDateG']) ? (string) $p['iqamaExpiryDateG'] : (isset($p['visaExpiryDate']) ? (string) $p['visaExpiryDate'] : null)),
            transactionId:  isset($p['transId']) ? (string) $p['transId'] : null,
            audience:       isset($p['aud']) ? (string) $p['aud'] : null,
            isMinor:        (bool) ($p['isMinor'] ?? false),
            raw:            $p,
        );
    }

    /** @param array<string,mixed> $p @param list<string> $keys */
    private function join(array $p, array $keys): ?string
    {
        $parts = [];
        foreach ($keys as $k) {
            $v = isset($p[$k]) ? trim((string) $p[$k]) : '';
            if ($v !== '' && ! in_array($v, $parts, true)) {
                $parts[] = $v;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
