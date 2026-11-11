<?php
/**
 * Post store that keeps posts in memory, for the "Remove hidden links" tests.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use Earlyward\Fix\Post_Store;

/**
 * Memory post store.
 */
final class Memory_Post_Store implements Post_Store {

	/**
	 * Posts by ID: type, status, title, modified, content, meta (key to raw value).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $posts = array();

	/**
	 * When set, save() appends this to post_content, to prove that a bad write is detected and undone.
	 *
	 * @var string
	 */
	public $corrupt = '';

	/**
	 * Number of times caches were cleared, by post ID.
	 *
	 * @var array<int, int>
	 */
	public $cleared = array();

	/**
	 * Add or replace a post.
	 *
	 * @param int                  $id   ID.
	 * @param array<string, mixed> $post Post.
	 */
	public function add( int $id, array $post ): void {
		$this->posts[ $id ] = $post + array(
			'type'     => 'page',
			'status'   => 'publish',
			'title'    => 'Page',
			'modified' => '2026-01-01 00:00:00',
			'content'  => '',
			'meta'     => array(),
		);
	}

	/**
	 * The same data in the shape of a fixture's db.json, so the check can run on it.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$posts = array();
		$meta  = array();
		foreach ( $this->posts as $id => $post ) {
			$posts[] = array(
				'id'      => $id,
				'type'    => $post['type'],
				'status'  => $post['status'],
				'title'   => $post['title'],
				'content' => $post['content'],
			);
			foreach ( (array) $post['meta'] as $key => $value ) {
				$meta[] = array(
					'post_id' => $id,
					'key'     => $key,
					'value'   => $value,
				);
			}
		}
		return array(
			'posts'    => $posts,
			'postmeta' => $meta,
			'options'  => array( 'home' => 'https://site.fixture' ),
		);
	}

	/**
	 * Read.
	 *
	 * @param int      $id        ID.
	 * @param string[] $meta_keys Keys.
	 * @return array{id: int, type: string, title: string, status: string, modified: string, content: string, meta: array<string, string>}|null
	 */
	public function load( int $id, array $meta_keys ): ?array {
		if ( ! isset( $this->posts[ $id ] ) ) {
			return null;
		}
		$post = $this->posts[ $id ];
		$meta = array();
		foreach ( $meta_keys as $key ) {
			if ( isset( $post['meta'][ $key ] ) ) {
				$meta[ $key ] = (string) $post['meta'][ $key ];
			}
		}
		return array(
			'id'       => $id,
			'type'     => (string) $post['type'],
			'title'    => (string) $post['title'],
			'status'   => (string) $post['status'],
			'modified' => (string) $post['modified'],
			'content'  => (string) $post['content'],
			'meta'     => $meta,
		);
	}

	/**
	 * Write.
	 *
	 * @param int                   $id      ID.
	 * @param string|null           $content Content.
	 * @param array<string, string> $meta    Meta.
	 * @return bool
	 */
	public function save( int $id, ?string $content, array $meta ): bool {
		if ( ! isset( $this->posts[ $id ] ) ) {
			return false;
		}
		if ( null !== $content ) {
			$this->posts[ $id ]['content'] = $content . $this->corrupt;
			$this->corrupt                 = '';
		}
		foreach ( $meta as $key => $value ) {
			$this->posts[ $id ]['meta'][ $key ] = $value;
		}
		return true;
	}

	/**
	 * Count a cache clear.
	 *
	 * @param int $id ID.
	 */
	public function clear_caches( int $id ): void {
		$this->cleared[ $id ] = ( $this->cleared[ $id ] ?? 0 ) + 1;
	}
}
