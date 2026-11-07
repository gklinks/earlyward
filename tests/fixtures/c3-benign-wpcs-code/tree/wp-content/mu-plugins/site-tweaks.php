<?php
/**
 * Plugin Name: Site Tweaks
 */
if ( ! function_exists( 'site_tweaks_logo' ) ) {
	function site_tweaks_logo() {
		// A small inline image is base64 data, but it is never decoded and executed.
		return 'data:image/png;base64,' . base64_encode( 'fixture' );
	}
}
add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'tweaked';
	return $classes;
} );
