<?php
/**
 * Plugin updates from GitHub Releases.
 *
 * "release" channel (default): the latest release is read from the GitHub API
 * (repos/vitaliikaplia/site-backup-streamer/releases/latest). Version = tag_name without "v", package =
 * the immutable site-backup-streamer.zip asset of that release. Before installing, the zip is checked
 * against the site-backup-streamer.zip.sha256 asset of the same release (upgrader_pre_download); a
 * mismatch gives a WP_Error and the file is deleted. A release without both assets offers no update
 * (fail closed). Requires/Tested come from the site-backup-streamer.php header at the release tag, the
 * changelog in the "View details" window from the release notes.
 *
 * "branch" channel (dev): define( 'SBS_UPDATE_CHANNEL', 'branch' ) in wp-config.php — Version from the raw
 * site-backup-streamer.php of the SBS_GITHUB_BRANCH branch plus the branch zip, WITHOUT a checksum.
 *
 * Network access happens only while the update_plugins transient is refreshed
 * (pre_set_site_transient_update_plugins) and in the "View details" window (plugins_api); reading the
 * transient uses the cache only. Cache 12 h (failure 1 h), memoised per request, so "Check again"
 * (?force-check=1) makes one request, not several.
 *
 * @package SiteBackupStreamer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases updater.
 */
class SBS_GitHub_Updater {

	private const REPOSITORY   = 'vitaliikaplia/site-backup-streamer';
	private const MAIN_FILE    = 'site-backup-streamer.php';
	private const ASSET        = 'site-backup-streamer.zip';
	private const CACHE_KEY    = 'sbs_github_update_data';
	private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	private const FAIL_TTL     = HOUR_IN_SECONDS;
	private const CACHE_SCHEMA = 1; // Cache format; records of another schema count as a miss.
	private const DESCRIPTION  = '<p>Site Backup Streamer — віджет Dashboard для ручної резервної копії WordPress: файли сайту потоком у ZIP і база даних у SQL-дамп, без створення архіву на сервері.</p>';

	/**
	 * Update record (cache or fresh response) memoised for the request.
	 *
	 * @var array|null
	 */
	private $memo = null;

	/**
	 * Whether the cache was already read in this request.
	 *
	 * @var bool
	 */
	private $memo_loaded = false;

	/**
	 * Whether GitHub was already asked in this request (never twice).
	 *
	 * @var bool
	 */
	private $fetched = false;

	/**
	 * Registers the update hooks.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'refresh_update_plugins' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'read_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_github_source_directory' ), 11, 4 );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cached_update_data' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	// ── update_plugins transient ─────────────────────────────────────────

	/**
	 * Transient refresh (wp_update_plugins / cron): the only place allowed to call GitHub.
	 *
	 * @param mixed $transient update_plugins transient.
	 * @return mixed
	 */
	public function refresh_update_plugins( $transient ) {
		return $this->apply_update_data( $transient, $this->get_update_data( true, $this->should_force_check() ) );
	}

	/**
	 * Transient read (admin bar, menu, plugin list): cache only, no network.
	 *
	 * @param mixed $transient update_plugins transient.
	 * @return mixed
	 */
	public function read_update_plugins( $transient ) {
		return $this->apply_update_data( $transient, $this->get_update_data( false ) );
	}

	/**
	 * Puts this plugin into response or no_update.
	 *
	 * @param mixed      $transient update_plugins transient.
	 * @param array|null $data      Update record.
	 * @return mixed
	 */
	private function apply_update_data( $transient, ?array $data ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		if ( ! $data ) {
			// No data of our own (failed check): do not let a foreign package through under our basename
			// (wordpress.org, another updater, a branch zip in the release channel).
			foreach ( array( 'response', 'no_update' ) as $list ) {
				$item = $transient->{$list}[ SBS_BASENAME ] ?? null;
				if ( null !== $item && ! $this->is_own_package( (string) ( ( (array) $item )['package'] ?? '' ) ) ) {
					unset( $transient->{$list}[ SBS_BASENAME ] );
				}
			}
			return $transient;
		}

		$local_version = (string) ( $transient->checked[ SBS_BASENAME ] ?? SBS_VERSION );
		$update        = $this->build_update_response( $data );

		if ( version_compare( $data['version'], $local_version, '>' ) ) {
			$transient->response[ SBS_BASENAME ] = $update;
			unset( $transient->no_update[ SBS_BASENAME ] );
		} else {
			$transient->no_update[ SBS_BASENAME ] = $update;
			unset( $transient->response[ SBS_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Builds the update_plugins entry.
	 *
	 * @param array $data Update record.
	 */
	private function build_update_response( array $data ): stdClass {
		$update = array(
			'id'          => $this->get_repository_url(), // = Update URI.
			'slug'        => $this->get_slug(),
			'plugin'      => SBS_BASENAME,
			'new_version' => $data['version'],
			'url'         => $data['url'],
			'package'     => $data['package'],
			'icons'       => array(),
			'banners'     => array(),
			'banners_rtl' => array(),
		);
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}
		return (object) $update;
	}

	// ── "View details" window ────────────────────────────────────────────

	/**
	 * Answers plugins_api for this plugin.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action plugins_api action.
	 * @param mixed  $args   Request arguments.
	 * @return mixed
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || $args->slug !== $this->get_slug() ) {
			return $result;
		}

		$data = $this->get_update_data( true );
		// Without remote data show the installed copy (and no package link).
		$meta = $data ? $data : $this->local_headers();

		return (object) array(
			'name'          => 'Site Backup Streamer',
			'slug'          => $this->get_slug(),
			'version'       => $data['version'] ?? SBS_VERSION,
			'author'        => '<a href="https://vitaliikaplia.com/">Vitalii Kaplia</a>',
			'homepage'      => $this->get_repository_url(),
			'requires'      => (string) ( $meta['requires'] ?? '' ),
			'requires_php'  => (string) ( $meta['requires_php'] ?? '' ),
			'tested'        => (string) ( $meta['tested'] ?? '' ),
			'last_updated'  => (string) ( $data['published'] ?? '' ),
			'download_link' => (string) ( $data['package'] ?? '' ),
			'sections'      => array(
				'description' => self::DESCRIPTION,
				'changelog'   => $this->changelog_html( $data ),
			),
		);
	}

	/**
	 * Changelog section of the "View details" window.
	 *
	 * @param array|null $data Update record.
	 */
	private function changelog_html( ?array $data ): string {
		$releases = '<p><a href="' . esc_url( $this->get_repository_url() . '/releases' ) . '">Усі релізи на GitHub</a></p>';
		if ( ! $data ) {
			return '<p>Не вдалося отримати дані релізу з GitHub — спробуйте пізніше.</p>' . $releases;
		}
		if ( $this->is_branch_channel() ) {
			return '<p>Dev-канал (гілка <code>' . esc_html( $this->get_branch() ) . '</code>): пакет — zip гілки без контрольної суми. Список змін — у <a href="'
				. esc_url( $this->get_repository_url() . '/blob/' . $this->get_url_ref( $this->get_branch() ) . '/CHANGELOG.md' ) . '">CHANGELOG.md</a>.</p>';
		}
		$body = trim( (string) ( $data['body'] ?? '' ) );
		$html = '' !== $body ? self::markdown_to_html( $body ) : '<p>Опис змін до релізу не додано.</p>';
		return '<h4>' . esc_html( $data['version'] ) . '</h4>' . $html . $releases;
	}

	// ── Package check before installing ──────────────────────────────────

	/**
	 * upgrader_pre_download: in the release channel downloads the site-backup-streamer.zip asset itself and
	 * checks its SHA-256 against site-backup-streamer.zip.sha256 of the same release. A mismatch, a missing
	 * checksum or a package that is not a release asset → WP_Error (the update is cancelled and the
	 * downloaded file deleted).
	 *
	 * @param mixed  $reply      Result so far.
	 * @param string $package    Package URL.
	 * @param mixed  $upgrader   WP_Upgrader instance.
	 * @param array  $hook_extra Extra arguments.
	 * @return mixed
	 */
	public function verify_package( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$package      = (string) $package;
		$checksum_url = self::checksum_url_for( $package );
		$ours         = ( is_array( $hook_extra ) && ( $hook_extra['plugin'] ?? '' ) === SBS_BASENAME ) || null !== $checksum_url;
		if ( ! $ours || $this->is_branch_channel() ) {
			return $reply; // A foreign package or the dev channel (no checksum there).
		}
		if ( null === $checksum_url ) {
			return new WP_Error( 'sbs_untrusted_package', 'Site Backup Streamer: пакет оновлення не є asset-ом ' . self::ASSET . ' GitHub-релізу — оновлення скасовано. Перевірте оновлення ще раз.' );
		}

		$sums = $this->http_get( $checksum_url, 'text/plain', 15, true );
		if ( is_wp_error( $sums ) ) {
			return new WP_Error( 'sbs_checksum_unavailable', 'Site Backup Streamer: не вдалося завантажити ' . self::ASSET . '.sha256 релізу (' . $sums->get_error_message() . ') — оновлення скасовано.' );
		}
		$expected = self::parse_checksum( $sums, self::ASSET );
		if ( null === $expected ) {
			return new WP_Error( 'sbs_checksum_invalid', 'Site Backup Streamer: ' . self::ASSET . '.sha256 релізу має неочікуваний формат — оновлення скасовано.' );
		}

		if ( is_string( $reply ) && '' !== $reply && is_file( $reply ) ) {
			$file = $reply; // Another filter already downloaded the package: check exactly that file.
		} else {
			if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
				$upgrader->skin->feedback( 'downloading_package', $package );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$file = download_url( $package, 300 );
			if ( is_wp_error( $file ) ) {
				return new WP_Error( 'download_failed', 'Site Backup Streamer: не вдалося завантажити пакет оновлення.', $file->get_error_message() );
			}
		}

		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'sbs_checksum_mismatch', 'Site Backup Streamer: SHA-256 завантаженого пакета не збігається з ' . self::ASSET . '.sha256 релізу — оновлення скасовано, файл видалено.' );
		}

		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( 'SHA-256 пакета збігається з ' . self::ASSET . '.sha256 релізу.' );
		}
		return $file;
	}

	// ── Package directory ────────────────────────────────────────────────

	/**
	 * The release zip unpacks into "site-backup-streamer/", a branch zip into "site-backup-streamer-<branch>/".
	 * Both are renamed to the directory of the installed plugin (slug).
	 *
	 * @param mixed  $source        Unpacked source directory.
	 * @param string $remote_source Directory the package was unpacked into.
	 * @param mixed  $upgrader      WP_Upgrader instance.
	 * @param array  $hook_extra    Extra arguments.
	 * @return mixed
	 */
	public function normalize_github_source_directory( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || SBS_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$source_path        = untrailingslashit( (string) $source );
		$source_name        = basename( $source_path );
		$expected_directory = $this->get_slug();
		$repo_name          = basename( self::REPOSITORY );

		if ( $source_name === $expected_directory ) {
			return trailingslashit( $source_path );
		}
		if ( $source_name !== $repo_name
			&& ! str_starts_with( $source_name, $repo_name . '-' )
			&& ! str_starts_with( $source_name, $expected_directory . '-' ) ) {
			return $source;
		}

		$target = trailingslashit( dirname( $source_path ) ) . $expected_directory;

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		} elseif ( file_exists( $target ) ) {
			$this->delete_directory( $target );
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source_path, $target, true ) ) {
			return trailingslashit( $target );
		}
		if ( @rename( $source_path, $target ) ) {
			return trailingslashit( $target );
		}

		return $source;
	}

	/**
	 * Deletes a directory tree without WP_Filesystem.
	 *
	 * @param string $directory Directory.
	 */
	private function delete_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$items = scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->delete_directory( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $directory );
	}

	// ── Cache ────────────────────────────────────────────────────────────

	/**
	 * Drops the cache once this plugin was updated.
	 *
	 * @param mixed $upgrader   WP_Upgrader instance.
	 * @param mixed $hook_extra Extra arguments.
	 */
	public function clear_cache_after_update( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array( $hook_extra['plugin'] ?? '' );
		if ( in_array( SBS_BASENAME, $plugins, true ) ) {
			$this->clear_cached_update_data();
		}
	}

	/**
	 * Drops the cached update record.
	 */
	public function clear_cached_update_data(): void {
		delete_site_transient( self::CACHE_KEY );
		$this->memo        = null;
		$this->memo_loaded = false;
		$this->fetched     = false;
	}

	/**
	 * Update data: memo → cache → (only when $remote) GitHub. $force skips the cache but not the memo:
	 * one request makes at most one network call.
	 *
	 * @param bool $remote Whether a network call is allowed.
	 * @param bool $force  Whether to skip the cache.
	 */
	private function get_update_data( bool $remote, bool $force = false ): ?array {
		if ( ! $this->fetched && ! $this->memo_loaded ) {
			$this->memo        = $this->read_cache();
			$this->memo_loaded = true;
		}
		if ( $this->fetched || ( null !== $this->memo && ! $force ) || ! $remote ) {
			return ( null !== $this->memo && ! empty( $this->memo['ok'] ) ) ? $this->memo : null;
		}

		$record        = $this->is_branch_channel() ? $this->fetch_branch() : $this->fetch_release();
		$this->memo    = $record;
		$this->fetched = true;
		set_site_transient( self::CACHE_KEY, $record, ! empty( $record['ok'] ) ? self::CACHE_TTL : self::FAIL_TTL );

		return ! empty( $record['ok'] ) ? $record : null;
	}

	/**
	 * Reads the cached record of the current schema and channel.
	 */
	private function read_cache(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! is_array( $cached )
			|| ( $cached['schema'] ?? 0 ) !== self::CACHE_SCHEMA
			|| ( $cached['channel'] ?? '' ) !== $this->channel_key() ) {
			return null;
		}
		return $cached;
	}

	/**
	 * Builds a cache record.
	 *
	 * @param bool  $ok     Whether the check succeeded.
	 * @param array $fields Record fields.
	 */
	private function record( bool $ok, array $fields = array() ): array {
		return array_merge(
			array(
				'schema'       => self::CACHE_SCHEMA,
				'channel'      => $this->channel_key(),
				'ok'           => $ok,
				'last_checked' => time(),
			),
			$fields
		);
	}

	/**
	 * Reads the latest release from the GitHub API.
	 */
	private function fetch_release(): array {
		$body = $this->http_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			'application/vnd.github+json',
			10
		);
		if ( is_wp_error( $body ) ) {
			return $this->record( false, array( 'error' => $body->get_error_message() ) );
		}
		$json    = json_decode( $body, true );
		$release = self::parse_release( is_array( $json ) ? $json : array() );
		if ( null === $release ) {
			return $this->record( false, array( 'error' => 'Останній реліз не має тегу vX.Y.Z або assets ' . self::ASSET . ' і ' . self::ASSET . '.sha256' ) );
		}

		// Requires/Tested come from the main file header at the release tag. Optional: empty without it.
		$php = $this->http_get( $this->raw_file_url( $release['tag'] ), 'text/plain', 6 );
		if ( ! is_wp_error( $php ) ) {
			$headers = self::parse_headers( $php );
			if ( '' !== $headers['version'] && $headers['version'] !== $release['version'] ) {
				return $this->record( false, array( 'error' => 'Тег ' . $release['tag'] . ' не збігається з Version: ' . $headers['version'] . ' у ' . self::MAIN_FILE . ' релізу' ) );
			}
			$release['requires']     = $headers['requires'];
			$release['requires_php'] = $headers['requires_php'];
			$release['tested']       = $headers['tested'];
		}

		return $this->record( true, $release );
	}

	/**
	 * Reads the version of the dev branch.
	 */
	private function fetch_branch(): array {
		$php = $this->http_get( $this->raw_file_url( $this->get_branch() ), 'text/plain', 10 );
		if ( is_wp_error( $php ) ) {
			return $this->record( false, array( 'error' => $php->get_error_message() ) );
		}
		$headers = self::parse_headers( $php );
		$version = self::version_from_tag( $headers['version'] );
		if ( null === $version ) {
			return $this->record( false, array( 'error' => 'Не знайдено валідний Version: у ' . self::MAIN_FILE . ' гілки ' . $this->get_branch() ) );
		}
		return $this->record(
			true,
			array(
				'version'      => $version,
				'tag'          => '',
				'package'      => sprintf( 'https://github.com/%s/archive/refs/heads/%s.zip', self::REPOSITORY, $this->get_url_ref( $this->get_branch() ) ),
				'checksum_url' => '',
				'url'          => $this->get_repository_url(),
				'body'         => '',
				'published'    => '',
				'requires'     => $headers['requires'],
				'requires_php' => $headers['requires_php'],
				'tested'       => $headers['tested'],
			)
		);
	}

	/**
	 * GET → response body (2xx) or WP_Error. $safe uses wp_safe_remote_get (redirects to the assets CDN).
	 *
	 * @param string $url     URL.
	 * @param string $accept  Accept header.
	 * @param int    $timeout Timeout in seconds.
	 * @param bool   $safe    Whether to use wp_safe_remote_get.
	 * @return string|WP_Error
	 */
	private function http_get( string $url, string $accept, int $timeout, bool $safe = false ) {
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'Site-Backup-Streamer/' . SBS_VERSION . '; ' . home_url( '/' ),
		);
		if ( str_starts_with( $url, 'https://api.github.com/' ) ) {
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}
		$args     = array(
			'timeout'     => $timeout,
			'redirection' => $safe ? 5 : 3,
			'headers'     => $headers,
		);
		$response = $safe ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'sbs_http', sprintf( 'HTTP %d від %s', $code, $url ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	// ── Pure parsers (covered by tests/) ─────────────────────────────────

	/**
	 * "v1.1.0" / "1.1.0" → "1.1.0"; an invalid tag → null.
	 *
	 * @param string $tag Tag.
	 */
	public static function version_from_tag( string $tag ): ?string {
		$version = preg_replace( '/^v/i', '', trim( $tag ) );
		return preg_match( '/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version ) ? $version : null;
	}

	/**
	 * releases/latest response → update data or null (draft/pre-release, invalid tag, no uploaded
	 * site-backup-streamer.zip and site-backup-streamer.zip.sha256 assets in this tag's directory).
	 *
	 * @param array $release Decoded API response.
	 */
	public static function parse_release( array $release ): ?array {
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$tag     = (string) ( $release['tag_name'] ?? '' );
		$version = self::version_from_tag( $tag );
		if ( null === $version ) {
			return null;
		}

		$dir    = 'https://github.com/' . self::REPOSITORY . '/releases/download/' . rawurlencode( $tag ) . '/';
		$assets = array();
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['state'] ?? '' ) !== 'uploaded' ) {
				continue;
			}
			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );
			if ( ( self::ASSET === $name || self::ASSET . '.sha256' === $name ) && $url === $dir . $name ) {
				$assets[ $name ] = $url;
			}
		}
		if ( ! isset( $assets[ self::ASSET ], $assets[ self::ASSET . '.sha256' ] ) ) {
			return null;
		}

		$html_url = (string) ( $release['html_url'] ?? '' );
		$body     = (string) ( $release['body'] ?? '' );
		if ( strlen( $body ) > 65536 ) {
			$body = function_exists( 'mb_strcut' ) ? mb_strcut( $body, 0, 65536, 'UTF-8' ) : substr( $body, 0, 65536 );
		}

		return array(
			'version'      => $version,
			'tag'          => $tag,
			'package'      => $assets[ self::ASSET ],
			'checksum_url' => $assets[ self::ASSET . '.sha256' ],
			'url'          => str_starts_with( $html_url, 'https://github.com/' . self::REPOSITORY . '/' ) ? $html_url : 'https://github.com/' . self::REPOSITORY,
			'body'         => $body,
			'published'    => (string) ( $release['published_at'] ?? '' ),
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
		);
	}

	/**
	 * URL of the site-backup-streamer.zip release asset → URL of its .sha256; any other URL → null.
	 *
	 * @param string $package Package URL.
	 */
	public static function checksum_url_for( string $package ): ?string {
		$pattern = '#^https://github\.com/' . preg_quote( self::REPOSITORY, '#' ) . '/releases/download/[^/?\#]+/' . preg_quote( self::ASSET, '#' ) . '$#';
		return preg_match( $pattern, $package ) ? $package . '.sha256' : null;
	}

	/**
	 * *.sha256 in sha256sum format ("<hex>  site-backup-streamer.zip") → lower-case hex or null.
	 *
	 * @param string $contents File contents.
	 * @param string $filename File the hash must belong to.
	 */
	public static function parse_checksum( string $contents, string $filename ): ?string {
		$lines = preg_split( '/\R/', trim( $contents ) );
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^\s*([0-9a-fA-F]{64})(?:\s+\*?(\S.*?))?\s*$/', $line, $m ) ) {
				continue;
			}
			$name = $m[2] ?? '';
			if ( $name === $filename || ( '' === $name && count( $lines ) === 1 ) ) {
				return strtolower( $m[1] );
			}
		}
		return null;
	}

	/**
	 * Plugin headers (like get_file_data: the first 8 KB) → version/requires/requires_php/tested.
	 *
	 * @param string $php Main plugin file contents.
	 */
	public static function parse_headers( string $php ): array {
		$php    = substr( $php, 0, 8192 );
		$fields = array(
			'version'      => 'Version',
			'requires'     => 'Requires at least',
			'requires_php' => 'Requires PHP',
			'tested'       => 'Tested up to',
		);
		$out    = array();
		foreach ( $fields as $key => $header ) {
			$out[ $key ] = '';
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $php, $m ) ) {
				$out[ $key ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			}
		}
		return $out;
	}

	/**
	 * Minimal release-notes Markdown → HTML for the "View details" window: headings (h4), lists (with
	 * continuation lines), paragraphs, `code`, **bold**, [links](https://…). Everything else is escaped.
	 *
	 * @param string $markdown Markdown.
	 */
	public static function markdown_to_html( string $markdown ): string {
		$html  = '';
		$para  = array();
		$items = array();
		$flush = function () use ( &$html, &$para, &$items ) {
			if ( $para ) {
				$html .= '<p>' . self::markdown_inline( implode( ' ', $para ) ) . '</p>';
				$para  = array();
			}
			if ( $items ) {
				$html .= '<ul><li>' . implode( '</li><li>', array_map( array( self::class, 'markdown_inline' ), $items ) ) . '</li></ul>';
				$items = array();
			}
		};

		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $markdown ) ) as $line ) {
			if ( '' === trim( $line ) ) {
				$flush();
			} elseif ( preg_match( '/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m ) ) {
				$flush();
				$html .= '<h4>' . self::markdown_inline( $m[1] ) . '</h4>';
			} elseif ( preg_match( '/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/', $line, $m ) ) {
				if ( $para ) {
					$flush();
				}
				$items[] = trim( $m[1] );
			} elseif ( $items && preg_match( '/^\s+\S/', $line ) ) {
				$items[ count( $items ) - 1 ] .= ' ' . trim( $line ); // Continuation of a list item.
			} else {
				if ( $items ) {
					$flush();
				}
				$para[] = trim( $line );
			}
		}
		$flush();

		return $html;
	}

	/**
	 * Inline Markdown: `code`, **bold** and http(s) links; the rest is escaped.
	 *
	 * @param string $text Text.
	 */
	public static function markdown_inline( string $text ): string {
		$out   = '';
		$parts = preg_split( '/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$out .= '<code>' . esc_html( substr( $part, 1, -1 ) ) . '</code>';
				continue;
			}
			$part = esc_html( $part );
			$part = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $part );
			$part = preg_replace_callback(
				'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
				function ( $m ) {
					return '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) ) . '">' . $m[1] . '</a>';
				},
				$part
			);
			$out .= $part;
		}
		return $out;
	}

	// ── Small helpers ────────────────────────────────────────────────────

	/**
	 * Requires/Tested headers of the installed copy.
	 */
	private function local_headers(): array {
		$headers = function_exists( 'get_file_data' )
			? get_file_data(
				SBS_FILE,
				array(
					'requires'     => 'Requires at least',
					'requires_php' => 'Requires PHP',
					'tested'       => 'Tested up to',
				)
			)
			: array();
		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Whether a package of an update_plugins entry is ours. Release channel: only the
	 * site-backup-streamer.zip release asset (verify_package would reject anything else, so do not show an
	 * "update" that will not install — e.g. a branch zip left by the dev channel); dev channel: anything
	 * from the repository.
	 *
	 * @param string $package Package URL.
	 */
	private function is_own_package( string $package ): bool {
		if ( ! $this->is_branch_channel() ) {
			return null !== self::checksum_url_for( $package );
		}
		return str_starts_with( $package, $this->get_repository_url() . '/' );
	}

	/**
	 * Whether the dev channel is on.
	 */
	private function is_branch_channel(): bool {
		return defined( 'SBS_UPDATE_CHANNEL' ) && 'branch' === SBS_UPDATE_CHANNEL;
	}

	/**
	 * Cache key of the current channel.
	 */
	private function channel_key(): string {
		return $this->is_branch_channel() ? 'branch:' . $this->get_branch() : 'release';
	}

	/**
	 * Whether "Check again" (?force-check=1) was pressed by a user who may update plugins.
	 */
	private function should_force_check(): bool {
		$force_check = isset( $_GET['force-check'] ) ? sanitize_text_field( wp_unslash( $_GET['force-check'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag of the core update screen.
		return is_admin()
			&& current_user_can( 'update_plugins' )
			&& '1' === $force_check;
	}

	/**
	 * Plugin directory name.
	 */
	private function get_slug(): string {
		return dirname( SBS_BASENAME );
	}

	/**
	 * Dev channel branch.
	 */
	private function get_branch(): string {
		$branch = defined( 'SBS_GITHUB_BRANCH' ) ? trim( (string) SBS_GITHUB_BRANCH ) : 'master';
		return '' !== $branch ? $branch : 'master';
	}

	/**
	 * Raw URL of the main plugin file at a git ref.
	 *
	 * @param string $ref Branch or tag.
	 */
	private function raw_file_url( string $ref ): string {
		return sprintf( 'https://raw.githubusercontent.com/%s/%s/%s', self::REPOSITORY, $this->get_url_ref( $ref ), self::MAIN_FILE );
	}

	/**
	 * Repository URL (= Update URI).
	 */
	private function get_repository_url(): string {
		return 'https://github.com/' . self::REPOSITORY;
	}

	/**
	 * URL-encodes a git ref segment by segment.
	 *
	 * @param string $ref Branch or tag.
	 */
	private function get_url_ref( string $ref ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $ref ) ) );
	}
}
