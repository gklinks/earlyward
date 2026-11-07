<?php
/**
 * B3: backups, database dumps and config copies sitting where the web server can serve them.
 *
 * Real case: a retired site's full backup zip was left in its own docroot while the domain
 * still pointed there. It downloaded for anyone, and the wp-config.php inside held live
 * database credentials.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Hosting;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Exposed backup check.
 */
final class Exposed_Backup_Check implements Check {

	const ID = 'B3';

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$fs       = $ctx->fs();
		$findings = array();
		$dirs     = array(
			$ctx->root()        => true,
			$ctx->content_dir() => false,
		);
		foreach ( $dirs as $dir => $is_docroot ) {
			foreach ( $fs->list_dir( $dir ) as $name ) {
				$path = $dir . '/' . $name;
				if ( ! $fs->is_file( $path ) ) {
					continue;
				}
				$kind = self::kind( $name );
				if ( null === $kind ) {
					continue;
				}
				// Hex-named zips in wp-content are malware seeds, reported by C2.
				if ( 'archive' === $kind && ! $is_docroot && preg_match( '/^[a-f0-9]{8,16}\.zip$/', $name ) ) {
					continue;
				}
				$findings[] = $this->finding( $ctx, $path, $kind, $is_docroot );
			}
		}
		return $findings;
	}

	/**
	 * What kind of exposed file a name suggests.
	 *
	 * @param string $name File name.
	 * @return string|null "config", "dump", "archive" or null.
	 */
	public static function kind( string $name ): ?string {
		$lower = strtolower( $name );
		if ( 'wp-config.php' !== $lower && 'wp-config-sample.php' !== $lower
			&& preg_match( '/^\.?wp-config.*(\.(bak|old|orig|save|swp|swo|txt|backup|copy|tmp|dist)|~)$|^wp-config\.php\..+$/', $lower ) ) {
			return 'config';
		}
		if ( preg_match( '/\.sql(\.(gz|zip|bz2|xz))?$/', $lower ) ) {
			return 'dump';
		}
		if ( preg_match( '/\.(zip|tar|tgz|gz|bz2|xz|7z|rar)$/', $lower ) ) {
			return 'archive';
		}
		return null;
	}

	/**
	 * Build a finding.
	 *
	 * @param Context $ctx        Context.
	 * @param string  $path       Path.
	 * @param string  $kind       Kind.
	 * @param bool    $is_docroot In the site root.
	 * @return Finding
	 */
	private function finding( Context $ctx, string $path, string $kind, bool $is_docroot ): Finding {
		$evidence = array(
			new Evidence( 'file', $ctx->relative( $path ), 0, '', array( 'bytes' => $ctx->fs()->size( $path ) ) ),
		);
		$steps    = array(
			__( 'Open the file\'s web address in a private browser window. If it downloads, assume someone else has a copy.', 'earlyward' ),
			__( 'Move it out of the website folder (for example to a "backups" folder beside it), or delete it if you have another copy.', 'earlyward' ),
			__( 'If it was downloadable and contains wp-config.php or a database, change the database password and the WordPress security keys.', 'earlyward' ),
		);

		if ( 'config' === $kind ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				__( 'A copy of wp-config.php may be readable by anyone', 'earlyward' ),
				__( 'Copies with endings like .bak or ~ are not run as PHP, so the web server sends them as plain text: database password and security keys included.', 'earlyward' ),
				$evidence,
				$steps
			);
		}
		if ( 'dump' === $kind ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				__( 'A database dump sits inside the website folder', 'earlyward' ),
				__( 'A database dump contains every user, password hash and setting. Inside the website folder it can usually be downloaded by anyone who guesses the name.', 'earlyward' ),
				$evidence,
				$steps
			);
		}
		return new Finding(
			self::ID,
			Finding::SEVERITY_WARNING,
			$is_docroot ? Finding::CONFIDENCE_MEDIUM : Finding::CONFIDENCE_LOW,
			$is_docroot ? __( 'An archive sits in the site root, where it can probably be downloaded', 'earlyward' ) : __( 'An archive sits in wp-content, where it can probably be downloaded', 'earlyward' ),
			__( 'Site backups always include wp-config.php, which holds the database password. Archives in a web folder are a common, easily guessed target.', 'earlyward' ),
			$evidence,
			$steps
		);
	}
}
