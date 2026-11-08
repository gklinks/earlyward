<?php
/**
 * C12: PHP files inside the uploads folder.
 *
 * Uploads should hold images and documents. Attackers drop PHP there because the folder is writable
 * and public. Detection only: nothing is deleted. Tiny placeholder index files that WordPress and
 * plugins create ("Silence is golden") are ignored. The walk is bounded: uploads, year and month
 * folders, and the first level of folders plugins create.
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
 * Uploads PHP check.
 */
final class Uploads_Php_Check implements Check {

	const ID = 'C12';

	const PHP_NAME   = '/\.(php\d?|phtml|phar)$/i';
	const MAX_DIRS   = 400;
	const MAX_FILES  = 40;
	const TINY_BYTES = 120;

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
		$count    = 0;
		foreach ( $this->dirs( $ctx ) as $dir ) {
			foreach ( $fs->list_dir( $dir ) as $name ) {
				$path = $dir . '/' . $name;
				if ( 1 !== preg_match( self::PHP_NAME, $name ) || ! $fs->is_file( $path ) ) {
					continue;
				}
				$code   = (string) $fs->read( $path, Code_Traits::READ_BYTES );
				$traits = Code_Traits::find( $code );
				if ( empty( $traits ) && $this->is_placeholder( $name, $code ) ) {
					continue;
				}
				if ( ++$count > self::MAX_FILES ) {
					break 2;
				}
				$findings[] = $this->finding( $ctx, $path, $traits );
			}
		}
		return $findings;
	}

	/**
	 * Directories to look in.
	 *
	 * @param Context $ctx Context.
	 * @return string[]
	 */
	private function dirs( Context $ctx ): array {
		$fs      = $ctx->fs();
		$uploads = $ctx->uploads_dir();
		$dirs    = array( $uploads );
		foreach ( $fs->list_dir( $uploads ) as $first ) {
			if ( ! $fs->is_dir( $uploads . '/' . $first ) || is_link( $uploads . '/' . $first ) ) {
				continue;
			}
			$dirs[] = $uploads . '/' . $first;
			if ( 1 === preg_match( '/^\d{4}$/', $first ) ) {
				foreach ( $fs->list_dir( $uploads . '/' . $first ) as $month ) {
					if ( 1 === preg_match( '/^\d{2}$/', $month ) && $fs->is_dir( $uploads . '/' . $first . '/' . $month ) ) {
						$dirs[] = $uploads . '/' . $first . '/' . $month;
					}
				}
			}
			if ( count( $dirs ) >= self::MAX_DIRS ) {
				break;
			}
		}
		return $dirs;
	}

	/**
	 * A tiny index.php with no executable statements, such as "<?php // Silence is golden."
	 *
	 * @param string $name File name.
	 * @param string $code Contents.
	 * @return bool
	 */
	private function is_placeholder( string $name, string $code ): bool {
		if ( 'index.php' !== strtolower( $name ) || strlen( $code ) > self::TINY_BYTES ) {
			return false;
		}
		$body = preg_replace( '#/\*.*?\*/|//[^\n]*|\#[^\n]*#s', '', $code );
		$body = trim( str_replace( array( '<?php', '?>' ), '', (string) $body ) );
		return '' === $body;
	}

	/**
	 * One finding.
	 *
	 * @param Context               $ctx    Context.
	 * @param string                $path   File.
	 * @param array<string, string> $traits Code traits found.
	 * @return Finding
	 */
	private function finding( Context $ctx, string $path, array $traits ): Finding {
		$evidence = new Evidence(
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
		$steps    = array(
			__( 'Open the file in a text editor (do not run it) and check what it does. Image and document uploads never need PHP.', 'earlyward' ),
			__( 'If you do not recognise it, download a copy for your records, then delete it, and look for other files with the same name or a similar one added at the same time.', 'earlyward' ),
			__( 'Ask your host to block PHP execution inside the uploads folder, so a file like this cannot run even if one is planted.', 'earlyward' ),
		);
		if ( ! empty( $traits ) ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				__( 'A PHP file with malware traits sits in uploads', 'earlyward' ),
				__( 'The file contains code patterns used to hide and run attacker code, and uploads is a folder anyone can reach from the web. Treat the site as possibly compromised.', 'earlyward' ),
				array( $evidence ),
				$steps
			);
		}
		if ( 0 === $ctx->fs()->size( $path ) ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_LOW,
				__( 'An empty PHP file sits in the uploads folder', 'earlyward' ),
				__( 'An empty file cannot run anything. It is often what is left after a malware scanner or a cleanup emptied a planted file, so it can point to an old break-in worth knowing about.', 'earlyward' ),
				array( $evidence ),
				array(
					__( 'You can delete it. If the site was ever hacked, make sure the cleanup also covered the rest of the site, then change the passwords.', 'earlyward' ),
				)
			);
		}
		return new Finding(
			self::ID,
			Finding::SEVERITY_WARNING,
			Finding::CONFIDENCE_MEDIUM,
			__( 'A PHP file sits in the uploads folder', 'earlyward' ),
			__( 'Uploads should contain images and documents only. Some plugins keep PHP there on purpose, but attackers use this folder because it is writable and public.', 'earlyward' ),
			array( $evidence ),
			$steps
		);
	}
}
