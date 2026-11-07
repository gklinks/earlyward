<?php
/**
 * Database answers from a fixture's db.json, mirroring Wpdb_Database's contract.
 *
 * Format of db.json:
 * {
 *   "users":   [ { "id": 1, "login": "", "email": "", "registered": "", "roles": ["administrator"],
 *                  "meta": { "session_tokens": {...}, "_application_passwords": [...] } } ],
 *   "posts":   [ { "id": 1, "type": "page", "status": "publish", "title": "", "content": "" } ],
 *   "options": { "name": value }
 * }
 *
 * @package Wallwarden
 */

namespace Wallwarden\Tests;

use Wallwarden\Env\Database;

/**
 * Fixture database.
 */
final class Fixture_Database implements Database {

	/**
	 * Decoded db.json.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Decoded db.json.
	 */
	public function __construct( array $data ) {
		$this->data = $data + array(
			'users'   => array(),
			'posts'   => array(),
			'options' => array(),
		);
	}

	/**
	 * Administrators.
	 *
	 * @return array<int, array{id: int, login: string, email: string, registered: string}>
	 */
	public function administrators(): array {
		$out = array();
		foreach ( (array) $this->data['users'] as $user ) {
			if ( in_array( 'administrator', (array) ( $user['roles'] ?? array() ), true ) ) {
				$out[] = array(
					'id'         => (int) $user['id'],
					'login'      => (string) $user['login'],
					'email'      => (string) $user['email'],
					'registered' => (string) $user['registered'],
				);
			}
		}
		return $out;
	}

	/**
	 * User meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key.
	 * @return mixed
	 */
	public function user_meta( int $user_id, string $key ) {
		foreach ( (array) $this->data['users'] as $user ) {
			if ( (int) $user['id'] === $user_id ) {
				return $user['meta'][ $key ] ?? null;
			}
		}
		return null;
	}

	/**
	 * Posts containing all needles.
	 *
	 * @param string[] $needles  Needles.
	 * @param string[] $types    Types.
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    Limit.
	 * @return array<int, array{id: int, type: string, title: string, content: string}>
	 */
	public function posts_containing( array $needles, array $types, array $statuses, int $limit ): array {
		$out = array();
		foreach ( (array) $this->data['posts'] as $post ) {
			if ( ! in_array( $post['type'], $types, true ) || ! in_array( $post['status'], $statuses, true ) ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false === stripos( (string) $post['content'], $needle ) ) {
					continue 2;
				}
			}
			$out[] = array(
				'id'      => (int) $post['id'],
				'type'    => (string) $post['type'],
				'title'   => (string) $post['title'],
				'content' => (string) $post['content'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Option.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function option( string $name ) {
		return $this->data['options'][ $name ] ?? null;
	}
}
