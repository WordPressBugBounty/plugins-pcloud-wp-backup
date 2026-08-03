<?php
/**
 * WP2PcloudFuncs class
 *
 * @file class-wp2pcloudfuncs.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

/**
 * Class WP2PcloudFuncs
 */
class WP2PcloudFuncs {

	/**
	 * The host's own `memory_limit` in megabytes, captured before we lift it to -1.
	 *
	 * -1 means the host itself was already unlimited; 0 means "not captured yet".
	 * See get_zip_memory_budget_mb() for why we keep this.
	 *
	 * @var int
	 */
	private static int $host_memory_limit_mb = 0;

	/**
	 * Try to set high execution limits
	 */
	public static function set_execution_limits(): void {

		// Remember what the host was willing to give us BEFORE we raise the ceiling.
		// Lifting memory_limit to -1 stops PHP from aborting mid-backup, but it also
		// removes the only signal we had about how much RAM this container actually
		// has. Callers that need a working budget use get_zip_memory_budget_mb().
		if ( 0 === self::$host_memory_limit_mb ) {
			self::$host_memory_limit_mb = self::get_memory_limit();
		}

		// Lift the memory limit for the duration of the backup, where the host allows it.
		// (Previous revisions checked function_exists('memory_limit'), which is not a PHP
		// function — the branch was dead and memory was never actually raised.)
		if ( wp_is_ini_value_changeable( 'memory_limit' ) ) {
			@ini_set( 'memory_limit', '-1' ); // phpcs:ignore
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}

		if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
			$current_limit = ini_get( 'memory_limit' );
			if ( false === wp_is_ini_value_changeable( 'memory_limit' ) && is_string( $current_limit ) && '' !== $current_limit ) {
				define( 'WP_MEMORY_LIMIT', $current_limit );
			} else {
				define( 'WP_MEMORY_LIMIT', '256M' );
			}
		}
	}

	/**
	 * Drop hardening files into a working directory that temporarily holds backup
	 * archives, so those archives cannot be directory-listed or downloaded over HTTP.
	 *
	 * These files only affect the web server — the plugin itself reads and writes the
	 * archives via direct filesystem calls, which are unaffected.
	 *
	 * @param string $dir Absolute path to the directory to harden.
	 * @return void
	 */
	public static function harden_dir( string $dir ): void {

		$dir = rtrim( $dir, '/' );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		foreach ( self::guard_files() as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * The guard files harden_dir() writes, as name => contents.
	 *
	 * Single source of truth so cleanup routines can recognise and preserve them.
	 * Any routine that removes files from a working directory MUST skip these —
	 * see is_guard_file().
	 *
	 * @return array<string, string>
	 */
	public static function guard_files(): array {
		return array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# pCloud WP Backup - deny direct web access to backup archives.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		);
	}

	/**
	 * Is this basename one of the working-directory guard files?
	 *
	 * @param string $basename File name to test (no path).
	 * @return bool
	 */
	public static function is_guard_file( string $basename ): bool {
		return array_key_exists( $basename, self::guard_files() );
	}

	/**
	 * Provides the pCloud API endpoint hostname depending on the selected by user datacenter.
	 *
	 * @return string
	 */
	public static function get_api_ep_hostname(): string {
		$location = intval( self::get_stored_val( PCLOUD_API_LOCATIONID, '1' ) );
		if ( $location < 1 ) {
			$location = 1;
		}

		if ( 1 === $location ) {
			$wp2pcl_api_server = 'api.pcloud.com';
		} else {
			$wp2pcl_api_server = 'eapi.pcloud.com';
		}

		return $wp2pcl_api_server;
	}

	/**
	 * Get stored value in the WP options system
	 *
	 * @param string          $key Storred item key, must be a string.
	 * @param int|string|null $default Item default value.
	 *
	 * @return int|string|null
	 */
	public static function get_stored_val( string $key, int|string|null $default = '' ): int|string|null {

		$key = trim( $key );

		$test_val = get_option( $key, false );
		if ( is_bool( $test_val ) && ! $test_val ) {
			add_option( $key, $default, '', self::should_autoload( $key ) ? 'yes' : 'no' );

			return $default;
		}

		return $test_val;
	}

	/**
	 * Set stored value in the WP options system
	 *
	 * @param string $key   Storred item key, must be a string.
	 * @param mixed  $value Storred item value.
	 */
	public static function set_stored_val( string $key, mixed $value ): void {

		$key = trim( $key );

		$test_val = get_option( $key, false );
		if ( is_bool( $test_val ) && ! $test_val ) {
			add_option( $key, strval( $value ), '', self::should_autoload( $key ) ? 'yes' : 'no' );
		} elseif ( strval( $test_val ) !== strval( $value ) ) {
			update_option( $key, strval( $value ) );
		}
	}

	/**
	 * Decide whether a given option key should be autoloaded on every WP request.
	 *
	 * Small, always-read config options autoload. Large, hot-write operational options
	 * (log, debug log, operation state JSON, async-update queue, notifications) do not.
	 * Frontend page loads used to pay the cost of loading hundreds of kilobytes of backup
	 * logs into memory just to render a blog post.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private static function should_autoload( string $key ): bool {
		static $no_autoload = null;
		if ( null === $no_autoload ) {
			$no_autoload = array(
				PCLOUD_OPERATION,
				PCLOUD_LOG,
				PCLOUD_DBG_LOG,
				PCLOUD_NOTIFICATIONS,
				PCLOUD_ASYNC_UPDATE_VAL,
			);
		}
		return ! in_array( $key, $no_autoload, true );
	}

	/**
	 * Get operation
	 *
	 * @return array
	 */
	public static function get_operation(): array {

		$opration_json = self::get_stored_val( PCLOUD_OPERATION );
		if ( empty( $opration_json ) ) {
			$opration_json = '{"operation": "nothing", "state": "sleep"}';
		}

		$resp_arr = json_decode( $opration_json, true );
		if ( ! is_array( $resp_arr ) ) {
			$resp_arr = array(
				'operation' => 'nothing',
				'state'     => 'sleep',
			);
		}

		return $resp_arr;
	}

	/**
	 * Set operation
	 *
	 * @param array|null $operation_data Array with current operational data, can be empty.
	 *
	 * @return void
	 */
	public static function set_operation( ?array $operation_data = array() ): void {

		if ( null === $operation_data || count( $operation_data ) < 1 ) {
			$operation_data = array(
				'operation' => 'nothing',
				'state'     => 'sleep',
			);
		}

		if ( isset( $operation_data['state'] ) && 'sleep' === $operation_data['state'] ) {
			$operation_data['cleanat'] = time() + 5;
		}

		// Merge queued async-update items first, then let the caller's explicit values win.
		// Previous revisions had this inverted (async clobbered caller), which meant any stale
		// queue entry could silently reset `failures`, `offset`, or other progress fields.
		$merged = array();

		$waiting_async_items = self::get_stored_val( PCLOUD_ASYNC_UPDATE_VAL );
		if ( ! empty( $waiting_async_items ) ) {
			$items_to_update = json_decode( $waiting_async_items, true );
			if ( is_array( $items_to_update ) ) {
				$merged = $items_to_update;
			}
			self::set_stored_val( PCLOUD_ASYNC_UPDATE_VAL, '' );
		}

		$merged = array_merge( $merged, $operation_data );

		self::set_stored_val( PCLOUD_OPERATION, wp_json_encode( $merged ) );
	}

	/**
	 * Add item for async update as a setting.
	 *
	 * @param string $key Key of the setting.
	 * @param mixed  $value The value of the setting.
	 *
	 * @return void
	 */
	public static function add_item_for_async_update( string $key, mixed $value ): void {

		if ( empty( $key ) ) {
			return;
		}

		$items_to_update = array();

		$waiting_items = self::get_stored_val( PCLOUD_ASYNC_UPDATE_VAL );
		if ( ! empty( $waiting_items ) ) {
			$items_to_update = json_decode( $waiting_items, true );
		}

		$items_to_update[ $key ] = $value;

		$json_data = wp_json_encode( $items_to_update );

		self::set_stored_val( PCLOUD_ASYNC_UPDATE_VAL, $json_data );
	}

	/**
	 * Format bytes to human-readable format.
	 *
	 * Delegates to WP core's `size_format()`. The previous homegrown implementation was a
	 * strlen-based factor calculation that produced wrong results at decimal boundaries
	 * (e.g. 1,000,000 bytes reported as "0.95MB").
	 *
	 * @param string|int $bytes Bytes to be made human-readable.
	 * @return string
	 */
	public static function format_bytes( $bytes ): string {
		$bytes = (int) $bytes;
		if ( function_exists( 'size_format' ) ) {
			$formatted = size_format( $bytes, 2 );
			if ( false !== $formatted && '' !== $formatted ) {
				return $formatted;
			}
		}
		// Fallback for unit tests / CLI contexts where WP isn't loaded.
		$units  = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$factor = 0;
		$value  = (float) $bytes;
		while ( $value >= 1024 && $factor < count( $units ) - 1 ) {
			$value /= 1024;
			$factor++;
		}
		return sprintf( '%.2f %s', $value, $units[ $factor ] );
	}

	/**
	 * Get the current memory usage allocated to this PHP process and convert it to human-readable.
	 *
	 * @return string
	 */
	public static function memory_usage(): string {
		$mem_limit_ini = ini_get( 'memory_limit' );

		$mem = memory_get_usage();
		if ( $mem > 0 ) {
			return 'mem: ' . self::format_bytes( $mem ) . '/' . $mem_limit_ini;
		} else {
			return 'mem: --';
		}
	}

	/**
	 * Get the PHP memory limit in megabytes.
	 *
	 * Previous revisions did `str_replace(['M','K','G'], '', $limit)` which mixed units: a
	 * `1G` limit returned `1` (one, compared against a 64 MB threshold), a `65536K` limit
	 * returned `65536`. Callers then treated the value as megabytes. This function now
	 * returns a true megabyte count regardless of the underlying unit suffix.
	 *
	 * A return value of `-1` indicates an unlimited `memory_limit` (ini `-1`).
	 *
	 * @return int Megabytes, or -1 for unlimited.
	 */
	public static function get_memory_limit(): int {

		$current_limit = ini_get( 'memory_limit' );
		if ( ! is_string( $current_limit ) || '' === $current_limit ) {
			return 128; // Conservative default if ini_get misbehaves.
		}

		if ( '-1' === trim( $current_limit ) ) {
			return -1;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			$bytes = wp_convert_hr_to_bytes( $current_limit );
		} else {
			// Fallback for environments where WP helpers aren't loaded yet.
			$value = trim( $current_limit );
			$unit  = strtolower( substr( $value, -1 ) );
			$num   = (int) $value;
			$bytes = match ( $unit ) {
				'g'     => $num * 1024 * 1024 * 1024,
				'm'     => $num * 1024 * 1024,
				'k'     => $num * 1024,
				default => $num,
			};
		}

		return (int) round( $bytes / 1024 / 1024 );
	}

	/**
	 * Working memory budget, in megabytes, for deciding when to close a ZIP archive
	 * and start the next one. ALWAYS returns a positive number.
	 *
	 * Why this exists (regression fixed in 2.0.7): set_execution_limits() raises
	 * `memory_limit` to -1, after which get_memory_limit() reports -1 and the caller
	 * in create_zip() disabled memory-based splitting entirely (PHP_INT_MAX). The
	 * whole site then went into a single archive built by one synchronous
	 * save_as_file() call — no intermediate flushes, no log output for the duration,
	 * and no ceiling at which PHP would stop itself. On a memory-capped container the
	 * kernel OOM killer or the php-fpm request_terminate_timeout ends the worker with
	 * no PHP error at all, which is exactly how a large-site backup "hangs" forever.
	 *
	 * An unlimited `memory_limit` is a licence not to crash — it is not a promise of
	 * infinite RAM. So we budget against the host's ORIGINAL limit, and fall back to a
	 * conservative default when the host was unlimited to begin with.
	 *
	 * @return int Megabytes. Always > 0.
	 */
	public static function get_zip_memory_budget_mb(): int {

		// Prefer the limit the host had before set_execution_limits() lifted it.
		$limit_mb = self::$host_memory_limit_mb;

		// Not captured yet (create_zip reached before any set_execution_limits call):
		// read the live value, which is still the host's own at that point.
		if ( 0 === $limit_mb ) {
			$limit_mb = self::get_memory_limit();
		}

		if ( $limit_mb > 0 ) {
			$budget = (int) round( $limit_mb * 0.7 );
		} else {
			// Host itself is unlimited. Assume a mainstream container size rather than
			// pretending we can grow without bound.
			$budget = 256;
		}

		// Never let the budget fall below the point where a single archive is pointless,
		// and never trust a filter to hand back something unusable.
		$budget = (int) apply_filters( 'pcloud_zip_memory_budget_mb', $budget, $limit_mb );

		return $budget > 32 ? $budget : 32;
	}

	/**
	 * Snapshot of the runtime facts that decide whether a backup survives.
	 *
	 * Collected for the debug log so a support ticket arrives with the environment
	 * already described. The SAPI matters because php-fpm enforces
	 * `request_terminate_timeout` regardless of set_time_limit(0), which is a common
	 * silent killer of long ZIP phases.
	 *
	 * @param string $tmp_dir Optional working directory to report free space for.
	 *
	 * @return array<string, string>
	 */
	public static function environment_snapshot( string $tmp_dir = '' ): array {

		$time_limit = ini_get( 'max_execution_time' );
		$changeable = wp_is_ini_value_changeable( 'memory_limit' ) ? 'yes' : 'no';

		$snapshot = array(
			'php'                 => PHP_VERSION,
			'sapi'                => php_sapi_name(),
			'memory_limit (ini)'  => (string) ini_get( 'memory_limit' ),
			'memory_limit (host)' => ( self::$host_memory_limit_mb > 0 )
				? self::$host_memory_limit_mb . 'M'
				: 'unlimited',
			'memory_limit mutable' => $changeable,
			'zip split budget'    => self::get_zip_memory_budget_mb() . 'M',
			'max_execution_time'  => ( false === $time_limit ) ? '?' : (string) $time_limit,
			'peak memory'         => self::format_bytes( memory_get_peak_usage( true ) ),
		);

		if ( '' !== $tmp_dir && is_dir( $tmp_dir ) ) {
			$free = @disk_free_space( $tmp_dir ); // phpcs:ignore
			$snapshot['tmp free space'] = is_float( $free ) ? self::format_bytes( (int) $free ) : 'unknown';
		}

		return $snapshot;
	}
}
