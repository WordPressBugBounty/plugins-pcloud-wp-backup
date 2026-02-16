<?php
/**
 * WP2PcloudFileBackup class
 *
 * @file class-wp2pcloudfilebackup.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

use Exception;
use Pcloud\Classes\ZipFile\ZipFile;
use stdClass;
use ZipArchive;

/**
 * Class WP2PcloudFileBackup
 */
class WP2PcloudFileBackup {

	/**
	 * Authentication key
	 *
	 * @var string|null $authkey API authentication key.
	 */
	private ?string $authkey;

	/**
	 * API endpoint
	 *
	 * @var string|null $apiep API endpoint
	 */
	private ?string $apiep;

	/**
	 * Backup File name
	 *
	 * @var string|null $sql_backup_file SQL backup file name
	 */
	private ?string $sql_backup_file;

	/**
	 * Skip this folder on backup.
	 *
	 * @var string[] $skip_folders
	 */
	private array $skip_folders = array( '.idea', '.code', 'wp-pcloud-backup', 'pcloud-wp-backup', 'wp2pcloud_tmp' );

	/**
	 * The size in bytes of each uploaded/downloaded chunk.
	 *
	 * @var int $part_size
	 */
	private int $part_size;

	/**
	 * Base plugin dir
	 *
	 * @var string|null $base_dir
	 */
	private ?string $base_dir;

	/**
	 * Class contructor
	 *
	 * @param string|null $base_dir Base directory.
	 */
	public function __construct( ?string $base_dir ) {

		$this->sql_backup_file = '';
		$this->base_dir        = $base_dir;
		$this->authkey         = wp2pcloudfuncs::get_storred_val( PCLOUD_AUTH_KEY );
		$this->apiep           = rtrim( 'https://' . wp2pcloudfuncs::get_api_ep_hostname() );
		$this->part_size       = 3 * 1000 * 1000;

		$this_dirs_path = explode( DIRECTORY_SEPARATOR, __DIR__ );
		if ( is_array( $this_dirs_path ) && count( $this_dirs_path ) > 2 ) {
			if ( isset( $this_dirs_path[ count( $this_dirs_path ) - 2 ] ) ) {
				$plugin_dir = $this_dirs_path[ count( $this_dirs_path ) - 2 ];
				if ( str_contains( $plugin_dir, 'pcloud' ) ) {
					$this->skip_folders[] = $plugin_dir;
				}
			}
		}
	}

	/**
	 * Set MySQL backup file name
	 *
	 * @param string $file_name File name.
	 *
	 * @return void
	 */
	public function set_mysql_backup_filename( string $file_name ): void {
		$this->sql_backup_file = $file_name;
	}

	/**
	 * Start backup process
	 *
	 * @param string|null $mode Backup process mode.
	 *
	 * @return void
	 * @throws Exception Standart Exception can be thrown.
	 */
	public function start( ?string $mode = 'manual' ): void {

		wp2pcloudfuncs::set_storred_val( PCLOUD_BACKUP_FILE_INDEX, '' );
		wp2pcloudfuncs::set_execution_limits();

		$local_backup_path_name = $this->base_dir . '/tmp';

		if ( ! is_dir( $local_backup_path_name ) ) {
			mkdir( $local_backup_path_name );
			wp2pclouddebugger::log( 'TMP directory created!' );
		}

		$op_data = array(
			'operation'  => 'upload',
			'state'      => 'preparing',
			'mode'       => $mode,
			'chunkstate' => 'OK',
			'failures'   => 0,
			'folder_id'  => 0,
			'offset'     => 0,
		);
		wp2pcloudfuncs::set_operation( $op_data );

		$this->clear_all_tmp_files();

		wp2pclouddebugger::log( 'All temporary files - cleared!' );

		$rootdir = rtrim( ABSPATH, '/' );

		wp2pclouddebugger::log( 'Creating a list of files to be compressed!' );

		$files = self::find_all_files( $rootdir );

		wp2pclouddebugger::log( 'The List of all files is ready and will be sent for compression!' );

		$sql_backup_file_name      = '';
		$php_extensions            = get_loaded_extensions();
		$has_archive_ext_installed = array_search( 'zip', $php_extensions, true );

		if ( $has_archive_ext_installed ) { // TODO: check if this is actually needed.

			wp2pclouddebugger::log( 'Start creating ZIP archives!' );

			/**
			 * STEP 1 -> we will archivate the database.
			 */
			if ( ! empty( $this->sql_backup_file ) ) {
				if ( file_exists( $this->sql_backup_file ) && is_readable( $this->sql_backup_file ) ) {

					wp2pclouddebugger::log( 'ZIP state - zipping the database file!' );

					$sql_backup_file_name = 'backup.sql';

					$zip = new ZipFile();

					// We need to remove from PCLOUD_TEMP_DIR the ABSPATH and add the file name.
					$db_path = rtrim( str_replace( ABSPATH, '', PCLOUD_TEMP_DIR ) );

					try {
						$zip->add_file( $this->sql_backup_file, $db_path . '/' . $sql_backup_file_name );
						wp2pclouddebugger::log( 'ZIP DB file - added!' );
					} catch ( Exception $e ) {
						wp2pclouddebugger::log( 'ZIP - failed to add DB file to archive! Error: ' . $e->getMessage() );
					}

					try {
						$zip->save_as_file( $local_backup_path_name . '/' . $sql_backup_file_name . '.zip' );
						$zip->close();
						$size = wp2pcloudfuncs::format_bytes( filesize( $local_backup_path_name . '/' . $sql_backup_file_name . '.zip' ) );
						wp2pcloudlogger::info( "<span style='color: #00ff00' class='pcl_transl' data-i10nk='db_backup_file_created_with_size'>DB Backup file created, size</span>: ( $size )" );
						wp2pclouddebugger::log( 'DB ZIP File successfully closed! [ ' . $size . ' ]' );
					} catch ( Exception $e ) {
						wp2pcloudlogger::info( "<span style='color: red'>Error:</span> failed to create database zip archive! Check the 'debug' info ( right/top ) button for more info!" );
						wp2pclouddebugger::log( '--------- |||| -------- Failed to create database ZIP file!' );
						wp2pclouddebugger::log( '--------- |||| -------- Error:' );
						wp2pclouddebugger::log( $e->getMessage() );
					}

					$zip = null;
				}
			}

			for ( $try = 0; $try < 5; $try ++ ) {

				wp2pclouddebugger::log( 'Attempt [ #' . ( $try + 1 ) . ' ] to create the ZIP archive!' );

				$archive_files = $this->create_zip( $files, $local_backup_path_name );
				if ( 0 < count( $archive_files ) ) {
					break;
				} else {

					$operation             = wp2pcloudfuncs::get_operation();
					$operation['failures'] = 0;
					wp2pcloudfuncs::set_operation( $operation );

					wp2pcloudfuncs::add_item_for_async_update( 'failures', 0 );

					wp2pclouddebugger::log( 'Closing ZIP archive with attempt: ' . ( $try + 1 ) . ' failed, retrying!' );

					$files = self::find_all_files( $rootdir );
				}
			}

			if ( 0 === count( $archive_files ) ) {

				wp2pcloudfuncs::set_operation();

				wp2pcloudlogger::info( '<span>ERROR: Failed to create backup ZIP files !</span>' );
				wp2pclouddebugger::log( 'Failed to create valid ZIP archives after all 5 tries!' );

			} else {

				sleep( 3 );

				try {

					wp2pclouddebugger::log( 'Archive seems ready, trying to validate it!' );

					$this->validate_zip_archive_in_dir( $local_backup_path_name );

					wp2pclouddebugger::log( 'Zip Archive - seems valid!' );

				} catch ( Exception $e ) {

					wp2pcloudlogger::info( '<span>ERROR: Backup archives not valid!</span>' );
					wp2pclouddebugger::log( 'Invalid backup files detected, error: ' . $e->getMessage() );

					wp2pcloudfuncs::set_operation();

					exit();
				}
			}
		} else {

			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='err_backup_arch_no_file'>ERROR: Backup archive file don't exist!</span>" );
			wp2pclouddebugger::log( 'Backup file does not exist! PHP Zip extension is missing!' );

			wp2pcloudfuncs::set_operation();

			exit();
		}

		wp2pclouddebugger::log( 'Archiving process - COMPLETED!' );

		wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='zip_file_created'>Zip file is created! Uploading to pCloud</span>" );

		if ( 'auto' === $mode ) {
			$time_limit = ini_get( 'max_execution_time' );
			if ( ! is_bool( $time_limit ) && intval( $time_limit ) === 0 ) {
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='upd_strategy_once'>Upload strategy - at once !</span>" );
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='pls_wait_may_take_time'>Please wait, may take time!</span>" );
				wp2pclouddebugger::log( 'Upload strategy - at once !' );
			} else {
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='upd_strategy_chunks'>Upload strategy - chunk by chunk !</span>" );
				wp2pclouddebugger::log( 'Upload strategy - chunk by chunk !' );
			}
		}

		$folder_id = self::get_upload_dir_id();

		$upload = $this->create_upload();
		if ( ! is_object( $upload ) ) {
			wp2pclouddebugger::log( 'File -> upload -> "createUpload" not returning the expected data!' );
			throw new Exception( 'File -> upload -> "createUpload" not returning the expected data!' );
		} else {

			$files_to_upload = array();
			if ( ! empty( $sql_backup_file_name ) ) {
				$files_to_upload[] = $sql_backup_file_name . '.zip';
			}
			foreach ( $archive_files as $file ) {
				$files_to_upload[] = basename( $file );
			}

			$op_data['state']            = 'ready_to_push';
			$op_data['folder_id']        = $folder_id;
			$op_data['upload_id']        = $upload->uploadid;
			$op_data['upload_files']     = wp_json_encode( $files_to_upload );
			$op_data['current_file']     = 0;
			$op_data['local_backup_dir'] = $local_backup_path_name;
			$op_data['failures']         = 0;

			wp2pcloudfuncs::set_operation( $op_data );
		}
	}

	/**
	 * Clear all temporary files
	 *
	 * @return void
	 */
	public function clear_all_tmp_files(): void {
		$files = glob( $this->base_dir . '/tmp/*' );
		foreach ( $files as $file ) {
			if ( is_file( $file ) && is_writable( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Collect all files in directory
	 *
	 * @param string $dir Directory to scan for files.
	 *
	 * @return array
	 */
	private function find_all_files( string $dir ): array {

		if ( in_array( $dir, $this->skip_folders, true ) ) {
			return array();
		}

		$root = scandir( $dir );

		if ( is_array( $root ) ) {

			$result = array();
			foreach ( $root as $value ) {
				if ( '.' === $value || '..' === $value ) {
					continue;
				}
				if ( in_array( $value, $this->skip_folders, true ) ) {
					continue;
				}

				if ( is_file( "$dir/$value" ) ) {
					$result[] = "$dir/$value";
					continue;
				}
				foreach ( self::find_all_files( "$dir/$value" ) as $val ) {
					$result[] = $val;
				}
			}

			return $result;
		}

		return array();
	}

	/**
	 * Create ZIP archive procedure.
	 *
	 * @param array  $files Array of files to be added to the ZIP archive.
	 * @param string $local_backup_dir Local backup directory.
	 *
	 * @return array
	 */
	private function create_zip( array $files, string $local_backup_dir ): array {

		wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='start_create_zip'>Starting with creating ZIP archive, please wait...</span>" );

		$final_zip_files = array();

		/**
		 * STEP 2 -> we will archivate the rest of the files.
		 */

		wp2pclouddebugger::log( 'ZIP state - start zipping the rest of the files!' );

		$files_skipped      = array();
		$num_files          = count( $files );
		$actually_added     = 0;
		$max_memory_allowed = WP2PcloudFuncs::get_memory_limit();
		$max_memory_allowed = round( $max_memory_allowed - ( ( $max_memory_allowed / 100 ) * 30 ), 2 ); // 30% lower

		if ( $num_files > 500000 ) {
			wp2pcloudfuncs::set_storred_val( PCLOUD_MAX_NUM_FAILURES_NAME, 15000 );
		} elseif ( $num_files > 100000 ) {
			wp2pcloudfuncs::set_storred_val( PCLOUD_MAX_NUM_FAILURES_NAME, 6000 );
		} elseif ( $num_files > 40000 ) {
			wp2pcloudfuncs::set_storred_val( PCLOUD_MAX_NUM_FAILURES_NAME, 3000 );
		} elseif ( $num_files > 10000 ) {
			wp2pcloudfuncs::set_storred_val( PCLOUD_MAX_NUM_FAILURES_NAME, 1000 );
		}

		$zip = new ZipFile();

		$archive_index     = 1;
		$all_skipped_files = array();

		foreach ( $files as $file ) {
			if ( file_exists( $file ) && is_readable( $file ) ) {

				$file_limit       = 3.6;
				$file_limit_bytes = $file_limit * 1000 * 1000 * 1000; // 36000000... bytes

				$file_size       = filesize( $file );
				$file_path_short = str_replace( ABSPATH, '', $file );

				if ( is_bool( $file_size ) ) {
					$files_skipped[]     = $file_path_short . ' [ unknown size ]';
					$all_skipped_files[] = $file_path_short . ' [ unknown size ]';
					continue;
				}

				if ( intval( $file_size ) >= $file_limit_bytes ) {
					$file_size = round( ( $file_size / 1000 / 1000 ), 2 );
					if ( $file_size > 1000 ) {
						$file_size = round( ( $file_size / 1000 ), 2 ) . ' Gb';
					} else {
						$file_size .= ' Mb';
					}

					$files_skipped[]     = $file_path_short . ' [ ' . $file_size . ' / ' . $file_limit . ' Gb ]';
					$all_skipped_files[] = $file_path_short . ' [ ' . $file_size . ' / ' . $file_limit . ' Gb ]';
					continue;
				}

				try {
					$zip->add_file( $file, $file_path_short );
					$actually_added ++;
				} catch ( Exception $e ) {
					wp2pclouddebugger::log( 'ZIP - failed to add file! Error: ' . $e->getMessage() . ' file: ' . $file_path_short );
				}

				$current_mem_usage = floatval( ( memory_get_usage() / 1024 / 1024 ) );

				if ( $current_mem_usage > $max_memory_allowed ) {

					try {

						$archive_index_str = str_pad( $archive_index, 3, '0', STR_PAD_LEFT );
						$final_zip_file    = $local_backup_dir . '/' . $archive_index_str . '_archive.zip';

						$zip->save_as_file( $final_zip_file );
						$zip->close();

						$final_zip_files[] = $final_zip_file;

						$size = wp2pcloudfuncs::format_bytes( filesize( $final_zip_file ) );

						wp2pcloudlogger::info( "[ $archive_index ] <span class='pcl_transl' data-i10nk='backup_file_size'>Backup file size:</span> ( $size )" );
						wp2pclouddebugger::log( 'ZIP File ' . $archive_index . ' successfully closed! [ ' . $size . ' ]' );

					} catch ( Exception $e ) {

						wp2pcloudlogger::info( "Error: failed to create zip archive! Check the 'debug' info ( right/top ) button for more info!" );
						wp2pclouddebugger::log( '--------- |||| -------- Failed to create ZIP file!' );
						wp2pclouddebugger::log( '--------- |||| -------- Error:' );
						wp2pclouddebugger::log( $e->getMessage() );

						die();
					}

					$archive_index++;

					$zip = null;

					sleep( 2 );

					$zip = new ZipFile();
				}
			}
		}

		if ( count( $all_skipped_files ) > 0 ) {
			foreach ( $all_skipped_files as $file ) {
				wp2pcloudlogger::notification( $file . ' was not addded to the archive because of size or permissions!' );
			}
		}

		try {

			$archive_index_str = str_pad( $archive_index, 3, '0', STR_PAD_LEFT );
			$final_zip_file    = $local_backup_dir . '/' . $archive_index_str . '_archive.zip';
			$zip->save_as_file( $final_zip_file );
			$zip->close();

			$final_zip_files[] = $final_zip_file;

			$size = wp2pcloudfuncs::format_bytes( filesize( $final_zip_file ) );

			wp2pcloudlogger::info( "[ $archive_index ] <span class='pcl_transl' data-i10nk='backup_file_size'>Backup file size:</span> ( $size )" );
			wp2pclouddebugger::log( 'ZIP File ' . $archive_index . ' successfully closed! [ ' . $size . ' ]' );

		} catch ( Exception $e ) {

			wp2pcloudlogger::info( "Error: failed to create zip archive! Check the 'debug' info ( right/top ) button for more info!" );
			wp2pclouddebugger::log( '--------- |||| -------- Failed to create ZIP file!' );
			wp2pclouddebugger::log( '--------- |||| -------- Error:' );
			wp2pclouddebugger::log( $e->getMessage() );
		}

		$zip = null;

		wp2pclouddebugger::log( 'ZIP entries added [ ' . $actually_added . ' from ' . $num_files . ' ]' );

		$num_skipped = count( $files_skipped );

		if ( $num_skipped > 0 ) {

			if ( $num_skipped > 50 ) {
				$files_skipped   = array_slice( $files_skipped, 0, 50 );
				$files_skipped[] = ' ... + ' . ( 50 - $num_skipped ) . ' entries';
			}

			$files_skipped_list = implode( '<br>', $files_skipped );
			wp2pcloudlogger::info( "<span style='color: red' class='pcl_transl' data-i10nk='error'>ERROR</span>: " . $num_skipped . " <span class='pcl_transl' data-i10nk='n_files_not_added_2_archive'>was not added to the archive, check the debug log for more details!</span>" );
			wp2pclouddebugger::log( "<span style='color: red'>ERROR:</span> files been skipped:" );
			wp2pclouddebugger::log( $files_skipped_list );
		}

		wp2pclouddebugger::log( 'ZIP archive - filling-up and closing' );

		return $final_zip_files;
	}

	/**
	 * Create remote directory
	 *
	 * @param string $dir_name Remote directory name.
	 *
	 * @return stdClass
	 */
	private function make_directory( string $dir_name = '/WORDPRESS_BACKUPS' ): stdClass {

		$dir_name = rtrim( $dir_name, '/' );

		$response = new stdClass();

		for ( $i = 1; $i < 4; $i ++ ) {
			$api_response = wp_remote_get( $this->apiep . '/createfolder?path=' . $dir_name . '&name=' . trim( $dir_name, '/' ) . '&access_token=' . $this->authkey );
			if ( is_array( $api_response ) && ! is_wp_error( $api_response ) ) {
				$response_raw = wp_remote_retrieve_body( $api_response );
				if ( is_string( $response_raw ) && ! is_wp_error( $response_raw ) ) {
					$response_json = json_decode( $response_raw );
					if ( ! is_bool( $response_json ) ) {
						$response = $response_json;
						wp2pclouddebugger::log( 'make_directory() - OK' );
						break;
					} else {
						wp2pclouddebugger::log( 'make_directory() - failed to convert the response JSON to object! Will retry!' );
					}
				} else {
					wp2pclouddebugger::log( 'make_directory() - no response body detected! Will retry!' );
				}
			} else {
				$error = '';
				if ( is_wp_error( $api_response ) ) {
					$error = $api_response->get_error_message();
				}
				wp2pclouddebugger::log( 'make_directory() - api call failed ! [ ' . $error . ' ] Will retry!' );
			}

			sleep( 5 * $i );
		}

		return $response;
	}

	/**
	 * Get Upload directory ID
	 *
	 * @return int
	 */
	private function get_upload_dir_id(): int {
		$error = '';

		$response     = new stdClass();
		$response_raw = '';

		$backup_file_index = wp2pcloudfuncs::get_storred_val( PCLOUD_BACKUP_FILE_INDEX );
		if ( empty( $backup_file_index ) ) {
			$backup_file_index = time();
			wp2pcloudfuncs::set_storred_val( PCLOUD_BACKUP_FILE_INDEX, $backup_file_index );
		}

		$dir_name       = gmdate( 'Ymd_Hi', $backup_file_index );
		$final_dir_name = rtrim( PCLOUD_BACKUP_DIR, '/' ) . '/' . $dir_name;
		$final_dir_name = ltrim( $final_dir_name, '/' );

		$folder_id = 0;
		for ( $i = 1; $i < 4; $i ++ ) {

			$api_response = wp_remote_get( $this->apiep . '/listfolder?path=/' . $final_dir_name . '&access_token=' . $this->authkey );
			if ( is_array( $api_response ) && ! is_wp_error( $api_response ) ) {
				$response_raw = wp_remote_retrieve_body( $api_response );
				if ( is_string( $response_raw ) && ! is_wp_error( $response_raw ) ) {
					$response_json = json_decode( $response_raw );
					if ( ! is_bool( $response_json ) ) {
						$response = $response_json;
					} else {
						wp2pclouddebugger::log( 'get_upload_dir_id() - failed to convert the response JSON to object!' );
					}
				} else {
					wp2pclouddebugger::log( 'get_upload_dir_id() - no response body detected!' );
				}
			} else {

				if ( is_wp_error( $api_response ) ) {
					$error .= $api_response->get_error_message();
				}
				wp2pclouddebugger::log( 'get_upload_dir_id() - api call failed ! [ ' . $error . ' ]' );
			}

			if ( is_object( $response ) && property_exists( $response, 'metadata' ) && property_exists( $response->metadata, 'folderid' ) ) {

				$folder_id = intval( $response->metadata->folderid );

			} elseif ( property_exists( $response, 'result' ) && 2005 === $response->result ) {

				$folders = explode( '/', $final_dir_name );
				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='backup_in_fld'>Backup will be in folder:</span> " . $final_dir_name );

				$res = new stdClass();

				$final_path  = '';
				$num_folders = count( $folders );

				for ( $n = 0; $n < $num_folders; $n++ ) {
					if ( ! empty( $folders[ $n ] ) ) {
						$final_path .= '/' . $folders[ $n ];
						$res         = self::make_directory( $final_path . '/' );
					}
				}

				if ( property_exists( $res, 'metadata' ) && property_exists( $res->metadata, 'folderid' ) ) {
					$folder_id = intval( $res->metadata->folderid );
				}
			} else {
				wp2pclouddebugger::log( 'get_upload_dir_id() - response from the API does not contain the needed info! Check below:' );
			}

			if ( 0 < $folder_id ) { // We have folder ID , break and move forward.
				break;
			}

			sleep( 5 * $i );
		}

		if ( 0 === $folder_id ) {

			wp2pclouddebugger::log( 'get_upload_dir_id() - api call failed ! [ ' . $error . ' ]' );

			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='invalid_resp_from_server'>Invalid response from the server:</span> " . $error . "\n" );
			wp2pcloudfuncs::set_operation();
			die();
		}

		return $folder_id;
	}

	/**
	 * Chunked upload procedure
	 *
	 * @param string $path File path to be backed-up.
	 * @param int    $folder_id pCloud Folder ID.
	 * @param int    $upload_id pCloud Upload ID.
	 * @param int    $uploadoffset Upload offset.
	 * @param int    $num_failures Number of failures, will increase the wait time before the next try.
	 *
	 * @return int
	 */
	public function upload_chunk( string $path, int $folder_id = 0, int $upload_id = 0, int $uploadoffset = 0, int $num_failures = 0 ): int {

		$filesize = abs( filesize( $path ) );

		$this->set_chunk_size( $filesize );

		if ( ! file_exists( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='invalid_file_provided'>Invalid file provided!</span>" );
			wp2pclouddebugger::log( 'upload_chunk() - Invalid file provided! [ ' . $path . ' ]' );

			return intval( $uploadoffset + $this->part_size );
		}

		if ( $uploadoffset > $filesize ) {
			return $uploadoffset;
		}

		$params = array(
			'uploadid'     => $upload_id,
			'uploadoffset' => $uploadoffset,
		);

		// Complicated file operations, currently not supported by: WP_Filesystem.
		$file = fopen( $path, 'r' ); // phpcs:ignore

		if ( $uploadoffset > 0 ) {
			fseek( $file, $uploadoffset ); // phpcs:ignore
		}
		$content = fread( $file, $this->part_size ); // phpcs:ignore
		try {
			if ( ! empty( $content ) ) {
				try {
					$this->write( $content, $params );

					$uploadoffset += $this->part_size;

				} catch ( Exception $e ) {

					$retry_in = $num_failures * 2;
					if ( $retry_in > 120 ) {
						$retry_in = 60;
					}

					$dbg_msg = $e->getMessage();

					wp2pcloudlogger::info( 'Upload failed with message: ' . $dbg_msg . ' will retry in: ' . $retry_in . ' sec.' );
					wp2pclouddebugger::log( 'Upload failed with message: ' . $dbg_msg . ' will retry in: ' . $retry_in . ' sec.' );
					sleep( $retry_in );

				}
			}
			fclose( $file ); // phpcs:ignore
		} catch ( Exception ) {
			fclose( $file ); // phpcs:ignore
		}

		return intval( $uploadoffset );
	}


	/**
	 * Upload procedure
	 *
	 * @param string $path File path to be backed-up.
	 * @param int    $folder_id Folder ID.
	 * @param int    $upload_id Upload ID.
	 * @param int    $uploadoffset Upload Offset.
	 *
	 * @return int
	 * @throws Exception Standart Exception can be thrown.
	 */
	public function upload( string $path, int $folder_id = 0, int $upload_id = 0, int $uploadoffset = 0 ): int {
		if ( ! file_exists( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {

			wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='invalid_file_provided'>Invalid file provided!</span>" );
			wp2pclouddebugger::log( 'upload() -> Invalid file provided!' );

			return intval( $uploadoffset + $this->part_size );

		} else {
			$filesize = abs( filesize( $path ) );

			$this->set_chunk_size( $filesize );
		}

		if ( $uploadoffset > $filesize ) {
			return $uploadoffset;
		}

		$params = array(
			'uploadid'     => $upload_id,
			'uploadoffset' => $uploadoffset,
		);

		$num_failures = 0;

		$file = fopen( $path, 'r' ); // phpcs:ignore
		while ( ! feof( $file ) ) {
			$content = fread( $file, $this->part_size ); // phpcs:ignore
			do {
				try {

					if ( PCLOUD_DEBUG ) {
						wp2pclouddebugger::log( 'upload() -> prep2write' );
					}

					$this->write( $content, $params );

					if ( PCLOUD_DEBUG ) {
						wp2pclouddebugger::log( 'upload() -> wrote done !' );
					}

					$params['uploadoffset'] += $this->part_size;
					$uploadoffset           += $this->part_size;

					if ( PCLOUD_DEBUG ) {
						wp2pclouddebugger::log( 'upload() -> chunk ++' );
					}

					$num_failures = 0;
					continue 2;

				} catch ( Exception $e ) {

					$dbg_ex = $e->getMessage();

					wp2pcloudlogger::info( 'ERR: ' . $dbg_ex . ' [id: ' . $upload_id . ' | offset: ' . $uploadoffset );
					wp2pclouddebugger::log( 'upload() -> Exception: ' . $dbg_ex );

					$retry_in = $num_failures * 5;
					if ( $retry_in > 30 ) {
						$retry_in = 30;
					}

					$num_failures ++;

					sleep( $retry_in );
				}
			} while ( $num_failures < 10 );

			if ( $num_failures > 30 ) {
				break;
			}
		}

		fclose( $file ); // phpcs:ignore

		return $uploadoffset;
	}

	/**
	 * Prepare to initiate Upload process
	 *
	 * @return stdClass
	 * @throws Exception Standart Exception can be thrown.
	 */
	public function create_upload(): stdClass {

		$response = new stdClass();

		for ( $i = 1; $i < 4; $i ++ ) {

			wp2pclouddebugger::log( 'create_upload() - trying to get new upload_id from: ' . $this->apiep . '/upload_create?access_token=...' );

			$api_response = wp_remote_get( $this->apiep . '/upload_create?access_token=' . $this->authkey );
			if ( is_array( $api_response ) && ! is_wp_error( $api_response ) ) {
				$response_raw = wp_remote_retrieve_body( $api_response );
				if ( is_string( $response_raw ) && ! is_wp_error( $response_raw ) ) {
					$response_json = json_decode( $response_raw );
					if ( ! is_bool( $response_json ) ) {
						$response = $response_json;
						wp2pclouddebugger::log( 'create_upload() - OK' );
						break;
					} else {
						wp2pclouddebugger::log( 'create_upload() - failed to convert the response JSON to object! Will retry!' );
					}
				} else {
					wp2pclouddebugger::log( 'create_upload() - no response body detected! Will retry!' );
				}
			} else {
				$error = '';
				if ( is_wp_error( $api_response ) ) {
					$error = $api_response->get_error_message();
				}
				wp2pclouddebugger::log( 'create_upload() - api call failed ! [ ' . $error . ' ]! Will retry!' );
			}

			sleep( 5 * $i );
		}

		return $response;
	}

	/**
	 * After successfull upload - we need to call "save" procedure.
	 *
	 * @param int    $upload_id pCloud Upload ID.
	 * @param string $name File name to save.
	 * @param int    $folder_id pCloud Folder ID.
	 *
	 * @return void
	 * @throws Exception Standart Exception can be thrown.
	 */
	public function save( int $upload_id, string $name, int $folder_id ): void {

		$get_params = array(
			'uploadid'     => $upload_id,
			'name'         => rawurlencode( $name ),
			'folderid'     => $folder_id,
			'access_token' => rawurlencode( $this->authkey ),
		);

		$api_response = wp_remote_get( $this->apiep . '/upload_save?' . http_build_query( $get_params ) );
		if ( is_array( $api_response ) && ! is_wp_error( $api_response ) ) {
			$response_raw = wp_remote_retrieve_body( $api_response );
			if ( is_string( $response_raw ) && ! is_wp_error( $response_raw ) ) {
				wp2pclouddebugger::log( 'save() - File remotelly saved ! [ uplid: ' . $upload_id . ', name: ' . $name . ', fldid: ' . $folder_id . ' ]' );
			} else {
				wp2pclouddebugger::log( 'save() - no response body detected!' );
			}
		} else {
			$error = '';
			if ( is_wp_error( $api_response ) ) {
				$error = $api_response->get_error_message();
			}
			wp2pclouddebugger::log( 'save() - api call failed ! [ ' . $error . ' ]' );
		}
	}

	/**
	 * Upload - write content chunk
	 *
	 * @param string     $content String content to be writen.
	 * @param array|null $get_params Additinal request params.
	 *
	 * @return void
	 * @throws Exception Standart Exception can be thrown.
	 */
	private function write( string $content, ?array $get_params ): void {

		$err_message = 'failed to write to upload!';

		$get_params['access_token'] = $this->authkey;

		$args = array(
			'method'      => 'POST', // Adjust as needed
			'timeout'     => 45,
			'redirection' => 5,
			// 'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array(
				'Content-Type'   => 'application/octet-stream',
				'Content-Length' => strlen( $content ),
				'Expect'         => '',
			),
			'body'        => $content, // Ensure this matches the server's expected format
			'cookies'     => array(),
			'sslverify'   => false,
		);

		$api_response = wp_remote_request( $this->apiep . '/upload_write?' . http_build_query( $get_params ), $args );
		if ( is_array( $api_response ) && ! is_wp_error( $api_response ) ) {
			$response_body = wp_remote_retrieve_body( $api_response );

			$response_json = json_decode( $response_body, true );
			if ( ! is_bool( $response_json ) ) {
				if ( is_array( $response_json ) && isset( $response_json['result'] ) ) {
					if ( 0 === intval( $response_json['result'] ) ) {
						return;
					}
					if ( isset( $response_json['error'] ) && is_string( $response_json['error'] ) ) {
						$err_message = trim( $response_json['error'] );
					} else {
						wp2pclouddebugger::log( 'write() - unexpected msg returned: ' . wp_json_encode( $response_json ) );
					}
				}
			} else {
				$err_message = 'write error json decode message:' . json_last_error_msg();
			}
		} else {
			if ( is_wp_error( $api_response ) ) {
				$err_message = $api_response->get_error_message();
			}
		}

		wp2pclouddebugger::log( 'write() - api call failed ! [ ' . $err_message . ' ]' );

		throw new Exception( $err_message );
	}

	/**
	 * Validate the ZIP archives in folder.
	 *
	 * @param string $target_folder Target folder.
	 *
	 * @return void
	 * @throws Exception Throws exception if issue is detected.
	 */
	private function validate_zip_archive_in_dir( string $target_folder ): void {

		$target_folder = rtrim( $target_folder, '/' ) . '/';
		$handle        = opendir( $target_folder );
		if ( $handle ) {
			while ( $entry = readdir( $handle ) ) {
				if ( preg_match( '/\.zip$/', $entry ) ) {

					$file = $target_folder . $entry;

					$zip          = new ZipArchive();
					$open_archive = $zip->open( $file, ZIPARCHIVE::CHECKCONS );

					if ( is_bool( $open_archive ) && ! $open_archive ) {

						$zip->close();
						$zip = null;

						throw new Exception( 'error opening zip for validation! [ ' . $file . ' ]' );

					}

					if ( is_int( $open_archive ) ) {
						switch ( $open_archive ) {

							case ZipArchive::ER_MULTIDISK:
							case ZipArchive::ER_OK:
								break;

							case ZipArchive::ER_NOZIP:
								throw new Exception( 'not a zip archive [ ' . $entry . ' ]' );
							case ZipArchive::ER_INCONS:
								throw new Exception( 'zip archive inconsistent [ ' . $entry . ' ]' );
							case ZipArchive::ER_CRC:
								throw new Exception( 'checksum failed [ ' . $entry . ' ]' );
							case ZipArchive::ER_INTERNAL:
								throw new Exception( 'internal error [ ' . $entry . ' ]' );
							case ZipArchive::ER_EOF:
								throw new Exception( 'premature EOF [ ' . $entry . ' ]' );
							case ZipArchive::ER_CHANGED:
								throw new Exception( 'entry has been changed [ ' . $entry . ' ]' );
							case ZipArchive::ER_MEMORY:
								throw new Exception( 'memory allocation failure [ ' . $entry . ' ]' );
							case ZipArchive::ER_ZLIB:
								throw new Exception( 'zlib error [ ' . $entry . ' ]' );
							case ZipArchive::ER_TMPOPEN:
								throw new Exception( 'failure to create temporary file. [ ' . $entry . ' ]' );
							case ZipArchive::ER_OPEN:
								throw new Exception( 'can\'t open file [ ' . $entry . ' ]' );
							case ZipArchive::ER_SEEK:
								throw new Exception( 'seek error [ ' . $entry . ' ]' );
							case ZipArchive::ER_NOENT:
								throw new Exception( 'ZIP file not found! [ ' . $entry . ' ]' );

							default:
								throw new Exception( 'unknown error occured: ' . $open_archive . ' [ ' . $entry . ' ]' );
						}
					}

					if ( ! is_bool( $zip ) ) {
						$zip->close();
					}
					$zip = null;

					wp2pclouddebugger::log( $entry . ' seems valid ZIP archive!' );
				}
			}

			closedir( $handle );
		}
	}

	/**
	 * Set chunk size, based on the archive size.
	 *
	 * @param int $filesize ZIP Archive file.
	 *
	 * @return void
	 */
	public function set_chunk_size( int $filesize ): void {

		if ( ! is_numeric( $filesize ) ) {
			return;
		}

		if ( $filesize > ( 100 * 1000 * 1000 ) ) { // If Archive size is higher than 100MB.
			$this->part_size = 10 * 1000 * 1000;
		}
	}

	/**
	 * Get new upload_id in case it's not recognized.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function get_new_upload_id(): void {

		wp2pclouddebugger::log( 'get_new_upload_id() - started!' );

		$op_data = wp2pcloudfuncs::get_operation();

		$upload = $this->create_upload();
		if ( ! is_object( $upload ) ) {
			wp2pclouddebugger::log( 'File -> upload -> "get_new_upload_id" not returning the expected data!' );
			throw new Exception( 'File -> upload -> "get_new_upload_id" not returning the expected data!' );
		} else if ( property_exists( $upload, 'uploadid' ) || isset( $upload->uploadid ) ) {

			$op_data['state']        = 'ready_to_push';
			$op_data['upload_id']    = $upload->uploadid;
			$op_data['current_file'] = 0;
			$op_data['failures']     = 0;

			wp2pclouddebugger::log( 'get_new_upload_id() - new upload_id provided: ' . $upload->uploadid );

			wp2pcloudfuncs::set_operation( $op_data );
		}
	}

}
