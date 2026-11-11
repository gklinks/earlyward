<?php
/**
 * C19: external link domains that were not in published content before.
 *
 * Injected spam links are often hidden in ways no rule can list. What they all share is a new outside
 * domain in a page that did not link there. This remembers which outside domains published posts and pages
 * (and the data of Elementor, Beaver Builder, Bricks and Oxygen) link to. The first scan stores that list and
 * reports nothing; later scans report a domain that is not in it, with the posts that link to it.
 *
 * A new domain is a reason to look, not proof: editors add links all the time. "Set the baseline for link
 * domains" (or "wp earlyward baseline --check=C19 --reset") accepts the current list.
 *
 * Own host: the same rule as C9 (home and siteurl options, WP_HOME, WP_SITEURL; "www." and the bare host are the
 * same). When the own host is unknown nothing is measured, because every link would look external.
 * Hosts are lower case without "www.". Only links in anchors (href) are read.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks\Integrity;

use Earlyward\Checks\Check;
use Earlyward\Checks\Observes;
use Earlyward\Env\Context;
use Earlyward\Finding\Evidence;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * New external link domains check.
 */
final class New_Link_Domains_Check implements Check, Observes {

	const ID = 'C19';

	/**
	 * Posts read per source (the same cap as C9).
	 */
	const MAX_POSTS = 1000;

	/**
	 * Domains kept in the baseline. A site with more is not given a baseline (the list would be cut).
	 */
	const MAX_DOMAINS = 2000;

	/**
	 * Domains shown as evidence, and posts named per domain.
	 */
	const MAX_SHOWN = 20;

	const MAX_POST_IDS = 10;

	/**
	 * Last observation.
	 *
	 * @var array<string, mixed>
	 */
	private $observation = array();

	/**
	 * Whether the last observation may become the baseline.
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
	 * The list of domains seen in this run.
	 *
	 * @return array<string, mixed>
	 */
	public function observation(): array {
		return $this->observation;
	}

	/**
	 * The first list becomes the baseline; it is never replaced automatically.
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
		$db                = $ctx->db();
		$own               = Cloaked_Link_Check::own_hosts( $ctx );
		if ( null === $db || null === $own ) {
			return array();
		}

		$posts  = $db->posts_containing( array( 'href' ), array( 'post', 'page' ), array( 'publish' ), self::MAX_POSTS );
		$metas  = $db->meta_containing( Cloaked_Link_Check::BUILDER_KEYS, array( 'href' ), array( 'publish' ), self::MAX_POSTS );
		$capped = count( $posts ) >= self::MAX_POSTS || count( $metas ) >= self::MAX_POSTS;

		$by_domain = array(); // Host => post ID => true.
		foreach ( $posts as $post ) {
			foreach ( self::external_hosts( $post['content'], $own ) as $host ) {
				$by_domain[ $host ][ $post['id'] ] = true;
			}
		}
		foreach ( $metas as $row ) {
			foreach ( self::external_hosts( Cloaked_Link_Check::unescape_builder( $row['value'] ), $own ) as $host ) {
				$by_domain[ $host ][ $row['id'] ] = true;
			}
		}
		ksort( $by_domain );
		$domains = array_map( 'strval', array_keys( $by_domain ) );

		$this->observation = array( 'domains' => $domains );
		$this->healthy     = count( $domains ) <= self::MAX_DOMAINS;

		$baseline = $ctx->baseline( self::ID );
		if ( null === $baseline ) {
			return array(); // First run: the list is stored, nothing is reported.
		}
		$known = array_map( 'strval', isset( $baseline['domains'] ) && is_array( $baseline['domains'] ) ? $baseline['domains'] : array() );
		$new   = array_values( array_diff( $domains, $known ) );
		if ( empty( $new ) ) {
			return array();
		}

		$evidence = array();
		foreach ( array_slice( $new, 0, self::MAX_SHOWN ) as $host ) {
			$ids   = array_keys( $by_domain[ $host ] );
			$facts = array(
				'posts'       => implode( ', ', array_slice( $ids, 0, self::MAX_POST_IDS ) ),
				'posts_count' => count( $ids ),
			);
			if ( $capped ) {
				$facts['note'] = sprintf( 'checked the first %d matching items per source; more content may exist', self::MAX_POSTS );
			}
			$evidence[] = new Evidence( 'domain', 'domain:' . $host, 0, $host, $facts );
		}
		return array(
			new Finding(
				self::ID,
				Finding::SEVERITY_WARNING,
				Finding::CONFIDENCE_MEDIUM,
				sprintf(
					/* translators: %d: number of new domains. */
					_n( '%d new outside domain is linked from published content', '%d new outside domains are linked from published content', count( $new ), 'earlyward' ),
					count( $new )
				),
				__( 'Published posts or pages now link to a domain that no page linked to when Earlyward last recorded the list. Editors add links all the time, so this is a reason to look, not proof. Injected spam links usually show up exactly like this.', 'earlyward' ),
				$evidence,
				array(
					__( 'Open the posts named in the evidence and look for links you did not add, including ones that visitors cannot see (view the page source, or the page builder code view).', 'earlyward' ),
					__( 'If the links are yours, accept them: tick the box and use "Accept new link domains" on the Earlyward screen, or run "wp earlyward baseline --check=C19 --reset" and then "wp earlyward run". If they are not, find out how they got in and change the administrator passwords.', 'earlyward' ),
				)
			),
		);
	}

	/**
	 * Outside hosts linked from anchors in some markup: lower case, without "www.", each once, own hosts left out.
	 *
	 * @param string   $html Markup.
	 * @param string[] $own  Own hosts (lower case, without "www.").
	 * @return string[]
	 */
	public static function external_hosts( string $html, array $own ): array {
		$out = array();
		if ( ! preg_match_all( '/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/is', $html, $anchors, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $anchors as $anchor ) {
			$href = html_entity_decode( ( $anchor[1] ?? '' ) . ( $anchor[2] ?? '' ) . ( $anchor[3] ?? '' ) ); // Only one of the three groups matched.
			$host = rtrim( Cloaked_Link_Check::host_of( $href ), '.' );
			if ( '' !== $host && ! in_array( $host, $own, true ) ) {
				$out[ $host ] = $host;
			}
		}
		return array_values( $out );
	}
}
