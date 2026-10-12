# Static Sitemap Generator

Static XML sitemaps that **mirror Yoast SEO** (post types, taxonomies, authors, noindex rules)
but are pre-generated as real files, served by the web server with zero PHP. Built for sites with
millions of posts.

**Requires Yoast SEO.** No Yoast reindex is needed: posts default to *indexed* and are only
dropped when an explicit noindex flag exists. Noindex is read directly from where Yoast writes it
on save — post meta `_yoast_wpseo_meta-robots-noindex` (`1` = noindex), the `wpseo_taxonomy_meta`
option for terms, user meta `wpseo_noindex_author` for authors, plus the global `wpseo_titles`
settings (per-type / per-taxonomy / author).

## Output

Files are written to a `sitemap/` directory in the web root (created if missing):

```
/sitemap/sitemap_index.xml        <- the index
/sitemap/post-sitemap.xml         <- posts,  bucket 0
/sitemap/post-sitemap2.xml        <- posts,  bucket 1
/sitemap/page-sitemap.xml
/sitemap/category-sitemap.xml
/sitemap/post_tag-sitemap.xml
/sitemap/author-sitemap.xml
```

Naming matches Yoast. `Sitemap: .../sitemap/sitemap_index.xml` is added to robots.txt.

## Incremental updates (only the affected file is rewritten)

Files are chunked by **ID range** — `SSG_CHUNK` (default 1000) IDs per file:

```
bucket(id) = floor((id - 1) / SSG_CHUNK)
```

The mapping is permanent, so:

- **New post** -> highest ID -> latest bucket -> only the latest file is rewritten.
- **Updated / deleted post** -> `bucket(id)` -> only that one file is rewritten.
- The post's **terms** and **author** files are refreshed too (so their `lastmod` tracks the
  latest post, like Yoast).
- **Term / author change** -> only that bucket is rewritten.

Changes are collected per request and flushed on `shutdown` (after the response is sent via
`fastcgi_finish_request`), so editing stays fast. The index rebuilds from a stored registry.

### Yoast vs. stable buckets — the one deviation

Yoast paginates by **count** (1000 entries/page), where adding one post reshuffles every later
page. That makes "rewrite only the affected file" impossible. This plugin keeps file membership
**stable by ID range** instead, so a file holds however many indexable posts fall in its ID window
(up to `SSG_CHUNK`) rather than exactly 1000. Structure, URLs and naming are otherwise the same.

## Install

1. Install and activate **Yoast SEO** (no reindex / SEO data optimization required).
2. Copy `static-sitemap-generator/` to `wp-content/plugins/` and activate.
3. (Optional) `Settings -> Static Sitemap`: pick post types / taxonomies, gzip, core-sitemap toggle.
4. Build from WP-CLI (do **not** build millions from the admin page):

```bash
wp staticsitemap generate --all
```

## WP-CLI

```bash
wp staticsitemap generate --all                  # post types + taxonomies + authors + index
wp staticsitemap generate --type=post,page
wp staticsitemap generate --taxonomy=category
wp staticsitemap generate --author
wp staticsitemap index                           # rebuild index only
wp staticsitemap bucket --type=post --id=1234567 # rebuild one bucket (debug)
wp staticsitemap status
```

## Configuration (wp-config.php)

```php
define( 'SSG_CHUNK', 1000 );   // URLs per file (<=50000). Use 1000 to match Yoast's page size,
                               // or raise (e.g. 20000) to cut file count drastically at 3M+.
define( 'SSG_WINDOW', 5000 );  // rows per query during CLI builds
define( 'SSG_DIR', trailingslashit( ABSPATH ) . 'sitemap/' );
define( 'SSG_URL', home_url( '/sitemap/' ) );
```

Filters: `ssg_post_types`, `ssg_taxonomies`.

## Notes

- Reads are raw `$wpdb` against core tables (posts/terms/users + Yoast meta); writes stream to a temp file + atomic rename.
- A MySQL named lock serializes concurrent incremental flushes / index writes.
- Per-post / per-term / per-author noindex changes are picked up automatically on save.
  Changing a **global** Yoast noindex setting (e.g. noindex a whole post type) needs a rebuild of
  that object: `wp staticsitemap generate --type=<type>` (or `--all`).
- Term and author `<lastmod>` are omitted (post `<lastmod>` uses `post_modified`).
- Bulk SQL imports bypass hooks — run a CLI rebuild afterwards.
- Images in sitemaps are not emitted; single site only.
