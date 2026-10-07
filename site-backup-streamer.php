<?php
/**
 * Plugin Name: Site Backup Streamer
 * Description: Adds a dashboard widget for streaming WordPress site files and database backups.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Tested up to: 7.0
 * Author: Vitalii Kaplia
 * Author URI: https://vitaliikaplia.com/
 * Update URI: https://github.com/vitaliikaplia/site-backup-streamer
 * Text Domain: site-backup-streamer
 *
 * @package SiteBackupStreamer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SBS_Site_Backup_Streamer', false ) ) {
	define( 'SBS_VERSION', '1.1.0' );
	define( 'SBS_FILE', __FILE__ );
	define( 'SBS_BASENAME', plugin_basename( __FILE__ ) );
	// Branch of the dev update channel (SBS_UPDATE_CHANNEL = 'branch'); can be overridden in wp-config.php.
	if ( ! defined( 'SBS_GITHUB_BRANCH' ) ) {
		define( 'SBS_GITHUB_BRANCH', 'master' );
	}

	require_once __DIR__ . '/includes/class-sbs-github-updater.php';

	/**
	 * Dashboard backup streamer plugin.
	 */
	final class SBS_Site_Backup_Streamer {
		private const ACTION            = 'sbs_download_backup';
		private const TYPES             = array( 'files', 'database' );
		private const ZIP_MIN_TIMESTAMP = 315532800; // 1980-01-01 00:00:00 UTC.
		// ZipStream reads every file in 16 MB blocks, and fread() allocates the whole block up front.
		private const ZIP_MEMORY_HEADROOM = 33554432; // 32 MB.

		/**
		 * Boots plugin hooks.
		 */
		public static function boot(): void {
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_download_request' ) );
			add_action( 'wp_ajax_sbs_scan_site_size', array( __CLASS__, 'handle_site_size_scan' ) );

			new SBS_GitHub_Updater(); // Updates from GitHub Releases; also runs in cron, not only in wp-admin.
		}

		/**
		 * Registers the WordPress dashboard widget.
		 */
		public static function register_dashboard_widget(): void {
			if ( ! self::current_user_can_backup() ) {
				return;
			}

			wp_add_dashboard_widget(
				'sbs_site_backup_streamer',
				esc_html__( 'Резервна копія сайту', 'site-backup-streamer' ),
				array( __CLASS__, 'render_dashboard_widget' )
			);
		}

		/**
		 * Renders dashboard widget controls.
		 */
		public static function render_dashboard_widget(): void {
			if ( ! self::current_user_can_backup() ) {
				esc_html_e( 'Недостатньо прав для завантаження резервних копій.', 'site-backup-streamer' );
				return;
			}

			$checks      = self::get_system_checks();
			$files_ready = self::checks_are_ready( $checks, 'files' );
			$db_ready    = self::checks_are_ready( $checks, 'database' );
			$files_url   = $files_ready ? self::download_url( 'files' ) : '#';
			$db_url      = $db_ready ? self::download_url( 'database' ) : '#';
			?>
			<style>
				.sbs-backup-widget{display:grid;gap:10px}
				.sbs-backup-widget p{margin:0 0 4px}
				.sbs-backup-widget__actions{display:flex;flex-wrap:wrap;gap:8px}
				.sbs-backup-widget__meta{color:#646970;font-size:12px}
				.sbs-backup-widget__checks{display:grid;gap:4px;margin:2px 0 0}
				.sbs-backup-widget__check{display:flex;justify-content:space-between;gap:10px;padding:4px 0;border-bottom:1px solid #dcdcde}
				.sbs-backup-widget__check:last-child{border-bottom:0}
				.sbs-backup-widget__label{font-weight:600}
				.sbs-backup-widget__value{text-align:right;overflow-wrap:anywhere}
				.sbs-backup-widget__ok{color:#008a20}
				.sbs-backup-widget__bad{color:#b32d2e}
				.sbs-backup-widget__warning{color:#996800}
				.sbs-backup-widget__size-result{padding:8px 10px;background:#f6f7f7;border-left:4px solid #72aee6}
				.sbs-backup-widget__size-result.is-error{border-left-color:#b32d2e}
				.sbs-backup-widget__size-result strong{display:block;margin-bottom:2px}
			</style>

			<div class="sbs-backup-widget">
				<p><?php esc_html_e( 'Потокове завантаження без створення ZIP-архіву на сервері.', 'site-backup-streamer' ); ?></p>
				<div class="sbs-backup-widget__checks">
					<?php foreach ( $checks as $check ) : ?>
						<div class="sbs-backup-widget__check">
							<span class="sbs-backup-widget__label"><?php echo esc_html( $check['label'] ); ?></span>
							<span class="sbs-backup-widget__value sbs-backup-widget__<?php echo esc_attr( $check['status'] ); ?>">
								<?php echo esc_html( $check['message'] ); ?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="sbs-backup-widget__actions">
					<button type="button" class="button button-secondary" id="sbs-scan-site-size">
						<?php esc_html_e( 'Порахувати розмір', 'site-backup-streamer' ); ?>
					</button>
					<a class="button button-secondary<?php echo $files_ready ? '' : ' disabled'; ?>" href="<?php echo esc_url( $files_url ); ?>" <?php echo $files_ready ? '' : 'aria-disabled="true"'; ?>>
						<?php esc_html_e( 'Завантажити сайт', 'site-backup-streamer' ); ?>
					</a>
					<a class="button button-primary<?php echo $db_ready ? '' : ' disabled'; ?>" href="<?php echo esc_url( $db_url ); ?>" <?php echo $db_ready ? '' : 'aria-disabled="true"'; ?>>
						<?php esc_html_e( 'Завантажити базу даних', 'site-backup-streamer' ); ?>
					</a>
				</div>
				<p class="sbs-backup-widget__meta">
					<?php esc_html_e( 'Доступно тільки адміністраторам. Якщо перевірка не пройдена, завантаження не запускається.', 'site-backup-streamer' ); ?>
				</p>
				<div class="sbs-backup-widget__size-result" id="sbs-site-size-result" hidden></div>
			</div>
			<script>
				(function() {
					const button = document.getElementById('sbs-scan-site-size');
					const result = document.getElementById('sbs-site-size-result');

					if (!button || !result) {
						return;
					}

					button.addEventListener('click', function() {
						const formData = new FormData();
						formData.append('action', 'sbs_scan_site_size');
						formData.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'sbs_scan_site_size' ) ); ?>');

						button.disabled = true;
						button.textContent = '<?php echo esc_js( __( 'Рахую...', 'site-backup-streamer' ) ); ?>';
						result.hidden = false;
						result.classList.remove('is-error');
						result.textContent = '<?php echo esc_js( __( 'Сканую файли сайту. Для великих сайтів це може зайняти трохи часу.', 'site-backup-streamer' ) ); ?>';

						fetch(ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						})
							.then(function(response) {
								// A proxy timeout or a PHP fatal returns HTML instead of JSON.
								return response.json().catch(function() {
									throw new Error('<?php echo esc_js( __( 'Не вдалося порахувати розмір.', 'site-backup-streamer' ) ); ?> HTTP ' + response.status);
								});
							})
							.then(function(response) {
								if (!response || !response.success) {
									throw new Error(response && response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Не вдалося порахувати розмір.', 'site-backup-streamer' ) ); ?>');
								}

								result.innerHTML = '<strong>' + response.data.size_human + '</strong>'
									+ response.data.files + ' <?php echo esc_js( __( 'файлів', 'site-backup-streamer' ) ); ?>, '
									+ response.data.directories + ' <?php echo esc_js( __( 'папок', 'site-backup-streamer' ) ); ?>, '
									+ response.data.elapsed + 's';

								if (response.data.skipped > 0) {
									result.innerHTML += '<br>' + response.data.skipped + ' <?php echo esc_js( __( 'недоступних елементів пропущено', 'site-backup-streamer' ) ); ?>';
								}
							})
							.catch(function(error) {
								result.classList.add('is-error');
								result.textContent = error.message;
							})
							.finally(function() {
								button.disabled = false;
								button.textContent = '<?php echo esc_js( __( 'Порахувати розмір', 'site-backup-streamer' ) ); ?>';
							});
					});
				})();
			</script>
			<?php
		}

		/**
		 * Handles protected download requests.
		 */
		public static function handle_download_request(): void {
			if ( ! self::current_user_can_backup() ) {
				wp_die( esc_html__( 'Недостатньо прав.', 'site-backup-streamer' ), '', array( 'response' => 403 ) );
			}

			$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				wp_die( esc_html__( 'Невідомий тип завантаження.', 'site-backup-streamer' ), '', array( 'response' => 400 ) );
			}

			check_admin_referer( self::ACTION . '_' . $type );

			try {
				self::load_dependencies();
				self::prepare_streaming_response();

				if ( 'database' === $type ) {
					self::stream_database_sql_download();
				} else {
					self::stream_files_zip_download();
				}
			} catch ( Throwable $e ) {
				self::handle_stream_error( $type, $e );
			}

			exit;
		}

		/**
		 * Handles AJAX site size scans.
		 */
		public static function handle_site_size_scan(): void {
			if ( ! self::current_user_can_backup() ) {
				wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'site-backup-streamer' ) ), 403 );
			}

			check_ajax_referer( 'sbs_scan_site_size' );

			try {
				self::prepare_long_operation();
				$scan = self::scan_site_size();
				wp_send_json_success( $scan );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
			}
		}

		/**
		 * Checks whether the current user may download backups.
		 *
		 * On multisite the archive and the dump cover the whole network, so only network administrators qualify.
		 */
		private static function current_user_can_backup(): bool {
			return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
		}

		/**
		 * Builds a nonce-protected admin-post URL.
		 */
		private static function download_url( string $type ): string {
			return wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::ACTION,
						'type'   => $type,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION . '_' . $type
			);
		}

		/**
		 * Loads Composer dependencies only when missing.
		 */
		private static function load_dependencies(): void {
			$has_zipstream = class_exists( 'ZipStream\\ZipStream' );
			$has_mysqldump = class_exists( 'Ifsnop\\Mysqldump\\Mysqldump' );

			if ( ! $has_zipstream || ! $has_mysqldump ) {
				$autoload = __DIR__ . '/vendor/autoload.php';

				if ( ! is_readable( $autoload ) ) {
					throw new RuntimeException( __( 'Немає Composer-залежностей. Виконайте composer install у папці плагіна перед завантаженням на сервер.', 'site-backup-streamer' ) );
				}

				require_once $autoload;
			}

			if ( ! class_exists( 'ZipStream\\ZipStream' ) || ! class_exists( 'Ifsnop\\Mysqldump\\Mysqldump' ) ) {
				throw new RuntimeException( __( 'Не вдалося завантажити класи експорту.', 'site-backup-streamer' ) );
			}

			self::assert_dependency_compatibility();
		}

		/**
		 * Checks whether loaded dependency versions expose the APIs used by this plugin.
		 */
		private static function assert_dependency_compatibility(): void {
			$zip_constructor = new ReflectionMethod( 'ZipStream\\ZipStream', '__construct' );
			$zip_parameters  = array_map(
				static function ( ReflectionParameter $parameter ): string {
					return $parameter->getName();
				},
				$zip_constructor->getParameters()
			);

			foreach ( array( 'outputName', 'outputStream', 'sendHttpHeaders', 'defaultCompressionMethod', 'enableZip64', 'flushOutput' ) as $parameter ) {
				if ( ! in_array( $parameter, $zip_parameters, true ) ) {
					throw new RuntimeException( __( 'Завантажена версія ZipStream несумісна з плагіном.', 'site-backup-streamer' ) );
				}
			}

			if ( ! method_exists( 'ZipStream\\ZipStream', 'addFileFromPath' ) || ! method_exists( 'ZipStream\\ZipStream', 'finish' ) ) {
				throw new RuntimeException( __( 'У завантаженій версії ZipStream немає потрібних методів.', 'site-backup-streamer' ) );
			}

			$add_file_parameters = array_map(
				static function ( ReflectionParameter $parameter ): string {
					return $parameter->getName();
				},
				( new ReflectionMethod( 'ZipStream\\ZipStream', 'addFileFromPath' ) )->getParameters()
			);

			foreach ( array( 'fileName', 'path', 'lastModificationDateTime' ) as $parameter ) {
				if ( ! in_array( $parameter, $add_file_parameters, true ) ) {
					throw new RuntimeException( __( 'Завантажена версія ZipStream несумісна з плагіном.', 'site-backup-streamer' ) );
				}
			}

			if ( ! class_exists( 'ZipStream\\CompressionMethod' ) || ! defined( 'ZipStream\\CompressionMethod::STORE' ) || ! method_exists( 'Ifsnop\\Mysqldump\\Mysqldump', 'start' ) ) {
				throw new RuntimeException( __( 'Завантажені бібліотеки експорту несумісні з плагіном.', 'site-backup-streamer' ) );
			}
		}

		/**
		 * Prepares PHP for long streaming responses.
		 */
		private static function prepare_streaming_response(): void {
			self::prepare_long_operation();

			while ( ob_get_level() ) {
				ob_end_clean();
			}
		}

		/**
		 * Prepares PHP for longer admin operations.
		 *
		 * ignore_user_abort() stays at its default, so a cancelled download stops the export
		 * instead of reading the whole site into a closed connection.
		 */
		private static function prepare_long_operation(): void {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}

			// admin-post.php and admin-ajax.php do not raise the limit the way wp-admin pages do.
			wp_raise_memory_limit( 'admin' );
			@ini_set( 'zlib.output_compression', 'Off' );
		}

		/**
		 * Returns memory left under memory_limit in bytes, or null when there is no limit.
		 */
		private static function free_memory(): ?int {
			$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

			return $limit > 0 ? $limit - memory_get_usage() : null;
		}

		/**
		 * Stops a files export that would run out of memory on the first file.
		 *
		 * Otherwise PHP dies right after the first ZIP entry header and WordPress appends its
		 * "critical error" page to the download.
		 */
		private static function assert_zip_memory(): void {
			$free = self::free_memory();

			if ( null !== $free && $free < self::ZIP_MEMORY_HEADROOM ) {
				throw new RuntimeException( self::zip_memory_message( $free ) );
			}
		}

		/**
		 * Describes a memory shortage for the files archive.
		 */
		private static function zip_memory_message( int $free ): string {
			return sprintf(
				/* translators: 1: free memory, 2: required memory. */
				__( 'Замало пам\'яті для архіву: вільно %1$s, потрібно щонайменше %2$s. Збільште WP_MAX_MEMORY_LIMIT або memory_limit.', 'site-backup-streamer' ),
				size_format( max( 0, $free ) ),
				size_format( self::ZIP_MEMORY_HEADROOM )
			);
		}

		/**
		 * Reports an export failure: as an error page before streaming starts, inside the stream after that.
		 */
		private static function handle_stream_error( string $type, Throwable $e ): void {
			error_log( 'Site Backup Streamer: ' . $type . ' export failed: ' . $e->getMessage() );

			if ( ! headers_sent() ) {
				// Otherwise the browser saves the error page as the backup file.
				header_remove( 'Content-Description' );
				header_remove( 'Content-Disposition' );
				wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 500 ) );
			}

			// The download has already started. A broken ZIP has no central directory and archivers reject it,
			// but a cut SQL file looks valid, so mark it explicitly.
			if ( 'database' === $type ) {
				/* translators: %s: error message. */
				$message = sprintf( __( 'ПОМИЛКА: дамп неповний, експорт перервано: %s', 'site-backup-streamer' ), $e->getMessage() );
				echo PHP_EOL . '-- ' . str_replace( array( "\r", "\n" ), ' ', $message ) . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SQL comment in a text download.
			}
		}

		/**
		 * Streams only WordPress files as a ZIP archive.
		 */
		private static function stream_files_zip_download(): void {
			self::assert_zip_memory();

			$directories = self::backup_directories();
			$zip         = self::create_zip( self::filename( 'site-files', 'zip' ) );

			self::walk_backup_files(
				$directories,
				static function ( string $path, string $name, SplFileInfo $file ) use ( $zip ): bool {
					return self::add_file_to_zip( $zip, $path, $name, $file );
				}
			);

			$zip->finish();
		}

		/**
		 * Adds one file to the archive; returns false when the file has to be skipped.
		 */
		private static function add_file_to_zip( ZipStream\ZipStream $zip, string $path, string $name, SplFileInfo $file ): bool {
			try {
				$modified = $file->getMTime();
			} catch ( RuntimeException $e ) {
				return false;
			}

			// ZIP dates start in 1980 and ZipStream throws on older ones (files unpacked with a zero timestamp).
			$modified_at = $modified < self::ZIP_MIN_TIMESTAMP ? new DateTimeImmutable( '@' . self::ZIP_MIN_TIMESTAMP ) : null;

			try {
				$zip->addFileFromPath( fileName: $name, path: $path, lastModificationDateTime: $modified_at );
			} catch ( ZipStream\Exception\FileNotFoundException | ZipStream\Exception\FileNotReadableException $e ) {
				// Deleted or locked after the directory was listed; nothing was written for it yet.
				return false;
			}

			return true;
		}

		/**
		 * Streams only the database as SQL.
		 */
		private static function stream_database_sql_download(): void {
			self::send_download_headers( self::filename( 'database', 'sql' ), 'application/sql; charset=utf-8' );
			self::create_database_dump()->start( 'php://output' );
		}

		/**
		 * Creates configured ZipStream instance.
		 */
		private static function create_zip( string $filename ): ZipStream\ZipStream {
			self::send_download_headers( $filename, 'application/zip' );

			return new ZipStream\ZipStream(
				outputName: $filename,
				outputStream: fopen( 'php://output', 'wb' ),
				sendHttpHeaders: false,
				defaultCompressionMethod: ZipStream\CompressionMethod::STORE,
				enableZip64: true,
				flushOutput: true
			);
		}

		/**
		 * Returns the directories archived as site files.
		 *
		 * WP_CONTENT_DIR is added on its own when it lives outside ABSPATH
		 * (Bedrock, WordPress in its own directory), otherwise themes, plugins and uploads would be missing.
		 *
		 * @return array<string,string> Real directory path with trailing separator => ZIP path prefix.
		 */
		private static function backup_directories(): array {
			$root = realpath( ABSPATH );

			if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
				throw new RuntimeException( __( 'Корінь WordPress не знайдено або він недоступний для читання.', 'site-backup-streamer' ) );
			}

			$root        = self::with_trailing_separator( $root );
			$directories = array( $root => 'site/' );

			$content = defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false;
			if ( false !== $content && is_dir( $content ) ) {
				$content = self::with_trailing_separator( $content );

				if ( ! self::path_is_inside( $content, $root ) ) {
					$directories[ $content ] = 'wp-content/';
				}
			}

			return $directories;
		}

		/**
		 * Walks every file of the files archive and passes it to a callback.
		 *
		 * The size scan and the ZIP export share this walk, so the scan counts exactly what the archive gets.
		 * Symbolic links and unreadable entries are skipped.
		 *
		 * @param array<string,string>                    $directories Directories from backup_directories().
		 * @param callable(string,string,SplFileInfo):bool $on_file     Gets the real path, the ZIP name and the file; returns false if it skipped the file.
		 * @return array{files:int,directories:int,skipped:int}
		 */
		private static function walk_backup_files( array $directories, callable $on_file ): array {
			$stats = array(
				'files'       => 0,
				'directories' => 0,
				'skipped'     => 0,
			);

			foreach ( $directories as $directory => $prefix ) {
				try {
					// CATCH_GET_CHILD only works as a constructor argument: setFlags() is forwarded to
					// RecursiveDirectoryIterator, where 16 means CURRENT_AS_SELF and replaces SKIP_DOTS.
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
						RecursiveIteratorIterator::SELF_FIRST,
						RecursiveIteratorIterator::CATCH_GET_CHILD
					);
				} catch ( UnexpectedValueException $e ) {
					$stats['skipped']++;
					continue;
				}

				foreach ( $iterator as $item ) {
					if ( $item->isLink() ) {
						$stats['skipped']++;
						continue;
					}

					if ( $item->isDir() ) {
						// CATCH_GET_CHILD steps over the contents of an unreadable directory.
						if ( $item->isReadable() ) {
							$stats['directories']++;
						} else {
							$stats['skipped']++;
						}
						continue;
					}

					$real = $item->isFile() && $item->isReadable() ? $item->getRealPath() : false;
					if ( false === $real || ! self::path_is_inside( $real, $directory ) ) {
						$stats['skipped']++;
						continue;
					}

					$name = $prefix . str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $directory ) ) );
					if ( $on_file( $real, $name, $item ) ) {
						$stats['files']++;
					} else {
						$stats['skipped']++;
					}
				}
			}

			$config_path = self::external_wp_config_path( $directories );
			if ( null !== $config_path ) {
				if ( $on_file( $config_path, 'wp-config.php', new SplFileInfo( $config_path ) ) ) {
					$stats['files']++;
				} else {
					$stats['skipped']++;
				}
			}

			return $stats;
		}

		/**
		 * Returns wp-config.php when it is not inside any archived directory.
		 *
		 * @param array<string,string> $directories Directories from backup_directories().
		 */
		private static function external_wp_config_path( array $directories ): ?string {
			$root = realpath( ABSPATH );
			if ( false === $root ) {
				return null;
			}

			$config_path = self::find_wp_config_path( self::with_trailing_separator( $root ) );
			if ( null === $config_path || ! is_readable( $config_path ) ) {
				return null;
			}

			foreach ( array_keys( $directories ) as $directory ) {
				if ( self::path_is_inside( $config_path, $directory ) ) {
					return null;
				}
			}

			return $config_path;
		}

		/**
		 * Finds wp-config.php in the normal WordPress locations.
		 */
		private static function find_wp_config_path( string $root ): ?string {
			$local_config = $root . 'wp-config.php';
			if ( is_readable( $local_config ) ) {
				return realpath( $local_config ) ?: null;
			}

			// Same rule as wp-load.php: the parent file belongs to this site only if the parent is not another install.
			$parent        = dirname( rtrim( $root, DIRECTORY_SEPARATOR ) ) . DIRECTORY_SEPARATOR;
			$parent_config = $parent . 'wp-config.php';
			if ( is_readable( $parent_config ) && ! file_exists( $parent . 'wp-settings.php' ) ) {
				return realpath( $parent_config ) ?: null;
			}

			return null;
		}

		/**
		 * Checks whether a real path lies inside a directory given with a trailing separator.
		 */
		private static function path_is_inside( string $path, string $directory ): bool {
			return 0 === strpos( $path, $directory );
		}

		/**
		 * Appends a single directory separator to a path.
		 */
		private static function with_trailing_separator( string $path ): string {
			return rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		}

		/**
		 * Creates a mysqldump-php instance using current wp-config.php constants.
		 */
		private static function create_database_dump(): Ifsnop\Mysqldump\Mysqldump {
			foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ) as $constant ) {
				if ( ! defined( $constant ) ) {
					/* translators: %s: constant name. */
					throw new RuntimeException( sprintf( __( '%s не визначено у wp-config.php.', 'site-backup-streamer' ), $constant ) );
				}
			}

			$charset = self::database_charset();

			return new Ifsnop\Mysqldump\Mysqldump(
				self::database_dsn() . ';charset=' . $charset,
				DB_USER,
				DB_PASSWORD,
				array(
					// mysqldump-php sends SET NAMES utf8 by default, which turns 4-byte characters (emoji) into "?".
					'default-character-set' => $charset,
					'single-transaction'    => true,
					'add-drop-table'        => true,
					'add-drop-trigger'      => true,
					'routines'              => true,
					'events'                => true,
					// Comments stay on: the closing "-- Dump completed" line tells a full dump from a cut one.
				),
				array(
					// A one-off export should not leave an idle MySQL connection in the PHP worker.
					PDO::ATTR_PERSISTENT => false,
				)
			);
		}

		/**
		 * Returns the connection charset WordPress really uses: wpdb upgrades DB_CHARSET utf8 to utf8mb4.
		 */
		private static function database_charset(): string {
			global $wpdb;

			$charset = isset( $wpdb->charset ) ? (string) $wpdb->charset : '';

			return preg_match( '/^[A-Za-z0-9_]+$/', $charset ) ? $charset : 'utf8mb4';
		}

		/**
		 * Builds a PDO MySQL DSN from WordPress DB_HOST.
		 */
		private static function database_dsn(): string {
			$host = DB_HOST;
			$port = null;
			$socket = null;

			if ( false !== strpos( $host, ':/' ) ) {
				list( $host, $socket ) = explode( ':', $host, 2 );
			} elseif ( preg_match( '/^(.+):([0-9]+)$/', $host, $matches ) ) {
				$host = $matches[1];
				$port = $matches[2];
			}

			$dsn = 'mysql:';
			if ( $socket ) {
				$dsn .= 'unix_socket=' . $socket;
			} else {
				$dsn .= 'host=' . $host;
				if ( $port ) {
					$dsn .= ';port=' . $port;
				}
			}

			return $dsn . ';dbname=' . DB_NAME;
		}

		/**
		 * Runs lightweight checks shown in the dashboard widget.
		 *
		 * @return array<int,array{label:string,message:string,status:string,critical:bool}>
		 */
		private static function get_system_checks(): array {
			$checks = array();
			$checks[] = self::check_result(
				'PHP',
				PHP_VERSION_ID >= 80300,
				PHP_VERSION . ' OK',
				PHP_VERSION . ' потрібен PHP 8.3+'
			);
			$checks[] = self::check_result(
				'Composer vendor',
				is_readable( __DIR__ . '/vendor/autoload.php' ) || ( class_exists( 'ZipStream\\ZipStream' ) && class_exists( 'Ifsnop\\Mysqldump\\Mysqldump' ) ),
				'Залежності доступні',
				'Немає vendor/autoload.php'
			);

			$missing_extensions = array();
			foreach ( array( 'pdo_mysql', 'mbstring', 'zlib' ) as $extension ) {
				if ( ! extension_loaded( $extension ) ) {
					$missing_extensions[] = $extension;
				}
			}
			$checks[] = self::check_result(
				'PHP розширення',
				array() === $missing_extensions,
				'pdo_mysql, mbstring, zlib доступні',
				'Немає ' . implode( ', ', $missing_extensions )
			);

			$checks[] = self::check_result(
				'Файли сайту',
				is_readable( ABSPATH ) && class_exists( 'RecursiveIteratorIterator' ),
				'Корінь WordPress читається',
				'Немає доступу до ABSPATH'
			);
			$checks[] = self::check_result(
				'DB конфіг',
				defined( 'DB_HOST' ) && defined( 'DB_NAME' ) && defined( 'DB_USER' ) && defined( 'DB_PASSWORD' ),
				'DB_* константи знайдено',
				'DB_* константи не знайдено'
			);
			$checks[] = self::check_result(
				'Потік відповіді',
				function_exists( 'header' ) && self::can_open_output_stream(),
				'php://output доступний',
				'php://output недоступний'
			);

			// The download request raises the limit the same way, so this is what the export will get.
			wp_raise_memory_limit( 'admin' );
			$free     = self::free_memory();
			$checks[] = self::check_result(
				'Пам\'ять',
				null === $free || $free >= self::ZIP_MEMORY_HEADROOM,
				null === $free ? 'Без обмеження' : 'Вільно ' . size_format( $free ),
				null === $free ? '' : self::zip_memory_message( $free ),
				'files'
			);

			try {
				self::load_dependencies();
				$checks[] = self::check_result( 'Класи експорту', true, 'Сумісні', '' );
			} catch ( Throwable $e ) {
				$checks[] = self::check_result( 'Класи експорту', false, '', $e->getMessage() );
			}

			return $checks;
		}

		/**
		 * Creates one check row.
		 *
		 * @param string $scope Download type the check blocks: "all", "files" or "database".
		 * @return array{label:string,message:string,status:string,critical:bool,scope:string}
		 */
		private static function check_result( string $label, bool $passed, string $ok_message, string $bad_message, string $scope = 'all' ): array {
			return array(
				'label'    => $label,
				'message'  => $passed ? $ok_message : $bad_message,
				'status'   => $passed ? 'ok' : 'bad',
				'critical' => ! $passed,
				'scope'    => $scope,
			);
		}

		/**
		 * Checks whether PHP can open the browser output stream.
		 */
		private static function can_open_output_stream(): bool {
			$stream = @fopen( 'php://output', 'wb' );

			if ( ! is_resource( $stream ) ) {
				return false;
			}

			fclose( $stream );

			return true;
		}

		/**
		 * Returns whether all critical checks that apply to a download type passed.
		 *
		 * @param array<int,array{critical:bool,scope:string}> $checks Checks.
		 * @param string                                       $type   Download type.
		 */
		private static function checks_are_ready( array $checks, string $type ): bool {
			foreach ( $checks as $check ) {
				if ( ! empty( $check['critical'] ) && in_array( $check['scope'], array( 'all', $type ), true ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Scans the files that go into the archive and returns size statistics.
		 *
		 * @return array{bytes:int,size_human:string,files:int,directories:int,skipped:int,elapsed:float}
		 */
		private static function scan_site_size(): array {
			$started = microtime( true );
			$bytes   = 0;

			$stats = self::walk_backup_files(
				self::backup_directories(),
				static function ( string $path, string $name, SplFileInfo $file ) use ( &$bytes ): bool {
					try {
						$bytes += $file->getSize();
					} catch ( RuntimeException $e ) {
						return false;
					}

					return true;
				}
			);

			return array(
				'bytes'       => $bytes,
				'size_human'  => self::format_bytes( $bytes ),
				'files'       => $stats['files'],
				'directories' => $stats['directories'],
				'skipped'     => $stats['skipped'],
				'elapsed'     => round( microtime( true ) - $started, 2 ),
			);
		}

		/**
		 * Formats bytes for dashboard display.
		 */
		private static function format_bytes( int $bytes ): string {
			$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
			$size = (float) $bytes;
			$unit = 0;

			while ( $size >= 1024 && $unit < count( $units ) - 1 ) {
				$size /= 1024;
				$unit++;
			}

			return round( $size, $unit > 0 ? 2 : 0 ) . ' ' . $units[ $unit ];
		}

		/**
		 * Sends browser download headers.
		 */
		private static function send_download_headers( string $filename, string $content_type ): void {
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: ' . $content_type );
			header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );
			header( 'X-Accel-Buffering: no' );
		}

		/**
		 * Builds a filesystem-safe backup filename.
		 */
		private static function filename( string $prefix, string $extension ): string {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$host = $host ? sanitize_title( $host ) : 'wordpress-site';

			return $host . '-' . $prefix . '-' . gmdate( 'Y-m-d-H-i-s' ) . '.' . $extension;
		}
	}

	SBS_Site_Backup_Streamer::boot();
}
