<?php
/**
 * A4: WP-Cron is not firing.
 *
 * WP-Cron runs scheduled jobs (updates, backups, emails, and Wallwarden's own scans) only
 * when something triggers wp-cron.php: a visitor, or a real system cron job. If neither
 * happens, everything scheduled quietly stops.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Runtime;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Cron check.
 */
final class Cron_Check implements Check {

	const ID = 'A4';

	const WARN_AFTER   = 21600; // 6 hours overdue.
	const STRONG_AFTER = 86400; // 24 hours overdue.

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
		$cron = $db->option( 'cron' );
		if ( ! is_array( $cron ) ) {
			return array();
		}
		$now     = $ctx->now();
		$overdue = 0;
		$oldest  = null;
		$hook    = '';
		foreach ( $cron as $ts => $events ) {
			if ( ! is_numeric( $ts ) || ! is_array( $events ) ) {
				continue; // Skips the "version" entry.
			}
			$ts = (int) $ts;
			if ( $now - $ts < self::WARN_AFTER ) {
				continue;
			}
			$overdue += count( $events );
			if ( null === $oldest || $ts < $oldest ) {
				$oldest = $ts;
				$hook   = (string) array_key_first( $events );
			}
		}
		if ( null === $oldest ) {
			return array();
		}

		$hours    = (int) floor( ( $now - $oldest ) / 3600 );
		$disabled = (bool) $ctx->constant( 'DISABLE_WP_CRON' );
		$why      = $disabled
			? 'DISABLE_WP_CRON is set, so WordPress expects a system cron job to call wp-cron.php, and that is not happening. Updates, scheduled posts, backups and these scans have stopped.'
			: 'Scheduled jobs are running late. WordPress only runs them when the site gets visits (or a system cron job calls wp-cron.php), and something is stopping that: very low traffic, a page cache that serves every visit, or a firewall blocking the server\'s request to itself.';
		$steps    = $disabled
			? array(
				'Ask your host (or check the control panel) for a cron job that requests wp-cron.php every 5-15 minutes, and add one if it is missing.',
				'Or remove DISABLE_WP_CRON from wp-config.php to go back to visit-triggered cron.',
			)
			: array(
				'Set up a system cron job that requests your-site/wp-cron.php every 5-15 minutes, then add define( \'DISABLE_WP_CRON\', true ); to wp-config.php.',
				'If the site has visitors, check that your firewall allows the server to reach its own address (WordPress Site Health shows this as a loopback error).',
			);

		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				$now - $oldest >= self::STRONG_AFTER ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
				sprintf( 'Scheduled jobs are %d hours overdue (WP-Cron is not running)', $hours ),
				$why,
				array(
					new Evidence(
						'option',
						'option:cron',
						0,
						'oldest overdue: ' . $hook,
						array(
							'overdue_events'  => $overdue,
							'oldest_due'      => gmdate( 'Y-m-d H:i', $oldest ) . ' UTC',
							'disable_wp_cron' => $disabled,
						)
					),
				),
				$steps
			),
		);
	}
}
