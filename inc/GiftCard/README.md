# GiftCard module (Gift cards / Gjafabréf)

Code-keyed gift card balances. WordPress owns the money; Storedash mirrors it.
Contracts: woo-dash `docs/plans/2026-10-05-gift-cards-contracts.md` (frozen for P1, sections A–F).
**Cards never expire.** Gated in Storedash by the existing `store_credit` feature key.

## Shape

| File | Role |
|---|---|
| `Gift_Card_Manager.php` | Wires everything (constructed by `StoreDash_API`). |
| `Route_Registry.php` | `storedash/v1/gift-cards/*` routes (all `manage_woocommerce`, consumer-key auth). |
| `Settings.php` | `storedash_gift_card_settings` option: `enabled`, `issue_on_status`, `max_cards_per_order`. |
| `Code.php` | Code generate / normalise / format, `code_hash` (HMAC `wp_salt('auth')`), `code_enc` (secretbox or AES-256-GCM, key from `wp_salt('secure_auth')`). |
| `Card_Ledger.php` | `{prefix}storedash_gift_cards` + `{prefix}storedash_gift_card_ledger`. Locked, idempotent moves. |
| `Serializer.php` | Contract-C `GiftCard` / `LedgerRow`. |
| `Gift_Card_Webhook.php` | Signed `giftcard.*` events → `resolve_webhook_url('storedash_gift_card_webhook_url', …)`. |
| `Product/Gift_Card_Product.php` | `_storedash_gift_card` flag, virtual + tax-free enforcement, coupon exclusion. |
| `Product/Recipient_Fields.php` | Recipient email/name, sender, message, send date → cart item → `_storedash_gc_*` order item meta. |
| `Engine/Issuer.php` | Mint on paid status, one card per unit, `issue:{order_item_id}:{unit}`. |
| `Engine/Redemption.php` | Session apply/remove, fee per card (`woocommerce_cart_calculate_fees`:30), claimed in `Credit\Payment_Fee_Pass`. |
| `Engine/Fee_Allocator.php` | Pure cap math (unit tested). |
| `Engine/Rate_Limiter.php` | Failed applies per 10 min: 5 per session, 30 per IP (headless proxies share IPs; forward X-Forwarded-For). Filter `storedash_gift_card_rate_limits`. |
| `Engine/Reservation.php` | Spend on order processed (one locked transaction), re-sync on retry, release on cancelled/failed and on permanent delete of an unpaid order (not trash); re-sync when Recalculate shrinks a card fee line (`storedash_payment_fees_adjusted`). |
| `Engine/Refund_Handler.php` | Card part of a refund back to the cards; refunded gift card purchases reduce their cards; order marked Refunded / amount-only full refund reclaims the purchased cards' remaining face value; card-only quantity-subset refunds return their share. |
| `Checkout/Classic_Checkout.php` | Code box on `woocommerce_review_order_before_submit` + `wc_ajax_storedash_gift_card_{apply,remove}` + `assets/js/gift-card-checkout.js`. |
| `Checkout/Gift_Card_Only_Checkout.php` | No billing address for gift-card-only carts (classic, blocks, Store API). |
| `Checkout/Blocks_Checkout.php` | `assets/js/gift-card-blocks.js` (no build): DiscountsMeta slot fill → `extensionCartUpdate`. |
| `Blocks/Store_API_Integration.php` | `extensions.storedash_gift_card` on cart / checkout / cart-item / product; update callback namespace `storedash_gift_card`. |

## Invariants

- Raw code exists only at mint (in memory), in `code_enc` (encrypted), in the webhook `delivery` block and in emails. Never logged, never in order meta, never in REST responses.
- Card balance changes only inside a transaction that locks the card row (`SELECT … FOR UPDATE`) and writes its ledger row; `balance_after` on every row.
- Idempotency keys (UNIQUE `idem_key`): `issue:{order_item_id}:{unit}`, `issue:manual:{key}`, `spend:{order}:{card}:{n}`, `release:{order}:{card}:{n}`, `refund:{refund_id}:{card}`, `refund:order-{order}:{card}:{n}` (order marked Refunded; n = spend count; older rows without `:n` count as n = 1), `refund_purchase:{refund_id}:{card}`, `adjust:{key}`, `disable|enable:{card}:{n}`. Cards also UNIQUE on `(order_item_id, unit_index)`.
- What an order holds per card = spent − released − refunded (`Card_Ledger::summarize_order_rows`).
- Fee id `storedash_gift_card_{id}`, name "Gift card ····K9QZ" (is: "Gjafabréf ····K9QZ"); order fee line meta `_storedash_gift_card_id`; order meta `_storedash_gift_cards_applied` = `[{card_id, last4, amount}]`. Purchase line meta `_storedash_gc_card_ids`. **storedash-sync must preserve `_storedash_gift_card*` and `_storedash_gc_*`.**
- Gift card products: virtual, `tax_status=none` (forced on every save AND by getter filters). WooCommerce coupons DO apply (card value stays the pre-coupon line subtotal ÷ qty; a purchase refund takes back the refunded share of the FACE value). Excluded from Storedash automatic discounts (`Discount_Matcher`) and Rewards credit EARN (`Eligibility::excluded_from_earn`); Rewards credit may PAY for them. A card cannot be applied while the cart holds a gift card product.
- Gift-card-only carts (`Checkout/Gift_Card_Only_Checkout.php`): billing address_1/2, city, state, postcode optional + hidden (country locales + `woocommerce_billing_fields`, locale cache reset once the cart is known); name + email still required; Store API checkout without a country gets the store base country. Cart extension `gift_card_only` tells storefronts.

## Decisions

- Gift card lines count toward Storedash discount `min_cart_total` / `min_quantity` gates — owner decision 2026-10-21 (matches WC coupon `minimum_amount`).
- Admin "Recalculate" keeps card + credit fee lines untaxed and at their pre-clamp amounts (`Credit\Payment_Fee_Pass` order hooks; `tests/GiftCard/Order_Fee_TotalsTest.php`).
- Manual issue / adjust round to store price decimals (`wc_get_price_decimals()`), like every other money path — a finer fraction would be floored away at checkout and never be spendable.

## The totals trap (how the fee covers shipping)

`WC_Cart_Totals::calculate()` runs items → **shipping** → fees → totals (since WC 3.2), so shipping IS known inside
`woocommerce_cart_calculate_fees`, also on the first render. The real traps are in `get_fees_from_cart()`:

1. Negative fees get taxes split onto them whenever taxes are on, ignoring `taxable=false` → the order's VAT would drop.
2. Negative fees are clamped at items + shipping **ex tax** → with tax-inclusive prices a card could never cover the VAT part.

The shared `StoreDash\Credit\Payment_Fee_Pass` (also used by Rewards credit) hooks
`woocommerce_cart_totals_get_fees_from_cart_taxes` (runs per fee after the clamp, before the fee is stored): for a
claimed fee it returns no taxes and resets `$fee->total` to the claim's cap given what is still payable
(gross items + gross shipping + every earlier fee incl. its taxes). Each card claims `min(balance, payable)`.
`tests/GiftCard/Fee_CapTest.php` replays the WC loop.

## P1 limitations

- Positive fees added by other plugins AFTER priority 30 are not covered by the card (shopper pays them).
- Webhook delivery is fire-and-forget (non-blocking `wp_remote_post`, no retry queue). The `/gift-cards` feed endpoints currently have no reconciliation consumer (deferred), so a missed event stays missing from the mirror. Manual issue reports `dispatched` / `dispatch_error`.
- Refund amounts are treated as the cash part: the cards get `refund × card_paid ÷ cash_total` back (contract E). WooCommerce pre-fills the refund amount from the selected lines' full value, so a line refund typed at full value also returns the card share — refund the cash part only, or use Storedash.
- Rotating WordPress salts (`AUTH_SALT` / `SECURE_AUTH_SALT`) invalidates every code and resend; a fingerprint (`storedash_gift_card_key_fp`) logs an error when it happens.
- Block checkout field relies on `wc.blocksCheckout.ExperimentalDiscountsMeta` + `extensionCartUpdate`; needs a live test per WooCommerce version.
- `sent_at` is never set in WordPress (the worker sets it in Supabase).
