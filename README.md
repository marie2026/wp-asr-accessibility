```markdown
# ASR Accessibility — Squelette de plugin

But: plugin skeleton pour proposer une fonctionnalité Speech→Text (accessibilité), avec :
- Web Speech API en client,
- fallback enregistrement -> upload -> traitement sur serveur (whisper.cpp ou autre),
- settings admin pour configurer URL du service et clé.

Installation rapide
1. Copier les fichiers dans wp-content/plugins/asr-accessibility/
2. Activer le plugin depuis l'administration WP.
3. Aller dans Réglages → ASR Accessibility et renseigner :
   - URL du service whisper (ex: https://asr.example.com/transcribe)
   - Clé API si nécessaire
   - Langue par défaut
4. Frontend : un bouton "Parler" apparaît en bas à droite (demo). Tu peux remplacer l'UI par une intégration dans ton thème.

Notes techniques et étapes suivantes
- Ce squelette utilise wp_schedule_single_event pour la queue. Pour production, je recommande Action Scheduler (plus résilient) ou un worker dédié.
- Le service whisper côté serveur doit exposer une API qui accepte multipart/form-data (file) et renvoie JSON { transcript: "..." }.
- À ajouter : quotas, logs, suppression automatique des fichiers audio, génération VTT/SRT, support WASM, tests, sécurité renforcée (IP restrictions), UI d'intégration plus accessible.
```