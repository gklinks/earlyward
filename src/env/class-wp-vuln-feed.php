<?php
/**
 * Wordfence Intelligence vulnerability feed (scanner format), through the WordPress HTTP API.
 *
 * Opt-in: nothing is contacted unless EARLYWARD_WORDFENCE_KEY is defined in wp-config.php. The request
 * is a plain GET of the public feed; no information about this site is sent. The download is reduced to
 * the records that mention installed software and cached for 12 hours.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress-backed feed.
 */
final class Wp_Vuln_Feed implements Vuln_Feed {

	const URL         = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';
	const OPTION      = 'earlyward_vuln_cache';
	const TTL         = 43200; // 12 hours.
	const RETRY_AFTER = 3600;  // After a failure, wait an hour before asking again.
	const STALE_MAX   = 604800; // Use an old copy for up to 7 days while the feed is unreachable.

	/**
	 * Whether a key is configured.
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return '' !== $this->key();
	}

	/**
	 * Records for the installed software.
	 *
	 * @param array<int, array{type: string, slug: string, name: string, version: string}> $software Installed software.
	 * @return array{error: string, records: array<int, array<string, mixed>>}
	 */
	public function lookup( array $software ): array {
		$ids = array();
		foreach ( $software as $item ) {
			$ids[] = $item['type'] . ':' . $item['slug'];
		}
		sort( $ids );
		$set   = sha1( implode( '|', $ids ) );
		$now   = time();
		$cache = get_option( self::OPTION );
		$cache = is_array( $cache ) ? $cache : array();

		$fresh = isset( $cache['fetched'], $cache['set'], $cache['records'] ) && $cache['set'] === $set && $now - (int) $cache['fetched'] < self::TTL;
		if ( $fresh ) {
			return array(
				'error'   => '',
				'records' => (array) $cache['records'],
			);
		}
		$backoff = isset( $cache['failed'] ) && $now - (int) $cache['failed'] < self::RETRY_AFTER;
		$result  = $backoff ? array( 'error' => (string) ( $cache['error'] ?? 'The feed could not be read.' ) ) : $this->download( $ids );

		if ( isset( $result['records'] ) ) {
			update_option(
				self::OPTION,
				array(
					'fetched' => $now,
					'set'     => $set,
					'records' => $result['records'],
				),
				false
			);
			return array(
				'error'   => '',
				'records' => $result['records'],
			);
		}
		if ( ! $backoff ) {
			$cache['failed'] = $now;
			$cache['error']  = $result['error'];
			update_option( self::OPTION, $cache, false );
		}
		// Keep working from an older copy of the same set; say nothing is wrong until it is a week old.
		if ( isset( $cache['fetched'], $cache['set'], $cache['records'] ) && $cache['set'] === $set && $now - (int) $cache['fetched'] < self::STALE_MAX ) {
			return array(
				'error'   => '',
				'records' => (array) $cache['records'],
			);
		}
		return array(
			'error'   => $result['error'],
			'records' => array(),
		);
	}

	/**
	 * Download the feed and keep only records for installed software.
	 *
	 * @param string[] $ids Installed "type:slug" values.
	 * @return array{records: array<int, array<string, mixed>>}|array{error: string}
	 */
	private function download( array $ids ): array {
		$tmp = wp_tempnam( 'earlyward-feed' );
		if ( ! $tmp ) {
			return array( 'error' => 'Could not create a temporary file for the vulnerability feed.' );
		}
		try {
			$resp = wp_remote_get(
				self::URL,
				array(
					'timeout'    => 60,
					'stream'     => true,
					'filename'   => $tmp,
					'user-agent' => 'Earlyward/' . ( defined( 'EARLYWARD_VERSION' ) ? EARLYWARD_VERSION : '0' ) . ' (vulnerability feed)',
					'headers'    => array( 'Authorization' => 'Bearer ' . $this->key() ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return array( 'error' => 'Vulnerability feed unreachable: ' . $resp->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 401 === $code || 403 === $code ) {
				return array( 'error' => 'The vulnerability feed rejected the API key (HTTP ' . $code . '). Check EARLYWARD_WORDFENCE_KEY.' );
			}
			if ( 429 === $code ) {
				return array( 'error' => 'The vulnerability feed is rate limiting this site (HTTP 429).' );
			}
			if ( 200 !== $code ) {
				return array( 'error' => 'The vulnerability feed answered HTTP ' . $code . '.' );
			}
			// Decoding needs several times the file size in memory. Refuse rather than crash the site.
			$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
			$size  = (int) filesize( $tmp );
			if ( $limit > 0 && $size * 6 + memory_get_usage() > $limit ) {
				return array( 'error' => 'The vulnerability feed is too large to read within this PHP memory limit.' ); // ponytail: no streaming parser; add one if hosts hit this.
			}
			$data = json_decode( (string) file_get_contents( $tmp ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
			if ( ! is_array( $data ) ) {
				return array( 'error' => 'The vulnerability feed returned data that could not be read.' );
			}
			$wanted  = array_flip( $ids );
			$records = array();
			foreach ( $data as $record ) {
				if ( ! is_array( $record ) || ! isset( $record['software'] ) || ! is_array( $record['software'] ) ) {
					continue;
				}
				$mine = array();
				foreach ( $record['software'] as $sw ) {
					if ( is_array( $sw ) && isset( $sw['type'], $sw['slug'], $wanted[ $sw['type'] . ':' . $sw['slug'] ] ) ) {
						$mine[] = $sw;
					}
				}
				if ( array() !== $mine ) {
					$record['software'] = $mine;
					unset( $record['copyrights']['defiant']['license'] ); // Long text; the notice and record link are kept.
					$records[] = $record;
				}
			}
			return array( 'records' => $records );
		} finally {
			unset( $data );
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * The API key, or ''.
	 *
	 * @return string
	 */
	private function key(): string {
		return defined( 'EARLYWARD_WORDFENCE_KEY' ) && is_string( EARLYWARD_WORDFENCE_KEY ) ? trim( EARLYWARD_WORDFENCE_KEY ) : '';
	}
}
