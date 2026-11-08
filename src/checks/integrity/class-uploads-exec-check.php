<?php
/**
 * C17: the uploads folder is not set to refuse PHP.
 *
 * Uploaded files are the usual way in for an attacker: a plugin flaw lets them drop a .php file in uploads
 * and then request it. Telling the web server never to run PHP there stops that last step. The check reads
 * wp-content/uploads/.htaccess only (it sends no test file and writes nothing). Apache and LiteSpeed obey
 * that file; nginx does not, so for nginx the finding gives the server rule instead of a button.
 * Detection here; the one-click fix creates the file only when none exists.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Integrity;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads execution check.
 */
final class Uploads_Exec_Check implements Check {

	const ID = 'C17';

	/**
	 * The file the fix creates. Works on Apache 2.2 and 2.4 and on LiteSpeed. The pattern also catches
	 * names such as shell.php.jpg, which some servers still run.
	 */
	const RULES = '# Added by Earlyward: never run PHP from the uploads folder.
# Delete this file to undo; uploaded images and documents are not affected.
<FilesMatch "\.(php[0-9]?|phtml|phar|pht|phps)(\.|$)">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Order allow,deny
		Deny from all
	</IfModule>
</FilesMatch>
';

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Whether a web server that reads .htaccess is (or may be) in use. Unknown counts as yes: a scan from
	 * the command line has no server name, and the fix runs in a web request that does.
	 *
	 * @param Context $ctx Context.
	 * @return bool
	 */
	public static function server_reads_htaccess( Context $ctx ): bool {
		$server = strtolower( $ctx->ini( 'earlyward.server' ) );
		return '' === $server || false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' );
	}

	/**
	 * Whether an .htaccess text already stops PHP from running.
	 *
	 * @param string $text File contents.
	 * @return bool
	 */
	public static function blocks_php( string $text ): bool {
		$text = (string) preg_replace( '/^\s*#.*$/m', '', $text ); // Commented-out rules do not count.
		if ( 1 === preg_match( '/php_flag\s+engine\s+off/i', $text ) || 1 === preg_match( '/RemoveHandler[^\n]*php/i', $text ) ) {
			return true;
		}
		return 1 === preg_match( '/<(FilesMatch|Files)\s[^>]*php/i', $text ) && 1 === preg_match( '/(Require\s+all\s+denied|Deny\s+from\s+all)/i', $text );
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$uploads = $ctx->uploads_dir();
		if ( ! $ctx->fs()->is_dir( $uploads ) ) {
			return array();
		}
		$file   = $uploads . '/.htaccess';
		$exists = $ctx->fs()->is_file( $file );
		if ( $exists ) {
			$text = $ctx->fs()->read( $file );
			if ( null !== $text && self::blocks_php( $text ) ) {
				return array();
			}
		}
		$htaccess = self::server_reads_htaccess( $ctx );
		$steps    = $htaccess
			? array(
				$exists
					? __( 'Add the lines from the Earlyward readme (a FilesMatch block that denies php, phtml and phar files) to the existing .htaccess in the uploads folder.', 'earlyward' )
					: __( 'Use the button to create the protective .htaccess file in the uploads folder, or create it yourself.', 'earlyward' ),
				__( 'Then open a test: upload nothing, but ask your host or a developer to confirm that a .php file placed in uploads answers 403.', 'earlyward' ),
			)
			: array(
				__( 'Your web server does not read .htaccess. Add a rule to the server configuration that refuses PHP under the uploads folder, for example in nginx: location ~* ^/wp-content/uploads/.*\.php$ { deny all; }', 'earlyward' ),
				__( 'Ask your host to add it if you cannot edit the server configuration.', 'earlyward' ),
			);
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_MEDIUM,
				__( 'The uploads folder is not set to refuse PHP', 'earlyward' ),
				__( 'If an attacker gets a PHP file into uploads through a plugin flaw, the web server will run it. Refusing PHP there stops the most common last step of a break-in. Nothing here says a file has been planted: check C12 looks for that.', 'earlyward' ),
				array(
					new Evidence(
						'file',
						$ctx->relative( $file ),
						0,
						'',
						array(
							'exists'         => $exists ? 'yes' : 'no',
							'reads_htaccess' => $htaccess ? 'yes' : 'no',
						)
					),
				),
				$steps
			),
		);
	}
}
