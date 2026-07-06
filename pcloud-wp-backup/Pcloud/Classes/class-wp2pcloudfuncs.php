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
	 * Try to set high execution limits
	 */
	public static function set_execution_limits(): void {

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
	 * Apache honours the .htaccess deny rule, IIS honours web.config, and index.php
	 * blocks directory listing on every server. These files only affect the web
	 * server — the plugin itself reads/writes the archives via direct filesystem
	 * calls, which are unaffected.
	 *
	 * NOTE: nginx ignores .htaccess. For complete cross-server coverage the working
	 * directory should also live outside the web root; that relocation is tracked
	 * separately. This hardening is the in-place mitigation.
	 *
	 * @param string $dir Absolute path to the directory to harden.
	 * @return void
	 */
	public static function harden_dir( string $dir ): void {

		$dir = rtrim( (string) $dir, '/' );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$guard_files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# pCloud WP Backup - deny direct web access to backup archives.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		);

		foreach ( $guard_files as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Provides tha pCloud API endpoint hostname depending on the selected by user datacenter.
	 *
	 * @return string
	 */
	public static function get_api_ep_hostname(): string {
		$location = intval( self::get_storred_val( PCLOUD_API_LOCATIONID, '1' ) );
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
	 * Get storred value in WP options system
	 *
	 * @param string          $key Storred item key, must be a string.
	 * @param int|string|null $default Item default value.
	 *
	 * @return int|string|null
	 */
	public static function get_storred_val( string $key, int|string|null $default = '' ): int|string|null {

		$key = trim( $key );

		$test_val = get_option( $key, false );
		if ( is_bool( $test_val ) && ! $test_val ) {
			add_option( $key, $default, '', self::should_autoload( $key ) ? 'yes' : 'no' );

			return $default;
		}

		return $test_val;
	}

	/**
	 * Set storred value in WP options system
	 *
	 * @param string $key   Storred item key, must be a string.
	 * @param mixed  $value Storred item value.
	 */
	public static function set_storred_val( string $key, mixed $value ): void {

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

		$opration_json = self::get_storred_val( PCLOUD_OPERATION );
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

		$waiting_async_items = self::get_storred_val( PCLOUD_ASYNC_UPDATE_VAL );
		if ( ! empty( $waiting_async_items ) ) {
			$items_to_update = json_decode( $waiting_async_items, true );
			if ( is_array( $items_to_update ) ) {
				$merged = $items_to_update;
			}
			self::set_storred_val( PCLOUD_ASYNC_UPDATE_VAL, '' );
		}

		$merged = array_merge( $merged, $operation_data );

		self::set_storred_val( PCLOUD_OPERATION, wp_json_encode( $merged ) );
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

		$waiting_items = self::get_storred_val( PCLOUD_ASYNC_UPDATE_VAL );
		if ( ! empty( $waiting_items ) ) {
			$items_to_update = json_decode( $waiting_items, true );
		}

		$items_to_update[ $key ] = $value;

		$json_data = wp_json_encode( $items_to_update );

		self::set_storred_val( PCLOUD_ASYNC_UPDATE_VAL, $json_data );
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
		$memlimitini = ini_get( 'memory_limit' );

		$mem = memory_get_usage();
		if ( $mem > 0 ) {
			return 'mem: ' . self::format_bytes( $mem ) . '/' . $memlimitini;
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
}
