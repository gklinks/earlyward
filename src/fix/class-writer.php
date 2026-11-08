<?php
/**
 * The only place in Earlyward that changes files on disk. Everything it does is on the list
 * in docs/WRITES.md, and only the Fixer and the Quarantine call it, after the user has confirmed.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Deliberate, confirmed, logged changes; WP_Filesystem may ask for credentials and has no chmod-and-verify.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Failures are reported through the return values.

/**
 * File writer.
 */
class Writer {

	/**
	 * Change a file's permission bits.
	 *
	 * @param string $path Absolute path.
	 * @param int    $mode New mode, for example 0600.
	 * @return bool
	 */
	public function chmod_file( string $path, int $mode ): bool {
		return @chmod( $path, $mode );
	}

	/**
	 * Delete one file.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete_file( string $path ): bool {
		return @unlink( $path );
	}

	/**
	 * Move a file or a folder. Both ends are on the same disk (inside the site), so this is a rename.
	 *
	 * @param string $from Absolute source.
	 * @param string $to   Absolute target, which must not exist.
	 * @return bool
	 */
	public function move( string $from, string $to ): bool {
		if ( file_exists( $to ) || is_link( $to ) ) {
			return false;
		}
		return @rename( $from, $to );
	}

	/**
	 * Create a folder (and its parents).
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function make_dir( string $path ): bool {
		return is_dir( $path ) || @mkdir( $path, 0755, true );
	}

	/**
	 * Write a small file (the quarantine folder's guard files and manifest).
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Contents.
	 * @return bool
	 */
	public function put_file( string $path, string $contents ): bool {
		return false !== @file_put_contents( $path, $contents );
	}

	/**
	 * Delete a folder and everything in it. Links are removed, never followed.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete_dir( string $path ): bool {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			return @unlink( $path );
		}
		$names = @scandir( $path );
		if ( false === $names ) {
			return false;
		}
		$ok = true;
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			$ok    = ( ( is_dir( $child ) && ! is_link( $child ) ) ? $this->delete_dir( $child ) : @unlink( $child ) ) && $ok;
		}
		return @rmdir( $path ) && $ok;
	}
}
