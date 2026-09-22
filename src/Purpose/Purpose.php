<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Purpose;

/**
 * Why you are asking somebody to verify.
 *
 * Verification is rarely only about signing up. The same person may be asked to
 * prove who they are when they log in from a new device, before a payout, when
 * changing a phone number, or before signing a contract — and your audit trail
 * needs to say which, long after the fact.
 *
 * This is an open concept on purpose: the built-in cases cover the common
 * journeys, and `Purpose::of('withdraw_funds')` covers yours. The library never
 * branches on it; it carries it through to the audit record so you can answer
 * "why did we ask this person for their national ID in March".
 */
final readonly class Purpose
{
    private function __construct(
        public string $value,
        /** The Nafath service key for the app-push flow, which decides biometrics and timeout. */
        public string $serviceKey,
    ) {}

    public static function registration(): self { return new self('registration', 'Login'); }
    public static function login(): self        { return new self('login', 'Login'); }

    /** Higher assurance: requires biometrics, 180s window. */
    public static function highValueAction(): self { return new self('high_value_action', 'OpenAccount'); }

    public static function contractSigning(): self { return new self('contract_signing', 'OpenAccount'); }
    public static function profileChange(): self   { return new self('profile_change', 'ResetPassword'); }

    /**
     * Anything else you need.
     *
     * `$serviceKey` must be one Nafath recognises — see their Service Types
     * table. Keys ending `WithoutBio` skip biometrics and expire in 60 seconds;
     * everything else requires biometrics and lives 180.
     */
    public static function of(string $value, string $serviceKey = 'Login'): self
    {
        return new self($value, $serviceKey);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
