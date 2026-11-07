<?php
/**
 * H1 (old local backup copies): what is reported, when a fix is offered, and that the fix removes
 * only the older UpdraftPlus files.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Housekeeping\Old_Backups_Check;
use Earlyward\Env\Context;
use Earlyward\Fix\Fixer;
use Earlyward\Fix\Writer;
use Earlyward\Scanner\Scanner;

/**
 * Housekeeping tests.
 */
final class HousekeepingTest extends TestCase {

	const OLD    = 'backup_2026-11-01-0300_Example_aaaaaaaaaaaa';
	const MID    = 'backup_2026-11-05-0300_Example_bbbbbbbbbbbb';
	const NEWEST = 'backup_2026-11-07-1745_Example_cccccccccccc';

	/**
	 * Temporary site root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Create a site with an empty UpdraftPlus folder.
	 */
	protected function setUp(): void {
		$this->root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-h1-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/updraft', 0777, true );
		mkdir( $this->root . '/wp-content/uploads', 0777, true );
	}

	/**
	 * Remove it.
	 */
	protected function tearDown(): void {
		self::rmrf( $this->root );
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
	 * Write a file of a given size.
	 *
	 * @param string $relative Path under the site root.
	 * @param int    $bytes    Size.
	 */
	private function put( string $relative, int $bytes = 10 ): void {
		file_put_contents( $this->root . '/' . $relative, str_repeat( 'x', $bytes ) );
	}

	/**
	 * Each set gets a database file and a plugins file.
	 *
	 * @param string[] $sets Set prefixes.
	 */
	private function sets( array $sets ): void {
		foreach ( $sets as $set ) {
			$this->put( 'wp-content/updraft/' . $set . '-db.gz', 100 );
			$this->put( 'wp-content/updraft/' . $set . '-plugins.zip', 200 );
		}
	}

	/**
	 * Context, with the backup plugin history as given.
	 *
	 * @param mixed $history Value of option updraft_backup_history, or null for none.
	 * @return Context
	 */
	private function context( $history ): Context {
		$options = null === $history ? array() : array( 'updraft_backup_history' => $history );
		return new Context(
			$this->root,
			$this->root . '/wp-content',
			$this->root . '/wp-content/uploads',
			array( 'earlyward.sapi' => 'fpm-fcgi' ),
			array(
				'db'  => new Fixture_Database( array( 'options' => $options ) ),
				'now' => Fixture_Site::NOW,
			)
		);
	}

	/**
	 * History in which the newest set was uploaded.
	 *
	 * @param mixed $service Service value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function history( $service ): array {
		return array(
			1794000000 => array(
				'nonce'   => 'cccccccccccc',
				'service' => $service,
			),
			1793000000 => array(
				'nonce'   => 'bbbbbbbbbbbb',
				'service' => array( 'googledrive' ),
			),
		);
	}

	/**
	 * Findings of the check at a given threshold, in to_array form.
	 *
	 * @param Context $ctx       Context.
	 * @param int     $min_bytes Threshold.
	 * @return array<int, array<string, mixed>>
	 */
	private function found( Context $ctx, int $min_bytes = 0 ): array {
		$out = array();
		foreach ( ( new Scanner( array( new Old_Backups_Check( $min_bytes ) ) ) )->run( $ctx )['findings'] as $finding ) {
			$out[] = $finding->to_array();
		}
		return $out;
	}

	/**
	 * Names the backup plugin gives its files are understood; anything else is not a backup.
	 */
	public function test_parse(): void {
		$this->assertSame(
			array(
				'stamp' => '2026-11-07-1745',
				'nonce' => 'cccccccccccc',
			),
			Old_Backups_Check::parse( self::NEWEST . '-plugins2.zip' )
		);
		$this->assertNotNull( Old_Backups_Check::parse( self::NEWEST . '-db.gz' ) );
		$this->assertNull( Old_Backups_Check::parse( 'backup_notes.php' ) );
		$this->assertNull( Old_Backups_Check::parse( self::NEWEST . '-db.php' ) );
		$this->assertNull( Old_Backups_Check::parse( 'log.cccccccccccc.txt' ) );
	}

	/**
	 * A single set is the newest one: nothing is old.
	 */
	public function test_one_set_is_quiet(): void {
		$this->sets( array( self::NEWEST ) );
		$this->assertSame( array(), $this->found( $this->context( self::history( array( 'googledrive' ) ) ) ) );
	}

	/**
	 * Small old sets are not worth a notice at the default threshold.
	 */
	public function test_small_old_sets_are_quiet_by_default(): void {
		$this->sets( array( self::OLD, self::NEWEST ) );
		$this->assertSame( array(), $this->found( $this->context( self::history( array( 'googledrive' ) ) ), Old_Backups_Check::MIN_BYTES ) );
	}

	/**
	 * Old sets plus proof of upload: reported, only old files listed, fix offered.
	 */
	public function test_proven_upload_lists_only_old_files_and_offers_the_fix(): void {
		$this->sets( array( self::OLD, self::MID, self::NEWEST ) );
		$this->put( 'wp-content/updraft/notes.txt' );
		$found = $this->found( $this->context( self::history( array( 'googledrive' ) ) ) );

		$this->assertCount( 1, $found );
		$this->assertSame( 'H1', $found[0]['check'] );
		$this->assertSame( 'info', $found[0]['severity'] );
		$locations = array_column( $found[0]['evidence'], 'location' );
		$this->assertCount( 4, $locations );
		foreach ( $locations as $location ) {
			$this->assertStringNotContainsString( 'cccccccccccc', $location );
			$this->assertStringNotContainsString( 'notes.txt', $location );
		}
		$offer = Fixer::offer( $found[0] );
		$this->assertNotNull( $offer );
		$this->assertSame( Fixer::ID_DELETE_OLD_BACKUPS, $offer['id'] );
		$this->assertNull( Fixer::offer( $found[0], true ), 'EARLYWARD_NO_FIXES removes the offer.' );
	}

	/**
	 * Histories that do not prove the newest set was uploaded.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function no_proof(): array {
		return array(
			'no history at all'     => array( null ),
			'history is not a list' => array( 'garbage' ),
			'service empty'         => array( self::history( array() ) ),
			'service none'          => array( self::history( 'none' ) ),
			'newest set not listed' => array(
				array(
					1793000000 => array(
						'nonce'   => 'bbbbbbbbbbbb',
						'service' => array( 'googledrive' ),
					),
				),
			),
			'service is odd text'   => array( self::history( array( '<script>' ) ) ),
		);
	}

	/**
	 * No proof: reported, but no fix.
	 *
	 * @dataProvider no_proof
	 * @param mixed $history History.
	 */
	public function test_without_proof_there_is_no_fix( $history ): void {
		$this->sets( array( self::OLD, self::NEWEST ) );
		$found = $this->found( $this->context( $history ) );
		$this->assertCount( 1, $found );
		$this->assertNull( Fixer::offer( $found[0] ) );
		$this->assertStringContainsString( 'Do not delete', implode( ' ', $found[0]['fix_steps'] ) );
	}

	/**
	 * The fix removes the older files and nothing else.
	 */
	public function test_fix_removes_only_old_backup_files(): void {
		$this->sets( array( self::OLD, self::MID, self::NEWEST ) );
		$this->put( 'wp-content/updraft/notes.txt' );
		$this->put( 'wp-content/uploads/keep.zip' );
		$ctx    = $this->context( self::history( array( 'googledrive' ) ) );
		$found  = $this->found( $ctx );
		$result = Fixer::apply( Fixer::ID_DELETE_OLD_BACKUPS, 'H1', (string) $found[0]['evidence'][0]['location'], $found, $ctx, new Writer(), 1, Fixture_Site::NOW );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 4, $result['log'] );
		foreach ( array( self::OLD, self::MID ) as $set ) {
			$this->assertFileDoesNotExist( $this->root . '/wp-content/updraft/' . $set . '-db.gz' );
			$this->assertFileDoesNotExist( $this->root . '/wp-content/updraft/' . $set . '-plugins.zip' );
		}
		$this->assertFileExists( $this->root . '/wp-content/updraft/' . self::NEWEST . '-db.gz' );
		$this->assertFileExists( $this->root . '/wp-content/updraft/' . self::NEWEST . '-plugins.zip' );
		$this->assertFileExists( $this->root . '/wp-content/updraft/notes.txt' );
		$this->assertFileExists( $this->root . '/wp-content/uploads/keep.zip' );
	}

	/**
	 * A finding that points at anything but a backup file is refused, even when forged.
	 */
	public function test_fix_refuses_a_path_that_is_not_a_backup_file(): void {
		$this->put( 'wp-content/uploads/keep.zip' );
		$this->put( 'wp-content/updraft/backup_x.php' );
		foreach ( array( 'wp-content/uploads/keep.zip', 'wp-content/updraft/backup_x.php' ) as $location ) {
			$forged = array(
				array(
					'check'    => 'H1',
					'severity' => 'info',
					'evidence' => array(
						array(
							'kind'     => 'file',
							'location' => $location,
							'facts'    => array( 'remote_copy' => 'yes: forged' ),
						),
					),
				),
			);
			$result = Fixer::apply( Fixer::ID_DELETE_OLD_BACKUPS, 'H1', $location, $forged, $this->context( null ), new Writer(), 1, Fixture_Site::NOW );
			$this->assertFalse( $result['ok'] );
			$this->assertFileExists( $this->root . '/' . $location );
		}
	}
}
