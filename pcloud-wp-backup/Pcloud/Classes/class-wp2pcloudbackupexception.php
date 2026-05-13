<?php
/**
 * WP2PcloudBackupException class
 *
 * @file class-wp2pcloudbackupexception.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

use Exception;

/**
 * Thrown when a backup operation cannot proceed. Caught at the AJAX entry point so the
 * state machine can be reset cleanly and a user-facing error can be surfaced, instead of
 * terminating the PHP worker with die()/exit() mid-request.
 */
class WP2PcloudBackupException extends Exception {}
