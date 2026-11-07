<?php
/**
 * Loopback answers from a fixture's loopback.json:
 * { "/": { "status": 200, "file": "home.html" }, "/wp-json/": { "status": 200, "file": "rest.json" } }
 * Unlisted paths answer 404. "status": 0 with "error" simulates a blocked request.
 *
 * @package Earlyward
 */

namespace Earlyward\Tests;

use Earlyward\Env\Loopback;

/**
 * Fixture loopback.
 */
final class Fixture_Loopback implements Loopback {

	/**
	 * Case directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Routes.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $routes;

	/**
	 * Constructor.
	 *
	 * @param string                              $dir    Case directory.
	 * @param array<string, array<string, mixed>> $routes Routes.
	 */
	public function __construct( string $dir, array $routes ) {
		$this->dir    = $dir;
		$this->routes = $routes;
	}

	/**
	 * Fetch.
	 *
	 * @param string $path Path.
	 * @return array{status: int, body: string, error: string, ms: int}
	 */
	public function get( string $path ): array {
		$route = $this->routes[ $path ] ?? array( 'status' => 404 );
		$body  = isset( $route['file'] ) ? (string) file_get_contents( $this->dir . '/' . $route['file'] ) : (string) ( $route['body'] ?? '' );
		return array(
			'status' => (int) $route['status'],
			'body'   => $body,
			'error'  => (string) ( $route['error'] ?? '' ),
			'ms'     => 5,
		);
	}
}
