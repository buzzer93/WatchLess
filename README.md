# WatchLess

Application personnelle pour transformer une vidéo YouTube en transcription conservée et compte rendu détaillé en français. Symfony 7.4, PHP 8.3, Doctrine/SQLite, Twig, Messenger. Interface sans build JavaScript.

## Lancer avec Docker (configuration recommandée)

Prérequis : Docker Engine + Compose, ou Docker Desktop avec les conteneurs Linux. Tout le traitement est local ; aucune clé API n’est nécessaire.

```sh
docker compose up -d --build
```

Ouvrir **http://localhost:8090**. Le premier démarrage télécharge les images et le modèle Qwen3.5 9B (environ 6,6 Go). La compilation de l’image worker et le téléchargement initial peuvent prendre plusieurs minutes. Le worker attend la fin de l’initialisation du LLM. Whisper télécharge son modèle `base` au premier fallback audio puis le conserve dans le volume.

Les services :

| Service | Rôle |
| --- | --- |
| `web` | Caddy, interface sur `127.0.0.1:8090` |
| `app` | PHP-FPM, Symfony |
| `worker` | Messenger, yt-dlp, FFmpeg, Whisper local et runtime Node pour YouTube |
| `ollama` | LLM local, sans port publié sur l’hôte |
| `init` | Migrations, cache, permissions et secret aléatoire persistant |
| `model-init` | Télécharge le modèle et crée `watchless` avec un contexte de 32 768 tokens |

```sh
docker compose ps -a
docker compose logs -f worker model-init
docker compose exec worker php bin/console watchless:doctor
docker compose stop
docker compose start
```

Le profil est CPU pour fonctionner sans GPU. Une transcription d’une heure peut être lente sur CPU. Prévoir de l’espace disque pour les images, modèles et fichiers audio temporaires ; 16 Go de RAM alloués à Docker sont recommandés pour ce profil CPU et son contexte de 32K. La qualité du rapport dépend du modèle. Le profil actuel utilise Qwen3.5 9B ; les essais et leurs limites sont documentés dans docs/VALIDATION.md.

### Paramètres Docker

Compose lit les variables `WATCHLESS_PORT` (8090), `WATCHLESS_BASE_MODEL` (`qwen3.5:9b`) et `WATCHLESS_WHISPER_MODEL` (`base`) depuis l’environnement ou `.env.local` passé avec `--env-file` :

```dotenv
WATCHLESS_PORT=8090
WATCHLESS_BASE_MODEL=qwen3.5:9b
WATCHLESS_WHISPER_MODEL=small
```

```sh
docker compose --env-file .env.local up -d --build
```

Après un changement de modèle de base, relancer `model-init` avec le même fichier d’environnement puis le worker. `LLM_MODEL=watchless` désigne le modèle dérivé créé par ce service. Pour les autres réglages (langues, API externe, LM Studio), utiliser un `compose.override.yaml` et remplacer les variables du **worker**. Le fichier `.env.local` de l’hôte n’est jamais copié dans les images.

Images de base : `php:8.3-fpm-bookworm`, `composer:2`, `caddy:2-alpine`, `node:22-bookworm-slim`, `ollama/ollama:latest`. Les dépendances PHP sont verrouillées dans `composer.lock`, yt-dlp et Whisper dans `requirements-tools.txt`. Les tags Docker sont volontairement actualisables ; figer leurs digests pour un déploiement strictement reproductible.

### Données et sauvegardes

`app-data` contient `/app/var/watchless.db` (vidéos + file Messenger), les logs, le secret, les verrous, les fichiers temporaires et le cache Whisper. `ollama-models` conserve les poids du LLM. Un arrêt/redémarrage ne supprime pas ces données. **`docker compose down -v` les supprime.**

Pour une sauvegarde cohérente, arrêter `app` et `worker` avant de copier le volume `app-data`, ou utiliser la sauvegarde SQLite à chaud. Conserver le secret avec la base. Ne pas copier uniquement le fichier SQLite pendant une écriture. Une seule instance de worker est recommandée pour ce MVP personnel.

## Utilisation

1. Depuis l’accueil **Mes sujets**, créer ou ouvrir un sujet, puis y coller une URL `youtube.com/watch?v=…`, `youtu.be/…` ou `youtube.com/shorts/…` dans **Ajouter une vidéo à ce sujet**.
2. La fiche apparaît immédiatement, avec suivi léger du statut toutes les quatre secondes.
3. Le worker récupère les métadonnées puis les sous-titres manuels, sinon automatiques (français puis anglais par défaut, puis autre langue disponible).
4. Si les sous-titres sont absents ou inexploitables, il télécharge l’audio et appelle Whisper.
5. Une seule transcription est enregistrée, horodatée quand les segments sont disponibles, **avant** l’appel du LLM. Le texte sans horodatage est calculé à la volée.
6. Le rapport couvre les 14 rubriques du cahier des charges. Les vidéos restent dans `/videos`, avec recherche dans les titres et pagination.

Une même vidéo, même avec des paramètres de suivi différents, redirige vers sa fiche. Aucun nouveau coût de traitement n’est engagé. Les vidéos privées, supprimées, les blocages YouTube et les échecs des fournisseurs apparaissent sur la fiche avec l’étape concernée.

## Architecture

```text
Formulaire → SubmitVideo → transaction [Video + message Doctrine]
                          ↓
                   AnalyzeVideoHandler
                          ↓
     YouTubeVideoService → YtDlpService → Symfony Process
                          ↓
          Sous-titres JSON3 / audio + TranscriptionProviderInterface
                          ↓
              Transcription persistée dans Video
                          ↓
   TranscriptChunker → notes par morceau → réduction hiérarchique
                          ↓
             LlmProviderInterface → rapport final
```

Les deux interfaces interchangeables se trouvent dans `src/Transcription` et `src/Llm`. Les prompts sont dans `src/Prompt/VideoSummaryPrompt.php`. Aucun appel shell dans les contrôleurs. Les processus reçoivent des tableaux d’arguments ; les URL sont validées et normalisées avant utilisation. Le rendu Markdown supprime le HTML et les liens dangereux ; le formulaire est protégé par CSRF.

### Fournisseur cloud optionnel

Le fonctionnement par défaut reste entièrement local avec Ollama et Qwen. Pour utiliser un fournisseur compatible OpenAI en priorité, renseigner dans `.env.local` ou dans l’environnement du service `worker` :

```dotenv
LLM_CLOUD_ENABLED=1
LLM_CLOUD_BASE_URL=https://api.openai.com/v1
LLM_CLOUD_API_KEY=votre-cle
LLM_CLOUD_MODEL=le-modele-du-fournisseur
LLM_CLOUD_TOKEN_PARAMETER=max_completion_tokens
```

Le cloud est essayé pour chaque appel LLM. Une réponse HTTP 402/429 ou une réponse indiquant explicitement un quota, des crédits ou une facturation épuisés déclenche automatiquement le retour vers le modèle local configuré par `LLM_BASE_URL` et `LLM_MODEL`. Une erreur de clé, de modèle ou de connexion n’est pas masquée par un basculement silencieux. La clé reste une variable d’environnement et n’est jamais enregistrée dans SQLite ni affichée dans l’interface.

Le découpage privilégie segments/paragraphes, phrases, puis espaces. Une borne en **octets UTF-8** conserve aussi les très longues phrases sans corrompre les caractères. Les notes fusionnées sont redécoupées jusqu’à entrer dans le même budget ; une absence de convergence produit une erreur explicite plutôt qu’une troncature silencieuse. `TRANSCRIPT_CHUNK_BYTES=12000` laisse une marge avec le contexte Ollama configuré à 32K et une sortie de 6000 tokens. Réduire cette borne pour un modèle à petit contexte. Les étapes de réduction peuvent perdre des détails ; la transcription intégrale reste accessible.

L’accueil affiche **Mes sujets**. Ouvrir un sujet permet d’y importer directement des liens YouTube : l’ajout au sujet et le lancement de l’analyse sont enregistrés ensemble. Les statuts se mettent à jour dans le sujet. Un lien déjà importé réutilise la vidéo existante sans seconde analyse. Une option secondaire permet de retrouver les anciennes vidéos. Une vidéo peut appartenir à plusieurs sujets. Une fois au moins deux analyses terminées, **Générer le compte rendu global** lance une synthèse asynchrone avec Qwen local : les transcriptions originales sont croisées comme si les vidéos formaient une grande réunion, avec enseignements, convergences, nuances, méthodes, plan de mise en œuvre, limites, questions ouvertes et références `[Vn]` aux vidéos sources. Les comptes rendus individuels servent uniquement de filet de compatibilité pour les anciennes vidéos sans transcription ; la synthèse ne vérifie pas les affirmations auprès de sources externes.

Chaque sujet peut aussi recevoir une intention facultative (**Veille**, **Apprentissage**, **Projet / action**, **Aide à la décision**, **Comparaison** ou **Inspiration**). Cette intention est transmise au prompt de synthèse pour en adapter les priorités ; le mode **Général** reste transversal.

Retirer une vidéo du sujet ne la supprime pas de la bibliothèque. Modifier le sujet ou sa sélection rend l’ancienne synthèse obsolète et permet de la régénérer. Une génération en cours ne peut pas publier un résultat correspondant à une ancienne sélection. Supprimer une vidéo de la bibliothèque actualise également les sujets concernés. Les références de chaque rapport restent conservées avec ce rapport.

Quand la synthèse globale est à jour, la page du sujet propose quatre sorties éditoriales indépendantes : **Post réseaux sociaux** (storytelling concret de longueur intermédiaire pour LinkedIn, X ou Facebook), **Newsletter** orientée actualité, **Article de blog** qui choisit entre tutoriel et analyse détaillée selon le contenu, et **Résumé court** strictement synthétique. Chaque format est mis en file séparément, peut échouer ou être relancé sans bloquer les autres, et reste lié à la révision de la synthèse qui l’a produit.

Le bouton **Régénérer le compte rendu** sur la fiche vidéo relance seulement la rédaction à partir de la transcription conservée. Il est disponible après un traitement terminé ou interrompu, dès lors que la transcription existe. L’ancien compte rendu reste consultable en attendant le nouveau, même si la relance échoue. Les clics répétés ne créent pas plusieurs traitements. Les synthèses des sujets concernés deviennent à actualiser. L’ajout de vidéos et la relance individuelle ne déclenchent jamais la synthèse du sujet : une fois toutes les analyses terminées, utiliser **Générer le compte rendu global** ou **Régénérer le compte rendu global**.

## Erreurs, reprises et logs

Les messages en échec passent dans la file `failed`. Pas de répétition automatique des requêtes LLM potentiellement payantes. Corriger la configuration puis :

```sh
docker compose exec worker php bin/console messenger:failed:show
docker compose exec worker php bin/console messenger:failed:retry --force
```

Le retry reprend la transcription stockée lorsqu’elle existe. Un message d’une vidéo déjà terminée ne relance rien. Les verrous de fichier sont partagés par les conteneurs dans `app-data` ; la contrainte SQL unique reste la garantie contre les doublons. La création de la vidéo et l’envoi en file utilisent une même transaction SQLite.

Dans Docker et le service systemd Linux, le worker renouvelle sa réservation toutes les 60 secondes (--keepalive=60). Le délai Docker par défaut est de six heures (`WORKER_REDELIVER_TIMEOUT=21600`), au-dessus du délai maximal Whisper de quatre heures, ce qui évite de redonner une transcription longue à un autre worker pendant qu’elle est encore traitée. Après un arrêt brutal, le message redevient récupérable à l’expiration de ce délai ; le statut peut rester sur son étape jusque-là. Les fichiers temporaires sont supprimés dans un `finally`, succès ou erreur ; après un arrêt forcé, des dossiers orphelins peuvent subsister dans `var/watchless/tmp` et être supprimés une fois les workers arrêtés. Les résultats intermédiaires LLM ne sont pas mis en cache : une reprise du résumé recommence ses appels.

Logs : `docker compose logs worker`, et journaux applicatifs avec rotation de 14 fichiers dans `/app/var/log/watchless-*.log`. Aucun texte de transcription ni clé n’est volontairement journalisé.

YouTube peut limiter ou bloquer yt-dlp, notamment sur un VPS. Garder yt-dlp à jour. Un fichier de cookies Netscape peut être monté dans le worker et référencé par `YTDLP_COOKIES_FILE` ; ne jamais le committer. L’application ne contourne pas les vidéos auxquelles le compte n’a pas accès. Les directs en cours et à venir sont rejetés. Les horodatages de sous-titres et de speech-to-text restent ceux estimés par leurs fournisseurs.

## Tests sans appels externes

```sh
docker compose --profile test run --build --rm tests
```

Ou avec PHP installé :

```sh
composer install
composer test
composer check
```

Tests unitaires et intégration : URL et domaines interdits, doublons + un seul message en file, rollback si la file échoue, transitions, sous-titres manuels/automatiques/fallback, nettoyage, Unicode, prompts, réduction hiérarchique, erreurs et troncature LLM, multipart STT, reprise après échec, pages et recherche, CSRF et rendu Markdown. yt-dlp, transcription et LLM sont simulés. SQLite en mémoire isole les tests des données personnelles. CI prévue pour PHP 8.3 et 8.4.

## Sans Docker (alternative)

Prérequis : PHP 8.3+ avec PDO SQLite, mbstring, intl, XML ; Composer ; Python 3.11 ; FFmpeg ; Node 22 ; yt-dlp ; Whisper ; serveur LLM local.

```sh
composer install
python3 -m venv .venv
.venv/bin/pip install -r requirements-tools.txt
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console watchless:doctor
php -S 127.0.0.1:8000 -t public
```

Dans un second terminal : `php bin/console messenger:consume async -v`. Sous Windows, les exécutables Python se trouvent dans `.venv/Scripts` ; sous Linux dans `.venv/bin`. Utiliser des chemins absolus dans `.env.local` si ces répertoires ne sont pas dans le PATH.

Exemple `.env.local` pour Ollama/Whisper :

```dotenv
APP_SECRET=remplacer-par-une-valeur-aleatoire
TRANSCRIPTION_PROVIDER=local
YTDLP_BINARY=/chemin/watchless/.venv/bin/yt-dlp
WHISPER_BINARY=/chemin/watchless/.venv/bin/whisper
WHISPER_MODEL=base
LLM_BASE_URL=http://127.0.0.1:11434/v1
LLM_MODEL=watchless
LLM_TOKEN_PARAMETER=max_tokens
```

Créer le modèle avec le script `docker/model-init.sh`, ou un Modelfile avec `FROM votre-modele` et `PARAMETER num_ctx 32768`. Avec LM Studio, utiliser son URL `/v1`, le nom exact de son modèle chargé et un contexte équivalent.

API OpenAI facultative : `TRANSCRIPTION_PROVIDER=openai`, `OPENAI_API_KEY`, `LLM_BASE_URL=https://api.openai.com/v1`, `LLM_API_KEY`, `LLM_MODEL` adapté à votre compte et `LLM_TOKEN_PARAMETER=max_completion_tokens`. La transcription API utilise `whisper-1` et découpe l’audio en segments de dix minutes à 64 kb/s avant envoi. Cette configuration transmet l’audio/la transcription aux fournisseurs ; la configuration Docker par défaut conserve ces traitements en local.

## VPS Ubuntu

Docker Compose peut être utilisé tel quel, derrière un Caddy hôte. Conserver le port lié à `127.0.0.1`, puis utiliser HTTPS + `basic_auth` devant `reverse_proxy 127.0.0.1:8090`. Ne publier ni Ollama ni PHP-FPM. Générer le hash avec `caddy hash-password`. Le fichier `deploy/Caddyfile` illustre aussi l’installation PHP-FPM native ; `deploy/watchless-worker.service` fournit son worker systemd. Adapter domaine, chemins et hash.

Pour une mise à jour Docker, arrêter proprement le worker, sauvegarder les données, reconstruire les images et relancer `init` avant `app`/`worker`. Les migrations sont conservées dans Git. En installation native, `APP_ENV=prod`, `APP_DEBUG=0`, secret aléatoire dans `.env.local`, `composer install --no-dev --optimize-autoloader`, migrations puis cache warmup ; le compte PHP doit pouvoir écrire dans `var/`.

## Références techniques

- [Symfony Messenger et transport Doctrine](https://symfony.com/doc/7.4/messenger.html)
- [Options yt-dlp](https://github.com/yt-dlp/yt-dlp#usage-and-options)
- [Whisper local](https://github.com/openai/whisper)
- [Ollama Docker](https://docs.ollama.com/docker) et [compatibilité API/contexte](https://docs.ollama.com/api/openai-compatibility)
- [LM Studio Chat Completions](https://lmstudio.ai/docs/developer/openai-compat/chat-completions)
- [Transcription API et limites de fichiers](https://developers.openai.com/api/docs/guides/speech-to-text)

## Xiaomi MiMo (optionnel)

La configuration Docker par défaut reste entièrement locale (Qwen3.5 9B + Whisper).
L’API Xiaomi est compatible avec notre adaptateur Chat Completions. Un profil prêt à configurer est fourni :

1. Renseigner `LLM_CLOUD_BASE_URL`, `LLM_CLOUD_API_KEY`, `LLM_CLOUD_MODEL` et `LLM_CLOUD_ENABLED=1` dans `.env.local`.
2. Lancer :

```sh
docker compose --env-file .env.local -f compose.yaml up -d
```

Le profil utilise `mimo-v2.6-pro` avec `max_completion_tokens`. Il envoie les transcriptions à Xiaomi pour les rapports, avec facturation selon votre compte. Whisper reste local. Aucun appel Xiaomi réel n’a été fait durant la validation, faute de clé. Ollama reste disponible pour revenir au profil local : `docker compose up -d`.

Pour l’auto-hébergement MiMo : le modèle historique [MiMo-7B-RL](https://huggingface.co/XiaomiMiMo/MiMo-7B-RL) est publié par Xiaomi et vise surtout le raisonnement mathématique et le code ; sa qualité pour nos rapports français reste à évaluer. Il n’est pas équivalent aux modèles MiMo récents. [MiMo-V2.5](https://huggingface.co/XiaomiMiMo/MiMo-V2.5) compte 310 milliards de paramètres au total : les 15 milliards activés par token ne représentent pas la mémoire nécessaire pour charger les poids. Ce n’est pas un remplacement léger du modèle local 7B. Aucun téléchargement MiMo massif n’est lancé automatiquement.

Sources : [guide officiel API](https://mimo.mi.com/docs/en-US/quick-start/summary/first-api-call), [authentification](https://mimo.mi.com/docs/en-US/quick-start/faq/api-integration). Configuration vérifiée le 25 septembre 2026.

## Supprimer une vidéo

Cliquer sur **Supprimer** dans la bibliothèque ou **Supprimer cette vidéo** sur sa fiche, puis confirmer. La ligne, sa transcription et son compte rendu sont effacés de WatchLess ; la vidéo YouTube reste intacte. Une URL supprimée peut être soumise de nouveau. Les messages en attente d’une vidéo supprimée sont ignorés par le worker. Pendant une analyse active, la suppression est temporairement refusée (HTTP 409), grâce au même verrou partagé que le worker. Réessayer après la fin du traitement. Chaque suppression nécessite un POST avec jeton CSRF propre à la vidéo.


### Réglages du modèle local Qwen3.5 9B

Le profil Docker utilise Qwen3.5 9B quantifié par Ollama, avec un contexte de 32 768 tokens. Les transcriptions longues restent découpées. Les paramètres de génération du modèle sont hérités du paquet Ollama. `LLM_REASONING_EFFORT=none` désactive le raisonnement prolongé via le connecteur compatible OpenAI ; le champ est omis si la variable est vide (profil API MiMo notamment). `LLM_TIMEOUT=1800` laisse au processeur jusqu'à 30 minutes par appel. Les anciens rapports restent inchangés lors du changement de modèle.



### Relecture du compte rendu

Après le brouillon, le LLM reçoit à nouveau les preuves utilisées pour sa rédaction et doit retirer les affirmations non étayées. Les sources et les fragments de brouillon sont bornés chacun par `TRANSCRIPT_CHUNK_BYTES`. Le contexte local de 32K réserve la place de ces deux entrées et de la sortie. La relecture conserve les détails présents ; les rubriques sans preuve restent « Non abordé ». Pour une longue vidéo, les preuves sont les notes issues de la réduction hiérarchique, qui conservent désormais des extraits de transcription.

Cette deuxième passe prend du temps supplémentaire sur CPU et ne constitue pas une vérification externe des propos de la vidéo. Si elle échoue, le traitement passe en erreur sans publier le brouillon non contrôlé. Les transcriptions restent consultables. Les essais de fidélité sont décrits dans `docs/VALIDATION.md`.

