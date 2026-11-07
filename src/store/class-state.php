<?php
/**
 * The only data Earlyward stores: the last scan and the last alerted set.
 * One non-autoloaded option, so it costs nothing on normal page loads.
 *
 * @package Earlyward
 */

namespace Earlyward\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Stored state.
 */
final class State {

	const OPTION = 'earlyward_state';

	/**
	 * Keep stored findings bounded even on a badly infected site.
	 */
	const MAX_FINDINGS = 500;

	/**
	 * Read the state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Previous alerting findings for one environment ("web" or "cli").
	 *
	 * @param string $env Environment.
	 * @return array<int, array<string, mixed>>
	 */
	public static function alerted( string $env ): array {
		$state = self::get();
		return isset( $state['alerted'][ $env ] ) && is_array( $state['alerted'][ $env ] ) ? $state['alerted'][ $env ] : array();
	}

	/**
	 * Stored healthy baselines, by check ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function baselines(): array {
		$state = self::get();
		return isset( $state['baselines'] ) && is_array( $state['baselines'] ) ? $state['baselines'] : array();
	}

	/**
	 * Observations from the previous scan, by check ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function observations(): array {
		$state = self::get();
		return isset( $state['observations'] ) && is_array( $state['observations'] ) ? $state['observations'] : array();
	}

	/**
	 * Set (or with null, clear) one check's baseline.
	 *
	 * @param string                    $check Check ID.
	 * @param array<string, mixed>|null $data  Baseline.
	 */
	public static function set_baseline( string $check, ?array $data ): void {
		$state = self::get();
		if ( null === $data ) {
			unset( $state['baselines'][ $check ] );
		} else {
			$state['baselines'][ $check ] = array( 'at' => time() ) + $data;
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Save a scan.
	 *
	 * @param string                                                          $env      Environment.
	 * @param array<int, array<string, mixed>>                                $findings All findings (to_array form).
	 * @param array<string, string>                                           $errors   Checks that could not run.
	 * @param array<string, float>                                            $timings  Seconds per check.
	 * @param array<int, array<string, mixed>>                                $alerted  Alerting findings now open.
	 * @param bool                                                            $mailed   Whether an email was sent.
	 * @param array<string, array{data: array<string, mixed>, healthy: bool}> $observations What checks measured.
	 * @param array{at: int, new: int, resolved: int, critical: int}|null     $change       Set when the set of warning/critical findings changed in this scan.
	 */
	public static function save( string $env, array $findings, array $errors, array $timings, array $alerted, bool $mailed, array $observations = array(), ?array $change = null ): void {
		$state                    = self::get();
		$state['last']            = array(
			'at'       => time(),
			'env'      => $env,
			'findings' => array_slice( $findings, 0, self::MAX_FINDINGS ),
			'total'    => count( $findings ),
			'errors'   => $errors,
			'timings'  => $timings,
			'mailed'   => $mailed,
		);
		$state['alerted']         = isset( $state['alerted'] ) && is_array( $state['alerted'] ) ? $state['alerted'] : array();
		$state['alerted'][ $env ] = array_slice( $alerted, 0, self::MAX_FINDINGS );
		$state['version']         = defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : '';
		if ( null !== $change ) {
			$state['last_change'] = $change;
		}
		$state['baselines']    = isset( $state['baselines'] ) && is_array( $state['baselines'] ) ? $state['baselines'] : array();
		$state['observations'] = isset( $state['observations'] ) && is_array( $state['observations'] ) ? $state['observations'] : array();
		foreach ( $observations as $check => $obs ) {
			$state['observations'][ $check ] = $obs['data'];
			// The first healthy observation becomes the baseline. It is never replaced
			// automatically: a slow drift into "broken" must not become the new normal.
			if ( $obs['healthy'] && ! isset( $state['baselines'][ $check ] ) ) {
				$state['baselines'][ $check ] = array( 'at' => time() ) + $obs['data'];
			}
		}
		update_option( self::OPTION, $state, false );
	}
}
