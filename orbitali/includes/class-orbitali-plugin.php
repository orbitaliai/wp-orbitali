<?php
/**
 * Plugin bootstrap.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Orbitali_Sync_Manager::init();
		Orbitali_Rest_Controller::init();
		Orbitali_Editor_Panel::init();
		Orbitali_Withdrawal_Hooks::init();
		Orbitali_Settings_Page::init();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'orbitali', false, dirname( ORBITALI_PLUGIN_BASENAME ) . '/languages' );
	}
}
