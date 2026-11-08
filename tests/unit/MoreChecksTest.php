<?php
/**
 * B7 (update checks stalled) and C13 (injected theme templates).
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Check;
use Earlyward\Checks\Hosting\Update_Check_Health_Check;
use Earlyward\Checks\Integrity\Theme_Injection_Check;
use Earlyward\Env\Context;

/**
 * Tests.
 */
final class MoreChecksTest extends TestCase {

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
		$this->root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-mc-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/uploads', 0777, true );
		mkdir( $this->root . '/wp-content/themes/good', 0777, true );
		mkdir( $this->root . '/wp-content/themes/bad', 0777, true );
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
	 * Context.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return Context
	 */
	private function context( array $options = array() ): Context {
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
	 * Findings of a check in to_array form.
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
	 * Update information that was refreshed this many days ago.
	 *
	 * @param int $days Days.
	 * @return array<string, mixed>
	 */
	private static function updates( int $days ): array {
		$stamp = Fixture_Site::NOW - $days * 86400;
		return array(
			'_site_transient_update_core'    => (object) array( 'last_checked' => $stamp ),
			'_site_transient_update_plugins' => array( 'last_checked' => $stamp ),
			'_site_transient_update_themes'  => (object) array( 'last_checked' => $stamp ),
		);
	}

	/**
	 * Stale update checks warn; fresh, absent or partly fresh ones do not.
	 */
	public function test_update_checks(): void {
		$check = new Update_Check_Health_Check();
		$this->assertSame( array(), $this->found( $check, $this->context() ), 'Nothing stored: quiet.' );
		$this->assertSame( array(), $this->found( $check, $this->context( self::updates( 1 ) ) ) );
		$f = $this->found( $check, $this->context( self::updates( 6 ) ) );
		$this->assertCount( 1, $f );
		$this->assertSame( 'B7', $f[0]['check'] );
		$this->assertSame( 'warning', $f[0]['severity'] );

		$mixed                                  = self::updates( 6 );
		$mixed['_site_transient_update_themes'] = (object) array( 'last_checked' => Fixture_Site::NOW - 3600 );
		$this->assertSame( array(), $this->found( $check, $this->context( $mixed ) ), 'One fresh list means the connection works.' );
	}

	/**
	 * On a real site the option arrives as an incomplete class (unserialize without classes). It must be read without warnings.
	 */
	public function test_update_checks_read_incomplete_class_objects(): void {
		$stale  = Fixture_Site::NOW - 6 * 86400;
		$object = unserialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- a fixed string, objects disabled.
			'O:8:"stdClass":1:{s:12:"last_checked";i:' . $stale . ';}',
			array( 'allowed_classes' => false )
		);
		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $object );
		$options = array(
			'_site_transient_update_core'    => $object,
			'_site_transient_update_plugins' => $object,
			'_site_transient_update_themes'  => $object,
		);
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- turns warnings into failures in this test.

			static function ( int $no, string $message ): bool {
				throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test, not output.
			}
		);
		try {
			$f = $this->found( new Update_Check_Health_Check(), $this->context( $options ) );
		} finally {
			restore_error_handler();
		}
		$this->assertCount( 1, $f );
	}

	/**
	 * Write a theme file.
	 *
	 * @param string $relative Path under themes.
	 * @param string $content  Contents.
	 */
	private function put( string $relative, string $content ): void {
		file_put_contents( $this->root . '/wp-content/themes/' . $relative, $content );
	}

	/**
	 * A theme file with the kit's compact style is reported; ordinary theme code, a data URI and other files are not.
	 */
	public function test_theme_injection(): void {
		$check = new Theme_Injection_Check();
		$this->put( 'good/functions.php', "<?php\nfunction good_setup() {\n\tadd_theme_support( 'title-tag' );\n}\n" );
		$this->put( 'good/header.php', '<img src="data:image/png;base64,' . str_repeat( 'A', 3000 ) . '">' );
		$this->put( 'bad/style.css', "if(!function_exists('x')){}" );
		$this->assertSame( array(), $this->found( $check, $this->context() ) );

		$this->put( 'bad/functions.php', "<?php if(!function_exists('x')){function x(){return 1;}}\n" );
		$f = $this->found( $check, $this->context() );
		$this->assertCount( 1, $f );
		$this->assertSame( 'wp-content/themes/bad/functions.php', $f[0]['evidence'][0]['location'] );
		$this->assertSame( 'warning', $f[0]['severity'] );
	}
}
