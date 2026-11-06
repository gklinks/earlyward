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
	 * Run all checks and print the findings.
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
