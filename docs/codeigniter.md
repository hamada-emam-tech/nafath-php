# CodeIgniter

> See also: [how verification actually flows](flow.md) ·
> [getting credentials from Nafath](getting-started-with-nafath.md) ·
> [Laravel](laravel.md) · [Symfony](symfony.md)

Written for CodeIgniter 4. CI4 has no PSR-18 HTTP client of its own, so bring
one — Guzzle is the simplest fit:

```bash
composer require guzzlehttp/guzzle nyholm/psr7
```

## Wiring

Add a factory to `app/Config/Services.php`:

```php
// app/Config/Services.php
public static function nafath(bool $getShared = true): Nafath
{
    if ($getShared) {
        return static::getSharedInstance('nafath');
    }

    $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
    $env   = config('Nafath'); // your own config file, see below

    return Nafath::make(
        appId:       $env->appId,
        appKey:      $env->appKey,
        environment: Environment::from($env->environment),
        serverIp:    $env->serverIp,
        http:        new \GuzzleHttp\Client(['timeout' => 15]),
        requests:    $psr17,
        streams:     $psr17,
        cache:       new \App\Nafath\Psr16CacheAdapter(\Config\Services::cache()),
        logger:      new \App\Nafath\PsrLoggerAdapter(log_message(...)),
    );
}
```

```php
// app/Config/Nafath.php
class Nafath extends BaseConfig
{
    public string $appId      = '';
    public string $appKey     = '';
    public string $environment = 'staging';
    public string $serverIp    = '';

    public function __construct()
    {
        parent::__construct();
        $this->appId      = env('NAFATH_APP_ID', '');
        $this->appKey     = env('NAFATH_APP_KEY', '');
        $this->environment = env('NAFATH_ENVIRONMENT', 'staging');
        $this->serverIp    = env('NAFATH_SERVER_IP', '');
    }
}
```

CI4's cache and logger interfaces aren't PSR-16/PSR-3, so a thin adapter is
needed for each — a handful of pass-through methods, not shown here since
they're mechanical. Then in a controller:

```php
public function initiate()
{
    $nafath  = service('nafath');
    $session = $nafath->web()->start(service('uuid')->uuid4()->toString(), $this->request->getIPAddress());

    cache()->save('nafath_state_' . $session->hashedState, $session->state, 300);

    return redirect()->to($session->url);
}
```

## Persistence

Same three pieces as the [Laravel guide](laravel.md#building-persistence-yourself)
— read that for the reasoning behind each; this is the CI4 Query
Builder/Model equivalent.

### Migration

```php
// app/Database/Migrations/2024-01-01-000000_CreateNafathVerifications.php
public function up()
{
    $this->forge->addField([
        'id'                => ['type' => 'INT', 'auto_increment' => true],
        // Multi-tenant: scope every row. Single-tenant apps drop this
        // column entirely.
        'tenant_id'         => ['type' => 'INT', 'null' => true],
        'request_id'        => ['type' => 'VARCHAR', 'constraint' => 64],
        'hashed_state'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        'transaction_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        'purpose'           => ['type' => 'VARCHAR', 'constraint' => 64],
        'status'            => ['type' => 'VARCHAR', 'constraint' => 32],
        'identifier_digest' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        'expires_at'        => ['type' => 'DATETIME'],
        'created_at'        => ['type' => 'DATETIME', 'null' => true],
    ]);
    $this->forge->addPrimaryKey('id');
    $this->forge->addUniqueKey('request_id');
    $this->forge->addKey('hashed_state');
    $this->forge->addKey(['tenant_id', 'identifier_digest']); // uniqueness scope, see below
    $this->forge->createTable('nafath_verifications');
}
```

Don't add a column for the raw `state` — cache it, keyed by `hashedState`,
the same way as every other framework guide here:

```php
cache()->save('nafath_state_' . $session->hashedState, [
    'state'     => $session->state,
    'tenant_id' => $tenantId,
], 300);
```

### Model — resolving the outcome, with NIN uniqueness

```php
class UserModel extends Model
{
    public function resolveFromClaims(int $tenantId, Claims $claims, string $pepper): array
    {
        $digest = $claims->identifierDigest($pepper);

        // Multi-tenant: unique per (tenant_id, nin_digest), not globally —
        // the same national ID is a distinct customer per tenant. Enforce
        // this with a DB unique index; a find-then-insert alone races under
        // concurrent callbacks.
        $existing = $this->where('tenant_id', $tenantId)
                         ->where('nin_digest', $digest)
                         ->first();

        if ($existing) {
            return $existing;
        }

        $id = $this->insert([
            'tenant_id'   => $tenantId,
            'nin_digest'  => $digest,
            'name'        => $claims->fullNameAr,
            'user_type'   => $claims->userType->value,
            'nationality' => $claims->nationality,
        ]);

        return $this->find($id);
    }
}
```

### Audit trail

```php
final class DatabaseCallRecorder implements CallRecorder
{
    public function __construct(private readonly ?int $tenantId = null) {}

    public function record(array $entry): void
    {
        // MUST NOT throw — see CallRecorder's docblock. A broken audit write
        // must never fail a live verification.
        try {
            model('NafathCallLogModel')->insert([...$entry, 'tenant_id' => $this->tenantId]);
        } catch (\Throwable $e) {
            log_message('error', 'nafath audit write failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
```

### Multi-tenant wiring

Resolve credentials and the recorder per-request in the `Services::nafath()`
factory from the top of this guide — pull them from the current tenant
instead of the shared `Config\Nafath`, and pass `$getShared = false` (or key
the shared instance by tenant) so tenants never share one client instance.
Each tenant needs its own Rabet registration and its own `APP-ID`/`APP-KEY`
per environment — see [getting-started-with-nafath.md](getting-started-with-nafath.md).

### Single-tenant recap

Drop `tenant_id` from the migration, the model lookup, and the recorder
constructor. Same pieces, one set of credentials.
