# Laravel

> See also: [how verification actually flows](flow.md) ·
> [getting credentials from Nafath](getting-started-with-nafath.md) ·
> [Symfony](symfony.md) · [CodeIgniter](codeigniter.md)

There is no Laravel bridge package, and you do not need one. Bind it once in
`AppServiceProvider`:

```php
use HamadaEmamTech\Nafath\Nafath;
use HamadaEmamTech\Nafath\Config\Environment;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;

public function register(): void
{
    $this->app->singleton(Nafath::class, function () {
        $psr17 = new HttpFactory();

        return Nafath::make(
            appId:       config('services.nafath.app_id'),
            appKey:      config('services.nafath.app_key'),
            environment: Environment::from(config('services.nafath.environment')),
            serverIp:    config('services.nafath.server_ip'),
            http:        new Guzzle(['timeout' => 15]),
            requests:    $psr17,
            streams:     $psr17,
            cache:       cache()->store()->getStore() instanceof \Psr\SimpleCache\CacheInterface
                            ? cache()->store()
                            : null,
            logger:      logger()->channel('stack'),
        );
    });
}
```

That is the whole integration. Then anywhere:

```php
public function initiate(Request $request, Nafath $nafath)
{
    $session = $nafath->web()->start((string) Str::uuid(), $request->ip());

    session(['nafath_state' => $session->state]);

    return redirect()->away($session->url);
}
```

## Config

```php
// config/services.php
'nafath' => [
    'app_id'      => env('NAFATH_APP_ID'),
    'app_key'     => env('NAFATH_APP_KEY'),
    'environment' => env('NAFATH_ENVIRONMENT', 'staging'),
    'server_ip'   => env('NAFATH_SERVER_IP'),
],
```

## Why no bridge package

A bridge would trade the twenty lines above for a second package tracking
every Laravel release. The wiring isn't the hard part of this integration —
the wire format is, and that lives in the core where every framework benefits
from it.

Persistence — sessions, stored identities, an audit trail — is your domain,
not the library's. The rest of this guide builds that, step by step, for a
single-tenant app and a multi-tenant one.

---

## Building persistence yourself

Nothing below ships in the package. It's the shape one production integration
ended up with — adapt table and column names to your own conventions.

### 1. Migrations

A verification attempt has a lifecycle (started → pending → verified/rejected/
expired) independent of the person it eventually resolves to, so it gets its
own table rather than living inline on `users`.

```php
// database/migrations/xxxx_xx_xx_create_nafath_verifications_table.php
Schema::create('nafath_verifications', function (Blueprint $table) {
    $table->id();

    // Multi-tenant: every row is scoped to a tenant. Single-tenant apps drop
    // this column entirely — do not leave it nullable "just in case", an
    // unscoped row is how one tenant ends up seeing another's verification.
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

    $table->string('request_id')->unique();     // the UUID you generate per attempt
    $table->string('hashed_state')->nullable()->index(); // web flow correlation key
    $table->string('transaction_id')->nullable()->index(); // app-push flow correlation key
    $table->string('purpose');                  // Purpose::value — 'login', 'registration', …
    $table->string('status');                   // VerificationStatus::value
    $table->string('identifier_digest')->nullable()->index(); // Claims::identifierDigest()
    $table->string('user_type')->nullable();     // UserType::value
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->timestamp('expires_at');
    $table->timestamps();
});

// database/migrations/xxxx_xx_xx_create_nafath_call_logs_table.php
Schema::create('nafath_call_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('endpoint');
    $table->string('method');
    $table->unsignedSmallInteger('http_status')->nullable();
    $table->string('nafath_code')->nullable();
    $table->unsignedBigInteger('reference')->nullable()->index(); // Elm's own trace number
    $table->string('outcome');
    $table->unsignedInteger('duration_ms');
    $table->string('environment');
    $table->timestamp('created_at');
});
```

Deliberately **not** stored: the raw `state` (single-use and only needed for
the seconds between start and complete — cache it, see below) and the raw
`identifier` (store `identifierDigest()`, not the national ID itself, unless
your compliance review specifically requires the plaintext at rest — see
[`getting-started-with-nafath.md`](getting-started-with-nafath.md)).

### 2. The `state` is short-lived — cache it, don't table it

The web flow's `state` only needs to survive between `start()` and `complete()`
— typically well under the transaction's 200-second window. A `nafath_verifications`
row can track the attempt's lifecycle; the state itself belongs in cache, keyed
so a callback can look it up by the `hashedState` Nafath posts back:

```php
use HamadaEmamTech\Nafath\Session\AuthorizeUrl;

$session = $nafath->web()->start($requestId, $request->ip());

Cache::put("nafath:state:{$session->hashedState}", [
    'state'      => $session->state,
    'tenant_id'  => $tenant->id,
    'request_id' => $requestId,
], now()->addMinutes(5));

Verification::create([
    'tenant_id'    => $tenant->id,
    'request_id'   => $requestId,
    'hashed_state' => $session->hashedState,
    'purpose'      => Purpose::login()->value,
    'status'       => VerificationStatus::Pending->value,
    'expires_at'   => now()->addSeconds(200),
]);
```

On the callback, Nafath posts the full `state`; digest it and look up the cache
entry — this is what `AuthorizeUrl::digest()` and `matches()` are for (see the
README trap table on why comparing `state` to `hashedState` directly fails
silently):

```php
$cacheKey = 'nafath:state:' . AuthorizeUrl::digest($request->input('state'));
$pending  = Cache::get($cacheKey) ?? abort(404);

abort_unless($pending['tenant_id'] === $tenant->id, 403); // never trust the callback's tenant

$result = $nafath->web()->complete($request->input('state'), $request->ip());
```

### 3. Persisting the outcome — with NIN uniqueness

Once `complete()` or `verifyCallbackToken()` returns a verified result, resolve
it against your `users` table. `identifierDigest()` gives you a peppered digest
safe to index and compare — never index the raw identifier.

```php
use HamadaEmamTech\Nafath\Identity\Claims;

function resolveUser(Tenant $tenant, Claims $claims): User
{
    $digest = $claims->identifierDigest(config('services.nafath.pepper'));

    // Multi-tenant: uniqueness is scoped per tenant, not global — the same
    // national ID legitimately exists as a separate customer in two tenants.
    // Single-tenant apps drop the tenant_id from this lookup and the
    // underlying unique index.
    return User::firstOrCreate(
        ['tenant_id' => $tenant->id, 'nin_digest' => $digest],
        [
            'name'        => $claims->fullNameAr,
            'user_type'   => $claims->userType->value,
            'nationality' => $claims->nationality,
        ],
    );
}
```

Back this with a **unique index** on `(tenant_id, nin_digest)` (or just
`nin_digest` single-tenant) — `firstOrCreate` alone is not race-safe under
concurrent callbacks, the database constraint is what actually prevents a
duplicate person record.

### 4. Audit trail — implement `CallRecorder`

```php
namespace App\Nafath;

use HamadaEmamTech\Nafath\Http\CallRecorder;
use App\Models\NafathCallLog;

final class DatabaseCallRecorder implements CallRecorder
{
    public function __construct(private readonly ?int $tenantId = null) {}

    public function record(array $entry): void
    {
        // MUST NOT throw — a broken audit write should never fail a live
        // verification. Swallow and let your error tracker catch it instead.
        try {
            NafathCallLog::create([...$entry, 'tenant_id' => $this->tenantId]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
```

Pass a tenant-bound instance in when you build `Nafath` per-request (see
below) — `reference` is Elm's own trace number, and is what you hand their
support desk instead of reconstructing an incident from application logs.

### 5. Multi-tenant wiring

Bind `Nafath` per-request, not as a global singleton, once credentials and the
recorder need to vary by tenant:

```php
$this->app->bind(Nafath::class, function () {
    $tenant = app(CurrentTenant::class)->get(); // however you resolve the current tenant
    $psr17  = new HttpFactory();

    return Nafath::make(
        appId:       $tenant->nafath_app_id,
        appKey:      $tenant->nafath_app_key,       // encrypted cast on the model
        environment: Environment::from($tenant->nafath_environment),
        serverIp:    config('services.nafath.server_ip'), // same server, shared across tenants
        http:        new Guzzle(['timeout' => 15]),
        requests:    $psr17,
        streams:     $psr17,
        cache:       cache()->store(),
        logger:      logger()->channel('stack'),
        recorder:    new DatabaseCallRecorder($tenant->id),
    );
});
```

Each tenant registers its **own** Rabet application and gets its own
`APP-ID`/`APP-KEY` pair per environment — see
[`getting-started-with-nafath.md`](getting-started-with-nafath.md). Don't share
one Nafath registration across tenants; Elm's allowlisting and support model
assumes one SP per registration, and it also means one tenant's traffic volume
never affects another's.

### 6. Single-tenant recap

Drop every `tenant_id` column, scope and constructor argument above and this is
the whole thing: one `nafath_verifications` table, one `nafath_call_logs`
table, a unique index on `nin_digest`, and the singleton binding from the top
of this guide. The step-by-step is the same; there's just one row of
credentials instead of one per tenant.
