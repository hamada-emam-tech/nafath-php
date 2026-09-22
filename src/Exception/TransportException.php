<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Exception;

/**
 * Nafath could not be reached, or answered in a way we cannot act on.
 *
 * Distinct from a refusal: this is retryable, and the caller should surface
 * "try again shortly" rather than "verification failed".
 */
final class TransportException extends NafathException
{
    public static function unreachable(string $endpoint, \Throwable $previous): self
    {
        return new self("Could not reach Nafath at {$endpoint}: {$previous->getMessage()}", null, null, $previous);
    }

    public static function unexpectedStatus(string $endpoint, int $status, ?string $code, ?int $reference): self
    {
        return new self(
            "Nafath answered {$status} for {$endpoint}" . ($code !== null ? " (code {$code})" : ''),
            $code,
            $reference,
        );
    }
}
