<?php
/**
 * C2: hex-named zip archives that malware uses to rebuild itself.
 *
 * Real case: a seed archive sat in wp-content/ with a copy under uploads/YYYY/MM/. Every
 * cleanup removed the PHP files and left the archive, so everything came back in seconds.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Integrity;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Archive seed check.
 */
final class Archive_Seed_Check implements Check {

	const ID = 'C2';

	const NAME_PATTERN = '/^[a-f0-9]{8,16}\.zip$/';

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
		$fs = $ctx->fs();

		// basename => list of [path, in_uploads].
		$seen = array();
		foreach ( $this->candidate_dirs( $ctx ) as $dir => $in_uploads ) {
			foreach ( $fs->list_dir( $dir ) as $name ) {
				if ( preg_match( self::NAME_PATTERN, $name ) && $fs->is_file( $dir . '/' . $name ) ) {
					$seen[ $name ][] = array( $dir . '/' . $name, $in_uploads );
				}
			}
		}

		$findings = array();
		foreach ( $seen as $name => $copies ) {
			$twinned = count( $copies ) > 1;
			foreach ( $copies as $copy ) {
				$finding = $this->classify( $ctx, $copy[0], $copy[1], $twinned );
				if ( null !== $finding ) {
					$findings[] = $finding;
				}
			}
		}
		return $findings;
	}

	/**
	 * The bounded set of directories to look in: wp-content, uploads, uploads/YYYY, uploads/YYYY/MM.
	 *
	 * @param Context $ctx Context.
	 * @return array<string, bool> dir => is inside uploads
	 */
	private function candidate_dirs( Context $ctx ): array {
		$fs      = $ctx->fs();
		$uploads = $ctx->uploads_dir();
		$dirs    = array(
			$ctx->content_dir() => false,
			$uploads            => true,
		);
		foreach ( $fs->list_dir( $uploads ) as $year ) {
			if ( ! preg_match( '/^\d{4}$/', $year ) || ! $fs->is_dir( $uploads . '/' . $year ) ) {
				continue;
			}
			$dirs[ $uploads . '/' . $year ] = true;
			foreach ( $fs->list_dir( $uploads . '/' . $year ) as $month ) {
				if ( preg_match( '/^\d{2}$/', $month ) && $fs->is_dir( $uploads . '/' . $year . '/' . $month ) ) {
					$dirs[ $uploads . '/' . $year . '/' . $month ] = true;
				}
			}
		}
		return $dirs;
	}

	/**
	 * Decide what one archive means.
	 *
	 * @param Context $ctx        Context.
	 * @param string  $path       Archive path.
	 * @param bool    $in_uploads Inside uploads.
	 * @param bool    $twinned    Same name found in more than one place.
	 * @return Finding|null
	 */
	private function classify( Context $ctx, string $path, bool $in_uploads, bool $twinned ): ?Finding {
		$entries  = $ctx->fs()->zip_entries( $path );
		$matched  = null === $entries ? array() : preg_grep( '/\.(php\d?|phtml|phar)$/i', $entries );
		$php      = false === $matched ? array() : array_values( $matched );
		$has_php  = ! empty( $php );
		$evidence = array(
			new Evidence(
				'file',
				$ctx->relative( $path ),
				0,
				$has_php ? 'contains: ' . implode( ', ', array_slice( $php, 0, 5 ) ) : '',
				array(
					'bytes'       => $ctx->fs()->size( $path ),
					'entries'     => null === $entries ? -1 : count( $entries ),
					'php_entries' => count( $php ),
					'twinned'     => $twinned,
				)
			),
		);

		if ( $in_uploads && null !== $entries && ! $has_php ) {
			return null; // A hex-named zip of images or documents is ordinary.
		}

		$steps = array(
			'Download a copy for your records if you want one, then delete this archive, and every other copy listed, in the same cleanup pass as the PHP files. Archives first.',
			'Do not trust a cleanup that removed PHP files but left these archives: the malware rebuilds itself from them within seconds.',
			'If several sites share one hosting account, check every site for the same file name.',
		);

		if ( $in_uploads && null === $entries ) {
			return new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_LOW,
				'A randomly named zip archive in uploads could not be inspected',
				'Archives with random hex names have been used to rebuild malware after cleanup. This one could not be opened to check for PHP files (the PHP zip extension may be missing).',
				$evidence,
				array_merge( array( 'Open the archive on your computer and check whether it contains .php files.' ), $steps )
			);
		}

		$high = $has_php || $twinned;
		return new Finding(
			self::ID,
			Finding::SEVERITY_CRITICAL,
			$high ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
			$has_php ? 'A randomly named zip archive containing PHP code was found' : 'A randomly named zip archive sits directly in wp-content',
			'Self-healing malware keeps a zip "seed" like this and re-extracts itself from it after every cleanup. Nothing legitimate in WordPress stores hex-named archives at this location.',
			$evidence,
			$steps
		);
	}
}
