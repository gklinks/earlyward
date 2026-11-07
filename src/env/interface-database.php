<?php
/**
 * The only database questions checks may ask. Narrow on purpose: no raw SQL
 * reaches a check, and a fixture can answer every question from JSON.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only database access.
 */
interface Database {

	/**
	 * Users holding the administrator role on this site.
	 *
	 * @return array<int, array{id: int, login: string, email: string, registered: string}>
	 */
	public function administrators(): array;

	/**
	 * One user meta value, decoded. Serialized objects are never instantiated.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @return mixed Null when absent.
	 */
	public function user_meta( int $user_id, string $key );

	/**
	 * Posts whose content contains every one of the given substrings (case-insensitive).
	 *
	 * @param string[] $needles  Substrings that must all appear.
	 * @param string[] $types    Post types.
	 * @param string[] $statuses Post statuses.
	 * @param int      $limit    Maximum rows.
	 * @return array<int, array{id: int, type: string, title: string, content: string}>
	 */
	public function posts_containing( array $needles, array $types, array $statuses, int $limit ): array;

	/**
	 * One option, decoded. Serialized objects are never instantiated.
	 *
	 * @param string $name Option name.
	 * @return mixed Null when absent.
	 */
	public function option( string $name );
}
