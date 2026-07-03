# Implementation Order (sequential)

> Mirror of pinned tracking issue #141. Regenerate both from the `depends-on:` metadata in issue bodies.

**Canonical, ordered implementation queue for sequential issue-by-issue work by AI agents.** Walk it top to bottom. This list is *derived* from `depends-on` metadata in each issue, tie-broken by (unblock-count desc, then size asc). Mirror in the repo at `docs/IMPLEMENTATION_ORDER.md`.

## Rules for the implementing agent
1. **Pick the lowest-numbered unchecked `status:ready` item.** Skip any item that is `status:blocked` / `needs-decision` / `investigation` / `discussion`, and any `status:needs-design` item whose design-gate comment is unresolved.
2. **Read the issue's `**[Backlog metadata]**` header first** — its `depends-on:` line names the blockers to do before it.
3. **Between every item: run the full suite green** (`composer test` + `npm test`) before starting the next. In a single sequential chain, an uncaught regression at step N poisons every later step.
4. `security-sensitive` items require a security-aware review; do not ship the happy-path only.
5. When an issue closes, its box here strikes through automatically; re-derive if `depends-on` changes.

---

## Tier 0 — foundations & quick-win bugs (do first; several unblock the rest)
- [ ] 1. #119 — inspect_site returns smells for real pages `bug area:guardrails size:S` · unblocks #51, #87
- [ ] 2. #131 — AJAX capability model per action/apply `bug security-sensitive size:M` · unblocks #16
- [ ] 3. #120 — field-map ⊆ schema drift test (cta/grid) `bug area:actions size:S` · pairs #85
- [ ] 4. #128 — DOMDocument charset in post-apply validator `bug area:ai-chat size:S` · unblocks #77, clears #83
- [ ] 5. #125 — wp_unslash chat fallback message content `bug area:ai-chat size:S`
- [ ] 6. #129 — dead validation block in _pp_validate_length `bug area:actions size:S`
- [ ] 7. #124 — media inventory image-mime filter `bug area:ai-chat size:S`
- [ ] 8. #140 — internal confirmation msgs leak on reload `bug area:ai-chat size:S`
- [ ] 9. #123 — style repair resolves wrong component (id targeting) `bug area:ai-chat size:S`
- [ ] 10. #121 — auto-draft GC for Add-New-Page `bug area:actions size:S`
- [ ] 11. #122 — apply reset records touched tokens / rollbackable `bug area:cli size:M`
- [ ] 12. #76 — grid collapsed-row title label `bug area:editor size:S`
- [ ] 13. #95 — inspect-composition CLI alias / doc `enhancement area:cli size:S`
- [ ] 14. #36 — safe data:image/* source escaper `bug security-sensitive area:media size:S` · precedes media cluster
- [ ] 15. #129→ (done above)

## Tier 0b — shared primitives (build once; many issues consume these)
- [ ] 16. #99 — gradient slot type **+ the reusable "add-a-style-slot" scaffold** `enhancement security-sensitive area:styling size:L` · precedes #100, #111
- [ ] 17. #93 — extend shared button primitive to hero/grid/section `enhancement area:styling size:M` · precedes #111
- [ ] 18. **DESIGN SPIKE:** composition freshness/version marker (see gate on #13) — one decision consumed by #13, #113, #133

## Tier 1 — styling consumers
- [ ] 19. #100 — faq/stats per-instance style slots `enhancement area:styling size:M` · needs #99
- [ ] 20. #61 — dark-surface foreground authority (needs `--faq-heading-color` from #100) `bug area:styling size:M` · needs #100
- [ ] 21. #111 — secondary/outline CTA style slots `enhancement area:styling size:M` · needs #93, #99

## Tier 1 — component/content patterns
- [ ] 22. #102 — section-header pattern (eyebrow+centered heading+subhead) `enhancement area:components size:L` · supersedes/absorbs #85
- [ ] 23. #85 — hero eyebrow render-or-remove `bug area:components size:S` · do within #102
- [ ] 24. #110 — two-tone/accent-word headings `enhancement security-sensitive area:components size:M`
- [ ] 25. #103 — grid card richness: rich body + per-item icon/image (absorbed #109) `enhancement area:components size:L`
- [ ] 26. #1 — testimonial/quote component `enhancement area:components size:M` · can emit Review schema via #3
- [ ] 27. #56 — grid-steps default layout balance `enhancement area:components/visual-qa size:M`

## Tier 1 — media (ordered by dependency)
- [ ] 28. #105 — import/sideload media action (SSRF-hardened) `enhancement security-sensitive area:media size:L` · precedes #107
- [ ] 29. #107 — responsive srcset/sizes output `enhancement area:media size:M` · needs #105 attachment ids
- [ ] 30. #106 — site logo via safe surface `enhancement area:media size:S`
- [ ] 31. #108 — image focal-point / aspect control `enhancement area:media size:M`

## Tier 1 — SEO (one subsystem)
- [ ] 32. #41 — per-page SEO metadata (head plumbing) `enhancement area:seo size:M` · precedes #3
- [ ] 33. #3 — JSON-LD structured data (FAQPage first) `enhancement area:seo size:M` · needs #41

## Tier 1 — routing
- [ ] 34. #134 — update_page_slug / create_page slug param `enhancement area:cli/actions size:S` · supports #62
- [ ] 35. #62 — canonical core routes / redirects (no 404) `bug area:routing size:M` · needs #134
- [ ] 36. #126 — blog listing (home.php) + pagination `bug area:routing size:M`
- [ ] 37. #138 — search results template `enhancement area:routing size:M`

## Tier 1 — guardrails (need #119 first)
- [ ] 38. #51 — over-narrow/over-compact rhythm smells `enhancement area:guardrails size:S` · needs #119
- [ ] 39. #87 — empty-FAQ (empty structured section) smell `enhancement area:guardrails size:S` · needs #119

## Tier 1 — AI-chat robustness
- [ ] 40. #130 — preview-time media-URL validation (preview/execute parity) `enhancement area:ai-chat size:S`
- [ ] 41. #135 — enqueue_font wires font-family token `enhancement area:ai-chat size:M`
- [ ] 42. #136 — explicit page-target selector (not substring match) `enhancement area:ai-chat size:M`
- [ ] 43. #139 — stop button + first-token SSE fallback `enhancement area:ai-chat size:M`
- [ ] 44. #137 — atomic multi-step proposal apply/rollback `enhancement area:ai-chat size:M` · benefits from #133

## Tier 1 — actions / cli / integrity
- [ ] 45. #132 — create/populate/assign nav menus `enhancement area:actions size:L`
- [ ] 46. #127 — Windows path separators in integrity/drift hashing `bug area:integrity size:M`
- [ ] 47. #77 — `wp pp validate page` reuses post-apply validator `enhancement area:cli size:S` · needs #128
- [ ] 48. #63 — sticky-header anchor scroll offset `bug area:visual-qa size:S`

## Tier 1 — tests / CI
- [ ] 49. #16 — PHP unit-coverage gaps (refresh refs; encode #131 caps) `enhancement area:tests size:M` · needs #131
- [ ] 50. #14 — JS/E2E chat coverage (mock SSE) `enhancement area:tests size:M`
- [ ] 51. #98 — real WP+MySQL token concurrency harness `enhancement area:tests/concurrency size:M`
- [ ] 52. #54 — dev QA gallery for logos/stats/embed `enhancement area:tests size:M`
- [ ] 53. #81 — move wp-env CLI out of E2E test bodies `enhancement area:tests size:M`

## Tier 2 — needs-design (resolve the gate comment before coding; not for the smallest agents)
- [ ] 54. #13 — optimistic locking on composition writes `enhancement needs-design area:concurrency size:L` · needs spike (18)
- [ ] 55. #113 — preflight freshness marker `enhancement needs-design area:concurrency size:M` · needs spike (18)
- [ ] 56. #133 — composition history / restore_composition `enhancement needs-design area:concurrency size:L` · needs spike (18)
- [ ] 57. #69 — variant/theme/layout naming (branch: early-with-alias or last) `enhancement needs-design area:styling size:L`
- [ ] 58. #104 — text + content-panel/columns layout `enhancement needs-design area:components size:L`

---

## Not in the chain (do NOT pick these as implementation work)
- **Epics (umbrellas, tracked, not single work orders):** #27 (visual-QA gate), #45 (durable artifacts).
- **status:blocked (depend on a feature that doesn't exist yet):** #94 (needs an import/bulk path), #114 (deferred site-target grain).
- **status:needs-decision (maintainer call first):** #60 (rgba policy), #82 (branch protection).
- **status:investigation (confirm root cause first):** #83 (missing_local_media on WP7 — do #128 first, then investigate the uploads-baseurl prefix).
- **status:discussion (product/workflow, not code):** #38 (writable presentation path), #49 (target override), #59 (page-family drift), #72 (homepage CSS upgrade risk).

## Derivation / maintenance

**Source of truth** = the `> depends-on:` line inside each issue's `**[Backlog metadata]**` block (every open issue has exactly one; value is a comma-separated list of `#N` refs, or `none`). Order = tiered topological sort of that graph, tie-broken by (unblock-count desc, then `size:` asc), with `status:ready` items only.

**Extract every edge** (proven to parse cleanly for all open issues except the index #141):

```bash
gh issue list --repo FJCF76/PromptingPress --state open --limit 200 --json number,body \
  --jq '.[] | select(.number != 141)
        | "\(.number): \(.body | capture("(?m)^> depends-on: (?<d>.+)$").d)"'
```

**Regenerate after closures/edits:**
1. Run the command above to get `issue: deps` for every open issue.
2. Also pull labels: `gh issue list --state open --limit 200 --json number,labels` — keep only `status:ready`; note `size:S|M|L` for tie-breaking and `security-sensitive` for the review flag.
3. Build the DAG from the `#N` refs; topological-sort; within a layer sort by unblock-count (how many issues name this one) desc, then size asc.
4. Re-emit this list and the pinned tracking issue #141; both are outputs, not hand-maintained.

The current edge set (for reference): `3→41, 16→131, 51→119, 61→100, 62→134, 77→128, 83→128, 85→102, 87→119, 94→105, 100→99, 107→105, 111→{93,99}, 113→13, 133→13`; all others `none`. The graph is acyclic — every edge points to a more-foundational issue.
