<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Exception;

use HamadaEmam\Nafath\Config\Environment;

/**
 * Nafath rejected the credentials — HTTP 403.
 *
 * Almost never a bad key in practice. The usual causes, in order of likelihood:
 *   1. the credential belongs to a different environment than the URL
 *   2. the Rabet subscription has not been ACTIVATED yet (it is issued first,
 *      activated later, and returns 403 in between with no distinguishing code)
 *   3. the calling server's IP is not allowlisted for that environment
 */
final class AuthenticationException extends NafathException
{
    public static function rejected(Environment $environment, string $appId): self
    {
        return new self(
            "Nafath rejected APP-ID {$appId} for the {$environment->value} environment (403). "
            . 'Check, in this order: the credential matches the environment; the Rabet '
            . 'subscription is ACTIVATED and not merely issued; your egress IP is allowlisted.'
        );
    }
}
