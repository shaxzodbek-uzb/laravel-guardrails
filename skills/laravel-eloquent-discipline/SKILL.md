---
name: laravel-eloquent-discipline
description: This skill should be used when the agent writes or edits Eloquent models, controllers, actions, jobs, or repositories — specifically when it calls create(), update(), fill(), forceFill(), all(), get(), whereRaw, orderByRaw, selectRaw, DB::raw, or mass update()/delete(); when it touches $fillable, $guarded, $casts, $appends, unguard(), updateOrCreate, firstOrCreate, or upsert; or when it passes request()->all()/$request->all() into a model. Also load when reviewing migrations-to-model mapping, dealing with money/decimal/boolean/date/enum/JSON columns, exporting or iterating large tables, or wiring user-supplied sort/filter parameters into a query. Keywords — mass assignment, MassAssignmentException, SQL injection, casts, fillable, guarded, unbounded query, memory exhaustion, chunk, cursor, lazy, paginate.
version: 1.0.0
license: MIT
---

# Eloquent Discipline

Eloquent makes the wrong thing easy: mass-assign a hidden column, leak an untyped value, scan a million rows into memory, or interpolate a user string straight into SQL. This skill keeps every model interaction safe, typed, bounded, and injection-proof.

## The footgun

Four classes of expensive failure all live in routine Eloquent code:

- **Mass assignment** — `User::create($request->all())` lets an attacker POST `is_admin=1`, `role=owner`, or `account_id=<victim>`. One extra form field becomes privilege escalation or cross-tenant data theft. Passes review because the line looks normal.
- **Missing/implicit casts** — a `decimal` money column read back as a string fails `===` comparisons and rounding; a `tinyint` flag read as `"0"` is truthy; a JSON column comes back as a raw string. These bugs are silent until a payment is off by a cent or a "disabled" feature stays on.
- **Unbounded queries** — `Model::all()` or `->get()` on a growing table loads every row into PHP memory. It works in dev with 50 rows and OOM-kills the worker in prod with 5 million. The query that scaled yesterday is the outage today.
- **Raw SQL + silent mass writes** — interpolating user input into `whereRaw("name = '$name'")` is textbook SQL injection. `Model::query()->delete()` with a forgotten `where()` wipes the whole table in one statement, no confirmation.

## Rules

1. **NEVER pass `request()->all()` / `$request->all()` into `create()`, `update()`, `fill()`, or `forceFill()`.** Pass `$request->validated()` from a FormRequest, or an explicit, hand-built array of known keys. Validated data is the only mass-assignment-safe source.
2. **NEVER call `Model::unguard()`, `Model::reguard()`, or `Eloquent::unguard()` in application code.** It disables mass-assignment protection process-wide. (Seeders are framework-internal and already unguarded — do not add your own.)
3. **Prefer explicit `$fillable`.** Avoid `$guarded = []` (allow-everything) unless input is *always* a validated DTO/array. If you use `$guarded`, list the dangerous columns (`id`, `*_id` foreign keys, `is_admin`, `role`, `tenant_id`) explicitly. `$fillable` is fail-closed; `$guarded = []` is fail-open.
4. **Always declare explicit `$casts`** for every non-string column: `'price' => 'decimal:2'`, `'is_active' => 'boolean'`, `'published_at' => 'datetime'`, `'meta' => 'array'` (or `AsArrayObject::class`), `'status' => Status::class` (enum), `'secret' => 'encrypted'`. Laravel 11+ supports the `protected function casts(): array` method — use either form, but be explicit. Never rely on implicit string casts.
5. **NEVER interpolate user input into `whereRaw`, `orderByRaw`, `havingRaw`, `selectRaw`, `DB::raw`, or `DB::statement`.** Use bindings: `whereRaw('price > ?', [$min])`. For raw column names you cannot bind (e.g. `orderBy` direction/column), validate against an explicit allow-list before use.
6. **NEVER run `Model::all()` or `->get()` on an unbounded table.** Use `paginate()`/`simplePaginate()` for UI, `cursor()` or `lazy()` for read-only streaming, and `chunkById()` for batch writes. `chunk()` is unsafe while modifying the chunked column — use `chunkById()` there.
7. **Select only the columns you need** with `select([...])` / `->select(...)`, especially before `cursor()`/`get()`. Hydrating wide rows you never read wastes memory and time.
8. **Scope every mass write.** A bare `Model::query()->update([...])` or `->delete()` hits every row. Always chain a `where()`; if a query genuinely targets all rows, make it loud (a comment + a guard) so review can see it is intentional.
9. **Use `findOrFail` / `firstOrFail` in controllers**, not `find()` + manual null check. They throw `ModelNotFoundException` → clean 404 via the handler. Even better, use route-model binding.
10. **`updateOrCreate` / `firstOrCreate` are racy.** Two concurrent requests can both pass the "find" and both insert. Back them with a **unique index** on the lookup columns, and prefer `upsert()` for bulk idempotent writes. Wrap multi-step create-then-related logic in a `DB::transaction`.
11. **Beware accessors and `$appends` that run queries.** An appended attribute that lazy-loads a relation triggers N+1 on every serialized model. Keep accessors pure; eager-load instead. (See `laravel-n-plus-one-guard`.)
12. **Keep models lean** — push business logic into actions/services. Secondary to safety, but a 600-line model hides the footguns above.

## Good vs bad

### Mass assignment

```php
// ❌ attacker POSTs is_admin=1 / tenant_id=<victim> and it sticks
public function store(Request $request)
{
    return User::create($request->all());
}
```

```php
// ✅ only validated, known keys reach the model
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
        ];
    }
}

public function store(StoreUserRequest $request)
{
    // is_admin / role / tenant_id are NOT in validated() → cannot be set
    return User::create($request->validated());
}
```

### Casts

```php
// ❌ price comes back as "19.99" (string), is_active as "1", meta as raw JSON string
class Product extends Model
{
    protected $fillable = ['name', 'price', 'is_active', 'meta'];
    // no $casts → silent type bugs in money math and boolean checks
}
```

```php
// ✅ typed values everywhere; money compares and rounds correctly
class Product extends Model
{
    protected $fillable = ['name', 'price', 'is_active', 'meta', 'status'];

    protected function casts(): array
    {
        return [
            'price'     => 'decimal:2',
            'is_active' => 'boolean',
            'meta'      => 'array',
            'status'    => ProductStatus::class, // enum cast
        ];
    }
}
```

### Raw SQL / sorting

```php
// ❌ SQL injection: ?sort=name; DROP TABLE products; --
$products = Product::query()
    ->whereRaw("name LIKE '%{$request->q}%'")
    ->orderByRaw($request->input('sort'))
    ->get();
```

```php
// ✅ bindings for values, allow-list for identifiers
$sortable = ['name', 'price', 'created_at'];
$column   = in_array($request->input('sort'), $sortable, true)
    ? $request->input('sort')
    : 'created_at';
$direction = $request->input('dir') === 'asc' ? 'asc' : 'desc';

$products = Product::query()
    ->where('name', 'like', '%'.$request->q.'%') // builder escapes the binding
    ->orderBy($column, $direction)
    ->paginate(20);
```

### Unbounded query

```php
// ❌ loads the entire table into memory → OOM at scale
foreach (Order::all() as $order) {
    ExportRow::dispatch($order);
}
```

```php
// ✅ streams one row at a time, selecting only needed columns
Order::query()
    ->select(['id', 'total', 'customer_id'])
    ->where('status', 'completed')
    ->lazy() // or ->cursor()
    ->each(fn (Order $order) => ExportRow::dispatch($order));

// ✅ for batch UPDATES, chunkById keeps a stable cursor while you write
Order::query()
    ->where('status', 'pending')
    ->chunkById(500, function ($orders) {
        foreach ($orders as $order) {
            $order->update(['status' => 'expired']);
        }
    });
```

### Mass write scope

```php
// ❌ forgot the where() → every order on the platform marked paid
Order::query()->update(['status' => 'paid']);
```

```php
// ✅ scoped, intentional, and idempotent at the DB level
Order::query()
    ->where('id', $orderId)
    ->where('customer_id', $customer->id) // tenant/owner scope
    ->update(['status' => 'paid']);
```

## How to verify

Run these from the project root after editing. Each should return nothing (or only intentional, reviewed hits).

```bash
# 1. Raw SQL: every hit must use bindings (?, [$x]) or an allow-list — never "{$var}" / . $var
grep -rn "whereRaw\|orderByRaw\|havingRaw\|selectRaw\|DB::raw\|DB::statement" app/

# 2. Mass-assignment from raw request input near a write
grep -rn "request()->all()\|\$request->all()" app/ \
  | grep -iE "create|update|fill"

# 3. Unguarding protection in app code (should be ZERO outside seeders)
grep -rn "unguard\|::reguard" app/

# 4. Unbounded reads — audit each ::all()/->get() on a model that grows
grep -rnE "::all\(\)|->all\(\)" app/
grep -rn "->get()" app/ | grep -iE "Model|::query"

# 5. Guarded-wide-open models — confirm input is always validated
grep -rn "guarded = \[\]\|guarded = \['\*'\]" app/Models

# 6. Static analysis (run whichever is installed)
./vendor/bin/phpstan analyse   # or: ./vendor/bin/pint --test
php artisan test               # or: ./vendor/bin/pest
```

Assert the guardrails in tests:

```php
it('rejects mass-assignment of protected columns', function () {
    $user = User::create([
        'name' => 'A', 'email' => 'a@b.test', 'is_admin' => true,
    ]);

    // not in $fillable → silently discarded, never assigned (stays null, not false)
    expect($user->is_admin)->toBeNull();
    expect($user->fresh()->is_admin)->not->toBe(true); // and never persisted as true
});

it('casts money to a comparable decimal', function () {
    $p = Product::factory()->create(['price' => 19.9]);

    expect($p->fresh()->price)->toBe('19.90'); // decimal:2 cast
});
```

Set `Model::preventSilentlyDiscardingAttributes()` (and optionally `preventAccessingMissingAttributes()`, or all three via `Model::shouldBeStrict()`) in `AppServiceProvider::boot()` for non-production so a mass-assign of an unknown column throws `MassAssignmentException` instead of being silently dropped — surfacing the footgun in tests. Note: with this enabled, the first test above would *throw* on the unknown `is_admin` rather than discard it, so assert the exception instead:

```php
it('throws on mass-assigning an unfillable column under strict mode', function () {
    expect(fn () => User::create([
        'name' => 'A', 'email' => 'a@b.test', 'is_admin' => true,
    ]))->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);
});
```

## When it's OK to bend the rule

- **`$guarded = []`** is acceptable for internal-only models whose input is *always* a validated DTO or a hand-built array you control (never request data) — e.g. import pipelines that map verified CSV columns.
- **`whereRaw` / `selectRaw`** are fine for DB functions and computed expressions (`selectRaw('SUM(total) as revenue')`) **as long as no part of the string comes from user input.** Constants and your own column names are safe.
- **`Model::all()` / `->get()`** is fine for small, bounded reference tables (roles, countries, settings) that cannot grow unboundedly. Document the assumption.
- **`find()` without `OrFail`** is correct when the absence of a record is a valid, handled branch (not a 404) — e.g. "create if missing" flows.

## References

- Mass assignment & `$fillable`/`$guarded`: https://laravel.com/docs/eloquent#mass-assignment
- Attribute casting (incl. `casts()` method, enum, encrypted, array): https://laravel.com/docs/eloquent-mutators#attribute-casting
- Chunking / `cursor` / `lazy` for large result sets: https://laravel.com/docs/eloquent#chunking-results
- Query builder bindings & raw expressions (`whereRaw`, `DB::raw`): https://laravel.com/docs/queries#raw-expressions
- `upsert` and bulk writes: https://laravel.com/docs/eloquent#upserts
- Strict model behavior (`preventSilentlyDiscardingAttributes`, `shouldBeStrict`): https://laravel.com/docs/eloquent#configuring-eloquent-strictness
- Form Request validation: https://laravel.com/docs/validation#form-request-validation
- Pest testing: https://pestphp.com/docs/writing-tests
