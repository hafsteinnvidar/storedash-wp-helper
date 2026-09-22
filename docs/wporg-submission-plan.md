# StoreDash Helper — WordPress.org Submission Readiness Plan (2026-07)

Companion to `security-audit-2026-07.md`. Goal: get this plugin submission-ready for the WordPress.org
directory **without breaking anything**, by fixing what the directory actually requires rather than
grinding ~1,900 cosmetic linter nits.

## The strategic insight (read this first)

`phpcs.xml` pulls in `WordPress-Core` + **`WordPress-Docs`** + `WordPress-Extra`. Full run:

```
1,595 errors + 294 warnings = 1,889 violations across 74 files   (composer phpcs)
```

But that number is dominated by **house-style pedantry WordPress.org does not require**:

| Bucket | Count | Required by WP.org? |
|--------|------:|---------------------|
| `Squiz.Commenting.InlineComment.InvalidEndChar` (comments must end in `.`/`!`/`?`) | 908 | ❌ No (`WordPress-Docs`) |
| `@param` full-stops + missing docblocks + file/class comments | ~230 | ❌ No (`WordPress-Docs`) |
| `YodaConditions.NotYoda` | 70 | ❌ No |
| Line length, array alignment, whitespace | ~250 | ❌ No |
| **Everything above = cosmetic / house-style** | **~1,460 (77%)** | **❌ No** |

**The real gate is the official Plugin Check tool, not this `phpcs.xml`.** Approximating Plugin Check's
enforced sniff set (security + i18n + prefixing + php-compat + forbidden functions) yields:

```
~306 WP.org-relevant violations in 18 sniffs   (phpcs, curated sniff subset)
```

And even those 306 decompose into mostly-annotation work, not code rewrites (see classification below).

**Decision — target Path B** (fix the real bar; relax the cosmetic `WordPress-Docs`), not Path A
(zero out all 1,889). Path A adds ~1 day of comment-punctuation editing for zero functional or
submission value. Path A remains available as an optional final polish (Phase 4).

---

## Ground-truth classification of the ~306 WP.org-relevant violations

| Sniff | Count | Reality | Action |
|-------|------:|---------|--------|
| `DB.DirectDatabaseQuery` (direct/no-cache/schema) | 178 | Legit custom tables (`woodash_carts`). Plugin Check treats as **warnings**, not blockers. | Justified `phpcs:ignore` (+ add caching where cheap) |
| `DB.PreparedSQL.InterpolatedNotPrepared` / `NotPrepared` | 55 | Table-name interpolation (`$wpdb->prefix . '…'`). **Audited safe.** | Justified `phpcs:ignore` |
| `Security.ValidatedSanitizedInput` (missing unslash / not sanitized) | 25 | **Genuine — review each.** Some are checkout `$_POST` reads. | **Real fix** (`wp_unslash` + sanitizer) or justified ignore |
| `Security.NonceVerification.Recommended` | 12 | Consumer-key auth / WC-checkout-nonce-upstream contexts. **Audited safe.** | Justified `phpcs:ignore` (some already present) |
| `WP.AlternativeFunctions` (json_encode, parse_url, unlink, rename, fs) | 11 | Mixed. `json_encode`→`wp_json_encode`, `parse_url`→`wp_parse_url` are real, easy. unlink/rename = SEC-9 (deferred). | Mostly **real fix**; fs ones ignore |
| `PHP.DevelopmentFunctions` (error_log / print_r) | 11 | `WP_DEBUG`-gated debug logging. | Justified `phpcs:ignore` or wrap |
| `Security.EscapeOutput.OutputNotEscaped` | 5 | All in `class-admin-components.php` HTML builder (escapes per-piece upstream). **Audited safe.** | Justified `phpcs:ignore` |
| `WP.I18n.MissingTranslatorsComment` | 6 | **Genuine — easy.** | **Real fix** (add `/* translators: */`) |
| `PHP.NoSilencedErrors` | 3 | `@` operators (SEC-8 cleared the activator; these are elsewhere). | **Real fix** (remove `@`) |
| `WP.Capabilities.Unknown` | 35 | **False positive** — phpcs doesn't know `manage_woocommerce`/`edit_shop_orders`. | **Config** (declare custom caps in `phpcs.xml`) |

**Net genuine code fixes: ~50** (input sanitization ~25, alt-functions ~8, i18n ~6, silenced errors ~3,
plus spot caching). Everything else is justified annotations or one config change. This is a
**~1-1.5 day** job, not a week.

---

## Tracking table (phases, safest → riskiest)

| ID | Done | Phase | Risk | Effort | Output |
|----|------|-------|------|--------|--------|
| WP-0 | ☐ | Ground truth: run **official Plugin Check** in a WP env / CI | none | 30m | Authoritative blocker list |
| WP-1 | ☐ | `phpcs.xml` policy: declare custom caps, relax `WordPress-Docs`, reconcile PHP floor | none (config) | 1h | −35 false positives; cosmetic noise reclassified to warnings |
| WP-2 | ☐ | `composer phpcbf` — 144 auto-fixable mechanical | very low | 15m | Whitespace/alignment clean |
| WP-3 | ☐ | Genuine security/i18n fixes (~50) + justified ignores for audited-safe | medium | 3h | Real WP.org bar met |
| WP-4 | ☐ | *(Path A only, optional)* zero out `WordPress-Docs` cosmetic (~1,100) | low | 4-6h | Pristine full-phpcs |
| WP-5 | ☐ | WP.org non-phpcs: `readme.txt`, headers, GPL, zip hygiene | low | 2h | Submittable package |
| WP-6 | ☐ | CI gate: `composer phpcs` + Plugin Check on PR | none | 1h | Stays green |

---

## Phase detail

### WP-0 — Ground truth (run the REAL gate)
The official **Plugin Check** (WP.org plugin, run via `wp plugin check storedash`, or the composer
`wordpress/plugin-check` package inside a WP install / CI) is THE submission gate. It checks things
phpcs alone doesn't: `readme.txt` parsing, Stable Tag, plugin headers, trademark in slug, disallowed
functions, i18n, "no obfuscation," "no phoning home without consent," etc.
- Cannot run in this repo's bare test harness (no WordPress bootstrap). Run it in a real WP install
  or a CI job with WP + WP-CLI.
- Its output supersedes the phpcs approximation in this doc. Record the authoritative must-fix list
  here when available.

### WP-1 — `phpcs.xml` policy (config only, zero code risk)
- **Declare custom capabilities** so the 35 `Capabilities.Unknown` false positives disappear:
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
- **Decide `WordPress-Docs`:** Path B → downgrade it to `warning` severity (keeps it visible, stops it
  being a blocker) or exclude `Squiz.Commenting.InlineComment.InvalidEndChar` +
  `Squiz.Commenting.FunctionComment.ParamCommentFullStop`. Path A → keep as errors, fix in WP-4.
- **Reconcile PHP floor:** `phpcs.xml` has `testVersion 7.0-` but CLAUDE.md/readme target PHP 7.4.
  Set `testVersion 7.4-` (or align the docs) so `PHPCompatibility` checks the version you actually ship.
- Verify no code changed: `git diff --stat` shows only `phpcs.xml`.

### WP-2 — Auto-fix the mechanical 144
`composer phpcbf`, then **review the diff** and `composer test`. Only touches whitespace / `=>`
alignment / brackets / blank lines — cannot change behavior. Commit as one "phpcbf autofix" commit.

### WP-3 — Genuine fixes + justified ignores (the real work)
Work one sniff-category at a time, `composer test` after each, commit per category:
- **`ValidatedSanitizedInput` (25):** review each. Add `wp_unslash()` + the right sanitizer where a
  real input is read; where it's a pre-vetted checkout read, add `phpcs:ignore` **with the reason**.
  (Cross-check against the SEC-4 pattern already applied to `Cart_Data`.)
- **`I18n.MissingTranslatorsComment` (6):** add `/* translators: %s = … */` above each.
- **`NoSilencedErrors` (3) / `AlternativeFunctions` json/parse_url (real ones):** fix directly
  (`wp_json_encode`, `wp_parse_url`, drop `@`).
- **Audited-safe buckets** (`InterpolatedNotPrepared` 55, `NonceVerification` 12, `EscapeOutput` 5,
  `DirectDatabaseQuery` 178, `error_log`/`print_r` 11, fs alt-functions): add a **justified**
  `phpcs:ignore <sniff> -- <one-line reason>` on each. These were confirmed safe in the security
  audit; the ignore documents *why*, which is exactly what WP.org reviewers want to see.
  - For `DirectDatabaseQuery.NoCaching` specifically: add cheap object-cache reads where trivial;
    ignore-with-reason where the query is inherently uncacheable (writes, schema).
- **DO NOT** touch logic. An `phpcs:ignore` is a comment; a real fix is scoped and test-covered.

### WP-4 — Cosmetic bulk (Path A only, optional)
The 908 comment-periods + 70 Yoda + docblocks. Per-directory, commit per batch, `composer test` after
each. Zero functional value; only do this if you want a fully `WordPress-Docs`-clean tree. Skippable.

### WP-5 — WP.org packaging requirements (beyond phpcs)
- **`readme.txt`** in WP.org format: `Stable tag`, `Requires at least`, `Tested up to`, `Requires PHP`,
  `License: GPLv2 or later`, short/long description, `== Changelog ==`, FAQ. (This is a common
  first-submission rejection reason — it's mandatory and parsed by Plugin Check.)
- **Plugin header** in `storedash.php`: ensure `Requires at least`, `Requires PHP`, `License`,
  `Text Domain`, `Stable tag` consistency.
- **GPL compatibility:** all bundled code GPL-compatible; `vendor/` libraries compatible.
- **Zip hygiene:** `composer build-zip` must exclude dev files (`tests/`, `.github/`, `phpcs.xml`,
  `composer.*`, `docs/`, node/dev deps). Verify the produced `dist/storedash.zip` contains only runtime.
- **Assets:** `assets/` banner/icon/screenshots for the listing (separate SVN `assets/` dir on WP.org).
- **No "phoning home"** without disclosed consent; confirm outbound webhooks are user-configured (they
  are) and documented in readme.

### WP-6 — CI gate
Add a CI job: `composer install && composer phpcs && composer test`, plus a WP-CLI + Plugin Check job
(WP matrix). Fail the PR on new phpcs errors or Plugin Check errors so the tree stays submittable.

---

## Safety model (how we don't break anything)

- **One sniff-category (or phase) per commit** — reviewable, individually revertable.
- **`composer test` (23 tests) + `php -l` after every phase** — the regression net.
- **Cosmetic phases (WP-2, WP-4) can't change behavior** by construction (whitespace/comments only).
- **WP-3 is the only behavior-touching phase** and is small (~50 real edits); each is scoped and
  test-checked, and the audited-safe buckets get comment-only `phpcs:ignore`s, not code changes.
- **Same execution workflow as the security audit:** one scoped prompt per phase → fresh agent
  executes + reports → verify against the working tree before advancing.
- **`phpcs:ignore` discipline:** every ignore carries a `-- reason`. No blanket file-level disables.

## Provenance & caveats

- Counts from `composer phpcs` (full) and a curated WP.org-relevant sniff subset, 2026-07. The
  official Plugin Check tool was **not** runnable in this bare harness (no WordPress bootstrap) — WP-0
  must run it in a real WP env and its output supersedes the approximation here.
- The security-relevant buckets referenced as "audited safe" trace to `security-audit-2026-07.md`
  (SQLi/XSS/nonce PASS findings). Re-confirm any that changed since.
