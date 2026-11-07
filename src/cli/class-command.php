<?php
/**
 * WP-CLI: wp wallwarden scan
 *
 * @package Wallwarden
 */

namespace Wallwarden\Cli;

use Wallwarden\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Run Wallwarden checks from the command line.
 */
final class Command {

	/**
	 * Run the scheduled job now: scan, store the result, and email if the findings changed.
	 *
	 * Results from the command line are compared only with earlier command-line runs, because
	 * PHP settings (open_basedir, .user.ini) usually differ from the web server's.
	 *
	 * ## OPTIONS
	 *
	 * [--no-mail]
	 * : Store the result but never send an email.
	 *
	 * @param string[]                   $args       Positional args.
	 * @param array<string, string|bool> $assoc_args Flags.
	 */
	public function run( array $args, array $assoc_args ): void {
		$send = ! isset( $assoc_args['mail'] ) || false !== $assoc_args['mail'];
		$out  = Plugin::run( $send );
		if ( ! $out['ran'] ) {
			\WP_CLI::warning( 'Another scan is running, or the scan failed to start.' );
			return;
		}
		\WP_CLI::success(
			sprintf(
				'%d findings, %d checks could not run; set of alerts %s; email %s.',
				$out['findings'],
				$out['errors'],
				$out['changed'] ? 'changed' : 'unchanged',
				$out['mailed'] ? 'sent' : 'not sent'
			)
		);
	}

	/**
	 * Show the last stored scan: when it ran, where from, and what it found.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function report( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		$state = \Wallwarden\Store\State::get();
		if ( empty( $state['last'] ) ) {
			\WP_CLI::warning( 'No scan has been stored yet. Run "wp wallwarden run" or wait for WP-Cron.' );
			return;
		}
		$last = $state['last'];
		$age  = time() - (int) $last['at'];
		\WP_CLI::line( sprintf( 'Last scan: %s UTC (%s ago), from %s.', gmdate( 'Y-m-d H:i', (int) $last['at'] ), human_time_diff( (int) $last['at'] ), $last['env'] ) );
		if ( $age > 3 * HOUR_IN_SECONDS ) {
			\WP_CLI::warning( 'The last scan is more than 3 hours old. WP-Cron may not be running.' );
		}
		$next = wp_next_scheduled( Plugin::CRON_HOOK );
		\WP_CLI::line( false === $next ? 'Next scan: NOT SCHEDULED.' : sprintf( 'Next scan: %s UTC.', gmdate( 'Y-m-d H:i', (int) $next ) ) );
		foreach ( (array) $last['errors'] as $check => $message ) {
			\WP_CLI::warning( sprintf( 'Could not run %s: %s', $check, $message ) );
		}
		$rows = array();
		foreach ( (array) $last['findings'] as $f ) {
			$first  = $f['evidence'][0];
			$rows[] = array(
				'check'      => $f['check'],
				'severity'   => $f['severity'],
				'confidence' => $f['confidence'],
				'title'      => $f['title'],
				'where'      => $first['location'] . ( $first['line'] ? ':' . $first['line'] : '' ),
			);
		}
		if ( empty( $rows ) ) {
			\WP_CLI::success( 'The last scan had no findings.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'severity', 'confidence', 'title', 'where' ) );
	}

	/**
	 * Run all checks and print the findings. Read-only: stores nothing, sends nothing.
	 *
	 * Note: under WP-CLI, PHP does not apply .user.ini files, so the "runtime" evidence of
	 * check C1 only appears in scans that run through the web server (WP-Cron).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$result   = Plugin::scan();
		$findings = array();
		foreach ( $result['findings'] as $finding ) {
			$findings[] = $finding->to_array();
		}

		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					array(
						'findings' => $findings,
						'errors'   => $result['errors'],
						'timings'  => $result['timings'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		foreach ( $result['errors'] as $check => $message ) {
			\WP_CLI::warning( sprintf( 'Could not run %s: %s', $check, $message ) );
		}
		if ( empty( $findings ) ) {
			\WP_CLI::success( 'No findings from the checks that ran.' );
			return;
		}
		$rows = array();
		foreach ( $findings as $f ) {
			$first  = $f['evidence'][0];
			$rows[] = array(
				'check'      => $f['check'],
				'severity'   => $f['severity'],
				'confidence' => $f['confidence'],
				'title'      => $f['title'],
				'where'      => $first['location'] . ( $first['line'] ? ':' . $first['line'] : '' ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'severity', 'confidence', 'title', 'where' ) );
	}
}
