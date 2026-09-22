<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Config;

/**
 * Which Nafath environment a set of credentials belongs to.
 *
 * Nafath distinguishes its environments ONLY by a path prefix on one shared
 * host. That is the single easiest thing to get wrong in this integration, and
 * getting it wrong is silent: production traffic quietly reaches a test system,
 * or a staging credential returns 403 against production and looks like an
 * outage.
 *
 * So the prefix is not a string anyone passes around — it belongs to the
 * environment, and a credential cannot exist without one.
 */
enum Environment: string
{
    /** Sandbox. Registered separately on Rabet; its own APP-ID/APP-KEY. */
    case Sandbox = 'sandbox';

    /** Pre-production. Nafath's own documents call this "staging". */
    case Staging = 'staging';

    /** The live government system. Real citizens, real identity data. */
    case Production = 'production';

    public const DEFAULT_HOST = 'https://rabet-nafath.api.elm.sa';

    /**
     * The path prefix this environment lives under.
     *
     * Production has NONE — it is the bare host. `/prd`, `/prod` and
     * `/production` do not exist and answer 404 "No Mapping Rule matched".
     */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::Sandbox    => '/nafath-sandbox',
            self::Staging    => '/stg',
            self::Production => '',
        };
    }

    public function baseUrl(string $host = self::DEFAULT_HOST): string
    {
        return rtrim($host, '/') . $this->pathPrefix();
    }

    /**
     * Does this environment handle real people's identities?
     *
     * Used to refuse foot-guns: unverified JWT acceptance, debug token dumps,
     * and anything else that is a convenience in testing and a breach in
     * production.
     */
    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /**
     * Recover the environment from a base URL.
     *
     * Useful when adopting an existing configuration: pass whatever is in the
     * old config and find out what it actually points at.
     */
    public static function fromBaseUrl(string $baseUrl): self
    {
        $path = rtrim(parse_url($baseUrl, PHP_URL_PATH) ?: '', '/');

        return match ($path) {
            '/nafath-sandbox' => self::Sandbox,
            '/stg'            => self::Staging,
            ''                => self::Production,
            default           => throw new \InvalidArgumentException(
                "Unrecognised Nafath path prefix '{$path}'. Nafath uses /nafath-sandbox, "
                . '/stg, or no prefix at all for production.'
            ),
        };
    }
}
