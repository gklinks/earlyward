<?php
/**
 * Fix actions: what is offered, what is refused, and that only the reported file changes.
 * Runs the real scanner over fixture sites and the real Fixer over the temporary trees.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Env\Context;
use Earlyward\Fix\Fixer;
use Earlyward\Fix\Writer;
use Earlyward\Scanner\Scanner;

/**
 * Fixer tests.
 */
final class FixerTest extends TestCase {

	const NOW = 1794009600;

	/**
	 * Sites to remove after each test.
	 *
	 * @var Fixture_Site[]
	 */
	private $sites = array();

	/**
	 * Plain directories to remove after each test.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		foreach ( $this->sites as $site ) {
			$site->destroy();
		}
		foreach ( $this->dirs as $dir ) {
			self::rmrf( $dir );
		}
		$this->sites = array();
		$this->dirs  = array();
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $path Path.
	 */
	private static function rmrf( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					self::rmrf( $path . '/' . $name );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Build a fixture site and scan it.
	 *
	 * @param string $fixture Fixture name.
	 * @return array{0: Fixture_Site, 1: Context, 2: array<int, array<string, mixed>>}
	 */
	private function scanned( string $fixture ): array {
		$site          = new Fixture_Site( dirname( __DIR__ ) . '/fixtures/' . $fixture );
		$this->sites[] = $site;
		$ctx           = $site->context();
		$found         = array();
		foreach ( Scanner::with_default_checks()->run( $ctx )['findings'] as $finding ) {
			$found[] = $finding->to_array();
		}
		return array( $site, $ctx, $found );
	}

	/**
	 * A hand-made finding, for cases the scanner would never produce.
	 *
	 * @param string $check    Check.
	 * @param string $severity Severity.
	 * @param string $location Evidence location.
	 * @return array<string, mixed>
	 */
	private static function crafted( string $check, string $severity, string $location ): array {
		return array(
			'check'       => $check,
			'severity'    => $severity,
			'evidence'    => array( array( 'location' => $location ) ),
			'fingerprint' => str_repeat( 'a', 40 ),
		);
	}

	/**
	 * Which finding offers which fix.
	 */
	public function test_offers(): void {
		$this->assertSame( Fixer::ID_CHMOD_CONFIG, Fixer::offer( self::crafted( 'B4', 'info', 'wp-config.php' ) )['id'] );
		$backup = Fixer::offer( self::crafted( 'B3', 'critical', 'db.sql' ) );
		$this->assertSame( Fixer::ID_DELETE_BACKUP, $backup['id'] );
		$this->assertNotSame( '', $backup['extra'], 'Deleting a backup asks whether another copy exists.' );
		$this->assertSame( Fixer::ID_DELETE_MAINTENANCE, Fixer::offer( self::crafted( 'C3', 'info', 'wp-content/maintenance.php' ) )['id'] );

		$this->assertNull( Fixer::offer( self::crafted( 'C3', 'warning', 'wp-content/maintenance.php' ) ), 'A maintenance page with a warning is not a harmless leftover.' );
		$this->assertNull( Fixer::offer( self::crafted( 'C1', 'critical', '.user.ini' ) ), 'Findings about attacks have no delete button.' );
		$this->assertNull( Fixer::offer( self::crafted( 'B4', 'info', 'wp-config.php' ), true ), 'The kill switch hides every fix.' );
	}

	/**
	 * Deleting an exposed backup removes that file and no other, and logs its hash.
	 */
	public function test_delete_backup_removes_only_the_reported_file(): void {
		list( $site, $ctx, $findings ) = $this->scanned( 'b3-exposed-backups' );
		$this->assertFileExists( $site->root . '/db-export.sql' );

		$result = Fixer::apply( Fixer::ID_DELETE_BACKUP, 'B3', 'db-export.sql', $findings, $ctx, new Writer(), 7, self::NOW, array( 'extra' => true ) );

		$this->assertTrue( $result['ok'] );
		$this->assertFileDoesNotExist( $site->root . '/db-export.sql' );
		$this->assertFileExists( $site->root . '/wp-config.php.bak', 'Other findings are untouched.' );
		$this->assertFileExists( $site->root . '/wp-config.php', 'The live config is never touched.' );
		$this->assertSame( 'deleted', $result['log'][0]['result'] );
		$this->assertSame( 7, $result['log'][0]['user'] );
		$this->assertSame( 64, strlen( $result['log'][0]['sha256'] ) );
	}

	/**
	 * A fingerprint that a fresh scan does not report changes nothing.
	 */
	public function test_unknown_fingerprint_changes_nothing(): void {
		list( $site, $ctx, $findings ) = $this->scanned( 'b3-exposed-backups' );
		$result                        = Fixer::apply( Fixer::ID_DELETE_BACKUP, 'B3', 'nothing-here.zip', $findings, $ctx, new Writer(), 1, self::NOW, array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'gone', $result['code'] );
		$this->assertFileExists( $site->root . '/db-export.sql' );
	}

	/**
	 * A real fingerprint cannot be used with the wrong action.
	 */
	public function test_wrong_action_for_a_finding_is_refused(): void {
		list( $site, $ctx, $findings ) = $this->scanned( 'b3-exposed-backups' );
		$result                        = Fixer::apply( Fixer::ID_DELETE_MAINTENANCE, 'B3', 'db-export.sql', $findings, $ctx, new Writer(), 1, self::NOW );
		$this->assertFalse( $result['ok'] );
		$this->assertFileExists( $site->root . '/db-export.sql' );
	}

	/**
	 * Malicious: a finding that points at code, or outside the site, is refused.
	 */
	public function test_code_and_outside_paths_are_never_deleted(): void {
		list( $site, $ctx ) = $this->scanned( 'b3-exposed-backups' );
		file_put_contents( $site->base . '/outside.txt', 'keep me' );

		foreach ( array( 'wp-config.php', '../outside.txt', '/etc/hosts' ) as $location ) {
			$finding = self::crafted( 'B3', 'critical', $location );
			$result  = Fixer::apply( Fixer::ID_DELETE_BACKUP, 'B3', $location, array( $finding ), $ctx, new Writer(), 1, self::NOW, array( 'extra' => true ) );
			$this->assertFalse( $result['ok'], $location );
			$this->assertSame( 'refused', $result['code'], $location );
		}
		$this->assertFileExists( $site->root . '/wp-config.php' );
		$this->assertFileExists( $site->base . '/outside.txt' );
	}

	/**
	 * The harmless leftover maintenance page can be deleted; a suspicious one has no fix.
	 */
	public function test_maintenance_page(): void {
		list( $site, $ctx, $findings ) = $this->scanned( 'c3-maintenance-leftover' );
		$result                        = Fixer::apply( Fixer::ID_DELETE_MAINTENANCE, 'C3', 'wp-content/maintenance.php', $findings, $ctx, new Writer(), 1, self::NOW );
		$this->assertTrue( $result['ok'] );
		$this->assertFileDoesNotExist( $site->root . '/wp-content/maintenance.php' );

		list( $bad_site, $bad_ctx, $bad_findings ) = $this->scanned( 'c3-maintenance-fake-header' );
		$refused                                   = Fixer::apply( Fixer::ID_DELETE_MAINTENANCE, 'C3', 'wp-content/maintenance.php', $bad_findings, $bad_ctx, new Writer(), 1, self::NOW );
		$this->assertFalse( $refused['ok'] );
		$this->assertFileExists( $bad_site->root . '/wp-content/maintenance.php', 'Evidence of an attack is not deleted by a button.' );
	}

	/**
	 * A failed delete is reported as failed and is logged.
	 */
	public function test_failed_delete_is_reported(): void {
		list( $site, $ctx, $findings ) = $this->scanned( 'b3-exposed-backups' );
		$stuck                         = new class() extends Writer {
			/**
			 * Always fails.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function delete_file( string $path ): bool {
				return false && '' !== $path;
			}
		};
		$result                        = Fixer::apply( Fixer::ID_DELETE_BACKUP, 'B3', 'db-export.sql', $findings, $ctx, $stuck, 1, self::NOW, array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['code'] );
		$this->assertSame( 'delete failed', $result['log'][0]['result'] );
		$this->assertFileExists( $site->root . '/db-export.sql' );
	}

	/**
	 * The config file goes from world-readable to owner-only, and the log keeps the old mode.
	 */
	public function test_chmod_config(): void {
		if ( '/' !== DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Windows has no real file modes.' );
		}
		$dir = sys_get_temp_dir() . '/earlyward-chmod-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/wp-content/uploads', 0777, true );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/wp-config.php', '<?php // fixture' );
		chmod( $dir . '/wp-config.php', 0644 );
		$ctx     = new Context( $dir, $dir . '/wp-content', $dir . '/wp-content/uploads' );
		$finding = self::crafted( 'B4', 'info', 'wp-config.php' );

		$result = Fixer::apply( Fixer::ID_CHMOD_CONFIG, 'B4', 'wp-config.php', array( $finding ), $ctx, new Writer(), 1, self::NOW );

		$this->assertTrue( $result['ok'] );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $dir . '/wp-config.php' ) & 0777 );
		$this->assertSame( '0644', $result['log'][0]['mode_before'] );
		$this->assertSame( '0600', $result['log'][0]['mode_after'] );
	}
}
