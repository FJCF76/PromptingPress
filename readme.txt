=== PromptingPress ===
Contributors: fjcf76
Requires at least: 7.0
Tested up to: 7.0
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
