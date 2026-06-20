#!/usr/bin/env bash
#
# laravel-guardrails installer
#
# Copies the guardrail skills into a project's agent-skills directory.
# Works with Claude Code (.claude/skills), and the same files are portable to
# Cursor, Cline, Gemini CLI and any agent that reads SKILL.md.
#
# Usage (from your Laravel project root):
#
#   curl -fsSL https://raw.githubusercontent.com/shaxzodbek-uzb/laravel-guardrails/main/install.sh | bash
#
# Or, if you have the repo cloned:
#
#   ./install.sh [target-dir]      # default target: .claude/skills
#
# Env vars:
#   TARGET_DIR   override install location (default: .claude/skills)
#   REPO         override source repo (default: shaxzodbek-uzb/laravel-guardrails)
#   REF          git ref/branch/tag to install (default: main)

set -euo pipefail

REPO="${REPO:-shaxzodbek-uzb/laravel-guardrails}"
REF="${REF:-main}"
TARGET_DIR="${1:-${TARGET_DIR:-.claude/skills}}"

bold="\033[1m"; green="\033[32m"; yellow="\033[33m"; red="\033[31m"; dim="\033[2m"; reset="\033[0m"

say()  { printf "%b\n" "$1"; }
die()  { printf "%b\n" "${red}✗ $1${reset}" >&2; exit 1; }

# Resolve the source of the skills: either we're inside a checkout, or we fetch.
SCRIPT_SOURCE_SKILLS=""
if [ -d "$(dirname "$0")/skills" ]; then
  SCRIPT_SOURCE_SKILLS="$(cd "$(dirname "$0")/skills" && pwd)"
fi

cleanup() { [ -n "${TMP:-}" ] && rm -rf "$TMP"; }
trap cleanup EXIT

if [ -z "$SCRIPT_SOURCE_SKILLS" ]; then
  command -v git >/dev/null 2>&1 || die "git is required to fetch the skills"
  TMP="$(mktemp -d)"
  say "${dim}Fetching ${REPO}@${REF}…${reset}"
  git clone --depth 1 --branch "$REF" "https://github.com/${REPO}.git" "$TMP/repo" >/dev/null 2>&1 \
    || die "could not clone https://github.com/${REPO}.git@${REF}"
  SCRIPT_SOURCE_SKILLS="$TMP/repo/skills"
fi

[ -d "$SCRIPT_SOURCE_SKILLS" ] || die "no skills/ directory found to install from"

mkdir -p "$TARGET_DIR"

say "${bold}Installing laravel-guardrails skills → ${TARGET_DIR}${reset}\n"

count=0
for skill in "$SCRIPT_SOURCE_SKILLS"/*/; do
  [ -d "$skill" ] || continue
  name="$(basename "$skill")"
  dest="$TARGET_DIR/$name"
  if [ -d "$dest" ]; then
    say "  ${yellow}↻${reset} $name ${dim}(overwriting)${reset}"
    rm -rf "$dest"
  else
    say "  ${green}+${reset} $name"
  fi
  cp -R "$skill" "$dest"
  count=$((count + 1))
done

say "\n${green}✓ installed ${count} guardrail skill(s).${reset}"
say "${dim}Restart your agent (or run /doctor in Claude Code) so it picks up the new skills.${reset}"
