<?php
/**
 * The only way the "Remove hidden links" fix (C9) reads and changes a post and its page-builder data.
 * A test answers from memory; the live site answers through Wp_Post_Store.
 *
 * @package Earlyward
 */

namespace Earlyward\Fix;

defined( 'ABSPATH' ) || exit;

/**
 * Post store.
 */
interface Post_Store {

	/**
	 * One post as stored right now: never a revision or an autosave.
	 *
	 * Keys: id, type, title, status, modified (post_modified as stored), content (post_content, raw), meta (raw
	 * string value of each page-builder key the post has, by key).
	 *
	 * @param int      $id        Post ID.
	 * @param string[] $meta_keys Page-builder meta keys to read.
	 * @return array{id: int, type: string, title: string, status: string, modified: string, content: string, meta: array<string, string>}|null Null when there is no such post.
	 */
	public function load( int $id, array $meta_keys ): ?array;

	/**
	 * Write post_content (when not null) and the given meta values, byte for byte. post_modified is not touched.
	 *
	 * @param int                   $id      Post ID.
	 * @param string|null           $content New post_content, or null to leave it.
	 * @param array<string, string> $meta    New raw meta values, by key.
	 * @return bool True only when everything was written.
	 */
	public function save( int $id, ?string $content, array $meta ): bool;

	/**
	 * Drop caches that hold a rendered copy of the post (page builder caches, WordPress post cache).
	 *
	 * @param int $id Post ID.
	 */
	public function clear_caches( int $id ): void;
}
