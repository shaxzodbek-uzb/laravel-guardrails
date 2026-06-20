# Contributing to laravel-guardrails

Thanks for helping make AI-assisted Laravel safer. This repo is a curated set of
**guardrail skills** — opinionated, production-tested rules that stop agents (and
humans) from shipping the classic Laravel footguns.

## Philosophy

Every skill here earns its place by preventing a *specific, expensive mistake* —
a cross-tenant data leak, an N+1 that melts under load, a migration that drops a
column mid-deploy. We are not trying to teach Laravel from scratch; the official
docs and `laravel/agent-skills` do that. We catch the things that pass code
review and blow up in production.

A good guardrail skill:

- **Names the footgun** up front and why it's expensive.
- Gives a **clear DO / DON'T** with real, runnable code (good vs. bad).
- Tells the agent **how to verify** it didn't reintroduce the problem.
- Stays **version-resilient** — principles over API trivia. When an API is
  version-specific, say which version.
- Is **portable** — plain `SKILL.md`, no Claude-only frontmatter required to work.

## Adding or editing a skill

1. Each skill lives in `skills/<name>/SKILL.md`. The directory name **must** match
   the frontmatter `name` (kebab-case).
2. Frontmatter must include `name`, `description`, and `version`. The
   `description` is how agents decide *when* to load the skill — write it as
   trigger conditions, not a summary. Keep it under ~1024 characters.
3. Run the linter before opening a PR:

   ```bash
   php scripts/validate-skills.php
   ```

   CI runs the same check on every push and PR.

4. Keep claims accurate. If you cite a Laravel/Pest/Pint API that changed
   between versions, note the version. When in doubt, link the doc.

## Reporting issues

Found a guardrail that's wrong, outdated, or has a false positive? Open an issue
with the Laravel/PHP version and a minimal repro. Corrections are the most
valuable contribution here — a wrong guardrail is worse than no guardrail.

## License

By contributing you agree your work is released under the [MIT License](LICENSE).
