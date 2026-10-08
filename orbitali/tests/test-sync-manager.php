<?php
/**
 * Covers the create/replace/withdraw state machine against mocked Orbitali
 * responses: first sync, unchanged-content sync, changed content, changed
 * slug, duplicate clicks, indexing failure, cleanup-fails-after-replace,
 * a missing remote document during verification, and agent-switch
 * isolation.
 *
 * @package Orbitali
 */

class Test_Orbitali_Sync_Manager extends WP_UnitTestCase {

	private $post_id;
	private $agent_id = 'agent-a';

	public function set_up() {
		parent::set_up();
		Orbitali_Http_Mock::reset();

		Orbitali_Settings::set_api_key( 'orb_sk_good' );
		Orbitali_Settings::set_connection_ok( true );
		Orbitali_Settings::set_selected_agent( $this->agent_id, 'Docs Agent', 'draft' );

		$this->post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'About',
				'post_name'    => 'about',
				'post_content' => '<p>Hello world</p>',
			)
		);
	}

	private function current_hash( $post_id ) {
		$post = get_post( $post_id );
		$name = Orbitali_Document_Naming::build_document_name( $post );
		$body = Orbitali_Content_Extractor::extract( $post )['body_markdown'];
		return Orbitali_Document_Naming::compute_content_hash( $name, get_the_title( $post ), get_permalink( $post ), $body );
	}

	public function test_first_sync_creates_a_document() {
		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-1', 'name' => 'about', 'description' => null ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( array( 'id' => 'doc-1', 'status' => 'ready', 'name' => 'about' ) ), '/knowledge' );

		$started = Orbitali_Sync_Manager::start_sync( $this->post_id );
		$this->assertTrue( $started['ok'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_QUEUED, $started['status'] );

		Orbitali_Sync_Manager::run_sync_job( $this->post_id, $this->agent_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( 'doc-1', $mapping['document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_SYNCED, $mapping['status'] );
		$this->assertNotEmpty( $mapping['last_synced_at'] );
		$this->assertFalse( Orbitali_Post_Meta::is_locked( $this->post_id, $this->agent_id ) );
	}

	public function test_unchanged_content_sync_skips_upload() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array(
				'document_id'  => 'doc-1',
				'content_hash' => $this->current_hash( $this->post_id ),
				'status'       => Orbitali_Post_Meta::STATUS_SYNCED,
			)
		);

		$result = Orbitali_Sync_Manager::start_sync( $this->post_id );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_SYNCED, $result['status'] );
		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
	}

	public function test_changed_content_triggers_upload_then_delete_of_old_document() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array(
				'document_id'  => 'doc-old',
				'content_hash' => 'stale-hash-does-not-match-anything',
				'status'       => Orbitali_Post_Meta::STATUS_SYNCED,
			)
		);

		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-new' ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( array( 'id' => 'doc-new', 'status' => 'ready' ) ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-old' ), '/knowledge/doc-old' );

		Orbitali_Sync_Manager::start_sync( $this->post_id );
		Orbitali_Sync_Manager::run_sync_job( $this->post_id, $this->agent_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( 'doc-new', $mapping['document_id'] );
		$this->assertNull( $mapping['stale_document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_SYNCED, $mapping['status'] );

		$requests = Orbitali_Http_Mock::requests();
		$this->assertSame( 'DELETE', $requests[2]['method'] );
		$this->assertStringContainsString( 'doc-old', $requests[2]['url'] );
	}

	public function test_changed_slug_triggers_replace_even_if_body_is_identical() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array(
				'document_id'  => 'doc-old',
				'content_hash' => $this->current_hash( $this->post_id ),
				'status'       => Orbitali_Post_Meta::STATUS_SYNCED,
			)
		);

		wp_update_post( array( 'ID' => $this->post_id, 'post_name' => 'about-us' ) );
		clean_post_cache( $this->post_id );

		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-renamed' ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( array( 'id' => 'doc-renamed', 'status' => 'ready' ) ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-old' ), '/knowledge/doc-old' );

		$started = Orbitali_Sync_Manager::start_sync( $this->post_id );
		$this->assertSame( Orbitali_Post_Meta::STATUS_QUEUED, $started['status'] );

		Orbitali_Sync_Manager::run_sync_job( $this->post_id, $this->agent_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( 'doc-renamed', $mapping['document_id'] );
	}

	public function test_indexing_failure_preserves_the_last_working_document() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array(
				'document_id'  => 'doc-old',
				'content_hash' => 'stale-hash-does-not-match-anything',
				'status'       => Orbitali_Post_Meta::STATUS_SYNCED,
			)
		);

		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-bad' ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json(
			200,
			array( array( 'id' => 'doc-bad', 'status' => 'failed', 'error' => 'Embedding failed' ) ),
			'/knowledge'
		);
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-bad' ), '/knowledge/doc-bad' );

		Orbitali_Sync_Manager::start_sync( $this->post_id );
		Orbitali_Sync_Manager::run_sync_job( $this->post_id, $this->agent_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( Orbitali_Post_Meta::STATUS_FAILED, $mapping['status'] );
		$this->assertStringContainsString( 'Embedding failed', $mapping['error'] );
		// The previously synced document is untouched.
		$this->assertSame( 'doc-old', $mapping['document_id'] );
		$this->assertNull( $mapping['pending_document_id'] );
	}

	public function test_replacement_succeeds_but_old_document_cleanup_failure_is_retryable() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array(
				'document_id'  => 'doc-old',
				'content_hash' => 'stale-hash-does-not-match-anything',
				'status'       => Orbitali_Post_Meta::STATUS_SYNCED,
			)
		);

		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-new' ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( array( 'id' => 'doc-new', 'status' => 'ready' ) ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 500, array( 'error' => 'Orbitali unavailable' ), '/knowledge/doc-old' );

		Orbitali_Sync_Manager::start_sync( $this->post_id );
		Orbitali_Sync_Manager::run_sync_job( $this->post_id, $this->agent_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		// New content is live and authoritative even though cleanup failed.
		$this->assertSame( 'doc-new', $mapping['document_id'] );
		$this->assertSame( 'doc-old', $mapping['stale_document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_SYNCED, $mapping['status'] );
		$this->assertNotEmpty( $mapping['error'] );

		// A later sync click retries the stale cleanup before anything else.
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-old' ), '/knowledge/doc-old' );
		$result = Orbitali_Sync_Manager::start_sync( $this->post_id );

		$this->assertTrue( $result['ok'] );
		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertNull( $mapping['stale_document_id'] );
	}

	public function test_missing_remote_document_during_verification_fails_without_looping_forever() {
		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-x' ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array(), '/knowledge' ); // doc-x not present in the list.
		Orbitali_Http_Mock::enqueue_json( 404, array( 'error' => 'not found' ), '/knowledge/doc-x' );

		Orbitali_Sync_Manager::start_sync( $this->post_id );
		Orbitali_Sync_Manager::run_sync_job( $this->post_id, $this->agent_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( Orbitali_Post_Meta::STATUS_FAILED, $mapping['status'] );
		$this->assertNull( $mapping['document_id'] );
	}

	public function test_duplicate_click_while_a_sync_is_in_progress_does_not_start_a_second_flow() {
		Orbitali_Post_Meta::acquire_lock( $this->post_id, $this->agent_id );

		$result = Orbitali_Sync_Manager::start_sync( $this->post_id );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
	}

	public function test_switching_destination_agent_keeps_mappings_isolated() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			'agent-a',
			array(
				'document_id'  => 'doc-a',
				'content_hash' => $this->current_hash( $this->post_id ),
				'status'       => Orbitali_Post_Meta::STATUS_SYNCED,
			)
		);

		Orbitali_Settings::set_selected_agent( 'agent-b', 'Second Agent', 'draft' );

		Orbitali_Http_Mock::enqueue_json( 201, array( 'id' => 'doc-b' ), '/knowledge' );
		Orbitali_Http_Mock::enqueue_json( 200, array( array( 'id' => 'doc-b', 'status' => 'ready' ) ), '/knowledge' );

		Orbitali_Sync_Manager::start_sync( $this->post_id );
		Orbitali_Sync_Manager::run_sync_job( $this->post_id, 'agent-b' );

		$mapping_a = Orbitali_Post_Meta::get( $this->post_id, 'agent-a' );
		$mapping_b = Orbitali_Post_Meta::get( $this->post_id, 'agent-b' );

		$this->assertSame( 'doc-a', $mapping_a['document_id'] );
		$this->assertSame( 'doc-b', $mapping_b['document_id'] );
	}

	public function test_remove_deletes_the_mapped_document_and_resets_status() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array( 'document_id' => 'doc-1', 'status' => Orbitali_Post_Meta::STATUS_SYNCED )
		);
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-1' ), '/knowledge/doc-1' );

		$result = Orbitali_Sync_Manager::remove( $this->post_id );

		$this->assertTrue( $result['ok'] );
		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertNull( $mapping['document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_NOT_SYNCED, $mapping['status'] );
	}

	public function test_remove_never_claims_success_when_the_delete_call_fails() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array( 'document_id' => 'doc-1', 'status' => Orbitali_Post_Meta::STATUS_SYNCED )
		);
		Orbitali_Http_Mock::enqueue_json( 500, array( 'error' => 'Orbitali unavailable' ), '/knowledge/doc-1' );

		$result = Orbitali_Sync_Manager::remove( $this->post_id );

		$this->assertFalse( $result['ok'] );
		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( 'doc-1', $mapping['document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_WITHDRAWAL_FAILED, $mapping['status'] );
	}
}
