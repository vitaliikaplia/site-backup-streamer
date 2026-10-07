<?php
/**
 * Prints the CHANGELOG.md entry of a release (used as GitHub Release notes).
 *
 *   php bin/release-notes.php v1.1.0           # Markdown body of "### 1.1.0 — …" (hard wraps joined)
 *   php bin/release-notes.php --title v1.1.0   # "1.1.0 — <title>"
 *
 * Exit code 1 when the entry is missing or empty.
 */
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/release-lib.php';

$args  = array_slice($argv, 1);
$title = in_array('--title', $args, true);
$args  = array_values(array_diff($args, ['--title']));
if (!isset($args[0]) || $args[0] === '') {
    fwrite(STDERR, "Usage: php bin/release-notes.php [--title] <tag>\n");
    exit(1);
}

$version = sbs_tag_version($args[0]);
$log     = (string) @file_get_contents(sbs_root() . '/CHANGELOG.md');
$entry   = sbs_changelog_entry($log, $version);
if ($entry === null || $entry['body'] === '') {
    fwrite(STDERR, "CHANGELOG.md has no non-empty entry \"### $version\" under \"## Зміни\".\n");
    exit(1);
}

// The changelog is hard-wrapped; GitHub shows every newline of a release body as a line break.
echo $title ? ($entry['title'] !== '' ? $version . ' — ' . $entry['title'] : $version) . "\n" : sbs_unwrap_markdown($entry['body']);
exit(0);
