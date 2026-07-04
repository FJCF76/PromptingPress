=== PromptingPress ===
Contributors: fjcf76
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.16.1
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
