<?php
/**
 * Named, explainable traits of suspicious PHP. Each trait is a reason a human can read,
 * not a score. Deliberately small: every trait here has a benign fixture proving that
 * ordinary WordPress code does not trigger it.
 *
 * Never treat "small file" as a signal: WordPress ships tiny index.php guard files everywhere.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Code trait detector.
 */
final class Code_Traits {

	/**
	 * How much of a file to read. The real kit hid its signature past a fake plugin header,
	 * so a few hundred bytes reads as clean.
	 */
	const READ_BYTES = 65536;

	/**
	 * Find traits in PHP source.
	 *
	 * @param string $code Source (possibly truncated).
	 * @return array<string, string> trait => short evidence excerpt
	 */
	public static function find( string $code ): array {
		$found = array();

		// Decode-and-run: eval() fed by a decoder.
		if ( preg_match( '/\beval\s*\(/i', $code )
			&& preg_match( '/\b(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|hex2bin)\s*\(/i', $code, $m ) ) {
			$found['eval_of_decoded'] = 'eval() together with ' . strtolower( $m[1] ) . '()';
		}

		// Request data straight into an executor.
		if ( preg_match( '/\b(eval|assert|system|exec|passthru|shell_exec|popen|proc_open)\s*\(\s*(?:base64_decode\s*\(\s*)?\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/i', $code, $m ) ) {
			$found['request_to_executor'] = $m[0];
		}

		// The kit's own style: no space after the paren. WordPress Coding Standards always put one.
		if ( false !== strpos( $code, "if(!function_exists('" ) ) {
			$found['compact_function_exists'] = "if(!function_exists('";
		}

		// A long encoded blob inside a string literal.
		if ( preg_match( '/[\'"][A-Za-z0-9+\/]{2000,}={0,2}[\'"]/', $code, $m ) ) {
			$found['long_encoded_blob'] = 'encoded string of ' . strlen( $m[0] ) . ' characters';
		}

		// Names assembled from character codes so that grep finds nothing.
		if ( preg_match( '/(?:chr\s*\(\s*\d+\s*\)\s*\.\s*){6,}/i', $code ) ) {
			$found['chr_assembled_strings'] = 'string built from 6+ chr() calls';
		}

		return $found;
	}
}
