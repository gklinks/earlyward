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
	 * Post types that are not content people write (history, uploads, menus, settings blobs).
	 */
	const NON_CONTENT_TYPES = array( 'revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_block' );

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

	/**
	 * Saved revisions of one post (post type revision, autosaves included), newest first, with the page-builder
	 * meta stored on each revision.
	 *
	 * @param int      $post_id   Parent post ID.
	 * @param string[] $meta_keys Builder meta keys to read from each revision.
	 * @param int      $limit     Maximum revisions.
	 * @return array<int, array{id: int, author_id: int, author_login: string, date: string, content: string, meta: array<string, string>}> "date" is UTC, "Y-m-d H:i:s"; "author_login" is empty when the user no longer exists.
	 */
	public function revisions( int $post_id, array $meta_keys, int $limit ): array;

	/**
	 * Terms (categories, tags, product categories, any other taxonomy except menus, link categories and post formats),
	 * including empty ones.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array{id: int, name: string, slug: string, taxonomy: string, count: int}>
	 */
	public function terms( int $limit ): array;

	/**
	 * Titles and slugs of content posts in the given statuses (never revisions, uploads, menus or other non-content types).
	 *
	 * @param string[] $statuses Post statuses.
	 * @param int      $limit    Maximum rows.
	 * @return array<int, array{id: int, type: string, status: string, title: string, slug: string}>
	 */
	public function post_titles( array $statuses, int $limit ): array;

	/**
	 * Accounts that hold one of the roles: ID, login and display name only.
	 *
	 * @param string[] $roles Role names.
	 * @param int      $limit Maximum rows.
	 * @return array<int, array{id: int, login: string, display_name: string}>
	 */
	public function user_names( array $roles, int $limit ): array;
}
