<?php
/**
 * GitHub Releases updater: tag/asset/checksum/header parsing, release-notes rendering and the
 * package directory normalisation. Network and WordPress-hook behaviour is not covered here.
 */

const SBS_TEST_RELEASES = 'https://github.com/vitaliikaplia/site-backup-streamer/releases';

/** A releases/latest payload shaped like the GitHub API response. */
function sbs_release_fixture(array $override = []): array {
    $dir = SBS_TEST_RELEASES . '/download/v1.1.0/';
    return array_merge([
        'tag_name'     => 'v1.1.0',
        'name'         => '1.1.0',
        'draft'        => false,
        'prerelease'   => false,
        'html_url'     => SBS_TEST_RELEASES . '/tag/v1.1.0',
        'published_at' => '2026-10-07T12:00:00Z',
        'body'         => "- **Fixed:** something\n",
        'assets'       => [
            ['name' => 'site-backup-streamer.zip', 'state' => 'uploaded', 'browser_download_url' => $dir . 'site-backup-streamer.zip'],
            ['name' => 'site-backup-streamer.zip.sha256', 'state' => 'uploaded', 'browser_download_url' => $dir . 'site-backup-streamer.zip.sha256'],
        ],
    ], $override);
}

sbs_test('updater: version_from_tag', function () {
    sbs_assert_same('1.1.0', SBS_GitHub_Updater::version_from_tag('v1.1.0'));
    sbs_assert_same('1.1.0', SBS_GitHub_Updater::version_from_tag('1.1.0'));
    sbs_assert_same('1.1.0', SBS_GitHub_Updater::version_from_tag(' V1.1.0 '));
    sbs_assert_same('1.1', SBS_GitHub_Updater::version_from_tag('v1.1'));
    sbs_assert_same('1.1.0-rc.1', SBS_GitHub_Updater::version_from_tag('v1.1.0-rc.1'));
    foreach (['', 'v', 'latest', 'release-1.1.0', 'v1', '1.1.0 beta', 'v1.1.0/../x', 'vv1.1.0'] as $bad) {
        sbs_assert_null(SBS_GitHub_Updater::version_from_tag($bad), var_export($bad, true));
    }
});

sbs_test('updater: parse_release extracts version, immutable assets and notes', function () {
    $release = SBS_GitHub_Updater::parse_release(sbs_release_fixture());
    sbs_assert_same('1.1.0', $release['version']);
    sbs_assert_same('v1.1.0', $release['tag']);
    sbs_assert_same(SBS_TEST_RELEASES . '/download/v1.1.0/site-backup-streamer.zip', $release['package']);
    sbs_assert_same(SBS_TEST_RELEASES . '/download/v1.1.0/site-backup-streamer.zip.sha256', $release['checksum_url']);
    sbs_assert_same(SBS_TEST_RELEASES . '/tag/v1.1.0', $release['url']);
    sbs_assert_same("- **Fixed:** something\n", $release['body']);
    sbs_assert_same('2026-10-07T12:00:00Z', $release['published']);
});

sbs_test('updater: parse_release fails closed', function () {
    $dir = SBS_TEST_RELEASES . '/download/v1.1.0/';
    $zip = ['name' => 'site-backup-streamer.zip', 'state' => 'uploaded', 'browser_download_url' => $dir . 'site-backup-streamer.zip'];
    $sum = ['name' => 'site-backup-streamer.zip.sha256', 'state' => 'uploaded', 'browser_download_url' => $dir . 'site-backup-streamer.zip.sha256'];
    $cases = [
        'draft'                 => ['draft' => true],
        'prerelease'            => ['prerelease' => true],
        'bad tag'               => ['tag_name' => 'latest'],
        'no assets'             => ['assets' => []],
        'zip only'              => ['assets' => [$zip]],
        'checksum only'         => ['assets' => [$sum]],
        'asset still uploading' => ['assets' => [array_merge($zip, ['state' => 'starter']), $sum]],
        'asset of another repo' => ['assets' => [array_merge($zip, ['browser_download_url' => 'https://github.com/evil/site-backup-streamer/releases/download/v1.1.0/site-backup-streamer.zip']), $sum]],
        'asset of another tag'  => ['assets' => [array_merge($zip, ['browser_download_url' => SBS_TEST_RELEASES . '/download/v1.0.0/site-backup-streamer.zip']), $sum]],
        'asset off github.com'  => ['assets' => [array_merge($zip, ['browser_download_url' => 'https://example.com/site-backup-streamer.zip']), $sum]],
        'garbage assets'        => ['assets' => ['x', null, 5]],
    ];
    foreach ($cases as $label => $override) {
        sbs_assert_null(SBS_GitHub_Updater::parse_release(sbs_release_fixture($override)), $label);
    }
    sbs_assert_null(SBS_GitHub_Updater::parse_release([]), 'empty payload (e.g. 404 body)');
    sbs_assert_null(SBS_GitHub_Updater::parse_release(['message' => 'Not Found']), 'API error payload');
});

sbs_test('updater: parse_release ignores a foreign html_url and caps the body', function () {
    $release = SBS_GitHub_Updater::parse_release(sbs_release_fixture([
        'html_url' => 'https://evil.example/',
        'body'     => str_repeat('я', 40000), // 80 000 bytes
    ]));
    sbs_assert_same('https://github.com/vitaliikaplia/site-backup-streamer', $release['url']);
    sbs_assert_true(strlen($release['body']) <= 65536, 'body capped at 64 KB');
    sbs_assert_true(preg_match('//u', $release['body']) === 1, 'cut stays valid UTF-8');
});

sbs_test('updater: checksum_url_for accepts only release assets of this repo', function () {
    $pkg = SBS_TEST_RELEASES . '/download/v1.1.0/site-backup-streamer.zip';
    sbs_assert_same($pkg . '.sha256', SBS_GitHub_Updater::checksum_url_for($pkg));
    foreach ([
        'https://github.com/vitaliikaplia/site-backup-streamer/archive/refs/heads/master.zip',
        SBS_TEST_RELEASES . '/download/v1.1.0/other.zip',
        SBS_TEST_RELEASES . '/download/v1.1.0/site-backup-streamer.zip?x=1',
        SBS_TEST_RELEASES . '/download/a/b/site-backup-streamer.zip',
        'https://github.com/evil/site-backup-streamer/releases/download/v1.1.0/site-backup-streamer.zip',
        'http://github.com/vitaliikaplia/site-backup-streamer/releases/download/v1.1.0/site-backup-streamer.zip',
        'https://downloads.wordpress.org/plugin/site-backup-streamer.zip',
        '/tmp/site-backup-streamer.zip',
        '',
    ] as $url) {
        sbs_assert_null(SBS_GitHub_Updater::checksum_url_for($url), $url);
    }
});

sbs_test('updater: parse_checksum (sha256sum format)', function () {
    $file = 'site-backup-streamer.zip';
    $hex  = hash('sha256', 'site-backup-streamer');
    sbs_assert_same($hex, SBS_GitHub_Updater::parse_checksum("$hex  $file\n", $file), 'text mode');
    sbs_assert_same($hex, SBS_GitHub_Updater::parse_checksum("$hex *$file", $file), 'binary mode');
    sbs_assert_same($hex, SBS_GitHub_Updater::parse_checksum(strtoupper($hex) . "  $file\r\n", $file), 'upper case + CRLF');
    sbs_assert_same($hex, SBS_GitHub_Updater::parse_checksum($hex, $file), 'bare hash');
    sbs_assert_same($hex, SBS_GitHub_Updater::parse_checksum(hash('sha256', 'x') . "  other.zip\n$hex  $file\n", $file), 'multi-line list');
    sbs_assert_null(SBS_GitHub_Updater::parse_checksum("$hex  other.zip", $file), 'another file');
    sbs_assert_null(SBS_GitHub_Updater::parse_checksum(substr($hex, 1) . "  $file", $file), 'short hash');
    sbs_assert_null(SBS_GitHub_Updater::parse_checksum(md5('x') . "  $file", $file), 'md5');
    sbs_assert_null(SBS_GitHub_Updater::parse_checksum('<html>Not Found</html>', $file));
    sbs_assert_null(SBS_GitHub_Updater::parse_checksum('', $file));
});

sbs_test('updater: parse_headers reads the plugin header like get_file_data', function () {
    $php = "<?php\n/**\n * Plugin Name: Site Backup Streamer\n * Version: 1.1.0\n * Requires at least: 6.0\n * Requires PHP: 8.3\n * Tested up to: 7.0\n */\n";
    sbs_assert_same(['version' => '1.1.0', 'requires' => '6.0', 'requires_php' => '8.3', 'tested' => '7.0'], SBS_GitHub_Updater::parse_headers($php));
    sbs_assert_same('', SBS_GitHub_Updater::parse_headers("<?php\n// nothing\n")['version']);
    sbs_assert_same('1.0', SBS_GitHub_Updater::parse_headers("<?php /* Version: 1.0 */")['version'], 'closing comment stripped');
});

sbs_test('updater: parse_headers of the real site-backup-streamer.php matches the constant', function () {
    $headers = SBS_GitHub_Updater::parse_headers((string) file_get_contents(SBS_FILE));
    sbs_assert_same(SBS_VERSION, $headers['version']);
    sbs_assert_true($headers['tested'] !== '', 'Tested up to header present');
    sbs_assert_same('8.3', $headers['requires_php']);
});

sbs_test('updater: the plugin declares Update URI', function () {
    $php = (string) file_get_contents(SBS_FILE);
    sbs_assert_true(preg_match('/^[ \t\/*#@]*Update URI:\s*https:\/\/github\.com\/vitaliikaplia\/site-backup-streamer\s*$/mi', $php) === 1);
});

sbs_test('updater: markdown_to_html renders release notes safely', function () {
    $md = "### 1.1.0 — Title\n\n- **Fixed:** `walk_backup_files` <b>x</b>\n  continued line\n- see [docs](https://example.com/a?b=1&c=2)\n\nPlain <script>alert(1)</script> text\nsecond line\n";
    $expected = '<h4>1.1.0 — Title</h4>'
        . '<ul><li><strong>Fixed:</strong> <code>walk_backup_files</code> &lt;b&gt;x&lt;/b&gt; continued line</li>'
        . '<li>see <a href="https://example.com/a?b=1&#038;c=2">docs</a></li></ul>'
        . '<p>Plain &lt;script&gt;alert(1)&lt;/script&gt; text second line</p>';
    sbs_assert_same($expected, SBS_GitHub_Updater::markdown_to_html($md));
});

sbs_test('updater: markdown_to_html refuses non-http links and escapes code', function () {
    sbs_assert_same('<p>[x](javascript:alert(1))</p>', SBS_GitHub_Updater::markdown_to_html('[x](javascript:alert(1))'));
    sbs_assert_same('<p><code>&lt;a href=&quot;x&quot;&gt;</code></p>', SBS_GitHub_Updater::markdown_to_html('`<a href="x">`'));
    sbs_assert_same('<p>a</p><p>b</p>', SBS_GitHub_Updater::markdown_to_html("a\n\n\nb"));
    sbs_assert_same('', SBS_GitHub_Updater::markdown_to_html(''));
});

sbs_test('updater: normalize_github_source_directory handles release and branch archives', function () {
    $updater = new SBS_GitHub_Updater();
    $extra   = ['plugin' => SBS_BASENAME, 'type' => 'plugin', 'action' => 'update'];
    $GLOBALS['wp_filesystem'] = null;

    $work = sbs_temp_dir('release');
    try {
        // Release zip: top-level "site-backup-streamer/" already matches the slug — left as is.
        mkdir($work . '/site-backup-streamer');
        sbs_assert_same($work . '/site-backup-streamer/', $updater->normalize_github_source_directory($work . '/site-backup-streamer/', $work, null, $extra));
    } finally {
        sbs_rm_tree($work);
    }

    $work = sbs_temp_dir('branch');
    try {
        // Branch archive: "site-backup-streamer-master/" is renamed to the slug.
        mkdir($work . '/site-backup-streamer-master');
        file_put_contents($work . '/site-backup-streamer-master/site-backup-streamer.php', '<?php');
        sbs_assert_same($work . '/site-backup-streamer/', $updater->normalize_github_source_directory($work . '/site-backup-streamer-master/', $work, null, $extra));
        sbs_assert_true(is_file($work . '/site-backup-streamer/site-backup-streamer.php'));
        sbs_assert_false(is_dir($work . '/site-backup-streamer-master'));
    } finally {
        sbs_rm_tree($work);
    }

    $work = sbs_temp_dir('foreign');
    try {
        // Another plugin's upgrade, or an unrelated directory name — untouched.
        mkdir($work . '/other-plugin');
        sbs_assert_same($work . '/other-plugin/', $updater->normalize_github_source_directory($work . '/other-plugin/', $work, null, ['plugin' => 'other/other.php']));
        sbs_assert_same($work . '/other-plugin/', $updater->normalize_github_source_directory($work . '/other-plugin/', $work, null, $extra));
    } finally {
        sbs_rm_tree($work);
    }

    $error = new WP_Error('x', 'y');
    sbs_assert_same($error, $updater->normalize_github_source_directory($error, '', null, $extra));
});

sbs_test('updater: verify_package ignores foreign packages', function () {
    $updater = new SBS_GitHub_Updater();
    sbs_assert_false($updater->verify_package(false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, ['plugin' => 'akismet/akismet.php']));
    sbs_assert_same('/tmp/x.zip', $updater->verify_package('/tmp/x.zip', '/tmp/x.zip', null, ['type' => 'plugin', 'action' => 'install']));
    $error = new WP_Error('x', 'y');
    sbs_assert_same($error, $updater->verify_package($error, SBS_TEST_RELEASES . '/download/v1.1.0/site-backup-streamer.zip', null, ['plugin' => SBS_BASENAME]));
});

sbs_test('updater: without GitHub data only release-asset entries survive for this plugin', function () {
    if (defined('SBS_UPDATE_CHANNEL')) {
        sbs_skip('SBS_UPDATE_CHANNEL is defined in this environment');
    }
    $updater = new SBS_GitHub_Updater(); // test stubs: empty cache, no network on reads
    $entry   = function ($package) { return (object) ['package' => $package, 'new_version' => '99.0']; };
    $release = SBS_TEST_RELEASES . '/download/v1.1.0/site-backup-streamer.zip';
    foreach ([
        'wordpress.org package'      => ['https://downloads.wordpress.org/plugin/site-backup-streamer.99.0.zip', false],
        'branch zip of this repo'    => ['https://github.com/vitaliikaplia/site-backup-streamer/archive/refs/heads/master.zip', false],
        'API zipball of this repo'   => ['https://api.github.com/repos/vitaliikaplia/site-backup-streamer/zipball/v99.0', false],
        'lookalike repository'       => ['https://github.com/vitaliikaplia/site-backup-streamer-evil/releases/download/v9/site-backup-streamer.zip', false],
        'release asset of this repo' => [$release, true],
    ] as $label => [$package, $kept]) {
        $t = (object) [
            'checked'   => [SBS_BASENAME => '1.0.0', 'akismet/akismet.php' => '5.0'],
            'response'  => [SBS_BASENAME => $entry($package), 'akismet/akismet.php' => $entry('https://downloads.wordpress.org/plugin/akismet.zip')],
            'no_update' => [SBS_BASENAME => $entry($package)],
        ];
        $t = $updater->read_update_plugins($t);
        sbs_assert_same($kept, isset($t->response[SBS_BASENAME]), "$label (response)");
        sbs_assert_same($kept, isset($t->no_update[SBS_BASENAME]), "$label (no_update)");
        sbs_assert_true(isset($t->response['akismet/akismet.php']), "$label: other plugins untouched");
    }
});

sbs_test('updater: verify_package refuses a non-release package for this plugin', function () {
    if (defined('SBS_UPDATE_CHANNEL')) {
        sbs_skip('SBS_UPDATE_CHANNEL is defined in this environment');
    }
    $updater = new SBS_GitHub_Updater();
    $result  = $updater->verify_package(false, 'https://github.com/vitaliikaplia/site-backup-streamer/archive/refs/heads/master.zip', null, ['plugin' => SBS_BASENAME]);
    sbs_assert_true($result instanceof WP_Error);
    sbs_assert_same('sbs_untrusted_package', $result->get_error_code());
});
