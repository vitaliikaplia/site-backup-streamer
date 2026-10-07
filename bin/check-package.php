<?php
/**
 * Checks an unpacked release package (the site-backup-streamer/ folder of site-backup-streamer.zip).
 *
 *   php bin/check-package.php build/site-backup-streamer
 *
 * Every file site-backup-streamer.php requires is present, the Composer vendor loads both export
 * libraries, and every PHP file in the package passes `php -l` with the running PHP. Exit code 1 on any
 * problem.
 */
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/release-lib.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
if ($dir === '' || !is_file($dir . '/site-backup-streamer.php')) {
    fwrite(STDERR, "Usage: php bin/check-package.php <unpacked site-backup-streamer folder>\n");
    exit(1);
}

$errors  = [];
$targets = sbs_require_targets((string) file_get_contents($dir . '/site-backup-streamer.php'));
if (!$targets) $errors[] = "site-backup-streamer.php: no require_once __DIR__ . '/…' found";
foreach ($targets as $rel) {
    if (!is_file($dir . '/' . $rel)) $errors[] = 'missing required file: ' . $rel;
}

// The plugin loads vendor/autoload.php lazily, so a package without vendor would only fail at export time.
if (!is_file($dir . '/vendor/autoload.php')) {
    $errors[] = 'missing vendor/autoload.php (run composer install before tagging)';
} else {
    require $dir . '/vendor/autoload.php';
    foreach (['ZipStream\\ZipStream', 'Ifsnop\\Mysqldump\\Mysqldump'] as $class) {
        if (!class_exists($class)) $errors[] = 'vendor does not load ' . $class;
    }
}

$files = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') $files[] = $file->getPathname();
}
sort($files);
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) $errors[] = implode("\n", $out);
    $out = [];
}

printf("PHP %s: %d required files, %d PHP files linted.\n", PHP_VERSION, count($targets), count($files));
if ($errors) {
    fwrite(STDERR, "\nPackage check FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
exit(0);
