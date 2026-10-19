# Seo Module (B1: head renderer, B3: REST)

Native SEO for Storedash merchants so they can drop Yoast / RankMath. Storedash owns all values; this module reads them at render time. Plan: woo-dash `docs/plans/2026-10-16-seo-search-console-ai-visibility.md` (§4.9 meta keys, §5 Phase B, §6.4 coexistence, §6.5 performance).

## When it renders

Both must be true, otherwise the module registers **zero** front-end hooks:

1. Option `storedash_seo_enabled` is truthy (set by Storedash; default off).
2. No other SEO plugin is active: Yoast (`WPSEO_VERSION`), RankMath (`RANK_MATH_VERSION`), SEOPress (`SEOPRESS_VERSION`), AIOSEO (`AIOSEO_VERSION`), The SEO Framework (`THE_SEO_FRAMEWORK_VERSION`). Constants are checked first, then the `active_plugins` / `active_sitewide_plugins` options (same data `is_plugin_active()` reads).

Also skipped in admin, AJAX and cron requests.

`Seo_Manager` is constructed on every request by `StoreDash_API` and always registers the filter `storedash_seo_conflict` (returns `yoast|rankmath|seopress|aioseo|seoframework` or `null`) and the REST routes below — even when the module is off or another SEO plugin is active, so Storedash can read status and migrate while Yoast / RankMath is still installed.

## What renders

Supported pages: single product, shop page (meta read from the WC shop page), pages, posts, and term archives for `product_cat`, `product_tag`, `product_brand`, `pwb-brand`, `pa_*`, `category`, `post_tag`.

| Output | Source |
|---|---|
| `<title>` (via `pre_get_document_title`, returned `esc_html`-escaped because a short-circuit skips core's escaping; WP's own `_wp_render_title_tag` prints it) | `_storedash_seo_title` (rendered like a template when it uses supported variables, e.g. `Buy {product} {sep} {store}`; else verbatim), else the settings template |
| `<meta name="description">` | `_storedash_seo_description`, else short description / excerpt / term description cut to 160 chars |
| `<link rel="canonical">` (core `rel_canonical` removed on these pages only) | `_storedash_seo_canonical`, else permalink / term link / shop archive link + `/page/N/` |
| robots (via the `wp_robots` filter, never a raw tag) | `_storedash_seo_robots` JSON + settings rules (below). Only adds restrictions; WP/WC's own noindex stays |
| `og:type` (`product`/`website`), `og:title`, `og:description`, `og:url`, `og:image`, `og:site_name`, `og:locale` | OG meta, else SEO title / description; image = `_storedash_seo_og_image` (URL or attachment ID), else product image / featured image / category thumbnail, else `default_og_image_url` |
| `product:price:amount`, `product:price:currency`, `product:availability` (`instock`/`oos`/`pending`) | product display price, store currency, `get_stock_status()` |
| `twitter:card` (`summary_large_image`), `twitter:title`, `twitter:description`, `twitter:image` | Twitter meta, else OG values |

Empty tags are never printed. Every attribute is escaped (`esc_attr`, URLs `esc_url`). The final tag list passes through the filter `storedash_seo_head_tags` (`$tags, $context`) so later steps can extend it.

Robots rules (settings): `noindex_search` (WP search), `noindex_paginated` (`/page/N`), `noindex_tags` (post tags + `product_tag`), `noindex_attributes` (`pa_*` archives), `noindex_filtered` (`?orderby`, `?filter_*`, `?query_type_*`, `?min_price`, `?max_price`, `?rating_filter`). Rule-based noindex is `noindex, follow`.

## Meta keys (plan §4.9)

Same keys on `postmeta` (products, pages, posts) and `termmeta` (categories, tags, attributes):

`_storedash_seo_title`, `_storedash_seo_description`, `_storedash_seo_focus_keyword`, `_storedash_seo_canonical`, `_storedash_seo_robots` (JSON `{"index","follow","imageindex","archive","snippet"}`, absent = all true), `_storedash_seo_breadcrumb_title`, `_storedash_seo_og_title`, `_storedash_seo_og_description`, `_storedash_seo_og_image`, `_storedash_seo_twitter_title`, `_storedash_seo_twitter_description`.

## Options

| Option | Meaning |
|---|---|
| `storedash_seo_enabled` | Module on/off (Storedash sets it). Default off. |
| `storedash_seo_settings` | Array pushed by Storedash (B3). Until then code defaults in `Settings::defaults()`: templates `{product} {sep} {store}` / `{category} {sep} {store}` / `{page} {sep} {store}`, separator `|`, `noindex_search` + `noindex_filtered` on, the other noindex rules off. |

Title template variables: `{product} {store} {category} {price} {sku} {sep} {page} {brand}`. Unknown variables render empty, dangling separators collapse, `{brand}` is the first `product_brand` / `pwb-brand` term. Expensive variables are only computed when the template uses them.

## Performance

Per request: one `get_post_meta( $id )` or `get_term_meta( $id )` (no key, cache already primed by the main query), the two options above (autoloaded), no queries in loops. Context is resolved once and shared by the title, robots and head callbacks.

## File structure

```
inc/Seo/
├── Seo_Manager.php                  # boot: conflict filter, REST routes, gate, registers Head_Renderer
├── Settings.php                     # storedash_seo_settings accessor + defaults, storedash_seo_enabled
├── Route_Registry.php               # storedash/v1/seo/* (always registered)
├── Controllers/
│   ├── Abstract_Seo_Controller.php  # body / error / success helpers
│   ├── Seo_Settings_Controller.php  # GET/PUT settings, GET status
│   ├── Seo_Bulk_Controller.php      # POST bulk-meta
│   └── Seo_Migrate_Controller.php   # POST migrate
├── fixtures/migration-map.json      # cross-language key map fixture
├── Engine/
│   ├── Plugin_Conflict_Guard.php    # other SEO plugin detection
│   ├── Title_Template.php           # pure template engine
│   ├── Meta_Reader.php              # one meta read per object, §4.9 keys
│   ├── Head_Renderer.php            # hooks + pure tag builder
│   ├── Meta_Writer.php              # validated §4.9 writes (bulk + migrate)
│   └── Migration_Map.php            # Yoast / RankMath → Storedash (pure)
└── README.md
```

## REST endpoints (B3)

All under `storedash/v1`, all require `manage_woocommerce` (`Base_Permissions::check_permission`; the consumer-key user from `StoreDash_Auth_Handler`), same as Discounts / Credit. Errors are `WP_Error` (`{ code, message, data: { status } }`).

### `GET /seo/status`

`{ enabled, conflict: slug|null, version, sitemap_url: null (until B2), detected_plugins: { yoast, rankmath, seopress, aioseo, seoframework }, settings_hash: string|null, wc_version, wp_version, permalinks_pretty }`. `detected_plugins` = active plugins only.

### `GET /seo/settings` · `PUT /seo/settings`

- PUT body `{ enabled: bool, settings: { …keys of Settings::defaults() }, hash: string }`. All three required. Unknown top-level body keys are ignored; unknown **setting** keys → 400 (`storedash_seo_unknown_settings`) listing them. Types per key (bool / string / object), strings ≤ 500 chars, arrays ≤ 64 KB JSON, `default_og_image_url` absolute http(s) or `''` (non-ASCII paths such as `/vörur/þráðlaus/` are accepted; the host must be ASCII / punycode).
- **PUT replaces, it does not merge.** The stored array is rebuilt from the body: a missing key or `null` takes the code default (so `noindex_filtered`, which is not in `seo_store_settings`, may be sent or left out). Always send every key you care about.
- A string setting that the sanitiser (`wp_strip_all_tags`) would change → 400 `storedash_seo_invalid_setting` naming the key, nothing stored — instead of silently storing the stripped value. A lone `<` / `>` separator is such a case; the migrator never produces one (see `‹` / `›` substitution under `/seo/migrate`). Surrounding whitespace is trimmed, not rejected.
- Writes `storedash_seo_settings` (normalized, autoloaded), `storedash_seo_enabled` (`'1'`/`'0'`), `storedash_seo_settings_hash`, then `Settings::reset_cache()`.
- Both return `{ enabled, settings (defaults merged with stored), hash: string|null }`.
- **Hash contract:** opaque. woo-dash computes it (`seo_store_settings.push_hash`) and compares with GET (or status `settings_hash`) to decide whether a push is needed. The plugin never computes one.

### `POST /seo/bulk-meta`

Body `{ items: [ { object: 'product'|'term'|'post'|'page', id, taxonomy? (terms), fields: { seo_title, seo_description, focus_keyword, canonical, robots: {index,follow,imageindex,archive,snippet}, breadcrumb_title, og_title, og_description, og_image, twitter_title, twitter_description } } ] }`, 1–200 items (else 400).

- `id` must be a positive integer (int or digit-only string; `1e3`, `1.5`, `' 7'` → item 400). Field → §4.9 key via `Meta_Writer::FIELDS`. `''` / `null` deletes the key. `robots` → §4.9 JSON (missing directives = true); all true → key deleted. `canonical` / `og_image` absolute http(s) URL (`Settings::is_absolute_url()`: non-ASCII path / query / fragment accepted, `javascript:`, relative and `ftp:` rejected; `og_image` may be an attachment ID int). Text fields strip tags, ≤ 2000 chars. Any invalid field rejects that item (nothing half-written).
- Products must be `product` (variations → 422). Terms: taxonomy must be one the renderer reads (`product_cat`, `product_tag`, `product_brand`, `pwb-brand`, `category`, `post_tag`, `pa_*`) and the term must exist in it.
- Response `{ results: [ { id, object, taxonomy?, ok, written: [keys], deleted: [keys], status?, error? } ], ok_count, error_count }`. Per item: `taxonomy` echoed for `object: 'term'` only; `status` (400 / 404 / 422) + `error` only on failure. Partial success; one object-cache clean per written item.

### `POST /seo/migrate`

Body `{ from: 'yoast'|'rankmath', dry_run = true, limit = 100 (1–200), offset = 0, objects = ['product','term','post','page'], overwrite = false }`.

- `objects` must be a non-empty array of strings from that set (non-string elements → 400).
- One sequence: products → terms (`product_cat`, `product_tag`, `product_brand` / `pwb-brand` if registered) → posts → pages, each by ID asc; posts in `publish|draft|private`, no variations. `offset` indexes that sequence, so pages are stable while writing. Per page: one ID query per type (meta cache primed in one query), then one `get_post_meta( $id )` / `get_term_meta( $id )` per item. A page that processes 0 items (counts and the ID query can disagree on caps / private posts) returns `remaining: 0`, `next_offset: null` — clients loop on `next_offset !== null`, never on `remaining`.
- Mapping (`Engine/Migration_Map.php`, pure): same field names / source keys as woo-dash `seo-meta.ts`, plus OG image. Yoast **term** meta comes from the `wpseo_taxonomy_meta` option (`[taxonomy][term_id]`, `wpseo_noindex = 'noindex'` → index false) — Yoast does not use termmeta. RankMath terms use termmeta with the post keys.
- Robots: Yoast `-noindex` `'1'` → index false (`'0'`/`'2'` true), `-nofollow` `'1'` → follow false, `-adv` list → imageindex/archive/snippet false; legacy `_yoast_wpseo_meta-robots` only when the three keys are absent. RankMath `rank_math_robots` token array. All true → nothing written.
- Per-item SEO titles with variables (`%%title%% %%sep%% %%sitename%%`) are translated (`{product} {sep} {store}`; `%%page%%` dropped, it is empty on single objects) and rendered by the head renderer.
- Per field skip reasons: `exists` (our key set, `overwrite=false`), `template_variables` (a title with a variable Storedash lacks, or any other field with variables — those print verbatim — so the fallback applies), `invalid` (fails `Meta_Writer`, e.g. relative canonical).
- Never writes, deletes or renames Yoast / RankMath meta or options.
- Primary category (`_yoast_wpseo_primary_product_cat` / `rank_math_primary_product_cat`) is returned, not written.
- **offset 0 only:** `templates` (Yoast `wpseo_titles` / RankMath `rank-math-options-titles` → `{product}`/`{category}`/`{page}`, `{store}`, `{sep}`, `{sku}`, `{price}`, `{brand}`; other variables dropped and listed in `warnings`; separator resolved to the character Yoast renders — except `<` / `>` (Yoast `sc-gt` / `sc-lt`, RankMath `&lt;` / `&gt;`), which `PUT /seo/settings` would reject, so they come back as `‹` / `›` with the warning `separator '<' replaced with '‹'`) and `redirects` (RankMath `{prefix}rank_math_redirections` active rows with `exact` sources; Yoast Premium `wpseo-premium-redirects-base` `plain` entries, both as a list of `{ origin, url, type, format }` and keyed by origin) as `{ source_path, target_url, status_code }`, only 301/302/307/308/410, max 5,000 (`redirects_truncated`). Later pages return `templates: null`, `redirects: []`.
- Response `{ dry_run, from, processed, written (items with ≥ 1 field to write), skipped, remaining, next_offset (null when done), sample, templates, redirects, redirects_truncated, primary_categories: [{ product_id, term_id }], warnings }`.
- `sample` (≤ 20 items, only items with ≥ 1 field written or skipped): `{ object, id, taxonomy? (terms only), fields_written: [field], fields_skipped: [{ field, reason }], values: { field: value } }` — `values` holds the value as it would be written (titles already translated to `{product} {sep} {store}`), `fields_skipped[].reason` ∈ `exists | template_variables | invalid`.

Cross-language fixture: `inc/Seo/fixtures/migration-map.json` (asserted by `Migration_MapTest`; woo-dash keeps a copy). `shared` holds the 9 fields woo-dash `seo-meta.ts` reads (`fields`, `yoast`, `rankmath`, `storedash`) so a TS test can assert exact equality; `extra` holds the 3 read-only source keys only the helper maps (`contentScore`, `readabilityScore`, `primaryCategory` — returned, never written to §4.9); `storedash` is the full §4.9 key set (shared + `ogImage` + `robots`).

## Coming next (not built yet)

- **B2**: `Engine/Sitemap_Generator.php` (`/sitemap.xml`), `Engine/Redirect_Manager.php` + `Controllers/Seo_Redirects_Controller.php`.
- **B5**: `Engine/Schema_Builder.php` (JSON-LD `@graph`), `Engine/Bot_Crawl_Logger.php`, robots.txt + `/llms.txt`.

## Tests

`tests/Seo/` (standalone PHPUnit, no WordPress): `Title_TemplateTest`, `Plugin_Conflict_GuardTest`, `Meta_ReaderTest`, `Head_RendererTest`, `Meta_WriterTest`, `Migration_MapTest`, `Seo_Settings_ControllerTest`, `Seo_Bulk_ControllerTest`, `Seo_Migrate_ControllerTest`. Run `vendor/bin/phpunit tests/Seo`.
