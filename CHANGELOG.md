# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `laravel-authorization-guard` — policy/gate coverage: endpoints with no
  `authorize()`, `Gate::before` returning `false` (which denies everything),
  authorizing a class where an instance was meant, mass-assignable `role`
  columns, and `Auth::user()` inside a queued job, where it is always null.
- `laravel-config-env-safety` — `env()` called outside `config/` returns null
  once `config:cache` runs, so the app breaks only in the environment that
  caches. Also covers closures in config files breaking the cache outright,
  `APP_DEBUG=true` leaking every secret through the error page, and `APP_KEY`
  rotation destroying encrypted columns.

### Fixed

- `laravel-eloquent-discipline` had an unquoted `": "` inside its description,
  which a real YAML parser reads as a nested mapping. The frontmatter did not
  parse, so the skill could not load at all — the exact silent breakage this
  repo exists to prevent.
- `scripts/validate-skills.php` now rejects an unquoted frontmatter value
  containing `": "`. Its lenient parser accepted the broken description above,
  so the validator passed a skill no agent could load.

### Added

- Initial set of 7 production guardrail skills for AI-assisted Laravel:
  - `laravel-eloquent-discipline`
  - `laravel-migration-safety`
  - `laravel-n-plus-one-guard`
  - `laravel-pest-testing`
  - `laravel-pint-formatting`
  - `laravel-queue-discipline`
  - `laravel-multi-tenant-guard`
- `scripts/validate-skills.php` — dependency-free SKILL.md linter.
- GitHub Action that validates skill frontmatter on every push/PR.
- `install.sh` one-line installer (Claude Code, portable to other agents).
