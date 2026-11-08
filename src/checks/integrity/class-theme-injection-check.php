<?php
/**
 * C13: injected code in the template files of installed themes.
 *
 * A common way to keep a foothold is a decode-and-run line added at the start or end of a theme's
 * functions.php, header.php or footer.php. This check reads those three files of every installed
 * theme (first 64 KB) and applies the same code-trait rules used elsewhere. Detection only: it never
 * edits a theme, and offers no fix button.
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
 * Theme injection check.
 */
final class Theme_Injection_Check implements Check {

	const ID = 'C13';

	const FILES      = array( 'functions.php', 'header.php', 'footer.php' );
	const MAX_THEMES = 60;

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
		$themes   = $ctx->content_dir() . '/themes';
		$findings = array();
		foreach ( array_slice( $fs->list_dir( $themes ), 0, self::MAX_THEMES ) as $theme ) {
			if ( ! $fs->is_dir( $themes . '/' . $theme ) || is_link( $themes . '/' . $theme ) ) {
				continue;
			}
			foreach ( self::FILES as $file ) {
				$path = $themes . '/' . $theme . '/' . $file;
				if ( ! $fs->is_file( $path ) ) {
					continue;
				}
				$traits = Code_Traits::find( (string) $fs->read( $path, Code_Traits::READ_BYTES ) );
				if ( ! empty( $traits ) ) {
					$findings[] = $this->finding( $ctx, $path, $traits );
				}
			}
		}
		return $findings;
	}

	/**
	 * One finding.
	 *
	 * @param Context               $ctx    Context.
	 * @param string                $path   File.
	 * @param array<string, string> $traits Traits found.
	 * @return Finding
	 */
	private function finding( Context $ctx, string $path, array $traits ): Finding {
		$strong = isset( $traits['eval_of_decoded'] ) || isset( $traits['request_to_executor'] );
		return new Finding(
			self::ID,
			$strong ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_WARNING,
			$strong ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
			__( 'A theme template file contains code that hides or runs other code', 'earlyward' ),
			__( 'Theme files such as functions.php run on every page. Attackers add a short line at the top or bottom to keep access after a cleanup. The patterns found here are not normal in a theme.', 'earlyward' ),
			array(
				new Evidence(
					'file',
					$ctx->relative( $path ),
					0,
					implode( '; ', array_values( $traits ) ),
					array(
						'bytes'  => $ctx->fs()->size( $path ),
						'sha256' => $ctx->fs()->sha256( $path ),
						'traits' => implode( ',', array_keys( $traits ) ),
					)
				),
			),
			array(
				__( 'Compare the file with a fresh copy of the same theme and version from its official download. Do not edit the file on the live site first: keep a copy for your records.', 'earlyward' ),
				__( 'If the theme is not in use, delete it. Reinstall the theme from its official source if it is.', 'earlyward' ),
				__( 'Treat the site as possibly compromised: change all administrator passwords and look for other changed files.', 'earlyward' ),
			)
		);
	}
}
