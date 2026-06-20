---
name: laravel-migration-safety
description: This skill should be used when the agent writes, edits, or reviews files in database/migrations, generates a migration via make:migration, or runs/suggests migrate, migrate:fresh, migrate:refresh, migrate:rollback, or db:wipe. Trigger on Schema::create / Schema::table / Blueprint usage, on adding/dropping/renaming columns or indexes, on NOT NULL / nullable / default decisions, on foreign-key (constrained, foreignId, dropForeign) changes, on data backfills inside up(), on any down() method, and on deploy/CI steps that apply migrations to a populated or production database. Also load when the user mentions "migration", "schema change", "drop column", "rename column", "add index", "zero downtime", "expand contract", "table lock", "online DDL", "doctrine/dbal", or "lost data after deploy".
version: 1.0.0
license: MIT
---

# Migration Safety

Schema migrations are the one place where a single careless line drops a production column, NOT-NULL-fails on a populated table, or locks the busiest table for minutes. This skill stops migrations that cause downtime or irreversible data loss.

## The footgun

A migration that passed review locally — empty SQLite, three rows — runs against a 50M-row production table and:

- **Destroys data.** `migrate:fresh` / `migrate:refresh` / `db:wipe` DROP every table. Run on prod (or pointed at prod via `--database`) and the database is gone. `dropColumn` in the same deploy that stopped writing it means the data is unrecoverable if you need to roll back.
- **Takes the site down.** Adding an index or changing a column type can take an exclusive metadata/table lock for the entire operation. On a large table that is minutes of `ERROR 1205 Lock wait timeout` and 503s for every request that touches the table.
- **Half-applies and won't reverse.** MySQL/MariaDB auto-commit on every DDL statement, so a multi-statement migration that fails midway leaves a corrupt schema with no rollback. A missing or wrong `down()` means you cannot safely roll back at all.
- **Fails on deploy.** Adding a `NOT NULL` column with no default to a populated table errors instantly: existing rows have no value to put there.

These are deploy-time, customer-facing, sometimes data-loss-permanent failures. Treat every migration as if it runs against a populated production database under load.

## Rules

1. **ALWAYS write a `down()` that truly reverses `up()`.** Anonymous-class migrations (`return new class extends Migration`) still need `down()`. If `up()` adds a column, `down()` drops it; if `up()` creates a table, `down()` drops it. A `down()` that does not restore the prior schema is a broken rollback — worse than none because it looks safe.

2. **NEVER run `migrate:fresh`, `migrate:refresh`, or `db:wipe` outside local/testing.** They DROP ALL TABLES. Never put them in deploy scripts, CI against shared DBs, or Forge/Envoyer/Laravel Cloud deploy hooks. Production/staging deploys use `php artisan migrate --force` only.

3. **NEVER add a `NOT NULL` column without a default to a populated table.** Add it `->nullable()` or with `->default(...)`, deploy, backfill existing rows, then in a *separate* migration tighten to `NOT NULL`. A bare `$table->string('x')` on a non-empty table fails the deploy.

4. **Use expand/contract (two-phase) for every breaking schema change.** Deploy 1: additive change + code that writes to both old and new shape. Then backfill. Deploy 2 (or later): switch reads to the new shape. Deploy 3 (separate): remove the old column. **NEVER drop a column in the same deploy that stops using it** — a rollback to the previous release would crash against the dropped column.

5. **Prefer add-new + backfill + drop-old over renaming columns.** `renameColumn` is native in Laravel 11+ (no `doctrine/dbal` needed) but still takes a table lock on large tables, and a rename breaks any old running code mid-deploy. Renames are acceptable only on small tables in a maintenance window.

6. **Large-table DDL can lock the table for its entire duration.** Adding an index, changing a column type, or adding a non-nullable column on a big table may block reads/writes. For large production tables, use an online schema change tool (`pt-online-schema-change`, `gh-ost`) or run in a low-traffic maintenance window — do NOT just push the raw migration and hope.

7. **Do NOT backfill data inside schema migrations.** Put backfills in an idempotent artisan command or a queued/batched job using `chunkById`, so they are re-runnable and don't time out or hold a transaction open for minutes. Small static seed/lookup inserts (a handful of rows) inside a migration are fine.

8. **In migrations use the `DB` facade / query builder, NEVER Eloquent models.** Models drift over time; their casts, mutators, `$fillable`, global scopes, and observers fire unexpectedly and silently corrupt a backfill. A migration must behave identically forever regardless of today's model code.

9. **Make migrations idempotent where reasonable.** Guard with `Schema::hasTable()`, `Schema::hasColumn()`, and `Schema::hasIndex()` so a partial/re-run does not throw "column already exists".

10. **Keep each migration small and atomic.** Postgres wraps DDL in a transaction and auto-rolls-back on failure; **MySQL/MariaDB do NOT** — each DDL implicitly commits, so a failed multi-statement MySQL migration leaves a half-applied schema. One logical change per migration. You may set `public $withinTransaction = false;` to skip Laravel's wrapper (required for some Postgres ops like `CREATE INDEX CONCURRENTLY`), but this does not make MySQL DDL atomic.

11. **Name or `constrained()` your foreign keys, and drop the FK before the column.** Dropping a column that backs an FK fails until the constraint is dropped first (`$table->dropForeign([...])` / `dropConstrainedForeignId()`). Let Laravel infer the name via `constrained()` or set one explicitly so `down()` can drop it deterministically.

## Good vs bad

### Adding a required column to a populated table

```php
// ❌ fails instantly on any table that already has rows
public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->string('status'); // NOT NULL, no default -> error 1364 "Field 'status' doesn't have a default value" on a populated MySQL table
    });
}
```

```php
// ✅ nullable (or default) now; backfill separately; tighten later
public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->string('status')->default('pending');
        // or ->nullable() then backfill in a command, then a later migration sets NOT NULL
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropColumn('status'); // truly reverses up()
    });
}
```

### Renaming via expand/contract instead of dropping in place

```php
// ❌ same deploy drops the column the previous release still reads -> rollback crashes,
// and the data in `full_name` is gone for good
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->renameColumn('full_name', 'name'); // locks big tables; breaks in-flight requests
    });
}
```

```php
// ✅ DEPLOY 1: add the new column, app writes BOTH columns from here on
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('name')->nullable()->after('full_name');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('name');
    });
}
// Then: backfill `name` from `full_name` in a batched command (see below).
// DEPLOY 2: switch reads to `name`. DEPLOY 3 (separate): drop `full_name`.
```

### Backfill: artisan command with chunkById, not inside a migration

```php
// ❌ inside a migration: uses Eloquent (observers/casts fire), loads every row,
// holds the connection, and times out on large tables
public function up(): void
{
    foreach (\App\Models\User::all() as $user) { // OOM + observers + no resume
        $user->update(['name' => $user->full_name]);
    }
}
```

```php
// ✅ idempotent, resumable command using the DB facade + chunkById
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillUserName extends Command
{
    protected $signature = 'users:backfill-name';

    public function handle(): int
    {
        DB::table('users')
            ->whereNull('name')
            ->whereNotNull('full_name')
            ->orderBy('id')
            ->chunkById(1000, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['name' => $user->full_name]);
                }
            });

        return self::SUCCESS;
    }
}
```

### Idempotent guards and foreign keys

```php
// ❌ throws "column already exists" on re-run; dropForeign omitted so down() fails
public function up(): void
{
    Schema::table('posts', function (Blueprint $table) {
        $table->foreignId('author_id')->constrained('users');
    });
}

public function down(): void
{
    Schema::table('posts', function (Blueprint $table) {
        $table->dropColumn('author_id'); // ❌ FK still references it -> error
    });
}
```

```php
// ✅ guarded, and the FK is dropped before the column
public function up(): void
{
    if (! Schema::hasColumn('posts', 'author_id')) {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->constrained('users');
        });
    }
}

public function down(): void
{
    Schema::table('posts', function (Blueprint $table) {
        $table->dropConstrainedForeignId('author_id'); // drops FK + column together
    });
}
```

## How to verify

Run these after writing or editing any migration. All paths are relative to the project root.

1. **No destructive commands in app/deploy/CI.** Grep the whole repo (excluding vendor); any hit outside local-only docs is a red flag:
   ```bash
   grep -rEn 'migrate:fresh|migrate:refresh|db:wipe' \
     --include='*.php' --include='*.sh' --include='*.yml' --include='*.yaml' \
     . | grep -v vendor/
   ```
   Deploy hooks must use `php artisan migrate --force` only.

2. **Every migration has a real `down()`.** List migrations and confirm each defines `down()`; open new ones and check `down()` reverses `up()` line-for-line:
   ```bash
   for f in database/migrations/*.php; do
     grep -q 'function down' "$f" || echo "MISSING down(): $f";
   done
   ```

3. **No bare NOT NULL adds on existing tables.** In any migration that `Schema::table(...)` (altering, not creating), confirm new columns are `->nullable()` or `->default(...)`. Scan added columns:
   ```bash
   grep -rEn '\$table->(string|integer|bigInteger|boolean|text|date|dateTime|decimal|uuid|foreignId)\(' \
     database/migrations/ | grep -Ev 'nullable|default|->change|Schema::create' 
   ```
   Manually confirm each remaining hit is inside a `Schema::create` (new table) and not an alter on a populated table.

4. **No Eloquent models in migrations.** Should return nothing:
   ```bash
   grep -rEn 'App\\\\Models\\\\|use App\\Models|::create\(|::all\(|::find\(|::where\(' \
     database/migrations/
   ```
   Backfills must use `DB::table(...)`.

5. **No row-level data loops inside migrations.** Inspect for `foreach`, `->get()`, `->all()`, or `->update(` inside `up()`/`down()`; move them to a command/job.

6. **Round-trip the migration locally.** Against a disposable DB only:
   ```bash
   php artisan migrate && php artisan migrate:rollback && php artisan migrate
   ```
   It must run, reverse cleanly, and re-run — proving `down()` works and `up()` is repeatable.

7. **Test the backfill is idempotent.** In Pest, run the backfill command twice and assert the second run is a no-op (no duplicate writes, same final state):
   ```php
   it('backfills name idempotently', function () {
       $this->artisan('users:backfill-name')->assertSuccessful();
       $this->artisan('users:backfill-name')->assertSuccessful(); // re-runnable
       expect(DB::table('users')->whereNull('name')->whereNotNull('full_name')->count())->toBe(0);
   });
   ```

8. **Style.** `vendor/bin/pint database/migrations` to keep the diff clean.

## When it's OK to bend the rule

- **`migrate:fresh` / `migrate:refresh`** are correct and expected in local dev and the test suite (`RefreshDatabase`). The rule is about non-local/shared databases only.
- **Backfilling inside a migration** is acceptable when the table is guaranteed small and bounded (e.g. a lookup/config table you also seed), the write count is trivial, and it still uses the `DB` facade. Anything that scales with user/order volume goes in a job.
- **Skipping the nullable/default dance** is fine when the table is provably empty at deploy time (e.g. a brand-new table created earlier in the same release, or a feature not yet shipped).
- **`$withinTransaction = false`** is required, not optional, for Postgres `CREATE INDEX CONCURRENTLY` and similar — those cannot run inside a transaction.
- **A `down()` that throws `new RuntimeException('irreversible')`** is honest for genuinely one-way operations, but only after you have a tested backup/restore plan for that change; never use it to dodge writing a reversible migration.

## References

- Database: Migrations — https://laravel.com/docs/12.x/migrations
- Migration rollback / `migrate:fresh` / `db:wipe` — https://laravel.com/docs/12.x/migrations#rolling-back-migrations
- Renaming/dropping columns (native, no doctrine/dbal in 11+) — https://laravel.com/docs/12.x/migrations#renaming-columns
- Foreign key constraints / `constrained()` / `dropConstrainedForeignId()` — https://laravel.com/docs/12.x/migrations#foreign-key-constraints
- Laravel 11 upgrade guide (doctrine/dbal removal) — https://laravel.com/docs/11.x/upgrade
- Query builder `chunkById` — https://laravel.com/docs/12.x/queries#chunking-results
- Artisan commands — https://laravel.com/docs/12.x/artisan
- Pest — testing artisan commands — https://pestphp.com/docs/plugins#laravel
- MySQL statements that cause an implicit commit (DDL is non-transactional) — https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html
