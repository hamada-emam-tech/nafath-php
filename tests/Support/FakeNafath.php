<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A stand-in for Nafath that answers the way the real service does — including
 * the parts of its behaviour that are surprising.
 */
final class FakeNafath implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var array<string, array{int, array<string,mixed>}> */
    private array $routes = [];

    public function on(string $pathFragment, int $status, array $body): self
    {
        $this->routes[$pathFragment] = [$status, $body];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $path = $request->getUri()->getPath();

        foreach ($this->routes as $fragment => [$status, $body]) {
            if (str_contains($path, $fragment)) {
                return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
            }
        }

        return new Response(404, [], json_encode(['message' => 'No Mapping Rule matched']));
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    /** Build an authorize URL shaped exactly like Nafath's, state embedded in the query. */
    public static function authorizeUrl(string $state, string $aud = 'https://example.sa', string $portal = 'www.iam.sa'): string
    {
        return "https://{$portal}/authservice/authorize?" . http_build_query([
            'scope'         => 'openid',
            'response_type' => 'id_token',
            'response_mode' => 'form_post',
            'client_id'     => 'https://nafath.api.elm.sa/stg',
            'redirect_uri'  => 'https://nafath.api.elm.sa/stg/api/v2/oidc/callback',
            'aud'           => $aud,
            'state'         => $state,
        ]);
    }

    /** A realistic 344-character base64 state — with +, / and = in it. */
    public static function realisticState(): string
    {
        return base64_encode(random_bytes(258));
    }
}
