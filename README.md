```markdown
# ASR Accessibility — MVP

But
- MVP pour ajouter une fonctionnalité Speech→Text accessible dans WordPress.
- Priorité : UX public (Web Speech API) + fallback upload -> processing (whisper server or placeholder).

Installation
1. Copier le dossier `asr-accessibility` dans `wp-content/plugins/`.
2. Activer le plugin depuis l'administration WordPress.
3. Aller Réglages → ASR Accessibility et configurer :
   - URL du service whisper (si tu as déployé whisper.cpp en tant que service).
   - Clé API (optionnelle).
   - Langue par défaut.
   - Option de suppression automatique des fichiers audio après traitement.

Fonctionnement (MVP)
- Frontend :
  - Si le navigateur supporte Web Speech API, il est utilisé en priorité.
  - Sinon : le plugin enregistre 5s (MediaRecorder), upload au REST endpoint `/wp-json/asr/v1/transcribe`.
  - Le frontend poll `/wp-json/asr/v1/status/<attachment_id>` pour récupérer la transcription finale.
- Backend :
  - Le REST route accepte l'upload et crée un attachment WP.
  - Un job asynchrone est planifié (wp_schedule_single_event) pour traiter le fichier.
  - Le processor :
    - Si `asr_whisper_url` est configurée, il envoie le fichier à cette URL (multipart/form-data) et attend une réponse JSON `{ "transcript": "..." }`.
    - Sinon il écrit une transcription placeholder indiquant que le service n'est pas configuré (MVP).

Sécurité / limites
- Simple rate limiting par IP sur les uploads anonymes (limite par heure).
- Pour la production, je recommande :
  - Utiliser Action Scheduler pour la queue.
  - Ajouter quotas / alertes pour éviter coûts sur services externes.
  - Déployer whisper.cpp dans un container sécurisé et protégé (TLS + auth).
  - Revoir la stratégie nonce/auth pour autoriser ou restreindre l'usage public selon ton site.

Prochaines améliorations recommandées
- Remplacer wp_schedule_single_event par Action Scheduler.
- Génération automatique VTT/SRT et attachement au média.
- Gestion fine des quotas, monitoring, logs.
- Docker-compose + nginx + systemd pour déployer whisper.cpp en tant que service.
- Implémenter WASM client pour usage privé (expérimental).

```

## Sécurité et recommandations de déploiement

### Pour les administrateurs de sites

1. **Rate limiting** : Le plugin limite les uploads anonymes à 3/heure par IP. 
   Pour un usage intensif, demandez aux utilisateurs de se connecter.

2. **Clé API** : Ne stockez JAMAIS votre clé dans le code. Utilisez wp-config.php :
   ```php
   define('ASR_WHISPER_API_KEY', 'votre_clé');