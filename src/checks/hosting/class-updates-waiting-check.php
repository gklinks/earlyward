<?php
/**
 * B8: plugin, theme or core updates are waiting.
 *
 * Reads the update lists WordPress already stored (no request of its own) and names what is waiting.
 * Outdated software is the most common way in, but pending updates are normal for a day or two, so
 * this is a note, not a warning. Detection only: update through WordPress's Updates screen.
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
 * Updates waiting check.
 */
final class Updates_Waiting_Check implements Check {

	const ID = 'B8';

	const MAX_SHOWN = 40;

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Fields of a stored option as an array. The database layer returns objects as incomplete classes, so they are cast, never read as objects.
	 *
	 * @param mixed $value Value.
	 * @return array<int|string, mixed>
	 */
	private static function fields( $value ): array {
		if ( is_object( $value ) ) {
			return (array) $value;
		}
		return is_array( $value ) ? $value : array();
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
		$names = array();
		foreach ( array(
			'_site_transient_update_plugins' => 'plugin',
			'_site_transient_update_themes'  => 'theme',
		) as $option => $kind ) {
			$response = self::fields( $db->option( $option ) )['response'] ?? array();
			if ( is_array( $response ) ) {
				foreach ( array_keys( $response ) as $key ) {
					$names[] = $kind . ':' . (string) $key;
				}
			}
		}
		$core = self::fields( $db->option( '_site_transient_update_core' ) )['updates'] ?? array();
		if ( is_array( $core ) ) {
			foreach ( $core as $offer ) {
				$offer = self::fields( $offer );
				if ( 'upgrade' === ( $offer['response'] ?? '' ) ) {
					$names[] = 'core:WordPress';
					break;
				}
			}
		}
		if ( empty( $names ) ) {
			return array();
		}
		sort( $names );
		$evidence = array();
		foreach ( array_slice( $names, 0, self::MAX_SHOWN ) as $name ) {
			$evidence[] = new Evidence( 'update', $name, 0, '', array() );
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_HIGH,
				/* translators: %d: number of updates */
				sprintf( _n( '%d update is waiting', '%d updates are waiting', count( $names ), 'earlyward' ), count( $names ) ),
				__( 'Outdated plugins, themes and WordPress itself are the most common way sites are broken into. Updates that wait for a day or two are normal; ones that wait for weeks are a risk.', 'earlyward' ),
				$evidence,
				array(
					__( 'Make a backup, then open Dashboard, Updates, and update the items listed. Update one at a time if the site is business critical, and check the home page after each.', 'earlyward' ),
					__( 'Turn on automatic updates for plugins you trust, from the Plugins screen.', 'earlyward' ),
				)
			),
		);
	}
}
