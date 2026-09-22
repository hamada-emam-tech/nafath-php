<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Exception;

use HamadaEmamTech\Nafath\Config\Environment;

/** Something is wrong with how the library was set up, not with Nafath. */
final class ConfigurationException extends NafathException
{
    public static function missingCredentials(Environment $environment): self
    {
        return new self(
            "No Nafath APP-ID/APP-KEY configured for the {$environment->value} environment. "
            . 'Each environment has its own pair, issued from a separate Rabet registration — '
            . 'a staging pair returns 403 against production.'
        );
    }

    public static function callbackNotInSaudiArabia(string $url): self
    {
        return new self(
            "The callback URL {$url} does not appear to be hosted inside Saudi Arabia. "
            . 'Nafath requires the service provider callback to reside within the Kingdom; '
            . 'hosting it elsewhere is a regulatory issue, not just a technical one.'
        );
    }
}
