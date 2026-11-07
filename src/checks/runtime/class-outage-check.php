<?php
/**
 * A1: the site answers, but it is broken.
 *
 * Real case: a fatal error in custom code made the site's code loader switch that code off.
 * The homepage still returned HTTP 200, so every uptime check stayed green, but the site was
 * unstyled and its custom routes returned 404. Nobody noticed for 12 days.
 *
 * So this compares the rendered homepage with a baseline recorded while the site was healthy:
 * which stylesheets load (by WordPress handle), whether a menu renders, and which REST
 * namespaces are registered. Assets whose plugin or theme was switched off are explained, not
 * reported.
 *
 * @package Wallwarden
 */

namespace Wallwarden\Checks\Runtime;

use Wallwarden\Checks\Check;
use Wallwarden\Checks\Observes;
use Wallwarden\Env\Context;
use Wallwarden\Finding\Evidence;
use Wallwarden\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Outage check.
 */
final class Outage_Check implements Check, Observes {

	const ID = 'A1';

	/**
	 * Last observation.
	 *
	 * @var array<string, mixed>
	 */
	private $observation = array();

	/**
	 * Whether the last observation looked healthy.
	 *
	 * @var bool
	 */
	private $healthy = false;

	/**
	 * Stable ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Last observation.
	 *
	 * @return array<string, mixed>
	 */
	public function observation(): array {
		return $this->observation;
	}

	/**
	 * Whether the observation may become a baseline.
	 *
	 * @return bool
	 */
	public function observation_is_healthy(): bool {
		return $this->healthy;
	}

	/**
	 * Run.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array {
		$this->observation = array();
		$this->healthy     = false;
		$lb                = $ctx->loopback();
		if ( null === $lb ) {
			return array();
		}

		$home = $lb->get( '/' );
		if ( 0 === $home['status'] ) {
			return array(
				$this->finding(
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_LOW,
					'The site could not load its own homepage',
					'Wallwarden checks the homepage from the server itself. Some hosts or firewalls block that, which is harmless; but it also happens when the site is down.',
					array( new Evidence( 'url', 'home', 0, $home['error'], array( 'ms' => $home['ms'] ) ) ),
					array(
						'Open the site in a browser. If it works, ask your host whether requests from the server to itself are blocked (WordPress Site Health shows the same "loopback" problem).',
						'If it does not work, check the error log (see check A3).',
					)
				),
			);
		}
		if ( $home['status'] >= 500 ) {
			return array(
				$this->finding(
					Finding::SEVERITY_CRITICAL,
					Finding::CONFIDENCE_HIGH,
					sprintf( 'The homepage returns an error (HTTP %d)', $home['status'] ),
					'Visitors see an error page.',
					array( new Evidence( 'url', 'home', 0, 'HTTP ' . $home['status'], array( 'ms' => $home['ms'] ) ) ),
					array( 'Check the error log (check A3) and any recent plugin, theme or code change.' )
				),
			);
		}
		if ( $home['status'] >= 400 ) {
			return array(
				$this->finding(
					Finding::SEVERITY_WARNING,
					Finding::CONFIDENCE_MEDIUM,
					sprintf( 'The homepage refused the server\'s own request (HTTP %d)', $home['status'] ),
					'A firewall or security rule may be blocking requests from the server itself, or the homepage really is unavailable.',
					array( new Evidence( 'url', 'home', 0, 'HTTP ' . $home['status'] ) ),
					array( 'Open the site in a private browser window. If it works, the block only affects the server\'s own requests; ask your host to allow them.' )
				),
			);
		}

		$signals = self::signals( $home['body'] );
		if ( $signals['bytes'] < 512 || ! $signals['html'] ) {
			return array(
				$this->finding(
					Finding::SEVERITY_CRITICAL,
					Finding::CONFIDENCE_MEDIUM,
					'The homepage answers "OK" but is nearly empty',
					'An empty or non-HTML page with a success code usually means a fatal error, a firewall challenge page, or a broken cache. Uptime monitors that only check the status code will not notice.',
					array( new Evidence( 'url', 'home', 0, substr( $home['body'], 0, 200 ), array( 'bytes' => $signals['bytes'] ) ) ),
					array( 'Open the homepage in a private window and look at what visitors get. Check the error log (A3).' )
				),
			);
		}

		$namespaces        = self::namespaces( $lb->get( '/wp-json/' ) );
		$this->observation = array(
			'styles'     => $signals['styles'],
			'nav'        => $signals['nav'],
			'namespaces' => $namespaces,
			'bytes'      => $signals['bytes'],
			'plugins'    => self::active_plugins( $ctx ),
		);
		$this->healthy     = true;

		$baseline = $ctx->baseline( self::ID );
		if ( null === $baseline ) {
			return array();
		}
		return $this->compare( $ctx, $baseline );
	}

	/**
	 * Compare with the healthy baseline.
	 *
	 * @param Context              $ctx      Context.
	 * @param array<string, mixed> $baseline Baseline.
	 * @return Finding[]
	 */
	private function compare( Context $ctx, array $baseline ): array {
		$explain  = $this->explainer( $ctx );
		$evidence = array();
		$severe   = false;

		$before_styles = isset( $baseline['styles'] ) && is_array( $baseline['styles'] ) ? $baseline['styles'] : array();
		$missing       = array();
		foreach ( array_diff_key( $before_styles, $this->observation['styles'] ) as $handle => $href ) {
			if ( ! $explain( (string) $href ) ) {
				$missing[ $handle ] = (string) $href;
			}
		}
		foreach ( $missing as $handle => $href ) {
			$evidence[] = new Evidence( 'asset', 'style:' . $handle, 0, $href, array( 'was' => 'loaded on the homepage when healthy' ) );
			if ( false !== strpos( $href, '/themes/' ) || false !== strpos( $href, '/uploads/' ) ) {
				$severe = true; // The theme's own styles, or custom code's assets, are gone.
			}
		}
		if ( count( $before_styles ) > 0 && count( $missing ) / count( $before_styles ) >= 0.3 ) {
			$severe = true;
		}

		$before_ns = isset( $baseline['namespaces'] ) && is_array( $baseline['namespaces'] ) ? $baseline['namespaces'] : array();
		$switched  = array_diff(
			isset( $baseline['plugins'] ) && is_array( $baseline['plugins'] ) ? $baseline['plugins'] : array(),
			(array) $this->observation['plugins']
		);
		if ( null !== $this->observation['namespaces'] ) {
			foreach ( array_diff( $before_ns, $this->observation['namespaces'] ) as $ns ) {
				if ( in_array( strtok( (string) $ns, '/' ), $switched, true ) ) {
					continue; // Its plugin was switched off on purpose.
				}
				$evidence[] = new Evidence( 'rest', 'rest:' . $ns, 0, '/wp-json/' . $ns, array( 'was' => 'registered when healthy' ) );
				$severe     = true;
			}
		}

		if ( ! empty( $baseline['nav'] ) && ! $this->observation['nav'] ) {
			$evidence[] = new Evidence( 'html', 'home:navigation', 0, 'no menu markup found', array( 'was' => 'present when healthy' ) );
		}

		if ( empty( $evidence ) ) {
			return array();
		}
		return array(
			$this->finding(
				$severe ? Finding::SEVERITY_CRITICAL : Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				'The homepage loads, but parts that worked before are missing',
				'The page still returns "OK", so ordinary uptime checks stay green. Missing styles, menus or API routes usually mean some code stopped loading after an error, which is how a real site stayed broken for 12 days unnoticed.',
				$evidence,
				array(
					'Open the homepage in a private window and compare it with how it should look.',
					'Check the error log (A3) and crash markers (A2) for the code that owns the missing parts.',
					'If you changed the site on purpose, record the new healthy state: wp wallwarden baseline --reset',
				)
			),
		);
	}

	/**
	 * Returns a function that tells whether a missing asset is explained by a plugin or theme
	 * having been switched off on purpose.
	 *
	 * @param Context $ctx Context.
	 * @return callable(string): bool
	 */
	private function explainer( Context $ctx ): callable {
		$db      = $ctx->db();
		$active  = array();
		$themes  = array();
		$plugins = null === $db ? null : $db->option( 'active_plugins' );
		if ( is_array( $plugins ) ) {
			foreach ( $plugins as $file ) {
				$active[] = strtok( (string) $file, '/' );
			}
		}
		if ( null !== $db ) {
			$themes = array_filter( array( (string) $db->option( 'stylesheet' ), (string) $db->option( 'template' ) ) );
		}
		return static function ( string $href ) use ( $plugins, $active, $themes ): bool {
			if ( is_array( $plugins ) && preg_match( '#/plugins/([^/]+)/#', $href, $m ) ) {
				return ! in_array( $m[1], $active, true );
			}
			if ( ! empty( $themes ) && preg_match( '#/themes/([^/]+)/#', $href, $m ) ) {
				return ! in_array( $m[1], $themes, true );
			}
			return false;
		};
	}

	/**
	 * Active plugin folder names, or an empty list when unknown.
	 *
	 * @param Context $ctx Context.
	 * @return string[]
	 */
	private static function active_plugins( Context $ctx ): array {
		$db      = $ctx->db();
		$plugins = null === $db ? null : $db->option( 'active_plugins' );
		$out     = array();
		foreach ( is_array( $plugins ) ? $plugins : array() as $file ) {
			$out[] = (string) strtok( (string) $file, '/' );
		}
		sort( $out );
		return $out;
	}

	/**
	 * Signals from homepage HTML.
	 *
	 * @param string $html HTML.
	 * @return array{styles: array<string, string>, nav: bool, bytes: int, html: bool}
	 */
	public static function signals( string $html ): array {
		$styles = array();
		// WordPress prints enqueued styles with id="<handle>-css" (files) or "<handle>-inline-css".
		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( preg_match( '/\brel\s*=\s*["\']?stylesheet/i', $tag ) && preg_match( '/\bid\s*=\s*["\']([^"\']+)-css["\']/i', $tag, $id ) ) {
					$href                           = preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $h ) ? $h[1] : '';
					$styles[ strtolower( $id[1] ) ] = (string) preg_replace( '/[?#].*$/', '', html_entity_decode( $href ) );
				}
			}
		}
		if ( preg_match_all( '/<style\b[^>]*\bid\s*=\s*["\']([^"\']+)-inline-css["\']/i', $html, $inline ) ) {
			foreach ( $inline[1] as $handle ) {
				$styles[ strtolower( $handle ) . '(inline)' ] = 'inline';
			}
		}
		ksort( $styles );
		return array(
			'styles' => $styles,
			'nav'    => (bool) preg_match( '/class\s*=\s*["\'][^"\']*\b(menu-item|wp-block-navigation)\b/i', $html ),
			'bytes'  => strlen( $html ),
			'html'   => (bool) preg_match( '/<html\b/i', $html ) || (bool) preg_match( '/<body\b/i', $html ),
		);
	}

	/**
	 * REST namespaces from the /wp-json/ index, or null when the index is not available
	 * (many sites restrict it; that is not a failure).
	 *
	 * @param array{status: int, body: string, error: string, ms: int} $resp Response.
	 * @return string[]|null
	 */
	public static function namespaces( array $resp ): ?array {
		if ( 200 !== $resp['status'] ) {
			return null;
		}
		$data = json_decode( $resp['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['namespaces'] ) || ! is_array( $data['namespaces'] ) ) {
			return null;
		}
		$ns = array_values( array_filter( array_map( 'strval', $data['namespaces'] ) ) );
		sort( $ns );
		return $ns;
	}

	/**
	 * Build a finding.
	 *
	 * @param string     $severity   Severity.
	 * @param string     $confidence Confidence.
	 * @param string     $title      Title.
	 * @param string     $why        Why.
	 * @param Evidence[] $evidence   Evidence.
	 * @param string[]   $steps      Steps.
	 * @return Finding
	 */
	private function finding( string $severity, string $confidence, string $title, string $why, array $evidence, array $steps ): Finding {
		return new Finding( self::ID, $severity, $confidence, $title, $why, $evidence, $steps );
	}
}
