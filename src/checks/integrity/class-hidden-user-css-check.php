<?php
/**
 * C6: CSS that hides a user row.
 *
 * Real case: the attacker stored tr#user-4{display:none!important} in custom CSS on three
 * sites, so the Users screen silently left out their account.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Integrity;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden user CSS check.
 */
final class Hidden_User_Css_Check implements Check {

	const ID = 'C6';

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
		$findings = array();
		foreach ( $db->posts_containing( array( '#user' ), array( 'custom_css' ), array( 'publish', 'draft', 'private' ), 50 ) as $post ) {
			if ( ! preg_match_all( '/[^{}]*#user[-_](\d+)[^{]*\{[^}]*display\s*:\s*none[^}]*\}/i', $post['content'], $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			$evidence = array();
			foreach ( $matches as $m ) {
				$evidence[] = new Evidence( 'post', 'post:' . $post['id'], 0, trim( $m[0] ), array( 'hidden_user_id' => (int) $m[1] ) );
			}
			$findings[] = new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				__( 'Custom CSS hides a user from the Users screen', 'earlyward' ),
				__( 'There is no ordinary reason to hide a specific user\'s row. Attackers have used exactly this to keep their administrator account out of sight.', 'earlyward' ),
				$evidence,
				array(
					__( 'Look up each hidden user ID (Users > Edit, or ?user_id= in the address bar) and check its role.', 'earlyward' ),
					__( 'Remove the CSS rule (Appearance > Customize > Additional CSS), then treat the hidden account as in check C4.', 'earlyward' ),
				)
			);
		}
		return $findings;
	}
}
