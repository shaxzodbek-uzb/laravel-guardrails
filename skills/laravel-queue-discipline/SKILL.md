---
name: laravel-queue-discipline
description: This skill should be used when the agent writes or edits a queued job, mailable, notification, queued listener, or batch/chain — anything implementing ShouldQueue or dispatched via dispatch()/->dispatch()/Bus::batch()/Bus::chain(). Load it when it touches handle(), the job constructor/payload, $tries, $backoff, $timeout, $maxExceptions, $deleteWhenMissingModels, retryUntil(), failed(), ShouldBeUnique/uniqueId, WithoutOverlapping or RateLimited middleware, ->afterCommit(), or dispatches a job inside a DB::transaction; and when the user mentions queues, jobs, workers, Horizon, retries, idempotency, failed_jobs, duplicate jobs, race conditions, or "job ran before the row existed". Loads guardrails for idempotent, bounded, transaction-safe background jobs.
version: 1.0.0
license: MIT
---

# Queue & Job Discipline

Queued jobs run **at-least-once, out of process, and possibly more than once**. Code that assumes "exactly once, right now, with fresh data" corrupts data, double-charges customers, storms retries, or runs before the row it needs exists. This skill keeps jobs idempotent, bounded, and transaction-safe.

## The footgun

A job looks like a normal method, so it gets written like one — and four assumptions silently break in production:

- **It only runs once.** Workers crash, time out, and retry; a delivery can fire twice. A non-idempotent `charge()` double-charges. At-least-once delivery is the contract, so the job must be safe to run twice.
- **The row exists when it runs.** Dispatch a job from inside a `DB::transaction` and the worker can pick it up *before the transaction commits* — the model isn't in the database yet, and the job 500s or acts on stale data. A top real-world bug.
- **Retries are free.** No `$tries` / `$backoff` cap means a permanently failing job retries forever, hammering a downstream API and filling the queue — a retry storm.
- **The payload is just arguments.** Stuffing a huge collection or file into the constructor bloats the serialized payload in Redis/DB and slows every worker. Models are special-cased (only the key is serialized) but everything else is stored verbatim.

## Rules

1. **Make every job idempotent.** Running `handle()` twice must not double-charge, double-send, or duplicate rows. Guard with a status check, a unique constraint + `firstOrCreate`/`updateOrCreate`, or an idempotency key. Assume it *will* run twice.
2. **Dispatch after the transaction commits.** When dispatching inside a `DB::transaction`, use `SomeJob::dispatch(...)->afterCommit()`, or set `'after_commit' => true` on the queue connection in `config/queue.php`. Otherwise the worker may run before the row exists.
3. **Bound retries explicitly.** Set `public int $tries` (e.g. 3) or `public function retryUntil(): \DateTimeInterface`. Add `public $backoff = [10, 60, 300];` for incremental backoff, and `public int $maxExceptions` to stop after N errors even within `$tries`. Never leave retries unbounded.
4. **Set a timeout.** `public int $timeout = 120;` kills a hung job (requires the `pcntl` extension). Keep `$timeout` shorter than the worker's `--timeout`/`retry_after` so a job can't be retried while still running.
5. **Prevent duplicate/overlapping runs.** Implement `ShouldBeUnique` with `uniqueId()` and `public int $uniqueFor` to dedupe identical dispatches; use the `WithoutOverlapping` middleware to serialize jobs sharing a key (e.g. per-account). Use `ShouldBeUniqueUntilProcessing` if a new dispatch should be allowed once processing starts.
6. **Rate-limit external calls** with the `RateLimited` / `ThrottlesExceptions` middleware (returned from a `middleware()` method) instead of hammering a third-party API on every retry.
7. **Always handle failure.** Implement `failed(\Throwable $e): void` to clean up, mark state, and alert. Monitor the `failed_jobs` table and alert on growth; use **Horizon** (Redis) for visibility, metrics, and balancing.
8. **Keep payloads small — pass IDs, not blobs.** A job using the `SerializesModels` trait (bundled into the `Queueable` trait that `make:job` generates) serializes only a model's **key** and re-fetches it on run (fresh data — good). But a deleted model then throws `ModelNotFoundException`: set `public bool $deleteWhenMissingModels = true;` to discard the job instead. Never pass large arrays, file contents, or big collections into the constructor — pass an id/path and load inside `handle()`.
9. **Don't write one multi-hour job.** Chunk the work (`chunkById`) or use **batching** — `Bus::batch([...])->then()->catch()->finally()->dispatch()` — and **chaining** — `Bus::chain([...])->dispatch()` — for sequential steps. Batches give progress and partial-failure handling; chains stop on first failure.
10. **Route by latency.** Put slow/bulk jobs on a separate queue/connection from latency-sensitive ones, and run dedicated workers, so a backlog of exports doesn't delay password-reset emails.
11. **Avoid unserializable payloads.** No closures, no resources, no PDO/connection objects in the constructor.
12. **Test the job, don't just dispatch it.** `Queue::fake()` / `Bus::fake()` assert it was *queued*; also call `handle()` directly (or `dispatchSync`) to assert it does the right thing — and is idempotent when run twice.

## Good vs bad

### Idempotency + transaction-safe dispatch

```php
// ❌ dispatched mid-transaction (job may run before commit) and not idempotent
DB::transaction(function () use ($data) {
    $order = Order::create($data);
    ChargeCustomer::dispatch($order); // worker may pick this up before commit
});

class ChargeCustomer implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function handle(PaymentGateway $gw): void
    {
        $gw->charge($this->order->total); // retry → charges AGAIN
    }
}
```

```php
// ✅ dispatch after commit + idempotent charge keyed by the order
DB::transaction(function () use ($data) {
    $order = Order::create($data);
    ChargeCustomer::dispatch($order->id)->afterCommit();
});

class ChargeCustomer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];
    public int $timeout = 30;

    public function __construct(public int $orderId) {} // pass the id

    public function handle(PaymentGateway $gw): void
    {
        $order = Order::findOrFail($this->orderId);

        if ($order->charged_at !== null) {
            return; // already charged on a prior attempt — idempotent no-op
        }

        $gw->charge($order->total, idempotencyKey: "order-{$order->id}");
        $order->update(['charged_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        // alert / mark the order so a human can act
    }
}
```

### Dedupe overlapping dispatches

```php
// ✅ only one sync-per-account in flight; identical dispatches deduped for 1h
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncAccount implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->accountId))->releaseAfter(60)];
    }

    public function handle(): void { /* ... */ }
}
```

### Batching instead of one huge job

```php
// ✅ thousands of rows as a monitored batch, not a single multi-hour job
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

$jobs = User::query()
    ->where('digest', true)
    ->pluck('id')
    ->map(fn ($id) => new SendDigest($id));

Bus::batch($jobs)
    ->name('daily-digest')
    ->allowFailures()
    ->then(fn (Batch $b) => logger("digest done: {$b->processedJobs()} jobs"))
    ->catch(fn (Batch $b, \Throwable $e) => report($e))
    ->dispatch();
```

## How to verify

```bash
# 1. Queued jobs should set reliability knobs — find jobs missing $tries/$timeout
grep -rL "tries\|retryUntil" app/Jobs

# 2. Dispatches inside a transaction must use afterCommit (or after_commit config)
grep -rn "DB::transaction" app/ -A 15 | grep -i "dispatch(" | grep -v "afterCommit"

# 3. Critical jobs should implement failed()
grep -rL "function failed" app/Jobs

# 4. Fat payloads — constructors taking models/collections instead of ids
grep -rnE "__construct\(.*(Collection|array \\\$).*\)" app/Jobs

# 5. Run the suite (with Queue::fake()/Bus::fake() assertions)
./vendor/bin/pest    # or: php artisan test
```

Assert queueing and idempotency in tests:

```php
use Illuminate\Support\Facades\{Bus, Queue};

it('queues the charge after the order commits', function () {
    Queue::fake();

    $this->postJson('/orders', [/* ... */])->assertCreated();

    Queue::assertPushed(ChargeCustomer::class);
});

it('is idempotent — running twice charges once', function () {
    $order = Order::factory()->create(['charged_at' => null]);
    $gw = Mockery::spy(PaymentGateway::class);

    (new ChargeCustomer($order->id))->handle($gw);
    (new ChargeCustomer($order->id))->handle($gw); // second attempt

    $gw->shouldHaveReceived('charge')->once();
});
```

In production, alert when `failed_jobs` grows and watch Horizon's wait-time/throughput; a climbing failed count or wait time means a job is violating one of the rules above.

## When it's OK to bend the rule

- **`dispatchSync()` / `Bus::dispatchSync`** (run inline, no queue) is fine for tiny work or inside an already-async context — then transaction/`afterCommit` timing isn't a concern, but idempotency still is if it can be retried upstream.
- **`$tries = 1`** is correct for jobs that must *not* retry (e.g. a non-idempotent legacy call you can't make safe) — pair it with strong alerting on `failed()`.
- **Passing a whole model** (not just an id) is acceptable for small models when you specifically want `SerializesModels` to re-fetch fresh data on run — just set `$deleteWhenMissingModels` and keep the model small.

## References

- Queues — retries, timeout, backoff, `failed()`: https://laravel.com/docs/queues
- Unique jobs (`ShouldBeUnique`): https://laravel.com/docs/queues#unique-jobs
- Job middleware (`WithoutOverlapping`, `RateLimited`): https://laravel.com/docs/queues#job-middleware
- Dispatching after database transactions (`afterCommit`): https://laravel.com/docs/queues#dispatching-after-database-transactions-commit
- Job batching (`Bus::batch`): https://laravel.com/docs/queues#job-batching
- Job chaining (`Bus::chain`): https://laravel.com/docs/queues#job-chaining
- Laravel Horizon: https://laravel.com/docs/horizon
- Faking the queue in tests: https://laravel.com/docs/mocking#queue-fake
