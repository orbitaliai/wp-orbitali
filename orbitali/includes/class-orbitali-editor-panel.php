<?php
/**
 * Wires the Orbitali panel into both the block editor and the classic
 * editor, using whichever is active for a given post type/post.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Editor_Panel {

	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_classic_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_classic_editor' ) );
	}

	private static function settings_url() {
		return admin_url( 'options-general.php?page=' . Orbitali_Settings_Page::PAGE_SLUG );
	}

	private static function localize( $handle ) {
		wp_localize_script(
			$handle,
			'OrbitaliEditorData',
			array(
				'settingsUrl' => self::settings_url(),
			)
		);
	}

	public static function enqueue_block_editor() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, Orbitali_Settings::allowed_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'orbitali-editor-core',
			ORBITALI_PLUGIN_URL . 'assets/js/orbitali-editor-core.js',
			array( 'wp-api-fetch' ),
			ORBITALI_VERSION,
			true
		);
		self::localize( 'orbitali-editor-core' );

		wp_enqueue_script(
			'orbitali-block-editor-panel',
			ORBITALI_PLUGIN_URL . 'assets/js/block-editor-panel.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-i18n', 'wp-components', 'orbitali-editor-core' ),
			ORBITALI_VERSION,
			true
		);

		wp_enqueue_style( 'orbitali-admin', ORBITALI_PLUGIN_URL . 'assets/css/admin.css', array(), ORBITALI_VERSION );
	}

	public static function register_classic_meta_box( $post_type, $post ) {
		if ( ! in_array( $post_type, Orbitali_Settings::allowed_post_types(), true ) ) {
			return;
		}
		if ( $post instanceof WP_Post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		add_meta_box(
			'orbitali-panel',
			__( 'Orbitali', 'orbitali' ),
			array( __CLASS__, 'render_classic_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	public static function render_classic_meta_box( $post ) {
		require ORBITALI_PLUGIN_DIR . 'admin/views/editor-panel.php';
	}

	public static function enqueue_classic_editor( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, Orbitali_Settings::allowed_post_types(), true ) ) {
			return;
		}

		global $post;
		if ( $post instanceof WP_Post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return; // Gutenberg's own panel handles this post.
		}

		wp_enqueue_script(
			'orbitali-editor-core',
			ORBITALI_PLUGIN_URL . 'assets/js/orbitali-editor-core.js',
			array( 'wp-api-fetch' ),
			ORBITALI_VERSION,
			true
		);
		self::localize( 'orbitali-editor-core' );

		wp_enqueue_script(
			'orbitali-classic-editor-panel',
			ORBITALI_PLUGIN_URL . 'assets/js/classic-editor-panel.js',
			array( 'orbitali-editor-core' ),
			ORBITALI_VERSION,
			true
		);

		wp_enqueue_style( 'orbitali-admin', ORBITALI_PLUGIN_URL . 'assets/css/admin.css', array(), ORBITALI_VERSION );
	}
}
