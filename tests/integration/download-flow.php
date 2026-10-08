<?php
/**
 * The real download handler inside WordPress: streams the site files ZIP or the SQL dump to stdout.
 *
 *   wp eval-file tests/integration/download-flow.php files > site.zip 2> download.log
 *   wp eval-file tests/integration/download-flow.php database > site.sql 2> download.log
 *
 * Runs as the first administrator; the plugin does not have to be active. For "files" download.log gets
 * the number of files the same directory walk finds, so compare it with `zipinfo -1 site.zip | wc -l` and
 * run `unzip -t site.zip`; a complete dump ends with "-- Dump completed on …" (`tail -1 site.sql`).
 * Run WP-CLI with a kill timer: it sets memory_limit = -1.
 */

if (!defined('ABSPATH')) exit(1);

if (!class_exists('SBS_Site_Backup_Streamer')) {
    require WP_PLUGIN_DIR . '/site-backup-streamer/site-backup-streamer.php';
}

$type = (string) ($args[0] ?? '');
if (!in_array($type, ['files', 'database'], true)) {
    fwrite(STDERR, "Usage: wp eval-file tests/integration/download-flow.php files|database > out 2> log\n");
    exit(1);
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (!$admins) {
    fwrite(STDERR, "No administrator found.\n");
    exit(1);
}
wp_set_current_user((int) $admins[0]);

if ($type === 'files') {
    $call  = function (string $method, ...$params) {
        return (new ReflectionMethod('SBS_Site_Backup_Streamer', $method))->invoke(null, ...$params);
    };
    $count = 0;
    $stats = $call('walk_backup_files', $call('backup_directories'), function () use (&$count) {
        $count++;
        return true;
    });
    fwrite(STDERR, sprintf("walk: %d files, %d directories, %d skipped\n", $count, $stats['directories'], $stats['skipped']));
}

$_GET['type']         = $type;
$_REQUEST['_wpnonce'] = wp_create_nonce('sbs_download_backup_' . $type);
fwrite(STDERR, sprintf("PHP %s, plugin %s, streaming %s…\n", PHP_VERSION, SBS_VERSION, $type));

SBS_Site_Backup_Streamer::handle_download_request(); // exits when the stream is finished
