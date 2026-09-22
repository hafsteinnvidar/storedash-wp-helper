# Verification — Evidence Filter + Cross-Audit

## Evidence filter

Before reporting ANY finding, it must pass ALL gates:

1. **Concrete reference** — specific `inc/…:line` or code block.
2. **Clear impact** — what breaks, degrades, or is wrong (a reproducible path, not a hunch).
3. **Verified across callers** — if the fix touches a shared class/method/hook, every caller/registrant confirmed (grep).
4. **Convention source is checked-in** — if citing a rule, it comes from this repo's `CLAUDE.md`, a module `README.md`, `phpcs.xml`, or a grep-proven pattern. The skill's own text is NOT a project convention.
5. **WooCommerce claims cite live docs** — any "this WC API/shape is wrong" finding cites context7 / developer.woocommerce.com, and respects the PHP 7.4 / WC 6.0 floor. A WC finding that contradicts current docs is dropped.
6. **Cross-consumer claims cite the consumer** — any "this breaks sync/woo-dash" or "this field is unused" finding greps the consumer repo (`storedash-sync`, `woo-dash`) and names the file.

Fail any gate → drop it.

## HPOS / order-access guard (common false positive)

A `get_post_meta`/`WP_Query`/`$wpdb->postmeta` hit is only a finding when the post type is an **order** (`shop_order`/`shop_order_refund`/an `$order_id`). Against products, terms, media, or a custom table it's fine. Confirm the type from surrounding code before flagging — most such hits in this plugin are NOT order data.

## Load-wiring guard

Before flagging "file never loads": grep `include_files()` in `class-storedash-bootstrap.php` AND check whether it's a PSR-4 module file (autoloaded). Only flag if it's neither.

## Backward-compat guard

Never flag the `woodash_*` stored surface (table/options/meta/hook/`X-WooDash-Signature`) as "inconsistent naming" — it's a deliberate live contract (CLAUDE.md). New `storedash_*` names coexisting with old `woodash_*` reads is correct, not a bug.

## Finding folding

If finding B is subsumed by A's fix (A deletes the file B flags), fold B into A. Don't inflate counts.

## Cross-audit (adversarial second pass)

After self-verification, spawn an independent verifier:

```
Agent({
  subagent_type: "general-purpose",
  description: "Cross-audit helper-xray findings",
  prompt: `You are a skeptical cross-auditor of a WordPress/WooCommerce plugin audit. Plugin repo: /Users/pineapple/Documents/GitHub/storedash-helper. Consumers: storedash-sync (/Users/pineapple/Documents/GitHub/storedash-sync, Go) and woo-dash (/Users/pineapple/Documents/GitHub/woo-dash).

Default to skepticism. For EACH finding verify:
1. Line numbers — open the file, confirm.
2. Load path — is the file autoloaded (PSR-4) or in include_files()? A "never loads" claim must prove neither.
3. HPOS claims — is the get_post_meta/WP_Query hit actually against an ORDER? If it's products/terms/media, the finding is wrong.
4. Auth claims — read the permission_callback chain (class-storedash-auth-handler.php / class-base-permissions.php); confirm a "public route" is really __return_true.
5. HMAC/SQL claims — read the actual comparison / query.
6. WooCommerce API claims — check against current WC docs; reject if it assumes a version above the WC 6.0 / PHP 7.4 floor or contradicts live docs.
7. Cross-consumer claims — grep the named consumer repo; confirm the route/field is really used/unused.
8. Convention claims — confirm the cited rule exists in CLAUDE.md/README/phpcs.xml.

Report per finding: CONFIRMED / DISPUTED / UNVERIFIABLE, with the evidence. Produce corrected text for DISPUTED. Drop findings whose core premise is false.

--- FINDINGS ---
[paste complete audit output]`
})
```

### After cross-audit
- Apply DISPUTED corrections; drop wrong-premise findings.
- Fix line numbers, call counts, signatures.
- Note "Cross-audit corrected: [what changed]" in the output.
