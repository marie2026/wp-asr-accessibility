<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Processor {

	public static function init() {
		// Nothing heavy here yet
	}

	/**
	 * $job : array('attachment_id','file_path','file_url','language')
	 */
	public static function process_attachment( $job ) {
		$attachment_id = intval( $job['attachment_id'] );
		$file_path     = $job['file_path'];
		$file_url      = $job['file_url'];
		$language      = isset( $job['language'] ) ? $job['language'] : get_option( 'asr_default_language', 'fr-FR' );

		// Decide where to send: server whisper if configured, else return error (you can add remote providers)
		$whisper_url = get_option( 'asr_whisper_url', '' );
		$api_key     = get_option( 'asr_whisper_api_key', '' );

		if ( empty( $whisper_url ) ) {
			// mark attachment meta as failed
			update_post_meta( $attachment_id, '_asr_status', 'no_server_configured' );
			return;
		}

		// Prepare request (multipart)
		$body = array(
			'file'     => curl_file_create( $file_path ),
			'language' => $language,
			'attach_id'=> $attachment_id,
		);

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $whisper_url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
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
			update_post_meta( $attachment_id, '_asr_error', $err );
			return;
		}

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $response, true );
			if ( is_array( $data ) && isset( $data['transcript'] ) ) {
				// Store transcript
				update_post_meta( $attachment_id, '_asr_status', 'completed' );
				update_post_meta( $attachment_id, '_asr_transcript', wp_kses_post( $data['transcript'] ) );
				update_post_meta( $attachment_id, '_asr_segments', isset( $data['segments'] ) ? $data['segments'] : array() );
				// Optionally generate VTT and attach as file (omitted here)
				return;
			}
		}

		update_post_meta( $attachment_id, '_asr_status', 'invalid_response' );
		update_post_meta( $attachment_id, '_asr_response_raw', $response );
	}
}