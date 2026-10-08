<?php
/**
 * Test helper: intercepts every outbound Orbitali HTTP call via
 * `pre_http_request` and serves canned responses in order, optionally
 * matched against a URL substring/pattern. No test in this suite ever
 * reaches the network.
 *
 * @package Orbitali
 */

class Orbitali_Http_Mock {

	/** @var array<int,array{pattern:?string,response:array|WP_Error}> */
	private static $queue = array();

	/** @var array<int,array{url:string,method:string,body:?string}> */
	private static $requests = array();

	public static function register() {
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10, 3 );
	}

	public static function reset() {
		self::$queue    = array();
		self::$requests = array();
	}

	/**
	 * @param int        $status
	 * @param array|null $json
	 * @param string|null $url_pattern Only consumed for requests whose URL contains this substring.
	 */
	public static function enqueue_json( $status, $json, $url_pattern = null ) {
		self::$queue[] = array(
			'pattern'  => $url_pattern,
			'response' => array(
				'response' => array( 'code' => $status, 'message' => '' ),
				'body'     => null === $json ? '' : wp_json_encode( $json ),
				'headers'  => array(),
			),
		);
	}

	public static function enqueue_error( $message, $url_pattern = null ) {
		self::$queue[] = array(
			'pattern'  => $url_pattern,
			'response' => new WP_Error( 'http_request_failed', $message ),
		);
	}

	public static function intercept( $preempt, $args, $url ) {
		foreach ( self::$queue as $index => $entry ) {
			if ( null !== $entry['pattern'] && false === strpos( $url, $entry['pattern'] ) ) {
				continue;
			}

			unset( self::$queue[ $index ] );
			self::$queue = array_values( self::$queue );

			self::$requests[] = array(
				'url'     => $url,
				'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
				'body'    => isset( $args['body'] ) ? $args['body'] : null,
				'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			);

			return $entry['response'];
		}

		// Nothing queued for this request: fail loudly instead of hitting the network.
		return new WP_Error( 'orbitali_http_mock_unconfigured', 'No mocked response queued for ' . $url );
	}

	/**
	 * @return array<int,array{url:string,method:string,body:?string}>
	 */
	public static function requests() {
		return self::$requests;
	}

	public static function request_count( $url_pattern = null ) {
		if ( null === $url_pattern ) {
			return count( self::$requests );
		}
		return count(
			array_filter(
				self::$requests,
				function ( $request ) use ( $url_pattern ) {
					return false !== strpos( $request['url'], $url_pattern );
				}
			)
		);
	}
}

Orbitali_Http_Mock::register();
