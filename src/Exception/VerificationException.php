<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Exception;

/** The verification itself cannot proceed. Terminal — do not retry the same transaction. */
final class VerificationException extends NafathException
{
    public static function transactionExpired(?int $reference = null): self
    {
        return new self(
            'The Nafath transaction has expired. A transaction lives 200 seconds, of which '
            . 'the user may act during the first 180. Start a new one.',
            '400-034-051',
            $reference,
        );
    }

    public static function transactionAlreadyActive(?int $reference = null): self
    {
        return new self(
            'This identity already has an active Nafath transaction. Only one is permitted at '
            . 'a time — ask the person to finish or dismiss the pending request in their Nafath '
            . 'app before starting another.',
            '400-034-050',
            $reference,
        );
    }

    public static function transactionNotFound(?int $reference = null): self
    {
        return new self(
            'Nafath does not recognise this transaction. It was never created, or it was '
            . 'created against a different environment.',
            '400-034-053',
            $reference,
        );
    }

    public static function rejectedByUser(): self
    {
        return new self('The person rejected the request in their Nafath app.');
    }
}
