<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Session;

/**
 * What Nafath hands back when you open a web session.
 *
 * Two things about this response cost this project days, and both are handled
 * here so nobody meets them again.
 *
 * FIRST — the `state` is not in the JSON body. The body carries only
 * `hashedState`. The redeemable credential lives inside the query string of the
 * `url`, and you must parse it out. Miss this and you can never complete a
 * verification yourself: you are left waiting on a callback that may never come.
 *
 * SECOND — `hashedState` is not the state, and not a truncation of it:
 *
 *     hashedState = base64( sha256( state ) )
 *
 * Nafath posts the FULL state on the callback while you stored the digest.
 * Comparing them directly never matches, and the failure is silent — the
 * callback answers 200, nothing advances, and it looks exactly like Nafath
 * never called. Verified against live Nafath: a 344-character state hashes to
 * the 44-character hashedState they return.
 *
 * So: correlate on the digest, keep the state to redeem with, and never confuse
 * the two.
 */
final readonly class AuthorizeUrl
{
    public function __construct(
        /** Send the browser here, untouched. It is signed; any edit invalidates it. */
        public string $url,
        /** The single-use redeemable credential, extracted from the URL for you. */
        public string $state,
        /** base64(sha256(state)) — the correlation key Nafath gives you up front. */
        public string $hashedState,
        /** The UUID you supplied, echoed back. */
        public string $requestId,
        /** The SP name Nafath has registered for you. Useful for validating tokens. */
        public ?string $audience = null,
        /** The portal the person will land on — iam.sa for staging, iam.gov.sa for production. */
        public ?string $portal = null,
    ) {}

    /**
     * Build from Nafath's SessionResponseDto, doing the parsing nobody tells you about.
     */
    public static function fromResponse(array $body, string $requestId): self
    {
        $url = (string) ($body['url'] ?? '');

        if ($url === '') {
            throw new \RuntimeException(
                'Nafath returned a session with no authorize URL. This usually means the '
                . 'service provider profile is not fully linked to these credentials.'
            );
        }

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // parse_str already percent-decodes. The state is base64 and contains
        // +, / and = — a naive hex or alphanumeric pattern silently returns an
        // empty string and every callback then fails to match.
        $state = (string) ($query['state'] ?? '');

        return new self(
            url:         $url,
            state:       $state,
            hashedState: (string) ($body['hashedState'] ?? ''),
            requestId:   (string) ($body['requestId'] ?? $requestId),
            audience:    isset($query['aud']) ? (string) $query['aud'] : null,
            portal:      parse_url($url, PHP_URL_HOST) ?: null,
        );
    }

    /**
     * The digest of a state, as Nafath computes it.
     *
     * Use this to find your stored session when a callback arrives carrying the
     * full state.
     */
    public static function digest(string $state): string
    {
        return base64_encode(hash('sha256', $state, true));
    }

    /** Does a state Nafath just posted us belong to this session? */
    public function matches(string $state): bool
    {
        // Constant-time: this decides which session a caller may redeem.
        return hash_equals($this->hashedState, self::digest($state))
            || hash_equals($this->hashedState, $state);
    }

    /**
     * Was this issued by the live government portal?
     *
     * Production sends people to iam.gov.sa; staging uses iam.sa. A quick way to
     * catch a configuration slip before real identities are involved.
     */
    public function isProductionPortal(): bool
    {
        return str_contains((string) $this->portal, 'iam.gov.sa');
    }

    public function __debugInfo(): array
    {
        return [
            'requestId'   => $this->requestId,
            'portal'      => $this->portal,
            'audience'    => $this->audience,
            'state'       => '***redacted***',
            'hashedState' => $this->hashedState,
        ];
    }
}
