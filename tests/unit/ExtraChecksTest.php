<?php
/**
 * B5 (PHP runtime), B6 (disk space), C12 (PHP in uploads) and H2 (backup freshness):
 * each has a case that must report and cases that must stay quiet.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Check;
use Earlyward\Checks\Hosting\Disk_Space_Check;
use Earlyward\Checks\Hosting\Php_Runtime_Check;
use Earlyward\Checks\Housekeeping\Backup_Freshness_Check;
use Earlyward\Checks\Integrity\Uploads_Php_Check;
use Earlyward\Env\Context;

/**
 * Tests.
 */
final class ExtraChecksTest extends TestCase {

	const GB = 1073741824;

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
		$this->root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-xc-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/uploads/2026/11', 0777, true );
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
	 * Code with a malware trait: the compact style of the real kit (no space after the paren).
	 *
	 * @return string
	 */
	private static function trait_code(): string {
		return "<?php if(!function_exists('x')){function x(){return 1;}}";
	}

	/**
	 * Context.
	 *
	 * @param array<string, string> $ini     Ini values.
	 * @param array<string, mixed>  $options Options.
	 * @return Context
	 */
	private function context( array $ini = array(), array $options = array() ): Context {
		return new Context(
			$this->root,
			$this->root . '/wp-content',
			$this->root . '/wp-content/uploads',
			$ini + array( 'earlyward.sapi' => 'fpm-fcgi' ),
			array(
				'db'  => new Fixture_Database( array( 'options' => $options ) ),
				'now' => Fixture_Site::NOW,
			)
		);
	}

	/**
	 * Run one check.
	 *
	 * @param Check   $check Check.
	 * @param Context $ctx   Context.
	 * @return array<int, array<string, mixed>>
	 */
	private function run_check( Check $check, Context $ctx ): array {
		$out = array();
		foreach ( $check->run( $ctx ) as $finding ) {
			$out[] = $finding->to_array();
		}
		return $out;
	}

	/**
	 * Severity of the first finding for a PHP version, or null when quiet.
	 *
	 * @param string $version PHP version.
	 * @return string|null
	 */
	private function php_severity( string $version ): ?string {
		$f = $this->run_check( new Php_Runtime_Check(), $this->context( array( 'earlyward.php_version' => $version ) ) );
		return empty( $f ) ? null : (string) $f[0]['severity'];
	}

	/**
	 * PHP versions: past end of life warns, close to it notes, supported and unknown stay quiet.
	 */
	public function test_php_version(): void {
		$this->assertSame( 'warning', $this->php_severity( '7.4.33' ) );
		$this->assertSame( 'warning', $this->php_severity( '8.1.2' ) );
		$this->assertSame( 'warning', $this->php_severity( '5.6.40' ) );
		$this->assertSame( 'info', $this->php_severity( '8.2.20' ) );
		$this->assertNull( $this->php_severity( '8.3.36' ) );
		$this->assertNull( $this->php_severity( '9.0.1' ) );
		$this->assertSame( array(), $this->run_check( new Php_Runtime_Check(), $this->context() ), 'No value: quiet.' );
	}

	/**
	 * Memory limit.
	 */
	public function test_memory_limit(): void {
		$check = new Php_Runtime_Check();
		$this->assertCount( 1, $this->run_check( $check, $this->context( array( 'memory_limit' => '32M' ) ) ) );
		$this->assertSame( array(), $this->run_check( $check, $this->context( array( 'memory_limit' => '128M' ) ) ) );
		$this->assertSame( array(), $this->run_check( $check, $this->context( array( 'memory_limit' => '-1' ) ) ) );
		$this->assertSame( array(), $this->run_check( $check, $this->context( array( 'memory_limit' => 'garbage' ) ) ) );
		$this->assertSame( self::GB, Php_Runtime_Check::bytes( '1G' ) );
		$this->assertSame( 524288, Php_Runtime_Check::bytes( '512k' ) );
	}

	/**
	 * Severity of the disk finding for given free and total bytes, or null when quiet.
	 *
	 * @param int $free  Free bytes.
	 * @param int $total Total bytes.
	 * @return string|null
	 */
	private function disk_severity( int $free, int $total ): ?string {
		$ini = array(
			'earlyward.disk_free'  => (string) $free,
			'earlyward.disk_total' => (string) $total,
		);
		$f   = $this->run_check( new Disk_Space_Check(), $this->context( $ini ) );
		return empty( $f ) ? null : (string) $f[0]['severity'];
	}

	/**
	 * Disk space.
	 */
	public function test_disk_space(): void {
		$this->assertSame( array(), $this->run_check( new Disk_Space_Check(), $this->context() ), 'No numbers: quiet.' );
		$this->assertNull( $this->disk_severity( 50 * self::GB, 100 * self::GB ) );
		$this->assertNull( $this->disk_severity( 100 * self::GB, 4000 * self::GB ), '2.5% free of a huge disk is still 100 GiB.' );
		$this->assertSame( 'warning', $this->disk_severity( 8 * self::GB, 100 * self::GB ) );
		$this->assertSame( 'critical', $this->disk_severity( 2 * self::GB, 100 * self::GB ) );
		$this->assertSame( 'critical', $this->disk_severity( 5000000, 4000 * self::GB ) );
	}

	/**
	 * Write a file under the site root.
	 *
	 * @param string $relative Path.
	 * @param string $content  Contents.
	 */
	private function put( string $relative, string $content ): void {
		file_put_contents( $this->root . '/' . $relative, $content );
	}

	/**
	 * PHP in uploads: malware traits are critical, plain PHP is a warning, placeholders and images are quiet.
	 */
	public function test_uploads_php(): void {
		$this->put( 'wp-content/uploads/index.php', "<?php\n// Silence is golden.\n" );
		$this->put( 'wp-content/uploads/2026/11/photo.jpg', 'not php' );
		$this->put( 'wp-content/uploads/2026/11/readme.txt', self::trait_code() );
		$check = new Uploads_Php_Check();
		$this->assertSame( array(), $this->run_check( $check, $this->context() ) );

		$this->put( 'wp-content/uploads/2026/11/bad.php', self::trait_code() );
		$this->put( 'wp-content/uploads/helper.php', '<?php function helper() { return 1; }' );
		$by = array();
		foreach ( $this->run_check( $check, $this->context() ) as $item ) {
			$by[ $item['evidence'][0]['location'] ] = $item['severity'];
		}
		ksort( $by );
		$this->assertSame(
			array(
				'wp-content/uploads/2026/11/bad.php' => 'critical',
				'wp-content/uploads/helper.php'      => 'warning',
			),
			$by
		);
	}

	/**
	 * A placeholder that carries code is not a placeholder.
	 */
	public function test_uploads_index_with_code_is_reported(): void {
		$this->put( 'wp-content/uploads/index.php', '<?php include "/tmp/x";' );
		$this->assertCount( 1, $this->run_check( new Uploads_Php_Check(), $this->context() ) );
	}

	/**
	 * Backup freshness.
	 */
	public function test_backup_freshness(): void {
		$check = new Backup_Freshness_Check();
		$now   = Fixture_Site::NOW;
		$day   = 86400;
		$this->assertSame( array(), $this->run_check( $check, $this->context() ), 'No backup plugin history: quiet.' );
		$this->assertSame( array(), $this->run_check( $check, $this->context( array(), array( 'updraft_backup_history' => 'garbage' ) ) ) );
		$this->assertSame( array(), $this->run_check( $check, $this->context( array(), array( 'updraft_backup_history' => array( $now - 2 * $day => array() ) ) ) ) );
		$f = $this->run_check(
			$check,
			$this->context(
				array(),
				array(
					'updraft_backup_history' => array(
						$now - 40 * $day => array(),
						$now - 90 * $day => array(),
					),
				)
			)
		);
		$this->assertCount( 1, $f );
		$this->assertSame( 'H2', $f[0]['check'] );
		$this->assertSame( 'info', $f[0]['severity'] );
	}
}
