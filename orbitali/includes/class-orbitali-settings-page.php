<?php
/**
 * Admin settings screen: connect with an API key, choose or create the
 * destination agent.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Settings_Page {

	const NONCE_ACTION = 'orbitali_settings';
	const PAGE_SLUG     = 'orbitali-settings';

	/**
	 * Curated options mirroring the voices and languages Orbitali's own
	 * dashboard offers today. The API itself accepts free-form strings;
	 * this list only avoids guesswork in the UI and can drift if Orbitali
	 * adds options - the create-agent call will still work with any value.
	 */
	public static function voice_options() {
		return array(
			'eve' => __( 'Eve (female)', 'orbitali' ),
			'ara' => __( 'Ara (female)', 'orbitali' ),
			'rex' => __( 'Rex (male)', 'orbitali' ),
			'sal' => __( 'Sal (male)', 'orbitali' ),
			'leo' => __( 'Leo (male)', 'orbitali' ),
		);
	}

	public static function language_options() {
		return array(
			'en-US' => __( 'English (US)', 'orbitali' ),
			'es-ES' => __( 'Spanish (Spain)', 'orbitali' ),
			'es-US' => __( 'Spanish (US)', 'orbitali' ),
			'it-IT' => __( 'Italian (Italy)', 'orbitali' ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_orbitali_connect', array( __CLASS__, 'ajax_connect' ) );
		add_action( 'wp_ajax_orbitali_select_agent', array( __CLASS__, 'ajax_select_agent' ) );
		add_action( 'wp_ajax_orbitali_create_agent', array( __CLASS__, 'ajax_create_agent' ) );
		add_action( 'wp_ajax_orbitali_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
	}

	public static function register_menu() {
		add_options_page(
			__( 'Orbitali', 'orbitali' ),
			__( 'Orbitali', 'orbitali' ),
			Orbitali_Settings::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'orbitali-admin', ORBITALI_PLUGIN_URL . 'assets/css/admin.css', array(), ORBITALI_VERSION );
		wp_enqueue_script( 'orbitali-settings', ORBITALI_PLUGIN_URL . 'assets/js/settings.js', array( 'jquery' ), ORBITALI_VERSION, true );
		wp_localize_script(
			'orbitali-settings',
			'OrbitaliSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'connecting' => __( 'Connecting…', 'orbitali' ),
					'confirmDisconnect' => __( 'Disconnect Orbitali? Your destination agent selection will be cleared.', 'orbitali' ),
				),
			)
		);
	}

	public static function render() {
		if ( ! Orbitali_Settings::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage Orbitali settings.', 'orbitali' ) );
		}

		$view = self::build_view_data();
		require ORBITALI_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	private static function build_view_data() {
		$connected = Orbitali_Settings::has_api_key() && Orbitali_Settings::is_connection_ok();
		$agents    = array();
		$list_error = null;

		if ( $connected ) {
			$result = ( new Orbitali_Api_Client() )->list_agents();
			if ( $result['ok'] ) {
				$agents = self::format_agents( is_array( $result['data'] ) ? $result['data'] : array() );
			} else {
				$connected  = false;
				$list_error = $result['error'];
				Orbitali_Settings::set_connection_ok( false );
			}
		}

		return array(
			'connected'       => $connected,
			'has_key'         => Orbitali_Settings::has_api_key(),
			'masked_key'      => Orbitali_Settings::get_masked_api_key(),
			'list_error'      => $list_error,
			'agents'          => $agents,
			'selected_agent_id' => Orbitali_Settings::get_agent_id(),
			'selected_agent_name' => Orbitali_Settings::get_agent_name(),
			'selected_agent_status' => Orbitali_Settings::get_agent_status(),
		);
	}

	private static function verify_request() {
		if ( ! Orbitali_Settings::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage Orbitali settings.', 'orbitali' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function ajax_connect() {
		self::verify_request();

		$submitted_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		if ( '' === $submitted_key ) {
			wp_send_json_error( array( 'message' => __( 'Enter an API key.', 'orbitali' ) ) );
		}

		$client = new Orbitali_Api_Client( $submitted_key );
		$result = $client->list_agents();

		if ( ! $result['ok'] ) {
			Orbitali_Settings::set_connection_ok( false );
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		$previous_key   = Orbitali_Settings::get_api_key();
		$agents         = is_array( $result['data'] ) ? $result['data'] : array();
		$agent_ids      = wp_list_pluck( $agents, 'id' );
		$current_agent  = Orbitali_Settings::get_agent_id();

		if ( $submitted_key !== $previous_key || ( $current_agent && ! in_array( $current_agent, $agent_ids, true ) ) ) {
			Orbitali_Settings::clear_selected_agent();
		}

		Orbitali_Settings::set_api_key( $submitted_key );
		Orbitali_Settings::set_connection_ok( true );

		wp_send_json_success(
			array(
				'agents'       => self::format_agents( $agents ),
				'maskedKey'    => Orbitali_Settings::get_masked_api_key(),
				'selectedAgentId' => Orbitali_Settings::get_agent_id(),
			)
		);
	}

	public static function ajax_disconnect() {
		self::verify_request();
		Orbitali_Settings::delete_api_key();
		Orbitali_Settings::reset_connection_state();
		wp_send_json_success();
	}

	public static function ajax_select_agent() {
		self::verify_request();

		if ( ! Orbitali_Settings::is_connection_ok() ) {
			wp_send_json_error( array( 'message' => __( 'Connect Orbitali first.', 'orbitali' ) ) );
		}

		$agent_id = isset( $_POST['agent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_id'] ) ) : '';
		if ( '' === $agent_id ) {
			wp_send_json_error( array( 'message' => __( 'Choose an agent.', 'orbitali' ) ) );
		}

		$client = new Orbitali_Api_Client();
		$result = $client->get_agent( $agent_id );

		if ( ! $result['ok'] || ! isset( $result['data']['agent'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ? $result['error'] : __( 'That agent is no longer accessible.', 'orbitali' ) ) );
		}

		$agent = $result['data']['agent'];
		Orbitali_Settings::set_selected_agent( $agent['id'], $agent['name'], $agent['status'] );

		wp_send_json_success( array( 'agent' => self::format_agents( array( $agent ) )[0] ) );
	}

	public static function ajax_create_agent() {
		self::verify_request();

		if ( ! Orbitali_Settings::is_connection_ok() ) {
			wp_send_json_error( array( 'message' => __( 'Connect Orbitali first.', 'orbitali' ) ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$voice = isset( $_POST['voice_name'] ) ? sanitize_text_field( wp_unslash( $_POST['voice_name'] ) ) : '';
		$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';

		if ( '' === trim( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Name is required.', 'orbitali' ) ) );
		}
		if ( '' === $language ) {
			$language = 'en-US';
		}
		if ( '' === $voice ) {
			$voice = 'eve';
		}
		if ( '' === trim( $instructions ) ) {
			$instructions = __( 'Answer questions using only the uploaded knowledge base. If the answer is not in the knowledge base, say you do not know and offer to connect the caller with a human.', 'orbitali' );
		}

		$client = new Orbitali_Api_Client();
		$result = $client->create_agent(
			array(
				'name'         => $name,
				'language'     => $language,
				'voiceName'    => $voice,
				'identity'     => '',
				'instructions' => $instructions,
			)
		);

		if ( ! $result['ok'] || empty( $result['data']['id'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ? $result['error'] : __( 'Could not create the agent.', 'orbitali' ) ) );
		}

		$agent_id = $result['data']['id'];
		Orbitali_Settings::set_selected_agent( $agent_id, $name, 'draft' );

		wp_send_json_success(
			array(
				'agent' => array(
					'id'     => $agent_id,
					'name'   => $name,
					'status' => 'draft',
				),
			)
		);
	}

	private static function format_agents( array $agents ) {
		return array_map(
			function ( $agent ) {
				return array(
					'id'                     => $agent['id'],
					'name'                   => $agent['name'],
					'status'                 => $agent['status'],
					'agentType'              => isset( $agent['agentType'] ) ? $agent['agentType'] : null,
					'knowledgeDocumentCount' => isset( $agent['knowledgeDocumentCount'] ) ? $agent['knowledgeDocumentCount'] : null,
				);
			},
			$agents
		);
	}
}
