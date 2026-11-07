<?php
/**
 * C9: links hidden off-screen inside published content.
 *
 * Real case: a paid-backlink operation injected blocks such as
 * <div style="position:fixed; top:-4568px; left:-4039px"> full of links into the site's own
 * legitimate pages. Visitors never saw them; search engines did.
 *
 * Benign look-alikes this must ignore (each has a fixture):
 * - decorative negative offsets inside a <style> block (not a style attribute);
 * - small offsets used for layout;
 * - signup-form honeypots, which hide an input off-screen but contain no links.
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
		$posts    = $db->posts_containing( array( 'position' ), array( 'post', 'page', 'wp_block' ), array( 'publish' ), 2000 );
		$findings = array();
		foreach ( $posts as $post ) {
			$hits = self::find_cloaked_links( $post['content'] );
			if ( empty( $hits ) ) {
				continue;
			}
			$evidence = array();
			foreach ( $hits as $hit ) {
				$evidence[] = new Evidence(
					'post',
					'post:' . $post['id'],
					0,
					$hit['tag'],
					array(
						'type'  => $post['type'],
						'title' => $post['title'],
						'links' => $hit['links'],
					)
				);
			}
			$findings[] = new Finding(
				self::ID,
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				sprintf( 'Hidden links were injected into "%s"', '' === $post['title'] ? '#' . $post['id'] : $post['title'] ),
				'An element positioned thousands of pixels off-screen holds links. Visitors never see them, search engines do: this is how paid-backlink operations use hacked sites, and it can get the site penalised.',
				$evidence,
				array(
					'Edit the page, switch to the code editor and remove the hidden block. Keep a copy first.',
					'Purge any page cache, then load the page with ?nocache=1 added to the address to confirm the links are gone from the real content.',
					'Search your other pages too: this kind of injection usually hits several.',
				)
			);
		}
		return $findings;
	}

	/**
	 * Elements whose style attribute moves them far off-screen and which contain links.
	 *
	 * @param string $html Content.
	 * @return array<int, array{tag: string, links: int}>
	 */
	public static function find_cloaked_links( string $html ): array {
		$out = array();
		if ( ! preg_match_all( '/<([a-z][a-z0-9]*)\b[^>]*\bstyle\s*=\s*(["\'])(.*?)\2[^>]*>/is', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $tags as $tag ) {
			$style = $tag[3][0];
			if ( ! preg_match( '/position\s*:\s*(fixed|absolute)/i', $style ) ) {
				continue;
			}
			if ( ! preg_match_all( '/(?:top|left|right|bottom|margin-left|margin-top|text-indent)\s*:\s*-\s*(\d+)(?:\.\d+)?px/i', $style, $offsets ) ) {
				continue;
			}
			if ( max( array_map( 'intval', $offsets[1] ) ) < self::MIN_OFFSET ) {
				continue;
			}
			// Look inside the element (up to its closing tag, bounded) for real links.
			$name  = strtolower( $tag[1][0] );
			$start = $tag[0][1] + strlen( $tag[0][0] );
			$end   = stripos( $html, '</' . $name . '>', $start );
			$inner = substr( $html, $start, false === $end ? 5000 : min( 20000, $end - $start ) );
			$links = preg_match_all( '/<a\b[^>]*\bhref\s*=/i', $inner );
			if ( $links > 0 ) {
				$out[] = array(
					'tag'   => $tag[0][0],
					'links' => (int) $links,
				);
			}
		}
		return $out;
	}
}
