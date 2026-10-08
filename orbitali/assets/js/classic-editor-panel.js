/**
 * Classic editor meta box - same behavior as the block editor panel,
 * built with plain DOM calls (no React dependency needed here since
 * the classic editor doesn't load the block editor packages that
 * provide it... except wp-element/wp-api-fetch, which we do load).
 */
/* global OrbitaliEditor, OrbitaliEditorData, document, window */
( function () {
	'use strict';

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'className' === key ) {
				node.className = attrs[ key ];
			} else if ( 'onClick' === key ) {
				node.addEventListener( 'click', attrs[ key ] );
			} else if ( 'disabled' === key ) {
				node.disabled = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function formatTimestamp( unixSeconds ) {
		return unixSeconds ? new Date( unixSeconds * 1000 ).toLocaleString() : '';
	}

	function render( container, postId, status, options ) {
		options = options || {};
		container.innerHTML = '';

		if ( ! status || ! status.configured ) {
			container.appendChild(
				el( 'p', { text: 'Connect Orbitali and choose a destination agent to enable syncing.' } )
			);
			container.appendChild(
				el( 'a', { className: 'button button-secondary', href: OrbitaliEditorData.settingsUrl, text: 'Open Orbitali settings' } )
			);
			return;
		}

		var mapping = status.mapping || {};
		var label = OrbitaliEditor.statusLabel( mapping, status.has_changes );
		var canSync = status.eligible && 'processing' !== mapping.status && 'queued' !== mapping.status;
		var canRemove = !! mapping.document_id || !! mapping.pending_document_id || !! mapping.stale_document_id;
		var busy = options.busy;

		container.appendChild(
			el( 'p', {}, [
				document.createTextNode( 'Destination agent: ' ),
				el( 'strong', { text: status.agent_name } ),
				document.createTextNode( ' (' + status.agent_status + ')' ),
			] )
		);
		container.appendChild(
			el( 'p', { className: 'orbitali-status-row' }, [
				document.createTextNode( 'Status: ' ),
				el( 'strong', { text: label } ),
			] )
		);
		if ( mapping.last_synced_at ) {
			container.appendChild( el( 'p', { text: 'Last successful sync: ' + formatTimestamp( mapping.last_synced_at ) } ) );
		}
		if ( ! status.eligible ) {
			container.appendChild( el( 'p', { className: 'orbitali-warning', text: status.reason } ) );
		}
		if ( mapping.error ) {
			container.appendChild( el( 'p', { className: 'orbitali-error-text', text: mapping.error } ) );
		}
		if ( options.actionError ) {
			container.appendChild( el( 'p', { className: 'orbitali-error-text', text: options.actionError } ) );
		}

		var actions = el( 'div', { className: 'orbitali-actions' } );
		actions.appendChild(
			el( 'button', {
				type: 'button',
				className: 'button',
				disabled: 'preview' === busy,
				text: 'Preview text',
				onClick: function () {
					setBusy( container, postId, 'preview' );
				},
			} )
		);
		actions.appendChild(
			el( 'button', {
				type: 'button',
				className: 'button button-primary',
				disabled: ! canSync || !! busy,
				text:
					'sync' === busy || 'processing' === mapping.status || 'queued' === mapping.status
						? 'Syncing…'
						: 'Sync to Orbitali',
				onClick: function () {
					runSync( container, postId );
				},
			} )
		);
		actions.appendChild(
			el( 'button', {
				type: 'button',
				className: 'button-link-delete',
				disabled: ! canRemove || !! busy,
				text: 'Remove from Orbitali',
				onClick: function () {
					if ( window.confirm( 'Remove this content from Orbitali’s knowledge base?' ) ) {
						runRemove( container, postId );
					}
				},
			} )
		);
		container.appendChild( actions );

		container.appendChild(
			el( 'p', {
				className: 'description',
				text: 'Manual sync only: edits made after your last save or last Sync are not sent until you click Sync again.',
			} )
		);

		if ( options.previewData ) {
			if ( options.previewData.warnings && options.previewData.warnings.length ) {
				container.appendChild(
					el( 'p', { className: 'orbitali-warning', text: options.previewData.warnings.join( ' ' ) } )
				);
			}
			container.appendChild( el( 'p', { text: 'This reflects only the last saved version of this content.' } ) );
			container.appendChild( el( 'pre', { className: 'orbitali-preview', text: options.previewData.markdown } ) );
		}
	}

	function refresh( container, postId, extraOptions ) {
		OrbitaliEditor.fetchStatus( postId ).then( function ( status ) {
			render( container, postId, status, extraOptions || {} );
		} );
	}

	function setBusy( container, postId, which ) {
		OrbitaliEditor.preview( postId ).then(
			function ( data ) {
				OrbitaliEditor.fetchStatus( postId ).then( function ( status ) {
					render( container, postId, status, { previewData: data } );
				} );
			},
			function ( err ) {
				OrbitaliEditor.fetchStatus( postId ).then( function ( status ) {
					render( container, postId, status, { actionError: ( err && err.message ) || 'Preview failed.' } );
				} );
			}
		);
	}

	function runSync( container, postId ) {
		refresh( container, postId, { busy: 'sync' } );
		OrbitaliEditor.sync( postId ).then(
			function ( result ) {
				if ( ! result.ok ) {
					refresh( container, postId, { actionError: result.error } );
					return;
				}
				if ( 'queued' === result.status ) {
					OrbitaliEditor.pollUntilSettled( postId, function ( status ) {
						render( container, postId, status, {} );
					} );
				} else {
					refresh( container, postId );
				}
			},
			function ( err ) {
				refresh( container, postId, { actionError: ( err && err.message ) || 'Sync failed.' } );
			}
		);
	}

	function runRemove( container, postId ) {
		refresh( container, postId, { busy: 'remove' } );
		OrbitaliEditor.remove( postId ).then(
			function ( result ) {
				refresh( container, postId, result.ok ? {} : { actionError: result.error } );
			},
			function ( err ) {
				refresh( container, postId, { actionError: ( err && err.message ) || 'Remove failed.' } );
			}
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var container = document.getElementById( 'orbitali-classic-panel' );
		if ( ! container ) {
			return;
		}
		var postId = parseInt( container.getAttribute( 'data-post-id' ), 10 );
		if ( ! postId ) {
			return;
		}
		refresh( container, postId );
	} );
} )();
