<?php
/**
 * The only place in Earlyward that changes files on disk. Everything it does is on the list
 * in docs/WRITES.md, and only the Fixer calls it, after the user has confirmed.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Deliberate, confirmed, logged changes; WP_Filesystem may ask for credentials and has no chmod-and-verify.

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
		return @chmod( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failure is reported through the return value.
	}

	/**
	 * Delete one file.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete_file( string $path ): bool {
		return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failure is reported through the return value.
	}
}
