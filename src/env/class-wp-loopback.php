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
	 * Headers only. Redirects are not followed: a redirect to a login page is not a download.
	 *
	 * @param string $path Path starting with "/".
	 * @return array{status: int, type: string, length: int, error: string}
	 */
	public function head( string $path ): array {
		$url  = add_query_arg( 'earlyward_probe', (string) time(), home_url( $path ) );
		$resp = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'user-agent'  => 'Earlyward/' . ( defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : '0' ) . ' (exposure check, headers only)',
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'cookies'     => array(),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'status' => 0,
				'type'   => '',
				'length' => -1,
				'error'  => $resp->get_error_message(),
			);
		}
		$length = wp_remote_retrieve_header( $resp, 'content-length' );
		$type   = wp_remote_retrieve_header( $resp, 'content-type' );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $resp ),
			'type'   => is_string( $type ) ? strtolower( $type ) : '',
			'length' => is_string( $length ) && ctype_digit( $length ) ? (int) $length : -1,
			'error'  => '',
		);
	}

	/**
	 * Response headers of a path, as an anonymous visitor gets them.
	 *
	 * @param string $path Path starting with "/".
	 * @return array{status: int, https: bool, headers: array<string, string>, error: string}
	 */
	public function headers( string $path ): array {
		$url   = add_query_arg( 'earlyward_probe', (string) time(), home_url( $path ) );
		$https = 0 === strpos( $url, 'https://' );
		$resp  = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'user-agent'  => 'Earlyward/' . ( defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : '0' ) . ' (security headers check, headers only)',
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'cookies'     => array(),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'status'  => 0,
				'https'   => $https,
				'headers' => array(),
				'error'   => $resp->get_error_message(),
			);
		}
		$raw     = wp_remote_retrieve_headers( $resp );
		$raw     = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		$headers = array();
		foreach ( $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $resp ),
			'https'   => $https,
			'headers' => $headers,
			'error'   => '',
		);
	}

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
