<?php
/**
 * Read-only, bounded filesystem access restricted to allowed roots.
 *
 * All direct file access in the plugin goes through this class, so the
 * phpcs exceptions for it live in one place.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Env;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- read-only scanner; WP_Filesystem is for writes and may need credentials.

/**
 * Filesystem reader.
 */
final class Filesystem {

	const DEFAULT_MAX_BYTES   = 65536;
	const DEFAULT_MAX_ENTRIES = 5000;

	/**
	 * Real paths of the directories this reader may touch.
	 *
	 * @var string[]
	 */
	private $roots = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $roots Allowed root directories.
	 */
	public function __construct( array $roots ) {
		foreach ( $roots as $root ) {
			$real = realpath( $root );
			if ( false !== $real && is_dir( $real ) ) {
				$this->roots[] = self::normalise( $real );
			}
		}
	}

	/**
	 * Forward slashes, no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalise( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/**
	 * Whether a path resolves inside an allowed root. Symlinks that escape are refused.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function allowed( string $path ): bool {
		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}
		$real = self::normalise( $real );
		foreach ( $this->roots as $root ) {
			if ( $real === $root || 0 === strpos( $real, $root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is a readable regular file inside the roots.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_file( string $path ): bool {
		return $this->allowed( $path ) && is_file( $path ) && is_readable( $path );
	}

	/**
	 * Is a directory inside the roots.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_dir( string $path ): bool {
		return $this->allowed( $path ) && is_dir( $path );
	}

	/**
	 * File size in bytes, or -1.
	 *
	 * @param string $path Path.
	 * @return int
	 */
	public function size( string $path ): int {
		if ( ! $this->is_file( $path ) ) {
			return -1;
		}
		$size = filesize( $path );
		return false === $size ? -1 : (int) $size;
	}

	/**
	 * Read up to $max_bytes from the start of a file.
	 *
	 * @param string $path      Path.
	 * @param int    $max_bytes Cap.
	 * @return string|null Null when the file cannot be read.
	 */
	public function read( string $path, int $max_bytes = self::DEFAULT_MAX_BYTES ): ?string {
		if ( ! $this->is_file( $path ) ) {
			return null;
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		$data = fread( $handle, max( 1, $max_bytes ) );
		fclose( $handle );
		return false === $data ? null : $data;
	}

	/**
	 * Lines of a file, keyed by 1-based line number, without line endings.
	 *
	 * @param string $path      Path.
	 * @param int    $max_bytes Cap.
	 * @return array<int, string>|null
	 */
	public function lines( string $path, int $max_bytes = self::DEFAULT_MAX_BYTES ): ?array {
		$data = $this->read( $path, $max_bytes );
		if ( null === $data ) {
			return null;
		}
		$split = preg_split( '/\r\n|\r|\n/', $data );
		if ( false === $split ) {
			return null;
		}
		$out = array();
		foreach ( $split as $i => $line ) {
			$out[ $i + 1 ] = (string) $line;
		}
		return $out;
	}

	/**
	 * Entry names in a directory (no . or ..), capped and sorted.
	 *
	 * @param string $path        Directory.
	 * @param int    $max_entries Cap.
	 * @return string[]
	 */
	public function list_dir( string $path, int $max_entries = self::DEFAULT_MAX_ENTRIES ): array {
		if ( ! $this->is_dir( $path ) ) {
			return array();
		}
		$handle = opendir( $path );
		if ( false === $handle ) {
			return array();
		}
		$names = array();
		$found = 0;
		while ( $found < $max_entries ) {
			$name = readdir( $handle );
			if ( false === $name ) {
				break;
			}
			if ( '.' !== $name && '..' !== $name ) {
				$names[] = $name;
				++$found;
			}
		}
		closedir( $handle );
		sort( $names );
		return $names;
	}

	/**
	 * Names of entries inside a zip archive, without extracting anything.
	 *
	 * @param string $path        Zip path.
	 * @param int    $max_entries Cap.
	 * @return string[]|null Null when the archive cannot be inspected.
	 */
	public function zip_entries( string $path, int $max_entries = 2000 ): ?array {
		if ( ! $this->is_file( $path ) || ! class_exists( '\ZipArchive' ) ) {
			return null;
		}
		$zip = new \ZipArchive();
		// No flags: opens an existing archive for reading and never creates one. RDONLY needs PHP 7.4.3+.
		if ( true !== $zip->open( $path ) ) {
			return null;
		}
		$names = array();
		$count = min( $zip->numFiles, $max_entries ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP core property.
		for ( $i = 0; $i < $count; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== $name ) {
				$names[] = $name;
			}
		}
		$zip->close();
		return $names;
	}
}
