<?php
/**
 * WP-CLI: wp earlyward scan
 *
 * @package Earlyward
 */

namespace Earlyward\Cli;

use Earlyward\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Run Earlyward checks from the command line.
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
	 * [--[no-]mail]
	 * : Send an email if the findings changed (the default). --no-mail stores the result only.
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
	 * Show or reset a baseline: the healthy homepage used by check A1 (default), or the outside link domains used by check C19.
	 *
	 * The first healthy scan records it automatically. Reset it after you change the site on
	 * purpose (new theme, removed feature, links you added); the next scan records the new state.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Forget the current baseline.
	 *
	 * [--check=<id>]
	 * : Which baseline: A1 (default) or C19.
	 *
	 * @param string[]                   $args       Positional args.
	 * @param array<string, string|bool> $assoc_args Flags.
	 */
	public function baseline( array $args, array $assoc_args ): void {
		$check = isset( $assoc_args['check'] ) ? strtoupper( (string) $assoc_args['check'] ) : 'A1';
		if ( ! in_array( $check, array( 'A1', 'C19' ), true ) ) {
			\WP_CLI::error( 'Unknown baseline. Use --check=A1 or --check=C19.' );
		}
		if ( ! empty( $assoc_args['reset'] ) ) {
			\Earlyward\Store\State::set_baseline( $check, null );
			\WP_CLI::success( 'Baseline cleared. The next healthy scan records a new one (run "wp earlyward run" to do it now).' );
			return;
		}
		$baselines = \Earlyward\Store\State::baselines();
		if ( 'C19' === $check ) {
			if ( empty( $baselines['C19'] ) ) {
				\WP_CLI::warning( 'No link-domain baseline yet. The first scan records it.' );
				return;
			}
			\WP_CLI::line( sprintf( 'Recorded: %s UTC', gmdate( 'Y-m-d H:i', (int) ( $baselines['C19']['at'] ?? 0 ) ) ) );
			\WP_CLI::line( 'Outside link domains: ' . count( (array) ( $baselines['C19']['domains'] ?? array() ) ) );
			return;
		}
		if ( empty( $baselines['A1'] ) ) {
			\WP_CLI::warning( 'No baseline yet. It is recorded by the first scan that loads a healthy homepage.' );
			return;
		}
		$b = $baselines['A1'];
		\WP_CLI::line( sprintf( 'Recorded: %s UTC', gmdate( 'Y-m-d H:i', (int) ( $b['at'] ?? 0 ) ) ) );
		\WP_CLI::line( 'Styles: ' . implode( ', ', array_keys( (array) ( $b['styles'] ?? array() ) ) ) );
		\WP_CLI::line( 'Menu present: ' . ( ! empty( $b['nav'] ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'REST namespaces: ' . ( is_array( $b['namespaces'] ?? null ) ? implode( ', ', $b['namespaces'] ) : 'not available' ) );
	}

	/**
	 * Show the last stored scan: when it ran, where from, and what it found.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function report( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		$state = \Earlyward\Store\State::get();
		if ( empty( $state['last'] ) ) {
			\WP_CLI::warning( 'No scan has been stored yet. Run "wp earlyward run" or wait for WP-Cron.' );
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
	 * Measure what a scan costs: time and memory per check, over several runs. Read-only.
	 *
	 * The numbers describe this site on this server, from the command line. Web and WP-Cron
	 * scans run with different PHP limits and cache state, so measure there too before quoting
	 * a figure. Per-check memory needs PHP 8.2 or newer.
	 *
	 * ## OPTIONS
	 *
	 * [--runs=<n>]
	 * : How many full scans to run (1 to 50).
	 * ---
	 * default: 5
	 * ---
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
	public function cost( array $args, array $assoc_args ): void {
		$n      = max( 1, min( 50, (int) ( $assoc_args['runs'] ?? 5 ) ) );
		$runs   = array();
		$before = memory_get_usage();
		$result = array(
			'errors'   => array(),
			'findings' => array(),
		);
		for ( $i = 0; $i < $n; $i++ ) {
			$result = Plugin::scan();
			$runs[] = array(
				'timings' => $result['timings'],
				'memory'  => $result['memory'],
			);
		}
		$sum            = \Earlyward\Scanner\Cost::summarise( $runs );
		$sum['process'] = array(
			'php'                 => PHP_VERSION,
			'sapi'                => PHP_SAPI,
			'memory_before_bytes' => $before,
			'process_peak_bytes'  => memory_get_peak_usage(),
			'errors_in_last_scan' => count( $result['errors'] ),
			'findings_last_scan'  => count( $result['findings'] ),
		);

		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			\WP_CLI::line( (string) wp_json_encode( $sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $sum['checks'] as $id => $c ) {
			$rows[] = array(
				'check'      => $id,
				'median_ms'  => (int) round( $c['median_s'] * 1000 ),
				'max_ms'     => (int) round( $c['max_s'] * 1000 ),
				'peak_extra' => null === $c['peak_bytes'] ? 'n/a (PHP < 8.2)' : size_format( $c['peak_bytes'], 1 ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'median_ms', 'max_ms', 'peak_extra' ) );
		\WP_CLI::line(
			sprintf(
				'%d full scans: median %d ms, slowest %d ms. Process peak memory %s (PHP %s, %s).',
				$sum['runs'],
				(int) round( $sum['total_median_s'] * 1000 ),
				(int) round( $sum['total_max_s'] * 1000 ),
				size_format( $sum['process']['process_peak_bytes'], 1 ),
				PHP_VERSION,
				PHP_SAPI
			)
		);
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
						'memory'   => $result['memory'],
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
