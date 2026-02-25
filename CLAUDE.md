# CLAUDE.md — Maillage Interne WordPress Plugin

## Projet

Plugin WordPress de maillage interne SEO intelligent basé sur les embeddings sémantiques.
Repo : https://github.com/NaisBinocle/maillage-interne
Branche principale : `main` (WordPress plugin)
Branche legacy : `master` (ancien script Python CLI)

## Emplacement local

```
C:\Users\quent\Local Sites\test\app\public\wp-content\plugins\maillage-interne\
```

WordPress local via Local by Flywheel, PHP 8.2.27.

## Architecture

### Autoloader

L'autoloader dans `maillage-interne.php` mappe les préfixes de classes vers les sous-dossiers :

```
MI_Admin_*       → includes/admin/
MI_Analysis_*    → includes/analysis/
MI_Api_*         → includes/api/
MI_Background_*  → includes/background/
MI_Embedding_*   → includes/embedding/
MI_Hooks_*       → includes/hooks/
MI_Storage_*     → includes/storage/
MI_*             → includes/
```

Convention de nommage des fichiers : `class-{classe-en-kebab-case}.php`
Exemple : `MI_Storage_Embedding_Repository` → `includes/storage/class-storage-embedding-repository.php`

### Tables custom (4)

- `{prefix}mi_embeddings` — vecteurs BLOB binaire (pack/unpack float32)
- `{prefix}mi_similarity_cache` — scores pré-calculés source→cible
- `{prefix}mi_link_graph` — graphe des liens internes
- `{prefix}mi_embedding_queue` — file d'attente avec priorités/retry

Créées via `dbDelta()` dans `MI_Activator::create_tables()`.
Supprimées dans `uninstall.php`.

### Settings

Toutes les options sont stockées dans un seul `wp_option` : `mi_settings` (tableau sérialisé).
Accès via `MI_Settings::get('key')` / `MI_Settings::set('key', $value)`.
Les valeurs par défaut sont dans `MI_Settings::defaults()`.

### Embedding providers

Interface : `MI_Embedding_Provider_Interface` (embed_single, embed_batch, is_available, etc.)
Implémentations :
- `MI_Embedding_Voyage_Provider` — API Voyage AI (`voyage-4-lite`)
- `MI_Embedding_Openai_Provider` — API OpenAI (`text-embedding-3-small`)
- `MI_Embedding_Tfidf_Provider` — (TODO) Fallback PHP pur

Les embeddings sont stockés en binaire via `pack('f*', ...$vector)` et décodés avec `unpack('f*', $blob)`.

### Pondération du texte pour embedding

Dans `MI_Embedding_Manager::prepare_text()` :
- Titre : 3x (répété 3 fois)
- H1 : 2x
- Excerpt / meta description (Yoast/RankMath) : 2x
- Body (sans HTML/shortcodes) : 1x, max 50 000 caractères

### Pipeline async

`save_post` → enqueue (priorité 1) → Action Scheduler/WP Cron → API batch → store embedding → cache invalidé.
Le `save_post` hook ne bloque JAMAIS. Tout le calcul est async.

### Scoring

```
final_score = cosine_similarity(source, target) + bonus
bonus = same_category(0.05) + shared_tags(0.02×n) + orphan(0.08) + fresh(0.03)
```

## Conventions de code

- PHP 7.4+ (pas de typed properties, pas de match expressions)
- WordPress Coding Standards (tabs, Yoda conditions, prefixed globals)
- Toutes les requêtes SQL via `$wpdb->prepare()` (pas de SQL brut avec variables)
- Toutes les sorties HTML échappées (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- Nonces et capability checks sur tous les endpoints REST
- Pas de `// phpcs:ignore` sans raison documentée
- Textes utilisateur en français, internationalisation via `__()` et `_e()` avec domaine `maillage-interne`

## Commandes utiles

```bash
# Vérifier la syntaxe PHP
PHP="/c/Users/quent/AppData/Roaming/Local/lightning-services/php-8.2.27+1/bin/win64/php.exe"
find . -name "*.php" -exec "$PHP" -l {} \;

# Git
git status
git log --oneline
git push origin main
```

## REST API

Namespace : `maillage-interne/v1`
Tous les endpoints sont enregistrés dans `MI_Plugin::init_rest_api()`.
Contrôleurs dans `includes/api/`.

Endpoints principaux :
- `GET /recommendations/{post_id}` — suggestions de liens
- `POST /bulk/vectorize` — vectoriser tout le contenu
- `POST /bulk/scan-links` — scanner tous les liens
- `GET /status/queue` — progression de la file

## Ce qui reste à faire

1. **Sidebar Gutenberg** — React avec `@wordpress/plugins`, `PluginDocumentSettingPanel`, store `wp.data`
2. **Provider TF-IDF** — fallback PHP pur sans API externe
3. **Action Scheduler** — intégrer via Composer pour un traitement async fiable
4. **Tests** — PHPUnit avec WordPress test suite
5. **i18n** — générer le .pot, créer les .po/.mo français
6. **Export CSV** — depuis le dashboard admin

## Erreurs connues à éviter

- Ne jamais bloquer `save_post` avec un appel API synchrone
- Ne jamais exposer les clés API en clair dans les réponses REST (utiliser `mask_key()`)
- Les embeddings binaires doivent être pack/unpack avec le même format (`f*` = float32)
- `dbDelta()` est sensible au formatage SQL : 2 espaces après `PRIMARY KEY`, pas de virgule avant la parenthèse fermante
- L'autoloader est case-sensitive sur le mapping sous-dossier : le premier segment après `MI_` doit matcher exactement les clés de `$subdirs`
