<?php
/**
 * A2: code that was switched off after a crash, and fatal errors WordPress caught.
 *
 * Real case: a code-sandbox plugin wrote a ".crashed" marker after one of its files was killed
 * mid-run, and from then on silently skipped all the custom code it manages. The marker sat
 * there for 12 days on one site, and longer on two others, before anyone looked.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Runtime;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Crash check.
 */
final class Crash_Check implements Check {

	const ID = 'A2';

	/**
	 * Known crash markers, relative to wp-content. Add entries as more tools are documented.
	 *
	 * @var array<int, array{path: string, product: string, file_key: string}>
	 */
	const MARKERS = array(
		array(
			'path'     => 'novamira-sandbox/.crashed',
			'product'  => 'Novamira sandbox',
			'file_key' => 'sandbox_file',
		),
	);

	/**
	 * How far back a recovery-mode email counts as recent.
	 */
	const RECENT_SECONDS = 604800;

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
		$findings = array();
		foreach ( self::MARKERS as $marker ) {
			$path = $ctx->content_dir() . '/' . $marker['path'];
			if ( ! $ctx->fs()->is_file( $path ) ) {
				continue;
			}
			$since = $ctx->fs()->mtime( $path );
			$data  = json_decode( (string) $ctx->fs()->read( $path, 8192 ), true );
			$file  = is_array( $data ) && isset( $data[ $marker['file_key'] ] ) ? basename( (string) $data[ $marker['file_key'] ] ) : '';
			$days  = $since > 0 ? (int) floor( ( $ctx->now() - $since ) / 86400 ) : -1;

			$findings[] = new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				sprintf( '%s switched off custom code after a crash', $marker['product'] ),
				'While this marker exists, the code it manages does not run at all, but the site keeps answering normally. Pages can lose their styling, features and custom addresses without any error being shown.',
				array(
					new Evidence(
						'file',
						$ctx->relative( $path ),
						0,
						'' === $file ? '' : 'crashed file: ' . $file,
						array(
							'since'      => $since > 0 ? gmdate( 'Y-m-d H:i', $since ) . ' UTC' : 'unknown',
							'days_ago'   => $days,
							'file_named' => $file,
						)
					),
				),
				array(
					'Open the file named in the evidence and check it for a real error (run it in a test copy if you can). A crash can also be a one-off, such as the host killing a long request.',
					'Once the file is fixed, or you are sure it was a one-off, delete the marker file to switch the code back on.',
					'Purge page caches afterwards, then check the homepage (check A1).',
				)
			);
		}

		$db = $ctx->db();
		if ( null !== $db ) {
			$sent = (int) $db->option( 'recovery_mode_email_last_sent' );
			if ( $sent > 0 && $ctx->now() - $sent <= self::RECENT_SECONDS ) {
				$findings[] = new Finding(
					self::ID,
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_HIGH,
					'WordPress caught a fatal error recently',
					'WordPress emails a "recovery mode" link when a plugin or theme causes a fatal error. Visitors may have seen "There has been a critical error on this website".',
					array(
						new Evidence(
							'option',
							'option:recovery_mode_email_last_sent',
							0,
							'',
							array( 'sent' => gmdate( 'Y-m-d H:i', $sent ) . ' UTC' )
						),
					),
					array(
						'Look for the recovery-mode email sent to the admin address at that time; it names the plugin or theme.',
						'Check the error log (A3) around that time for the "PHP Fatal error" line.',
					)
				);
			}
		}
		return $findings;
	}
}
