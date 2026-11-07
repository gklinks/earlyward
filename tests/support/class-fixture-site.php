<?php
/**
 * Materialises a fixture case into a temporary site tree.
 *
 * Fixture layout: tests/fixtures/<case>/
 *   tree/           the site root (wp-content/, wp-content/uploads/, ...)
 *   ini.json        optional effective ini values
 *   expected.json   the findings the default scanner must return, no more, no less
 *   db.json         optional database contents (see Fixture_Database)
 *   loopback.json   optional loopback answers (see Fixture_Loopback)
 *   baseline.json / previous.json / constants.json   optional, by check ID / constant name
 *   The clock is fixed at Fixture_Site::NOW.
 *   layout.json     optional {"root": "dir"}: tree/ holds several sites, tree/dir is scanned
 *
 * Conventions inside tree/:
 *   - the text {ROOT} is replaced by the temporary site root (real configs hold absolute paths);
 *   - a directory named NAME.zip.src/ becomes a zip archive NAME.zip with the same contents,
 *     so no binary archives are committed.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Tests;

use Wallwarden\Env\Context;

/**
 * Fixture site.
 */
final class Fixture_Site {

	/**
	 * Fixed clock for every fixture: 2026-11-07 00:00:00 UTC.
	 */
	const NOW = 1794009600;

	/**
	 * Temporary root.
	 *
	 * @var string
	 */
	public $root;

	/**
	 * Case directory.
	 *
	 * @var string
	 */
	public $case_dir;

	/**
	 * Per-case temporary directory that holds the site (and any sibling sites).
	 *
	 * @var string
	 */
	public $base;

	/**
	 * Build from a case directory.
	 *
	 * @param string $case_dir Case directory.
	 */
	public function __construct( string $case_dir ) {
		$this->case_dir = $case_dir;
		$this->base     = str_replace( '\\', '/', sys_get_temp_dir() ) . '/wallwarden-fixture-' . bin2hex( random_bytes( 6 ) );
		// Default: tree/ is the site root. With layout.json {"root": "x"}: tree/ holds several
		// sites side by side and tree/x is this one (used for shared-hosting cases).
		$layout     = is_file( $case_dir . '/layout.json' ) ? (array) json_decode( (string) file_get_contents( $case_dir . '/layout.json' ), true ) : array();
		$has_layout = isset( $layout['root'] ) && is_string( $layout['root'] );
		$this->root = $this->base . '/' . ( $has_layout ? $layout['root'] : 'site' );
		mkdir( $this->root, 0777, true );
		$this->copy( $case_dir . '/tree', $has_layout ? $this->base : $this->root );
		foreach ( array( '/wp-content', '/wp-content/uploads' ) as $dir ) {
			if ( ! is_dir( $this->root . $dir ) ) {
				mkdir( $this->root . $dir, 0777, true );
			}
		}
	}

	/**
	 * Context pointing at the temporary tree.
	 *
	 * @return Context
	 */
	public function context(): Context {
		$ini      = array();
		$ini_file = $this->case_dir . '/ini.json';
		if ( is_file( $ini_file ) ) {
			$raw = str_replace( '{ROOT}', $this->root, (string) file_get_contents( $ini_file ) );
			$ini = (array) json_decode( $raw, true );
		}
		$ini  += array( 'wallwarden.sapi' => 'fpm-fcgi' );
		$extra = array();
		if ( is_file( $this->case_dir . '/db.json' ) ) {
			$extra['db'] = new Fixture_Database( (array) json_decode( (string) file_get_contents( $this->case_dir . '/db.json' ), true ) );
		}
		$extra['now'] = self::NOW;
		foreach ( array(
			'baselines' => 'baseline.json',
			'previous'  => 'previous.json',
			'constants' => 'constants.json',
		) as $key => $file ) {
			if ( is_file( $this->case_dir . '/' . $file ) ) {
				$extra[ $key ] = (array) json_decode( (string) file_get_contents( $this->case_dir . '/' . $file ), true );
			}
		}
		if ( is_file( $this->case_dir . '/loopback.json' ) ) {
			$extra['loopback'] = new Fixture_Loopback( $this->case_dir, (array) json_decode( (string) file_get_contents( $this->case_dir . '/loopback.json' ), true ) );
		}
		return new Context( $this->root, $this->root . '/wp-content', $this->root . '/wp-content/uploads', $ini, $extra );
	}

	/**
	 * Expected findings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function expected(): array {
		return (array) json_decode( (string) file_get_contents( $this->case_dir . '/expected.json' ), true );
	}

	/**
	 * Remove the temporary tree.
	 */
	public function destroy(): void {
		self::remove( $this->base );
	}

	/**
	 * Recursive copy with {ROOT} substitution and zip building.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 */
	private function copy( string $from, string $to ): void {
		if ( ! is_dir( $from ) ) {
			return;
		}
		foreach ( (array) scandir( $from ) as $name ) {
			if ( '.' === $name || '..' === $name || '.gitkeep' === $name ) {
				continue;
			}
			$src = $from . '/' . $name;
			if ( is_dir( $src ) && '.zip.src' === substr( $name, -8 ) ) {
				$this->zip( $src, $to . '/' . substr( $name, 0, -4 ) );
			} elseif ( is_dir( $src ) ) {
				if ( ! is_dir( $to . '/' . $name ) ) {
					mkdir( $to . '/' . $name, 0777, true );
				}
				$this->copy( $src, $to . '/' . $name );
			} else {
				file_put_contents( $to . '/' . $name, str_replace( '{ROOT}', $this->root, (string) file_get_contents( $src ) ) );
			}
		}
	}

	/**
	 * Build a zip from a directory.
	 *
	 * @param string $src  Directory.
	 * @param string $dest Zip path.
	 */
	private function zip( string $src, string $dest ): void {
		$zip = new \ZipArchive();
		$zip->open( $dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			$zip->addFile( $path, substr( $path, strlen( str_replace( '\\', '/', $src ) ) + 1 ) );
		}
		$zip->close();
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $path Path.
	 */
	private static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					self::remove( $path . '/' . $name );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}
