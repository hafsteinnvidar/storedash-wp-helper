---
name: helper-xray
description: Deep module-level audit of the StoreDash helper WordPress/WooCommerce plugin — exhaustive per-file examination of a plugin module (a PSR-4 module like inc/Discounts/, or a subsystem like inc/services/cart/, or the whole plugin) for bugs, cross-file contract breaks, HPOS/CRUD violations, security gaps, dead code, and load-wiring mistakes, backed by cross-consumer verification against storedash-sync and woo-dash and an adversarial cross-audit pass. Use when the user says "helper-xray", "xray the plugin", "audit this plugin module", "deep audit the cart module", "xray inc/Discounts", or wants exhaustive review of a whole plugin subsystem. For a lighter pre-release conventions checklist use helper-review; for a single file, just audit it directly.
---

# Helper X-Ray: Deep Plugin-Module Audit

Exhaustive, evidence-based audit of a StoreDash plugin module. Single-file review can't see systemic issues — "three controllers all skip the capability check" or "the manager registers a hook the service never fires" only emerge with the whole module in context.

**Zero false positives, not maximum findings.** Every finding names a file:line, shows the failing path, and survives the cross-audit in [VERIFICATION.md](VERIFICATION.md).

## Invocation

User names a target. Map it:
- A **PSR-4 module** → `inc/Products|Carts|Discounts|Orders/`
- A **subsystem** → `inc/services/<x>/` (cart, webhooks, waitlist, optin, enquiry, …), `inc/api/`, `inc/integrations/`, `inc/widgets/`, `inc/modules/`
- **"whole plugin"** → scope by subsystem, one module at a time (don't try to hold all of `inc/` at once)

No clear match → list `inc/` subdirs and ask.

## Workflow

### Step 0: Load context

Read `CLAUDE.md` (boot order, dual loader, `woodash_*` contract, auth model). List the target's files:
```bash
find inc/<target> -name '*.php' | sort
grep -n "<target-file>" inc/core/class-storedash-bootstrap.php   # how each file is loaded
```
Never re-flag an intentional pattern documented in CLAUDE.md.

### Step 1: Two-pass scan

Large modules have many files. See [SCANNING.md](SCANNING.md).
- **Pass 1 (shallow):** every file's first ~40 lines — build the module map: classes, hooks registered, routes registered, DB tables/options/meta touched, load path (autoload vs `include_files()`), and the hot-spot list.
- **Pass 2 (deep):** read hot spots fully — Managers, Route_Registries, Controllers, Permissions, the `services/**` logic, anything with SQL, HMAC, capability checks, or WC order access.

### Step 2: Contracts & consumers (the plugin's "DB verification")

There is no local app DB to query — the plugin's contracts live in **WordPress state**, in **sibling repos**, and in the **live Supabase DB** the data eventually lands in. Full method + exact MCP tool names + availability caveats in [references/environment.md](references/environment.md). Verify:
- **WordPress data**: tables (`storedash_get_cart_table_name()` / `woodash_carts`), options (`woodash_*`/`storedash_*`), meta keys (`_woodash_*`), schema version behind `maybe_create_tables()`. Confirm claimed reads/writes match the activator's schema.
- **WooCommerce shapes**: any WC REST/CRUD/Store-API call — verify against **context7 / developer.woocommerce.com** (live, not memory). Respect the PHP 7.4 / WC 6.0 floor.
- **Cross-consumer**: every `storedash/v1` route and webhook payload is consumed by **storedash-sync** (`/Users/pineapple/Documents/GitHub/storedash-sync`, Go) and the **woo-dash** app (`/Users/pineapple/Documents/GitHub/woo-dash`, e.g. `src/lib/api/woocommerce/` + the consuming features). Grep the consumer for each route/field before flagging; flag any route/response/payload change as a cross-repo breaking change with named blast radius.
- **End-to-end landing** (optional): the chain is plugin → sync → Supabase. Use `mcp__supabase-woo-dash__query` (read-only) to confirm a plugin-emitted value actually lands in the expected table/column. Mark "unverified — no DB access" if the MCP server isn't connected; never fabricate.

### Step 3: Per-file audit

For each deep-read file: bugs & logic errors, HPOS-unsafe order access (see helper-review `references/hpos.md`), auth/security gaps (`references/security.md`), load-wiring mistakes (`references/rest-controllers.md`), PHP 8.0+ syntax against the 7.4 floor, missing `ABSPATH`/nonce/escape/prepare, and dead code (grep to prove unused).

### Step 4: Cross-file analysis (the unique value)

Patterns only visible across the module:
- **Registered-but-never-fired** hooks/actions, or fired-but-never-registered.
- **Inconsistent auth** — some controllers gate on the capability, others don't.
- **Schema drift** — activator creates a column/option nothing reads, or code reads meta the activator never writes.
- **Duplication** — same helper logic re-implemented across files (report once).
- **Load gaps** — a `class-*.php` not wired into `include_files()`.

### Step 5: Verification

Apply the evidence filter, then spawn the adversarial cross-audit agent. See [VERIFICATION.md](VERIFICATION.md).

### Step 6: Output

Inline + optional disk. See [OUTPUT-TEMPLATES.md](OUTPUT-TEMPLATES.md). Header MUST include the **Consumer surface** line (which `storedash/v1` routes / webhooks the module exposes and which consumer repos were checked) and the **Load path** line (autoload vs `include_files()`), or the audit is incomplete.

## Do NOT

- Raise the PHP/WC floor or add `strict_types` as a "fix" — 7.4 support is deliberate.
- Re-flag the dual loader, the two public routes, or the `woodash_*` stored contract — intentional (CLAUDE.md).
- Assert WC/WP versions or API deprecations from memory — use context7.
- Invent hypothetical bugs, or report a WC-shape "bug" without citing live docs.
- "No issues found" is a valid result. Don't manufacture findings.
