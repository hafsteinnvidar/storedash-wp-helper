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
