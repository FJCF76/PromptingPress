# TODOs

## P3

- **Supported extension points for site-specific structural changes** — The v0.11.0 upgrade-safety guardrails make parent-theme `templates/`/`components/`/`assets/` inspect-only for site work, and the AI is told to "STOP and escalate" when a site genuinely needs a structural (template/component) change. That's tenable only if there's an eventual supported path; otherwise every real structural customization becomes a release request. Design a child-theme or runtime override layer (deliberately excluded from the v0.11.0 sprint to keep it focused). Depends on observing how often escalations actually arise in practice. Surfaced by /plan-eng-review outside-voice (Codex), 2026-06-24.

- **Refactor E2E setup: move wp-env CLI calls out of test bodies** — E2E specs call `wp pp ...` via `execSync('npx wp-env run cli ...')` inline (e.g. `tests/e2e/actions.spec.ts`, plus the create/setComposition helpers). This couples every browser test to a live Docker/wp-env, duplicates setup plumbing, and makes the CI E2E workflow harder to stabilize. Move them into shared fixtures / `global-setup`. Do only if needed to stabilize `.github/workflows/e2e.yml`. See #81. Surfaced by /plan-eng-review D2 spike (2026-06-22).

- **Promote E2E @smoke check to required** — The push `@smoke` check (`.github/workflows/e2e.yml`) is non-blocking. Promote it to a required status check once it holds green on main for 2 weeks with zero flakes — only meaningful if a PR + branch-protection model is adopted (repo currently ships direct to main). Builds on #12. See #82. Surfaced by /plan-eng-review (2026-06-22).

- **Partial data loss guard for array field sync** — The `wouldLoseArrayData` guard (added in the composition editor safety sprint, issue #73) only catches total loss (ALL items read as empty). Partial loss (e.g., 3 of 4 items empty due to a future sync bug) is not guarded because it's indistinguishable from legitimate editing. If partial loss is observed in practice, add a per-item heuristic: "items lost N of M sub-fields" with a configurable threshold. Start point: `wouldLoseArrayData()` in `assets/js/pp-editor-logic.js`. Depends on observing partial loss in production or dogfooding.

- **`wp pp target set` for multi-environment** — Manual override of auto-detected target config. Currently `wp pp target show` derives target info from live WP state (`get_option('siteurl')`, `get_template_directory()`, `ABSPATH`) without persisting anything. A `set` command would store an explicit override and enable staging/production disambiguation for operators managing multiple environments. Depends on a concrete workflow that auto-derived target discovery can't serve (see GitHub issue #49).

- **In-progress step reporting for operating loop** — Add structured observability so agents can report which loop step they're currently executing, not just what they completed. Currently `pp_validate_loop_run()` only checks post-mortem completeness. A step-reporting mechanism (e.g., `pp_report_step($step, $status)` writing to a run log) would help debug loops that fail mid-way. Natural v1 enhancement after dogfooding reveals which mid-loop failures are hardest to diagnose. Surfaced by independent review during /plan-eng-review (2026-06-01).

- **Evaluate `--measure-centered` value** — After visual validation on dev, test whether centered section body can tighten from `56rem` to a ch-based value (e.g., `75ch` or `80ch`). The `--measure-centered` token in `assets/css/base.css` was set to `56rem` as a safe default (preserves existing layout). A tighter value may produce better reading measure for centered marketing intros, but needs visual validation first. See issue #53.

- **`wp pp validate page` CLI command** — Expose `pp_post_apply_validate()` via WP-CLI: `wp pp validate page --post_id=N [--component-index=M]`. Reuses the existing validation function from `lib/post-apply-validate.php`. Useful for batch validation, CI checks, and debugging. Depends on post-apply validation shipping (issue #75).

- **New Chat confirmation dialog** — Clicking "New Chat" immediately clears all messages without confirmation. Add a simple `confirm()` or inline prompt when a conversation exists (1+ messages). Low severity — conversations are ephemeral in v1 (no server-side history). Surfaced by live design audit (2026-06-15, FINDING-L04).

- **Markdown h4/h5 heading visual distinction in assistant messages** — Both render identically (14px, 600 weight). Rarely used in practice by AI responses. If needed, differentiate h4 at 14px and h5 at 13px or italic. Surfaced by live design audit (2026-06-15, FINDING-L07).

## P2

- **Wire `component_id` into `reorder_components` and `add_component`** — v1 only wired `component_id` addressing into `update_component` and `remove_component`. `reorder_components` takes an `order[]` array (positional) and `add_component` takes a `position` param. Both would need new semantics (e.g., "insert after component_id X") rather than just replacing index with ID. Deferred from Semantic Composition Operator v1 sprint (2026-06-12).

- **Concurrent edit hash check for patch** — `pp_patch_composition()` currently has a TOCTOU gap: the composition can change between preview and apply. Add a content hash to `inspect-composition` output, accept it as an optional `--etag` flag on `patch`, and reject the apply if the composition changed. Single-operator use makes this low-risk for v1 but should be addressed before multi-operator scenarios. Deferred from Semantic Composition Operator v1 sprint (2026-06-12).

- **Investigate broken-media missing_local_media validation on WP 7.0** — The E2E spec `validation.spec.ts › broken media` is quarantined (`test.fixme`): `style_component` succeeds but post-apply validation returns `ok=true` — `missing_local_media` does not fire for an unresolvable local image URL. Determine whether it's a product gap in `pp_post_apply_validate()` or a test-setup mismatch, then re-enable. See #83. Surfaced by /plan-eng-review D2 spike (2026-06-22).

## Completed

- **Server-driven action warning metadata** — Destructive-action warnings are now server-driven from the action/apply registries (`impact_warning` key on `pp_register_action()` / `pp_register_apply()`), aggregated and localized via `ppAiChat.impact_warnings`; the hardcoded JS map is gone. A registry-coverage test fails CI if a known-destructive capability ships without a warning. **Completed:** 2026-06-22, #74

- **Error status message structural differentiation** — Error steps now use `.pp-ai-step-impossible` (grey border/background) and `.pp-ai-step-fixable` (amber border/background) for structural visual differentiation beyond color alone. **Completed:** v0.8.3 (2026-06-16)
