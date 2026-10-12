# Fiche portfolio — WatchLess

## Titre

WatchLess

## Description

Application Symfony 7.4 qui transforme des vidéos YouTube en transcriptions horodatées et en comptes rendus détaillés en français, avec une IA 100 % locale (Whisper + Qwen via Ollama). Les vidéos sont regroupées par sujet pour générer une synthèse croisée et des publications prêtes à diffuser.

## Présentation

Ce projet permet de :

- Coller un lien YouTube et obtenir sa transcription complète puis un compte rendu structuré en 14 rubriques
- Regrouper plusieurs vidéos dans un **sujet** et générer un compte rendu global qui croise leurs transcriptions
- Décliner cette synthèse en post réseaux sociaux, newsletter, article de blog ou résumé court
- Tout traiter en local, sans clé API, grâce à Docker Compose (Whisper, Ollama, Qwen3.5 9B)

## Fonctionnalités

### Analyse d'une vidéo

- Soumission d'une URL `youtube.com/watch`, `youtu.be` ou `youtube.com/shorts`, validée et normalisée
- Récupération des métadonnées : titre, chaîne, durée, miniature, date de publication
- Sous-titres manuels en priorité, puis automatiques (français, anglais, puis autre langue)
- Repli automatique sur Whisper local quand aucun sous-titre n'est exploitable
- Transcription horodatée enregistrée **avant** l'appel au LLM, toujours consultable
- Compte rendu en 14 rubriques : sujet, objectif, concepts, méthodes, étapes, arguments, avantages, limites, exemples, ressources, recommandations, points clés, affirmations à vérifier, conclusion
- Règle de preuve : chaque rubrique cite un extrait exact de la transcription, sinon « Non abordé »
- Seconde passe de relecture par le LLM qui retire les affirmations non étayées
- Découpage des longues transcriptions et réduction hiérarchique, sans troncature silencieuse
- Régénération du compte rendu à partir de la transcription conservée

### Sujets et synthèse multi-vidéos

- Création de sujets avec intention : Veille, Apprentissage, Projet / action, Aide à la décision, Comparaison, Inspiration
- Import direct de liens YouTube dans un sujet ; une vidéo peut appartenir à plusieurs sujets
- Compte rendu global en 11 rubriques : convergences, désaccords, plan de mise en œuvre, risques…
- Références `[Vn]` vers les vidéos sources dans la synthèse
- Détection des synthèses obsolètes après modification du sujet ou de sa sélection
- Quatre formats de publication indépendants : post réseaux sociaux, newsletter, article de blog, résumé court

### Bibliothèque et suivi

- Bibliothèque de toutes les vidéos avec recherche dans les titres et pagination
- Statuts de traitement mis à jour automatiquement toutes les 4 secondes
- Détection des doublons : une même vidéo redirige vers sa fiche existante, sans nouveau traitement
- Suppression d'une vidéo, de sa transcription et de son compte rendu
- Erreurs affichées sur la fiche avec l'étape concernée (vidéo privée, blocage YouTube, échec du fournisseur…)
- Commande console `watchless:doctor` pour vérifier l'installation

### Traitement asynchrone

- File Symfony Messenger avec transport Doctrine
- Création de la vidéo et envoi du message dans une même transaction SQLite
- Verrous partagés entre conteneurs pour éviter les traitements concurrents
- File `failed` et reprise manuelle, sans relance automatique d'appels LLM potentiellement payants
- Fournisseur cloud compatible OpenAI optionnel, avec bascule automatique vers le modèle local en cas de quota épuisé

## Sécurité

- Protection CSRF sur tous les formulaires : soumission, sujets, régénération, suppression
- Validation stricte des URL et liste de domaines autorisés
- Aucun appel shell dans les contrôleurs ; processus lancés avec des tableaux d'arguments via Symfony Process
- Rendu Markdown sécurisé : HTML supprimé, liens dangereux neutralisés
- Clés API uniquement en variables d'environnement, jamais stockées en base ni journalisées
- Ollama et PHP-FPM non exposés ; déploiement VPS derrière Caddy en HTTPS avec `basic_auth`

---

## Stack technique

## Stack principale

- **Backend :** PHP 8.3 avec Symfony 7.4
- **Symfony :** Framework Bundle, Messenger, Process, Lock, HttpClient, Security CSRF, Monolog
- **Base de données :** SQLite (`var/watchless.db`) pour les données et la file Messenger
- **ORM :** Doctrine ORM 3 + Doctrine Migrations
- **Entités principales :** `Video`, `Topic`, `TopicPublication`
- **Markdown :** `league/commonmark` avec rendu sécurisé pour les comptes rendus

## IA & traitement vidéo

- **LLM local :** Ollama avec Qwen3.5 9B, contexte de 32 768 tokens
- **Speech-to-text :** OpenAI Whisper en local (modèle `base` par défaut)
- **Extraction YouTube :** yt-dlp avec runtime Node 22, FFmpeg pour l'audio
- **Interfaces interchangeables :** `LlmProviderInterface` et `TranscriptionProviderInterface`
- **Fournisseurs optionnels :** API compatible OpenAI, LM Studio, Xiaomi MiMo

## Frontend

- **Templates :** Twig, pages `topic/` et `video/`
- **CSS :** CSS natif, sans framework ni build
- **JavaScript :** vanilla JS minimal pour le suivi des statuts en temps réel
- **Accessibilité :** lien d'évitement, rôles ARIA `status` et `alert`, libellés explicites

## Infrastructure & outils

- **Conteneurs :** Docker Compose avec 6 services : Caddy, PHP-FPM, worker, Ollama, init, model-init
- **Déploiement :** VPS Ubuntu derrière Caddy, ou installation native avec service systemd pour le worker
- **Tests :** PHPUnit 11, tests unitaires et d'intégration avec yt-dlp, Whisper et LLM simulés
- **CI :** prévue pour PHP 8.3 et 8.4
- **Migrations :** 5 migrations Doctrine

## Lien du repo GitHub

https://github.com/buzzer93/WatchLess

## Images

Dans `docs/screenshots/`, dans l'ordre du carousel :

1. `1-bibliotheque.png` — Bibliothèque des vidéos avec statuts de traitement
2. `2-fiche-video.png` — Fiche vidéo et compte rendu détaillé
3. `3-synthese-sujet.png` — Compte rendu global d'un sujet multi-vidéos
4. `4-publication.png` — Génération de publications (post réseaux sociaux, newsletter, blog…)
