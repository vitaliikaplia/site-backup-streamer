<?php
/**
 * Updater inside a real WordPress, with GitHub mocked through pre_http_request.
 *
 *   wp eval-file tests/integration/updater-flow.php /path/to/site-backup-streamer.zip
 *
 * The zip is a built release package (see .github/workflows/release.yml). Checks the update offer, the
 * "View details" data and the SHA-256 check of the downloaded package (match and mismatch). Makes no
 * network calls and installs nothing; only the updater's own cache transient is written and removed.
 */

if (!defined('ABSPATH')) exit(1);

if (!class_exists('SBS_GitHub_Updater')) {
    require WP_PLUGIN_DIR . '/site-backup-streamer/site-backup-streamer.php';
}

$zip = (string) ($args[0] ?? '');
if (!is_file($zip)) {
    WP_CLI::error('Usage: wp eval-file tests/integration/updater-flow.php <site-backup-streamer.zip>');
}

$tag      = 'v9.9.9';
$download = 'https://github.com/vitaliikaplia/site-backup-streamer/releases/download/' . $tag . '/';
$checksum = hash_file('sha256', $zip) . '  site-backup-streamer.zip';
$requests = [];

$mock = function ($pre, $request, $url) use ($zip, $tag, $download, &$checksum, &$requests) {
    $requests[] = $url;
    $reply      = function (string $body, int $code = 200) {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    if ($url === 'https://api.github.com/repos/vitaliikaplia/site-backup-streamer/releases/latest') {
        return $reply(wp_json_encode([
            'tag_name'     => $tag,
            'draft'        => false,
            'prerelease'   => false,
            'html_url'     => 'https://github.com/vitaliikaplia/site-backup-streamer/releases/tag/' . $tag,
            'published_at' => '2026-10-07T12:00:00Z',
            'body'         => "- **Виправлено:** тестовий реліз\n",
            'assets'       => [
                ['name' => 'site-backup-streamer.zip', 'state' => 'uploaded', 'browser_download_url' => $download . 'site-backup-streamer.zip'],
                ['name' => 'site-backup-streamer.zip.sha256', 'state' => 'uploaded', 'browser_download_url' => $download . 'site-backup-streamer.zip.sha256'],
            ],
        ]));
    }
    if ($url === 'https://raw.githubusercontent.com/vitaliikaplia/site-backup-streamer/' . $tag . '/site-backup-streamer.php') {
        return $reply("<?php\n/**\n * Plugin Name: Site Backup Streamer\n * Version: 9.9.9\n * Requires at least: 6.0\n * Requires PHP: 8.3\n * Tested up to: 7.0\n */\n");
    }
    if ($url === $download . 'site-backup-streamer.zip.sha256') {
        return $reply($checksum);
    }
    if ($url === $download . 'site-backup-streamer.zip') {
        if (!empty($request['stream']) && !empty($request['filename'])) {
            copy($zip, $request['filename']); // download_url() streams into this temp file
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $request['filename']];
        }
        return $reply((string) file_get_contents($zip));
    }
    return new WP_Error('sbs_test_offline', 'Unexpected request in the updater test: ' . $url);
};
add_filter('pre_http_request', $mock, 10, 3);

$fail = function (string $message) {
    delete_site_transient('sbs_github_update_data');
    WP_CLI::error($message);
};

delete_site_transient('sbs_github_update_data');
$updater = new SBS_GitHub_Updater();

// 1. The update offer.
$transient = $updater->refresh_update_plugins((object) ['checked' => [SBS_BASENAME => SBS_VERSION]]);
$offer     = $transient->response[SBS_BASENAME] ?? null;
if (!$offer || $offer->new_version !== '9.9.9' || $offer->package !== $download . 'site-backup-streamer.zip' || ($offer->requires_php ?? '') !== '8.3') {
    $fail('No correct update offer: ' . wp_json_encode($transient));
}
WP_CLI::log('ok  update offered: ' . SBS_VERSION . ' → ' . $offer->new_version . ', package ' . basename($offer->package));

// 2. A cached read makes no new request.
$before = count($requests);
(new SBS_GitHub_Updater())->read_update_plugins((object) ['checked' => [SBS_BASENAME => SBS_VERSION]]);
if (count($requests) !== $before) {
    $fail('Reading the transient went to the network');
}
WP_CLI::log('ok  transient read uses the cache only');

// 3. "View details".
$info = $updater->filter_plugins_api(false, 'plugin_information', (object) ['slug' => dirname(SBS_BASENAME)]);
if (!is_object($info) || $info->version !== '9.9.9' || !str_contains($info->sections['changelog'], 'тестовий реліз')) {
    $fail('Wrong plugins_api answer: ' . wp_json_encode($info));
}
WP_CLI::log('ok  "View details" shows ' . $info->version . ' with the release notes');

// 4. Package with a matching checksum.
$file = $updater->verify_package(false, $offer->package, null, ['plugin' => SBS_BASENAME]);
if (is_wp_error($file) || !is_file($file) || hash_file('sha256', $file) !== hash_file('sha256', $zip)) {
    $fail('Matching package rejected: ' . (is_wp_error($file) ? $file->get_error_message() : wp_json_encode($file)));
}
wp_delete_file($file);
WP_CLI::log('ok  package with a matching SHA-256 accepted');

// 5. Package with a wrong checksum.
$checksum = str_repeat('0', 64) . '  site-backup-streamer.zip';
$tmp_before = glob(get_temp_dir() . '*.tmp') ?: [];
$result     = $updater->verify_package(false, $offer->package, null, ['plugin' => SBS_BASENAME]);
$tmp_after  = glob(get_temp_dir() . '*.tmp') ?: [];
if (!is_wp_error($result) || $result->get_error_code() !== 'sbs_checksum_mismatch') {
    $fail('Wrong checksum was not refused: ' . wp_json_encode($result));
}
if (array_diff($tmp_after, $tmp_before)) {
    $fail('The refused package was left on disk');
}
WP_CLI::log('ok  package with a wrong SHA-256 refused and deleted: ' . $result->get_error_message());

remove_filter('pre_http_request', $mock, 10);
delete_site_transient('sbs_github_update_data');
WP_CLI::success('Updater flow OK (' . count($requests) . ' mocked requests).');
