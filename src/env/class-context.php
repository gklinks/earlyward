<?php
/**
 * Everything a check may look at. Built from WordPress in production and
 * from a fixture tree in tests.
 *
 * @package Earlyward
 */

namespace Earlyward\Env;

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
	 * Vulnerability feed, or null when unavailable.
	 *
	 * @var Vuln_Feed|null
	 */
	private $vuln_feed;

	/**
	 * Installed core, plugins and themes.
	 *
	 * @var array<int, array{type: string, slug: string, name: string, version: string}>
	 */
	private $software;

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
	 * File permission bits by path relative to the root, or null to read them from disk.
	 * Tests set it, because fixture files cannot carry real modes (and Windows has none).
	 *
	 * @var array<string, int>|null
	 */
	private $file_modes;

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
	 * @param array<string, mixed>  $extra       Optional: mu_plugins_dir, db, loopback, vuln_feed, software, baselines, previous, constants, file_modes, now.
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
		$this->vuln_feed      = isset( $extra['vuln_feed'] ) && $extra['vuln_feed'] instanceof Vuln_Feed ? $extra['vuln_feed'] : null;
		$this->software       = isset( $extra['software'] ) && is_array( $extra['software'] ) ? $extra['software'] : array();
		$this->baselines      = isset( $extra['baselines'] ) && is_array( $extra['baselines'] ) ? $extra['baselines'] : array();
		$this->previous       = isset( $extra['previous'] ) && is_array( $extra['previous'] ) ? $extra['previous'] : array();
		$this->constants      = isset( $extra['constants'] ) && is_array( $extra['constants'] ) ? $extra['constants'] : array();
		$this->file_modes     = isset( $extra['file_modes'] ) && is_array( $extra['file_modes'] ) ? $extra['file_modes'] : null;
		$this->now            = isset( $extra['now'] ) && is_int( $extra['now'] ) ? $extra['now'] : time();
		$this->ini            = $ini;
		$this->fs             = new Filesystem( array( $this->root, $this->content_dir, $this->uploads_dir, $this->mu_plugins_dir ) );
	}

	/**
	 * Build from the running WordPress.
	 *
	 * @param array<string, array<string, mixed>> $baselines Stored baselines by check ID.
	 * @param array<string, array<string, mixed>> $previous  Previous observations by check ID.
	 * @param bool                                $network   Whether checks may make requests to the site itself. A fix re-checks without them.
	 * @return self
	 */
	public static function from_wordpress( array $baselines = array(), array $previous = array(), bool $network = true ): self {
		global $wpdb;
		$constants = array();
		$names     = array( 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_DEBUG', 'WP_DEBUG_LOG', 'EARLYWARD_LOG_WARN_BYTES', 'EARLYWARD_LOG_CRITICAL_BYTES', 'EARLYWARD_LOG_GROWTH_BYTES_PER_DAY' );
		foreach ( $names as $name ) {
			$constants[ $name ] = defined( $name ) ? constant( $name ) : null;
		}
		$uploads = wp_upload_dir( null, false );
		$ini     = array();
		foreach ( array( 'auto_prepend_file', 'auto_append_file', 'open_basedir', 'error_log' ) as $name ) {
			$value        = ini_get( $name );
			$ini[ $name ] = false === $value ? '' : (string) $value;
		}
		$ini['earlyward.sapi'] = PHP_SAPI;
		return new self(
			ABSPATH,
			WP_CONTENT_DIR,
			(string) $uploads['basedir'],
			$ini,
			array(
				'mu_plugins_dir' => WPMU_PLUGIN_DIR,
				'db'             => new Wpdb_Database( $wpdb ),
				'loopback'       => $network ? new Wp_Loopback() : null,
				'vuln_feed'      => $network ? new Wp_Vuln_Feed() : null,
				'software'       => self::installed_software(),
				'baselines'      => $baselines,
				'previous'       => $previous,
				'constants'      => $constants,
			)
		);
	}

	/**
	 * Core, every installed plugin (active or not: the files are still reachable) and every installed theme.
	 *
	 * @return array<int, array{type: string, slug: string, name: string, version: string}>
	 */
	private static function installed_software(): array {
		global $wp_version;
		$out = array(
			array(
				'type'    => 'core',
				'slug'    => 'wordpress',
				'name'    => 'WordPress',
				'version' => (string) $wp_version,
			),
		);
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$dir   = dirname( (string) $file );
			$out[] = array(
				'type'    => 'plugin',
				'slug'    => '.' === $dir ? basename( (string) $file, '.php' ) : $dir,
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
			);
		}
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'type'    => 'theme',
				'slug'    => (string) $slug,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			);
		}
		return $out;
	}

	/**
	 * Vulnerability feed, or null.
	 *
	 * @return Vuln_Feed|null
	 */
	public function vuln_feed(): ?Vuln_Feed {
		return $this->vuln_feed;
	}

	/**
	 * Installed software.
	 *
	 * @return array<int, array{type: string, slug: string, name: string, version: string}>
	 */
	public function software(): array {
		return $this->software;
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
	 * Permission bits of a file, or null when unknown.
	 *
	 * @param string $path Absolute path.
	 * @return int|null
	 */
	public function file_mode( string $path ): ?int {
		if ( null !== $this->file_modes ) {
			$mode = $this->file_modes[ $this->relative( $path ) ] ?? null;
			return is_int( $mode ) ? $mode : null;
		}
		return $this->fs->mode( $path );
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
		return 'cli' === $this->ini( 'earlyward.sapi' );
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
