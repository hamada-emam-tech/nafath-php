<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Identity;

/**
 * The outcome of asking Nafath whether a verification has finished.
 *
 * This is a RESULT type on purpose. Nafath signals "the user has not approved
 * yet" with HTTP 401 and code 401-033-024 — which looks exactly like a failure
 * and is not one. It is the normal state for most of a transaction's life, and
 * an integration that throws on it reports itself broken while working
 * perfectly. That mistake cost this project weeks, so it is impossible here:
 * waiting is a state you must handle, not an exception you might catch.
 */
final readonly class VerificationResult
{
    private function __construct(
        public VerificationStatus $status,
        public ?Claims $claims = null,
        public ?string $transactionId = null,
    ) {}

    /** Verified. `claims` carries the identity. */
    public static function verified(Claims $claims, ?string $transactionId = null): self
    {
        return new self(VerificationStatus::Verified, $claims, $transactionId);
    }

    /**
     * The person approved, but the signed identity has not arrived yet.
     *
     * App-push flow only. Wait for the token on your callback and pass it to
     * verifyCallbackToken() — never treat this alone as proof of identity.
     */
    public static function approved(?string $transactionId = null): self
    {
        return new self(VerificationStatus::Approved, null, $transactionId);
    }

    /** Still waiting on the person. Keep polling — this is NOT a failure. */
    public static function pending(?string $transactionId = null): self
    {
        return new self(VerificationStatus::Pending, null, $transactionId);
    }

    /** The person declined in their Nafath app. Terminal. */
    public static function rejected(?string $transactionId = null): self
    {
        return new self(VerificationStatus::Rejected, null, $transactionId);
    }

    /** The window closed without a decision. Terminal — start a new transaction. */
    public static function expired(?string $transactionId = null): self
    {
        return new self(VerificationStatus::Expired, null, $transactionId);
    }

    public function isVerified(): bool { return $this->status === VerificationStatus::Verified; }
    public function isPending(): bool  { return $this->status === VerificationStatus::Pending; }
    public function isApproved(): bool { return $this->status === VerificationStatus::Approved; }

    /** Nothing further will change — stop polling and tell the person. */
    public function isTerminal(): bool
    {
        return $this->status !== VerificationStatus::Pending
            && $this->status !== VerificationStatus::Approved;
    }

    /** @throws \LogicException when called on a result that is not verified */
    public function claims(): Claims
    {
        return $this->claims ?? throw new \LogicException(
            "No claims available: the verification is {$this->status->value}, not verified. "
            . 'Check isVerified() first.'
        );
    }
}
