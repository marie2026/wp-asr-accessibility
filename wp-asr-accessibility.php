<?php
/**
 * Plugin Name: ASR Accessibility (squelette)
 * Plugin URI:  https://example.org
 * Description: Squelette pour intégration Speech->Text (Web Speech API, fallback upload vers service whisper.cpp ou service distant).
 * Version:     0.1.0
 * Author:      Your Name
 * Text Domain: asr-accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASR_VERSION', '0.1.0' );

require_once ASR_PLUGIN_DIR . 'includes/class-asr-admin.php';
require_once ASR_PLUGIN_DIR . 'includes/class-asr-rest.php';
require_once ASR_PLUGIN_DIR . 'includes/class-asr-processor.php';

add_action( 'plugins_loaded', function() {
	ASR_Admin::init();
	ASR_REST::init();
	ASR_Processor::init();
} );

// Enqueue frontend assets
add_action( 'wp_enqueue_scripts', function() {
	wp_enqueue_script( 'asr-frontend', ASR_PLUGIN_URL . 'assets/js/frontend-asr.js', array(), ASR_VERSION, true );
	wp_localize_script( 'asr-frontend', 'ASRSettings', array(
		'restUrl' => esc_url_raw( rest_url( 'asr/v1/transcribe' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'lang'    => get_option( 'asr_default_language', 'fr-FR' ),
	) );
} );

// Enqueue admin assets
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( strpos( $hook, 'asr-accessibility' ) !== false ) {
		wp_enqueue_script( 'asr-admin', ASR_PLUGIN_URL . 'assets/js/admin-asr.js', array('jquery'), ASR_VERSION, true );
		wp_localize_script( 'asr-admin', 'ASRAdmin', array(
			'testEndpointNonce' => wp_create_nonce( 'asr_test_endpoint' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
	}
} );