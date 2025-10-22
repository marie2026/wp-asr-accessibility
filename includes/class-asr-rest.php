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

		register_rest_route( 'asr/v1', '/status/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_status_request' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Permission callback: allow uploads but apply simple rate limiting by IP.
	 */
	public static function permission_callback( $request ) {
		// Allow if valid WP nonce for logged-in users
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		// Simple rate limit by IP for anonymous usage
		$ip = self::get_ip();
		$transient_key = 'asr_rate_' . md5( $ip );
		$count = (int) get_transient( $transient_key );
		if ( $count >= 15 ) {
			return new WP_Error( 'rate_limited', 'Trop de requêtes depuis votre IP, réessayez plus tard', array( 'status' => 429 ) );
		}
		set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );

		// We allow anonymous uploads by default (useful for public accessibility features)
		return true;
	}

	public static function handle_transcribe_request( WP_REST_Request $request ) {
		// Accept a multipart file upload 'file'
		$files = $request->get_file_params();
		if ( empty( $files ) ) {
			return new WP_Error( 'no_file', 'Aucun fichier envoyé', array( 'status' => 400 ) );
		}
		$file = current( $files );

		// Pull optional duration param (seconds)
		$duration = $request->get_param( 'duration' );
		$duration = is_numeric( $duration ) ? floatval( $duration ) : 0;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$overrides = array( 'test_form' => false );
		$movefile  = wp_handle_upload( $file, $overrides );

		if ( isset( $movefile['error'] ) ) {
			return new WP_Error( 'upload_error', $movefile['error'], array( 'status' => 500 ) );
		}

		// Create an attachment
		$attachment = array(
			'post_mime_type' => $movefile['type'],
			'post_title'     => sanitize_file_name( wp_basename( $movefile['file'] ) ),
			'post_status'    => 'inherit',
			'guid'           => $movefile['url'],
		);
		$attach_id = wp_insert_attachment( $attachment, $movefile['file'] );
		if ( ! is_wp_error( $attach_id ) ) {
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $movefile['file'] ) );
		}

		// Store duration (if provided) to attachment meta for later usage
		update_post_meta( $attach_id, '_asr_duration', $duration );

		// Mark status queued
		update_post_meta( $attach_id, '_asr_status', 'queued' );

		// Schedule async processing via Action Scheduler if available, fallback to WP Cron
		$timestamp = time() + 3;
		$job = array(
			'attachment_id' => $attach_id,
			'file_path'     => $movefile['file'],
			'file_url'      => $movefile['url'],
			'language'      => $request->get_param( 'language' ) ?: get_option( 'asr_default_language', 'fr-FR' ),
		);

		// Use the utility wrapper (defined in main plugin file)
		if ( function_exists( 'asr_schedule_job' ) ) {
			asr_schedule_job( $timestamp, 'asr_process_job', array( $job ) );
		} else {
			// Safety fallback
			if ( ! wp_next_scheduled( 'asr_process_job', array( $job ) ) ) {
				wp_schedule_single_event( $timestamp, 'asr_process_job', array( $job ) );
			}
		}

		return rest_ensure_response( array(
			'status' => 'queued',
			'attachment_id' => $attach_id,
		) );
	}

	public static function handle_status_request( WP_REST_Request $request ) {
		$id = intval( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'invalid_id', 'ID invalide', array( 'status' => 400 ) );
		}
		$status = get_post_meta( $id, '_asr_status', true );
		$transcript = get_post_meta( $id, '_asr_transcript', true );
		$error = get_post_meta( $id, '_asr_error', true );

		return rest_ensure_response( array(
			'attachment_id' => $id,
			'status' => $status ?: 'unknown',
			'transcript' => $transcript ?: '',
			'error' => $error ?: '',
		) );
	}

	private static function get_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			return sanitize_text_field( trim( $parts[0] ) );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
	}

	public static function handle_process_job( $job ) {
		// Forward to processor class
		ASR_Processor::process_attachment( $job );
	}
}