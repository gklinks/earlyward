<?php
/**
 * C9: links hidden from visitors inside published content.
 *
 * Real cases: a paid-backlink operation injected blocks such as
 * <div style="position:fixed; top:-4568px; left:-4039px"> full of links into a site's own pages, and
 * another hid them in <div style="overflow:hidden;height:1px"> inside the page builder's data
 * (post meta), leaving post_content clean. Visitors never saw them; search engines did.
 *
 * What is reported (style attribute of an element that contains at least one link):
 * - "Always" patterns, when the element holds an external link: a box of 2px or less with overflow:hidden,
 *   font-size:0, text-indent of -1000px or beyond, clip / clip-path that hides everything.
 * - Off-screen offset (position fixed/absolute and an offset of -1000px or beyond): any link.
 * - "Soft" patterns (display:none, visibility:hidden, opacity:0) are also used by tabs, accordions and
 *   menus, so they are reported only when the block holds 3 or more external links, or an external link
 *   whose anchor text appears nowhere else on the page.
 * Links count as external when they point to a host other than the site's own (home / siteurl options, WP_HOME / WP_SITEURL;
 * "www." and the bare host are the same). When the own host is unknown, the soft patterns stay quiet.
 *
 * Sources: post_content, and the data of the common page builders (post meta, published posts only).
 * This detects common hiding patterns, not all of them.
 *
 * Benign look-alikes this must ignore (each has a fixture):
 * - decorative negative offsets inside a <style> block (not a style attribute);
 * - small offsets used for layout;
 * - signup-form honeypots, which hide an input off-screen but contain no links;
 * - screen-reader text and skip links (class names, internal links);
 * - hidden tab / accordion panels and menus with ordinary internal links;
 * - page-builder widgets hidden through settings rather than inline style.
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
 * Cloaked link check.
 */
final class Cloaked_Link_Check implements Check {

	const ID = 'C9';

	const MIN_OFFSET = 1000;

	const SOFT_MIN_LINKS = 3;

	const MAX_DOMAINS = 5;

	const MAX_POSTS = 1000;

	/**
	 * The SQL pre-filter: a link, and one of these hints that something is being hidden.
	 */
	const HIDING_HINTS = array( 'overflow', 'display', 'visibility', 'opacity', 'font-size', 'text-indent', 'clip', 'position' );

	/**
	 * Post meta keys that hold page-builder markup: Elementor, Beaver Builder, Bricks, Oxygen.
	 */
	const BUILDER_KEYS = array( '_elementor_data', '_fl_builder_data', '_bricks_page_content_2', 'ct_builder_shortcodes' );

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
		$db = $ctx->db();
		if ( null === $db ) {
			return array();
		}
		$own   = self::own_hosts( $ctx );
		$found = array();

		// One query per source, each with a LIMIT; the SQL LIKEs (a link and one hiding hint) are only a pre-filter.
		$posts  = $db->posts_containing( array( 'href' ), array( 'post', 'page', 'wp_block' ), array( 'publish' ), self::MAX_POSTS, self::HIDING_HINTS );
		$metas  = $db->meta_containing( self::BUILDER_KEYS, array( 'href' ), array( 'publish' ), self::MAX_POSTS, self::HIDING_HINTS );
		$capped = count( $posts ) >= self::MAX_POSTS || count( $metas ) >= self::MAX_POSTS;
		foreach ( $posts as $post ) {
			self::collect( $found, $post['id'], $post['type'], $post['title'], 'content', $post['content'], $own );
		}
		foreach ( $metas as $row ) {
			self::collect( $found, $row['id'], $row['type'], $row['title'], 'builder data', self::unescape_builder( $row['value'] ), $own );
		}

		$findings = array();
		foreach ( $found as $id => $item ) {
			$domains = array_slice( array_keys( $item['domains'] ), 0, self::MAX_DOMAINS );
			$data    = array(
				'id'            => (int) $id,
				'type'          => $item['type'],
				'title'         => $item['title'],
				'where'         => implode( ' + ', array_keys( $item['where'] ) ),
				'hidden_blocks' => $item['blocks'],
				'links'         => $item['links'],
				'domains'       => implode( ', ', $domains ),
				'edit'          => 'post.php?post=' . (int) $id . '&action=edit',
			);
			if ( $capped ) {
				$data['note'] = sprintf( 'checked the first %d matching items', self::MAX_POSTS );
			}
			$evidence   = array(
				new Evidence( 'post', 'post:' . $id, 0, $item['tag'], $data ),
			);
			$findings[] = new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				sprintf(
					/* translators: %s: post title, or # followed by the post ID when it has no title */
					__( 'Hidden links were injected into "%s"', 'earlyward' ),
					'' === $item['title'] ? '#' . $id : $item['title']
				),
				__( 'An element hidden from visitors (moved off-screen, shrunk to a tiny box, or hidden with CSS) holds links. Visitors never see them, search engines do: this is how paid-backlink operations use hacked sites, and it can get the site penalised.', 'earlyward' ),
				$evidence,
				array(
					__( 'Use "Fix it here" under this finding to remove the hidden blocks you tick: Earlyward saves the old values first, checks the result, and "Put back" on the Quarantine and fix log tab restores them. Or open the page in the page builder code view (or the code editor) and remove the hidden block yourself, or ask your host to. Keep a copy first.', 'earlyward' ),
					__( 'Change the passwords of all administrator accounts, even after the links are removed: this is usually done with a stolen login, and removing the links does not close that way in.', 'earlyward' ),
					__( 'Check Google Search Console, under Security issues, and search your other pages too: this kind of injection usually hits several.', 'earlyward' ),
				)
			);
		}
		return $findings;
	}

	/**
	 * Add one source's hits to the per-post result.
	 *
	 * @param array<int, array<string, mixed>> $found  Result, by post ID.
	 * @param int                              $id     Post ID.
	 * @param string                           $type   Post type.
	 * @param string                           $title  Title.
	 * @param string                           $where  "content" or "builder data".
	 * @param string                           $html   Markup.
	 * @param string[]|null                    $own    Own hosts, null when unknown.
	 */
	private static function collect( array &$found, int $id, string $type, string $title, string $where, string $html, ?array $own ): void {
		$hits = self::find_cloaked_links( $html, $own );
		if ( empty( $hits ) ) {
			return;
		}
		if ( ! isset( $found[ $id ] ) ) {
			$found[ $id ] = array(
				'type'    => $type,
				'title'   => $title,
				'where'   => array(),
				'blocks'  => 0,
				'links'   => 0,
				'domains' => array(),
				'tag'     => $hits[0]['tag'],
			);
		}
		$found[ $id ]['where'][ $where ] = true;
		foreach ( $hits as $hit ) {
			++$found[ $id ]['blocks'];
			$found[ $id ]['links'] += $hit['links'];
			foreach ( $hit['domains'] as $domain ) {
				$found[ $id ]['domains'][ $domain ] = true;
			}
		}
	}

	/**
	 * Lower-case hosts that count as the site itself: the home and siteurl options, then the WP_HOME / WP_SITEURL
	 * constants. Null when none is known.
	 *
	 * @param Context $ctx Context.
	 * @return string[]|null
	 */
	public static function own_hosts( Context $ctx ): ?array {
		$hosts = array();
		$db    = $ctx->db();
		foreach ( array(
			'home'    => 'WP_HOME',
			'siteurl' => 'WP_SITEURL',
		) as $option => $name ) {
			$values = array( $ctx->constant( $name ) );
			if ( null !== $db ) {
				$values[] = $db->option( $option );
			}
			foreach ( $values as $value ) {
				$host = is_string( $value ) ? self::host_of( $value ) : '';
				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}
		}
		return empty( $hosts ) ? null : array_values( array_unique( $hosts ) );
	}

	/**
	 * Host of an absolute or protocol-relative URL, lower case, without "www."; empty for anything else.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		if ( ! preg_match( '#^\s*(?:https?:)?//([^/:?\#\s]+)#i', $url, $m ) ) {
			return '';
		}
		return (string) preg_replace( '/^www\./', '', strtolower( $m[1] ) );
	}

	/**
	 * Page-builder data as searchable markup: the JSON is decoded (so \/, \" and < become / " and <),
	 * and every string value is kept. Data that is not JSON is unescaped by hand.
	 *
	 * @param string $raw Meta value.
	 * @return string
	 */
	public static function unescape_builder( string $raw ): string {
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			$parts = array();
			array_walk_recursive(
				$data,
				static function ( $value ) use ( &$parts ): void {
					if ( is_string( $value ) ) {
						$parts[] = $value;
					}
				}
			);
			return implode( "\n", $parts );
		}
		$raw = str_replace( array( '\\/', '\\"' ), array( '/', '"' ), $raw );
		return (string) preg_replace_callback(
			'/\\\\u([0-9a-f]{4})/i',
			static function ( array $m ): string {
				$char = json_decode( '"\\u' . $m[1] . '"' );
				return is_string( $char ) ? $char : '';
			},
			$raw
		);
	}

	/**
	 * Elements whose style attribute hides them from visitors and which contain links (see the class comment).
	 *
	 * Each hit also carries what the "Remove hidden links" fix needs to find the same element again: "style" (how it
	 * is hidden), "kind" (offset, hard or soft), "id" (a fingerprint of tag, style and links, plus the number of the
	 * element among identical ones) and "hrefs".
	 *
	 * @param string        $html      Content.
	 * @param string[]|null $own_hosts Hosts that are the site itself (links to them are internal); null when the
	 *                                 site's own host is unknown, which makes the display:none / visibility / opacity rule quiet.
	 * @return array<int, array{tag: string, links: int, domains: string[], style: string, kind: string, id: string, hrefs: string[], span: array{0: int, 1: int}}>
	 */
	public static function find_cloaked_links( string $html, ?array $own_hosts = array() ): array {
		$out = array();
		if ( ! preg_match_all( '/<([a-z][a-z0-9]*)\b[^>]*\bstyle\s*=\s*(["\'])(.*?)\2[^>]*>/is', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		$covered = 0; // End of the last reported element: hidden elements nested inside it add nothing.
		$seen    = array(); // Identical hidden elements met so far, by fingerprint.
		foreach ( $tags as $tag ) {
			list( $kind, $style ) = self::classify( $tag[3][0] );
			if ( '' === $kind ) {
				continue;
			}
			$name  = strtolower( $tag[1][0] );
			$start = $tag[0][1] + strlen( $tag[0][0] );
			$block = self::element_inner( $html, $name, $start );
			$end   = $start + $block['length'];
			// A hidden <a> is its own link.
			$region = ( 'a' === $name ? $tag[0][0] : '' ) . $block['inner'] . ( 'a' === $name ? '</a>' : '' );

			$links    = array();
			$hrefs    = array();
			$external = array();
			if ( preg_match_all( '/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)(?:<\/a>|$)/is', $region, $anchors, PREG_SET_ORDER ) ) {
				foreach ( $anchors as $anchor ) {
					$href    = html_entity_decode( '' !== ( $anchor[1] ) ? $anchor[1] : ( '' !== ( $anchor[2] ) ? $anchor[2] : ( $anchor[3] ) ) );
					$host    = self::host_of( $href );
					$links[] = $host;
					$hrefs[] = $href;
					if ( '' !== $host && ! in_array( $host, (array) $own_hosts, true ) ) {
						$external[] = array(
							'host' => $host,
							'text' => trim( html_entity_decode( self::plain( $anchor[4] ) ) ),
						);
					}
				}
			}
			if ( empty( $links ) ) {
				continue;
			}
			$print          = self::fingerprint( $name, html_entity_decode( $tag[3][0] ), $hrefs );
			$nth            = $seen[ $print ] ?? 0;
			$seen[ $print ] = $nth + 1;
			if ( $tag[0][1] < $covered ) {
				continue;
			}
			if ( 'offset' === $kind ) {
				$report = true;
			} elseif ( 'hard' === $kind ) {
				$report = ! empty( $external );
			} else {
				$report = null !== $own_hosts && self::soft_block_is_suspicious( $external, $html, $tag[0][1], $end );
			}
			if ( ! $report ) {
				continue;
			}
			$covered = $end;
			$out[]   = array(
				'tag'     => $tag[0][0],
				'links'   => count( $links ),
				'domains' => array_values( array_unique( array_column( $external, 'host' ) ) ),
				'style'   => $style,
				'kind'    => $kind,
				'id'      => $print . '.' . $nth,
				'hrefs'   => $hrefs,
				'span'    => array( $tag[0][1], $end ),
			);
		}
		return $out;
	}

	/**
	 * A short fingerprint of a hidden element: tag name, decoded style attribute and the links inside it.
	 * The "Remove hidden links" fix computes the same value from a parsed document to find the element again.
	 *
	 * @param string   $name  Tag name.
	 * @param string   $style Style attribute, entities decoded.
	 * @param string[] $hrefs Link targets inside the element, entities decoded, in document order.
	 * @return string 12 hex characters.
	 */
	public static function fingerprint( string $name, string $style, array $hrefs ): string {
		return substr( hash( 'sha256', strtolower( $name ) . "\n" . trim( $style ) . "\n" . implode( "\n", $hrefs ) ), 0, 12 );
	}

	/**
	 * A display:none / visibility:hidden / opacity:0 block is only suspicious with 3 or more external links,
	 * or with an external link whose text is not shown anywhere else on the page (an image link has no text, so it counts).
	 *
	 * @param array<int, array{host: string, text: string}> $external External links in the block.
	 * @param string                                        $html     Whole content.
	 * @param int                                           $from     Block start offset.
	 * @param int                                           $to       Block end offset.
	 * @return bool
	 */
	private static function soft_block_is_suspicious( array $external, string $html, int $from, int $to ): bool {
		if ( count( $external ) >= self::SOFT_MIN_LINKS ) {
			return true;
		}
		if ( empty( $external ) ) {
			return false;
		}
		$visible = html_entity_decode( self::plain( substr( $html, 0, $from ) . ' ' . substr( $html, $to ) ) );
		foreach ( $external as $link ) {
			if ( '' === $link['text'] || false === stripos( $visible, $link['text'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Text of a markup fragment: tags replaced by spaces.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function plain( string $html ): string {
		return (string) preg_replace( '/<[^>]*>/', ' ', $html );
	}

	/**
	 * Markup between an opening tag and its matching closing tag (nested same-name tags are balanced).
	 *
	 * @param string $html  Content.
	 * @param string $name  Lower-case tag name.
	 * @param int    $start Offset just after the opening tag.
	 * @return array{inner: string, length: int} Length covers the inner markup and the closing tag.
	 */
	private static function element_inner( string $html, string $name, int $start ): array {
		$window = substr( $html, $start, 50000 );
		$depth  = 1;
		if ( preg_match_all( '/<(\/?)' . preg_quote( $name, '/' ) . '\b[^>]*>/i', $window, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $t ) {
				if ( '/' === $t[1][0] ) {
					--$depth;
				} elseif ( '/>' !== substr( $t[0][0], -2 ) ) {
					++$depth;
				}
				if ( 0 === $depth ) {
					return array(
						'inner'  => substr( $window, 0, $t[0][1] ),
						'length' => $t[0][1] + strlen( $t[0][0] ),
					);
				}
			}
		}
		$window = substr( $window, 0, 5000 );
		return array(
			'inner'  => $window,
			'length' => strlen( $window ),
		);
	}

	/**
	 * Classify a style attribute: "offset" (off-screen), "hard" (always hidden), "soft" (hidden, but common
	 * in tabs, accordions and menus) or "" (not hidden).
	 *
	 * @param string $style Style attribute value.
	 * @return string
	 */
	public static function hiding_kind( string $style ): string {
		return self::classify( $style )[0];
	}

	/**
	 * Kind (as hiding_kind) and a style key: offscreen, tinybox, textindent, clip, fontsize0, displaynone, visibility
	 * or opacity. Both are empty when the style does not hide the element.
	 *
	 * @param string $style Style attribute value.
	 * @return array{0: string, 1: string}
	 */
	public static function classify( string $style ): array {
		$props = array();
		foreach ( explode( ';', strtolower( html_entity_decode( $style ) ) ) as $decl ) {
			$pair = explode( ':', $decl, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$props[ trim( $pair[0] ) ] = trim( (string) preg_replace( '/\s*!important\s*$/', '', $pair[1] ) );
		}
		if ( empty( $props ) ) {
			return array( '', '' );
		}

		if ( isset( $props['position'] ) && in_array( $props['position'], array( 'fixed', 'absolute' ), true ) ) {
			foreach ( array( 'top', 'left', 'right', 'bottom', 'margin-left', 'margin-top' ) as $side ) {
				$px = isset( $props[ $side ] ) ? self::length_px( $props[ $side ] ) : null;
				if ( null !== $px && $px <= -self::MIN_OFFSET ) {
					return array( 'offset', 'offscreen' );
				}
			}
		}

		$indent = isset( $props['text-indent'] ) ? self::length_px( $props['text-indent'] ) : null;
		if ( null !== $indent && $indent <= -self::MIN_OFFSET ) {
			return array( 'hard', 'textindent' );
		}

		$clips = (string) ( $props['clip'] ?? '' );
		if ( preg_match( '/^rect\(\s*0(?:px)?[\s,]+0(?:px)?[\s,]+0(?:px)?[\s,]+0(?:px)?\s*\)$/', $clips ) ) {
			return array( 'hard', 'clip' );
		}
		if ( preg_match( '/^inset\(\s*(?:50|100)%/', (string) ( $props['clip-path'] ?? '' ) ) ) {
			return array( 'hard', 'clip' );
		}

		$size = isset( $props['font-size'] ) ? self::length_px( $props['font-size'], true ) : null;
		if ( null !== $size && 0.0 === $size ) {
			return array( 'hard', 'fontsize0' );
		}

		$clipped = 'hidden' === ( $props['overflow'] ?? '' ) || 'hidden' === ( $props['overflow-x'] ?? '' ) || 'hidden' === ( $props['overflow-y'] ?? '' );
		if ( $clipped ) {
			foreach ( array( 'height', 'max-height', 'width', 'max-width' ) as $dimension ) {
				$px = isset( $props[ $dimension ] ) ? self::length_px( $props[ $dimension ] ) : null;
				if ( null !== $px && $px >= 0 && $px <= 2 ) {
					return array( 'hard', 'tinybox' );
				}
			}
		}

		if ( 'none' === ( $props['display'] ?? '' ) ) {
			return array( 'soft', 'displaynone' );
		}
		if ( 'hidden' === ( $props['visibility'] ?? '' ) ) {
			return array( 'soft', 'visibility' );
		}
		if ( isset( $props['opacity'] ) && preg_match( '/^0*(?:\.0*)?$/', $props['opacity'] ) && '' !== $props['opacity'] ) {
			return array( 'soft', 'opacity' );
		}
		return array( '', '' );
	}

	/**
	 * A CSS length in pixels (em/rem count as 16px), or null when it is not a plain length.
	 *
	 * @param string $value        Value, for example "-4568px", "0" or "0.1em".
	 * @param bool   $allow_percent Treat "0%" as 0 (for font-size).
	 * @return float|null
	 */
	private static function length_px( string $value, bool $allow_percent = false ): ?float {
		$units = $allow_percent ? 'px|em|rem|pt|%' : 'px|em|rem';
		if ( ! preg_match( '/^(-?)\s*(\d*\.?\d+)\s*(' . $units . ')?$/', trim( $value ), $m ) ) {
			return null;
		}
		$number = (float) $m[2];
		if ( count( $m ) < 4 ) {
			if ( 0.0 !== $number ) {
				return null; // Unit-less non-zero lengths are invalid CSS.
			}
			return 0.0;
		}
		if ( 'em' === $m[3] || 'rem' === $m[3] ) {
			$number *= 16;
		} elseif ( 'pt' === $m[3] ) {
			$number *= 1.33;
		} elseif ( '%' === $m[3] ) {
			$number = 0.0 === $number ? 0.0 : 100.0; // Only an exact zero matters.
		}
		return ( '-' === $m[1] ? -1 : 1 ) * $number;
	}
}
