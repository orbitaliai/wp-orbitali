/**
 * Shared editor logic for the block editor panel and the classic editor
 * meta box. Both load this before their own script and talk to the same
 * REST endpoints via wp.apiFetch (nonce handling is wired up automatically
 * by WordPress core whenever wp-api-fetch is enqueued for a logged-in user).
 */
/* global wp */
window.OrbitaliEditor = ( function () {
	'use strict';

	var TERMINAL_STATUSES = [ 'synced', 'failed', 'not_synced', 'withdrawal_failed', 'changes_pending' ];
	var POLL_INTERVAL_MS = 3000;
	// Comfortably longer than the server's lock TTL (10 minutes) so that if
	// the background job never actually runs, polling is still live when the
	// lock expires and the UI can unlock itself without a page reload.
	var POLL_MAX_ATTEMPTS = 240; // ~12 minutes

	function fetchStatus( postId ) {
		return wp.apiFetch( { path: '/orbitali/v1/status/' + postId, method: 'GET' } );
	}

	function preview( postId ) {
		return wp.apiFetch( { path: '/orbitali/v1/preview', method: 'POST', data: { post_id: postId } } );
	}

	function sync( postId ) {
		return wp.apiFetch( { path: '/orbitali/v1/sync', method: 'POST', data: { post_id: postId } } );
	}

	function remove( postId ) {
		return wp.apiFetch( { path: '/orbitali/v1/remove', method: 'POST', data: { post_id: postId } } );
	}

	/**
	 * Polls status until it reaches a terminal state, a cap is hit, or the
	 * caller cancels. Returns a cancel function.
	 */
	function pollUntilSettled( postId, onUpdate ) {
		var attempts = 0;
		var cancelled = false;

		function tick() {
			if ( cancelled ) {
				return;
			}
			attempts++;
			fetchStatus( postId ).then( function ( status ) {
				if ( cancelled ) {
					return;
				}
				onUpdate( status );
				var mappingStatus = status.mapping ? status.mapping.status : 'not_synced';
				var settled = TERMINAL_STATUSES.indexOf( mappingStatus ) !== -1;
				// Also stop once the lock clears, even if the status string
				// never reached a terminal value - that happens when the
				// background job never actually ran and the lock simply
				// expired, which the UI needs to recover from either way.
				var unlocked = false === status.locked;
				if ( settled || unlocked || attempts >= POLL_MAX_ATTEMPTS ) {
					return;
				}
				window.setTimeout( tick, POLL_INTERVAL_MS );
			} );
		}

		tick();

		return function cancel() {
			cancelled = true;
		};
	}

	var STATUS_LABELS = {
		not_synced: 'Not synced',
		changes_pending: 'Changes pending',
		queued: 'Processing',
		processing: 'Processing',
		synced: 'Synced',
		failed: 'Failed',
		withdrawal_failed: 'Removal failed',
	};

	function statusLabel( mapping, hasChanges ) {
		if ( ! mapping ) {
			return STATUS_LABELS.not_synced;
		}
		if ( 'synced' === mapping.status && hasChanges ) {
			return STATUS_LABELS.changes_pending;
		}
		return STATUS_LABELS[ mapping.status ] || mapping.status;
	}

	return {
		fetchStatus: fetchStatus,
		preview: preview,
		sync: sync,
		remove: remove,
		pollUntilSettled: pollUntilSettled,
		statusLabel: statusLabel,
	};
} )();
