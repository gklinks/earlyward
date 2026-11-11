<?php
/**
 * Database answers from the live site via $wpdb, with prepared statements only.
 *
 * Reads go straight to the tables rather than through get_option()/get_users(), because the
 * data under inspection may be attacker-written and must not be hidden by filters or
 * instantiated as objects.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only forensic reads; caching would hide changes.

/**
 * $wpdb-backed database reader.
 */
final class Wpdb_Database implements Database {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Decode a stored value without ever instantiating objects.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function decode( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- objects disabled (attacker-written data); malformed values are expected.
		return ( false === $decoded && 'b:0;' !== trim( $value ) ) ? null : $decoded;
	}

	/**
	 * Administrators.
	 *
	 * @return array<int, array{id: int, login: string, email: string, registered: string}>
	 */
	public function administrators(): array {
		$wpdb = $this->wpdb;
		$key  = $wpdb->get_blog_prefix() . 'capabilities';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.ID, u.user_login, u.user_email, u.user_registered, m.meta_value
				FROM %i u
				INNER JOIN %i m ON m.user_id = u.ID AND m.meta_key = %s
				WHERE m.meta_value LIKE %s
				LIMIT 1000',
				$wpdb->users,
				$wpdb->usermeta,
				$key,
				'%' . $wpdb->esc_like( '"administrator"' ) . '%'
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$caps = self::decode( $row['meta_value'] );
			if ( ! is_array( $caps ) || empty( $caps['administrator'] ) ) {
				continue; // The LIKE is only a pre-filter; confirm the role really is set.
			}
			$out[] = array(
				'id'         => (int) $row['ID'],
				'login'      => (string) $row['user_login'],
				'email'      => (string) $row['user_email'],
				'registered' => (string) $row['user_registered'],
			);
		}
		return $out;
	}

	/**
	 * User meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @return mixed
	 */
	public function user_meta( int $user_id, string $key ) {
		$wpdb  = $this->wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 1',
				$wpdb->usermeta,
				$user_id,
				$key
			)
		);
		return null === $value ? null : self::decode( $value );
	}

	/**
	 * Posts containing all needles.
	 *
	 * @param string[] $needles  Substrings.
	 * @param string[] $types    Post types.
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    Limit.
	 * @param string[] $any_of   At least one of these, when not empty.
	 * @return array<int, array{id: int, type: string, title: string, content: string}>
	 */
	public function posts_containing( array $needles, array $types, array $statuses, int $limit, array $any_of = array() ): array {
		$wpdb    = $this->wpdb;
		$needles = array_values( $needles );
		if ( empty( $needles ) || empty( $types ) || empty( $statuses ) ) {
			return array();
		}
		// SQL filters on the first needle and (when given) on one of the hints, with a fixed, literal query; the rest is checked in PHP.
		// One small query per type and status keeps it portable (MySQL, MariaDB, SQLite integration).
		$any_of = self::slots( $any_of );
		$out    = array();
		foreach ( $types as $type ) {
			foreach ( $statuses as $status ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are passed as one array: 5 fixed, 8 patterns, the limit.
						'SELECT ID, post_type, post_title, post_content FROM %i
						WHERE post_type = %s AND post_status = %s AND post_content LIKE %s
						AND ( %d = 0 OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s
						OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
						ORDER BY ID LIMIT %d',
						array_merge(
							array( $wpdb->posts, $type, $status, '%' . $wpdb->esc_like( $needles[0] ) . '%', count( $any_of ) ),
							self::like_args( $wpdb, $any_of ),
							array( max( 1, $limit ) )
						)
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$content = (string) $row['post_content'];
					foreach ( $needles as $needle ) {
						if ( false === stripos( $content, $needle ) ) {
							continue 2;
						}
					}
					if ( ! self::has_any( $content, $any_of ) ) {
						continue;
					}
					$out[] = array(
						'id'      => (int) $row['ID'],
						'type'    => (string) $row['post_type'],
						'title'   => (string) $row['post_title'],
						'content' => $content,
					);
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Builder post meta containing all needles.
	 *
	 * @param string[] $keys     Meta keys.
	 * @param string[] $needles  Substrings.
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    Limit.
	 * @param string[] $any_of   At least one of these, when not empty.
	 * @return array<int, array{id: int, type: string, title: string, key: string, value: string}>
	 */
	public function meta_containing( array $keys, array $needles, array $statuses, int $limit, array $any_of = array() ): array {
		$wpdb    = $this->wpdb;
		$needles = array_values( $needles );
		if ( empty( $keys ) || empty( $needles ) || empty( $statuses ) ) {
			return array();
		}
		// Revisions and autosaves have status inherit, so a status like publish never returns them. One fixed query per key and status, LIMITed; SQL filters on the first needle and one of the hints, the rest is checked in PHP.
		$any_of = self::slots( $any_of );
		$out    = array();
		foreach ( $keys as $key ) {
			foreach ( $statuses as $status ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are passed as one array: 6 fixed, 8 patterns, the limit.
						'SELECT p.ID, p.post_type, p.post_title, m.meta_value FROM %i m
						INNER JOIN %i p ON p.ID = m.post_id
						WHERE m.meta_key = %s AND p.post_status = %s
						AND m.meta_value LIKE %s
						AND ( %d = 0 OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s
						OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s )
						ORDER BY p.ID LIMIT %d',
						array_merge(
							array( $wpdb->postmeta, $wpdb->posts, $key, $status, '%' . $wpdb->esc_like( $needles[0] ) . '%', count( $any_of ) ),
							self::like_args( $wpdb, $any_of ),
							array( max( 1, $limit ) )
						)
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$value = (string) $row['meta_value'];
					foreach ( $needles as $needle ) {
						if ( false === stripos( $value, $needle ) ) {
							continue 2;
						}
					}
					if ( ! self::has_any( $value, $any_of ) ) {
						continue;
					}
					$out[] = array(
						'id'    => (int) $row['ID'],
						'type'  => (string) $row['post_type'],
						'title' => (string) $row['post_title'],
						'key'   => (string) $key,
						'value' => $value,
					);
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * At most 8 non-empty hints (the queries have 8 fixed LIKE slots).
	 *
	 * @param string[] $any_of Hints.
	 * @return string[]
	 */
	private static function slots( array $any_of ): array {
		$out = array();
		foreach ( $any_of as $hint ) {
			if ( '' !== (string) $hint ) {
				$out[] = (string) $hint;
			}
		}
		return array_slice( $out, 0, 8 );
	}

	/**
	 * Exactly 8 LIKE patterns: the hints, padded with the last hint (the slots are ignored when there are no hints).
	 *
	 * @param \wpdb    $wpdb   Database.
	 * @param string[] $any_of Hints, from slots().
	 * @return string[]
	 */
	private static function like_args( \wpdb $wpdb, array $any_of ): array {
		$pad = empty( $any_of ) ? '' : end( $any_of );
		$out = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$out[] = '%' . $wpdb->esc_like( $any_of[ $i ] ?? $pad ) . '%';
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
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function option( string $name ) {
		$wpdb  = $this->wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name )
		);
		return null === $value ? null : self::decode( $value );
	}

	/**
	 * Counts of accounts that look unused (see the interface).
	 *
	 * @param int      $cutoff Unix time.
	 * @param string[] $roles  Roles.
	 * @return array{total: int, candidates: int, by_role: array<string, int>}
	 */
	public function stale_accounts( int $cutoff, array $roles ): array {
		$wpdb  = $this->wpdb;
		$roles = array_values(
			array_filter(
				array_map( 'strval', $roles ),
				static function ( string $role ): bool {
					return '' !== $role;
				}
			)
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->users ) );
		$by    = array_fill_keys( $roles, 0 );
		$seen  = array();
		// One fixed query per role (like posts_containing): a user with two listed roles is counted once.
		foreach ( $roles as $role ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, c.meta_value FROM %i u
					INNER JOIN %i c ON c.user_id = u.ID AND c.meta_key = %s
					LEFT JOIN %i s ON s.user_id = u.ID AND s.meta_key = 'session_tokens'
					LEFT JOIN %i w ON w.user_id = u.ID AND w.meta_key = 'wc_last_active'
					WHERE u.user_registered < %s
					AND ( s.umeta_id IS NULL OR s.meta_value = '' OR s.meta_value = 'a:0:{}' )
					AND ( w.umeta_id IS NULL OR CAST( w.meta_value AS UNSIGNED ) < %d )
					AND c.meta_value LIKE %s
					LIMIT 20000",
					$wpdb->users,
					$wpdb->usermeta,
					$wpdb->get_blog_prefix() . 'capabilities',
					$wpdb->usermeta,
					$wpdb->usermeta,
					gmdate( 'Y-m-d H:i:s', $cutoff ),
					$cutoff,
					'%' . $wpdb->esc_like( '"' . $role . '"' ) . '%'
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$id   = (int) $row['ID'];
				$caps = self::decode( $row['meta_value'] );
				// The LIKE is only a pre-filter; confirm the role really is set.
				if ( isset( $seen[ $id ] ) || ! is_array( $caps ) || empty( $caps[ $role ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				++$by[ $role ];
			}
		}
		return array(
			'total'      => $total,
			'candidates' => count( $seen ),
			'by_role'    => $by,
		);
	}

	/**
	 * Revisions of one post (see the interface).
	 *
	 * @param int      $post_id   Parent post ID.
	 * @param string[] $meta_keys Builder meta keys.
	 * @param int      $limit     Maximum revisions.
	 * @return array<int, array{id: int, author_id: int, author_login: string, date: string, content: string, meta: array<string, string>}>
	 */
	public function revisions( int $post_id, array $meta_keys, int $limit ): array {
		$wpdb  = $this->wpdb;
		$limit = max( 1, $limit );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.ID, r.post_author, r.post_content, r.post_modified_gmt, r.post_date_gmt, u.user_login FROM %i r
				LEFT JOIN %i u ON u.ID = r.post_author
				WHERE r.post_type = 'revision' AND r.post_parent = %d
				ORDER BY r.ID DESC LIMIT %d",
				$wpdb->posts,
				$wpdb->users,
				$post_id,
				$limit
			),
			ARRAY_A
		);
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$date                    = (string) $row['post_modified_gmt'];
			$out[ (int) $row['ID'] ] = array(
				'id'           => (int) $row['ID'],
				'author_id'    => (int) $row['post_author'],
				'author_login' => (string) ( $row['user_login'] ?? '' ),
				'date'         => ( '' === $date || '0000-00-00 00:00:00' === $date ) ? (string) $row['post_date_gmt'] : $date,
				'content'      => (string) $row['post_content'],
				'meta'         => array(),
			);
		}
		// One fixed query per key; only meta of the revisions already read is kept.
		foreach ( $meta_keys as $key ) {
			$metas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.post_id, m.meta_value FROM %i m
					INNER JOIN %i r ON r.ID = m.post_id
					WHERE m.meta_key = %s AND r.post_type = 'revision' AND r.post_parent = %d
					ORDER BY m.post_id DESC LIMIT %d",
					$wpdb->postmeta,
					$wpdb->posts,
					(string) $key,
					$post_id,
					$limit
				),
				ARRAY_A
			);
			foreach ( (array) $metas as $meta ) {
				if ( isset( $out[ (int) $meta['post_id'] ] ) ) {
					$out[ (int) $meta['post_id'] ]['meta'][ (string) $key ] = (string) $meta['meta_value'];
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Terms (see the interface).
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array{id: int, name: string, slug: string, taxonomy: string, count: int}>
	 */
	public function terms( int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, x.taxonomy, x.count FROM %i t
				INNER JOIN %i x ON x.term_id = t.term_id
				WHERE x.taxonomy NOT IN ( 'nav_menu', 'link_category', 'post_format' )
				ORDER BY t.term_id LIMIT %d",
				$wpdb->terms,
				$wpdb->term_taxonomy,
				max( 1, $limit )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'       => (int) $row['term_id'],
				'name'     => (string) $row['name'],
				'slug'     => (string) $row['slug'],
				'taxonomy' => (string) $row['taxonomy'],
				'count'    => (int) $row['count'],
			);
		}
		return $out;
	}

	/**
	 * Post titles and slugs (see the interface). One query per status.
	 *
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    Maximum rows.
	 * @return array<int, array{id: int, type: string, status: string, title: string, slug: string}>
	 */
	public function post_titles( array $statuses, int $limit ): array {
		$wpdb = $this->wpdb;
		$out  = array();
		$skip = Database::NON_CONTENT_TYPES;
		foreach ( $statuses as $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- the values are passed as one array: table, status, the skipped types, the limit; the only built-in part is a list of %s.
					'SELECT ID, post_type, post_status, post_title, post_name FROM %i
					WHERE post_status = %s AND post_type NOT IN ( ' . implode( ', ', array_fill( 0, count( $skip ), '%s' ) ) . ' )
					ORDER BY ID LIMIT %d',
					array_merge( array( $wpdb->posts, $status ), $skip, array( max( 1, $limit ) ) )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'id'     => (int) $row['ID'],
					'type'   => (string) $row['post_type'],
					'status' => (string) $row['post_status'],
					'title'  => (string) $row['post_title'],
					'slug'   => (string) $row['post_name'],
				);
			}
		}
		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * Accounts with one of the roles (see the interface). One query per role; a user with two listed roles appears once.
	 *
	 * @param string[] $roles Roles.
	 * @param int      $limit Maximum rows.
	 * @return array<int, array{id: int, login: string, display_name: string}>
	 */
	public function user_names( array $roles, int $limit ): array {
		$wpdb = $this->wpdb;
		$out  = array();
		foreach ( $roles as $role ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT u.ID, u.user_login, u.display_name, c.meta_value FROM %i u
					INNER JOIN %i c ON c.user_id = u.ID AND c.meta_key = %s
					WHERE c.meta_value LIKE %s
					ORDER BY u.ID LIMIT %d',
					$wpdb->users,
					$wpdb->usermeta,
					$wpdb->get_blog_prefix() . 'capabilities',
					'%' . $wpdb->esc_like( '"' . (string) $role . '"' ) . '%',
					max( 1, $limit )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$caps = self::decode( $row['meta_value'] );
				if ( ! is_array( $caps ) || empty( $caps[ $role ] ) ) {
					continue; // The LIKE is only a pre-filter.
				}
				$out[ (int) $row['ID'] ] = array(
					'id'           => (int) $row['ID'],
					'login'        => (string) $row['user_login'],
					'display_name' => (string) $row['display_name'],
				);
			}
		}
		ksort( $out );
		return array_slice( array_values( $out ), 0, max( 1, $limit ) );
	}
}
