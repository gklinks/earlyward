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
}
