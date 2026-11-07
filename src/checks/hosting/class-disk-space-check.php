<?php
/**
 * B6: the disk the site lives on is nearly full.
 *
 * A full disk stops uploads, backups, the database and error logging, often without a clear message.
 * Reads two numbers the context supplies (free and total bytes); quiet when either is missing.
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
 * Disk space check.
 */
final class Disk_Space_Check implements Check {

	const ID = 'B6';

	const WARN_PERCENT     = 10;
	const CRITICAL_PERCENT = 3;
	const WARN_BYTES       = 1073741824; // 1 GiB.
	const CRITICAL_BYTES   = 104857600;  // 100 MiB.

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$free  = $ctx->ini( 'earlyward.disk_free' );
		$total = $ctx->ini( 'earlyward.disk_total' );
		if ( '' === $free || '' === $total || ! ctype_digit( $free ) || ! ctype_digit( $total ) || (int) $total <= 0 ) {
			return array();
		}
		$free_b  = (int) $free;
		$total_b = (int) $total;
		$percent = (int) floor( $free_b * 100 / $total_b );

		// Percent alone misleads on very large disks, so both levels also need the free space to be small in absolute terms.
		$critical = ( $percent < self::CRITICAL_PERCENT && $free_b < self::WARN_BYTES * 20 ) || $free_b < self::CRITICAL_BYTES;
		$warning  = $percent < self::WARN_PERCENT && $free_b < self::WARN_BYTES * 20;
		if ( ! $critical && ! $warning ) {
			return array();
		}

		return array(
			new Finding(
				self::ID,
				$critical ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				$critical ? __( 'The disk is almost full', 'earlyward' ) : __( 'The disk is getting full', 'earlyward' ),
				__( 'When the disk is full, uploads, backups and database writes fail, and error logs stop, often without a clear message. On shared hosting this figure can describe the whole server rather than only your account.', 'earlyward' ),
				array(
					new Evidence(
						'site',
						'disk',
						0,
						sprintf( '%d%% free', $percent ),
						array(
							'free_bytes'  => $free_b,
							'total_bytes' => $total_b,
						)
					),
				),
				array(
					__( 'Delete old backups, caches and unused media, and check the Housekeeping section of Earlyward for old backup copies.', 'earlyward' ),
					__( 'Look for very large error logs (debug.log, error_log) and remove or rotate them.', 'earlyward' ),
					__( 'If the figure describes your whole account or plan, ask your host about the quota.', 'earlyward' ),
				)
			),
		);
	}
}
