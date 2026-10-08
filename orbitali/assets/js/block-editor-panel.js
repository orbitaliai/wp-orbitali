/**
 * Block editor (Gutenberg) document-settings panel.
 * Vanilla wp.element usage - no JSX/build step needed for a plugin this size.
 */
/* global wp, OrbitaliEditor, OrbitaliEditorData */
( function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;

	function formatTimestamp( unixSeconds ) {
		if ( ! unixSeconds ) {
			return '';
		}
		return new Date( unixSeconds * 1000 ).toLocaleString();
	}

	function OrbitaliPanel() {
		var postId = wp.data.select( 'core/editor' ).getCurrentPostId();
		var isSavingPost = wp.data.useSelect(
			function ( select ) {
				return select( 'core/editor' ).isSavingPost() && ! select( 'core/editor' ).isAutosavingPost();
			},
			[]
		);

		var statusState = useState( null );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];

		var loadingState = useState( true );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];

		var busyState = useState( null ); // null | 'sync' | 'remove' | 'preview'
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var errorState = useState( null );
		var actionError = errorState[ 0 ];
		var setActionError = errorState[ 1 ];

		var previewState = useState( null );
		var previewData = previewState[ 0 ];
		var setPreviewData = previewState[ 1 ];

		function refresh() {
			if ( ! postId ) {
				setLoading( false );
				return;
			}
			OrbitaliEditor.fetchStatus( postId ).then(
				function ( data ) {
					setStatus( data );
					setLoading( false );
				},
				function () {
					setLoading( false );
				}
			);
		}

		useEffect(
			function () {
				if ( ! postId ) {
					return;
				}
				OrbitaliEditor.fetchStatus( postId ).then(
					function ( data ) {
						setStatus( data );
						setLoading( false );
						// A sync was already in flight when this panel
						// mounted (e.g. started from another tab, or this
						// is a reload while queued/processing) - resume
						// live updates instead of leaving it static until
						// the next manual action.
						if ( data.locked ) {
							OrbitaliEditor.pollUntilSettled( postId, function ( polled ) {
								setStatus( polled );
							} );
						}
					},
					function () {
						setLoading( false );
					}
				);
			},
			[ postId ]
		);

		var wasSaving = wp.element.useRef( false );
		useEffect(
			function () {
				if ( wasSaving.current && ! isSavingPost ) {
					refresh();
				}
				wasSaving.current = isSavingPost;
			},
			[ isSavingPost ]
		);

		if ( ! postId ) {
			return null;
		}

		if ( loading ) {
			return el( 'p', {}, __( 'Loading Orbitali status…', 'orbitali' ) );
		}

		if ( ! status || ! status.configured ) {
			return el(
				'div',
				{ className: 'orbitali-panel' },
				el(
					'p',
					{},
					__( 'Connect Orbitali and choose a destination agent to enable syncing.', 'orbitali' )
				),
				el(
					'a',
					{ href: OrbitaliEditorData.settingsUrl, className: 'button button-secondary' },
					__( 'Open Orbitali settings', 'orbitali' )
				)
			);
		}

		var mapping = status.mapping || {};
		var label = OrbitaliEditor.statusLabel( mapping, status.has_changes );
		// Gate on the lock, not the persisted status string: if a queued
		// job never actually ran (e.g. WP-Cron didn't fire), the status
		// would otherwise stay "Processing" forever with no way back.
		// The lock expires on its own even when nothing updates it.
		var canSync = status.eligible && ! status.locked;
		var canRemove = !! mapping.document_id || !! mapping.pending_document_id || !! mapping.stale_document_id;

		function doPreview() {
			setBusy( 'preview' );
			setActionError( null );
			OrbitaliEditor.preview( postId ).then(
				function ( data ) {
					setBusy( null );
					setPreviewData( data );
				},
				function ( err ) {
					setBusy( null );
					setActionError( ( err && err.message ) || __( 'Preview failed.', 'orbitali' ) );
				}
			);
		}

		function doSync() {
			setBusy( 'sync' );
			setActionError( null );
			OrbitaliEditor.sync( postId ).then(
				function ( result ) {
					if ( ! result.ok ) {
						setBusy( null );
						setActionError( result.error );
						return;
					}
					if ( 'queued' === result.status ) {
						OrbitaliEditor.pollUntilSettled( postId, function ( data ) {
							setStatus( data );
							var s = data.mapping ? data.mapping.status : null;
							if ( 'processing' !== s && 'queued' !== s ) {
								setBusy( null );
							}
						} );
					} else {
						setBusy( null );
						refresh();
					}
				},
				function ( err ) {
					setBusy( null );
					setActionError( ( err && err.message ) || __( 'Sync failed.', 'orbitali' ) );
				}
			);
		}

		function doRemove() {
			if ( ! window.confirm( __( 'Remove this content from Orbitali’s knowledge base?', 'orbitali' ) ) ) {
				return;
			}
			setBusy( 'remove' );
			setActionError( null );
			OrbitaliEditor.remove( postId ).then(
				function ( result ) {
					setBusy( null );
					if ( ! result.ok ) {
						setActionError( result.error );
					}
					refresh();
				},
				function ( err ) {
					setBusy( null );
					setActionError( ( err && err.message ) || __( 'Remove failed.', 'orbitali' ) );
				}
			);
		}

		return el(
			'div',
			{ className: 'orbitali-panel' },
			el(
				'p',
				{},
				__( 'Destination agent: ', 'orbitali' ),
				el( 'strong', {}, status.agent_name ),
				' (',
				status.agent_status,
				')'
			),
			el( 'p', { className: 'orbitali-status-row' }, __( 'Status: ', 'orbitali' ), el( 'strong', {}, label ) ),
			mapping.last_synced_at
				? el(
					'p',
					{},
					__( 'Last successful sync: ', 'orbitali' ),
					formatTimestamp( mapping.last_synced_at )
				)
				: null,
			! status.eligible
				? el( 'p', { className: 'orbitali-warning' }, status.reason )
				: null,
			mapping.error
				? el( 'p', { className: 'orbitali-error-text' }, mapping.error )
				: null,
			actionError ? el( 'p', { className: 'orbitali-error-text' }, actionError ) : null,
			el(
				'div',
				{ className: 'orbitali-actions' },
				el(
					'button',
					{ type: 'button', className: 'button', disabled: 'preview' === busy, onClick: doPreview },
					__( 'Preview text', 'orbitali' )
				),
				el(
					'button',
					{
						type: 'button',
						className: 'button button-primary',
						disabled: ! canSync || !! busy,
						onClick: doSync,
					},
					'sync' === busy || status.locked
						? __( 'Syncing…', 'orbitali' )
						: __( 'Sync to Orbitali', 'orbitali' )
				),
				el(
					'button',
					{ type: 'button', className: 'button-link-delete', disabled: ! canRemove || !! busy, onClick: doRemove },
					__( 'Remove from Orbitali', 'orbitali' )
				)
			),
			el(
				'p',
				{ className: 'description' },
				__(
					'Manual sync only: edits made after your last save or last Sync are not sent until you click Sync again.',
					'orbitali'
				)
			),
			previewData
				? el(
					'div',
					{},
					previewData.warnings && previewData.warnings.length
						? el(
							'p',
							{ className: 'orbitali-warning' },
							previewData.warnings.join( ' ' )
						)
						: null,
					el( 'p', {}, __( 'This reflects only the last saved version of this content.', 'orbitali' ) ),
					el( 'pre', { className: 'orbitali-preview' }, previewData.markdown )
				)
				: null
		);
	}

	wp.plugins.registerPlugin( 'orbitali-panel', {
		render: function () {
			return el(
				wp.editPost.PluginDocumentSettingPanel,
				{ name: 'orbitali-panel', title: __( 'Orbitali', 'orbitali' ), className: 'orbitali-document-panel' },
				el( OrbitaliPanel )
			);
		},
	} );
} )();
