---
name: helper-review
description: Pre-release review of the StoreDash helper WordPress/WooCommerce plugin against its own conventions and current WC/WP best practices — HPOS-safety, order-data CRUD access, the include_files() load contract, REST controller + auth security, the PHP 7.4 / WC 6.0 compat floor, and the woodash_* backward-compat contract. Use when the user says "helper-review", "review the plugin", "wp-plugin review", "is this WooCommerce plugin correct", "pre-release check", "review this controller/module", or before shipping a plugin change. For an exhaustive per-file module deep-dive use helper-xray instead.
---

# Helper Review: StoreDash Plugin Standards Review

A checklist-driven review of the StoreDash helper plugin (`storedash.php` + `inc/`) against the conventions in `CLAUDE.md` and current WooCommerce/WordPress best practices. Lighter than `helper-xray` — use it on a diff, a single controller/service, or the whole plugin before a release.

**Report only what you can prove.** Zero false positives beats maximum findings. If a rule looks violated, open the file and confirm before flagging.

## Before you start (load real context, not memory)

1. **Read `CLAUDE.md`** in this repo — the boot order, the dual PSR-4/procedural loader, the `woodash_*` stored contract, the auth model, and the `storedash/v1`-only namespace are all documented there. Never re-flag an intentional pattern.
2. **Pull live WC/WP facts via context7** — do NOT assert version numbers, HPOS defaults, or API shapes from training data. Resolve `woocommerce` / `wordpress` and query `developer.woocommerce.com` for anything version-sensitive. The plugin's own floor is authoritative for *minimums*: `Requires PHP: 7.4`, `WC requires at least: 6.0.0` (read the header of `storedash.php` — do not raise these casually).
3. **Know your resources** — for anything touching a consumer or downstream data, use the sibling repos + live DB + live docs described in [references/environment.md](references/environment.md): the **woo-dash** main app (`/Users/pineapple/Documents/GitHub/woo-dash`) and **storedash-sync** consume these endpoints; the **live Supabase DB** (`mcp__supabase-woo-dash__query`, read-only) shows where the plugin's data lands after sync; **context7** confirms WC/WP APIs. Each is optional — mark a claim "unverified" if the resource is absent rather than guessing.
4. **Scope**: if given a diff/PR, review only changed files + their direct callers. If given a module or "the plugin", review that surface. State the scope in the output.

## Review dimensions

Run every applicable dimension. Each links a reference file with the concrete checks and known-good patterns.

1. **HPOS-safety** — order data must go through WC CRUD (`wc_get_order`, `WC_Order_Query`, `$order->get_*/update_meta_data`), never `get_post_meta`/`WP_Query`/`$wpdb->posts` against `shop_order`. Confirm the `custom_order_tables` + `cart_checkout_blocks` declarations in `storedash.php` are intact. See [references/hpos.md](references/hpos.md).
2. **Load contract** — any new non-PSR-4 file (`class-*.php`, `api/*.php`, `services/**`) MUST be `require_once`'d in `StoreDash_Bootstrap::include_files()`, or it silently never loads. PSR-4 modules (`inc/Products|Carts|Discounts|Orders/`) must match class-name-to-filename. See [references/rest-controllers.md](references/rest-controllers.md).
3. **REST + auth security** — routes register under `storedash/v1`; privileged routes gate on the consumer-key/`manage_woocommerce` permission callback (`class-storedash-auth-handler.php` / `class-base-permissions.php`), NOT on a request header; webhooks verify constant-time HMAC. Only `/ping` and `/recover-cart` may be `__return_true`. Every file guards `ABSPATH`. Sanitize in, escape out, nonces on admin/AJAX, `$wpdb->prepare` on SQL. See [references/security.md](references/security.md).
4. **Compat floor** — no PHP 8.0+ only syntax (enums, `readonly`, named-arg-only APIs, `str_contains` without polyfill) unless guarded; the plugin targets **7.4**. Keep `WC tested up to` / `Requires at least` honest — flag if the code uses APIs newer than the declared floor.
5. **Backward-compat contract** — never rename the `woodash_*` *stored* surface (`woodash_carts` table, `woodash_*` options, `_woodash_*` meta, `woodash_webhook` hook, `X-WooDash-Signature`). New code uses `storedash_*`; `storedash_get_cart_table_name()` auto-detects. See CLAUDE.md "Backward compatibility".

## Verify, then report

- Before flagging a "direct order access" hit, confirm the post type is actually an **order** — `get_post_meta` against products/terms is fine. (The plugin has legitimate non-order uses.)
- Before flagging "missing require_once", grep `include_files()` — it may already be there or be a PSR-4 module.
- Run `composer phpcs` and `composer test` and fold real failures into the report; don't hand-audit what the linter already proves.

## Output

Present inline; save to `docs/reviews/YYYY-MM-DD-helper-review.md` if the user wants it persisted. Group by dimension, severity-ranked (Blocker / Should-fix / Nice-to-have), each finding with `file:line`, what's wrong, why it matters, and the fix. See [OUTPUT-TEMPLATES.md](OUTPUT-TEMPLATES.md). End with the **compat line**: PHP/WC floor respected? HPOS declarations intact? — always state it explicitly.

## Do NOT

- Bake WC/WP version numbers into findings — cite context7/live docs instead.
- Raise the PHP/WC floor or add `strict_types` wholesale as a "fix" — 7.4 support is deliberate.
- Re-flag the dual loader, the `woodash_*` contract, or the two public routes — they're intentional (CLAUDE.md).
- Invent hypothetical bugs — show the failing path.
