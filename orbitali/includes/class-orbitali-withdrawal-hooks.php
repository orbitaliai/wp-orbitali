<?php
/**
 * Withdraws plugin-managed Orbitali documents when their WordPress content
 * stops being public: trashed, permanently deleted, unpublished, or
 * password-protected. Independent from manual "Sync"/"Remove" actions.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Withdrawal_Hooks {

	public static function init() {
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_or_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_trash_or_delete' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	public static function on_trash_or_delete( $post_id ) {
		self::withdraw_and_notify( $post_id );
	}

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Orbitali_Settings::allowed_post_types(), true ) ) {
			return;
		}
		if ( empty( Orbitali_Post_Meta::agent_ids_with_live_document( $post_id ) ) ) {
			return;
		}

		$eligibility = Orbitali_Sync_Manager::check_eligibility( $post_id );
		if ( ! $eligibility['eligible'] ) {
			self::withdraw_and_notify( $post_id );
		}
	}

	private static function withdraw_and_notify( $post_id ) {
		if ( empty( Orbitali_Post_Meta::agent_ids_with_live_document( $post_id ) ) ) {
			return;
		}

		$results = Orbitali_Sync_Manager::withdraw_all( $post_id );
		$failures = array_filter( $results, function ( $result ) {
			return empty( $result['ok'] );
		} );

		if ( ! empty( $failures ) ) {
			$user_id = get_current_user_id();
			if ( $user_id ) {
				set_transient(
					'orbitali_notice_' . $user_id,
					__( 'Orbitali: this content became non-public, but removing it from the knowledge base failed. Open the Orbitali panel on this item to retry.', 'orbitali' ),
					60
				);
			}
		}
	}

	public static function render_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$message = get_transient( 'orbitali_notice_' . $user_id );
		if ( ! $message ) {
			return;
		}
		delete_transient( 'orbitali_notice_' . $user_id );
		printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
