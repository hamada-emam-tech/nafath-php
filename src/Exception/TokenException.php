<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Exception;

/**
 * The signed identity token could not be trusted.
 *
 * Never fall back to reading an unverified token. Everything downstream — a
 * national ID, a verified name — is only as trustworthy as this signature.
 */
final class TokenException extends NafathException
{
    public static function signatureInvalid(): self
    {
        return new self('The identity token signature did not verify against Nafath\'s JWK set.');
    }

    public static function expired(): self
    {
        return new self('The identity token has expired.');
    }

    public static function unknownKeyId(string $kid): self
    {
        return new self(
            "The token was signed with key '{$kid}', which is not in Nafath's published JWK set. "
            . 'If Nafath has rotated keys, refresh the cached set.'
        );
    }

    public static function audienceMismatch(string $expected, ?string $actual): self
    {
        return new self(
            "Token audience mismatch: expected '{$expected}', got '" . ($actual ?? 'none') . "'. "
            . 'The audience is the service provider name registered on Rabet — it identifies your '
            . 'organisation, and a mismatch means this token was minted for someone else.'
        );
    }

    public static function missingIdentifier(): self
    {
        return new self('The verified token carried no national ID, Iqama, Visa or Border number.');
    }
}
