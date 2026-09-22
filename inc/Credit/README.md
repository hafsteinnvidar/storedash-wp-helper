# Credit module (Rewards credit)

Per-store customer credit ledger. WordPress owns the money; Storedash owns the rules.
Contracts: woo-dash `docs/plans/2026-09-11-store-credit-contracts.md` (frozen for P1).

## Shape

| File | Role |
|---|---|
| `Credit_Manager.php` | Wires everything (constructed by `StoreDash_API`). |
| `Route_Registry.php` | `storedash/v1/credit/*` routes. |
| `Settings.php` | `storedash_credit_settings` option + `storedash_credit_enabled_at`. |
| `Rules_Repository.php` | `{prefix}storedash_credit_rules` full-replace mirror (`supabase_id`). |
| `Ledger.php` | `{prefix}storedash_credit_ledger` — FIFO consume/release/reverse/expire, idempotent via `idem_key`. |
| `Money.php` | Rounding, decimal strings (REST/webhook), minor-unit strings (Store API), `LedgerRow` formatter. |
| `Credit_Webhook.php` | Signed `credit.*` events → `resolve_webhook_url('storedash_credit_webhook_url', …)`. |
| `Engine/Earn_Handler.php` | Earn on `woocommerce_order_status_{completed\|processing}` / `woocommerce_payment_complete`. |
| `Engine/Spend_Handler.php` | Negative fee (`woocommerce_cart_calculate_fees`:20), reserve on order processed, release on cancel/fail. |
| `Engine/Refund_Handler.php` | `woocommerce_order_refunded`: pro-rata claw-back + refund-as-credit. |
| `Engine/Expiry_Cron.php` | Daily `storedash_credit_expire`. |
| `Engine/Rule_Matcher.php`, `Earn_Calculator.php`, `Fee_Calculator.php`, `Eligibility.php` | Pure math / matching (unit tested). |
| `Checkout/Classic_Checkout.php` | Box on `woocommerce_review_order_before_submit` + `wc_ajax_storedash_credit_apply` + `assets/js/credit-checkout.js`. |
| `Checkout/Blocks_Checkout_Field.php` | Additional Checkout Field `storedash/use-credit` (location `order`). |
| `Blocks/Store_API_Integration.php` | `extensions.storedash_credit` on cart + checkout; update callback namespace `storedash_credit`. |
| `Controllers/*` | sync / balance / ledger / grant+adjust / me. |

## Invariants

- Balance = `SUM(remaining)` of unexpired credit-holding rows (`remaining IS NOT NULL`). Never depends on cron.
- Credit-holding rows: `earn`, `manual`, `migrate`, and **positive** `reverse` rows (refund returned as credit). Everything else has `remaining = NULL` and records what it touched in JSON `meta.allocated` (`{earn_id: amount}`).
- Release of a cancelled/failed order writes a positive `reverse` row (`release:{order_id}`) and restores `remaining` on exactly the rows the spend took from; rows that expired meanwhile are not restored.
- Failed → paid again: `spend:{order_id}:{n}` / `release:{order_id}:{n}` for the n-th attempt.
- `Checkout/Account_Prompt`: while credit is on, forces WC "create account at checkout" ON and "generate password" OFF (shopper picks a password; classic/blocks field, Store API `create_account` + `customer_password`), pre-ticks the classic checkbox, prints a hint. Guests earn but only accounts can spend — this is how they get one.
- Customer key = `lower(trim(email))` — account email (`user_email`) for registered customers, billing email for guests; the checkout billing field is never trusted for a logged-in shopper. Guests earn by email; spending requires login (classic/blocks cookie or `X-StoreDash-Customer` on the Store API).
- Fee id `storedash_credit` (visible name translated); the order fee line carries meta `_storedash_credit_fee=1`.
- Order meta: `_storedash_credit_earned`, `_storedash_credit_earned_ledger_id`, `_storedash_credit_applied`, `_storedash_credit_spend_ledger_id`, `_storedash_credit_released`. **storedash-sync must preserve `_storedash_credit_*`.**

## P1 limitations

- Block checkout: the `order`-location field value only arrives with the checkout request, so the fee is applied at submit (session flag set in `woocommerce_store_api_checkout_update_order_from_request`, fee lines re-synced from the cart). No live preview and no partial amount in blocks; headless storefronts get both via the update callback.
- `spend_covers_shipping` reads the chosen rates from the last calculated packages (fees are computed before shipping in `WC_Cart_Totals`), so it is empty on the very first cart render.
- "Sale item" = merchant `sale_price` at earn/spend time (same rule as `Discount_Matcher`).
- Cancelling an order that already earned does not claw the credit back (only refunds do).
