<?php
/**
 * A3: error logs that are huge, growing fast, or dominated by one repeating line.
 *
 * Real cases: one site's error_log reached 5 GB from a single theme warning repeated on every
 * page view; another 1.4 GB. A fix that set error_reporting in wp-config.php silently did
 * nothing, because WordPress resets the level later during start-up.
 *
 * Thresholds can be changed with the constants EARLYWARD_LOG_WARN_BYTES,
 * EARLYWARD_LOG_CRITICAL_BYTES and EARLYWARD_LOG_GROWTH_BYTES_PER_DAY.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Runtime;

use Earlyward\Checks\Check;
use Earlyward\Checks\Observes;
use Earlyward\Env\Context;
use Earlyward\Env\Filesystem;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Error log check.
 */
final class Error_Log_Check implements Check, Observes {

	const ID = 'A3';

	const WARN_BYTES       = 104857600;  // 100 MB.
	const CRITICAL_BYTES   = 1073741824; // 1 GB.
	const GROWTH_PER_DAY   = 52428800;   // 50 MB a day.
	const TAIL_BYTES       = 262144;     // Read only the last 256 KB.
	const MIN_GAP_FOR_RATE = 1800;       // Need 30 minutes between scans to judge growth.

	/**
	 * Last observation.
	 *
	 * @var array<string, mixed>
	 */
	private $observation = array();

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Last observation (sizes, to measure growth next time).
	 *
	 * @return array<string, mixed>
	 */
	public function observation(): array {
		return $this->observation;
	}

	/**
	 * Sizes are always worth keeping; they are not a "healthy" baseline.
	 *
	 * @return bool
	 */
	public function observation_is_healthy(): bool {
		return false;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$warn     = self::setting( $ctx, 'EARLYWARD_LOG_WARN_BYTES', self::WARN_BYTES );
		$critical = self::setting( $ctx, 'EARLYWARD_LOG_CRITICAL_BYTES', self::CRITICAL_BYTES );
		$growth   = self::setting( $ctx, 'EARLYWARD_LOG_GROWTH_BYTES_PER_DAY', self::GROWTH_PER_DAY );
		$previous = $ctx->previous( self::ID );
		$before   = isset( $previous['files'] ) && is_array( $previous['files'] ) ? $previous['files'] : array();

		$files    = array();
		$findings = array();
		foreach ( $this->candidates( $ctx ) as $path ) {
			$size = $ctx->fs()->size( $path );
			if ( $size < 0 ) {
				continue;
			}
			$rel           = $ctx->relative( $path );
			$files[ $rel ] = array(
				'bytes' => $size,
				'at'    => $ctx->now(),
			);

			$per_day = null;
			if ( isset( $before[ $rel ]['bytes'], $before[ $rel ]['at'] ) ) {
				$gap = $ctx->now() - (int) $before[ $rel ]['at'];
				if ( $gap >= self::MIN_GAP_FOR_RATE && $size >= (int) $before[ $rel ]['bytes'] ) {
					$per_day = (int) round( ( $size - (int) $before[ $rel ]['bytes'] ) * 86400 / $gap );
				}
			}

			$too_big = $size >= $warn;
			$fast    = null !== $per_day && $per_day >= $growth;
			if ( ! $too_big && ! $fast ) {
				continue;
			}

			$top        = $this->top_line( (string) $ctx->fs()->tail( $path, self::TAIL_BYTES ) );
			$facts      = array(
				'bytes'          => $size,
				'size'           => self::human( $size ),
				'growth_per_day' => null === $per_day ? 'not measured yet' : self::human( $per_day ),
				'top_line_share' => $top['share'] . '% of recent lines',
			);
			$findings[] = new Finding(
				self::ID,
				$size >= $critical ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_HIGH,
				$too_big
					? sprintf(
						/* translators: 1: log file path, 2: file size such as 1.5 GB. */
						__( 'Error log %1$s is %2$s', 'earlyward' ),
						$rel,
						self::human( $size )
					)
					: sprintf(
						/* translators: 1: log file path, 2: growth per day such as 60.0 MB. */
						__( 'Error log %1$s is growing by about %2$s a day', 'earlyward' ),
						$rel,
						self::human( (int) $per_day )
					),
				__( 'A runaway log can fill the hosting account\'s disk quota, which stops uploads, updates, email and sometimes every site on the account. It usually comes from one warning repeated on every page view.', 'earlyward' ),
				array( new Evidence( 'file', $rel, 0, $top['line'], $facts ) ),
				array(
					__( 'Fix the cause first: the most frequent recent line is shown as evidence. It usually names the plugin or theme file.', 'earlyward' ),
					__( 'Then empty or delete the log file (keep a copy of the end of it if you need it).', 'earlyward' ),
					__( 'Do not rely on setting error_reporting in wp-config.php: WordPress resets the level later during start-up, so it silently does nothing. Fix the warning, or set the level in a must-use plugin.', 'earlyward' ),
				)
			);
		}
		$this->observation = array( 'files' => $files );
		return $findings;
	}

	/**
	 * Log files to look at.
	 *
	 * @param Context $ctx Context.
	 * @return string[]
	 */
	private function candidates( Context $ctx ): array {
		$list = array(
			$ctx->root() . '/error_log',
			$ctx->root() . '/php_errorlog',
			$ctx->root() . '/wp-admin/error_log',
			$ctx->content_dir() . '/debug.log',
		);
		$ini  = $ctx->ini( 'error_log' );
		if ( '' !== $ini && 'syslog' !== strtolower( $ini ) ) {
			$list[] = ( '/' === $ini[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $ini ) ) ? $ini : $ctx->root() . '/' . $ini;
		}
		$debug_log = $ctx->constant( 'WP_DEBUG_LOG' );
		if ( is_string( $debug_log ) && '' !== $debug_log && ! in_array( strtolower( $debug_log ), array( '1', 'true' ), true ) ) {
			$list[] = $debug_log;
		}
		$seen = array();
		foreach ( $list as $path ) {
			$seen[ Filesystem::canonical( $path ) ] = $path;
		}
		return array_values( $seen );
	}

	/**
	 * The most frequent line in the tail, with timestamps removed.
	 *
	 * @param string $tail Tail text.
	 * @return array{line: string, share: int}
	 */
	private function top_line( string $tail ): array {
		$counts = array();
		$total  = 0;
		$lines  = preg_split( '/\r\n|\r|\n/', $tail );
		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = trim( (string) preg_replace( '/^\[[^\]]{6,40}\]\s*/', '', $line ) );
			if ( '' === $line || '#' === $line[0] || 0 === strpos( $line, 'Stack trace' ) || 0 === strpos( $line, 'thrown in' ) ) {
				continue;
			}
			$counts[ $line ] = ( $counts[ $line ] ?? 0 ) + 1;
			++$total;
		}
		if ( 0 === $total ) {
			return array(
				'line'  => '',
				'share' => 0,
			);
		}
		arsort( $counts );
		$line = (string) array_key_first( $counts );
		return array(
			'line'  => $line,
			'share' => (int) round( $counts[ $line ] * 100 / $total ),
		);
	}

	/**
	 * Integer setting from a constant, or the default.
	 *
	 * @param Context $ctx     Context.
	 * @param string  $name    Constant.
	 * @param int     $fallback Default.
	 * @return int
	 */
	private static function setting( Context $ctx, string $name, int $fallback ): int {
		$value = $ctx->constant( $name );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : $fallback;
	}

	/**
	 * Human-readable size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private static function human( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		$value = (float) $bytes;
		while ( $value >= 1024 && $i < 4 ) {
			$value /= 1024;
			++$i;
		}
		return ( $i > 0 ? number_format( $value, 1 ) : (string) $bytes ) . ' ' . $units[ $i ];
	}
}
