# REST Controllers & the Load Contract

Two file/class conventions coexist deliberately (CLAUDE.md "Architecture"). Getting a new file into the running plugin depends on which one you use.

## The two conventions

**PSR-4 modules** — `inc/Products/`, `inc/Carts/`, `inc/Discounts/`, `inc/Orders/`:
- PascalCase dirs, **class name matches filename**, autoloaded by `StoreDash_Loader` (a hand-rolled SPL autoloader registered before Composer's).
- `phpcs.xml` excludes these dirs from WP file-naming rules so the autoloader works on case-sensitive filesystems.
- Shape per module: a `*_Manager.php` coordinator, a `Route_Registry.php`, plus `Controllers/` and `Permissions/`. New controllers follow the module's existing `Abstract_*_Controller` base.

**WordPress-style procedural includes** — everything else under `inc/` (`class-*.php`, `api/*.php`, `services/**`):
- Loaded by an explicit `require_once` in `StoreDash_Bootstrap::include_files()` (`inc/core/class-storedash-bootstrap.php`). **It does NOT autoload.**

## The load-contract check (the silent-failure trap)

When a PR adds a non-PSR-4 file:

```bash
# Is the new file wired in?
grep -n "include_files" inc/core/class-storedash-bootstrap.php
grep -n "new-file-name" inc/core/class-storedash-bootstrap.php
```

If a `class-*.php` / `api/*.php` / `services/**` file is added but not `require_once`'d in `include_files()`, it **silently never loads** — no error, endpoints just don't register. Flag it as a **Blocker**. (Conversely, a PSR-4 module file should NOT be manually required.)

`inc/README.md` has a module tour but predates some wiring — treat `include_files()` as the authoritative list of what actually loads.

## REST route review

- All routes register under the single namespace **`storedash/v1`**. New standalone controllers in `inc/api/` (orders, coupons, media, dropp, shipment-tracking, headless-checkout, emails) are self-registering or wired in `include_files()` — confirm both the registration and the include.
- Every `register_rest_route` should declare: `methods`, `callback`, `permission_callback` (see security.md), and an `args` schema with `sanitize_callback`/`validate_callback` for inputs.
- Smoke test: `GET /wp-json/storedash/v1/ping` should return 200; a new route should appear in `GET /wp-json/storedash/v1`.

## Consumer contract (what depends on these endpoints)

The plugin's endpoints and webhook payloads are consumed by **storedash-sync** (Go, `/Users/pineapple/Documents/GitHub/storedash-sync`) and the **woo-dash** app (`/Users/pineapple/Documents/GitHub/woo-dash`). Before changing an endpoint's route, response shape, or a webhook payload:
1. Grep the consumer repos for the route/field name.
2. A response-shape or field-name change is a **cross-repo breaking change** — call out the blast radius; don't treat it as a local edit.
3. woo-dash asserts the waitlist handler emits `'source' => 'waitlist_widget'` (`inc/services/waitlist/class-waitlist-handler.php`) — a cross-repo string contract.
