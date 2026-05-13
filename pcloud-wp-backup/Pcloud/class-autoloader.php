<?php
/**
 * Class Autoloader.
 *
 * @file class-autoloader.php
 * @package pcloud_wp_backup
 */

namespace Pcloud;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Cached recursive autoloader.
 *
 * The previous implementation walked the full Pcloud/Classes/ tree on every single class
 * resolution, because the `foreach ($file_iterator as $file)` pattern calls rewind() and
 * restats every file on each call. Loading a dozen classes during a backup meant hundreds
 * of redundant stat syscalls.
 *
 * This revision walks the tree once on first lookup and caches a filename→path map. All
 * subsequent lookups are O(1) array reads. The map supports both naming conventions used
 * in the codebase:
 *   - class-<lowercase_name>.php  (most classes)
 *   - <lowercase_name>.php        (some constants files in ZipFile/Constants/)
 *
 * @noinspection PhpUnused
 */
class Autoloader {

	/**
	 * The topmost directory where recursion should begin.
	 *
	 * @var string
	 */
	protected static string $path_top = __DIR__;

	/**
	 * Lowercase filename (without `class-` prefix and without `.php`) → absolute path.
	 *
	 * Populated lazily on first autoload call via `build_class_map()`.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $class_map = null;

	/**
	 * Autoload handler registered with spl_autoload_register.
	 *
	 * @param string $class_name Fully-qualified class name, e.g. Pcloud\Classes\WP2PcloudFuncs.
	 */
	public static function loader( string $class_name ): void {

		// We only resolve classes under the Pcloud\ namespace. Anything else is another
		// plugin's concern and should not trigger a filesystem scan by us.
		if ( ! str_starts_with( $class_name, 'Pcloud\\' ) ) {
			return;
		}

		if ( null === self::$class_map ) {
			self::build_class_map();
		}

		// Short, lowercase basename without namespace — the key we index by.
		$short = $class_name;
		$pos   = strrpos( $short, '\\' );
		if ( false !== $pos ) {
			$short = substr( $short, $pos + 1 );
		}
		$key = strtolower( $short );

		if ( ! isset( self::$class_map[ $key ] ) ) {
			return;
		}

		$path = self::$class_map[ $key ];
		if ( is_readable( $path ) ) {
			try {
				include_once $path;
			} catch ( Throwable $e ) { // Throwable so ParseError / Error are caught too.
				// Best-effort diagnostic — can't depend on our own debug logger here since
				// it may itself be the class failing to load.
				if ( defined( 'PCLOUD_DEBUG' ) && PCLOUD_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[pCloud autoloader] include_once failed for ' . $path . ': ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Walk `$path_top` once and build the classname → path map.
	 */
	private static function build_class_map(): void {

		self::$class_map = array();

		if ( ! is_dir( self::$path_top ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::$path_top, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			$name = $file->getFilename();
			if ( ! str_ends_with( strtolower( $name ), '.php' ) ) {
				continue;
			}

			// Strip .php, then strip optional class- prefix. Match both forms under the
			// same lookup key so loader() can find either naming style.
			$base = strtolower( substr( $name, 0, -4 ) );
			if ( str_starts_with( $base, 'class-' ) ) {
				$base = substr( $base, 6 );
			}

			// First match wins. Duplicate basenames across the tree are not expected; if
			// they ever appear, they are a code-organization bug we'd want to notice.
			if ( ! isset( self::$class_map[ $base ] ) ) {
				self::$class_map[ $base ] = $file->getPathname();
			}
		}
	}

	/**
	 * Override the root path (kept for backward compatibility — used at bottom of file).
	 *
	 * @param string $path Top-level directory.
	 */
	public static function set_path( string $path ): void {
		self::$path_top  = $path;
		self::$class_map = null; // Invalidate cache on path change.
	}
}

Autoloader::set_path( plugin_dir_path( __FILE__ ) . 'Classes/' );

spl_autoload_register( '\Pcloud\Autoloader::loader' );