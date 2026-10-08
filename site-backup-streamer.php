<?php
/**
 * Plugin Name: Site Backup Streamer
 * Description: Adds a dashboard widget for streaming WordPress site files and database backups.
 * Version: 1.1.2
 * Requires at least: 6.0
 * Requires PHP: 8.1
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
	define( 'SBS_VERSION', '1.1.2' );
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
		private const ZIP_MIN_TIMESTAMP = 315532800;  // 1980-01-01 00:00:00 UTC.
		private const ZIP_MAX_TIMESTAMP = 4354819198; // 2107-12-31 23:59:58 UTC, the last DOS date.
		// ZipStream reads a file in 16 MB blocks, fread() allocates the whole block up front, and the previous
		// block is still alive while the next one is read: a file needs up to two blocks of min(size, 16 MB).
		private const ZIP_READ_BLOCK = 16777216;
		// Hashing, headers, the central directory record of the file and 2 MB heap chunks.
		private const ZIP_MEMORY_MARGIN = 4194304;
		// ZipStream keeps the central directory records in one array. When it doubles, the new block is allocated
		// while the old one is still alive: 32 bytes a slot on PHP 8.1, 16 since packed arrays hold bare zvals (8.2).
		private const ZIP_CDR_SLOT = PHP_VERSION_ID >= 80200 ? 16 : 32;
		// Kept free while files are added, so backup-incomplete.txt still fits once the memory has run out.
		private const ZIP_NOTE_RESERVE = 4194304;
		// Below this not even small files fit, so the archive does not start: every file needs the margin and the
		// reserve, and the measured usage moves in 2 MB heap chunks.
		private const ZIP_MEMORY_MINIMUM = 12582912;
		// Lists the files left out for lack of memory; added to the archive root only when there are any.
		private const ZIP_INCOMPLETE_NOTE = 'backup-incomplete.txt';
		private const ZIP_NOTE_MAX_FILES  = 1000;
		private const ZIP_NOTE_MAX_BYTES  = 262144; // Of listed file names.

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

			foreach ( array( 'outputName', 'outputStream', 'sendHttpHeaders', 'defaultCompressionMethod', 'defaultEnableZeroHeader', 'enableZip64', 'flushOutput' ) as $parameter ) {
				if ( ! in_array( $parameter, $zip_parameters, true ) ) {
					throw new RuntimeException( __( 'Завантажена версія ZipStream несумісна з плагіном.', 'site-backup-streamer' ) );
				}
			}

			if ( ! method_exists( 'ZipStream\\ZipStream', 'addFileFromPath' ) || ! method_exists( 'ZipStream\\ZipStream', 'addFileFromStream' ) || ! method_exists( 'ZipStream\\ZipStream', 'finish' ) ) {
				throw new RuntimeException( __( 'У завантаженій версії ZipStream немає потрібних методів.', 'site-backup-streamer' ) );
			}

			$add_file_parameters = array_map(
				static function ( ReflectionParameter $parameter ): string {
					return $parameter->getName();
				},
				( new ReflectionMethod( 'ZipStream\\ZipStream', 'addFileFromPath' ) )->getParameters()
			);

			foreach ( array( 'fileName', 'path', 'lastModificationDateTime', 'maxSize' ) as $parameter ) {
				if ( ! in_array( $parameter, $add_file_parameters, true ) ) {
					throw new RuntimeException( __( 'Завантажена версія ZipStream несумісна з плагіном.', 'site-backup-streamer' ) );
				}
			}

			$add_stream_parameters = array_map(
				static function ( ReflectionParameter $parameter ): string {
					return $parameter->getName();
				},
				( new ReflectionMethod( 'ZipStream\\ZipStream', 'addFileFromStream' ) )->getParameters()
			);

			foreach ( array( 'fileName', 'stream', 'maxSize' ) as $parameter ) {
				if ( ! in_array( $parameter, $add_stream_parameters, true ) ) {
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

			// A buffer started without the removable flag cannot be ended: stop instead of looping forever.
			while ( ob_get_level() > 0 ) {
				$level = ob_get_level();
				if ( ! @ob_end_clean() || ob_get_level() >= $level ) {
					break;
				}
			}

			// Through such a buffer the archive or the dump would pile up in memory, so refuse before any header.
			if ( ob_get_level() > 0 ) {
				throw new RuntimeException( __( 'Інший код запустив буфер виводу, який не можна зняти, тож потокове завантаження неможливе: архів або дамп накопичувався б у пам\'яті. Вимкніть плагін, що його запускає, і спробуйте знову.', 'site-backup-streamer' ) );
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
		 * Returns memory_limit in bytes, or null when there is no limit.
		 */
		private static function memory_limit(): ?int {
			$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

			return $limit > 0 ? $limit : null;
		}

		/**
		 * Returns memory left under memory_limit in bytes, or null when there is no limit.
		 *
		 * PHP checks the limit against the memory it took from the system, so this counts the real usage.
		 */
		private static function free_memory(): ?int {
			$limit = self::memory_limit();

			return null === $limit ? null : $limit - memory_get_usage( true );
		}

		/**
		 * Returns the memory PHP takes for one allocation of the given size.
		 *
		 * A string adds its header; blocks above about 2 MB are mapped on their own, page-aligned, and 2 MB-aligned
		 * on Windows.
		 */
		private static function heap_block( int $bytes ): int {
			if ( $bytes <= 0 ) {
				return 0;
			}

			$bytes += 32;
			$align  = $bytes > 2093056 && 'Windows' === PHP_OS_FAMILY ? 2097152 : 4096;

			return intdiv( $bytes + $align - 1, $align ) * $align;
		}

		/**
		 * Returns the new central directory array ZipStream allocates when it adds a record to the given count.
		 */
		private static function cdr_growth( int $entries ): int {
			// The array doubles when it holds a power of two records (it starts with 8 slots).
			return $entries >= 8 && 0 === ( $entries & ( $entries - 1 ) ) ? self::heap_block( 2 * $entries * self::ZIP_CDR_SLOT ) : 0;
		}

		/**
		 * Returns the free memory ZipStream needs to add a file of the given size to an archive of $entries files.
		 */
		private static function zip_memory_needed( int $size, int $entries = 0 ): int {
			$size  = max( 0, $size );
			$first = min( $size, self::ZIP_READ_BLOCK );
			$next  = min( $size - $first, self::ZIP_READ_BLOCK );

			return self::heap_block( $first ) + self::heap_block( $next ) + self::cdr_growth( $entries ) + self::ZIP_MEMORY_MARGIN;
		}

		/**
		 * Checks whether a file of the given size can be added to an archive of $entries files right now.
		 *
		 * Running out of memory in the middle of a file is a fatal error that leaves a broken ZIP. The note reserve,
		 * with the array growth the note itself may need, stays free for backup-incomplete.txt.
		 */
		private static function zip_file_fits( int $size, int $entries = 0 ): bool {
			$free = self::free_memory();

			return null === $free || $free >= self::zip_memory_needed( $size, $entries ) + self::cdr_growth( $entries + 1 ) + self::ZIP_NOTE_RESERVE;
		}

		/**
		 * Returns about the largest file that fits into the given free memory, or null when any file does.
		 */
		private static function largest_zip_file( int $free ): ?int {
			$room = $free - self::ZIP_MEMORY_MARGIN - self::ZIP_NOTE_RESERVE;

			return $room >= 2 * self::heap_block( self::ZIP_READ_BLOCK ) ? null : max( 0, $room );
		}

		/**
		 * Formats a size for plain text: size_format() puts &nbsp; between thousands in some locales.
		 */
		private static function plain_size( int $bytes, int $decimals = 0 ): string {
			return html_entity_decode( (string) size_format( $bytes, $decimals ), ENT_QUOTES, 'UTF-8' );
		}

		/**
		 * Stops a files export that has no memory even for small files.
		 *
		 * Otherwise PHP dies right after the first ZIP entry header and WordPress appends its
		 * "critical error" page to the download.
		 */
		private static function assert_zip_memory(): void {
			$free = self::free_memory();

			if ( null !== $free && $free < self::ZIP_MEMORY_MINIMUM ) {
				throw new RuntimeException( self::zip_memory_message( $free ) );
			}
		}

		/**
		 * Describes a memory shortage for the files archive.
		 */
		private static function zip_memory_message( int $free ): string {
			return sprintf(
				/* translators: 1: free memory, 2: memory_limit, 3: required memory. */
				__( 'Замало пам\'яті для архіву: вільно %1$s з %2$s, потрібно щонайменше %3$s. Збільште WP_MAX_MEMORY_LIMIT або memory_limit.', 'site-backup-streamer' ),
				size_format( max( 0, $free ) ),
				size_format( (int) self::memory_limit() ),
				size_format( self::ZIP_MEMORY_MINIMUM )
			);
		}

		/**
		 * Builds the "Пам'ять" check row from the free memory the files export will get.
		 *
		 * Short of memory for the largest files the row is a warning only: those files are left out and listed in
		 * the archive. It blocks the files download only when not even small files fit.
		 *
		 * @return array{label:string,message:string,status:string,critical:bool,scope:string}
		 */
		private static function memory_check_result( ?int $free, ?int $limit ): array {
			if ( null === $free || null === $limit ) {
				return self::check_result( 'Пам\'ять', true, 'Без обмеження', '', 'files' );
			}

			if ( $free < self::ZIP_MEMORY_MINIMUM ) {
				return self::check_result( 'Пам\'ять', false, '', self::zip_memory_message( $free ), 'files' );
			}

			$summary = sprintf(
				/* translators: 1: free memory, 2: memory_limit, 3: used memory. */
				__( 'Вільно %1$s: ліміт %2$s, зайнято %3$s', 'site-backup-streamer' ),
				size_format( $free ),
				size_format( $limit ),
				size_format( max( 0, $limit - $free ) )
			);
			$largest = self::largest_zip_file( $free );

			if ( null === $largest ) {
				return self::check_result( 'Пам\'ять', true, $summary, '', 'files' );
			}

			$row = self::check_result(
				'Пам\'ять',
				true,
				sprintf(
					/* translators: 1: free memory summary, 2: file size, 3: file name. */
					__( '%1$s. Файли понад ~%2$s можуть не ввійти в архів, а на сайтах із десятками тисяч файлів — і менші; їх список буде в %3$s. Збільште WP_MAX_MEMORY_LIMIT або memory_limit.', 'site-backup-streamer' ),
					$summary,
					size_format( $largest ),
					self::ZIP_INCOMPLETE_NOTE
				),
				'',
				'files'
			);

			$row['status'] = 'warning';

			return $row;
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

			self::write_files_zip( $zip, $directories );
			$zip->finish();
		}

		/**
		 * Adds the site files to the archive, leaving out the ones that do not fit into the free memory.
		 *
		 * @param array<string,string> $directories Directories from backup_directories().
		 * @return array{count:int,bytes:int,files:array<int,array{0:string,1:int}>} Files left out for lack of memory.
		 */
		private static function write_files_zip( ZipStream\ZipStream $zip, array $directories ): array {
			$left_out = array(
				'count' => 0,
				'bytes' => 0,
				'files' => array(),
			);
			$listed   = 0; // Bytes of listed names.
			$entries  = 0; // Records ZipStream holds.

			self::walk_backup_files(
				$directories,
				static function ( string $path, string $name, SplFileInfo $file ) use ( $zip, &$left_out, &$listed, &$entries ): bool {
					try {
						$size = $file->getSize();
					} catch ( RuntimeException $e ) {
						return false;
					}

					if ( ! self::zip_file_fits( $size, $entries ) ) {
						$left_out['count']++;
						$left_out['bytes'] += $size;
						if ( count( $left_out['files'] ) < self::ZIP_NOTE_MAX_FILES && $listed + strlen( $name ) <= self::ZIP_NOTE_MAX_BYTES ) {
							$left_out['files'][] = array( $name, $size );
							$listed             += strlen( $name );
						}
						return false;
					}

					if ( ! self::add_file_to_zip( $zip, $path, $name, $file ) ) {
						return false;
					}

					$entries++;
					return true;
				}
			);

			if ( $left_out['count'] > 0 ) {
				error_log( sprintf( 'Site Backup Streamer: %d files (%d bytes) left out of the archive for lack of memory.', $left_out['count'], $left_out['bytes'] ) );
				self::add_incomplete_note( $zip, $left_out, $entries );
			}

			return $left_out;
		}

		/**
		 * Adds one file to the archive; returns false when the file has to be skipped.
		 */
		private static function add_file_to_zip( ZipStream\ZipStream $zip, string $path, string $name, SplFileInfo $file ): bool {
			try {
				$modified = $file->getMTime();
				$size     = $file->getSize();
			} catch ( RuntimeException $e ) {
				return false;
			}

			// ZIP (DOS) dates cover 1980-2107 and ZipStream throws outside that range, which would abort the whole
			// archive: files unpacked with a zero timestamp or stamped far in the future get the nearest valid date.
			// The date always goes in UTC: left to ZipStream it is taken in the default timezone, where the range
			// check and the conversion disagree near the ends.
			$modified_at = new DateTimeImmutable( '@' . min( max( $modified, self::ZIP_MIN_TIMESTAMP ), self::ZIP_MAX_TIMESTAMP ) );

			try {
				// maxSize keeps fread() to the file size instead of a whole 16 MB block and holds the read to what
				// zip_file_fits() checked; a file that grows meanwhile is archived as it was when listed.
				$zip->addFileFromPath( fileName: $name, path: $path, lastModificationDateTime: $modified_at, maxSize: $size );
			} catch ( ZipStream\Exception\FileNotFoundException | ZipStream\Exception\FileNotReadableException $e ) {
				// Deleted or locked after the directory was listed; nothing was written for it yet.
				return false;
			}

			return true;
		}

		/**
		 * Adds backup-incomplete.txt with the files left out for lack of memory.
		 *
		 * @param array{count:int,bytes:int,files:array<int,array{0:string,1:int}>} $left_out Files left out.
		 * @param int                                                               $entries  Files in the archive.
		 */
		private static function add_incomplete_note( ZipStream\ZipStream $zip, array $left_out, int $entries ): void {
			$lines = array(
				sprintf(
					/* translators: 1: number of files, 2: their total size. */
					__( 'Архів неповний: через нестачу пам\'яті PHP не ввійшло файлів: %1$d (%2$s).', 'site-backup-streamer' ),
					$left_out['count'],
					self::plain_size( $left_out['bytes'] )
				),
				sprintf(
					/* translators: 1: memory_limit, 2: largest read, 3: fixed reserve. */
					__( 'memory_limit: %1$s. Файлу потрібно стільки вільної пам\'яті, скільки він важить (але не більше %2$s), плюс запас близько %3$s, а кожен уже доданий файл займає ще кількасот байтів до кінця архіву — тож на сайтах із дуже великою кількістю файлів не вміщаються й малі.', 'site-backup-streamer' ),
					self::plain_size( (int) self::memory_limit() ),
					self::plain_size( 2 * self::ZIP_READ_BLOCK ),
					self::plain_size( self::ZIP_MEMORY_MARGIN + self::ZIP_NOTE_RESERVE )
				),
				__( 'Заберіть ці файли окремо (FTP або файловий менеджер хостингу) або збільште WP_MAX_MEMORY_LIMIT чи memory_limit і завантажте архів знову.', 'site-backup-streamer' ),
				'',
			);

			foreach ( $left_out['files'] as $file ) {
				$lines[] = self::plain_size( $file[1], 1 ) . "\t" . $file[0];
			}

			$rest = $left_out['count'] - count( $left_out['files'] );
			if ( $rest > 0 ) {
				/* translators: %d: number of files. */
				$lines[] = sprintf( __( '… і ще файлів: %d', 'site-backup-streamer' ), $rest );
			}

			$text = implode( "\n", $lines ) . "\n";
			unset( $lines );

			// On top of the string: its copy in php://memory, the read buffer, the array growth for one more record
			// and a fresh 2 MB heap chunk. The note reserve kept this free while files were added.
			$free = self::free_memory();
			if ( null !== $free && $free < 2 * self::heap_block( strlen( $text ) ) + self::cdr_growth( $entries ) + 2097152 ) {
				error_log( 'Site Backup Streamer: no memory left for ' . self::ZIP_INCOMPLETE_NOTE . '.' );
				return;
			}

			// Our own stream rather than addFile(): with maxSize ZipStream 3.1.1 writes the text into php://memory twice,
			// and exactSize is missing before 3.1. maxSize keeps the read buffer to the text length.
			$stream = fopen( 'php://memory', 'w+b' );
			fwrite( $stream, $text );
			rewind( $stream );
			$zip->addFileFromStream( fileName: self::ZIP_INCOMPLETE_NOTE, stream: $stream, maxSize: strlen( $text ) );
			fclose( $stream );
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

			return self::zip_writer( fopen( 'php://output', 'wb' ), $filename );
		}

		/**
		 * Creates the ZipStream writer with the archive settings of the plugin.
		 *
		 * @param resource $output_stream Stream the archive is written to.
		 */
		private static function zip_writer( $output_stream, string $filename ): ZipStream\ZipStream {
			return new ZipStream\ZipStream(
				outputName: $filename,
				outputStream: $output_stream,
				sendHttpHeaders: false,
				defaultCompressionMethod: ZipStream\CompressionMethod::STORE,
				// Sizes go after the data, so every file is read once. Without it ZipStream 3.1.1 also writes
				// wrong local header sizes for files that start past 4 GB (fixed upstream only in 3.2.2).
				defaultEnableZeroHeader: true,
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

			// The download request raises the limit the same way. It loads the same plugins but does not render the
			// admin page, so the export usually gets a bit more than this.
			wp_raise_memory_limit( 'admin' );
			$checks[] = self::memory_check_result( self::free_memory(), self::memory_limit() );

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
