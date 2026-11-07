<?php
/**
 * Findings are shown on screen and sent by email. They must never carry stored secrets,
 * such as application password hashes or session token keys.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Scanner\Scanner;

/**
 * No secrets in findings.
 */
final class NoSecretsInFindingsTest extends TestCase {

	/**
	 * The fixture stores "NEVER-SHOWN" as each password hash and "h1" as a session token key.
	 */
	public function test_application_password_hashes_and_session_keys_are_not_in_findings(): void {
		$site = new Fixture_Site( dirname( __DIR__ ) . '/fixtures/c4-rogue-admins' );
		try {
			$result = Scanner::with_default_checks()->run( $site->context() );
			$this->assertNotEmpty( $result['findings'] );
			$all = array();
			foreach ( $result['findings'] as $finding ) {
				$all[] = $finding->to_array();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in unit tests.
			$json = (string) json_encode( $all );
			$this->assertStringNotContainsString( 'NEVER-SHOWN', $json );
			$this->assertStringNotContainsString( '"h1"', $json );
		} finally {
			$site->destroy();
		}
	}
}
