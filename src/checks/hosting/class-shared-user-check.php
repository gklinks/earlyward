<?php
/**
 * B1: other WordPress sites readable by this site's PHP.
 *
 * Real case: every site on one hosting account ran as the same Linux user. One infected site
 * read every other site's wp-config.php, took its database password, and re-infected it.
 * Rotating passwords changed nothing, and cleaning one site at a time could never work.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Hosting;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Shared user check.
 */
final class Shared_User_Check implements Check {

	const ID = 'B1';

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
		$open_basedir = '' !== $ctx->ini( 'open_basedir' );
		$siblings     = $ctx->fs()->readable_sibling_installs( $ctx->root(), $open_basedir );
		if ( empty( $siblings ) ) {
			return array();
		}

		$evidence = array();
		foreach ( $siblings as $location ) {
			$evidence[] = new Evidence( 'site', $location, 0, $location . '/wp-config.php is readable from this site' );
		}

		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_HIGH,
				1 === count( $siblings )
					? 'Another WordPress install can be read by this site'
					: sprintf( '%d other WordPress installs can be read by this site', count( $siblings ) ),
				'These sites run as the same system user, so code running on any one of them can read the others\' database passwords and change their files. A break-in on one site becomes a break-in on all of them, and cleaning them one at a time does not work.',
				$evidence,
				array(
					'Ask your host whether each site can run as its own system user (or in its own PHP pool), or move important sites to their own hosting account.',
					'Delete sites you no longer use: abandoned installs are the most common way in, and here they expose every other site.',
					'Until the sites are separated, treat them as one: if one shows signs of infection, check and clean all of them in the same pass.',
					'Ask your host to set open_basedir per site if separate users are not possible. It is weaker, but it stops casual cross-site reads.',
				)
			),
		);
	}
}
