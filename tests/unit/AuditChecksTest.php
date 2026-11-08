<?php
/**
 * Checks added from the gklinks.com audit: C15 root strays, C16 plugin copies, H3 unused plugins,
 * B8 updates waiting, D1 disclosure files, D2 login protection.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Check;
use Earlyward\Checks\Hosting\Disclosure_Files_Check;
use Earlyward\Checks\Hosting\Login_Protection_Check;
use Earlyward\Checks\Hosting\Updates_Waiting_Check;
use Earlyward\Checks\Housekeeping\Unused_Plugins_Check;
use Earlyward\Checks\Integrity\Plugin_Copies_Check;
use Earlyward\Checks\Integrity\Root_Strays_Check;
use Earlyward\Env\Context;

/**
 * Tests.
 */
final class AuditChecksTest extends TestCase {

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
		$this->root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-au-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/uploads', 0777, true );
		mkdir( $this->root . '/wp-content/plugins', 0777, true );
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
	 * @param array<string, mixed>                $options Options.
	 * @param array<string, array<string, mixed>> $routes  Loopback routes, or null for no loopback.
	 * @return Context
	 */
	private function context( array $options = array(), ?array $routes = null ): Context {
		$extra = array(
			'db'  => new Fixture_Database( array( 'options' => $options ) ),
			'now' => Fixture_Site::NOW,
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
	 * Root strays: core files, normal root files and folders are quiet; strays are reported with the right level.
	 */
	public function test_root_strays(): void {
		foreach ( array( 'index.php', 'wp-config.php', 'wp-login.php', 'xmlrpc.php', 'robots.txt', '.htaccess', 'error_log' ) as $name ) {
			$this->put( $name );
		}
		mkdir( $this->root . '/gklinks' );
		$check = new Root_Strays_Check();
		$this->assertSame( array(), $this->found( $check, $this->context() ) );

		$this->put( 'byp.php', '<?php echo 1;' );
		$this->put( 'up.php', '' );
		$this->put( 'bad.php', "<?php if(!function_exists('x')){function x(){return 1;}}" );
		$this->put( 'as.htaccess', 'Options -Indexes' );
		$this->put( '.1maintenance', '' );
		$by = array();
		foreach ( $this->found( $check, $this->context() ) as $item ) {
			$by[ $item['evidence'][0]['location'] ] = $item['severity'];
		}
		ksort( $by );
		$this->assertSame(
			array(
				'.1maintenance' => 'info',
				'as.htaccess'   => 'info',
				'bad.php'       => 'critical',
				'byp.php'       => 'warning',
				'up.php'        => 'info',
			),
			$by
		);
	}

	/**
	 * Renamed plugin copies warn; normal folders do not.
	 */
	public function test_plugin_copies(): void {
		$this->put( 'wp-content/plugins/akismet/akismet.php' );
		$this->put( 'wp-content/plugins/boldgrid-backup/x.php' );
		$check = new Plugin_Copies_Check();
		$this->assertSame( array(), $this->found( $check, $this->context() ) );
		$this->put( 'wp-content/plugins/pojo-accessibility.bak-20260809/pojo.php' );
		$f = $this->found( $check, $this->context() );
		$this->assertCount( 1, $f );
		$this->assertSame( 'warning', $f[0]['severity'] );
	}

	/**
	 * Inactive plugins are a note; active ones, empty folders and renamed copies are not counted.
	 */
	public function test_unused_plugins(): void {
		$this->put( 'wp-content/plugins/active-one/main.php' );
		$this->put( 'wp-content/plugins/idle-one/main.php' );
		$this->put( 'wp-content/plugins/idle-two/main.php' );
		$this->put( 'wp-content/plugins/assets-only/readme.txt' );
		$this->put( 'wp-content/plugins/old-thing.bak-1/main.php' );
		$check = new Unused_Plugins_Check();
		$this->assertSame( array(), $this->found( $check, $this->context() ), 'Unknown active list: quiet.' );
		$f = $this->found( $check, $this->context( array( 'active_plugins' => array( 'active-one/main.php' ) ) ) );
		$this->assertCount( 1, $f );
		$this->assertSame( 'H3', $f[0]['check'] );
		$this->assertSame( 'info', $f[0]['severity'] );
		$this->assertCount( 2, $f[0]['evidence'] );
	}

	/**
	 * Updates waiting, including objects that arrive as incomplete classes.
	 */
	public function test_updates_waiting(): void {
		$check = new Updates_Waiting_Check();
		$this->assertSame( array(), $this->found( $check, $this->context() ) );
		$this->assertSame( array(), $this->found( $check, $this->context( array( '_site_transient_update_plugins' => array( 'response' => array() ) ) ) ) );
		$object = unserialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- a fixed string, objects disabled.
			'O:8:"stdClass":1:{s:11:"new_version";s:3:"2.0";}',
			array( 'allowed_classes' => false )
		);
		$f      = $this->found(
			$check,
			$this->context(
				array(
					'_site_transient_update_plugins' => (object) array( 'response' => array( 'akismet/akismet.php' => $object ) ),
					'_site_transient_update_core'    => array( 'updates' => array( array( 'response' => 'upgrade' ) ) ),
				)
			)
		);
		$this->assertCount( 1, $f );
		$this->assertCount( 2, $f[0]['evidence'] );
		$this->assertSame( 'info', $f[0]['severity'] );
	}

	/**
	 * Disclosure files: served files are noted; blocked, absent files and a missing loopback are quiet.
	 */
	public function test_disclosure_files(): void {
		$check = new Disclosure_Files_Check();
		$this->put( 'readme.html' );
		$this->assertSame( array(), $this->found( $check, $this->context() ), 'No loopback: quiet.' );
		$this->assertSame( array(), $this->found( $check, $this->context( array(), array( '/readme.html' => array( 'status' => 403 ) ) ) ) );
		$this->assertSame( array(), $this->found( $check, $this->context( array(), array( '/license.txt' => array( 'status' => 200 ) ) ) ), 'File not on disk.' );
		$f = $this->found( $check, $this->context( array(), array( '/readme.html' => array( 'status' => 200 ) ) ) );
		$this->assertCount( 1, $f );
		$this->assertSame( 'D1', $f[0]['check'] );
	}

	/**
	 * Login protection: any known plugin keeps it quiet.
	 */
	public function test_login_protection(): void {
		$check = new Login_Protection_Check();
		$this->assertSame( array(), $this->found( $check, $this->context() ), 'Unknown plugin list: quiet.' );
		$this->assertSame( array(), $this->found( $check, $this->context( array( 'active_plugins' => array( 'wordfence/wordfence.php', 'x/x.php' ) ) ) ) );
		$f = $this->found( $check, $this->context( array( 'active_plugins' => array( 'x/x.php' ) ) ) );
		$this->assertCount( 1, $f );
		$this->assertSame( 'info', $f[0]['severity'] );
	}
}
