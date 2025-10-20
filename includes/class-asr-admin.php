<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_asr_test_endpoint', array( __CLASS__, 'ajax_test_endpoint' ) );
	}

	public static function add_settings_page() {
		add_options_page(
			'ASR Accessibility',
			'ASR Accessibility',
			'manage_options',
			'asr-accessibility',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'asr_settings', 'asr_mode' ); // 'auto'|'server'|'wasm'
		register_setting( 'asr_settings', 'asr_whisper_url' );
		register_setting( 'asr_settings', 'asr_whisper_api_key' );
		register_setting( 'asr_settings', 'asr_default_language', array( 'default' => 'fr-FR' ) );
		register_setting( 'asr_settings', 'asr_enable_wasm' );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>ASR Accessibility — Réglages</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'asr_settings' ); do_settings_sections( 'asr_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asr_mode">Mode</label></th>
						<td>
							<select id="asr_mode" name="asr_mode">
								<option value="auto" <?php selected( get_option( 'asr_mode', 'auto' ), 'auto' ); ?>>Auto (Web Speech API → Server → WASM)</option>
								<option value="server" <?php selected( get_option( 'asr_mode' ), 'server' ); ?>>Forcer serveur</option>
								<option value="wasm" <?php selected( get_option( 'asr_mode' ), 'wasm' ); ?>>Forcer WASM client</option>
							</select>
							<p class="description">Ordre recommandé : Web Speech API (navigateur) → Server (whisper) → WASM (client).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_whisper_url">URL du service whisper (server)</label></th>
						<td>
							<input id="asr_whisper_url" name="asr_whisper_url" type="url" value="<?php echo esc_attr( get_option( 'asr_whisper_url', '' ) ); ?>" class="regular-text" />
							<p class="description">Ex : https://asr.example.com/transcribe (doit être sécurisé et protégé par clé).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_whisper_api_key">Clé API du service (optionnelle)</label></th>
						<td>
							<input id="asr_whisper_api_key" name="asr_whisper_api_key" type="password" value="<?php echo esc_attr( get_option( 'asr_whisper_api_key', '' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_default_language">Langue par défaut</label></th>
						<td>
							<input id="asr_default_language" name="asr_default_language" type="text" value="<?php echo esc_attr( get_option( 'asr_default_language', 'fr-FR' ) ); ?>" class="regular-text" />
							<p class="description">Ex : fr-FR, en-US. Utilisé par Web Speech API et envoyé au service si possible.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_enable_wasm">Activer WASM (expérimental)</label></th>
						<td>
							<input id="asr_enable_wasm" name="asr_enable_wasm" type="checkbox" value="1" <?php checked( 1, get_option( 'asr_enable_wasm', 0 ) ); ?> />
							<p class="description">WASM implique téléchargement de modèles côté visiteur. Option avancée.</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr/>

			<h2>Tester la connexion au service serveur</h2>
			<p>Envoyer un petit test au service whisper (si configuré).</p>
			<button id="asr-test-endpoint" class="button">Tester endpoint</button>
			<div id="asr-test-result" style="margin-top:1rem;"></div>
		</div>
		<?php
	}

	public static function ajax_test_endpoint() {
		check_ajax_referer( 'asr_test_endpoint' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		$url = get_option( 'asr_whisper_url' );
		if ( empty( $url ) ) {
			wp_send_json_error( 'URL non configurée' );
		}

		// Small test ping (HEAD or GET)
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . get_option( 'asr_whisper_api_key', '' ),
			),
		);
		$res = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		wp_send_json_success( array( 'code' => $code ) );
	}
}