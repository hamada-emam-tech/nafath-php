# Nafath for PHP

[![Latest Version](https://img.shields.io/packagist/v/hamada-emam-tech/nafath.svg)](https://packagist.org/packages/hamada-emam-tech/nafath)
[![License](https://img.shields.io/packagist/l/hamada-emam-tech/nafath.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/hamada-emam-tech/nafath.svg)](composer.json)

Identity verification through **Nafath**, Saudi Arabia's national digital identity
service — both the web redirect and the app-push flow, in any PHP project.

The Nafath API is not difficult. What makes it expensive is that several of its
behaviours are surprising and **fail silently** — a callback that matches nothing,
a 404 on a path the specification lists, a `401` that means "still waiting" rather
than "failed". This package was extracted from a production integration and every
one of those is already handled.

```bash
composer require hamada-emam-tech/nafath
```

**Docs:** [how verification actually flows](docs/flow.md) ·
[getting credentials from Nafath](docs/getting-started-with-nafath.md) ·
[Laravel](docs/laravel.md) · [Symfony](docs/symfony.md) · [CodeIgniter](docs/codeigniter.md)
(each: wiring + persistence, single- and multi-tenant)

---

## Quick start

```php
use HamadaEmamTech\Nafath\Nafath;
use HamadaEmamTech\Nafath\Config\Environment;

$nafath = Nafath::make(
    appId:       getenv('NAFATH_APP_ID'),
    appKey:      getenv('NAFATH_APP_KEY'),
    environment: Environment::Staging,     // Sandbox | Staging | Production
    serverIp:    '203.0.113.10',           // this machine — Nafath allowlists it
    http:        $psr18Client,
    requests:    $psr17Factory,
    streams:     $psr17Factory,
);
```

### Web flow — the person goes to Nafath and comes back

```php
$session = $nafath->web()->start($requestId, $userIp);

// Send the browser to $session->url — unmodified, it is signed.
// Keep $session->state; it is what lets you finish without their callback.

$result = $nafath->web()->complete($session->state, $userIp);

if ($result->isPending())  return 'still waiting';   // NOT an error
if ($result->isVerified()) {
    $claims = $result->claims();
    $claims->identifier;   // national ID / Iqama / Visa / Border
    $claims->fullNameAr;
    $claims->userType;     // National | Resident | Visitor
}
```

### App-push flow — the person never leaves your site

```php
$txn = $nafath->app()->start($nationalId, $requestId, $userIp);

echo "Open your Nafath app and approve request number {$txn->random}";

$result = $nafath->app()->status($txn, $nationalId, $userIp);
```

---

## What this package already knows

Each row cost real days on a production integration.

| Trap | How it presents | Handled by |
|---|---|---|
| The `state` is **not** in the JSON body — it is inside the `url` query string | You can never complete a verification yourself | `AuthorizeUrl` parses it out |
| `hashedState = base64(sha256(state))` — they are different values | Callbacks match nothing, **silently** | `AuthorizeUrl::matches()` |
| JWK lives at `/api/v1/mfa/jwk`, **not** `/api/v2/oidc/jwk` | `404 No Mapping Rule matched`, looks like their outage | one resolved path |
| The token field is `token`, not `jwt` | Breaks on your first **successful** verification | client reads `token` |
| `401-033-024` means *not approved yet* | Integration reports itself broken while working | `VerificationResult::pending()` — a result, never a throw |
| Environments differ only by path prefix; production has **none** | Production traffic silently reaches a test system | `Environment` owns the prefix |
| Each environment needs its **own** APP-ID and APP-KEY | `403` that looks like a bad key | credentials bound to an environment |
| Only **one** active transaction per identity | Second attempt refused, user locked out | typed error that says to check the app |
| The transaction lives 200s, but a real person's journey is longer | Expires mid-approval, blamed on Nafath | documented; set your own window |

There is deliberately **no option to skip signature verification**. Everything
downstream — a national ID, a verified name, the decision to let somebody into an
account — is exactly as trustworthy as that signature.

---

## Verification is not only registration

The same person may need to prove who they are when logging in from a new device,
before a payout, or when changing a phone number. `Purpose` carries that through
to your audit trail, and picks the right Nafath service key:

```php
use HamadaEmamTech\Nafath\Purpose\Purpose;

$nafath->app()->start($nationalId, $requestId, $userIp, Purpose::login());
$nafath->app()->start($nationalId, $requestId, $userIp, Purpose::highValueAction());
$nafath->app()->start($nationalId, $requestId, $userIp, Purpose::of('withdraw_funds', 'MoneyTransfer'));
```

---

## Environments

One host; the environment is the path prefix.

| Environment | Base URL |
|---|---|
| Sandbox | `https://rabet-nafath.api.elm.sa/nafath-sandbox` |
| Staging | `https://rabet-nafath.api.elm.sa/stg` |
| **Production** | `https://rabet-nafath.api.elm.sa` — **no prefix** |

`/prd`, `/prod` and `/production` do not exist and answer `404`.

Each environment is a separate Rabet registration with its **own credentials**.
A staging pair returns `403` against production, indistinguishable from a bad key.

```php
$nafath->preflight();   // exercises auth, prefix and reachability in one call
```

---

## Requirements

- PHP 8.2+
- Any PSR-18 HTTP client and PSR-17 factories (Guzzle, Symfony, …)
- Optional PSR-16 cache for the JWK set, PSR-3 logger, and a `CallRecorder` for audit

## Before you go live

Nafath requires your **callback to be hosted inside Saudi Arabia**. This is a
regulatory requirement, not a technical one — hosting it elsewhere is the kind of
thing that gets an integration shut down.

## License

MIT © [Hamada Emam](https://github.com/hamada-emam-tech)
