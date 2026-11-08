<?php
/**
 * D2: no login-protection plugin is active.
 *
 * Looks at the active plugin list for well-known login-protection plugins (login attempt limits,
 * two-factor sign-in, security suites). It cannot see protection that your host or Cloudflare
 * provides, and it cannot see whether two-factor is actually switched on for each administrator,
 * so it only reports when nothing is found, as a low-confidence note.
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
 * Login protection check.
 */
final class Login_Protection_Check implements Check {

	const ID = 'D2';

	/**
	 * Plugin folders (lower case) that provide login attempt limits, two-factor sign-in or both.
	 */
	const KNOWN = array(
		'wordfence',
		'wordfence-login-security',
		'two-factor',
		'wp-2fa',
		'limit-login-attempts-reloaded',
		'limit-login-attempts',
		'loginizer',
		'all-in-one-wp-security-and-firewall',
		'better-wp-security',
		'solid-security',
		'wp-simple-firewall',
		'wp-cerber',
		'wps-limit-login',
		'miniorange-2-factor-authentication',
		'google-authenticator',
		'sucuri-scanner',
		'defender-security',
		'jetpack',
		'app-password-ip-guard',
	);

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
		$active = $db->option( 'active_plugins' );
		if ( ! is_array( $active ) || empty( $active ) ) {
			return array();
		}
		foreach ( $active as $file ) {
			if ( is_string( $file ) && in_array( strtolower( (string) strtok( $file, '/' ) ), self::KNOWN, true ) ) {
				return array();
			}
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_LOW,
				__( 'No login-protection plugin was found', 'earlyward' ),
				__( 'None of the well-known plugins that limit login attempts or add two-factor sign-in is active. Your host or Cloudflare may already protect the login page, which Earlyward cannot see.', 'earlyward' ),
				array( new Evidence( 'site', 'active_plugins', 0, '', array( 'active' => count( $active ) ) ) ),
				array(
					__( 'Turn on two-factor sign-in for every administrator, and limit failed login attempts, with a plugin you trust or your host\'s tools.', 'earlyward' ),
					__( 'If your host or Cloudflare already does this, you can ignore this note.', 'earlyward' ),
				)
			),
		);
	}
}
