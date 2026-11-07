<?php
/**
 * Code trait detection. Malicious samples are assembled at runtime so that no file in this
 * repository contains a working malware pattern (which antivirus tools would quarantine).
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Code_Traits;

/**
 * Code traits.
 */
final class CodeTraitsTest extends TestCase {

	/**
	 * Build a sample from parts.
	 *
	 * @param string ...$parts Parts.
	 * @return string
	 */
	private static function s( string ...$parts ): string {
		return implode( '', $parts );
	}

	/**
	 * Malicious samples and the trait each must trigger.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function malicious(): array {
		return array(
			'eval of decoded'      => array( self::s( '<?php ', 'ev', 'al(', 'base6', '4_decode($x));' ), 'eval_of_decoded' ),
			'eval of gzinflate'    => array( self::s( '<?php $a=1; ', 'ev', 'al (', 'gzin', 'flate($b));' ), 'eval_of_decoded' ),
			'request to executor'  => array( self::s( '<?php ', 'sys', 'tem($', '_GET["c"]);' ), 'request_to_executor' ),
			'compact exists check' => array( self::s( '<?php if(!function', "_exists('x')){}" ), 'compact_function_exists' ),
			'long blob'            => array( '<?php $p = "' . str_repeat( 'QUJD', 600 ) . '";', 'long_encoded_blob' ),
			'chr assembly'         => array( '<?php $f = chr(97).chr(115).chr(115).chr(101).chr(114).chr(116).chr(40);', 'chr_assembled_strings' ),
		);
	}

	/**
	 * Each malicious sample triggers its trait.
	 *
	 * @dataProvider malicious
	 * @param string $code     Code.
	 * @param string $expected Expected trait.
	 */
	public function test_malicious( string $code, string $expected ): void {
		$this->assertArrayHasKey( $expected, Code_Traits::find( $code ) );
	}

	/**
	 * Ordinary WordPress code that looks similar on the surface.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function benign(): array {
		return array(
			'wpcs function_exists'  => array( "<?php if ( ! function_exists( 'x' ) ) { function x() {} }" ),
			'base64 encode only'    => array( "<?php echo 'data:image/png;base64,' . base64_encode( \$img );" ),
			'decode without eval'   => array( '<?php $data = json_decode( base64_decode( $token ), true );' ),
			'evaluate in a name'    => array( '<?php function evaluate_score( $x ) { return $x; } $y = base64_decode( $z );' ),
			'sanitised request'     => array( "<?php \$id = absint( \$_GET['id'] ); echo esc_html( \$id );" ),
			'short base64 constant' => array( '<?php const ICON = "' . str_repeat( 'QUJD', 100 ) . '";' ),
			'index guard'           => array( "<?php\n// Silence is golden.\n" ),
			'two chr calls'         => array( '<?php $nl = chr(13) . chr(10);' ),
		);
	}

	/**
	 * No benign sample triggers any trait.
	 *
	 * @dataProvider benign
	 * @param string $code Code.
	 */
	public function test_benign( string $code ): void {
		$this->assertSame( array(), Code_Traits::find( $code ) );
	}
}
