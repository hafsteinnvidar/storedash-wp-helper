# Security & Auth Review

The plugin exposes REST endpoints and webhook receivers on a live merchant store. Auth is the single most important dimension. See CLAUDE.md "Auth (security-critical)" for the model.

## Authentication model (verify, don't assume)

- Privileged `storedash/v1` routes authenticate via a **real WooCommerce consumer key** — `determine_current_user` → `wp_woocommerce_api_keys` → `hash_equals`, requiring the `manage_woocommerce` capability. Canonical: `inc/core/class-storedash-auth-handler.php` and the shared `inc/core/class-base-permissions.php`.
- `X-StoreDash-Source` (or any request header) is **NOT** an authorization signal. Flag any route that grants access based on a header value.
- A controller's `permission_callback` must resolve to the capability check — never `'__return_true'` for anything that reads store/PII/order data or mutates state.

## Public routes (the allowlist)

Exactly two routes may be unauthenticated:
- `GET storedash/v1/ping` — onboarding verification, returns no version/PII (`inc/api/api.php`).
- `/recover-cart` — token + rate-limited (`inc/services/cart/Cart_Recovery.php`).

```bash
# Any route bypassing auth — must be ONLY the two above
grep -rn "permission_callback" inc | grep "__return_true"
```

A third `__return_true` route is a **Blocker** until justified. (The old `/recommendations/{id}` public route was removed with the recommendation-engine teardown — do not reintroduce it.)

## Webhooks

- Outbound and inbound webhooks sign/verify with **constant-time HMAC** (`hash_hmac` + `hash_equals`), header `X-WooDash-Signature`. Flag any `==`/`===` comparison of a signature (timing attack) or a missing verification on an inbound receiver.
- The `woodash_webhook` receiver hook and `X-WooDash-Signature` header are a **live stored contract** — don't rename (CLAUDE.md).

## WordPress hygiene (per-file)

- **`ABSPATH` guard** — every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`. Grep for files missing it.
- **Sanitize in** — `sanitize_text_field`, `absint`, `wc_clean`, `rest_sanitize_*` on all request input; typed `args` schemas on `register_rest_route`.
- **Escape out** — `esc_html/esc_attr/esc_url/wp_kses_post` on all output, especially admin screens and widgets (`inc/widgets/`, `inc/admin/`).
- **Nonces** — admin form/AJAX handlers verify `wp_verify_nonce` / `check_ajax_referer` (`inc/admin/cart-settings-ajax.php` is a reference).
- **Prepared SQL** — every `$wpdb` query with a variable uses `$wpdb->prepare`. Flag string-interpolated SQL.
- **Capability checks** — admin actions gate on `current_user_can( 'manage_woocommerce' )` (or narrower).
- **SSRF** — outbound fetches from user-supplied URLs (e.g. media upload-from-url) validate scheme/host and block internal ranges. Known prior gap area — check redirects/DNS-rebinding/IPv6 on any URL fetch.

## Output ranking

- **Blocker** — unauthenticated privileged route, header-based authz, missing/non-constant-time HMAC, unprepared SQL with user input, missing capability check on a mutation.
- **Should-fix** — missing escape on output, missing nonce, missing `ABSPATH` guard, SSRF gap.
- **Nice-to-have** — tighten an over-broad capability, add an `args` schema.
