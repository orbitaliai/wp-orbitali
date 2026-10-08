/* global jQuery, OrbitaliSettings */
( function ( $ ) {
	'use strict';

	function showNotice( message, isError ) {
		var $notice = $( '#orbitali-notice' );
		$notice
			.removeClass( 'notice-error notice-success' )
			.addClass( isError ? 'notice-error' : 'notice-success' )
			.text( message )
			.prop( 'hidden', false );
	}

	function call( action, data, onSuccess ) {
		data = data || {};
		data.action = action;
		data.nonce = OrbitaliSettings.nonce;

		$.post( OrbitaliSettings.ajaxUrl, data )
			.done( function ( response ) {
				if ( response && response.success ) {
					onSuccess( response.data || {} );
				} else {
					showNotice( ( response && response.data && response.data.message ) || 'Request failed.', true );
				}
			} )
			.fail( function () {
				showNotice( 'Network error while contacting WordPress.', true );
			} );
	}

	$( function () {
		$( '#orbitali-connect' ).on( 'click', function () {
			var apiKey = $( '#orbitali-api-key' ).val();
			if ( ! apiKey ) {
				showNotice( 'Enter an API key.', true );
				return;
			}
			$( this ).prop( 'disabled', true ).text( OrbitaliSettings.i18n.connecting );
			call( 'orbitali_connect', { api_key: apiKey }, function () {
				window.location.reload();
			} );
		} );

		$( '#orbitali-change-key' ).on( 'click', function () {
			$( '#orbitali-key-form' ).prop( 'hidden', false );
			$( this ).closest( 'p' ).hide();
		} );

		$( '#orbitali-disconnect' ).on( 'click', function () {
			if ( ! window.confirm( OrbitaliSettings.i18n.confirmDisconnect ) ) {
				return;
			}
			call( 'orbitali_disconnect', {}, function () {
				window.location.reload();
			} );
		} );

		$( '#orbitali-toggle-create' ).on( 'click', function () {
			$( '#orbitali-create-agent-form' ).prop( 'hidden', function ( _, hidden ) {
				return ! hidden;
			} );
		} );

		$( '#orbitali-use-agent' ).on( 'click', function () {
			var agentId = $( 'input[name="orbitali_agent"]:checked' ).val();
			if ( ! agentId ) {
				showNotice( 'Choose an agent first.', true );
				return;
			}
			call( 'orbitali_select_agent', { agent_id: agentId }, function () {
				window.location.reload();
			} );
		} );

		$( '#orbitali-create-agent' ).on( 'click', function () {
			var payload = {
				name: $( '#orbitali-new-name' ).val(),
				language: $( '#orbitali-new-language' ).val(),
				voice_name: $( '#orbitali-new-voice' ).val(),
				instructions: $( '#orbitali-new-instructions' ).val(),
			};
			if ( ! payload.name ) {
				showNotice( 'Name is required.', true );
				return;
			}
			$( this ).prop( 'disabled', true );
			call( 'orbitali_create_agent', payload, function () {
				window.location.reload();
			} );
		} );
	} );
} )( jQuery );
