
# Seo Module (B1: head renderer, B2: sitemap + redirects, B3: REST, B5: JSON-LD + bot log + robots/llms.txt)

Native SEO for Storedash merchants so they can drop Yoast / RankMath. Storedash owns all values; this module reads them at render time. Plan: woo-dash `docs/plans/2026-10-16-seo-search-console-ai-visibility.md` (§4.9 meta keys, §5 Phase B, §6.4 coexistence, §6.5 performance).

## When it renders

Both must be true, otherwise the module registers **zero** front-end hooks:

1. Option `storedash_seo_enabled` is truthy (set by Storedash; default off).
2. No other SEO plugin is active: Yoast (`WPSEO_VERSION`), RankMath (`RANK_MATH_VERSION`), SEOPress (`SEOPRESS_VERSION`), AIOSEO (`AIOSEO_VERSION`), The SEO Framework (`THE_SEO_FRAMEWORK_VERSION`). Constants are checked first, then the `active_plugins` / `active_sitewide_plugins` options (same data `is_plugin_active()` reads).

Two gates in `Seo_Manager`:

- `is_module_on()` = both conditions above, **any** request type (admin, AJAX, cron, REST included). Boots the sitemap and the redirect engine (B2) — their cache-bust and auto-redirect hooks must see product saves / trashes made in wp-admin.
- `should_render()` = `is_module_on()` and not admin / AJAX / cron. Boots the head renderer (B1).

`Seo_Manager` is constructed on every request by `StoreDash_API` and always registers the filter `storedash_seo_conflict` (returns `yoast|rankmath|seopress|aioseo|seoframework` or `null`) and the REST routes below — even when the module is off or another SEO plugin is active, so Storedash can read status and migrate while Yoast / RankMath is still installed. With Yoast / RankMath active the sitemap and redirects stay fully off too (they ship their own; `Seo_ManagerTest`).

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
| `storedash_seo_settings` | Array pushed by Storedash (B3). Until then code defaults in `Settings::defaults()`: templates `{product} {sep} {store}` / `{category} {sep} {store}` / `{page} {sep} {store}`, separator `|`, `noindex_search` + `noindex_filtered` on, the other noindex rules off. Inner shapes of `schema_org` / `ai_bots` below (B5). |
| `storedash_seo_settings_hash` | Opaque hash of the last settings push (B3). |
| `storedash_sitemap_keys` | Not autoloaded. Transient keys currently cached by the sitemap (B2). |
| `storedash_seo_redirects` | **Not autoloaded.** Redirect table `path => row` (B2). |
| `storedash_seo_redirects_active` | Autoloaded `'1'`/`'0'`: the table has rows (B2). |
| `storedash_seo_redirects_hash` | Opaque hash of the last redirects push (B2). |
| `storedash_schema_override` | B5. Truthy = emit our Product graph while **Yoast** is active (see JSON-LD modes). Default unset/off. No UI yet; Storedash sets it later. |
| `storedash_seo_webhook_url` | B5. URL the hourly bot-crawl flush POSTs to. Default `Bot_Crawl_Logger::DEFAULT_URL` = `https://webhooks.storedash.io/3fzku2m9uahetd` (Hookdeck source `woo-seo-crawl`). Unset → default, `''` → disabled (counts stay in the transient) — same contract as `storedash_credit_webhook_url`. |
| `storedash_seo_catalog_version` | B5, internal. Bumped on product writes (and token rotation) to invalidate `catalog.txt` page caches. |
| `storedash_seo_catalog_token` | B5. Not autoloaded. 32-hex token `catalog.txt` requires (`?token=`); generated on first use, returned as `catalog_token` by `GET/PUT /seo/settings`, replaced by `PUT /seo/settings` with `rotate_catalog_token: true`. |
| `storedash_seo_crawl_stats` | B5. Not autoloaded. `{ last_flush_at, consecutive_failures }` for `GET /seo/crawl-stats`. |

`Settings::reset_cache()` (called after a settings PUT) fires the action `storedash_seo_settings_reset` (`Settings::RESET_ACTION`); settings-derived caches hook it (sitemap pages, B5's llms.txt).

Title template variables: `{product} {store} {category} {price} {sku} {sep} {page} {brand}`. Unknown variables render empty, dangling separators collapse, `{brand}` is the first `product_brand` / `pwb-brand` term. Expensive variables are only computed when the template uses them.

## Performance

Per request: one `get_post_meta( $id )` or `get_term_meta( $id )` (no key, cache already primed by the main query), the two options above (autoloaded), no queries in loops. Context is resolved once and shared by the title, robots and head callbacks.

## JSON-LD (B5)

One `<script type="application/ld+json">` printed in `wp_head` priority 1 right after the meta tags: `{"@context":"https://schema.org","@graph":[…]}`. `Engine/Schema_Builder.php` is pure (`build( array $context ): array` + `render()`; context shape documented in the file header); `Engine/Schema_Output.php` collects the context from the live request **reusing the Head_Renderer context** (no second meta read) and owns the hooks.

Pieces per page (normal mode):

| Page | Pieces |
|---|---|
| every supported page | `Organization` (only when `schema_org.name` is set; `@id` `<home>#organization`, `logo` as `ImageObject`, `sameAs[]`, `telephone`, `email`, `description`), `WebSite` (`@id` `<home>#website`, `publisher` → Organization, `potentialAction: SearchAction` → `<home>/?s={search_term_string}&post_type=product`), `BreadcrumbList` when `breadcrumbs_enabled` (home → shop page → category chain → product; home → ancestor terms → term; last item has no `item`; `_storedash_seo_breadcrumb_title` wins over the name) |
| single product | `Product` (`@id` `<permalink>#product`, `name`, `description` = SEO description → short description → description, plain text ≤ 5,000, `image[]` main + gallery, `sku`, `gtin8/12/13/14` by digit count from WC's `global_unique_id` (WC ≥ 9.2 "GTIN, UPC, EAN or ISBN" field), `brand` from `product_brand` / `pwb-brand`, `category` = first `product_cat`, `aggregateRating` + `review[]` (≤ 10 approved rated top-level reviews, only when WC reviews are enabled and the cached count > 0), `offers: Offer` (`price` decimal string = display price, `priceCurrency`, `availability` `InStock` / `OutOfStock` / `BackOrder`, `priceValidUntil` = sale end date when set and not past else today + 1 year (UTC), `itemCondition: NewCondition`, `url`)). No `mpn` (WC has no field). |
| variable product, ≤ 50 visible variations | `ProductGroup` instead of `Product` (Google's variant guidance): same `@id`, `productGroupID` = parent SKU or ID, `variesBy[]` from the variation attributes that map to `color` / `size` / `material` / `pattern`, `aggregateRating` / `review` on the group, `hasVariant[]` of `Product` (`@id` `<permalink>#variation-<id>`, name = parent name + attribute values, `sku`, `gtin*`, `image` (variation image else parent main), `color` / `size` / … , `offers: Offer` with the variation's price / stock / sale end / permalink). Variations without a price are skipped. |
| variable product, > 50 variations | `Product` with `offers: AggregateOffer` (`lowPrice` / `highPrice` from the cached variation prices, `offerCount`, `availability`, `url`). |
| `product_cat` / `product_tag` / `product_brand` / `pwb-brand` / `pa_*` archive, **page 1 only** | `ItemList` (`@id` `<archive>#itemlist`, `ListItem` → `position` + `url`, ≤ 24 products of the main query). |

Modes (plan §6.4):

| Mode | When | Our pieces | Others |
|---|---|---|---|
| normal | `should_render()` (module on, no SEO plugin) | everything above | WooCommerce's `product`, `review`, `website`, `breadcrumblist` structured data is removed via `woocommerce_structured_data_type_for_page` (WC 11.x `WC_Structured_Data::get_data_type_for_page()`); WC's `order` / `onlinestore` types stay. |
| override | conflict is **`yoast`** AND option `storedash_schema_override` truthy AND module enabled (front-end only) | **only** `Product` / `ProductGroup` | Yoast keeps Organization / WebSite / Breadcrumb / WebPage; Yoast's `Product` / `ProductGroup` / `Offer` / `AggregateOffer` nodes and the WebPage `mainEntity` reference to them are removed via `wpseo_schema_graph` (priority 99). WC's `product` / `review` data removed. Yoast / RankMath data is never written. |
| off | module off, or RankMath / SEOPress / AIOSEO / SEO Framework active, or Yoast without the override | nothing | — |

Escaping: `wp_json_encode( …, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP )` — `<` / `>` become `\u003C` / `\u003E`, so `</script` cannot appear inside the element.

Performance: the only extra queries on a product page are the reviews (one `get_comments`, ≤ 10, only when enabled and count > 0) and the variations (one `_prime_post_caches()` for all ≤ 50 variation rows + meta, then `wc_get_product` per variation from cache, or the cached price range above 50; `get_term_by` once per distinct taxonomy attribute value). Category chains for breadcrumbs use `get_ancestors` (term cache). Everything else comes from the already-loaded product and the B1 context.

### `schema_org` inner shape

`{ name: string, logo: url, url?: url, description?: string, sameAs?: url[], telephone?: string, email?: string }`. `Settings::normalize_schema_org()`: unknown keys dropped, non-URL `logo` / `url` / `sameAs` entries dropped (absolute http(s) only, de-duplicated), strings tag-stripped; returns `{}` when nothing valid is set. The Organization piece renders only when `name` is non-empty; `url` defaults to the home URL; `description` is also the `/llms.txt` blockquote.

## Bot crawl log (B5)

`Engine/Bot_Crawl_Logger.php`, registered on `template_redirect` priority -1 (before B2's sitemap at 0 and redirects at 1, so sitemap fetches and redirected hits count) only when `should_render()`.

**404s:** the UA is trivially spoofable, so a bot hit on a request WP resolved as 404 is held back and counted only when B2 redirects it (action `storedash_seo_redirect_matched`, `Redirect_Manager::MATCHED_ACTION`) or it is `/llms.txt` (action `storedash_seo_llms_served`, `Bot_Crawl_Logger::LLMS_ACTION`). Random 404 paths never enter the map. The handler never throws (try/catch → debug log).

**Matching:** `$_SERVER['HTTP_USER_AGENT']` against one case-insensitive alternation of the vendor UA tokens, compiled once per request, longest token first (`Google-Extended` before `Googlebot`, `Perplexity-User` before `PerplexityBot`). Non-bot traffic = one `preg_match`, nothing read or written.

| slug (plan §4.7) | UA token | notes |
|---|---|---|
| `gptbot` / `oai-searchbot` / `chatgpt-user` | `GPTBot` / `OAI-SearchBot` / `ChatGPT-User` | OpenAI |
| `claudebot` / `claude-user` | `ClaudeBot` / `Claude-User` | Anthropic (`Claude-SearchBot` is not in the plan list → not counted) |
| `perplexitybot` / `perplexity-user` | `PerplexityBot` / `Perplexity-User` | Perplexity |
| `google-extended` | `Google-Extended` | robots.txt-only token, Google sends no such UA → never matches; kept for completeness |
| `googlebot` / `bingbot` | `Googlebot` / `bingbot` | search engines (never blockable from `ai_bots`) |
| `applebot` | `Applebot` | UA; the robots.txt block uses `Applebot-Extended` (AI training opt-out) |
| `amazonbot` / `bytespider` / `ccbot` / `meta-externalagent` | `Amazonbot` / `Bytespider` / `CCBot` / `meta-externalagent` | Amazon / ByteDance / Common Crawl / Meta |

**Transient** `storedash_bot_hits` (TTL 48 h): `array( "YYYY-MM-DD|<bot>|<path>" => array( <hits>, <product_id> ) )` — UTC date (`gmdate`), path = `REQUEST_URI` (unslashed) without query / fragment, ≤ 500 chars, `product_id` = `get_queried_object_id()` when `is_product()` else `0` (the first non-zero id wins). ≤ 5,000 keys: when full, keys are sorted and the oldest (date prefix) dropped first. Known race: two concurrent bot hits may lose one increment — accepted, counts are approximate.

**Hourly flush** — `wp_cron` event `storedash_seo_hourly_flush` (`hourly`), handler registered on every request; scheduled while `storedash_seo_enabled` is on, unscheduled when it is off (self-heal in `Seo_Manager::init()`) and in `StoreDash_Deactivator`. **Opportunistic flush** for stores whose cron never runs: after a counted bot hit, when transient `storedash_seo_last_flush` (set at every flush start, 2 h) is absent and no flush is running, `flush()` is queued on `shutdown`. Transient `storedash_seo_flush_lock` (5 min) keeps cron and the opportunistic flush from overlapping. `Bot_Crawl_Logger::flush()`:

1. URL = `StoreDash_Helpers::resolve_webhook_url( 'storedash_seo_webhook_url', DEFAULT_URL )` (store must be connected: `woodash_store_id` + `woodash_webhook_secret`). **Empty → return, nothing sent, transient kept** (WP_DEBUG log).
2. Read + delete the transient; drain B2's redirect hits when `\StoreDash\Seo\Engine\Redirect_Manager::drain_hits(): array` exists (absent → `[]`, never fails). B2 returns `{ hits: { '/old': 2 }, since }`; `Bot_Crawl_Logger::normalize_redirect_hits()` turns that (or a `[ { source_path, hits } ]` list) into the payload rows.
3. Skip when both lists are empty. Otherwise one POST per chunk — ≤ 2,000 crawl rows **and** ≤ 256 KB of JSON body (`MAX_BODY_BYTES`, plan §6.6), redirect hits ride on the first — `wp_remote_post`, `sslverify` on. In cron / WP-CLI (the hourly event) the POST is **blocking** (timeout 5) and only a 2xx counts as delivered; the opportunistic flush (a visitor's `shutdown`) stays non-blocking (timeout 10), where only connection errors are seen. (A non-blocking flush was observed on wp-env reporting success while the receiver got nothing.)
4. Stats (`storedash_seo_crawl_stats`): `last_flush_at` = now; `consecutive_failures` +1 when any chunk was not delivered, else 0. Rows of failed chunks are merged back into the transient (never lost on one failure) until 3 consecutive failures, then dropped. Redirect hits of a failed first chunk are not merged back.

```json
{ "action": "seo.crawl_batch", "store_id": "1105", "store_url": "https://shop.test", "timestamp": "2026-10-19 12:00:00", "plugin_version": "1.27.0",
  "crawls": [ { "date": "2026-10-19", "bot": "gptbot", "path": "/product/blue-shirt/", "product_id": 123, "hits": 4 },
              { "date": "2026-10-19", "bot": "claudebot", "path": "/", "product_id": null, "hits": 1 } ],
  "redirect_hits": [ { "source_path": "/old/", "hits": 2 } ] }
```

Headers: `Content-Type: application/json`, `X-WooDash-Signature: <hex hash_hmac('sha256', <exact body bytes>, get_option('woodash_webhook_secret'))>` — identical to `Credit_Webhook`. `timestamp` = `current_time( 'mysql', true )` (UTC). `crawls[].path` is a path, the receiver builds `seo_bot_crawls.url` (`store_url + path`) and sums `hits` on `(store_id, date, bot, url)`; `product_id` is the WooCommerce product ID (nullable).

**Debug:** `GET /storedash/v1/seo/crawl-stats` (`manage_woocommerce`) → `{ pending_keys, last_flush_at: iso|null, consecutive_failures, webhook_url_configured: bool, next_scheduled_at: iso|null }`.

**Receiver:** storedash-sync `internal/webhooks/seo_bot_crawl.go` (`POST /webhooks/seo-crawl`), reached via Hookdeck connection `woo-seo-crawl` (source `https://webhooks.storedash.io/3fzku2m9uahetd`). It verifies the signature with the store's `woodash_webhook_secret`, caps the body at 256 KB, and upserts `seo_bot_crawls` (summing on `store_id,date,bot,url`). `redirect_hits` are added to `seo_redirects.hits` (exact `source_path` match) in the same transaction.

## robots.txt, /llms.txt, catalog.txt (B5)

`Engine/Robots_Txt.php` (hooks only when `should_render()`):

- **`robots_txt` filter** (priority 20, `$output, $public`): skipped for non-public sites; appends one block per `ai_bots` slug set to `false`: `User-agent: <token>` / `Disallow: /`; then `Sitemap: <home>/sitemap.xml` only when B2's `\StoreDash\Seo\Engine\Sitemap_Generator` exists (and its static `is_active()` returns true, if it has one). WooCommerce adds nothing to robots.txt.
- **`ai_bots` inner shape**: `{ "<slug>": bool }`, slugs = `Settings::AI_BOT_SLUGS` (plan §4.7 minus `googlebot` / `bingbot` — search engines are never blocked here). `false` → block emitted, `true` / absent → allowed (nothing). `Settings::normalize_ai_bots()` drops unknown slugs and non-boolean values. slug → robots token: `gptbot→GPTBot`, `oai-searchbot→OAI-SearchBot`, `chatgpt-user→ChatGPT-User`, `claudebot→ClaudeBot`, `claude-user→Claude-User`, `perplexitybot→PerplexityBot`, `perplexity-user→Perplexity-User`, `google-extended→Google-Extended`, `applebot→Applebot-Extended` (Apple's AI-training opt-out; plain `Applebot` would also drop the store from Siri / Spotlight), `amazonbot→Amazonbot`, `bytespider→Bytespider`, `ccbot→CCBot`, `meta-externalagent→meta-externalagent`. Note `ChatGPT-User`, `Claude-User` and `Perplexity-User` are user-triggered fetchers that may ignore robots.txt by their vendors' own docs.
- **`/llms.txt`** when `llms_txt_enabled`: served from `template_redirect` priority 1 by path match (`<home path>/llms.txt`, case-insensitive; no rewrite rule, nothing to flush), `text/plain; charset=UTF-8`, `X-Robots-Tag: noindex`, cached 12 h in transient `storedash_seo_llms_txt`, busted on `update_option_storedash_seo_settings` (every settings PUT). Format per llmstxt.org: `# <schema_org.name or site name>`, `> <schema_org.description or tagline>`, one plain paragraph, `## Categories` (top-level `product_cat` with products, by count, ≤ 50, `uncategorized` skipped, `- [Name](link): N products`), `## Catalog` → link to `rest_url( 'storedash/v1/seo/catalog.txt' )` **with `?token=`**, `## Policies` (published WC terms page, WC refund/returns page, WP privacy page). Fires `storedash_seo_llms_served` before serving (bot crawl count). Also busted by a catalog token rotation.

`Controllers/Seo_Catalog_Controller.php` — **`GET /storedash/v1/seo/catalog.txt?token=<t>&page=N`**, the one route in this module without WC auth (`permission_callback => '__return_true'`; public product data only). 404 `storedash_seo_catalog_disabled` unless the module is enabled **and** `llms_txt_enabled` **and** `token` equals `storedash_seo_catalog_token` (`hash_equals`). The token only stops blind enumeration — it is published in `/llms.txt`. `?page=N` (1 … 10,000; else 400; past the last page → 404 `storedash_seo_catalog_bad_page`, never cached — the product total is cached per catalog version so that check is free), 500 products per page: published, catalog-visible, password-free, `simple` / `variable` / `grouped` / `external` (no variations), by ID. Body `text/plain; charset=UTF-8`, one per line `name | <price> <currency> | in_stock|out_of_stock|backorder|unknown | <permalink>` (pipes in names become `/`, price = display price, `n/a` when empty). Headers `X-Total-Count`, `Link: <…?token=<t>&page=N+1>; rel="next"` while more pages exist, `X-Robots-Tag: noindex`, `Cache-Control: public, max-age=3600`. Pages cached 1 h in transients `storedash_seo_catalog_<version>_p<N>`; `woocommerce_new_product` / `_update_product` / `_delete_product` / `_trash_product` / `_untrash_product` bump option `storedash_seo_catalog_version` once per request. Plain text leaves the REST server via `rest_pre_serve_request`.

## File structure

```
inc/Seo/
├── Seo_Manager.php                  # boot: conflict filter, REST routes, gates, Sitemap + Redirects + Head_Renderer
├── Settings.php                     # storedash_seo_settings accessor + defaults, storedash_seo_enabled, RESET_ACTION
├── Route_Registry.php               # storedash/v1/seo/* (always registered)
├── Controllers/
│   ├── Abstract_Seo_Controller.php  # body / error / success helpers
│   ├── Seo_Settings_Controller.php  # GET/PUT settings, GET status
│   ├── Seo_Bulk_Controller.php      # POST bulk-meta
│   ├── Seo_Migrate_Controller.php   # POST migrate
│   └── Seo_Redirects_Controller.php # GET/PUT redirects, GET redirects/hits (B2)
│   └── Seo_Catalog_Controller.php   # GET seo/catalog.txt (token-gated plain text, B5)
├── fixtures/migration-map.json      # cross-language key map fixture
├── fixtures/ai-bots.json            # cross-language bot map (slug, UA, robots token, kind, blockable; B5)
├── Engine/
│   ├── Plugin_Conflict_Guard.php    # other SEO plugin detection
│   ├── Title_Template.php           # pure template engine
│   ├── Meta_Reader.php              # one meta read per object, §4.9 keys
│   ├── Head_Renderer.php            # hooks + pure tag builder
│   ├── Meta_Writer.php              # validated §4.9 writes (bulk + migrate)
│   ├── Migration_Map.php            # Yoast / RankMath → Storedash (pure)
│   ├── Sitemap_Generator.php        # /sitemap.xml index + pages, transient cache (B2)
│   ├── Redirect_Manager.php         # redirect table, matcher, hits, auto redirects (B2)
│   ├── Schema_Builder.php           # pure JSON-LD @graph builder (B5)
│   ├── Schema_Output.php            # WP/WC glue: context, wp_head print, WC + Yoast suppression (B5)
│   ├── Bot_Crawl_Logger.php         # UA matcher, transient counter, hourly signed flush (B5)
│   └── Robots_Txt.php               # robots.txt blocks + /llms.txt (B5)
└── README.md
```

## Sitemap (B2)

Served only while `is_module_on()`; WP core's own sitemap is then switched off (`wp_sitemaps_enabled` → false) so the store has one sitemap. When the module is off nothing is touched (core's `/wp-sitemap.xml` keeps working).

| URL | Content |
|---|---|
| `/sitemap.xml` | Index: one `<sitemap>` per page of every served type (no `lastmod`). |
| `/sitemap-products-N.xml` | Published `product` posts (not variations), 1,000 per file, `<loc>` + `<lastmod>` + `<image:image>` for the main image and gallery (max 10). |
| `/sitemap-product_cat-N.xml`, `/sitemap-product_tag-N.xml` (only when `noindex_tags` is false), `/sitemap-product_brand-N.xml` (only when the taxonomy exists) | Terms with ≥ 1 product; `<loc>` only. |
| `/sitemap-pages-N.xml`, `/sitemap-posts-N.xml` | Published pages / posts, `<loc>` + `<lastmod>`. The home URL is always the first entry of `pages-1` (added when the front page shows latest posts, moved first when it is a static page). |
| `/wp-sitemap.xml`, `/wp-sitemap-*.xml` | 301 → `/sitemap.xml` (core's sitemap is off while ours is on). |

Rewrite rules `^sitemap\.xml$` and `^sitemap-([a-z_]+)-([0-9]+)\.xml$` (`add_rewrite_rule`, `top`) → query vars `storedash_sitemap` + `storedash_sitemap_page` → rendered on `template_redirect` priority 0 with `Content-Type: application/xml; charset=UTF-8` and `X-Robots-Tag: noindex`. Unknown type / page out of range → 404. No `<priority>` / `<changefreq>`, no ping. `lastmod` = `post_modified_gmt` as `YYYY-MM-DDTHH:MM:SSZ`. `GET /seo/status` reports `sitemap_url = home_url('/sitemap.xml')` while active (B5's robots.txt reads `Sitemap_Generator::is_active()`).

**Rewrite flush:** `Sitemap_Generator::sync_rewrite_rules( bool )` runs on every request (two autoloaded option reads) and schedules one soft `flush_rewrite_rules( false )` on `wp_loaded` only when "our index rule is in the stored `rewrite_rules`" disagrees with "the module is on" — i.e. once per on/off transition, self-healing if some other plugin flushes our rule away. Nothing with plain permalinks.

**Exclusions:** password-protected posts; the WooCommerce cart / checkout / my-account pages (WC marks them noindex); products with catalog visibility **Hidden** (both `product_visibility` terms `exclude-from-catalog` and `exclude-from-search`; "Search results only" / "Shop only" products have public URLs and stay); `_storedash_seo_robots` with `index: false`; `_storedash_seo_canonical` pointing at another URL (duplicate — a self-canonical is kept); terms with 0 products. Settings noindex rules (`noindex_search` …) do not apply here. Pages are offsets over the raw published count, so every URL lands on exactly one page; a page can be shorter than 1,000 (in theory empty) after exclusions.

**Cache:** each document in a transient `storedash_sitemap_<type>_<n>` / `storedash_sitemap_index` (12 h), keys tracked in option `storedash_sitemap_keys` (not autoloaded) so busting is a `delete_transient()` per key, never a `LIKE` delete. Bust on `save_post` / `deleted_post` of `product|page|post`, `woocommerce_update_product`, `created_term` / `edited_term` / `delete_term` of the three taxonomies, and `storedash_seo_settings_reset` (settings PUT). The index lists pages from one count query per type (`wp_count_posts`, `wp_count_terms`); a cached page costs one transient read, no count.

**Performance (§6.5):** per post page one `WP_Query` (`fields => 'ids'`, `no_found_rows`, no meta / term cache) + one `_prime_post_caches( ids, true, true )` (posts + terms + meta), one more `_prime_post_caches` for the collected attachment IDs; after that every read (`get_post`, `get_post_meta`, `get_the_terms`, `wp_get_attachment_image_url`) is a cache hit. Per term page one `get_terms` with term meta primed. `Sitemap_GeneratorTest` pins a 1,000-URL page at exactly 3 data calls.

## Redirects (B2)

**Storage:** option `storedash_seo_redirects` (not autoloaded), `normalised source path => { target_url, status_code, id?, auto?, reason?, post_id? }`, cap 5,000 rows. A 5,000-row table serialises to ~500 KB, well under MySQL's `max_allowed_packet` (16 MB+ by default). The autoloaded flag `storedash_seo_redirects_active` lets a request with no rows return after one in-memory option read.

**Normalisation** (`Redirect_Manager::normalize_path()`, applied to stored sources and to the request alike): scheme + host dropped, path percent-decoded, leading `/`, repeated slashes collapsed, trailing slash removed (root stays `/`), fragment dropped, query string kept verbatim. So `/old/`, `/old`, `https://x.is/old/` all key `/old`; `/old?x=1` is a different key.

**Matching** (`template_redirect` priority 1 — WP's `redirect_canonical` and WooCommerce's `wc_template_redirect` both run at 10, so ours runs before both): only `GET` / `HEAD`; candidates in order: (1) normalised path **with** query, (2) path without query — the trailing-slash variant is covered by normalisation; on a sub-directory install both are retried with the home path stripped. A match on a protected path is ignored: `/wp-admin`, `/wp-json`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, `/wp-includes`, `/wp-content`, any `?rest_route=`, and the WC cart / checkout / my-account pages (looked up only after a match). Then the hit is counted and: 301 / 302 / 307 / 308 → `wp_redirect( target, status, 'StoreDash' )` + exit (relative targets resolved against `home_url()`); **410** → `status_header( 410 )`, `nocache_headers()`, empty body, exit. Right before responding the action `storedash_seo_redirect_matched` (`$source_path, $status`) fires (bot crawl log).

**Debug bypass:** `?storedash_no_redirect=1` skips the redirect (and the hit) for a logged-in user with `edit_posts`; visitors are redirected as usual.

**Hits:** transient `storedash_seo_redirect_hits` = `{ since: iso, hits: { "<source_path>": int } }` (48 h, ≤ 5,000 keys, new keys beyond that dropped; two concurrent hits may lose one increment — accepted). Drained by `GET /seo/redirects/hits` and by B5's hourly flush (see Shared internals). The plugin never POSTs hits anywhere itself.

**Auto redirects** (rows carry `auto: true`, survive a PUT unless the body names the same source or lists it in `remove`, listed by GET with `auto: true`):

- `post_updated` on `product` / `page` / `post` where `post_name` changed and **both** the old and new state are `publish` → `old permalink path → new permalink` (301). An existing row for that source wins (merchant or auto). An auto row whose source is the new live path is removed (it would redirect the page away; merchant rows are left alone); auto rows that pointed at the old URL are re-pointed to the new one (no 301 chains).
- `save_post` (priority 20) of a **published** `product` / `page` / `post`: an auto row whose source is that post's own path is removed — e.g. the trash redirect of an old product whose freed slug a new product now uses (the matcher runs before WP resolves the request and would otherwise hijack the live page). Merchant rows are untouched.
- `wp_trash_post` (fires before WP appends `__trashed` to the slug) on a published product → `product path → primary category` (301): `_storedash_seo_primary_category` term if present (nothing writes it yet), else the first `product_cat` term (skipping `uncategorized` when there is another), else `wc_get_page_permalink( 'shop' )`. `untrashed_post` removes that row (matched by `post_id` + `reason: trash`; a slug-change row for the same product stays).
- At the cap nothing is dropped; the auto row is skipped (`error_log` when `WP_DEBUG`).

### `GET /seo/redirects` · `PUT /seo/redirects`

Same auth as every `/seo/*` route.

- GET → `{ redirects: [ { source_path, target_url, status_code, auto: bool, id?: int } ], count, hash: string|null }`. Merchant rows first (body order), then auto rows. `source_path` is the normalised key.
- PUT body `{ redirects: [ { source_path, target_url, status_code, id? } ], hash, remove?: [ source_path ] }`. `hash` non-empty string ≤ 500 (opaque — Storedash computes it, like `seo_store_settings.push_hash`); `redirects` an array of ≤ 5,000 rows (else 400 `storedash_seo_redirects_cap`), may be empty (clears the merchant rows). Per row: `source_path` non-empty string ≤ 2,000 that normalises to a path, not the home page `/` (root with a query, e.g. `/?p=123`, is allowed) and not a protected path (`/wp-admin…`, `/wp-json…`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, `/wp-includes…`, `/wp-content…`, any `?rest_route=`); `status_code` ∈ 301, 302, 307, 308, 410 (int or digit string); `target_url` ≤ 2,000, absolute http(s) or site-relative `/…` (never `//…`, `javascript:`, `ftp:`), required unless 410 (then stored as `''`); `id` optional positive int; a target that resolves to its own source (same host, same normalised path — including `/a` → `/a?x=1`, which the query-stripping matcher would loop on) is refused; duplicate normalised sources inside the body are refused. **Any invalid row → 400** `storedash_seo_invalid_redirects` with `data.errors: [ { index, reason } ]` and nothing stored. **PUT replaces the merchant rows** (not a merge); auto rows survive unless the body carries the same `source_path` (then the body row wins and becomes a merchant row). **`remove`** (optional, ≤ 5,000 strings, normalised like sources): auto rows with these sources are deleted — how B4b drops auto rows after importing them into `seo_redirects`. Unknown sources are ignored; a non-path entry → 400 `storedash_seo_invalid_remove` (`data.errors`), a source that is both in `redirects` and in `remove` → 400 `storedash_seo_invalid_redirects`; nothing stored in either case. If merchant + surviving auto rows would exceed 5,000 → 400 `storedash_seo_redirects_cap`, nothing stored. On success writes the table, the active flag and `storedash_seo_redirects_hash`, and returns the GET shape.

### `GET /seo/redirects/hits`

`{ hits: { "<source_path>": int }, since: iso|null }` — returns **and clears** the counter (`hits` is `{}` when empty, never `[]`).

## REST endpoints (B3 + B5)

All under `storedash/v1`, all require `manage_woocommerce` (`Base_Permissions::check_permission`; the consumer-key user from `StoreDash_Auth_Handler`), same as Discounts / Credit — except the public `GET /seo/catalog.txt` (B5, above). Errors are `WP_Error` (`{ code, message, data: { status } }`).

### `GET /seo/status`

`{ enabled, conflict: slug|null, version, sitemap_url: string|null, detected_plugins: { yoast, rankmath, seopress, aioseo, seoframework }, settings_hash: string|null, wc_version, wp_version, permalinks_pretty, robots_txt_physical }`. `detected_plugins` = active plugins only. `robots_txt_physical` = a real `ABSPATH/robots.txt` exists, so WP's virtual robots.txt (and the `ai_bots` blocks / `Sitemap:` line) is never served — woo-dash should warn. `sitemap_url` = `home_url( '/sitemap.xml' )` while the sitemap is active on this request (module on, no conflict), else `null`.

### `GET /seo/settings` · `PUT /seo/settings`

- PUT body `{ enabled: bool, settings: { …keys of Settings::defaults() }, hash: string }`. All three required. Unknown top-level body keys are ignored; unknown **setting** keys → 400 (`storedash_seo_unknown_settings`) listing them. Types per key (bool / string / object), strings ≤ 500 chars, arrays ≤ 64 KB JSON, `default_og_image_url` absolute http(s) or `''` (non-ASCII paths such as `/vörur/þráðlaus/` are accepted; the host must be ASCII / punycode).
- **PUT replaces, it does not merge.** The stored array is rebuilt from the body: a missing key or `null` takes the code default (so `noindex_filtered`, which is not in `seo_store_settings`, may be sent or left out). Always send every key you care about.
- A string setting that the sanitiser (`wp_strip_all_tags`) would change → 400 `storedash_seo_invalid_setting` naming the key, nothing stored — instead of silently storing the stripped value. A lone `<` / `>` separator is such a case; the migrator never produces one (see `‹` / `›` substitution under `/seo/migrate`). Surrounding whitespace is trimmed, not rejected.
- Writes `storedash_seo_settings` (normalized, autoloaded), `storedash_seo_enabled` (`'1'`/`'0'`), `storedash_seo_settings_hash`, then `Settings::reset_cache()`.
- Both return `{ enabled, settings (defaults merged with stored), hash: string|null, catalog_token }`. PUT with top-level `rotate_catalog_token: true` (literal boolean; not a setting, not part of the hash) replaces the catalog token after storing.
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

## Shared internals

- `Redirect_Manager::drain_hits(): array` (static) — returns `{ hits: { "<source_path>": int }, since: iso|null }` and clears the transient. B5's hourly flush calls it and ships `hits` as `redirect_hits` in the same payload as the bot-crawl counts; `GET /seo/redirects/hits` uses the same method, so whichever runs first gets the counts.
- `Sitemap_Generator::is_active(): bool` (static) — true once `register_hooks()` ran on this request; B5's `robots_txt` filter appends `Sitemap: <home>/sitemap.xml` when true. `Sitemap_Generator::reset_active()` clears it (tests).
- `Settings::RESET_ACTION` (`storedash_seo_settings_reset`) — fired by `Settings::reset_cache()` after a settings PUT.
- `Redirect_Manager::MATCHED_ACTION` (`storedash_seo_redirect_matched`) and `Bot_Crawl_Logger::LLMS_ACTION` (`storedash_seo_llms_served`) — let the bot logger count 404 requests that a redirect or `/llms.txt` answers.
- Hook priorities on `template_redirect`: bot logger -1, sitemap render 0 (also the `/wp-sitemap*.xml` 301), redirect matcher 1, `/llms.txt` 1, WP canonical / WC 10.

## Coming next (not built yet)

- **B4b / receiver**: the `seo.crawl_batch` ingest (woo-dash route or storedash-sync handler) + Hookdeck source + `storedash_seo_webhook_url`; woo-dash UI for `schema_org`, `ai_bots`, `llms_txt_enabled`.
- **D-lane**: AI visibility reports read `seo_bot_crawls` filled by the flush above.

## Cron

`storedash_seo_hourly_flush` (hourly, B5) — scheduled while `storedash_seo_enabled` is on, cleared when off and in `StoreDash_Deactivator::deactivate()`.

## Tests

`tests/Seo/` (standalone PHPUnit, no WordPress): `Title_TemplateTest`, `Plugin_Conflict_GuardTest`, `Meta_ReaderTest`, `Head_RendererTest`, `Meta_WriterTest`, `Migration_MapTest`, `Seo_Settings_ControllerTest`, `Seo_Bulk_ControllerTest`, `Seo_Migrate_ControllerTest`, `Sitemap_GeneratorTest`, `Redirect_ManagerTest`, `Seo_Redirects_ControllerTest`, `Seo_ManagerTest`, `Schema_BuilderTest`, `Bot_Crawl_LoggerTest`, `Robots_TxtTest`, `Seo_Catalog_ControllerTest`, `Ai_Bots_FixtureTest`. Run `vendor/bin/phpunit tests/Seo`.
