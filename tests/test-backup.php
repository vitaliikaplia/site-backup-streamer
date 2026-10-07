<?php
/**
 * Files export: the shared directory walk, ZIP entries, wp-config.php lookup, the dump charset and the
 * per-download checks. Regression tests for the bugs fixed in 1.1.0.
 */

/** Creates a small site tree: f1, a/f2, a/b/f3, c/f4 → 3 directories, 4 files. */
function sbs_backup_tree(): string {
    $root = sbs_temp_dir('tree');
    mkdir($root . '/a/b', 0777, true);
    mkdir($root . '/c');
    file_put_contents($root . '/f1.txt', '1');
    file_put_contents($root . '/a/f2.txt', '22');
    file_put_contents($root . '/a/b/f3.txt', '333');
    file_put_contents($root . '/c/f4.txt', '4444');
    return $root;
}

/** Walks $root as "site/" and returns [stats, ZIP names]. */
function sbs_walk(string $root): array {
    $names = [];
    $stats = sbs_call('walk_backup_files', [$root . '/' => 'site/'], function ($path, $name) use (&$names) {
        $names[] = $name;
        return true;
    });
    sort($names);
    return [$stats, $names];
}

/** Opens a ZIP written by ZipStream into a temp file. */
function sbs_zip_to_file(callable $fill): string {
    sbs_call('load_dependencies');
    $file = tempnam(sys_get_temp_dir(), 'sbs-zip-');
    $zip  = new ZipStream\ZipStream(
        outputStream: fopen($file, 'wb'),
        sendHttpHeaders: false,
        defaultCompressionMethod: ZipStream\CompressionMethod::STORE
    );
    $fill($zip);
    $zip->finish();
    return $file;
}

sbs_test('backup: walk counts real directories, without "." and ".."', function () {
    $root = sbs_backup_tree();
    try {
        [$stats, $names] = sbs_walk($root);
        // Before 1.1.0 setFlags(CATCH_GET_CHILD) reached RecursiveDirectoryIterator and dropped SKIP_DOTS: 11 directories.
        sbs_assert_same(3, $stats['directories']);
        sbs_assert_same(4, $stats['files']);
        sbs_assert_same(0, $stats['skipped']);
        sbs_assert_same(['site/a/b/f3.txt', 'site/a/f2.txt', 'site/c/f4.txt', 'site/f1.txt'], $names);
    } finally {
        sbs_rm_tree($root);
    }
});

sbs_test('backup: an unreadable directory is skipped instead of aborting the walk', function () {
    $root = sbs_backup_tree();
    try {
        mkdir($root . '/locked');
        file_put_contents($root . '/locked/secret.txt', 'x');
        chmod($root . '/locked', 0000);
        if (is_readable($root . '/locked')) {
            sbs_skip('running as a user that ignores directory permissions');
        }
        [$stats, $names] = sbs_walk($root);
        sbs_assert_same(4, $stats['files']);
        sbs_assert_same(3, $stats['directories']);
        sbs_assert_same(1, $stats['skipped']);
        sbs_assert_false(in_array('site/locked/secret.txt', $names, true));
    } finally {
        sbs_rm_tree($root);
    }
});

sbs_test('backup: symbolic links are skipped and not followed', function () {
    $root    = sbs_backup_tree();
    $outside = sbs_temp_dir('outside');
    try {
        file_put_contents($outside . '/leak.txt', 'x');
        symlink($outside, $root . '/linked-dir');
        symlink($root . '/f1.txt', $root . '/linked-file.txt');
        [$stats, $names] = sbs_walk($root);
        sbs_assert_same(4, $stats['files']);
        sbs_assert_same(2, $stats['skipped']);
        sbs_assert_false(in_array('site/linked-dir/leak.txt', $names, true));
    } finally {
        sbs_rm_tree($root);
        sbs_rm_tree($outside);
    }
});

sbs_test('backup: a file older than 1980 gets the ZIP minimum date instead of aborting the archive', function () {
    if (!class_exists('ZipArchive')) {
        sbs_skip('ext-zip is not available');
    }
    $root = sbs_backup_tree();
    try {
        touch($root . '/c/old.txt', 86400); // 1970-01-02
        $file = sbs_zip_to_file(function ($zip) use ($root) {
            sbs_call('walk_backup_files', [$root . '/' => 'site/'], function ($path, $name, $info) use ($zip) {
                return sbs_call('add_file_to_zip', $zip, $path, $name, $info);
            });
        });
        $archive = new ZipArchive();
        sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true, 'archive is consistent');
        sbs_assert_same(5, $archive->numFiles);
        $stat = $archive->statName('site/c/old.txt');
        sbs_assert_true(is_array($stat), 'old file is in the archive');
        // DOS time has no zone; libzip reads it in local time, so allow a day either side of 1980-01-01.
        sbs_assert_true(abs($stat['mtime'] - 315532800) <= 86400, 'mtime ' . $stat['mtime']);
        $archive->close();
        @unlink($file);
    } finally {
        sbs_rm_tree($root);
    }
});

sbs_test('backup: a file that disappears before it is added is skipped, the archive stays valid', function () {
    if (!class_exists('ZipArchive')) {
        sbs_skip('ext-zip is not available');
    }
    $root = sbs_backup_tree();
    try {
        $results = [];
        $file    = sbs_zip_to_file(function ($zip) use ($root, &$results) {
            $info = new SplFileInfo($root . '/f1.txt');
            $results[] = sbs_call('add_file_to_zip', $zip, $root . '/missing.txt', 'site/missing.txt', $info);
            $results[] = sbs_call('add_file_to_zip', $zip, $root . '/f1.txt', 'site/f1.txt', $info);
        });
        sbs_assert_same([false, true], $results);
        $archive = new ZipArchive();
        sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true);
        sbs_assert_same(1, $archive->numFiles);
        $archive->close();
        @unlink($file);
    } finally {
        sbs_rm_tree($root);
    }
});

sbs_test('backup: wp-config.php one level up is used only when the parent is not another install', function () {
    $parent = sbs_temp_dir('config');
    try {
        mkdir($parent . '/wp');
        $root = $parent . '/wp/';
        sbs_assert_null(sbs_call('find_wp_config_path', $root), 'nothing found');

        file_put_contents($parent . '/wp-config.php', '<?php');
        sbs_assert_same($parent . '/wp-config.php', sbs_call('find_wp_config_path', $root), 'parent config');

        file_put_contents($parent . '/wp-settings.php', '<?php');
        sbs_assert_null(sbs_call('find_wp_config_path', $root), 'parent is another WordPress install');

        file_put_contents($root . 'wp-config.php', '<?php');
        sbs_assert_same($root . 'wp-config.php', sbs_call('find_wp_config_path', $root), 'local config wins');
    } finally {
        sbs_rm_tree($parent);
    }
});

sbs_test('backup: the dump uses the charset WordPress connects with', function () {
    $saved = $GLOBALS['wpdb'] ?? null;
    try {
        foreach ([
            'utf8mb4'          => 'utf8mb4',
            'latin1'           => 'latin1',
            ''                 => 'utf8mb4',
            'utf8; DROP TABLE' => 'utf8mb4',
        ] as $charset => $expected) {
            $GLOBALS['wpdb'] = (object) ['charset' => $charset];
            sbs_assert_same($expected, sbs_call('database_charset'), var_export($charset, true));
        }
    } finally {
        $GLOBALS['wpdb'] = $saved;
    }
});

sbs_test('backup: a files-only check blocks the files download but not the database', function () {
    $checks = [
        sbs_call('check_result', 'PHP', true, 'ok', 'bad'),
        sbs_call('check_result', 'Memory', false, 'ok', 'bad', 'files'),
    ];
    sbs_assert_false(sbs_call('checks_are_ready', $checks, 'files'));
    sbs_assert_true(sbs_call('checks_are_ready', $checks, 'database'));

    $checks[] = sbs_call('check_result', 'DB', false, 'ok', 'bad');
    sbs_assert_false(sbs_call('checks_are_ready', $checks, 'database'));
});

sbs_test('backup: the files export refuses to start without 32 MB of free memory', function () {
    $saved = ini_get('memory_limit');
    try {
        ini_set('memory_limit', (string) (memory_get_usage() + 8 * 1048576));
        $error = null;
        try {
            sbs_call('assert_zip_memory');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        sbs_assert_true(is_string($error) && str_contains($error, 'WP_MAX_MEMORY_LIMIT'), (string) $error);

        ini_set('memory_limit', '-1');
        sbs_call('assert_zip_memory'); // no limit: no exception
    } finally {
        ini_set('memory_limit', $saved);
    }
});

sbs_test('backup: the bundled vendor matches the APIs the export uses', function () {
    sbs_call('load_dependencies'); // throws on an incompatible ZipStream or mysqldump-php
    sbs_assert_true(class_exists('ZipStream\\ZipStream') && class_exists('Ifsnop\\Mysqldump\\Mysqldump'));
});
