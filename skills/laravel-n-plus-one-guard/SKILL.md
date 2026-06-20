---
name: laravel-n-plus-one-guard
description: This skill should be used when the agent writes or edits Eloquent queries, controllers, API Resources, Blade/Livewire/Filament views, or jobs that iterate over a model collection and access a relationship (e.g. `$post->author`, `$order->items`, `@foreach`/`v-for` over models touching a relation, `->comments->count()`); when it sees `->get()`, `->all()`, `->paginate()`, `with()`, `load()`, `whenLoaded()`, `withCount`, `chunk`, `cursor`, `lazy`, accessors that query, Filament table relation columns, or `modifyQueryUsing`; or when the user mentions N+1, "slow query", "too many queries", lazy loading, eager loading, Telescope/Debugbar query counts, or DB load/timeouts under traffic. Loads guardrails that turn silent 1+N lazy-load explosions into eager-loaded, count-aggregated, strict-mode queries.
version: 1.0.0
license: MIT
---

# N+1 Query Guard

Prevents the classic N+1 query explosion: code that loops over a collection of models and lazily reads a relationship, firing one query per row. It looks fine in dev with 5 rows and melts the database in production with 50,000.

## The footgun

You load 100 posts in 1 query, then in a loop touch `$post->author->name`. Eloquent silently fires 100 more queries — one per post. Same for `$post->comments->count()` in a Blade `@foreach`, or an API Resource that reads `$this->category` without it being loaded. The query count scales with row count, so it is invisible in development (tiny tables) and catastrophic under production load: connection-pool exhaustion, p99 latency spikes, DB CPU pinned, request timeouts, and a pager going off. The fix is almost always cheap (one `with(...)`), but the bug is silent by default — Eloquent will happily lazy-load forever and never warn you. The real fix is to make lazy loading **throw** in dev/test so it can never reach prod.

## Rules

1. **Eager load every relation you will read.** If a loop, Blade view, or Resource touches `$model->relation`, add it to the query: `Post::with('author', 'comments')->get()`. This collapses 1+N into 2 queries (one per relation level).
2. **Eager load nested relations with dot syntax.** Reading `$post->comments[i]->author` needs `with('comments.author')`, not just `with('comments')`.
3. **Constrain eager loads instead of filtering in PHP.** Use `with(['comments' => fn ($q) => $q->latest()->limit(5)])` rather than loading all comments and slicing — the constraint runs in SQL. Per-parent `limit()` on an eager load is honored natively in Laravel 11+ (window functions, formerly the `staudenmeir/eloquent-eager-limit` trait); on Laravel ≤10 a bare `limit()` caps the *combined* result set across all parents, so don't rely on it there.
4. **On an already-fetched collection, use `load()` / `loadMissing()`** to attach relations in a single extra query. `loadMissing()` is idempotent (skips relations already loaded); prefer it when unsure.
5. **Count/aggregate with `withCount` / `withSum` / `withAvg` / `withMax` / `withMin` / `withExists`, never `$model->relation->count()` in a loop.** `withCount('comments')` exposes `$post->comments_count` via a correlated subquery embedded in the parent SELECT (no extra query); calling `->count()` on an unloaded relation lazy-loads every row first. On an already-fetched collection use the `loadCount()` / `loadSum()` equivalents.
6. **Make lazy loading a LOUD error outside production.** In `AppServiceProvider::boot()` call `Model::preventLazyLoading(! $this->app->isProduction())`. This converts every silent N+1 into a `LazyLoadingViolationException` you catch in dev and CI before it ships.
7. **Prefer `Model::shouldBeStrict()` for new apps** — it bundles `preventLazyLoading()`, `preventSilentlyDiscardingAttributes()`, and `preventAccessingMissingAttributes()`. Gate it on `! isProduction()` so a missed eager load degrades gracefully (lazy-loads) in prod instead of 500-ing real users.
8. **For large datasets, bound memory with `lazy()` / `lazyById()` / `chunkById()` — and keep the eager loads.** `Post::with('author')->lazy()` streams in chunks AND eager-loads. Do NOT use `cursor()` if you access relations: `cursor()` runs a single query with no eager loading, so touching a relation reintroduces N+1.
9. **In controllers/actions, eager load BEFORE handing models to API Resources.** Resources are dumb mappers; they must not trigger queries.
10. **In API Resources, gate relations with `whenLoaded()`.** `'author' => new AuthorResource($this->whenLoaded('author'))` omits the relation when it was not eager-loaded instead of silently lazy-loading it per item.
11. **In Blade/Livewire/Filament, the relation must be loaded before the view.** `@foreach ($posts as $post) {{ $post->author->name }}` N+1s unless `$posts` arrived with `author`. Filament tables that show relation columns must eager load via `->modifyQueryUsing(fn ($query) => $query->with('author'))`.
12. **NEVER "fix" N+1 by adding an accessor that itself queries.** An accessor like `getAuthorNameAttribute()` that runs `User::find(...)` just hides the N+1 inside the model. Eager load the relation instead.

## Good vs bad

```php
// ❌ N+1: 1 query for posts + 1 query PER post for the author = 1+N
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name;          // lazy-loads author every iteration
    echo $post->comments->count();     // lazy-loads ALL comments just to count
}

// ✅ 2 queries total regardless of post count
$posts = Post::with('author')->withCount('comments')->get();
foreach ($posts as $post) {
    echo $post->author->name;          // already loaded (1 batched eager-load query)
    echo $post->comments_count;        // correlated subquery baked into the posts SELECT — no extra query
}
// Query 1: SELECT posts.*, (SELECT COUNT(*) ... ) AS comments_count FROM posts
// Query 2: SELECT * FROM users WHERE id IN (...)   ← the with('author') eager load
```

```php
// ❌ API Resource lazy-loads category + tags once per item in the collection
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'category' => new CategoryResource($this->category), // queries per item
            'tags'     => TagResource::collection($this->tags),  // queries per item
        ];
    }
}

// ✅ Resource only maps; relations are gated and must be eager-loaded by the caller
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'tags'     => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}

// caller (controller/action) does the eager loading:
return ProductResource::collection(
    Product::with('category', 'tags')->paginate()
);
```

```php
// ❌ cursor() with relation access = silent N+1 (cursor cannot eager load)
foreach (Order::cursor() as $order) {
    $total += $order->items->sum('price'); // one query PER order
}

// ✅ lazy() streams in chunks AND honors eager loads — bounded memory, no N+1
foreach (Order::with('items')->lazy() as $order) {
    $total += $order->items->sum('price'); // items already loaded
}
```

```php
// ❌ "fix" that hides N+1 inside an accessor
class Post extends Model
{
    public function getAuthorNameAttribute(): string
    {
        return User::find($this->user_id)->name; // queries on every access
    }
}

// ✅ no querying accessor — eager load the real relation and read it
$posts = Post::with('author')->get();
foreach ($posts as $post) {
    echo $post->author->name; // already loaded, no query
}
```

## How to verify

1. **Enable strict mode in tests so any N+1 throws.** In `AppServiceProvider::boot()`:
   ```php
   Model::preventLazyLoading(! $this->app->isProduction());
   ```
   Then run the suite — a lazy load now fails the test:
   ```bash
   ./vendor/bin/pest          # or: php artisan test
   ```
   A `LazyLoadingViolationException` on a previously green test is the smoking gun.

2. **Assert query counts in a feature test** for hot endpoints. The exact number is endpoint-specific (count the eager-load levels); the point is that it stays **constant** as you add rows — a growing count is the N+1:
   ```php
   use App\Models\Post;
   use Illuminate\Support\Facades\DB;

   Post::factory()->count(25)->create();

   DB::enableQueryLog();
   $this->getJson('/api/posts')->assertOk();

   // e.g. 1 posts query (with a withCount subselect) + 1 author eager-load = 2,
   // regardless of how many posts exist:
   expect(DB::getQueryLog())->toHaveCount(2); // NOT 1 + N
   ```

3. **Grep for relation access inside loops with no preceding eager load:**
   ```bash
   # Blade @foreach bodies touching a relation arrow on the loop var
   grep -rn "@foreach" resources/views | head
   grep -rEn '\$[a-z]+->[a-z]+->' resources/views app/Http
   # ->all()/->get() that may feed a relation-touching loop
   grep -rn '::all()' app/
   ```
   Each hit: confirm the source query has a matching `with(...)`.

4. **Confirm Resources never lazy-load:**
   ```bash
   grep -rn 'whenLoaded' app/Http/Resources   # should be present
   grep -rEn '\$this->[a-zA-Z]+\b' app/Http/Resources | grep -v whenLoaded
   ```
   The second command surfaces direct relation reads that bypass `whenLoaded`.

5. **Watch real query counts** with [Laravel Telescope](https://laravel.com/docs/telescope) (Queries tab), [barryvdh/laravel-debugbar](https://github.com/barryvdh/laravel-debugbar), or [beyondcode/laravel-query-detector](https://github.com/beyondcode/laravel-query-detector) (alerts on N+1 in dev). A query count that grows when you add seed rows confirms the footgun is still present.

## When it's OK to bend the rule

- **A relation you genuinely access on a single model**, not in a loop, is fine to lazy-load — N+1 needs the loop. `$post->author` on one post is one query.
- **`shouldBeStrict()` / `preventLazyLoading()` should be gated to non-production.** Leaving lazy loading *enabled* (graceful) in prod is the safer failure mode: a missed eager load degrades to extra queries instead of throwing a 500 at a paying user. Catch it in CI, not in front of customers.
- **`cursor()` is correct** when you stream a huge result set and touch NO relations (or only columns) — its single-query, one-model-in-memory design is the most memory-efficient option there.
- **Tiny, fixed-size relations** (e.g. a hasOne settings row on a handful of records) may not be worth the eager-load ceremony — but it is never *wrong* to eager load, so default to it.

## References

- [Eloquent Relationships — Eager Loading](https://laravel.com/docs/eloquent-relationships#eager-loading)
- [Eloquent Relationships — Lazy Eager Loading (`load`/`loadMissing`)](https://laravel.com/docs/eloquent-relationships#lazy-eager-loading)
- [Eloquent Relationships — Counting & Aggregating Related Models (`withCount`/`withSum`/`withExists`)](https://laravel.com/docs/eloquent-relationships#counting-related-models)
- [Eloquent Relationships — Preventing Lazy Loading](https://laravel.com/docs/eloquent-relationships#preventing-lazy-loading)
- [Eloquent: Getting Started — Chunking / `lazy()` / `cursor()`](https://laravel.com/docs/eloquent#chunking-results)
- [Eloquent API Resources — Conditional Relationships (`whenLoaded`)](https://laravel.com/docs/eloquent-resources#conditional-relationships)
- [Configuring Eloquent Strict Mode (`shouldBeStrict`)](https://laravel.com/docs/eloquent#configuring-eloquent-strictness)
- [Laravel Telescope](https://laravel.com/docs/telescope) · [laravel-debugbar](https://github.com/barryvdh/laravel-debugbar) · [laravel-query-detector](https://github.com/beyondcode/laravel-query-detector)
