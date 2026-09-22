<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Flow;

use HamadaEmam\Nafath\Http\NafathClient;
use HamadaEmam\Nafath\Identity\VerificationResult;
use HamadaEmam\Nafath\Session\AuthorizeUrl;
use HamadaEmam\Nafath\Token\TokenVerifier;

/**
 * The web redirect flow: the person leaves your site, authenticates on Nafath's
 * portal, and comes back.
 *
 * Stateless by design. You get an AuthorizeUrl, you keep whatever you need from
 * it, and you ask again later. The library does not decide where your sessions
 * live — see VerificationManager if you want that handled for you.
 *
 * Completing does NOT depend on Nafath calling you back. The state extracted
 * from the authorize URL is enough to ask directly, which is what makes this
 * work when their callback is misconfigured, delayed, or firewalled — a
 * situation that is far more common than the documentation suggests.
 */
final class WebFlow
{
    public function __construct(
        private readonly NafathClient $client,
        private readonly TokenVerifier $verifier,
    ) {}

    /**
     * Begin. Send the person to the returned `url`, unmodified.
     *
     * @param string $requestId a UUID you generate, unique per attempt
     */
    public function start(string $requestId, string $userIp, string $locale = 'ar'): AuthorizeUrl
    {
        return $this->client->createWebSession($locale, $requestId, $userIp);
    }

    /**
     * Ask whether the person has finished.
     *
     * Poll this every 2–3 seconds while they are away. A pending result is
     * normal and not an error — see VerificationResult.
     *
     * The state is single-use: once this returns Verified, that state is spent
     * and asking again will fail. Persist the outcome; do not re-ask.
     */
    public function complete(string $state, string $userIp): VerificationResult
    {
        $token = $this->client->retrieveWebToken($state, $userIp);

        if ($token === null) {
            return VerificationResult::pending();
        }

        $claims = $this->verifier->verify($token, $userIp);

        return VerificationResult::verified($claims, $claims->transactionId);
    }
}
