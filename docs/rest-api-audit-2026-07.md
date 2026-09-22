# REST API Audit — StoreDash Helper

**Date:** 2026-07-01
**Auditor:** Claude (wp-rest-api-development skill)
**Plugin version at audit:** see `Version:` header in `storedash.php`
**Scope:** Every `register_rest_route()` call in the plugin — route registration, `permission_callback` authorization, `args` input validation/sanitization, and response shaping. Namespace: `storedash/v1`.

> **How to use this document.** Each finding has a **Verify** block (a command + expected result) so an engineer or an AI agent can independently confirm the issue still exists before fixing, and re-run the same command to confirm the fix. Work top-down: reproduce, fix, re-verify, tick the checkbox in the [Tracking Checklist](#tracking-checklist). Do not mark a finding resolved without re-running its Verify step.

---

## Methodology

Detection used ripgrep sweeps over `*.php` (excluding `vendor/`) plus manual reads of every route callback and permission function. The commands below reproduce the full route inventory:

```bash
# All route registrations
rg -n "register_rest_route\s*\(" inc -g '*.php'

# All permission callbacks
rg -n "permission_callback" inc -g '*.php'

# All arg schemas / validation
rg -n "'args'|validate_callback|sanitize_callback" inc -g '*.php'
```

**Auth model (context for every finding):** requests authenticate via a real WooCommerce consumer key resolved in `determine_current_user`, verified with `hash_equals`, and re-checked for the `manage_woocommerce` capability on the resolved user (`inc/core/class-storedash-auth-handler.php:241-257`). Route `permission_callback`s therefore rely on `current_user_can('manage_woocommerce')` returning true only for a validly-keyed request. `X-StoreDash-Source` is **not** used for authorization.

---

## Summary

| # | Severity | Finding | File |
|---|----------|---------|------|
| 1 | WARNING | Dynamic integration routes don't enforce a `permission_callback` (public-by-default footgun) | `inc/integrations/class-base-integration.php:154` |
| 2 | WARNING | Write endpoints validate inside the callback instead of declaring an `args` schema | `inc/Discounts/Route_Registry.php`, `inc/Carts/Route_Registry.php` |
| 3 | WARNING | `/recover-cart` rate limiter is a no-op without a persistent object cache | `inc/services/cart/Cart_Recovery.php:70-83` |
| 4 | INFO | No `WP_REST_Controller` subclasses; response envelopes vary | plugin-wide |
| 5 | INFO | `media.php` URL-upload is an SSRF surface → route to security review | `inc/api/media.php:64` |

**Overall verdict:** healthy. Authorization is uniform and correct across all ~34 routes. The only two unauthenticated routes (`/ping`, `/recover-cart`) are intentional and documented in `CLAUDE.md`. **No CRITICAL findings** — no missing `permission_callback`, no `__return_true` on a privileged write, no raw superglobals inside REST callbacks.

---

## Findings

### 1. Dynamic integration routes don't enforce a `permission_callback`

- **Severity:** WARNING
- **File:** `inc/integrations/class-base-integration.php:154`
- **Status:** latent (no integration currently triggers it)

**Issue.** The route config is passed verbatim from a subclass's `get_api_endpoints()`:

```php
foreach ( $this->get_api_endpoints() as $endpoint ) {
    register_rest_route( 'storedash/v1', $endpoint['path'], $endpoint['args'] );
}
```

Nothing guarantees each endpoint declares a `permission_callback`. When it is absent, WordPress emits a `_doing_it_wrong` notice and **registers the route as public**. The adjacent `/status` route (line 158) sets one correctly, which masks the gap in the loop.

**Why it matters.** A future integration author who copies an endpoint definition and forgets `permission_callback` silently ships an unauthenticated route under `storedash/v1`. This is a privilege-escalation footgun baked into the base class.

**Current exposure.** The only concrete integration (`inc/integrations/posturinn/class-posturinn-integration.php:50`) returns `array()`, so no dynamic route is registered today. Fix is preventive.

**Verify (issue present):**
```bash
# Loop registers caller args verbatim, with no permission_callback default:
sed -n '152,156p' inc/integrations/class-base-integration.php
# Expected: the register_rest_route line passes $endpoint['args'] with no merge/guard.
```

**Recommended fix.** Enforce a default in the loop instead of trusting the caller:
```php
foreach ( $this->get_api_endpoints() as $endpoint ) {
    $args = $endpoint['args'];
    if ( ! isset( $args['permission_callback'] ) ) {
        $args['permission_callback'] = array( $this, 'check_permission' );
    }
    register_rest_route( 'storedash/v1', $endpoint['path'], $args );
}
```
Adjust for the multi-method array shape if an endpoint declares one entry per HTTP method.

**Verify (fixed):** re-run the `sed` above; the loop now assigns a default `permission_callback` before `register_rest_route`. Optionally add a unit assertion that a stub integration returning an endpoint without `permission_callback` gets `check_permission` injected.

---

### 2. Write endpoints validate inside the callback instead of declaring an `args` schema

- **Severity:** WARNING
- **Files:** `inc/Discounts/Route_Registry.php:145,175,190`; `inc/Carts/Route_Registry.php` (POST route); `inc/Carts/Controllers/Carts_Settings_Controller.php:38`

**Issue.** These write routes register with **no `args` schema**. Validation happens inside the controller — e.g. `inc/Discounts/Controllers/Discounts_Sync_Controller.php:134-161` returns well-formed `WP_Error`s with `status` codes (good) — but the schema layer is skipped entirely.

**Why it matters.**
- No automatic `400` for missing/mistyped params; the request reaches the callback unchecked.
- No schema published at `OPTIONS /wp-json/storedash/v1/...`, so the Go consumer (`storedash-sync`) has no machine-readable contract.
- Inconsistent with routes that *do* declare `args`: `inc/api/orders.php:38`, `inc/api/media.php:59`, `inc/api/headless-checkout.php:43`, and `store-config` in `inc/core/class-storedash-auth-handler.php:277`.

**Verify (issue present):**
```bash
# Discounts registers write routes...
rg -n "WP_REST_Server::CREATABLE|WP_REST_Server::DELETABLE" inc/Discounts/Route_Registry.php
# ...but declares no args schema in that file:
rg -n "'args'" inc/Discounts/Route_Registry.php   # expected: no matches
```

**Recommended fix.** Declare `args` with `required`, `type`, and `sanitize_callback`/`validate_callback` on each write route; keep business-rule checks (e.g. percentage ≤ 100) inline in the controller. Example for a discount id path param:
```php
'args' => array(
    'id' => array(
        'required'          => true,
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
    ),
),
```

**Verify (fixed):** `rg -n "'args'" inc/Discounts/Route_Registry.php` now returns the declared schemas; a request missing a required param returns `400` before hitting the callback.

---

### 3. `/recover-cart` rate limiter is a no-op without a persistent object cache

- **Severity:** WARNING
- **File:** `inc/services/cart/Cart_Recovery.php:70-83`

**Issue.**
```php
$attempts = (int) wp_cache_get( $rate_key, 'storedash_rate_limits' );
...
wp_cache_set( $rate_key, $attempts + 1, 'storedash_rate_limits', MINUTE_IN_SECONDS );
```
On a stock WordPress install with no persistent object cache drop-in (Redis/Memcached), `wp_cache_*` is **per-request only**. Each REST request is a fresh PHP process, so `$attempts` is always `0` and the `>= 10` throttle never trips.

**Why it matters.** `/recover-cart` is one of only two public (`__return_true`) routes. It is the single route that genuinely depends on cross-request rate limiting, and on default hosting that limiting silently does nothing. The inline comment ("non-persistent to avoid Redis key bloat") documents the intent but the intent defeats the security control.

**Verify (issue present):**
```bash
sed -n '69,84p' inc/services/cart/Cart_Recovery.php
# Confirm it uses wp_cache_get/wp_cache_set on group 'storedash_rate_limits'.
# On a site without a persistent object cache, wp_using_ext_object_cache() === false,
# so this counter resets every request.
```

**Recommended fix.** Use a store that survives without a persistent object cache — a short-TTL transient works on any host:
```php
$attempts = (int) get_transient( $rate_key );
if ( $attempts >= 10 ) { /* 429 */ }
set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );
```
Or branch on `wp_using_ext_object_cache()` and fall back to a transient when false. Also note the key is `md5( REMOTE_ADDR )`; behind a proxy/CDN all clients may share one IP — acceptable, but add a comment and consider an `X-Forwarded-For`-aware key if the deployment terminates TLS upstream.

**Verify (fixed):** issue 10 rapid `GET /wp-json/storedash/v1/recover-cart?token=x` requests on a site with no object cache; the 11th within a minute returns HTTP `429`.

---

### 4. No `WP_REST_Controller` subclasses; response envelopes vary

- **Severity:** INFO
- **File:** plugin-wide

**Issue.** No route extends `WP_REST_Controller`, so there is no `get_item_schema()`, no schema at `OPTIONS`, and no framework-level arg validation. Response envelopes are mixed: `rest_ensure_response( array(...) )` (orders, media, taxonomies, integrations) vs. bare `new WP_REST_Response(...)` (dropp, auth-handler, Carts controllers). All valid, but an inconsistent contract for `storedash-sync`.

**Note.** The `wp_send_json*` calls the scanner flags in `inc/admin/cart-settings-ajax.php` and `inc/services/optin/class-optin-handler.php` are `admin-ajax`/form handlers, **not** REST callbacks — `wp_send_json` is correct there. No action.

**Verify:**
```bash
rg -n "extends\s+WP_REST_Controller" inc -g '*.php'   # expected: no matches
rg -n "rest_ensure_response|new WP_REST_Response" inc -g '*.php' | grep -v vendor
```

**Recommendation.** Low priority (code works and is authed). The PSR-4 modules already have `Abstract_*_Controller` classes — extending `WP_REST_Controller` there and adding `get_item_schema()` would consolidate response shape and publish a schema. Track as tech-debt, not a release blocker.

---

### 5. `media.php` URL-upload is an SSRF surface → route to security review

- **Severity:** INFO (for this REST review; may be higher under a security review)
- **File:** `inc/api/media.php:64`

**Issue.** The route accepts a URL param (`sanitize_callback => 'esc_url_raw'` plus a `validate_callback`) and the handler fetches it server-side to sideload media. It is `manage_woocommerce`-gated, so exploitation requires an authenticated admin-capable key — but server-side URL fetch is a classic SSRF primitive (internal-IP access, cloud metadata endpoints, redirect abuse).

**Why it's only INFO here.** REST review confirms the route is authed and inputs are sanitized. Whether the *fetch* is safe (allowed hosts/schemes, redirect handling, internal-IP blocking) is a security-review question, out of scope for route/auth/schema review.

**Verify:**
```bash
sed -n '51,90p' inc/api/media.php   # confirm URL param + server-side fetch handler
```

**Recommendation.** Hand to the `wp-security-review` skill for SSRF-specific analysis of the fetch path.

---

## What was checked and found clean

- **Every route has a `permission_callback`.** No route is missing one; the only `__return_true` routes are `/ping` (`inc/api/api.php:297`) and `/recover-cart` (`inc/services/cart/Cart_Recovery.php:59`), both intentional per `CLAUDE.md`.
- **Uniform authorization.** All privileged routes gate on `current_user_can('manage_woocommerce')`, directly or via `Base_Permissions::check_permission()` (`inc/core/class-base-permissions.php:23`).
- **No raw superglobals inside REST callbacks.** `$_GET`/`$_POST`/`$_REQUEST` usages are confined to form/AJAX handlers and cart tracking, all sanitized (`wp_unslash` + `sanitize_*`/`absint`).
- **Safe DB access.** Cart-token lookup uses `$wpdb->prepare(... %s ...)` (`inc/services/cart/Cart_Recovery.php:205-210`).
- **Single namespace.** All routes register under `storedash/v1`; the removed `woodash/v1` REST namespace was not reintroduced.
- **Signed/stateless handoff.** `headless-checkout` uses HMAC signing rather than a public unauthenticated data route (`inc/api/headless-checkout.php:80-88`).

---

## Residual gaps (not findings, but worth tracking)

- **No request-level tests.** `tests/` are standalone unit tests that don't boot WordPress, so no route has HTTP-level coverage. The auth boundary, the `/recover-cart` throttle, and arg validation are exactly what regresses silently. Consider a minimal WP integration or Playground-based smoke suite covering at least the auth boundary.
- **No published schema artifact.** There is no OpenAPI/JSON schema for `storedash-sync` to consume; the contract lives implicitly in the callbacks.

---

## Tracking Checklist

Re-run each finding's **Verify (fixed)** step before ticking.

- [ ] **#1** Integration route loop injects a default `permission_callback` (`class-base-integration.php:154`)
- [ ] **#2** Write routes in Discounts/Carts declare an `args` schema (`Discounts/Route_Registry.php`, `Carts/Route_Registry.php`)
- [ ] **#3** `/recover-cart` rate limiter uses a persistent store / transient (`Cart_Recovery.php:70-83`)
- [ ] **#4** (tech-debt) Migrate PSR-4 module controllers to `WP_REST_Controller` + `get_item_schema()`
- [ ] **#5** SSRF review of `media.php` URL-upload handed to `wp-security-review`
- [ ] **Residual** Add request-level REST tests for the auth boundary and `/recover-cart` throttle

---

*Generated with the `wp-rest-api-development` skill. Line numbers reflect the working tree at audit time; re-anchor with the Verify commands if the code has since moved.*
