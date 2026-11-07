<?php
/**
 * H2: the newest backup is old.
 *
 * Reads the backup plugin's own history (UpdraftPlus, option updraft_backup_history) and compares the
 * newest entry with now. Quiet when there is no such history, because then the site may use another
 * backup tool and this check cannot tell. Read-only; it does not look at the files.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Housekeeping;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Backup freshness check.
 */
final class Backup_Freshness_Check implements Check {

	const ID = 'H2';

	const MAX_AGE_DAYS = 14;

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
		$db = $ctx->db();
		if ( null === $db ) {
			return array();
		}
		$history = $db->option( 'updraft_backup_history' );
		if ( ! is_array( $history ) || empty( $history ) ) {
			return array();
		}
		$newest = 0;
		foreach ( array_keys( $history ) as $stamp ) {
			// Array keys are ints, or numeric strings that PHP did not convert (for example with leading zeros).
			if ( ctype_digit( (string) $stamp ) ) {
				$newest = max( $newest, (int) $stamp );
			}
		}
		if ( $newest <= 0 ) {
			return array();
		}
		$age_days = (int) floor( ( $ctx->now() - $newest ) / 86400 );
		if ( $age_days <= self::MAX_AGE_DAYS ) {
			return array();
		}

		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_MEDIUM,
				/* translators: %d: number of days */
				sprintf( _n( 'The newest backup is %d day old', 'The newest backup is %d days old', $age_days, 'earlyward' ), $age_days ),
				__( 'The backup plugin\'s own history shows no recent backup. Scheduled backups may have stopped, for example because WP-Cron is not running or the plan limit was reached.', 'earlyward' ),
				array(
					new Evidence(
						'option',
						'updraft_backup_history',
						0,
						gmdate( 'Y-m-d H:i', $newest ) . ' UTC',
						array( 'age_days' => $age_days )
					),
				),
				array(
					__( 'Open the backup plugin and check that a schedule is set and that the last run did not report an error.', 'earlyward' ),
					__( 'Run a backup now and confirm that it reaches your remote storage.', 'earlyward' ),
				)
			),
		);
	}
}
