<?php
/**
 * Quarantine fixes (C12, C13, C15, C16, D1, H3), the B8 update button and the D2 link:
 * what is offered, that only reported items move, that they cannot run where they land,
 * and that "Put back" and "Delete for good" do exactly that.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Check;
use Earlyward\Checks\Hosting\Disclosure_Files_Check;
use Earlyward\Checks\Hosting\Updates_Waiting_Check;
use Earlyward\Checks\Housekeeping\Unused_Plugins_Check;
use Earlyward\Checks\Integrity\Plugin_Copies_Check;
use Earlyward\Checks\Integrity\Root_Strays_Check;
use Earlyward\Checks\Integrity\Theme_Injection_Check;
use Earlyward\Checks\Integrity\Uploads_Php_Check;
use Earlyward\Env\Context;
use Earlyward\Fix\Fixer;
use Earlyward\Fix\Quarantine;
use Earlyward\Fix\Writer;

/**
 * Tests.
 */
final class QuarantineTest extends TestCase {

	const NOW    = 1794009600;
	const RANDOM = 'abc123def456';

	/**
	 * Temporary site root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Create a site.
	 */
	protected function setUp(): void {
		$this->root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-q-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/uploads', 0777, true );
		mkdir( $this->root . '/wp-content/plugins', 0777, true );
		mkdir( $this->root . '/wp-content/themes', 0777, true );
	}

	/**
	 * Remove it.
	 */
	protected function tearDown(): void {
		( new Writer() )->delete_dir( $this->root );
	}

	/**
	 * Write a file under the root.
	 *
	 * @param string $relative Path.
	 * @param string $content  Contents.
	 */
	private function put( string $relative, string $content = 'x' ): void {
		$dir = dirname( $this->root . '/' . $relative );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $this->root . '/' . $relative, $content );
	}

	/**
	 * Context.
	 *
	 * @param array<string, mixed>                     $options Options.
	 * @param array<string, array<string, mixed>>|null $routes  Loopback routes, or null for no loopback.
	 * @return Context
	 */
	private function context( array $options = array(), ?array $routes = null ): Context {
		$extra = array(
			'db'  => new Fixture_Database( array( 'options' => $options ) ),
			'now' => self::NOW,
		);
		if ( null !== $routes ) {
			$extra['loopback'] = new Fixture_Loopback( $this->root, $routes );
		}
		return new Context( $this->root, $this->root . '/wp-content', $this->root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), $extra );
	}

	/**
	 * Findings in to_array form.
	 *
	 * @param Check   $check Check.
	 * @param Context $ctx   Context.
	 * @return array<int, array<string, mixed>>
	 */
	private function found( Check $check, Context $ctx ): array {
		$out = array();
		foreach ( $check->run( $ctx ) as $finding ) {
			$out[] = $finding->to_array();
		}
		return $out;
	}

	/**
	 * First evidence location.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return string
	 */
	private static function first( array $finding ): string {
		return (string) $finding['evidence'][0]['location'];
	}

	/**
	 * Files in the quarantine folder, without the guard files.
	 *
	 * @return string[]
	 */
	private function held(): array {
		$dir = $this->root . '/wp-content/' . Quarantine::PREFIX . self::RANDOM;
		return is_dir( $dir ) ? array_values( array_diff( (array) scandir( $dir ), array( '.', '..', '.htaccess', 'index.html', Quarantine::MANIFEST ) ) ) : array();
	}

	/**
	 * A root stray goes into quarantine renamed so it cannot run, and comes back with "Put back".
	 */
	public function test_root_stray_round_trip(): void {
		$this->put( 'CP.php', '<?php echo 1;' );
		$ctx      = $this->context();
		$findings = $this->found( new Root_Strays_Check(), $ctx );
		$this->assertSame( Fixer::ID_QUARANTINE_FILE, Fixer::offer( $findings[0] )['id'] );

		$result = Fixer::apply(
			Fixer::ID_QUARANTINE_FILE,
			'C15',
			'CP.php',
			$findings,
			$ctx,
			new Writer(),
			3,
			self::NOW,
			array( 'random' => self::RANDOM )
		);
		$this->assertTrue( $result['ok'], (string) $result['code'] );
		$this->assertFileDoesNotExist( $this->root . '/CP.php' );
		$held = $this->held();
		$this->assertCount( 1, $held );
		$this->assertStringNotContainsString( '.php', $held[0], 'No ".php" may remain anywhere in the stored name.' );
		$this->assertStringEndsWith( Quarantine::SUFFIX, $held[0] );
		$folder = $this->root . '/wp-content/' . Quarantine::PREFIX . self::RANDOM;
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $folder . '/.htaccess' ) );
		$this->assertStringContainsString( "\tCP.php\t", (string) file_get_contents( $folder . '/' . Quarantine::MANIFEST ) );

		$record = Quarantine::normalise( $result['quarantine'] );
		$this->assertCount( 1, $record['items'] );
		$id   = (string) array_key_first( $record['items'] );
		$item = $record['items'][ $id ];
		$this->assertSame( 'CP.php', $item['original'] );
		$this->assertSame( hash( 'sha256', '<?php echo 1;' ), $item['sha256'] );
		$this->assertSame( 'C15', $item['check'] );
		$this->assertSame( 'CP.php', $result['log'][0]['target'] );

		// Something new at the original place: "Put back" refuses rather than overwrite.
		$this->put( 'CP.php', 'new' );
		$blocked = Quarantine::restore( $ctx, new Writer(), $record, $id, 3, self::NOW );
		$this->assertSame( 'refused', $blocked['code'] );
		$this->assertSame( 'new', file_get_contents( $this->root . '/CP.php' ) );
		unlink( $this->root . '/CP.php' );

		$back = Quarantine::restore( $ctx, new Writer(), $record, $id, 3, self::NOW );
		$this->assertTrue( $back['ok'] );
		$this->assertSame( '<?php echo 1;', file_get_contents( $this->root . '/CP.php' ) );
		$this->assertSame( array(), $back['record']['items'] );
		$this->assertSame( array(), $this->held() );
		$this->assertSame( 'gone', Quarantine::restore( $ctx, new Writer(), $back['record'], $id, 3, self::NOW )['code'] );
	}

	/**
	 * "Delete for good" removes the stored file and the record entry.
	 */
	public function test_purge(): void {
		$this->put( 'wp-content/uploads/2024/05/x.php', '<?php echo 2;' );
		$ctx      = $this->context();
		$findings = $this->found( new Uploads_Php_Check(), $ctx );
		$result   = Fixer::apply( Fixer::ID_QUARANTINE_FILE, 'C12', self::first( $findings[0] ), $findings, $ctx, new Writer(), 1, self::NOW, array( 'random' => self::RANDOM ) );
		$this->assertTrue( $result['ok'] );
		$record = Quarantine::normalise( $result['quarantine'] );
		$id     = (string) array_key_first( $record['items'] );
		$gone   = Quarantine::purge( $ctx, new Writer(), $record, $id, 1, self::NOW );
		$this->assertTrue( $gone['ok'] );
		$this->assertSame( array(), $this->held() );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/uploads/2024/05/x.php' );
		$this->assertSame( hash( 'sha256', '<?php echo 2;' ), $gone['entry']['sha256'] );
	}

	/**
	 * Plugin copies (C16) and inactive plugins (H3): only ticked folders move, never Earlyward itself.
	 */
	public function test_folders_move_only_when_ticked(): void {
		$this->put( 'wp-content/plugins/old.bak-20260809/old.php', '<?php' );
		$this->put( 'wp-content/plugins/keep.bak/keep.php', '<?php' );
		$ctx      = $this->context();
		$findings = $this->found( new Plugin_Copies_Check(), $ctx );
		$offer    = Fixer::offer( $findings[0] );
		$this->assertSame( Fixer::ID_QUARANTINE_FOLDER, $offer['id'] );
		$this->assertTrue( $offer['pick'] );

		$none = Fixer::apply( Fixer::ID_QUARANTINE_FOLDER, 'C16', 'anything', $findings, $ctx, new Writer(), 1, self::NOW, array( 'random' => self::RANDOM ) );
		$this->assertSame( 'unconfirmed', $none['code'], 'Nothing ticked, nothing moved.' );

		$forged = Fixer::apply(
			Fixer::ID_QUARANTINE_FOLDER,
			'C16',
			'',
			$findings,
			$ctx,
			new Writer(),
			1,
			self::NOW,
			array(
				'random'   => self::RANDOM,
				'selected' => array( 'wp-content/themes' ),
			)
		);
		$this->assertSame( 'unconfirmed', $forged['code'], 'A path the scan does not list is ignored.' );

		$moved = Fixer::apply(
			Fixer::ID_QUARANTINE_FOLDER,
			'C16',
			'',
			$findings,
			$ctx,
			new Writer(),
			1,
			self::NOW,
			array(
				'random'   => self::RANDOM,
				'selected' => array( 'wp-content/plugins/old.bak-20260809' ),
			)
		);
		$this->assertTrue( $moved['ok'] );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/plugins/old.bak-20260809' );
		$this->assertDirectoryExists( $this->root . '/wp-content/plugins/keep.bak' );
		$this->assertCount( 1, $this->held() );

		$this->put( 'wp-content/plugins/idle/idle.php', '<?php' );
		$this->put( 'wp-content/plugins/earlyward/earlyward.php', '<?php' );
		$ctx  = $this->context( array( 'active_plugins' => array( 'other/other.php' ) ) );
		$idle = $this->found( new Unused_Plugins_Check(), $ctx );
		$this->assertFalse( Fixer::offer( $idle[0] )['checked'], 'Inactive plugins start unticked.' );
		$self = Fixer::apply(
			Fixer::ID_QUARANTINE_FOLDER,
			'H3',
			'',
			$idle,
			$ctx,
			new Writer(),
			1,
			self::NOW,
			array(
				'random'   => self::RANDOM,
				'selected' => array( 'wp-content/plugins/earlyward' ),
				'self_dir' => 'earlyward',
			)
		);
		$this->assertSame( 'refused', $self['code'], 'Earlyward never moves itself.' );
		$this->assertDirectoryExists( $this->root . '/wp-content/plugins/earlyward' );
		$ok = Fixer::apply(
			Fixer::ID_QUARANTINE_FOLDER,
			'H3',
			'',
			$idle,
			$ctx,
			new Writer(),
			1,
			self::NOW,
			array(
				'random'     => self::RANDOM,
				'selected'   => array( 'wp-content/plugins/idle' ),
				'quarantine' => $moved['quarantine'],
			)
		);
		$this->assertTrue( $ok['ok'] );
		$this->assertCount( 2, Quarantine::normalise( $ok['quarantine'] )['items'] );

		// A folder comes back whole.
		$record = Quarantine::normalise( $ok['quarantine'] );
		foreach ( $record['items'] as $id => $item ) {
			if ( 'wp-content/plugins/idle' === $item['original'] ) {
				$this->assertTrue( Quarantine::restore( $ctx, new Writer(), $record, (string) $id, 1, self::NOW )['ok'] );
			}
		}
		$this->assertFileExists( $this->root . '/wp-content/plugins/idle/idle.php' );
	}

	/**
	 * Theme files: a theme in use needs the second box; one not in use does not.
	 */
	public function test_theme_file_in_use_needs_second_confirmation(): void {
		$bad = "<?php if(!function_exists('x')){function x(){return 1;}}\n";
		$this->put( 'wp-content/themes/live/functions.php', $bad );
		$this->put( 'wp-content/themes/spare/functions.php', $bad );
		$ctx      = $this->context(
			array(
				'stylesheet' => 'live',
				'template'   => 'live',
			)
		);
		$findings = $this->found( new Theme_Injection_Check(), $ctx );
		$by_theme = array();
		foreach ( $findings as $finding ) {
			$by_theme[ explode( '/', self::first( $finding ) )[2] ] = $finding;
		}
		$this->assertArrayHasKey( 'live', $by_theme, 'The fixture must trip C13.' );
		$this->assertSame( 'yes', $by_theme['live']['evidence'][0]['facts']['in_use'] );
		$this->assertSame( 'no', $by_theme['spare']['evidence'][0]['facts']['in_use'] );
		$this->assertNotSame( '', Fixer::offer( $by_theme['live'] )['extra'] );
		$this->assertSame( '', Fixer::offer( $by_theme['spare'] )['extra'] );

		$live = self::first( $by_theme['live'] );
		$this->assertSame( 'unconfirmed', Fixer::apply( Fixer::ID_QUARANTINE_FILE, 'C13', $live, $findings, $ctx, new Writer(), 1, self::NOW, array( 'random' => self::RANDOM ) )['code'] );
		$this->assertFileExists( $this->root . '/' . $live );
		$this->assertTrue(
			Fixer::apply(
				Fixer::ID_QUARANTINE_FILE,
				'C13',
				$live,
				$findings,
				$ctx,
				new Writer(),
				1,
				self::NOW,
				array(
					'random' => self::RANDOM,
					'extra'  => true,
				)
			)['ok']
		);
		$this->assertFileDoesNotExist( $this->root . '/' . $live );
	}

	/**
	 * Version files (D1) are reported as URLs and move from the site root.
	 */
	public function test_disclosure_files(): void {
		$this->put( 'readme.html', '<html>WordPress</html>' );
		$ctx      = $this->context( array(), array( '/readme.html' => array( 'status' => 200 ) ) );
		$findings = $this->found( new Disclosure_Files_Check(), $ctx );
		$this->assertSame( '/readme.html', self::first( $findings[0] ) );
		$result = Fixer::apply( Fixer::ID_QUARANTINE_FILE, 'D1', self::first( $findings[0] ), $findings, $ctx, new Writer(), 1, self::NOW, array( 'random' => self::RANDOM ) );
		$this->assertTrue( $result['ok'] );
		$this->assertFileDoesNotExist( $this->root . '/readme.html' );
	}

	/**
	 * Crafted findings that point anywhere unusual are refused, whatever the check claims.
	 */
	public function test_unsafe_targets_are_refused(): void {
		$this->put( 'wp-config.php', '<?php' );
		$this->put( 'wp-content/plugins/a/a.php', '<?php' );
		$this->put( 'readme.html', 'x' );
		$this->put( 'notes.txt', 'x' );
		$ctx   = $this->context();
		$cases = array(
			array( 'C15', 'wp-config.php' ),
			array( 'C15', 'wp-content/plugins/a/a.php' ),
			array( 'C12', 'wp-content/plugins/a/a.php' ),
			array( 'C13', 'wp-content/plugins/a/a.php' ),
			array( 'D1', 'notes.txt' ),
			array( 'C15', '../outside.php' ),
		);
		foreach ( $cases as $case ) {
			$finding = array(
				'check'       => $case[0],
				'severity'    => 'warning',
				'evidence'    => array(
					array(
						'location' => $case[1],
						'facts'    => array( 'in_use' => 'no' ),
					),
				),
				'fingerprint' => str_repeat( 'b', 40 ),
			);
			$result  = Fixer::apply( Fixer::ID_QUARANTINE_FILE, $case[0], $case[1], array( $finding ), $ctx, new Writer(), 1, self::NOW, array( 'random' => self::RANDOM ) );
			$this->assertSame( 'refused', $result['code'], implode( ' ', $case ) );
		}
		$this->assertFileExists( $this->root . '/wp-config.php' );
		$this->assertFileExists( $this->root . '/wp-content/plugins/a/a.php' );
		$this->assertSame( array(), $this->held() );

		$folder = array(
			'check'       => 'C16',
			'severity'    => 'warning',
			'evidence'    => array( array( 'location' => 'wp-content/plugins/a/a.php' ), array( 'location' => 'wp-content' ) ),
			'fingerprint' => str_repeat( 'c', 40 ),
		);
		foreach ( array( 'wp-content/plugins/a/a.php', 'wp-content' ) as $where ) {
			$this->assertSame(
				'refused',
				Fixer::apply(
					Fixer::ID_QUARANTINE_FOLDER,
					'C16',
					'',
					array( $folder ),
					$ctx,
					new Writer(),
					1,
					self::NOW,
					array(
						'random'   => self::RANDOM,
						'selected' => array( $where ),
					)
				)['code'],
				$where
			);
		}
	}

	/**
	 * Updates (B8): only ticked plugins and themes go to the updater, never WordPress itself; a failure is reported.
	 */
	public function test_updates(): void {
		$ctx      = $this->context(
			array(
				'_site_transient_update_plugins' => array(
					'response' => array(
						'akismet/akismet.php' => array(),
						'hello/hello.php'     => array(),
					),
				),
				'_site_transient_update_core'    => array( 'updates' => array( array( 'response' => 'upgrade' ) ) ),
			)
		);
		$findings = $this->found( new Updates_Waiting_Check(), $ctx );
		$offer    = Fixer::offer( $findings[0] );
		$this->assertSame( Fixer::ID_UPDATE, $offer['id'] );
		$this->assertFalse( Fixer::pickable( Fixer::ID_UPDATE, 'core:WordPress' ) );

		$calls   = array();
		$upgrade = function ( string $kind, string $key ) use ( &$calls ): array {
			$calls[] = $kind . ':' . $key;
			return array(
				'ok'     => 'hello/hello.php' !== $key,
				'result' => 'hello/hello.php' === $key ? 'failed: test' : 'updated 1 to 2',
			);
		};
		$this->assertSame(
			'unconfirmed',
			Fixer::apply(
				Fixer::ID_UPDATE,
				'B8',
				'',
				$findings,
				$ctx,
				new Writer(),
				1,
				self::NOW,
				array(
					'selected' => array( 'plugin:akismet/akismet.php' ),
					'upgrade'  => $upgrade,
				)
			)['code'],
			'Needs the backup box.'
		);
		$this->assertSame( array(), $calls );

		$result = Fixer::apply(
			Fixer::ID_UPDATE,
			'B8',
			'',
			$findings,
			$ctx,
			new Writer(),
			1,
			self::NOW,
			array(
				'extra'    => true,
				'selected' => array( 'plugin:akismet/akismet.php', 'core:WordPress' ),
				'upgrade'  => $upgrade,
			)
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'plugin:akismet/akismet.php' ), $calls, 'WordPress itself is never updated from here.' );
		$this->assertSame( 'updated 1 to 2', $result['log'][0]['result'] );

		$failed = Fixer::apply(
			Fixer::ID_UPDATE,
			'B8',
			'',
			$findings,
			$ctx,
			new Writer(),
			1,
			self::NOW,
			array(
				'extra'    => true,
				'selected' => array( 'plugin:hello/hello.php' ),
				'upgrade'  => $upgrade,
			)
		);
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( 'failed', $failed['code'] );
	}

	/**
	 * D2 offers a link, not a change; the kill switch hides every button.
	 */
	public function test_link_and_kill_switch(): void {
		$d2 = array(
			'check'    => 'D2',
			'severity' => 'info',
			'evidence' => array( array( 'location' => 'active_plugins' ) ),
		);
		$this->assertSame( Fixer::ID_LINK, Fixer::offer( $d2 )['id'] );
		$this->assertStringStartsWith( 'plugin-install.php', Fixer::offer( $d2 )['url'] );
		$this->assertNotContains( Fixer::ID_LINK, Fixer::ids(), 'A link is not an action that can be posted.' );
		$this->assertNull( Fixer::offer( $d2, true ) );
	}

	/**
	 * Stored names never keep a dot, and a stored record with a bad folder name is ignored.
	 */
	public function test_names_and_record_shape(): void {
		$this->assertSame( 'shell_php5', Quarantine::clean_name( 'shell.php5' ) );
		$this->assertSame( 'htaccess', Quarantine::clean_name( '.htaccess' ) );
		$this->assertSame( 'item', Quarantine::clean_name( '...' ) );
		$this->assertSame( '', Quarantine::normalise( array( 'dir' => '../../etc' ) )['dir'] );
		$this->assertSame( array(), Quarantine::normalise( 'junk' )['items'] );
	}
}
