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
 * @package Earlyward
 */

namespace Earlyward\Tests;

use Earlyward\Env\Database;

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
	 * Record of posts_containing / meta_containing calls, for tests.
	 *
	 * @var array<int, array{source: string, any_of: string[], limit: int}>
	 */
	public $calls = array();

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
	 * @param string[] $any_of   At least one of these, when not empty.
	 * @return array<int, array{id: int, type: string, title: string, content: string}>
	 */
	public function posts_containing( array $needles, array $types, array $statuses, int $limit, array $any_of = array() ): array {
		$this->calls[] = array(
			'source' => 'posts',
			'any_of' => $any_of,
			'limit'  => $limit,
		);
		$out           = array();
		foreach ( (array) $this->data['posts'] as $post ) {
			if ( ! in_array( $post['type'], $types, true ) || ! in_array( $post['status'], $statuses, true ) ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false === stripos( (string) $post['content'], $needle ) ) {
					continue 2;
				}
			}
			if ( ! self::has_any( (string) $post['content'], $any_of ) ) {
				continue;
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
	 * Builder post meta containing all needles, from db.json "postmeta": [ { "post_id": 1, "key": "_elementor_data", "value": "" } ] joined to "posts".
	 *
	 * @param string[] $keys     Keys.
	 * @param string[] $needles  Needles.
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    Limit.
	 * @param string[] $any_of   At least one of these, when not empty.
	 * @return array<int, array{id: int, type: string, title: string, key: string, value: string}>
	 */
	public function meta_containing( array $keys, array $needles, array $statuses, int $limit, array $any_of = array() ): array {
		$this->calls[] = array(
			'source' => 'meta',
			'any_of' => $any_of,
			'limit'  => $limit,
		);
		$out           = array();
		foreach ( (array) ( $this->data['postmeta'] ?? array() ) as $meta ) {
			if ( ! in_array( $meta['key'], $keys, true ) ) {
				continue;
			}
			foreach ( (array) $this->data['posts'] as $post ) {
				if ( (int) $post['id'] !== (int) $meta['post_id'] || ! in_array( $post['status'], $statuses, true ) ) {
					continue;
				}
				foreach ( $needles as $needle ) {
					if ( false === stripos( (string) $meta['value'], $needle ) ) {
						continue 3;
					}
				}
				if ( ! self::has_any( (string) $meta['value'], $any_of ) ) {
					continue;
				}
				$out[] = array(
					'id'    => (int) $post['id'],
					'type'  => (string) $post['type'],
					'title' => (string) $post['title'],
					'key'   => (string) $meta['key'],
					'value' => (string) $meta['value'],
				);
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * True when no hints are given, or the text contains at least one.
	 *
	 * @param string   $text   Text.
	 * @param string[] $any_of Hints.
	 * @return bool
	 */
	private static function has_any( string $text, array $any_of ): bool {
		if ( empty( $any_of ) ) {
			return true;
		}
		foreach ( $any_of as $hint ) {
			if ( false !== stripos( $text, $hint ) ) {
				return true;
			}
		}
		return false;
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

	/**
	 * Counts of accounts that look unused: same rules as Wpdb_Database, answered from db.json users
	 * ("registered" as "Y-m-d H:i:s", meta session_tokens, meta wc_last_active as a Unix time).
	 *
	 * @param int      $cutoff Unix time.
	 * @param string[] $roles  Roles.
	 * @return array{total: int, candidates: int, by_role: array<string, int>}
	 */
	public function stale_accounts( int $cutoff, array $roles ): array {
		$by    = array_fill_keys( $roles, 0 );
		$count = 0;
		foreach ( (array) $this->data['users'] as $user ) {
			$registered = strtotime( (string) ( $user['registered'] ?? '' ) . ' UTC' );
			$tokens     = $user['meta']['session_tokens'] ?? null;
			$active     = (int) ( $user['meta']['wc_last_active'] ?? 0 );
			if ( false === $registered || $registered >= $cutoff || ! empty( $tokens ) || $active >= $cutoff ) {
				continue;
			}
			foreach ( $roles as $role ) {
				if ( in_array( $role, (array) ( $user['roles'] ?? array() ), true ) ) {
					++$by[ $role ];
					++$count;
					break;
				}
			}
		}
		return array(
			'total'      => count( (array) $this->data['users'] ),
			'candidates' => $count,
			'by_role'    => $by,
		);
	}
}
