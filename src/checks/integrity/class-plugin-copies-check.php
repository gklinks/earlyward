<?php
/**
 * C16: renamed plugin copies (for example plugin.bak-20260809) inside wp-content/plugins.
 *
 * WordPress no longer lists a renamed folder, so nobody updates it, yet its PHP files can still be
 * requested from the web, and an old copy often has a known flaw. Detection only; delete the folder
 * with your host's file manager.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Integrity;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin copies check.
 */
final class Plugin_Copies_Check implements Check {

	const ID = 'C16';

	const COPY_NAME   = '/(\.(bak|old|orig|backup|disabled|copy)([.\-_0-9]|$)|[\-_](bak|old|orig|disabled|copy|backup)[\-_.]?\d[\d\-_.]*$)/i';
	const MAX_SHOWN   = 40;
	const MAX_ENTRIES = 500;

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
		$fs      = $ctx->fs();
		$plugins = $ctx->content_dir() . '/plugins';
		$copies  = array();
		foreach ( $fs->list_dir( $plugins, self::MAX_ENTRIES ) as $name ) {
			if ( $fs->is_dir( $plugins . '/' . $name ) && ! is_link( $plugins . '/' . $name ) && 1 === preg_match( self::COPY_NAME, $name ) ) {
				$copies[] = $name;
			}
		}
		if ( empty( $copies ) ) {
			return array();
		}
		$evidence = array();
		foreach ( array_slice( $copies, 0, self::MAX_SHOWN ) as $name ) {
			$evidence[] = new Evidence( 'file', 'wp-content/plugins/' . $name, 0, '', array() );
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				/* translators: %d: number of folders */
				sprintf( _n( '%d renamed plugin copy is still on the server', '%d renamed plugin copies are still on the server', count( $copies ), 'earlyward' ), count( $copies ) ),
				__( 'WordPress does not list a plugin folder that was renamed (for example to .bak), so nobody updates it, yet its PHP files can still be requested from the web. An old copy often has a known security flaw.', 'earlyward' ),
				$evidence,
				array(
					__( 'Check that you do not need the folder, then delete it with your host\'s file manager or FTP.', 'earlyward' ),
					__( 'If you want a backup of the old version, keep it outside the website folder, not inside wp-content/plugins.', 'earlyward' ),
				)
			),
		);
	}
}
