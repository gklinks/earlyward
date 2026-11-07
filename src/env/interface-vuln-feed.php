<?php
/**
 * Known-vulnerability data for the software installed on this site.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

defined( 'ABSPATH' ) || exit;

/**
 * Vulnerability feed.
 */
interface Vuln_Feed {

	/**
	 * Whether the feed is switched on (an API key is configured). Without one nothing is contacted.
	 *
	 * @return bool
	 */
	public function configured(): bool;

	/**
	 * Feed records that mention any of the given software. Records are in the Wordfence "scanner" format.
	 *
	 * @param array<int, array{type: string, slug: string, name: string, version: string}> $software Installed software.
	 * @return array{error: string, records: array<int, array<string, mixed>>} A non-empty error means the feed could not be read.
	 */
	public function lookup( array $software ): array;
}
