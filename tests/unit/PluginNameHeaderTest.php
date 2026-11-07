<?php
/**
 * C3 shows a must-use plugin's name in the finding title. It must read the header the way
 * WordPress does, or titles end in a stray comment close (found in a real run on a test site).
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Integrity\Unexpected_Php_Check;

/**
 * Plugin Name header parsing.
 */
final class PluginNameHeaderTest extends TestCase {

	/**
	 * Header styles and the name WordPress would show.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function headers(): array {
		return array(
			'docblock line'       => array( "<?php\n/**\n * Plugin Name: Site Tools\n */", 'Site Tools' ),
			'one-line comment'    => array( "<?php /* Plugin Name: Site Tools */\n", 'Site Tools' ),
			'one-line with close' => array( "<?php /* Plugin Name: Site Tools (local) */ ?>\n", 'Site Tools (local)' ),
			'hash comment'        => array( "<?php\n# Plugin Name: Site Tools\n", 'Site Tools' ),
			'no header'           => array( "<?php\necho 1;\n", '' ),
			'keeps an inner star' => array( "<?php\n * Plugin Name: 5* Reviews\n", '5* Reviews' ),
		);
	}

	/**
	 * The name matches WordPress's own reading.
	 *
	 * @dataProvider headers
	 * @param string $code     Source.
	 * @param string $expected Name.
	 */
	public function test_name( string $code, string $expected ): void {
		$this->assertSame( $expected, Unexpected_Php_Check::plugin_name( $code ) );
	}
}
