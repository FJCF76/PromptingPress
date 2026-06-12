# TODOs

## P3

- **`wp pp target set` for multi-environment** — Manual override of auto-detected target config. Currently `wp pp target show` derives target info from live WP state (`get_option('siteurl')`, `get_template_directory()`, `ABSPATH`) without persisting anything. A `set` command would store an explicit override and enable staging/production disambiguation for operators managing multiple environments. Depends on a concrete workflow that auto-derived target discovery can't serve (see GitHub issue #49).

- **In-progress step reporting for operating loop** — Add structured observability so agents can report which loop step they're currently executing, not just what they completed. Currently `pp_validate_loop_run()` only checks post-mortem completeness. A step-reporting mechanism (e.g., `pp_report_step($step, $status)` writing to a run log) would help debug loops that fail mid-way. Natural v1 enhancement after dogfooding reveals which mid-loop failures are hardest to diagnose. Surfaced by independent review during /plan-eng-review (2026-06-01).

- **Evaluate `--measure-centered` value** — After visual validation on dev, test whether centered section body can tighten from `56rem` to a ch-based value (e.g., `75ch` or `80ch`). The `--measure-centered` token in `assets/css/base.css` was set to `56rem` as a safe default (preserves existing layout). A tighter value may produce better reading measure for centered marketing intros, but needs visual validation first. See issue #53.

## P2

- **Wire `component_id` into `reorder_components` and `add_component`** — v1 only wired `component_id` addressing into `update_component` and `remove_component`. `reorder_components` takes an `order[]` array (positional) and `add_component` takes a `position` param. Both would need new semantics (e.g., "insert after component_id X") rather than just replacing index with ID. Deferred from Semantic Composition Operator v1 sprint (2026-06-12).

- **Concurrent edit hash check for patch** — `pp_patch_composition()` currently has a TOCTOU gap: the composition can change between preview and apply. Add a content hash to `inspect-composition` output, accept it as an optional `--etag` flag on `patch`, and reject the apply if the composition changed. Single-operator use makes this low-risk for v1 but should be addressed before multi-operator scenarios. Deferred from Semantic Composition Operator v1 sprint (2026-06-12).
