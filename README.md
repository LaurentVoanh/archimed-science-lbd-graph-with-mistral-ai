# 🧠 Archimed Science LBD Graph with Mistral AI

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?style=for-the-badge&logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![Mistral AI](https://img.shields.io/badge/Mistral_AI-API-FD6F00?style=for-the-badge&logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iI0ZENEYwMCIgZD0iTTEyIDJMMiAxOWgyMHoiLz48L3N2Zz4=&logoColor=white)](https://mistral.ai/)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](LICENSE)

> **Un système intelligent de gestion de connaissances scientifiques** combinant une base de données locale graphique (LBD), une visualisation interactive et l'intelligence artificielle générative de Mistral pour l'analyse et l'enrichissement sémantique.

---

## 📑 Table des matières

1. [Aperçu du projet](#-aperçu-du-projet)
2. [Architecture du système](#-architecture-du-système)
3. [Fonctionnalités clés](#-fonctionnalités-clés)
4. [Prérequis](#-prérequis)
5. [Installation](#-installation)
6. [Configuration](#-configuration)
7. [Utilisation](#-utilisation)
8. [Documentation API](#-documentation-api)
9. [Structure de la Base de Données](#-structure-de-la-base-de-données)
10. [Intégration Mistral AI](#-intégration-mistral-ai)
11. [Dashboard & Visualisation](#-dashboard--visualisation)
12. [Logs & Monitoring](#-logs--monitoring)
13. [Contribuer](#-contribuer)
14. [Licence](#-licence)

---

## 🚀 Aperçu du projet

**Archimed Science LBD** est une plateforme conçue pour les chercheurs et scientifiques afin de structurer, visualiser et enrichir leurs données de recherche. Le système repose sur trois piliers :
1.  **Stockage Local (SQLite)** : Une base de données légère et portable stockant les entités et leurs relations.
2.  **Visualisation Graphique** : Une interface dynamique pour explorer les connexions entre les concepts scientifiques.
3.  **Intelligence Artificielle (Mistral)** : Utilisation des modèles de langage pour générer des résumés, suggérer des liens manquants et analyser le contexte scientifique.

---

## 🏗 Architecture du système

Le système suit une architecture modulaire en 5 phases :

```text
+----------------+       +----------------+       +----------------+
|   Interface    | <-->  |     Backend    | <-->  |   Base de      |
|   Utilisateur  | AJAX  |     (PHP)      | SQL   |   Données      |
|   (Dashboard)  |       |   (API REST)   |       |   (SQLite)     |
+----------------+       +-------+--------+       +----------------+
                                 |
                                 v
                       +----------------+
                       |  Mistral AI    |
                       |  (Cloud API)   |
                       +----------------+
Les 5 Phases de Traitement :
Ingestion : Saisie manuelle ou import de données brutes.
Structuration : Nettoyage et formatage des entités/nœuds.
Enrichissement IA : Appel à Mistral pour extraire des métadonnées et des relations implicites.
Persistance : Sauvegarde dans le graphe SQLite.
Visualisation : Rendu interactif sur le dashboard.
✨ Fonctionnalités clés
Graphe de Connaissances : Visualisation interactive des nœuds (concepts) et arêtes (relations).
Assistant IA Mistral :
Génération automatique de résumés scientifiques.
Suggestion de liens sémantiques entre des documents non connectés.
Analyse de similarité contextuelle.
Recherche Avancée : Filtrage par type d'entité, date, ou pertinence sémantique.
Mode Hors-Ligne : Le cœur du système fonctionne sans connexion internet (sauf pour les appels IA).
Journalisation Complète : Tracking de toutes les interactions et requêtes IA.
🛠 Prérequis
Avant de commencer, assurez-vous d'avoir les éléments suivants installés :
Composant
Version Requise
Lien
PHP
8.2 ou supérieur
Télécharger
SQLite
3.x (inclus avec PHP)
Documentation
Composer
Dernière version
Installer
Mistral API Key
Clé valide
Obtenir ici
Navigateur
Récent (Chrome/Firefox)
-
📥 Installation
Suivez ces étapes pour déployer le projet localement :
1. Cloner le repository
bash
12
2. Installer les dépendances
Si un fichier composer.json est présent :
bash
1
Sinon, le projet utilise uniquement les bibliothèques standards PHP.
3. Configuration de la base de données
Le script d'initialisation créera automatiquement le fichier database.sqlite.
bash
1
4. Configuration de l'API
Copiez le fichier d'exemple et ajoutez votre clé API Mistral :
bash
1
Éditez config/config.php et insérez votre clé :
php
1
5. Lancer le serveur
bash
1
Accédez ensuite à http://localhost:8000.
⚙️ Configuration
Le fichier config/config.php permet de régler les paramètres suivants :
DB_PATH : Chemin vers le fichier .sqlite.
MISTRAL_MODEL : Modèle à utiliser (ex: mistral-small, mistral-large).
DEBUG_MODE : Activer/désactiver les logs détaillés.
MAX_TOKENS : Limite de tokens pour les réponses IA.
💻 Utilisation
Dashboard Principal
Le tableau de bord se divise en 4 onglets principaux :
Explorateur : Vue graphique du réseau de connaissances.
Éditeur : Formulaire pour ajouter/modifier des entités.
Analyse IA : Interface pour soumettre des textes à Mistral.
Logs : Historique des opérations.
Scénario typique
Ajoutez un nouveau concept scientifique via l'onglet Éditeur.
Cliquez sur "Enrichir avec IA" pour que Mistral propose des définitions et des liens potentiels.
Validez les suggestions.
Visualisez le nouveau nœud apparaître dans le graphe de l'onglet Explorateur.
📡 Documentation API
Le backend expose plusieurs endpoints AJAX pour le frontend.
POST /api/add_node.php
Ajoute un nouveau nœud au graphe.
Params : label, type, description
Retour : JSON { status: "success", id: 123 }
POST /api/enrich.php
Soumet un texte à Mistral AI pour extraction d'entités.
Params : text_content
Retour : JSON { entities: [...], relations: [...] }
GET /api/get_graph.php
Récupère la structure complète du graphe pour la visualisation.
Retour : JSON { nodes: [...], links: [...] }
🗄 Structure de la Base de Données
Le schéma SQLite comprend principalement deux tables :
Table nodes
Colonne
Type
Description
id
INTEGER
Clé primaire
label
TEXT
Nom du concept
type
TEXT
Catégorie (ex: Théorie, Auteur, Expérience)
metadata
TEXT
JSON contenant les détails enrichis par IA
Table relations
Colonne
Type
Description
source_id
INTEGER
ID du nœud source
target_id
INTEGER
ID du nœud cible
type
TEXT
Nature du lien (ex: "influence", "utilise")
🤖 Intégration Mistral AI
Le système utilise l'API REST de Mistral. Voici comment fonctionne la logique d'enrichissement :
Le texte brut est envoyé au endpoint /v1/chat/completions.
Le system prompt demande à l'IA de retourner un JSON strict listant les entités et relations.
Le parser PHP valide le JSON et met à jour la base de données.
Exemple de Prompt Système :
"Tu es un assistant scientifique expert. Analyse le texte fourni et extrais les entités clés et leurs relations sous format JSON."
📊 Logs & Monitoring
Toutes les activités sont enregistrées dans le fichier logs/app.log (si activé dans la config).
Cela inclut :
Les timestamps des requêtes API.
Les erreurs de connexion à la base de données.
Les tokens consommés par l'IA.
Pour consulter les logs en temps réel :
bash
1
🤝 Contribuer
Les contributions sont les bienvenues ! Voici comment procéder :
Forker le projet.
Créer une branche de fonctionnalité (git checkout -b feature/AmazingFeature).
Commiter vos changements (git commit -m 'Add some AmazingFeature').
Pusher vers la branche (git push origin feature/AmazingFeature).
Ouvrir une Pull Request.
📄 Licence
Distribué sous la licence MIT. Voir LICENSE pour plus d'informations.
📬 Contact
Laurent Voanh
GitHub : @LaurentVoanh
Projet : Archimed Science LBD
<p align="center">Développé avec 💜 et l'IA de Mistral</p>
