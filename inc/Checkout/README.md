# Checkout Module

## Overview

Checkout optimization: per-store, default-OFF features that Storedash switches on
by pushing config to this plugin. This task ships the shared config + settings
route and the **checkout funnel counter** (count-only, no output). The
free-shipping bar and delivery estimate components are follow-ups and plug into
`Checkout_Manager::init()` at the marked spots.

Safety contract for every component: when its feature is off the constructor
returns before registering any hook; nothing here touches order creation,
payment, totals or shipping cost.

## Directory Structure

```
Checkout/
├── Checkout_Manager.php                 # Routes + components (front-end/AJAX/cron only)
├── Config.php                           # Option storedash_checkout_config (defaults, sanitize, hash)
├── Route_Registry.php                   # REST route registration
├── Controllers/
│   └── Checkout_Settings_Controller.php # GET/POST /checkout/settings
├── Permissions/
│   └── Checkout_Permissions.php         # manage_woocommerce (Base_Permissions)
├── Funnel/
│   ├── Funnel_Counter.php               # Pure: per-session/day counts in a transient
│   ├── Funnel_Tracker.php               # WP glue: template_redirect + order-processed hooks
│   └── Funnel_Flusher.php               # Hourly cron → signed cart.funnel_batch POST
└── FreeShipping/
    ├── Threshold_Calculator.php         # Pure: WC free-shipping arithmetic + {amount} copy
    └── Free_Shipping_Bar.php            # Classic hooks + fragments; blocks via assets/js/checkout-bar.js
```

## API Endpoints

- `GET /storedash/v1/checkout/settings` → `{ config, config_hash, checkout_type, plugin_version }`
- `POST /storedash/v1/checkout/settings` (partial config) → `{ success, config, config_hash, checkout_type, plugin_version }`; `400` on a non-object body.

`checkout_type` is `classic | blocks | unknown`.

## Funnel

- Steps: `cart_view`, `checkout_view`, `order_placed` (+ reserved `bump_view`, `bump_accept`).
- One count per WC session per step per day (`storedash_funnel_seen` in the session). Bots (SEO
  `Bot_Crawl_Logger` token list) and sessionless requests are never counted.
- Transient `storedash_funnel_counts["Y-m-d|step|device|variant"]`, store-timezone date, ≤ 2,000 keys, 48 h.
- Cron `storedash_checkout_hourly_flush` (scheduled only while `funnel.enabled`), lock
  `storedash_funnel_flush_lock`, opportunistic `shutdown` flush after 2 h without one. POSTs
  `{ action: "cart.funnel_batch", store_id, store_url, timestamp, rows: [{ date, step, device, variant, sessions }] }`
  to the cart webhook URL (`woodash_cart_webhook_url`), header `X-WooDash-Signature` = hex HMAC-SHA256
  of the body with `woodash_webhook_secret`. Blocking, 2xx only, failed chunks merged back.
- Limitation: pure headless Store API shoppers only produce `order_placed`.
- Catch-up: a shopper request never POSTs. After a counted hit with no flush for 2 h, `shutdown`
  schedules a single cron event due now and calls `spawn_cron()`; with `DISABLE_WP_CRON` and no
  system cron the counts wait in the transient.

## Free-shipping bar

- On only when `free_shipping_bar.enabled` AND `threshold > 0`; surfaces from `show_on`.
- Arithmetic mirrors `WC_Shipping_Free_Shipping::is_available()` (`Threshold_Calculator`);
  `include_tax`: `woo` follows the cart tax display, `incl`/`excl` force.
- Classic: `woocommerce_before_cart` / `woocommerce_before_checkout_form` + fragment key `.storedash-fs-bar`.
  Blocks: `<template id="storedash-fs-bar-tpl">` in `wp_footer` + `checkout-bar.js` subscribing to `wc/store/cart`.
- Markup `.storedash-fs-bar[.is-unlocked][.is-hidden] > __text + __track > __fill`; tokens as
  `--sd-fs-bg/fg/accent/radius`. Copy `{amount}` via `str_replace`, never sprintf.
- Tests: `tests/Checkout/Threshold_CalculatorTest.php`.

## Testing

`tests/Checkout/` — `ConfigTest` (sanitize, partial update, hash) and `Funnel_CounterTest`
(dedupe, cap, allowlists, bot skip). Both run standalone (`composer test`).
