# Laravel

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

A bridge would add auto-discovery, a facade, a config stub and migrations — and
in exchange you would maintain a second package tracking every Laravel release,
for roughly the twenty lines above. The wiring is not the hard part of this
integration; the wire format is, and that lives in the core where every framework
benefits from it.

If your project needs persistence — sessions, stored identities, an audit trail —
own those tables yourself. They belong to your domain, not to a verification
library, and the shape differs for everyone.
