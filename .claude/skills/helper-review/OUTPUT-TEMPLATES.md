# Output Template — helper-review

## Inline

```markdown
## Helper Review — {scope}

**Scope**: [diff / module `inc/…` / whole plugin]
**Floor**: PHP 7.4 · WC 6.0 (from storedash.php header)
**Live facts**: [context7 resolved WC/WP versions used, or "n/a"]
**Lint/tests**: [`composer phpcs` result · `composer test` result]

---

### Blockers
#### B-1: [title]
- **Where**: `inc/…:{line}`
- **Dimension**: [HPOS | load-contract | auth/security | compat | backward-compat]
- **What's wrong**: [specific, with the failing path]
- **Fix**: [concrete change]
- **Verified**: [what you checked — grep, phpcs, consumer repo, header]

### Should-fix
#### S-1: [title]
- **Where**: `inc/…:{line}`
- **Dimension**: […]
- **What's wrong / Fix / Verified**: […]

### Nice-to-have
#### N-1: [title] — `inc/…:{line}` — [one line + fix]

---

### Compat statement (always include)
- PHP 7.4 floor respected: [yes/no — cite any 8.0+ syntax found]
- WC 6.0 floor respected: [yes/no]
- HPOS declarations intact (`custom_order_tables` + `cart_checkout_blocks`): [yes/no]
- `woodash_*` stored contract untouched: [yes/no]

### Verdict
[2-3 sentences: ship / fix-blockers-first / needs-work. Biggest risk.]
```

If a tier is empty, say "none".

## Persisted (optional)

Save to `docs/reviews/YYYY-MM-DD-helper-review.md` with the same structure plus a one-line header (`# Helper Review — {scope} — YYYY-MM-DD`). Only when the user asks to persist.
