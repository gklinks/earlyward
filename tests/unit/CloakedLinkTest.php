<?php
/**
 * C9 hiding patterns, builder-data unescaping and the Database::meta_containing contract.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Integrity\Cloaked_Link_Check;
use Earlyward\Env\Context;

/**
 * Cloaked link tests.
 */
final class CloakedLinkTest extends TestCase {

	/**
	 * Style attributes that must be reported, each around one external link.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function hiding_styles(): array {
		return array(
			'off-screen'      => array( 'position:fixed; top:-4568px' ),
			'tiny height'     => array( 'overflow:hidden;height:1px' ),
			'tiny max-height' => array( 'max-height: 0; overflow: hidden !important' ),
			'tiny width'      => array( 'width:2px;overflow:hidden' ),
			'em height'       => array( 'height:0.1em;overflow:hidden' ),
			'font-size zero'  => array( 'font-size:0' ),
			'text-indent'     => array( 'text-indent:-9999px' ),
			'clip rect'       => array( 'clip:rect(0,0,0,0)' ),
			'clip-path'       => array( 'clip-path:inset(50%)' ),
		);
	}

	/**
	 * Always-hidden patterns are reported.
	 *
	 * @dataProvider hiding_styles
	 * @param string $style Style.
	 */
	public function test_hiding_style_is_reported( string $style ): void {
		$hits = Cloaked_Link_Check::find_cloaked_links( '<div style="' . $style . '"><div><a href="https://spam.fixture/x">x</a></div></div>' );
		$this->assertCount( 1, $hits );
		$this->assertSame( 1, $hits[0]['links'] );
		$this->assertSame( array( 'spam.fixture' ), $hits[0]['domains'] );
	}

	/**
	 * Visible or merely small styles are not.
	 */
	public function test_ordinary_styles_are_quiet(): void {
		foreach ( array( 'height:1px', 'overflow:hidden;height:40px', 'font-size:12px', 'opacity:1', 'text-indent:-20px' ) as $style ) {
			$this->assertSame( array(), Cloaked_Link_Check::find_cloaked_links( '<div style="' . $style . '"><a href="https://spam.fixture/x">x</a></div>' ), $style );
		}
	}

	/**
	 * Links to the site itself do not count for the tiny-box rule.
	 */
	public function test_own_host_links_are_internal(): void {
		$html = '<div style="overflow:hidden;height:1px"><a href="https://www.site.fixture/page">p</a></div>';
		$this->assertSame( array(), Cloaked_Link_Check::find_cloaked_links( $html, array( 'site.fixture' ) ) );
		$this->assertCount( 1, Cloaked_Link_Check::find_cloaked_links( $html ) );
	}

	/**
	 * Hidden blocks without a link are not reported.
	 */
	public function test_no_link_no_hit(): void {
		$this->assertSame( array(), Cloaked_Link_Check::find_cloaked_links( '<div style="font-size:0"><input name="hp"></div>' ) );
	}

	/**
	 * The soft rule: 3 external links, or one whose text is not shown elsewhere.
	 */
	public function test_soft_rule(): void {
		$three = '<div style="display:none"><a href="https://a.fixture/">a</a><a href="https://b.fixture/">b</a><a href="https://c.fixture/">c</a></div>';
		$this->assertCount( 1, Cloaked_Link_Check::find_cloaked_links( $three ) );
		$shown = '<a href="https://a.fixture/">Partner</a><div style="opacity:0"><a href="https://a.fixture/">Partner</a></div>';
		$this->assertSame( array(), Cloaked_Link_Check::find_cloaked_links( $shown ) );
		$hidden = '<p>Text</p><div style="opacity:0"><a href="https://a.fixture/">Secret</a></div>';
		$this->assertCount( 1, Cloaked_Link_Check::find_cloaked_links( $hidden ) );
		$internal = '<div style="display:none"><a href="/a">a</a><a href="/b">b</a><a href="/c">c</a><a href="/d">d</a></div>';
		$this->assertSame( array(), Cloaked_Link_Check::find_cloaked_links( $internal ) );
	}

	/**
	 * Nested blocks: links deep inside count, and nested hidden elements are not double counted.
	 */
	public function test_nesting(): void {
		$html = '<div style="height:1px;overflow:hidden"><div><div style="font-size:0"><a href="https://spam.fixture/1">1</a></div></div><a href="https://spam.fixture/2">2</a></div>';
		$hits = Cloaked_Link_Check::find_cloaked_links( $html );
		$this->assertCount( 1, $hits );
		$this->assertSame( 2, $hits[0]['links'] );
	}

	/**
	 * Escaped builder JSON is turned back into markup.
	 */
	public function test_unescape_builder(): void {
		$json = '[{"settings":{"html":"\u003Cdiv style=\"height:1px;overflow:hidden\"\u003E\u003Ca href=\"https:\/\/spam.fixture\/1\"\u003Ex\u003C\/a\u003E\u003C\/div\u003E"}}]';
		$this->assertCount( 1, Cloaked_Link_Check::find_cloaked_links( Cloaked_Link_Check::unescape_builder( $json ) ) );
		$broken = 'junk \u003Ca href=\"https:\/\/spam.fixture\/1\"\u003E';
		$this->assertStringContainsString( '<a href="https://spam.fixture/1">', Cloaked_Link_Check::unescape_builder( $broken ) );
	}

	/**
	 * Fixture database: only published posts, only the listed keys, all needles.
	 */
	public function test_meta_containing(): void {
		$db   = new Fixture_Database(
			array(
				'posts'    => array(
					array(
						'id'      => 1,
						'type'    => 'page',
						'status'  => 'publish',
						'title'   => 'A',
						'content' => '',
					),
					array(
						'id'      => 2,
						'type'    => 'page',
						'status'  => 'draft',
						'title'   => 'B',
						'content' => '',
					),
				),
				'postmeta' => array(
					array(
						'post_id' => 1,
						'key'     => '_elementor_data',
						'value'   => 'style href',
					),
					array(
						'post_id' => 1,
						'key'     => 'other',
						'value'   => 'style href',
					),
					array(
						'post_id' => 2,
						'key'     => '_elementor_data',
						'value'   => 'style href',
					),
					array(
						'post_id' => 1,
						'key'     => '_fl_builder_data',
						'value'   => 'style only',
					),
				),
			)
		);
		$rows = $db->meta_containing( array( '_elementor_data', '_fl_builder_data' ), array( 'style', 'href' ), array( 'publish' ), 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]['id'] );
	}

	/**
	 * Context over a fixture database.
	 *
	 * @param Fixture_Database $db Database.
	 * @return Context
	 */
	private function context( Fixture_Database $db ): Context {
		$root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-c9-unused';
		return new Context( $root, $root . '/wp-content', $root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), array( 'db' => $db ) );
	}

	/**
	 * The check asks for a narrow pre-filter (a link plus a hiding hint), with a limit of 1000 per source,
	 * and says so when the limit is reached.
	 */
	public function test_prefilter_and_cap_note(): void {
		$posts = array();
		for ( $i = 1; $i <= 1000; $i++ ) {
			$posts[] = array(
				'id'      => $i,
				'type'    => 'page',
				'status'  => 'publish',
				'title'   => 'P' . $i,
				'content' => 1 === $i ? '<div style="height:1px;overflow:hidden"><a href="https://spam.fixture/x">x</a></div>' : '<p style="position:relative"><a href="https://a.fixture/">ok</a></p>',
			);
		}
		$posts[]  = array(
			'id'      => 2000,
			'type'    => 'page',
			'status'  => 'publish',
			'title'   => 'Plain',
			'content' => '<p>No link here, style only.</p>',
		);
		$db       = new Fixture_Database( array( 'posts' => $posts ) );
		$findings = ( new Cloaked_Link_Check() )->run( $this->context( $db ) );

		$this->assertCount( 2, $db->calls );
		foreach ( $db->calls as $call ) {
			$this->assertSame( Cloaked_Link_Check::HIDING_HINTS, $call['any_of'] );
			$this->assertSame( 1000, $call['limit'] );
		}
		$this->assertCount( 1, $findings );
		$facts = $findings[0]->evidence()[0]->to_array();
		$this->assertStringContainsString( 'checked the first 1000 matching items', json_encode( $facts ) );
	}

	/**
	 * Under the limit there is no cap note; posts without a hiding hint are not even returned.
	 */
	public function test_no_cap_note_below_limit(): void {
		$db       = new Fixture_Database(
			array(
				'posts' => array(
					array(
						'id'      => 1,
						'type'    => 'page',
						'status'  => 'publish',
						'title'   => 'Bad',
						'content' => '<div style="font-size:0"><a href="https://spam.fixture/x">x</a></div>',
					),
					array(
						'id'      => 2,
						'type'    => 'page',
						'status'  => 'publish',
						'title'   => 'No hint',
						'content' => '<a href="https://a.fixture/" style="color:red">x</a>',
					),
				),
			)
		);
		$findings = ( new Cloaked_Link_Check() )->run( $this->context( $db ) );
		$this->assertCount( 1, $findings );
		$this->assertStringNotContainsString( 'checked the first', json_encode( $findings[0]->evidence()[0]->to_array() ) );
		$this->assertCount( 1, $db->posts_containing( array( 'href' ), array( 'page' ), array( 'publish' ), 10, Cloaked_Link_Check::HIDING_HINTS ) );
	}

	/**
	 * Unknown own host: the soft rule is quiet, the hard rule still reports. Known through the home option: internal links stay quiet.
	 */
	public function test_unknown_own_host(): void {
		$soft = '<div style="display:none"><a href="https://a.fixture/">a</a><a href="https://b.fixture/">b</a><a href="https://c.fixture/">c</a></div>';
		$this->assertSame( array(), Cloaked_Link_Check::find_cloaked_links( $soft, null ) );
		$hard = '<div style="font-size:0"><a href="https://spam.fixture/x">x</a></div>';
		$this->assertCount( 1, Cloaked_Link_Check::find_cloaked_links( $hard, null ) );

		$page = array(
			'id'      => 1,
			'type'    => 'page',
			'status'  => 'publish',
			'title'   => 'T',
			'content' => '<div style="display:none"><a href="https://www.site.fixture/1">1</a><a href="https://site.fixture/2">2</a><a href="https://site.fixture/3">3</a></div>',
		);
		$this->assertSame( array(), ( new Cloaked_Link_Check() )->run( $this->context( new Fixture_Database( array( 'posts' => array( $page ) ) ) ) ) );
		$known = new Fixture_Database(
			array(
				'posts'   => array( $page ),
				'options' => array( 'home' => 'https://site.fixture' ),
			)
		);
		$this->assertSame( array(), ( new Cloaked_Link_Check() )->run( $this->context( $known ) ) );
	}
}
