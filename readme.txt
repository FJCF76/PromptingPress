=== PromptingPress ===
Contributors: fjcf76
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 2.0.1
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

An AI-first WordPress theme built for clarity. PromptingPress uses a component-based rendering system with typed props, machine-readable schemas, and a single AI_CONTEXT.md that maps the entire site. Designed so AI agents can build, modify, and maintain WordPress sites through structured composition data.

== Installation ==

1. Download the theme ZIP file from the GitHub releases page.
2. In your WordPress admin, go to Appearance > Themes > Add New > Upload Theme.
3. Upload the ZIP file and click Install Now.
4. Activate the theme.

== Changelog ==

= 2.0.1 =
* The 2.0.1 fix cycle, seeded by rebuilding a real site on 2.0.0: a responsive value no longer fails `wp pp validate site`; a verified judgment-call advisory can be acknowledged with a required note (`wp pp check acknowledge`), and the acknowledgement re-opens when what was judged changes; `clamp()`/`calc()` lengths work in every length list; `wp pp schema` shows role defaults; the grid docs match the schema; card checklists sit under their text in a stretched row
* Verified by full PHP/JS/E2E suites per issue, planted-defect runs in scratch copies, and independent adversarial reviews; one judgment (an accent over a scrim) was held back from acknowledgement until its photo can be fingerprinted reliably (#1211)

= 2.0.0 =
* v2, the first stable release of the rebuilt styling model: one design contract (roles, style groups, design-token references, per-width maps and states) styles all ten components, the header and the footer, down to a single grid card, and replaces the whole 1.x styling system. There is no migration from 1.x: stored v1 style maps are ignored and must be cleared, then the design re-authored (see the migration how-tos in docs/)
* Verified by rebuilding promptingpress.com on it from its stored 1.20.0 values: every recorded value carried over exactly except the hero-proof styling production had always dropped, which now renders as authored, and the pages measure computed-style equal to production at three viewports, with each divergence named in issue #1170

= 1.7.0 =
* Actions/integrity hardening: snapshot rollback distinguishes absent from explicit-empty site options; the media-URL gate is schema-driven with a fail-closed floor; publish/trash/slug/SEO actions reject auto-draft phantoms (first-save promotion intact) and GC'd editor URLs redirect; the length validator rejects malformed number/paren/comma shapes with documented residuals
* Verified by full PHP/JS/E2E suites per issue and authoring-path tests through the real action surfaces

= 1.6.0 =
* Brand-fidelity release: heading letter-spacing token (signed lengths accepted), step-badge ink slot, section body size/weight slots, split-hero stretch alignment, footer secondary menu column and social-icon row (closed network set, inline SVG), and chat page-context adjacency hints
* Verified by a brand-shaped rendered evidence page at mobile and desktop viewports

= 1.5.0 =
* Default-quality release: one fluid band heading scale at every viewport (no more body-size mobile headings), all nine bands on the shared symmetric rhythm with truthful slot contracts, WCAG AA links and legible panels on dark bands, an honest muted theme value (legacy dark renders identically forever), a documented inline-markup contract for supporting text, and graceful single-column degradation for image-less split heroes
* Verified by re-running the audit's rendered evidence pass on a seeded ten-band default page at mobile, tablet, and desktop viewports

= 1.4.0 =
* Operator-trust release: run-token state is durable across containerized WP-CLI invocations (options-table store, bounded locking, honest errors); shipped CSS carries no page-specific demo selectors (lint-enforced) and flat primary buttons are reachable through the documented button slots; validation failures always return the standard error envelope; masked design-token changes warn and surface as an inspect smell; the site icon/favicon is settable through the typed action path
* Verified by a two-process run-state repro, rendered flat-button and demo-ID-parity checks, and an end-to-end typed favicon set

= 1.3.0 =
* First post-1.0 feature release: explicit grid column-count control (1-4, auto grain unchanged when unset), icon-scale grid item images via a new image treatment option and size slot, and hierarchical dropdown menus (set_menu children) rendered as accessible WAI-ARIA disclosures with keyboard support
* Verified by a rendered-evidence dogfood of all three capabilities at desktop and mobile viewports

= 1.2.0 =
* AI chat composition writes are now protected by the write-time compare-and-swap: proposals carry a page baseline captured when the AI read the page, stale writes are rejected with a clear conflict card and a Re-read & re-preview retry, and multi-step proposals chain baselines server-side so they never conflict with their own changes
* Fail-closed: a chat write without a baseline is rejected rather than silently skipping the safety check

= 1.1.0 =
* Executor-level safety hardening (seven-issue gate from the 2026-07-16 complexity audit): data-safety invariants moved to shared choke points — the composition-presence precondition now guards every executor caller including chat, the retired variant prop is rejected at write time while stored legacy pages still migrate on read/restore/render, operate patch shares the real per-action gate and error parity with action execute, and the WP-CLI gate stack's fail-closed branches are unit-pinned
* No new product features; trust/guardrail release verified by the full suites

= 1.0.0 =
* First stable release: the v1.0.0 acceptance gate is closed. Every capability shipped in the 0.16.x series; 1.0.0 certifies that surface rather than adding to it, following three benchmark dogfoods that verified the product materially credible with all trust-class defects resolved
* Version bump and a documentation-freshness pass only — no behavior changed

= 0.16.48 =
* Rollup of the 0.16 series (48 patch releases). Highlights:
* New testimonials component; two-tone headings, eyebrow pills and subheadings, checklist bullets in grid cards, gradient backgrounds, and selectable button styles on hero's two CTAs
* External images can be sideloaded into the Media Library (SSRF-safe), with real responsive srcset output and focal-point/aspect-ratio control
* Page-specific SEO metadata, FAQ rich-snippet structured data (JSON-LD), page slug editing, and a proper blog listing and search results template
* AI chat: explicit user-chosen page targeting, Stop button with automatic fallback for stuck responses, atomic multi-step proposals with rollback (including navigation menus), and menu-building actions
* Per-action capability model for chat endpoints — Contributors can no longer publish, trash, or rewrite the site
* wp pp validate page runs the same rendered-HTML validation that gates the AI chat's success message from the CLI
* 1230 PHP tests, 350 JS tests, 34 E2E specs

= 0.16.0 =
* The site logo is a safe surface: set it via the update_site_option action with a Media Library attachment ID — no theme file edits
* Nav and footer render image logos with text-wordmark fallback

= 0.15.0 =
* Preflight before mutation: no database-backed write lands before the safety gate — page edits need a preflight covering that exact post, site-wide changes need a site-scoped preflight
* Every mutating CLI command requires a run token from wp pp operate inspect

= 0.14.0 =
* True per-run rollback: wp pp apply restore undoes one run's token changes from its pre-apply snapshot instead of resetting the whole palette
* Reset commands record their touched tokens, so a reset can be undone too

= 0.13.0 =
* Brand book fidelity via safe surfaces: honored style slots, token locking, and screenshot-readiness diagnostics (wp pp screenshot doctor)
* Honest verification statuses: VERIFIED / NEEDS_VISUAL_VERIFICATION / SCREENSHOT_FAILED

= 0.12.0 =
* Generic presentation controls: bounded typography, shadow, border, and radius style slots across components
* Button variants (primary, secondary, outline, ghost) as composition props

= 0.11.0 =
* Theme updates are now blocked when local theme files have been changed, so an update can't silently overwrite or delete your edits — with a clear notice listing the affected files and an override filter
* A daily integrity check keeps the "theme files modified" warning current without waiting for an activation or manual check
* If an update is blocked (including a silent auto-update), the admin screen shows when it happened and why
* AI guidance now treats theme template/component/asset files as inspect-only for site work — site changes go through design tokens, fonts, and compositions that survive updates
* 671 PHP tests, 247 JS tests

= 0.10.0 =
* CI now runs the full unit suite on every push and gates releases — a failing test can't reach a published theme
* End-to-end tests run against WordPress 7.0 with a nightly full run and a non-blocking push smoke check
* Destructive AI actions derive their warnings from the action registry, so new destructive actions always warn
* Version consistency enforced across style.css, functions.php, package.json, README, and readme.txt
* 645 PHP tests, 247 JS tests

= 0.9.0 =
* Editor serialization safety gate: the accordion blocks rather than silently corrupting a composition on open, with a JSON-only fallback and per-component diff table
* One-click "Copy as GitHub Issue" from the diff; save/publish re-check against server-normalized state and restore the accordion once clean
* Live preview refreshes on accordion edits; empty/new compositions no longer trip the gate
* 641 PHP tests, 236 JS tests

= 0.8.4 =
* Token family derivation: changing a base color auto-derives related tokens
* Fallback-only semantics: existing overrides preserved, stale warnings surfaced
* Post-apply rendered HTML validation with DOM inspection
* Adaptive CSS: hardcoded blue shadows replaced with token-adaptive color-mix()
* 641 PHP tests, 208 JS tests

= 0.8.3 =
* System prompt hardening with pre-proposal verification checklist
* Cross-component slot search with guided error recovery
* Impossible vs fixable error state visual distinction
* Contextual status bar messages
* 614 PHP tests, 191 JS tests

= 0.8.2 =
* AI context enrichment: style slots, recipes, enum values, and inspect data in system prompt
* Proposal card with preview diffs, impact warnings, and post-apply confirmation
* Style slot validation repair with fuzzy matching and friendly error messages
* 59 style slots across 4 components (was 58)
* 607 PHP tests, 180 JS tests

= 0.8.1 =
* Non-destructive dashboard saves: array field sync selector fix + data-loss guard

= 0.8.0 =
* Theme integrity checking with build-time manifests
* Admin notice for modified theme files

= 0.7.0 =
* 58 per-instance style slots across 4 components
* Style recipes for named visual shorthand
* Font management applies (enqueue, remove, reset)
* Surface classification guardrails

= 0.4.0 =
* WP 7.0 Connectors integration for AI provider credentials
* Anthropic-native streaming transport
* Theme packaging infrastructure (ZIP distribution via GitHub releases)

== Resources ==

No third-party resources are bundled with this theme.
