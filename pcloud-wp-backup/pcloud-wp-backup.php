<?php
/**
 * Pcloud WP Backup plugin
 *
 * @package pcloud_wp_backup
 * @author pCloud
 *
 * Plugin Name: pCloud WP Backup
 * Plugin URI: https://www.pcloud.com
 * Summary: pCloud WP Backup plugin
 * Description: pCloud WP Backup has been created to make instant backups of your blog and its data, regularly.
 * Version: 2.0.8
 * Requires PHP: 8.0
 * Author: pCloud
 * URI: https://www.pcloud.com
 * License: Copyright 2013-2023 - pCloud
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

use Pcloud\Classes\wp2pclouddbbackup;
use Pcloud\Classes\wp2pclouddebugger;
use Pcloud\Classes\wp2pcloudfilebackup;
use Pcloud\Classes\wp2pcloudfilerestore;
use Pcloud\Classes\wp2pcloudfuncs;
use Pcloud\Classes\wp2pcloudlogger;
use Pcloud\Classes\WP2PcloudRatingPrompt;

require plugin_dir_path( __FILE__ ) . 'Pcloud/class-autoloader.php';

if ( ! defined( 'PCLOUD_API_LOCATIONID' ) ) {
	define( 'PCLOUD_API_LOCATIONID', 'wp2pcl_api_locationid' );
}
if ( ! defined( 'PCLOUD_AUTH_KEY' ) ) {
	define( 'PCLOUD_AUTH_KEY', 'wp2pcl_auth' );
}
if ( ! defined( 'PCLOUD_AUTH_MAIL' ) ) {
	define( 'PCLOUD_AUTH_MAIL', 'wp2pcl_auth_mail' );
}
if ( ! defined( 'PCLOUD_SCHDATA_KEY' ) ) {
	define( 'PCLOUD_SCHDATA_KEY', 'wp2pcl_schdata' );
}
if ( ! defined( 'PCLOUD_SCHHOUR_FROM_KEY' ) ) {
	define( 'PCLOUD_SCHHOUR_FROM_KEY', 'wp2pcl_schhour_from' );
}
if ( ! defined( 'PCLOUD_SCHHOUR_TO_KEY' ) ) {
	define( 'PCLOUD_SCHHOUR_TO_KEY', 'wp2pcl_schhour_to' );
}
if ( ! defined( 'PCLOUD_SCHDATA_INCLUDE_MYSQL' ) ) {
	define( 'PCLOUD_SCHDATA_INCLUDE_MYSQL', 'wp2pcl_include_mysql' );
}
if ( ! defined( 'PCLOUD_OPERATION' ) ) {
	define( 'PCLOUD_OPERATION', 'wp2pcl_operation' );
}
if ( ! defined( 'PCLOUD_HAS_ACTIVITY' ) ) {
	define( 'PCLOUD_HAS_ACTIVITY', 'wp2pcl_has_activity' );
}
if ( ! defined( 'PCLOUD_LOG' ) ) {
	define( 'PCLOUD_LOG', 'wp2pcl_logs' );
}
if ( ! defined( 'PCLOUD_DBG_LOG' ) ) {
	define( 'PCLOUD_DBG_LOG', 'wp2pcl_dbg_logs' );
}
if ( ! defined( 'PCLOUD_NOTIFICATIONS' ) ) {
	define( 'PCLOUD_NOTIFICATIONS', 'wp2pcl_notifications' );
}
if ( ! defined( 'PCLOUD_LAST_BACKUPDT' ) ) {
	define( 'PCLOUD_LAST_BACKUPDT', 'wp2pcl_last_backupdt' );
}
if ( ! defined( 'PCLOUD_QUOTA' ) ) {
	define( 'PCLOUD_QUOTA', 'wp2pcl_quota' );
}
if ( ! defined( 'PCLOUD_USEDQUOTA' ) ) {
	define( 'PCLOUD_USEDQUOTA', 'wp2pcl_usedquota' );
}
if ( ! defined( 'PCLOUD_MAX_NUM_FAILURES_NAME' ) ) {
	define( 'PCLOUD_MAX_NUM_FAILURES_NAME', 'wp2pcl_max_num_failures' );
}
if ( ! defined( 'PCLOUD_ASYNC_UPDATE_VAL' ) ) {
	define( 'PCLOUD_ASYNC_UPDATE_VAL', 'wp2pcl_async_upd_item' );
}
if ( ! defined( 'PCLOUD_BACKUP_FILE_INDEX' ) ) {
	define( 'PCLOUD_BACKUP_FILE_INDEX', 'wp2pcl_backup_file_index' );
}
if ( ! defined( 'PCLOUD_OAUTH_CLIENT_ID' ) ) {
	define( 'PCLOUD_OAUTH_CLIENT_ID', 'beFbFDM0paj' );
}
if ( ! defined( 'PCLOUD_TEMP_DIR' ) ) {
	$backup_dir = rtrim( WP_CONTENT_DIR, '/' ) . '/pcloud_tmp';
	define( 'PCLOUD_TEMP_DIR', $backup_dir );
}
if ( ! defined( 'PCLOUD_DEBUG' ) ) {
	define( 'PCLOUD_DEBUG', false );
}
if ( ! defined( 'PCLOUD_PLUGIN_MIN_PHP_VERSION' ) ) {
	define( 'PCLOUD_PLUGIN_MIN_PHP_VERSION', '8.0' );
}

// The maximum number of failures allowed.
$max_num_failures = 1800;

// Cron event args — must be identical everywhere we schedule, check, or clear init_autobackup.
// WordPress matches cron events by BOTH hook name AND args. The original code used array(false)
// but some call sites omitted it, causing wp_next_scheduled() to return false even when the
// event existed. This constant ensures every call site agrees.
if ( ! defined( 'PCLOUD_CRON_ARGS' ) ) {
	define( 'PCLOUD_CRON_ARGS', serialize( array( false ) ) );
}
/**
 * Helper — returns the cron args array. Using a function because define() can't hold arrays
 * directly on PHP < 8.1 in all contexts, and we want a single source of truth.
 */
function wp2pcl_cron_args(): array {
	return array( false );
}

/**
 * Raise the HTTP timeout for pCloud API calls, which otherwise give up after 5-10 sec.
 *
 * Two things changed in 2.0.8:
 *
 * 1. **Scoped to pCloud.** This is a global filter — it previously lengthened the
 *    timeout of every `wp_remote_*` call made by WordPress, any theme and any other
 *    plugin, for as long as this plugin was active. It now only applies to our own
 *    API hosts.
 * 2. **180s -> 60s.** Many hosts cap a request at around 120s (php-fpm
 *    `request_terminate_timeout`), which a 180s HTTP timeout outlives: the worker is
 *    killed before the call can time out, be retried and logged. A ceiling below the
 *    common cap means we handle the failure instead of dying from it. Chunk uploads
 *    are unaffected — `write()` passes its own explicit `timeout` in `$args`, which
 *    takes precedence over this filter.
 *
 * @param mixed  $timeout Current timeout in seconds.
 * @param string $url     Request URL (passed by WP core).
 *
 * @return int
 * @noinspection PhpUnused
 */
function pcl_wb_bkup_timeout_extend( $timeout = 5, string $url = '' ): int {

	// Only touch our own traffic. Anything else keeps WordPress's own timeout.
	if ( '' !== $url && ! str_contains( $url, 'pcloud.com' ) ) {
		return intval( $timeout );
	}

	$pcl_timeout = intval( apply_filters( 'pcloud_http_timeout', 60 ) );

	return ( $pcl_timeout > 0 ) ? $pcl_timeout : 60;
}

add_filter( 'http_request_timeout', 'pcl_wb_bkup_timeout_extend', 10, 2 );

$sitename = preg_replace( '/http(s?):\/\//', '', get_bloginfo( 'url' ) );
$sitename = str_replace( '.', '_', $sitename );

define( 'PCLOUD_BACKUP_DIR', 'WORDPRESS_BACKUPS/' . strtoupper( $sitename ) );

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$plugin_path_base = __DIR__;

$num_failures = wp2pcloudfuncs::get_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME );
if ( empty( $num_failures ) ) {
	wp2pcloudfuncs::set_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME, $max_num_failures );
}

$backup_file_index = wp2pcloudfuncs::get_stored_val( PCLOUD_BACKUP_FILE_INDEX );
if ( empty( $backup_file_index ) ) {
	$backup_file_index = time();
	wp2pcloudfuncs::set_stored_val( PCLOUD_BACKUP_FILE_INDEX, $backup_file_index );
}

/**
 * This function creates a menu item
 *
 * @return void
 * @noinspection PhpUnused
 */
function backup_to_pcloud_admin_menu(): void {
	$img_url = rtrim( plugins_url( '/assets/img/logo_16.png', __FILE__ ) );
	add_menu_page( 'WP2pCloud', 'pCloud Backup', 'administrator', 'wp2pcloud_settings', 'wp2pcloud_display_settings', $img_url );
}

/**
 * This function handles all ajax request sent back to the plugin
 *
 * @throws Exception Standart exception will be thrown.
 * @noinspection PhpUnused
 */
function wp2pcl_ajax_process_request(): void {

	global $sitename;

	try {
		wp2pcl_ajax_process_request_inner();
	} catch ( \Pcloud\Classes\WP2PcloudBackupException $e ) {
		wp2pclouddebugger::log( 'ajax: backup exception caught: ' . $e->getMessage() );
		wp2pcloudfuncs::set_operation();
		echo wp_json_encode( array(
			'status'   => 90,
			'message'  => 'Backup failed: ' . $e->getMessage(),
			'sitename' => $sitename,
		) );
	} catch ( \Pcloud\Classes\WP2PcloudRestoreException $e ) {
		wp2pclouddebugger::log( 'ajax: restore exception caught: ' . $e->getMessage() );
		wp2pcloudfuncs::set_operation();
		echo wp_json_encode( array(
			'status'   => 91,
			'message'  => 'Restore failed: ' . $e->getMessage(),
			'sitename' => $sitename,
		) );
	}
	die();
}

/**
 * Inner body of the AJAX handler. Kept separate so top-level `wp2pcl_ajax_process_request`
 * can uniformly catch typed backup/restore exceptions and reset state, instead of each
 * deep call site having to die() on its own.
 *
 * @return void
 * @throws Exception
 */
function wp2pcl_ajax_process_request_inner(): void {

	global $sitename;

	$result = array(
		'status'  => 1, // 0: OK, 1+: error
		'message' => '',
	);

	$m = isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : false;

	$dbg_mode = false;
	if ( isset( $_GET['dbg'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['dbg'] ) ) ) {
		$dbg_mode = true;
	}

	// Authorization gate for every AJAX method below. The wp_ajax_pcloudbackup hook only
	// guarantees the requester is logged in; is_admin() in admin-ajax context is NOT a
	// capability check. Without this, a subscriber-level user could reach state-changing
	// branches such as start_backup and unlink_acc (CVE-2026-14503). The plugin is
	// administrator-only (see the admin menu capability), so require that here too.
	if ( ! current_user_can( 'administrator' ) ) {
		$result['status']   = 15;
		$result['msg']      = '<p>You do not have permission to perform this action.</p>';
		$result['sitename'] = $sitename;

		echo wp_json_encode( $result );

		return;
	}

	if ( 'unlink_acc' === $m ) {

		if ( ! isset( $_POST['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp2pcl_nonce'] ) ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		wp2pcloudfuncs::set_stored_val( PCLOUD_AUTH_KEY, '' );
		wp2pcloudfuncs::set_stored_val( PCLOUD_AUTH_MAIL, '' );
		wp2pcloudfuncs::set_stored_val( PCLOUD_QUOTA, '1' );
		wp2pcloudfuncs::set_stored_val( PCLOUD_USEDQUOTA, '1' );
		wp2pcloudfuncs::set_stored_val( PCLOUD_API_LOCATIONID, '1' );
		wp2pcloudfuncs::set_stored_val( PCLOUD_SCHDATA_INCLUDE_MYSQL, '1' );

		$result['status'] = 0;

	} elseif ( 'set_with_mysql' === $m ) {

		if ( ! isset( $_POST['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp2pcl_nonce'] ) ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		$withmysql = isset( $_POST['wp2pcl_withmysql'] ) ? '1' : '0';

		wp2pcloudfuncs::set_stored_val( PCLOUD_SCHDATA_INCLUDE_MYSQL, $withmysql );

		$result['status'] = 0;

	} elseif ( 'userinfo' === $m ) {

		if ( ! isset( $_GET['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['wp2pcl_nonce'] ) ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		$result['status'] = 0;

		$authkey  = wp2pcloudfuncs::get_stored_val( PCLOUD_AUTH_KEY );
		$apiep    = 'https://' . wp2pcloudfuncs::get_api_ep_hostname();
		$url      = $apiep . '/userinfo?access_token=' . $authkey;
		$response = wp_remote_get( $url );
		if ( is_array( $response ) && ! is_wp_error( $response ) ) {
			$response_body_list = json_decode( $response['body'] );
			if ( is_object( $response_body_list ) && property_exists( $response_body_list, 'result' ) ) {
				$resp_result = intval( $response_body_list->result );
				if ( 0 === $resp_result ) {
					$result['data'] = $response_body_list;
				}
			}
		}
	} elseif ( 'listfolder' === $m ) {

		if ( ! isset( $_GET['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['wp2pcl_nonce'] ) ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		$result['status']   = 0;
		$result['contents'] = array();

		$authkey  = wp2pcloudfuncs::get_stored_val( PCLOUD_AUTH_KEY );
		$apiep    = 'https://' . wp2pcloudfuncs::get_api_ep_hostname();
		$url      = $apiep . '/listfolder?path=/' . PCLOUD_BACKUP_DIR . '&access_token=' . $authkey;
		$response = wp_remote_get( $url );
		if ( is_array( $response ) && ! is_wp_error( $response ) ) {
			$response_body_list = json_decode( $response['body'] );
			if ( is_object( $response_body_list ) && property_exists( $response_body_list, 'result' ) ) {
				$resp_result = intval( $response_body_list->result );
				if ( ( 0 === $resp_result ) && property_exists( $response_body_list, 'metadata' ) && property_exists( $response_body_list->metadata, 'contents' ) ) {
					$result['folderid'] = $response_body_list->metadata->folderid;
					$result['contents'] = $response_body_list->metadata->contents;
				} else {
					pcl_verify_directory_structure();
				}
			}
		} else {
			$result['status'] = 65;
			$result['msg']    = '<p>Failed to get backup files list!</p>';
		}
	} elseif ( 'set_schedule' === $m ) {

		if ( ! isset( $_POST['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp2pcl_nonce'] ) ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		$freq      = isset( $_POST['freq'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['freq'] ) ) ) : 't';
		$hour_from = isset( $_POST['hour_from'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['hour_from'] ) ) ) : '-1';
		$hour_to   = isset( $_POST['hour_to'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['hour_to'] ) ) ) : '-1';

		if ( 't' === $freq ) {

			wp2pclouddebugger::log( 'Test initiated !' );

			$freq = 'daily';

			wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, '0' );

			wp_clear_scheduled_hook( 'init_autobackup', wp2pcl_cron_args() );

			wp2pcl_run_pcloud_backup_hook();
		}

		wp2pcloudfuncs::set_stored_val( PCLOUD_SCHDATA_KEY, $freq );
		wp2pcloudfuncs::set_stored_val( PCLOUD_SCHHOUR_FROM_KEY, $hour_from );
		wp2pcloudfuncs::set_stored_val( PCLOUD_SCHHOUR_TO_KEY, $hour_to );

		$result['status'] = 0;

	} elseif ( 'restore_archive' === $m ) {

		wp2pclouddebugger::generate_new( 'restore_archive at: ' . gmdate( 'Y-m-d H:i:s' ) );

		$memlimit    = ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '---' );
		$memlimitini = ini_get( 'memory_limit' );
		wp2pclouddebugger::log( 'Memory limits: ' . $memlimit . ' / ' . $memlimitini );

		if ( ! isset( $_POST['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp2pcl_nonce'] ) ) ) {

			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		wp2pcloudfuncs::set_execution_limits();

		wp2pcloudfuncs::set_stored_val( PCLOUD_HAS_ACTIVITY, '1' );

		wp2pcloudlogger::generate_new( "<span class='pcl_transl' data-i10nk='start_restore_at'>Start restore at</span> " . gmdate( 'Y-m-d H:i:s' ) );
		wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='prep_dwl_file_wait'>Preparing Download file request, please wait...</span>" );

		$file_id   = isset( $_POST['file_id'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) ) : 0;
		$folder_id = isset( $_POST['folder_id'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['folder_id'] ) ) ) : 0;

		$doc_root_arr = explode( DIRECTORY_SEPARATOR, dirname( __FILE__ ) );
		array_pop( $doc_root_arr );
		array_pop( $doc_root_arr );
		array_pop( $doc_root_arr );

		$authkey  = wp2pcloudfuncs::get_stored_val( PCLOUD_AUTH_KEY );
		$hostname = wp2pcloudfuncs::get_api_ep_hostname();

		if ( $file_id > 0 || $folder_id > 0 || empty( $hostname ) ) {

			$apiep      = 'https://' . wp2pcloudfuncs::get_api_ep_hostname();
			$archives   = array();
			$total_size = 0;

			if ( $folder_id > 0 ) {

				$url      = $apiep . '/listfolder?folderid=' . $folder_id . '&access_token=' . $authkey;
				$response = wp_remote_get( $url );
				if ( is_array( $response ) && ! is_wp_error( $response ) ) {
					$response_body_list = json_decode( $response['body'] );
					if ( is_object( $response_body_list ) && property_exists( $response_body_list, 'result' ) ) {
						$resp_result = intval( $response_body_list->result );
						if ( 0 === $resp_result && property_exists( $response_body_list, 'metadata' ) && property_exists( $response_body_list->metadata, 'contents' ) ) {
							foreach ( $response_body_list->metadata->contents as $item ) {
								if ( property_exists( $item, 'name' ) && property_exists( $item, 'fileid' ) ) {
									if ( 'backup.sql.zip' === $item->name || preg_match( '/^\d{3}_archive\.zip$/', $item->name ) ) {

										$url = $apiep . '/getfilelink?fileid=' . $item->fileid . '&access_token=' . $authkey;

										$response = wp_remote_get( $url );
										if ( is_array( $response ) && ! is_wp_error( $response ) ) {
											$r = json_decode( $response['body'] );
											if ( is_object( $r ) && property_exists( $r, 'result' ) && 0 === intval( $r->result )
												&& property_exists( $r, 'hosts' ) && is_array( $r->hosts ) && ! empty( $r->hosts )
												&& property_exists( $r, 'path' ) ) {
												$url         = 'https://' . reset( $r->hosts ) . $r->path;
												$archives[]  = array(
													'fileid' => $item->fileid,
													'name' => $item->name,
													'size' => $item->size,
													'dwlurl' => $url,
												);
												$total_size += $item->size;
											}
										}
									}
								}
							}
						}
					}
				}
			} else {
				$url      = $apiep . '/getfilelink?fileid=' . $file_id . '&access_token=' . $authkey;
				$response = wp_remote_get( $url );
				if ( is_array( $response ) && ! is_wp_error( $response ) ) {
					$r = json_decode( $response['body'] );
					if ( is_object( $r ) && property_exists( $r, 'result' ) && 0 === intval( $r->result )
						&& property_exists( $r, 'hosts' ) && is_array( $r->hosts ) && ! empty( $r->hosts )
						&& property_exists( $r, 'path' ) && property_exists( $r, 'size' ) ) {
						$url        = 'https://' . reset( $r->hosts ) . $r->path;
						$archives[] = array(
							'fileid' => $file_id,
							'name'   => 'restore_' . time() . '.zip',
							'size'   => $r->size,
							'dwlurl' => $url,
						);
						$total_size = $r->size;
					}
				}
			}

			if ( count( $archives ) < 1 ) {
				$result['status'] = 75;
				$result['msg']    = '<p>Failed to get backup file!</p>';
			}

			$op_data = array(
				'operation'   => 'download',
				'state'       => 'init',
				'mode'        => 'manual',
				'archive_num' => 0,
				'archives'    => wp_json_encode( $archives ),
				'offset'      => 0,
				'downloaded'  => 0,
				'total_size'  => $total_size,
				'failures'    => 0,
			);

			wp2pcloudfuncs::set_operation( $op_data );

		} else {

			$result['status'] = 80;
			$result['msg']    = '<p>File/Folder ID not provided, or maybe hostname is missing!</p>';

		}
	} elseif ( 'get_log' === $m ) {

		$nonce = isset( $_GET['wp2pcl_nonce'] ) ? sanitize_key( $_GET['wp2pcl_nonce'] ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;
			echo wp_json_encode( $result );
			return;
		}

		if ( ! current_user_can( 'administrator' ) ) {
			$result['status']   = 16;
			$result['msg']      = '<p>Insufficient permissions.</p>';
			$result['sitename'] = $sitename;
			echo wp_json_encode( $result );
			return;
		}

		$operation = wp2pcloudfuncs::get_operation();

		$op_type  = $operation['operation'] ?? 'nothing';
		$op_state = $operation['state'] ?? 'sleep';
		$op_mode  = $operation['mode'] ?? '';

		// Decide whether the poll should advance the state machine or just report.
		//
		// Heavy phases (init, preparing) run only from the cron context — they do
		// synchronous ZIP creation and folder setup that would block the AJAX
		// response. The poll just reports a human-readable status for these.
		//
		// Lightweight phases (ready_to_push, uploading_chunks, download states)
		// CAN be advanced by the poll. This is essential: it means auto backups
		// upload at the same speed as manual when the admin page is open, instead
		// of only moving one chunk per 2-minute cron tick.

		$poll_can_advance = in_array( $op_state, array(
			'ready_to_push',
			'uploading_chunks',
			'init',             // download init (trivial state transition)
			'download_chunks',
			'extract',
			'restoredb',
			'cleanup',
		), true );

		// For upload init/preparing, the cron handles the heavy lifting.
		$is_upload_prep = ( 'upload' === $op_type && in_array( $op_state, array( 'init', 'preparing' ), true ) );

		if ( $is_upload_prep ) {

			// The poll is the only thing still running when the ZIP worker has been killed
			// (manual backups never reach the cron's in-progress path), so this is where a
			// stalled backup has to be caught. Costs one timestamp comparison per poll.
			if ( wp2pcl_zip_is_stalled( $operation ) ) {
				// Re-read: the watchdog reset it. hasactivity is refreshed from storage
				// a few lines below, which now reports '0'.
				$operation = wp2pcloudfuncs::get_operation();
			} else {
				// Show a status message while the worker creates the ZIP archive.
				$result['hasactivity'] = '1';
				if ( 'init' === $op_state ) {
					$result['log'] = '<br/>' . gmdate( 'Y-m-d H:i:s' ) . ' - <span class="pcl_transl" data-i10nk="start_auto_backup_at">Automatic backup is starting, please wait...</span>';
				}
				// For 'preparing', the worker is writing to the user/debug log in real
				// time; we'll pick up those entries below via read_last_log.
			}
		} elseif ( ( 'upload' === $op_type || 'download' === $op_type ) && $poll_can_advance ) {
			$proc   = wp2pcl_event_processor();
			$result = $proc['result'];
		}

		$result['hasactivity'] = wp2pcloudfuncs::get_stored_val( PCLOUD_HAS_ACTIVITY, '0' );

		if ( $dbg_mode ) {
			$result['log'] = wp2pclouddebugger::read_last_log( false );
		} else {
			$result['log'] = wp2pcloudlogger::read_last_log( false );
		}

		$quota     = wp2pcloudfuncs::get_stored_val( PCLOUD_QUOTA, '1' );
		$usedquota = wp2pcloudfuncs::get_stored_val( PCLOUD_USEDQUOTA, '1' );

		if ( $quota > 0 && $usedquota > 0 ) {
			$perc                = round( ( $usedquota / ( $quota / 100 ) ), 2 );
			$result['quotaperc'] = $perc;
		}

		if ( isset( $operation['mode'] ) && 'nothing' !== $operation['mode'] ) {
			$result['operation'] = $operation;
		}

		// Auto-mode backups now show the same progress bar as manual. Previously
		// the percentage was stripped here, leaving no visible indication that an
		// auto backup was running — the #1 user complaint about scheduled backups.

		$result['memlimit']    = ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '---' );
		$result['memlimitini'] = ini_get( 'memory_limit' );
		$result['failures']    = $operation['failures'] ?? 0;
		$result['maxfailures'] = intval( wp2pcloudfuncs::get_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME ) );

	} elseif ( 'check_can_restore' === $m ) {

		$pl_dir_arr = dirname( __FILE__ );

		if ( ! is_writable( $pl_dir_arr . '/' ) ) {
			$result['status'] = 80;
			$result['msg']    = '<p>Path ' . $pl_dir_arr . '/ is not writable!</p>';
		} elseif ( ! is_writable( sys_get_temp_dir() ) ) {
			$result['status'] = 82;
			$result['msg']    = '<p>Path ' . sys_get_temp_dir() . ' is not writable!</p>';
		} else {
			$result['status'] = 0;
		}
	} elseif ( 'start_backup' === $m ) {

		if ( ! isset( $_POST['wp2pcl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp2pcl_nonce'] ) ) ) {
			$result['status']   = 15;
			$result['msg']      = '<p>Failed to validate the request!</p>';
			$result['sitename'] = $sitename;

			echo wp_json_encode( $result );

			return;
		}

		wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() );
		wp2pcloudfuncs::set_stored_val( PCLOUD_HAS_ACTIVITY, '1' );

		wp2pclouddebugger::generate_new( 'start_backup at: ' . gmdate( 'Y-m-d H:i:s' ) . ' | instance: ' . $sitename );

		$memlimit    = ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '---' );
		$memlimitini = ini_get( 'memory_limit' );

		wp2pclouddebugger::log( 'Memory limits: ' . $memlimit . ' / ' . $memlimitini );

		wp2pcl_perform_manual_backup();

		echo '{}';
		die();

	}

	$result['sitename'] = $sitename;

	echo wp_json_encode( $result );
	die();
}


/**
 * Detect a backup whose worker died during the ZIP phase, and clear it.
 *
 * The `preparing` state is produced by WP2PcloudFileBackup::start(), which builds the
 * archives synchronously inside whichever request triggered the backup. If that worker
 * is killed — php-fpm `request_terminate_timeout` (which set_time_limit(0) does not
 * override), a gateway timeout, or the container OOM killer — no PHP error is raised
 * and no code runs to reset the state. Before 2.0.7 the result was a permanently frozen
 * progress screen: the browser poll deliberately never advances `init`/`preparing`, and
 * the cron's in-progress override only applies to `mode = auto`, so a manual backup sat
 * in `preparing` until the failure counter aged out — days, at a 2-minute tick.
 *
 * Liveness comes from `zip_heartbeat`, refreshed every N entries and on both sides of
 * every archive write by WP2PcloudFileBackup::zip_heartbeat().
 *
 * @param array $operation Current operation state.
 *
 * @return bool True if the operation was found stalled and has been reset.
 */
function wp2pcl_zip_is_stalled( array $operation ): bool {

	if ( ! isset( $operation['operation'], $operation['state'] ) ) {
		return false;
	}
	if ( 'upload' !== $operation['operation'] || ! in_array( $operation['state'], array( 'init', 'preparing' ), true ) ) {
		return false;
	}

	$heartbeat = intval( $operation['zip_heartbeat'] ?? 0 );
	$since     = intval( $operation['preparing_since'] ?? 0 );
	$last_sign = max( $heartbeat, $since );

	// An operation started under <= 2.0.6 carries neither stamp. Adopt it rather than
	// judging it — otherwise an in-flight backup would be killed by the upgrade itself.
	if ( $last_sign < 1 ) {
		$operation['preparing_since'] = time();
		$operation['zip_heartbeat']   = time();
		wp2pcloudfuncs::set_operation( $operation );
		return false;
	}

	// Generous by design: a single very large file can sit inside one compression call
	// for a long time without touching the heartbeat.
	$timeout = intval( apply_filters( 'pcloud_zip_stall_timeout', 1200 ) );
	if ( $timeout < 300 ) {
		$timeout = 300;
	}

	$silent_for = time() - $last_sign;
	if ( $silent_for <= $timeout ) {
		return false;
	}

	// Second opinion before we reset anything. The heartbeat cannot fire during a single
	// save_as_file() call, and on a slow disk one archive can take a long time to write —
	// but the archive file itself keeps growing while that happens. A recent mtime in the
	// working directory means the worker is alive and this is a slow backup, not a dead one.
	clearstatcache();

	$tmp_dir   = plugin_dir_path( __FILE__ ) . 'tmp';
	$newest    = 0;
	$tmp_files = is_dir( $tmp_dir ) ? glob( $tmp_dir . '/*.zip' ) : array();

	if ( is_array( $tmp_files ) ) {
		foreach ( $tmp_files as $tmp_file ) {
			$mtime = @filemtime( $tmp_file ); // phpcs:ignore
			if ( is_int( $mtime ) && $mtime > $newest ) {
				$newest = $mtime;
			}
		}
	}

	if ( $newest > 0 && ( time() - $newest ) <= $timeout ) {
		wp2pclouddebugger::log(
			'Stall check: no heartbeat for ' . $silent_for . 's, but the working directory was '
			. 'written ' . ( time() - $newest ) . 's ago — worker still alive, not resetting.'
		);

		return false;
	}

	wp2pclouddebugger::log(
		'== STALLED == no ZIP progress for ' . $silent_for . 's (limit ' . $timeout . 's) in state "'
		. $operation['state'] . '". The worker building the archives was terminated without '
		. 'raising a PHP error — typically an fpm request_terminate_timeout or an out-of-memory '
		. 'kill. Resetting so a new backup can be started.'
	);

	wp2pcloudlogger::info(
		"<span style='color: red' class='pcl_transl' data-i10nk='backup_stalled'>Backup stopped: the server ended the process while archives were being created. Please try again — if it repeats, the site may be too large for this server's limits.</span>"
	);

	wp2pcloudfuncs::set_operation();
	wp2pcloudfuncs::set_stored_val( PCLOUD_HAS_ACTIVITY, '0' );

	return true;
}


/**
 * This function handles the processes required by the plugin
 *
 * @throws Exception Standard exception will be thrown.
 */
function wp2pcl_event_processor(): array {

	global $plugin_path_base;

	$result = array(
		'status'  => 1, // 0: OK, 1+: error
		'message' => '',
	);

	$operation = wp2pcloudfuncs::get_operation();

	// Prevent concurrent state transitions. Two poll requests (e.g. admin opens the page in
	// two tabs, or cron fires while a poll is in flight) used to race and could double-advance
	// the state machine — e.g., both transitioning `ready_to_push` -> `uploading_chunks` and
	// racing to upload chunks against the same upload_id.
	$lock_key  = 'wp2pcl_op_lock';
	$lock_ttl  = 30; // seconds
	$lock_held = false;
	if ( function_exists( 'get_transient' ) ) {
		if ( false !== get_transient( $lock_key ) ) {
			$result['status'] = 0;
			$result['busy']   = true;
			return array(
				'operation' => $operation,
				'result'    => $result,
			);
		}
		set_transient( $lock_key, 1, $lock_ttl );
		$lock_held = true;
	}

	try {

	if ( 'upload' === $operation['operation'] ) {
		wp2pclouddebugger::log( 'uploading' );
	} else {
		if ( 'nothing' !== $operation['operation'] ) {
			wp2pclouddebugger::log( 'wp2pcl_event_processor() - op:' . $operation['operation'] );
		}
	}

	if ( isset( $operation['operation'] ) ) {

		if ( isset( $operation['cleanat'] ) ) {

			unset( $operation['perc'] );

			if ( time() > $operation['cleanat'] ) {
				wp2pcloudlogger::clear_log();
				wp2pcloudfuncs::set_stored_val( PCLOUD_HAS_ACTIVITY, '0' );
			}
		} else {

			if ( 'upload' === $operation['operation'] || 'download' === $operation['operation'] ) {
				wp2pcloudfuncs::set_execution_limits();
			}

			if ( 'upload' === $operation['operation'] && 'ready_to_push' === $operation['state'] ) {

				wp2pclouddebugger::log( 'Upload: ready_to_push!<br/>' );

				$operation['state'] = 'uploading_chunks';
				wp2pcloudfuncs::set_operation( $operation );

			} elseif ( 'upload' === $operation['operation'] && 'preparing' === $operation['state'] ) {

				// Catch a killed ZIP worker here too — this is the path the cron takes for
				// auto backups, which never touch the browser poll.
				if ( wp2pcl_zip_is_stalled( $operation ) ) {
					$result['status'] = 0;

					return array(
						'operation' => wp2pcloudfuncs::get_operation(),
						'result'    => $result,
					);
				}

				$operation['failures'] += 1;

				wp2pcloudfuncs::set_operation( $operation );

				$max_num_failures = intval( wp2pcloudfuncs::get_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME ) );

				if ( $operation['failures'] > $max_num_failures ) {

					wp2pclouddebugger::log( '== ERROR == Too many failures ( ' . $operation['failures'] . ' / ' . $max_num_failures . ' ), leaving.. !' );

					wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='too_many_failures'>ERROR: Too many failures, try to disable/enable the plugin !</span>" );
					wp2pcloudfuncs::set_operation();

					if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {
						wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() - 5 );
					}
				}
			} elseif ( 'upload' === $operation['operation'] && 'uploading_chunks' === $operation['state'] ) {

				$upload_files = trim( $operation['upload_files'] );
				$current_file = intval( $operation['current_file'] );
				$folder_id    = intval( $operation['folder_id'] );
				$upload_id    = intval( $operation['upload_id'] );
				$offset       = intval( $operation['offset'] );
				$upload_files = json_decode( $upload_files, true );
				if ( ! is_array( $upload_files ) ) {
					$upload_files = array();
				}

				if ( 1 > count( $upload_files ) ) {

					wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='err_no_archive_files_found'>ERROR: No Archive files found!</span>" );
					wp2pcloudfuncs::set_operation();

					$result['newoffset'] = $offset + 99999;

					if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {
						wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() - 5 );
					}

					wp2pcloudfuncs::set_operation();

				} else {

					if ( ! isset( $upload_files[ $current_file ] ) ) {

						$operation['current_file'] = -1;
						wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='upload_completed'>Upload completed!</span>" );
						wp2pclouddebugger::log( 'UPLOAD COMPLETED, scheduler should be OFF!' );

						$file_op = new wp2pcloudfilebackup( $plugin_path_base );
						$file_op->clear_all_tmp_files();

						WP2PcloudRatingPrompt::record_successful_backup();

						if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {
							wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() );
						}

						wp2pcloudfuncs::set_operation();

					} else {

						$selected_file = $upload_files[ $current_file ];

						$path = rtrim( $plugin_path_base, '/' ) . '/tmp/' . $selected_file;

						$size = abs( filesize( $path ) );

						$result['offset']    = $offset;
						$result['size']      = $size;
						$result['sizefancy'] = '~' . round( ( $size / 1024 / 1024 ), 2 ) . ' MB';

						if ( 'OK' === $operation['chunkstate'] ) {

							$operation['chunkstate'] = 'uploading';

							wp2pcloudfuncs::set_operation( $operation );

							$file_op = new wp2pcloudfilebackup( $plugin_path_base );

							try {
								if ( isset( $operation['mode'] ) && 'manual' === $operation['mode'] ) {
									$newoffset = $file_op->upload_chunk( $path, $folder_id, $upload_id, $offset, $operation['failures'] );
								} else {
									$time_limit = ini_get( 'max_execution_time' );
									if ( ! is_bool( $time_limit ) && intval( $time_limit ) <= 0 ) {
										$newoffset = $file_op->upload( $path, $upload_id, $offset );
									} else {
										$newoffset = $file_op->upload_chunk( $path, $folder_id, $upload_id, $offset, $operation['failures'] );
									}
								}
							} catch ( Exception $e ) {
								wp2pclouddebugger::log( 'event_processor() upload exception: ' . $e->getMessage() );
								wp2pcloudlogger::info( 'Upload error: ' . $e->getMessage() );
								$newoffset = $offset; // Counts as a failure via the <= check below.
							}

							$result['newoffset']     = $newoffset;
							$operation['chunkstate'] = 'OK';

						} else {
							$result['newoffset'] = $offset;
							$newoffset           = $offset;
						}

						if ( $newoffset <= $offset ) {
							if ( ! isset( $operation['failures'] ) ) {
								$operation['failures'] = 1;
							}
							$operation['failures'] ++;
						} else {
							$operation['failures'] = 0;
						}

						if ( $newoffset > 0 ) {

							$operation['offset'] = $newoffset;
							$result['perc']      = 0;

							if ( $size > 0 ) {
								$result['perc'] = round( abs( $newoffset / ( $size / 100 ) ), 2 );
							}
						}

						wp2pcloudfuncs::set_operation( $operation );

						$max_num_failures = intval( wp2pcloudfuncs::get_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME ) );

						if ( $operation['failures'] > $max_num_failures ) {

							$operation['current_file'] = -1;

							wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='too_many_failures'>ERROR: Too many failures, try to disable/enable the plugin !</span>" );
							wp2pcloudfuncs::set_operation();

							$file_op = new wp2pcloudfilebackup( $plugin_path_base );
							$file_op->clear_all_tmp_files();

							if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {

								wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() );

								wp2pclouddebugger::log( 'UPLOAD COMPLETED, scheduler should be OFF!' );
							}
						} else {

							if ( $newoffset >= $size ) {

								$filename = basename( $upload_files[ $current_file ] );

								$file_op = new wp2pcloudfilebackup( $plugin_path_base );
								$file_op->save( $upload_id, $filename, $folder_id );

								wp2pclouddebugger::log( '[ ' . $current_file . ' ] File upload completed!' );

								$new_file_index = $current_file + 1;

								if ( isset( $upload_files[ $new_file_index ] ) ) {

									wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='upload_completed_wait_next' style='color: green'>File upload completed! Please wait for the next file to be uploaded!</span>" );

									try {
										$upload = $file_op->create_upload();
									} catch ( Exception $e ) {
										wp2pclouddebugger::log( 'event_processor() create_upload failed: ' . $e->getMessage() );
										wp2pcloudlogger::info( "<span style='color: red'>ERROR:</span> " . esc_html( $e->getMessage() ) );
										$file_op->clear_all_tmp_files();
										if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {
											wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() );
										}
										wp2pcloudfuncs::set_operation();
										return array(
											'operation' => wp2pcloudfuncs::get_operation(),
											'result'    => array( 'status' => 1, 'message' => $e->getMessage() ),
										);
									}

									$operation['current_file'] = $new_file_index;
									$operation['upload_id']    = $upload->uploadid;
									$operation['failures']     = 0;
									$operation['offset']       = 0;

									wp2pcloudfuncs::set_operation( $operation );

								} else {

									wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='upload_completed'>Upload completed!</span>" );
									wp2pclouddebugger::log( 'UPLOAD COMPLETED, scheduler should be OFF!' );

									$file_op = new wp2pcloudfilebackup( $plugin_path_base );
									$file_op->clear_all_tmp_files();

									WP2PcloudRatingPrompt::record_successful_backup();

									if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {
										wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() );
									}

									wp2pcloudfuncs::set_operation();
								}
							}
						}
					}
				}
			}

			if ( 'download' === $operation['operation'] && 'init' === $operation['state'] ) {

				$operation['state'] = 'download_chunks';
				wp2pcloudfuncs::set_operation( $operation );

			} elseif ( 'download' === $operation['operation'] && 'extract' === $operation['state'] ) {

				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='start_extr_file_folders'>Start extracting files and folders, please wait...</span>" );

				$file_op = new wp2pcloudfilerestore();

				$archives = json_decode( $operation['archives'], true );
				if ( is_array( $archives ) ) {
					foreach ( $archives as $archive ) {
						$file_op->extract( PCLOUD_TEMP_DIR . '/' . $archive['name'] );
					}
				}

				$operation['state'] = 'restoredb';
				wp2pcloudfuncs::set_operation( $operation );

			} elseif ( 'download' === $operation['operation'] && 'restoredb' === $operation['state'] ) {

				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='start_extr_db'>Start reconstructing the database, please wait...</span>" );

				$file_op = new wp2pcloudfilerestore();
				$file_op->restore_db();

				$operation['state'] = 'cleanup';
				wp2pcloudfuncs::set_operation( $operation );

			} elseif ( 'download' === $operation['operation'] && 'cleanup' === $operation['state'] ) {

				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='clean_up_pls_wait'>Cleaning up, please wait...</span>" );

				$file_op = new wp2pcloudfilerestore();

				$archives = json_decode( $operation['archives'], true );
				if ( is_array( $archives ) ) {
					foreach ( $archives as $archive ) {
						$file_op->remove_files( PCLOUD_TEMP_DIR . '/' . $archive['name'] );
					}
				}

				wp2pcloudfuncs::set_operation();

				wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='bk_restored'>Backup - restored! You can refresh the page now!</span>" );

			} elseif ( 'download' === $operation['operation'] && 'download_chunks' === $operation['state'] ) {

				if ( PCLOUD_DEBUG ) {
					$result['msg'] = 'Download chunks ...!';
				}

				$offset      = intval( $operation['offset'] );
				$archives    = trim( $operation['archives'] );
				$archive_num = intval( $operation['archive_num'] );
				$total_size  = intval( $operation['total_size'] );
				$archives    = json_decode( $archives, true );
				if ( ! is_array( $archives ) ) {
					$archives = array();
				}

				if ( 1 > count( $archives ) ) {

					wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='failed_no_archive_file_to_download'>ERROR: No Archive to download!</span>" );
					wp2pcloudfuncs::set_operation();

					$result['newoffset'] = $offset + 99999;

					if ( isset( $operation['mode'] ) && 'auto' === $operation['mode'] ) {
						wp2pcloudfuncs::set_stored_val( PCLOUD_LAST_BACKUPDT, time() - 5 );
					}

					wp2pcloudfuncs::set_operation();

				} elseif ( ! isset( $archives[ $archive_num ] ) ) {

					wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='dwl_completed'>Download completed!</span>" );
					wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='unzip_pls_wait'>Unzipping the archive, please wait:</span>" );

					$operation['state'] = 'extract';
					wp2pcloudfuncs::set_operation( $operation );

				} else {

					$archive = $archives[ $archive_num ];

					$dwlurl              = trim( $archive['dwlurl'] );
					$size                = intval( $archive['size'] );
					$archive_name        = PCLOUD_TEMP_DIR . '/' . trim( $archive['name'] );
					$result['offset']    = $offset;
					$result['size']      = $size;
					$result['sizefancy'] = '~' . round( ( $total_size / 1024 / 1024 ), 2 ) . ' MB';

					$file_op             = new wp2pcloudfilerestore();
					$newoffset           = $file_op->download_chunk_curl( $dwlurl, $offset, $archive_name );
					$result['newoffset'] = $newoffset;

					if ( $newoffset > $offset ) {
						$operation['downloaded'] += $newoffset - $offset;
						$operation['offset']      = $newoffset;
						$operation['failures']    = 0;

						$result['perc'] = 0;
						if ( $total_size > 0 ) {
							$result['perc'] = round( abs( $operation['downloaded'] / ( $total_size / 100 ) ), 2 );
						}
					} else {
						// No progress this tick — count as a failure so we can eventually abort.
						$operation['failures'] = intval( $operation['failures'] ?? 0 ) + 1;
					}

					if ( $newoffset >= $size ) {
						$operation['archive_num'] = $archive_num + 1;
						$operation['offset']      = 0;
						$operation['failures']    = 0;
					}

					$max_num_failures = intval( wp2pcloudfuncs::get_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME ) );
					if ( intval( $operation['failures'] ?? 0 ) > $max_num_failures ) {
						wp2pcloudlogger::info( "<span class='pcl_transl' data-i10nk='too_many_failures'>ERROR: Too many failures, try to disable/enable the plugin !</span>" );
						wp2pclouddebugger::log( 'Download failed - max_num_failures exceeded at archive ' . $archive_num . ' offset ' . $offset );
						$file_op = new wp2pcloudfilerestore();
						$file_op->remove_files( $archive_name );
						wp2pcloudfuncs::set_operation();
					} else {
						wp2pcloudfuncs::set_operation( $operation );
					}
				}
			}

			if ( isset( $result['perc'] ) && $result['perc'] > 100 ) {
				$result['perc'] = 100;
			}
		}
	}
	} finally {
		if ( $lock_held && function_exists( 'delete_transient' ) ) {
			delete_transient( $lock_key );
		}
	}

	return array(
		'operation' => $operation,
		'result'    => $result,
	);
}

/**
 * Start manual backup procedure
 *
 * @throws Exception Standart exception will be thrown.
 */
function wp2pcl_perform_manual_backup(): void {

	global $plugin_path_base;

	wp2pcloudfuncs::set_execution_limits();

	wp2pcloudlogger::generate_new( "<span class='pcl_transl' data-i10nk='start_backup_at'>Start backup at</span> " . gmdate( 'Y-m-d H:i:s' ) );

	$f = new wp2pcloudfilebackup( $plugin_path_base );

	$wp2pcl_withmysql = wp2pcloudfuncs::get_stored_val( PCLOUD_SCHDATA_INCLUDE_MYSQL );
	if ( ! empty( $wp2pcl_withmysql ) && 1 === intval( $wp2pcl_withmysql ) ) {
		wp2pclouddebugger::log( 'Database backup will start now!' );
		try {
			$b    = new wp2pclouddbbackup();
			$file = $b->start();
			if ( ! is_bool( $file ) ) {
				$f->set_mysql_backup_filename( $file );
				wp2pclouddebugger::log( 'Database backup - ready!' );
			} else {
				wp2pclouddebugger::log( 'Database backup - failed!' );
				wp2pcloudlogger::info( "<span style='color: red' class='pcl_transl' data-i10nk='failed_to_backup_db'>Database backup - failed!</span>" );
			}
		} catch ( Exception $db_ex ) {
			wp2pclouddebugger::log( 'Database backup constructor failed: ' . $db_ex->getMessage() );
			wp2pcloudlogger::info( "<span style='color: red' class='pcl_transl' data-i10nk='failed_to_backup_db'>Database backup - failed!</span> " . esc_html( $db_ex->getMessage() ) );
			// File backup will proceed without a DB snapshot.
		}
	}

	wp2pclouddebugger::log( 'File backup will start now!' );

	$f->start();
}


/**
 * This function performce auto-backup
 *
 * @throws Exception Standart exception will be thrown.
 */
function wp2pcl_perform_auto_backup(): void {

	global $plugin_path_base;

	$operation = wp2pcloudfuncs::get_operation();

	if ( 'init' === $operation['state'] ) {

		pcl_verify_directory_structure();

		wp2pclouddebugger::log( 'wp2pcl_perform_auto_backup() - op:init !' );

		wp2pcloudlogger::generate_new( "<span class='pcl_transl' data-i10nk='start_auto_backup_at'>Start auto backup at</span> " . gmdate( 'Y-m-d H:i:s' ) );

		$f = new wp2pcloudfilebackup( $plugin_path_base );

		$wp2pcl_withmysql = wp2pcloudfuncs::get_stored_val( PCLOUD_SCHDATA_INCLUDE_MYSQL );
		if ( ! empty( $wp2pcl_withmysql ) && 1 === intval( $wp2pcl_withmysql ) ) {
			try {
				$b    = new wp2pclouddbbackup();
				$file = $b->start();
				if ( ! is_bool( $file ) ) {
					$f->set_mysql_backup_filename( $file );
					wp2pclouddebugger::log( 'Database backup - ready!' );
				} else {
					wp2pclouddebugger::log( 'Database backup - failed!' );
					wp2pcloudlogger::info( "<span style='color: red' class='pcl_transl' data-i10nk='failed_to_backup_db'>Database backup - failed!</span>" );
				}
			} catch ( Exception $db_ex ) {
				wp2pclouddebugger::log( 'Database backup constructor failed: ' . $db_ex->getMessage() );
				wp2pcloudlogger::info( "<span style='color: red' class='pcl_transl' data-i10nk='failed_to_backup_db'>Database backup - failed!</span> " . esc_html( $db_ex->getMessage() ) );
				// File backup will proceed without a DB snapshot.
			}
		}

		$f->start( 'auto' );

		wp2pcloudfuncs::set_stored_val( PCLOUD_HAS_ACTIVITY, '1' );

	} else {

		wp2pclouddebugger::log( 'wp2pcl_perform_auto_backup() - op:processor !' );

		wp2pcl_event_processor();

	}
}


/**
 * Auto-backup hook function
 *
 * @throws Exception Standart exception will be thrown.
 */
function wp2pcl_run_pcloud_backup_hook(): void {

	$lastbackupdt_tm = intval( wp2pcloudfuncs::get_stored_val( PCLOUD_LAST_BACKUPDT ) );

	$freq        = wp2pcloudfuncs::get_stored_val( PCLOUD_SCHDATA_KEY );
	$after_hour  = wp2pcloudfuncs::get_stored_val( PCLOUD_SCHHOUR_FROM_KEY );
	$before_hour = wp2pcloudfuncs::get_stored_val( PCLOUD_SCHHOUR_TO_KEY );

	$rejected       = false;
	$reject_reasons = array(); // diagnostic — surfaced in debug log

	if ( $lastbackupdt_tm > 0 ) {

		if ( '2_minute' === $freq ) {
			if ( $lastbackupdt_tm > ( time() - 120 ) ) {
				$rejected         = true;
				$reject_reasons[] = 'freq=2_minute, last=' . $lastbackupdt_tm . ' < 120s ago';
			}
		} elseif ( '1_hour' === $freq ) {
			if ( $lastbackupdt_tm > ( time() - 3600 ) ) {
				$rejected         = true;
				$reject_reasons[] = 'freq=1_hour, last=' . $lastbackupdt_tm . ' < 1h ago';
			}
		} elseif ( '4_hours' === $freq ) {
			if ( $lastbackupdt_tm > ( time() - ( 3600 * 4 ) ) ) {
				$rejected         = true;
				$reject_reasons[] = 'freq=4_hours, last=' . $lastbackupdt_tm . ' < 4h ago';
			}
		} elseif ( 'daily' === $freq ) {
			if ( $lastbackupdt_tm > ( time() - 86400 ) ) {
				$rejected         = true;
				$reject_reasons[] = 'freq=daily, last=' . $lastbackupdt_tm . ' < 24h ago';
			}
		} elseif ( 'weekly' === $freq ) {
			if ( $lastbackupdt_tm > strtotime( '-1 week' ) ) {
				$rejected         = true;
				$reject_reasons[] = 'freq=weekly, last=' . $lastbackupdt_tm . ' < 1w ago';
			}
		} elseif ( 'monthly' === $freq ) {
			if ( $lastbackupdt_tm > strtotime( '-1 month' ) ) {
				$rejected         = true;
				$reject_reasons[] = 'freq=monthly, last=' . $lastbackupdt_tm . ' < 1mo ago';
			}
		} else {
			$rejected         = true;
			$reject_reasons[] = 'unexpected freq value: ' . $freq;
		}
	}

	$current_hour = intval( gmdate( 'H' ) );
	$after_hour   = intval( $after_hour );
	$before_hour  = intval( $before_hour );

	if ( $after_hour >= 0 && $current_hour < $after_hour ) {
		$rejected         = true;
		$reject_reasons[] = 'hour_window: current_hour=' . $current_hour . ' < after_hour=' . $after_hour;
	}
	if ( $before_hour >= 0 && $current_hour >= $before_hour ) {
		$rejected         = true;
		$reject_reasons[] = 'hour_window: current_hour=' . $current_hour . ' >= before_hour=' . $before_hour;
	}

	$operation = wp2pcloudfuncs::get_operation();

	// If there's an auto backup already in progress (any state other than 'nothing'),
	// always let it continue — even if the frequency/hour-window check says "not yet".
	$auto_in_progress = isset( $operation['operation'] )
		&& 'nothing' !== $operation['operation']
		&& isset( $operation['mode'] )
		&& 'auto' === $operation['mode'];

	if ( $auto_in_progress ) {
		wp2pclouddebugger::log( 'cron_hook() - auto backup in progress (state=' . ( $operation['state'] ?? '?' ) . '), continuing.' );
		wp2pcl_perform_auto_backup();
		return;
	}

	if ( $rejected ) {
		return;
	}

	wp2pclouddebugger::log(
		'cron_hook() - PASSED all gates. freq=' . $freq
		. ' last_backup=' . $lastbackupdt_tm
		. ' (' . ( $lastbackupdt_tm > 0 ? gmdate( 'Y-m-d H:i:s', $lastbackupdt_tm ) : 'never' ) . ')'
		. ' hours=' . $after_hour . '-' . $before_hour
		. ' current_hour=' . $current_hour
		. ' op=' . ( $operation['operation'] ?? '?' )
	);

	if ( isset( $operation['operation'] ) && ( 'nothing' === $operation['operation'] ) ) {

		wp2pclouddebugger::log( 'wp2pcl_run_pcloud_backup_hook() - op:nothing, going to init !' );

		$op_data = array(
			'operation'  => 'upload',
			'state'      => 'init',
			'mode'       => 'auto',
			'status'     => '',
			'chunkstate' => 'OK',
			'failures'   => 0,
			'folder_id'  => 0,
			'offset'     => 0,
		);

		$json_data = wp_json_encode( $op_data );

		wp2pcloudfuncs::set_stored_val( 'wp2pcl_operation', $json_data );

		if ( ! wp_next_scheduled( 'init_autobackup', wp2pcl_cron_args() ) ) { // This will always be false.
			wp_schedule_event( time(), '2_minute', 'init_autobackup', wp2pcl_cron_args() );
		}
	} else {

		wp2pclouddebugger::log( 'wp2pcl_run_pcloud_backup_hook() - uploading... ' );

		wp2pcl_perform_auto_backup();
	}
}

/**
 * This function calls the settings page file and loads some JS and CSS files
 *
 * @throws Exception Standart exception will be thrown.
 * @noinspection PhpUnused
 */
function wp2pcloud_display_settings(): void {

	if ( ! extension_loaded( 'zip' ) ) {
		print( '<h2 style="color: red">PHP ZIP extension not loaded</h2><small>Please, contact the server administrator!</small>' );
		return;
	}

	$do         = '';
	$auth_key   = '';
	$locationid = 1;

	if ( isset( $_GET['do'] ) ) { // phpcs:ignore
		$do = sanitize_text_field( wp_unslash( $_GET['do'] ) ); // phpcs:ignore
	}
	if ( isset( $_GET['access_token'] ) ) { // phpcs:ignore
		$auth_key = trim( sanitize_text_field( wp_unslash( $_GET['access_token'] ) ) ); // phpcs:ignore
	}
	if ( isset( $_GET['locationid'] ) ) { // phpcs:ignore
		$locationid = intval( sanitize_key( wp_unslash( $_GET['locationid'] ) ) ); // phpcs:ignore
	}

	$oauth_nonce = '';
	if ( isset( $_GET['wp2pcl_oauth'] ) ) { // phpcs:ignore
		$oauth_nonce = sanitize_key( wp_unslash( $_GET['wp2pcl_oauth'] ) ); // phpcs:ignore
	}

	if ( ( 'pcloud_auth' === $do ) && ! empty( $auth_key ) ) {

		// CSRF protection (CVE-2026-57757). The pCloud token returns via a GET redirect,
		// so it cannot carry a request nonce directly — we carry one through the OAuth
		// `state` parameter (set in views/wp2pcl-config.php) and verify it before storing.
		if ( ! wp_verify_nonce( $oauth_nonce, 'wp2pcl_oauth' ) ) {

			wp2pclouddebugger::log( 'OAuth callback rejected: state could not be verified.' );
			print '<h2 style="color: red;text-align: center" class="wp2pcloud-login-failed">Login could not be verified. Please start the pCloud connection again from this page.</h2>';

		} else {

			if ( $locationid > 0 && $locationid < 100 ) {
				wp2pcloudfuncs::set_stored_val( PCLOUD_API_LOCATIONID, $locationid );
				$result['status'] = 0;
			}

			wp2pcloudfuncs::set_stored_val( PCLOUD_AUTH_KEY, $auth_key );

			pcl_verify_directory_structure();

			print '<h2 style="color: green;text-align: center" class="wp2pcloud-login-succcess">You are successfully logged in!</h2>';
		}
	}

	$static_files_ver = '2.0.0.1';

	wp_enqueue_script( 'wp2pcl-scr', plugins_url( '/assets/js/wp2pcl.js', __FILE__ ), array(), $static_files_ver, true );
	wp_enqueue_style( 'wpb2pcloud', plugins_url( '/assets/css/wpb2pcloud.css', __FILE__ ), array(), $static_files_ver );

	$data = array(
		'blog_name'         => get_bloginfo( 'name' ),
		'blog_url'          => get_bloginfo( 'url' ),
		'archive_icon'      => plugins_url( '/assets/img/zip.png', __FILE__ ),
		'api_hostname'      => wp2pcloudfuncs::get_api_ep_hostname(),
		'PCLOUD_BACKUP_DIR' => PCLOUD_BACKUP_DIR,
	);

	wp_localize_script( 'wp2pcl-scr', 'php_data', $data );

	$plugin_path = plugins_url( '/', __FILE__ );

	include 'views/wp2pcl-config.php';
}

/**
 * This function will be called after the plugins is installed
 *
 * @return void
 * @noinspection PhpUnused
 */
function wp2pcl_install(): void {

	global $max_num_failures;

	wp2pcloudfuncs::get_stored_val( PCLOUD_API_LOCATIONID, '1' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_AUTH_KEY );
	wp2pcloudfuncs::get_stored_val( PCLOUD_AUTH_MAIL );
	wp2pcloudfuncs::get_stored_val( PCLOUD_SCHDATA_KEY, 'daily' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_SCHHOUR_FROM_KEY, '-1' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_SCHHOUR_TO_KEY, '-1' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_SCHDATA_INCLUDE_MYSQL, '1' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_OPERATION );
	wp2pcloudfuncs::get_stored_val( PCLOUD_HAS_ACTIVITY, '0' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_LOG );
	wp2pcloudfuncs::get_stored_val( PCLOUD_DBG_LOG );
	wp2pcloudfuncs::get_stored_val( PCLOUD_NOTIFICATIONS );
	wp2pcloudfuncs::get_stored_val( PCLOUD_LAST_BACKUPDT, strval( time() ) );
	wp2pcloudfuncs::get_stored_val( PCLOUD_QUOTA, '1' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_USEDQUOTA, '1' );
	wp2pcloudfuncs::get_stored_val( PCLOUD_MAX_NUM_FAILURES_NAME, strval( $max_num_failures ) );
	wp2pcloudfuncs::get_stored_val( PCLOUD_ASYNC_UPDATE_VAL );
	wp2pcloudfuncs::get_stored_val( PCLOUD_BACKUP_FILE_INDEX );
	// Note: PCLOUD_OAUTH_CLIENT_ID, PCLOUD_TEMP_DIR and PCLOUD_PLUGIN_MIN_PHP_VERSION are
	// constant *values* (not option names). Passing them into get_stored_val used to create
	// junk rows in wp_options keyed by the value itself (e.g. "beFbFDM0paj"). No longer done.

	add_filter(
		'cron_schedules',
		function ( $schedules ) {
			$schedules['10_sec']   = array(
				'interval' => 10,
				'display'  => __( '10 seconds' ),
			);
			$schedules['2_minute'] = array(
				'interval' => 120,
				'display'  => __( '2 minute' ),
			);
			$schedules['1_hour']   = array(
				'interval' => 3600,
				'display'  => __( '1 hour' ),
			);
			$schedules['4_hours']  = array(
				'interval' => 3600 * 4,
				'display'  => __( '4 hours' ),
			);

			return $schedules;
		}
	);

	wp_schedule_event( time(), '2_minute', 'init_autobackup', wp2pcl_cron_args() );

	WP2PcloudRatingPrompt::on_activate();
}

/**
 * Deactivation hook. Stops scheduled backups but preserves the user's configuration
 * (OAuth token, schedule, retention settings, etc.) so reactivation is transparent.
 *
 * Previously this wiped every plugin option. That turned any troubleshooting deactivation
 * into a full re-setup including re-authentication.
 *
 * @return void
 * @noinspection PhpUnused
 */
function wp2pcl_deactivate(): void {
	wp_clear_scheduled_hook( 'init_autobackup', wp2pcl_cron_args() );
	spl_autoload_unregister( '\Pcloud\Autoloader::loader' );
}

/**
 * Uninstall hook. Runs only when the admin explicitly deletes the plugin.
 * This is the correct place to wipe every plugin option.
 *
 * @return void
 * @noinspection PhpUnused
 */
function wp2pcl_uninstall(): void {

	delete_option( PCLOUD_API_LOCATIONID );
	delete_option( PCLOUD_AUTH_KEY );
	delete_option( PCLOUD_AUTH_MAIL );
	delete_option( PCLOUD_SCHDATA_KEY );
	delete_option( PCLOUD_SCHHOUR_FROM_KEY );
	delete_option( PCLOUD_SCHHOUR_TO_KEY );
	delete_option( PCLOUD_SCHDATA_INCLUDE_MYSQL );
	delete_option( PCLOUD_OPERATION );
	delete_option( PCLOUD_HAS_ACTIVITY );
	delete_option( PCLOUD_LOG );
	delete_option( PCLOUD_DBG_LOG );
	delete_option( PCLOUD_NOTIFICATIONS );
	delete_option( PCLOUD_LAST_BACKUPDT );
	delete_option( PCLOUD_MAX_NUM_FAILURES_NAME );
	delete_option( PCLOUD_QUOTA );
	delete_option( PCLOUD_USEDQUOTA );
	delete_option( PCLOUD_ASYNC_UPDATE_VAL );
	delete_option( PCLOUD_BACKUP_FILE_INDEX );
	delete_option( 'wp2pcl_plugin_version' );
	WP2PcloudRatingPrompt::on_uninstall();
	wp_clear_scheduled_hook( 'init_autobackup', wp2pcl_cron_args() );
}

/**
 * This func creates
 *
 * @param array|null $schedules Array of previews schedules.
 *
 * @return array
 * @noinspection PhpUnused
 */
function backup_to_pcloud_cron_schedules( ?array $schedules ): array {

	$new_schedules = array(
		'2_minute' => array(
			'interval' => 120,
			'display'  => __( '2 minute' ),
		),
		'1_hour'   => array(
			'interval' => 3600,
			'display'  => __( '1 hour' ),
		),
		'4_hours'  => array(
			'interval' => 3600 * 4,
			'display'  => __( '4 hours' ),
		),
		'daily'    => array(
			'interval' => 86400,
			'display'  => __( 'Daily' ),
		),
		'weekly'   => array(
			'interval' => 604800,
			'display'  => __( 'Weekly' ),
		),
		'monthly'  => array(
			'interval' => 2592000,
			'display'  => __( 'Monthly' ),
		),
	);

	return array_merge( (array) $schedules, $new_schedules );
}

/**
 * Verify that the folder exists on pCloud servers.
 *
 * @return void
 */
function pcl_verify_directory_structure(): void {

	$authkey = wp2pcloudfuncs::get_stored_val( PCLOUD_AUTH_KEY );
	if ( ! is_string( $authkey ) || empty( $authkey ) ) {
		return;
	}

	$hostname = wp2pcloudfuncs::get_api_ep_hostname();
	if ( empty( $hostname ) ) {
		return;
	}

	$backup_file_index = wp2pcloudfuncs::get_stored_val( PCLOUD_BACKUP_FILE_INDEX );
	if ( empty( $backup_file_index ) ) {
		$backup_file_index = time();
		wp2pcloudfuncs::set_stored_val( PCLOUD_BACKUP_FILE_INDEX, $backup_file_index );
	}

	$apiep = 'https://' . $hostname;
	$url   = $apiep . '/listfolder?path=/' . PCLOUD_BACKUP_DIR . '&access_token=' . $authkey;

	$response = wp_remote_get( $url );
	if ( is_array( $response ) && ! is_wp_error( $response ) ) {
		$response_body_list = json_decode( $response['body'] );
		if ( is_object( $response_body_list ) && property_exists( $response_body_list, 'result' ) ) {
			$resp_result = intval( $response_body_list->result );
			if ( 2005 === $resp_result ) {

				$backup_directories = explode( '/', PCLOUD_BACKUP_DIR );

				if ( is_array( $backup_directories ) && 0 < count( $backup_directories ) ) {
					$url                       = $apiep . '/createfolder?path=/' . $backup_directories[0] . '&name=' . $backup_directories[0] . '&access_token=' . $authkey;
					$response_main_folder      = wp_remote_get( $url );
					$response_main_folder_body = is_array( $response_main_folder ) && ! is_wp_error( $response_main_folder )
						? json_decode( wp_remote_retrieve_body( $response_main_folder ) )
						: null;
					if ( is_object( $response_main_folder_body ) && property_exists( $response_main_folder_body, 'result' ) && ( 0 === intval( $response_main_folder_body->result ) ) ) {
						$url = $apiep . '/createfolder?path=/' . PCLOUD_BACKUP_DIR . '&name=' . $backup_directories[1] . '&access_token=' . $authkey;
						wp_remote_get( $url );
					}
				}
			}
		}
	}
}

add_filter( 'cron_schedules', 'backup_to_pcloud_cron_schedules' );

if ( ! function_exists( 'pcloud_plugin_check' ) ) {

	/**
	 * Check if the PHP version is compatible with the plugin.
	 *
	 * @return void
	 */
	function pcloud_plugin_check(): void {
		if ( version_compare( PHP_VERSION, PCLOUD_PLUGIN_MIN_PHP_VERSION, '<' ) ) {
			// Deactivate the plugin if the current PHP version is lower than the required.
			deactivate_plugins( plugin_basename( __FILE__ ) );
			// Display an error message to the admin.
			add_action( 'admin_notices', 'pcloud_plugin_php_version_error' );
		}

		$current_limit = WP2PcloudFuncs::get_memory_limit();
		if ( $current_limit < 64 ) {
			add_action( 'admin_notices', 'pcloud_plugin_php_memory_limit_error' );
		}
	}
}

/**
 * Error notice for admins if PHP version is too low.
 */
if ( ! function_exists( 'pcloud_plugin_php_version_error' ) ) {

	/**
	 * Function to display an error message if the PHP version is too low.
	 *
	 * @return void
	 */
	function pcloud_plugin_php_version_error(): void {
		$message = sprintf(
			'[pCloud WP Backup] Your PHP version is %s, but the Your Plugin Name requires at least PHP %s to run. Please update PHP or contact your hosting provider for assistance.',
			PHP_VERSION,
			PCLOUD_PLUGIN_MIN_PHP_VERSION
		);
		printf( '<div class="error"><p>%s</p></div>', esc_html( $message ) );
	}
}

/**
 * Error notice for admins if PHP Memory Limit is too low.
 */
if ( ! function_exists( 'pcloud_plugin_php_memory_limit_error' ) ) {

	/**
	 * Function to display an error message if the PHP memory limit is too low.
	 *
	 * @return void
	 */
	function pcloud_plugin_php_memory_limit_error(): void {
		$current_limit = WP2PcloudFuncs::get_memory_limit();
		$message       = sprintf(
			"[pCloud WP Backup] Your PHP 'memory_limit' setting is currently too low at [ %dM ]; it must be at least 64Mb for the plugin to function properly.",
			$current_limit
		);
		printf( '<div class="error"><p>%s</p></div>', esc_html( $message ) );
	}
}

/**
 * Run any pending upgrade routines. WordPress does NOT fire the activation hook on
 * plugin updates — only on explicit activate. So anything that must happen once per
 * new version (cron re-registration, new options seeding) goes here.
 *
 * @return void
 */
function wp2pcl_maybe_upgrade(): void {

	$version_key     = 'wp2pcl_plugin_version';
	$current_version = '2.0.8';
	$stored_version  = get_option( $version_key, '' );

	if ( $stored_version === $current_version ) {
		return; // Already up to date — nothing to do.
	}

	// --- Ensure cron is registered (may have been lost on deactivate/reactivate
	//     during an older version, or simply never existed for fresh upgrades). ---
	$cron_was_missing = false;
	if ( ! wp_next_scheduled( 'init_autobackup', wp2pcl_cron_args() ) ) {
		wp_schedule_event( time(), '2_minute', 'init_autobackup', wp2pcl_cron_args() );
		$cron_was_missing = true;
	}

	// --- Seed options introduced in 2.0.2 ---
	WP2PcloudRatingPrompt::on_activate();

	// --- 2.0.4 security: harden the working directories on sites that already have
	//     them from a pre-patch version, so any leftover archive stops being
	//     web-downloadable immediately on upgrade (CVE-2026-14503). ---
	wp2pcloudfuncs::harden_dir( plugin_dir_path( __FILE__ ) . 'tmp' );
	wp2pcloudfuncs::harden_dir( PCLOUD_TEMP_DIR );

	// --- Mark as upgraded ---
	update_option( $version_key, $current_version, true );

	// Diagnostic — visible in the debug panel on the plugin page.
	wp2pclouddebugger::log(
		'wp2pcl_maybe_upgrade() - upgraded to ' . $current_version
		. ' (from ' . ( $stored_version ?: 'none' ) . ')'
		. ( $cron_was_missing ? ' — cron was MISSING, re-registered init_autobackup' : ' — cron already scheduled' )
		. ' — next run: ' . ( wp_next_scheduled( 'init_autobackup', wp2pcl_cron_args() ) ?: 'FAILED' )
	);
}

/**
 * Ensure init_autobackup is always registered.
 *
 * wp2pcl_maybe_upgrade() only re-schedules cron on a version change. Once the
 * stored version matches, that path is a no-op — and the plugin has no way to
 * recover if init_autobackup has been cleared since (deactivate/reactivate of
 * another plugin, conflict with a cron-management tool, direct DB edit). This
 * runs on every admin pageview, so a missing event is restored on the next
 * admin visit and WP core's spawn_cron() then fires it normally.
 *
 * Cost: one autoloaded option read per admin pageview (already in cache).
 *
 * @return void
 */
function wp2pcl_ensure_cron_scheduled(): void {

	if ( wp_doing_ajax() || wp_doing_cron() || wp_installing() ) {
		return;
	}

	if ( false === wp_next_scheduled( 'init_autobackup', wp2pcl_cron_args() ) ) {
		wp_schedule_event( time(), '2_minute', 'init_autobackup', wp2pcl_cron_args() );
		wp2pclouddebugger::log( 'wp2pcl_ensure_cron_scheduled() - init_autobackup was missing, re-registered' );
	}
}

// Hook into 'admin_init' to check PHP version and run upgrades as early as possible.
add_action( 'admin_init', 'wp2pcl_maybe_upgrade' );
add_action( 'admin_init', 'wp2pcl_ensure_cron_scheduled' );
add_action( 'admin_init', 'pcloud_plugin_check' );

register_activation_hook( __FILE__, 'wp2pcl_install' );
register_deactivation_hook( __FILE__, 'wp2pcl_deactivate' );
register_uninstall_hook( __FILE__, 'wp2pcl_uninstall' );
add_action( 'admin_menu', 'backup_to_pcloud_admin_menu' );
add_action( 'init_autobackup', 'wp2pcl_run_pcloud_backup_hook' );
if ( is_admin() ) {
	add_action( 'wp_ajax_pcloudbackup', 'wp2pcl_ajax_process_request' );
	add_action( 'wp_ajax_wp2pcl_rating_dismiss', array( WP2PcloudRatingPrompt::class, 'handle_ajax' ) );
	add_action( 'admin_notices', array( WP2PcloudRatingPrompt::class, 'maybe_render' ) );
}

if ( ! function_exists( 'debug_wp_remote_post_and_get_request' ) ) :
	/**
	 * Debug hook for pCloud HTTP calls. Redacts access_token from URL/body before logging.
	 *
	 * @param mixed  $response HTTP response or WP_Error.
	 * @param string $context  Context.
	 * @param string $class    Transport class.
	 * @param array  $request  Request args.
	 * @param string $url      Request URL.
	 */
	function debug_wp_remote_post_and_get_request( $response, $context, $class, $request, $url ): void {

		if ( ! PCLOUD_DEBUG || ! str_contains( $url, 'pcloud' ) ) {
			return;
		}

		$redact = static function ( $value ) {
			if ( is_string( $value ) ) {
				return preg_replace( '/access_token=[^&\s"\']+/i', 'access_token=REDACTED', $value );
			}
			if ( is_array( $value ) ) {
				return array_map( static function ( $v ) {
					return is_string( $v ) ? preg_replace( '/access_token=[^&\s"\']+/i', 'access_token=REDACTED', $v ) : $v;
				}, $value );
			}
			return $value;
		};

		$safe_url      = $redact( $url );
		$safe_request  = $redact( $request );
		$safe_response = is_array( $response ) ? $response : (string) $response;
		$safe_response = $redact( $safe_response );

		error_log( '------------------------------------------------------------------------------------------' );
		error_log( 'URL: ' . $safe_url );
		error_log( 'Request: ' . wp_json_encode( $safe_request ) );
		error_log( 'Response: ' . wp_json_encode( $safe_response ) );
	}
	add_action( 'http_api_debug', 'debug_wp_remote_post_and_get_request', 10, 5 );
endif;