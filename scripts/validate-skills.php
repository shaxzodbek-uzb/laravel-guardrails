<?php

/**
 * validate-skills.php — a tiny, dependency-free linter for the SKILL.md files
 * in this repo. Runs in CI and locally (`php scripts/validate-skills.php`).
 *
 * It checks the things that actually break skill discovery and portability:
 *   - frontmatter exists and is well-formed (opening/closing `---`)
 *   - required fields are present (name, description)
 *   - `name` is kebab-case and matches its directory name
 *   - `description` fits the discovery budget (Claude reads ~100 tokens per
 *     skill from name + description, so we cap the raw length)
 *   - a `version` is declared (semver-ish)
 *   - the body below the frontmatter is non-empty
 *
 * No Composer, no autoload — pure PHP so the GitHub Action stays trivial.
 *
 * Exit code 0 = all good, 1 = at least one problem found.
 */

const DESCRIPTION_MIN = 40;
const DESCRIPTION_MAX = 1024; // ~roughly the discovery token budget; keep it tight.

$root = dirname(__DIR__);
$skillsDir = $root . '/skills';

$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$dim = "\033[2m";
$reset = "\033[0m";

if (!is_dir($skillsDir)) {
    fwrite(STDERR, "{$red}✗ skills/ directory not found at {$skillsDir}{$reset}\n");
    exit(1);
}

$skillFiles = glob($skillsDir . '/*/SKILL.md');
sort($skillFiles);

if (empty($skillFiles)) {
    fwrite(STDERR, "{$red}✗ no skills found under skills/*/SKILL.md{$reset}\n");
    exit(1);
}

$errors = 0;
$skillCount = 0;

echo "Validating " . count($skillFiles) . " skill(s) in {$skillsDir}\n\n";

foreach ($skillFiles as $file) {
    $skillCount++;
    $dirName = basename(dirname($file));
    $problems = [];

    $raw = file_get_contents($file);
    if ($raw === false) {
        $problems[] = 'could not read file';
        report($dirName, $problems, $errors, $red, $green, $reset, $dim);
        continue;
    }

    // Split frontmatter from body. Frontmatter must be the very first thing.
    if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) {
        $problems[] = 'missing or malformed YAML frontmatter (must open and close with --- on their own lines)';
        report($dirName, $problems, $errors, $red, $green, $reset, $dim);
        continue;
    }

    $frontmatter = parse_frontmatter($m[1]);
    $body = trim($m[2]);

    // name
    if (!isset($frontmatter['name']) || $frontmatter['name'] === '') {
        $problems[] = 'frontmatter missing required field: name';
    } else {
        $name = $frontmatter['name'];
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            $problems[] = "name '{$name}' is not kebab-case (lowercase, digits, single hyphens)";
        }
        if ($name !== $dirName) {
            $problems[] = "name '{$name}' does not match directory '{$dirName}'";
        }
    }

    // description
    if (!isset($frontmatter['description']) || $frontmatter['description'] === '') {
        $problems[] = 'frontmatter missing required field: description';
    } else {
        $len = mb_strlen($frontmatter['description']);
        if ($len < DESCRIPTION_MIN) {
            $problems[] = "description is too short ({$len} chars; aim for >= " . DESCRIPTION_MIN . ") — it should say WHEN to use the skill";
        }
        if ($len > DESCRIPTION_MAX) {
            $problems[] = "description is too long ({$len} chars; keep <= " . DESCRIPTION_MAX . " to fit the discovery budget)";
        }
    }

    // version (recommended, semver-ish)
    if (!isset($frontmatter['version']) || $frontmatter['version'] === '') {
        $problems[] = 'frontmatter missing recommended field: version';
    } elseif (!preg_match('/^\d+\.\d+\.\d+/', $frontmatter['version'])) {
        $problems[] = "version '{$frontmatter['version']}' is not semver-like (e.g. 1.0.0)";
    }

    // Real-YAML parseability. Our own parser is deliberately lenient, which once let
    // through a description containing ": " — valid to us, a nested-mapping error to a
    // real YAML parser, and therefore a skill no agent could load.
    foreach (unquoted_colon_fields($m[1]) as $key) {
        $problems[] = "field '{$key}' contains \": \" but is not quoted — a YAML parser reads that "
            . 'as a nested mapping and the whole skill fails to load. Wrap the value in "double quotes" '
            . 'or replace the colon with a dash.';
    }

    // body
    if ($body === '') {
        $problems[] = 'body below the frontmatter is empty';
    }

    report($dirName, $problems, $errors, $red, $green, $reset, $dim);
}

echo "\n";
if ($errors === 0) {
    echo "{$green}✓ all {$skillCount} skill(s) valid{$reset}\n";
    exit(0);
}

echo "{$red}✗ {$errors} skill(s) have problems{$reset}\n";
exit(1);

/**
 * Top-level fields whose unquoted value contains ": ".
 *
 * A plain YAML scalar may not contain a colon followed by a space — the parser reads it
 * as a nested mapping and errors with "Nested mappings are not allowed in compact
 * mappings". The skill then fails to load entirely, which is exactly the silent-breakage
 * this repo exists to prevent, so it is checked here rather than left to the agent.
 *
 * @return list<string>
 */
function unquoted_colon_fields(string $yaml): array
{
    $bad = [];
    foreach (explode("\n", $yaml) as $line) {
        if (!preg_match('/^([A-Za-z0-9_-]+):\s?(.*)$/', $line, $m)) {
            continue;
        }
        $value = trim($m[2]);
        if ($value === '') {
            continue;
        }
        // A quoted scalar can hold anything.
        $first = $value[0];
        if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
            continue;
        }
        if (str_contains($value, ': ')) {
            $bad[] = $m[1];
        }
    }

    return $bad;
}

/**
 * Minimal frontmatter parser for flat `key: value` scalars.
 * Strips matching surrounding quotes. We deliberately do not support nested
 * YAML — skills in this repo use flat scalar frontmatter only.
 */
function parse_frontmatter(string $yaml): array
{
    $out = [];
    foreach (explode("\n", $yaml) as $line) {
        if (trim($line) === '' || str_starts_with(trim($line), '#')) {
            continue;
        }
        // Only treat top-level (non-indented) `key: value` lines as fields.
        if (!preg_match('/^([A-Za-z0-9_-]+):\s?(.*)$/', $line, $m)) {
            continue;
        }
        $key = $m[1];
        $value = trim($m[2]);
        // Strip one layer of matching quotes.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $out[$key] = $value;
    }
    return $out;
}

function report(string $name, array $problems, int &$errors, string $red, string $green, string $reset, string $dim): void
{
    if (empty($problems)) {
        echo "{$green}✓{$reset} {$name}\n";
        return;
    }
    $errors++;
    echo "{$red}✗{$reset} {$name}\n";
    foreach ($problems as $p) {
        echo "  {$dim}- {$p}{$reset}\n";
    }
}
