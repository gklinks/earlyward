<?php
/**
 * The only data Wallwarden stores: the last scan and the last alerted set.
 * One non-autoloaded option, so it costs nothing on normal page loads.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Stored state.
 */
final class State {

	const OPTION = 'wallwarden_state';

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
	 * Save a scan.
	 *
	 * @param string                           $env      Environment.
	 * @param array<int, array<string, mixed>> $findings All findings (to_array form).
	 * @param array<string, string>            $errors   Checks that could not run.
	 * @param array<string, float>             $timings  Seconds per check.
	 * @param array<int, array<string, mixed>> $alerted  Alerting findings now open.
	 * @param bool                             $mailed   Whether an email was sent.
	 */
	public static function save( string $env, array $findings, array $errors, array $timings, array $alerted, bool $mailed ): void {
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
		$state['version']         = defined( 'WALLWARDEN_VERSION' ) ? WALLWARDEN_VERSION : '';
		update_option( self::OPTION, $state, false );
	}
}
