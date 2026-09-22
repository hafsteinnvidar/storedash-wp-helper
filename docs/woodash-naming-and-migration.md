# The `woodash_*` naming — why it's still here, and how to finish the rename

_Last verified: 2026-07-01, against the live `woo-dash` app, the `storedash-sync` Go
service, `storedash-worker`, and the "Storedash" Supabase project._

## TL;DR

This plugin was renamed from **woodash → storedash**. Everything *public and active* was
renamed (REST namespace `storedash/v1`, `StoreDash_` class prefix, `storedash` text domain,
`X-StoreDash-*` identity headers). What still says `woodash` is **not laziness** — it is the
surface that is either:

1. **Persisted on live merchant WordPress installs** (a DB table, `wp_options` keys, `_meta`
   keys, WP-Cron hook names), where a rename is a *data migration*, not a find-replace; or
2. **Verified in production by another repo** (the `X-WooDash-Signature` webhook HMAC header,
   read by `storedash-sync`), where a rename is a *coordinated cross-repo deploy*.

Renaming any of these carelessly silently breaks existing stores. This document lists every
remaining `woodash` surface, its risk tier, its verified consumer, and the exact steps to
retire it safely.

> **Note on where the state lives.** The `woodash_*` state does **not** live in Supabase. It
> lives in each merchant's **WordPress** database (`wp_options`, `wp_postmeta`, the
> `woodash_carts` table). The Supabase app DB is already 100% storedash-named (verified: zero
> `woodash%` columns). That asymmetry is the whole reason this is hard — we don't control the
> merchant WP databases, only the code that reads/writes them.

## What has already been cleaned up

- The old `woodash/v1` REST namespace — **removed**. Only `storedash/v1` is registered.
- Identity headers — migrated to `X-StoreDash-Service` / `X-StoreDash-Version`.
- Class prefixes, text domain, constants (`STOREDASH_*` are canonical; `WOODASH_HELPER_*` are
  now just aliases).
- **Cosmetic branding** (this pass, 2026-07): user-facing labels ("Storedash Store ID"), admin
  notices, order notes, WC-log message prefixes, the `.storedash-marketing-optin` CSS class,
  and comments/docblocks across the integration + cart + API files. These carry no contract and
  were safe to change immediately.

## The remaining `woodash` surface, by risk tier

### Tier 1 — Hard wire contract (cross-repo, live in production)

| Surface | Where (plugin) | Verified consumer | Blast radius if renamed |
|---|---|---|---|
| **`X-WooDash-Signature`** header | Sent by `Cart_Tracking.php`, `services/optin/*`, `services/enquiry/*`, `services/waitlist/*` | **`storedash-sync`** reads `r.Header.Get("X-WooDash-Signature")` in `internal/webhooks/{cart,waitlist,enquiry,marketing_optin}.go` and verifies HMAC-SHA256 | **Every outbound webhook fails signature verification** at the sync service. Total loss of cart/waitlist/enquiry/opt-in events. |

This is the single most load-bearing string in the plugin. It cannot be changed from one side.

### Tier 2 — Stateful, on live merchant installs (rename = DB migration)

No consumer references these by name (the app pushes *values*, never *key names*), but they are
**persisted** on every existing install. Renaming without a migration orphans that data.

| Surface | Where | What breaks on a blind rename |
|---|---|---|
| **`woodash_carts` table** | `storedash_get_cart_table_name()` (auto-detects `woodash_carts` vs `storedash_carts`), `Cart_Tracking.php`, `Cart_Batch_Sync.php` | Cart tracking data orphaned; abandoned-cart recovery stops seeing history. |
| **`woodash_*` options** (`woodash_store_id`, `woodash_webhook_secret`, `woodash_cart_webhook_url`, `woodash_checkout_optin_enabled`, `woodash_cart_*`, `woodash_db_version`, `woodash_webhooks_setup`, …) | `admin/class-storedash-admin.php`, `api/api.php`, cart services | Store appears **disconnected** (lost `store_id` + webhook secret) until re-onboarded. |
| **`_woodash_*` meta** (`_woodash_managed`, `_woodash_cart_token`, `_woodash_marketing_optin`, `_woodash_customer_email_opt_out`, `_woodash_pending_recovery`, `_woodash_cart_recovered`, …) | `services/cart/*`, `services/optin/*`, `integrations/posturinn/*` | In-flight carts, opt-in flags, and Posturinn "managed order" markers lost on existing orders. |
| **WP-Cron hooks** `woodash_check_abandoned_carts` (schedule `woodash_ten_minutes`), `woodash_force_check_abandoned_carts` | `class-storedash-activator.php`, `class-storedash-deactivator.php`, `Cart_Tracking.php` | The scheduled event is stored in `wp_options.cron` under the old hook name; a rename orphans it until the plugin is reactivated. |
| **`'WooDash - %'`** WC-webhook-name match | `api/api.php:404` (`SELECT COUNT(*) ... WHERE name LIKE 'WooDash - %'`) | The system-info webhook count reads 0 on stores whose WC webhooks were created as `WooDash - …`. |

### Tier 3 — Ecosystem-internal extension points (low external surface)

| Surface | Where | Risk |
|---|---|---|
| **`woocommerce_api_woodash_webhook`** inbound hook | `services/webhooks/webhooks.php` | Legacy `/wc-api/woodash_webhook` URL. **No caller found** in any repo — likely dead, but it is a URL contract, so leave until confirmed unused via access logs. |
| **`woodash_*` action/filter hooks** (`woodash_register_integrations`, `woodash_cart_recovery_allowed_url_params`, `woodash_synced_cart`, `woodash_webhook_failed`, `woodash_recover_cart_url`, `woodash_cart_tracking_enabled`) | across services | A merchant/site snippet could hook these. Rename breaks their customization silently. |

### Tier 4 — Pure code, no state, no wire (safe to rename anytime)

| Surface | Where | Notes |
|---|---|---|
| **`WOODASH_HELPER_VERSION/PATH/URL`** constants | `storedash.php`, referenced internally | Already aliases of `STOREDASH_*`. Kept only as a courtesy for third-party snippets that referenced them. |
| **`Woodash\` autoloader fallback** | `class-storedash-loader.php` | No plugin class uses the `Woodash\` namespace. Almost certainly dead. |

## Contracts we still send `X-StoreDash-*` vs `X-WooDash-*` — the split explained

The ecosystem already migrated *identity* headers (`X-StoreDash-Service`, `X-StoreDash-Version`)
but deliberately kept the *signature* header (`X-WooDash-Signature`). There is no technical
reason they must differ — it's simply that the signature header requires the two-sided dance
below and hasn't been done yet.

## How to finish the rename — a safe, staged plan

The order matters. Do Tier 4 first (free), then Tier 2 (migration-gated), then Tier 1 (cross-repo).

### Step 0 — Prerequisite: a real upgrade routine

`StoreDash_Bootstrap::maybe_create_tables()` already re-runs the activator when
`storedash_db_schema_version` is behind the hardcoded current version. Use that same mechanism
to run **one-time, idempotent migrations**. Every Tier-2 rename below is a migration keyed to a
schema-version bump. Never rename in place without one.

### Step 1 — Tier 4 (no risk, do now if desired)

- Drop the `Woodash\` fallback branch in `class-storedash-loader.php` (grep the ecosystem for
  any `Woodash\`-namespaced class first; expected: none).
- Optionally remove the `WOODASH_HELPER_*` constant aliases once you've grep'd all sibling repos
  and any merchant snippets you control. Low value; low risk.

### Step 2 — Tier 2 options + meta + table (migration-gated)

For each, ship a migration that **copies old → new, then reads new with an old-key fallback**:

1. **Options.** Add a `storedash_get_option($key)` helper that reads `storedash_$key` and falls
   back to `woodash_$key`. Migration: `for each woodash_* option: add_option('storedash_*', get_option('woodash_*'))`. Flip all writers to the new key. Keep the read-fallback for ≥2 releases, then delete the old options in a later migration.
2. **Meta.** Same pattern with a `_storedash_*` prefix. Migrate with a batched
   `UPDATE wp_postmeta SET meta_key = REPLACE(meta_key,'_woodash_','_storedash_')` (chunked, HPOS-aware — also update the orders meta table if HPOS is active). Because sync does **not** read these, this is plugin-only and safe once migrated.
3. **Table.** `storedash_get_cart_table_name()` already auto-detects. To converge: on upgrade,
   if only `woodash_carts` exists, `RENAME TABLE woodash_carts TO storedash_carts` and update the
   stored schema version. The helper keeps working throughout.
4. **Cron.** On upgrade: `wp_clear_scheduled_hook('woodash_check_abandoned_carts')` then
   `wp_schedule_event(..., 'storedash_check_abandoned_carts')`, and rename the custom schedule
   `woodash_ten_minutes → storedash_ten_minutes`. Update `activator`/`deactivator`/`Cart_Tracking`.
5. **`'WooDash - %'` webhook-name match.** Have the app create *new* webhooks as `Storedash - …`
   going forward, and change the LIKE to `('WooDash - %' OR 'Storedash - %')` during transition;
   drop the old branch once old webhooks are re-provisioned.

### Step 3 — Tier 3 extension hooks (deprecation cycle)

Fire **both** the old and new hook names for ≥2 releases:
`do_action('storedash_synced_cart', …); do_action('woodash_synced_cart', …); // deprecated`.
Announce in the changelog; remove the `woodash_*` aliases after the deprecation window.

### Step 4 — Tier 1 `X-WooDash-Signature` (cross-repo, do last)

This requires a coordinated deploy with `storedash-sync`, in this order:

1. **sync first:** make each `internal/webhooks/*.go` handler accept **either**
   `X-StoreDash-Signature` or `X-WooDash-Signature`. Deploy. (Now both work.)
2. **plugin next:** flip the outbound header to `X-StoreDash-Signature`, version-gated. Release
   and wait until merchant installs have updated (track via plugin version telemetry).
3. **sync last:** once effectively all stores send the new header, drop the `X-WooDash-Signature`
   acceptance from sync. Deploy.
4. Same treatment for the inbound `woocommerce_api_woodash_webhook` hook *if* access logs show
   it's still hit; otherwise just remove it.

Never do step 2 before step 1, or webhooks break for every not-yet-updated store.

## Guardrails / don't-do list

- ❌ Do **not** blind find-replace `woodash` → `storedash` across the repo. It corrupts
  `X-WooDash-Signature`, the `'WooDash - %'` match, and `strpos($auth_header, 'WooDash')` request
  detection in `integrations/posturinn/class-posturinn-integration.php`.
- ❌ Do **not** rename any Tier-2 stored key without a paired migration + read-fallback.
- ❌ Do **not** touch `X-WooDash-Signature` in the plugin before `storedash-sync` accepts both.
- ✅ Every rename gets a `storedash_db_schema_version` bump and an idempotent migration.
- ✅ Keep read-fallbacks for ≥2 releases before deleting the old names.
