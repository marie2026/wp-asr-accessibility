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
		register_setting( 'asr_settings', 'asr_mode' );
		register_setting('asr_settings', 'asr_whisper_url', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_whisper_url' )
		));
		register_setting( 'asr_settings', 'asr_whisper_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asr_settings', 'asr_default_language', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'fr-FR' ) );
		register_setting( 'asr_settings', 'asr_enable_wasm', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'asr_settings', 'asr_auto_delete_audio', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'asr_settings', 'asr_allow_external_send', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'asr_settings', 'asr_external_quota_minutes', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'asr_settings', 'asr_quota_alert_percent', array( 'sanitize_callback' => 'absint', 'default' => 80 ) );
		register_setting( 'asr_settings', 'asr_allow_unknown_duration_send', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		
		// Nouveaux réglages pour configuration multi-sources
		register_setting( 'asr_settings', 'asr_enable_site_server', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'asr_settings', 'asr_show_local_config', array( 'sanitize_callback' => 'absint', 'default' => 1 ) );
	}

	public static function sanitize_whisper_url( $url ) {
		$url = esc_url_raw($url, array('http', 'https'));
		
		if (empty($url)) return '';
		
		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['host'])) {
			return '';
		}
		
		$host = $parsed['host'];
		$port = isset($parsed['port']) ? $parsed['port'] : 
				(isset($parsed['scheme']) && $parsed['scheme'] === 'https' ? 443 : 80);
		
		// Bloquer les ports dangereux
		$dangerous_ports = array(22, 23, 25, 3306, 5432, 6379, 9200, 11211, 27017, 50070);
		if (in_array($port, $dangerous_ports, true)) {
			add_settings_error('asr_whisper_url', 'dangerous_port', 
				'Port ' . $port . ' non autorisé pour des raisons de sécurité');
			return '';
		}
		
		// Bloquer les IPs privées (sauf pour développement local)
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				add_settings_error('asr_whisper_url', 'invalid_ip', 'Les adresses IP privées ne sont pas autorisées');
				return '';
			}
		}
		
		// Bloquer localhost côté admin (les visiteurs peuvent utiliser leur propre localhost)
		if (in_array(strtolower($host), array('localhost', '127.0.0.1', '::1', '0.0.0.0'), true)) {
			add_settings_error('asr_whisper_url', 'localhost_blocked', 
				'Localhost n\'est pas autorisé pour le serveur du site. Les visiteurs peuvent configurer leur propre serveur local.');
			return '';
		}
		
		return $url;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>ASR Accessibility — Réglages</h1>

			<?php settings_errors(); ?>

			<?php if ( ! function_exists( 'as_schedule_single_action' ) && ! function_exists( 'action_scheduler_schedule_single_action' ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Action Scheduler non détecté.</strong> Pour une meilleure fiabilité des tâches en arrière-plan, installez le plugin <em>Action Scheduler</em>.</p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p><strong>ℹ️ Approche multi-sources</strong></p>
				<p>Ce plugin propose plusieurs méthodes de reconnaissance vocale aux visiteurs. Ils peuvent choisir selon leurs besoins de confidentialité :</p>
				<ul style="list-style-type: disc; margin-left: 20px;">
					<li><strong>Web Speech API</strong> : Intégré au navigateur (peut envoyer données au fournisseur)</li>
					<li><strong>WASM</strong> : Traitement 100% local dans le navigateur (toujours disponible)</li>
					<li><strong>Serveur du site</strong> : Configuré par vous ci-dessous</li>
					<li><strong>Serveur local visiteur</strong> : Whisper.cpp sur leur propre ordinateur</li>
				</ul>
			</div>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'asr_settings' ); do_settings_sections( 'asr_settings' ); ?>
				<table class="form-table" role="presentation">
					
					<tr>
						<th scope="row"><label for="asr_default_language">Langue par défaut</label></th>
						<td>
							<input id="asr_default_language" name="asr_default_language" type="text" 
								value="<?php echo esc_attr( get_option( 'asr_default_language', 'fr-FR' ) ); ?>" 
								class="regular-text" />
							<p class="description">Ex : fr-FR, en-US, es-ES. Utilisé par toutes les méthodes de reconnaissance.</p>
						</td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-top:20px;">🌐 Serveur Whisper du site (optionnel)</h2></th>
					</tr>
					
					<tr>
						<th scope="row"><label for="asr_enable_site_server">Proposer le serveur du site</label></th>
						<td>
							<label>
								<input id="asr_enable_site_server" name="asr_enable_site_server" type="checkbox" value="1" 
									<?php checked( 1, get_option( 'asr_enable_site_server', 0 ) ); ?> />
								Activer le traitement sur le serveur du site
							</label>
							<p class="description">
								Si activé, les visiteurs pourront choisir d'envoyer leurs enregistrements à votre serveur Whisper.<br/>
								⚠️ Nécessite une instance whisper.cpp accessible et configurée ci-dessous.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_whisper_url">URL du service Whisper</label></th>
						<td>
							<input id="asr_whisper_url" name="asr_whisper_url" type="url" 
								value="<?php echo esc_attr( get_option( 'asr_whisper_url', '' ) ); ?>" 
								class="regular-text" 
								placeholder="https://whisper.example.com/transcribe" />
							<p class="description">
								URL de votre serveur whisper.cpp (doit être en HTTPS et sécurisé).<br/>
								Exemple : <code>https://whisper.votresite.com/transcribe</code>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_whisper_api_key">Clé API (optionnelle)</label></th>
						<td>
							<input id="asr_whisper_api_key" name="asr_whisper_api_key" type="password" 
								value="<?php echo esc_attr( get_option( 'asr_whisper_api_key', '' ) ); ?>" 
								class="regular-text" />
							<p class="description">
								<strong>💡 Sécurité :</strong> Pour plus de sécurité, définissez-la dans <code>wp-config.php</code> :<br/>
								<code>define('ASR_WHISPER_API_KEY', 'votre-clé-ici');</code>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_allow_external_send">Autoriser envoi vers le serveur</label></th>
						<td>
							<label>
								<input id="asr_allow_external_send" name="asr_allow_external_send" type="checkbox" value="1" 
									<?php checked( 1, get_option( 'asr_allow_external_send', 0 ) ); ?> />
								Autoriser les visiteurs à utiliser le serveur du site
							</label>
							<p class="description">Si décoché, même si l'URL est configurée, aucun envoi ne sera fait.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_external_quota_minutes">Quota mensuel (minutes)</label></th>
						<td>
							<input id="asr_external_quota_minutes" name="asr_external_quota_minutes" type="number" min="0" 
								value="<?php echo esc_attr( get_option( 'asr_external_quota_minutes', 0 ) ); ?>" 
								class="small-text" />
							<p class="description">
								0 = envoi interdit. Définissez le nombre de minutes d'audio par mois que vous acceptez de traiter.<br/>
								Ceci permet de contrôler les coûts serveur.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_quota_alert_percent">Alerte quota (%)</label></th>
						<td>
							<input id="asr_quota_alert_percent" name="asr_quota_alert_percent" type="number" min="1" max="100" 
								value="<?php echo esc_attr( get_option( 'asr_quota_alert_percent', 80 ) ); ?>" 
								class="small-text" />
							<p class="description">Recevoir un email quand le quota atteint ce pourcentage (défaut : 80%).</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_allow_unknown_duration_send">Autoriser si durée inconnue</label></th>
						<td>
							<label>
								<input id="asr_allow_unknown_duration_send" name="asr_allow_unknown_duration_send" type="checkbox" value="1" 
									<?php checked( 1, get_option( 'asr_allow_unknown_duration_send', 0 ) ); ?> />
								Permettre les envois dont la durée n'est pas connue
							</label>
							<p class="description">
								Si décoché, les enregistrements sans durée seront refusés (recommandé pour éviter dépassement quota).
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="asr_auto_delete_audio">Supprimer après traitement</label></th>
						<td>
							<label>
								<input id="asr_auto_delete_audio" name="asr_auto_delete_audio" type="checkbox" value="1" 
									<?php checked( 1, get_option( 'asr_auto_delete_audio', 0 ) ); ?> />
								Supprimer les fichiers audio après transcription
							</label>
							<p class="description">Recommandé pour la confidentialité et l'espace disque.</p>
						</td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-top:20px;">🏠 Serveur local visiteur</h2></th>
					</tr>

					<tr>
						<th scope="row">Configuration visiteur</th>
						<td>
							<div class="notice notice-info inline" style="margin:0; padding:12px;">
								<p><strong>ℹ️ Information importante</strong></p>
								<p>Les visiteurs peuvent installer whisper.cpp sur <strong>leur propre ordinateur</strong> 
								et l'utiliser via <code>http://localhost:8080</code>.</p>
								<p><strong>Avantages :</strong></p>
								<ul style="list-style-type: disc; margin-left: 20px;">
									<li>✅ Aucun impact sur votre serveur</li>
									<li>✅ Aucune donnée ne transite par votre site</li>
									<li>✅ Confidentialité maximale pour le visiteur</li>
									<li>✅ Pas de quota à gérer</li>
								</ul>
							</div>
							
							<p style="margin-top:16px;">
								<label>
									<input type="checkbox" name="asr_show_local_config" value="1" 
										<?php checked( 1, get_option( 'asr_show_local_config', 1 ) ); ?> />
									Afficher le bouton "Configurer mon serveur local" aux visiteurs
								</label>
							</p>
							<p class="description">
								Si activé, les visiteurs verront un bouton pour configurer leur propre serveur whisper.cpp local.<br/>
								Cette option est <strong>toujours disponible côté visiteur</strong> (vous ne pouvez pas la désactiver complètement, 
								seulement masquer le bouton d'aide).
							</p>
						</td>
					</tr>

					<tr>
						<th colspan="2"><h2 style="margin-top:20px;">🔒 WASM (traitement navigateur)</h2></th>
					</tr>

					<tr>
						<th scope="row">Méthode WASM</th>
						<td>
							<div class="notice notice-info inline" style="margin:0; padding:12px;">
								<p><strong>ℹ️ Toujours disponible pour les visiteurs</strong></p>
								<p>La méthode WASM permet aux visiteurs de traiter la reconnaissance vocale 
								<strong>entièrement dans leur navigateur</strong>, sans rien envoyer.</p>
								<p><strong>Cette option est toujours proposée</strong> aux visiteurs car :</p>
								<ul style="list-style-type: disc; margin-left: 20px;">
									<li>✅ Aucun impact sur vos ressources serveur</li>
									<li>✅ Aucune donnée personnelle collectée</li>
									<li>✅ Conforme RGPD par conception</li>
									<li>✅ Accessibilité maximale</li>
								</ul>
							</div>
							
							<p style="margin-top:16px;">
								<label>
									<input type="checkbox" name="asr_enable_wasm" value="1" 
										<?php checked( 1, get_option( 'asr_enable_wasm', 0 ) ); ?> />
									Afficher de l'aide sur WASM dans l'interface
								</label>
							</p>
							<p class="description">
								⚠️ <strong>Expérimental</strong> : WASM nécessite le téléchargement d'un modèle (~40-150 MB) et est encore en développement.
							</p>
						</td>
					</tr>

				</table>

				<?php submit_button(); ?>
			</form>

			<hr/>

			<h2>📊 Quota & Usage</h2>
			<?php self::render_quota_status(); ?>

			<hr/>

			<h2>🧪 Tester la connexion au serveur</h2>
			<p>Envoyer un test au service whisper (si configuré).</p>
			<button id="asr-test-endpoint" class="button">Tester endpoint</button>
			<div id="asr-test-result" style="margin-top:1rem;"></div>

			<hr/>

			<h2>📋 Liste des jobs ASR</h2>
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

		$api_key = defined('ASR_WHISPER_API_KEY') ? ASR_WHISPER_API_KEY : get_option( 'asr_whisper_api_key', '' );
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			'sslverify' => true,
		);
		$res = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		wp_send_json_success( array( 'code' => $code ) );
	}

	public static function ajax_delete_job() {
		check_ajax_referer( 'asr_admin_actions' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'ID manquant' );
		}
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
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			wp_send_json_error( 'Fichier introuvable ou non lisible' );
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
}
