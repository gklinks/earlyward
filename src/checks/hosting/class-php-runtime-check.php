<?php
/**
 * B5: PHP version past end of life, and a very small memory limit.
 *
 * Reads two values the context supplies (the running PHP version and memory_limit). When a value
 * is missing the check stays quiet.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Hosting;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * PHP runtime check.
 */
final class Php_Runtime_Check implements Check {

	const ID = 'B5';

	/**
	 * End-of-life dates of PHP branches (php.net supported versions), by major.minor.
	 * A newer branch than the last one listed is treated as supported.
	 */
	const EOL = array(
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	);

	const WARN_DAYS   = 90;
	const MIN_MEMORY  = 67108864; // 64 MiB.
	const SECONDS_DAY = 86400;

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Bytes in a php.ini shorthand value such as 128M. Null when unlimited or unreadable.
	 *
	 * @param string $value Value.
	 * @return int|null
	 */
	public static function bytes( string $value ): ?int {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^(\d+)\s*([kmg]?)b?$/i', $value, $m ) ) {
			return null;
		}
		$n    = (int) $m[1];
		$unit = strtolower( $m[2] );
		if ( 'k' === $unit ) {
			return $n * 1024;
		}
		if ( 'm' === $unit ) {
			return $n * 1048576;
		}
		return 'g' === $unit ? $n * 1073741824 : $n;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$findings = array();
		$version  = $ctx->ini( 'earlyward.php_version' );
		if ( 1 === preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
			$branch = $m[1] . '.' . $m[2];
			$f      = $this->version_finding( $ctx, $version, $branch );
			if ( null !== $f ) {
				$findings[] = $f;
			}
		}

		$limit = self::bytes( $ctx->ini( 'memory_limit' ) );
		if ( null !== $limit && $limit > 0 && $limit < self::MIN_MEMORY ) {
			$findings[] = new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_MEDIUM,
				__( 'PHP memory limit is very low', 'earlyward' ),
				__( 'Pages, imports and updates can fail with a blank screen or a "memory exhausted" error when PHP is allowed less than 64 MB.', 'earlyward' ),
				array( new Evidence( 'setting', 'memory_limit', 0, $ctx->ini( 'memory_limit' ), array( 'bytes' => $limit ) ) ),
				array(
					__( 'Ask your host to raise memory_limit to at least 128M, or raise it yourself in the hosting control panel (PHP settings).', 'earlyward' ),
				)
			);
		}
		return $findings;
	}

	/**
	 * Finding for a PHP branch that is past, or close to, end of life.
	 *
	 * @param Context $ctx     Context.
	 * @param string  $version Full version string.
	 * @param string  $branch  Major.minor.
	 * @return Finding|null
	 */
	private function version_finding( Context $ctx, string $version, string $branch ): ?Finding {
		if ( version_compare( $branch, '7.4', '<' ) ) {
			$eol = strtotime( '2019-12-31 UTC' );
		} elseif ( isset( self::EOL[ $branch ] ) ) {
			$eol = strtotime( self::EOL[ $branch ] . ' UTC' );
		} else {
			return null;
		}
		if ( false === $eol ) {
			return null;
		}
		$days_left = (int) floor( ( $eol - $ctx->now() ) / self::SECONDS_DAY );
		if ( $days_left > self::WARN_DAYS ) {
			return null;
		}
		$evidence = array( new Evidence( 'setting', 'php_version', 0, $version, array( 'end_of_life' => gmdate( 'Y-m-d', $eol ) ) ) );
		$steps    = array(
			__( 'Ask your host to switch this site to a supported PHP version (the hosting control panel usually has a "PHP version" selector).', 'earlyward' ),
			__( 'Before switching, update your plugins and theme, and keep a backup, because very old plugins can stop working on newer PHP.', 'earlyward' ),
		);
		if ( $days_left < 0 ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_HIGH,
				/* translators: %s: PHP version, for example 7.4.33 */
				sprintf( __( 'PHP %s no longer receives security fixes', 'earlyward' ), $version ),
				__( 'This PHP branch reached end of life. Security problems found in it are no longer fixed, so a flaw in PHP itself stays open on this server.', 'earlyward' ),
				$evidence,
				$steps
			);
		}
		return new Finding(
			self::ID,
			Finding::SEVERITY_INFO,
			Finding::CONFIDENCE_HIGH,
			/* translators: %s: PHP version, for example 8.1.2 */
			sprintf( __( 'PHP %s reaches end of life soon', 'earlyward' ), $version ),
			__( 'After the end-of-life date this PHP branch no longer receives security fixes. Plan the switch to a supported version now.', 'earlyward' ),
			$evidence,
			$steps
		);
	}
}
