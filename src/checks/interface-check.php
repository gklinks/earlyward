<?php
/**
 * Contract for a check.
 *
 * @package Earlyward
 */

namespace Earlyward\Checks;

use Earlyward\Env\Context;
use Earlyward\Finding\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * A check reads the context and returns findings. It never writes.
 */
interface Check {

	/**
	 * Stable ID, for example "C1".
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Run the check.
	 *
	 * @param Context $ctx Context.
	 * @return Finding[]
	 */
	public function run( Context $ctx ): array;
}
