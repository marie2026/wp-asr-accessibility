# Guide d'administration — Configurer et tester Web Speech API, WASM et serveur self‑hosted (whisper.cpp)

Ce guide explique, pas à pas, comment configurer et tester les trois modes de reconnaissance vocale supportés par le plugin ASR Accessibility :
- Web Speech API (client / navigateur) — mode par défaut gratuit et sans serveur ;
- WASM (client-side whisper/Vosk) — traitement 100% local dans le navigateur (expérimental) ;
- Serveur self‑hosted (whisper.cpp) — précision et confidentialité, nécessite une instance disponible.

Pour chaque option : prérequis, configuration dans le plugin, test simple et points d’attention (performances, vie privée).

---

## Rappels rapides (recommandations)
- Par défaut, activez uniquement Web Speech API (aucun coût). N’activez l’envoi vers un service externe qu’en connaissance de cause et après avoir défini un quota.
- Si vous voulez confidentialité totale sans hébergement, envisagez WASM (mais lourde UX).
- Si vous voulez fiabilité et contrôle, déployez whisper.cpp sur un VPS (always‑on recommandé).

---

## 1) Web Speech API (recommandé par défaut)

Quoi
- API JavaScript fournie par le navigateur (window.SpeechRecognition). Traitement fait par le navigateur ou par le backend du fournisseur du navigateur (ex. Google pour Chromium).

Prérequis
- Navigateur compatible : Chrome/Edge (Chromium) offrent la meilleure prise en charge ; Firefox / Safari ont un support limité ou différent.
- HTTPS (le site doit être servi en HTTPS pour accéder au microphone).
- Plugin ASR : aucun paramétrage serveur requis pour ce mode.

Activation dans le plugin
1. Dans WordPress → Réglages → ASR Accessibility, laisser le mode sur `auto` ou `server`/`wasm` selon vos besoins. Pour usage Web Speech API, `auto` suffit.
2. Assurez‑vous que l’option « Autoriser envoi vers service externe » reste désactivée si vous ne voulez pas d’envois.

Test (en tant qu’admin)
1. Ouvrez votre site en HTTPS avec Chrome.
2. Cliquez sur le bouton `Parler` fourni par le plugin.
3. Autorisez l’accès au microphone quand le navigateur le demande.
4. Dites une phrase courte ; la transcription doit s’afficher en temps réel (ou juste après).
5. Si rien ne s’affiche : ouvrez la console JS (F12) et vérifiez les erreurs liées à SpeechRecognition.

Points d’attention
- Vérifiez la politique de confidentialité : le navigateur peut envoyer l’audio à ses propres services.
- Compatibilité : testez sur plusieurs navigateurs.
- Fiabilité : dépend du navigateur et peut varier selon les versions.

---

## 2) WASM côté client (expérimental)

Quoi
- Exécuter un modèle STT (whisper.cpp, Vosk, Coqui) compilé en WebAssembly directement dans le navigateur => traitement 100% local.

Prérequis
- Plugin : option WASM activée dans les réglages.
- Les fichiers de modèles (téléchargement lors de la 1re utilisation) : potentiellement très volumineux (de dizaines à centaines de Mo).
- Visiteurs avec CPU/mémoire suffisants (desktop recommandé).

Activation dans le plugin
1. Aller Réglages → ASR Accessibility.
2. Cocher « Activer WASM (expérimental) ».
3. Documenter auprès des admins/usage : pré-annoncer que le chargement initial peut être long et coûteant en bande passante.

Test (en tant qu’admin)
1. Activez WASM et sauvegardez.
2. Ouvrez une page publique, acceptez le téléchargement du modèle si le plugin le propose.
3. Cliquez `Parler`. Le navigateur va exécuter la transcription localement ; vérifiez la transcription affichée.
4. Si l’interface indique un téléchargement ou une erreur, vérifiez la console réseau pour la taille des fichiers et les erreurs CORS.

Points d’attention
- UX : téléchargement initial long. Informez l’utilisateur et proposez un bouton « Télécharger modèle ».
- Performances : sur mobiles ou machines faibles, la transcription peut être lente ou impossible.
- Confidentialité : excellente (traitement local). Coût monétaire : nul.
- Mise à jour des modèles : prévoir un mécanisme pour mettre à jour les modèles WASM.

---

## 3) Serveur self‑hosted (whisper.cpp) — recommandé pour production contrôlée

Quoi
- Déployer whisper.cpp (ou un wrapper HTTP) sur un VPS/instance Docker qui expose un endpoint HTTP `/transcribe`. Le plugin envoie les fichiers audio et récupère la transcription.

Avantages : confidentialité (données restent chez vous), performances contrôlées si la machine est dimensionnée, pas de coûts tiers si vous gérez l'hébergement.
Inconvénients : coût d’hébergement si vous n’avez pas déjà un serveur, nécessité d’administration.

Prérequis
- Un VPS / machine (recommandé : 2 vCPU et 4 GB RAM pour tiny/base ; plus pour small/medium ; GPU pour large).
- Docker + docker-compose (ou installation native).
- Nginx en frontal pour TLS et protection.
- Stockage permanent pour modèles (volume Docker).
- Script/wrapper HTTP autour de whisper.cpp (ex : petit service Python/Flask ou binaire C++ exposant POST /transcribe).

Exemple minimal de test (côté admin) — curl
- Tester que votre service répond (ici `https://asr.example.com/transcribe`) :
```bash
curl -I -H "Authorization: Bearer <VOTRE_CLE>" https://asr.example.com/transcribe
```
- Exemple d’envoi de fichier (multipart/form-data) :
```bash
curl -X POST "https://asr.example.com/transcribe" \
  -H "Authorization: Bearer <VOTRE_CLE>" \
  -F "file=@/chemin/vers/recording.wav" \
  -F "language=fr-FR"
```
Réponse attendue (JSON simplifié) :
```json
{
  "transcript": "Bonjour tout le monde",
  "segments": [ { "start": 0.0, "end": 2.3, "text": "Bonjour tout le monde" } ]
}
```

Déployer un service simple (recommandation rapide)
1. Préparez un Dockerfile ou utilisez une image existante qui compile/installe whisper.cpp.
2. Exposez un petit wrapper HTTP (Flask, FastAPI ou équivalent) qui :
   - précharge le modèle au démarrage ;
   - accepte POST /transcribe et retourne JSON.
3. Protégez le service avec TLS (nginx reverse proxy) et header Authorization Bearer.
4. Exécutez comme service systemd ou via docker-compose up -d pour le garder always‑on.

Test depuis le plugin
- Dans WP → Réglages → ASR Accessibility :
  - Renseignez `URL du service whisper` (ex. https://asr.example.com/transcribe).
  - Renseignez la `Clé API` (optionnelle).
  - Activez « Autoriser envoi vers service externe » si vous voulez que le plugin utilise ce service.
- Utilisez le bouton « Tester endpoint » dans la page de réglages.
- Envoyez un enregistrement depuis le frontend (ou uploadez via la médiathèque et relancez le job depuis l’admin) et vérifiez que le job aboutit et que `_asr_transcript` est rempli.

Conseils d’exploitation
- Always‑on : pour une UX fluide, gardez le service up et préchargez le modèle au boot.
- On-demand : possible mais cold-starts longs (chargement modèle).
- Concurrence : limiter le nombre de requêtes simultanées si la machine est petite.
- Logs : surveillez /var/log, journald ou les logs Docker pour diagnostiquer les erreurs.

---

## Gestion du quota et sécurité (plugin)
- Par défaut, l’envoi externe est désactivé (aucun coût).
- Si vous activez l’envoi externe :
  - Définissez `Quota mensuel (minutes)` dans les réglages pour éviter toute facturation surprise.
  - Le plugin compte les minutes (arrondies par minute) basées sur la durée fournie par le frontend ; il bloque l’envoi si le quota serait dépassé.
  - Vous recevrez une alerte par mail si le quota dépasse le pourcentage configuré (ex. 80%).
- Toujours configurer la clé API côté plugin et protéger votre endpoint avec TLS + header auth.

---

## Foire aux problèmes (troubleshooting)

1. Rien ne se passe sur le frontend (Web Speech API)
- Vérifier HTTPS, autorisation micro, console JS (erreurs), navigateur incompatible.
- Tester sur Chrome pour isoler.

2. Upload -> REST retourne erreur 500
- Vérifier `wp-content/uploads` permissions, regarder les erreurs PHP/nginx, vérifier `wp_handle_upload` résultat.

3. Job reste en `needs_duration`
- Le frontend n’a pas envoyé la durée (ex. upload direct via médiathèque). Relancez manuellement depuis l’admin ou utilisez ffprobe pour extraire la durée automatiquement.

4. Service whisper renvoie erreur ou timeout
- Vérifier logs du service, augmenter `curl` timeout, s’assurer que le modèle est chargé ; vérifier protection/auth TLS.

5. Dépassement de quota inattendu
- Vérifier la clé du mois utilisée (option `asr_usage_YYYY_MM`), voir attachments `_asr_quota_counted`, vérifier envois automatiques ou bots.

---

## Recommandations finales
- Pour la quasi‑totalité des sites qui veulent zéro coût : laissez Web Speech API par défaut, désactivez l’envoi externe.
- Pour confidentialité stricte sans serveur : proposer WASM en option, mais avertir sur UX.
- Pour production fiable et maîtrise de la confidentialité : déployer whisper.cpp sur un petit VPS always‑on ; configurez quota et protections dans le plugin.

---

## Sécurisation de la clé API (RECOMMANDÉ)

Pour éviter d'exposer votre clé API dans la base de données, ajoutez-la dans `wp-config.php` :

```php
define('ASR_WHISPER_API_KEY', 'votre_clé_secrète_ici');
```