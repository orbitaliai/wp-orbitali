<?php
/**
 * Covers automatic withdrawal when a synced page is trashed, permanently
 * deleted, unpublished, or password-protected - including that failures
 * are surfaced and retryable rather than reported as success, and that
 * untracked content is never touched.
 *
 * @package Orbitali
 */

class Test_Orbitali_Withdrawal extends WP_UnitTestCase {

	private $post_id;
	private $agent_id = 'agent-a';
	private $admin_id;

	public function set_up() {
		parent::set_up();
		Orbitali_Http_Mock::reset();

		Orbitali_Settings::set_api_key( 'orb_sk_good' );
		Orbitali_Settings::set_connection_ok( true );
		Orbitali_Settings::set_selected_agent( $this->agent_id, 'Docs Agent', 'draft' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_content' => '<p>Hello</p>',
			)
		);
	}

	private function seed_synced_mapping() {
		Orbitali_Post_Meta::set(
			$this->post_id,
			$this->agent_id,
			array( 'document_id' => 'doc-1', 'status' => Orbitali_Post_Meta::STATUS_SYNCED )
		);
	}

	public function test_trashing_a_synced_post_withdraws_its_document() {
		$this->seed_synced_mapping();
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-1' ), '/knowledge/doc-1' );

		wp_trash_post( $this->post_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertNull( $mapping['document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_NOT_SYNCED, $mapping['status'] );
	}

	public function test_permanently_deleting_a_synced_post_withdraws_its_document() {
		$this->seed_synced_mapping();
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-1' ), '/knowledge/doc-1' );

		wp_delete_post( $this->post_id, true );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertNull( $mapping['document_id'] );
	}

	public function test_unpublishing_a_synced_post_withdraws_its_document() {
		$this->seed_synced_mapping();
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-1' ), '/knowledge/doc-1' );

		wp_update_post( array( 'ID' => $this->post_id, 'post_status' => 'draft' ) );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertNull( $mapping['document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_NOT_SYNCED, $mapping['status'] );
	}

	public function test_password_protecting_a_synced_post_withdraws_its_document() {
		$this->seed_synced_mapping();
		Orbitali_Http_Mock::enqueue_json( 200, array( 'id' => 'doc-1' ), '/knowledge/doc-1' );

		wp_update_post( array( 'ID' => $this->post_id, 'post_password' => 'secret' ) );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertNull( $mapping['document_id'] );
	}

	public function test_withdrawal_failure_is_retryable_and_never_reported_as_success() {
		$this->seed_synced_mapping();
		Orbitali_Http_Mock::enqueue_json( 500, array( 'error' => 'Orbitali unavailable' ), '/knowledge/doc-1' );

		wp_trash_post( $this->post_id );

		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( 'doc-1', $mapping['document_id'] );
		$this->assertSame( Orbitali_Post_Meta::STATUS_WITHDRAWAL_FAILED, $mapping['status'] );

		$notice = get_transient( 'orbitali_notice_' . $this->admin_id );
		$this->assertNotFalse( $notice );
	}

	public function test_trashing_untracked_content_never_contacts_orbitali() {
		wp_trash_post( $this->post_id );

		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
	}

	public function test_saving_a_still_published_post_does_not_withdraw_it() {
		$this->seed_synced_mapping();

		wp_update_post( array( 'ID' => $this->post_id, 'post_title' => 'Updated title' ) );

		$this->assertCount( 0, Orbitali_Http_Mock::requests() );
		$mapping = Orbitali_Post_Meta::get( $this->post_id, $this->agent_id );
		$this->assertSame( 'doc-1', $mapping['document_id'] );
	}
}
