<?php
/**
 * @package Orbitali
 */

class Test_Orbitali_Api_Client extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Orbitali_Http_Mock::reset();
	}

	public function test_invalid_key_returns_unauthorized_error() {
		Orbitali_Http_Mock::enqueue_json( 401, array( 'error' => 'Invalid or missing API key' ) );

		$client = new Orbitali_Api_Client( 'orb_sk_bad', 'https://example.test/public/v1' );
		$result = $client->list_agents();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'unauthorized', $result['code'] );
		$this->assertSame( 'Invalid or missing API key', $result['error'] );
	}

	public function test_ambiguous_network_failure_is_reported_as_network_error() {
		Orbitali_Http_Mock::enqueue_error( 'Connection timed out' );

		$client = new Orbitali_Api_Client( 'orb_sk_x', 'https://example.test/public/v1' );
		$result = $client->list_agents();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertSame( 'network_error', $result['code'] );
	}

	public function test_missing_key_short_circuits_without_any_http_call() {
		$client = new Orbitali_Api_Client( '', 'https://example.test/public/v1' );
		$result = $client->list_agents();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'missing_api_key', $result['code'] );
		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
	}

	public function test_create_knowledge_document_sends_bearer_auth_and_never_puts_the_key_in_the_body_or_url() {
		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-1', 'name' => 'test', 'description' => null ) );

		$client = new Orbitali_Api_Client( 'orb_sk_secret', 'https://example.test/public/v1' );
		$result = $client->create_knowledge_document( 'agent-1', 'test', null, 'hello world' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'doc-1', $result['data']['id'] );

		$requests = Orbitali_Http_Mock::requests();
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString( '/agents/agent-1/knowledge', $requests[0]['url'] );
		$this->assertStringNotContainsString( 'orb_sk_secret', $requests[0]['url'] );

		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'hello world', $body['content'] );
		$this->assertArrayNotHasKey( 'api_key', $body );

		$this->assertSame( 'Bearer orb_sk_secret', $requests[0]['headers']['Authorization'] );
	}

	public function test_rate_limited_response_is_mapped_to_a_friendly_code() {
		Orbitali_Http_Mock::enqueue_json( 429, array( 'error' => 'Too many requests' ) );

		$client = new Orbitali_Api_Client( 'orb_sk_x', 'https://example.test/public/v1' );
		$result = $client->list_agents();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'rate_limited', $result['code'] );
	}

	public function test_delete_treats_404_as_a_distinguishable_not_found_result() {
		Orbitali_Http_Mock::enqueue_json( 404, array( 'error' => 'Knowledge document not found' ) );

		$client = new Orbitali_Api_Client( 'orb_sk_x', 'https://example.test/public/v1' );
		$result = $client->delete_knowledge_document( 'agent-1', 'doc-missing' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 404, $result['status'] );
	}
}
