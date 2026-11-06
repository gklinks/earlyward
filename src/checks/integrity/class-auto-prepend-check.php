<?php
/**
 * C1: PHP files loaded before (or after) every request via auto_prepend_file / auto_append_file.
 *
 * Real case: a .user.ini at the site root AND in wp-content pointed every request at a tiny
 * loader. One file was a hybrid: a genuine firewall line plus a malicious one, so the file
 * must be classified line by line, never as a whole.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Integrity;

use Wallwarden\Checks\Check;
use Wallwarden\Env\Context;
use Wallwarden\Env\Filesystem;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-prepend check.
 */
final class Auto_Prepend_Check implements Check {

	const ID = 'C1';

	/**
	 * Per-directory config files PHP or the web server may honour.
	 *
	 * @var string[]
	 */
	const CONFIG_FILES = array( '.user.ini', 'php.ini', '.htaccess' );

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
		// target => list of Evidence.
		$by_target = array();

		foreach ( $this->directories( $ctx ) as $dir ) {
			foreach ( self::CONFIG_FILES as $name ) {
				$path  = $dir . '/' . $name;
				$lines = $ctx->fs()->lines( $path );
				if ( null === $lines ) {
					continue;
				}
				$is_htaccess = '.htaccess' === $name;
				foreach ( $lines as $number => $text ) {
					$parsed = self::parse_line( $text, $is_htaccess );
					if ( null === $parsed ) {
						continue;
					}
					$by_target[ $parsed['target'] ][] = new Evidence(
						'file',
						$ctx->relative( $path ),
						$number,
						$text,
						array( 'directive' => $parsed['directive'] )
					);
				}
			}
		}

		// The value PHP is actually using for this request (web SAPI only; CLI ignores .user.ini).
		foreach ( array( 'auto_prepend_file', 'auto_append_file' ) as $directive ) {
			$value = self::clean_value( $ctx->ini( $directive ) );
			if ( '' !== $value ) {
				$by_target[ $value ][] = new Evidence(
					'runtime',
					'php:' . $directive,
					0,
					$directive . ' = ' . $value,
					array( 'sapi' => $ctx->ini( 'wallwarden.sapi' ) )
				);
			}
		}

		$findings = array();
		foreach ( $by_target as $target => $evidence ) {
			$finding = $this->classify( $ctx, (string) $target, $evidence );
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}
		return $findings;
	}

	/**
	 * Directories whose config files apply to WordPress requests.
	 *
	 * @param Context $ctx Context.
	 * @return string[]
	 */
	private function directories( Context $ctx ): array {
		$dirs = array(
			$ctx->root(),
			$ctx->root() . '/wp-admin',
			$ctx->root() . '/wp-includes',
			$ctx->content_dir(),
			$ctx->uploads_dir(),
		);
		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Parse one config line.
	 *
	 * @param string $line        Raw line.
	 * @param bool   $is_htaccess Apache syntax instead of ini syntax.
	 * @return array{directive: string, target: string}|null
	 */
	public static function parse_line( string $line, bool $is_htaccess ): ?array {
		$trimmed = ltrim( $line );
		if ( '' === $trimmed || '#' === $trimmed[0] || ( ! $is_htaccess && ';' === $trimmed[0] ) ) {
			return null;
		}
		$pattern = $is_htaccess
			? '/^php_(?:admin_)?value\s+(auto_(?:prepend|append)_file)\s+(.*)$/i'
			: '/^(auto_(?:prepend|append)_file)\s*=\s*(.*)$/i';
		if ( ! preg_match( $pattern, $trimmed, $m ) ) {
			return null;
		}
		$value = self::clean_value( $m[2] );
		if ( '' === $value ) {
			return null;
		}
		return array(
			'directive' => strtolower( $m[1] ),
			'target'    => $value,
		);
	}

	/**
	 * Strip quotes and trailing comments; "none" means unset.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean_value( string $value ): string {
		$value = trim( $value );
		if ( '' !== $value && ( '"' === $value[0] || "'" === $value[0] ) ) {
			$end   = strpos( $value, $value[0], 1 );
			$value = false === $end ? substr( $value, 1 ) : substr( $value, 1, $end - 1 );
		} else {
			$value = trim( (string) preg_replace( '/\s[;#].*$/', '', $value ) );
		}
		return 'none' === strtolower( $value ) ? '' : $value;
	}

	/**
	 * Decide what a target means.
	 *
	 * @param Context    $ctx      Context.
	 * @param string     $target   File the directive loads.
	 * @param Evidence[] $evidence Where it was seen.
	 * @return Finding|null Null when the target is a recognised firewall loader.
	 */
	private function classify( Context $ctx, string $target, array $evidence ): ?Finding {
		$norm = Filesystem::normalise( $target );
		$base = basename( $norm );
		$abs  = ( '/' === $norm[0] || preg_match( '#^[A-Za-z]:/#', $norm ) ) ? $norm : $ctx->root() . '/' . $norm;
		// Compare real locations: the same directory can be spelled two ways (symlinked docroots are common).
		$abs        = Filesystem::canonical( $abs );
		$target_dir = dirname( $abs );
		$root       = Filesystem::canonical( $ctx->root() );
		$content    = Filesystem::canonical( $ctx->content_dir() );
		$plugins    = $content . '/plugins/';
		$evidence   = array_merge(
			$evidence,
			array( new Evidence( 'target', $ctx->relative( $abs ), 0, $target ) )
		);

		// Wordfence's own loader lives at the WordPress root. Benign only if Wordfence is installed.
		if ( 'wordfence-waf.php' === $base && $target_dir === $root ) {
			if ( $ctx->fs()->is_dir( $plugins . 'wordfence' ) ) {
				return null;
			}
			return new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				'A Wordfence firewall loader is set up, but Wordfence is not installed',
				'PHP loads this file before every request. Leftovers from an uninstalled plugin are usually harmless, but malware has used familiar names to blend in.',
				$evidence,
				array(
					'Open the target file and check that it only loads Wordfence.',
					'If Wordfence is no longer used, remove the line from the config file shown, then delete the loader.',
				)
			);
		}

		// Loaded from inside an installed plugin: firewall plugins do this on purpose.
		if ( 0 === strpos( $abs, $plugins ) ) {
			$slug = strtok( substr( $abs, strlen( $plugins ) ), '/' );
			if ( false !== $slug && $ctx->fs()->is_dir( $plugins . $slug ) ) {
				return new Finding(
					self::ID,
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_LOW,
					sprintf( 'The plugin "%s" runs before WordPress on every request', $slug ),
					'Firewall plugins do this on purpose. Anything else doing it can see and change every request, including logins.',
					$evidence,
					array(
						sprintf( 'Confirm that "%s" is a firewall or security plugin you installed.', $slug ),
						'If it is not, remove only this line from the config file shown, then investigate the plugin.',
					)
				);
			}
		}

		$suspicious_name = (bool) preg_match( '/^\.|^[a-f0-9]{6,}\.php$/i', $base );
		$in_content_root = $target_dir === $content || $target_dir === $root;
		return new Finding(
			self::ID,
			Finding::SEVERITY_CRITICAL,
			( $suspicious_name || $in_content_root ) ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
			'PHP is told to run an unknown file before (or after) every request',
			'This is a classic malware loader: it runs on every page view, before WordPress and any security plugin. Deleting the loaded file alone does not help, because the loader is usually recreated from elsewhere.',
			$evidence,
			array(
				'Remove only the flagged line from each config file shown. Keep any genuine firewall line in the same file.',
				'Check the same file name at the other locations listed (site root, wp-content, wp-admin, uploads).',
				'Look at the target file and any hidden file with a similar name (starting with a dot) next to it.',
				'PHP can cache .user.ini settings for a few minutes, so re-check after about 5 minutes.',
				'If several sites share one hosting account, check all of them at the same time: one infected site can re-infect the others.',
			)
		);
	}
}
