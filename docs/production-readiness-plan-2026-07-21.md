# Storedash Helper — Production Readiness & WP.org Submission Plan (2026-07-21)

**Plugin version at audit:** 1.6.1 · **Commit:** `6a4bb84` · **Working tree:** clean
**Scope:** all 75 PHP files in `inc/` + `storedash.php` + `uninstall.php` (~24.5k LOC). `vendor/` and `tests/` excluded.

> **Verdict: NOT safe to ship broadly. NOT submittable to WordPress.org today.**
> Five critical defects are live on merchant stores. The WP.org bar itself is only two small items —
> the reason not to submit is that submitting would push the critical defects to a much wider install base.

This document supersedes the open items in `perfect-production-wporg-plan-2026-07.md` and adds newly-found
defects. It does **not** supersede `security-audit-2026-07.md` (SEC-1..SEC-9), which is fully resolved —
see [Appendix A](#appendix-a--prior-audit-status).

---

## How an agent uses this document

Each item below is a **self-contained work unit**. Pick any unblocked item, follow it, satisfy its
acceptance criteria, move on. Do them in ID order (CRIT → HIGH → MED).

For every item:

1. Read **Why it matters** and **Where**.
2. **Run the Verify-before command first.** File:line references were captured 2026-07-21 against commit
   `6a4bb84` and may have drifted. If the command's output does not match **Current code**, STOP —
   re-locate the code before editing, and note the drift in the progress log.
3. Apply **Fix**.
4. Satisfy **Acceptance**.
5. Run **Verify-after**.
6. Tick the checkbox in the [master checklist](#master-checklist) and add a line to the
   [progress log](#progress-log) with the commit SHA.

### Global rules (apply to ALL items)

- **PHP 7.4 floor.** No PHP 8+ syntax. `PHPCompatibilityWP` currently reports **0 errors / 0 warnings**
  across 7.4–8.3 — do not regress that. (Note: several bugs below are *PHP 8 runtime* failures in
  7.4-valid syntax. Fixing them must not introduce 8+ syntax.)
- Keep every file's `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- **Do NOT rename the `woodash_*` stored contract** — table `woodash_carts`, `woodash_*` options,
  `_woodash_*` meta, `woodash_webhook` hook, `X-WooDash-Signature` header. See `CLAUDE.md`.
- **Brand casing:** `Storedash` (lowercase d) in ALL prose/user-facing text. NEVER change code
  identifiers — `StoreDash_` class prefix, `StoreDash\` namespace, `STOREDASH_*` constants, `storedash`
  text domain are contracts.
- **One item (or one tightly-related group) per commit** — reviewable, revertable.
- Work is not done until `php -l` is clean on every touched file **and** `composer test` passes.
- **Never change logic to satisfy a linter.**
- **No audit-ID comments in code.** Do not write `// CRIT-1` or `// fixes HIGH-6` into source files.
  The doc tracks the mapping; the code should read as if the bug was never there.

### Evidence legend

Findings carry a verification marker:

- **[V]** — the decisive code was read directly during the audit. High confidence.
- **[A]** — reported by a scanning agent, not independently confirmed. **Treat as a lead, not an
  established defect.** During the audit, 2 of 3 spot-checked `[A]` findings were materially narrower
  than described. Always run Verify-before on these and be prepared to close them as "not a defect".

---

## Master checklist

### Critical — fix before any further rollout

| ID | Done | Sev | Item | File | Effort |
|----|------|-----|------|------|--------|
| CRIT-1 | ☐ | Critical | Corrupt `conditions` JSON fails OPEN | `Discount_Resolver.php:250` | 20m |
| CRIT-2 | ☐ | Critical | Re-entrancy guard lacks `try/finally` | `Cart_Discount_Orchestrator.php:81` | 20m |
| CRIT-3 | ☐ | Critical | `null` from `get_results` is cached → shop-wide fatal | `Discount_DB_Handler.php:175` | 30m |
| CRIT-4 | ☐ | Critical | Blocking 10s HTTP inside checkout | `Cart_Tracking.php:1101` | 15m |
| CRIT-5 | ☐ | Critical | Posturinn auto-shipments killed store-wide | `class-posturinn-disable-auto.php:38` | 3h |
| CRIT-6 | ☐ | High→Crit | Basic Auth accepted over plaintext HTTP | `class-storedash-auth-handler.php:158` | 15m |

### High

| ID | Done | Item | File | Src | Effort |
|----|------|------|------|-----|--------|
| HIGH-6 | ☐ | N+1 term queries on every catalog page | `Discount_Matcher.php:271` | [V] | 15m |
| HIGH-7 | ☐ | Quadratic rule matching on shop pages | `Discount_Resolver.php:107` | [A] | 3h |
| HIGH-8 | ☐ | Blocking 10s HTTP per product on stock write | `class-stock-monitor.php:307` | [A] | 45m |
| HIGH-9 | ☐ | Media upload has no response size cap | `inc/api/media.php:389` | [V] | 1h |
| HIGH-10 | ☐ | PHP 8 `TypeError` can 500 the Blocks checkout | `Store_API_Integration.php:229` | [V] | 30m |
| HIGH-11 | ☐ | `$cart_item['data']` dereferenced without type guard | `Cart_Discount_Orchestrator.php:162` | [A] | 45m |
| HIGH-12 | ☐ | `DivisionByZeroError` on cart quantity change | `Bogo_Discount_Rule.php:255` | [A] | 30m |
| HIGH-13 | ☐ | Webhook payload modifier can fatal order processing | `webhook-payload-modifier.php:30` | [A] | 30m |
| HIGH-14 | ☐ | Private admin notes leak on unanchored host match | `webhook-payload-modifier.php:74` | [V] | 20m |
| HIGH-15 | ☐ | Webhook suppression not scoped to StoreDash URLs | `webhook-filter.php:126` | [V] | 1h |
| HIGH-16 | ☐ | Unchecked `$wpdb` writes → repeat notifications | `class-stock-monitor.php:406` | [A] | 1.5h |
| HIGH-17 | ☐ | Schema-upgrade thundering herd | `class-storedash-bootstrap.php:100` | [V] | 1h |
| HIGH-18 | ☐ | Silent carts-table creation failure | `class-storedash-activator.php:79` | [V] | 45m |
| HIGH-19 | ☐ | `include_files()` unguarded | `class-storedash-bootstrap.php:146` | [V] | 45m |
| HIGH-20 | ☐ | `SHOW TABLE STATUS` + 4× `COUNT(*)` on admin render | `class-storedash-admin.php:1859` | [V] | 1h |
| HIGH-21 | ☐ | Admin render force-fires `rest_api_init` | `class-storedash-admin.php:1499` | [A] | 45m |
| HIGH-22 | ☐ | Unbounded + N+1 YITH migration preview | `class-yith-migration.php:157` | [A] | 1.5h |

### WordPress.org submission

| ID | Done | Item | Effort |
|----|------|------|--------|
| WPORG-1 | ☐ | `Contributors:` placeholder in `readme.txt` | 5m |
| WPORG-2 | ☐ | Replace `phpcs.xml` severity-0 suppressions with inline justified ignores | 3h |
| WPORG-3 | ☐ | Run official Plugin Check in a real WP env to zero errors | 1h |
| WPORG-4 | ☐ | *(recommended)* Move chat widget off `*.ondigitalocean.app` | — |
| WPORG-5 | ☐ | *(optional)* CI gate: phpcs + plugin-check + tests on PR | 1h |

### Dead code cleanup

| ID | Done | Item | Effort |
|----|------|------|--------|
| DEAD-1 | ☐ | Delete no-op `StoreDash_Module_Loader` | 20m |
| DEAD-2 | ☐ | Delete 12 dead methods | 45m |
| DEAD-3 | ☐ | Delete dead `storedash_wp_get_system_info` AJAX handler | 15m |
| DEAD-4 | ☐ | Delete unreachable `woocommerce_api_auth` SSRF hooks | 20m |
| DEAD-5 | ☐ | Add `storedash_flush_cart_webhook` to cron cleanup | 15m |
| DEAD-6 | ☐ | Remove Action Scheduler cancels for never-scheduled hooks | 15m |
| DEAD-7 | ☐ | Fix stale file references in `docs/` and `inc/Products/README.md` | 20m |
| DEAD-8 | ☐ | *(optional)* Drop dead `vendor/autoload.php` require | 30m |

### Coverage gap — must close before sign-off

| ID | Done | Item | Effort |
|----|------|------|--------|
| GAP-1 | ☐ | Full read of the two ~1,800-line Elementor widget files | 3h |
| GAP-2 | ☐ | Full read of `inc/api/` (media, orders, coupons, emails, smart-coupons, dropp, headless-checkout, payment-gateways, shipment-tracking) | 4h |
| GAP-3 | ☐ | Full read of Posturinn + Dropp integration bodies | 2h |

---

# CRITICAL items

## CRIT-1 — Corrupt `conditions` JSON fails OPEN; silent revenue loss **[V]**

**Where.** `inc/Discounts/Engine/Discount_Resolver.php:249-253`. Mirrored at
`inc/Discounts/Engine/Discount_Priority_Resolver.php:191-194`.

**Current code.**
```php
$conditions = json_decode( $discount->conditions ?? '{}', true );
if ( ! is_array( $conditions ) ) {
    return true;   // treated as "all conditions met"
}
```

**Why it matters.** A rule meaning *"30% off carts over 50,000 ISK"* applies **unconditionally to every
cart** if that column is truncated, corrupt, or mid-migration. There is no log line and no admin notice —
the merchant discovers it in their revenue numbers. `json_last_error()` is consulted nowhere in the module.

This is the same failure *class* as C1 (silent mispricing) in a subsystem the prior audit signed off as clean.

**Verify-before.**
```bash
cd /Users/pineapple/Documents/GitHub/storedash-helper
grep -n "is_array( \$conditions )" -A2 inc/Discounts/Engine/Discount_Resolver.php \
  inc/Discounts/Engine/Discount_Priority_Resolver.php
# Expect: `return true;` inside the guard in both files.
```

**Fix.** Fail **closed**. A discount whose conditions cannot be parsed must not apply.
- Return `false` from the guard in both files.
- Log once via `StoreDash_Helpers` (WC_Logger) at `error` level including the discount ID and
  `json_last_error_msg()`. Do not log the raw `conditions` blob (may contain merchant data).
- Preserve the genuinely-empty case: `'{}'` / `''` / `null` decoding to an empty array means
  "no conditions" and must still apply. Only a **parse failure** fails closed.

**Acceptance.**
- [ ] Corrupt/unparseable `conditions` → discount does NOT apply, one error log line emitted.
- [ ] Empty or absent `conditions` → discount DOES apply (unchanged behaviour).
- [ ] Both files changed identically.
- [ ] Unit test added covering: valid JSON, empty string, `null`, truncated JSON, JSON scalar (`"5"`).

**Verify-after.** `php -l` on both files · `composer test` · new test passes.

---

## CRIT-2 — Re-entrancy guard has no `try/finally`; customer charged full price **[V]**

**Where.** `inc/Discounts/Engine/Cart_Discount_Orchestrator.php:81`…`:147`.
Same shape at `inc/Discounts/Engine/Dynamic_Price_Display.php:160`…`:176`.

**Current code.**
```php
$this->is_processing = true;
// ~60 lines: resolve() / set_price() / add_to_cart() — no try/catch
$this->is_processing = false;
```

**Why it matters.** Any throw between those two lines leaves the flag stuck `true` for the rest of the
request. **Every subsequent `calculate_totals()` silently skips all discounts — the customer is charged
full price at checkout, and nothing is logged.** This is a direct re-entry into C1's failure mode by a
different route.

**Verify-before.**
```bash
grep -n "is_processing" inc/Discounts/Engine/Cart_Discount_Orchestrator.php
grep -n "try\|finally" inc/Discounts/Engine/Cart_Discount_Orchestrator.php
# Expect: is_processing set true/false with no try/finally between them.
```

**Fix.** Wrap the guarded region in `try { … } finally { $this->is_processing = false; }`.
- Use `finally` (PHP 5.5+, safe at the 7.4 floor) — **not** a `catch` that swallows.
- Do not catch and suppress the exception unless you also log it; re-throwing after the `finally`
  is preferred so the real error stays visible.
- Apply the identical pattern to `Dynamic_Price_Display.php`.

> Related: `Cart_Tracking.php:1039-1043` (`without_cart_tracking()`) has the same missing-`finally` shape
> — see LOW-5. Fixing it in the same commit is reasonable.

**Acceptance.**
- [ ] Flag is reset on both the success and the throw path in both files.
- [ ] A thrown exception is not silently swallowed.
- [ ] Test: force a throw inside the guarded region, assert `is_processing` is `false` afterwards and
      that a subsequent `calculate_totals()` still applies discounts.

**Verify-after.** `php -l` · `composer test`.

---

## CRIT-3 — `null` from `get_results` is cached, then fatals every shop page **[V]**

**Where.** `inc/Discounts/Sync/Discount_DB_Handler.php:175-179`, identically `:228-232`.

**Current code.**
```php
$results = $wpdb->get_results( $query );
self::$active_discounts_cache[ $cache_key ] = $results;   // null cached on DB error
```

**Why it matters.** `$wpdb->last_error` is never checked. The `null` propagates to
`Discount_Matcher.php:88` (`usort(null)`) and `:111` (`count(null)`), throwing a PHP 8 `TypeError` from
inside a `woocommerce_get_price_html` filter → **white screen on every shop, category and product page**.
Because the `null` is cached, the failure persists for the request even after the DB recovers.

Triggers: deadlock, `max_connections` exhaustion, a mid-migration `ALTER TABLE`.

**Verify-before.**
```bash
grep -n "get_results" -A3 inc/Discounts/Sync/Discount_DB_Handler.php
grep -n "last_error" inc/Discounts/Sync/Discount_DB_Handler.php
# Expect: get_results assigned straight to cache; last_error absent (or absent at :175/:228).
```

**Fix.**
- After each `get_results`, check `$wpdb->last_error`. On error: log via WC_Logger, **do not populate
  the cache**, and return an empty array `array()` — never `null`.
- Normalise the return type: these methods should be documented and guaranteed to return `array`.
- Defensively harden the consumers at `Discount_Matcher.php:88` and `:111` to tolerate a non-array
  (belt and braces — the real fix is at the source).

**Acceptance.**
- [ ] DB error → empty array returned, cache NOT poisoned, one log line.
- [ ] Successful empty result set → empty array cached normally (still avoids a re-query).
- [ ] `Discount_Matcher` cannot receive `null`.
- [ ] Test simulating `last_error` set asserts no `TypeError` and no cache entry.

**Verify-after.** `php -l` · `composer test`.

---

## CRIT-4 — Blocking 10s HTTP inside the shopper's checkout request **[V]**

**Where.** `inc/services/cart/Cart_Tracking.php:1101-1113`, hooked at `:96-98`.

**Current code.**
```php
$is_critical = in_array( $action, array( 'converted', 'recovered' ), true );
$blocking    = $is_critical;
$response = wp_remote_post( $webhook_url, array( 'timeout' => 10, 'blocking' => $blocking, ... ) );
```

**Why it matters.** `mark_cart_as_converted` fires on `woocommerce_payment_complete` and
`woocommerce_order_status_processing` — **inside `WC_Checkout::process_checkout()`** for COD, bank
transfer, and any gateway that calls `payment_complete()` synchronously.

- Normal case: ~150-400ms of dead time on the single most conversion-sensitive request in the store.
- Receiver outage: the full 10s. With `max_execution_time=30` this is a **white-screened checkout on a
  card that was already charged.**
- There is no circuit breaker.

**The blocking send is redundant** — the cron backstop at `Cart_Tracking.php:1314` already guarantees
delivery. This is why the fix is safe.

**Verify-before.**
```bash
grep -n "is_critical\|'blocking'\|'timeout'" inc/services/cart/Cart_Tracking.php | head -20
grep -n "payment_complete\|order_status_processing" inc/services/cart/Cart_Tracking.php
# Confirm the cron backstop still exists:
sed -n '1300,1330p' inc/services/cart/Cart_Tracking.php
```

**Fix.** Set `'blocking' => false` unconditionally. Delete the now-unused `$is_critical` computation.

> **Do not skip the backstop check.** Before committing, confirm the cron sweep at `:1314` still
> re-sends unconfirmed `converted` events. If that sweep has been removed or altered, this fix loses
> its safety net and CRIT-4 must instead be solved by moving the send to a scheduled single event.
> Coordinate with MED-2 and MED-3 below, which affect that same sweep.

**Acceptance.**
- [ ] No `blocking => true` remains in `Cart_Tracking.php`.
- [ ] Cron backstop verified present and functional.
- [ ] Manual smoke: place a COD order on wp-env with the webhook URL pointed at a black-holed host;
      checkout completes at normal speed.

**Verify-after.** `php -l` · `composer test` · wp-env COD checkout smoke test.

---

## CRIT-5 — Posturinn auto-shipments killed store-wide **[V]**

**Where.** `inc/integrations/posturinn/class-posturinn-disable-auto.php:38` and `:67-112`.
Hooked on `init` / `plugins_loaded` / `woocommerce_loaded`.

**Why it matters.** `:38` removes Posturinn's status-change handler outright, and `:67-112` walks
`$wp_filter` stripping **every** callback matching `postis|posturinn` across 52 order hooks. Any store
with Posturinn active loses **all** automatic shipment creation — including for orders StoreDash never
touched (manual, phone, POS). Silent: no admin notice, no opt-out, no per-order scoping.

**Posturinn merchants are hard-blocked by this. It is the single largest merchant-facing defect.**

**Status.** This is `H5` from `perfect-production-wporg-plan-2026-07.md`. Decision 8 in that document
specified per-order scoping. **It was decided and never implemented.**

**Verify-before.**
```bash
sed -n '30,115p' inc/integrations/posturinn/class-posturinn-disable-auto.php
grep -rn "_woodash_managed\|admin_notice" inc/integrations/posturinn/
# Expect: unconditional removal, no per-order check, admin_notice() defined but never hooked.
```

**Fix.** Implement plan Decision 8 — scope the suppression to StoreDash-managed orders only.
- Replace the global `$wp_filter` walk with a **per-order** conditional: suppress Posturinn's automatic
  shipment creation only when the order carries the StoreDash-managed marker (e.g. `_woodash_managed`
  order meta — confirm the actual marker key in code before relying on it; if none exists, introduce one
  and set it wherever StoreDash creates/books an order).
- Delete the store-wide `remove_all_actions`-style killer at `:67-112`.
- Hook the existing-but-orphaned `admin_notice()` at `class-posturinn-integration.php:100` so merchants
  are told the integration is active and what it changes.
- Provide a filter to disable the behaviour entirely.

**This is the one item in this document that is a real feature change, not a patch.** It needs a
wp-env Posturinn smoke test, not just unit tests. If Posturinn cannot be installed in wp-env, ship it
behind a filter defaulting to the new scoped behaviour and validate on one consenting merchant store first.

**Acceptance.**
- [ ] An order StoreDash never touched → Posturinn auto-shipment fires normally.
- [ ] A StoreDash-managed order → Posturinn auto-shipment suppressed, StoreDash books it.
- [ ] Global `$wp_filter` walk deleted.
- [ ] Admin notice visible when the integration is active.
- [ ] Filter available to opt out.

**Verify-after.** `php -l` · `composer test` · wp-env Posturinn smoke test covering both order paths.

---

## CRIT-6 — Basic Auth accepted over plaintext HTTP **[V]**

**Where.** `inc/core/class-storedash-auth-handler.php:158-165`.

**Why it matters.** WooCommerce core gates Basic Auth on `is_ssl()`
(`class-wc-rest-authentication.php:98-100`). This handler does not. On a non-HTTPS store the plugin
**accepts a cleartext `consumer_key:consumer_secret` pair where stock WooCommerce would refuse it** —
the plugin is strictly weaker than the platform it extends.

**Status.** SEC-5 hardened the *query-string* fallback path (`:172`, gated on `is_ssl()` with deprecation
logging) and left the *primary* Basic Auth path ungated. This is a gap in the SEC-5 fix, not a regression.

**Verify-before.**
```bash
sed -n '150,190p' inc/core/class-storedash-auth-handler.php
grep -n "is_ssl" inc/core/class-storedash-auth-handler.php
# Expect: is_ssl() present around :172 (query-string path) but absent from the :158-165 Basic path.
```

**Fix.** Wrap the Basic Auth branch in an `is_ssl()` check, matching WC core's behaviour and the existing
treatment of the query-string path directly below it. Log the rejection at `notice` level so merchants on
HTTP get a diagnosable signal rather than a silent auth failure.

**Acceptance.**
- [ ] Basic Auth over HTTP is rejected; over HTTPS unchanged.
- [ ] Behaviour matches `WC_REST_Authentication` for the same condition.
- [ ] The rejection is logged.
- [ ] The constant-time `hash_equals` comparison at `:274` is untouched.

**Verify-after.** `php -l` · `composer test` · confirm authenticated REST still works over HTTPS in wp-env.

---

# HIGH items

> These are summarised rather than fully expanded. Each carries enough to locate and verify. Expand an
> item into the full CRIT format before working it if the fix is non-obvious.

## HIGH-6 — N+1 term queries on every catalog page **[V]**
`inc/Discounts/Engine/Discount_Matcher.php:271`. `wp_get_object_terms()` bypasses the object-term cache
that `WP_Query` already primed → one uncached query per product per taxonomy. A 24-product page with
category+tag+brand rules = **72 extra queries per page view**.
**Fix:** swap to `wc_get_product_term_ids()`. One line. Do this alongside CRIT-1/2/3 — same subsystem.

## HIGH-7 — Quadratic rule matching on shop pages **[A]**
`Discount_Resolver.php:107-116` matches every product against every rule **twice** (ceiling pass +
candidate loop). `Discount_Matcher.php:289-295` re-explodes the `target_ids` TEXT blob per
(product × rule) with no memoization. `in_array(..., true)` at `:134/160/173` is a linear scan.
24 products × 200 rules ≈ 9,600 matcher calls / 4,800 `json_decode`s / ~19,200 explodes per page.
**Likely the root of any "shop got slow after we added discounts" report.**
**Fix:** memoize the parsed `target_ids` per rule (per request); flip the linear scans to array-key
lookups (`isset( $map[ $id ] )`); collapse the double pass.

## HIGH-8 — Blocking 10s HTTP per product on every stock write **[A]**
`inc/services/waitlist/class-stock-monitor.php:307-316` (`'blocking' => true`), hooked `:48-58`.
Not the shopper path, but it is order cancel/refund restock, admin bulk edit, CSV import, ERP/DK sync,
and storedash-sync's own REST writes. N products = N × up to 10s **serially**; a 50-SKU batch against a
degraded endpoint hits the PHP timeout and dies half-applied.
Also: `:205`/`:222` run two queries **before** the URL gate, so unconnected stores pay the cost.
**Fix:** non-blocking send (mirror CRIT-4); move the URL/connection gate above the queries.

## HIGH-9 — Media upload has no response size cap **[V]**
`inc/api/media.php:389` — `download_url( $url, 60 )` with no `limit_response_size` and no
`Content-Length` pre-check; `media_handle_sideload` at `:441` then regenerates every image size
synchronously. An authenticated Shop Manager can exhaust disk or OOM the process. `$tmp` is not unlinked
on that path. `catch ( Exception )` at `:512` misses PHP 8 `Error`.
**Status:** residual half of `H1` — the redirect/DNS-rebinding half is fixed; this was never in scope.
**Fix:** pass `limit_response_size`; pre-check `Content-Length` via a HEAD request; unlink `$tmp` in a
`finally`; catch `\Throwable`.

## HIGH-10 — PHP 8 `TypeError` can 500 the Blocks checkout **[V]**
`inc/Discounts/Blocks/Store_API_Integration.php:229-231` and `:494-496`:
```php
$original = $cart_item['data']->get_regular_price();   // '' when unset
$savings  = ( $original - $current ) * $cart_item['quantity'];
```
`get_regular_price()` returns `string`; `'' - 5` is a `TypeError` on PHP 8 (confirmed empirically). The
file has `declare(strict_types=1)`. When it fires, `/wc/store/v1/cart` fatals and **Blocks cart and
checkout return 500**.
**Reachability (honest):** requires a BOGO free item whose product has an empty `regular_price` —
real for sale-price-only and some grouped/external setups, not every BOGO line.
**Note:** the code is 7.4-correct and only became fatal on the version most merchants run. The
`Requires PHP 7.4` header hides this class of bug — see the note in Global rules.
**Fix:** cast via `wc_format_decimal()` / `(float)` with an empty-string guard at both sites.

## HIGH-11 — `$cart_item['data']` dereferenced without a type guard **[A]**
`Cart_Discount_Orchestrator.php:162-166` (`restore_line`), `Bogo_Discount_Rule.php:133-137`,
`Store_API_Integration.php:229`. `wc_get_product()` returns `false` for a trashed product; WC removes
such lines in `check_cart_items`, which runs **after** `woocommerce_before_calculate_totals` p102.
Merchant trashes a product sitting in a live session → `get_regular_price() on bool` → **fatal on cart
and checkout for that shopper** until they clear cookies.
**Fix:** `if ( ! $product instanceof \WC_Product ) { continue; }` at each site.

## HIGH-12 — `DivisionByZeroError` on cart quantity change **[A]**
`Bogo_Discount_Rule.php:255-260` — `floor( $quantity / $buy_qty )` with no clamp. The resolver twin
clamps correctly at `Discount_Resolver.php:429`. `Discounts_Sync_Controller::validate_discount_data()`
(`:132-165`) never inspects `rule_config`, so a dashboard bug can plant `buy_quantity: 0` → fatal on
the cart page.
**Fix:** clamp at the rule (mirror `:429`) **and** validate `rule_config` in the sync controller.

## HIGH-13 — Webhook payload modifier can fatal order processing **[A]**
`inc/services/webhooks/webhook-payload-modifier.php:30-47` has no try/catch; `:169`
`new \DateTime( $date['date'] )` throws on unparseable input. WC delivers webhooks on `shutdown` for the
request that mutated the order → **fatal at shutdown on checkout/admin-save**.
`inc/api/dropp.php:294` gets this right — copy that pattern.
**Fix:** wrap the modifier in try/catch(`\Throwable`), log, return the unmodified payload.

## HIGH-14 — Private admin notes leak on unanchored hostname match **[V]**
`inc/services/webhooks/webhook-payload-modifier.php:74` — `strpos( $host, 'storedash' )`, unanchored.
`notstoredash.evil.com` matches and receives `$payload['notes']`, which the file's own comment at
`:36-37` flags as **private internal admin notes**.
**Status:** partial regression. The plan's MEDIUM item was addressed in *intent* — the gate exists —
but the match is exploitable. This was initially reported as fixed during the audit; that was wrong.
**Fix:** exact-suffix match against a known host allowlist (`storedash.io` and subdomains), anchored.
Do not use `strpos`. Reuse the host-validation helper from `media.php` if suitable.

## HIGH-15 — Webhook suppression not scoped to StoreDash delivery URLs **[V]**
`inc/services/webhooks/webhook-filter.php:126` returns `false` for **every** `WC_Webhook` regardless of
`$webhook->get_delivery_url()`. A legitimate authenticated StoreDash sync request silently drops the
merchant's third-party integration webhooks for that request. The header value is never HMAC-verified.
**Status:** residual `H3`. The unauthenticated kill-switch hole is closed (`:126` now requires
`$GLOBALS['storedash_request_active']` + `current_user_can('manage_woocommerce')`); the over-broad
scope was never addressed.
**Fix:** suppress only webhooks whose delivery URL is a StoreDash host (reuse HIGH-14's anchored matcher).

## HIGH-16 — Unchecked `$wpdb` writes; worst causes repeat customer notifications **[A]**
`Cart_Tracking.php:535,539,676,786,831,1382`; `Cart_Recovery.php:374`;
`class-stock-monitor.php:393,406`.
Worst is `class-stock-monitor.php:406`: if `SET status='notified'` fails **after** the webhook was
delivered, rows stay `pending` and **the next restock re-notifies the same shoppers** — the exact
runaway the comment at `:378-385` claims to prevent. `Cart_Tracking.php:539`: a failed insert silently
disables cart recovery for that shopper.
**Fix:** check the return of each write; log failures; for `:406` specifically, treat a failed status
write as a hard error and do not proceed as if notified.

## HIGH-17 — Schema-upgrade thundering herd **[V]**
`inc/core/class-storedash-bootstrap.php:100-121` runs on `plugins_loaded` for **every request**, with
**no lock**, and the version flag is written at `:117` *after* the work. Post-update, concurrent requests
each re-run `Activator::activate()`: 4× `dbDelta`, ~10 `INFORMATION_SCHEMA` probes, up to 6
`CREATE INDEX`, `ALTER TABLE`, plus an opcache walk. At 20 req/s → 20 simultaneous DDL statements on the
carts table → metadata locks → request pileup. The first arrival is often a checkout.
**Fix:** take a lock (`$wpdb` `GET_LOCK` or a transient-based mutex) before the upgrade block; losers
skip and proceed. Consider writing an "in progress" marker before the work.

## HIGH-18 — Silent carts-table creation failure recorded as success forever **[V]**
`inc/core/class-storedash-activator.php:79` — the `dbDelta( $sql_carts )` return is discarded and
`last_error` unread. The other three table creators **do** check (`:174`, `:225`, `:296`).
`bootstrap.php:117` then writes the version flag unconditionally, so a failed creation is permanently
"up to date": cart recovery is silently dead, with no signal and no retry.
Compounding: `Activator::log()` (`:13-22`) is a no-op when `StoreDash_Helpers` isn't loaded — exactly
the activation-hook case.
**Fix:** check `dbDelta` result + `last_error` at `:79` (copy `:174`); do not write the version flag if
any creation failed; make `Activator::log()` fall back to `error_log` when the helper is unavailable.

## HIGH-19 — `include_files()` unguarded **[V]**
`inc/core/class-storedash-bootstrap.php:146-220` — 25+ `require_once` on every request including
checkout, with no try/catch and no `file_exists`. A truncated file from a failed rsync/deploy = **fatal
on every page**. Both existing wrappers (`:236`, `:295`) catch only `Exception`, so PHP 8 `Error` /
`TypeError` passes straight through.
**Fix:** catch `\Throwable` in the wrappers; consider a guarded include helper that logs and degrades
rather than fataling for non-essential modules.

## HIGH-20 — `SHOW TABLE STATUS` + 4× exact `COUNT(*)` on plain admin render **[V]**
`inc/admin/class-storedash-admin.php:1859`, `:1881`, reached via `render_diagnostics_panel()` → `:224`.
**Not behind a button** — the panel is merely collapsed by default, so the queries run on every render.
On a 500k-order HPOS store with 600+ tables this routinely takes 5-30s.
**Fix:** move behind an explicit AJAX "run diagnostics" action, or cache in a short transient.

## HIGH-21 — Admin render force-fires `rest_api_init` **[A]**
`inc/admin/class-storedash-admin.php:1499-1512` — `do_action('rest_api_init')` executes **every**
plugin's route registration inside a wp-admin request. Any third-party registration fatal now kills the
StoreDash admin page.
**Fix:** obtain the route list without firing the action (`rest_get_server()->get_routes()` in a REST
context, or register-and-introspect only StoreDash's own routes).

## HIGH-22 — Unbounded + N+1 YITH migration preview **[A]**
`inc/services/waitlist/class-yith-migration.php:157-179` — no `LIMIT`, a correlated `COUNT(*)` subquery
per group, then `wc_get_product()` per row. This is the **cheap preview** endpoint the dashboard calls
first; it OOMs or 504s on any store large enough to want the migration.
**Fix:** paginate; replace the correlated subquery with a `GROUP BY`; batch product lookups.

---

# MEDIUM and LOW items

Recorded for completeness. **Most are `[A]` — verify before acting.** Work these only after CRIT and HIGH.

| ID | Finding | file:line | Src |
|----|---------|-----------|-----|
| MED-1 | `$wpdb->update` 0-rows reported as success (`0 !== false`) → HTTP 200 "Discount enabled" for a discount never written | `Discount_DB_Handler.php:301-309`; `Discounts_Toggle_Controller.php:92-100` | [A] |
| MED-2 | Cron lock excludes the converted sweep → overlapping runs both send → **duplicate `cart.converted` → double attribution in ClickHouse** | `Cart_Tracking.php:1197-1210` | [A] |
| MED-3 | `trigger_cart_webhook()` returns `null` when webhook disabled; callers treat as bool → `converted_webhook_sent_at` never stamped → same 50 rows reprocessed every 10 min forever | `Cart_Tracking.php:1065-1067`, callers `:822`, `:1376` | [A] |
| MED-4 | Uncaught `TypeError` on scalar JSON sync body → HTTP 500 instead of a REST error | `Abstract_Discounts_Controller.php:46-50` → `Discounts_Sync_Controller.php:61` | [A] |
| MED-5 | Diagnostics report SUCCESS for 5xx — a 502 renders `✓ ALL TESTS PASSED` on the exact screen merchants use when onboarding fails. `:1682` does it right | `class-storedash-admin.php:1130-1135,1153,1177,1286` | [A] |
| MED-6 | Raw `$wpdb->last_error` / `getMessage()` returned to REST clients — discloses table names, SQLSTATE, absolute server paths | `Discount_DB_Handler.php:271-274`; `Discounts_Sync_Controller.php:116-120` | [A] |
| MED-7 | `hash_equals()` TypeError in `determine_current_user` on NULL `consumer_secret` → uncatchable, 500s every StoreDash REST request. Same blast radius as the documented Yoast incident at `:78-87` | `class-storedash-auth-handler.php:276` | [A] |
| MED-8 | `TEXT DEFAULT NULL` rejected by MySQL < 8.0.13 → error 1101 → waitlist table never created → back-in-stock silently dead | `class-storedash-activator.php:203,445` | [V] |
| MED-9 | `wp_parse_url()` null → `trim(null)` deprecation on **every** front-end request | `inc/modules/headless-checkout.php:69` | [V] |
| MED-11 | Rate limiter unsafe at **both** proxy settings: filter false + Cloudflare → all visitors share one bucket → store-wide lockout at 20/hr; filter true → XFF attacker-controlled → bypass; empty IP → `md5('')` shared bucket. **Needs a CIDR allowlist** | `class-storedash-helpers.php:63-91`; `Cart_Recovery.php:72-74` | [V] |
| MED-12 | Inverted null guard dereferences the null it detects → fatal on a public `?wdc=` URL | `inc/services/cart/Cart_Recovery.php:440-441` | [A] |
| MED-13 | `to_array()` runs 4× per cart sync, each re-running `determine_customer_data()` + 6 WC accessors + `wp_json_encode` + `md5` | `Cart_Tracking.php:343,347,358,376` | [A] |
| MED-14 | Uninstall never touches `$wpdb->postmeta` → orphans `_woodash_marketing_optin`, a **consent record** | `uninstall.php` | [V] |
| MED-15 | Uninstall data removal is opt-in, default off → cart + enquiry + waitlist PII persists after deletion. Defensible per WP.org norms | `uninstall.php:29-32` | [V] |
| MED-16 | Uninstall cleans only the current site; network uninstall leaves tables/options/usermeta on every other subsite. (Creation *does* self-heal per-site — only teardown is broken) | `uninstall.php` | [V] |
| MED-17 | `storedash_flush_cart_webhook` cron in neither cleanup list → one-shot events fire post-deactivation as no-ops | scheduled `Cart_Tracking.php:387` | [V] |
| MED-18 | **Dead onboarding gate** — option read at `storedash.php:84` + `api.php:372` but **written nowhere in the repo**. The 30s-timeout `http_request_args` filter registers permanently, contradicting its "onboarding only" comment, and `/status` permanently reports webhooks unconfigured. Likely lost in the woodash→storedash rename (`docs/woodash-naming-and-migration.md:57` lists it as at-risk) | `storedash.php:84`; `api.php:372` | [V] |
| MED-19 | Unbounded purge loop, no time budget — 2M stale carts = 4000 gap-locking iterations in one cron request | `Cart_Tracking.php:1411-1429` | [A] |
| MED-20 | Unindexed `LIKE` meta_query + `posts_per_page => 200`; truncates at 200 with no `has_more`, presenting a partial sum as *the* store-credit balance | `inc/api/smart-coupons.php:92-110` | [A] |
| MED-21 | `wc_get_product()` ×2 per variable product (48 loads on a 24-product page); loose `array_search($price, $prices, false)` can select the wrong variation | `Dynamic_Price_Display.php:251-271` | [A] |
| MED-22 | Negative prices reachable — stale session `storedash_discount_percent > 100` unclamped → negative line price; "You saved -10.00 kr" rendered | `Bogo_Discount_Rule.php:125,141`; `Store_API_Integration.php:229-231` | [A] |
| MED-23 | Headless handoff replay guard has TOCTOU + object-cache dependency. **Single-use IS enforced** (`:119-122`, `:144`) — only the residuals remain. A Redis flush silently reverts the endpoint to replayable | `inc/modules/headless-checkout.php:120,144` | [V] |
| MED-24 | Multisite: no `wp_initialize_site` handler; new-site provisioning relies entirely on the per-request self-heal | — | [V] |
| LOW-2 | `wp_cache_flush()` on uninstall nukes the whole site's object cache | `uninstall.php:119` | [V] |
| LOW-3 | Non-blocking `wp_remote_post` returns fully discarded — WP still returns `WP_Error` on DNS/TLS setup failure, so broken egress loses 100% of signups with zero log lines. These are the three **unauthenticated public** handlers | `waitlist:387`, `enquiry:291`, `optin:293` | [A] |
| LOW-4 | `woodash_webhook_secret` autoloads → HMAC signing secret in the object cache on every anonymous frontend hit. The activator is disciplined about `autoload=false`; the auth handler isn't | `class-storedash-auth-handler.php:396` | [V] |
| LOW-5 | `without_cart_tracking()` has no `try/finally` — a throw leaves `self::$no_sync` true, killing tracking for the request | `Cart_Tracking.php:1039-1043` | [A] |
| LOW-6 | `catch (\Exception)` where `\Throwable` is needed — `class-blocks-optin-field.php:99-104` is on `woocommerce_init`, so an `\Error` takes down the storefront | `class-blocks-optin-field.php:99`, `waitlist:249`, `enquiry:215`, 3 discount controllers | [A] |
| LOW-7 | Unbounded static caches never cleared — grows unbounded in WP-CLI/CSV imports | `class-stock-monitor.php:34,41`; `Cart_Tracking.php:43` | [A] |
| LOW-8 | `is_store_api_request()` matches `/wc/store/` anywhere including the query string | `Discounts_Manager.php:105-106` | [V] |
| LOW-11 | Live-chat script loads on every storefront page of every connected store; `apply_filters('storedash_live_chat_enabled', true)` defaults on with no dashboard off switch | `inc/live-chat.php:64-95` | [V] |
| LOW-15 | Multiple `Discount_DB_Handler`/`Discount_Matcher` instances per request, each with its own term cache — **multiplies HIGH-6** | `Discounts_Manager.php:84-91` | [A] |
| LOW-16 | Non-sargable `DATE(created_at) = %s` defeats `idx_created`, uncached on dashboard polls | `class-waitlist-api.php:183-197` | [A] |

---

# WordPress.org submission

**Only two blockers.** Neither is architectural. The submission-critical architecture — consent gating,
external-service disclosure, escaping, auth — is genuinely done, not merely claimed.

## WPORG-1 — `Contributors:` placeholder ☐
`readme.txt:2` — `Contributors: TODO_WPORG_USERNAME`. Plugin Check flags it and a reviewer bounces it on
sight. Replace with the real wordpress.org username(s). **5 minutes.**

Also confirm `readme.txt:6` `Tested up to: 7.0` is still current at submission time (it was valid on
2026-07-21 — WP 7.0.2 was the current release).

## WPORG-2 — Replace `phpcs.xml` severity-0 suppressions with inline justified ignores ☐

**The problem.** `phpcs.xml:150-157` sets these to `<severity>0</severity>`:
```xml
<rule ref="WordPress.DB.PreparedSQL.InterpolatedNotPrepared"><severity>0</severity></rule>
<rule ref="WordPress.DB.DirectDatabaseQuery.DirectQuery"><severity>0</severity></rule>
<rule ref="WordPress.Security.NonceVerification.Missing"><severity>0</severity></rule>
```

**Plugin Check runs its own bundled ruleset and ignores your `phpcs.xml`.** This is why `composer phpcs`
reports 1 warning while a vanilla WPCS run reports **54 errors**. Checklist item I was closed by
suppressing the sniffs rather than annotating the code — the violations come straight back at review.

**Every one inspected is table-name interpolation with values correctly bound via `%d`/`%s`. There is no
actual injection.** But they must be annotated, not hidden.

| File | Errors |
|---|---|
| `inc/services/waitlist/class-yith-migration.php` | 13 (lines 137-161+) |
| `inc/core/class-storedash-activator.php` | 9 (231, 240, 250, 387, 415, 444, 474, 502, 525) |
| `inc/Discounts/Sync/Discount_DB_Handler.php` | 6 (119, 139, 218, 220, 355, 365) |
| `inc/services/waitlist/class-waitlist-api.php` | 3 (117, 130, 192) |
| `inc/admin/class-storedash-admin.php` | 1 (1881) |
| `inc/Discounts/Blocks/Store_API_Integration.php` | 1 (526) |
| `inc/Carts/Controllers/Carts_Recovery_Controller.php` | 1 (44) |
| `inc/api/dropp.php` | 1 (119) |
| `inc/modules/headless-checkout.php` | 1 (205) |
| `inc/services/waitlist/class-waitlist-handler.php` | 1 (270) |
| `Cart_Tracking.php`, `Cart_Recovery.php`, `class-stock-monitor.php` | remainder |

**Steps.**
1. For each line, add an inline ignore with a real reason:
   `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a $wpdb->prefix constant, values are bound.`
2. **Confirm the claim per line before annotating.** If any line interpolates a *value* rather than a
   table name, that is a real fix, not an ignore. Escalate rather than annotate.
3. Same treatment for the 15 `NonceVerification` warnings —
   `class-storedash-auth-handler.php:479,550,557`, `headless-checkout.php:104-106`,
   `class-custom-order-statuses.php:176,180` — all verified benign read-only reads.
4. **Then remove the severity-0 blanket suppressions from `phpcs.xml`** so the file stops hiding future
   real regressions.

**Acceptance.** `composer phpcs` clean **with the suppressions removed**. ~2-3 hours mechanical.

## WPORG-3 — Run the official Plugin Check in a real WP env ☐
Checklist item K, still open. Plugin Check also runs non-PHPCS checks — readme parsing, trademark
scanning, file-type scanning — that were **not reproducible during this audit**.
**Do not submit before this passes.** Install `plugin-check` in wp-env, run against `dist/storedash.zip`,
clear every error.

## WPORG-4 — *(recommended)* Move the chat widget off `*.ondigitalocean.app` ☐
`inc/live-chat.php:37` loads storefront JS from
`https://storedash-chat-service-5nte2.ondigitalocean.app/widget/chat-widget.js`. It is properly
`wp_enqueue_script`'d and disclosed, and SaaS widget loaders are routinely approved — but the
auto-generated hostname reads as staging infrastructure and is the most likely thing to draw a reviewer
question. A stable branded domain (`chat.storedash.io`) removes the doubt cheaply.

## WPORG-5 — *(optional)* CI gate ☐
Checklist item M. Add phpcs + plugin-check + tests on PR so WPORG-2's suppression removal cannot
silently regress.

## Minor reviewer-risk notes
- `remove_all_actions( 'admin_notices' )` at `inc/admin/class-storedash-admin.php:60-63` is correctly
  scoped to your own screens, but it suppresses other plugins' security warnings there. Reviewers
  occasionally comment. Consider filtering to your own notices.
- `posts_per_page => 200` warning at `inc/api/smart-coupons.php:96` — the only hit from your own
  `composer phpcs`. Cosmetic; overlaps MED-20.
- `composer.lock` drift warning from the checklist is still unresolved. Harmless, build-time only.

---

# Dead code cleanup

**No dead files and no dead assets exist** — all 48 procedural files are required, all 25 PSR-4 classes
referenced, all 5 CSS + 6 JS enqueued, fonts and images used. **Zip hygiene is clean**, verified against
the actual `dist/storedash.zip` artifact.

- **DEAD-1** — `inc/modules/class-module-loader.php` is an **entire no-op class**: `load_modules()`
  (`:61`) has an empty body, `$active_modules` is never written, and both public accessors
  (`is_module_active()` `:70`, `get_active_modules()` `:79`) are dead. Delete the file, its
  `require_once`, and the `StoreDash_Module_Loader::instance()` call at
  `class-storedash-bootstrap.php:214,269`.
- **DEAD-2** — 12 dead methods (0 references across `inc/`, `tests/`, `storedash.php`, `uninstall.php`):

  | File:line | Symbol |
  |---|---|
  | `inc/integrations/class-integration-registry.php:136` | `get_all_integrations()` |
  | `inc/integrations/class-integration-registry.php:145` | `get_available_integrations()` |
  | `inc/integrations/class-integration-registry.php:163` | `is_registered()` — **the only caller of `get_integration()` (`:127`), so that pair is a dead chain** |
  | `inc/integrations/class-base-integration.php:139` | `sanitize_data()` |
  | `inc/integrations/class-base-integration.php:188` | `get_product()` |
  | `inc/integrations/class-base-integration.php:205` | `api_error()` |
  | `inc/integrations/posturinn/class-posturinn-integration.php:100` | `admin_notice()` — never hooked. **Do NOT delete — CRIT-5 hooks it up.** |
  | `inc/modules/class-module-loader.php:70,79` | covered by DEAD-1 |
  | `inc/admin/class-admin-components.php:209` | `empty_state()` |
  | `inc/admin/class-admin-assets.php:104` | `is_storedash_wp_page()` |
  | `inc/Discounts/Engine/Bogo_Discount_Rule.php:324` | `get_free_item_key()` |

- **DEAD-3** — `wp_ajax_storedash_wp_get_system_info` → `ajax_get_system_info()`
  (`inc/admin/class-storedash-admin.php:39,2102`) has no caller in JS or PHP. The other three
  (`copy`/`download`/`export`) are used by `assets/js/admin-unified.js:169,240,272` — keep those.
- **DEAD-4** — `class-storedash-auth-handler.php:517,562` contain a latent SSRF
  (`wp_remote_get($_GET['callback_url'])`, no private-IP/scheme validation, no `sslverify`) that is
  **unreachable**: it hooks `woocommerce_api_auth` and `woocommerce_rest_api_valid_to_send`, **neither
  of which exists anywhere in WooCommerce 10.9.1**. Delete the dead hooks (plan Decision 10, unactioned).
  **Do not "fix" the SSRF — remove the code.**
- **DEAD-5** — `storedash_flush_cart_webhook` (scheduled `Cart_Tracking.php:387`) is in **neither**
  `class-storedash-deactivator.php:30-39` nor `uninstall.php:78-89`. Add it. (= MED-17.)
- **DEAD-6** — `uninstall.php:97-99` cancels three Action Scheduler hooks
  (`storedash_activate_discount`, `storedash_deactivate_discount`, `storedash_batch_apply_discount`)
  that **nothing ever schedules** — no `WC()->queue()->schedule*` / `as_schedule*` call exists in `inc/`.
  Also `storedash_sync_recommendation_tracking` has **zero trace anywhere in the repo**.
  Removing cleanup entries is slightly risky for legacy installs — prefer keeping legacy entries that
  correspond to hooks that *once* existed, and remove only ones that never did. Document the decision.
- **DEAD-7** — stale doc references to deleted paths:
  - `docs/rest-api-audit-2026-07.md` → `inc/admin/cart-settings-ajax.php` (deleted by SEC-7)
  - `docs/security-audit-2026-07.md` → `inc/admin/cart-settings-ajax.php`, `inc/admin/cart-settings.php`
  - `docs/wporg-fix-checklist.md` → `inc/Taxonomies/Controllers/Brands_Controller.php` (whole
    `inc/Taxonomies/` tree removed in v1.6.0)
  - `inc/Products/README.md` → `inc/setup.php` (actual path `inc/utilities/setup.php`)
- **DEAD-8** — *(optional)* The shipped `vendor/` is only Composer's autoloader (~13 files); the plugin
  has **no runtime package dependencies**. `StoreDash_Loader` (`inc/core/class-storedash-loader.php:37-44`)
  registers its own PSR-4 map **before** Composer's and handles every namespaced class, so the
  `vendor/autoload.php` require is dead weight. `composer.json`'s `autoload.psr-4` block duplicates
  `StoreDash_Loader::$psr4_prefixes` verbatim — redundant but harmless.
  Removing this touches the build; treat as optional and test the zip thoroughly.

---

# Coverage gap — close before declaring sign-off

**This audit did not fully read the following.** They received grep-level verification only, and
represent the largest unread surface in the plugin — the most likely home for an undiscovered
blocking-HTTP-on-checkout issue of the CRIT-4 class.

- **GAP-1** — `inc/widgets/class-back-in-stock-widget.php` (1,884 lines) and
  `inc/widgets/class-product-enquiry-widget.php` (1,841 lines).
- **GAP-2** — most of `inc/api/`: `media.php`, `orders.php`, `coupons.php`, `emails.php`,
  `smart-coupons.php`, `dropp.php`, `headless-checkout.php`, `payment-gateways.php`,
  `shipment-tracking.php`.
- **GAP-3** — the Posturinn and Dropp integration bodies (beyond `class-posturinn-disable-auto.php`,
  which was read).

**No one should treat this plugin as audited-complete until these are read.** Prioritise GAP-1 and GAP-2
before WP.org submission.

---

# Recommended sequence

1. **CRIT-1, CRIT-2, CRIT-3, HIGH-6** — one commit. All in the discount engine, all small, all
   independent of each other. Highest value per minute of work in this document.
2. **CRIT-4** — one commit, after confirming the cron backstop.
3. **CRIT-6** — one commit.
4. **CRIT-5** — its own branch. Real feature change, needs a wp-env Posturinn smoke test.
5. **GAP-1 + GAP-2** — read the uncovered surface. Expect new findings; add them to this document.
6. **HIGH-10, 11, 12** (storefront fatals) → **HIGH-17, 18** (schema safety) → **HIGH-14, 15**
   (webhook scoping) → **HIGH-8, 16** → the rest.
7. **DEAD-1..7** — one cleanup commit.
8. **WPORG-1, WPORG-2** → **WPORG-3** (Plugin Check to zero) → submit.

---

# Appendix A — Prior-audit status

Verified in current code on 2026-07-21. **Do not re-derive these.**

### `security-audit-2026-07.md` — SEC-1..SEC-9

**All nine are genuine fixes. The document's status markers are accurate.**

| ID | Status | Evidence |
|----|--------|----------|
| SEC-1 rate-limit persistence | **FIXED** | Zero `wp_cache_*` rate-limit counters remain. `bootstrap.php:50-59` registers only `woodash_carts` non-persistent. All six counters transient-backed. Residual weaknesses → MED-11. |
| SEC-2 spoofable rate-limit identity | **FIXED** | Single shared `StoreDash_Helpers::get_client_ip()` `:63-91`; forwarded headers only behind `apply_filters('storedash_trust_proxy_headers', false)`. |
| SEC-3 HMAC signs ≠ bytes sent | **FIXED (all 6 senders)** | Each computes `$body = wp_json_encode($payload)` once, used for both `hash_hmac` and the POST body. |
| SEC-4 missing `wp_unslash()` | **FIXED** | `Cart_Data.php:149,151,166-172,190-192`. Plugin-wide sweep clean. |
| SEC-5 `consumer_secret` in query string | **FIXED as scoped** | `:172` gates the query-string fallback on `is_ssl()`. **But the primary Basic Auth path at `:158-165` is ungated → CRIT-6.** |
| SEC-6 `register_setting` sanitizers | **FIXED** | `class-storedash-admin.php:74` carries `rest_sanitize_boolean`. |
| SEC-7 orphaned admin AJAX/settings | **FIXED** | `cart-settings.php` + `cart-settings-ajax.php` deleted, no dangling requires. |
| SEC-8 `@` error suppression | **FIXED** | Zero in the activator. Only `media.php:313`/`:446` remain, both annotated and benign. |
| SEC-9 log writes bypass `WP_Filesystem` | **RESOLVED — stronger than its WONTFIX** | `write_to_log_file()` no longer exists; nothing writes under `wp-content/`. |

### `perfect-production-wporg-plan-2026-07.md`

| ID | Status | Note |
|----|--------|------|
| **C1** display discounts never reduce real price | **FIXED** | `Cart_Discount_Orchestrator.php:66` registers a real `woocommerce_before_calculate_totals` at p102; `:108` calls `set_price()`. Idempotent. **But CRIT-1/2/3 re-open silent mispricing by other routes.** |
| **H1** SSRF in `media.php` | **MOSTLY FIXED** | Scheme allowlist `:289`; private+reserved IP block with A **and** AAAA `:308-332`; metadata blocklist `:297`; `reject_unsafe_urls` forced `:383-392`. Redirects closed. TOCTOU/rebinding honestly self-documented at `:379-382`. Residual → HIGH-9. |
| **H2** quantity discount above sale | **FIXED** | `Discount_Resolver.php:404-405` clamps to `min($unit,$current)` and bails if `>=`. Overcharge structurally impossible. |
| **H3** `X-StoreDash-Source` kill switch | **FIXED (auth hole); scoping wrong** | `webhook-filter.php:126` now requires `$GLOBALS['storedash_request_active']` + `manage_woocommerce`. Residual → HIGH-15. |
| **H4** PII before connection | **FIXED** | `is_store_connected()` `:198-207` requires `store_id > 0 && '' !== $secret`; `resolve_webhook_url()` `:225-227` returns `''`. All six senders gated. |
| **H5** Posturinn | **STILL LIVE** | → CRIT-5. The one decided-but-never-implemented plan item. |
| **H6** `woodash_sync_carts_batch` cron | **FIXED** | Present in both cleanup lists. |
| **H7** waitlist never reaches `notified` | **FIXED, with a reliability hole** | Status written at `class-stock-monitor.php:406`; `$wpdb` return unchecked → HIGH-16. |
| **H8** uninstall incomplete | **MOSTLY FIXED** | All 6 tables incl. enquiry PII `:38-50`, option sweep `:56-65`, transients, cron, Action Scheduler, usermeta. Residuals → MED-14/15/16. |
| **H9-H15** | **FIXED** | H14 live-chat now `wp_enqueue_script` (`live-chat.php:95`), store_id-gated `:76-87`. H15 confirmed: no `file_put_contents`/`WP_CONTENT_DIR` writes in `inc/`. |

---

# Appendix B — Verified clean. **Do not re-flag.**

- `php -l` clean on all 75 files.
- **PHPCompatibilityWP 7.4–8.3: 0 errors, 0 warnings.** No 8.x-only syntax anywhere; `Requires PHP 7.4`
  is accurate. (Several bugs above are PHP 8 *runtime* failures in 7.4-valid syntax — a different thing.)
- **HPOS declared** (`storedash.php:76-78`, both `custom_order_tables` and `cart_checkout_blocks`) with
  **zero** direct order postmeta/posts queries. All order meta via CRUD.
- WooCommerce-absent path genuinely safe (`storedash.php:117-120` returns before anything loads;
  `Requires Plugins:` header present).
- All third-party integrations `class_exists`-guarded; Elementor widgets double-gated by
  `did_action('elementor/loaded')`.
- Autoloader is path-traversal-safe with a trailing-separator boundary check.
- `dbDelta` usage correct — **no** `IF NOT EXISTS` (the trap is explicitly avoided, commented
  `activator:39-41`); real schema versioning with idempotent `INFORMATION_SCHEMA` guards.
- 26 of 28 REST routes require `manage_woocommerce`. The two `__return_true` routes are safe by design:
  `/ping` returns two booleans; `/recover-cart` uses a 128-bit CSPRNG token with prepared lookups.
- All 8 admin-AJAX handlers carry **both** `check_ajax_referer( 'storedash-wp-admin', … )` and
  `current_user_can( 'manage_woocommerce' )`.
- No secret reaches HTML/JS/REST across all 6 `wp_localize_script` sites.
- `determine_current_user` re-entrancy guard is correct (`try/finally`, no `user_can` inside the window)
  — **the Yoast OOM has not regressed.**
- Discount SQL is injection-free with a proper writable-column whitelist
  (`Discount_DB_Handler.php:56,101`).
- No `posts_per_page => -1`. No every-minute cron. No autoloaded-array bloat.
- Cart tracking is deliberately checkout-only, hash-diffed, 120s-throttled.
- **Consent gating is real** — nothing leaves the site pre-connection. Matches the readme exactly.
- **External-services disclosure is complete** — all 12 `wp_remote_*` calls traced. Outbound targets are
  only `webhooks.storedash.io`, `app.storedash.io`, the chat service, the user-supplied WC OAuth
  callback, and the site's own URL. The `storedash-sync-go-*.ondigitalocean.app` string at
  `inc/api/api.php:200` is an **inbound CORS allowlist entry, not an outbound send** — correctly omitted.
- Vanilla WPCS returns **zero** for `EscapeOutput`, `EnqueuedResources`, `PrefixAllGlobals`,
  `AlternativeFunctions`, `NoSilencedErrors`, `SafeRedirect`, `PluginMenuSlug`.
- No obfuscation. The two `base64_decode` calls (`class-storedash-auth-handler.php:161`,
  `headless-checkout.php:170`) are JWT payload decodes. No `eval`, `gzinflate`, or minified blobs.
- Fonts OK — `assets/fonts/inter-latin-variable.woff2` + `OFL.txt`, SIL OFL 1.1, GPL-compatible,
  disclosed in the readme.
- **Headless token replay IS fixed** — single-use enforced at `inc/modules/headless-checkout.php:119-122`
  (rejects a consumed signature) and `:144` (burns after successful restore). Judging this from
  `inc/api/headless-checkout.php` — the signer — gives the wrong answer. No account takeover:
  `wp_set_auth_cookie`, `wp_set_current_user`, `wp_signon` appear nowhere in `inc/`.
- **Multisite table creation self-heals per-site** (`bootstrap.php:100-122`, keyed on a per-site option,
  short-circuiting on one `get_option`). Only *uninstall* is single-site-only (MED-16).
- `LOW-9` `is_storedash_rest_request()` substring match (`class-storedash-auth-handler.php:132-133`) is
  **byte-for-byte identical to WC core** (`class-wc-rest-authentication.php:69-84`) — an upstream issue,
  not a regression here.
- `LOW-10` `information_schema` COUNT in `inc/utilities/helper-functions.php:20-45` is **static-cached
  per request** — one query, not one per call.
- `LOW-12` widget nonces baked into full-page-cached HTML (`class-widgets-manager.php:81,112,143`): for
  logged-out visitors WP derives nonces from user ID 0, so one scraped nonce is valid for all anonymous
  visitors ~24h. **The nonce is not the anti-automation control here — the rate limit is.** The
  architecture is correct; this is noted so nobody "simplifies" by trusting the nonce.
- `LOW-14` silent catch at `class-storedash-admin.php:1826-1828` can never fire — `disk_free_space()`
  warns, it doesn't throw.

---

# Appendix C — Audit methodology and its limits

Read this before trusting any single finding above.

**Method.** Three parallel agent passes over the repo (dead-code, WP.org-readiness, production/security),
each cross-checked against the prior audit documents, with the security pass re-reading decisive code
directly for every CRITICAL and HIGH.

**Known failure modes observed during this audit — carry these forward:**

1. **Self-attestation is unreliable.** The security pass's own first sweep **declared the discount
   engine sound** after confirming C1's mechanism was correctly implemented. CRIT-1, CRIT-2 and CRIT-3
   are all in that subsystem, in files it had already read. Confirming that *one* fix landed is not
   evidence that the surrounding code is correct.
2. **`[A]` findings run narrow.** Of three MEDIUM/LOW agent claims spot-checked, **two were materially
   narrower than presented** (`Quantity_Discount_Rule.php:204` is guarded by `isset()` and only fatals
   on a non-empty scalar; `helper-functions.php` is static-cached — one query, not per-call).
3. **Two findings were initially reported backwards** and corrected only on re-read:
   - MED-23 (headless replay) was reported STILL LIVE; it is **FIXED**. The error came from reading the
     signer (`inc/api/headless-checkout.php`) instead of the verifier (`inc/modules/headless-checkout.php`).
   - HIGH-14 (admin-note leak) was reported FIXED; it is **NOT** — the gate exists but the match is
     exploitable.
4. **Prior-audit status markers were, in this case, accurate** — SEC-1..SEC-9 all checked out. But H5
   sat marked as a *decision* in the plan document and was never implemented, which is a different and
   easier failure to miss: read for implementation, not for intent.

**Practical rule for the next agent:** run the Verify-before command for every item you touch. If it
does not match, the finding is stale — say so in the progress log rather than forcing a fix.

---

# Progress log

Append one line per completed item: `- **<ID> ✅** commit `<sha>` — <what changed, any drift found>`

<!-- entries go below this line -->
