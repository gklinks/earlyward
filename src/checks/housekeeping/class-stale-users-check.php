<?php
/**
 * H4: many ordinary accounts look unused.
 *
 * Old subscriber and customer accounts nobody signs in to are a quiet risk: leaked passwords from other
 * sites still open them, and they hold personal data nobody needs. WordPress records no last-login time,
 * so an account counts only when it was registered long ago, has no sign-in session on record and (with
 * WooCommerce) shows no recent activity. The result is counts, never names or addresses. Detection only:
 * Earlyward never deletes accounts.
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
 * Stale users check.
 */
final class Stale_Users_Check implements Check {

	const ID = 'H4';

	const MIN_AGE_DAYS = 730;

	/**
	 * Fewer unused accounts than this is normal and not worth a note.
	 */
	const MIN_COUNT = 5;

	const ROLES = array( 'subscriber', 'customer' );

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
		$cutoff = $ctx->now() - self::MIN_AGE_DAYS * 86400;
		$result = $db->stale_accounts( $cutoff, self::ROLES );
		if ( $result['candidates'] < self::MIN_COUNT ) {
			return array();
		}
		$facts = array(
			'accounts_unused'   => $result['candidates'],
			'accounts_total'    => $result['total'],
			'registered_before' => gmdate( 'Y-m-d', $cutoff ),
		);
		foreach ( $result['by_role'] as $role => $n ) {
			$facts[ 'role_' . $role ] = $n;
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_LOW,
				/* translators: %d: number of accounts */
				sprintf( __( '%d ordinary accounts look unused', 'earlyward' ), $result['candidates'] ),
				__( 'These subscriber or customer accounts were registered more than two years ago and show no sign-in or shop activity. WordPress keeps no last-login time, so this is a hint, not proof. Old accounts can be opened with passwords leaked from other sites, and they hold personal data you may not need.', 'earlyward' ),
				array( new Evidence( 'users', 'accounts', 0, '', $facts ) ),
				array(
					__( 'Review them in Users: filter by role and sort by registration date. Export or back up first; remove only accounts you are sure about.', 'earlyward' ),
					__( 'If the site is a shop, check each account for orders before removing it; WooCommerce can anonymise instead of delete.', 'earlyward' ),
					__( 'Earlyward never deletes accounts and lists no names or addresses; it only counts.', 'earlyward' ),
				)
			),
		);
	}
}
