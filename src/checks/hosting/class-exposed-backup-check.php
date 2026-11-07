<?php
/**
 * B3: backups, database dumps and config copies sitting where the web server can serve them.
 *
 * Real case: a retired site's full backup zip was left in its own docroot while the domain
 * still pointed there. It downloaded for anyone, and the wp-config.php inside held live
 * database credentials.
 *
 * Finding a file is not the same as proving it can be downloaded. For each file the check asks
 * the site itself for the headers (HTTP HEAD, nothing is downloaded, at most 8 per scan). Only
 * positive proof raises the finding: a successful answer that is not an HTML page and whose
 * length equals the file size means "served", and an archive then becomes critical. A refusal
 * (403/404) never lowers a finding, because a firewall may treat the site's own request
 * differently from a visitor's. Known false positives of the base finding: an archive the web
 * server already blocks (stays a warning, with the refusal shown as evidence).
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
	 * Most files probed per scan, so the check stays cheap on a site full of archives.
	 */
	const MAX_PROBES = 8;

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
		$probes   = 0;
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
				$probe = null;
				if ( $probes < self::MAX_PROBES && null !== $ctx->loopback() ) {
					++$probes;
					$probe = $ctx->loopback()->head( self::web_path( $ctx->relative( $path ) ) );
				}
				$findings[] = $this->finding( $ctx, $path, $kind, $is_docroot, $probe );
			}
		}
		return $findings;
	}

	/**
	 * Web path of a file given its path relative to the site root.
	 *
	 * @param string $relative Path relative to the site root.
	 * @return string
	 */
	public static function web_path( string $relative ): string {
		return '/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $relative, '/' ) ) ) );
	}

	/**
	 * Did the site's own answer prove the file is served?
	 *
	 * True only for a 200 that is not an HTML page and whose Content-Length equals the file size.
	 * False for a plain refusal or an HTML page (a catch-all "not found" page). Null when the
	 * answer proves nothing either way (errors, no length, a different size).
	 *
	 * @param array{status: int, type: string, length: int, error: string} $head Answer to the HEAD request.
	 * @param int                                                          $size Size of the file on disk.
	 * @return bool|null
	 */
	public static function is_served( array $head, int $size ): ?bool {
		if ( 200 === $head['status'] && false === strpos( $head['type'], 'html' ) ) {
			return $head['length'] === $size && $size > 0 ? true : null;
		}
		if ( in_array( $head['status'], array( 401, 403, 404, 410 ), true ) || ( 200 === $head['status'] && false !== strpos( $head['type'], 'html' ) ) ) {
			return false;
		}
		return null;
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
	 * @param Context                                                           $ctx        Context.
	 * @param string                                                            $path       Path.
	 * @param string                                                            $kind       Kind.
	 * @param bool                                                              $is_docroot In the site root.
	 * @param array{status: int, type: string, length: int, error: string}|null $probe Answer of the site to a HEAD request for the file, or null when not asked.
	 * @return Finding
	 */
	private function finding( Context $ctx, string $path, string $kind, bool $is_docroot, ?array $probe = null ): Finding {
		$size   = $ctx->fs()->size( $path );
		$served = null === $probe ? null : self::is_served( $probe, $size );
		$facts  = array( 'bytes' => $size );
		if ( true === $served ) {
			$facts['downloadable'] = 'yes: the site answered HTTP ' . $probe['status'] . ' with the same length';
		} elseif ( null !== $probe && 0 !== $probe['status'] ) {
			$facts['site_answer'] = 'HTTP ' . $probe['status'] . ( '' !== $probe['type'] ? ', ' . $probe['type'] : '' );
		}
		$evidence = array(
			new Evidence( 'file', $ctx->relative( $path ), 0, '', $facts ),
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
		if ( true === $served ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				$is_docroot ? __( 'An archive in the site root can be downloaded by anyone', 'earlyward' ) : __( 'An archive in wp-content can be downloaded by anyone', 'earlyward' ),
				__( 'The site itself asked for this file over the web and the server said it would send it. Site backups always include wp-config.php, which holds the database password, so assume the contents are exposed.', 'earlyward' ),
				$evidence,
				array(
					__( 'Move it out of the website folder (for example to a "backups" folder beside it), or delete it if you have another copy. Do this first: the web server is sending it right now.', 'earlyward' ),
					__( 'Open the file\'s web address again afterwards. It should say "not found".', 'earlyward' ),
					$steps[2],
				)
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
