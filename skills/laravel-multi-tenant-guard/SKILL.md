---
name: laravel-multi-tenant-guard
description: This skill should be used when the agent works in a multi-tenant / SaaS Laravel app — any time it writes or edits a model, controller, action, Policy, route, job, listener, command, API Resource, cache key, or file path that touches tenant-owned data. Load it when it sees or adds tenant_id / team_id / account_id / organization_id / company_id columns, a BelongsToTenant trait, global scopes, route-model binding (`/{post}`), Rule::exists / exists validation on a foreign key, Filament panel tenancy (->tenant()), or queued jobs that query tenant data; and whenever the user mentions multi-tenant, tenancy, tenant isolation, cross-tenant leak, data leak, IDOR, stancl/tenancy, or spatie/laravel-multitenancy. Loads the guardrails that prevent one tenant from reading or mutating another tenant's data.
version: 1.0.0
license: MIT
---

# 🛡️ Multi-Tenant Guardrail

Prevents the single most expensive bug a SaaS can ship: a **cross-tenant data leak**. One missing `where tenant_id = ?`, one unscoped route binding, one job that lost its tenant context — and customer A reads, edits, or deletes customer B's data.

## The footgun

In a multi-tenant app every tenant-owned query must be scoped to the *current* tenant. Miss it once and you have a breach: an attacker (or just a buggy link) reaches another tenant's records. These leaks are silent — the code returns a 200 with the wrong data, no error, no log — and they pass review because the line looks like normal Eloquent.

The expensive lesson behind this skill: a production CRM where **almost** every controller action authorized correctly — 156 of 160. The other 4 skipped the check and leaked across tenants. "Almost all actions are covered" is exactly how breaches happen. Coverage must be **100%**, and it must be **enforced by the framework**, not by reviewer diligence. The rules below default to fail-closed: scope at the lowest layer, authorize every action, and make the dangerous path the one that requires explicit, visible opt-out.

## Rules

1. **NEVER trust a tenant identifier from request input** — not the body, query string, route parameter, or a client-set header. Derive the current tenant **only** from server-side authenticated context: the logged-in user's tenant, the resolved subdomain/domain, or a signed token verified on the server. Accepting `tenant_id` from input is a direct IDOR.
2. **Scope at the lowest layer so it cannot be forgotten.** Put a **global scope** on every tenant-owned model via a `BelongsToTenant` trait that (a) filters all queries by the current tenant and (b) auto-fills `tenant_id` on the `creating` event. A developer (or an AI agent) writing `Post::all()` then gets *only* this tenant's posts automatically — the safe default is the only default.
3. **`tenant_id` must NEVER be mass-assignable from user input.** Keep it out of `$fillable`; set it server-side (the trait does this on `creating`). Otherwise a crafted request reassigns a record to another tenant.
4. **Authorize EVERY action with a Policy** — and the policy must verify the record belongs to the current tenant, not merely that the user has a role. Use `authorizeResource()`, `$this->authorize()`, or `Gate`. Enforce 100% coverage with an architecture test (below) or a base controller that fails closed. A role check without an ownership check still leaks.
5. **Scope route-model binding.** `/posts/{post}` will happily resolve *another* tenant's post unless scoped. Rely on the global scope (so a foreign id throws `ModelNotFoundException` → 404) and/or use scoped bindings for nested routes (`->scopeBindings()` / `Route::scopeBindings()`). Never `Post::find($id)` straight from a route id without tenant scoping.
6. **Validate foreign keys scoped to the tenant.** When input carries a related id (`category_id`, `assignee_id`), validate it with `Rule::exists(...)->where('tenant_id', $tenantId)`. An unscoped `exists:categories,id` lets a tenant attach another tenant's row — relationship smuggling.
7. **Re-establish tenant context inside background work.** Jobs, queued listeners, notifications, scheduled commands, and exports run **outside the request**, where the "current tenant" is gone. Capture the tenant id at dispatch and restore it (set the current tenant) at the start of `handle()` **before any tenant-scoped query**. Otherwise the global scope runs with no tenant (returns everything) or the worker's leftover tenant (the previous job's) — a severe, easy-to-miss leak. Tenancy packages provide helpers (e.g. `tenancy()->initialize($tenant)`); if hand-rolled, set your container-bound current tenant explicitly.
8. **Namespace cache and rate-limit keys by tenant** — `"tenant:{$id}:dashboard"`, never a bare `"dashboard"`. A shared key serves one tenant's cached data to another.
9. **Scope file storage paths and signed URLs by tenant.** Store under `tenants/{id}/...` and never build a path or signed URL that another tenant can guess or enumerate.
10. **In Filament, use first-class tenancy** (`$panel->tenant(Team::class)`, the `HasTenants` contract, the ownership relationship, `getTenantQuery`) rather than rolling your own — then still apply the Policy + scoping rules above. Resource queries must remain tenant-scoped.
11. **Use a proven package unless you have a reason not to.** `stancl/tenancy` (multi-database / domain-based) and `spatie/laravel-multitenancy` (single or multi DB) are battle-tested. This skill is the **guardrails that apply whichever you use** — including a hand-rolled single-DB `tenant_id` column. Do not mandate a package; do enforce the rules.
12. **A cross-tenant isolation test is MANDATORY, not optional.** Every tenant-owned resource needs a test proving tenant B gets 403/404 — and no mutation — on tenant A's record (see *How to verify*). Treat a missing isolation test like a missing migration.

## Good vs bad

### The `BelongsToTenant` trait + global scope (the backbone)

```php
// app/Models/Scopes/TenantScope.php
namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // currentTenant() resolves from auth/subdomain — NEVER from request input
        if ($tenantId = app('currentTenant')?->id) {
            $builder->where($model->getTable().'.tenant_id', $tenantId);
        }
    }
}
```

```php
// app/Models/Concerns/BelongsToTenant.php
namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            // server-side only; ignores any tenant_id coming from input
            if (! $model->tenant_id && $tenant = app('currentTenant')) {
                $model->tenant_id = $tenant->id;
            }
        });
    }
}
```

```php
// ✅ every tenant-owned model just uses the trait — scoping is automatic
class Post extends Model
{
    use BelongsToTenant;

    protected $fillable = ['title', 'body']; // ❗ tenant_id NOT fillable
}
```

### Controller: leaking vs scoped + authorized

```php
// ❌ leaks: find() ignores tenant; no authorization; trusts route id blindly
public function show(int $id)
{
    return Post::find($id); // returns ANY tenant's post
}

// ❌ also leaks: "user is a manager" is not "this post is theirs"
public function update(Request $request, Post $post)
{
    abort_unless($request->user()->isManager(), 403);
    $post->update($request->all());
    return $post;
}
```

```php
// ✅ global scope makes binding tenant-safe (foreign id → 404),
//    and the Policy verifies ownership for THIS action
public function update(UpdatePostRequest $request, Post $post)
{
    $this->authorize('update', $post);     // PostPolicy::update checks tenant ownership
    $post->update($request->validated());  // tenant_id can't be reassigned
    return $post;
}
```

```php
// app/Policies/PostPolicy.php — ownership, not just role
public function update(User $user, Post $post): bool
{
    return $post->tenant_id === $user->tenant_id
        && $user->can('posts.update');
}
```

### Foreign-key smuggling

```php
// ❌ tenant B can pass tenant A's category_id and attach it
$request->validate(['category_id' => 'required|exists:categories,id']);
```

```php
// ✅ the related row must belong to the current tenant
use Illuminate\Validation\Rule;

$request->validate([
    'category_id' => [
        'required',
        Rule::exists('categories', 'id')->where('tenant_id', app('currentTenant')->id),
    ],
]);
```

### Background jobs lose tenant context

```php
// ❌ job runs with NO current tenant → global scope returns everything,
//    or reuses the worker's previous tenant → cross-tenant write
class GenerateReport implements ShouldQueue
{
    public function __construct(public int $reportId) {}

    public function handle(): void
    {
        $report = Report::find($this->reportId); // wrong/no tenant scope here
        // ... touches Post::all(), etc. — leaks across tenants
    }
}
```

```php
// ✅ carry the tenant id, re-establish it before any tenant-scoped query
class GenerateReport implements ShouldQueue
{
    public function __construct(public int $tenantId, public int $reportId) {}

    public function handle(): void
    {
        $tenant = Tenant::withoutGlobalScopes()->findOrFail($this->tenantId);
        app()->instance('currentTenant', $tenant); // restore context
        // (with a package: tenancy()->initialize($tenant);)

        $report = Report::findOrFail($this->reportId); // now correctly scoped
        // ...
    }
}
```

### The mandatory cross-tenant isolation test

```php
use App\Models\{Post, Tenant, User};

it('forbids reading or mutating another tenant\'s post', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $postA  = Post::factory()->for($tenantA)->create();
    $userB  = User::factory()->for($tenantB)->create();

    actingAs($userB);
    app()->instance('currentTenant', $tenantB);

    // hidden by the global scope → 404, not 403-with-leak
    $this->getJson("/api/posts/{$postA->id}")->assertNotFound();

    $this->putJson("/api/posts/{$postA->id}", ['title' => 'hacked'])
        ->assertNotFound();

    // and nothing was mutated
    expect($postA->fresh()->title)->not->toBe('hacked');
});
```

## How to verify

Run from the project root after any change to tenant-owned code.

```bash
# 1. Tenant-owned models must use the trait / a global scope. List models with a
#    tenant_id column, then confirm each uses BelongsToTenant.
grep -rln "tenant_id\|team_id\|account_id" database/migrations
grep -rL "BelongsToTenant" app/Models   # files MISSING the trait → audit each

# 2. Route ids resolved without tenant scoping (potential IDOR)
grep -rnE "::find\(|::findOrFail\(" app/Http/Controllers

# 3. Unscoped foreign-key validation (relationship smuggling)
grep -rn "exists:" app/Http/Requests app/Http/Controllers   # each must be tenant-scoped

# 4. tenant_id leaking into mass assignment
grep -rn "tenant_id" app/Models | grep -i "fillable"        # should be EMPTY

# 5. Jobs that query tenant data but never restore context
grep -rLn "currentTenant\|tenancy()->initialize" app/Jobs   # audit each that touches models

# 6. Bare (non-namespaced) cache keys
grep -rnE "Cache::(remember|put|get)\(" app/ | grep -v "tenant"

# 7. Run the suite — the isolation tests must pass
./vendor/bin/pest        # or: php artisan test
```

Enforce trait coverage with an architecture test so a new tenant model can't ship unscoped:

```php
// tests/Arch.php (Pest)
arch('tenant-owned models use BelongsToTenant')
    ->expect('App\Models')
    ->classes()
    // narrow this to your actual tenant-owned models, or invert via ->ignoring(...)
    ->toUseTrait('App\Models\Concerns\BelongsToTenant');
```

If you use `stancl/tenancy` or `spatie/laravel-multitenancy`, verify the bootstrappers/scoping are registered and that queued jobs are tenant-aware per that package's docs (both ship middleware/listeners for this) — but still keep the Policy + isolation-test rules; the package scopes data, it does not authorize actions for you.

## When it's OK to bend the rule

- **Central / shared models** (the `Tenant` table itself, global plans, system settings) are intentionally *not* tenant-scoped. Mark them clearly and access tenant rows on them with `withoutGlobalScopes()` only in vetted, central code (e.g. the job re-hydration above).
- **Super-admin / impersonation** flows legitimately cross tenants. Gate them behind an explicit ability, log every access, and never reuse the normal request path — make the cross-tenant capability loud and audited.
- **Multi-database tenancy** (`stancl/tenancy` domain mode) isolates at the connection level, so per-query `tenant_id` scoping may be redundant — but jobs/cache/storage context rules (7–9) still apply, and isolation tests are still mandatory.

## References

- Authorization & Policies: https://laravel.com/docs/authorization
- Global scopes (`addGlobalScope`, `Scope` contract): https://laravel.com/docs/eloquent#global-scopes
- Scoped route-model binding (`scopeBindings`): https://laravel.com/docs/routing#implicit-model-binding-scoping
- Validation `Rule::exists()->where(...)`: https://laravel.com/docs/validation#rule-exists
- Filament multi-tenancy: https://filamentphp.com/docs/panels/tenancy
- stancl/tenancy: https://tenancyforlaravel.com · spatie/laravel-multitenancy: https://spatie.be/docs/laravel-multitenancy
- Pest architecture tests (`toUseTrait`): https://pestphp.com/docs/arch-testing
