<?php
/**
 * Covers REST authorization: a nonce is never treated as sufficient on its
 * own - every route also requires edit_post capability on the specific
 * post referenced in the request.
 *
 * @package Orbitali
 */

class Test_Orbitali_Rest_Permissions extends WP_UnitTestCase {

	/** @var WP_REST_Server */
	protected static $server;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		self::$server    = $wp_rest_server;
		do_action( 'rest_api_init', self::$server );

		Orbitali_Http_Mock::reset();
		Orbitali_Settings::set_api_key( 'orb_sk_good' );
		Orbitali_Settings::set_connection_ok( true );
		Orbitali_Settings::set_selected_agent( 'agent-a', 'Docs Agent', 'draft' );
	}

	private function create_page() {
		return self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Hi</p>' ) );
	}

	public function test_subscriber_cannot_fetch_status_for_a_page_they_cannot_edit() {
		$post_id = $this->create_page();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/orbitali/v1/status/' . $post_id ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_logged_out_user_cannot_fetch_status() {
		$post_id = $this->create_page();
		wp_set_current_user( 0 );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/orbitali/v1/status/' . $post_id ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_editor_can_fetch_status() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $editor ) );
		wp_set_current_user( $editor );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/orbitali/v1/status/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['configured'] );
	}

	public function test_sync_rejects_an_unauthorized_user_even_with_a_valid_post_id_in_the_body() {
		$post_id = $this->create_page();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/orbitali/v1/sync' );
		$request->set_param( 'post_id', $post_id );

		$response = self::$server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
	}

	public function test_preview_is_rejected_for_a_disallowed_post_type() {
		$attachment_id = self::factory()->attachment->create_object(
			array( 'file' => 'image.jpg', 'post_status' => 'inherit' )
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/orbitali/v1/preview' );
		$request->set_param( 'post_id', $attachment_id );

		$response = self::$server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
