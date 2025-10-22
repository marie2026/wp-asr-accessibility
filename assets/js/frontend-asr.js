// Frontend minimal : Web Speech API -> fallback MediaRecorder upload -> polling status
(function() {
  const restUrl = window.ASRSettings && window.ASRSettings.restUrl;
  const statusUrl = window.ASRSettings && window.ASRSettings.statusUrl;
  const nonce = window.ASRSettings && window.ASRSettings.nonce;
  const lang = window.ASRSettings && window.ASRSettings.lang || 'fr-FR';

  function createUI() {
    const container = document.createElement('div');
    container.id = 'asr-floating';
    container.style = 'position:fixed;bottom:20px;right:20px;z-index:9999';
    const btn = document.createElement('button');
    btn.textContent = 'Parler';
    btn.className = 'asr-button';
    btn.setAttribute('aria-label','Activer la dictée');
    const out = document.createElement('div');
    out.id = 'asr-output';
    out.setAttribute('aria-live','polite');
    out.style = 'margin-top:8px;max-width:320px;background:#fff;padding:8px;border:1px solid #ccc;border-radius:4px';
    container.appendChild(btn);
    container.appendChild(out);
    document.body.appendChild(container);

    btn.addEventListener('click', () => startRecognition(out));
  }

  function startRecognition(outEl) {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognition) {
      const recog = new SpeechRecognition();
      recog.lang = lang;
      recog.interimResults = true;
      recog.onresult = (e) => {
        let text = Array.from(e.results).map(r => r[0].transcript).join('');
        outEl.textContent = text;
      };
      recog.onerror = (e) => {
        console.error('Speech API error', e);
        startRecorder(outEl);
      };
      recog.onend = () => {
        // stop
      };
      try {
        recog.start();
      } catch (err) {
        console.warn('SpeechRecognition start failed', err);
        startRecorder(outEl);
      }
    } else {
      startRecorder(outEl);
    }
  }

  function startRecorder(outEl) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      outEl.textContent = 'Impossible d\'enregistrer : votre navigateur ne le permet pas.';
      return;
    }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
      const mediaRecorder = new MediaRecorder(stream);
      const chunks = [];
      let startTime = Date.now();
      mediaRecorder.ondataavailable = (e) => chunks.push(e.data);
      mediaRecorder.onstop = () => {
        const stopTime = Date.now();
        const durationSec = Math.max(0, (stopTime - startTime) / 1000);
        const blob = new Blob(chunks, { type: 'audio/webm' });
        outEl.textContent = 'Envoi au serveur pour transcription…';
        uploadBlob(blob, durationSec).then(res => {
          if (res && res.transcript) {
            outEl.textContent = res.transcript;
          } else if (res && res.status === 'queued' && res.attachment_id) {
            pollStatus(res.attachment_id, outEl, 0);
          } else {
            outEl.textContent = 'Erreur lors de la transcription';
          }
        }).catch(err => {
          console.error(err);
          outEl.textContent = 'Erreur réseau : ' + (err.message || 'timeout ou erreur serveur');
        });
      };
      // record until user releases or for a default 5s demo
      mediaRecorder.start();
      startTime = Date.now();
      outEl.textContent = 'Enregistrement (5s)...';
      setTimeout(() => {
        mediaRecorder.stop();
        stream.getTracks().forEach(t => t.stop());
      }, 5000);
    }).catch(err => {
      console.error(err);
      outEl.textContent = 'Accès au microphone refusé.';
    });
  }

  // CORRECTION: Ajouter timeout et meilleure gestion d'erreur
  async function uploadBlob(blob, durationSec, timeoutMs = 30000) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    
    try {
      const form = new FormData();
      form.append('file', blob, 'recording.webm');
      form.append('language', lang);
      form.append('duration', durationSec); // IMPORTANT: send duration in seconds so server can charge quota
      
      const res = await fetch(restUrl, {
        method: 'POST',
        body: form,
        headers: {
          'x-wp-nonce': nonce
        },
        credentials: 'include',
        signal: controller.signal
      });
      
      if (!res.ok) {
        throw new Error('HTTP ' + res.status + ': ' + res.statusText);
      }
      return res.json();
    } finally {
      clearTimeout(timeoutId);
    }
  }

  function pollStatus(attachmentId, outEl, attempt) {
    attempt = attempt || 0;
    if (attempt > 40) {
      outEl.textContent = 'Temps d\'attente dépassé pour la transcription.';
      return;
    }
    const url = statusUrl + '/' + attachmentId + '?_=' + Date.now();
    fetch(url, { credentials: 'include' }).then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(data => {
      if (!data) {
        outEl.textContent = 'Erreur lecture statut';
        return;
      }
      if (data.status === 'completed') {
        outEl.textContent = data.transcript || 'Transcription vide';
      } else if (data.status === 'processing' || data.status === 'queued') {
        outEl.textContent = 'Transcription en cours…';
        setTimeout(() => pollStatus(attachmentId, outEl, attempt + 1), 3000);
      } else if (data.status === 'error') {
        outEl.textContent = 'Erreur traitement : une erreur est survenue. Merci de réessayer.';
      } else if (data.status === 'needs_duration') {
        outEl.textContent = 'Durée inconnue côté serveur — transcription non effectuée. Administrateur doit relancer manuellement.';
      } else if (data.status === 'blocked_quota') {
        outEl.textContent = 'Quota dépassé — transcription non effectuée.';
      } else {
        outEl.textContent = 'Statut : ' + data.status;
      }
    }).catch(err => {
      console.error(err);
      setTimeout(() => pollStatus(attachmentId, outEl, attempt + 1), 3000);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createUI);
  } else {
    createUI();
  }
})();
