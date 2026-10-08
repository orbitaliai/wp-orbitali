<?php
/**
 * Document naming and content hashing.
 *
 * The WordPress post ID + Orbitali org/agent/document ids are the real
 * identity (see Orbitali_Post_Meta); the "name" built here only exists to
 * make documents recognizable in the Orbitali dashboard. Every name carries
 * a post-id suffix so two different posts can never collide, even if they
 * share a slug across post types or a slug is empty.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Document_Naming {

	const MAX_NAME_LENGTH = 120;

	/**
	 * Full hierarchical slug path ("parent/child/grandchild") for
	 * hierarchical post types; plain slug for non-hierarchical ones.
	 */
	public static function build_slug_path( WP_Post $post ) {
		$segments = array();

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$ancestors = array_reverse( get_post_ancestors( $post ) );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );
				if ( $ancestor && '' !== $ancestor->post_name ) {
					$segments[] = $ancestor->post_name;
				}
			}
		}

		if ( '' !== $post->post_name ) {
			$segments[] = $post->post_name;
		}

		if ( empty( $segments ) ) {
			return '';
		}

		return implode( '/', $segments );
	}

	/**
	 * Human-readable, collision-proof document name sent to Orbitali.
	 */
	public static function build_document_name( WP_Post $post ) {
		$slug_path = self::build_slug_path( $post );
		if ( '' === $slug_path ) {
			$slug_path = 'untitled';
		}

		$suffix     = sprintf( ' (wp-%d)', $post->ID );
		$max_prefix = self::MAX_NAME_LENGTH - strlen( $suffix );

		if ( strlen( $slug_path ) > $max_prefix ) {
			// Keep the most specific (rightmost) part of the path when truncating.
			$slug_path = '…' . substr( $slug_path, -( $max_prefix - 1 ) );
		}

		return $slug_path . $suffix;
	}

	public static function build_canonical_url( WP_Post $post ) {
		return get_permalink( $post );
	}

	/**
	 * Hash of everything that is semantically part of the uploaded document,
	 * deliberately excluding the "last modified" timestamp line that gets
	 * embedded in the document body: WordPress bumps post_modified on every
	 * save, even trivial ones, so hashing it would mark content as changed
	 * on every resave regardless of whether anything meaningful changed.
	 */
	public static function compute_content_hash( $name, $title, $url, $body ) {
		return hash( 'sha256', wp_json_encode( array( $name, $title, $url, $body ) ) );
	}
}
