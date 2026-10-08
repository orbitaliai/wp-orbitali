<?php
/**
 * PHPUnit bootstrap. Uses the standard wp-phpunit test scaffolding so the
 * suite runs against real WordPress core functions (post meta, hooks,
 * capabilities, DOMDocument-based content filters, etc.) rather than
 * hand-rolled stubs. No network access or real Orbitali account is used -
 * every Orbitali HTTP call is intercepted via `pre_http_request`.
 *
 * @package Orbitali
 */

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/orbitali.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

require __DIR__ . '/class-orbitali-http-mock.php';
