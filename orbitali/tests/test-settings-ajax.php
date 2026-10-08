<?php
/**
 * Covers: invalid/revoked API key, no agents + agent creation, existing
 * agent selection, switching API key clears an invalid destination
 * selection, and an unauthorized WordPress user cannot manage the
 * connection.
 *
 * @package Orbitali
 */

class Test_Orbitali_Settings_Ajax extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Orbitali_Http_Mock::reset();
		Orbitali_Settings::reset_connection_state();
		Orbitali_Settings::delete_api_key();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function call( $action, array $post ) {
		$_POST            = $post;
		$_POST['action']  = $action;
		$_POST['nonce']   = wp_create_nonce( Orbitali_Settings_Page::NONCE_ACTION );

		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}

	public function test_connect_with_invalid_key_reports_error_and_does_not_persist_it() {
		Orbitali_Http_Mock::enqueue_json( 401, array( 'error' => 'Invalid or missing API key' ) );

		$response = $this->call( 'orbitali_connect', array( 'api_key' => 'orb_sk_bad' ) );

		$this->assertFalse( $response['success'] );
		$this->assertFalse( Orbitali_Settings::has_api_key() );
		$this->assertFalse( Orbitali_Settings::is_connection_ok() );
	}

	public function test_connect_with_valid_key_but_no_agents_returns_an_empty_list() {
		Orbitali_Http_Mock::enqueue_json( 200, array() );

		$response = $this->call( 'orbitali_connect', array( 'api_key' => 'orb_sk_good' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array(), $response['data']['agents'] );
		$this->assertTrue( Orbitali_Settings::is_connection_ok() );
	}

	public function test_connect_with_valid_key_lists_existing_agents() {
		Orbitali_Http_Mock::enqueue_json(
			200,
			array(
				array( 'id' => 'a1', 'name' => 'Support', 'status' => 'draft', 'agentType' => 'static', 'knowledgeDocumentCount' => 0 ),
			)
		);

		$response = $this->call( 'orbitali_connect', array( 'api_key' => 'orb_sk_good' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'a1', $response['data']['agents'][0]['id'] );
	}

	public function test_create_agent_when_none_exist_creates_a_static_draft_and_selects_it() {
		Orbitali_Settings::set_api_key( 'orb_sk_good' );
		Orbitali_Settings::set_connection_ok( true );
		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'agent-new' ) );

		$response = $this->call( 'orbitali_create_agent', array( 'name' => 'Docs Agent' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'agent-new', Orbitali_Settings::get_agent_id() );
		$this->assertSame( 'draft', Orbitali_Settings::get_agent_status() );

		$requests = Orbitali_Http_Mock::requests();
		$body     = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'static', $body['agentType'] );
		$this->assertSame( 'static', $body['promptType'] );
	}

	public function test_select_existing_agent_persists_the_choice() {
		Orbitali_Settings::set_api_key( 'orb_sk_good' );
		Orbitali_Settings::set_connection_ok( true );
		Orbitali_Http_Mock::enqueue_json(
			200,
			array( 'agent' => array( 'id' => 'a1', 'name' => 'Support', 'status' => 'draft' ), 'promptConfig' => null )
		);

		$response = $this->call( 'orbitali_select_agent', array( 'agent_id' => 'a1' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'a1', Orbitali_Settings::get_agent_id() );
	}

	public function test_changing_the_api_key_clears_a_destination_that_is_no_longer_visible() {
		Orbitali_Settings::set_api_key( 'orb_sk_old' );
		Orbitali_Settings::set_connection_ok( true );
		Orbitali_Settings::set_selected_agent( 'agent-old', 'Old Agent', 'draft' );

		Orbitali_Http_Mock::enqueue_json( 200, array( array( 'id' => 'agent-new', 'name' => 'New Agent', 'status' => 'draft' ) ) );

		$response = $this->call( 'orbitali_connect', array( 'api_key' => 'orb_sk_new' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( '', Orbitali_Settings::get_agent_id() );
	}

	public function test_unauthorized_user_cannot_connect() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->call( 'orbitali_connect', array( 'api_key' => 'orb_sk_x' ) );

		$this->assertFalse( $response['success'] );
		$this->assertFalse( Orbitali_Settings::has_api_key() );
		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
	}
}
