<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Http;

use HamadaEmamTech\Nafath\Config\Credentials;
use HamadaEmamTech\Nafath\Exception\AuthenticationException;
use HamadaEmamTech\Nafath\Exception\TransportException;
use HamadaEmamTech\Nafath\Exception\VerificationException;
use HamadaEmamTech\Nafath\Session\AuthorizeUrl;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Every call this library makes to Nafath, and nothing else.
 *
 * Deliberately thin and stateless: no persistence, no policy, no opinions about
 * your users. It knows the wire format — including the parts the published
 * specification gets wrong — and hands back typed results.
 *
 * Endpoint map, with the surprises marked:
 *
 *   GET  {base}/api/v2/oidc/session        web flow: returns the authorize URL
 *   POST {base}/api/v2/oidc/jwt            web flow: state -> signed token
 *   POST {base}/api/v1/mfa/request         app flow: create a push request
 *   POST {base}/api/v1/mfa/request/status  app flow: poll the status
 *   GET  {base}/api/v1/mfa/jwk             signing keys — note: `mfa`, for BOTH
 *                                          flows. /api/v2/oidc/jwk does not
 *                                          exist and answers 404.
 */
final class NafathClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Credentials $credentials,
        /** This server's own IP. Nafath requires it in X-Forwarded-For alongside the user's. */
        private readonly string $serverIp,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?CallRecorder $recorder = null,
    ) {}

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    /* ── web flow ─────────────────────────────────────────────────────────── */

    /**
     * Open a web session. Returns the URL to send the browser to.
     *
     * `$requestId` must be a UUID, unique per transaction. Nafath echoes it on
     * the callback so you can correlate — despite their own sample using
     * `requestId=1`, which violates the rule in their own business rules table.
     */
    public function createWebSession(string $locale, string $requestId, string $userIp): AuthorizeUrl
    {
        $body = $this->send(
            'GET',
            '/api/v2/oidc/session?' . http_build_query(['locale' => $locale, 'requestId' => $requestId]),
            null,
            $userIp,
            'oidc/session',
        );

        return AuthorizeUrl::fromResponse($body, $requestId);
    }

    /**
     * Exchange a state for the signed identity token.
     *
     * Returns NULL when the person simply has not approved yet. Nafath answers
     * 401 with code 401-033-024 in that case — which reads like a failure and is
     * not one. Do not turn this into an exception; it is the normal state for
     * most of a transaction's life.
     */
    public function retrieveWebToken(string $state, string $userIp): ?string
    {
        $body = $this->send(
            'POST',
            '/api/v2/oidc/jwt',
            ['state' => $state],
            $userIp,
            'oidc/jwt',
            allowPending: true,
        );

        if ($body === null) {
            return null; // 401-033-024 — still waiting
        }

        // The field is `token`. Their AuthorizationCodeConsumeResponse schema
        // says so; plenty of integrations assume `jwt` and only discover the
        // difference on the first SUCCESSFUL verification, which is the worst
        // possible time.
        $token = $body['token'] ?? $body['jwt'] ?? $body['id_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new TransportException('Nafath returned success but carried no identity token.');
        }

        return $token;
    }

    /* ── app-push (MFA) flow ──────────────────────────────────────────────── */

    /**
     * Create an in-app request. The person never leaves your site.
     *
     * Returns the transaction id and a two-digit number you MUST display — the
     * same number appears in their Nafath app so they can confirm they are
     * approving the right request.
     */
    public function createAppRequest(
        string $identifier,
        string $service,
        string $locale,
        string $requestId,
        string $userIp,
    ): AppTransaction {
        $body = $this->send(
            'POST',
            '/api/v1/mfa/request?' . http_build_query(['local' => $locale, 'requestId' => $requestId]),
            ['nationalId' => $identifier, 'service' => $service],
            $userIp,
            'mfa/request',
        );

        return new AppTransaction(
            transactionId: (string) ($body['transId'] ?? ''),
            random:        (string) ($body['random'] ?? ''),
            requestId:     $requestId,
        );
    }

    /** Poll an app-push request. Returns WAITING | COMPLETED | REJECTED | EXPIRED. */
    public function appRequestStatus(string $identifier, string $transactionId, string $random, string $userIp): string
    {
        $body = $this->send(
            'POST',
            '/api/v1/mfa/request/status',
            ['nationalId' => $identifier, 'transId' => $transactionId, 'random' => $random],
            $userIp,
            'mfa/request/status',
        );

        return strtoupper((string) ($body['status'] ?? 'WAITING'));
    }

    /* ── shared ───────────────────────────────────────────────────────────── */

    /**
     * Nafath's signing keys.
     *
     * Note the path: `/api/v1/mfa/jwk`. The `mfa` key set signs tokens for BOTH
     * flows. There is no `/api/v2/oidc/jwk` — it answers 404 "No Mapping Rule
     * matched", which is easily mistaken for an outage on their side. This
     * project reported that as a bug to Nafath before realising the path was
     * simply wrong.
     */
    public function jwkSet(string $userIp): array
    {
        $body = $this->send('GET', '/api/v1/mfa/jwk', null, $userIp, 'mfa/jwk');

        if (! isset($body['keys']) || ! is_array($body['keys'])) {
            throw new TransportException('Nafath returned a JWK document with no keys.');
        }

        return $body;
    }

    /* ── transport ────────────────────────────────────────────────────────── */

    /**
     * @param  bool  $allowPending  treat 401-033-024 as "not yet", returning null
     * @return array<string,mixed>|null
     */
    private function send(
        string $method,
        string $path,
        ?array $json,
        string $userIp,
        string $label,
        bool $allowPending = false,
    ): ?array {
        $url     = $this->credentials->url($path);
        $started = microtime(true);

        $request = $this->requests->createRequest($method, $url);
        foreach ($this->credentials->headers($userIp, $this->serverIp) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->record($label, $method, null, null, null, $started, 'unreachable');
            throw TransportException::unreachable($url, $e);
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();
        $body   = json_decode($raw, true);
        $body   = is_array($body) ? $body : [];

        $code      = isset($body['code']) ? (string) $body['code'] : null;
        $reference = isset($body['reference']) ? (int) $body['reference'] : null;

        if ($status >= 200 && $status < 300) {
            $this->record($label, $method, $status, $code, $reference, $started, 'ok');

            return $body;
        }

        // Not approved yet. A result, not a failure.
        if ($allowPending && $status === 401 && str_starts_with((string) $code, '401-033')) {
            $this->record($label, $method, $status, $code, $reference, $started, 'pending');

            return null;
        }

        $this->record($label, $method, $status, $code, $reference, $started, 'error');

        throw match (true) {
            $status === 403 => AuthenticationException::rejected(
                $this->credentials->environment,
                $this->credentials->appId,
            ),
            $code === '400-034-050' => VerificationException::transactionAlreadyActive($reference),
            $code === '400-034-051' => VerificationException::transactionExpired($reference),
            $code === '400-034-053' => VerificationException::transactionNotFound($reference),
            default                 => TransportException::unexpectedStatus($url, $status, $code, $reference),
        };
    }

    private function record(
        string $label,
        string $method,
        ?int $status,
        ?string $code,
        ?int $reference,
        float $started,
        string $outcome,
    ): void {
        $entry = [
            'endpoint'    => $label,
            'method'      => $method,
            'http_status' => $status,
            'nafath_code' => $code,
            // Nafath's own trace number. Quote it in a support ticket and they
            // can find the exact call — the difference between "it does not
            // work" and a solvable report.
            'reference'   => $reference,
            'outcome'     => $outcome,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'environment' => $this->credentials->environment->value,
        ];

        $this->logger->info('[Nafath] ' . $label, $entry);
        $this->recorder?->record($entry);
    }
}
