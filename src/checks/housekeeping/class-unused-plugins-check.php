<?php
/**
 * H3: plugins that are installed but not active.
 *
 * An inactive plugin does not run, but its files stay on the server and a flaw in them can still be
 * reached by URL, and it is rarely updated. Renamed copies (plugin.bak-20260809) are check C16.
 * Read-only: Earlyward offers no delete button here; use WordPress's Plugins screen.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Housekeeping;

use Earlyward\Checks\Check;
use Earlyward\Checks\Integrity\Plugin_Copies_Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Unused plugins check.
 */
final class Unused_Plugins_Check implements Check {

	const ID = 'H3';

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
		$db = $ctx->db();
		if ( null === $db ) {
			return array();
		}
		$active = $db->option( 'active_plugins' );
		if ( ! is_array( $active ) || empty( $active ) ) {
			return array(); // Unknown or empty: cannot tell what is in use.
		}
		$active_dirs = array();
		foreach ( $active as $file ) {
			if ( is_string( $file ) && false !== strpos( $file, '/' ) ) {
				$active_dirs[ strtolower( (string) strtok( $file, '/' ) ) ] = true;
			}
		}

		$fs      = $ctx->fs();
		$plugins = $ctx->content_dir() . '/plugins';
		$idle    = array();
		foreach ( $fs->list_dir( $plugins, self::MAX_ENTRIES ) as $name ) {
			if ( ! $fs->is_dir( $plugins . '/' . $name ) || is_link( $plugins . '/' . $name ) ) {
				continue;
			}
			if ( 1 === preg_match( Plugin_Copies_Check::COPY_NAME, $name ) ) {
				continue; // Reported by C16, with a higher severity.
			}
			if ( ! isset( $active_dirs[ strtolower( $name ) ] ) && $this->has_php( $ctx, $plugins . '/' . $name ) ) {
				$idle[] = $name;
			}
		}

		$findings = array();
		if ( ! empty( $idle ) ) {
			$findings[] = new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_HIGH,
				/* translators: %d: number of plugins */
				sprintf( _n( '%d installed plugin is not active', '%d installed plugins are not active', count( $idle ), 'earlyward' ), count( $idle ) ),
				__( 'Plugins that are switched off still keep their files on the server, still need updates, and can still be a way in if they have a flaw. Remove the ones you do not plan to use.', 'earlyward' ),
				$this->evidence( $idle ),
				array(
					__( 'Open Plugins, Installed Plugins, and delete the inactive plugins you no longer need. Their settings can be kept if you reinstall later.', 'earlyward' ),
					__( 'Keep a backup first if the plugin stored data you might want.', 'earlyward' ),
				)
			);
		}
		return $findings;
	}

	/**
	 * Whether a folder holds PHP at its top level (so it is a plugin, not an empty or asset folder).
	 *
	 * @param Context $ctx Context.
	 * @param string  $dir Folder.
	 * @return bool
	 */
	private function has_php( Context $ctx, string $dir ): bool {
		foreach ( $ctx->fs()->list_dir( $dir, 200 ) as $name ) {
			if ( 1 === preg_match( '/\.php$/i', $name ) && 'index.php' !== strtolower( $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evidence for a list of folders.
	 *
	 * @param string[] $names Folder names.
	 * @return Evidence[]
	 */
	private function evidence( array $names ): array {
		$out = array();
		foreach ( array_slice( $names, 0, self::MAX_SHOWN ) as $name ) {
			$out[] = new Evidence( 'file', 'wp-content/plugins/' . $name, 0, '', array() );
		}
		return $out;
	}
}
