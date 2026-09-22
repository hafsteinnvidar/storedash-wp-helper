# Discounts Module (v3.2.0)

Dynamic pricing engine for StoreDash/WooCommerce. All discounts are applied at runtime via WooCommerce hooks — no `sale_price` or `regular_price` is ever written. Supabase is the source of truth; this plugin is the execution cache.

## File Structure

```
inc/Discounts/
├── Discounts_Manager.php               # Module entry point, hooks registration
├── Route_Registry.php                  # REST API route registration (storedash/v1)
├── Abstract_Discounts_Controller.php   # Base controller with shared logic
├── Controllers/
│   ├── Discounts_Sync_Controller.php   # POST /discounts/sync
│   ├── Discounts_Delete_Controller.php # DELETE /discounts/{id}/delete
│   ├── Discounts_Status_Controller.php # GET /discounts/status
│   └── Discounts_Toggle_Controller.php # POST /discounts/{id}/toggle
├── Engine/
│   ├── Discount_Resolver.php           # Sole decision-maker (rulebook: eligibility → ceiling → winner → price math)
│   ├── Cart_Discount_Orchestrator.php  # Single cart hook — applies one winner per line via the resolver
│   ├── Discount_Matcher.php            # Structural product-to-discount matching (targets/taxonomies/exclusions)
│   ├── Dynamic_Price_Display.php       # Frontend price HTML — price math delegated to the resolver
│   ├── Bogo_Discount_Rule.php          # BOGO free-item mechanics (triggered by the orchestrator)
│   ├── Quantity_Discount_Rule.php      # Tier presentation + tier math helpers
│   ├── Trait_Sale_Check.php            # Shared merchant sale detection
│   └── Discount_Priority_Resolver.php  # Cross-engine priority ceiling (used by the resolver)
├── Sync/
│   ├── Discount_DB_Handler.php         # WordPress database CRUD
│   └── Discount_Sync_Manager.php       # Supabase → WordPress sync
└── Blocks/
    └── Store_API_Integration.php       # WooCommerce Blocks compatibility
```

## REST API Endpoints

All endpoints require `manage_woocommerce` capability. Namespace: `storedash/v1`.

### POST /discounts/sync

Sync a discount from Supabase to WordPress. Creates or updates the local `wp_storedash_discounts` row.

**Request:**
```json
{
  "supabase_id": "uuid",
  "store_id": 123,
  "name": "Summer Sale",
  "rule_type": "category",
  "discount_type": "percentage",
  "amount": 20,
  "priority": 10,
  "enabled": true,
  "target_ids": "101,102,103",
  "exclude_ids": "",
  "exclude_category_ids": "",
  "exclude_tag_ids": "",
  "exclude_brand_ids": "",
  "conditions": { "min_quantity": 2 },
  "start_date": "2026-06-01T00:00:00Z",
  "end_date": "2026-06-30T23:59:59Z",
  "disable_on_sale": false,
  "disable_lower_priority": false,
  "disable_with_coupons": false,
  "apply_to_sale_price": false,
  "rule_config": null
}
```

**Response:**
```json
{
  "success": true,
  "wp_rule_id": 5,
  "message": "Discount synced successfully"
}
```

### POST /discounts/{id}/toggle

Toggle enabled/disabled state of an existing discount.

**Request:** `{ "enabled": true }`

**Response:** `{ "success": true, "message": "Discount enabled" }`

### DELETE /discounts/{id}/delete

Delete a discount from the WordPress database.

**Response:** `{ "success": true, "message": "Discount deleted" }`

### GET /discounts/status

Returns aggregate discount statistics.

**Response:**
```json
{
  "success": true,
  "stats": {
    "total": 10,
    "active": 5,
    "by_type": { "product": 3, "category": 2 },
    "products_with_discounts": 120
  }
}
```

## Pricing Engines

All discount decisions run through **one** place. `Discount_Resolver` applies the full rulebook (eligibility → suppression ceiling → winner selection → price-basis math with hard invariants → rounding) and returns the single winning discount for a product/line. `Cart_Discount_Orchestrator` is the **only** primary cart hook (`woocommerce_before_calculate_totals`, priority 102): per cart line it asks the resolver for the winner and applies exactly one — recomputing from the pristine base price on every firing. The other engine classes are presentation surfaces or apply-mechanics; none of them decide winners.

### Discount_Resolver

The sole decision-maker. `resolve( $product, $qty, $context )` returns the winning discount (or `null`) after eligibility, the cross-engine suppression ceiling, and tie-break (lowest priority number → biggest customer saving → lowest `id`). Price basis is per-rule via `apply_to_sale_price`. Both `Dynamic_Price_Display` and `Cart_Discount_Orchestrator` call it so catalog display and cart charge stay consistent.

### Dynamic_Price_Display

Hooks into `woocommerce_get_price_html` and related filters to show strikethrough prices on product pages and archives. Skipped for REST API and cart/checkout contexts (`should_process()`). All price math is delegated to `Discount_Resolver` (display context), so the struck-through catalog price matches what the cart charges.

### Cart_Discount_Orchestrator

The single primary cart hook. For each cart line it resolves the one winner and applies it: price/quantity winners set the line price, BOGO winners drive free-item add/adjust/remove (delegated to `Bogo_Discount_Rule`). Idempotent — always recomputes from the pristine base price, never from an already-discounted price. (This supersedes the interim `Display_Discount_Cart_Rule` engine, which was removed when the resolver architecture landed.)

### Bogo_Discount_Rule

Free-item **mechanics** only (no longer a self-contained matching engine). Triggered by the orchestrator, it manages the free/discounted line: sets its price from the product's regular/sale price × `(1 − discount_percent/100)` (0 for a 100% BOGO), hides its quantity/remove controls, syncs its quantity with the parent, removes it with the parent, and adds the "FREE"/"N% OFF" label. The price is derived from the base price (not `get_price()`) so it is **idempotent** across the multiple `before_calculate_totals` firings — a partial (<100%) BOGO no longer compounds each firing (fixed 2026-07-03).

### Quantity_Discount_Rule

Tier **presentation** plus tier-math helpers. Registers the cart-line price display, the product-page tier table, and the tier-pricing stylesheet. The actual per-line tier application is performed by the orchestrator via the resolver.

### Shared Traits

- **Trait_Sale_Check** — Detects merchant-set sales purely by presence of a product sale price (`get_sale_price()`); a product on sale is treated as merchant-discounted. Supports the `disable_on_sale` flag. (The former `wp_storedash_discount_products` tracking-table check was dead code — the table never had writers — and has been removed.)

> There is no shared conditions trait (`Trait_Discount_Conditions.php` does not
> exist). Cart-context gates are enforced in exactly one place —
> `Discount_Resolver::cart_conditions_met()`. `Discount_Matcher` is now purely
> **structural** (targets / taxonomies / exclusions / `disable_on_sale`); its
> former `check_conditions()` was **removed** (2026-07-03) because WooCommerce
> resets cart totals before `woocommerce_before_calculate_totals`, so a cart-total
> read there always saw 0. Cart-quantity eligibility for the priority ceiling is
> checked separately by `Discount_Priority_Resolver::cart_quantity_condition_met()`
> (cart-presence-guarded, so display-time behaviour is unchanged). Role-based
> gating (user roles / user exclusions) is **NOT supported** — no
> `user_roles`/`exclude_user_ids` columns exist on `storedash_discounts`, and the
> dead parse paths that read them have been removed (audit M4).

### Discount_Priority_Resolver

Shared helper (`Engine/Discount_Priority_Resolver.php`) that computes the cross-engine **priority ceiling** for the `disable_lower_priority` option, so the display engine and both cart engines honour the same suppression rule. See "Cross-Engine Priority Suppression" below.

## Cart Conditions (`conditions` JSONB)

The `conditions` column holds a JSON object gating when a discount applies. In cart context the resolver (`Discount_Resolver::cart_conditions_met()`) enforces all four:

| Key | Meaning | Source |
|-----|---------|--------|
| `min_cart_total` / `max_cart_total` | Whole-cart **pre-discount subtotal** must be ≥ / ≤ this value. | Derived from cart contents by `Discount_Resolver::cart_base_subtotal()` |
| `min_quantity` / `max_quantity` | **Total number of PAID items in the cart** (sum of every line item's quantity, BOGO free-item lines excluded) must be ≥ / ≤ this value. | Derived from cart contents by `Discount_Resolver::cart_paid_quantity()` (not `get_cart_contents_count()`, which would count BOGO free lines) |

`min_quantity`/`max_quantity` mean the **total paid item count in the whole cart**, parallel to `min_cart_total` (whole-cart subtotal) — *not* the quantity of a single qualifying product. Each gate is presence-gated (`! empty()`), so a discount that omits a condition behaves exactly as if the gate were absent.

**Why the subtotal is derived, not `get_subtotal()`:** `WC_Cart::calculate_totals()` resets totals *before* firing `woocommerce_before_calculate_totals`, so `get_subtotal()` reads 0 inside the hook where the resolver runs — the gate could never pass. `cart_base_subtotal()` therefore sums each paid line at its shopper-facing base price (sale price when set, else regular; BOGO free lines excluded). (Fixed 2026-07-03 — the gate was previously inert.)

**Display limitation:** these are **cart-context** gates. The display engine (`Discount_Matcher` → `Dynamic_Price_Display`, Store API product schema) runs at price-display time where there is no cart, so cart-quantity (and cart-total) conditions are **not** enforced for display rule types (`product`, `category`, `tag`, `brand`, `store_wide`) on product pages. They take effect once items reach the cart.

## Cross-Engine Priority Suppression (`disable_lower_priority`)

When a discount sets `disable_lower_priority = true` and it **applies to a product**, it raises a **priority ceiling** for that product: no discount whose `priority` number is **strictly greater** (i.e. lower priority — remember lower number = higher priority, applied first) may apply to that product, in **either** the display engine or the cart engines.

Semantics (implemented by `Engine/Discount_Priority_Resolver`):

1. For a given product, consider every **active** discount (any rule type, display *or* cart) that **structurally** matches the product (`Discount_Matcher::discount_applies_to_product` — exclusions, `disable_on_sale`, and target/taxonomy match; it resolves `bogo`/`quantity` by product `target_ids` too — empty list = store-wide) AND, at cart-calc time, whose cart-quantity condition is met (`cart_quantity_condition_met()`).
2. If at least one of those has `disable_lower_priority = true`, let `P` = the smallest `priority` number among them.
3. Any discount with `priority > P` is suppressed for that product. Discounts with `priority` equal to `P` (a tie) or smaller are unaffected — **only strictly greater priority numbers are suppressed**.
4. If no matching flagged discount exists, the ceiling is `PHP_INT_MAX` → nothing is suppressed and behaviour is byte-for-byte identical to a build without this option.

The ceiling is computed once per product (request-cached) from the full active-discount set (`Discount_DB_Handler::get_active_discounts()`). Because `Discount_Resolver` is the single decision-maker for both the display surfaces (`Dynamic_Price_Display`) and the cart (`Cart_Discount_Orchestrator`), the same ceiling is honoured everywhere — true cross-engine suppression rather than per-engine first-match-wins. A flagged cart discount (e.g. a priority-1 BOGO) can therefore suppress a lower-priority display discount on the same product, and vice-versa.

**Eligibility caveat:** the applies-to-product test uses the matcher's structural check (exclusions + `disable_on_sale` + target/taxonomy match) plus the cart-quantity gate; cart-total and `disable_with_coupons` are **not** part of ceiling eligibility, so a flagged discount that would be skipped only because of a cart-total gate or an applied coupon still imposes its ceiling.

## Database Tables

### wp_storedash_discounts

Main discount storage. Key columns: `id` (wp_rule_id), `supabase_id`, `rule_type`, `discount_type`, `amount`, `priority`, `enabled`, `target_ids`, `exclude_ids`, `exclude_category_ids`, `exclude_tag_ids`, `exclude_brand_ids`, `conditions`, `rule_config`, `apply_to_sale_price`. Exclusions are typed: `exclude_ids` holds product/variation post IDs; the three `exclude_*_ids` columns hold term IDs for `product_cat`/`product_tag`/`product_brand`, and excluding a parent category also excludes its descendants (same expansion as targeting).

The former `wp_storedash_discount_products` tracking table was never written to and has been dropped (the `upgrade_discount_tables_to_1_4()` upgrade path removes it on existing installs).

## Sync Flow

1. Dashboard server action writes discount to Supabase
2. Server action calls `POST /discounts/sync` with mapped WooCommerce IDs
3. `Discount_Sync_Manager` upserts into `wp_storedash_discounts`
4. Action Scheduler hooks handle scheduled activation/deactivation
5. Engines read from `wp_storedash_discounts` at runtime

## Scheduling

Uses WooCommerce Action Scheduler:
- `storedash_activate_discount` — fires at `start_date`
- `storedash_deactivate_discount` — fires at `end_date`

## Safe Failure Mode

If the plugin is deactivated, all WooCommerce hooks are removed and pricing returns to normal. No `sale_price` data is written, so deactivation is clean.
