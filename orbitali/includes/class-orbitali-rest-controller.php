<?php
/**
 * REST endpoints consumed by the editor panel (block editor + classic
 * editor). Every route requires the standard WP REST nonce plus the
 * capability to edit the specific post - a valid nonce alone is never
 * treated as sufficient authorization.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Rest_Controller {

	const NAMESPACE_ = 'orbitali/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/status/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => self::post_id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_preview' ),
				'permission_callback' => array( __CLASS__, 'can_edit_from_body' ),
				'args'                => self::post_id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_sync' ),
				'permission_callback' => array( __CLASS__, 'can_edit_from_body' ),
				'args'                => self::post_id_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/remove',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_remove' ),
				'permission_callback' => array( __CLASS__, 'can_edit_from_body' ),
				'args'                => self::post_id_args(),
			)
		);
	}

	private static function post_id_args() {
		return array(
			'post_id' => array(
				'required'          => true,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);
	}

	public static function can_edit( WP_REST_Request $request ) {
		return self::user_can_edit( (int) $request['post_id'] );
	}

	public static function can_edit_from_body( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		return self::user_can_edit( $post_id );
	}

	private static function user_can_edit( $post_id ) {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, Orbitali_Settings::allowed_post_types(), true ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	public static function handle_status( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		return rest_ensure_response( Orbitali_Sync_Manager::get_status( $post_id ) );
	}

	public static function handle_preview( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( Orbitali_Sync_Manager::preview( $post_id ) );
	}

	public static function handle_sync( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( Orbitali_Sync_Manager::start_sync( $post_id ) );
	}

	public static function handle_remove( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( Orbitali_Sync_Manager::remove( $post_id ) );
	}
}
