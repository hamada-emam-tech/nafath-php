<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath;

use HamadaEmam\Nafath\Config\Credentials;
use HamadaEmam\Nafath\Config\Environment;
use HamadaEmam\Nafath\Flow\AppFlow;
use HamadaEmam\Nafath\Flow\WebFlow;
use HamadaEmam\Nafath\Http\CallRecorder;
use HamadaEmam\Nafath\Http\NafathClient;
use HamadaEmam\Nafath\Token\TokenVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Nafath identity verification.
 *
 *     $nafath = Nafath::make(
 *         appId: 'your-app-id',
 *         appKey: 'your-app-key',
 *         environment: Environment::Staging,
 *         serverIp: '203.0.113.10',
 *         http: $psr18Client,
 *         requests: $psr17Factory,
 *         streams: $psr17Factory,
 *     );
 *
 *     // Web redirect — the person goes to Nafath and comes back
 *     $session = $nafath->web()->start($requestId, $userIp);
 *     // … send the browser to $session->url, keep $session->state …
 *     $result = $nafath->web()->complete($session->state, $userIp);
 *
 *     // Or app push — the person never leaves your site
 *     $txn = $nafath->app()->start($nationalId, $requestId, $userIp);
 *     // … show $txn->random, they approve in the Nafath app …
 *
 * Two things this library will not let you do, because both have caused real
 * incidents: trust a token without verifying its signature, and mix an
 * environment's credentials with another environment's URL.
 */
final class Nafath
{
    private ?WebFlow $web = null;
    private ?AppFlow $app = null;

    public function __construct(
        private readonly NafathClient $client,
        private readonly TokenVerifier $verifier,
    ) {}

    /**
     * The usual way to build one.
     *
     * `$serverIp` is this machine's own address. Nafath requires it alongside
     * the end user's in X-Forwarded-For for their audit chain — and it is the
     * address they allowlist, so getting it wrong looks like an auth failure.
     */
    public static function make(
        string $appId,
        string $appKey,
        Environment $environment,
        string $serverIp,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        ?string $audience = null,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
        ?CallRecorder $recorder = null,
        string $host = Environment::DEFAULT_HOST,
    ): self {
        $credentials = new Credentials($appId, $appKey, $environment, $host, $audience);

        $client = new NafathClient(
            http:        $http,
            requests:    $requests,
            streams:     $streams,
            credentials: $credentials,
            serverIp:    $serverIp,
            logger:      $logger ?? new NullLogger(),
            recorder:    $recorder,
        );

        return new self(
            $client,
            new TokenVerifier($client, $cache, expectedAudience: $audience),
        );
    }

    /** The web redirect flow. */
    public function web(): WebFlow
    {
        return $this->web ??= new WebFlow($this->client, $this->verifier);
    }

    /** The app-push flow — no redirect, the person stays on your site. */
    public function app(): AppFlow
    {
        return $this->app ??= new AppFlow($this->client, $this->verifier);
    }

    /** Verify a token Nafath posted to your callback, in either flow. */
    public function verifyToken(string $token, string $userIp): Identity\Claims
    {
        return $this->verifier->verify($token, $userIp);
    }

    public function environment(): Environment
    {
        return $this->client->credentials()->environment;
    }

    /**
     * Confirm the credentials work before you need them to.
     *
     * Fetches the JWK set, which exercises authentication, the environment
     * prefix and network reachability in one call. Worth running at deploy time
     * — the alternative is finding out from a user.
     */
    public function preflight(string $userIp = '127.0.0.1'): bool
    {
        $this->client->jwkSet($userIp);

        return true;
    }
}
