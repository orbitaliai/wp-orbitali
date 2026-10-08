<?php
/**
 * Per-post, per-agent sync mapping storage.
 *
 * A post can have been synced to more than one agent over its lifetime
 * (the destination agent is a global setting that can change). Mappings are
 * keyed by Orbitali agent id so switching the destination, or switching
 * credentials entirely, never overwrites or loses a previous mapping.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Post_Meta {

	const META_KEY = '_orbitali_mappings';

	const STATUS_NOT_SYNCED        = 'not_synced';
	const STATUS_CHANGES_PENDING   = 'changes_pending';
	const STATUS_QUEUED            = 'queued';
	const STATUS_PROCESSING        = 'processing';
	const STATUS_SYNCED            = 'synced';
	const STATUS_FAILED            = 'failed';
	const STATUS_WITHDRAWAL_FAILED = 'withdrawal_failed';

	public static function default_mapping() {
		return array(
			'document_id'         => null,
			'document_name'       => null,
			'content_hash'        => null,
			'pending_hash'        => null,
			'pending_document_id' => null,
			'stale_document_id'   => null,
			'status'              => self::STATUS_NOT_SYNCED,
			'error'               => null,
			'last_synced_at'      => null,
			'last_attempt_at'     => null,
		);
	}

	/**
	 * @return array<string,array> keyed by agent id
	 */
	public static function get_all( $post_id ) {
		$value = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $value ) ? $value : array();
	}

	public static function get( $post_id, $agent_id ) {
		$all = self::get_all( $post_id );
		if ( isset( $all[ $agent_id ] ) && is_array( $all[ $agent_id ] ) ) {
			return array_merge( self::default_mapping(), $all[ $agent_id ] );
		}
		return self::default_mapping();
	}

	public static function set( $post_id, $agent_id, array $mapping ) {
		$all              = self::get_all( $post_id );
		$all[ $agent_id ]  = array_merge( self::default_mapping(), self::get( $post_id, $agent_id ), $mapping );
		update_post_meta( $post_id, self::META_KEY, $all );
		return $all[ $agent_id ];
	}

	/**
	 * Agent ids this post currently has (or had) a tracked mapping for.
	 *
	 * @return string[]
	 */
	public static function agent_ids_with_mapping( $post_id ) {
		return array_keys( self::get_all( $post_id ) );
	}

	/**
	 * Agent ids with a live (non-null) remote document for this post -
	 * i.e. the set that withdrawal must attempt to clean up.
	 *
	 * @return string[]
	 */
	public static function agent_ids_with_live_document( $post_id ) {
		$live = array();
		foreach ( self::get_all( $post_id ) as $agent_id => $mapping ) {
			if ( ! empty( $mapping['document_id'] ) || ! empty( $mapping['pending_document_id'] ) || ! empty( $mapping['stale_document_id'] ) ) {
				$live[] = $agent_id;
			}
		}
		return $live;
	}

	private static function lock_key( $post_id, $agent_id ) {
		return 'orbitali_lock_' . $post_id . '_' . md5( $agent_id );
	}

	/**
	 * Best-effort mutual exclusion against duplicate clicks, repeated
	 * submits, or overlapping retries for the same post+agent pair.
	 */
	public static function acquire_lock( $post_id, $agent_id, $ttl_seconds = 300 ) {
		$key = self::lock_key( $post_id, $agent_id );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), $ttl_seconds );
		return true;
	}

	public static function release_lock( $post_id, $agent_id ) {
		delete_transient( self::lock_key( $post_id, $agent_id ) );
	}

	public static function is_locked( $post_id, $agent_id ) {
		return false !== get_transient( self::lock_key( $post_id, $agent_id ) );
	}
}
