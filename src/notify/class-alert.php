<?php
/**
 * Decides whether a scan deserves an email, and writes it. Pure: no WordPress calls,
 * so the "only when the set of findings changes" rule is unit-tested directly.
 *
 * @package Earlyward
 */

namespace Earlyward\Notify;

use Earlyward\Finding\Finding;

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
			/* translators: 1: site name, 2: number of new findings, 3: number of resolved findings, 4: number of critical findings still open. */
			__( '[Earlyward] %1$s: %2$d new, %3$d resolved (%4$d critical open)', 'earlyward' ),
			self::line( $site_name ),
			count( $diff['new'] ),
			count( $diff['resolved'] ),
			$critical
		);

		$lines = array();
		/* translators: %s: site address. */
		$lines[] = sprintf( __( 'Earlyward found a change in the checks for %s.', 'earlyward' ), self::line( $site_url ) );
		$lines[] = __( 'You get this email only when the set of findings changes.', 'earlyward' );
		$lines[] = '';
		if ( ! empty( $diff['new'] ) ) {
			$lines[] = __( '== NEW ==', 'earlyward' );
			foreach ( $diff['new'] as $item ) {
				$lines[] = '';
				/* translators: 1: severity in capitals, 2: confidence level, 3: finding title, 4: check ID. */
				$lines[] = sprintf( __( '[%1$s, %2$s confidence] %3$s (check %4$s)', 'earlyward' ), strtoupper( (string) $item['severity'] ), $item['confidence'], self::line( (string) $item['title'] ), $item['check'] );
				/* translators: %s: why the finding matters. */
				$lines[] = sprintf( __( '  Why: %s', 'earlyward' ), self::line( (string) $item['why'] ) );
				foreach ( (array) $item['evidence'] as $e ) {
					$where = $e['location'] . ( $e['line'] ? ':' . $e['line'] : '' );
					/* translators: %s: where the evidence was found, followed by an excerpt when there is one. */
					$lines[] = sprintf( __( '  Evidence: %s', 'earlyward' ), self::line( (string) $where ) . ( '' !== $e['excerpt'] ? '  ' . self::line( (string) $e['excerpt'] ) : '' ) );
				}
				foreach ( (array) $item['fix_steps'] as $i => $step ) {
					$lines[] = sprintf( '  %d. %s', $i + 1, self::line( (string) $step ) );
				}
			}
			$lines[] = '';
		}
		if ( ! empty( $diff['resolved'] ) ) {
			$lines[] = __( '== NO LONGER FOUND ==', 'earlyward' );
			foreach ( $diff['resolved'] as $item ) {
				/* translators: 1: finding title, 2: check ID. */
				$lines[] = sprintf( __( '- %1$s (check %2$s)', 'earlyward' ), self::line( (string) $item['title'] ), $item['check'] );
			}
			$lines[] = '';
			$lines[] = __( 'A finding that disappears is good news only if you fixed it. If you did not, find out why: malware can hide its traces.', 'earlyward' );
			$lines[] = '';
		}
		/* translators: %d: number of open findings. */
		$lines[] = sprintf( __( 'Open findings now: %d.', 'earlyward' ), count( $diff['current'] ) );
		$lines[] = __( 'No email means no change, not proof that the site is clean. Run "wp earlyward report" to see when the last scan ran.', 'earlyward' );

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
