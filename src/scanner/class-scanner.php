<?php
/**
 * Runs checks in isolation. One failing check never stops the others.
 *
 * @package Earlyward
 */

namespace Earlyward\Scanner;

use Earlyward\Checks\Check;
use Earlyward\Env\Context;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 */
final class Scanner {

	/**
	 * Checks to run.
	 *
	 * @var Check[]
	 */
	private $checks;

	/**
	 * Constructor.
	 *
	 * @param Check[] $checks Checks.
	 */
	public function __construct( array $checks ) {
		$this->checks = $checks;
	}

	/**
	 * The default set of checks.
	 *
	 * @return self
	 */
	public static function with_default_checks(): self {
		return new self(
			array(
				new \Earlyward\Checks\Runtime\Outage_Check(),
				new \Earlyward\Checks\Runtime\Crash_Check(),
				new \Earlyward\Checks\Runtime\Error_Log_Check(),
				new \Earlyward\Checks\Runtime\Cron_Check(),
				new \Earlyward\Checks\Integrity\Auto_Prepend_Check(),
				new \Earlyward\Checks\Integrity\Archive_Seed_Check(),
				new \Earlyward\Checks\Integrity\Unexpected_Php_Check(),
				new \Earlyward\Checks\Integrity\Rogue_Admin_Check(),
				new \Earlyward\Checks\Integrity\Admin_Credentials_Check(),
				new \Earlyward\Checks\Integrity\Hidden_User_Css_Check(),
				new \Earlyward\Checks\Integrity\Cloaked_Link_Check(),
				new \Earlyward\Checks\Hosting\Shared_User_Check(),
				new \Earlyward\Checks\Hosting\Open_Basedir_Check(),
				new \Earlyward\Checks\Hosting\Exposed_Backup_Check(),
				new \Earlyward\Checks\Hosting\Config_Permissions_Check(),
				new \Earlyward\Checks\Hosting\Php_Runtime_Check(),
				new \Earlyward\Checks\Hosting\Disk_Space_Check(),
				new \Earlyward\Checks\Hosting\Update_Check_Health_Check(),
				new \Earlyward\Checks\Integrity\Uploads_Php_Check(),
				new \Earlyward\Checks\Integrity\Theme_Injection_Check(),
				new \Earlyward\Checks\Housekeeping\Old_Backups_Check(),
				new \Earlyward\Checks\Housekeeping\Backup_Freshness_Check(),
				new \Earlyward\Checks\Vulnerabilities\Known_Vulnerability_Check(),
			)
		);
	}

	/**
	 * Run every check.
	 *
	 * @param Context $ctx Context.
	 * @return array{findings: Finding[], errors: array<string, string>, timings: array<string, float>, memory: array<string, int|null>, observations: array<string, array{data: array<string, mixed>, healthy: bool}>}
	 */
	public function run( Context $ctx ): array {
		$findings     = array();
		$errors       = array();
		$timings      = array();
		$memory       = array();
		$observations = array();
		foreach ( $this->checks as $check ) {
			// Peak memory above the starting point; only measurable per check on PHP 8.2+.
			$can_reset = function_exists( 'memory_reset_peak_usage' );
			$mem_base  = memory_get_usage();
			if ( $can_reset ) {
				memory_reset_peak_usage();
			}
			$start = microtime( true );
			try {
				foreach ( $check->run( $ctx ) as $finding ) {
					if ( $finding instanceof Finding ) {
						$findings[] = $finding;
					}
				}
				if ( $check instanceof \Earlyward\Checks\Observes && array() !== $check->observation() ) {
					$observations[ $check->id() ] = array(
						'data'    => $check->observation(),
						'healthy' => $check->observation_is_healthy(),
					);
				}
			} catch ( \Throwable $e ) {
				// "Could not check" is reported, never silently treated as clean.
				$errors[ $check->id() ] = get_class( $e ) . ': ' . $e->getMessage();
			}
			$timings[ $check->id() ] = round( microtime( true ) - $start, 4 );
			$memory[ $check->id() ]  = $can_reset ? max( 0, memory_get_peak_usage() - $mem_base ) : null;
		}
		return array(
			'findings'     => $findings,
			'errors'       => $errors,
			'timings'      => $timings,
			'memory'       => $memory,
			'observations' => $observations,
		);
	}
}
