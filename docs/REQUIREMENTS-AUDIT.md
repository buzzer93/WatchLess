# Audit du cahier des charges

État de contrôle après passage à Qwen3.5 9B. Cet audit distingue les fonctions présentes de leur validation réelle ; il ne constitue pas une déclaration de fidélité parfaite du modèle.

| Exigences | Implémentation et preuves | État |
| --- | --- | --- |
| 1–3, 14 : MVP Symfony/PHP/SQLite, formulaire | composer.lock, Dockerfile PHP 8.3, contrôleur, Twig ; tests HTTP en mémoire ; bibliothèque HTTP 200 | Fonctionnel |
| 4, 6, 24 : URL, métadonnées, yt-dlp | YouTubeUrl, YtDlpService, CommandRunner ; tests URL et arguments sans shell ; deux vidéos réelles de 19 s et 1 750 s | Fonctionnel ; erreurs externes dépendantes de YouTube |
| 5, 20 : sous-titres, fallback Whisper, texte intégral, temporaires | YouTubeVideoService, SubtitleParser, TranscriptionResult, LocalWhisperProvider ; tests priorité/fallback/nettoyage ; essai audio réel court documenté | Fonctionnel ; précision STT non garantie |
| 7, 8 : stockage et doublons | Video, migration, SubmitVideo ; transaction et contrainte unique ; tests doublons et rollback de file | Vérifié |
| 9, 15 : travail asynchrone, statuts | Messenger Doctrine, AnalyzeVideoHandler, app.js ; tests pipeline et reprise ; worker Docker actif | Vérifié |
| 10, 16 : fiche, historique, recherche | contrôleur et templates, Markdown sûr ; tests HTTP de recherche et rendu | Vérifié |
| 11, 13 : rapport détaillé, 14 rubriques, LLM interchangeable | VideoSummaryPrompt, VideoSummaryGenerator, LlmProviderInterface, adaptateur compatible ; génération réelle Qwen3.5 réussie, relecture factuelle intégrée | Fonctionnel ; risque résiduel d’ajouts non étayés du modèle |
| 12 : longues transcriptions | TranscriptChunker et réduction récursive ; tests limites UTF-8 et fusion ; vidéo de 29 min terminée (32 120 caractères de transcription, 39 707 avec horodatage, 4 667 de rapport) | Mécanisme vérifié ; qualité détaillée du rapport long à contrôler |
| 17–19 : extensions devenues demandées | Sujets avec sélection de vidéos, contexte utilisateur et synthèse multi-sources asynchrone ; révisions contre les résultats obsolètes | Déployé ; tests HTTP, file et concurrence réussis |
| 21 : configuration privée, protection publique | .gitignore/.dockerignore, secret persistant généré, Caddy basic_auth d'exemple, écoute loopback | Prévu ; pas de déploiement public demandé ni effectué |
| 22 : logs par étapes | handler et services, journaux tournants ; étapes observées dans les logs Docker | Vérifié |
| 23 : tests sans fournisseur externe | tests unitaires et intégration SQLite en mémoire, doubles HTTP/YouTube/STT/LLM | 57 tests / 499 assertions sous PHP 8.4 Windows et PHP 8.3 Docker |
| 25–28 : parcours bout en bout et information exploitable | essais réels sous-titres + fallback, interface consultable, stockage durable et rapport | Parcours fonctionnel ; audit de fidélité non terminé |
| Ajout utilisateur : suppression | confirmation GET, POST CSRF, verrou partagé avec worker ; tests suppression/recréation/message obsolète/conflit actif | Vérifié |
| Ajouts utilisateur : tout Docker, Qwen3.5 9B | Compose app/web/worker/Ollama, modèle téléchargé et alias actif, Whisper local | Vérifié |

Limite de qualité connue : la relecture a corrigé les inventions sur un texte témoin simple, mais le contrôle multi-sources montre encore des interprétations injustifiées. Les tests applicatifs sont réussis ; ils ne garantissent pas la fidélité de chaque réponse de Qwen3.5 9B. Le dernier renforcement du prompt est déployé sans prétendre que ce risque est résolu. Les rapports anciens ne sont pas réécrits automatiquement et les assertions du modèle ne sont pas vérifiées extérieurement. La vidéo 4, interrompue précédemment, reste prise en charge par la file persistante avec reprise automatique.
