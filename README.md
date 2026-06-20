<h1 align="center">laravel-guardrails</h1>

<p align="center">
  <strong>Production guardrail skills for AI-assisted Laravel.</strong><br>
  Stop the classic footguns — cross-tenant data leaks, N+1 meltdowns, destructive migrations —<br>
  <em>before</em> your AI agent ships them.
</p>

<p align="center">
  <a href="https://github.com/shaxzodbek-uzb/laravel-guardrails/actions/workflows/validate-skills.yml"><img src="https://github.com/shaxzodbek-uzb/laravel-guardrails/actions/workflows/validate-skills.yml/badge.svg" alt="Validate skills"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License"></a>
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white" alt="Laravel 11/12/13">
  <img src="https://img.shields.io/badge/agents-Claude%20Code%20%C2%B7%20Cursor%20%C2%B7%20Cline%20%C2%B7%20Gemini%20CLI-8A2BE2" alt="Agent compatibility">
</p>

---

AI coding agents write Laravel fast — and they write the **classic Laravel footguns** just as fast. The query that looks fine with 10 rows and dies at 10,000. The migration that drops a column mid-deploy. The controller action that forgets `where tenant_id` and hands customer A's data to customer B.

`laravel-guardrails` is a curated pack of **[agent skills](https://docs.claude.com/en/docs/claude-code/skills)** — portable `SKILL.md` files — that teach your AI agent the production discipline that normally only comes from getting paged at 3am. Each skill names a specific, expensive mistake and gives the agent the rules, the good-vs-bad code, and the checks to **never ship it**.

These are **guardrails, not a tutorial.** They don't duplicate [`laravel/agent-skills`](https://github.com/laravel/agent-skills) (code review, starter kits, Cloud, Nightwatch) — they cover the day-to-day safety rails that the official pack leaves to you.

## The skills

| Skill | Stops you from… |
|---|---|
| 🛡️ **`laravel-multi-tenant-guard`** | Leaking data across tenants — missing scopes, IDOR via route binding, related-id smuggling, jobs that lose tenant context. *The flagship.* |
| 🗄️ **`laravel-eloquent-discipline`** | Mass-assignment holes, raw-SQL injection, unbounded `::all()` queries, silent table-wide updates. |
| 🚦 **`laravel-n-plus-one-guard`** | Lazy-loaded N+1 queries that pass in dev and melt the DB in prod. |
| 🧱 **`laravel-migration-safety`** | Destructive / table-locking migrations that cause downtime or data loss on a populated database. |
| ⏳ **`laravel-queue-discipline`** | Non-idempotent jobs, retry storms, dispatch-before-commit races, fat payloads. |
| 🧪 **`laravel-pest-testing`** | Untested features and brittle, over-mocked tests that check implementation instead of behavior. |
| ✨ **`laravel-pint-formatting`** | Noisy diffs and style bikeshedding — keep AI-generated code matching house style. |

Every skill is **version-resilient** (principles over API trivia, target: Laravel 11/12/13, PHP 8.2–8.4, Pest 3/4) and **portable** — plain `SKILL.md` with no agent-specific lock-in.

## Install

### One line (from your Laravel project root)

```bash
curl -fsSL https://raw.githubusercontent.com/shaxzodbek-uzb/laravel-guardrails/main/install.sh | bash
```

This copies the skills into `.claude/skills/`. Restart your agent (or run `/doctor` in Claude Code) and they'll be picked up automatically.

> Prefer to read before you pipe to bash? [The script is short.](install.sh) Or clone and run `./install.sh` yourself.

### With the `skills` CLI

```bash
npx skills add github.com/shaxzodbek-uzb/laravel-guardrails
```

### Manually

Copy any skill folder you want from [`skills/`](skills/) into your agent's skills directory:

```bash
# Claude Code (project-level)
cp -R skills/laravel-multi-tenant-guard .claude/skills/

# Cursor, Cline, Gemini CLI, etc. — point at that agent's skills/rules dir.
```

Take all seven, or cherry-pick the ones you need. They're independent.

## How it works

Skills are **model-invoked**: your agent reads each skill's `name` + `description` (a few hundred tokens total) and pulls the full skill into context **only when it's relevant** — when you ask it to write a migration, add a queued job, touch a tenant-scoped model, and so on. You don't invoke them by hand; they fire on the work.

That means zero overhead until they're needed, and no need to remember which rules apply — the agent matches the task to the guardrail.

## Compatibility

`SKILL.md` is an open, portable format. These work with **Claude Code**, **Cursor**, **Cline**, **Gemini CLI**, and any agent that reads agent-skills. Claude-specific frontmatter (if any) is safely ignored by other agents.

## Validate / contribute

Every skill is linted in CI for valid frontmatter and structure. Run the same check locally:

```bash
php scripts/validate-skills.php
```

PRs welcome — especially **corrections**. A wrong guardrail is worse than no guardrail. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Why we built this

We run multi-tenant Laravel SaaS in production at [Blaze](https://blaze.uz). Every guardrail here is a mistake we've made, caught in review, or been burned by — distilled so your agent doesn't have to learn it the hard way. The `multi-tenant-guard` skill in particular comes from a real incident: an app where *almost* every action was authorized, and the few that weren't leaked across tenants. "Almost" is how data leaks happen. These skills make the agent default to "all the way."

## License

[MIT](LICENSE) © Shaxzodbek Qambaraliyev / [Blaze](https://blaze.uz)
