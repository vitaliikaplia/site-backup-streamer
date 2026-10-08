<?php
/**
 * The real download handler inside WordPress: streams the site files ZIP or the SQL dump to stdout.
 *
 *   wp eval-file tests/integration/download-flow.php files > site.zip 2> download.log
 *   wp eval-file tests/integration/download-flow.php database > site.sql 2> download.log
 *   wp --exec='define("WP_MAX_MEMORY_LIMIT", "96M");' eval-file tests/integration/download-flow.php files 96M > site.zip 2> download.log
 *
 * Runs as the first administrator; the plugin does not have to be active. For "files" download.log gets
 * the number of files the same directory walk finds, so compare it with `zipinfo -1 site.zip | wc -l` and
 * run `unzip -t site.zip`; a complete dump ends with "-- Dump completed on …" (`tail -1 site.sql`).
 * Run WP-CLI with a kill timer: it sets memory_limit = -1. The optional second argument acts like a host with a
 * hard memory_limit; WP_MAX_MEMORY_LIMIT must not be higher (define it with --exec, before wp-config.php), or the
 * plugin raises the limit to it. Files that do not fit are left out and listed in backup-incomplete.txt.
 */

if (!defined('ABSPATH')) exit(1);

if (!class_exists('SBS_Site_Backup_Streamer')) {
    require WP_PLUGIN_DIR . '/site-backup-streamer/site-backup-streamer.php';
}

$type = (string) ($args[0] ?? '');
if (!in_array($type, ['files', 'database'], true)) {
    fwrite(STDERR, "Usage: wp eval-file tests/integration/download-flow.php files|database [memory_limit] > out 2> log\n");
    exit(1);
}

$limit = (string) ($args[1] ?? '');
if ($limit !== '') {
    if (ini_set('memory_limit', $limit) === false) {
        fwrite(STDERR, "memory_limit $limit is below the current usage of " . memory_get_usage(true) . " bytes.\n");
        exit(1);
    }
    wp_raise_memory_limit('admin'); // the same call the plugin makes before the export
    if (wp_convert_hr_to_bytes((string) ini_get('memory_limit')) !== wp_convert_hr_to_bytes($limit)) {
        fwrite(STDERR, 'WP_MAX_MEMORY_LIMIT ' . WP_MAX_MEMORY_LIMIT . " (or an admin_memory_limit filter) raises $limit to " . ini_get('memory_limit') . ". Define WP_MAX_MEMORY_LIMIT with --exec.\n");
        exit(1);
    }
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
fwrite(STDERR, sprintf("PHP %s, plugin %s, memory_limit %s, streaming %s…\n", PHP_VERSION, SBS_VERSION, ini_get('memory_limit'), $type));

SBS_Site_Backup_Streamer::handle_download_request(); // exits when the stream is finished
