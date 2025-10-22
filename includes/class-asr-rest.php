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
    $nonce = $request->get_header( 'x-wp-nonce' );
    if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return true; // Utilisateurs connectés OK
    }

    // Pour anonymes : rate limit plus strict + vérification User-Agent
    $ip = self::get_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    
    // Bloquer les bots évidents
    if (empty($ua) || preg_match('/bot|crawler|spider/i', $ua)) {
        return new WP_Error('bot_detected', 'Bots non autorisés', array('status' => 403));
    }
    
    // Rate limit BEAUCOUP plus strict pour anonymes
    $transient_key = 'asr_rate_' . md5( $ip . $ua );
    $count = (int) get_transient( $transient_key );
    
    // NOUVEAU: 3 uploads par heure max pour anonymes (au lieu de 15)
    if ( $count >= 3 ) {
        return new WP_Error('rate_limited', 'Limite atteinte. Veuillez patienter 1 heure.', array('status' => 429));
    }
    
    set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );
    return true;
}

	public static function handle_transcribe_request( WP_REST_Request $request ) {
		// Accept a multipart file upload 'file'
		$files = $request->get_file_params();
		if ( empty( $files ) ) {
			return new WP_Error( 'no_file', 'Aucun fichier envoyé', array( 'status' => 400 ) );
		}
		$file = current( $files );
		// includes/class-asr-rest.php - Ajouter après ligne 57

	public static function handle_transcribe_request( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files ) ) {
			return new WP_Error( 'no_file', 'Aucun fichier envoyé', array( 'status' => 400 ) );
		}
		$file = current( $files );

		// NOUVEAU: Validation stricte
		$max_size = 10 * 1024 * 1024; // 10MB max (5s à 320kbps = ~200KB, donc 10MB est généreux)
		if ($file['size'] > $max_size) {
			return new WP_Error('file_too_large', 'Fichier trop volumineux (max 10MB)', array('status' => 413));
		}
		
		// Types audio uniquement
		$allowed_mimes = array(
			'audio/webm',
			'audio/wav',
			'audio/mpeg',
			'audio/mp3',
			'audio/ogg',
			'audio/x-m4a',
			'audio/mp4'
		);
		
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = finfo_file($finfo, $file['tmp_name']);
		finfo_close($finfo);
		
		if (!in_array($mime, $allowed_mimes)) {
			@unlink($file['tmp_name']); // Supprimer le fichier malveillant
			return new WP_Error('invalid_file', 'Type de fichier non autorisé', array('status' => 400));
		}

		// Continuer avec wp_handle_upload...

			// Pull optional duration param (seconds)
			$duration = $request->get_param( 'duration' );
			$duration = is_numeric( $duration ) ? floatval( $duration ) : 0;

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$overrides = array( 'test_form' => false );
			
			// Vérifier l'espace disque disponible
			$upload_dir = wp_upload_dir();
			$free_space = disk_free_space($upload_dir['basedir']);
			$min_space = 100 * 1024 * 1024; // 100MB minimum

			if ($free_space < $min_space) {
				return new WP_Error('disk_full', 'Espace disque insuffisant', array('status' => 507));
			}

			// Compter les fichiers ASR existants
			$asr_attachments = get_posts(array(
				'post_type' => 'attachment',
				'meta_key' => '_asr_status',
				'posts_per_page' => -1,
				'fields' => 'ids'
			));

			$max_asr_files = apply_filters('asr_max_files', 1000); // Filtrable par l'admin

			if (count($asr_attachments) >= $max_asr_files) {
				return new WP_Error('quota_exceeded', 'Limite de fichiers atteinte. Nettoyez les anciens enregistrements.', array('status' => 507));
			}
			
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
