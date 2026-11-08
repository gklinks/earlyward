<?php
/**
 * Requests to this site's own front end, the only network access checks get by default.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback HTTP.
 */
interface Loopback {

	/**
	 * Fetch a path on this site as an anonymous visitor, bypassing page caches where possible.
	 *
	 * @param string $path Path starting with "/".
	 * @return array{status: int, body: string, error: string, ms: int} status 0 means the request failed.
	 */
	public function get( string $path ): array;

	/**
	 * Ask this site for the headers of a path (HTTP HEAD, no body, redirects not followed).
	 * Used to find out whether a file is really served, without downloading it.
	 *
	 * @param string $path Path starting with "/".
	 * @return array{status: int, type: string, length: int, error: string} status 0 means the request failed; length is -1 when the server sent none.
	 */
	public function head( string $path ): array;

	/**
	 * Ask this site for the response headers of a path (HTTP HEAD, redirects not followed), as a visitor would see them.
	 *
	 * @param string $path Path starting with "/".
	 * @return array{status: int, https: bool, headers: array<string, string>, error: string} Header names in lower case; status 0 means the request failed.
	 */
	public function headers( string $path ): array;
}
