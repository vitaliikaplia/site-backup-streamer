<?php
/**
 * Plugin Name: Site Backup Streamer
 * Description: Adds a dashboard widget for streaming WordPress site files and database backups.
 * Version: 1.0.0
 * Author: Vitalii Kaplia
 * Author URI: https://vitaliikaplia.com/
 * Text Domain: site-backup-streamer
 *
 * @package SiteBackupStreamer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SBS_Site_Backup_Streamer', false ) ) {
	/**
	 * Dashboard backup streamer plugin.
	 */
	final class SBS_Site_Backup_Streamer {
		private const ACTION = 'sbs_download_backup';
		private const TYPES  = array( 'files', 'database' );

		/**
		 * Boots plugin hooks.
		 */
		public static function boot(): void {
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_download_request' ) );
			add_action( 'wp_ajax_sbs_scan_site_size', array( __CLASS__, 'handle_site_size_scan' ) );
		}

		/**
		 * Registers the WordPress dashboard widget.
		 */
		public static function register_dashboard_widget(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
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
			if ( ! current_user_can( 'manage_options' ) ) {
				esc_html_e( 'Недостатньо прав для завантаження резервних копій.', 'site-backup-streamer' );
				return;
			}

			$checks    = self::get_system_checks();
			$is_ready  = self::checks_are_ready( $checks );
			$files_url = $is_ready ? self::download_url( 'files' ) : '#';
			$db_url    = $is_ready ? self::download_url( 'database' ) : '#';
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
					<a class="button button-secondary<?php echo $is_ready ? '' : ' disabled'; ?>" href="<?php echo esc_url( $files_url ); ?>" <?php echo $is_ready ? '' : 'aria-disabled="true"'; ?>>
						<?php esc_html_e( 'Завантажити сайт', 'site-backup-streamer' ); ?>
					</a>
					<a class="button button-primary<?php echo $is_ready ? '' : ' disabled'; ?>" href="<?php echo esc_url( $db_url ); ?>" <?php echo $is_ready ? '' : 'aria-disabled="true"'; ?>>
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
								return response.json();
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
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Недостатньо прав.', 'site-backup-streamer' ), '', array( 'response' => 403 ) );
			}

			$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				wp_die( esc_html__( 'Невідомий тип завантаження.', 'site-backup-streamer' ), '', array( 'response' => 400 ) );
			}

			check_admin_referer( self::ACTION . '_' . $type );
			self::load_dependencies();
			self::prepare_streaming_response();

			try {
				if ( 'database' === $type ) {
					self::stream_database_sql_download();
				} else {
					self::stream_files_zip_download();
				}
			} catch ( Throwable $e ) {
				if ( ! headers_sent() ) {
					wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 500 ) );
				}
			}

			exit;
		}

		/**
		 * Handles AJAX site size scans.
		 */
		public static function handle_site_size_scan(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
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
					throw new RuntimeException( 'Composer dependencies are missing. Run composer install in the plugin folder before uploading.' );
				}

				require_once $autoload;
			}

			if ( ! class_exists( 'ZipStream\\ZipStream' ) || ! class_exists( 'Ifsnop\\Mysqldump\\Mysqldump' ) ) {
				throw new RuntimeException( 'Required backup classes could not be loaded.' );
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

			foreach ( array( 'outputStream', 'sendHttpHeaders', 'enableZip64' ) as $parameter ) {
				if ( ! in_array( $parameter, $zip_parameters, true ) ) {
					throw new RuntimeException( 'Loaded ZipStream version is not compatible with this plugin.' );
				}
			}

			if ( ! method_exists( 'ZipStream\\ZipStream', 'addFileFromPath' ) || ! method_exists( 'ZipStream\\ZipStream', 'finish' ) ) {
				throw new RuntimeException( 'Loaded ZipStream version is missing required methods.' );
			}

			if ( ! class_exists( 'ZipStream\\CompressionMethod' ) || ! defined( 'ZipStream\\CompressionMethod::STORE' ) || ! method_exists( 'Ifsnop\\Mysqldump\\Mysqldump', 'start' ) ) {
				throw new RuntimeException( 'Loaded backup dependencies are not compatible.' );
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
		 */
		private static function prepare_long_operation(): void {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}

			ignore_user_abort( true );
			@ini_set( 'zlib.output_compression', 'Off' );
		}

		/**
		 * Streams only WordPress files as a ZIP archive.
		 */
		private static function stream_files_zip_download(): void {
			$zip = self::create_zip( self::filename( 'site-files', 'zip' ) );
			self::add_site_files_to_zip( $zip );
			$zip->finish();
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
		 * Adds WordPress installation files to ZIP.
		 */
		private static function add_site_files_to_zip( ZipStream\ZipStream $zip ): void {
			$root = realpath( ABSPATH );

			if ( false === $root || ! is_dir( $root ) ) {
				throw new RuntimeException( 'WordPress root directory was not found.' );
			}

			$root = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $item ) {
				if ( $item->isLink() || ! $item->isFile() || ! $item->isReadable() ) {
					continue;
				}

				$real = $item->getRealPath();
				if ( false === $real || 0 !== strpos( $real, $root ) ) {
					continue;
				}

				$relative = 'site/' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) ) );
				$zip->addFileFromPath( fileName: $relative, path: $real );
			}

			$config_path = self::find_wp_config_path( $root );
			if ( $config_path && 0 !== strpos( $config_path, $root ) && is_readable( $config_path ) ) {
				$zip->addFileFromPath( fileName: 'wp-config.php', path: $config_path );
			}
		}

		/**
		 * Finds wp-config.php in the normal WordPress locations.
		 */
		private static function find_wp_config_path( string $root ): ?string {
			$local_config = $root . 'wp-config.php';
			if ( is_readable( $local_config ) ) {
				return realpath( $local_config ) ?: null;
			}

			$parent_config = dirname( rtrim( $root, DIRECTORY_SEPARATOR ) ) . DIRECTORY_SEPARATOR . 'wp-config.php';
			if ( is_readable( $parent_config ) ) {
				return realpath( $parent_config ) ?: null;
			}

			return null;
		}

		/**
		 * Creates a mysqldump-php instance using current wp-config.php constants.
		 */
		private static function create_database_dump(): Ifsnop\Mysqldump\Mysqldump {
			foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ) as $constant ) {
				if ( ! defined( $constant ) ) {
					throw new RuntimeException( $constant . ' is not defined in wp-config.php.' );
				}
			}

			$dsn = self::database_dsn();
			if ( defined( 'DB_CHARSET' ) && DB_CHARSET ) {
				$dsn .= ';charset=' . DB_CHARSET;
			}

			return new Ifsnop\Mysqldump\Mysqldump(
				$dsn,
				DB_USER,
				DB_PASSWORD,
				array(
					'skip-comments'       => true,
					'single-transaction'  => true,
					'add-drop-table'      => true,
					'add-drop-trigger'    => true,
					'routines'            => true,
					'events'              => true,
				)
			);
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
				PHP_VERSION_ID >= 80100,
				PHP_VERSION . ' OK',
				PHP_VERSION . ' потрібен PHP 8.1+'
			);
			$checks[] = self::check_result(
				'Composer vendor',
				is_readable( __DIR__ . '/vendor/autoload.php' ) || ( class_exists( 'ZipStream\\ZipStream' ) && class_exists( 'Ifsnop\\Mysqldump\\Mysqldump' ) ),
				'Залежності доступні',
				'Немає vendor/autoload.php'
			);
			$checks[] = self::check_result(
				'PHP розширення',
				extension_loaded( 'pdo_mysql' ),
				'pdo_mysql доступний',
				'Немає pdo_mysql'
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
		 * @return array{label:string,message:string,status:string,critical:bool}
		 */
		private static function check_result( string $label, bool $passed, string $ok_message, string $bad_message ): array {
			return array(
				'label'    => $label,
				'message'  => $passed ? $ok_message : $bad_message,
				'status'   => $passed ? 'ok' : 'bad',
				'critical' => ! $passed,
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
		 * Returns whether all critical checks passed.
		 *
		 * @param array<int,array{critical:bool}> $checks Checks.
		 */
		private static function checks_are_ready( array $checks ): bool {
			foreach ( $checks as $check ) {
				if ( ! empty( $check['critical'] ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Scans WordPress root and returns size statistics.
		 *
		 * @return array{bytes:int,size_human:string,files:int,directories:int,skipped:int,elapsed:float}
		 */
		private static function scan_site_size(): array {
			$root = realpath( ABSPATH );

			if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
				throw new RuntimeException( 'WordPress root directory is not readable.' );
			}

			$started = microtime( true );
			$bytes = 0;
			$files = 0;
			$directories = 0;
			$skipped = 0;

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setFlags( RecursiveIteratorIterator::CATCH_GET_CHILD );

			foreach ( $iterator as $item ) {
				if ( $item->isLink() ) {
					$skipped++;
					continue;
				}

				if ( $item->isDir() ) {
					$directories++;
					continue;
				}

				if ( ! $item->isFile() || ! $item->isReadable() ) {
					$skipped++;
					continue;
				}

				$size = $item->getSize();
				if ( false === $size ) {
					$skipped++;
					continue;
				}

				$files++;
				$bytes += $size;
			}

			$config_path = self::find_wp_config_path( rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
			if ( $config_path && 0 !== strpos( $config_path, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) && is_readable( $config_path ) ) {
				$bytes += filesize( $config_path ) ?: 0;
				$files++;
			}

			return array(
				'bytes'       => $bytes,
				'size_human'  => self::format_bytes( $bytes ),
				'files'       => $files,
				'directories' => $directories,
				'skipped'     => $skipped,
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
