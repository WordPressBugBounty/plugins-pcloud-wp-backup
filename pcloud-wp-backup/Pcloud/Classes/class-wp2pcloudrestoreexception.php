<?php
/**
 * WP2PcloudRestoreException class
 *
 * @file class-wp2pcloudrestoreexception.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

use Exception;

/**
 * Thrown when a restore operation cannot proceed. Caught at the AJAX entry point so the
 * state machine can be reset cleanly and a user-facing error can be surfaced.
 */
class WP2PcloudRestoreException extends Exception {}
