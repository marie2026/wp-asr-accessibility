<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_asr_test_endpoint', array( __CLASS__, 'ajax_test_endpoint' ) );
		add_action( 'wp_ajax_asr_delete_job', array( __CLASS__, 'ajax_delete_job' ) );
		add_action( 'wp_ajax_asr_rerun_job', array( __CLASS__, 'ajax_rerun_job' ) );
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

	function asr_sanitize_whisper_url($url) {
		$url = esc_url_raw($url, array('http', 'https'));
		
		if (empty($url)) return '';
		
		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['host'])) {
			return '';
		}
		
		$host = $parsed['host'];
		
		// Bloquer les IPs locales/privées
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				add_settings_error('asr_whisper_url', 'invalid_ip', 'Les adresses IP privées ne sont pas autorisées');
				return '';
			}
		}
		
		// Bloquer localhost
		if (in_array(strtolower($host), array('localhost', '127.0.0.1', '::1', '0.0.0.0'))) {
			add_settings_error('asr_whisper_url', 'localhost_blocked', 'Localhost n\'est pas autorisé');
			return '';
		}
		
		return $url;
	}

register_setting('asr_settings', 'asr_whisper_url', array('sanitize_callback' => 'asr_sanitize_whisper_url'));
		register_setting( 'asr_settings', 'asr_whisper_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asr_settings', 'asr_default_language', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'fr-FR' ) );
		register_setting( 'asr_settings', 'asr_enable_wasm', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'asr_settings', 'asr_auto_delete_audio', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );

		// New settings for external sending & quotas
		register_setting( 'asr_settings', 'asr_allow_external_send', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'asr_settings', 'asr_external_quota_minutes', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'asr_settings', 'asr_quota_alert_percent', array( 'sanitize_callback' => 'absint', 'default' => 80 ) );
		register_setting( 'asr_settings', 'asr_allow_unknown_duration_send', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>ASR Accessibility — Réglages</h1>

			<?php if ( ! function_exists( 'as_schedule_single_action' ) && ! function_exists( 'action_scheduler_schedule_single_action' ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Action Scheduler non détecté.</strong> Pour une meilleure fiabilité des tâches en arrière-plan, installez le plugin <em>Action Scheduler</em> ou activez <em>WooCommerce</em> (qui inclut Action Scheduler).</p>
				</div>
			<?php endif; ?>
			
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
							<p class="description">Stockée en option dans wp_options (masquée). Pour plus de sécurité, définissez-la dans wp-config.php ou utilisez un secret manager.</p>
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
					<tr>
						<th scope="row"><label for="asr_auto_delete_audio">Supprimer les fichiers audio après traitement</label></th>
						<td>
							<input id="asr_auto_delete_audio" name="asr_auto_delete_audio" type="checkbox" value="1" <?php checked( 1, get_option( 'asr_auto_delete_audio', 0 ) ); ?> />
							<p class="description">Si activé, l'audio uploadé sera supprimé du média après transcription (utile pour confidentialité).</p>
						</td>
					</tr>

					<!-- New external sending settings -->
					<tr>
						<th scope="row"><label for="asr_allow_external_send">Autoriser envoi vers service externe</label></th>
						<td>
							<input id="asr_allow_external_send" name="asr_allow_external_send" type="checkbox" value="1" <?php checked( 1, get_option( 'asr_allow_external_send', 0 ) ); ?> />
							<p class="description">Par défaut désactivé. Si activé, les enregistrements peuvent être envoyés au service configuré (quota appliqué).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_external_quota_minutes">Quota mensuel (minutes)</label></th>
						<td>
							<input id="asr_external_quota_minutes" name="asr_external_quota_minutes" type="number" min="0" value="<?php echo esc_attr( get_option( 'asr_external_quota_minutes', 0 ) ); ?>" class="small-text" />
							<p class="description">0 = envoi interdit. Définissez le nombre de minutes par mois autorisées vers le service externe.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_quota_alert_percent">Alerte quota atteint (%)</label></th>
						<td>
							<input id="asr_quota_alert_percent" name="asr_quota_alert_percent" type="number" min="1" max="100" value="<?php echo esc_attr( get_option( 'asr_quota_alert_percent', 80 ) ); ?>" class="small-text" />
							<p class="description">Envoyer un email aux administrateurs lorsque le quota atteint ce pourcentage.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asr_allow_unknown_duration_send">Autoriser envoi si durée inconnue</label></th>
						<td>
							<input id="asr_allow_unknown_duration_send" name="asr_allow_unknown_duration_send" type="checkbox" value="1" <?php checked( 1, get_option( 'asr_allow_unknown_duration_send', 0 ) ); ?> />
							<p class="description">Si décoché (par défaut), les envois sans durée connue seront bloqués pour éviter dépassement de quota.</p>
						</td>
					</tr>

				</table>

				<?php submit_button(); ?>
			</form>

			<hr/>

			<h2>Quota & Usage</h2>
			<?php self::render_quota_status(); ?>

			<hr/>

			<h2>Tester la connexion au service serveur</h2>
			<p>Envoyer un petit test au service whisper (si configuré).</p>
			<button id="asr-test-endpoint" class="button">Tester endpoint</button>
			<div id="asr-test-result" style="margin-top:1rem;"></div>

			<hr/>

			<h2>Liste des jobs ASR</h2>
			<p>Les derniers fichiers audio traités ou en attente :</p>
			<?php self::render_job_list(); ?>
		</div>
		<?php
	}

	public static function render_quota_status() {
		$quota = (int) get_option( 'asr_external_quota_minutes', 0 );
		$used = ASR_Processor::get_monthly_usage();
		$percent = $quota > 0 ? round( ( $used / $quota ) * 100, 1 ) : 0;

		echo '<table class="widefat fixed striped">';
		echo '<tr><th>Quota mensuel (minutes)</th><td>' . esc_html( $quota ) . '</td></tr>';
		echo '<tr><th>Utilisé ce mois</th><td>' . esc_html( $used ) . ' minutes</td></tr>';
		echo '<tr><th>Pourcentage</th><td>' . esc_html( $percent ) . '%</td></tr>';
		echo '</table>';
	}

	public static function render_job_list() {
		$args = array(
			'post_type' => 'attachment',
			'posts_per_page' => 30,
			'meta_query' => array(
				array(
					'key' => '_asr_status',
					'compare' => 'EXISTS',
				),
			),
			'orderby' => 'modified',
			'order' => 'DESC',
		);
		$q = new WP_Query( $args );
		if ( ! $q->have_posts() ) {
			echo '<p>Aucun job trouvé.</p>';
			return;
		}
		echo '<table class="widefat fixed striped"><thead><tr><th>ID</th><th>Fichier</th><th>Status</th><th>Durée (s)</th><th>Transcription</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $q->posts as $p ) {
			$status = get_post_meta( $p->ID, '_asr_status', true );
			$transcript = get_post_meta( $p->ID, '_asr_transcript', true );
			$file_url = wp_get_attachment_url( $p->ID );
			$duration = get_post_meta( $p->ID, '_asr_duration', true ) ?: '';
			echo '<tr>';
			printf( '<td>%d</td>', $p->ID );
			printf( '<td><a href="%s" target="_blank">%s</a></td>', esc_url( $file_url ), esc_html( $p->post_title ) );
			printf( '<td>%s</td>', esc_html( $status ) );
			printf( '<td>%s</td>', esc_html( $duration ) );
			printf( '<td style="max-width:400px"><div>%s</div></td>', esc_html( wp_trim_words( $transcript, 30 ) ) );
			printf( '<td><button class="button asr-delete-job" data-id="%d">Supprimer</button> <button class="button asr-rerun-job" data-id="%d">Relancer</button></td>', $p->ID, $p->ID );
			echo '</tr>';
		}
		echo '</tbody></table>';
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

	public static function ajax_delete_job() {
		check_ajax_referer( 'asr_admin_actions' ); // AJOUTER
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'ID manquant' );
		}
		// Remove meta and optionally delete attachment file
		delete_post_meta( $id, '_asr_status' );
		delete_post_meta( $id, '_asr_transcript' );
		delete_post_meta( $id, '_asr_error' );
		delete_post_meta( $id, '_asr_duration' );
		wp_delete_attachment( $id, true );
		wp_send_json_success( 'Supprimé' );
	}

	public static function ajax_rerun_job() {
    	check_ajax_referer( 'asr_admin_actions' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'ID manquant' );
		}
		$file_path = get_attached_file( $id );
		$file_url  = wp_get_attachment_url( $id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_send_json_error( 'Fichier introuvable' );
		}
		$job = array(
			'attachment_id' => $id,
			'file_path' => $file_path,
			'file_url' => $file_url,
			'language' => get_option( 'asr_default_language', 'fr-FR' ),
		);
		wp_schedule_single_event( time() + 3, 'asr_process_job', array( $job ) );
		update_post_meta( $id, '_asr_status', 'queued' );
		wp_send_json_success( 'Job relancé' );
	}


	wp_localize_script('asr-admin-js', 'ASRAdmin', array(
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'testEndpointNonce' => wp_create_nonce('asr_test_endpoint'),
		'adminActionsNonce' => wp_create_nonce('asr_admin_actions') // AJOUTER
	));

}