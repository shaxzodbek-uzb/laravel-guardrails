---
name: laravel-pint-formatting
description: This skill should be used whenever the agent generates, edits, or finishes a batch of PHP in a Laravel project — before presenting changes or committing. Load it when it touches code style, formatting, a pint.json, a pre-commit hook, or CI for PHP; when it runs or should run ./vendor/bin/pint (--dirty / --test / -v / --bail); or when the user mentions Pint, PHP-CS-Fixer, code style, formatting, lint, "match house style", or noisy diffs. Loads the guardrail that keeps AI-generated PHP formatted to the project's Pint standard so diffs stay clean and reviewable.
version: 1.0.0
license: MIT
---

# Pint Formatting

Keeps every PHP change formatted to the project's canonical style. AI-generated code that doesn't match house style produces noisy diffs, review bikeshedding, and merge conflicts. Laravel Pint settles it: one formatter, one standard, zero debate.

## The footgun

When generated code uses different spacing, import ordering, or brace placement than the rest of the codebase, the diff fills with cosmetic churn that hides the real change, reviewers argue style instead of substance, and unformatted code slips to `main` where it conflicts with the next person's formatter run. The fix is trivial and the agent's responsibility, not the human's: run Pint after writing PHP, every time, before showing or committing the change.

## Rules

1. **Run Pint after generating or editing any PHP, before presenting or committing.** `./vendor/bin/pint`. Treat unformatted output as an incomplete change.
2. **Use `--dirty` locally for speed.** `./vendor/bin/pint --dirty` formats only files with uncommitted changes according to Git — fast, and exactly the files you just touched. (In CI against a base branch, `--diff=origin/main` is the analogous narrowing.)
3. **Never hand-fight Pint.** If Pint reformats your code, that *is* the house style. Don't reformat against it or sprinkle ignore comments to win a style preference.
4. **Gate CI with `--test`.** `./vendor/bin/pint --test` makes **no changes** and exits non-zero if anything is unformatted — fail the build on style drift so it never reaches `main`.
5. **Default to the `laravel` preset; deviate only via `pint.json`, minimally.** Add a `pint.json` only to change the preset or toggle specific rules, and keep deviations few and justified. No `pint.json` = the sensible `laravel` preset.
6. **Use `--bail` / `-v` when diagnosing.** `-v` shows which rules changed which files; `--bail` is `--test` with fail-fast — it makes **no changes** and stops at the first file with a style error (a fast pre-flight check before the full `--test` run).
7. **Automate it.** A pre-commit hook running `pint --dirty` (or a CI auto-fix step) keeps humans from having to remember — but the `--test` gate is the non-negotiable baseline.

## Good vs bad

### Before / after Pint

```php
// ❌ unformatted: inconsistent spacing, brace, and import style
use App\Models\User ;
class Foo{
    public function bar(  ) {
        return  User::where('active',true)->get() ;
    }
}
```

```php
// ✅ after ./vendor/bin/pint — matches the laravel preset
use App\Models\User;

class Foo
{
    public function bar()
    {
        return User::where('active', true)->get();
    }
}
```

### A minimal, justified `pint.json`

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": { "sort_algorithm": "alpha" }
    }
}
```

> Keep it small. Every rule you add is a deviation reviewers must learn. If you don't need to deviate, ship no `pint.json` at all.

### CI gate (GitHub Actions)

```yaml
# .github/workflows/lint.yml
name: Lint
on: [push, pull_request]
jobs:
  pint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-interaction --prefer-dist
      - run: ./vendor/bin/pint --test   # fails the build on unformatted PHP
```

### Pre-commit hook (optional)

```bash
# .git/hooks/pre-commit  (chmod +x)
#!/usr/bin/env bash
./vendor/bin/pint --dirty
# Re-stage the .php files Pint may have reformatted (no-ops if none):
git diff --cached --name-only --diff-filter=ACM -- '*.php' | xargs -r git add
```

## How to verify

```bash
# Locally, format what you changed, then confirm the tree is clean:
./vendor/bin/pint --dirty
./vendor/bin/pint --test        # exits 0 when everything is formatted

# See exactly what (if anything) is non-compliant, with the rules involved:
./vendor/bin/pint --test -v
```

`./vendor/bin/pint --test` exiting `0` is the bar. In CI, a non-zero exit must block the merge.

## When it's OK to bend the rule

- **Generated or vendored files** you don't own (compiled assets, `vendor/`, framework stubs) can be excluded via `pint.json` `"exclude"` rather than formatted.
- **A one-off legacy file** mid-migration may be excluded temporarily — but track it and bring it under Pint, don't leave permanent carve-outs.
- **Disabling a specific rule** is fine when it genuinely fights a deliberate project convention — change it in `pint.json` (so it's shared and reviewable), never with scattered inline ignores.

## References

- Laravel Pint: https://laravel.com/docs/pint
- Pint presets & configuration (`pint.json`, `--dirty`, `--test`): https://laravel.com/docs/pint#configuring-pint
- PHP-CS-Fixer (Pint's engine) rule reference: https://github.com/PHP-CS-Fixer/PHP-CS-Fixer
- `shivammathur/setup-php` (CI): https://github.com/shivammathur/setup-php
