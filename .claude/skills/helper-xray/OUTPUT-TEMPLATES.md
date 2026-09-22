# Output Templates — helper-xray

## Inline

```markdown
## Helper X-Ray: `{module}`

**Target**: `inc/{module}/`
**Files scanned**: N total (M deep, K shallow)
**Load path**: [autoloaded PSR-4 module / wired in include_files() / mixed — note any unwired file]
**State footprint**: tables […], options […], meta […] — vs activator schema
**WooCommerce surface**: [order/product/coupon CRUD, Store API — verified vs live docs, or "none"]
**Consumer surface**: [storedash/v1 routes + webhooks exposed; consumer repos checked: sync / woo-dash]

---

## Strategic Assessment

### Architecture & wiring
[How the module is structured: Manager → Route_Registry → Controllers/Permissions, or procedural includes. Boot/registration flow. Load path correctness.]

### Cross-cutting concerns
- **Auth consistency**: [all privileged routes gate on the capability? any header-based authz?]
- **HPOS-safety**: [all order access via WC CRUD?]
- **State/schema integrity**: [reads match activator schema? drift?]
- **WP hygiene**: [ABSPATH / nonce / escape / prepared SQL consistent?]
- **Backward-compat**: [woodash_* stored contract respected?]

### Completeness & dead code
- [Registered-but-never-fired hooks; routes with no consumer; options written but never read; verified-unused code.]

### Strategic verdict
[2-4 sentences: module health, biggest systemic risk, structural vs tactical work.]

---

## Tactical Findings

### {file}

#### Must Fix
##### MF-{N}: [title]
**Location**: `inc/…:{lines}`
**What's wrong**: [specific, with failing path]
**Impact**: [what breaks — incl. cross-repo blast radius if a contract]
**Fix**: [how, respecting the 7.4/WC-6.0 floor]
**Verified**: [grep/callers/consumer repo/live-docs checked]
**Cross-audit**: [CONFIRMED | corrected]

#### Could Improve
##### CI-{N}: [title]
**Location**: `inc/…:{lines}`
**Current / Improvement / Why better / Safe because**: […]
**Cross-audit**: [CONFIRMED | corrected]

---

## Summary

| Severity | Count |
|----------|-------|
| Must Fix | N |
| Could Improve | N |

**Priority order**: [top 3 + why]
**Cross-audit results**: [confirmed / corrected / dropped]
```

If a tier is empty, say so.

## Persisted (saved to disk)

Only when asked. Save to `docs/audits/YYYY-MM-DD/helper-{module}/` as `strategic.md`, `tactical.md`, `actions.md` — same structure as the woo-dash feature-xray trio, with the header block above (Load path + Consumer surface lines REQUIRED).
