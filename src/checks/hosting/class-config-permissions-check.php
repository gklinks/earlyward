<?php
/**
 * B4: wp-config.php that other accounts on the server can read or change.
 *
 * It holds the database password and the keys. Most hosts keep accounts apart, so a readable
 * file is usually harmless and is reported as a note; a file other accounts can write is not.
 * This does not help against sites that share one system user (see B1): they can read it anyway.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Hosting;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Config permissions check.
 */
final class Config_Permissions_Check implements Check {

	const ID = 'B4';

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$mode = $ctx->file_mode( $ctx->root() . '/wp-config.php' );
		if ( null === $mode || 0 === ( $mode & 0006 ) ) {
			return array();
		}
		$writable = 0 !== ( $mode & 0002 );
		$octal    = sprintf( '%04o', $mode );
		$evidence = new Evidence( 'file', 'wp-config.php', 0, 'permissions ' . $octal, array( 'mode' => $octal ) );
		$steps    = array(
			__( 'Set the file to owner-only access (chmod 600 wp-config.php, or 640 if your host needs group access), then load the site to confirm it still works.', 'earlyward' ),
			__( 'This does not separate sites that share one system user: see the shared-hosting finding for that.', 'earlyward' ),
		);

		if ( $writable ) {
			return array(
				new Finding(
					self::ID,
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_HIGH,
					__( 'wp-config.php can be changed by any account on the server', 'earlyward' ),
					__( 'The file that holds your database password and keys is writable by everyone. Another account on the same server could change it, for example to load its own code on every request.', 'earlyward' ),
					array( $evidence ),
					$steps
				),
			);
		}

		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_LOW,
				__( 'wp-config.php can be read by other accounts on the server', 'earlyward' ),
				__( 'The file holds your database password and keys. Most hosts keep accounts apart, so this is often harmless, but owner-only access costs nothing and closes the gap on hosts that do not.', 'earlyward' ),
				array( $evidence ),
				$steps
			),
		);
	}
}
