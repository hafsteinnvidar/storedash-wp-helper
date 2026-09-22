# StoreDash Helper — "Perfect → Production-ready → WordPress.org-ready" plan (2026-07-02)

Driven by a 7-agent parallel survey of the whole plugin (26.2k LOC). This is the spine of the
three-pass effort on branch `perfect-production-wporg`. Commits are one-per-category, **local only
(no push)** per the owner's instruction.

## Decision log (owner recommendations taken while owner was away — each is vetoable)

The owner stepped away during the interview. All decisions below are the **safe/recommended default**
and every change is a reversible local commit. Items marked ⚠️ **CONFIRM** are the ones where a
different product truth is genuinely possible — review these first.

1. **Target = WordPress.org directory submission** (also makes it installable on WP.com
   Business/Commerce, which pull from the .org directory). *(owner-confirmed)*
2. **Code-style bar = fully clean phpcs, 0 errors / 0 warnings.** phpcbf handles the mechanical bulk;
   residue done by hand; assets JS/CSS excluded from the PHP standard (WPCS 3.x dropped JS sniffs).
   *(owner-confirmed)*
3. **Verification = real wp-env WordPress + WooCommerce**, run the official Plugin Check to 0 errors,
   smoke-test the REST surface. *(owner-confirmed)*
4. **`Contributors:` stays `TODO_WPORG_USERNAME`** — the one manual pre-submission step. *(owner-confirmed)*
5. **Git = commit per category, no push.** *(owner-confirmed)*
6. ⚠️ **CONFIRM — Discounts apply at the real cart price.** product/category/tag/brand/store_wide
   rules currently only change the *displayed* price; nothing calls `set_price` for them, so customers
   are charged full price at checkout — yet README lines 3 & 144 and the delete-controller comment all
   say they "take effect once items reach the cart." Decision: **implement the missing
   `woocommerce_before_calculate_totals` application** for those 5 rule types (isolated, TDD'd,
   trivially revertable commit). If the real intent is display-only-for-headless, revert that one commit.
7. ⚠️ **CONFIRM — Gate all outbound sends on a completed connection.** Cart tracking is default-ON and
   posts shopper PII to a hardcoded `webhooks.storedash.io` URL even before the store is connected,
   contradicting the readme's "no data until you connect" / "unset = disabled" promises. Decision: gate
   cart/waitlist/enquiry/opt-in senders on `store_id` + webhook secret present, and make "unset =
   disabled" real. (Matches readme + WP.org expectations.)
8. ⚠️ **CONFIRM — Posturinn: disable auto-shipments only for StoreDash-managed orders.** Make the
   conditional per-order logic live, remove the global store-wide killer, surface the admin notice.
9. **Waitlist: plugin marks entries `notified` when the stock-available webhook send succeeds** (fixes
   re-fire on every restock, always-0 stats, and blocked re-joins).
10. **Delete all dead code** (Cart_Batch_Sync + its cron, ~490 lines of admin-unified.js wired to 10
    nonexistent AJAX actions + jquery-ui-sortable, the no-op inbound webhook receiver, uninstall cleanup
    for never-created "recommendations" tables, PSR-4/doc refs to a nonexistent `inc/Orders`, the readme
    IP-detection disclosure with no matching code, and the dead `woocommerce_api_auth` /
    `woocommerce_rest_api_valid_to_send` hooks).
11. **Slug = `storedash`** (matches Text Domain, .pot, zip top-level dir, and the internal contract). The
    owner requests it at submission time; everything here stays consistent with `storedash`.

## Findings inventory (from the survey — severity-ranked)

### CRITICAL
- **C1** Display-type discounts (product/category/tag/brand/store_wide) never reduce the real
  cart/checkout price — silent overcharge. → Decision 6.

### HIGH
- **H1** SSRF in `inc/api/media.php` upload-from-url — guard validates only the first hop; bypassable
  via HTTP redirects + DNS rebinding. Reachable by any `manage_woocommerce` user (incl. Shop Manager).
- **H2** Quantity discount bases off `get_regular_price()` and can raise price **above** an active
  merchant sale when `disable_on_sale=false` (overcharge). BOGO correctly uses `get_price()`.
- **H3** Unauthenticated `X-StoreDash-Source` header suppresses **all** outbound WC webhooks (not just
  StoreDash's) for that request — an unauthenticated kill switch for every integration's delivery.
- **H4** Phone-home PII before connection + false readme claims. → Decision 7.
- **H5** Posturinn contradiction / silent shipment loss. → Decision 8.
- **H6** `woodash_sync_carts_batch` cron never cleared on deactivate/uninstall; the feature it drives is
  dead (options never written). → Decision 10 (delete).
- **H7** Waitlist entries never reach `notified`. → Decision 9.
- **H8** uninstall clean-up incomplete: wrong option names, enquiry PII table never dropped, live options
  + `_woodash_*` usermeta left behind, cron missed.
- **H9** Cart-tracking admin toggle silently discarded on connected stores while UI says "saved".
- **H10** ~490 lines dead admin-unified.js → 10 nonexistent AJAX actions + needless jquery-ui-sortable.
  → Decision 10.
- **H11** Stale `languages/storedash.pot` (predates rename/branding; references 9 deleted files).
- **H12** Repo fails its own phpcs gate (3,319 errors). → Decision 2.
- **H13** readme placeholders + stale `Tested up to`/`WC tested up to`.
- **H14** Live-chat widget printed as a raw external `<script>` tag (Plugin Check ERROR) + loads on
  every storefront page even when chat is disabled; reads only `storedash_store_id` (dead on legacy
  installs).
- **H15** Debug webhook log writes customer PII to a web-readable file in `wp-content/` root; also
  breaks on managed hosts (WP.com/VIP). → route through WC_Logger.

### MEDIUM (selected — full list in the survey output)
uninstall orphans; composer PSR-4 casing wrong (breaks on case-sensitive FS) + lock drift; auth handler
ignores API-key read/write scope; headless-checkout handoff replayable 1h/no single-use; brand routes
double-registered; `product_brand` registered with no cap map, pre-empting WC native Brands; discount
sync writes raw body with no column whitelist; cart_retention_days purge never implemented (PII kept
forever) + no GDPR exporter/eraser; emptied-cart sync corrupts row; recovery tokens never expire;
critical cart webhooks block payment hooks up to 10s + no retry; `dbDelta` fed `IF NOT EXISTS` (schema
diffs never apply); webhook-payload-modifier leaks admin order notes to every destination;
`opcache_reset()` on every activation; admin JS mostly untranslated; full signing secret embedded in
inline `onclick`; heavy diagnostics run on every admin page load; widget nonces break under full-page
caching; dead ~130-line block in Abstract_Taxonomies_Controller.

## Execution phases (matches TaskList #1–#4)

- **Pass 1a — correctness & security** (Task #1): C1, H1–H9, H14, H15 + the money/PII/security mediums.
  TDD where logic changes; one commit per category.
- **Pass 1b — cleanup / uninstall / i18n / phpcs 0/0** (Task #2): Decision 10 deletions; uninstall
  completeness; composer PSR-4 casing + lock; regenerate `.pot`; phpcs to 0/0 (exclude assets from the
  PHP standard, phpcbf, justified inline ignores with reasons, docblock residue).
- **Pass 2 — production verification** (Task #3): wp-env WP+WooCommerce; activate; official Plugin Check
  → 0 errors; REST smoke tests; zero PHP notices.
- **Pass 3 — WP.org finalization + /ship** (Task #4): readme version bumps + disclosure reconciled to
  code; header consistency; build-zip hygiene proof; CI workflow; `/ship` the whole change set.

## Safety model
- One category per commit (reviewable, revertable); `composer test` + `php -l` after each.
- The two behavior-defining ⚠️ items (C1 discounts, H4 PII gating) land as isolated, clearly-labelled
  commits so a different product decision costs a single `git revert`.
- Do **not** rename the `woodash_*` stored contract (table/options/meta/hook/signature header).
- No push; all work stays on `perfect-production-wporg`.
