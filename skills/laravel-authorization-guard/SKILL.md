---
name: laravel-authorization-guard
description: This skill should be used when the agent writes, edits, or reviews controllers, form requests, policies, gates, API resources, Livewire components, or route definitions that read or mutate a record belonging to a user or organization. Trigger on Gate::define, Gate::before, Gate::allows/denies, Auth::user()->can, $this->authorize, authorizeResource, Route::can / the "can" middleware, @can / @cannot Blade directives, make:policy, AuthServiceProvider, and on any find/findOrFail/route-model-binding followed by an update, delete, or state change. Also load when the user mentions "policy", "gate", "authorization", "permission", "role", "admin only", "403", "forbidden", "IDOR", "can this user", "ACL", or "who is allowed to".
version: 1.0.0
license: MIT
---

# Authorization Guard

Tenancy answers *whose data is this*. Authorization answers *is this particular user allowed to do this particular thing to it* — and it is a separate question with its own separate footguns. A perfectly tenant-scoped app still ships broken authorization: a member deleting the owner's records, a viewer editing a document, a support agent granting themselves admin.

This skill stops authorization that silently allows.

## The footgun

Laravel's authorization is opt-in at every layer, and almost every way of getting it wrong **fails open** — the request succeeds, no exception, nothing in the log:

- **A check nobody wrote.** `$post->update($request->validated())` with no `authorize()` above it is a working endpoint. Nothing warns you. The bug is invisible until someone finds it, because the happy path — an owner editing their own post — passes every test.
- **A policy method that does not exist.** Call `$this->authorize('publish', $post)` when `PostPolicy` has no `publish()` method and Laravel throws `AuthorizationException` — good. But `Gate::allows('publish', $post)` on an *unregistered* ability returns **false**, and `@can('publish')` renders nothing, so a missing policy looks like a working deny until you add a `Gate::before` that returns `true` for admins — and then the same missing method returns **true** for them. Absence of a rule is not a rule.
- **`Gate::before` that returns `false`.** Returning `false` from `Gate::before` **short-circuits every other check and denies**. Returning `null` is what "I have no opinion, keep going" means. A `return $user->isAdmin();` in `Gate::before` denies every ability to every non-admin, including their own records.
- **Authorizing the wrong thing.** `authorize('update', Post::class)` passes the *class*, which routes to the policy method's `$post` parameter as null-ish and checks a completely different question than `authorize('update', $post)`. Class-level is for `create`/`viewAny` only.
- **Checking after acting.** Validation, side effects, or an external API call before the `authorize()` line means an unauthorized caller has already changed something by the time they get a 403.
- **Roles that are mass-assignable.** `$user->update($request->all())` with `role` in `$fillable` is a one-request privilege escalation. So is a `role` column accepted by a form request that does not exclude it.
- **Auth in a place there is no auth.** `Auth::user()` inside a queued job, a console command, or an observer triggered by a job returns **null** — the job runs outside the request. A policy called from there authorizes against nobody.

Each of these produces a 200 response and correct-looking data for the person who wrote it.

## Rules

1. **ALWAYS authorize before you act, and before you validate side effects.** The `authorize()` call is the first statement in the controller method (or in the form request's `authorize()`). Nothing that writes, charges, emails, or calls an external service may run above it.

2. **NEVER rely on route-model binding for authorization.** Binding proves the record *exists*, not that this user may touch it. `Route::get('/posts/{post}', ...)` hands any authenticated user any post id. Pair every bound model with a policy check.

3. **Return `null` from `Gate::before`, never `false`, unless you mean "deny everything".** Use it only to grant:
   ```php
   Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
   ```
   A `Gate::before` returning `true` also **bypasses every policy method**, including `delete` and `forceDelete` — make sure that is what you want for that role.

4. **Write a policy method for every ability you check, and register the policy.** Laravel 11+ auto-discovers `App\Policies\PostPolicy` for `App\Models\Post`; anything off that convention needs an explicit `Gate::policy()` / `protected $policies` entry. A silently unmatched policy means `allows()` answers from the default, not from your rules.

5. **Use `authorizeResource()` for resource controllers, and know what it maps.** It binds `viewAny`/`view`/`create`/`update`/`delete` to the matching methods. `create` and `viewAny` receive **no model** — write them with the `$user` parameter only. If a controller has extra methods (`publish`, `archive`), authorize those explicitly; `authorizeResource` does not cover them.

6. **NEVER put `role`, `is_admin`, `permissions`, `team_id`, or `user_id` in `$fillable`.** Assign them explicitly in code after an authorization check. Prefer `$guarded = []` **only** with a strict form-request allowlist, and never with an `->all()` update.

7. **Authorize in the layer that owns the decision, not in the view.** `@can` controls what is *shown*; it is not a security boundary. Every `@can`-guarded action needs the same check server-side on the route that performs it.

8. **Do not authorize with `Auth::user()` outside a request.** Jobs, commands, listeners and observers must receive the acting user explicitly (`->can()` on a passed `$user`, or `Gate::forUser($user)->allows(...)`). A job that calls `Auth::user()` authorizes against `null`.

9. **Filter collections through the policy too.** A list endpoint that returns records the caller may not `view` is the same leak as an unauthorized show. Scope the query; do not fetch-then-filter in PHP, and never rely on the frontend to hide rows.

10. **Prefer `403` over `404` deliberately, and be consistent.** `authorize()` throws 403. If existence itself is confidential, use `findOrFail` on an already-scoped query so it is a genuine 404 — but pick one behaviour per resource and keep it, or the difference in status codes becomes the enumeration oracle you were trying to avoid.

11. **A `Response::deny()` message is user-visible.** `Response::deny('Only the workspace owner can remove members.')` is good UX; `Response::deny("user {$user->id} lacks role admin on team {$team->id}")` is an information leak. Keep denial reasons free of ids and internal role names.

## Good vs bad

### The check nobody wrote

```php
// ❌ any authenticated user can edit any post
public function update(UpdatePostRequest $request, Post $post)
{
    $post->update($request->validated());

    return redirect()->route('posts.show', $post);
}
```

```php
// ✅ authorize first, against the instance
public function update(UpdatePostRequest $request, Post $post)
{
    $this->authorize('update', $post);

    $post->update($request->validated());

    return redirect()->route('posts.show', $post);
}
```

Or push it into the form request, which runs before validation:

```php
final class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }
}
```

### `Gate::before` that denies everything

```php
// ❌ every non-admin is now denied every ability, including on their own records
Gate::before(fn (User $user) => $user->isAdmin());
```

```php
// ✅ null means "no opinion — consult the policy"
Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
```

### Authorizing the class instead of the record

```php
// ❌ asks "can this user update posts in general", not "may they update THIS post"
$this->authorize('update', Post::class);
```

```php
// ✅ instance for view/update/delete; class only for create/viewAny
$this->authorize('update', $post);
$this->authorize('create', Post::class);
```

### Privilege escalation through mass assignment

```php
// ❌ POST role=admin and you are an admin
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role'];
}

$user->update($request->all());
```

```php
// ✅ role is never mass-assignable; changing it is its own authorized action
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];
}

public function promote(User $user)
{
    $this->authorize('promote', $user);

    $user->role = Role::Admin;
    $user->save();
}
```

### Authorization inside a job

```php
// ❌ Auth::user() is null on the queue — this authorizes against nobody
class PublishPost implements ShouldQueue
{
    public function handle(): void
    {
        if (Auth::user()->can('publish', $this->post)) {
            $this->post->publish();
        }
    }
}
```

```php
// ✅ carry the actor, and authorize explicitly against them
class PublishPost implements ShouldQueue
{
    public function __construct(
        public readonly int $actorId,
        public readonly int $postId,
    ) {}

    public function handle(): void
    {
        $actor = User::findOrFail($this->actorId);
        $post = Post::findOrFail($this->postId);

        if (Gate::forUser($actor)->denies('publish', $post)) {
            return; // permissions changed between dispatch and run
        }

        $post->publish();
    }
}
```

### A list that leaks

```php
// ❌ returns every post, then hopes the view hides the wrong ones
return PostResource::collection(Post::latest()->paginate());
```

```php
// ✅ the query is the boundary
return PostResource::collection(
    Post::visibleTo($request->user())->latest()->paginate()
);
```

## Verification checklist

1. **Every write path has an authorize.** For each controller method that calls `create`, `update`, `delete`, `save`, `forceDelete`, or dispatches a state-changing job, confirm an `authorize()` / form-request `authorize()` / `can` middleware guards it. Grep for the write, not for the check — the check is what is missing.

2. **Every ability you check has a policy method.** Cross-reference each string passed to `authorize`, `can`, `allows`, `denies`, `@can` against the policy class. A checked ability with no method is a silent behaviour change the day someone adds `Gate::before`.

3. **`Gate::before` returns `null` or `true`, never `false`.** Grep `Gate::before` and read the return type.

4. **No privileged column is mass-assignable.** Grep `$fillable` and `$guarded` for `role`, `is_admin`, `permissions`, `*_id` of an owner/tenant. Grep for `->update($request->all())` and `->fill($request->all())`.

5. **No `Auth::` inside `app/Jobs`, `app/Console`, or observers.** Those run without a request.

6. **Test the negative case, not just the positive one.** A test that only proves the owner can edit proves nothing about authorization:
   ```php
   it('forbids a non-owner from updating a post', function () {
       $post = Post::factory()->create();

       $this->actingAs(User::factory()->create())
           ->put(route('posts.update', $post), ['title' => 'hijacked'])
           ->assertForbidden();

       expect($post->fresh()->title)->not->toBe('hijacked');
   });
   ```
   Assert the **record did not change**, not just the status code — a 403 with a completed write is still a breach.

7. **Test that a missing policy denies.** Add a case asserting an ability you have not defined is refused, so an accidental `Gate::before(true)` shows up as a failing test rather than a production hole.

8. **Style.** `vendor/bin/pint app/Policies app/Http` to keep the diff clean.

## When it's OK to bend the rule

- **A genuinely public endpoint** — a marketing page, a public post listing — needs no policy. Say so in a comment on the route so the absence reads as a decision rather than an omission.
- **`Gate::before` returning `false`** is correct for a hard global lockout: a suspended account or an org past its data-retention deadline should be denied everything. That is the one legitimate use.
- **Checking in the query instead of the policy** (rule 9) is not a bend — it is the preferred form for lists. Keep the policy for single-record paths so both layers agree.
- **`@can` without a server-side check** is acceptable only for purely cosmetic affordances that have no corresponding endpoint (a tooltip, a disabled-looking button that submits nothing).
- **Skipping `authorize` in an internal console command** is fine when the command is operator-only and documented as such — but not when it takes a user id from input and acts on their behalf.

## References

- Authorization — https://laravel.com/docs/12.x/authorization
- Gates, `Gate::before` / `Gate::after` — https://laravel.com/docs/12.x/authorization#gate-responses
- Creating & registering policies (auto-discovery) — https://laravel.com/docs/12.x/authorization#creating-policies
- Policy methods without models (`create`, `viewAny`) — https://laravel.com/docs/12.x/authorization#methods-without-models
- Authorizing resource controllers (`authorizeResource`) — https://laravel.com/docs/12.x/authorization#authorizing-resource-controllers
- `Gate::forUser()` — https://laravel.com/docs/12.x/authorization#supplying-additional-context
- Form request authorization — https://laravel.com/docs/12.x/validation#authorizing-form-requests
- Mass assignment protection — https://laravel.com/docs/12.x/eloquent#mass-assignment
- OWASP: Broken Object Level Authorization (API1:2023) — https://owasp.org/API-Security/editions/2023/en/0xa1-broken-object-level-authorization/
- Pest — HTTP tests & `actingAs` — https://pestphp.com/docs/plugins#laravel
