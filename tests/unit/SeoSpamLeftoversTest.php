<?php
/**
 * C18 word matching, the CJK rule, caps; and the C9 "since when, by whom" history.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Checks\Integrity\Cloaked_Link_Check;
use Earlyward\Checks\Integrity\Seo_Spam_Leftovers_Check;
use Earlyward\Env\Context;

/**
 * SEO spam leftovers and C9 history tests.
 */
final class SeoSpamLeftoversTest extends TestCase {

	/**
	 * Texts that match, with the word found.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function spam_texts(): array {
		return array(
			'plain'          => array( 'Best online Casino', 'casino' ),
			'slug'           => array( 'buy-viagra-online', 'viagra' ),
			'underscore'     => array( 'free_poker_game', 'poker' ),
			'slots'          => array( 'Slots & Tabs', 'slots' ),
			'slot'           => array( 'Time slot', 'slot' ),
			'plural'         => array( 'Casinos', 'casinos' ),
			'phrase'         => array( 'Fast Payday Loan today', 'payday loan' ),
			'phrase slug'    => array( 'essay-writing-service', 'essay-writing' ),
			'crypto bonus'   => array( 'crypto bonus', 'crypto bonus' ),
			'upper'          => array( 'GAMBLING', 'gambling' ),
			'name and login' => array( 'mary Mary Escort', 'escort' ),
		);
	}

	/**
	 * Whole-word matches are found.
	 *
	 * @dataProvider spam_texts
	 * @param string $text  Text.
	 * @param string $found Expected match.
	 */
	public function test_matches( string $text, string $found ): void {
		$this->assertSame( $found, Seo_Spam_Leftovers_Check::spam_word( $text ) );
	}

	/**
	 * Look-alikes stay quiet.
	 */
	public function test_lookalikes_are_quiet(): void {
		foreach ( array( 'slotted spoon', 'slotted-spoon', 'Escorted tours', 'Replicate', 'bettingly', 'pokerface', 'unslots', 'crypto', 'payday', 'essay', 'Hello world', '' ) as $text ) {
			$this->assertSame( '', Seo_Spam_Leftovers_Check::spam_word( $text ), $text );
		}
	}

	/**
	 * CJK detection.
	 */
	public function test_is_mostly_cjk(): void {
		$this->assertTrue( Seo_Spam_Leftovers_Check::is_mostly_cjk( '激安ブランドコピー通販' ) );
		$this->assertTrue( Seo_Spam_Leftovers_Check::is_mostly_cjk( '新商品セール sale' ) );
		$this->assertFalse( Seo_Spam_Leftovers_Check::is_mostly_cjk( 'Visit 東京' ) );
		$this->assertFalse( Seo_Spam_Leftovers_Check::is_mostly_cjk( 'Hello world' ) );
		$this->assertFalse( Seo_Spam_Leftovers_Check::is_mostly_cjk( '街' ) );
	}

	/**
	 * Context over a fixture database.
	 *
	 * @param Fixture_Database $db Database.
	 * @return Context
	 */
	private function context( Fixture_Database $db ): Context {
		$root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-c18-unused';
		return new Context( $root, $root . '/wp-content', $root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), array( 'db' => $db ) );
	}

	/**
	 * A site set to a CJK language is never flagged by the foreign-script rule, even with ordinary titles around.
	 */
	public function test_cjk_rule_quiet_for_cjk_locale(): void {
		$posts = array();
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $i => $title ) {
			$posts[] = array(
				'id'      => $i + 1,
				'type'    => 'post',
				'status'  => 'publish',
				'title'   => $title,
				'content' => '',
			);
		}
		$posts[] = array(
			'id'      => 9,
			'type'    => 'post',
			'status'  => 'publish',
			'title'   => '会社概要',
			'content' => '',
		);
		$check   = new Seo_Spam_Leftovers_Check();
		$this->assertCount( 1, $check->run( $this->context( new Fixture_Database( array( 'posts' => $posts ) ) ) ) );
		$this->assertSame(
			array(),
			$check->run(
				$this->context(
					new Fixture_Database(
						array(
							'posts'   => $posts,
							'options' => array( 'WPLANG' => 'ja' ),
						)
					)
				)
			)
		);
	}

	/**
	 * Only 5 logins are listed, the count is complete, and a published match is critical while a draft is a warning.
	 */
	public function test_user_cap_and_severity(): void {
		$users = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$users[] = array(
				'id'         => $i,
				'login'      => 'casino-' . $i,
				'email'      => 'x@x.test',
				'registered' => '2026-01-01 00:00:00',
				'roles'      => array( 'subscriber' ),
				'meta'       => array(),
			);
		}
		$check    = new Seo_Spam_Leftovers_Check();
		$findings = $check->run( $this->context( new Fixture_Database( array( 'users' => $users ) ) ) );
		$this->assertCount( 1, $findings );
		$a = $findings[0]->to_array();
		$this->assertCount( 5, $a['evidence'] );
		$this->assertStringContainsString( '8 registered accounts', $a['title'] );
		$this->assertSame( 'warning', $a['severity'] );

		$post  = array(
			'id'      => 1,
			'type'    => 'post',
			'status'  => 'draft',
			'title'   => 'Poker night',
			'content' => '',
		);
		$draft = $check->run( $this->context( new Fixture_Database( array( 'posts' => array( $post ) ) ) ) );
		$this->assertSame( 'warning', $draft[0]->to_array()['severity'] );
		$post['status'] = 'publish';
		$live           = $check->run( $this->context( new Fixture_Database( array( 'posts' => array( $post ) ) ) ) );
		$this->assertSame( 'critical', $live[0]->to_array()['severity'] );
	}

	/**
	 * A post with a hidden block in content, and revisions around it.
	 *
	 * @param array<int, array<string, mixed>> $revisions Revisions (type, parent and content are added).
	 * @param array<int, array<string, mixed>> $postmeta  Builder meta rows.
	 * @return array<string, mixed> Evidence facts of the C9 finding.
	 */
	private function c9_facts( array $revisions, array $postmeta = array() ): array {
		$bad   = '<div style="position:fixed; top:-4568px"><a href="https://spam.fixture/a">x</a></div>';
		$posts = array(
			array(
				'id'      => 20,
				'type'    => 'page',
				'status'  => 'publish',
				'title'   => 'About',
				'content' => $bad,
			),
		);
		foreach ( $revisions as $rev ) {
			$posts[] = $rev + array(
				'type'    => 'revision',
				'status'  => 'inherit',
				'parent'  => 20,
				'title'   => 'About',
				'content' => '',
			);
		}
		$db       = new Fixture_Database(
			array(
				'posts'    => $posts,
				'postmeta' => $postmeta,
			)
		);
		$root     = str_replace( '\\', '/', sys_get_temp_dir() ) . '/earlyward-c9-unused';
		$ctx      = new Context( $root, $root . '/wp-content', $root . '/wp-content/uploads', array( 'earlyward.sapi' => 'fpm-fcgi' ), array( 'db' => $db ) );
		$findings = ( new Cloaked_Link_Check() )->run( $ctx );
		$this->assertCount( 1, $findings );
		return $findings[0]->to_array()['evidence'][0]['facts'];
	}

	/**
	 * The oldest revision with the block is named, with its date and user; clean revisions are not counted.
	 */
	public function test_history_names_oldest(): void {
		$bad   = '<div style="position:fixed; top:-4568px"><a href="https://spam.fixture/a">x</a></div>';
		$facts = $this->c9_facts(
			array(
				array(
					'id'      => 105,
					'author'  => 'carl',
					'date'    => '2026-03-03 10:00:00',
					'content' => $bad,
				),
				array(
					'id'      => 104,
					'author'  => 'bea',
					'date'    => '2026-03-02 10:00:00',
					'content' => $bad,
				),
				array(
					'id'      => 103,
					'author'  => 'anna',
					'date'    => '2026-03-01 10:00:00',
					'content' => '<p>clean</p>',
				),
			)
		);
		$this->assertSame( 'first seen in a revision saved 2026-03-02 10:00:00 UTC by bea; 2 of 3 revisions checked contain it', $facts['history'] );
		$this->assertSame( 2, $facts['revisions_with_hidden'] );
		$this->assertSame( 'bea', $facts['first_seen_by_user_login'] );
	}

	/**
	 * Builder data on a revision counts; a user that no longer exists is named by number.
	 */
	public function test_history_reads_builder_meta(): void {
		$bad   = '<div style="overflow:hidden;height:1px"><a href="https://spam.fixture/a">x</a></div>';
		$facts = $this->c9_facts(
			array(
				array(
					'id'        => 105,
					'author_id' => 7,
					'date'      => '2026-04-01 08:00:00',
				),
			),
			array(
				array(
					'post_id' => 105,
					'key'     => '_elementor_data',
					'value'   => (string) json_encode( array( array( 'editor' => $bad ) ) ),
				),
			)
		);
		$this->assertStringContainsString( 'by user #7', $facts['history'] );
	}

	/**
	 * No revision has it, or there are none.
	 */
	public function test_history_none(): void {
		$this->assertSame( 'no revision history', $this->c9_facts( array() )['history'] );
		$facts = $this->c9_facts(
			array(
				array(
					'id'     => 105,
					'author' => 'anna',
					'date'   => '2026-03-01 10:00:00',
				),
			)
		);
		$this->assertSame( 'no revision history', $facts['history'] );
		$this->assertSame( 0, $facts['revisions_with_hidden'] );
	}

	/**
	 * At the 200-revision limit, when the oldest one read still has the block, the result says "at least since".
	 */
	public function test_history_limit(): void {
		$bad = '<div style="position:fixed; top:-4568px"><a href="https://spam.fixture/a">x</a></div>';
		$rev = array();
		for ( $i = 1; $i <= 250; $i++ ) {
			$rev[] = array(
				'id'      => 1000 + $i,
				'author'  => 'carl',
				'date'    => '2026-01-01 00:00:00',
				'content' => $bad,
			);
		}
		$facts = $this->c9_facts( $rev );
		$this->assertStringStartsWith( 'at least since a revision saved 2026-01-01 00:00:00 UTC by carl; 200 of 200', $facts['history'] );
		$this->assertStringContainsString( 'only the newest 200', $facts['history'] );
	}
}
