# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Visit Marche (`visitmarchebe/themewp`) is a hybrid **WordPress + Symfony 7.3** application for the Visit Marche tourism website. It integrates a WordPress CMS frontend with a Symfony backend that connects to the **Pivot** tourism database API (tourismewallonie.be).

## Architecture

### Two Codebases in One

1. **Symfony PivotAi Bundle** (`src/AcMarche/PivotAi/src/`) — API client, entity models, XML/JSON parsers, and CLI commands for the Pivot tourism API.
2. **WordPress Theme** (`wp-content/themes/visit/`) — Custom theme with PHP 8.3+ classes, Twig templates, and a separate git submodule.

### How They Connect

- `wp-content/themes/visit/Lib/Di.php` bootstraps the Symfony kernel as a singleton, giving WordPress access to the Symfony DI container.
- `wp-content/themes/visit/Repository/PivotRepository.php` fetches Pivot offers via `PivotClient` from the Symfony bundle.
- `wp-content/themes/visit/Lib/Twig.php` initializes a Twig environment for rendering (not standard WordPress PHP templates).

### PSR-4 Autoload Namespaces

- `AcMarche\PivotAi\` → `src/AcMarche/PivotAi/src`
- `VisitMarche\ThemeWp\` → `wp-content/themes/visit/`

### Key Data Flow

**Offer page**: WordPress URL rewrite (`RouterPivot`) → `single-offer.php` → `PivotRepository` → `PivotClient` (cached API call) → `PivotSerializer` (deserialize) → Twig render.

**Category page**: `category.php` → `WpRepository` fetches WP posts + Pivot offers linked via term meta → REST API `/wp-json/pivot/category_items/{catId}` → Twig render with `CommonItem` DTO.

### Patterns

- **Traits** on `Offer` entity: `AddressTrait`, `CommunicationTrait`, `DateTrait`, `DescriptionTrait`, `ImageTrait`, `SpecificationTrait`, `ProxyTrait`
- **Repository pattern**: `PivotRepository` (Pivot API), `WpRepository` (WordPress DB)
- **DTO**: `CommonItem` unifies WordPress posts and Pivot offers
- **Content Levels**: Pivot API supports 5 detail levels (0=Updated through 4=OpenData), defined in `ContentLevel` enum
- **File-based caching**: JSON cache in `/data/pivot/`

## Commands

### Symfony Console

```bash
bin/console pivot:fetch          # Fetch offers from Pivot API
bin/console thesaurus:fetch      # Fetch thesaurus data
bin/console pivot:compare-levels # Compare content levels
```

### Tailwind CSS (from project root)

```bash
npx @tailwindcss/cli -i wp-content/themes/visit/assets/css/input.css -o wp-content/themes/visit/assets/css/visit.css --watch
```

### Static Analysis

```bash
vendor/bin/phpstan analyse       # PHPStan level 6, scans bin/ config/ public/ src/ tests/
```

### Dependencies

```bash
composer install                 # PHP dependencies
cd wp-content/themes/visit && npm install  # Frontend (Tailwind)
```

## Frontend Stack

- **Tailwind CSS 4** with Montserrat font, `@tailwindcss/forms`, `@tailwindcss/typography`
- **Alpine.js** for interactivity (category filters, admin AJAX)
- **Swiper.js** for carousels
- **Leaflet.js** for maps
- **Tabler Icons**

## Multi-Language

Supports fr, en, nl, de via WPML integration. Translations in `wp-content/themes/visit/translations/messages.{locale}.yaml`. Optional OpenAI translation via `Lib/OpenAi.php`.

## WordPress REST API Routes

- `GET /wp-json/pivot/find-offers-by-name/{name}` — Search offers (admin)
- `GET /wp-json/pivot/category_offers/{catId}` — Pivot offers for category (admin)
- `GET /wp-json/pivot/category_items/{catId}` — Mixed posts + offers (frontend)

## Environment

- PHP 8.3+ required with extensions: curl, dom, json, pdo, redis, simplexml, intl
- MariaDB for Pivot data, PostgreSQL for Symfony/Doctrine
- MeiliSearch for search
- Docker Compose available (PostgreSQL + Mailpit)
- `.env` contains API keys and credentials — never commit `.env.local`

## Theme Structure (wp-content/themes/visit/)

- `Inc/` — Business logic: `SetupTheme`, `RouterPivot`, `ApiRoutes`, `AssetsLoader`, `AdminPages`, `Ajax`
- `Lib/` — Utilities: `Di` (DI bridge), `Twig` (renderer), `Menu`, `LocaleHelper`, `OpenAi`
- `Repository/` — Data access layer
- `Dto/` — Data transfer objects
- `Enums/` — `LanguageEnum`, `IconeEnum`
- `templates/` — Twig templates (admin, article, offer, category, header, footer, homepage)

## Available Claude Code Skills

WordPress-specific skills are installed in `~/.claude/skills/`. Use these for specialized tasks:

| Skill | Purpose |
|-------|---------|
| `wordpress-router` | Routes tasks to the correct domain skill based on project type |
| `wp-rest-api` | Build/debug WordPress REST API endpoints, schema, permissions |
| `wp-phpstan` | Configure and run PHPStan with WordPress stubs and fix type errors |
| `wp-plugin-development` | Plugin architecture, hooks, admin UI, security, packaging |
| `wp-block-development` | Gutenberg blocks: block.json, rendering, attributes, deprecations |
| `wp-block-themes` | Block themes: theme.json, templates, patterns, style variations |
| `wp-interactivity-api` | Client-side interactivity with data-wp-* directives and stores |
| `wp-performance` | Profile and optimize backend performance (queries, caching, cron) |
| `wp-wpcli-and-ops` | WP-CLI operations: search-replace, DB, plugin management, cron |
| `wp-playground` | Spin up disposable WordPress instances for testing |
| `wp-project-triage` | Inspect repo to determine project type, tooling, and tests |
| `wp-abilities-api` | WordPress Abilities API for registering abilities and REST exposure |
| `wpds` | WordPress Design System components, tokens, and patterns |

## Important Notes

- The WordPress theme directory (`wp-content/themes/visit/`) is a **git submodule** with its own `.git`.
- WordPress core files (`wp-admin/`, `wp-includes/`, `wp-*.php`) are in `.gitignore` — do not modify them.
- The `data/` directory holds cached Pivot API responses and is gitignored.