<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Http;

/**
 * An in-app push request awaiting the person's approval.
 *
 * The `random` is not decoration. Nafath shows the same two digits in the app,
 * and the person matches them against what you display before approving. Skip
 * showing it and you have trained your users to approve unseen requests, which
 * is precisely the attack the number exists to prevent.
 */
final readonly class AppTransaction
{
    public function __construct(
        public string $transactionId,
        /** Two digits. Display this to the person — it is a security control. */
        public string $random,
        public string $requestId,
    ) {}

    /** Deep link that opens the Nafath app on the person's device. */
    public function deepLink(string $platform = 'ios'): string
    {
        return $platform === 'android' ? 'nic://nafath' : 'nafath://home';
    }
}
