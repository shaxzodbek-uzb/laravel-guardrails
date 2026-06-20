---
name: laravel-pest-testing
description: This skill should be used when the agent writes or modifies a Laravel feature (controller, route, job, command, policy, model, notification, mail) and needs tests; when it adds or edits files under tests/ or a *Test.php / Pest test file; when it touches Pest.php, phpunit.xml, or a dataset; when it mocks or fakes (Http::fake, Queue::fake, Mail::fake, Mockery, ::shouldReceive); when it asserts on database state, status codes, redirects, jobs, or mail; when it deals with authorization (401/403/404) or time-dependent logic (now(), Carbon, travel, freezeTime); or when the user mentions Pest, "tests", "coverage", "datasets", "arch test", "factory", RefreshDatabase, flaky tests, over-mocking, or "test the happy path". Use it to ship behavior-driven, non-brittle tests that cover the unhappy path and authorization, not just the happy path.
version: 1.0.0
license: MIT
---

# Pest Testing Discipline

Features shipped untested — or "tested" with over-mocked suites that assert implementation details — break silently in production and rot on every refactor. This skill makes you write Pest 3/4 tests that prove *behavior*, cover the unhappy path and authorization, and survive refactoring.

## The footgun

Two failure modes, both expensive:

1. **No tests / happy-path-only tests.** The feature works in the demo, then in production a guest hits the endpoint, a tenant reads another tenant's data, a validation gap lets garbage in, or a 404 becomes a 500. Authorization holes (`403`/`404` paths that were never asserted) are how data leaks ship to prod.
2. **Brittle, over-mocked tests.** You mock your own `UserRepository`/service with `shouldReceive('save')->once()`, so the test passes even when the real query is wrong, and it explodes the moment anyone renames a method. The suite is green, gives false confidence, and is deleted in frustration during the next refactor — leaving you back at mode 1.

The fix is the same discipline either way: **fake the boundaries you don't own, exercise the code you do own, and assert observable outcomes** (DB rows, HTTP status, dispatched jobs/mail) — including the failure and authorization outcomes.

## Rules

1. **Write the test with the feature, not "later".** A controller/job/command/policy change is incomplete until it has a Pest test. Never report a feature done without a corresponding test asserting its behavior.
2. **Use Pest idioms.** Define cases with `it('...')` or `test('...')`; assert with the fluent `expect(...)`; share setup with `beforeEach()`. Do not write PHPUnit `class FooTest extends TestCase` unless the file already uses that style.
3. **Pick the right type.** HTTP/end-to-end behavior → Feature test (boots the framework, hits routes via `$this->get/post/...`). Pure logic with no framework/DB → Unit test. Default to a Feature test when in doubt — it catches more.
4. **Reset the database with a trait, via `uses()`.** Apply `RefreshDatabase` (transaction-wrapped) or `LazilyRefreshDatabase` (only migrates when a test touches the DB) in `tests/Pest.php` for the whole `Feature` directory, or `uses(RefreshDatabase::class)` at the top of a file. NEVER leave DB tests dependent on leftover state.
5. **Build data with factories, never hand-rolled inserts.** Use `User::factory()->create()` / `->make()` and relationship factories. Hand-built `DB::table()->insert([...])` or `new Model([...])->save()` in tests is brittle and skips casts/defaults — ban it.
6. **Deduplicate with datasets, not copy-paste.** When the same assertion runs over many inputs (valid/invalid payloads, role matrices), use `->with([...])` (inline or a named dataset in `tests/Datasets/`) instead of N near-identical tests.
7. **Fake the boundaries you don't own.** For anything external or async, use the facade fakes before the action: `Http::fake()`, `Queue::fake()`, `Bus::fake()`, `Mail::fake()`, `Notification::fake()`, `Event::fake()`, `Storage::fake()`. For time, use `$this->travel(...)`, `$this->travelTo(...)`, or `$this->freezeTime()` — never assert against an un-frozen `now()`.
8. **Do NOT mock your own models/services into meaninglessness.** Avoid `Mockery::mock(MyService::class)->shouldReceive(...)` and `Model::shouldReceive(...)` for code under test. Let the real code run against the test DB and the faked boundaries. Mock your own class only at a genuine seam you are *not* exercising (e.g. a slow third-party SDK wrapper).
9. **Assert outcomes, not internals.** Prefer `assertDatabaseHas` / `assertDatabaseMissing` / `assertModelExists`, `assertStatus` / `assertOk` / `assertCreated`, `assertJson` / `assertJsonPath`, `assertRedirect`, `Mail::assertSent`, `Notification::assertSentTo`, `Queue::assertPushed`, `Bus::assertDispatched`, `Storage::disk()->assertExists`. Do not assert "method X was called once" as a proxy for behavior.
10. **Always test the unhappy path AND authorization.** Every endpoint/action needs, at minimum: the happy path, a validation/failure path, and the auth paths — guest → `401`/redirect, wrong user/role → `403`, missing/foreign resource → `404`. For any security-sensitive feature add an explicit "another user cannot access/modify this" test. This is non-negotiable for anything touching ownership or tenancy.
11. **Add architecture tests as cheap, global guardrails.** Keep a `tests/Arch.php` (or arch cases in `Pest.php`) with the presets that fit: `arch()->preset()->php()` (bans `die`/`var_dump`/`dd`-style debug output and deprecated PHP functions), `arch()->preset()->security()` (bans `eval`, `extract`, `unserialize`, weak hashing/`md5`/`sha1`, insecure randomness, etc.), and `arch()->preset()->laravel()` (enforces Laravel conventions — including no `env()` outside config). Add project invariants like `arch('controllers')->expect('App\Http\Controllers')->toExtend('App\Http\Controllers\Controller')`.
12. **One behavior per test, descriptive name.** `it('blocks guests from the dashboard')`, not `it('works')`. If a test name needs "and", split it.
13. **Gate coverage in CI.** Run `./vendor/bin/pest --coverage --min=NN` to enforce a minimum, and `./vendor/bin/pest --type-coverage` (requires the type-coverage plugin) to enforce typed signatures. Treat new untested lines as a defect.
14. **Browser/E2E sparingly (Pest 4).** Pest 4 ships browser testing via `visit('/')->...->assertSee(...)` (Playwright-backed). Reserve it for true full-stack/JS flows; do not reimplement HTTP-layer assertions as slow browser tests.

## Good vs bad

```php
// tests/Feature/UpdatePostTest.php

use App\Models\Post;
use App\Models\User;

// ❌ over-mocked: tests that a method was called, not what happened.
//    Passes even if the wrong post is updated or auth is missing.
it('updates a post', function () {
    $service = Mockery::mock(App\Services\PostService::class);
    $service->shouldReceive('update')->once()->andReturnTrue();
    $this->app->instance(App\Services\PostService::class, $service);

    $this->put('/posts/1', ['title' => 'New'])->assertOk();
});
```

```php
// ✅ exercises real code against the test DB; asserts the outcome AND authz.
use App\Models\Post;
use App\Models\User;

it('lets the owner update their post', function () {
    $owner = User::factory()->create();
    $post  = Post::factory()->for($owner)->create(['title' => 'Old']);

    $this->actingAs($owner)
        ->put("/posts/{$post->id}", ['title' => 'New'])
        ->assertRedirect();

    $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'New']);
});

it('forbids a non-owner from updating the post', function () {
    $post     = Post::factory()->create(['title' => 'Old']);
    $attacker = User::factory()->create();

    $this->actingAs($attacker)
        ->put("/posts/{$post->id}", ['title' => 'Hacked'])
        ->assertForbidden(); // 403

    $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Old']);
});

it('blocks guests', function () {
    $post = Post::factory()->create();

    $this->put("/posts/{$post->id}", ['title' => 'New'])
        ->assertRedirect('/login'); // or assertUnauthorized() for an API
});
```

```php
// ❌ hits the real network (flaky, slow, may leak keys); asserts nothing useful.
it('notifies slack', function () {
    $resp = (new App\Services\Slack)->ping('deploy ok');
    expect($resp)->toBeArray();
});
```

```php
// ✅ fake the boundary you don't own; assert the request you sent.
use Illuminate\Support\Facades\Http;

it('posts the deploy message to slack', function () {
    Http::fake(['hooks.slack.com/*' => Http::response(['ok' => true])]);

    (new App\Services\Slack)->ping('deploy ok');

    Http::assertSent(fn ($request) =>
        $request->url() === 'https://hooks.slack.com/services/T/B/x'
        && $request['text'] === 'deploy ok'
    );
});
```

```php
// ❌ copy-pasted near-identical validation tests.
it('rejects empty email', function () {
    $this->post('/register', ['email' => ''])->assertSessionHasErrors('email');
});
it('rejects bad email', function () {
    $this->post('/register', ['email' => 'nope'])->assertSessionHasErrors('email');
});
```

```php
// ✅ one test, table-driven with a dataset.
it('rejects invalid emails', function (string $email) {
    $this->post('/register', ['email' => $email])
        ->assertSessionHasErrors('email');
})->with([
    'empty'       => '',
    'no at-sign'  => 'nope',
    'no domain'   => 'a@',
]);
```

```php
// ✅ async + time boundaries faked; assert the dispatch and the side effect.
use App\Jobs\SendReminder;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('queues a reminder and welcomes the user on signup', function () {
    Queue::fake();
    Mail::fake();
    $this->freezeTime();

    $this->post('/register', [
        'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-pass',
    ])->assertRedirect();

    Queue::assertPushed(SendReminder::class);
    Mail::assertSent(WelcomeMail::class, fn ($m) => $m->hasTo('ada@example.com'));
    $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
});
```

```php
// tests/Arch.php — ✅ cheap global guardrails
arch()->preset()->php();        // bans die/var_dump/dd-style debug output + deprecated PHP fns
arch()->preset()->security();   // bans eval, extract, unserialize, md5/sha1, weak randomness, etc.
arch()->preset()->laravel();    // enforces Laravel conventions, incl. no env() outside config

arch('controllers extend the base controller')
    ->expect('App\Http\Controllers')
    ->toExtend('App\Http\Controllers\Controller');

arch('models live in the right namespace')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');
```

## How to verify

Run these before claiming a feature + its tests are done:

```bash
# 1. The whole suite must be green.
./vendor/bin/pest

# 2. Run just the new/changed file(s) while iterating.
./vendor/bin/pest tests/Feature/UpdatePostTest.php

# 3. Enforce a coverage floor (requires Xdebug or PCOV).
./vendor/bin/pest --coverage --min=80

# 4. Type coverage (requires pestphp/pest-plugin-type-coverage).
./vendor/bin/pest --type-coverage --min=100

# 5. Style stays clean.
./vendor/bin/pint --test
```

Then audit that you did NOT reintroduce the footgun:

```bash
# Authz coverage: each protected feature test should assert a 401/403/404 path.
grep -rEn "assertForbidden|assertUnauthorized|assertNotFound|->assertStatus\((401|403|404)\)" tests/

# Over-mocking smell: mocking your OWN app classes is a red flag — review each hit.
grep -rEn "Mockery::|->shouldReceive\(|::partialMock\(|::spy\(" tests/

# Hand-rolled inserts instead of factories — replace with ->factory().
grep -rEn "DB::table\(.*\)->insert|new App\\\\Models" tests/

# External boundaries must be faked — confirm fakes exist where code calls out.
grep -rEn "Http::fake|Queue::fake|Bus::fake|Mail::fake|Notification::fake|Storage::fake|Event::fake" tests/

# The security + php arch presets (the cheap global guardrails) must be present somewhere.
grep -rEn "preset\(\)->(security|php|laravel)\(\)" tests/
```

Assert in tests, not just code: for every new endpoint confirm there is (a) a happy-path outcome assertion (`assertDatabaseHas`/`assertOk`/`assertJsonPath`), (b) at least one failure/validation assertion, and (c) the guest + wrong-user authorization assertions. If any is missing, the feature is not done.

## When it's OK to bend the rule

- **Mocking your own code at a true external seam.** A thin wrapper around a paid third-party SDK (SMS, payment gateway) that you cannot fake at the HTTP layer is a legitimate `Mockery` target — you are isolating *their* dependency, not faking the behavior under test.
- **Unit-testing pure logic without the DB.** Value objects, formatters, and calculators need no `RefreshDatabase` and no factories — instantiate them directly and `expect()` the result; that is faster and correct.
- **`make()` over `create()`** when a test never persists — `User::factory()->make()` avoids a DB write for pure-logic assertions.
- **Lower coverage thresholds on legacy code.** Set a realistic `--min` and ratchet it up over time rather than blocking all work; never let "100% or nothing" become an excuse to ship zero tests.
- **Browser tests for genuinely JS-driven flows** (Livewire/Inertia/Vue interactions, file pickers) — there, `visit()` is the right tool, not an over-reach.

## References

- Pest — Writing Tests, expectations, hooks: https://pestphp.com/docs/writing-tests
- Pest — Datasets: https://pestphp.com/docs/datasets
- Pest — Architecture Testing & presets (`security`, `laravel`, `toExtend`): https://pestphp.com/docs/arch-testing
- Pest — Coverage & `--min`: https://pestphp.com/docs/coverage
- Pest — Type Coverage plugin: https://pestphp.com/docs/type-coverage
- Pest v4 — Browser Testing (`visit()`, visual regression): https://pestphp.com/docs/browser-testing
- Laravel — HTTP Tests (status/JSON/redirect assertions): https://laravel.com/docs/testing
- Laravel — Database Testing (`RefreshDatabase`, factories, `assertDatabaseHas`): https://laravel.com/docs/database-testing
- Laravel — Mocking & facade fakes (`Http`, `Queue`, `Bus`, `Mail`, `Notification`, `Event`, `Storage`, time): https://laravel.com/docs/mocking
