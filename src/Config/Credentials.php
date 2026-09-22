<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Config;

use HamadaEmamTech\Nafath\Exception\ConfigurationException;

/**
 * One Nafath credential pair, bound to the environment it belongs to.
 *
 * Nafath issues a SEPARATE APP-ID and APP-KEY per environment, from a separate
 * Rabet registration. A staging pair returns 403 against production and vice
 * versa — with no message distinguishing that from any other auth failure.
 *
 * Binding the pair to its environment in one immutable object means the two can
 * never drift apart in configuration, which is how real deployments end up
 * sending production identities to a test system.
 */
final readonly class Credentials
{
    public function __construct(
        public string $appId,
        public string $appKey,
        public Environment $environment,
        /** Override only if Nafath ever moves hosts. The prefix still comes from the environment. */
        public string $host = Environment::DEFAULT_HOST,
        /** The SP name registered on Rabet. Appears as `aud` in the signed token. */
        public ?string $audience = null,
    ) {
        if (trim($appId) === '' || trim($appKey) === '') {
            throw ConfigurationException::missingCredentials($environment);
        }
    }

    public function baseUrl(): string
    {
        return $this->environment->baseUrl($this->host);
    }

    public function url(string $path): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * Headers every Nafath call must carry.
     *
     * X-Forwarded-For is mandatory per NSI001 and must contain the end user's IP
     * and the calling server's IP, comma separated, without brackets. Nafath
     * uses it for its own audit chain; omitting it is accepted today but is
     * documented as required, so it is always sent.
     */
    public function headers(string $endUserIp, string $serverIp): array
    {
        return [
            'APP-ID'          => $this->appId,
            'APP-KEY'         => $this->appKey,
            'X-Forwarded-For' => $endUserIp . ', ' . $serverIp,
            'Accept'          => 'application/json',
        ];
    }

    /** Never let a key reach a log, an exception message or a debug dump. */
    public function __debugInfo(): array
    {
        return [
            'appId'       => $this->appId,
            'appKey'      => '***redacted***',
            'environment' => $this->environment->value,
            'baseUrl'     => $this->baseUrl(),
        ];
    }
}
