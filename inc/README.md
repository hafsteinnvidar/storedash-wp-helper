# StoreDash Plugin Architecture

**Plugin Version**: 1.11.0
**Type**: WordPress / WooCommerce companion plugin (enhanced REST endpoints + outbound webhooks)

> Authoritative load order lives in `StoreDash_Bootstrap::include_files()`
> (`inc/core/class-storedash-bootstrap.php`). If a file isn't autoloaded (see below) and isn't
> `require_once`'d there, it does not load. Keep this document in sync with that method.

---

## Directory Structure

```
inc/
  core/            # Plugin lifecycle: activator, deactivator, requirements, loader, bootstrap, helpers, auth, base permissions
  utilities/       # Global helper functions + setup.php
  api/             # REST API controllers (procedural includes)
  admin/           # Admin UI (unified admin + cart settings)
  services/        # Business logic: cart/, waitlist/, optin/, enquiry/, webhooks/, custom order statuses
  integrations/    # Third-party plugin integrations (Base_Integration + registry; posturinn/)
  modules/         # Module_Loader (currently no registered modules) + headless files loaded via bootstrap require
  widgets/         # Elementor widgets (loaded only when Elementor is present)
  Products/        # PSR-4 module
  Carts/           # PSR-4 module
  Discounts/       # PSR-4 module
  live-chat.php    # Frontend live-chat feature (single file)
```

### Two file/class conventions coexist deliberately

- **PSR-4 modules** — `Products/`, `Carts/`, `Discounts/`. PascalCase dir
  names, class name matches file name, resolved by `StoreDash_Loader` (`inc/core/class-storedash-loader.php`).
  `phpcs.xml` excludes these dirs from WordPress file-naming rules so autoloading works on
  case-sensitive filesystems.
- **WordPress-style procedural includes** — everything else (`class-*.php`, `api/*.php`,
  `services/**`, `utilities/**`). These are **not autoloaded**; each is `require_once`'d explicitly in
  `StoreDash_Bootstrap::include_files()`. Add new such files there.

Each PSR-4 module follows the same shape: a `*_Manager.php` coordinator, a `Route_Registry.php`, and
`Controllers/` + (where present) `Contracts/`, `Abstract_*_Controller.php`. See each module's own
`README.md`.

---

## Boot Sequence

`storedash.php` → `storedash_init` (hooked on `plugins_loaded`, priority 20):

1. **`StoreDash_Requirements`** — gates activation on PHP 7.4+, WP 5.8+, WC 6.0+. On failure it shows
   admin notices and returns (nothing else loads).
2. **Admin interface** (only `is_admin()`) — `StoreDash_Admin` (`inc/admin/`).
3. **`StoreDash_Loader->register()`** — registers the SPL autoloader *before* Composer's, then loads
   Composer's `vendor/autoload.php` if present.
4. **`StoreDash_Bootstrap::instance()->init()`** — the real wiring:
   - registers non-persistent cache groups (`woodash_carts`; rate-limit counters use DB-backed transients),
   - `maybe_create_tables()` (schema upgrades, see below),
   - `include_files()` (requires every procedural file),
   - `init_api()` (the REST layer),
   - hooks `init` at priority 0 for custom order statuses (early — so WC REST/list queries recognize
     custom slugs) and priority 10 for the remaining components.

---

## Core (`core/`)

- **class-storedash-activator.php** — activation: creates/upgrades DB tables via `dbDelta()`.
- **class-storedash-deactivator.php** — clears cron + transients. Does NOT delete data (see `uninstall.php`).
- **class-storedash-requirements.php** — environment gate + admin notices.
- **class-storedash-loader.php** — SPL autoloader; explicit PSR-4 maps + legacy `Woodash\` and generic
  `StoreDash\` fallbacks; validates resolved paths stay inside the plugin dir.
- **class-storedash-bootstrap.php** — singleton; the include/init hub described above.
- **class-storedash-helpers.php** — static utilities incl. `log_message()` (WC_Logger) and `debug_log()`.
- **class-storedash-auth-handler.php** — onboarding/auth callback handling to app.storedash.io.
- **class-base-permissions.php** — shared REST permission logic.

---

## REST API (`api/`)

`StoreDash_API` (`api.php`, singleton) instantiates the feature controllers. **All routes register
under the single namespace `storedash/v1`.** (The legacy `woodash/v1` REST namespace was removed —
do not re-add it.)

Controllers / self-registering endpoints (all `require_once`'d in `Bootstrap`):

- **api.php** — main controller; wires products, orders, taxonomies, emails, carts, media, discounts, coupons.
- **emails.php** — list/toggle WooCommerce emails.
- **media.php** — uploads + URL-based media imports.
- **orders.php**, **coupons.php** — order/coupon endpoints.
- **headless-checkout.php** (+ `modules/headless-checkout.php`) — headless checkout support.
- **dropp.php** — writes StoreDash-booked Dropp shipments back into the `dropp-for-woocommerce` table (self-registering).
- **shipment-tracking.php** — writes tracking via the WC Shipment Tracking extension, used by ShipStation (self-registering).

Sanity check: `GET /wp-json/storedash/v1/ping`, `GET /wp-json/storedash/v1/verify`.

---

## Feature Modules (PSR-4)

- **Products/** — extends WC native `wc/v3/products` with custom fields; no custom endpoints. Brands, categories, tags and attributes all use native WC endpoints (WC 9.6+ required for brands).
- **Carts/** — `Carts_Manager` coordinator, `Route_Registry`, `Controllers/` (Recovery, Settings), `Contracts/`, permission handling.
- **Discounts/** — `Discounts_Manager`, `Route_Registry`, `Controllers/` (Delete, Status, Sync, Toggle),
  `Engine/` (matcher, priority resolver, BOGO + quantity rules, dynamic price display),
  `Sync/` (DB handler + sync manager), `Blocks/` (Store API integration).

---

## Services (`services/`)

- **cart/** — `Cart_Data`, `Cart_Tracking`, `Cart_Recovery`. Cart tracking always
  registers its cron hooks; the full tracker instantiates only on frontend / WC-AJAX requests. Cart
  table name resolved by `storedash_get_cart_table_name()`.
- **waitlist/** — back-in-stock: `class-waitlist-handler`, `class-stock-monitor`, `class-waitlist-api`,
  `class-yith-migration` (imports from YITH waitlist).
- **optin/** — marketing opt-in: `class-optin-handler` (checkout checkbox + Elementor signup) and
  `class-blocks-optin-field` (Store API / block checkout, bridges into the handler).
- **enquiry/** — `class-enquiry-handler` (product enquiry).
- **webhooks/** — `webhooks.php` (registration/handling), `webhook-payload-modifier.php`,
  `webhook-filter.php`. Inbound receiver hook is `woodash_webhook`;
  signature header is `X-WooDash-Signature`.
- **class-custom-order-statuses.php** — registers custom WC order statuses (e.g. ready-for-pickup);
  loaded early on `init` priority 0.

---

## Admin (`admin/`)

- **class-storedash-admin.php** — unified admin interface (only loaded when `is_admin()`); includes
  the cart-settings panel + its AJAX save handler (`ajax_save_cart_settings`).
- **class-admin-components.php**, **class-admin-assets.php** — shared UI + asset enqueue.

---

## Integrations (`integrations/`) & Modules (`modules/`)

- **Integrations** extend `Base_Integration` and are discovered/instantiated via
  `StoreDash_Integration_Registry::init()`. Each integration wires its own WP hooks in its
  constructor; the registry exposes no REST routes. Currently: **posturinn/** (Iceland Post —
  `class-posturinn-integration`, `class-posturinn-disable-auto`).
- **Modules**: `StoreDash_Module_Loader` currently registers **no modules** (the custom-tabs module
  was removed; see the loader docblock). `modules/headless-checkout.php` and
  `modules/headless-account-emails.php` are plain `require_once`s from the bootstrap, not
  Module_Loader-managed.

## Widgets (`widgets/`)

Elementor widgets — loaded only when `did_action('elementor/loaded')`: `class-widgets-manager`
(coordinator), signup, product-enquiry, and back-in-stock widgets.

---

## Backward Compatibility (important, non-obvious)

The plugin was renamed from "woodash". The **`woodash_*` stored contract stays live** even though the
`woodash/v1` REST namespace is gone:

- Cart table: existing installs keep `{prefix}woodash_carts`; new installs use `{prefix}storedash_carts`.
  `storedash_get_cart_table_name()` auto-detects — never hardcode the table name.
- Options `woodash_*`, post meta `_woodash_*`, the `woodash_webhook` receiver hook, and the
  `X-WooDash-Signature` header are all still in use — do not rename them.
- Constants: `WOODASH_HELPER_*` alias `STOREDASH_*`.
- Namespaces: `StoreDash\` (modern) and `Woodash\` (legacy) are both autoloaded.

---

## Database Schema Upgrades

`StoreDash_Bootstrap::maybe_create_tables()` re-runs `StoreDash_Activator::activate()` whenever the
stored `storedash_db_schema_version` option is behind the hardcoded current version in that method.
This handles plugin *updates* (which skip the activation hook). **Bump that version constant whenever
you change the schema.**

Cart table columns include: `id`, `cart_token`, `customer_id`, `email`, `cart_contents`, `cart_hash`,
`total`/`subtotal`/`total_tax`/`total_discount`/`total_shipping`/`total_fee`, `currency`, `locale`,
`recovery_status`/`recovery_sent_at`/`recovered_at`, `created_at`/`updated_at`/`abandoned_at`.

---

## Conventions

- **Logging**: `StoreDash_Helpers::log_message($msg, $level)` → WC_Logger; view under WooCommerce →
  Status → Logs → `storedash-{date}.log`. `error` always logs; `warning/info/debug` only with `WP_DEBUG`.
  `StoreDash_Helpers::debug_log()` for debug-only lines.
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Coding standards: `composer phpcs` (WordPress-Core/Docs/Extra via `phpcs.xml`), `composer phpcbf` to
  auto-fix. Global prefixes: `storedash`/`woodash`/`STOREDASH`/`StoreDash`. Text domain: `storedash`.
- Tests: `composer test` (PHPUnit). Tests are **standalone** — `tests/bootstrap.php` stubs a minimal
  WP/WC surface; they do not boot WordPress. Only pure-logic classes (e.g. the discount engine) are
  covered this way.

---

## Where to Put New Code

- **REST endpoint** in an existing PSR-4 module → its `Route_Registry.php`. Standalone → new file in
  `api/` + `require_once` in `Bootstrap::include_files()`.
- **Business logic** → appropriate `services/` subdir (+ include in `Bootstrap`).
- **Integration** → extend `Base_Integration`, add under `integrations/`, register via `Integration_Registry`.
- **Optional module** → add under `modules/`, register via `Module_Loader`.
- **Helper** → static on `StoreDash_Helpers`, or global in `utilities/helper-functions.php`.
