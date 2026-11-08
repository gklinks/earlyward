<?php
/**
 * Logic behind the Overview tab: cron grouping, owners, new users, update rows.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use PHPUnit\Framework\TestCase;
use Earlyward\Admin\Overview_Data;

/**
 * Overview data logic.
 */
final class OverviewDataTest extends TestCase {

	const NOW = 1700000000;

	/**
	 * Events are counted per job, and the "version" key WordPress adds is ignored.
	 */
	public function test_cron_groups_by_hook(): void {
		$cron    = array(
			self::NOW + 100  => array(
				'wp_version_check' => array(
					'a' => array( 'schedule' => 'twicedaily' ),
				),
			),
			self::NOW + 200  => array(
				'my_plugin_job' => array(
					'a' => array( 'schedule' => 'hourly' ),
					'b' => array( 'schedule' => 'hourly' ),
				),
			),
			self::NOW + 9000 => array(
				'my_plugin_job' => array(
					'c' => array( 'schedule' => 'hourly' ),
				),
			),
			'version'        => 2,
		);
		$summary = Overview_Data::cron_summary(
			$cron,
			array(
				'wp_version_check' => 'WordPress core',
				'my_plugin_job'    => 'Plugin: my-plugin',
			),
			self::NOW
		);
		$this->assertSame( 4, $summary['total'] );
		$this->assertSame( 0, $summary['late'] );
		$this->assertSame( 0, $summary['orphans'] );
		$this->assertCount( 2, $summary['hooks'] );
		$by = array_column( $summary['hooks'], null, 'hook' );
		$this->assertSame( 3, $by['my_plugin_job']['count'] );
		$this->assertSame( self::NOW + 200, $by['my_plugin_job']['next'] );
		$this->assertSame( 'hourly', $by['my_plugin_job']['schedule'] );
	}

	/**
	 * Late jobs and jobs with no code behind them are reported and listed first.
	 */
	public function test_late_and_ownerless_jobs_come_first(): void {
		$cron    = array(
			self::NOW - 7200 => array(
				'overdue_job' => array(
					'a' => array( 'schedule' => false ),
				),
			),
			self::NOW + 50   => array(
				'fine_job'      => array(
					'a' => array( 'schedule' => 'daily' ),
				),
				'leftover_hook' => array(
					'a' => array( 'schedule' => 'daily' ),
				),
			),
		);
		$summary = Overview_Data::cron_summary(
			$cron,
			array(
				'overdue_job' => 'Plugin: a',
				'fine_job'    => 'Plugin: b',
			),
			self::NOW
		);
		$this->assertSame( 1, $summary['late'] );
		$this->assertSame( 1, $summary['orphans'] );
		$this->assertSame( array( 'overdue_job', 'leftover_hook', 'fine_job' ), array_column( $summary['hooks'], 'hook' ) );
		$this->assertNull( $summary['hooks'][1]['owner'] );
		$this->assertSame( 'once', $summary['hooks'][0]['schedule'] );
	}

	/**
	 * Benign: a few minutes past due on a quiet site is not "late".
	 */
	public function test_slightly_overdue_is_not_late(): void {
		$summary = Overview_Data::cron_summary(
			array(
				self::NOW - 120 => array(
					'job' => array(
						'a' => array( 'schedule' => 'hourly' ),
					),
				),
			),
			array( 'job' => 'Plugin: a' ),
			self::NOW
		);
		$this->assertSame( 0, $summary['late'] );
	}

	/**
	 * Empty and malformed cron data is fine.
	 */
	public function test_cron_empty_and_malformed(): void {
		$this->assertSame( 0, Overview_Data::cron_summary( array(), array(), self::NOW )['total'] );
		$this->assertSame(
			0,
			Overview_Data::cron_summary(
				array(
					'version' => 2,
					5         => 'junk',
				),
				array(),
				self::NOW
			)['total']
		);
	}

	/**
	 * Files map to a plugin, a theme, a must-use plugin or core; unknown places map to null.
	 */
	public function test_owner_from_path(): void {
		$roots = array(
			'Plugin'          => '/site/wp-content/plugins',
			'Must-use'        => '/site/wp-content/mu-plugins/',
			'Theme'           => '/site/wp-content/themes',
			'WordPress core'  => '/site/wp-includes',
			'WordPress core ' => '/site/wp-admin',
		);
		$this->assertSame( 'Plugin: akismet', Overview_Data::owner_from_path( '/site/wp-content/plugins/akismet/akismet.php', $roots ) );
		$this->assertSame( 'Theme: astra', Overview_Data::owner_from_path( '/site/wp-content/themes/astra/functions.php', $roots ) );
		$this->assertSame( 'Must-use: loader.php', Overview_Data::owner_from_path( '/site/wp-content/mu-plugins/loader.php', $roots ) );
		$this->assertSame( 'WordPress core', Overview_Data::owner_from_path( '/site/wp-includes/cron.php', $roots ) );
		$this->assertSame( 'WordPress core', Overview_Data::owner_from_path( '/site/wp-admin/includes/update.php', $roots ) );
		$this->assertSame( 'Plugin: akismet', Overview_Data::owner_from_path( '\\site\\wp-content\\plugins\\akismet\\akismet.php', $roots ) );
		$this->assertNull( Overview_Data::owner_from_path( '/elsewhere/file.php', $roots ) );
		$this->assertNull( Overview_Data::owner_from_path( '/site/wp-content/plugins-extra/x.php', $roots ), 'A folder that only shares a prefix is not a plugin.' );
		$this->assertNull( Overview_Data::owner_from_path( '/anything.php', array( 'Plugin' => '' ) ), 'An empty directory matches nothing.' );
	}

	/**
	 * Newest accounts first, and a limit.
	 */
	public function test_newest_users(): void {
		$users  = array(
			array(
				'login'      => 'old',
				'registered' => '2020-01-01 00:00:00',
				'roles'      => array( 'editor' ),
			),
			array(
				'login'      => 'new',
				'registered' => '2026-11-07 06:00:00',
				'roles'      => array( 'administrator' ),
			),
			array(
				'login'      => 'mid',
				'registered' => '2024-05-05 12:00:00',
				'roles'      => array(),
			),
		);
		$newest = Overview_Data::newest_users( $users, 2 );
		$this->assertSame( array( 'new', 'mid' ), array_column( $newest, 'login' ) );
		$this->assertSame( array(), Overview_Data::newest_users( $users, 0 ) );
	}

	/**
	 * Update rows: names, versions, no rows for "already current" or missing versions.
	 */
	public function test_update_rows(): void {
		$rows = Overview_Data::update_rows(
			array(
				'b/b.php' => (object) array( 'new_version' => '2.0' ),
				'a/a.php' => array( 'new_version' => '1.5' ),
				'c/c.php' => (object) array( 'new_version' => '3.0' ),
				'd/d.php' => (object) array(),
			),
			array(
				'a/a.php' => 'Alpha',
				'b/b.php' => 'Beta',
			),
			array(
				'a/a.php' => '1.0',
				'b/b.php' => '1.0',
				'c/c.php' => '3.0',
			)
		);
		$this->assertSame( array( 'Alpha', 'Beta' ), array_column( $rows, 'name' ) );
		$this->assertSame( '1.5', $rows[0]['to'] );
		$this->assertSame( '1.0', $rows[0]['from'] );
		$this->assertSame( 'a/a.php', $rows[0]['key'], 'The key names the item for the update button.' );
	}
}
