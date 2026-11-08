<?php
/**
 * D1: readme.html and license.txt in the web root are publicly served.
 *
 * They state the exact WordPress version, which helps an attacker pick an exploit. The risk is small
 * (the version is also easy to learn elsewhere), so this is a low-confidence note. A file only counts
 * when it exists on disk and the site's own front end serves it, which rules out catch-all pages.
 * Detection only. WordPress puts the files back on every core update, so a deletion does not last.
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
 * Disclosure files check.
 */
final class Disclosure_Files_Check implements Check {

	const ID = 'D1';

	const FILES = array( 'readme.html', 'license.txt' );

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
		$loopback = $ctx->loopback();
		if ( null === $loopback ) {
			return array();
		}
		$evidence = array();
		foreach ( self::FILES as $file ) {
			if ( ! $ctx->fs()->is_file( $ctx->root() . '/' . $file ) ) {
				continue;
			}
			$head = $loopback->head( '/' . $file );
			if ( 200 === $head['status'] ) {
				$evidence[] = new Evidence( 'url', '/' . $file, 0, '', array( 'bytes' => $ctx->fs()->size( $ctx->root() . '/' . $file ) ) );
			}
		}
		if ( empty( $evidence ) ) {
			return array();
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_LOW,
				__( 'WordPress files that reveal the exact version are public', 'earlyward' ),
				__( 'readme.html and license.txt state the WordPress version, which helps an attacker choose an exploit. The risk is small, and keeping the site updated matters far more.', 'earlyward' ),
				$evidence,
				array(
					__( 'Block these two files in your web server or Cloudflare rules, or delete them. WordPress restores them on every core update, so a rule lasts longer than a deletion.', 'earlyward' ),
					__( 'Keep WordPress updated: that is the real protection.', 'earlyward' ),
				)
			),
		);
	}
}
