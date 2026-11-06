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
	 * Constructor.
	 *
	 * @param string                $root        WordPress root.
	 * @param string                $content_dir wp-content.
	 * @param string                $uploads_dir uploads base.
	 * @param array<string, string> $ini         Effective ini values the checks may read.
	 */
	public function __construct( string $root, string $content_dir, string $uploads_dir, array $ini = array() ) {
		$this->root        = Filesystem::normalise( $root );
		$this->content_dir = Filesystem::normalise( $content_dir );
		$this->uploads_dir = Filesystem::normalise( $uploads_dir );
		$this->ini         = $ini;
		$this->fs          = new Filesystem( array( $this->root, $this->content_dir, $this->uploads_dir ) );
	}

	/**
	 * Build from the running WordPress.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		$uploads = wp_upload_dir( null, false );
		$ini     = array();
		foreach ( array( 'auto_prepend_file', 'auto_append_file', 'open_basedir' ) as $name ) {
			$value        = ini_get( $name );
			$ini[ $name ] = false === $value ? '' : (string) $value;
		}
		$ini['wallwarden.sapi'] = PHP_SAPI;
		return new self( ABSPATH, WP_CONTENT_DIR, (string) $uploads['basedir'], $ini );
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
