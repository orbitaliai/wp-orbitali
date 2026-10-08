<?php
/**
 * Thin server-side HTTP client for the Orbitali public API
 * (https://api.orbitali.ai/public/v1). Every call runs through
 * wp_remote_request from the WordPress server; the API key never reaches
 * the browser.
 *
 * @package Orbitali
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orbitali_Api_Client {

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	public function __construct( $api_key = null, $base_url = null ) {
		$this->api_key  = null === $api_key ? Orbitali_Settings::get_api_key() : $api_key;
		$this->base_url = null === $base_url ? Orbitali_Settings::get_base_url() : rtrim( $base_url, '/' );
	}

	public function list_agents() {
		return $this->request( 'GET', '/agents' );
	}

	public function get_agent( $agent_id ) {
		return $this->request( 'GET', '/agents/' . rawurlencode( $agent_id ) );
	}

	/**
	 * Creates a draft agent. $fields follows the backend's createAgentRequestSchema;
	 * this client fills in the static-agent defaults the plugin relies on.
	 */
	public function create_agent( array $fields ) {
		$defaults = array(
			'agentType'                     => 'static',
			'promptType'                    => 'static',
			'greetingType'                  => 'none',
			'staticGreeting'                => null,
			'outboundGreeting'              => null,
			'serverUrl'                     => null,
			'serverHeaders'                 => array(),
			'serverSecret'                  => null,
			'handoffPhoneNumber'            => null,
			'backgroundSound'               => 'none',
			'ambientSound'                  => 'none',
			'ambientSoundVolume'            => 0.25,
			'ambientSoundDucking'           => true,
			'phoneNumberAssignments'        => array(),
		);

		$body = array_merge( $defaults, $fields );

		return $this->request( 'POST', '/agents', $body, 15, 201 );
	}

	public function list_knowledge_documents( $agent_id ) {
		return $this->request( 'GET', '/agents/' . rawurlencode( $agent_id ) . '/knowledge' );
	}

	/**
	 * Creates a knowledge document from text/markdown content. Runs on the
	 * backend's synchronous ingestion+embedding pipeline, so this call can
	 * legitimately take a long time for large documents; callers must invoke
	 * it from a background context (see Orbitali_Sync_Manager), never
	 * directly from an editor request.
	 */
	public function create_knowledge_document( $agent_id, $name, $description, $content ) {
		$body = array( 'content' => $content );
		if ( null !== $name && '' !== $name ) {
			$body['name'] = $name;
		}
		if ( null !== $description && '' !== $description ) {
			$body['description'] = $description;
		}

		return $this->request( 'POST', '/agents/' . rawurlencode( $agent_id ) . '/knowledge', $body, 120, 201 );
	}

	public function delete_knowledge_document( $agent_id, $document_id ) {
		return $this->request(
			'DELETE',
			'/agents/' . rawurlencode( $agent_id ) . '/knowledge/' . rawurlencode( $document_id )
		);
	}

	/**
	 * @return array{ok:bool,status:int,data:array|null,error:string|null,code:string|null}
	 */
	private function request( $method, $path, $body = null, $timeout = 15, $expected_status = 200 ) {
		if ( '' === trim( (string) $this->api_key ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => null,
				'error'  => __( 'No Orbitali API key configured.', 'orbitali' ),
				'code'   => 'missing_api_key',
			);
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                     = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => null,
				// WP_Error messages for HTTP transport failures are generic
				// (timeout, could not resolve host, etc.) and never contain
				// the key, so they are safe to surface to the admin.
				'error'  => $response->get_error_message(),
				'code'   => 'network_error',
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = null;

		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$data = $decoded;
			}
		}

		if ( $status === $expected_status ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'data'   => $data,
				'error'  => null,
				'code'   => null,
			);
		}

		return array(
			'ok'     => false,
			'status' => $status,
			'data'   => $data,
			'error'  => $this->error_message_for( $status, $data ),
			'code'   => $this->error_code_for( $status, $data ),
		);
	}

	private function error_message_for( $status, $data ) {
		if ( is_array( $data ) && ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}

		switch ( $status ) {
			case 401:
				return __( 'The Orbitali API key is invalid or has been revoked.', 'orbitali' );
			case 404:
				return __( 'The requested Orbitali resource was not found.', 'orbitali' );
			case 409:
				return __( 'The request conflicts with the current state on Orbitali.', 'orbitali' );
			case 429:
				return __( 'Orbitali is rate-limiting this API key. Please try again shortly.', 'orbitali' );
			case 0:
				return __( 'Could not reach Orbitali.', 'orbitali' );
		}

		if ( $status >= 500 ) {
			return __( 'Orbitali returned a server error. Please try again.', 'orbitali' );
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Orbitali request failed (HTTP %d).', 'orbitali' ),
			$status
		);
	}

	private function error_code_for( $status, $data ) {
		if ( is_array( $data ) && ! empty( $data['code'] ) && is_string( $data['code'] ) ) {
			return $data['code'];
		}

		if ( 401 === $status ) {
			return 'unauthorized';
		}
		if ( 404 === $status ) {
			return 'not_found';
		}
		if ( 429 === $status ) {
			return 'rate_limited';
		}

		return 'http_' . $status;
	}
}
