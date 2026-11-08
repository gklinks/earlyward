<?php
/**
 * B7: WordPress has not managed to check for updates for days.
 *
 * Core refreshes its update information about twice a day. When the stored timestamps are old,
 * either WP-Cron is not running or this server cannot reach api.wordpress.org (a firewall or a
 * blocked outbound connection). The check reads the timestamps WordPress already stored and makes
 * no request of its own. Quiet when the values are absent (a fresh site, or a site that disables updates).
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
 * Update check health.
 */
final class Update_Check_Health_Check implements Check {

	const ID = 'B7';

	const MAX_AGE_DAYS = 3;

	/**
	 * Transients that carry a last_checked timestamp.
	 */
	const OPTIONS = array(
		'_site_transient_update_core'    => 'WordPress',
		'_site_transient_update_plugins' => 'plugins',
		'_site_transient_update_themes'  => 'themes',
	);

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
		$evidence = array();
		$stale    = 0;
		$seen     = 0;
		foreach ( self::OPTIONS as $option => $label ) {
			$value = $db->option( $option );
			$when  = is_object( $value ) ? ( $value->last_checked ?? null ) : ( is_array( $value ) ? ( $value['last_checked'] ?? null ) : null );
			if ( ! is_numeric( $when ) || (int) $when <= 0 ) {
				continue;
			}
			++$seen;
			$age_days = (int) floor( ( $ctx->now() - (int) $when ) / 86400 );
			if ( $age_days > self::MAX_AGE_DAYS ) {
				++$stale;
				$evidence[] = new Evidence( 'option', $option, 0, gmdate( 'Y-m-d H:i', (int) $when ) . ' UTC', array( 'age_days' => $age_days ) );
			}
		}
		// Every list that was recorded must be stale: one old list can be a plugin that filters it.
		if ( 0 === $stale || $stale < $seen ) {
			return array();
		}

		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				__( 'WordPress has not checked for updates for several days', 'earlyward' ),
				__( 'WordPress asks api.wordpress.org about updates about twice a day. It has not succeeded for days, so security updates may not be offered. The usual causes are WP-Cron not running, or the server blocking outbound connections.', 'earlyward' ),
				$evidence,
				array(
					__( 'Open Dashboard, then Updates, and click "Check again". If a message about a failed connection appears, ask your host to allow outbound HTTPS connections to api.wordpress.org.', 'earlyward' ),
					__( 'If scheduled tasks are not running (see the WP-Cron finding), fix that first: update checks depend on it.', 'earlyward' ),
				)
			),
		);
	}
}
