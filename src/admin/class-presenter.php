<?php
/**
 * Pure helpers for the admin screen: ordering and the "copy for host support" text.
 * No WordPress calls, so the logic is unit-tested directly.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Presentation logic.
 */
final class Presenter {

	/**
	 * Most severe first.
	 *
	 * @var array<string, int>
	 */
	const SEVERITY_ORDER = array(
		Finding::SEVERITY_CRITICAL => 0,
		Finding::SEVERITY_WARNING  => 1,
		Finding::SEVERITY_INFO     => 2,
	);

	/**
	 * Order stored findings: severity, then check ID, then first evidence location.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings in to_array form.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort( array $findings ): array {
		usort(
			$findings,
			static function ( array $a, array $b ): int {
				$sa = self::SEVERITY_ORDER[ (string) ( $a['severity'] ?? '' ) ] ?? 3;
				$sb = self::SEVERITY_ORDER[ (string) ( $b['severity'] ?? '' ) ] ?? 3;
				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}
				$by_check = strnatcmp( (string) ( $a['check'] ?? '' ), (string) ( $b['check'] ?? '' ) );
				if ( 0 !== $by_check ) {
					return $by_check;
				}
				return strcmp( self::where( $a ), self::where( $b ) );
			}
		);
		return $findings;
	}

	/**
	 * First evidence location of a finding, "path:line" when there is a line.
	 *
	 * @param array<string, mixed> $finding Finding in to_array form.
	 * @return string
	 */
	public static function where( array $finding ): string {
		$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
		$first    = isset( $evidence[0] ) && is_array( $evidence[0] ) ? $evidence[0] : array();
		return self::where_item( $first );
	}

	/**
	 * Location of one evidence item.
	 *
	 * @param array<string, mixed> $item Evidence in to_array form.
	 * @return string
	 */
	public static function where_item( array $item ): string {
		$line = (int) ( $item['line'] ?? 0 );
		return (string) ( $item['location'] ?? '' ) . ( $line > 0 ? ':' . $line : '' );
	}

	/**
	 * Count findings by severity.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings in to_array form.
	 * @return array{critical: int, warning: int, info: int}
	 */
	public static function counts( array $findings ): array {
		$counts = array(
			Finding::SEVERITY_CRITICAL => 0,
			Finding::SEVERITY_WARNING  => 0,
			Finding::SEVERITY_INFO     => 0,
		);
		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? '' );
			if ( isset( $counts[ $severity ] ) ) {
				++$counts[ $severity ];
			}
		}
		return $counts;
	}

	/**
	 * Plain text a site owner can paste into a support ticket. Contains the findings and the
	 * environment, nothing else: no settings, no keys, no user data beyond what evidence shows.
	 * Evidence excerpts are attacker-controlled, so each is flattened to one line.
	 *
	 * @param array<string, mixed>      $last Stored last scan ("last" key of the state), or empty.
	 * @param array<string, string|int> $env  Facts: site_url, wp, php, sapi, plugin, now.
	 * @return string
	 */
	public static function support_text( array $last, array $env ): string {
		$lines   = array();
		$lines[] = 'Earlyward report for host support';
		$lines[] = 'Site: ' . self::line( (string) ( $env['site_url'] ?? '' ) );
		$lines[] = sprintf(
			'WordPress %s, PHP %s (%s), Earlyward %s',
			self::line( (string) ( $env['wp'] ?? '' ) ),
			self::line( (string) ( $env['php'] ?? '' ) ),
			self::line( (string) ( $env['sapi'] ?? '' ) ),
			self::line( (string) ( $env['plugin'] ?? '' ) )
		);
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i', (int) ( $env['now'] ?? 0 ) ) . ' UTC';

		if ( empty( $last ) ) {
			$lines[] = 'Last scan: none stored yet.';
			return implode( "\n", $lines ) . "\n";
		}

		$lines[] = sprintf( 'Last scan: %s UTC, run from %s.', gmdate( 'Y-m-d H:i', (int) ( $last['at'] ?? 0 ) ), self::line( (string) ( $last['env'] ?? '' ) ) );

		$findings = self::sort( isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array() );
		$counts   = self::counts( $findings );
		$lines[]  = sprintf( 'Findings: %d critical, %d warning, %d info.', $counts['critical'], $counts['warning'], $counts['info'] );

		$errors = isset( $last['errors'] ) && is_array( $last['errors'] ) ? $last['errors'] : array();
		foreach ( $errors as $check => $message ) {
			$lines[] = sprintf( 'Check %s could not run: %s', self::line( (string) $check ), self::line( (string) $message ) );
		}

		foreach ( $findings as $finding ) {
			$lines[]  = '';
			$lines[]  = sprintf(
				'[%s, %s confidence] %s (check %s)',
				strtoupper( (string) ( $finding['severity'] ?? '' ) ),
				self::line( (string) ( $finding['confidence'] ?? '' ) ),
				self::line( (string) ( $finding['title'] ?? '' ) ),
				self::line( (string) ( $finding['check'] ?? '' ) )
			);
			$lines[]  = '  Why: ' . self::line( (string) ( $finding['why'] ?? '' ) );
			$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
			foreach ( $evidence as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$excerpt = (string) ( $item['excerpt'] ?? '' );
				$lines[] = '  Evidence: ' . self::line( self::where_item( $item ) ) . ( '' !== $excerpt ? '  ' . self::line( $excerpt ) : '' );
			}
		}
		if ( empty( $findings ) ) {
			$lines[] = 'No findings in the checks that ran. That is not proof the site is clean.';
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One line of plain text: no line breaks, no control characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
	}
}
