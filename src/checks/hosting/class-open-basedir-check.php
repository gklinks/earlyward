<?php
/**
 * B2: PHP may open files anywhere the account can.
 *
 * Measured only through the web server: the command line often has different settings.
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
 * Checks whether open_basedir is set.
 */
final class Open_Basedir_Check implements Check {

	const ID = 'B2';

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
		if ( $ctx->is_cli() || ! $ctx->has_ini( 'open_basedir' ) || '' !== trim( $ctx->ini( 'open_basedir' ) ) ) {
			return array();
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				'PHP on this site can open files outside the site',
				'open_basedir is not set, so any code running here (including malware) can read everything the hosting account can read. It is a backstop, not a wall, but its absence is what let one compromised site read its neighbours on a real account.',
				array(
					new Evidence(
						'runtime',
						'php:open_basedir',
						0,
						'open_basedir = (empty)',
						array( 'sapi' => $ctx->ini( 'wallwarden.sapi' ) )
					),
				),
				array(
					'Ask your host to set open_basedir for this site to its own folder plus the system temp folder.',
					'If the host offers per-site system users or PHP pools, prefer that: it is a stronger separation.',
				)
			),
		);
	}
}
