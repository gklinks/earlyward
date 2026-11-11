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
	 * @param string[] $any_of   When not empty, at least one of these substrings must also appear (case-insensitive).
	 * @return array<int, array{id: int, type: string, title: string, content: string}>
	 */
	public function posts_containing( array $needles, array $types, array $statuses, int $limit, array $any_of = array() ): array;

	/**
	 * Post meta values (page-builder data) that contain every one of the given substrings (case-insensitive),
	 * for posts in one of the given statuses. Revisions and autosaves are never returned.
	 *
	 * @param string[] $keys     Meta keys.
	 * @param string[] $needles  Substrings that must all appear.
	 * @param string[] $statuses Post statuses.
	 * @param int      $limit    Maximum rows.
	 * @param string[] $any_of   When not empty, at least one of these substrings must also appear (case-insensitive).
	 * @return array<int, array{id: int, type: string, title: string, key: string, value: string}>
	 */
	public function meta_containing( array $keys, array $needles, array $statuses, int $limit, array $any_of = array() ): array;

	/**
	 * One option, decoded. Serialized objects are never instantiated.
	 *
	 * @param string $name Option name.
	 * @return mixed Null when absent.
	 */
	public function option( string $name );

	/**
	 * Counts of ordinary accounts that look unused. Counts only: no names, addresses or IDs leave this layer.
	 * An account counts when it has one of the roles, was registered before the cutoff, has no sign-in session
	 * on record, and (when WooCommerce records it) was not active since the cutoff. WordPress itself keeps no
	 * last-login time, so this is a hint, never proof.
	 *
	 * @param int      $cutoff Unix time: registered and last active before this.
	 * @param string[] $roles  Role names to look at, for example subscriber and customer.
	 * @return array{total: int, candidates: int, by_role: array<string, int>}
	 */
	public function stale_accounts( int $cutoff, array $roles ): array;
}
