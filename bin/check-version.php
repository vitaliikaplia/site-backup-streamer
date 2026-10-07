<?php
/**
 * Release version consistency check.
 *
 *   php bin/check-version.php           # all version sources agree
 *   php bin/check-version.php v1.1.0    # …and match this tag
 *
 * Sources: the site-backup-streamer.php "Version:" header, SBS_VERSION, README «Поточна версія» and the
 * newest CHANGELOG.md entry ("### X.Y.Z — …" under "## Зміни").
 * Exit code 0 when consistent, 1 otherwise.
 */
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/release-lib.php';

$tag      = $argv[1] ?? null;
$versions = sbs_versions(sbs_read_sources(sbs_root()));

foreach ($versions as $label => $version) {
    printf("%s: %s\n", $label, $version ?? "(not found)");
}
if ($tag !== null) {
    printf("tag %s: %s\n", $tag, sbs_tag_version($tag));
}

$errors = sbs_version_errors($versions, $tag);
if ($errors) {
    fwrite(STDERR, "\nVersion check FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "\nVersion check OK.\n";
exit(0);
