<?php
/**
 * Orchestrates sync, replacement, cleanup-retry and withdrawal.
 *
 * The Orbitali backend's public API only supports creating and deleting
 * knowledge documents - there is no update/upsert endpoint. Every content
 * or slug change is therefore handled as: upload the new version, verify it
 * is ready, then remove the previous mapped version. Between those two
 * steps the agent can legitimately answer from both the old and the new
 * document; this plugin does not claim an atomic replacement because the
 * backend does not provide one.
 *
 * Uploading runs the backend's synchronous embedding pipeline and can take
 * a long time for large documents, so it always runs out of band via
 * WP-Cron rather than inside the request that the editor is waiting on.
 * Deletes are cheap and run synchronously.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Sync_Manager {

	const CRON_HOOK      = 'orbitali_run_sync';
	const LOCK_TTL        = 600;
	const MAX_VERIFY_TRIES = 20;
	const DUPLICATE_GUARD_WINDOW = 600;

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sync_job' ), 10, 2 );
	}

	/**
	 * @return array{eligible:bool,reason:string}
	 */
	public static function check_eligibility( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'eligible' => false, 'reason' => __( 'Content not found.', 'orbitali' ) );
		}

		if ( ! in_array( $post->post_type, Orbitali_Settings::allowed_post_types(), true ) ) {
			return array( 'eligible' => false, 'reason' => __( 'This content type is not supported.', 'orbitali' ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return array( 'eligible' => false, 'reason' => __( 'Only published content can be synced.', 'orbitali' ) );
		}

		if ( '' !== $post->post_password ) {
			return array( 'eligible' => false, 'reason' => __( 'Password-protected content cannot be synced.', 'orbitali' ) );
		}

		if ( ! is_post_publicly_viewable( $post ) ) {
			return array( 'eligible' => false, 'reason' => __( 'This content is not publicly accessible.', 'orbitali' ) );
		}

		return array( 'eligible' => true, 'reason' => '' );
	}

	public static function preview( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'ok' => false, 'error' => __( 'Content not found.', 'orbitali' ) );
		}

		$extraction = Orbitali_Content_Extractor::extract( $post );

		return array(
			'ok'       => true,
			'markdown' => $extraction['markdown'],
			'empty'    => $extraction['empty'],
			'warnings' => $extraction['warnings'],
			'name'     => Orbitali_Document_Naming::build_document_name( $post ),
		);
	}

	/**
	 * Full status view for the currently selected destination agent.
	 */
	public static function get_status( $post_id ) {
		$configured = Orbitali_Settings::is_connection_ok() && Orbitali_Settings::has_selected_agent();
		$eligibility = self::check_eligibility( $post_id );

		$result = array(
			'configured'   => $configured,
			'agent_id'     => Orbitali_Settings::get_agent_id(),
			'agent_name'   => Orbitali_Settings::get_agent_name(),
			'agent_status' => Orbitali_Settings::get_agent_status(),
			'eligible'     => $eligibility['eligible'],
			'reason'       => $eligibility['reason'],
			'mapping'      => null,
			'has_changes'  => false,
			'locked'       => false,
		);

		if ( ! $configured ) {
			return $result;
		}

		$agent_id = Orbitali_Settings::get_agent_id();
		$mapping  = Orbitali_Post_Meta::get( $post_id, $agent_id );
		// The source of truth for "a sync is actually in flight" is the
		// lock, not the persisted status string: if the background job
		// never ran (e.g. WP-Cron didn't fire) the lock still expires on
		// its own, but a status of "processing" would otherwise stay
		// stuck forever with no way for the UI to recover.
		$result['locked'] = Orbitali_Post_Meta::is_locked( $post_id, $agent_id );

		if ( $eligibility['eligible'] && in_array( $mapping['status'], array( Orbitali_Post_Meta::STATUS_SYNCED ), true ) ) {
			$post   = get_post( $post_id );
			$name   = Orbitali_Document_Naming::build_document_name( $post );
			$title  = get_the_title( $post );
			$url    = Orbitali_Document_Naming::build_canonical_url( $post );
			$body   = Orbitali_Content_Extractor::extract( $post )['body_markdown'];
			$hash   = Orbitali_Document_Naming::compute_content_hash( $name, $title, $url, $body );
			$result['has_changes'] = ( $hash !== $mapping['content_hash'] );
		}

		$result['mapping'] = $mapping;
		return $result;
	}

	/**
	 * Entry point for the "Sync to Orbitali" button. Validates, dedupes
	 * no-op syncs, retries any pending cleanup from a previous run, then
	 * queues the actual upload/replace work in the background.
	 */
	public static function start_sync( $post_id ) {
		if ( ! Orbitali_Settings::is_connection_ok() || ! Orbitali_Settings::has_selected_agent() ) {
			return array( 'ok' => false, 'error' => __( 'Connect Orbitali and select a destination agent first.', 'orbitali' ) );
		}

		$eligibility = self::check_eligibility( $post_id );
		if ( ! $eligibility['eligible'] ) {
			return array( 'ok' => false, 'error' => $eligibility['reason'] );
		}

		$agent_id = Orbitali_Settings::get_agent_id();

		if ( Orbitali_Post_Meta::is_locked( $post_id, $agent_id ) ) {
			return array(
				'ok'     => true,
				'status' => Orbitali_Post_Meta::get( $post_id, $agent_id )['status'],
				'message' => __( 'A sync is already in progress for this content.', 'orbitali' ),
			);
		}

		$post = get_post( $post_id );
		$name = Orbitali_Document_Naming::build_document_name( $post );

		$extraction = Orbitali_Content_Extractor::extract( $post );
		if ( $extraction['empty'] ) {
			return array( 'ok' => false, 'error' => __( 'Nothing to sync: no readable content was extracted from this page.', 'orbitali' ) );
		}

		$title = get_the_title( $post );
		$url   = Orbitali_Document_Naming::build_canonical_url( $post );
		$hash  = Orbitali_Document_Naming::compute_content_hash( $name, $title, $url, $extraction['body_markdown'] );

		$mapping = Orbitali_Post_Meta::get( $post_id, $agent_id );

		if ( ! Orbitali_Post_Meta::acquire_lock( $post_id, $agent_id, self::LOCK_TTL ) ) {
			return array(
				'ok'      => true,
				'status'  => $mapping['status'],
				'message' => __( 'A sync is already in progress for this content.', 'orbitali' ),
			);
		}

		// Opportunistically retry a cleanup left over from a prior replace
		// whose old-document delete failed. This is a fast call, safe to run
		// inline before deciding whether new content even needs uploading.
		if ( ! empty( $mapping['stale_document_id'] ) ) {
			$mapping = self::try_cleanup_stale( $post_id, $agent_id, $mapping );
		}

		if ( $hash === $mapping['content_hash'] && ! empty( $mapping['document_id'] ) && Orbitali_Post_Meta::STATUS_SYNCED === $mapping['status'] ) {
			Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
			return array( 'ok' => true, 'status' => Orbitali_Post_Meta::STATUS_SYNCED, 'message' => __( 'Already up to date.', 'orbitali' ) );
		}

		Orbitali_Post_Meta::set(
			$post_id,
			$agent_id,
			array(
				'status'          => Orbitali_Post_Meta::STATUS_QUEUED,
				'pending_hash'    => $hash,
				'document_name'   => $name,
				'error'           => null,
				'last_attempt_at' => time(),
			)
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id, $agent_id ) ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $post_id, $agent_id ) );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return array( 'ok' => true, 'status' => Orbitali_Post_Meta::STATUS_QUEUED );
	}

	/**
	 * WP-Cron callback. Runs in its own request, outside any admin page
	 * load, so it is free to take as long as the upload actually needs.
	 */
	public static function run_sync_job( $post_id, $agent_id ) {
		$mapping = Orbitali_Post_Meta::get( $post_id, $agent_id );

		if ( ! Orbitali_Post_Meta::is_locked( $post_id, $agent_id ) ) {
			// Lock expired or was never held (e.g. manual wp_cron trigger); reacquire defensively.
			Orbitali_Post_Meta::acquire_lock( $post_id, $agent_id, self::LOCK_TTL );
		}

		$post = get_post( $post_id );
		$eligibility = self::check_eligibility( $post_id );
		if ( ! $post || ! $eligibility['eligible'] ) {
			self::fail( $post_id, $agent_id, __( 'Content is no longer public; sync was cancelled.', 'orbitali' ) );
			return;
		}

		$name  = Orbitali_Document_Naming::build_document_name( $post );
		$title = get_the_title( $post );
		$url   = Orbitali_Document_Naming::build_canonical_url( $post );

		$extraction = Orbitali_Content_Extractor::extract( $post );
		if ( $extraction['empty'] ) {
			self::fail( $post_id, $agent_id, __( 'Nothing to sync: no readable content was extracted from this page.', 'orbitali' ) );
			return;
		}

		$hash = Orbitali_Document_Naming::compute_content_hash( $name, $title, $url, $extraction['body_markdown'] );

		if ( $hash === $mapping['content_hash'] && ! empty( $mapping['document_id'] ) ) {
			Orbitali_Post_Meta::set( $post_id, $agent_id, array( 'status' => Orbitali_Post_Meta::STATUS_SYNCED, 'error' => null ) );
			Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
			return;
		}

		Orbitali_Post_Meta::set( $post_id, $agent_id, array( 'status' => Orbitali_Post_Meta::STATUS_PROCESSING ) );

		$api = new Orbitali_Api_Client();
		$pending_id = $mapping['pending_document_id'];

		if ( empty( $pending_id ) ) {
			$pending_id = self::find_recent_duplicate( $api, $agent_id, $name, $post_id );
		}

		if ( empty( $pending_id ) ) {
			$created = $api->create_knowledge_document( $agent_id, $name, null, $extraction['markdown'] );
			if ( ! $created['ok'] ) {
				self::fail( $post_id, $agent_id, $created['error'] );
				return;
			}
			$pending_id = isset( $created['data']['id'] ) ? $created['data']['id'] : null;
			if ( empty( $pending_id ) ) {
				self::fail( $post_id, $agent_id, __( 'Orbitali did not return a document id for the upload.', 'orbitali' ) );
				return;
			}
		}

		// Persist immediately: if anything below fails or the process is
		// killed, a retry must know this document already exists remotely
		// instead of uploading a second copy.
		Orbitali_Post_Meta::set( $post_id, $agent_id, array( 'pending_document_id' => $pending_id ) );

		$ready = self::verify_ready( $api, $agent_id, $pending_id, $post_id );
		if ( 'retry' === $ready['state'] ) {
			$attempts = (int) get_post_meta( $post_id, '_orbitali_verify_attempts_' . $agent_id, true ) + 1;
			update_post_meta( $post_id, '_orbitali_verify_attempts_' . $agent_id, $attempts );
			if ( $attempts >= self::MAX_VERIFY_TRIES ) {
				self::fail( $post_id, $agent_id, __( 'Indexing is taking longer than expected. Please try again later.', 'orbitali' ) );
				return;
			}
			wp_schedule_single_event( time() + 15, self::CRON_HOOK, array( $post_id, $agent_id ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
			return; // keep the lock held across the retry.
		}

		delete_post_meta( $post_id, '_orbitali_verify_attempts_' . $agent_id );

		if ( 'failed' === $ready['state'] ) {
			$api->delete_knowledge_document( $agent_id, $pending_id );
			Orbitali_Post_Meta::set( $post_id, $agent_id, array( 'pending_document_id' => null ) );
			self::fail( $post_id, $agent_id, $ready['error'] );
			return;
		}

		// $ready['state'] === 'ready' - commit the new document, then clean
		// up the previous one. The new content is authoritative from here.
		$old_document_id = $mapping['document_id'];
		$commit = array(
			'document_id'         => $pending_id,
			'pending_document_id' => null,
			'document_name'       => $name,
			'content_hash'        => $hash,
			'pending_hash'        => null,
			'status'              => Orbitali_Post_Meta::STATUS_SYNCED,
			'error'               => null,
			'last_synced_at'      => time(),
		);

		if ( empty( $old_document_id ) || $old_document_id === $pending_id ) {
			Orbitali_Post_Meta::set( $post_id, $agent_id, $commit );
			Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
			return;
		}

		$deleted = $api->delete_knowledge_document( $agent_id, $old_document_id );
		if ( $deleted['ok'] || 404 === $deleted['status'] ) {
			$commit['stale_document_id'] = null;
			Orbitali_Post_Meta::set( $post_id, $agent_id, $commit );
		} else {
			$commit['stale_document_id'] = $old_document_id;
			$commit['error'] = sprintf(
				/* translators: %s: error message from Orbitali */
				__( 'Synced, but removing the previous version failed and will be retried: %s', 'orbitali' ),
				$deleted['error']
			);
			Orbitali_Post_Meta::set( $post_id, $agent_id, $commit );
		}

		Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
	}

	private static function find_recent_duplicate( Orbitali_Api_Client $api, $agent_id, $name, $post_id ) {
		$list = $api->list_knowledge_documents( $agent_id );
		if ( ! $list['ok'] || ! is_array( $list['data'] ) ) {
			return null;
		}

		foreach ( $list['data'] as $doc ) {
			if ( ! isset( $doc['name'], $doc['createdAt'] ) || $doc['name'] !== $name ) {
				continue;
			}
			$created_ts = strtotime( $doc['createdAt'] );
			if ( $created_ts && ( time() - $created_ts ) <= self::DUPLICATE_GUARD_WINDOW ) {
				return $doc['id'];
			}
		}

		return null;
	}

	/**
	 * @return array{state:string,error?:string}
	 */
	private static function verify_ready( Orbitali_Api_Client $api, $agent_id, $document_id, $post_id ) {
		$list = $api->list_knowledge_documents( $agent_id );
		if ( ! $list['ok'] || ! is_array( $list['data'] ) ) {
			return array( 'state' => 'retry' );
		}

		foreach ( $list['data'] as $doc ) {
			if ( ! isset( $doc['id'] ) || $doc['id'] !== $document_id ) {
				continue;
			}
			if ( 'ready' === $doc['status'] ) {
				return array( 'state' => 'ready' );
			}
			if ( 'failed' === $doc['status'] ) {
				return array(
					'state' => 'failed',
					'error' => ! empty( $doc['error'] ) ? $doc['error'] : __( 'Indexing failed on Orbitali.', 'orbitali' ),
				);
			}
			return array( 'state' => 'retry' );
		}

		// Not found at all - treat as unverifiable rather than retrying forever.
		return array( 'state' => 'failed', 'error' => __( 'The uploaded document could not be found for verification.', 'orbitali' ) );
	}

	private static function try_cleanup_stale( $post_id, $agent_id, array $mapping ) {
		$api = new Orbitali_Api_Client();
		$deleted = $api->delete_knowledge_document( $agent_id, $mapping['stale_document_id'] );
		if ( $deleted['ok'] || 404 === $deleted['status'] ) {
			$mapping = Orbitali_Post_Meta::set( $post_id, $agent_id, array( 'stale_document_id' => null, 'error' => null ) );
		}
		return $mapping;
	}

	private static function fail( $post_id, $agent_id, $error ) {
		Orbitali_Post_Meta::set(
			$post_id,
			$agent_id,
			array(
				'status' => Orbitali_Post_Meta::STATUS_FAILED,
				'error'  => $error,
			)
		);
		Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
	}

	/**
	 * "Remove from Orbitali" - deliberate manual withdrawal for the
	 * currently selected agent, independent of publish-status hooks.
	 */
	public static function remove( $post_id ) {
		if ( ! Orbitali_Settings::has_selected_agent() ) {
			return array( 'ok' => false, 'error' => __( 'No destination agent is selected.', 'orbitali' ) );
		}
		return self::withdraw_from_agent( $post_id, Orbitali_Settings::get_agent_id() );
	}

	/**
	 * Withdrawal across every agent this post has ever been synced to.
	 * Used by the trash/delete/unpublish/password-protect hooks.
	 *
	 * @return array<string,array> keyed by agent id
	 */
	public static function withdraw_all( $post_id ) {
		$results = array();
		foreach ( Orbitali_Post_Meta::agent_ids_with_live_document( $post_id ) as $agent_id ) {
			$results[ $agent_id ] = self::withdraw_from_agent( $post_id, $agent_id );
		}
		return $results;
	}

	private static function withdraw_from_agent( $post_id, $agent_id ) {
		$mapping = Orbitali_Post_Meta::get( $post_id, $agent_id );

		$ids = array_filter( array( $mapping['document_id'], $mapping['pending_document_id'], $mapping['stale_document_id'] ) );
		if ( empty( $ids ) ) {
			return array( 'ok' => true, 'status' => Orbitali_Post_Meta::STATUS_NOT_SYNCED );
		}

		if ( ! Orbitali_Post_Meta::acquire_lock( $post_id, $agent_id, self::LOCK_TTL ) ) {
			return array( 'ok' => false, 'error' => __( 'A sync is in progress; try removing again shortly.', 'orbitali' ) );
		}

		$api        = new Orbitali_Api_Client();
		$remaining  = array();
		$last_error = null;

		foreach ( array_unique( $ids ) as $document_id ) {
			$deleted = $api->delete_knowledge_document( $agent_id, $document_id );
			if ( ! $deleted['ok'] && 404 !== $deleted['status'] ) {
				$remaining[] = $document_id;
				$last_error  = $deleted['error'];
			}
		}

		if ( empty( $remaining ) ) {
			Orbitali_Post_Meta::set(
				$post_id,
				$agent_id,
				array(
					'document_id'         => null,
					'pending_document_id' => null,
					'stale_document_id'   => null,
					'content_hash'        => null,
					'pending_hash'        => null,
					'status'              => Orbitali_Post_Meta::STATUS_NOT_SYNCED,
					'error'               => null,
				)
			);
			Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
			return array( 'ok' => true, 'status' => Orbitali_Post_Meta::STATUS_NOT_SYNCED );
		}

		Orbitali_Post_Meta::set(
			$post_id,
			$agent_id,
			array(
				'document_id'         => in_array( $mapping['document_id'], $remaining, true ) ? $mapping['document_id'] : null,
				'pending_document_id' => in_array( $mapping['pending_document_id'], $remaining, true ) ? $mapping['pending_document_id'] : null,
				'stale_document_id'   => in_array( $mapping['stale_document_id'], $remaining, true ) ? $mapping['stale_document_id'] : null,
				'status'              => Orbitali_Post_Meta::STATUS_WITHDRAWAL_FAILED,
				'error'               => $last_error,
			)
		);
		Orbitali_Post_Meta::release_lock( $post_id, $agent_id );
		return array( 'ok' => false, 'error' => $last_error );
	}
}
