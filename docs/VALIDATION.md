# Vérifications du MVP — 25 septembre 2026

## Tests automatisés

- PHP 8.4.14 sur Windows : **40 tests, 365 assertions**, tous réussis.
- PHP 8.3.33 dans Docker Linux : **40 tests, 365 assertions**, tous réussis.
- Conteneur de services Symfony, templates Twig, YAML, mapping Doctrine et schéma SQLite validés.
- Les tests utilisent SQLite en mémoire et des mocks ; aucun appel réseau aux fournisseurs.

Commandes reproductibles :

```sh
docker compose --profile test run --build --rm tests
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:twig templates
docker compose exec app php bin/console doctrine:schema:validate
docker compose exec worker php bin/console watchless:doctor
```

## Essais manuels réels

- Démarrage des images PHP-FPM, Caddy, worker et Ollama ; migrations automatiques et volume SQLite persistant.
- Interface affichée dans le navigateur, validation d’URL invalide et soumission avec CSRF.
- Vidéo publique `jNQXAC9IVRw` (« Me at the zoo », 19 secondes) : métadonnées récupérées, sous-titres manuels anglais enregistrés, rapport généré par Ollama, état `completed`, file Messenger vidée sans échec.
- Fallback audio forcé séparément sur cette même vidéo : téléchargement avec yt-dlp, conversion FFmpeg et Whisper `base` local. Résultat : 192 caractères, 3 segments horodatés, langue `en`, source `whisper-local`, aucun répertoire temporaire restant.
- Le petit modèle Qwen2.5 3B a produit des formulations non étayées lors de l’essai de rapport. Le profil par défaut a été relevé à 7B et le prompt renforcé pour préciser la fidélité, la traduction contextuelle et l’absence de vérification externe.

## Limites de cette validation

Le découpage long et la réduction hiérarchique ont été testés avec des textes synthétiques et un LLM simulé. Aucun lot réel de vidéos d’une heure n’a été traité dans cette vérification. L’adaptateur API OpenAI a été testé avec des réponses simulées, sans clé et sans dépense. L’exactitude d’un compte rendu généré ne peut être garantie par des tests structurels : la transcription d’origine reste consultable.


- Suppression : confirmation sans effet en GET, CSRF, suppression effective, nouvelle soumission et message obsolète testés ; conflit avec un worker actif refusé. Profil MiMo Docker validé et protocole testé avec réponse simulée (aucune clé Xiaomi utilisée).
- Essai réel Qwen2.5 7B : génération achevée, mais la relecture détecte encore des ajouts non étayés (explication du pourquoi des trompes, bruit final absent du texte) et une traduction ponctuelle incorrecte. Le rapport de démonstration existant est conservé. La qualité du LLM local reste une limite connue ; la compatibilité MiMo est préparée mais sa qualité reste à mesurer sans clé API.

## Passage à Qwen3.5 9B

- Profil Docker par défaut : `qwen3.5:9b`, alias `watchless`, Q4_K_M, contexte 16 384 tokens, `reasoning_effort=none`. Ollama 0.34.4.
- Modèle chargé en local sur CPU, environ 9,8 Gio consommés par Ollama sur les 15,6 Gio disponibles pour Docker.
- 40 tests / 367 assertions réussis sous PHP 8.4 Windows et PHP 8.3 Docker. Lint Symfony, Twig, YAML et schéma validés. Bibliothèque HTTP 200 après déploiement.
- Essai réel via le connecteur et le générateur de rapports sur un texte de sauvegarde de projet : terminé en 290,7 secondes, 14 rubriques produites, réponse complète acceptée. Le rapport ajoute cependant des informations absentes (coût logiciel, contraintes de temps, affirmations à vérifier inventées). Compatibilité technique validée ; fidélité insuffisante sur cet essai. Résultat local dans `var/qwen35-report.json`, sans modification des rapports existants.

## Essai de fidélité en cours

Le prompt candidat ajoute une preuve citée par rubrique, des interdictions explicites de déductions et une limite de longueur pour les sources brèves. Le test réel sur le texte de sauvegarde produit encore des rubriques associant un ajout et « Non abordé » ; ce prompt seul ne satisfait pas le contrôle. Résultat : `var/qwen35-grounded-report.json` (177,5 secondes).

Un second essai de correction factuelle est en cours via `var/review-qwen35.php`, avec sortie attendue dans `var/qwen35-reviewed-report.json`. Il ne modifie pas les vidéos enregistrées. Le prompt candidat est dans le code source mais pas encore déployé dans les images de production. Le mécanisme de relecture reste expérimental, non intégré au générateur tant que son résultat réel n'est pas contrôlé.

Sept tests additionnels de CommandRunner passent sur Windows : catégories d'erreurs et délai, sans fuite de stderr sensible (7 tests / 14 assertions). Les 40 tests existants passent après la modification du prompt (367 assertions). Le prochain passage de suite complète devra inclure les sept nouveaux cas.
- Contrôle réel complémentaire : la vidéo de 366 secondes (id 3) a terminé sous Qwen3.5 9B. Sa transcription de 6 480 caractères et son rapport sont persistés. La relecture comparée observe des ajouts (comparaisons à Discord/Slack, développement non sourcé de MCP) et la transformation de bruits ASR en noms certains. Le prompt candidat impose désormais de signaler les noms/version incohérents comme transcription incertaine. Aucun remplacement du rapport utilisateur n'a été effectué.
- Suite complète sur Windows après ajout des contrôles CommandRunner : **47 tests, 381 assertions**, tous réussis.

## Intégration de la relecture et reprise après arrêt — 26 septembre 2026

- Le test de correction du texte témoin est terminé : les affirmations inventées sur les coûts, l'automatisation et les garanties ont été retirées. Fichier local : `var/qwen35-reviewed-report.json`. Son champ `seconds` vaut 0 par erreur de mesure dans le script exploratoire et ne constitue pas un temps de génération valide.
- La relecture est intégrée à VideoSummaryGenerator : chaque fragment de brouillon est revu face aux mêmes preuves finales, l'échec ne publie pas le brouillon. Les notes intermédiaires conservent des extraits, les incertitudes de transcription sont explicitement demandées. Les tests vérifient que tous les fragments sont revus, que les bornes sont respectées et qu'un échec est propagé.
- Contexte Qwen3.5 porté à 32 768 tokens pour la paire source/brouillon. Essai réel avec `reasoning_effort=none` terminé (« OK »), Ollama indique 32 768 tokens et environ 7,7 Gio utilisés sur 15,6 Gio disponibles lors du contrôle.
- Docker avait cessé de démarrer : sockets Windows orphelins dans `Docker/run` et `docker-secrets-engine`. Ces seuls dossiers de communication, contenant des fichiers de 0 octet, ont été renommés en sauvegardes datées puis recréés ; aucun volume de données supprimé. Docker Engine répond de nouveau.
- La réservation du message de la vidéo 4 datait du 25 septembre à 21:49 et empêchait sa reprise avant 24 h. Le profil Docker/Linux reçoit un keepalive de 60 s et une expiration à 300 s ; le profil Windows conserve son délai long faute de PCNTL. Un test réel de transport Doctrine en mémoire vérifie le renouvellement sans double distribution et la récupération après expiration.

## Transcription unique — 26 septembre 2026

- La colonne de transcription horodatée en doublon a été supprimée. `video.transcript` est désormais la source canonique ; `transcriptText()` retire les préfixes horaires à la volée pour le LLM et la lecture simple.
- La migration reprend les valeurs horodatées existantes avant de supprimer l’ancien champ. La base Docker vérifiée ne contient plus que la colonne `transcript`.
- La fiche vidéo affiche un seul bloc « Transcription complète — horodatée » et indique que les horodatages sont intégrés à chaque ligne.

## Fallback cloud vers local — 26 septembre 2026

- `LLM_CLOUD_ENABLED=1` active un fournisseur OpenAI-compatible en priorité ; ses paramètres restent des variables d’environnement.
- Les réponses de quota/crédits épuisés (HTTP 402/429 ou corps explicitement signalé) basculent vers le fournisseur local. Les erreurs de clé ou de modèle restent visibles pour éviter de masquer une mauvaise configuration.
- Tests unitaires : fallback cloud → local et propagation d’une erreur d’authentification ; 65 tests / 576 assertions réussis sous PHP 8.4.

## Sujets et synthèse agrégée — 26 septembre 2026

### Parcours centré sur les sujets

### Relance des comptes rendus

La fiche vidéo propose une régénération à partir de la transcription existante, avec conservation du précédent rapport. Une transaction regroupe le changement de statut, l’invalidation des synthèses de sujets et la mise en file ; le verrou du worker et le statut empêchent les doubles relances. Les sujets restent à génération manuelle après ajout ou relance individuelle.

Validation : **63 tests / 569 assertions** réussis dans Docker PHP 8.3. Les nouveaux tests couvrent le CSRF, les doubles clics, la conservation du texte, la réutilisation de la transcription sans accès YouTube, le retour au sujet, le rollback d’une panne de file et le verrou d’analyse active. Interface déployée et boutons vérifiés dans le navigateur ; aucune génération personnelle déclenchée pendant ces vérifications.

L’accueil affiche désormais les sujets. Chaque sujet propose l’import direct d’un lien YouTube ; l’enregistrement de la vidéo, son rattachement et la mise en file sont atomiques. Les liens déjà connus réutilisent la vidéo existante. La page suit aussi les statuts des vidéos et la fiche vidéo propose un retour au sujet d’origine. La récupération d’anciennes vidéos reste une option secondaire repliée.

Validation : **60 tests / 539 assertions** réussis sur Windows PHP 8.4 et Docker PHP 8.3, dont le parcours accueil → création → import direct, les doublons dans plusieurs sujets, le CSRF, les URL invalides et le rollback en cas de panne de file. Accueil et champ d’import du sujet existant vérifiés dans le navigateur après déploiement. Le worker actif n’a pas été interrompu pour cette modification de parcours.

- Création et édition de sujets, contexte facultatif, sélection de plusieurs vidéos existantes, retrait sans suppression de la vidéo. Génération réservée aux sélections d’au moins deux comptes rendus terminés.
- Synthèse Messenger avec références `[Vn]`, réduction des rapports longs, relecture factuelle et rejet des références inconnues ou des sources omises. Les références pointent vers les vidéos YouTube enregistrées dans un instantané du rapport.
- Les tests vérifient les formulaires et le CSRF, l’envoi unique en file, la génération, l’idempotence, l’abandon d’un résultat après modification simultanée de la sélection, et l’invalidation du rapport lors de la suppression d’une vidéo. Aucun test ne modifie les vidéos personnelles : SQLite en mémoire.
- **57 tests / 499 assertions réussis** sous PHP 8.4.14 Windows et PHP 8.3.35 Docker. Conteneur Symfony, Twig, YAML, Compose et schéma Doctrine valides. Migration et interface déployées ; page `/topics` vérifiée dans le navigateur. Le style est versionné pour renouveler le cache des onglets déjà ouverts.
- Nouveau worker démarré : la réservation de la vidéo 4 a été reprise à 10:08:52 UTC, puis son renouvellement a été observé à 10:10:52 UTC. Le traitement de cette vidéo continue en arrière-plan.
- Essai réel de TopicSummaryGenerator avec Qwen3.5 9B sur deux courts comptes rendus de sauvegarde : **608,5 secondes**, rapport et relecture terminés, références `[V1]` et `[V2]` présentes. Résultat : `var/topic-real-result.json`. Le rapport conserve les faits principaux mais ajoute encore des interprétations non justifiées (lieu de stockage implicite, étapes déduites, confusion entre silence et désaccord). La fidélité parfaite n’est donc pas validée. Les consignes sont renforcées sur ces erreurs et les sources brèves plafonnées à 350 mots ; cette dernière variante de prompt n’a pas encore été mesurée sur un nouvel essai réel. L’interface signale explicitement la nécessité de relire la synthèse.
