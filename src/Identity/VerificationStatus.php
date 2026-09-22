<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Identity;

enum VerificationStatus: string
{
    case Pending  = 'pending';

    /**
     * The person approved, but the identity is not in hand yet.
     *
     * Only the app-push flow reaches this. Its status endpoint reports the
     * decision; the attributes arrive separately as a signed token on your
     * callback. Conflating this with Verified would mean trusting a status
     * string where a signature is required.
     */
    case Approved = 'approved';

    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired  = 'expired';
}
