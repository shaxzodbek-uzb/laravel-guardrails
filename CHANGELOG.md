# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
