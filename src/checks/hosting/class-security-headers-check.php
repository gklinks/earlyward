<?php
/**
 * D3: the home page is sent without the standard browser-protection headers.
 *
 * One header-only request to the site's own home page, as an anonymous visitor gets it (through Cloudflare
 * or any cache in front, which is what counts). Missing headers weaken defences against clickjacking,
 * content-type sniffing and downgrade attacks; none of them is an attack in itself, so this is a
 * medium-confidence note that never emails. Detection only: a header manager is a later hardening toggle.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Hosting;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Security headers check.
 */
final class Security_Headers_Check implements Check {

	const ID = 'D3';

	/**
	 * Six months, the usual minimum for HSTS to be worth having.
	 */
	const HSTS_MIN_SECONDS = 15552000;

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$loopback = $ctx->loopback();
		if ( null === $loopback ) {
			return array();
		}
		$resp = $loopback->headers( '/' );
		if ( 200 !== $resp['status'] ) {
			return array(); // A failed or redirected request says nothing about the real page; A1 reports outages.
		}
		$h        = $resp['headers'];
		$evidence = array();

		if ( $resp['https'] ) {
			$hsts = $h['strict-transport-security'] ?? '';
			if ( '' === $hsts ) {
				$evidence[] = self::item( 'strict-transport-security', 'missing', '' );
			} elseif ( 1 === preg_match( '/max-age\s*=\s*"?(\d+)/i', $hsts, $m ) && (int) $m[1] < self::HSTS_MIN_SECONDS ) {
				$evidence[] = self::item( 'strict-transport-security', 'too short: ' . (int) $m[1] . ' seconds, at least ' . self::HSTS_MIN_SECONDS . ' wanted', $hsts );
			}
		}
		if ( 'nosniff' !== strtolower( trim( $h['x-content-type-options'] ?? '' ) ) ) {
			$evidence[] = self::item( 'x-content-type-options', 'missing (nosniff)', $h['x-content-type-options'] ?? '' );
		}
		$csp = $h['content-security-policy'] ?? '';
		if ( '' === ( $h['x-frame-options'] ?? '' ) && false === stripos( $csp, 'frame-ancestors' ) ) {
			$evidence[] = self::item( 'x-frame-options', 'missing (and no frame-ancestors in a content security policy)', '' );
		}
		if ( '' === ( $h['referrer-policy'] ?? '' ) ) {
			$evidence[] = self::item( 'referrer-policy', 'missing', '' );
		}
		if ( '' === $csp && '' === ( $h['content-security-policy-report-only'] ?? '' ) ) {
			$evidence[] = self::item( 'content-security-policy', 'missing (try report-only first)', '' );
		}

		if ( empty( $evidence ) ) {
			return array();
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_MEDIUM,
				__( 'The home page is sent without some standard security headers', 'earlyward' ),
				__( 'Browsers use these headers to refuse clickjacking, content-type confusion and downgrades to plain HTTP. Missing ones are not an attack, but they remove a cheap layer of defence.', 'earlyward' ),
				$evidence,
				array(
					__( 'Add the headers in your web server or in Cloudflare (Rules, Transform Rules, Modify Response Header). They apply to every page at once.', 'earlyward' ),
					__( 'Start HSTS with a short max-age (for example one day), check that every part of the site works over HTTPS, then raise it to at least six months.', 'earlyward' ),
					__( 'Add Content-Security-Policy-Report-Only first and watch for blocked items before enforcing a policy; a strict policy can break themes, forms and payments.', 'earlyward' ),
				)
			),
		);
	}

	/**
	 * One evidence item.
	 *
	 * @param string $header Header name.
	 * @param string $state  What is wrong.
	 * @param string $value  Value seen, or ''.
	 * @return Evidence
	 */
	private static function item( string $header, string $state, string $value ): Evidence {
		$facts = array( 'problem' => $state );
		if ( '' !== $value ) {
			$facts['value'] = $value;
		}
		return new Evidence( 'header', $header, 0, '', $facts );
	}
}
