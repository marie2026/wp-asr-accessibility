<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Processor {

	public static function init() {
		// nothing for now
	}

	/**
	 * Helpers for quota tracking
	 */
	public static function get_current_month_key() {
		return gmdate( 'Y_m', current_time( 'timestamp' ) );
	}

	public static function get_monthly_usage( $month_key = '' ) {
		if ( empty( $month_key ) ) {
			$month_key = self::get_current_month_key();
		}
		$opt_key = 'asr_usage_' . $month_key;
		return (int) get_option( $opt_key, 0 );
	}

	public static function add_usage_minutes( $minutes ) {
		$month_key = self::get_current_month_key();
		$opt_key = 'asr_usage_' . $month_key;
		$used = (int) get_option( $opt_key, 0 );
		$used += (int) $minutes;
		update_option( $opt_key, $used );

		// Check alert threshold
		$quota = (int) get_option( 'asr_external_quota_minutes', 0 );
		$alert_pct = (int) get_option( 'asr_quota_alert_percent', 80 );
		if ( $quota > 0 ) {
			$percent = ( $used / $quota ) * 100;
			if ( $percent >= $alert_pct ) {
				self::send_quota_alert( $used, $quota, $percent );
			}
		}
	}

	public static function send_quota_alert( $used, $quota, $percent ) {
		// Avoid sending duplicate alerts too often: set transient for day
		$transient = 'asr_quota_alert_sent_' . self::get_current_month_key();
		if ( get_transient( $transient ) ) {
			return;
		}

		$admin_emails = array();
		$users = get_users( array( 'capability' => 'manage_options' ) );
		foreach ( $users as $u ) {
			$email = get_user_meta( $u->ID, 'email', true );
			if ( empty( $email ) && isset( $u->user_email ) ) {
				$email = $u->user_email;
			}
			if ( $email ) {
				$admin_emails[] = $email;
			}
		}
		// Fallback to site admin email
		if ( empty( $admin_emails ) ) {
			$admin_emails[] = get_option( 'admin_email' );
		}

		$subject = sprintf( '[ASR] Quota atteint : %d%% utilisé', round( $percent ) );
		$body = sprintf(
			"Le quota ASR externe est à %d%% (%d/%d minutes).\n\nVeuillez vérifier l'utilisation ou augmenter le quota dans Réglages → ASR Accessibility.",
			round( $percent ), $used, $quota
		);

		foreach ( $admin_emails as $to ) {
			wp_mail( $to, $subject, $body );
		}

		// Prevent repeat alerts for the day
		set_transient( $transient, 1, DAY_IN_SECONDS );
	}

	/**
	 * Process a scheduled job.
	 * $job : array('attachment_id','file_path','file_url','language')
	 */
	public static function process_attachment( $job ) {
		$attachment_id = intval( $job['attachment_id'] );
		$file_path     = $job['file_path'];
		$file_url      = $job['file_url'];
		$language      = isset( $job['language'] ) ? $job['language'] : get_option( 'asr_default_language', 'fr-FR' );

		update_post_meta( $attachment_id, '_asr_status', 'processing' );
		update_post_meta( $attachment_id, '_asr_started_at', current_time( 'mysql' ) );

		$whisper_url = get_option( 'asr_whisper_url', '' );
		$api_key     = get_option( 'asr_whisper_api_key', '' );

		// If external sending is disabled, mark accordingly (even if whisper_url is set)
		$allow_external = (int) get_option( 'asr_allow_external_send', 0 );
		if ( empty( $whisper_url ) || ! $allow_external ) {
			$placeholder = sprintf(
				'Transcription non disponible (envoi externe non autorisé ou service non configuré). Fichier: %s',
				basename( $file_path )
			);
			update_post_meta( $attachment_id, '_asr_transcript', $placeholder );
			update_post_meta( $attachment_id, '_asr_status', 'completed' );
			update_post_meta( $attachment_id, '_asr_completed_at', current_time( 'mysql' ) );
			return;
		}

		// Get duration stored on attachment (seconds) if available
		$duration = get_post_meta( $attachment_id, '_asr_duration', true );
		$duration = is_numeric( $duration ) ? floatval( $duration ) : 0;

		// If duration unknown and admin disallows unknown duration sends, block and mark job
		$allow_unknown = (int) get_option( 'asr_allow_unknown_duration_send', 0 );
		if ( $duration <= 0 && ! $allow_unknown ) {
			update_post_meta( $attachment_id, '_asr_status', 'needs_duration' );
			update_post_meta( $attachment_id, '_asr_error', 'Durée inconnue — impossible d\'appliquer le quota. Relancer depuis l\'admin si vous souhaitez forcer.' );
			return;
		}

		// Compute minutes to charge (rounded up)
		$minutes = $duration > 0 ? ceil( $duration / 60 ) : 0;

		// Check quota before sending
		$quota = (int) get_option( 'asr_external_quota_minutes', 0 );
		if ( $quota > 0 ) {
			$used = self::get_monthly_usage();
			if ( $minutes > 0 && ( $used + $minutes ) > $quota ) {
				update_post_meta( $attachment_id, '_asr_status', 'blocked_quota' );
				update_post_meta( $attachment_id, '_asr_error', 'Quota mensuel dépassé — envoi refusé.' );
				return;
			}
		} else {
			// quota == 0 means external sending disabled by default; but we've checked allow_external earlier
			// If quota is 0 but allow_external is true, we treat as unlimited; however default behavior sets allow_external=0
		}

		// Send to whisper server (multipart/form-data)
		if ( ! file_exists( $file_path ) ) {
			update_post_meta( $attachment_id, '_asr_status', 'error' );
			update_post_meta( $attachment_id, '_asr_error', 'Fichier introuvable sur le serveur' );
			return;
		}

		$body = array(
			'file' => curl_file_create( $file_path ),
			'language' => $language,
			'attachment_id' => $attachment_id,
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $whisper_url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
		$headers = array();
		if ( ! empty( $api_key ) ) {
			$headers[] = 'Authorization: Bearer ' . $api_key;
		}
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		$response = curl_exec( $ch );
		$err = curl_error( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $err ) {
			update_post_meta( $attachment_id, '_asr_status', 'error' );
			update_post_meta( $attachment_id, '_asr_error', 'cURL error: ' . $err );
			return;
		}

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $response, true );
			if ( is_array( $data ) && isset( $data['transcript'] ) ) {
				$transcript = wp_kses_post( $data['transcript'] );
				update_post_meta( $attachment_id, '_asr_transcript', $transcript );
				update_post_meta( $attachment_id, '_asr_segments', isset( $data['segments'] ) ? $data['segments'] : array() );
				update_post_meta( $attachment_id, '_asr_status', 'completed' );
				update_post_meta( $attachment_id, '_asr_completed_at', current_time( 'mysql' ) );

				// Charge quota after successful response
				if ( $minutes > 0 ) {
					self::add_usage_minutes( $minutes );
					update_post_meta( $attachment_id, '_asr_quota_counted', $minutes );
				} else {
					update_post_meta( $attachment_id, '_asr_quota_counted', 0 );
				}

				// Optionally remove original audio if setting enabled
				if ( get_option( 'asr_auto_delete_audio', 0 ) ) {
					wp_delete_attachment( $attachment_id, true );
				}
				return;
			}

			// Unexpected response format
			update_post_meta( $attachment_id, '_asr_status', 'invalid_response' );
			update_post_meta( $attachment_id, '_asr_response_raw', $response );
			return;
		}

		// HTTP error
		update_post_meta( $attachment_id, '_asr_status', 'error' );
		update_post_meta( $attachment_id, '_asr_error', 'HTTP ' . $code . ' - ' . $response );
	}
}