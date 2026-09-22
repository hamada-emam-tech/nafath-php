<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Tests\Unit;

use HamadaEmamTech\Nafath\Config\Credentials;
use HamadaEmamTech\Nafath\Config\Environment;
use HamadaEmamTech\Nafath\Exception\AuthenticationException;
use HamadaEmamTech\Nafath\Exception\VerificationException;
use HamadaEmamTech\Nafath\Http\NafathClient;
use HamadaEmamTech\Nafath\Session\AuthorizeUrl;
use HamadaEmamTech\Nafath\Tests\Support\FakeNafath;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each test here corresponds to a real incident on a production Nafath
 * integration. They are the reason this package exists: the API is not
 * difficult, but several of its behaviours are surprising and fail silently.
 */
final class TrapsTest extends TestCase
{
    private function client(FakeNafath $fake, Environment $env = Environment::Staging): NafathClient
    {
        $psr17 = new Psr17Factory();

        return new NafathClient(
            http: $fake, requests: $psr17, streams: $psr17,
            credentials: new Credentials('app-id', 'app-key', $env),
            serverIp: '203.0.113.10',
        );
    }

    #[Test]
    public function the_state_is_extracted_from_the_url_not_the_body(): void
    {
        // Trap 1. Nafath's JSON body carries only hashedState; the redeemable
        // credential is inside the url's query string. Miss it and you can never
        // complete a verification without their callback.
        $state = FakeNafath::realisticState();
        $fake  = (new FakeNafath())->on('/oidc/session', 200, [
            'url'         => FakeNafath::authorizeUrl($state),
            'hashedState' => AuthorizeUrl::digest($state),
            'requestId'   => 'req-1',
        ]);

        $session = $this->client($fake)->createWebSession('ar', 'req-1', '1.2.3.4');

        $this->assertSame($state, $session->state, 'the state must be parsed out of the URL');
        $this->assertNotSame($session->state, $session->hashedState);
    }

    #[Test]
    public function hashed_state_is_the_sha256_of_the_state_not_the_state(): void
    {
        // Trap 2. Comparing a posted state to a stored hashedState never matches,
        // and the failure is silent — the callback 200s and nothing advances.
        $state = FakeNafath::realisticState();

        $this->assertSame(
            base64_encode(hash('sha256', $state, true)),
            AuthorizeUrl::digest($state),
        );

        $url = new AuthorizeUrl('https://x', $state, AuthorizeUrl::digest($state), 'req-1');
        $this->assertTrue($url->matches($state), 'a session must recognise its own state');
        $this->assertFalse($url->matches('some-other-state'));
    }

    #[Test]
    public function a_realistic_state_survives_url_parsing(): void
    {
        // Real states are base64 and contain +, / and =. A hex or alphanumeric
        // pattern silently yields an empty string and every callback then fails.
        $state = FakeNafath::realisticState();
        $this->assertMatchesRegularExpression('#[+/=]#', $state, 'fixture should exercise base64 punctuation');

        $fake = (new FakeNafath())->on('/oidc/session', 200, [
            'url'         => FakeNafath::authorizeUrl($state),
            'hashedState' => AuthorizeUrl::digest($state),
            'requestId'   => 'r',
        ]);

        $this->assertSame($state, $this->client($fake)->createWebSession('ar', 'r', '1.2.3.4')->state);
    }

    #[Test]
    public function not_approved_yet_is_a_pending_result_never_an_exception(): void
    {
        // Trap 5. 401-033-024 reads like a failure and is not one. An integration
        // that throws here reports itself broken while working perfectly.
        $fake = (new FakeNafath())->on('/oidc/jwt', 401, [
            'status' => '401', 'code' => '401-033-024', 'reference' => 12345,
        ]);

        $this->assertNull(
            $this->client($fake)->retrieveWebToken('some-state', '1.2.3.4'),
            'a waiting user must not raise an exception',
        );
    }

    #[Test]
    public function the_token_field_is_called_token(): void
    {
        // Trap 4. Their schema says `token`. Assuming `jwt` works until the first
        // SUCCESSFUL verification — the worst possible moment to discover it.
        $fake = (new FakeNafath())->on('/oidc/jwt', 200, ['state' => 's', 'token' => 'the.jwt.here']);

        $this->assertSame('the.jwt.here', $this->client($fake)->retrieveWebToken('s', '1.2.3.4'));
    }

    #[Test]
    public function environments_differ_only_by_path_prefix(): void
    {
        // Trap 6. One host, three environments, distinguished by prefix alone.
        $this->assertSame('https://rabet-nafath.api.elm.sa/nafath-sandbox', Environment::Sandbox->baseUrl());
        $this->assertSame('https://rabet-nafath.api.elm.sa/stg', Environment::Staging->baseUrl());
        $this->assertSame('https://rabet-nafath.api.elm.sa', Environment::Production->baseUrl());
    }

    #[Test]
    public function production_has_no_prefix_and_prd_is_not_a_thing(): void
    {
        $this->assertSame('', Environment::Production->pathPrefix());
        $this->assertSame(Environment::Production, Environment::fromBaseUrl('https://rabet-nafath.api.elm.sa'));
        $this->assertSame(Environment::Staging, Environment::fromBaseUrl('https://rabet-nafath.api.elm.sa/stg'));

        $this->expectException(\InvalidArgumentException::class);
        Environment::fromBaseUrl('https://rabet-nafath.api.elm.sa/prd');
    }

    #[Test]
    public function the_jwk_path_is_mfa_not_oidc(): void
    {
        // Trap 3. /api/v2/oidc/jwk does not exist. The mfa key set signs tokens
        // for BOTH flows — this project reported the 404 to Nafath as a bug
        // before realising the path was simply wrong.
        $fake = (new FakeNafath())->on('/api/v1/mfa/jwk', 200, ['keys' => [['kid' => 'elm']]]);

        $this->client($fake)->jwkSet('1.2.3.4');

        $this->assertStringContainsString('/api/v1/mfa/jwk', (string) $fake->lastRequest()?->getUri());
    }

    #[Test]
    public function a_403_explains_the_three_real_causes(): void
    {
        // Trap 7. Almost never a bad key: usually wrong environment, an
        // unactivated subscription, or an unallowlisted IP.
        $fake = (new FakeNafath())->on('/oidc/session', 403, ['message' => 'Authentication failed']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/environment|ACTIVATED|allowlisted/i');
        $this->client($fake, Environment::Production)->createWebSession('ar', 'r', '1.2.3.4');
    }

    #[Test]
    public function one_active_transaction_per_identity_is_explained_not_just_thrown(): void
    {
        // Trap 11. A user who taps verify twice locks themselves out. The message
        // has to tell them to check their app, not just say "failed".
        $fake = (new FakeNafath())->on('/mfa/request', 400, ['code' => '400-034-050', 'reference' => 77]);

        try {
            $this->client($fake)->createAppRequest('1000000000', 'Login', 'ar', 'r', '1.2.3.4');
            $this->fail('expected a refusal');
        } catch (VerificationException $e) {
            $this->assertSame('400-034-050', $e->nafathCode);
            $this->assertSame(77, $e->reference(), 'Nafath\'s trace number must survive for support tickets');
            $this->assertStringContainsString('Nafath app', $e->getMessage());
        }
    }

    #[Test]
    public function credentials_never_leak_the_key_in_a_dump(): void
    {
        $c = new Credentials('app-id', 'super-secret-key', Environment::Production);

        $this->assertStringNotContainsString('super-secret-key', print_r($c->__debugInfo(), true));
    }

    #[Test]
    public function the_x_forwarded_for_header_carries_both_ips(): void
    {
        // Required by NSI001, and it is the address Nafath allowlists — so
        // getting it wrong presents as an authentication failure.
        $fake = (new FakeNafath())->on('/api/v1/mfa/jwk', 200, ['keys' => []]);
        $this->client($fake)->jwkSet('198.51.100.7');

        $this->assertSame(
            '198.51.100.7, 203.0.113.10',
            $fake->lastRequest()?->getHeaderLine('X-Forwarded-For'),
        );
    }
}
