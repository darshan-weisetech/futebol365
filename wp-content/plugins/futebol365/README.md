# Futebol365 WordPress Plugin — v2.0.0

## Overview

Complete WordPress plugin for the Futebol365 football portal, migrated from Django/PostgreSQL. Provides all Custom Post Types, taxonomies, ACF field groups, Yoast SEO integration, and WP-CLI migration commands.

---

## What's New in v2.0.0

| Module | CPT(s) | Status |
|---|---|---|
| **Newsstand** | `f365_publisher`, `f365_newsstand` | ✅ New |
| **Live Streaming** | `f365_livestream` | ✅ New |
| **Ranking** | `f365_ranking` | ✅ New |
| **Author** | Native WP Users (no CPT) | ✅ New |
| **Yoast SEO** | All CPTs + WP posts | ✅ New |
| All original modules | (unchanged) | ✅ Preserved |

---

## Plugin File Structure

```
futebol365/
├── futebol365.php                        ← Main plugin file (v2.0.0)
├── logs/                                 ← Per-module migration logs (auto-created)
│   └── f365-{module}-YYYY-MM-DD-HHmmss.log
└── includes/
    ├── cpt/
    │   ├── register-cpt.php              ← All CPT registrations (original + new)
    │   ├── register-taxonomies.php       ← All taxonomy registrations (original + new)
    │   └── register-rewrite-rules.php    ← URL rewrite rules (unchanged)
    ├── acf/
    │   ├── acf-team.php                  ← (unchanged)
    │   ├── acf-person.php                ← (unchanged)
    │   ├── acf-competition.php           ← (unchanged)
    │   ├── acf-federation.php            ← (unchanged)
    │   ├── acf-stadium.php               ← (unchanged)
    │   ├── acf-transfer.php              ← (unchanged)
    │   ├── acf-multimedia.php            ← (unchanged)
    │   ├── acf-newsstand.php             ← NEW: Publisher + PublicationEdition
    │   ├── acf-live-streaming.php        ← NEW: LiveStreaming / MatchOnTV / LiveStream
    │   ├── acf-ranking.php               ← NEW: Club / National / Player rankings
    │   └── acf-news-extended.php         ← NEW: SEO + Author fields on WP posts
    ├── admin/
    │   ├── admin-columns.php             ← (unchanged)
    │   └── admin-columns-new.php         ← NEW: columns for Newsstand, etc.
    ├── seo/
    │   └── yoast-seo.php                 ← NEW: Yoast SEO registration + helpers
    └── cli/
        ├── class-f365-migrate.php        ← Base migration (unchanged, original modules)
        └── class-f365-migrate-new-modules.php ← NEW: extends base with new modules
```

---

## Django → WordPress Mapping

### Custom Post Types

| Django Model(s) | WordPress CPT | Notes |
|---|---|---|
| `Team + TeamStep` | `f365_team` | TeamStep stored as ACF repeater |
| `Player + Person` | `f365_player` | |
| `Coach + Person` | `f365_coach` | |
| `President + Person` | `f365_president` | |
| `Referee + Person` | `f365_referee` | |
| `Federation` | `f365_federation` | Hierarchical (confederation→federation→association) |
| `FederationCompetition` | `f365_fed_comp` | |
| `Competition` | `f365_competition` | Includes phases + participants repeater |
| `Match` | `f365_match` | |
| `Stadium` | `f365_stadium` | |
| `Transfer` | `f365_transfer` | |
| `Video` | `f365_video` | |
| `PhotoGallery` | `f365_gallery` | |
| `News` | `post` (native WP) | WP native post type |
| `Publisher` | `f365_publisher` | Newsstand publisher |
| `PublicationEdition` | `f365_newsstand` | Cover image as featured image |
| `LiveStreaming` | `f365_livestream` | Also covers MatchOnTV + LiveStream |
| `ClubRanking + NationalRanking + PlayerRanking` | `f365_ranking` | Discriminated by `ranking_type` meta |
| `Source` | WP User (`author` role) | No CPT — native WP users |

### Taxonomies

| Django concept | WordPress taxonomy | Applies to |
|---|---|---|
| `Country` | `f365_country` | All CPTs |
| `Season/Edition` | `f365_season` | Competitions, Teams, Matches, Rankings |
| `NewsCategory` | `f365_news_cat` | `post` |
| `Source` | `f365_news_source` | `post` |
| `Tag` | `post_tag` | All |
| `Federation type` | `f365_fed_type` | Federations |
| `Competition scope` | `f365_comp_scope` | Competitions |
| `Modality` | `f365_modality` | Teams, Competitions, Matches, Rankings |
| `Gender` | `f365_gender` | Teams, Competitions, Matches, Rankings |
| `Team step` | `f365_step` | Teams |
| `Player position` | `f365_position` | Players |
| `Match situation` | `f365_match_status` | Matches |
| `Transfer type` | `f365_transfer_type` | Transfers |
| `Ranking type` | `f365_ranking_type` | Rankings (club / national / player) |
| `Ranking period` | `f365_ranking_period` | Rankings (YYYY-MM) |
| `Publisher type` | `f365_pub_type` | Publishers, Newsstand |

---

## WP-CLI Migration Commands

### Requirements

- PHP `pgsql` extension (`apt install php-pgsql` or `yum install php-pgsql`)
- Direct PostgreSQL access from the WordPress server
- ACF Pro installed and activated
- Yoast SEO plugin (optional but recommended)

### Environment Variables (alternative to flags)

```bash
export PG_HOST=localhost
export PG_PORT=5432
export PG_NAME=futebol365
export PG_USER=postgres
export PG_PASSWORD=secret
export DJANGO_MEDIA_URL=https://media.futebol365.pt  # for image sideloading
```

### Full Migration (all modules in dependency order)

```bash
wp futebol365 migrate --only=all \
  --pg-name=futebol365 --pg-pass=secret
```

### Per-Module Commands

```bash
# Original modules
wp futebol365 migrate --only=countries
wp futebol365 migrate --only=stadiums
wp futebol365 migrate --only=federations
wp futebol365 migrate --only=fed_competitions
wp futebol365 migrate --only=players
wp futebol365 migrate --only=coaches
wp futebol365 migrate --only=presidents
wp futebol365 migrate --only=referees
wp futebol365 migrate --only=teams
wp futebol365 migrate --only=competitions
wp futebol365 migrate --only=matches    --batch=1000
wp futebol365 migrate --only=transfers
wp futebol365 migrate --only=videos
wp futebol365 migrate --only=galleries
wp futebol365 migrate --only=news       --batch=500
wp futebol365 migrate --only=resolve_fk

# New modules (can run independently after base modules)
wp futebol365 migrate --only=authors
wp futebol365 migrate --only=publishers
wp futebol365 migrate --only=newsstand
wp futebol365 migrate --only=polls
wp futebol365 migrate --only=livestreaming
wp futebol365 migrate --only=rankings
wp futebol365 migrate --only=resolve_fk_new

# Or run all new modules in one step
wp futebol365 migrate --only=all_new
```

### Testing / Dry Run

```bash
# Preview what would be imported (nothing written)
wp futebol365 migrate --only=polls --dry-run

# Import first 50 records only
wp futebol365 migrate --only=rankings --limit=50

# Resume from offset
wp futebol365 migrate --only=matches --offset=10000 --batch=500
```

### Force Re-import

```bash
# Re-import even if already imported
wp futebol365 migrate --only=polls --force
```

### Rollback a Module

```bash
# Delete all imported posts for a module and clear its migration map
wp futebol365 migrate --rollback=polls
wp futebol365 migrate --rollback=rankings
wp futebol365 migrate --rollback=newsstand
```

### Status

```bash
wp futebol365 migrate --only=status
```

---

## Yoast SEO Integration

All CPTs are automatically registered with Yoast SEO:
- Appear in the SEO sitemap
- Show the Yoast SEO meta-box in admin edit screens
- Support OpenGraph / Twitter Card meta tags
- Support breadcrumbs

### SEO fields are stored as standard Yoast postmeta keys:

| Field | Meta Key |
|---|---|
| SEO Title | `_yoast_wpseo_title` |
| SEO Description | `_yoast_wpseo_metadesc` |

These can be written by the migration command via `f365_set_yoast_seo()`:

```php
f365_set_yoast_seo( $post_id, 'Custom SEO Title', 'Custom meta description.' );
```

---

## Image Handling

The migration command handles all image sideloading:

1. **Deduplication**: checks `_source_url` postmeta — skips if already sideloaded
2. **Downloads**: uses WordPress `download_url()` (respects SSL, follows redirects)
3. **Upload**: uses `media_handle_sideload()` — triggers thumbnail generation
4. **Featured image**: calls `set_post_thumbnail()` automatically
5. **Error logging**: image failures logged per-module without stopping the import

Base URL for relative image paths: set `DJANGO_MEDIA_URL` env var (default: `https://media.futebol365.pt`).

---

## Author / Native WP Users

Authors are **not** a new CPT. The Django `Source` model maps to WordPress native users:

- `Source.name` → `display_name`
- `Source.slug` → `user_login`
- Role assigned: `author`
- `django_source_id` stored as user meta for audit

News posts (`post_author`) are resolved to the correct WP user in the `resolve_fk_new` step.

---

## Logging

Each migration run creates a new timestamped log file:
```
/wp-content/plugins/futebol365/logs/f365-polls-2024-03-15-143022.log
```

Log entries include:
- `OK` — successful imports
- `SKIP` — already imported (duplicate prevention)
- `FAIL` — insertion errors
- `IMAGE_FAIL` — image download/sideload errors
- `SUMMARY` — final counts per module

The `logs/` directory is protected from public access by `.htaccess` (`deny from all`).

---

## Data Integrity

- **Duplicate post prevention**: `already_imported()` checks the migration map before inserting
- **Duplicate term prevention**: `ensure_term()` uses `get_term_by('name')` before `wp_insert_term()`
- **Duplicate image prevention**: `sideload_image()` checks `_source_url` postmeta
- **Migration maps**: stored as `wp_options` rows (`f365_mig_{type}`) — JSON `{django_id: wp_id}`
- **Original dates**: `post_date` and `post_modified` preserve PostgreSQL timestamps
- **Original field names**: all Django field names preserved as postmeta keys

---

## Dependencies

| Plugin | Required | Notes |
|---|---|---|
| Advanced Custom Fields Pro | ✅ Yes | ACF field groups |
| Yoast SEO | ⚠ Recommended | SEO meta-box + sitemap |
| WordPress 6.0+ | ✅ Yes | |
| PHP 8.1+ | ✅ Yes | Uses named arguments, `str_starts_with()` |
| PHP `pgsql` extension | ✅ (CLI only) | Required for WP-CLI migration |
