<?php
/**
 * Release tooling (bin/release-lib.php): version sources, changelog extraction, tag matching.
 */

function sbs_release_sources(string $header = '1.1.0', string $constant = '1.1.0', string $readme = '1.1.0', string $log = '1.1.0'): array {
    return [
        'site-backup-streamer.php' => "<?php\n/**\n * Plugin Name: Site Backup Streamer\n * Version: $header\n */\ndefine( 'SBS_VERSION', '$constant' );\n",
        'README.md'                => "# Site Backup Streamer\n\nПоточна версія: **$readme** · PHP 8.3+\n",
        'CHANGELOG.md'             => "# Site Backup Streamer\n\n## Вступ\n\n### 9.9.9 — not a changelog entry\n\n"
            . "## Зміни\n\n### $log — нове\n\n- **Виправлено:** перше\n  продовження\n- друге\n\n### 1.0.0 — старе\n\n- старий пункт\n\n## Ліцензія\n\n### 0.0.1 — outside\n",
    ];
}

sbs_test('release: consistent sources pass, with and without a tag', function () {
    $versions = sbs_versions(sbs_release_sources());
    sbs_assert_same(['1.1.0'], array_values(array_unique($versions)));
    sbs_assert_same([], sbs_version_errors($versions));
    sbs_assert_same([], sbs_version_errors($versions, 'v1.1.0'));
    sbs_assert_same([], sbs_version_errors($versions, 'refs/tags/v1.1.0'));
    sbs_assert_same([], sbs_version_errors($versions, '1.1.0'));
});

sbs_test('release: every single mismatch is reported', function () {
    $cases = [
        'header'    => sbs_release_sources('1.1.1'),
        'constant'  => sbs_release_sources('1.1.0', '1.0.0'),
        'readme'    => sbs_release_sources('1.1.0', '1.1.0', '1.0.0'),
        'changelog' => sbs_release_sources('1.1.0', '1.1.0', '1.1.0', '1.1.1'),
    ];
    foreach ($cases as $label => $sources) {
        sbs_assert_true(sbs_version_errors(sbs_versions($sources)) !== [], $label);
    }
});

sbs_test('release: tag mismatch and missing sources fail', function () {
    sbs_assert_true(sbs_version_errors(sbs_versions(sbs_release_sources()), 'v1.1.1') !== [], 'wrong tag');
    $sources = sbs_release_sources();
    $sources['README.md'] = 'no version line';
    sbs_assert_true(sbs_version_errors(sbs_versions($sources)) !== [], 'missing README line');
    sbs_assert_true(sbs_version_errors(sbs_versions([])) !== [], 'nothing found');
    sbs_assert_true(sbs_version_errors(sbs_versions(sbs_release_sources('1.1', '1.1', '1.1', '1.1'))) !== [], 'not X.Y.Z');
});

sbs_test('release: changelog is read only from the «Зміни» section, newest first', function () {
    $log = sbs_changelog(sbs_release_sources()['CHANGELOG.md']);
    sbs_assert_same(['1.1.0', '1.0.0'], array_column($log, 'version'));
    sbs_assert_same('нове', $log[0]['title']);
    sbs_assert_same("- **Виправлено:** перше\n  продовження\n- друге\n", $log[0]['body']);
    sbs_assert_same("- старий пункт\n", $log[1]['body']);
    sbs_assert_null(sbs_changelog_entry(sbs_release_sources()['CHANGELOG.md'], '0.0.1'), 'entries after the section are ignored');
    sbs_assert_same([], sbs_changelog("# No changelog here\n"));
});

sbs_test('release: non-version ### lines stay inside the entry body', function () {
    $md  = "## Зміни\n\n### 1.1.0 — нове\n\n- a\n\n### Безпека\n\n- b\n\n### 1.0.0 — старе\n\n- c\n";
    $log = sbs_changelog($md);
    sbs_assert_same(['1.1.0', '1.0.0'], array_column($log, 'version'));
    sbs_assert_same("- a\n\n### Безпека\n\n- b\n", $log[0]['body']);
});

sbs_test('release: tag_version strips refs/tags/ and v', function () {
    sbs_assert_same('1.1.0', sbs_tag_version('v1.1.0'));
    sbs_assert_same('1.1.0', sbs_tag_version('refs/tags/v1.1.0'));
    sbs_assert_same('1.1.0', sbs_tag_version('1.1.0'));
});

sbs_test('release: the repository itself is version-consistent', function () {
    $versions = sbs_versions(sbs_read_sources(SBS_TEST_ROOT));
    sbs_assert_same([], sbs_version_errors($versions), 'run php bin/check-version.php for details');
    $entry = sbs_changelog_entry((string) file_get_contents(SBS_TEST_ROOT . '/CHANGELOG.md'), (string) reset($versions));
    sbs_assert_true($entry !== null && $entry['body'] !== '', 'CHANGELOG.md entry for the current version has release notes');
});

sbs_test('release: notes are unwrapped for GitHub (paragraphs and list items on one line)', function () {
    $md   = "Intro line one\nline two.\n\n**Heading**\n- item one\n  continues here\n- item two\n  1. nested\n\n### Sub\ntext after heading\nmore\n\n```\ncode line\nkept\n```\nhard break  \nnext\n";
    $want = "Intro line one line two.\n\n**Heading**\n- item one continues here\n- item two\n  1. nested\n\n### Sub\ntext after heading more\n\n```\ncode line\nkept\n```\nhard break  \nnext\n";
    sbs_assert_same($want, sbs_unwrap_markdown($md));
});

sbs_test('release: every file site-backup-streamer.php requires exists in the plugin', function () {
    $main    = (string) file_get_contents(SBS_TEST_ROOT . '/site-backup-streamer.php');
    $targets = sbs_require_targets($main);
    sbs_assert_true(in_array('includes/class-sbs-github-updater.php', $targets, true), sbs_export($targets));
    foreach ($targets as $rel) {
        sbs_assert_true(is_file(SBS_TEST_ROOT . '/' . $rel), $rel);
    }
    sbs_assert_same(['a/b.php', 'c.php'], sbs_require_targets("require_once __DIR__ . '/a/b.php';\nrequire( __DIR__ . \"/c.php\" );\nrequire_once __DIR__ . '/a/b.php';"));
});

sbs_test('release: dev-only files stay out of the release zip', function () {
    $attributes = (string) file_get_contents(SBS_TEST_ROOT . '/.gitattributes');
    foreach (['/.github', '/tests', '/bin', '/.idea'] as $path) {
        sbs_assert_true(preg_match('#^' . preg_quote($path, '#') . '\s+export-ignore\s*$#m', $attributes) === 1, $path);
    }
    sbs_assert_true(preg_match('#^/vendor\s+export-ignore#m', $attributes) === 0, 'vendor must ship in the release');
});
