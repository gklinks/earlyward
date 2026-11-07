<?php
/**
 * Database answers from the live site via $wpdb, with prepared statements only.
 *
 * Reads go straight to the tables rather than through get_option()/get_users(), because the
 * data under inspection may be attacker-written and must not be hidden by filters or
 * instantiated as objects.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Env;

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
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- objects disabled; attacker-written data.
		$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed data is expected here.
		return ( false === $decoded && 'b:0;' !== trim( $value ) ) ? null : $decoded;
	}

	/**
	 * Administrators.
	 *
	 * @return array<int, array{id: int, login: string, email: string, registered: string}>
	 */
	public function administrators(): array {
		$key  = $this->wpdb->get_blog_prefix() . 'capabilities';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT u.ID, u.user_login, u.user_email, u.user_registered, m.meta_value
				FROM %i u
				INNER JOIN %i m ON m.user_id = u.ID AND m.meta_key = %s
				WHERE m.meta_value LIKE %s
				LIMIT 1000',
				$this->wpdb->users,
				$this->wpdb->usermeta,
				$key,
				'%' . $this->wpdb->esc_like( '"administrator"' ) . '%'
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
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 1',
				$this->wpdb->usermeta,
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
		$needles = array_values( $needles );
		if ( empty( $needles ) || empty( $types ) || empty( $statuses ) ) {
			return array();
		}
		// SQL filters on the first needle only, with a fixed, literal query; the rest are checked in PHP.
		// One small query per type and status keeps it portable (MySQL, MariaDB, SQLite integration).
		$out = array();
		foreach ( $types as $type ) {
			foreach ( $statuses as $status ) {
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT ID, post_type, post_title, post_content FROM %i
						WHERE post_type = %s AND post_status = %s AND post_content LIKE %s
						ORDER BY ID LIMIT %d',
						$this->wpdb->posts,
						$type,
						$status,
						'%' . $this->wpdb->esc_like( $needles[0] ) . '%',
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
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $this->wpdb->options, $name )
		);
		return null === $value ? null : self::decode( $value );
	}
}
