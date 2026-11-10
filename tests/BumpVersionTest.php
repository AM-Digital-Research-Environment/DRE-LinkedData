<?php
declare(strict_types=1);

use function DRELinkedData\Release\disagreements;
use function DRELinkedData\Release\main;
use function DRELinkedData\Release\nextVersion;
use function DRELinkedData\Release\releasedChangelog;
use function DRELinkedData\Release\withCitationVersion;
use function DRELinkedData\Release\withIniVersion;

require_once dirname(__DIR__) . '/scripts/bump-version.php';

/** @return array<string,string> A small tree with every file the bump writes. */
function bumpFixture(): array
{
    return [
        'config/module.ini' => "[info]\nname = \"DRE Linked Data\"\nversion           = \"0.6.0\"\nomeka_version_constraint = \"^4.2.1\"\n",
        'CITATION.cff' => "cff-version: 1.2.0\nversion: 0.6.0\ndate-released: \"2026-11-07\"\n",
        'CHANGELOG.md' => "# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n- Something.\n\n## [0.6.0] - 2026-11-07\n\n- Before.\n\n"
            . "[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.6.0...HEAD\n"
            . "[0.6.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.5.0...v0.6.0\n",
    ];
}

/** @param array<string,string> $files */
function bumpTree(array $files): string
{
    $root = sys_get_temp_dir() . '/dre-ld-bump-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0777, true);
    foreach ($files as $path => $text) {
        file_put_contents($root . '/' . $path, $text);
    }
    return $root;
}

function removeTree(string $root): void
{
    foreach (['config/module.ini', 'CITATION.cff', 'CHANGELOG.md'] as $path) {
        @unlink($root . '/' . $path);
    }
    @rmdir($root . '/config');
    @rmdir($root);
}

/** @param array<string,string> $files */
function readTree(string $root, array $files): array
{
    $read = [];
    foreach (array_keys($files) as $path) {
        $read[$path] = (string) file_get_contents($root . '/' . $path);
    }
    return $read;
}

test('bump: patch, minor, major and an explicit version', function (): void {
    assertSameValue('0.6.1', nextVersion('0.6.0', 'patch'));
    assertSameValue('0.7.0', nextVersion('0.6.3', 'minor'));
    assertSameValue('1.0.0', nextVersion('0.6.3', 'major'));
    assertSameValue('0.7.0-rc.1', nextVersion('0.6.3', '0.7.0-rc.1'));
    foreach (['0.5.9', 'v0.7.0', '0.7', 'next'] as $refused) {
        $threw = false;
        try {
            nextVersion('0.6.0', $refused);
        } catch (RuntimeException) {
            $threw = true;
        }
        assertTrue($threw, "'{$refused}' must be refused");
    }
});

test('bump: writes module.ini, CITATION.cff and a hyphenated changelog section', function (): void {
    $files = bumpFixture();
    assertContainsText('version           = "0.7.0"', withIniVersion($files['config/module.ini'], '0.7.0'));
    $cff = withCitationVersion($files['CITATION.cff'], '0.7.0', '2026-11-12');
    assertContainsText("version: 0.7.0\ndate-released: \"2026-11-12\"", $cff);

    $changelog = releasedChangelog($files['CHANGELOG.md'], '0.6.0', '0.7.0', '2026-11-12');
    assertContainsText("## [Unreleased]\n\n## [0.7.0] - 2026-11-12\n\n### Fixed\n\n- Something.", $changelog);
    assertContainsText('[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.7.0...HEAD', $changelog);
    assertContainsText('[0.7.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.6.0...v0.7.0', $changelog);
    assertSameValue([], disagreements([
        'config/module.ini' => withIniVersion($files['config/module.ini'], '0.7.0'),
        'CITATION.cff' => $cff,
        'CHANGELOG.md' => $changelog,
    ], '0.7.0'));
});

test('bump: an empty Unreleased section is refused and nothing is written', function (): void {
    $files = bumpFixture();
    $files['CHANGELOG.md'] = str_replace("### Fixed\n\n- Something.\n\n", '', $files['CHANGELOG.md']);
    $root = bumpTree($files);
    $threw = false;
    try {
        main($root, ['minor', '--date', '2026-11-12']);
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), 'Unreleased section is empty');
    }
    $after = readTree($root, $files);
    removeTree($root);
    assertTrue($threw, 'an empty Unreleased section must be refused');
    assertSameValue($files, $after, 'a refused bump leaves every file as it was');
});

test('bump: the whole run, then --check agrees and a re-run changes nothing', function (): void {
    $files = bumpFixture();
    $root = bumpTree($files);
    ob_start();
    try {
        assertSameValue(0, main($root, ['minor', '--date=2026-11-12']));
        $bumped = readTree($root, $files);
        assertSameValue([], disagreements($bumped, '0.7.0'));
        assertSameValue(0, main($root, ['0.7.0']), 'the same bump again');
        assertSameValue($bumped, readTree($root, $files), 'a re-run of the same bump changes nothing');
    } finally {
        ob_end_clean();
        removeTree($root);
    }
});

test('bump --check names every declaration that disagrees with the tag', function (): void {
    $problems = disagreements(bumpFixture(), '0.7.0');
    assertSameValue(3, count($problems), implode('; ', $problems));
    assertContainsText('config/module.ini declares 0.6.0', $problems[0]);
    assertContainsText('CITATION.cff declares 0.6.0', $problems[1]);
    assertContainsText('CHANGELOG.md has no `## [0.7.0] - YYYY-MM-DD` section', $problems[2]);
});

test('the repository itself agrees with its declared version', function (): void {
    $root = dirname(__DIR__);
    $files = [];
    foreach (['config/module.ini', 'CITATION.cff', 'CHANGELOG.md'] as $path) {
        $files[$path] = str_replace("\r\n", "\n", (string) file_get_contents($root . '/' . $path));
    }
    $version = DRELinkedData\Release\currentVersion($files['config/module.ini']);
    assertSameValue([], disagreements($files, $version));
});

test('every changelog release heading is `## [x.y.z] - YYYY-MM-DD`', function (): void {
    $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
    preg_match_all('/^## \[(?!Unreleased\]).*$/m', $changelog, $headings);
    assertTrue(count($headings[0]) > 0, 'the changelog has releases');
    foreach ($headings[0] as $heading) {
        assertTrue(
            preg_match('/^## \[\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\] - \d{4}-\d{2}-\d{2}$/', $heading) === 1,
            "'{$heading}' is not `## [x.y.z] - YYYY-MM-DD` (a hyphen, as in the other DRE repositories)"
        );
    }
});
