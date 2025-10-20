<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'asr_process_job', array( __CLASS__, 'handle_process_job' ), 10, 1 );
	}

	public static function register_routes() {
		register_rest_route( 'asr/v1', '/transcribe', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_transcribe_request' ),
			'permission_callback' => array( __CLASS__, 'permission_callback' ),
			'args'                => array(),
		) );
	}

	public static function permission_callback( $request ) {
		// Allow anonymous usage for public accessibility UI if you want:
		// But protect with nonce for CSRF and limit abuse.
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		// Fallback: require authenticated user with capability
		return current_user_can( 'upload_files' );
	}

	public static function handle_transcribe_request( WP_REST_Request $request ) {
		// Accept a multipart file upload 'file' or a posted audio blob
		if ( empty( $_FILES['file'] ) && ! $request->get_file_params() ) {
			return new WP_Error( 'no_file', 'Aucun fichier envoyé', array( 'status' => 400 ) );
		}

		// Handle via WP upload API
		$file = isset( $_FILES['file'] ) ? $_FILES['file'] : current( $request->get_file_params() );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$overrides = array( 'test_form' => false );
		$movefile  = wp_handle_upload( $file, $overrides );

		if ( isset( $movefile['error'] ) ) {
			return new WP_Error( 'upload_error', $movefile['error'], array( 'status' => 500 ) );
		}

		// Create an attachment to keep track (optional)
		$attachment = array(
			'post_mime_type' => $movefile['type'],
			'post_title'     => sanitize_file_name( $movefile['file'] ),
			'post_status'    => 'inherit',
			'guid'           => $movefile['url'],
		);
		$attach_id = wp_insert_attachment( $attachment, $movefile['file'] );

		// Schedule processing (asynchronous)
		$timestamp = time() + 5; // slight delay
		wp_schedule_single_event( $timestamp, 'asr_process_job', array( array(
			'attachment_id' => $attach_id,
			'file_path'     => $movefile['file'],
			'file_url'      => $movefile['url'],
			'language'      => $request->get_param( 'language' ) ?: get_option( 'asr_default_language', 'fr-FR' ),
		) ) );

		return rest_ensure_response( array(
			'status' => 'queued',
			'attachment_id' => $attach_id,
		) );
	}

	public static function handle_process_job( $job ) {
		// Simple wrapper delegating to processor class
		ASR_Processor::process_attachment( $job );
	}
}