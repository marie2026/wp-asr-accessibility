/**
 * ASR Accessibility - Frontend Multi-Source Recognition
 * Supporte : Web Speech API, WASM, Serveur du site, Serveur local visiteur
 */
(function() {
  'use strict';
  
  // Configuration depuis WordPress
  const restUrl = window.ASRSettings?.restUrl;
  const statusUrl = window.ASRSettings?.statusUrl;
  const nonce = window.ASRSettings?.nonce;
  const lang = window.ASRSettings?.lang || 'fr-FR';
  const siteWhisperUrl = window.ASRSettings?.whisperUrl || null;
  const siteServerEnabled = window.ASRSettings?.serverEnabled || false;
  
  // Configuration des différentes sources possibles
  const ASR_SOURCES = {
    // Web Speech API (navigateur)
    browserAPI: {
      id: 'browser-api',
      label: '🎤 API du navigateur',
      privacy: '⚠️ Données possiblement envoyées au fournisseur du navigateur (ex: Google)',
      description: 'Rapide et simple, mais peut envoyer vos données selon le navigateur.',
      type: 'browser-api',
      priority: 3,
      checkAvailable: function() {
        return 'SpeechRecognition' in window || 'webkitSpeechRecognition' in window;
      }
    },
    
    // WASM dans le navigateur
    wasm: {
      id: 'wasm',
      label: '🔒 Traitement local (WASM)',
      privacy: '✅✅ 100% privé - Aucune donnée ne quitte votre appareil',
      description: 'Le modèle est téléchargé une fois, puis tout se passe localement.',
      type: 'wasm',
      priority: 1,
      checkAvailable: function() {
        return typeof WebAssembly !== 'undefined';
      }
    },
    
    // Serveur du site
    siteServer: {
      id: 'site-server',
      label: '🌐 Serveur du site',
      privacy: '✅ Données traitées sur le serveur privé de ce site',
      description: 'Vos données sont envoyées au serveur du site et supprimées après traitement.',
      type: 'server-remote',
      priority: 2,
      checkAvailable: function() {
        return siteServerEnabled && siteWhisperUrl;
      }
    },
    
    // Serveur local du visiteur
    userLocal: {
      id: 'user-local',
      label: '🏠 Mon serveur local',
      privacy: '✅✅ Traitement sur VOTRE ordinateur (localhost)',
      description: 'Si vous avez installé whisper.cpp sur votre ordinateur.',
      type: 'server-local',
      priority: 1,
      checkAvailable: function() {
        return !!localStorage.getItem('asr_user_whisper_url');
      }
    }
  };

  /**
   * Tester si un endpoint répond (pour localhost)
   */
  async function testEndpoint(url, timeoutMs = 2000) {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
      
      const response = await fetch(url + '/health', {
        method: 'GET',
        signal: controller.signal
      });
      
      clearTimeout(timeoutId);
      return response.ok;
    } catch (err) {
      console.log('Endpoint non disponible:', url, err.message);
      return false;
    }
  }

  /**
   * Détecter les sources disponibles
   */
  async function detectAvailableSources() {
    const sources = [];
    
    // Vérifier chaque source
    for (const key in ASR_SOURCES) {
      const source = ASR_SOURCES[key];
      let isAvailable = false;
      
      if (source.checkAvailable()) {
        // Pour le serveur local, tester s'il répond
        if (source.type === 'server-local') {
          const localUrl = localStorage.getItem('asr_user_whisper_url');
          isAvailable = await testEndpoint(localUrl);
          source.endpoint = localUrl;
        } else if (source.type === 'server-remote') {
          source.endpoint = siteWhisperUrl;
          isAvailable = true;
        } else {
          isAvailable = true;
        }
      }
      
      if (isAvailable) {
        sources.push(source);
      }
    }
    
    // Trier par priorité (1 = plus privé = premier)
    sources.sort((a, b) => a.priority - b.priority);
    
    return sources;
  }

  /**
   * Créer l'interface utilisateur
   */
  async function createUI() {
    const sources = await detectAvailableSources();
    
    if (sources.length === 0) {
      console.warn('ASR: Aucune source de reconnaissance vocale disponible');
      return;
    }
    
    const container = document.createElement('div');
    container.id = 'asr-floating';
    container.className = 'asr-panel';
    container.setAttribute('role', 'region');
    container.setAttribute('aria-label', 'Reconnaissance vocale');
    
    // Header avec bouton de fermeture
    const header = document.createElement('div');
    header.className = 'asr-header';
    const title = document.createElement('h3');
    title.textContent = '🎤 Reconnaissance vocale';
    const closeBtn = document.createElement('button');
    closeBtn.className = 'asr-close';
    closeBtn.textContent = '×';
    closeBtn.setAttribute('aria-label', 'Fermer');
    closeBtn.onclick = () => container.style.display = 'none';
    header.appendChild(title);
    header.appendChild(closeBtn);
    
    // Sélecteur de méthode
    const sourceSelector = document.createElement('div');
    sourceSelector.className = 'asr-source-selector';
    
    const label = document.createElement('label');
    label.htmlFor = 'asr-source';
    label.innerHTML = '<strong>Choisir la méthode :</strong>';
    
    const select = document.createElement('select');
    select.id = 'asr-source';
    select.className = 'asr-select';
    select.setAttribute('aria-describedby', 'asr-privacy-box');
    
    // Ajouter les options disponibles
    sources.forEach(source => {
      const option = document.createElement('option');
      option.value = source.id;
      option.textContent = source.label;
      option.dataset.privacy = source.privacy;
      option.dataset.description = source.description;
      option.dataset.type = source.type;
      select.appendChild(option);
    });
    
    // Restaurer le choix précédent si disponible
    const savedSource = localStorage.getItem('asr_preferred_source');
    if (savedSource && sources.some(s => s.id === savedSource)) {
      select.value = savedSource;
    }
    
    sourceSelector.appendChild(label);
    sourceSelector.appendChild(select);
    
    // Info sur la confidentialité
    const privacyBox = document.createElement('div');
    privacyBox.id = 'asr-privacy-box';
    privacyBox.className = 'asr-privacy-box';
    privacyBox.setAttribute('aria-live', 'polite');
    
    function updatePrivacyInfo() {
      const selected = select.options[select.selectedIndex];
      privacyBox.innerHTML = `
        <div class="privacy-badge">${selected.dataset.privacy}</div>
        <p class="privacy-desc">${selected.dataset.description}</p>
      `;
    }
    updatePrivacyInfo();
    
    select.addEventListener('change', () => {
      updatePrivacyInfo();
      localStorage.setItem('asr_preferred_source', select.value);
    });
    
    sourceSelector.appendChild(privacyBox);
    
    // Bouton de configuration pour serveur local
    const configBtn = document.createElement('button');
    configBtn.className = 'asr-config-btn';
    configBtn.textContent = '⚙️ Configurer mon serveur local';
    configBtn.onclick = () => showLocalServerConfig();
    
    // Bouton de démarrage
    const startBtn = document.createElement('button');
    startBtn.className = 'asr-start-btn';
    startBtn.textContent = '🎤 Démarrer la dictée';
    startBtn.onclick = () => startRecognition(select.value, sources);
    
    // Zone de sortie
    const output = document.createElement('div');
    output.id = 'asr-output';
    output.className = 'asr-output';
    output.setAttribute('aria-live', 'polite');
    output.textContent = 'Prêt à démarrer. Choisissez une méthode et cliquez sur "Démarrer".';
    
    // Assemblage
    container.appendChild(header);
    container.appendChild(sourceSelector);
    container.appendChild(configBtn);
    container.appendChild(startBtn);
    container.appendChild(output);
    
    document.body.appendChild(container);
  }

  /**
   * Afficher la configuration du serveur local
   */
  function showLocalServerConfig() {
    // Supprimer modal existante si présente
    const existing = document.querySelector('.asr-modal');
    if (existing) existing.remove();
    
    const modal = document.createElement('div');
    modal.className = 'asr-modal';
    modal.onclick = (e) => {
      if (e.target === modal) modal.remove();
    };
    
    const content = document.createElement('div');
    content.className = 'asr-modal-content';
    content.onclick = (e) => e.stopPropagation();
    
    content.innerHTML = `
      <h3>🏠 Configurer votre serveur Whisper local</h3>
      
      <p>Si vous avez installé <strong>whisper.cpp</strong> sur votre ordinateur, 
      vous pouvez l'utiliser pour un traitement 100% privé.</p>
      
      <div class="asr-steps">
        <h4>📋 Instructions rapides :</h4>
        <ol>
          <li>Téléchargez <a href="https://github.com/ggerganov/whisper.cpp" target="_blank" rel="noopener">whisper.cpp</a></li>
          <li>Compilez et téléchargez un modèle (ex: base)</li>
          <li>Lancez le serveur : <code>./server -m models/ggml-base.bin</code></li>
          <li>Le serveur démarre sur <code>http://localhost:8080</code></li>
          <li>Configurez l'URL ci-dessous et testez</li>
        </ol>
      </div>
      
      <label for="asr-local-url">
        <strong>URL de votre serveur local :</strong>
      </label>
      <input type="url" id="asr-local-url" 
        value="${localStorage.getItem('asr_user_whisper_url') || 'http://localhost:8080'}"
        placeholder="http://localhost:8080" 
        class="asr-input" />
      
      <div class="asr-modal-actions">
        <button id="asr-test-local" class="asr-btn-secondary">🧪 Tester la connexion</button>
        <button id="asr-save-local" class="asr-btn-primary">💾 Enregistrer</button>
        <button id="asr-cancel-local" class="asr-btn-secondary">Annuler</button>
      </div>
      
      <div id="asr-test-result" class="asr-test-result"></div>
    `;
    
    modal.appendChild(content);
    document.body.appendChild(modal);
    
    // Tester la connexion
    content.querySelector('#asr-test-local').onclick = async () => {
      const url = content.querySelector('#asr-local-url').value;
      const result = content.querySelector('#asr-test-result');
      result.textContent = '⏳ Test en cours...';
      result.className = 'asr-test-result';
      
      const isAvailable = await testEndpoint(url);
      if (isAvailable) {
        result.innerHTML = '✅ <strong>Connexion réussie !</strong> Votre serveur répond correctement.';
        result.className = 'asr-test-result success';
      } else {
        result.innerHTML = '❌ <strong>Impossible de se connecter.</strong> Vérifiez que whisper.cpp est bien lancé sur ce port.';
        result.className = 'asr-test-result error';
      }
    };
    
    // Sauvegarder
    content.querySelector('#asr-save-local').onclick = () => {
      const url = content.querySelector('#asr-local-url').value.trim();
      if (!url) {
        alert('❌ Veuillez entrer une URL valide');
        return;
      }
      localStorage.setItem('asr_user_whisper_url', url);
      alert('✅ Configuration sauvegardée ! Rechargez la page pour voir la nouvelle option.');
      modal.remove();
    };
    
    // Annuler
    content.querySelector('#asr-cancel-local').onclick = () => {
      modal.remove();
    };
  }

  /**
   * Démarrer la reconnaissance avec la source choisie
   */
  async function startRecognition(sourceId, sources) {
    const source = sources.find(s => s.id === sourceId);
    const output = document.getElementById('asr-output');
    
    if (!source) {
      output.textContent = '❌ Source non trouvée';
      return;
    }
    
    output.textContent = `🎤 Démarrage avec : ${source.label}...`;
    
    switch (source.type) {
      case 'browser-api':
        startBrowserAPI(output);
        break;
      case 'server-remote':
        startServerRecognition(source.endpoint, output, false);
        break;
      case 'server-local':
        startServerRecognition(source.endpoint, output, true);
        break;
      case 'wasm':
        startWASM(output);
        break;
      default:
        output.textContent = '❌ Type de source non supporté';
    }
  }

  /**
   * Démarrer avec Web Speech API
   */
  function startBrowserAPI(output) {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      output.textContent = '❌ Web Speech API non disponible dans votre navigateur';
      return;
    }
    
    const recognition = new SpeechRecognition();
    recognition.lang = lang;
    recognition.interimResults = true;
    recognition.continuous = false;
    
    recognition.onstart = () => {
      output.textContent = '🎤 Écoute en cours... Parlez maintenant.';
    };
    
    recognition.onresult = (event) => {
      const transcript = Array.from(event.results)
        .map(result => result[0].transcript)
        .join('');
      output.textContent = transcript;
    };
    
    recognition.onerror = (event) => {
      output.textContent = `❌ Erreur: ${event.error}`;
      console.error('Speech API error', event);
    };
    
    recognition.onend = () => {
      const currentText = output.textContent;
      if (!currentText.includes('❌')) {
        output.textContent = currentText + '\n\n✅ Terminé';
      }
    };
    
    try {
      recognition.start();
    } catch (err) {
      output.textContent = `❌ Impossible de démarrer: ${err.message}`;
      console.error(err);
    }
  }

  /**
   * Démarrer avec serveur (site ou local)
   */
  function startServerRecognition(endpoint, output, isLocal = false) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      output.textContent = '❌ Votre navigateur ne permet pas l\'enregistrement audio.';
      return;
    }
    
    output.textContent = '📡 Demande d\'accès au microphone...';
    
    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(stream => {
        const mediaRecorder = new MediaRecorder(stream);
        const chunks = [];
        const startTime = Date.now();
        
        mediaRecorder.ondataavailable = (e) => chunks.push(e.data);
        
        mediaRecorder.onstop = async () => {
          const stopTime = Date.now();
          const durationSec = Math.max(0, (stopTime - startTime) / 1000);
          const blob = new Blob(chunks, { type: 'audio/webm' });
          
          output.textContent = '⏳ Envoi pour transcription...';
          
          try {
            let transcriptText;
            
            if (isLocal) {
              // Envoi direct au serveur local
              transcriptText = await sendToLocalServer(blob, endpoint, durationSec);
            } else {
              // Envoi au serveur du site via REST API WordPress
              transcriptText = await sendToSiteServer(blob, durationSec);
            }
            
            output.textContent = transcriptText || '❌ Pas de transcription reçue';
            
          } catch (err) {
            output.textContent = `❌ Erreur: ${err.message}`;
            console.error(err);
          }
          
          // Arrêter le stream
          stream.getTracks().forEach(track => track.stop());
        };
        
        output.textContent = '🎤 Enregistrement en cours (5 secondes)...';
        mediaRecorder.start();
        
        setTimeout(() => {
          if (mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
          }
        }, 5000);
      })
      .catch(err => {
        output.textContent = `❌ Accès au microphone refusé: ${err.message}`;
        console.error(err);
      });
  }

  /**
   * Envoyer au serveur local (whisper.cpp du visiteur)
   */
  async function sendToLocalServer(blob, endpoint, duration) {
    const formData = new FormData();
    formData.append('file', blob, 'recording.webm');
    formData.append('language', lang);
    
    const response = await fetch(endpoint + '/inference', {
      method: 'POST',
      body: formData
    });
    
    if (!response.ok) {
      throw new Error(`Serveur local: ${response.status} ${response.statusText}`);
    }
    
    const data = await response.json();
    return data.text || data.transcript || '';
  }

  /**
   * Envoyer au serveur du site (via WordPress REST API)
   */
  async function sendToSiteServer(blob, duration) {
    const formData = new FormData();
    formData.append('file', blob, 'recording.webm');
    formData.append('language', lang);
    formData.append('duration', duration);
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000);
    
    try {
      const response = await fetch(restUrl, {
        method: 'POST',
        body: formData,
        headers: {
          'X-WP-Nonce': nonce
        },
        credentials: 'include',
        signal: controller.signal
      });
      
      clearTimeout(timeoutId);
      
      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(`HTTP ${response.status}: ${errorText}`);
      }
      
      const data = await response.json();
      
      // Si le traitement est asynchrone, on poll le statut
      if (data.status === 'queued' && data.attachment_id) {
        return await pollTranscription(data.attachment_id);
      }
      
      return data.transcript || '';
      
    } finally {
      clearTimeout(timeoutId);
    }
  }

  /**
   * Polling pour récupérer la transcription
   */
  async function pollTranscription(attachmentId, maxAttempts = 40) {
    const output = document.getElementById('asr-output');
    
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      await new Promise(resolve => setTimeout(resolve, 3000));
      
      try {
        const url = `${statusUrl}/${attachmentId}?_=${Date.now()}`;
        const response = await fetch(url, { 
          credentials: 'include',
          headers: {
            'X-WP-Nonce': nonce
          }
        });
        
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.status === 'completed') {
          return data.transcript || 'Transcription vide';
        } else if (data.status === 'error') {
          throw new Error(data.error || 'Erreur de traitement');
        } else if (data.status === 'blocked_quota') {
          throw new Error('Quota mensuel dépassé');
        } else if (data.status === 'needs_duration') {
          throw new Error('Durée inconnue - transcription non effectuée');
        }
        
        // Toujours en traitement
        output.textContent = `⏳ Transcription en cours... (${attempt + 1}/${maxAttempts})`;
        
      } catch (err) {
        console.error('Poll error:', err);
        // Continuer à essayer sauf si c'est une erreur finale
        if (err.message.includes('Quota') || err.message.includes('Durée')) {
          throw err;
        }
      }
    }
    
    throw new Error('Temps d\'attente dépassé pour la transcription');
  }

  /**
   * Démarrer avec WASM (à implémenter)
   */
  function startWASM(output) {
    output.innerHTML = `
      <strong>⚠️ WASM en cours de développement</strong>
      <p>Le traitement WASM nécessite le téléchargement d'un modèle (~40-150 MB selon la taille).</p>
      <p>Cette fonctionnalité sera disponible prochainement.</p>
      <p>En attendant, utilisez une autre méthode.</p>
    `;
  }

  // Initialisation au chargement de la page
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createUI);
  } else {
    createUI();
  }
})();
