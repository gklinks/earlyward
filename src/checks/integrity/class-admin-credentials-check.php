<?php
/**
 * C5: application passwords on administrator accounts.
 *
 * Real case: 40 application passwords had been planted on rogue admins, some stacked ten
 * deep on one account, never used, waiting. Application passwords are separate credentials:
 * they survive every password change.
 *
 * Application passwords are also used legitimately (automation, mobile apps), so a single
 * one is reported as information. Evidence never includes the stored hash.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Integrity;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Admin credentials check.
 */
final class Admin_Credentials_Check implements Check {

	const ID = 'C5';

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
		foreach ( $db->administrators() as $admin ) {
			$passwords = $db->user_meta( $admin['id'], '_application_passwords' );
			if ( ! is_array( $passwords ) || empty( $passwords ) ) {
				continue;
			}
			$evidence = array();
			$unused   = 0;
			foreach ( $passwords as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$last_used = isset( $item['last_used'] ) && $item['last_used'] ? (int) $item['last_used'] : 0;
				if ( 0 === $last_used ) {
					++$unused;
				}
				$evidence[] = new Evidence(
					'credential',
					'user:' . $admin['id'] . ':app-password:' . ( isset( $item['uuid'] ) ? (string) $item['uuid'] : (string) count( $evidence ) ),
					0,
					isset( $item['name'] ) ? (string) $item['name'] : '',
					array(
						'login'     => $admin['login'],
						'created'   => isset( $item['created'] ) ? gmdate( 'Y-m-d H:i', (int) $item['created'] ) . ' UTC' : '',
						'last_used' => $last_used ? gmdate( 'Y-m-d H:i', $last_used ) . ' UTC' : 'never',
					)
				);
			}
			if ( empty( $evidence ) ) {
				continue;
			}

			$stacked = count( $evidence ) >= 3 && $unused >= 2;
			if ( $stacked ) {
				$findings[] = new Finding(
					self::ID,
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_MEDIUM,
					sprintf( 'Administrator "%s" has %d application passwords, %d never used', $admin['login'], count( $evidence ), $unused ),
					'Several unused application passwords on one administrator look like keys left behind for later. They keep working after the account\'s password is changed.',
					$evidence,
					array(
						'Revoke every application password you do not recognise (Users > Edit > Application Passwords).',
						'If the account itself is unfamiliar, see check C4: demote it and revoke its sessions too.',
					)
				);
			} else {
				$findings[] = new Finding(
					self::ID,
					Finding::SEVERITY_INFO,
					Finding::CONFIDENCE_LOW,
					sprintf( 'Administrator "%s" has %d application password(s)', $admin['login'], count( $evidence ) ),
					'Application passwords let apps and scripts act as this administrator. They are separate from the login password and survive password changes, so keep only the ones you use.',
					$evidence,
					array( 'Revoke any you no longer use (Users > Edit > Application Passwords).' )
				);
			}
		}
		return $findings;
	}
}
