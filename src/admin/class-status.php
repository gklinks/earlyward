<?php
/**
 * The always-visible part of Earlyward: a status badge in the admin bar (front end and admin,
 * for administrators only) and a widget on the dashboard home. Read-only: it shows the last
 * stored scan and never starts one. Every callback is fail-open.
 *
 * @package Earlyward
 */

namespace Earlyward\Admin;

use Earlyward\Store\State;

defined( 'ABSPATH' ) || exit;

/**
 * Admin bar badge and dashboard widget.
 */
final class Status {

	/**
	 * Register hooks. The callbacks do nothing for visitors and for users who cannot manage options.
	 */
	public static function boot(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_bar_node' ), 100 );
		add_action( 'wp_head', array( __CLASS__, 'print_css' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_css' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
	}

	/**
	 * Level and counts from the stored last scan.
	 *
	 * @param array<string, mixed> $last Stored last scan ("last" key of the state), or empty.
	 * @param int                  $now  Current time.
	 * @return array{level: string, counts: array{critical: int, warning: int, info: int}, at: int}
	 */
	public static function summary( array $last, int $now ): array {
		$at       = (int) ( $last['at'] ?? 0 );
		$findings = isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array();
		$counts   = Presenter::counts( $findings );
		if ( $at <= 0 ) {
			$level = 'none';
		} elseif ( $counts['critical'] > 0 ) {
			$level = 'critical';
		} elseif ( $counts['warning'] > 0 ) {
			$level = 'warning';
		} elseif ( $now - $at > Site_Health::STALE_AFTER ) {
			$level = 'stale';
		} else {
			$level = 'ok';
		}
		return array(
			'level'  => $level,
			'counts' => $counts,
			'at'     => $at,
		);
	}

	/**
	 * Short text for a summary.
	 *
	 * @param array{level: string, counts: array{critical: int, warning: int, info: int}, at: int} $summary From summary().
	 * @return string
	 */
	public static function label( array $summary ): string {
		$counts = $summary['counts'];
		switch ( $summary['level'] ) {
			case 'critical':
				/* translators: %d: number of critical findings. */
				return sprintf( _n( 'Earlyward: %d critical', 'Earlyward: %d critical', $counts['critical'], 'earlyward' ), $counts['critical'] );
			case 'warning':
				/* translators: %d: number of warnings. */
				return sprintf( _n( 'Earlyward: %d warning', 'Earlyward: %d warnings', $counts['warning'], 'earlyward' ), $counts['warning'] );
			case 'stale':
				return __( 'Earlyward: scan is old', 'earlyward' );
			case 'ok':
				return __( 'Earlyward: all clear', 'earlyward' );
			default:
				return __( 'Earlyward: no scan yet', 'earlyward' );
		}
	}

	/**
	 * Admin bar node arguments. Pure, so it is unit-tested.
	 *
	 * @param array{level: string, counts: array{critical: int, warning: int, info: int}, at: int} $summary From summary().
	 * @param string                                                                               $url     Where the badge leads.
	 * @return array<string, mixed>
	 */
	public static function bar_node( array $summary, string $url ): array {
		return array(
			'id'    => 'earlyward',
			'title' => '<span class="earlyward-dot"></span>' . htmlspecialchars( self::label( $summary ), ENT_QUOTES, 'UTF-8' ),
			'href'  => $url,
			'meta'  => array( 'class' => 'earlyward-' . $summary['level'] ),
		);
	}

	/**
	 * Whether the current user may see the status.
	 *
	 * @return bool
	 */
	private static function allowed(): bool {
		return current_user_can( Page::CAPABILITY );
	}

	/**
	 * Stored last scan, or empty.
	 *
	 * @return array<string, mixed>
	 */
	private static function last(): array {
		$state = State::get();
		return isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : array();
	}

	/**
	 * Add the badge to the admin bar.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function add_bar_node( $bar ): void {
		try {
			if ( ! self::allowed() ) {
				return;
			}
			$bar->add_node( self::bar_node( self::summary( self::last(), time() ), Page::url() ) );
		} catch ( \Throwable $e ) {
			return; // Fail open.
		}
	}

	/**
	 * Colours for the badge dot. Static text, printed only where the admin bar is showing to an administrator.
	 */
	public static function print_css(): void {
		try {
			if ( ! is_admin_bar_showing() || ! self::allowed() ) {
				return;
			}
			echo '<style id="earlyward-bar">#wpadminbar .earlyward-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:7px;background:#8c8f94;vertical-align:middle}#wpadminbar .earlyward-ok .earlyward-dot{background:#46b450}#wpadminbar .earlyward-warning .earlyward-dot,#wpadminbar .earlyward-stale .earlyward-dot{background:#dba617}#wpadminbar .earlyward-critical .earlyward-dot{background:#d63638}.earlyward-widget .earlyward-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:8px;background:#8c8f94;vertical-align:middle}.earlyward-widget .earlyward-ok .earlyward-dot{background:#46b450}.earlyward-widget .earlyward-warning .earlyward-dot,.earlyward-widget .earlyward-stale .earlyward-dot{background:#dba617}.earlyward-widget .earlyward-critical .earlyward-dot{background:#d63638}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static string.
		} catch ( \Throwable $e ) {
			return; // Fail open.
		}
	}

	/**
	 * Add the dashboard widget.
	 */
	public static function add_widget(): void {
		try {
			if ( self::allowed() ) {
				wp_add_dashboard_widget( 'earlyward_status', __( 'Earlyward', 'earlyward' ), array( __CLASS__, 'render_widget' ) );
			}
		} catch ( \Throwable $e ) {
			return; // Fail open.
		}
	}

	/**
	 * Widget body: status, counts and the most serious findings.
	 */
	public static function render_widget(): void {
		try {
			$last     = self::last();
			$summary  = self::summary( $last, time() );
			$findings = Presenter::sort( isset( $last['findings'] ) && is_array( $last['findings'] ) ? $last['findings'] : array() );
			$counts   = $summary['counts'];

			echo '<div class="earlyward-widget"><p class="earlyward-' . esc_attr( $summary['level'] ) . '" style="font-size:14px"><span class="earlyward-dot"></span><strong>' . esc_html( self::label( $summary ) ) . '</strong></p>';
			if ( $summary['at'] > 0 ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: critical count, 2: warning count, 3: info count, 4: how long ago the scan ran, for example "5 minutes". */
						__( '%1$d critical, %2$d warnings, %3$d notes. Last scan %4$s ago.', 'earlyward' ),
						$counts['critical'],
						$counts['warning'],
						$counts['info'],
						human_time_diff( $summary['at'], time() )
					)
				) . '</p>';
			}
			$shown = 0;
			echo '<ul style="margin:0 0 12px 18px;list-style:disc">';
			foreach ( $findings as $finding ) {
				if ( $shown >= 3 || 'info' === ( $finding['severity'] ?? '' ) ) {
					continue;
				}
				echo '<li>' . esc_html( (string) ( $finding['title'] ?? '' ) ) . '</li>';
				++$shown;
			}
			echo '</ul>';
			echo '<p><a class="button button-primary" href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Open Earlyward', 'earlyward' ) . '</a></p></div>';
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html__( 'The Earlyward status could not be shown.', 'earlyward' ) . '</p>';
		}
	}
}
