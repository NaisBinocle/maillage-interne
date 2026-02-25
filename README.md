# Maillage Interne — Plugin WordPress SEO

Plugin WordPress de recommandations intelligentes de maillage interne basées sur les **embeddings sémantiques** (Voyage AI / OpenAI) avec fallback TF-IDF.

## Fonctionnalités

- **Analyse sémantique** : utilise des embeddings vectoriels pour comprendre le sens du contenu (pas juste les mots-clés)
- **Recommandations de liens** : suggère les pages les plus pertinentes à relier entre elles, avec texte d'ancre optimisé
- **Détection des liens existants** : parse le contenu HTML pour cartographier le maillage interne actuel
- **Pages orphelines** : identifie les pages qui ne reçoivent aucun lien interne
- **Clustering thématique** : regroupe automatiquement les pages par thématique via K-Means
- **Scoring contextuel** : bonus pour même catégorie, tags partagés, pages orphelines, contenu frais
- **Dashboard admin** : vue d'ensemble du maillage, statistiques, actions en masse
- **Metabox éditeur** : suggestions de liens directement dans l'éditeur classique
- **API REST complète** : 15 endpoints pour intégration avec Gutenberg ou outils tiers
- **Traitement asynchrone** : les embeddings sont calculés en arrière-plan sans bloquer l'éditeur

## Prérequis

- WordPress 6.0+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- Clé API [Voyage AI](https://dash.voyageai.com) ou [OpenAI](https://platform.openai.com) (optionnel si mode TF-IDF)

## Installation

1. Cloner le repo dans `wp-content/plugins/` :
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/NaisBinocle/maillage-interne.git
   ```

2. Activer le plugin dans **Extensions > Extensions installées**

3. Aller dans **Maillage Interne > Réglages** :
   - Choisir le fournisseur (Voyage AI recommandé)
   - Entrer la clé API
   - Tester la connexion
   - Configurer les types de contenu à analyser

4. Cliquer sur **Vectoriser tout le contenu** dans le tableau de bord

## Fournisseurs d'embeddings

| Fournisseur | Modèle | Coût / 1M tokens | Qualité | API Key |
|-------------|--------|-------------------|---------|---------|
| **Voyage AI** (recommandé) | `voyage-4-lite` | $0.02 | Excellent multilingue | [dash.voyageai.com](https://dash.voyageai.com) |
| OpenAI | `text-embedding-3-small` | $0.02 | Très bon | [platform.openai.com](https://platform.openai.com) |
| TF-IDF local | — | Gratuit | Correct (mots-clés uniquement) | Aucune |

**Estimation de coût** : un site de 500 pages coûte environ **$0.015** à vectoriser avec `voyage-4-lite`.

## Architecture

### Structure du plugin

```
maillage-interne/
├── maillage-interne.php              # Bootstrap, autoloader, hooks activation
├── uninstall.php                     # Nettoyage complet à la suppression
│
├── includes/
│   ├── class-plugin.php              # Orchestrateur principal (singleton)
│   ├── class-activator.php           # Création des 4 tables custom
│   ├── class-deactivator.php         # Nettoyage des crons
│   ├── class-settings.php            # Accès centralisé aux réglages
│   │
│   ├── embedding/                    # Couche embedding
│   │   ├── class-embedding-provider-interface.php
│   │   ├── class-embedding-voyage-provider.php
│   │   ├── class-embedding-openai-provider.php
│   │   └── class-embedding-manager.php
│   │
│   ├── analysis/                     # Moteur d'analyse
│   │   ├── class-analysis-similarity-engine.php
│   │   ├── class-analysis-cluster-engine.php
│   │   ├── class-analysis-anchor-suggester.php
│   │   ├── class-analysis-recommendation-engine.php
│   │   └── class-analysis-link-detector.php
│   │
│   ├── storage/                      # Couche persistance
│   │   ├── class-storage-embedding-repository.php
│   │   ├── class-storage-similarity-cache.php
│   │   └── class-storage-link-graph-repository.php
│   │
│   ├── background/                   # Traitement asynchrone
│   │   ├── class-background-batch-processor.php
│   │   └── class-background-queue-manager.php
│   │
│   ├── api/                          # REST API (5 contrôleurs)
│   │   ├── class-api-rest-recommendations.php
│   │   ├── class-api-rest-dashboard.php
│   │   ├── class-api-rest-settings.php
│   │   ├── class-api-rest-bulk-actions.php
│   │   └── class-api-rest-embedding-status.php
│   │
│   ├── admin/                        # Interface admin
│   │   ├── class-admin-menu.php
│   │   ├── class-admin-dashboard-page.php
│   │   ├── class-admin-settings-page.php
│   │   ├── class-admin-metabox-classic.php
│   │   ├── class-admin-notices.php
│   │   └── class-admin-assets.php
│   │
│   └── hooks/                        # WordPress hooks
│       ├── class-hooks-post-save-handler.php
│       └── class-hooks-post-delete-handler.php
│
├── admin/
│   ├── css/admin-dashboard.css
│   └── js/dashboard.js
│
└── gutenberg/                        # (à venir) Sidebar Gutenberg React
    └── src/
```

### Base de données (4 tables custom)

| Table | Description |
|-------|-------------|
| `{prefix}mi_embeddings` | Vecteurs stockés en BLOB binaire float32 (~2 KB/post pour 512 dims) |
| `{prefix}mi_similarity_cache` | Scores de similarité pré-calculés avec index composé |
| `{prefix}mi_link_graph` | Graphe des liens internes existants (source → cible + ancre) |
| `{prefix}mi_embedding_queue` | File d'attente de traitement avec priorités et retry |

### Pipeline de traitement

```
save_post (non-bloquant, <5ms)
    ├── Update link graph (synchrone)
    ├── Invalide le cache de similarité
    └── Enqueue pour embedding (async)
            │
            ▼
      Action Scheduler / WP Cron
            │
            ▼
      API Voyage AI / OpenAI (batch de 10)
            │
            ▼
      Stocke embedding en BLOB binaire
            │
            ▼
      Calcul similarité cosinus + bonus contextuels
            │
            ▼
      Cache dans mi_similarity_cache
```

### Scoring des recommandations

```
Score final = Similarité cosinus + Bonus contextuels

Bonus configurables :
  - Même catégorie WP    → +0.05
  - Tag partagé           → +0.02 (par tag, max 3)
  - Cible orpheline       → +0.08
  - Contenu frais (<30j)  → +0.03
```

## API REST

Namespace : `maillage-interne/v1`

| Méthode | Endpoint | Permission | Description |
|---------|----------|------------|-------------|
| GET | `/recommendations/{post_id}` | `edit_post` | Suggestions pour un article |
| GET | `/dashboard/stats` | `manage_options` | Statistiques globales |
| GET | `/dashboard/orphans` | `manage_options` | Pages orphelines |
| GET | `/dashboard/top-opportunities` | `manage_options` | Meilleures opportunités |
| GET | `/settings` | `manage_options` | Lire les réglages |
| POST | `/settings` | `manage_options` | Modifier les réglages |
| POST | `/settings/test-api` | `manage_options` | Tester la connexion API |
| POST | `/bulk/vectorize` | `manage_options` | Vectoriser tout le contenu |
| POST | `/bulk/scan-links` | `manage_options` | Scanner tous les liens |
| POST | `/bulk/recompute-similarities` | `manage_options` | Recalculer les similarités |
| GET | `/status/queue` | `manage_options` | Progression de la file |
| GET | `/status/post/{post_id}` | `edit_post` | Statut embedding d'un post |
| POST | `/status/post/{post_id}/refresh` | `edit_post` | Forcer le recalcul |

## Développement

### TODO

- [ ] Sidebar Gutenberg (React `PluginDocumentSettingPanel`)
- [ ] Provider TF-IDF fallback en PHP pur
- [ ] Export CSV des recommandations
- [ ] Support Action Scheduler (Composer)
- [ ] Internationalisation complète (fichiers .po/.mo)
- [ ] Tests unitaires PHPUnit
- [ ] Compatibilité multisite

## Licence

GPL-2.0-or-later
