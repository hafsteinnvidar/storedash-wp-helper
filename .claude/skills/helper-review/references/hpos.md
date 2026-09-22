# HPOS-Safety (High-Performance Order Storage)

WooCommerce stores orders in custom tables (HPOS / `custom_order_tables`), not `wp_posts`, when enabled. Code that reaches orders through post APIs breaks silently on HPOS stores. This plugin **declares HPOS compatibility** (`storedash.php` → `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__)` on `before_woocommerce_init`) — that promise must hold.

## The rule

Access order data ONLY through WooCommerce CRUD:

| Need | Use (HPOS-safe) | Never (post-based) |
|------|------------------|--------------------|
| Load an order | `wc_get_order( $id )` | `get_post( $id )`, `new WP_Post` |
| Query orders | `wc_get_orders()`, `WC_Order_Query` | `WP_Query(['post_type'=>'shop_order'])`, `get_posts` |
| Read order meta | `$order->get_meta( $key )`, `$order->get_*()` | `get_post_meta( $order_id, ... )` |
| Write order meta | `$order->update_meta_data(); $order->save()` | `update_post_meta( $order_id, ... )` |
| Raw SQL on orders | order query APIs / `wc_get_orders` | `$wpdb->posts`, `$wpdb->postmeta` filtered to orders |
| Order status | `$order->get_status()` / `$order->update_status()` | reading `post_status` |

## How to audit

```bash
# Candidate HPOS-unsafe accessors
grep -rn "get_post_meta\|update_post_meta\|WP_Query\|get_posts\|\$wpdb->posts\|\$wpdb->postmeta" inc
```

For each hit, determine the post type in play:
- **Against orders** (`shop_order`, `shop_order_refund`, or an `$order_id`) → **flag it**, propose the CRUD equivalent.
- **Against products, terms, attachments, custom tables** → fine, not an HPOS issue. Do not flag.

Known legitimate non-order uses in this plugin include product/term/media meta (e.g. `class-stock-monitor.php`, `media.php`, custom-tabs, taxonomies). Confirm the type before flagging — most `get_post_meta` here is NOT order data.

## Declarations must stay intact

```bash
grep -n "declare_compatibility" storedash.php
# expect: custom_order_tables AND cart_checkout_blocks, both on before_woocommerce_init
```

If a PR removes or conditionally skips either declaration, that's a **Blocker** — WooCommerce will flag the plugin as incompatible and block HPOS on the merchant's store.

## Custom order statuses

`inc/services/class-custom-order-statuses.php` registers slugs on `init` priority 0 (early, so WC REST/list queries recognize them). Custom statuses must be registered for both the legacy and HPOS query paths — verify new statuses flow through the WC status APIs, not `post_status` string matching.

## Live-facts caveat

Whether HPOS is *default* on, the current WC version, and any newly-deprecated order API belong to **context7 / developer.woocommerce.com**, not this file or training data. This file is the *pattern*; versions are looked up live.
