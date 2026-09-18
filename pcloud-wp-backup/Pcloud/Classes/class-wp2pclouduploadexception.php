<?php
/**
 * WP2PcloudUploadException class
 *
 * @file class-wp2pclouduploadexception.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

use Exception;

/**
 * Thrown by the chunk-upload routines when a file cannot be pushed further.
 *
 * Carries two things the event processor needs to recover instead of wedging:
 *  - the byte offset the upload actually reached before failing, so progress made
 *    inside a long-running upload() call is persisted rather than discarded;
 *  - whether the pCloud upload session is unusable (e.g. pCloud keeps rejecting every
 *    offset with 2068 and upload_info cannot tell us where it is), in which case the
 *    caller should open a fresh session and restart the file from zero.
 */
class WP2PcloudUploadException extends Exception {

	/**
	 * Offset reached before the failure.
	 *
	 * @var int
	 */
	private int $reached_offset;

	/**
	 * True when the upload session can no longer be resumed.
	 *
	 * @var bool
	 */
	private bool $session_unusable;

	/**
	 * Constructor.
	 *
	 * @param string $message          Human readable message.
	 * @param int    $reached_offset   Byte offset reached before the failure.
	 * @param bool   $session_unusable Whether the upload session must be abandoned.
	 * @param int    $code             pCloud result code, if any.
	 */
	public function __construct( string $message, int $reached_offset, bool $session_unusable = false, int $code = 0 ) {
		parent::__construct( $message, $code );
		$this->reached_offset   = $reached_offset;
		$this->session_unusable = $session_unusable;
	}

	/**
	 * Offset the upload reached before failing.
	 *
	 * @return int
	 */
	public function get_reached_offset(): int {
		return $this->reached_offset;
	}

	/**
	 * Whether the upload session must be abandoned and the file restarted.
	 *
	 * @return bool
	 */
	public function is_session_unusable(): bool {
		return $this->session_unusable;
	}
}
