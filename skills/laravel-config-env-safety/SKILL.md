---
name: laravel-config-env-safety
description: This skill should be used when the agent calls env(), edits files under config/, touches .env or .env.example, writes deploy or Dockerfile steps, or suggests config:cache, config:clear, optimize, optimize:clear, route:cache, or view:cache. Trigger on any new configuration value, on reading a secret or API key, on APP_DEBUG / APP_ENV / APP_KEY / APP_URL changes, on closures or dynamic values placed inside config files, and on service-provider or middleware code that reads configuration. Also load when the user mentions "env", "environment variable", "config cache", "works locally but not in production", "returns null in production", "APP_KEY", "APP_DEBUG", "secrets", "dotenv", or "deploy script".
version: 1.0.0
license: MIT
---

# Config & Env Safety

`env()` outside a config file returns **null** once `config:cache` has run. The code is correct, the variable is set, the deploy is green — and the value is gone. It is the most reliable way to ship a bug that cannot be reproduced locally, because locally nobody runs `config:cache`.

This skill stops configuration that evaporates in production, and secrets that leak when it does.

## The footgun

Laravel loads `.env` at boot and `env()` reads from it. `php artisan config:cache` serializes the whole merged config into one PHP file and **stops loading `.env` entirely** — that is the point, it is what makes the cache fast. From then on:

- **Every `env()` call outside `config/` returns its default, or `null`.** A `env('STRIPE_KEY')` in a service class becomes `null`. Nothing throws. The Stripe client is constructed with a null key and fails later, somewhere else, with an unrelated-looking error.
- **The failure is environment-shaped.** Local dev has no config cache, so it works. CI often has no config cache, so it passes. Only the production deploy — the one place that caches — is broken.
- **A closure in a config file makes the cache fatal, not silent.** `config:cache` runs `var_export()` over the config array; a `Closure` cannot be serialized, so the command dies with `Your configuration files are not serializable`. Better than silent, but it breaks the deploy for everyone, and the usual "fix" is to stop caching config — which is the wrong end of the problem.
- **`APP_DEBUG=true` in production is a credential dump.** The debug error page renders the stack trace *and the environment*: database password, mail password, API keys, `APP_KEY`. One uncaught exception on a public route is a full secret disclosure. It is not a hypothetical — it is one of the most common Laravel breaches there is.
- **Rotating `APP_KEY` invalidates data, not just sessions.** Every `Crypt::` value, every encrypted cast column, and every "remember me" token becomes undecryptable. Changing it to fix a session problem destroys encrypted columns permanently.
- **A stale cache outlives the code.** `bootstrap/cache/config.php` committed, baked into an image, or left behind by a failed deploy serves yesterday's configuration with today's code.

## Rules

1. **NEVER call `env()` outside `config/`.** Not in controllers, models, jobs, service providers' `boot()`, middleware, Blade, or tests. Add a config key and read `config('services.stripe.key')`. This is the single rule that prevents most of this skill's failures.

2. **Every `env()` in a config file gets a sensible default.** `env('QUEUE_CONNECTION', 'sync')` — so a missing variable is a documented fallback, not `null` propagating into a client constructor.

3. **NEVER put a closure, object, or resource in a config file.** `config:cache` serializes with `var_export()`; a closure makes it fail outright. Bind the dynamic thing in a service provider and let config hold only scalars, arrays, and strings.

4. **`APP_DEBUG=false` in every non-local environment. No exceptions.** Staging included — staging usually holds real-shaped secrets. To debug production, read the log; never flip debug on a public host.

5. **`APP_ENV=production` on production, and check it before destructive work.** Laravel's own confirmation prompts (`migrate --force`, `db:wipe`) key off it, and `App::isProduction()` is how your code refuses to seed, wipe, or send test mail.

6. **`APP_KEY` must be set, unique per environment, and never rotated casually.** Generate with `php artisan key:generate`. Rotating it invalidates every encrypted value; if you must rotate, decrypt-and-re-encrypt first with the old key available via `APP_PREVIOUS_KEYS`.

7. **`.env` is never committed; `.env.example` always is.** Every new variable gets an entry in `.env.example` with a placeholder — that file is the only documentation of what a deployment needs. Confirm `.env` is in `.gitignore` before adding anything to it.

8. **Deploys run `config:cache` (or `optimize`) — and always after the code is in place.** Cache in the release step, not the build step, if any value depends on the environment the container lands in. Serve nothing from a config cache built on a different host with a different `.env`.

9. **`bootstrap/cache/*.php` is generated, never committed.** A committed `config.php` shadows the real configuration in a way that reads as "the deploy didn't pick up my change".

10. **Secrets live in the environment (or a secret manager), not in `config/` defaults.** `env('API_KEY', 'sk_live_realkeyhere')` puts the real key in git forever, and the default silently masks a missing variable.

11. **Cache config in tests only if production does.** If the deploy caches, run at least one CI job with `config:cache` applied — that is the job that catches an `env()` call that slipped into application code.

## Good vs bad

### The bug that only happens in production

```php
// ❌ null after config:cache — and only after config:cache
final class StripeClient
{
    public function __construct()
    {
        $this->key = env('STRIPE_SECRET');
    }
}
```

```php
// ✅ config/services.php — the only place env() belongs
return [
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
```

```php
// ✅ and the class reads config, which the cache preserves
final class StripeClient
{
    public function __construct()
    {
        $this->key = config('services.stripe.secret');
    }
}
```

### A closure that breaks `config:cache`

```php
// ❌ LogicException: Your configuration files are not serializable.
return [
    'timezone' => fn () => Auth::user()?->timezone ?? 'UTC',
];
```

```php
// ✅ config holds the scalar; the provider holds the behaviour
// config/app.php
return ['timezone' => env('APP_TIMEZONE', 'UTC')];

// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->bind(TimezoneResolver::class, fn ($app) =>
        new TimezoneResolver(config('app.timezone'))
    );
}
```

### Environment checks

```php
// ❌ reads the raw env var, which is empty under a config cache
if (env('APP_ENV') === 'production') {
    // never true in production. exactly backwards.
}
```

```php
// ✅
if (App::isProduction()) {
    // ...
}
```

### A destructive command with no guard

```php
// ❌ one wrong --env away from wiping production
public function handle(): void
{
    Artisan::call('migrate:fresh --seed');
}
```

```php
// ✅ refuse in production, loudly
public function handle(): int
{
    if (App::isProduction()) {
        $this->error('Refusing to run against production.');

        return self::FAILURE;
    }

    $this->call('migrate:fresh', ['--seed' => true]);

    return self::SUCCESS;
}
```

### Deploy ordering

```bash
# ❌ caches config, then changes the code it was built from
php artisan config:cache
git pull && composer install --no-dev
php artisan migrate --force
```

```bash
# ✅ code first, then cache, and clear the old cache if the deploy can fail midway
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize        # config + route + view cache, after the code is final
```

## Verification checklist

1. **No `env()` outside `config/`.** This is the whole skill in one grep — run it and expect zero results:
   ```bash
   grep -rn --include=*.php "env(" app/ routes/ database/ resources/ bootstrap/ | grep -v "config/"
   ```
   Anything it finds is a value that will be `null` in production.

2. **No `env()` in Blade.** `grep -rn "env(" resources/views/`.

3. **`config:cache` succeeds.** Run it locally against a scratch environment; a `not serializable` error means a closure or object reached a config file:
   ```bash
   php artisan config:cache && php artisan config:clear
   ```

4. **Every new key is in `.env.example`.** Diff the variables referenced by `config/` against `.env.example`; a missing entry means the next person's deploy comes up misconfigured with no error.

5. **`.env` is ignored and untracked.** `git check-ignore -v .env` and `git ls-files --error-unmatch .env` (the latter should fail).

6. **`bootstrap/cache/*.php` is untracked.** `git ls-files bootstrap/cache/` should list only `.gitignore`.

7. **Debug is off outside local.** Assert it, so a bad `.env` fails a test rather than a customer:
   ```php
   it('never runs with debug enabled in production', function () {
       config()->set('app.env', 'production');

       expect(config('app.debug'))->toBeFalse();
   });
   ```

8. **One CI job runs with the config cached**, matching production, so an `env()` that slipped into app code fails the build instead of the deploy.

9. **Style.** `vendor/bin/pint config app` to keep the diff clean.

## When it's OK to bend the rule

- **`env()` in `config/`** is not a bend — it is the one correct place for it.
- **`env()` in `bootstrap/app.php` or a `.php` file that runs before the framework boots** is unavoidable and safe: it executes before any config cache is consulted. Keep it to the handful of values that genuinely need it.
- **`env()` in tests** is acceptable when the test itself sets the variable and the suite never runs with a config cache. Prefer `config()->set()` — it works either way.
- **Not caching config at all** is a legitimate choice for a low-traffic app that values a simpler deploy. Then this skill's central rule relaxes — but write that decision down, because the first person to add `config:cache` for performance will otherwise break the app in a way nobody can reproduce.
- **`APP_DEBUG=true` on a private, seeded, secret-free staging box** is fine. On anything reachable from the internet, or holding real credentials, it is not.

## References

- Configuration — https://laravel.com/docs/12.x/configuration
- Environment configuration & `env()` — https://laravel.com/docs/12.x/configuration#environment-configuration
- Configuration caching (`config:cache`, and why `env()` returns null) — https://laravel.com/docs/12.x/configuration#configuration-caching
- Debug mode — https://laravel.com/docs/12.x/configuration#debug-mode
- Encryption & `APP_KEY` / `APP_PREVIOUS_KEYS` — https://laravel.com/docs/12.x/encryption
- Deployment — optimization (`optimize`, `config:cache`, `route:cache`) — https://laravel.com/docs/12.x/deployment#optimization
- `App::isProduction()` / environment detection — https://laravel.com/docs/12.x/configuration#determining-the-current-environment
- Artisan — production confirmation prompts (`--force`) — https://laravel.com/docs/12.x/artisan
- Laravel Pint — https://laravel.com/docs/12.x/pint
