<?php
/**
 * Wires the plugin into WordPress. Every callback is fail-open.
 *
 * @package Earlyward
 */

namespace Earlyward;

use Earlyward\Env\Context;
use Earlyward\Notify\Alert;
use Earlyward\Scanner\Scanner;
use Earlyward\Store\State;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	const CRON_HOOK = 'earlyward_scan';
	const LOCK      = 'earlyward_scan_lock';

	/**
	 * Register hooks. Nothing here runs on front-end requests.
	 */
	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
		// Self-heal the schedule (malware and migrations both drop cron events). Admin requests only.
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );

		// Not admin-only: WordPress also runs Site Health tests from its own scheduled check.
		Admin\Site_Health::boot();

		if ( is_admin() ) {
			Admin\Page::boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'earlyward', Cli\Command::class );
		}
	}

	/**
	 * Activation: schedule the hourly scan.
	 */
	public static function activate(): void {
		self::ensure_scheduled();
	}

	/**
	 * Deactivation: remove the schedule. Stored results stay until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK );
	}

	/**
	 * Schedule the scan if it is missing.
	 */
	public static function ensure_scheduled(): void {
		try {
			if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
			}
		} catch ( \Throwable $e ) {
			return; // Fail open.
		}
	}

	/**
	 * Run a scan and return the raw result. Never throws, never writes.
	 *
	 * @return array{findings: \Earlyward\Finding\Finding[], errors: array<string, string>, timings: array<string, float>, memory: array<string, int|null>, observations: array<string, array{data: array<string, mixed>, healthy: bool}>}
	 */
	public static function scan(): array {
		try {
			return Scanner::with_default_checks()->run( Context::from_wordpress( State::baselines(), State::observations() ) );
		} catch ( \Throwable $e ) {
			return array(
				'findings'     => array(),
				'errors'       => array( 'scanner' => get_class( $e ) . ': ' . $e->getMessage() ),
				'timings'      => array(),
				'memory'       => array(),
				'observations' => array(),
			);
		}
	}

	/**
	 * WP-Cron callback.
	 */
	public static function run_scheduled_scan(): void {
		self::run( true );
	}

	/**
	 * Scan, store the result, and email only if the alerting set changed.
	 *
	 * @param bool $send_mail Whether a change may send an email.
	 * @return array{ran: bool, changed: bool, mailed: bool, findings: int, errors: int}
	 */
	public static function run( bool $send_mail ): array {
		$out = array(
			'ran'      => false,
			'changed'  => false,
			'mailed'   => false,
			'findings' => 0,
			'errors'   => 0,
		);
		try {
			if ( get_transient( self::LOCK ) ) {
				return $out; // Another scan is running.
			}
			set_transient( self::LOCK, time(), 10 * MINUTE_IN_SECONDS );

			// Web and command line can see different PHP settings; compare like with like.
			$env    = 'cli' === PHP_SAPI ? 'cli' : 'web';
			$result = self::scan();
			$diff   = Alert::diff( State::alerted( $env ), $result['findings'] );
			$first  = empty( State::get() );

			$mailed = false;
			if ( $send_mail && $diff['changed'] && ! ( $first && empty( $diff['current'] ) ) ) {
				$mail   = Alert::compose( (string) get_bloginfo( 'name' ), (string) home_url( '/' ), $diff );
				$mailed = (bool) wp_mail( (string) get_option( 'admin_email' ), $mail['subject'], $mail['body'] );
			}

			$all = array();
			foreach ( $result['findings'] as $finding ) {
				$all[] = $finding->to_array();
			}
			// If the email failed, keep the old alerted set so the next run tries again.
			$alerted = ( $send_mail && $diff['changed'] && ! $mailed && ! ( $first && empty( $diff['current'] ) ) )
				? State::alerted( $env )
				: $diff['current'];
			// Remember when the set of warnings last changed, for the Overview tab.
			$change = null;
			if ( $diff['changed'] && ! ( $first && empty( $diff['current'] ) ) ) {
				$critical = 0;
				foreach ( $diff['current'] as $item ) {
					if ( 'critical' === (string) ( $item['severity'] ?? '' ) ) {
						++$critical;
					}
				}
				$change = array(
					'at'       => time(),
					'new'      => count( $diff['new'] ),
					'resolved' => count( $diff['resolved'] ),
					'critical' => $critical,
				);
			}
			State::save( $env, $all, $result['errors'], $result['timings'], $alerted, $mailed, $result['observations'], $change );

			$out = array(
				'ran'      => true,
				'changed'  => $diff['changed'],
				'mailed'   => $mailed,
				'findings' => count( $all ),
				'errors'   => count( $result['errors'] ),
			);
		} catch ( \Throwable $e ) {
			$out['errors'] = 1; // Fail open.
		} finally {
			delete_transient( self::LOCK );
		}
		return $out;
	}
}
