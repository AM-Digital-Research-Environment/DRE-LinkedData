<?php
declare(strict_types=1);

/*
 * Writes every version declaration at once, dates the release and opens the
 * changelog's next Unreleased section. The only supported way to bump the
 * module:
 *
 *   composer bump-version -- patch                     0.6.0 -> 0.6.1
 *   composer bump-version -- minor                     0.6.0 -> 0.7.0
 *   composer bump-version -- 0.7.0                     explicit
 *   composer bump-version -- minor --date 2026-11-12   a date other than today
 *   composer bump-version -- --check 0.7.0             only check (the release workflow)
 *
 * What it writes:
 *   - config/module.ini `version`;
 *   - CITATION.cff `version` and `date-released`;
 *   - CHANGELOG.md: the `## [Unreleased]` notes become `## [x.y.z] - date`, a
 *     fresh, empty `## [Unreleased]` opens above them, and the compare links
 *     at the foot move on.
 *
 * Every check runs before the first write (the target version, a non-empty
 * Unreleased section, since the release notes are built from it, every
 * pattern), so a refused bump leaves the tree as it was. It never commits,
 * tags or pushes. `--check` changes nothing: it fails unless every
 * declaration names that version and the changelog has its section.
 *
 * The same contract as DRESearch's `npm run bump`, in PHP because this
 * module has no Node toolchain to hang it on. Excluded from the release zip
 * (.gitattributes).
 */

namespace DRELinkedData\Release;

use RuntimeException;

const REPOSITORY = 'https://github.com/AM-Digital-Research-Environment/DRE-LinkedData';
const SEMVER = '(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?';

/** The version config/module.ini declares now. */
function currentVersion(string $ini): string
{
    if (!preg_match('/^version\s*=\s*"(' . SEMVER . ')"\s*$/m', $ini, $match)) {
        throw new RuntimeException('config/module.ini has no `version = "x.y.z"` line.');
    }
    return $match[1];
}

/** `patch`, `minor`, `major` or an explicit version, from the current one. */
function nextVersion(string $current, string $requested): string
{
    if (in_array($requested, ['patch', 'minor', 'major'], true)) {
        [$major, $minor, $patch] = array_map('intval', explode('.', preg_replace('/-.*$/', '', $current)));
        return match ($requested) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
        };
    }
    if (!preg_match('/^' . SEMVER . '$/', $requested)) {
        throw new RuntimeException("'{$requested}' is neither patch, minor, major nor a version (x.y.z).");
    }
    if (version_compare($requested, $current, '<')) {
        throw new RuntimeException("{$requested} is older than the current version, {$current}.");
    }
    return $requested;
}

/** One substitution that must match exactly once. */
function replaceOnce(string $pattern, string $replacement, string $text, string $file): string
{
    $result = preg_replace($pattern, $replacement, $text, -1, $count);
    if ($result === null || $count !== 1) {
        throw new RuntimeException("{$file}: expected exactly one match for {$pattern}, found " . (int) $count . '.');
    }
    return $result;
}

function withIniVersion(string $ini, string $version): string
{
    return replaceOnce('/^(version\s*=\s*")' . SEMVER . '(")/m', '${1}' . $version . '${2}', $ini, 'config/module.ini');
}

function withCitationVersion(string $cff, string $version, string $date): string
{
    $cff = replaceOnce('/^version:\s*"?' . SEMVER . '"?\s*$/m', 'version: ' . $version, $cff, 'CITATION.cff');
    return replaceOnce('/^date-released:\s*"?\d{4}-\d{2}-\d{2}"?\s*$/m', 'date-released: "' . $date . '"', $cff, 'CITATION.cff');
}

/**
 * The changelog with its Unreleased notes released as $version. Refuses an
 * empty Unreleased section: the release notes are built from it.
 */
function releasedChangelog(string $changelog, string $previous, string $version, string $date): string
{
    if (!preg_match('/^## \[Unreleased\]\n(.*?)(?=^## \[)/ms', $changelog, $match)) {
        throw new RuntimeException('CHANGELOG.md has no `## [Unreleased]` section followed by a release.');
    }
    if (trim($match[1]) === '') {
        throw new RuntimeException('CHANGELOG.md: the Unreleased section is empty; write the release notes first.');
    }
    if (str_contains($changelog, "## [{$version}]")) {
        throw new RuntimeException("CHANGELOG.md already has a {$version} section.");
    }
    $changelog = replaceOnce(
        '/^## \[Unreleased\]\n/m',
        "## [Unreleased]\n\n## [{$version}] - {$date}\n",
        $changelog,
        'CHANGELOG.md'
    );
    return replaceOnce(
        '/^\[Unreleased\]: .*$/m',
        '[Unreleased]: ' . REPOSITORY . "/compare/v{$version}...HEAD\n"
            . "[{$version}]: " . REPOSITORY . "/compare/v{$previous}...v{$version}",
        $changelog,
        'CHANGELOG.md'
    );
}

/**
 * Every place that disagrees with $version, as messages; empty when the tree
 * is ready to be tagged v$version.
 *
 * @param array<string,string> $files path => contents
 * @return list<string>
 */
function disagreements(array $files, string $version): array
{
    $problems = [];
    if (currentVersion($files['config/module.ini']) !== $version) {
        $problems[] = 'config/module.ini declares ' . currentVersion($files['config/module.ini']);
    }
    if (!preg_match('/^version:\s*"?(' . SEMVER . ')"?\s*$/m', $files['CITATION.cff'], $cff) || $cff[1] !== $version) {
        $problems[] = 'CITATION.cff declares ' . ($cff[1] ?? 'no version');
    }
    if (!preg_match('/^date-released:\s*"?\d{4}-\d{2}-\d{2}"?\s*$/m', $files['CITATION.cff'])) {
        $problems[] = 'CITATION.cff has no ISO date-released';
    }
    if (!preg_match('/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}$/m', $files['CHANGELOG.md'])) {
        $problems[] = "CHANGELOG.md has no `## [{$version}] - YYYY-MM-DD` section";
    }
    return $problems;
}

/** @param list<string> $arguments */
function main(string $root, array $arguments): int
{
    $paths = ['config/module.ini', 'CITATION.cff', 'CHANGELOG.md'];
    $files = [];
    foreach ($paths as $path) {
        $text = @file_get_contents($root . '/' . $path);
        if ($text === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        $files[$path] = str_replace("\r\n", "\n", $text);
    }

    $date = date('Y-m-d');
    $check = false;
    $requested = null;
    for ($i = 0; $i < count($arguments); $i++) {
        $argument = $arguments[$i];
        if ($argument === '--check') {
            $check = true;
        } elseif ($argument === '--date') {
            $date = (string) ($arguments[++$i] ?? '');
        } elseif (str_starts_with($argument, '--date=')) {
            $date = substr($argument, 7);
        } elseif ($requested === null) {
            $requested = $argument;
        } else {
            throw new RuntimeException("Unexpected argument '{$argument}'.");
        }
    }
    if ($requested === null) {
        throw new RuntimeException('Usage: composer bump-version -- <patch|minor|major|x.y.z> [--date YYYY-MM-DD] | --check x.y.z');
    }

    if ($check) {
        $problems = disagreements($files, $requested);
        foreach ($problems as $problem) {
            fwrite(STDERR, "✗ {$problem} (expected {$requested}).\n");
        }
        if ($problems === []) {
            echo "✓ module.ini, CITATION.cff and CHANGELOG.md all declare {$requested}.\n";
        }
        return $problems === [] ? 0 : 1;
    }

    $parsed = date_create_immutable_from_format('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException("--date must be YYYY-MM-DD, not '{$date}'.");
    }
    $current = currentVersion($files['config/module.ini']);
    $version = nextVersion($current, $requested);
    if ($version === $current) {
        if (disagreements($files, $version) === []) {
            echo "Already at {$version}; nothing to do.\n";
            return 0;
        }
        throw new RuntimeException("{$version} is the current version, but the declarations disagree; fix them by hand.");
    }

    // Every new text is built before anything is written.
    $next = [
        'config/module.ini' => withIniVersion($files['config/module.ini'], $version),
        'CITATION.cff' => withCitationVersion($files['CITATION.cff'], $version, $date),
        'CHANGELOG.md' => releasedChangelog($files['CHANGELOG.md'], $current, $version, $date),
    ];
    foreach ($next as $path => $text) {
        file_put_contents($root . '/' . $path, $text);
    }
    echo "Bumped {$current} -> {$version} ({$date}): " . implode(', ', array_keys($next)) . ".\n";
    echo "Next: composer check, commit \"Release {$version}\", then git tag -a v{$version} and push the tag.\n";
    return 0;
}

// Run only from the command line, not when a test includes the functions.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        exit(main(dirname(__DIR__), array_slice($argv, 1)));
    } catch (RuntimeException $error) {
        fwrite(STDERR, '✗ bump: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
