# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Workflow (where to commit/push, how to release) is in `AGENTS.md` — read it before pushing or tagging.**

StoreDash is a WordPress/WooCommerce companion plugin (`storedash.php` is the entry file) that
exposes enhanced REST endpoints and outbound webhooks for the StoreDash app. It targets PHP 7.4+,
WordPress 5.8+, WooCommerce 6.0+, and declares HPOS + cart/checkout blocks compatibility. There is
no build step for the runtime code — PHP + committed `vendor/` ship as-is.

## Ecosystem

Part of StoreDash (multi-repo). Consumers of this plugin:
- **storedash-sync** (Go) calls `storedash/v1/*` and receives the outbound webhooks.
- The **woo-dash** app ships this plugin to merchants via `GET /api/plugin/download`, rebuilding its
  served copy (`public/storedash-plugin.zip`) from this repo's `composer build-zip`.

Extracted from the woo-dash monorepo (2026-07); woo-dash expects it cloned as a **sibling**
`../storedash-helper` for local dev and delegates its plugin build to `scripts/build-zip.sh` here.

The canonical repo is the **public** GitHub repo `hafsteinnvidar/storedash-wp-helper` (the local
checkout directory is still named `storedash-helper`). Public because installed plugins fetch updates
from its GitHub Releases — never commit secrets, store credentials, or customer data here.

## Commands

```bash
composer install          # dev dependencies (phpcs, phpunit); vendor/ is committed for distribution
composer test             # run PHPUnit suite (vendor/bin/phpunit)
vendor/bin/phpunit tests/Discounts/Discount_Priority_ResolverTest.php   # run a single test file
vendor/bin/phpunit --filter test_method_name                            # run a single test method
composer phpcs            # lint against WordPress coding standards (phpcs.xml)
composer phpcbf           # auto-fix lint violations
composer build-zip        # build dist/storedash.zip + dist/storedash.json (scripts/build-zip.sh)
```

Tests are **standalone unit tests** — they do NOT boot WordPress. `tests/bootstrap.php` stubs the
minimal WP/WooCommerce surface (e.g. `WC_Product`, a fake cart, `WC()`) and `require`s the class
under test directly. Only pure-logic classes that guard on `ABSPATH` and avoid live WP calls are
testable this way; most of the plugin is integration code exercised in a real WP install.

## Architecture

Boot order (`storedash.php` → `storedash_init` on `plugins_loaded` priority 20):
1. `StoreDash_Requirements` gates activation (PHP/WP/WC versions); on failure it shows admin notices and returns.
2. `StoreDash_Loader->register()` — a hand-rolled SPL autoloader (registered *before* Composer's)
   with explicit PSR-4 maps for the PascalCase module dirs, plus a legacy `Woodash\` fallback.
3. `StoreDash_Bootstrap::instance()->init()` — the real wiring: `include_files()` requires every
   procedural/`class-*.php` file, then registers API + components on `init`.

**Two file/class conventions coexist deliberately:**
- **PSR-4 modules** — `inc/Products/`, `inc/Carts/`, `inc/Discounts/`, `inc/Orders/`
  (PascalCase dirs, class-name-matches-filename, autoloaded). phpcs.xml explicitly excludes these
  dirs from WordPress file-naming rules so the autoloader works on case-sensitive filesystems.
- **WordPress-style procedural includes** — everything else under `inc/` (`class-*.php`,
  `api/*.php`, `services/**`) is `require_once`'d explicitly in `StoreDash_Bootstrap::include_files()`.
  If you add such a file, you must add its `require_once` there; it will not autoload.

Each PSR-4 module follows the same shape: a `*_Manager.php` coordinator, a `Route_Registry.php`,
and `Controllers/` + `Permissions/` (see the per-module `README.md` files).

**REST API:** `StoreDash_API` (singleton, `inc/api/api.php`) wires up feature controllers. All
routes register under the single namespace **`storedash/v1`**. Test with
`GET /wp-json/storedash/v1/ping`. Additional standalone controllers in `inc/api/` (orders, coupons,
media, emails, dropp, shipment-tracking, headless-checkout) are self-registering or wired in
`include_files()`.

**Other subsystems** (all wired in `Bootstrap`): outbound webhooks (`inc/services/webhooks/`),
cart tracking + abandoned-cart recovery (`inc/services/cart/`, table via `storedash_get_cart_table_name()`),
waitlist / back-in-stock, marketing opt-in, custom order statuses (registered on `init` priority 0 —
early, so WC REST/list queries recognize custom slugs), Elementor widgets (`inc/widgets/`, only when
Elementor is loaded), third-party integrations (`inc/integrations/`, extend `Base_Integration`,
register via `Integration_Registry`), and optional modules (`inc/modules/`, via `Module_Loader`).

**Schema upgrades:** `StoreDash_Bootstrap::maybe_create_tables()` re-runs `StoreDash_Activator::activate()`
whenever `storedash_db_schema_version` is behind the hardcoded current version — this handles
plugin updates that skip the activation hook. Bump that constant when changing DB schema.

## Auth (security-critical)

Requests authenticate via a **real WooCommerce consumer key** (`determine_current_user` →
`wp_woocommerce_api_keys`, `hash_equals`) requiring the `manage_woocommerce` capability — see
`class-storedash-auth-handler.php`. `X-StoreDash-Source` is **NOT** used for authorization.
Outbound/inbound webhooks sign and verify with **constant-time HMAC** (`X-WooDash-Signature`).

Only two intentional **public** (unauthenticated) routes exist: `/ping` (onboarding verification, no
version exposure — `inc/api/api.php`) and the token + rate-limited `/recover-cart`
(`inc/services/cart/Cart_Recovery.php`). Do not add unauthenticated privileged routes; do not gate
authz on a request header. (Both are the only `permission_callback => '__return_true'` routes — grep
to confirm before adding a third.)

## Backward compatibility (important, non-obvious)

This plugin was renamed from "woodash". Two rules:
- The **`storedash/v1` REST namespace is the only one registered** — the old `woodash/v1` REST
  namespace was removed. Do not re-add it.
- The **`woodash_*` *stored* contract remains fully live**: the `woodash_carts` table (existing
  installs), `woodash_*` options, `_woodash_*` meta, the `woodash_webhook` receiver hook, and the
  `X-WooDash-Signature` header. Do not rename these. `storedash_get_cart_table_name()` auto-detects
  `woodash_carts` vs `storedash_carts`. `WOODASH_HELPER_*` constants alias the `STOREDASH_*` ones.
- **Cross-repo string contract:** woo-dash has a test asserting
  `inc/services/waitlist/class-waitlist-handler.php` emits `'source' => 'waitlist_widget'`. That
  literal is a contract read by another repo — don't rename it casually.

## Releasing

Installed plugins update themselves from GitHub Releases (see "Self-hosted updates" below), so
**publishing a release is what ships a version to merchants.**

1. Bump `Version:` in `storedash.php` (and `STOREDASH_VERSION`), the `Stable tag:` in `readme.txt`
   (must match `Version:` exactly), AND the schema-version constant checked by
   `StoreDash_Bootstrap::maybe_create_tables()` if the DB schema changed. Add a `= x.y.z =` entry
   under `== Changelog ==` in `readme.txt` — it becomes the release notes and the "View details"
   changelog merchants see.
2. Run `composer phpcs && composer test && composer build-zip`; commit to `main`.
3. Tag and push: `git tag v1.19.0 && git push origin main v1.19.0`. The `Release` workflow
   (`.github/workflows/release.yml`) refuses a tag that doesn't match both version strings, runs
   lint + tests, builds the zip, and publishes a GitHub Release with `storedash.zip` and
   `storedash.json` attached. Every site sees "Update available" within ~12 hours.
   A tag with a suffix (`v1.20.0-beta.1`) is published as a pre-release, which merchants never see.
4. woo-dash still serves `public/storedash-plugin.zip` for first installs; copy `dist/storedash.zip`
   there and commit when the first-install copy should move forward.

### Self-hosted updates

`inc/core/class-storedash-updater.php` (registered in `storedash.php` *before* the requirements gate,
so a site with WooCommerce deactivated can still be offered a fix) hooks WordPress core's
`Update URI` mechanism: the `Update URI: https://storedash.app/wp-helper` header makes core call
`update_plugins_storedash.app`, which fetches
`https://github.com/hafsteinnvidar/storedash-wp-helper/releases/latest/download/storedash.json`
(overridable via the `storedash_update_manifest_url` filter, cached in the
`storedash_update_manifest` transient for 6h; "Check again" bypasses the cache). `plugins_api` fills
the "View details" modal, and `upgrader_pre_download` verifies the zip's SHA-256 from the manifest
before install. The manifest is generated by `scripts/build-manifest.php` from the plugin headers,
`readme.txt` and the zip hash, so it cannot drift from the zip. Do not change the `Update URI` host
or the manifest URL without a redirect in place — both are baked into every installed copy.

### Building the distributable zip

`composer build-zip` (→ `scripts/build-zip.sh`) produces `dist/storedash.zip`, the file a merchant
uploads via **Plugins → Add New → Upload**. `dist/` is gitignored — the zip is a build artifact, not
committed.

- **Requirements:** `composer` and `rsync` on `PATH` (composer is guaranteed, since the command is a
  composer script). The `--no-dev` prune runs against the committed `composer.lock` with no runtime
  package deps, so **no network is needed**.
- **What it does:** stages the source under `dist/storedash/`, then ships **runtime code only** — it
  strips VCS/tooling dotfiles, `tests/`, `docs/`, `scripts/`, `node_modules/`, `phpcs.xml`,
  `phpunit.xml.dist`, all `*.md` (README.md, CLAUDE.md, per-module docs), and `composer.json`/`.lock`.
  It re-runs `composer install --no-dev --optimize-autoloader` in the stage so dev packages (phpunit,
  phpcs, wpcs, `vendor/bin`) are removed and only the production autoloader remains. The committed
  `vendor/` is never modified — only the disposable stage copy is pruned.
- **Output shape:** a single top-level `storedash/` directory (the plugin slug / Text Domain) so it
  installs cleanly. Inspect with `unzip -l dist/storedash.zip`; sanity-check no dev files leaked:
  `unzip -l dist/storedash.zip | grep -E "tests/|docs/|phpcs|phpunit|composer\.(json|lock)|CLAUDE|README\.md"` → empty.
- **Adding a runtime Composer dependency:** because the zip is built with `--no-dev`, a new *production*
  `require` is picked up automatically; a `require-dev` package is correctly excluded. No exclude-list
  maintenance needed.

## Conventions

- Logging goes through `StoreDash_Helpers::log_message($msg, $level)` (WC_Logger; view under
  WooCommerce → Status → Logs → `storedash-{date}.log`). `error` always logs; `warning/info/debug`
  only with `WP_DEBUG`. Use `StoreDash_Helpers::debug_log()` for debug-only lines.
- Every PHP file starts with the `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard.
- phpcs enforces WordPress-Core/Docs/Extra, the `storedash`/`woodash` global prefixes, and text
  domain `storedash`. Run `composer phpcs` before considering work done.

`inc/README.md` has a deeper module-by-module tour (note: it predates some current wiring — trust
`StoreDash_Bootstrap::include_files()` for the authoritative list of what actually loads).
