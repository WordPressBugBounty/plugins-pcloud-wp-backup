<?php
/**
 * WP2PcloudLogger class
 *
 * @file class-wp2pcloudlogger.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

/**
 * Class WP2PcloudLogger
 */
class WP2PcloudLogger {

	/**
	 * Generate new log
	 *
	 * @param string|null $initial_message Initial message ot be saved in the log.
	 *
	 * @return void
	 */
	public static function generate_new( ?string $initial_message = '' ): void {

		wp2pcloudfuncs::set_storred_val( PCLOUD_LOG, $initial_message );

	}

	/**
	 * Get last log content
	 *
	 * @param bool $as_json Choose the see the last log data as JSON or raw string.
	 *
	 * @return string
	 */
	public static function read_last_log( ?bool $as_json = true ): string {
		$log = wp2pcloudfuncs::get_storred_val( PCLOUD_LOG );

		if ( $as_json ) {
			return wp_json_encode( array( 'log' => $log ) );
		} else {
			return $log;
		}
	}

	/**
	 * Updates the log records
	 *
	 * @param string|null $new_data String or HTML data to be added to the log.
	 *
	 * @return void
	 */
	public static function info( ?string $new_data ): void {

		$new_data = trim( strip_tags( $new_data, '<span><strong><br><em>' ) );
		if ( strlen( $new_data ) < 2 ) {
			return;
		}

		$current_data = wp2pcloudfuncs::get_storred_val( PCLOUD_LOG );

		$current_data .= '<br/>' . gmdate( 'Y-m-d H:i:s' ) . ' - ' . $new_data;

		wp2pcloudfuncs::set_storred_val( PCLOUD_LOG, $current_data );

	}

	/**
	 * Updates the notification records
	 *
	 * @param string|null $new_message String or HTML data to be added to the log.
	 *
	 * @return void
	 */
	public static function notification( ?string $new_message ): void {

		$new_message = trim( strip_tags( $new_message, '<span><strong><br><em>' ) );
		if ( strlen( $new_message ) < 2 ) {
			return;
		}

		$current_data = wp2pcloudfuncs::get_storred_val( PCLOUD_NOTIFICATIONS );
		if ( empty( $current_data ) ) {
			$current_data = '[]';
		}

		$current_data_arr = json_decode( $current_data, true );
		if ( ! $current_data_arr ) {
			$current_data_arr = array();
		}

		$current_data_arr[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'message' => $new_message,
		);

		if ( count( $current_data_arr ) > 20 ) {
			array_shift( $current_data_arr );
		}

		$store_back_the_data = wp_json_encode( $current_data_arr );

		wp2pcloudfuncs::set_storred_val( PCLOUD_NOTIFICATIONS, $store_back_the_data );
	}

	/**
	 * Clear log method, clears the log data
	 *
	 * @return void
	 */
	public static function clear_log(): void {
		wp2pcloudfuncs::set_storred_val( PCLOUD_LOG, '' );
	}
}
