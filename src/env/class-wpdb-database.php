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
	 * @return array<int, array{id: int, type: string, title: string, content: string}>
	 */
	public function posts_containing( array $needles, array $types, array $statuses, int $limit ): array {
		$wpdb    = $this->wpdb;
		$needles = array_values( $needles );
		if ( empty( $needles ) || empty( $types ) || empty( $statuses ) ) {
			return array();
		}
		// SQL filters on the first needle only, with a fixed, literal query; the rest are checked in PHP.
		// One small query per type and status keeps it portable (MySQL, MariaDB, SQLite integration).
		$out = array();
		foreach ( $types as $type ) {
			foreach ( $statuses as $status ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT ID, post_type, post_title, post_content FROM %i
						WHERE post_type = %s AND post_status = %s AND post_content LIKE %s
						ORDER BY ID LIMIT %d',
						$wpdb->posts,
						$type,
						$status,
						'%' . $wpdb->esc_like( $needles[0] ) . '%',
						max( 1, $limit )
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
}
