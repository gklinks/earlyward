<?php
/**
 * C19: host extraction and normalisation, first run, new domains, cap note.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Integrity\New_Link_Domains_Check;
use Earlyward\Env\Context;

/**
 * New link domains tests.
 */
final class NewLinkDomainsTest extends TestCase {

	/**
	 * Hosts are lower case, without www., once each; own hosts, relative, mail and junk links are left out.
	 */
	public function test_external_hosts_are_normalised(): void {
		$html = '<a href="https://WWW.Example.ORG/a">1</a> <a href=\'http://example.org/b\'>2</a> <a href=//cdn.Other.example/x>3</a>'
			. ' <a class="x" href="https://site.fixture/own">4</a> <a href="https://www.site.fixture/own">5</a> <a href="/rel">6</a>'
			. ' <a href="mailto:a@b.example">7</a> <a href="https://a.example:8080/p?x=1#y">8</a> <a href="https://b.example/?u=1&amp;v=2">9</a> <a name="x">10</a>';
		$this->assertSame(
			array( 'example.org', 'cdn.other.example', 'a.example', 'b.example' ),
			New_Link_Domains_Check::external_hosts( $html, array( 'site.fixture' ) )
		);
	}

	/**
	 * No links, no hosts.
	 */
	public function test_no_links_no_hosts(): void {
		$this->assertSame( array(), New_Link_Domains_Check::external_hosts( '<p>plain</p>', array() ) );
	}

	/**
	 * The first run keeps the list as an observation that may become the baseline, and reports nothing.
	 */
	public function test_first_run_reports_nothing_and_offers_a_baseline(): void {
		$check = new New_Link_Domains_Check();
		$this->assertSame( array(), $check->run( $this->context( array( self::post( 1, 'https://a.example/' ) ), null ) ) );
		$this->assertSame( array( 'domains' => array( 'a.example' ) ), $check->observation() );
		$this->assertTrue( $check->observation_is_healthy() );
	}

	/**
	 * Without a known own host nothing is measured.
	 */
	public function test_unknown_own_host_measures_nothing(): void {
		$check   = new New_Link_Domains_Check();
		$db      = new Fixture_Database( array( 'posts' => array( self::post( 1, 'https://a.example/' ) ) ) );
		$root    = str_replace( '\\', '/', sys_get_temp_dir() );
		$context = new Context( $root, $root . '/wp-content', $root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), array( 'db' => $db ) );
		$this->assertSame( array(), $check->run( $context ) );
		$this->assertSame( array(), $check->observation() );
	}

	/**
	 * A domain missing from the baseline is reported with its post IDs and count.
	 */
	public function test_new_domain_is_reported_with_posts(): void {
		$check    = new New_Link_Domains_Check();
		$findings = $check->run(
			$this->context(
				array( self::post( 1, 'https://a.example/' ), self::post( 2, 'https://new.example/' ), self::post( 3, 'https://www.new.example/z' ) ),
				array( 'domains' => array( 'a.example' ) )
			)
		);
		$this->assertCount( 1, $findings );
		$a = $findings[0]->to_array();
		$this->assertSame( 'warning', $a['severity'] );
		$this->assertCount( 1, $a['evidence'] );
		$this->assertSame( 'domain:new.example', $a['evidence'][0]['location'] );
		$this->assertSame( '2, 3', $a['evidence'][0]['facts']['posts'] );
		$this->assertSame( 2, $a['evidence'][0]['facts']['posts_count'] );
	}

	/**
	 * A removed domain is not a finding.
	 */
	public function test_removed_domain_is_quiet(): void {
		$check = new New_Link_Domains_Check();
		$this->assertSame( array(), $check->run( $this->context( array( self::post( 1, 'https://a.example/' ) ), array( 'domains' => array( 'a.example', 'gone.example' ) ) ) ) );
	}

	/**
	 * Hitting the post cap is said in the evidence.
	 */
	public function test_cap_is_said_in_the_evidence(): void {
		$posts = array();
		for ( $i = 1; $i <= New_Link_Domains_Check::MAX_POSTS; $i++ ) {
			$posts[] = self::post( $i, 'https://new.example/' );
		}
		$check    = new New_Link_Domains_Check();
		$findings = $check->run( $this->context( $posts, array( 'domains' => array() ) ) );
		$a        = $findings[0]->to_array();
		$this->assertArrayHasKey( 'note', $a['evidence'][0]['facts'] );
		$this->assertSame( New_Link_Domains_Check::MAX_POST_IDS, count( explode( ', ', $a['evidence'][0]['facts']['posts'] ) ) );
	}

	/**
	 * A published post with one link.
	 *
	 * @param int    $id   Post ID.
	 * @param string $href Link.
	 * @return array<string, mixed>
	 */
	private static function post( int $id, string $href ): array {
		return array(
			'id'      => $id,
			'type'    => 'post',
			'status'  => 'publish',
			'title'   => 'P' . $id,
			'slug'    => 'p' . $id,
			'content' => '<a href="' . $href . '">x</a>',
		);
	}

	/**
	 * Context with a fixture database and an optional C19 baseline.
	 *
	 * @param array<int, array<string, mixed>> $posts    Posts.
	 * @param array<string, mixed>|null        $baseline Baseline.
	 * @return Context
	 */
	private function context( array $posts, ?array $baseline ): Context {
		$db    = new Fixture_Database(
			array(
				'options' => array(
					'home'    => 'https://site.fixture',
					'siteurl' => 'https://site.fixture',
				),
				'posts'   => $posts,
			)
		);
		$root  = str_replace( '\\', '/', sys_get_temp_dir() );
		$extra = array( 'db' => $db );
		if ( null !== $baseline ) {
			$extra['baselines'] = array( 'C19' => $baseline );
		}
		return new Context( $root, $root . '/wp-content', $root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), $extra );
	}
}
