# Two-Pass Module Scan

## Pass 1: Shallow scan (build the map)

Read every file in the target **shallowly** — first ~40 lines (the `if (!defined('ABSPATH'))` guard, class declaration, hook registrations, `register_rest_route` calls, `require`/`use` lines).

Build:

1. **Module map** — every class/file, what each is responsible for, and how they connect (`*_Manager` → `Route_Registry` → `Controllers/` → `Permissions/`; or procedural `class-*.php` wired by Bootstrap).
2. **Load path** — for each file: PSR-4 autoloaded, or `require_once`'d in `StoreDash_Bootstrap::include_files()`? (Grep Bootstrap.) Note any file that is *neither* — that's a silent-load bug.
3. **Hook/route surface** — every `add_action`/`add_filter`/`register_rest_route`/`register_post_status` the module registers, with its namespace/priority.
4. **State footprint** — every DB table (`$wpdb`, cart table helper), option (`get/update_option`), meta key, and transient the module reads or writes.
5. **WooCommerce surface** — every order/product/coupon CRUD call, Store API touch, and WC hook.
6. **Consumer surface** — every `storedash/v1` route and outbound webhook the module exposes (these are the cross-repo contracts).
7. **Hot-spot list** — files with SQL, HMAC, capability checks, order access, large classes, or many responsibilities.

## Pass 2: Deep read (audit the hot spots)

Read fully:
- The module's `*_Manager.php` and `Route_Registry.php` (wiring/coordination — where "registered but never fired" hides).
- All `Controllers/` and `Permissions/` (auth correctness).
- All `services/**` logic files (where bugs live).
- Anything touching `$wpdb`, `hash_hmac`, `wc_get_order*`/order meta, or capability checks.
- The activator schema (`inc/core/class-storedash-activator.php`) when the module claims table/column reads/writes — to catch schema drift.

For each deep-read file check: bugs, HPOS-unsafe order access, auth/security gaps, load wiring, PHP 7.4-floor violations, WP hygiene (ABSPATH/nonce/escape/prepare), dead code.

## Practical

- A module of ≤ ~8 files: deep-read all of them, skip the shallow pass.
- Whole-plugin request: scope by subsystem and run the two passes per subsystem; never hold all of `inc/` at once.
