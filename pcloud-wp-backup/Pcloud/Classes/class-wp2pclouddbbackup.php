<?php
/**
 * WP2PcloudDBBackup class
 *
 * @file class-wp2pclouddbbackup.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

use Exception;

/**
 * Class WP2PcloudDBBackup
 */
class WP2PcloudDBBackup {

	/**
	 * Holds the file location where data is saved. This can either be a string representing the path or false if saving is disabled.
	 *
	 * @var string $save_file
	 */
	private string $save_file;

	/**
	 * Class constructor
	 *
	 * @throws Exception If no writable temporary location is available for the SQL dump.
	 */
	public function __construct() {

		// Prefer the system temp dir; fall back to our plugin tmp dir if sys_get_temp_dir is
		// unwritable. Never fall back to ABSPATH — that location is typically web-readable and
		// a raw SQL dump there would leak credentials/emails/options.
		$save_file = @tempnam( sys_get_temp_dir(), 'sqlarchive' ); // phpcs:ignore

		if ( is_string( $save_file ) && is_writable( $save_file ) ) {
			$this->save_file = $save_file;
			return;
		}

		if ( defined( 'PCLOUD_TEMP_DIR' ) ) {
			if ( ! is_dir( PCLOUD_TEMP_DIR ) ) {
				@mkdir( PCLOUD_TEMP_DIR, 0755, true ); // phpcs:ignore
			}
			$fallback = @tempnam( PCLOUD_TEMP_DIR, 'sqlarchive' ); // phpcs:ignore
			if ( is_string( $fallback ) && is_writable( $fallback ) ) {
				$this->save_file = $fallback;
				return;
			}
		}

		throw new Exception(
			'Unable to create a writable temporary file for the database dump. Check permissions on '
			. sys_get_temp_dir()
			. ( defined( 'PCLOUD_TEMP_DIR' ) ? ' and ' . PCLOUD_TEMP_DIR : '' )
			. '.'
		);
	}

	/**
	 * Initiates Database backup
	 *
	 * @throws Exception Throws standart Exception.
	 */
	public function start(): bool|string {

		wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='start_db_backup'>Starting Database Backup</span>" );
		wp2pclouddebugger::log( 'db_backup->start()' );

		$dump_settings = array(
			'exclude-tables'     => array(),
			'compress'           => PclMysqlDump::NONE,
			'no-data'            => false,
			'add-drop-table'     => true,
			'single-transaction' => true,
			'lock-tables'        => false,
			'add-locks'          => false,
			'extended-insert'    => false,
			'disable-keys'       => true,
			'skip-triggers'      => false,
			'add-drop-trigger'   => true,
			'routines'           => true,
			'databases'          => false,
			'add-drop-database'  => false,
			'hex-blob'           => true,
			'no-create-info'     => false,
			'no-autocommit'      => false,
		);

		$dump = new PclMysqlDump( self::build_pdo_dsn( DB_HOST, DB_NAME ), DB_USER, DB_PASSWORD, $dump_settings );

		try {

			$dump->start( $this->save_file );

			wp2pclouddebugger::log( 'db_backup->start() - process succeeded!' );
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='db_backup_finished' style='color: #00ff00'>Database Backup Finished</span>" );

		} catch ( Exception $e ) {

			$msg = $e->getMessage();

			wp2pclouddebugger::log( 'db_backup->start() - Failed:' . $msg );
			wp2pcloudlogger::info( '<span style="color: red">Plugin error: ' . esc_html( $msg ) . '</span>' );

			// The dump never completed, so $this->save_file is empty (only the
			// ZIP header's worth of bytes once compressed). Returning that path
			// would let the caller ship a backup that silently omits the whole
			// database. Clean up and report failure instead.
			if ( is_file( $this->save_file ) ) {
				@unlink( $this->save_file ); // phpcs:ignore
			}

			wp2pcloudlogger::notification(
				"<span class='pcl_transl' data-i10nk='db_backup_failed_notice'>The database was NOT included in this backup because the connection to MySQL failed.</span> "
				. '<span>' . esc_html( $msg ) . '</span>'
			);

			return false;
		}

		return $this->save_file;
	}

	/**
	 * Build a PDO DSN from a WordPress DB_HOST value.
	 *
	 * WordPress (via mysqli/wpdb) accepts several DB_HOST forms that a PDO DSN
	 * does not understand as-is: "host:port", "host:/path/to/socket",
	 * ":/path/to/socket", and bracketed IPv6 such as "[::1]:3306". In a PDO DSN
	 * the host, port and unix_socket must be separate keys, so a raw
	 * "mysql:host=localhost:3306;..." makes PDO treat "localhost:3306" as a
	 * literal hostname and fail with "Unknown MySQL server host". This mirrors
	 * wpdb::parse_db_host() so the dumper connects wherever WordPress itself can.
	 *
	 * Public since v2.0.6 so the restore path (WP2PcloudFileRestore::restore_db)
	 * can build its own DSN the same way instead of concatenating DB_HOST raw.
	 *
	 * @param string $db_host Raw DB_HOST constant value.
	 * @param string $db_name Database name.
	 * @return string PDO DSN string (`mysql:host=...;port=...;dbname=...` or unix_socket).
	 */
	public static function build_pdo_dsn( string $db_host, string $db_name ): string {

		$host   = $db_host;
		$port   = '';
		$socket = '';

		// Peel off a unix socket path (":/path/to/socket") from the right.
		$socket_pos = strpos( $host, ':/' );
		if ( false !== $socket_pos ) {
			$socket = substr( $host, $socket_pos + 1 );
			$host   = substr( $host, 0, $socket_pos );
		}

		if ( substr_count( $host, ':' ) > 1 ) {
			// IPv6 address, optionally "[::1]:port".
			$pattern = '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>\d+))?#';
		} else {
			// IPv4 address / hostname, optionally "host:port".
			$pattern = '#^(?P<host>[^:/]*)(?::(?P<port>\d+))?#';
		}

		$matches = array();
		if ( 1 === preg_match( $pattern, $host, $matches ) ) {
			$host = $matches['host'] ?? '';
			$port = ( isset( $matches['port'] ) && '' !== $matches['port'] ) ? $matches['port'] : '';
		}

		$dsn = 'mysql:';
		if ( '' !== $socket ) {
			$dsn .= 'unix_socket=' . $socket . ';';
		} else {
			$dsn .= 'host=' . $host . ';';
			if ( '' !== $port ) {
				$dsn .= 'port=' . $port . ';';
			}
		}

		return $dsn . 'dbname=' . $db_name;
	}
}
