# Symfony

> See also: [how verification actually flows](flow.md) ·
> [getting credentials from Nafath](getting-started-with-nafath.md) ·
> [Laravel](laravel.md) · [CodeIgniter](codeigniter.md)

No bundle needed — `Nafath` is a plain service; wire it as one.

## Wiring

```yaml
# config/services.yaml
services:
    HamadaEmamTech\Nafath\Nafath:
        factory: ['HamadaEmamTech\Nafath\Nafath', 'make']
        arguments:
            $appId:       '%env(NAFATH_APP_ID)%'
            $appKey:      '%env(NAFATH_APP_KEY)%'
            $environment: !php/enum HamadaEmamTech\Nafath\Config\Environment::Staging
            $serverIp:    '%env(NAFATH_SERVER_IP)%'
            $http:        '@Psr\Http\Client\ClientInterface'
            $requests:    '@Psr\Http\Message\RequestFactoryInterface'
            $streams:     '@Psr\Http\Message\StreamFactoryInterface'
            $cache:       '@cache.app'
            $logger:      '@logger'
```

`symfony/http-client` and `nyholm/psr7` cover the PSR-18/17 arguments if you
don't already have an HTTP client bound to those interfaces:

```bash
composer require symfony/http-client nyholm/psr7 symfony/psr-http-message-bridge
```

Then inject `Nafath` like any other service:

```php
final class VerificationController extends AbstractController
{
    public function initiate(Request $request, Nafath $nafath): RedirectResponse
    {
        $session = $nafath->web()->start(Uuid::v4()->toRfc4122(), $request->getClientIp());

        $this->cache->save(
            $this->cache->getItem('nafath.state.' . $session->hashedState)
                ->set($session->state)->expiresAfter(300)
        );

        return $this->redirect($session->url);
    }
}
```

The environment value can't be an `!php/enum` literal if it varies by
deploy — resolve it in a factory instead:

```php
// src/Factory/NafathFactory.php
final class NafathFactory
{
    public static function create(
        string $appId, string $appKey, string $environment, string $serverIp,
        ClientInterface $http, RequestFactoryInterface $requests,
        StreamFactoryInterface $streams, CacheInterface $cache, LoggerInterface $logger,
    ): Nafath {
        return Nafath::make(
            appId: $appId, appKey: $appKey,
            environment: Environment::from($environment),
            serverIp: $serverIp, http: $http, requests: $requests,
            streams: $streams, cache: $cache, logger: $logger,
        );
    }
}
```

```yaml
services:
    HamadaEmamTech\Nafath\Nafath:
        factory: ['App\Factory\NafathFactory', 'create']
        arguments:
            $appId: '%env(NAFATH_APP_ID)%'
            $appKey: '%env(NAFATH_APP_KEY)%'
            $environment: '%env(NAFATH_ENVIRONMENT)%'
            $serverIp: '%env(NAFATH_SERVER_IP)%'
```

## Persistence with Doctrine

The shape is identical to the [Laravel guide](laravel.md#building-persistence-yourself)
— read that first for the reasoning; this is the same three pieces in Doctrine.

### Entity

```php
#[ORM\Entity]
#[ORM\Table(name: 'nafath_verifications')]
#[ORM\UniqueConstraint(columns: ['request_id'])]
class NafathVerification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    // Multi-tenant: scope every row. Single-tenant apps drop this property
    // and its column entirely.
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    private Tenant $tenant;

    #[ORM\Column] private string $requestId;
    #[ORM\Column(nullable: true)] private ?string $hashedState = null;
    #[ORM\Column(nullable: true)] private ?string $transactionId = null;
    #[ORM\Column] private string $purpose;
    #[ORM\Column] private string $status;
    #[ORM\Column(nullable: true, name: 'identifier_digest')] private ?string $identifierDigest = null;
    #[ORM\Column] private \DateTimeImmutable $expiresAt;
}
```

Generate the migration with `bin/console make:migration` as usual. As with
Laravel, don't add a column for the raw `state` — it lives in cache for the
minutes it's needed, keyed by `hashedState`:

```php
$this->cache->save(
    $this->cache->getItem('nafath.state.' . $session->hashedState)
        ->set(['state' => $session->state, 'tenantId' => $tenant->getId()])
        ->expiresAfter(300)
);
```

### Resolving the outcome, with NIN uniqueness

```php
public function resolveUser(Tenant $tenant, Claims $claims): User
{
    $digest = $claims->identifierDigest($this->pepper);

    // Multi-tenant: unique per (tenant, digest), not globally — see the
    // Laravel guide for why. Back this with a unique DB index, the same
    // caution about race conditions under concurrent callbacks applies here.
    return $this->users->findOneBy(['tenant' => $tenant, 'ninDigest' => $digest])
        ?? $this->users->createFrom($tenant, $claims);
}
```

### Audit trail

```php
final class DoctrineCallRecorder implements CallRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?Tenant $tenant = null,
    ) {}

    public function record(array $entry): void
    {
        // MUST NOT throw — see CallRecorder's own docblock. A failed audit
        // write must never fail a live verification.
        try {
            $log = NafathCallLog::fromArray($entry, $this->tenant);
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('nafath audit write failed', ['exception' => $e]);
        }
    }
}
```

### Multi-tenant wiring

Bind `Nafath` per-request once credentials vary by tenant — a request-scoped
service rather than the `services.yaml` singleton above:

```yaml
services:
    HamadaEmamTech\Nafath\Nafath:
        factory: ['App\Factory\NafathFactory', 'createForTenant']
        arguments: ['@App\Tenant\CurrentTenant', '@cache.app', '@logger']
        # request-scoped: resolved fresh per request, not shared across tenants
```

Each tenant needs its own Rabet registration and its own `APP-ID`/`APP-KEY`
per environment — see [getting-started-with-nafath.md](getting-started-with-nafath.md).

### Single-tenant recap

Drop `tenant` from the entity, the lookup, and the recorder constructor, and
go back to the plain `services.yaml` binding at the top of this guide. Same
three pieces — one set of credentials instead of one per tenant.
