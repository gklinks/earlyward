<?php
/**
 * Everything a check may look at. Built from WordPress in production and
 * from a fixture tree in tests.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Env;

defined( 'ABSPATH' ) || exit;

/**
 * Scan context.
 */
final class Context {

	/**
	 * WordPress root (ABSPATH), normalised.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Content directory, normalised.
	 *
	 * @var string
	 */
	private $content_dir;

	/**
	 * Uploads base directory, normalised.
	 *
	 * @var string
	 */
	private $uploads_dir;

	/**
	 * Filesystem reader.
	 *
	 * @var Filesystem
	 */
	private $fs;

	/**
	 * Effective PHP ini values, by name.
	 *
	 * @var array<string, string>
	 */
	private $ini;

	/**
	 * Must-use plugins directory, normalised.
	 *
	 * @var string
	 */
	private $mu_plugins_dir;

	/**
	 * Database reader, or null when none is available (some unit tests).
	 *
	 * @var Database|null
	 */
	private $db;

	/**
	 * Loopback HTTP to this site, or null when unavailable.
	 *
	 * @var Loopback|null
	 */
	private $loopback;

	/**
	 * Stored healthy baselines, by check ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $baselines;

	/**
	 * Observations from the previous scan, by check ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $previous;

	/**
	 * Selected WordPress constants, by name.
	 *
	 * @var array<string, mixed>
	 */
	private $constants;

	/**
	 * Current Unix time (fixed in tests).
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param string                $root        WordPress root.
	 * @param string                $content_dir wp-content.
	 * @param string                $uploads_dir uploads base.
	 * @param array<string, string> $ini         Effective ini values the checks may read.
	 * @param array<string, mixed>  $extra       Optional: mu_plugins_dir, db, loopback, baselines, previous, constants, now.
	 */
	public function __construct( string $root, string $content_dir, string $uploads_dir, array $ini = array(), array $extra = array() ) {
		$this->root           = Filesystem::normalise( $root );
		$this->content_dir    = Filesystem::normalise( $content_dir );
		$this->uploads_dir    = Filesystem::normalise( $uploads_dir );
		$this->mu_plugins_dir = isset( $extra['mu_plugins_dir'] ) && is_string( $extra['mu_plugins_dir'] )
			? Filesystem::normalise( $extra['mu_plugins_dir'] )
			: $this->content_dir . '/mu-plugins';
		$this->db             = isset( $extra['db'] ) && $extra['db'] instanceof Database ? $extra['db'] : null;
		$this->loopback       = isset( $extra['loopback'] ) && $extra['loopback'] instanceof Loopback ? $extra['loopback'] : null;
		$this->baselines      = isset( $extra['baselines'] ) && is_array( $extra['baselines'] ) ? $extra['baselines'] : array();
		$this->previous       = isset( $extra['previous'] ) && is_array( $extra['previous'] ) ? $extra['previous'] : array();
		$this->constants      = isset( $extra['constants'] ) && is_array( $extra['constants'] ) ? $extra['constants'] : array();
		$this->now            = isset( $extra['now'] ) && is_int( $extra['now'] ) ? $extra['now'] : time();
		$this->ini            = $ini;
		$this->fs             = new Filesystem( array( $this->root, $this->content_dir, $this->uploads_dir, $this->mu_plugins_dir ) );
	}

	/**
	 * Build from the running WordPress.
	 *
	 * @param array<string, array<string, mixed>> $baselines Stored baselines by check ID.
	 * @param array<string, array<string, mixed>> $previous  Previous observations by check ID.
	 * @return self
	 */
	public static function from_wordpress( array $baselines = array(), array $previous = array() ): self {
		global $wpdb;
		$constants = array();
		$names     = array( 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_DEBUG', 'WP_DEBUG_LOG', 'WALLWARDEN_LOG_WARN_BYTES', 'WALLWARDEN_LOG_CRITICAL_BYTES', 'WALLWARDEN_LOG_GROWTH_BYTES_PER_DAY' );
		foreach ( $names as $name ) {
			$constants[ $name ] = defined( $name ) ? constant( $name ) : null;
		}
		$uploads = wp_upload_dir( null, false );
		$ini     = array();
		foreach ( array( 'auto_prepend_file', 'auto_append_file', 'open_basedir', 'error_log' ) as $name ) {
			$value        = ini_get( $name );
			$ini[ $name ] = false === $value ? '' : (string) $value;
		}
		$ini['wallwarden.sapi'] = PHP_SAPI;
		return new self(
			ABSPATH,
			WP_CONTENT_DIR,
			(string) $uploads['basedir'],
			$ini,
			array(
				'mu_plugins_dir' => WPMU_PLUGIN_DIR,
				'db'             => new Wpdb_Database( $wpdb ),
				'loopback'       => new Wp_Loopback(),
				'baselines'      => $baselines,
				'previous'       => $previous,
				'constants'      => $constants,
			)
		);
	}

	/**
	 * Loopback HTTP, or null.
	 *
	 * @return Loopback|null
	 */
	public function loopback(): ?Loopback {
		return $this->loopback;
	}

	/**
	 * Stored healthy baseline for a check, or null.
	 *
	 * @param string $check Check ID.
	 * @return array<string, mixed>|null
	 */
	public function baseline( string $check ): ?array {
		return isset( $this->baselines[ $check ] ) && is_array( $this->baselines[ $check ] ) ? $this->baselines[ $check ] : null;
	}

	/**
	 * Previous scan's observation for a check, or null.
	 *
	 * @param string $check Check ID.
	 * @return array<string, mixed>|null
	 */
	public function previous( string $check ): ?array {
		return isset( $this->previous[ $check ] ) && is_array( $this->previous[ $check ] ) ? $this->previous[ $check ] : null;
	}

	/**
	 * A WordPress constant's value, or null when undefined.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function constant( string $name ) {
		return $this->constants[ $name ] ?? null;
	}

	/**
	 * Current Unix time.
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->now;
	}

	/**
	 * Must-use plugins directory.
	 *
	 * @return string
	 */
	public function mu_plugins_dir(): string {
		return $this->mu_plugins_dir;
	}

	/**
	 * Database reader.
	 *
	 * @return Database|null
	 */
	public function db(): ?Database {
		return $this->db;
	}

	/**
	 * WordPress root.
	 *
	 * @return string
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Content directory.
	 *
	 * @return string
	 */
	public function content_dir(): string {
		return $this->content_dir;
	}

	/**
	 * Uploads directory.
	 *
	 * @return string
	 */
	public function uploads_dir(): string {
		return $this->uploads_dir;
	}

	/**
	 * Filesystem reader.
	 *
	 * @return Filesystem
	 */
	public function fs(): Filesystem {
		return $this->fs;
	}

	/**
	 * Effective ini value, or '' when unknown.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function ini( string $name ): string {
		return isset( $this->ini[ $name ] ) ? $this->ini[ $name ] : '';
	}

	/**
	 * Whether an ini value is known at all (as opposed to known to be empty).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public function has_ini( string $name ): bool {
		return isset( $this->ini[ $name ] );
	}

	/**
	 * Whether the scan runs from the command line, where PHP settings can differ from the web server's.
	 *
	 * @return bool
	 */
	public function is_cli(): bool {
		return 'cli' === $this->ini( 'wallwarden.sapi' );
	}

	/**
	 * Path relative to the WordPress root (or content dir), for evidence.
	 * Keeps server paths out of emails and screenshots.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public function relative( string $path ): string {
		$path  = Filesystem::normalise( $path );
		$bases = array(
			$this->root,
			dirname( $this->content_dir ),
			Filesystem::canonical( $this->root ),
			dirname( Filesystem::canonical( $this->content_dir ) ),
		);
		foreach ( array( $path, Filesystem::canonical( $path ) ) as $candidate ) {
			foreach ( $bases as $base ) {
				if ( 0 === strpos( $candidate, $base . '/' ) ) {
					return substr( $candidate, strlen( $base ) + 1 );
				}
			}
		}
		return basename( $path );
	}
}
