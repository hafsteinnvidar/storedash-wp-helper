# External Resources — Main App, Live DB, Live Docs

The plugin doesn't stand alone. These four resources let an audit verify *end-to-end* contracts instead of guessing. **All are optional enrichers** — if one isn't available in the current session, say so in the output ("unverified — {resource} unavailable") and never fabricate the result.

## 1. woo-dash (the main app) — sibling repo

**Path:** `/Users/pineapple/Documents/GitHub/woo-dash` (Next.js/TypeScript).
The primary consumer of the plugin. Grep it to confirm how the app calls this plugin and what shapes it expects:
- `src/lib/api/woocommerce/` — the request layer that hits `storedash/v1/*` (`core/server-request.ts`, `upload-media-from-url.ts`). Confirms the error-envelope contract (`{ success:false, data.message }`) and timeout expectations.
- Features that depend on plugin endpoints — `src/features/{discounts,categorization,shipments,coupons,waitlist,carts,orders}/` (their `CONTEXT.md`/`README.md` name the `storedash/v1` routes they use).
- `src/app/api/plugin/download/route.ts` + `scripts/build-plugin-zip.ts` — how the app distributes this plugin (built from this repo's `build-zip.sh`).
- `docs/woo-api/` — the app's offline mirror of the official WooCommerce REST API spec; useful to cross-check field/param shapes.

**Before changing any endpoint route, response shape, or webhook payload:** grep woo-dash for the route/field name and report the blast radius. A shape change here is a cross-repo breaking change.

## 2. storedash-sync (the ingest engine) — sibling repo

**Path:** `/Users/pineapple/Documents/GitHub/storedash-sync` (Go).
Calls the plugin's endpoints and receives its webhooks, then writes into Supabase. Grep `internal/webhooks/*.go` and `internal/sync/*.go` for the route/payload the module under audit exposes. This is the code that turns the plugin's output into DB rows.

## 3. Live Supabase DB — via MCP (end-to-end landing check)

The plugin writes the **merchant's WordPress DB**; `storedash-sync` mirrors that into **Supabase**. So to prove a plugin-emitted value actually lands correctly, query the live Supabase DB:

- Tool: `mcp__supabase-woo-dash__query` (may appear namespaced, e.g. `mcp__plugin_supabase_supabase__execute_sql`) — **read-only SELECTs only**.
- Use it to confirm the downstream shape, e.g. a waitlist signal the plugin emits lands in `product_waitlist` with the expected `source`; a cart the plugin tracks appears in the carts table; an order status the plugin registers shows up on synced orders.
- The chain is **plugin → sync → Supabase** — a mismatch could be the plugin OR sync; note which side you can actually attribute it to. Don't claim the plugin is wrong from a DB row alone without tracing sync.

If the Supabase MCP server isn't connected in this session, skip DB verification and mark those claims "unverified (no DB access)".

## 4. context7 — live WooCommerce / WordPress docs

Never assert WC/WP version numbers, HPOS defaults, API signatures, or deprecations from training data — they move. Confirm via context7:
- `mcp__context7__resolve-library-id` → `woocommerce` / `wordpress` (tool may be namespaced `mcp__plugin_context7_context7__*`).
- `mcp__context7__query-docs` for the specific API (order CRUD, `WC_Order_Query`, `FeaturesUtil`, Store API, REST controller base classes).
- Canonical source: `developer.woocommerce.com/docs`.
- The plugin's own **minimum floor is authoritative** and separate from "latest": `Requires PHP: 7.4`, `WC requires at least: 6.0.0` (read `storedash.php`). Don't recommend an API newer than the floor without guarding it.

## Availability rule (degrade honestly)

| Resource | If absent |
|----------|-----------|
| woo-dash / storedash-sync repo not on disk | Mark cross-consumer claims "unverified — sibling repo not cloned"; don't guess the consumer's behavior. |
| Supabase MCP not connected | Mark DB-landing claims "unverified — no DB access". |
| context7 not connected | State WC/WP facts as "needs live-doc confirmation"; do NOT fill from memory. |

An honest "unverified" is a valid finding state. A fabricated confirmation is a failed audit.
