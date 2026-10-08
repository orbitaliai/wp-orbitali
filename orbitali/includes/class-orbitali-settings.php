<?php
/**
 * Connection settings storage.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Settings {

	const OPTION_API_KEY        = 'orbitali_api_key';
	const OPTION_BASE_URL       = 'orbitali_api_base_url';
	const OPTION_AGENT_ID       = 'orbitali_agent_id';
	const OPTION_AGENT_NAME     = 'orbitali_agent_name';
	const OPTION_AGENT_STATUS   = 'orbitali_agent_status';
	const OPTION_CONNECTION_OK  = 'orbitali_connection_ok';
	const OPTION_CONNECTED_AT   = 'orbitali_connected_at';
	const CAPABILITY            = 'manage_options';

	/**
	 * Post types this plugin is allowed to operate on.
	 *
	 * @return string[]
	 */
	public static function allowed_post_types() {
		/**
		 * Filters the post types Orbitali can sync.
		 *
		 * @param string[] $post_types
		 */
		return apply_filters( 'orbitali_allowed_post_types', array( 'post', 'page' ) );
	}

	public static function get_api_key() {
		$value = get_option( self::OPTION_API_KEY, '' );
		return is_string( $value ) ? $value : '';
	}

	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	public static function set_api_key( $key ) {
		update_option( self::OPTION_API_KEY, (string) $key, false );
	}

	public static function delete_api_key() {
		delete_option( self::OPTION_API_KEY );
	}

	/**
	 * A masked representation safe to send to the browser. The raw key never
	 * leaves the server after it is first saved.
	 */
	public static function get_masked_api_key() {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}
		$length = strlen( $key );
		$tail   = substr( $key, -4 );
		return str_repeat( '•', max( 8, $length - 4 ) ) . $tail;
	}

	public static function get_base_url() {
		$value = get_option( self::OPTION_BASE_URL, '' );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return defined( 'ORBITALI_API_BASE_URL' ) ? ORBITALI_API_BASE_URL : ORBITALI_DEFAULT_API_BASE_URL;
		}
		return rtrim( $value, '/' );
	}

	public static function set_base_url( $url ) {
		update_option( self::OPTION_BASE_URL, (string) $url, false );
	}

	public static function get_agent_id() {
		$value = get_option( self::OPTION_AGENT_ID, '' );
		return is_string( $value ) ? $value : '';
	}

	public static function get_agent_name() {
		$value = get_option( self::OPTION_AGENT_NAME, '' );
		return is_string( $value ) ? $value : '';
	}

	public static function get_agent_status() {
		$value = get_option( self::OPTION_AGENT_STATUS, '' );
		return is_string( $value ) ? $value : '';
	}

	public static function set_selected_agent( $agent_id, $agent_name, $agent_status ) {
		update_option( self::OPTION_AGENT_ID, (string) $agent_id, false );
		update_option( self::OPTION_AGENT_NAME, (string) $agent_name, false );
		update_option( self::OPTION_AGENT_STATUS, (string) $agent_status, false );
	}

	public static function clear_selected_agent() {
		delete_option( self::OPTION_AGENT_ID );
		delete_option( self::OPTION_AGENT_NAME );
		delete_option( self::OPTION_AGENT_STATUS );
	}

	public static function has_selected_agent() {
		return '' !== self::get_agent_id();
	}

	public static function set_connection_ok( $ok ) {
		update_option( self::OPTION_CONNECTION_OK, $ok ? '1' : '0', false );
		if ( $ok ) {
			update_option( self::OPTION_CONNECTED_AT, time(), false );
		}
	}

	public static function is_connection_ok() {
		return '1' === get_option( self::OPTION_CONNECTION_OK, '0' );
	}

	public static function get_connected_at() {
		return (int) get_option( self::OPTION_CONNECTED_AT, 0 );
	}

	/**
	 * Clears everything derived from the API key. Called whenever the key
	 * changes so a stale agent selection from a different account can never
	 * silently persist.
	 */
	public static function reset_connection_state() {
		self::set_connection_ok( false );
		self::clear_selected_agent();
	}

	public static function current_user_can_manage() {
		return current_user_can( self::CAPABILITY );
	}
}
