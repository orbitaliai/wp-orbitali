<?php
/**
 * Covers deterministic document naming: hierarchical slug paths, the
 * always-present post-ID suffix that makes name collisions structurally
 * impossible, the empty-slug fallback, and hash stability.
 *
 * @package Orbitali
 */

class Test_Orbitali_Document_Naming extends WP_UnitTestCase {

	public function test_hierarchical_pages_use_the_full_slug_path() {
		$parent = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'about' ) );
		$child  = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => $parent ) );

		$name = Orbitali_Document_Naming::build_document_name( get_post( $child ) );

		$this->assertStringStartsWith( 'about/team', $name );
	}

	public function test_two_different_posts_with_the_same_slug_never_collide() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'launch' ) );
		$post = self::factory()->post->create( array( 'post_type' => 'post', 'post_name' => 'launch' ) );

		$page_name = Orbitali_Document_Naming::build_document_name( get_post( $page ) );
		$post_name = Orbitali_Document_Naming::build_document_name( get_post( $post ) );

		$this->assertNotSame( $page_name, $post_name );
		$this->assertStringContainsString( '(wp-' . $page . ')', $page_name );
		$this->assertStringContainsString( '(wp-' . $post . ')', $post_name );
	}

	public function test_empty_slug_falls_back_to_a_deterministic_name() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => '' ) );

		$name = Orbitali_Document_Naming::build_document_name( get_post( $post_id ) );

		$this->assertStringStartsWith( 'untitled', $name );
		$this->assertStringContainsString( '(wp-' . $post_id . ')', $name );
	}

	public function test_name_is_never_longer_than_the_api_limit() {
		$post_id = self::factory()->post->create(
			array( 'post_type' => 'page', 'post_name' => str_repeat( 'a-very-long-slug-segment-', 10 ) )
		);

		$name = Orbitali_Document_Naming::build_document_name( get_post( $post_id ) );

		$this->assertLessThanOrEqual( Orbitali_Document_Naming::MAX_NAME_LENGTH, strlen( $name ) );
		$this->assertStringContainsString( '(wp-' . $post_id . ')', $name );
	}

	public function test_hash_is_stable_for_identical_inputs_and_changes_when_name_changes() {
		$hash1 = Orbitali_Document_Naming::compute_content_hash( 'about (wp-1)', 'About', 'https://example.test/about', 'Body text' );
		$hash2 = Orbitali_Document_Naming::compute_content_hash( 'about (wp-1)', 'About', 'https://example.test/about', 'Body text' );
		$hash3 = Orbitali_Document_Naming::compute_content_hash( 'about-us (wp-1)', 'About', 'https://example.test/about', 'Body text' );

		$this->assertSame( $hash1, $hash2 );
		$this->assertNotSame( $hash1, $hash3 );
	}
}
