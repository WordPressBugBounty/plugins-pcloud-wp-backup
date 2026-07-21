<?php
/**
 * WP2PcloudFileRestore class
 *
 * @file class-wp2pcloudfilerestore.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

use PDO;
use PDOException;
use ZipArchive;

/**
 * Class WP2PcloudFileRestore
 */
class WP2PcloudFileRestore {

	/**
	 * Path where the files will be restored
	 *
	 * @var string $restore_path
	 */
	private string $restore_path;

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->restore_path = PCLOUD_TEMP_DIR . '/';

		if ( ! is_dir( $this->restore_path ) ) {
			mkdir( $this->restore_path );
		}

		// Deny web access to the archives downloaded here for restore (see CVE-2026-14503).
		wp2pcloudfuncs::harden_dir( $this->restore_path );

		if ( ! is_dir( $this->restore_path ) ) {
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='err_temp_folder_fail2mk'>ERROR: Temporary folder can not be created!</span> [" . $this->restore_path . ']' );
			wp2pclouddebugger::log( 'Failed to create Temporary folder!' );
			wp2pcloudfuncs::set_operation();

			throw new WP2PcloudRestoreException( 'Unable to create temporary folder: ' . $this->restore_path );
		}

		WP_Filesystem();
	}

	/**
	 * Download files by chunks
	 *
	 * @param string $url Final URL from which the backup file will be downloaded.
	 * @param int    $offset Offset of the downloaded file.
	 * @param string $archive_file Output archive file name.
	 *
	 * @return int
	 */
	public function download_chunk_curl( string $url, int $offset = 0, string $archive_file = 'tmp.zip' ): int {

		$chunksize = 2 * ( 1000 * 1000 ); // 2 MB

		$errstr = '';

		$args = array(
			'headers' => array(
				'Range' => 'bytes=' . $offset . '-' . ( $offset + ( $chunksize - 1 ) ),
			),
		);

		$content      = false;
		$api_response = wp_remote_get( $url, $args );
		if ( is_array( $api_response ) && ! is_wp_error( $api_response ) ) {
			$response_raw = wp_remote_retrieve_body( $api_response );
			if ( is_string( $response_raw ) ) {
				$content = $response_raw;
			}
		} elseif ( is_wp_error( $api_response ) ) {
			$errstr = $api_response->get_error_message();
		}

		if ( ! is_string( $content ) || '' === $content ) {
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='err_failed2open_conn'>Failed to open connection to the backup file:</span> [url: $url] " . $errstr );
			wp2pclouddebugger::log( 'download_chunk_curl() - Failed to open connection to the backup file: [ url: ' . $url . ', err: ' . $errstr . ' ]' );
			return $offset; // No progress; caller treats this as a failure.
		}

		// Open for read/write (create if missing). We use fseek+fwrite so retries on the same
		// offset are idempotent — unlike the previous append-mode, which produced duplicate bytes.
		if ( ! file_exists( $archive_file ) ) {
			// Ensure the file exists so r+b can open it.
			$create_handle = fopen( $archive_file, 'wb' ); // phpcs:ignore
			if ( false === $create_handle ) {
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='err_open_output_f'>Error opening the output file!</span>" );
				wp2pclouddebugger::log( 'download_chunk_curl() - Error creating the output file!' );
				return $offset;
			}
			fclose( $create_handle ); // phpcs:ignore
		}

		$o_handle = fopen( $archive_file, 'r+b' ); // phpcs:ignore
		if ( false === $o_handle ) {
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='err_open_output_f'>Error opening the output file!</span>" );
			wp2pclouddebugger::log( 'download_chunk_curl() - Error opening the output file!' );
			return $offset;
		}

		$bytes_written = 0;
		try {
			if ( 0 !== fseek( $o_handle, $offset ) ) { // phpcs:ignore
				wp2pclouddebugger::log( 'download_chunk_curl() - fseek failed, offset=' . $offset );
				return $offset;
			}
			$written = fwrite( $o_handle, $content ); // phpcs:ignore
			if ( false === $written ) {
				wp2pclouddebugger::log( 'download_chunk_curl() - fwrite failed, offset=' . $offset );
				return $offset;
			}
			$bytes_written = $written;
		} finally {
			fclose( $o_handle ); // phpcs:ignore
		}

		return intval( $offset + $bytes_written );
	}

	/**
	 * Extract the archive
	 *
	 * @param string $archive_file Output archive file.
	 * @return void
	 */
	public function extract( string $archive_file ): void {

		$zip = new ZipArchive();
		$res = $zip->open( $archive_file );

		if ( true === $res ) {

			// Single call extracts all entries in one pass. Previously we looped calling
			// extractTo($target, [$one_name]) per entry, which forced a central-directory
			// re-scan on every iteration and made large-archive restores effectively hang.
			$ok = $zip->extractTo( rtrim( ABSPATH, '/' ) );
			$zip->close();

			if ( $ok ) {
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='zip_file_extracted'>ZIP file ( archive ), successfully extracted!</span>" );
				wp2pclouddebugger::log( 'restore->extract() - ZIP file ( archive ), successfully extracted!' );
			} else {
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='zip_file_extract_fail'>Failed to extract the archive, check the ZIP file for issues!</span>" );
				wp2pclouddebugger::log( 'restore->extract() - extractTo() returned false for ' . $archive_file );
			}

			return;
		}

		wp2pcloudlogger::info( '<span>' . esc_html( $archive_file ) . '</span>' );
		wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='zip_file_extract_fail'>Failed to extract the archive, check the ZIP file for issues!</span>" );

		$zip_file_functions_errors = array(
			0                        => 'OK',
			ZIPARCHIVE::ER_EXISTS    => 'File already exists.',
			ZIPARCHIVE::ER_INCONS    => 'Zip archive inconsistent.',
			ZIPARCHIVE::ER_INVAL     => 'Invalid argument.',
			ZIPARCHIVE::ER_MEMORY    => 'Malloc failure.',
			ZIPARCHIVE::ER_NOENT     => 'No such file.',
			ZIPARCHIVE::ER_NOZIP     => 'Not a zip archive.',
			ZIPARCHIVE::ER_OPEN      => 'Can not open file.',
			ZIPARCHIVE::ER_READ      => 'Read error.',
			ZIPARCHIVE::ER_SEEK      => 'Seek error.',
			ZIPARCHIVE::ER_MULTIDISK => 'Multi-disk zip archives not supported.',
		);

		if ( is_int( $res ) && isset( $zip_file_functions_errors[ $res ] ) ) {
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='zip_error'>ZIP Error</span>: " . $zip_file_functions_errors[ $res ] );
			wp2pclouddebugger::log( 'restore->extract() - ZIP Error: ' . $zip_file_functions_errors[ $res ] );
		} else {
			wp2pclouddebugger::log( 'restore->extract() - open failed with code ' . var_export( $res, true ) );
		}
	}

	/**
	 * Restore the database archive
	 *
	 * @return void
	 */
	public function restore_db(): void {

		global $wp_filesystem, $wpdb;

		$sql = $this->restore_path . 'backup.sql';
		if ( ! is_file( $sql ) ) {
			$sql = rtrim( ABSPATH, '/' ) . '/backup.sql';
			if ( ! is_file( $sql ) ) {
				wp2pclouddebugger::log( 'restore->restore_db() - Failed to restore Database, backup.sql not found in the archive!' );
				return;
			}
		}

		$session_tokens = array();
		$results        = $wpdb->get_results( "SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key = 'session_tokens'", ARRAY_A );
		foreach ( $results as $row ) {
			$session_tokens[ $row['user_id'] ] = $row['meta_value'];
		}

		wp2pclouddebugger::log( 'restore->restore_db() - SQL db file found!' );
		wp2pclouddebugger::log( 'restore->restore_db() - file: ' . $sql );

		$q_ex_num = 0;

		$db = null;

		try {

			// v2.0.6: build the DSN via the shared helper so a DB_HOST with a port or
			// socket (e.g. "localhost:3306") no longer fails with "Unknown MySQL server
			// host" — the same fix applied to the DB *backup* path in v2.0.5.
			$dns = WP2PcloudDBBackup::build_pdo_dsn( DB_HOST, DB_NAME ) . ';charset=' . DB_CHARSET;
			$db  = new PDO( // phpcs:ignore
				$dns,
				DB_USER,
				DB_PASSWORD,
				array(
					PDO::ATTR_EMULATE_PREPARES   => false, // phpcs:ignore
					PDO::ATTR_PERSISTENT         => false, // phpcs:ignore
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // phpcs:ignore
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // phpcs:ignore
				)
			);

		} catch ( PDOException $e ) {
			$dbg_ex = $e->getMessage();
			wp2pcloudlogger::info( 'Connection to Database failed with message: ' . $dbg_ex );
			wp2pclouddebugger::log( 'restore->restore_db() - Connection to Database failed with message: ' . $dbg_ex );
		}

		if ( ! is_null( $db ) ) {

			if ( ! empty( DB_COLLATE ) ) {

				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='db_collation_detected'>Database collation detected</span>: " . DB_COLLATE );

				try {
					$db->exec( 'SET NAMES ' . DB_COLLATE );
				} catch ( PDOException $e ) {
					$dbg_ex = $e->getMessage();
					wp2pclouddebugger::log( 'restore->restore_db() - Failed to change the Database collation! Error: ' . $dbg_ex );
					wp2pcloudlogger::info( 'Failed to change the Database collation! Error: ' . $dbg_ex );
				}
			}

			// Stream the SQL file line by line, splitting on `;` that are outside string
			// literals. Previous revisions did explode(";\n", ...) which (a) loaded the whole
			// dump into RAM (OOM on large sites) and (b) broke any row whose content contained
			// a ";\n" sequence (common in post_content / options).
			$fh = fopen( $sql, 'rb' ); // phpcs:ignore
			if ( false === $fh ) {
				wp2pclouddebugger::log( 'restore->restore_db() - fopen failed for ' . $sql );
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='failed_to_open_sql'>Failed to open the SQL backup for reading.</span>" );
			} else {
				$buffer    = '';
				$in_single = false;
				$in_double = false;
				$escaped   = false;

				$flush = static function ( string $stmt ) use ( &$q_ex_num, $db ) {
					$stmt = trim( $stmt );
					if ( '' === $stmt || ';' === $stmt ) {
						return;
					}
					try {
						$db->exec( $stmt );
						$q_ex_num++;
					} catch ( PDOException $e ) {
						$short = substr( $stmt, 0, 120 );
						wp2pclouddebugger::log( 'restore->restore_db() - stmt failed: ' . $short . ' | ' . $e->getMessage() );
						wp2pcloudlogger::info( 'Failed to execute database query: ' . $e->getMessage() );
					}
				};

				try {
					while ( ( $line = fgets( $fh ) ) !== false ) {
						$len = strlen( $line );
						for ( $i = 0; $i < $len; $i++ ) {
							$ch      = $line[ $i ];
							$buffer .= $ch;

							if ( $escaped ) {
								$escaped = false;
								continue;
							}
							if ( '\\' === $ch ) {
								$escaped = true;
								continue;
							}
							if ( "'" === $ch && ! $in_double ) {
								$in_single = ! $in_single;
								continue;
							}
							if ( '"' === $ch && ! $in_single ) {
								$in_double = ! $in_double;
								continue;
							}
							if ( ';' === $ch && ! $in_single && ! $in_double ) {
								$flush( $buffer );
								$buffer = '';
							}
						}
					}
					// Flush any trailing statement missing a terminating semicolon.
					if ( '' !== trim( $buffer ) ) {
						$flush( $buffer );
					}
				} finally {
					fclose( $fh ); // phpcs:ignore
				}
			}
		} else {
			wp2pclouddebugger::log( 'restore->restore_db() - Connection to Database failed!' );
			wp2pcloudlogger::info( 'Connection to Database failed!' );
		}

		if ( $q_ex_num > 3 ) {
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='db_restorred_q_executed'>Database restored, number of queries executed</span>: " . $q_ex_num );
			wp2pclouddebugger::log( "restore->restore_db() - Database restored, $q_ex_num queries executed!" );

			if ( 0 < count( $session_tokens ) ) {
				foreach ( $session_tokens as $user_id => $meta_value ) {
					$exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM $wpdb->usermeta WHERE user_id = %d AND meta_key = 'session_tokens'",
							$user_id
						)
					);
					if ( $exists ) {
						$wpdb->query(
							$wpdb->prepare(
								"UPDATE $wpdb->usermeta SET meta_value = %s WHERE user_id = %d AND meta_key = 'session_tokens'",
								$meta_value,
								$user_id
							)
						);
					}
				}
			}

			// Always clear transients after a DB restore, regardless of whether there were any
			// logged-in sessions to preserve. Stale transients from the old DB confuse the cache
			// layer (WP core and many plugins).
			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%'" );
			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_site_transient_%'" );
		} else {
			wp2pcloudlogger::info( 'Failed to restore Database, no queries executed, check the backup.sql file!' );
			wp2pclouddebugger::log( 'restore->restore_db() - Failed to restore Database, no queries executed, check the backup.sql file!' );
		}

		if ( $wp_filesystem->exists( $sql ) ) {
			unlink( $sql );
			wp2pclouddebugger::log( 'restore->restore_db() - backup.sql removed!' );
		} else {
			wp2pclouddebugger::log( 'restore->restore_db() - Failed to remove the backup.sql file!' );
		}
	}

	/**
	 * Remove files in directory
	 *
	 * @param string $archive_file Archive file to be removed.
	 * @return void
	 */
	public function remove_files( string $archive_file ): void {
		if ( file_exists( $archive_file ) ) {
			unlink( $archive_file );
		}
		wp2pclouddebugger::log( 'restore->remove_files() - Start!' );
		if ( is_dir( $this->restore_path ) ) {
			$this->recurse_rm_dir( $this->restore_path );
		}
		wp2pclouddebugger::log( 'restore->remove_files() - Completed!' );
	}

	/**
	 * Recursive removal of directories.
	 *
	 * @param string $dir Directory name.
	 *
	 * @return void
	 */
	private function recurse_rm_dir( string $dir ): void {

		$dir_cnt = scandir( $dir );
		if ( is_array( $dir_cnt ) ) {
			$files = array_diff( $dir_cnt, array( '.', '..' ) );
			foreach ( $files as $file ) {
				( is_dir( "$dir/$file" ) ) ? $this->recurse_rm_dir( "$dir/$file" ) : unlink( "$dir/$file" );
			}
		}
		rmdir( $dir );
	}
}
