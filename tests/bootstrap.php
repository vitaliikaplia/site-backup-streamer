<?php
/**
 * Test bootstrap: a tiny assertion framework plus the minimal WordPress stubs needed to load the
 * plugin and exercise its WordPress-independent logic. No WordPress, no database.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
// Some PHP builds (e.g. macOS hardened runtime) cannot allocate PCRE JIT memory and warn on the first
// preg_*() call; run.php turns that warning into a failure. The tests do not need the JIT.
ini_set('pcre.jit', '0');
date_default_timezone_set('UTC');

define('SBS_TEST_ROOT', dirname(__DIR__));

// ── WordPress constants & stubs (only what the loaded code touches) ──────────

if (!defined('ABSPATH'))         define('ABSPATH', sys_get_temp_dir() . '/sbs-fake-wp/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('MB_IN_BYTES'))     define('MB_IN_BYTES', 1048576);

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

$GLOBALS['sbs_test_filters'] = [];
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['sbs_test_filters'][$hook][] = $callback; return true; }
function add_action($hook, $callback, $priority = 10, $args = 1) { return add_filter($hook, $callback, $priority, $args); }
function apply_filters($hook, $value) { return $value; }
function do_action($hook) {}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function __($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
function esc_url($url) {
    $url = trim((string) $url);
    if (!preg_match('#^https?://#i', $url)) return '';
    return str_replace(['&', "'", '"', '<', '>'], ['&#038;', '&#039;', '&quot;', '', ''], $url);
}
function trailingslashit($value) { return rtrim((string) $value, '/\\') . '/'; }
function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function home_url($path = '') { return 'https://example.test' . $path; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function get_site_transient($key) { return false; }
function set_site_transient($key, $value, $ttl = 0) { return true; }
function delete_site_transient($key) { return true; }
function is_admin() { return false; }
function is_multisite() { return false; }
function current_user_can($cap) { return false; }
function wp_delete_file($file) { @unlink($file); }
function wp_convert_hr_to_bytes($value) {
    $value = strtolower(trim((string) $value));
    $bytes = (int) $value;
    if (str_contains($value, 'g')) $bytes *= 1024 ** 3;
    elseif (str_contains($value, 'm')) $bytes *= 1024 ** 2;
    elseif (str_contains($value, 'k')) $bytes *= 1024;
    return min($bytes, PHP_INT_MAX);
}
function size_format($bytes, $decimals = 0) { return round($bytes / 1048576, $decimals) . ' MB'; }

// ── Load the units under test ────────────────────────────────────────────────

foreach ([
    'site-backup-streamer.php', // defines SBS_* constants and requires the updater
    'bin/release-lib.php',
] as $file) {
    require_once SBS_TEST_ROOT . '/' . $file;
}

// ── Mini test framework ──────────────────────────────────────────────────────

class SBS_Test_Failure extends Exception {}
class SBS_Test_Skipped extends Exception {}

$GLOBALS['sbs_tests'] = [];

/** Registers a test case. */
function sbs_test(string $name, callable $fn): void {
    $GLOBALS['sbs_tests'][] = [$name, $fn];
}

function sbs_skip(string $reason): void {
    throw new SBS_Test_Skipped($reason);
}

function sbs_export($value): string {
    return str_replace("\n", ' ', var_export($value, true));
}

function sbs_assert_same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new SBS_Test_Failure(($message !== '' ? $message . ': ' : '') . 'expected ' . sbs_export($expected) . ', got ' . sbs_export($actual));
    }
}

function sbs_assert_true($actual, string $message = ''): void {
    sbs_assert_same(true, $actual, $message);
}

function sbs_assert_false($actual, string $message = ''): void {
    sbs_assert_same(false, $actual, $message);
}

function sbs_assert_null($actual, string $message = ''): void {
    sbs_assert_same(null, $actual, $message);
}

/** Calls a private static method of the plugin class. */
function sbs_call(string $method, ...$args) {
    return (new ReflectionMethod('SBS_Site_Backup_Streamer', $method))->invoke(null, ...$args);
}

function sbs_temp_dir(string $prefix): string {
    $dir = sys_get_temp_dir() . '/sbs-test-' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    return realpath($dir);
}

function sbs_rm_tree(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    @chmod($dir, 0755);
    foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? sbs_rm_tree($path) : @unlink($path);
    }
    @rmdir($dir);
}
