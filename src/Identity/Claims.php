<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Identity;

/**
 * A verified identity, normalised across the four document types.
 *
 * Nafath returns different field names depending on how the person
 * authenticated — `nin` for a citizen, `iqamaNumber` for a resident,
 * `visaNumber` for a visitor — and different name fields with them. Consumers
 * should never have to branch on that, so this normalises once and keeps the
 * untouched payload in `raw` for anything unusual.
 */
final readonly class Claims
{
    public function __construct(
        /** The national ID, Iqama, Visa or Border number. Regulated data — treat accordingly. */
        public string $identifier,
        public UserType $userType,
        public ?string $fullNameAr = null,
        public ?string $fullNameEn = null,
        public ?string $nationality = null,
        public ?string $dateOfBirth = null,
        public ?string $gender = null,
        public ?string $documentExpiry = null,
        public ?string $transactionId = null,
        public ?string $audience = null,
        /** True when Nafath flags the person as a minor — some journeys need a guardian. */
        public bool $isMinor = false,
        /** Everything Nafath sent, untouched. */
        public array $raw = [],
    ) {}

    /**
     * Has the identity document itself expired?
     *
     * Deliberately reported rather than enforced: Nafath verified the person
     * either way, and whether an expired document is acceptable is a business
     * rule, not an authentication one.
     */
    public function documentExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->documentExpiry === null) {
            return false;
        }

        try {
            return new \DateTimeImmutable($this->documentExpiry) < ($now ?? new \DateTimeImmutable());
        } catch (\Exception) {
            return false;
        }
    }

    /** A stable, non-reversible digest for uniqueness checks and audit trails. */
    public function identifierDigest(string $pepper): string
    {
        if ($pepper === '') {
            throw new \InvalidArgumentException(
                'A pepper is required. An unpeppered digest of a 10-digit national ID is '
                . 'brute-forceable in seconds.'
            );
        }

        return hash_hmac('sha256', trim($this->identifier), $pepper);
    }

    /** Keep the identifier out of logs and crash reports. */
    public function __debugInfo(): array
    {
        return [
            'identifier' => '***redacted***',
            'userType'   => $this->userType->value,
            'fullNameEn' => $this->fullNameEn,
        ];
    }
}
