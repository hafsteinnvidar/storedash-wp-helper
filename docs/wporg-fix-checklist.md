# StoreDash Helper — WordPress.org Submission Fix Checklist (2026-07)

Implementation playbook for making this plugin WordPress.org-submittable. Companion to the strategy in
`wporg-submission-plan.md` and the security work in `security-audit-2026-07.md`.

**Every item below is a self-contained work unit.** An agent (or engineer) can pick any unblocked item,
follow its steps, satisfy its acceptance criteria, and move on. Do items in priority order (P0 → P3).

---

## How to use this document

For each item:
1. Read **Goal / Why / Where**.
2. Follow **Steps** exactly. File:line references were captured 2026-07 and may have drifted a few
   lines — re-run the item's `grep`/`phpcs` locator command to get current positions before editing.
3. Satisfy **Acceptance**.
4. Run **Verify** (always: `php -l` on touched files + `composer test`; category-specific checks noted).
5. Tick the box in the master checklist and note the commit.

### Global rules (apply to ALL items)
- **PHP 7.4 floor.** No PHP 8+ syntax. Keep every file's `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- **Do NOT rename the `woodash_*` stored contract** (table `woodash_carts`, `woodash_*` options,
  `_woodash_*` meta, `woodash_webhook` hook, `X-WooDash-Signature` header). See CLAUDE.md.
- **Brand casing: write `Storedash` (lowercase d) in ALL prose/user-facing text** — readme.txt, plugin
  display name, descriptions, admin notices, translatable strings. NEVER change code identifiers:
  class prefix `StoreDash_`, namespace `StoreDash\`, constants `STOREDASH_*`, text domain `storedash`
  stay exactly as they are (they are contracts). See item N.
- **One category per commit** — reviewable, revertable. Run `composer test` (23 tests) after each.
- **`phpcs:ignore` discipline:** every ignore is inline and carries `-- <reason>`. Never a blanket
  file-level or directory-level disable of a security sniff.
- **Never change logic to satisfy a linter.** A cosmetic sniff → cosmetic edit. A security sniff →
  either a real fix or a *justified* ignore after confirming safety. If unsure whether a flagged item
  is safe, STOP and escalate rather than ignoring it.
- Work is not "done" until `composer test` passes and `php -l` is clean on every touched file.

---

## Master checklist (priority order)

| ID | Done | Prio | Item | Type | Effort |
|----|------|------|------|------|--------|
| N  | ✅ | P0 | Brand casing sweep: `StoreDash` → `Storedash` in user-facing text | Branding | 45m |
| A  | ✅ | P0 | `readme.txt` (WP.org format) | Packaging blocker | 1.5h |
| B  | ✅ | P0 | Plugin header completeness | Packaging blocker | 20m |
| C  | ✅ | P0 | Build-zip hygiene (no dev files shipped) | Packaging blocker | 45m |
| D  | ✅ | P0 | Genuine input sanitization (`wp_unslash`/sanitize) | Security | 2h |
| E  | ✅ | P1 | `phpcs.xml` policy (custom caps, PHP floor, Docs) | Config | 1h |
| F  | ✅ | P1 | i18n translator comments | i18n | 30m |
| G  | ✅ | P1 | Remove `@` silenced errors | Security-adjacent | 20m |
| H  | ✅ | P1 | WP alternative functions (`wp_json_encode`, `wp_parse_url`) | Correctness | 45m |
| I  | ☐ | P2 | Justified `phpcs:ignore` for audited-safe DB/nonce/escape | Annotation | 2.5h |
| J  | ☐ | P2 | `composer phpcbf` auto-fix (144 mechanical) | Cosmetic (safe) | 20m |
| K  | ☐ | P2 | Run official Plugin Check in WP env; clear its errors | Gate | 1h |
| L  | ☐ | P3 | *(optional)* `WordPress-Docs` cosmetic cleanup (~1,100) | Cosmetic | 4-6h |
| M  | ☐ | P3 | CI gate (`phpcs` + `plugin-check` + tests on PR) | Infra | 1h |

### Progress log (2026-07-01)
- **N ✅** committed `f1a672b` "Branding" — 25 user-facing strings + `Plugin Name:` header → `Storedash`; verified no code identifiers/contracts touched, 23 tests green.
- **A ✅** committed `dd34324` "Add readme.txt" — WP.org-format readme; `Stable tag` 1.2.4 == plugin `Version`; external services disclosed from real code; removed a bogus "sync analytics" service (was an inbound CORS origin, not an outbound send). **Human TODOs before submit:** `Contributors:` (real wporg username) + `Tested up to:` (confirm highest WP major tested).
- **B ✅** *(done in working tree, uncommitted)* — `storedash.php` header completed: `Plugin Name: Storedash Helper`, added `Plugin URI`, `Domain Path: /languages`, normalized `License: GPLv2 or later`; all fields consistent with readme.txt; 23 tests green.
- **C ✅** *(done in working tree, uncommitted)* — `scripts/build-zip.sh` now ships runtime only: rsync excludes `docs/`, `node_modules/`, `*.md` (+ existing dotfile/`tests/`/`scripts/`/`phpcs.xml`/`phpunit.xml.dist`), and a staged `composer install --no-dev --optimize-autoloader` prunes dev packages (phpunit/phpcs/wpcs/`vendor/bin`), leaving only the production autoloader. Verified: dev-file grep on `dist/storedash.zip` empty, runtime intact, single top-level `storedash/`, committed `vendor/` untouched. Build/release process documented in `CLAUDE.md` → "Building the distributable zip".
- **Watch item (out of scope):** `composer build-zip` emits a benign "lock file is not up to date with composer.json" warning — pre-existing repo drift between committed `composer.json` and `composer.lock`; harmless (no runtime deps to resolve). Fix with `composer update --lock` when convenient.
- **D ✅** *(done in working tree, uncommitted)* — `ValidatedSanitizedInput` now 0 hits. Wrapped real `$_SERVER`/`$_POST` reads with `sanitize_text_field( wp_unslash( … ) )` (webhooks.php ×6, class-storedash-admin.php ×5, Discounts_Manager.php ×1) + `wp_unslash()` on 2 condition-only `is_email()` reads (Cart_Data, Cart_Tracking). 5 justified inline ignores for sanitizer-behind-decode patterns (auth-handler `esc_url_raw(urldecode())` ×3, helpers deferred type-switch ×1, Cart_Recovery `wc_clean(rawurldecode())` ×1). **HMAC verified safe:** webhooks signature is compared-only over raw `php://input`; `wp_unslash` is a no-op on base64 — no sign≠verify divergence. 23 tests green. Preexisting NonceVerification ignores preserved.
- **P0 tier COMPLETE (N, A, B, C, D).**
- **E ✅** *(done in working tree, uncommitted)* — `phpcs.xml` config-only: declared custom caps (`manage_woocommerce`, `edit_shop_orders`, `edit_others_shop_orders`) → `Capabilities.Unknown` 35→0; `testVersion` 7.0-→7.4-; demoted `InlineComment.InvalidEndChar` + `FunctionComment.ParamCommentFullStop` to `<severity>3</severity>` (warnings, not deleted). Total **1594/297 → 517/262 errors/warnings**. Zero PHP touched; 23 tests green.
- **F ✅** *(done in working tree, uncommitted)* — added `/* translators: */` above 6 placeholder translations (Carts_Recovery_Controller, orders.php, class-storedash-admin.php ×3, class-custom-order-statuses.php — the last moved the comment inside `esc_html()` to sit directly above `_n()`). `MissingTranslatorsComment` 0; diff comments-only; 23 tests green.
- **G ✅ + H ✅** *(done together in working tree, uncommitted)* — G: all 3 `@`-silenced calls are best-effort temp-file `@unlink` cleanup (media.php:397, Brands_Controller.php:626/635); kept with justified combined ignores (these lines trip both G and H). H: real swaps `parse_url→wp_parse_url` (media.php ×2, flags PHP_URL_HOST/PATH preserved) + `json_encode→wp_json_encode` (Cart_Data.php:255 internal md5 hash key, webhooks.php:193 error_log fallback) — both verified NOT signed/transmitted, no SEC-3 HMAC divergence. SEC-9 filesystem hits in class-storedash-helpers.php kept with justified ignores (deferred). Both sniffs 0; 23 tests green; diff is swaps + reasoned ignores only.
- **P1 tier COMPLETE (E, F, G, H).** Next: **I** (justified `phpcs:ignore` for audited-safe DB/nonce/escape buckets — the bulk of the remaining 517 errors), then J (`phpcbf` auto-fix), K (official Plugin Check in a WP env). Resume here.

Recommended target = **through K** (skip L unless you want a pristine full-phpcs tree).

---

# P0 — Submission blockers (a reviewer rejects without these)

## N — Brand casing sweep: `StoreDash` → `Storedash` in user-facing text
**Goal.** All human-readable brand text reads `Storedash` (lowercase d). Code identifiers are untouched.
**Why.** Brand styling requirement; must be consistent before the listing and its readme go public.
**Where (locate).** User-facing occurrences only:
- Translatable strings (~22): `grep -rEn "(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x)\( *['\"][^'\"]*StoreDash" inc/`
  — e.g. `inc/core/class-storedash-requirements.php`, `inc/core/class-storedash-bootstrap.php`,
  `inc/admin/class-storedash-admin.php`, `inc/Discounts/Blocks/Store_API_Integration.php`.
- Plugin `Plugin Name:` header in `storedash.php` (display name shown in wp-admin).
- The future `readme.txt` (item A) and any user-facing JS strings in `assets/js/`.
**Steps.**
1. Replace `StoreDash` → `Storedash` ONLY inside quoted user-facing strings and the `Plugin Name:`
   header value. Do a targeted review of each match — do NOT run a blanket find/replace.
2. **Explicitly DO NOT change:** class names / prefix `StoreDash_`, namespace `StoreDash\`, constants
   `STOREDASH_*`, text domain `'storedash'`, file names, `X-StoreDash-Source` header (that is a wire
   contract with the sync service — verify with storedash-sync before touching), or any comment/code
   that isn't shown to a user.
3. When translated strings change, note that `.pot`/translations may need regenerating (item related to
   i18n / packaging).
**Acceptance.** No `StoreDash` (capital D) remains inside a translatable string or the `Plugin Name:`
header. Code identifiers (`StoreDash_*`, `STOREDASH_*`, `StoreDash\`) unchanged. `composer test` passes.
**Verify.** `grep -rEn "(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x)\( *['\"][^'\"]*StoreDash" inc/` → empty;
`grep -n "Plugin Name:" storedash.php` shows `Storedash`; `grep -rn "class StoreDash_\|STOREDASH_\|namespace StoreDash" inc/ | head` still present (unchanged).

## A — `readme.txt` in WordPress.org format
**Goal.** Ship a valid `readme.txt` at the plugin root. Its absence or malformed headers is the single
most common first-submission rejection, and Plugin Check parses it.
**Where.** New file: repo root `readme.txt` (NOT `README.md`).
**Steps.**
1. Create `readme.txt` with the required header block and sections:
   ```
   === Storedash Helper ===
   Contributors: <wporg-username(s)>
   Tags: woocommerce, cart, webhooks, abandoned-cart, waitlist
   Requires at least: 5.8
   Tested up to: <current WP major, e.g. 6.6>
   Requires PHP: 7.4
   Stable tag: <MATCHES Version: in storedash.php EXACTLY>
   License: GPLv2 or later
   License URI: https://www.gnu.org/licenses/gpl-2.0.html

   Short description (<= 150 chars).

   == Description ==
   ...
   == Installation ==
   ...
   == Frequently Asked Questions ==
   ...
   == Changelog ==
   = <version> =
   * ...
   == Upgrade Notice ==
   ```
2. `Stable tag` MUST equal the `Version:` header in `storedash.php`. Confirm they match.
3. Disclose the outbound webhook / external-service behavior in the Description (WP.org requires
   disclosing any data sent off-site). List the endpoints the plugin talks to and that they are
   user-configured.
**Acceptance.** `readme.txt` exists, parses (all required headers present), `Stable tag` == plugin
`Version`, external services disclosed.
**Verify.** Paste into https://wordpress.org/plugins/developers/readme-validator/ (or Plugin Check in
item K). `grep -E "Stable tag|Requires PHP|Tested up to" readme.txt`.

## B — Plugin header completeness
**Goal.** `storedash.php` header has every field WP.org expects, consistent with `readme.txt`.
**Where.** `storedash.php` top doc-block.
**Steps.**
1. Ensure the header includes: `Plugin Name`, `Plugin URI`, `Description`, `Version`, `Requires at
   least`, `Requires PHP`, `Author`, `License: GPLv2 or later`, `License URI`, `Text Domain: storedash`,
   `Domain Path` (if `/languages`).
2. `Requires PHP: 7.4` and `Requires at least: 5.8` must match `readme.txt` and CLAUDE.md.
**Acceptance.** All fields present and consistent with `readme.txt`.
**Verify.** `grep -nE "Requires PHP|Requires at least|Text Domain|License" storedash.php`.

## C — Build-zip hygiene (do not ship dev files)
**Goal.** The distributed zip contains only runtime code — no `tests/`, `docs/`, `.github/`,
`phpcs.xml`, `composer.*`, `.git*`, node/dev deps, or this checklist.
**Where.** `scripts/build-zip.sh` (invoked by `composer build-zip`).
**Steps.**
1. Read `scripts/build-zip.sh`; confirm its include/exclude list drops: `tests/`, `docs/`, `.github/`,
   `.git`, `phpcs.xml`, `phpunit.xml*`, `composer.json/lock`, `node_modules/`, `*.md`, `scripts/`,
   dev-only `vendor/` packages.
2. Keep the committed runtime `vendor/` (production autoload) but exclude dev tooling
   (`vendor/bin`, phpcs/phpunit/wpcs) from the zip — or run `composer install --no-dev` into a build
   dir before zipping.
3. Build and inspect: `composer build-zip` then `unzip -l dist/storedash.zip` — verify no dev files.
**Acceptance.** `unzip -l dist/storedash.zip` shows runtime PHP + assets + production vendor only.
**Verify.** `composer build-zip && unzip -l dist/storedash.zip | grep -E "tests/|docs/|phpcs|composer\.|\.github|phpunit"` → empty.

## D — Genuine input sanitization (`wp_unslash` + sanitize)
**Goal.** Every superglobal read is `wp_unslash()`-ed and sanitized, or carries a justified ignore if
the context genuinely doesn't need it. Reviewers enforce this sniff strictly.
**Where (locate live positions first).**
`vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.Security.ValidatedSanitizedInput --report=full inc/ storedash.php`
Captured 2026-07 (14 hits):
- `inc/admin/class-storedash-admin.php` — lines ~2035, 2036, 2052, 2058, 2127
- `inc/services/webhooks/webhooks.php` — lines ~18, 34, 54, 70, 85, 95 (reads of `$_SERVER['HTTP_X_WC_WEBHOOK_*']` / `REMOTE_ADDR`)
- `inc/Discounts/Discounts_Manager.php` — line ~95
- `inc/services/cart/Cart_Data.php` — line ~149 (`is_email( $_POST['email'] )` in the condition)
- `inc/services/cart/Cart_Tracking.php` — line ~718
**Steps.**
1. For each hit, read the surrounding code. Determine: is a real request value being read?
   - **Yes →** wrap: `sanitize_text_field( wp_unslash( $_SERVER['...'] ?? '' ) )` (or the type-correct
     sanitizer: `absint`, `sanitize_email`, `esc_url_raw`). For `$_SERVER` header reads in
     `webhooks.php`, add `wp_unslash()` + `sanitize_text_field()` even though they're signature bytes —
     then verify the HMAC still validates (compare against the raw header used for signing; if the
     signature is computed over the sanitized value, keep them consistent).
   - **Condition-only read (e.g. `is_email($_POST['email'])`) →** still unslash inside the check, or
     assign to an unslashed+sanitized local first and test that.
2. Mirror the SEC-4 pattern already applied in `Cart_Data.php` (`sanitize_* ( wp_unslash( ... ) )`).
3. Preserve existing `phpcs:ignore ...NonceVerification...` comments (consumer-key/checkout auth).
**Acceptance.** `...ValidatedSanitizedInput` reports 0 (or only justified inline ignores with reasons).
Webhook signature verification still passes (don't break HMAC by sanitizing signed bytes inconsistently).
**Verify.** Re-run the locator (0 unjustified hits); `php -l`; `composer test`.

---

# P1 — Plugin Check errors (should be clean before submitting)

## E — `phpcs.xml` policy (config only, zero code risk)
**Goal.** Kill false positives and align the standard to what WP.org enforces.
**Where.** `phpcs.xml`.
**Steps.**
1. **Declare custom capabilities** (removes 35 `WP.Capabilities.Unknown` false positives):
   ```xml
   <rule ref="WordPress.WP.Capabilities">
     <properties>
       <property name="custom_capabilities" type="array">
         <element value="manage_woocommerce"/>
         <element value="edit_shop_orders"/>
         <element value="edit_others_shop_orders"/>
       </property>
     </properties>
   </rule>
   ```
2. **Reconcile PHP floor:** change `<config name="testVersion" value="7.0-"/>` → `value="7.4-"` (matches
   CLAUDE.md / plugin header). This makes `PHPCompatibility` check the version actually shipped.
3. **`WordPress-Docs` decision (Path B, recommended):** downgrade the two pedantic cosmetic sniffs to
   warnings so they stop being errors/blockers (fix fully only if doing item L):
   ```xml
   <rule ref="Squiz.Commenting.InlineComment.InvalidEndChar"><severity>3</severity></rule>
   <rule ref="Squiz.Commenting.FunctionComment.ParamCommentFullStop"><severity>3</severity></rule>
   ```
**Acceptance.** `git diff --stat` shows only `phpcs.xml`. `WP.Capabilities.Unknown` count = 0.
Full `composer phpcs` error count drops substantially (Docs demoted to warnings).
**Verify.** `vendor/bin/phpcs --standard=phpcs.xml --sniffs=WordPress.WP.Capabilities --report=summary inc/` → 0.

## F — i18n translator comments
**Goal.** Every `printf`/`sprintf`-style translation with a placeholder has a `/* translators: */`
comment immediately above it.
**Where (locate).** `vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --report=full inc/`
Captured 2026-07 (6 hits):
- `inc/admin/class-storedash-admin.php` — lines ~1248, 1274, 1903
- `inc/Carts/Controllers/Carts_Recovery_Controller.php` — line ~64
- `inc/api/orders.php` — line ~89
- `inc/services/class-custom-order-statuses.php` — line ~194
**Steps.** Add a translator comment directly above each flagged translation call:
```php
/* translators: %s: order number */
sprintf( __( 'Order %s marked ready', 'storedash' ), $number );
```
Describe each placeholder. Keep the text domain `storedash`.
**Acceptance.** `...WP.I18n` reports 0 missing-translators-comment.
**Verify.** Re-run locator; `composer test`.

## G — Remove `@` silenced errors
**Goal.** No `@` error suppression (WPCS 7.7; SEC-8 cleared the activator — these are the remaining).
**Where (locate).** `vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.PHP.NoSilencedErrors --report=full inc/`
Captured 2026-07 (3 hits):
- `inc/api/media.php` — line ~397
- `inc/Taxonomies/Controllers/Brands_Controller.php` — lines ~626, 635
**Steps.** Remove the `@`. If the call can legitimately fail, branch on its return and log via
`StoreDash_Helpers::debug_log()`. Exception allowed by audit rules: `@unlink()` for best-effort temp
cleanup — if that's the case, leave it and add `phpcs:ignore WordPress.PHP.NoSilencedErrors -- temp
cleanup, failure is acceptable`.
**Acceptance.** `...NoSilencedErrors` reports 0 (or only justified `@unlink` ignores).
**Verify.** `grep -rn "@[a-z_]*(" inc/api/media.php inc/Taxonomies/Controllers/Brands_Controller.php`; `composer test`.

## H — WP alternative functions
**Goal.** Use WP wrappers where required: `json_encode`→`wp_json_encode`, `parse_url`→`wp_parse_url`.
**Where (locate).** `vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.AlternativeFunctions --report=full inc/`
Captured 2026-07:
- `inc/api/media.php` — lines ~280, 371
- `inc/services/cart/Cart_Data.php` — line ~255
- `inc/services/webhooks/webhooks.php` — line ~190
**Steps.**
1. Replace `json_encode(` → `wp_json_encode(` and `parse_url(` → `wp_parse_url(` at these sites.
   ⚠️ If a `json_encode` result is used as HMAC-signed bytes, ensure the SAME value is signed and sent
   (see SEC-3 — sign the exact bytes transmitted). Don't reintroduce a sign≠send mismatch.
2. `unlink`/`rename`/filesystem alt-function hits map to SEC-9 (deferred fallback logger) — leave those
   and add `phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations -- debug-only
   fallback logger, see SEC-9` rather than rewriting.
**Acceptance.** No `json_encode(`/`parse_url(` bare calls remain at these sites; fs ones carry justified
ignores.
**Verify.** Re-run locator; `composer test`; confirm webhook signatures still validate.

---

# P2 — Plugin Check warnings / annotations

## I — Justified `phpcs:ignore` for audited-safe DB / nonce / escape
**Goal.** The security-relevant sniffs that the audit already confirmed SAFE get inline, *reasoned*
ignores — which is exactly what WP.org reviewers want to see (documents why it's safe).
**Where (locate per sniff).**
- `WordPress.DB.PreparedSQL` (interpolation, ~55) — table-name interpolation `$wpdb->prefix . '…'`:
  top files `class-yith-migration.php` (13), `Discount_DB_Handler.php` (10), `class-storedash-activator.php` (8),
  `Cart_Tracking.php` (7), + others.
- `WordPress.DB.DirectDatabaseQuery` (~178) — legit custom-table access; top files
  `Discount_DB_Handler.php` (41), `class-storedash-activator.php` (36), `Cart_Tracking.php` (25),
  `class-yith-migration.php` (13).
- `WordPress.Security.NonceVerification` (12) — `headless-checkout.php` (6),
  `class-storedash-auth-handler.php` (4), `class-custom-order-statuses.php` (2).
- `WordPress.Security.EscapeOutput` (5) — all in `inc/admin/class-admin-components.php` (HTML builder).
- `WordPress.PHP.DevelopmentFunctions` error_log/print_r (~11) — `WP_DEBUG`-gated debug logging across
  integrations/loader/helpers.
**Steps.**
1. For EACH flagged line, FIRST re-confirm it matches an audited-safe pattern (interpolated value is a
   table name, not user input; nonce context is consumer-key/checkout auth; output is pre-escaped;
   debug fn is WP_DEBUG-gated). If a line does NOT match a safe pattern, treat it as a real bug (fix,
   don't ignore) and escalate.
2. Add an inline ignore with a specific reason, e.g.:
   ```php
   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, not user input
   // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom woodash_carts table, no core API
   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST consumer-key auth, not nonces (see CLAUDE.md Auth)
   ```
3. For `DirectDatabaseQuery.NoCaching`: where a read is trivially cacheable, add
   `wp_cache_get/set` instead of ignoring; where inherently uncacheable (writes, schema, migration),
   ignore-with-reason.
4. Many sites may already carry partial ignores — extend, don't duplicate.
**Acceptance.** These sniffs report 0 *unjustified* hits; every remaining occurrence has an inline
`-- reason`. No blanket disables.
**Verify.** Re-run each sniff locator; spot-check 5 random ignores have real reasons; `composer test`.

## J — `composer phpcbf` auto-fix (safe mechanical 144)
**Goal.** Clear the 144 auto-fixable whitespace/alignment/bracket violations.
**Steps.** `composer phpcbf`, then **review `git diff`** (should be whitespace / `=>` alignment / blank
lines / brackets only — NO logic), then `composer test`. Commit as "phpcbf: auto-fix mechanical style".
**Acceptance.** `phpcbf`-fixable count drops to ~0; diff is whitespace-only; tests pass.
**Verify.** `git diff` visual scan; `composer test`.

## K — Run the official Plugin Check (the real gate)
**Goal.** Pass the actual WP.org tool, which checks things phpcs can't (readme parsing, trademark,
disallowed functions, "phoning home," header/stable-tag consistency).
**Where.** Needs a WordPress environment (local WP + WP-CLI, `wp-env`, or CI) — it does NOT run in this
repo's bare PHPUnit harness.
**Steps.**
1. In a WP install with the plugin active: `wp plugin install plugin-check --activate` then
   `wp plugin check storedash` (or use the composer `wordpress/plugin-check` package).
2. Triage output: fix all ERRORS; assess WARNINGS (many overlap items D/F/G/H/I above).
3. Record the authoritative results at the bottom of this file; its findings supersede the phpcs
   approximations used to build this checklist.
**Acceptance.** Plugin Check reports 0 errors; warnings triaged/justified.
**Verify.** Re-run `wp plugin check storedash`.

---

# P3 — Optional / infra

## L — *(Optional, Path A only)* `WordPress-Docs` cosmetic cleanup
**Goal.** Zero out the ~1,100 comment-punctuation / docblock violations for a pristine full-phpcs tree.
Only do this if you explicitly want Path A; it has zero submission or functional value.
**Steps.** Per-directory: add missing docblocks, end inline comments in `.`, add `@param` full-stops.
Commit per directory, `composer test` after each. Consider whether item E already demoted these to
warnings (if so, this is purely optional polish).
**Acceptance.** `composer phpcs` reports 0 errors AND 0 warnings.
**Verify.** `composer phpcs`.

## M — CI gate
**Goal.** Keep the tree submittable — fail PRs that regress lint/tests/Plugin Check.
**Steps.**
1. Add a CI workflow: `composer install`, `composer phpcs`, `composer test`.
2. Add a WP-CLI + Plugin Check job (WP version matrix) running `wp plugin check`.
3. Fail on new phpcs errors or Plugin Check errors.
**Acceptance.** CI runs on PRs and blocks regressions.
**Verify.** Open a test PR; confirm the gate runs and can fail.

---

## Ground-truth data (captured 2026-07, this repo, no WP env)

- Full `composer phpcs`: **1,595 errors + 294 warnings (1,889)** across 74 files.
- ~**77% is cosmetic `WordPress-Docs`/style** not required by WP.org (908 comment-end-char alone).
- WP.org-relevant sniff subset: **~306** violations in 18 sniffs.
- Of those, **~50 are genuine code fixes** (items D/F/G/H); the rest are one config change (E, −35
  false positives), justified annotations (I), or safe auto-fixes (J).
- **Official Plugin Check NOT run here** (no WordPress bootstrap) — item K is authoritative and must run
  in a real WP env; its output supersedes this approximation.
- Cross-references: security-relevant "audited-safe" buckets trace to `security-audit-2026-07.md`
  (SQLi/XSS/nonce PASS). Strategy & Path A/B rationale: `wporg-submission-plan.md`.
