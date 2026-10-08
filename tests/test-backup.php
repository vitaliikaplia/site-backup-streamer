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

/** Writes a ZIP with the plugin's own ZipStream settings into a temp file. */
function sbs_zip_to_file(callable $fill): string {
    sbs_call('load_dependencies');
    $file = tempnam(sys_get_temp_dir(), 'sbs-zip-');
    $zip  = sbs_call('zip_writer', fopen($file, 'wb'), 'test.zip');
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

sbs_test('backup: a file dated after 2107 gets the last ZIP date instead of aborting the archive', function () {
    if (!class_exists('ZipArchive')) {
        sbs_skip('ext-zip is not available');
    }
    $root = sbs_backup_tree();
    try {
        touch($root . '/c/future.txt', 7258118400); // 2200-01-01: past the DOS range, ZipStream throws on it
        $file = sbs_zip_to_file(function ($zip) use ($root) {
            sbs_call('walk_backup_files', [$root . '/' => 'site/'], function ($path, $name, $info) use ($zip) {
                return sbs_call('add_file_to_zip', $zip, $path, $name, $info);
            });
        });
        $archive = new ZipArchive();
        sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true, 'archive is consistent');
        sbs_assert_same(5, $archive->numFiles);
        $stat = $archive->statName('site/c/future.txt');
        sbs_assert_true(is_array($stat), 'future file is in the archive');
        // 2107-12-31 23:59:58 UTC; libzip reads DOS time in local time, so allow a day either side.
        sbs_assert_true(abs($stat['mtime'] - 4354819198) <= 86400, 'mtime ' . $stat['mtime']);
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

sbs_test('backup: a file that starts past 4 GB gets a valid zip64 local header', function () {
    $root = sbs_temp_dir('zip64');
    try {
        file_put_contents($root . '/f.txt', 'hello');
        $file = sbs_zip_to_file(function ($zip) use ($root) {
            // Pretend 4 GB are already written: the entry's offset needs zip64, its sizes do not.
            (new ReflectionProperty($zip, 'offset'))->setValue($zip, 0x100000000);
            sbs_call('add_file_to_zip', $zip, $root . '/f.txt', 'site/f.txt', new SplFileInfo($root . '/f.txt'));
        });
        $data = (string) file_get_contents($file);
        @unlink($file);
        $header = unpack('Vsignature/vversion/vflags/x6/Vcrc/Vcompressed/Vuncompressed/vname/vextra', $data);
        $zip64  = unpack('vid/vsize', substr($data, 30 + $header['name'], $header['extra']));
        sbs_assert_same(0x04034b50, $header['signature'], 'local file header');
        sbs_assert_true(($header['flags'] & 0x08) !== 0, 'sizes follow the data (zero header)');
        sbs_assert_same(0x0001, $zip64['id'], 'zip64 extra field');
        // Every 0xFFFFFFFF size needs its 8 bytes in the zip64 field. ZipStream 3.1.1 without the zero header
        // wrote both sentinels with only the offset there (upstream PR #413), and unzippers read it as the size.
        $sentinels = (int) ($header['compressed'] === 0xFFFFFFFF) + (int) ($header['uncompressed'] === 0xFFFFFFFF);
        sbs_assert_true($zip64['size'] >= 8 * $sentinels, "zip64 field of {$zip64['size']} bytes for $sentinels size sentinels");
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

/** Reads a private constant of the plugin class. */
function sbs_const(string $name) {
    return (new ReflectionClassConstant('SBS_Site_Backup_Streamer', $name))->getValue();
}

sbs_test('backup: the files export refuses to start below the memory minimum', function () {
    $saved   = ini_get('memory_limit');
    $minimum = sbs_const('ZIP_MEMORY_MINIMUM');
    try {
        ini_set('memory_limit', (string) (memory_get_usage(true) + $minimum - 1048576));
        $error = null;
        try {
            sbs_call('assert_zip_memory');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        sbs_assert_true(is_string($error) && str_contains($error, 'WP_MAX_MEMORY_LIMIT'), (string) $error);

        ini_set('memory_limit', (string) (memory_get_usage(true) + $minimum + 1048576));
        sbs_call('assert_zip_memory'); // enough for small files: no exception

        ini_set('memory_limit', '-1');
        sbs_call('assert_zip_memory'); // no limit: no exception
    } finally {
        ini_set('memory_limit', $saved);
    }
});

sbs_test('backup: a file needs its size in free memory, at most two 16 MB blocks, plus the margin', function () {
    $mb     = 1048576;
    $margin = sbs_const('ZIP_MEMORY_MARGIN');
    $needed = fn(int $size, int $entries = 0) => sbs_call('zip_memory_needed', $size, $entries) - $margin;
    sbs_assert_same(0, $needed(0), 'an empty file reads nothing');
    sbs_assert_true($needed(4096) >= 4096 && $needed(4096) <= 4096 + 8192, 'small file: about its size, ' . $needed(4096));
    sbs_assert_true($needed(20 * $mb) >= 20 * $mb && $needed(20 * $mb) <= 20 * $mb + 16384, '20 MB: two blocks of 16 + 4 MB');
    sbs_assert_same($needed(32 * $mb), $needed(5000 * $mb), 'large files read in two blocks at most');
    sbs_assert_true($needed(32 * $mb) >= 32 * $mb, 'two whole blocks');
});

sbs_test('backup: the central directory array doubling is counted at 2^n records', function () {
    $slot = sbs_const('ZIP_CDR_SLOT');
    sbs_assert_same(0, sbs_call('cdr_growth', 0));
    sbs_assert_same(0, sbs_call('cdr_growth', 65535));
    sbs_assert_same(0, sbs_call('cdr_growth', 65537));
    sbs_assert_true(sbs_call('cdr_growth', 65536) >= 2 * 65536 * $slot, 'the new array for 131072 records');
    sbs_assert_same(PHP_VERSION_ID >= 80200 ? 16 : 32, $slot, 'packed array slot of this PHP');
    sbs_assert_true(sbs_call('cdr_growth', 262144) >= 2 * 262144 * $slot, '262144 records: 8 MB block, 16 MB on PHP 8.1');
    sbs_assert_same(
        sbs_call('cdr_growth', 131072),
        sbs_call('zip_memory_needed', 100, 131072) - sbs_call('zip_memory_needed', 100, 131071),
        'zip_memory_needed adds the growth'
    );
});

sbs_test('backup: the largest file that fits keeps the margin and the note reserve free', function () {
    $mb      = 1048576;
    $reserve = sbs_const('ZIP_MEMORY_MARGIN') + sbs_const('ZIP_NOTE_RESERVE');
    sbs_assert_same(10 * $mb, sbs_call('largest_zip_file', 10 * $mb + $reserve));
    sbs_assert_null(sbs_call('largest_zip_file', 33 * $mb + $reserve), 'any file fits');
    sbs_assert_same(0, sbs_call('largest_zip_file', 0));
});

sbs_test('backup: the memory row warns when the largest files may not fit and blocks only below the minimum', function () {
    $mb      = 1048576;
    $minimum = sbs_const('ZIP_MEMORY_MINIMUM');

    $row = sbs_call('memory_check_result', null, null);
    sbs_assert_same(['ok', false], [$row['status'], $row['critical']], 'no limit');

    $row = sbs_call('memory_check_result', $minimum - 1, 128 * $mb);
    sbs_assert_same(['bad', true, 'files'], [$row['status'], $row['critical'], $row['scope']], 'below the minimum');

    $row = sbs_call('memory_check_result', 14 * $mb, 128 * $mb);
    sbs_assert_same(['warning', false], [$row['status'], $row['critical']], 'short for large files');
    sbs_assert_true(str_contains($row['message'], 'backup-incomplete.txt') && str_contains($row['message'], '128 MB'), $row['message']);
    sbs_assert_true(sbs_call('checks_are_ready', [$row], 'files'), 'a warning does not block the download');

    $row = sbs_call('memory_check_result', 200 * $mb, 256 * $mb);
    sbs_assert_same(['ok', false], [$row['status'], $row['critical']], 'enough for any file');
    sbs_assert_true(str_contains($row['message'], '256 MB') && str_contains($row['message'], '56 MB'), $row['message']);
});

sbs_test('backup: files that do not fit into memory are left out and listed, the archive stays valid', function () {
    if (!class_exists('ZipArchive')) {
        sbs_skip('ext-zip is not available');
    }
    $root      = sbs_backup_tree();
    $saved     = ['memory_limit' => ini_get('memory_limit'), 'error_log' => ini_get('error_log')];
    $left_out  = null;
    $log       = (string) tempnam(sys_get_temp_dir(), 'sbs-log-');
    try {
        $big = fopen($root . '/c/big.bin', 'w');
        ftruncate($big, 20 * 1048576); // sparse: needs 20 MB of memory to archive, almost no disk
        fclose($big);
        ini_set('error_log', $log);

        $file = sbs_zip_to_file(function ($zip) use ($root, &$left_out) {
            ini_set('memory_limit', (string) (memory_get_usage(true) + sbs_const('ZIP_MEMORY_MINIMUM') + 2 * 1048576));
            $left_out = sbs_call('write_files_zip', $zip, [$root . '/' => 'site/']);
        });
        ini_set('memory_limit', $saved['memory_limit']);

        sbs_assert_same(1, $left_out['count']);
        sbs_assert_same([['site/c/big.bin', 20 * 1048576]], $left_out['files']);
        $archive = new ZipArchive();
        sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true, 'archive is consistent');
        sbs_assert_same(5, $archive->numFiles, 'four small files and the note');
        sbs_assert_false($archive->statName('site/c/big.bin'), 'big file left out');
        $note = (string) $archive->getFromName('backup-incomplete.txt');
        sbs_assert_true(str_contains($note, "site/c/big.bin") && str_contains($note, 'memory_limit'), $note);
        $archive->close();
        @unlink($file);
        sbs_assert_true(str_contains((string) @file_get_contents($log), '1 files (20971520 bytes) left out'), 'error_log line');

        $file = sbs_zip_to_file(function ($zip) use ($root, &$left_out) {
            $left_out = sbs_call('write_files_zip', $zip, [$root . '/' => 'site/']);
        });
        $archive = new ZipArchive();
        sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true);
        sbs_assert_same([0, 5, false], [$left_out['count'], $archive->numFiles, $archive->statName('backup-incomplete.txt')], 'with enough memory everything goes in, no note');
        $archive->close();
        @unlink($file);
    } finally {
        ini_set('memory_limit', $saved['memory_limit']);
        ini_set('error_log', (string) $saved['error_log']);
        @unlink($log);
        sbs_rm_tree($root);
    }
});

sbs_test('backup: backup-incomplete.txt still fits when not even small files do', function () {
    if (!class_exists('ZipArchive')) {
        sbs_skip('ext-zip is not available');
    }
    $root     = sbs_backup_tree();
    $saved    = ['memory_limit' => ini_get('memory_limit'), 'error_log' => ini_get('error_log')];
    $log      = (string) tempnam(sys_get_temp_dir(), 'sbs-log-');
    $left_out = null;
    try {
        ini_set('error_log', $log);
        $file = sbs_zip_to_file(function ($zip) use ($root, &$left_out) {
            // Every file needs margin + reserve (8 MB+) and is left out; the note gets only about the reserve.
            sbs_assert_true(sbs_const('ZIP_NOTE_RESERVE') >= 4194304, 'note reserve');
            ini_set('memory_limit', (string) (memory_get_usage(true) + 4194304 + 262144));
            $left_out = sbs_call('write_files_zip', $zip, [$root . '/' => 'site/']);
        });
        ini_set('memory_limit', $saved['memory_limit']);

        sbs_assert_same(4, $left_out['count']);
        $archive = new ZipArchive();
        sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true, 'archive is consistent');
        sbs_assert_same(1, $archive->numFiles, 'only the note');
        $note = (string) $archive->getFromName('backup-incomplete.txt');
        sbs_assert_true(str_contains($note, 'site/a/b/f3.txt') && str_contains($note, ': 4 ('), $note);
        $archive->close();
        @unlink($file);
    } finally {
        ini_set('memory_limit', $saved['memory_limit']);
        ini_set('error_log', (string) $saved['error_log']);
        @unlink($log);
        sbs_rm_tree($root);
    }
});

sbs_test('backup: file dates go in UTC, so a non-UTC default timezone breaks neither end of the DOS range', function () {
    if (!class_exists('ZipArchive')) {
        sbs_skip('ext-zip is not available');
    }
    $root = sbs_backup_tree();
    try {
        touch($root . '/c/late.txt', 4354812000);  // 2107-12-31 22:00 UTC: 2108 in Tokyo
        touch($root . '/c/early.txt', 315532800);  // 1980-01-01 00:00 UTC: 1979 in New York
        foreach (['Asia/Tokyo', 'America/New_York'] as $zone) {
            date_default_timezone_set($zone);
            $file = sbs_zip_to_file(function ($zip) use ($root) {
                foreach (['late', 'early'] as $name) {
                    $path = $root . "/c/$name.txt";
                    sbs_call('add_file_to_zip', $zip, $path, "site/c/$name.txt", new SplFileInfo($path));
                }
            });
            date_default_timezone_set('UTC');
            $archive = new ZipArchive();
            sbs_assert_true($archive->open($file, ZipArchive::CHECKCONS) === true, "$zone: archive is consistent");
            // libzip reads DOS time in the system timezone, so allow a day; the bug gave an exception or 2107 for 1980.
            sbs_assert_true(abs($archive->statName('site/c/late.txt')['mtime'] - 4354812000) <= 86400, "$zone: late date");
            sbs_assert_true(abs($archive->statName('site/c/early.txt')['mtime'] - 315532800) <= 86400, "$zone: early date");
            $archive->close();
            @unlink($file);
        }
    } finally {
        date_default_timezone_set('UTC');
        sbs_rm_tree($root);
    }
});

sbs_test('backup: an output buffer that cannot be removed stops the download with an error instead of looping', function () {
    $script = tempnam(sys_get_temp_dir(), 'sbs-ob-') . '.php';
    file_put_contents($script, '<?php require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';'
        . ' ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);'
        . ' try { sbs_call("prepare_streaming_response"); fwrite(STDERR, "returned"); }'
        . ' catch (RuntimeException $e) { fwrite(STDERR, "refused: " . $e->getMessage()); }');
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    try {
        stream_set_blocking($pipes[2], false);
        $stderr   = '';
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && proc_get_status($process)['running']) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            usleep(50000);
        }
        $stderr .= (string) stream_get_contents($pipes[2]);
        $running = proc_get_status($process)['running'];
        if ($running) {
            proc_terminate($process, 9);
        }
        sbs_assert_false($running, 'prepare_streaming_response() still running after 20 s');
        sbs_assert_true(str_contains($stderr, 'refused: ') && str_contains($stderr, 'буфер виводу'), $stderr);
    } finally {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        @unlink(substr($script, 0, -4));
    }
});

sbs_test('backup: the bundled vendor matches the APIs the export uses', function () {
    sbs_call('load_dependencies'); // throws on an incompatible ZipStream or mysqldump-php
    sbs_assert_true(class_exists('ZipStream\\ZipStream') && class_exists('Ifsnop\\Mysqldump\\Mysqldump'));
});
