// Frontend minimal : Web Speech API -> fallback MediaRecorder upload
(function() {
  const restUrl = window.ASRSettings && window.ASRSettings.restUrl;
  const nonce = window.ASRSettings && window.ASRSettings.nonce;
  const lang = window.ASRSettings && window.ASRSettings.lang || 'fr-FR';

  function createUI() {
    // Append a small floating control for demo purposes
    const container = document.createElement('div');
    container.id = 'asr-floating';
    container.style = 'position:fixed;bottom:20px;right:20px;z-index:9999';
    const btn = document.createElement('button');
    btn.textContent = 'Parler';
    btn.className = 'asr-button';
    const out = document.createElement('div');
    out.id = 'asr-output';
    out.setAttribute('aria-live','polite');
    out.style = 'margin-top:8px;max-width:300px;background:#fff;padding:8px;border:1px solid #ccc;border-radius:4px';
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
        // fallback to recorder
        startRecorder(outEl);
      };
      recog.onend = () => {
        // end
      };
      try {
        recog.start();
      } catch (err) {
        console.warn('SpeechRecognition start failed', err);
        startRecorder(outEl);
      }
    } else {
      // fallback to media recorder
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
      mediaRecorder.ondataavailable = (e) => chunks.push(e.data);
      mediaRecorder.onstop = () => {
        const blob = new Blob(chunks, { type: 'audio/webm' });
        outEl.textContent = 'Envoi au serveur pour transcription…';
        uploadBlob(blob).then(res => {
          if (res && res.transcript) {
            outEl.textContent = res.transcript;
          } else if (res && res.status === 'queued') {
            outEl.textContent = 'Transcription en file d\'attente (vérifiez l\'admin pour le résultat).';
          } else {
            outEl.textContent = 'Erreur lors de la transcription';
          }
        }).catch(err => {
          console.error(err);
          outEl.textContent = 'Erreur réseau.';
        });
      };
      // simple UI: record 5 seconds
      mediaRecorder.start();
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

  async function uploadBlob(blob) {
    const form = new FormData();
    form.append('file', blob, 'recording.webm');
    form.append('language', lang);
    const res = await fetch(restUrl, {
      method: 'POST',
      body: form,
      headers: {
        'x-wp-nonce': nonce
      },
      credentials: 'include'
    });
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  }

  // Auto init
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createUI);
  } else {
    createUI();
  }
})();