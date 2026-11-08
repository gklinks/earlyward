<?php
/**
 * C15: files in the web root that do not belong to WordPress.
 *
 * Attack kits and half-finished cleanups leave PHP files next to wp-config.php (web shells, bypass
 * scripts, upload helpers, empty placeholders) and renamed server-config files. Core's own root files
 * are listed here; anything else that PHP can run, or that looks like a config or maintenance
 * leftover, is reported. Detection only. The walk is one directory, never recursive.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Integrity;

use Earlyward\Checks\Check;
use Earlyward\Checks\Code_Traits;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Root strays check.
 */
final class Root_Strays_Check implements Check {

	const ID = 'C15';

	/**
	 * PHP files WordPress core keeps in the root.
	 */
	const CORE = array(
		'index.php',
		'wp-activate.php',
		'wp-blog-header.php',
		'wp-comments-post.php',
		'wp-config.php',
		'wp-config-sample.php',
		'wp-cron.php',
		'wp-links-opml.php',
		'wp-load.php',
		'wp-login.php',
		'wp-mail.php',
		'wp-settings.php',
		'wp-signup.php',
		'wp-trackback.php',
		'xmlrpc.php',
	);

	/**
	 * Root PHP files that well-known plugins create on purpose. Reported only if they carry malware traits.
	 */
	const KNOWN_PLUGIN_FILES = array( 'wordfence-waf.php' );

	const PHP_NAME  = '/\.(php\d?|phtml|phar)$/i';
	const LEFTOVER  = '/^(.+\.htaccess|\.?\d*maintenance)$/i';
	const MAX_FILES = 40;
	const MAX_ROOT  = 2000;

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
		$root     = $ctx->root();
		$findings = array();
		foreach ( $fs->list_dir( $root, self::MAX_ROOT ) as $name ) {
			$path = $root . '/' . $name;
			if ( ! $fs->is_file( $path ) || is_link( $path ) || in_array( strtolower( $name ), self::CORE, true ) ) {
				continue;
			}
			if ( 1 === preg_match( self::PHP_NAME, $name ) ) {
				if ( in_array( strtolower( $name ), self::KNOWN_PLUGIN_FILES, true ) && empty( Code_Traits::find( (string) $fs->read( $path, Code_Traits::READ_BYTES ) ) ) ) {
					continue;
				}
				$findings[] = $this->php_finding( $ctx, $path );
			} elseif ( 1 === preg_match( self::LEFTOVER, $name ) ) {
				$findings[] = $this->leftover_finding( $ctx, $path );
			}
			if ( count( $findings ) >= self::MAX_FILES ) {
				break;
			}
		}
		return $findings;
	}

	/**
	 * Evidence for a file.
	 *
	 * @param Context               $ctx    Context.
	 * @param string                $path   Path.
	 * @param array<string, string> $traits Traits.
	 * @return Evidence
	 */
	private function evidence( Context $ctx, string $path, array $traits = array() ): Evidence {
		return new Evidence(
			'file',
			$ctx->relative( $path ),
			0,
			empty( $traits ) ? '' : implode( '; ', array_values( $traits ) ),
			array(
				'bytes'  => $ctx->fs()->size( $path ),
				'sha256' => $ctx->fs()->sha256( $path ),
				'traits' => implode( ',', array_keys( $traits ) ),
			)
		);
	}

	/**
	 * A PHP file that core does not ship.
	 *
	 * @param Context $ctx  Context.
	 * @param string  $path Path.
	 * @return Finding
	 */
	private function php_finding( Context $ctx, string $path ): Finding {
		$traits   = Code_Traits::find( (string) $ctx->fs()->read( $path, Code_Traits::READ_BYTES ) );
		$evidence = $this->evidence( $ctx, $path, $traits );
		$steps    = array(
			__( 'Open the file in a text editor (do not run it) and check what it does. WordPress never needs extra PHP files next to wp-config.php.', 'earlyward' ),
			__( 'If you do not recognise it, download a copy for your records and then delete it. Also look for files with similar names or the same modification time.', 'earlyward' ),
			__( 'If a security scanner or your host created it, it may be a harmless leftover that you can delete once you know what made it.', 'earlyward' ),
		);
		if ( ! empty( $traits ) ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				__( 'A PHP file with malware traits sits in the web root', 'earlyward' ),
				__( 'The file contains code patterns used to hide and run attacker code. Treat the site as possibly compromised.', 'earlyward' ),
				array( $evidence ),
				$steps
			);
		}
		if ( 0 === $ctx->fs()->size( $path ) ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_LOW,
				__( 'An empty PHP file sits in the web root', 'earlyward' ),
				__( 'An empty file cannot run anything. It is often what a malware scanner or a cleanup leaves behind, so it can point to an old break-in worth knowing about.', 'earlyward' ),
				array( $evidence ),
				$steps
			);
		}
		return new Finding(
			self::ID,
			Finding::SEVERITY_WARNING,
			Finding::CONFIDENCE_MEDIUM,
			__( 'An unexpected PHP file sits in the web root', 'earlyward' ),
			__( 'This file is not part of WordPress core. Web shells and upload helpers are often placed here, because every file in the root can be requested from the web.', 'earlyward' ),
			array( $evidence ),
			$steps
		);
	}

	/**
	 * A renamed server-config or maintenance leftover.
	 *
	 * @param Context $ctx  Context.
	 * @param string  $path Path.
	 * @return Finding
	 */
	private function leftover_finding( Context $ctx, string $path ): Finding {
		return new Finding(
			self::ID,
			Finding::SEVERITY_INFO,
			Finding::CONFIDENCE_LOW,
			__( 'A leftover configuration or maintenance file sits in the web root', 'earlyward' ),
			__( 'Files like these are usually left behind by a cleanup, a host tool or a maintenance plugin. They do no harm by themselves, but they can contain server rules or old settings you no longer remember.', 'earlyward' ),
			array( $this->evidence( $ctx, $path ) ),
			array(
				__( 'Open the file and check whether you still need it. If not, download a copy and delete it.', 'earlyward' ),
			)
		);
	}
}
