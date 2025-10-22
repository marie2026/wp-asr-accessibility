<?php
/**
 * Plugin Name: ASR Accessibility (MVP)
 * Plugin URI:  https://example.org
 * Description: MVP pour intégration Speech->Text (Web Speech API, fallback upload -> processing via server whisper or remote provider). 
 * Version:     0.3.1
 * Author:      Your Name
 * Text Domain: asr-accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASR_VERSION', '0.3.1' );

require_once ASR_PLUGIN_DIR . 'includes/class-asr-admin.php';
require_once ASR_PLUGIN_DIR . 'includes/class-asr-rest.php';
require_once ASR_PLUGIN_DIR . 'includes/class-asr-processor.php';

add_action( 'plugins_loaded', function() {
	ASR_Admin::init();
	ASR_REST::init();
	ASR_Processor::init();
} );

/**
 * Schedule a single job using Action Scheduler if available, otherwise fallback to WP Cron.
 *
 * @param int    $timestamp Unix timestamp when to run.
 * @param string $hook      Action hook name.
 * @param array  $args      Arguments to pass to the hook (array).
 * @return bool             True on success.
 */
function asr_schedule_job( $timestamp, $hook, $args = array() ) {
	// Prefer Action Scheduler if present (modern function name)
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( $timestamp, $hook, $args );
		return true;
	}

	// Older Action Scheduler function name
	if ( function_exists( 'action_scheduler_schedule_single_action' ) ) {
		action_scheduler_schedule_single_action( $timestamp, $hook, $args );
		return true;
	}

	// Fallback to WP Cron - ensure we don't double-schedule same args/hook at exactly the same time
	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_single_event( $timestamp, $hook, $args );
		return true;
	}

	return false;
}

// Enqueue frontend assets
add_action( 'wp_enqueue_scripts', function() {
	wp_enqueue_script( 'asr-frontend', ASR_PLUGIN_URL . 'assets/js/frontend-asr.js', array(), ASR_VERSION, true );
	wp_localize_script( 'asr-frontend', 'ASRSettings', array(
		'restUrl' => esc_url_raw( rest_url( 'asr/v1/transcribe' ) ),
		'statusUrl' => esc_url_raw( rest_url( 'asr/v1/status' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'lang'    => get_option( 'asr_default_language', 'fr-FR' ),
	) );

	wp_enqueue_style( 'asr-frontend-css', ASR_PLUGIN_URL . 'assets/css/frontend-asr.css', array(), ASR_VERSION );
} );

// Enqueue admin assets
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( strpos( $hook, 'asr-accessibility' ) !== false ) {
		wp_enqueue_script( 'asr-admin', ASR_PLUGIN_URL . 'assets/js/admin-asr.js', array('jquery'), ASR_VERSION, true );
		// CORRECTION: Ajouter les nonces pour les actions AJAX
		wp_localize_script( 'asr-admin', 'ASRAdmin', array(
			'testEndpointNonce' => wp_create_nonce( 'asr_test_endpoint' ),
			'adminActionsNonce' => wp_create_nonce( 'asr_admin_actions' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
		wp_enqueue_style( 'asr-admin-css', ASR_PLUGIN_URL . 'assets/css/admin-asr.css', array(), ASR_VERSION );
	}
} );

// Activation: ensure default options
register_activation_hook( __FILE__, function() {
	if ( get_option( 'asr_mode' ) === false ) {
		update_option( 'asr_mode', 'auto' );
	}
	if ( get_option( 'asr_default_language' ) === false ) {
		update_option( 'asr_default_language', 'fr-FR' );
	}
	if ( get_option( 'asr_enable_wasm' ) === false ) {
		update_option( 'asr_enable_wasm', 0 );
	}
	// New defaults for external sending and quota
	if ( get_option( 'asr_allow_external_send' ) === false ) {
		update_option( 'asr_allow_external_send', 0 ); // disabled by default
	}
	if ( get_option( 'asr_external_quota_minutes' ) === false ) {
		update_option( 'asr_external_quota_minutes', 0 ); // 0 = disabled / no sends
	}
	if ( get_option( 'asr_quota_alert_percent' ) === false ) {
		update_option( 'asr_quota_alert_percent', 80 ); // percent to alert
	}
	if ( get_option( 'asr_allow_unknown_duration_send' ) === false ) {
		update_option( 'asr_allow_unknown_duration_send', 0 ); // by default block unknown durations
	}
} );

/**
 * Admin notice recommending Action Scheduler for improved queue reliability.
 */
add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// If Action Scheduler is present, do nothing
	if ( function_exists( 'as_schedule_single_action' ) || function_exists( 'action_scheduler_schedule_single_action' ) ) {
		return;
	}

	$class = 'notice notice-warning is-dismissible';
	$message = __( 'ASR Accessibility: Action Scheduler plugin is not active. For more reliable background job processing we recommend installing "Action Scheduler" (or activate WooCommerce, which includes it).', 'asr-accessibility' );

	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
} );
