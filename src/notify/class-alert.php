<?php
/**
 * Decides whether a scan deserves an email, and writes it. Pure: no WordPress calls,
 * so the "only when the set of findings changes" rule is unit-tested directly.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Notify;

use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Alert logic.
 */
final class Alert {

	/**
	 * Severities that can trigger an email. Info findings never do.
	 *
	 * @var string[]
	 */
	const ALERTING = array( Finding::SEVERITY_CRITICAL, Finding::SEVERITY_WARNING );

	/**
	 * Compare the previous alerting set with the current findings.
	 *
	 * @param array<int, array<string, mixed>> $previous Previous alerting findings (to_array form).
	 * @param Finding[]                        $current  Current findings.
	 * @return array{changed: bool, current: array<int, array<string, mixed>>, new: array<int, array<string, mixed>>, resolved: array<int, array<string, mixed>>}
	 */
	public static function diff( array $previous, array $current ): array {
		$now = array();
		foreach ( $current as $finding ) {
			if ( in_array( $finding->severity(), self::ALERTING, true ) ) {
				$now[ $finding->fingerprint() ] = $finding->to_array();
			}
		}
		$before = array();
		foreach ( $previous as $item ) {
			if ( isset( $item['fingerprint'] ) ) {
				$before[ (string) $item['fingerprint'] ] = $item;
			}
		}
		$new      = array_values( array_diff_key( $now, $before ) );
		$resolved = array_values( array_diff_key( $before, $now ) );
		return array(
			'changed'  => ! empty( $new ) || ! empty( $resolved ),
			'current'  => array_values( $now ),
			'new'      => $new,
			'resolved' => $resolved,
		);
	}

	/**
	 * Plain-text email. Evidence excerpts are attacker-controlled: they are flattened to one
	 * line each and never rendered as HTML.
	 *
	 * @param string                                                                                                                                             $site_name Site name.
	 * @param string                                                                                                                                             $site_url  Site URL.
	 * @param array{changed: bool, current: array<int, array<string, mixed>>, new: array<int, array<string, mixed>>, resolved: array<int, array<string, mixed>>} $diff Diff.
	 * @return array{subject: string, body: string}
	 */
	public static function compose( string $site_name, string $site_url, array $diff ): array {
		$critical = 0;
		foreach ( $diff['current'] as $item ) {
			if ( Finding::SEVERITY_CRITICAL === $item['severity'] ) {
				++$critical;
			}
		}
		$subject = sprintf(
			'[Wallwarden] %s: %d new, %d resolved (%d critical open)',
			self::line( $site_name ),
			count( $diff['new'] ),
			count( $diff['resolved'] ),
			$critical
		);

		$lines   = array();
		$lines[] = 'Wallwarden found a change in the checks for ' . self::line( $site_url ) . '.';
		$lines[] = 'You get this email only when the set of findings changes.';
		$lines[] = '';
		if ( ! empty( $diff['new'] ) ) {
			$lines[] = '== NEW ==';
			foreach ( $diff['new'] as $item ) {
				$lines[] = '';
				$lines[] = sprintf( '[%s, %s confidence] %s (check %s)', strtoupper( (string) $item['severity'] ), $item['confidence'], self::line( (string) $item['title'] ), $item['check'] );
				$lines[] = '  Why: ' . self::line( (string) $item['why'] );
				foreach ( (array) $item['evidence'] as $e ) {
					$where   = $e['location'] . ( $e['line'] ? ':' . $e['line'] : '' );
					$lines[] = '  Evidence: ' . self::line( (string) $where ) . ( '' !== $e['excerpt'] ? '  ' . self::line( (string) $e['excerpt'] ) : '' );
				}
				foreach ( (array) $item['fix_steps'] as $i => $step ) {
					$lines[] = sprintf( '  %d. %s', $i + 1, self::line( (string) $step ) );
				}
			}
			$lines[] = '';
		}
		if ( ! empty( $diff['resolved'] ) ) {
			$lines[] = '== NO LONGER FOUND ==';
			foreach ( $diff['resolved'] as $item ) {
				$lines[] = '- ' . self::line( (string) $item['title'] ) . ' (check ' . $item['check'] . ')';
			}
			$lines[] = '';
			$lines[] = 'A finding that disappears is good news only if you fixed it. If you did not, find out why: malware can hide its traces.';
			$lines[] = '';
		}
		$lines[] = sprintf( 'Open findings now: %d.', count( $diff['current'] ) );
		$lines[] = 'No email means no change, not proof that the site is clean. Run "wp wallwarden report" to see when the last scan ran.';

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ) . "\n",
		);
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
