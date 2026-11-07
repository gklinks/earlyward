<?php
/**
 * Loopback via the WordPress HTTP API, to home_url() only.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress loopback.
 */
final class Wp_Loopback implements Loopback {

	const MAX_BYTES = 2097152;

	/**
	 * Fetch.
	 *
	 * @param string $path Path starting with "/".
	 * @return array{status: int, body: string, error: string, ms: int}
	 */
	public function get( string $path ): array {
		// A unique query string gets past most full-page caches, so we see the real page.
		$url   = add_query_arg( 'earlyward_probe', (string) time(), home_url( $path ) );
		$start = microtime( true );
		$resp  = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Earlyward/' . ( defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : '0' ) . ' (loopback health check)',
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
				'cookies'             => array(),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $resp ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => $resp->get_error_message(),
				'ms'     => $ms,
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $resp ),
			'body'   => (string) wp_remote_retrieve_body( $resp ),
			'error'  => '',
			'ms'     => $ms,
		);
	}
}
