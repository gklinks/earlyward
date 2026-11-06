<?php
/**
 * Wires the plugin into WordPress. Every callback is fail-open.
 *
 * @package Wallwarden
 */

namespace Wallwarden;

use Wallwarden\Env\Context;
use Wallwarden\Scanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	const CRON_HOOK = 'wallwarden_scan';

	/**
	 * Register hooks. Nothing here runs on front-end requests.
	 */
	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wallwarden', Cli\Command::class );
		}
	}

	/**
	 * Run a scan and return the raw result. Never throws.
	 *
	 * @return array{findings: \Wallwarden\Finding\Finding[], errors: array<string, string>, timings: array<string, float>}
	 */
	public static function scan(): array {
		try {
			return Scanner::with_default_checks()->run( Context::from_wordpress() );
		} catch ( \Throwable $e ) {
			return array(
				'findings' => array(),
				'errors'   => array( 'scanner' => get_class( $e ) . ': ' . $e->getMessage() ),
				'timings'  => array(),
			);
		}
	}

	/**
	 * WP-Cron callback. Storage and alerts arrive in a later milestone.
	 */
	public static function run_scheduled_scan(): void {
		self::scan();
	}
}
