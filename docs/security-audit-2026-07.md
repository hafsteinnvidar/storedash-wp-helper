# StoreDash Helper — Security & Code Quality Audit (2026-07)

> Source: `devvn-wp-security-audit` skill (WPCS + WordPress Plugin Check ruleset), 4 parallel
> reviewers over all 84 PHP files + 6 JS files, reconciled against an independent cross-audit.
> Every finding below was **read directly in code** — no IDE/intelephense noise.

## How to use this document

Each item is an independently-grabbable work unit. To pick one up:

1. Read the **What / Where / Why / Improves** block.
2. Follow **Implementation** — it names the reference implementation already in this repo to copy.
3. Satisfy the **Acceptance criteria** checklist.
4. Run **Verify** (always includes `php -l` on touched files; `composer phpcs && composer test` before PR).
5. Tick the box in the tracking table and note the PR.

**Do the work in the order of the tracking table.** SEC-1 and SEC-2 are coupled — SEC-1's transient
fix is *ineffective without* the shared trusted-IP helper it depends on. Don't ship SEC-1 alone.

Overall posture: the plugin is **well-hardened** (WooCommerce consumer-key auth, constant-time inbound
HMAC, SSRF protection in `media.php`, path-boundary-checked autoloader, clean PSR-4 REST modules). The
findings are concentrated in two cross-cutting patterns (rate-limit storage/identity, and outbound HMAC
byte-consistency) plus a handful of hardening/cleanup items.

---

## Tracking table (priority order)

| ID | Done | Priority | Type | Severity | Effort | Files |
|----|------|----------|------|----------|--------|-------|
| SEC-1 | ✅ | P0 | Broken rate limiting (storage) | High | M | `bootstrap.php`, `Cart_Recovery.php`, `webhooks.php`, `class-enquiry-handler.php`, `class-optin-handler.php`, `api/api.php` |
| SEC-2 | ✅ | P0 | Spoofable rate-limit identity | High | M | `class-enquiry-handler.php`, `class-optin-handler.php` (ref: `class-waitlist-handler.php`) |
| SEC-3 | ✅ | P1 | Outbound HMAC signs ≠ sent bytes | Medium | S | `Cart_Tracking.php`, `class-enquiry-handler.php`, `class-waitlist-handler.php`, (`class-stock-monitor.php` consistency) |
| SEC-4 | ✅ | P1 | Missing `wp_unslash()` (data corruption) | Medium | S | `Cart_Data.php` |
| SEC-5 | ✅ | P2 | `consumer_secret` via query string | Medium | S | `class-storedash-auth-handler.php` |
| SEC-6 | ✅ | P2 | `register_setting()` missing sanitizer | Low–Med | S | `cart-settings.php`, `class-storedash-admin.php` |
| SEC-7 | ✅ | P3 | Orphaned live admin AJAX/settings | Low | S | `cart-settings-ajax.php`, `cart-settings.php`, bootstrap `include_files()` |
| SEC-8 | ✅ | P3 | `@` error suppression | Low | S | `class-storedash-activator.php` |
| SEC-9 | ⏸️ WONTFIX | P3 | Log writes bypass `WP_Filesystem` | Low | M | `class-storedash-helpers.php` |

Effort: **S** ≈ <1h, **M** ≈ 1–3h.

---

## SEC-1 — Rate limiting does not work across requests (P0, High)

> **✅ DONE** (2026-07, commits `f40ef96` + merge `4accd81` on `main`). All 6 counters moved to
> DB-backed transients; `storedash_rate_limits` group removed; verified `grep` clean, `php -l` +
> `composer test` (23/23) pass.

**What.** All public/reachable rate-limit counters use `wp_cache_get/set()` against the
`storedash_rate_limits` group, which is registered **non-persistent** — so counters never survive past
a single PHP process. Every HTTP request is a fresh process, so the counter is effectively always 0.
**Rate limiting is silently disabled in production.**

**Where.**
- Root cause: `inc/core/class-storedash-bootstrap.php:54-57` (`wp_cache_add_non_persistent_groups`)
- `inc/services/cart/Cart_Recovery.php:73-83` — `/recover-cart` (public route)
- `inc/services/webhooks/webhooks.php:43-51` — inbound webhook receiver
- `inc/services/enquiry/class-enquiry-handler.php:243-252` — public enquiry AJAX
- `inc/services/optin/class-optin-handler.php:301-307` — public opt-in AJAX
- `inc/api/api.php:377-382` — user-activity update throttle *(missed by first-pass audit; found in cross-audit)*

**Why fix.** These counters are the only abuse mitigation on the public `/recover-cart`, enquiry, and
opt-in endpoints. As written they provide zero throttling — an attacker can hammer any of them without
limit (enumeration, spam submissions, resource exhaustion). CLAUDE.md documents `/recover-cart` as
"token + rate-limited"; that guarantee is currently false.

**Improves.** Restores real per-identity throttling; closes the abuse vector on every public endpoint;
makes the documented security model true.

**Reference implementation.** `inc/services/waitlist/class-waitlist-handler.php` already does this
correctly with a **DB-backed transient** (100/hr/IP), which persists even on sites without an external
object cache. Copy that storage approach.

**Implementation.**
- Replace each `wp_cache_get/set(..., 'storedash_rate_limits', ...)` pair with
  `get_transient()` / `set_transient()` using the same key + TTL.
- Keep TTLs as-is (`MINUTE_IN_SECONDS` for cart recovery, `HOUR_IN_SECONDS` for enquiry/optin, 5 min
  for the api.php activity throttle, per-window for webhooks).
- Leave `woodash_carts` in the non-persistent list (that group is correct — it prevents stale cart data
  during checkout). Only remove/replace the `storedash_rate_limits` usage.
- **Depends on SEC-2** for the key identity — the transient key must be derived from a non-spoofable IP.

**Acceptance criteria.**
- [ ] All rate-limit counters use transients; `grep -rn "storedash_rate_limits" inc/` returns nothing
      (both the `wp_cache_*` calls and the `wp_cache_add_non_persistent_groups()` entry are gone).
- [ ] `woodash_carts` remains registered non-persistent (unrelated to this fix — leave it).
- [ ] A second request within the window sees the incremented counter (counter persists across processes).

**Verify.** `grep -rn "storedash_rate_limits" inc/` → nothing; `php -l` each file; manual: fire 2 requests,
confirm the 2nd sees count ≥ 1.

---

## SEC-2 — Rate-limit identity trusts spoofable proxy headers (P0, High)

> **✅ DONE** (2026-07, same commits as SEC-1). Added shared `StoreDash_Helpers::get_client_ip()`
> (defaults to `REMOTE_ADDR`, forwarded headers gated behind `storedash_trust_proxy_headers` filter,
> default false); enquiry + optin + cart-recovery + api now use it. Waitlist left on its own
> `storedash_waitlist_trust_proxy_headers` filter (already correct); webhooks already used `REMOTE_ADDR`.

**What.** `enquiry` and `optin` derive the client IP from `HTTP_X_REAL_IP` → `HTTP_X_FORWARDED_FOR` →
`REMOTE_ADDR`, defaulting to the forwarded headers. Those headers are attacker-controlled, so a client
rotates `X-Forwarded-For` to get a fresh rate-limit bucket on every request — **defeating the rate
limit even after SEC-1 is fixed.**

**Where.**
- `inc/services/enquiry/class-enquiry-handler.php:259-` (`get_client_ip()`, headers at 262-264)
- `inc/services/optin/class-optin-handler.php:316-` (`get_client_ip()`, headers at 319-321)

**Why fix.** SEC-1 and SEC-2 are a pair. A persistent counter keyed on a spoofable identity is still
bypassable. Both must land together for rate limiting to actually hold.

**Improves.** Rate-limit buckets become stable per real client; forwarded-header trust becomes an
explicit, opt-in decision for operators who actually run a trusted reverse proxy.

**Reference implementation.** `inc/services/waitlist/class-waitlist-handler.php:325-347` —
`get_client_ip()` uses `REMOTE_ADDR` only by default and gates forwarded headers behind
`apply_filters( 'storedash_waitlist_trust_proxy_headers', false )`.

**Implementation.**
- Port the waitlist `get_client_ip()` logic to enquiry and optin (or, preferred, extract a single
  shared helper — e.g. a static method on `StoreDash_Helpers` — and have all handlers call it).
- Use a shared filter name for the whole plugin (e.g. `storedash_trust_proxy_headers`) rather than
  three widget-specific ones; keep default `false`.
- `Cart_Recovery.php:71` and `api/api.php` already use `REMOTE_ADDR` only — align them to the shared
  helper for consistency but they are not currently vulnerable.

**Acceptance criteria.**
- [ ] enquiry + optin default to `REMOTE_ADDR`; forwarded headers only when the filter returns true.
- [ ] A single shared IP helper exists (no 3+ divergent copies).
- [ ] Sending a forged `X-Forwarded-For` does **not** reset the rate-limit counter (with filter off).

**Verify.** `php -l` each file; manual: send N+1 requests with rotating `X-Forwarded-For`, confirm the
(N+1)th is throttled.

---

## SEC-3 — Outbound webhook HMAC signs different bytes than the body sent (P1, Medium)

> **✅ DONE** (2026-07). Every outbound webhook (cart-tracking, enquiry, waitlist-handler,
> stock-monitor) computes `$body = wp_json_encode($payload)` once and uses it for both the sha256
> HMAC and the `wp_remote_post()` body. Header name + algorithm unchanged. Verified in-scope grep
> clean, `php -l` + `composer test` (23/23) pass.

**What.** Several outbound webhooks compute the `X-WooDash-Signature` HMAC over `json_encode($payload)`
but send `wp_json_encode($payload)` as the body. `wp_json_encode()` runs `_wp_json_sanity_check()`
(forces valid UTF-8) and can produce different bytes. When they diverge (product names, free-text
`message` fields, malformed UTF-8), the receiver recomputes a mismatched HMAC → **signature
verification fails silently and the webhook is rejected/ignored.**

> Correct category: this is **webhook delivery/signature reliability**, not information disclosure.
> Nothing leaks; delivery breaks.

**Where.**
- `inc/services/cart/Cart_Tracking.php:931` (signs) vs `:941` (sends)
- `inc/services/enquiry/class-enquiry-handler.php:309` vs `:317`
- `inc/services/waitlist/class-waitlist-handler.php:402` vs `:410` *(missed by first-pass audit; found in cross-audit — waitlist is NOT clean here)*
- `inc/admin/cart-settings-ajax.php:83` vs `:91` — moot, that handler is orphaned legacy code (see SEC-7)

**Why fix.** Intermittent, hard-to-debug webhook drops that only reproduce on certain payload content.
Erodes trust in the outbound webhook contract with `storedash-sync` (Go).

**Improves.** Deterministic signature that always matches the transmitted body; reliable delivery.

**Reference implementation.** `inc/services/optin/class-optin-handler.php:262-273` does it right:
```php
$body = wp_json_encode( $payload );          // compute ONCE
$headers['X-WooDash-Signature'] = hash_hmac( 'sha256', $body, $webhook_secret );
wp_remote_post( $url, array( 'body' => $body, ... ) );
```

**Implementation.**
- In each site above: compute `$body = wp_json_encode( $payload )` once, then use `$body` for both
  `hash_hmac()` and the `wp_remote_post()` `'body'`.
- `inc/services/waitlist/class-stock-monitor.php:299` already signs `wp_json_encode()` and sends
  `wp_json_encode()` — **no bug today**. Refactor to the compute-once `$body` form only for
  consistency/future-proofing; do not file it as a defect.

**Acceptance criteria.**
- [ ] Every outbound webhook signs the exact `$body` string it sends (single `wp_json_encode` call).
- [ ] `grep -rn "hash_hmac(.*[^_]json_encode(" inc/` returns nothing (i.e. no signing over the
      non-`wp_` `json_encode()`; note a bare `hash_hmac.*json_encode` grep also matches `wp_json_encode`).

**Verify.** `php -l` each file; unit or manual: send a payload with a non-ASCII product name and confirm
the receiver validates the signature.

---

## SEC-4 — Missing `wp_unslash()` corrupts customer data (P1, Medium)

> **✅ DONE** (2026-07). `Cart_Data.php` now wraps all four `$_POST` fields
> (email/first_name/last_name/phone) in `wp_unslash()` before sanitizing.

**What.** `Cart_Data` sanitizes `$_POST` fields without `wp_unslash()` first. WordPress adds slashes to
superglobals; `sanitize_text_field()` does not strip them, so values are stored/synced with stray
backslashes. Most visible on names with apostrophes ("O'Brien", "D'Angelo").

**Where.** `inc/services/cart/Cart_Data.php:151` (`email`), `:168`/`:172` (`first_name`/`last_name`),
`:192` (`phone`).

**Why fix.** Real, reproducible data-corruption for a meaningful slice of real customer names; the
mangled value propagates into the synced cart record. `email`/`phone` are lower-impact but WPCS-correct
to unslash too.

**Improves.** Correct customer data captured and synced; WPCS compliance.

**Implementation.** Wrap each with `wp_unslash()` before the sanitizer:
```php
$this->name = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
```
Apply to `first_name`, `last_name`, `email` (`sanitize_email( wp_unslash(...) )`), `phone`.

**Acceptance criteria.**
- [ ] All four fields use `wp_unslash()` before sanitizing.
- [ ] A submitted name `O'Brien` round-trips without a backslash.

**Verify.** `php -l`; manual: submit `O'Brien`, inspect stored cart record.

---

## SEC-5 — `consumer_secret` accepted via query string (P2, Medium, compat-sensitive)

> **✅ DONE** (2026-07). Query-string fallback retained but HTTPS-gated via `is_ssl()`; logs a
> non-sensitive deprecation `warning` when used. Basic-Auth path and validation logic unchanged.
> Removal of the fallback deferred pending telemetry on whether any client still uses it.

**What.** An auth fallback accepts the WooCommerce `consumer_key`/`consumer_secret` (a
`manage_woocommerce` credential) from the URL query string, with no HTTPS requirement.

**Where.** `inc/core/class-storedash-auth-handler.php:144-151`.

**Why fix.** Query strings are recorded in web-server access logs, browser history, proxy logs, and
`Referer` headers — widening credential exposure well beyond the Authorization-header path directly
above it. Rule 5.6: credentials belong in the POST body / auth header, not the URL.

> **Compatibility caution.** WooCommerce's own REST API historically supports query-string key auth, and
> old/external clients may rely on it. **Do not remove outright** without a deprecation path.

**Improves.** Shrinks credential-leakage surface; nudges consumers to header-based auth.

**Implementation (staged).**
1. First: reject the query-string path unless `is_ssl()` is true (fast, low-risk hardening).
2. Then: log a deprecation notice when the query-string path is used, so we can see if any consumer
   still relies on it.
3. Later (separate decision): remove the fallback once telemetry shows it's unused.

**Acceptance criteria.**
- [ ] Query-string credentials rejected on non-HTTPS requests.
- [ ] Deprecation logged when the path is exercised.
- [ ] Header-based auth path unchanged.

**Verify.** `php -l`; manual: HTTP (non-SSL) query-string auth is rejected; header auth still works.

---

## SEC-6 — `register_setting()` calls missing `sanitize_callback` (P2, Low–Medium)

**What.** Settings are registered without a `sanitize_callback`, so any path reaching `options.php` with
a valid nonce can write unsanitized values (including webhook URL/secret) to `wp_options`.

**Where.** `inc/admin/cart-settings.php:57-64`, `inc/admin/class-storedash-admin.php:71-79`.

**Why fix.** Settings API hardening / Plugin Check compliance. **Severity note:** below security-Medium
in practice — the live save flow (`StoreDash_Admin::ajax_save_cart_settings`) already sanitizes each
field; the unsanitized path is only reachable via a direct `options.php` POST. Worth fixing, not urgent.

**Improves.** Framework-level sanitization independent of the UI path; passes Plugin Check.

**Implementation.**
- Add a `sanitize_callback` to every `register_setting()`: `esc_url_raw` for webhook URLs,
  `sanitize_text_field` for secret/store-id, `rest_sanitize_boolean`/`absint` for booleans/ints.
- `cart-settings.php` still registers these settings live on `admin_init`, but its **page is orphaned**
  (menu no longer registered). Prefer removing the whole orphaned file as part of SEC-7 rather than
  hardening registrations that back no reachable UI — but if SEC-7 is deferred, add the `sanitize_callback`s
  here in the meantime since the registrations are live.

**Acceptance criteria.**
- [ ] Every surviving `register_setting()` has a `sanitize_callback`.
- [ ] Duplicate/dead registrations removed (coordinate with SEC-7).

**Verify.** `php -l`; `composer phpcs`.

---

## SEC-7 — Orphaned but live admin AJAX/settings code (P3, Low)

> **✅ DONE** (2026-07). Deleted `inc/admin/cart-settings.php` + `inc/admin/cart-settings-ajax.php`
> and their `require_once` lines in `include_files()`. No live references remain; webhook-test lives on
> via `StoreDash_Admin::ajax_test_webhook()`. **SEC-6 done alongside**: all 9 surviving
> `register_setting()` calls in `class-storedash-admin.php` now carry typed `sanitize_callback`s.
>
> **Follow-up (not caused by this work):** `Cart_Data::get_cart_setting()` reads
> `get_option('woodash_cart_settings')`, but no code in this repo writes that option (the deleted files
> only used the string as a settings-GROUP label, never `update_option`). Confirm whether woo-dash/sync
> still writes it; if not, `get_cart_setting()` silently returns defaults.

**What.** These are **orphaned legacy admin UI/settings registrations** — still loaded and live, but no
longer reachable through the UI. `Cart_Settings_Ajax` registers `wp_ajax_woodash_test_webhook`,
duplicating `StoreDash_Admin::ajax_test_webhook()` (`wp_ajax_storedash_wp_test_webhook`). The only UI
that generated its nonce/button lived in `cart-settings.php::render_settings_page()`, whose menu is no
longer registered. Note: `cart-settings.php` is **not inert** — it is still `require_once`'d
(`bootstrap.php:209`) and hooks `admin_init → register_settings()`, so it actively registers the
unsanitized settings called out in SEC-6 on every admin request. Only its *page* is orphaned.

**Where.** `inc/admin/cart-settings-ajax.php` (whole class), `inc/admin/cart-settings.php` (dead page),
and their `require_once` lines in `StoreDash_Bootstrap::include_files()`.

**Why fix.** Reduces attack surface (one fewer live, forgeable AJAX action) and removes two divergent
copies of the same webhook-test logic (which also carried the SEC-3 HMAC drift).

**Improves.** Less dead code, smaller surface, single source of truth for webhook testing.

**Implementation.**
- Delete `inc/admin/cart-settings-ajax.php` and `inc/admin/cart-settings.php`.
- Remove their `require_once` entries in `inc/core/class-storedash-bootstrap.php::include_files()`.
- Confirm nothing else references `Cart_Settings_Ajax`, `Cart_Settings`, or `woodash_test_webhook`.

**Acceptance criteria.**
- [ ] `grep -rn "woodash_test_webhook\|Cart_Settings_Ajax\|class Cart_Settings\b" inc/` returns nothing.
- [ ] Plugin loads without warnings; webhook-test still works via the StoreDash_Admin action.

**Verify.** `php -l` remaining files; load admin, run webhook test via the surviving handler.

---

## SEC-8 — `@` error suppression (P3, Low)

> **✅ DONE** (2026-07). Removed `@` from both `opcache_reset()` / `opcache_invalidate()` calls in the
> activator (already `function_exists()`-guarded). No `@`-suppressed calls remain in the file.

**What.** `@opcache_reset()` / `@opcache_invalidate()` suppress errors although both are already guarded
by `function_exists()`, so `@` only hides useful runtime errors during activation debugging.

**Where.** `inc/core/class-storedash-activator.php:542, 560`.

**Why fix.** WPCS rule 7.7 (no `@` except `@unlink` cleanup). Improves activation debuggability.

**Implementation.** Remove the `@`. If a call can legitimately fail, branch on the return and log via
`StoreDash_Helpers::debug_log()` instead of suppressing.

**Acceptance criteria.** [ ] No `@` operators remain on these calls. **Verify.** `php -l`; `composer phpcs`.

---

## SEC-9 — Log writes bypass `WP_Filesystem` (P3, Low)

> **⏸️ DEFERRED / WONTFIX** (2026-07). Verified `write_to_log_file()`/`rotate_log_file()` are reached
> only from `webhooks.php::log_webhook()`, which early-returns unless `WP_DEBUG` — a debug-only
> webhook-receipt tracer, not the primary/fallback log path (`debug_log`/`log_message` use
> `wc_get_logger()`/`error_log()`). A `WP_Filesystem` rewrite would add FS-method/credential fragility
> to a rarely-run debug path for a LOW Plugin-Check nicety. Revisit only if targeting a fully clean
> Plugin Check pass.

**What.** The fallback file logger uses raw `file_put_contents()` / `rename()` / `unlink()` for writing
and rotating log files instead of the `WP_Filesystem` API.

**Where.** `inc/core/class-storedash-helpers.php:149` (write), `:170-179` (rotate).

**Why fix.** Plugin Check / rule 5.3 concern. **Caution:** low value — this is an internal fallback
logger. A `WP_Filesystem` rewrite can add fragility (credential prompts, FS method quirks) that
outweighs the benefit unless this path is actually exercised. Do this only if targeting a clean Plugin
Check pass, and prefer a minimal, well-tested change.

**Improves.** Respects the site's configured filesystem method; Plugin Check compliance.

**Implementation.** Route writes through `global $wp_filesystem; WP_Filesystem();
$wp_filesystem->put_contents()/move()/delete()`, initialized once. Keep the primary logging path
(`WC_Logger`) as-is.

**Acceptance criteria.** [ ] Log write/rotate use `WP_Filesystem`; logging still works when WC_Logger is
unavailable. **Verify.** `php -l`; exercise the fallback path and confirm a log file is written+rotated.

---

## Confirmed clean (PASS)

Audited and found correct — no action needed:

- **SQL Injection** — `$wpdb->prepare()` throughout; `IN()` via `array_fill()`, `LIKE` via `esc_like()`,
  no `ORDER BY` from input; table names interpolate only `$wpdb->prefix`/constants.
- **XSS** — all output escaped late (`esc_html/attr/url/js`, `wp_kses_post(wc_price())`) across admin
  pages, Elementor widgets, `Dynamic_Price_Display`, live-chat.
- **CSRF/Nonce** — every state-changing AJAX handler calls `check_ajax_referer()` first; no buggy
  if/else/negation nonce patterns.
- **Access Control (REST)** — only `/ping` and `/recover-cart` are public (by design); all other write
  routes require `manage_woocommerce` via `Base_Permissions::check_permission()`.
- **Inbound webhook HMAC** — constant-time `hash_equals()`.
- **File/Remote** — SSRF protection in `media.php`; SSL never disabled (`sslverify => true`); no
  `unserialize()` on remote data.
- **Safe Redirect** — `wp_safe_redirect()` + `exit` (headless-checkout).
- **Forbidden functions** — no `eval`/`create_function`/backticks/`preg_replace(/e)`;
  `print_r`/`error_log` gated behind `WP_DEBUG`.
- **HPOS** — order access via `wc_get_order()` / `WC_Order` API.
- **i18n & prefixing** — `storedash` text domain consistent; `StoreDash_`/`storedash_`/`woodash_`
  (backward-compat) prefixes correct.
- **PSR-4 modules** (Discounts/Carts/Products/Taxonomies) — clean.

---

## Audit provenance & known limitations

- Method: `devvn-wp-security-audit` skill, 4 reviewers sharded by subsystem, reconciled against an
  independent cross-audit.
- **Sharding blind spot:** the first pass sharded by directory, which missed two *cross-cutting*
  patterns — the shared non-persistent rate-limit group (SEC-1's `api.php` instance) and the outbound
  HMAC encode-mismatch (SEC-3's waitlist instance). Both are folded into the findings above. When
  re-auditing, review rate-limit storage and outbound-HMAC signing as whole-plugin themes, not per-file.
- Before PR: `composer phpcs && composer test && php -l <touched files>`. If DB schema changes, bump the
  `StoreDash_Bootstrap::maybe_create_tables()` schema-version constant (none of these findings require
  schema changes).
