<?php
/**
 * C4: administrator accounts shaped like automation, not people.
 *
 * Real case: 53 rogue admins across 26 sites, created in synchronized waves months before
 * anyone noticed. Only 2 were caught by the first rule (admin_<hex>), because the rest used
 * support_, backup_, editor_, team_, content_, dev_, adm_ and more. What they shared was a
 * shape: {word}_{6-12 hex}, with the email's local part equal to the login. Match the shape,
 * never a list of prefixes.
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
 * Rogue admin check.
 */
final class Rogue_Admin_Check implements Check {

	const ID = 'C4';

	/**
	 * Admins created this close together count as a burst.
	 */
	const BURST_SECONDS = 3600;
	const BURST_SIZE    = 3;

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Generated-looking login: letters, then an underscore and 6-12 hex characters that
	 * include at least two digits (so ordinary words such as "facade" do not match).
	 *
	 * @param string $login Login.
	 * @return bool
	 */
	public static function is_generated_login( string $login ): bool {
		if ( ! preg_match( '/^[a-z]+(?:_[a-z]+)*_?([a-f0-9]{6,12})$/i', $login, $m ) ) {
			return false;
		}
		return preg_match_all( '/\d/', $m[1] ) >= 2 && false !== strpos( $login, '_' );
	}

	/**
	 * An address that cannot receive mail (reserved or local-only domains).
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public static function is_undeliverable( string $email ): bool {
		$domain = strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) );
		return (bool) preg_match( '/(^|\.)(example\.(com|net|org)|invalid|test|local|localhost)$/', $domain );
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
		$admins   = $db->administrators();
		$findings = array();

		foreach ( $admins as $admin ) {
			$local     = strtolower( (string) strstr( $admin['email'], '@', true ) );
			$generated = self::is_generated_login( $admin['login'] );
			$mirrored  = '' !== $local && strtolower( $admin['login'] ) === $local;
			$dead_mail = self::is_undeliverable( $admin['email'] );
			if ( ! $generated && ! $dead_mail ) {
				continue;
			}

			$sessions = $db->user_meta( $admin['id'], 'session_tokens' );
			$app_pw   = $db->user_meta( $admin['id'], '_application_passwords' );
			$evidence = array(
				new Evidence(
					'user',
					'user:' . $admin['id'],
					0,
					$admin['login'] . ' <' . $admin['email'] . '>',
					array(
						'registered'            => $admin['registered'],
						'sessions'              => is_array( $sessions ) ? count( $sessions ) : 0,
						'application_passwords' => is_array( $app_pw ) ? count( $app_pw ) : 0,
					)
				),
			);

			if ( $generated && $mirrored ) {
				$findings[] = new Finding(
					self::ID,
					Finding::SEVERITY_CRITICAL,
					Finding::CONFIDENCE_HIGH,
					'An administrator account looks machine-generated',
					'The login is a word plus a random code, and the email address repeats the login. Real attacks created dozens of admins exactly like this, often months before anyone noticed.',
					$evidence,
					self::steps()
				);
			} else {
				$findings[] = new Finding(
					self::ID,
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_MEDIUM,
					$generated ? 'An administrator login looks machine-generated' : 'An administrator\'s email address cannot receive mail',
					'Make sure you know who this account belongs to. Attackers create administrators with generated names or throwaway addresses so that password-reset emails never reach anyone.',
					$evidence,
					self::steps()
				);
			}
		}

		$burst = $this->burst( $admins );
		if ( null !== $burst ) {
			$findings[] = $burst;
		}
		return $findings;
	}

	/**
	 * Several administrators created within a short window.
	 *
	 * @param array<int, array{id: int, login: string, email: string, registered: string}> $admins Admins.
	 * @return Finding|null
	 */
	private function burst( array $admins ): ?Finding {
		$times = array();
		foreach ( $admins as $admin ) {
			$ts = strtotime( $admin['registered'] . ' UTC' );
			if ( false !== $ts && $ts > 0 ) {
				$times[ $admin['id'] ] = $ts;
			}
		}
		asort( $times );
		$ids  = array_keys( $times );
		$best = array();
		$n    = count( $ids );
		for ( $i = 0; $i < $n; $i++ ) {
			$group = array( $ids[ $i ] );
			for ( $j = $i + 1; $j < $n && $times[ $ids[ $j ] ] - $times[ $ids[ $i ] ] <= self::BURST_SECONDS; $j++ ) {
				$group[] = $ids[ $j ];
			}
			if ( count( $group ) > count( $best ) ) {
				$best = $group;
			}
		}
		if ( count( $best ) < self::BURST_SIZE ) {
			return null;
		}
		$evidence = array();
		foreach ( $admins as $admin ) {
			if ( in_array( $admin['id'], $best, true ) ) {
				$evidence[] = new Evidence( 'user', 'user:' . $admin['id'], 0, $admin['login'], array( 'registered' => $admin['registered'] ) );
			}
		}
		return new Finding(
			self::ID,
			Finding::SEVERITY_WARNING,
			Finding::CONFIDENCE_MEDIUM,
			sprintf( '%d administrator accounts were created within one hour', count( $best ) ),
			'People rarely create several administrators at once. In a real attack, rogue admins appeared in synchronized waves across many sites.',
			$evidence,
			array( 'Confirm each account belongs to someone you know. Check the other sites you run for accounts created at the same time.' )
		);
	}

	/**
	 * Shared fix steps.
	 *
	 * @return string[]
	 */
	private static function steps(): array {
		return array(
			'If you do not recognise the account, change its role to Subscriber rather than deleting it: deleting a user forces their posts to be reassigned or removed.',
			'Then revoke its sessions and application passwords (Users > Edit > "Log out everywhere" and the Application Passwords section). Changing the password alone does not remove application passwords.',
			'Set a new random password on it, so the attacker\'s password stops working.',
			'Check every other site you run for accounts with the same pattern.',
		);
	}
}
