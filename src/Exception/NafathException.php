<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Exception;

/**
 * Base for everything this library throws.
 *
 * Note what is NOT an exception: a user who has not yet approved in the Nafath
 * app. Nafath answers that with HTTP 401 and code 401-033-024, which reads like
 * a failure and is not one — it is the normal state for most of a transaction's
 * life. Treating it as an error is what makes an integration look broken while
 * it is working, so it is a RESULT type (Pending), never a throw.
 */
abstract class NafathException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $nafathCode = null,
        public readonly ?int $nafathReference = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Nafath's own trace number for this request.
     *
     * Quote it when raising a ticket — their support can find the exact call,
     * which turns "it does not work" into a solvable report.
     */
    public function reference(): ?int
    {
        return $this->nafathReference;
    }
}
