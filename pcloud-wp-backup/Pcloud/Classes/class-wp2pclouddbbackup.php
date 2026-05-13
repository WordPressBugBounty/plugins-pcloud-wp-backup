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

		$dump = new PclMysqlDump( 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD, $dump_settings );

		try {

			$dump->start( $this->save_file );

			wp2pclouddebugger::log( 'db_backup->start() - process succeeded!' );
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='db_backup_finished' style='color: #00ff00'>Database Backup Finished</span>" );

		} catch ( Exception $e ) {

			$msg = $e->getMessage();

			wp2pclouddebugger::log( 'db_backup->start() - Failed:' . $msg );
			wp2pcloudlogger::info( '<span>Plugin error: ' . $msg . '</span>' );
		}

		return $this->save_file;
	}
}
