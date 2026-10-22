# Products Module

Minimal products module that extends native WooCommerce REST API v3 endpoints with additional functionality.

## Current Structure

```
Products/
├── Products_Manager.php    # Hooks to extend WooCommerce functionality
└── README.md               # This file
```

## How It Works

Instead of custom REST controllers, this module uses WordPress hooks to extend native WooCommerce endpoints:

1. **Frontend calls native WooCommerce endpoints:**
   - `/wp-json/wc/v3/products` for create/read/update/delete
   - `/wp-json/wc/v3/products/{id}/variations/batch` for variations

2. **Products_Manager adds one behaviour via hooks:**
   - **Scheduled publish** — `woocommerce_rest_insert_product_object` maps a
     future-dated publish request to WP `future` status with the correct
     `post_date_gmt` (the WC REST controller alone does not schedule).

Brands are handled entirely by WooCommerce's native products controller (WC 9.6+ applies the
`brands` field itself) — no plugin hook needed.

## Benefits

- **No custom controllers** - Uses WooCommerce's battle-tested code
- **Automatic updates** - Compatible with future WooCommerce versions
- **Minimal code** - Single-purpose ~180-line manager
- **Better reliability** - Less custom code to maintain

## Usage

The system is automatically initialized when products are enabled:

```php
// In api.php
if ($enable_products) {
    if (class_exists('\StoreDash\Products\Products_Manager')) {
        $products_manager = new \StoreDash\Products\Products_Manager();
        $products_manager->init();
    }
}
```

## Testing

The module's own surface is scheduled publish:
- Future-dated create/update → `future` status with matching `post_date_gmt`
- Past/absent dates → untouched (native WC behaviour)

Everything else (CRUD, bulk, images, variations, stock) is native WooCommerce and
covered by WooCommerce itself.

## Storedash-owned routes

Two routes are not served by `wc/v3` (registered in `Route_Registry.php`):

- `POST /storedash/v1/products/bulk` — time-budgeted product / variation updates (`Controllers/Products_Bulk_Controller.php`).
- `POST /storedash/v1/products/menu-order` — shop-order positions, `{ items: [{ id, menu_order }] }` (max 500). Writes `posts.menu_order` directly, the way WooCommerce's own Sorting screen does: no product save, no `woocommerce_update_product`, no `post_modified` change. Cleans the post cache for changed rows and flushes the product-query transients once. Answers `{ done: [{ id, ok, code?, message? }], changed }` (`Controllers/Products_Menu_Order_Controller.php`).


## Variation swatches (`Swatches/`)

Bridges Storedash to the store's "Variation Swatches" plugin — Emran Ahmed's
(`woo-variation-swatches`, term meta `product_attribute_color` / `secondary_color` +
`is_dual_color` / `product_attribute_image` id) or CartFlows' (`variation-swatches-woo`,
term meta `cfvsw_color` / `cfvsw_image` **URL**). Wired from `Products_Manager::init()` via
`Swatches/Swatches_Manager.php`. Provider pick (`Provider_Resolver`): option
`storedash_swatch_provider` if that plugin is active, else Emran, else CartFlows. Detection
uses each plugin's version constant (`WOO_VARIATION_SWATCHES_PLUGIN_VERSION`, `CFVSW_VER`).

Normalized types: `select | color | image | button | radio` (CartFlows `label` ⇄ `button`,
no `radio` → 422).

- **Term field** `storedash_swatch` on `wc/v3/products/attributes/{id}/terms` (GET, PUT, POST,
  `terms/batch`). GET: `null` without provider, else
  `{ provider, color, color2, image_id, image_url, tooltip }`. Write: `{ color?, color2?, image_id?, image_src? }`,
  present key writes, `null`/`""` deletes. WooCommerce ignores field-callback errors, so
  `Swatch_Rest_Field::guard_request()` validates on `rest_request_before_callbacks` (409 no
  provider, 400 invalid; a batch is rejected whole) and resolves `image_src` → attachment id
  (existing attachment, previous sideload via `_source_url`, else `media_sideload_image`).
- **Routes** (`storedash/v1`, `manage_woocommerce`): `GET swatches/status`,
  `POST swatches/provider {provider|null}`, `POST swatches/attributes/{id}/type {type}`.
  Use the type route, not `PUT wc/v3/products/attributes/{id}` — WC validates `type` against
  `product_attributes_type_selector`, which CartFlows only fills on its admin screen.
- **Store API** product extension `storedash_swatches`:
  `{ "pa_x": { type, terms: { "<slug>": { color?, color2?, image_url? } } } }`, non-select
  taxonomy attributes only; `[]` when empty (Store API requires an array). Cached per request.
