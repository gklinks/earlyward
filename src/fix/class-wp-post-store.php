<?php
/**
 * Post store on the live site. Raw reads straight from the tables (so what the fix sees is what the C9 check saw),
 * writes with the least that works: one UPDATE of post_content (no revision, post_modified untouched) and
 * update_post_meta for page-builder data. Only the Fixer's "Remove hidden links" action uses it.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- raw reads must not be hidden by caches or filters; the one write is a confirmed, logged fix.

/**
 * $wpdb-backed post store.
 */
final class Wp_Post_Store implements Post_Store {

	/**
	 * Post meta that only holds a rendered copy of the page, and is dropped so the page is built again.
	 */
	const CACHE_META = array( '_elementor_element_cache', '_elementor_css' );

	/**
	 * Read one post.
	 *
	 * @param int      $id        Post ID.
	 * @param string[] $meta_keys Page-builder meta keys.
	 * @return array{id: int, type: string, title: string, status: string, modified: string, content: string, meta: array<string, string>}|null
	 */
	public function load( int $id, array $meta_keys ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ID, post_type, post_title, post_status, post_modified_gmt, post_content FROM %i WHERE ID = %d', $wpdb->posts, $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) || in_array( (string) $row['post_type'], array( 'revision', 'auto-draft' ), true ) ) {
			return null;
		}
		$meta = array();
		foreach ( $meta_keys as $key ) {
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $wpdb->postmeta, $id, $key ) );
			if ( is_string( $value ) ) {
				$meta[ $key ] = $value;
			}
		}
		return array(
			'id'       => (int) $row['ID'],
			'type'     => (string) $row['post_type'],
			'title'    => (string) $row['post_title'],
			'status'   => (string) $row['post_status'],
			'modified' => (string) $row['post_modified_gmt'],
			'content'  => (string) $row['post_content'],
			'meta'     => $meta,
		);
	}

	/**
	 * Write content and meta.
	 *
	 * @param int                   $id      Post ID.
	 * @param string|null           $content New content, or null.
	 * @param array<string, string> $meta    New meta values.
	 * @return bool
	 */
	public function save( int $id, ?string $content, array $meta ): bool {
		global $wpdb;
		$ok = true;
		if ( null !== $content ) {
			$ok = false !== $wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, wp_slash( $value ) ); // Returns false when nothing changed, so read it back instead.
			$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $wpdb->postmeta, $id, $key ) );
			$ok     = $ok && $stored === $value;
		}
		return $ok;
	}

	/**
	 * Drop cached renderings of the post.
	 *
	 * @param int $id Post ID.
	 */
	public function clear_caches( int $id ): void {
		foreach ( self::CACHE_META as $key ) {
			delete_post_meta( $id, $key );
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && is_object( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		clean_post_cache( $id );
	}
}
