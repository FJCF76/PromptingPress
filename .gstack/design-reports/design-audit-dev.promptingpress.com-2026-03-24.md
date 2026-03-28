# Design Audit: dev.promptingpress.com
**Date:** 2026-03-24
**Branch:** main
**Auditor:** /design-review + Claude design subagent

---

## Scores

| Score | Before | After |
|-------|--------|-------|
| **Design Score** | C+ | B- |
| **AI Slop Score** | B+ | B+ |

---

## First Impression

- "The site communicates **focused technical competence** — it knows what it is and who it's for."
- "I notice **the typography is doing all the heavy lifting** — no imagery, no color, no texture. The design is betting entirely on clean layout and copy quality."
- "The first 3 things my eye goes to are: **the headline**, **the CTA button**, **the 4-column feature grid below the fold**."
- "If I had to describe this in one word: **sparse**."

**Classifier:** MARKETING/LANDING PAGE

---

## Inferred Design System

| Token | Value | Notes |
|-------|-------|-------|
| Font (body + heading) | system-ui, sans-serif | Both identical — no typographic distinction |
| Colors | Black, white, gray (#6b7280), blue (#0055cc) | Clean 4-color palette |
| Heading scale | h1=56px / h2=32px / h3=20px* | *after fix; was 18px |
| Spacing base | 4px/8px scale via tokens | Systematic ✓ |
| Border radius | 6px | Conservative, consistent |
| Load time | 176ms total | Excellent |

---

## Litmus Checks

| Check | Result |
|-------|--------|
| Brand unmistakable in first screen? | ✓ YES |
| One strong visual anchor? | ✗ NO — typography only |
| Scannable headlines only? | ✓ YES |
| Each section has one job? | ✓ YES |
| Are cards necessary? | ⚠ BORDERLINE — feature text grid |
| Motion improves hierarchy? | ✗ N/A — no page-level motion |
| Premium without decorative shadows? | ✓ YES — none present |

---

## Findings & Fix Status

### Visual Review (primary audit)

| # | Impact | Finding | Status |
|---|--------|---------|--------|
| 001 | HIGH | Nav logo touch target 32px → needs 44px | ✅ FIXED — `components.css:74` |
| 002 | HIGH | `.grid__item-title` 18px barely above 16px body | ✅ FIXED — `components.css:467` |
| 003 | HIGH | No `prefers-reduced-motion` anywhere | ✅ FIXED — `base.css` |
| 004 | MEDIUM | No `text-wrap: balance` on headings | ✅ FIXED — `base.css` |
| 007 | MEDIUM | FAQ accordion snap-opens, no animation | ✅ FIXED — `components.css` |
| 008 | MEDIUM | Inner page hero padding 112px × 2 disproportionate for title-only | ✅ FIXED — `components.css` |
| 005 | MEDIUM | Hero flat white — no visual anchor | ⏸ DEFERRED — requires design direction |
| 006 | MEDIUM | Both fonts are `system-ui` (default stack) | ⏸ DEFERRED — requires font choice |

### Code Consistency (subagent audit)

| # | Impact | Finding | Status |
|---|--------|---------|--------|
| S1 | HIGH | `.btn:focus-visible` uses raw `outline-offset: 3px` vs base `2px` — magic number, no token | ⏸ DEFERRED |
| S2 | HIGH | No typography scale tokens — raw rem values scattered; two conflicting "small" sizes (`0.9rem` vs `0.875rem`) | ⏸ DEFERRED |
| S3 | MEDIUM | `sm` (640px) and `xl` (1280px) breakpoints documented but never implemented | ⏸ DEFERRED |
| S4 | MEDIUM | Line-height values untokenized (1.15, 1.4, 1.5, 1.6 in active use) | ⏸ DEFERRED |
| S5 | MEDIUM | `.sr-only` defined twice — `base.css:199` and `utilities.css:31`; both use deprecated `clip:` syntax | ⏸ DEFERRED |
| S6 | MEDIUM | Nav container `min-height: 64px` is a magic number — no token | ⏸ DEFERRED |
| S7 | MEDIUM | Grid card hover `translateY(-2px)` is a raw magic number — only motion value not tokened | ⏸ DEFERRED |
| S8 | MEDIUM | FAQ `<summary>` has no component-level `focus-visible` override — chevron visually competes with focus ring at right edge | ⏸ DEFERRED |

---

## Commits Applied

```
bd6821b style(design): FINDING-008 — hero--left padding reduced from 112px to 64px for inner pages
c04f6d9 style(design): FINDING-007 — FAQ answer entrance animation (fade + slide, CSS-only)
f001501 style(design): FINDING-004 — text-wrap: balance on all headings
5405327 style(design): FINDING-003 — add prefers-reduced-motion media query to reset
c262da6 style(design): FINDING-002 — grid item title size 1.125rem → 1.25rem for clearer hierarchy
0178038 style(design): FINDING-001 — nav logo touch target raised to 44px min-height
```

---

## Deferred / Next Session

**Requires design direction:**
- Font choice — replace `system-ui` with an intentional heading font
- Hero visual anchor — code screenshot, subtle texture, or background tint

**Token housekeeping (good candidate for a focused cleanup session):**
- Add `--focus-offset` token; unify base + button focus ring offset
- Add typography scale tokens (`--text-sm`, `--text-base`, etc.) or document the scale as CSS comments
- Remove the unused `sm`/`xl` breakpoints from documentation or implement them
- Deduplicate `.sr-only` (remove from `utilities.css`, keep in `base.css`)

---

## PR Summary

> Design review found 8 visual issues + 5 code consistency issues. Fixed 6 of 8 visual issues. Design score C+ → B-. AI Slop Score B+ unchanged (already clean). Deferred: font choice, hero visual anchor, typography token housekeeping.
