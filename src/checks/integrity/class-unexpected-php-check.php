<?php
/**
 * C3: PHP that WordPress loads automatically, or that has no business existing:
 * must-use plugins, drop-ins, hidden dot-files and loose PHP in wp-content.
 *
 * Real case: two campaigns hid here. A "recovery channel" named wp-<12 hex>.php with no
 * plugin header (so the plugin screen never lists it) re-installed itself every 30 minutes;
 * fake advanced-cache.php / db.php drop-ins; hex-named loaders and hidden ".name.php" twins
 * in wp-content; and a daemon that ran from a dot-file in wp-includes.
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
 * Unexpected PHP check.
 */
final class Unexpected_Php_Check implements Check {

	const ID = 'C3';

	/**
	 * Drop-in file names WordPress core loads from wp-content (see _get_dropins()).
	 *
	 * @var string[]
	 */
	const DROPINS = array(
		'advanced-cache.php',
		'db.php',
		'db-error.php',
		'install.php',
		'maintenance.php',
		'object-cache.php',
		'php-error.php',
		'fatal-error-handler.php',
		'sunrise.php',
		'blog-deleted.php',
		'blog-inactive.php',
		'blog-suspended.php',
	);

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
		$plugins  = $this->installed_plugins( $ctx );

		// 1. Must-use plugins: everything here runs on every request, with no on/off switch.
		$mu = $ctx->mu_plugins_dir();
		foreach ( $fs->list_dir( $mu ) as $name ) {
			$path = $mu . '/' . $name;
			if ( ! self::is_php_name( $name ) || ! $fs->is_file( $path ) ) {
				continue;
			}
			$findings[] = $this->classify_mu_plugin( $ctx, $path, $name );
		}

		// 2. wp-content root: drop-ins and loose PHP.
		$content = $ctx->content_dir();
		foreach ( $fs->list_dir( $content ) as $name ) {
			$path = $content . '/' . $name;
			if ( ! self::is_php_name( $name ) || ! $fs->is_file( $path ) ) {
				continue;
			}
			$finding = in_array( $name, self::DROPINS, true )
				? $this->classify_dropin( $ctx, $path, $name, $plugins )
				: $this->classify_loose( $ctx, $path, $name );
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}

		// 3. Hidden PHP in core directories and the site root. Core never ships dot-files.
		foreach ( array( $ctx->root(), $ctx->root() . '/wp-includes', $ctx->root() . '/wp-admin', $content . '/cache' ) as $dir ) {
			foreach ( $fs->list_dir( $dir ) as $name ) {
				if ( '.' === $name[0] && self::is_php_name( $name ) && $fs->is_file( $dir . '/' . $name ) ) {
					$findings[] = $this->hidden_file( $ctx, $dir . '/' . $name );
				}
			}
		}

		// 4. Hex-named PHP at the top of wp-content/cache (the kit's secondary bootstrap).
		foreach ( $fs->list_dir( $content . '/cache' ) as $name ) {
			$path = $content . '/cache/' . $name;
			if ( preg_match( '/^[a-f0-9]{8}\.php$/', $name ) && $fs->is_file( $path ) ) {
				$findings[] = $this->make(
					$ctx,
					$path,
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_MEDIUM,
					'A randomly named PHP file sits at the top of wp-content/cache',
					'Caching plugins keep their files in sub-folders. A hex-named PHP file here matches a known malware bootstrap.',
					array(),
					array( 'Check which caching plugin, if any, created it. If none, treat it as part of an infection.' )
				);
			}
		}

		return $findings;
	}

	/**
	 * PHP-executable file name.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private static function is_php_name( string $name ): bool {
		return (bool) preg_match( '/\.(php\d?|phtml)$/i', $name );
	}

	/**
	 * Installed plugin folder names.
	 *
	 * @param Context $ctx Context.
	 * @return string[]
	 */
	private function installed_plugins( Context $ctx ): array {
		$out = array();
		$dir = $ctx->content_dir() . '/plugins';
		foreach ( $ctx->fs()->list_dir( $dir ) as $name ) {
			if ( '.' !== $name[0] && $ctx->fs()->is_dir( $dir . '/' . $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Must-use plugin.
	 *
	 * @param Context $ctx  Context.
	 * @param string  $path Path.
	 * @param string  $name File name.
	 * @return Finding
	 */
	private function classify_mu_plugin( Context $ctx, string $path, string $name ): Finding {
		$code   = (string) $ctx->fs()->read( $path, Code_Traits::READ_BYTES );
		$traits = Code_Traits::find( $code );
		$header = self::plugin_name( $code );

		if ( preg_match( '/^wp-[a-f0-9]{10,14}\.php$/', $name ) ) {
			return $this->make(
				$ctx,
				$path,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				'A must-use plugin named like a known self-reinstalling backdoor',
				'Files named wp-<random hex>.php in mu-plugins were used as a "recovery channel" that reinstalls a spam operation every 30 minutes. Must-use plugins run on every request and never appear as active plugins.',
				$traits,
				array(
					'Check every site on the same hosting account for the same pattern at the same time.',
					'Removing this file alone is not enough: also look for the plugin it reinstalls and for database settings it left behind.',
				)
			);
		}

		if ( '.' === $name[0] ) {
			return $this->hidden_file( $ctx, $path );
		}

		if ( ! empty( $traits ) ) {
			return $this->make(
				$ctx,
				$path,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				'A must-use plugin contains code patterns typical of malware',
				'Must-use plugins run on every request before normal plugins. The patterns listed are how malware hides what it does.',
				$traits,
				array( 'Compare the file with a known-good copy from whoever installed it. If you do not know who installed it, treat the site as compromised.' )
			);
		}

		return $this->make(
			$ctx,
			$path,
			Finding::SEVERITY_INFO,
			Finding::CONFIDENCE_LOW,
			'' === $header ? 'A must-use plugin with no name' : sprintf( 'Must-use plugin: %s', $header ),
			'Must-use plugins run on every request and cannot be switched off from the dashboard. Hosts and developers add them on purpose; malware does too. Confirm you know where this one came from.',
			array(),
			array( 'If you recognise it, acknowledge it so Earlyward only alerts when it changes.' )
		);
	}

	/**
	 * Drop-in.
	 *
	 * @param Context  $ctx     Context.
	 * @param string   $path    Path.
	 * @param string   $name    File name.
	 * @param string[] $plugins Installed plugin folders.
	 * @return Finding|null
	 */
	private function classify_dropin( Context $ctx, string $path, string $name, array $plugins ): ?Finding {
		$code   = (string) $ctx->fs()->read( $path, Code_Traits::READ_BYTES );
		$traits = Code_Traits::find( $code );

		if ( ! empty( $traits ) ) {
			return $this->make(
				$ctx,
				$path,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				sprintf( 'The %s drop-in contains code patterns typical of malware', $name ),
				'WordPress loads drop-ins automatically. Fake caching and database drop-ins are a known hiding place.',
				$traits,
				array( 'Reinstall the drop-in from the plugin that owns it (usually by turning its caching or database feature off and on), or remove it if nothing should own it.' )
			);
		}

		// An empty "<?php" stub is a harmless leftover (it is what many cleanups leave behind).
		if ( '' === trim( (string) preg_replace( '/^<\?php|\?>$/', '', trim( $code ) ) ) ) {
			return null;
		}

		$lower = strtolower( $code );
		foreach ( $plugins as $slug ) {
			if ( strlen( $slug ) >= 3 && false !== strpos( $lower, strtolower( $slug ) ) ) {
				return null; // Mentions an installed plugin: the normal way drop-ins identify their owner.
			}
		}

		return $this->make(
			$ctx,
			$path,
			Finding::SEVERITY_WARNING,
			Finding::CONFIDENCE_MEDIUM,
			sprintf( 'The %s drop-in does not belong to any installed plugin', $name ),
			'WordPress loads this file automatically. Normally it is written by a caching or database plugin and names that plugin. Malware has used fake drop-ins with these exact names.',
			array(),
			array( 'Find out which plugin created it. If it belongs to a plugin you removed, delete the drop-in; if you cannot tell, compare it with a fresh copy.' )
		);
	}

	/**
	 * Loose PHP in wp-content.
	 *
	 * @param Context $ctx  Context.
	 * @param string  $path Path.
	 * @param string  $name File name.
	 * @return Finding|null
	 */
	private function classify_loose( Context $ctx, string $path, string $name ): ?Finding {
		if ( 'index.php' === $name ) {
			return null;
		}
		if ( '.' === $name[0] ) {
			return $this->hidden_file( $ctx, $path );
		}
		$code   = (string) $ctx->fs()->read( $path, Code_Traits::READ_BYTES );
		$traits = Code_Traits::find( $code );
		$hex    = (bool) preg_match( '/^[a-f0-9]{8,}\.php$/', $name );
		if ( $hex || ! empty( $traits ) ) {
			return $this->make(
				$ctx,
				$path,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				'Suspicious PHP file directly in wp-content',
				'WordPress never puts randomly named PHP files here. This matches the loader of a known self-healing malware kit.',
				$traits,
				array( 'Check whether a .user.ini or .htaccess points at this file (see check C1), and look for a hidden twin starting with a dot.' )
			);
		}
		return $this->make(
			$ctx,
			$path,
			Finding::SEVERITY_INFO,
			Finding::CONFIDENCE_LOW,
			'A PHP file sits directly in wp-content',
			'Some plugins put configuration files here (for example cache settings). Confirm you know where it came from.',
			array(),
			array( 'If you recognise it, acknowledge it so Earlyward only alerts when it changes.' )
		);
	}

	/**
	 * Hidden PHP file.
	 *
	 * @param Context $ctx  Context.
	 * @param string  $path Path.
	 * @return Finding
	 */
	private function hidden_file( Context $ctx, string $path ): Finding {
		$code = (string) $ctx->fs()->read( $path, Code_Traits::READ_BYTES );
		return $this->make(
			$ctx,
			$path,
			Finding::SEVERITY_CRITICAL,
			Finding::CONFIDENCE_HIGH,
			'A hidden PHP file (name starting with a dot)',
			'Files starting with a dot are hidden from most file listings. WordPress core never ships one; malware uses them for its bootstrap and for background processes.',
			Code_Traits::find( $code ),
			array(
				'Check running processes for anything executing this path (your host can do this): a process can keep running after the file is deleted.',
				'Look for a non-hidden file with the same name next to it.',
			)
		);
	}

	/**
	 * Plugin Name header, if any.
	 *
	 * @param string $code Source.
	 * @return string
	 */
	private static function plugin_name( string $code ): string {
		return preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $code, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * Build a file finding.
	 *
	 * @param Context               $ctx        Context.
	 * @param string                $path       Path.
	 * @param string                $severity   Severity.
	 * @param string                $confidence Confidence.
	 * @param string                $title      Title.
	 * @param string                $why        Why.
	 * @param array<string, string> $traits     Traits found.
	 * @param string[]              $steps      Fix steps.
	 * @return Finding
	 */
	private function make( Context $ctx, string $path, string $severity, string $confidence, string $title, string $why, array $traits, array $steps ): Finding {
		return new Finding(
			self::ID,
			$severity,
			$confidence,
			$title,
			$why,
			array(
				new Evidence(
					'file',
					$ctx->relative( $path ),
					0,
					empty( $traits ) ? '' : implode( '; ', $traits ),
					array(
						'bytes'  => $ctx->fs()->size( $path ),
						'sha256' => $ctx->fs()->sha256( $path ),
						'traits' => implode( ',', array_keys( $traits ) ),
					)
				),
			),
			$steps
		);
	}
}
