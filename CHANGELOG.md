# Changelog

All notable changes to PromptingPress are documented here.

---

## [v0.4.0] — 2026-06-08 — WP 7.0 AI Connector Integration

### AI provider credentials now managed by WordPress Connectors

PromptingPress no longer manages API keys or provider configuration. WordPress 7.0's Connectors API handles credential storage for Anthropic, Google, and OpenAI. The custom AI Settings page is deleted entirely — configure providers in Settings > Connectors.

The AI Chat header gains provider and model selector dropdowns. Switch between configured providers mid-conversation. When only one provider is configured, the provider selector renders as a static label. Model lists load dynamically from the WP AI Client registry.

Anthropic gets a native transport adapter. The Anthropic Messages API uses `x-api-key` + `anthropic-version` headers, top-level `system` param, and `content_block_delta` SSE events — different from the OpenAI-compatible format used by Google and OpenAI. The streaming layer now detects the provider and speaks the correct protocol.

### Added
- `pp_ai_connector_providers()` — hardcoded provider-to-URL map for Anthropic, Google, OpenAI
- `pp_ai_get_configured_connectors()` — reads configured connectors from WP 7.0 Connectors API
- `pp_ai_get_connector_models()` — queries WP AI Client model registry, filters for text generation
- Provider and model `<select>` dropdowns in AI Chat header with pill styling
- `wp_ajax_pp_ai_switch_provider` — saves provider/model selection, returns model list
- Anthropic-native streaming transport (Messages API format with `content_block_delta` events)
- Markdown rendering in assistant messages (bold, italic, inline code, code blocks, headings, lists)
- Unconfigured state with dashicon, help text, and link to Settings > Connectors
- CSS pill selector styles (`.pp-ai-chat-selector`) with hover/focus/loading/error states

### Changed
- `pp_ai_get_config()` reads credentials from WP Connectors instead of custom wp_options
- `pp_ai_is_configured()` checks connector API keys instead of legacy options
- `pp_ai_stream_completion()` uses provider-aware transport (Anthropic native vs OpenAI-compatible)
- Error messages reference "Settings > Connectors" instead of "AI Settings"
- Quota exhaustion errors distinguished from rate limiting with actionable guidance
- `max_tokens` bumped from 4096 to 16384 for Anthropic requests
- Ordered list numbering preserved across code block interruptions (`<ol start="N">`)

### Removed
- `lib/ai-settings.php` (443 lines) — entire custom AI Settings admin page
- `tests/AiSettingsTest.php` — replaced by connector-focused tests
- Legacy wp_options: `pp_ai_provider`, `pp_ai_base_url`, `pp_ai_api_key`, `pp_ai_model`
- Admin menu item for "AI Settings"

### Tests
- 414 tests, 1238 assertions
- Rewritten `AiProviderTest.php` for connector-only config
- Rewritten `AiChatHandlersTest.php` for provider switch AJAX

### Requires
- WordPress 7.0+ (hard requirement — no backward compatibility)

---

## [v0.3.0] — 2026-06-06 — Agent step enforcement + design token compliance

### AI agents can no longer skip safety steps; design tokens replace all color-mix() calls

The operating loop now enforces step ordering at the PHP/CLI level. Every `wp pp operate inspect` generates a run token (UUID v4 state file in /tmp). Mutating commands (`action execute`, `apply preflight`, `apply execute`, `apply restore`) require `--run-id` and reject if prerequisite steps haven't completed: INSPECT before any mutation, PREFLIGHT before any filesystem apply. State files auto-expire after 2 hours. `pp_validate_loop_run()` now checks viewport coverage against the playbook, checklist completeness for hard-gate items, and caps retry count at 2.

The design system gains 4 derived tokens (`--color-text-secondary`, `--color-accent-strong`, `--color-border-accent`, `--color-surface-accent`) that replace ~70 `color-mix()` calls in components.css. Grid markup now outputs a `data-pp-count` attribute, replacing `:has(nth-child)` CSS selectors with `[data-pp-count="N"]` attribute selectors. All raw hex removed from component styles.

### Added
- `pp_operate_create_run()` — generates UUID v4 run token, writes state file with `LOCK_EX`
- `pp_operate_check_step()` — reads state file, validates step completion with 2-hour expiry
- `pp_operate_record_step()` — appends step to state file using `fopen()`/`flock(LOCK_EX)`
- `pp_operate_cleanup_run()` — deletes state file at HANDOFF
- `pp_operate_run_path()` — centralized path helper for state files
- `pp_operate_valid_run_id()` — UUID v4 regex validation to prevent path traversal
- 4 derived CSS tokens in `base.css`: `--color-text-secondary`, `--color-accent-strong`, `--color-border-accent`, `--color-surface-accent`
- `data-pp-count` attribute on grid `<ul>` element in `grid.php` with `esc_attr()` escaping
- 17 new PHPUnit tests for run token lifecycle, validation hardening, and `--run-id` enforcement (44 total OperateTest)

### Changed
- `wp pp action execute`, `wp pp apply preflight`, `wp pp apply execute`, `wp pp apply restore` now require `--run-id` parameter
- `wp pp operate inspect` always generates and returns a run token in JSON output
- `pp_validate_loop_run()` rejects missing viewport coverage, incomplete checklists, and retry count > 2
- ~70 `color-mix()` calls in `components.css` replaced with semantic tokens or `rgba()` for decorative effects
- `:has()`/`nth-child` CSS selectors replaced with `[data-pp-count="N"]` attribute selectors
- Raw hex values removed from `components.css`
- REVIEW step instructions updated for separated critic pattern
- 6 stale `ApplyTest` expectations updated to match current token values (`#0055cc` → `#3157f4`, `#ffffff` → `#fcfdff`)

### Removed
- All `color-mix()` usage from theme CSS
- `:has()` and `nth-child` selectors from theme CSS

## [v0.2.9] — 2026-06-02 — Agent operating framework v0

### AI agents now follow an 8-step operating loop with screenshot verification

The agent operating framework defines how AI agents operate a PromptingPress site: an 8-step loop (INSPECT, PLAN, EDIT, PREFLIGHT, APPLY, SCREENSHOT, REVIEW, HANDOFF) across 4 roles (Strategist, Implementer, Operator, Reviewer). Three playbooks ship for common operations: `create-page`, `revise-section`, and `inspect-fix`. Screenshot capture at declared viewports (1280px desktop + 375px mobile) provides visual verification evidence. Preflight checks gate filesystem mutations with 6 safety conditions including drift detection.

Hero component gains an overlay scrim for background images, improving text readability over busy photographs. The overlay uses the `--overlay-bg` design token for consistent theming.

### Added
- Agent operating loop (`ai-instructions/operating-loop.md`) — 8 steps, 4 phases, escalation rules
- 3 playbooks: `playbook-create-page.md`, `playbook-revise-section.md`, `playbook-inspect-fix.md`
- `lib/operate.php` — `pp_operate_loop_steps()`, `pp_check_drift()`, `pp_inspect_site()`, `pp_preflight()`, `pp_operate_checklists()`, `pp_validate_loop_run()`
- `lib/screenshot.php` — browser-based screenshot capture with configurable viewports
- WP-CLI commands: `wp pp operate inspect`, `wp pp operate checklist`, `wp pp operate validate`, `wp pp screenshot capture`
- `--overlay-bg` design token (`rgba(0, 0, 0, 0.55)`) in `base.css`
- Hero overlay scrim for background images in `hero.php` and `components.css`
- Bootstrap instruction file (`ai-instructions/bootstrap.md`)
- 26 PHPUnit tests for operate functions (`tests/OperateTest.php`)
- 10 PHPUnit tests for screenshot capture (`tests/ScreenshotTest.php`)
- Hero overlay composition tests (`tests/HeroCompositionTest.php`)

### Changed
- `lib/cli.php` expanded with operate, screenshot, and preflight CLI subcommands
- `functions.php` includes `lib/operate.php` and `lib/screenshot.php`
- `base.css` adds `--measure-body`, `--measure-body-wide`, `--measure-centered`, `--overlay-bg` tokens
- Hero component (`hero.php`) restructured for overlay support with background image
- Premium component treatments in `components.css` (grid lines, card shadows, button gradients)

### Fixed
- Hero title constrained to heading measure for readable line lengths
- Grid column layout issues at various item counts

## [v0.2.8] — 2026-05-13 — Schema narrowing: 12 generic layout knobs down to 2

### AI agents now produce convincing pages with all-default props

The composition schema exposed 12 generic layout knobs (`width`, `spacing`, `content_measure`) across 7 components. Production data showed zero usage of `width` or `spacing` on any non-hero component, and `content_measure: wide` on every hero. This release removes the unused knobs entirely: `width` and `spacing` are gone from section, grid, CTA, stats, logos, and embed; `content_measure` is gone from hero (its `wide` value is now the CSS default). Hero retains `width` and `spacing` because variant geometry genuinely needs them.

Text-only sections now fill the full container width (1088px at desktop), matching grid, table, and CTA. Previously, a prose-readability constraint on the section body made it visibly narrower than adjacent components. The body wrapper is now unconstrained; only the inner prose content block is capped at `var(--measure-centered)` for readable line length.

Backup directory is now configurable via `PP_BACKUP_DIR` constant in wp-config.php, with graceful fallback to `WP_CONTENT_DIR/pp-backups`.

### Added
- `PP_BACKUP_DIR` constant support in `lib/apply.php` for configurable backup directory
- 3 new PHPUnit tests for backup directory configuration (`tests/ApplyTest.php`)
- Data-provider regression tests for section, grid, CTA, stats, logos, embed width/spacing removal
- Consecutive text sections guardrail in `pp_validate_composition_smells()`

### Changed
- Removed `width` and `spacing` props from 6 component schemas: section, grid, CTA, stats, logos, embed
- Removed `content_measure` prop from hero schema; baked `max-width: var(--measure-centered)` as CSS default
- Removed `data-pp-width` and `data-pp-spacing` attribute emission from 6 component PHP templates
- Removed `[data-pp-content-measure]` CSS rules; hero content uses `var(--measure-centered)` by default
- Text-only section body no longer constrained by `max-width`; fills container like all other components
- Simplified `pp_validate_composition_smells()` to remove width/spacing checks for removed props
- Updated AI guidance in `composition.md`, `AI_RULES.md`, `AI_CONTEXT.md`, `build-landing-page.md`
- Preflight error message in `lib/cli.php` now mentions `PP_BACKUP_DIR` constant

### Removed
- 12 generic layout knobs from non-hero components (net schema surface: 12 knobs down to 2)
- Stale tests for removed props in `ComponentPropsTest.php`, `HeroCompositionTest.php`, `GuardrailsTest.php`

### Closes
- #52 — Schema narrowing: reduce generic layout knobs
- #50 (partial) — Configurable backup directory

---

## [v0.2.7] — 2026-05-11 — Desktop width authority: coherent measure system, composition smell guardrails

### Pages built with default composition props now look credible on desktop

Scattered `max-width` rules across components.css (3 different unit types, 11 independent values) are now governed by 3 design tokens in base.css. The AI no longer needs to over-apply composition width/spacing/content_measure props to make pages look presentable at 1280px.

### Added
- `--measure-body` (70ch), `--measure-body-wide` (75ch), `--measure-centered` (56rem) design tokens in `assets/css/base.css`
- `pp_validate_composition_smells()` in `lib/guardrails.php` — detects 3+ consecutive narrow components, 3+ consecutive compact spacing, and hero left-aligned without image
- 12 new PHPUnit tests for composition smell validation in `tests/GuardrailsTest.php`
- Desktop expectations checklist in `ai-instructions/website-building.md`
- Desktop width expectations guidance in `AI_RULES.md`

### Changed
- `.section__content` max-width now uses `var(--measure-body)` instead of hardcoded `70ch`
- `.section--text-only .section__body` max-width now uses `var(--measure-body-wide)` — removed duplicate rule
- `.section--centered .section__body` max-width now uses `var(--measure-centered)` instead of hardcoded `56rem`
- Grid card body padding increased to `var(--space-lg)` (32px) at desktop breakpoints
- `.grid__item-text` line-height unified to 1.6 (matching body text)
- Smell guardrails wired into `PP_Check_Command` and `PP_Validate_Command` in `lib/cli.php`

---

## [v0.2.6] — 2026-05-11 — Ops foundation: target discovery, apply preflight, sync safeguard

### Operators can now preflight mutations and detect theme drift before syncing

Three new WP-CLI commands build the operator contract the AI needs to work safely on live sites:

- **`wp pp target show`** — Auto-discovers canonical target from WP state (site URL, WP root, theme path, environment label). Environment detection cascades: explicit `WP_ENVIRONMENT_TYPE` constant → `WP_DEBUG` heuristic → `wp_get_environment_type()` default.
- **`wp pp apply preflight`** — Three-check gate before any mutation: target resolved, capability OK (WP-CLI bypass + debug log), backup directory writable (probe + cleanup). JSON output with pass/fail per check.
- **`wp pp sync check`** — Drift detection using deployment manifests. Hashes live theme files against last-sync snapshot. Reports modified, added, and deleted files. `--force` to acknowledge drift, `--save-manifest` to record current state.

### Added
- `pp_get_target()` helper in `lib/apply.php` — returns associative array of target state
- `_pp_cli_require_apply_cap()` helper — DRY capability gate with WP-CLI bypass
- `_pp_check_backup_writability()` — probe-based writability check with cleanup
- Deployment manifest system (`_pp_deployment_manifest_path()`, `_pp_load_deployment_manifest()`, `_pp_save_deployment_manifest()`, `_pp_hash_theme_files()`)
- `PP_Target_Command` class with `show` subcommand
- `PP_Sync_Command` class with `check` subcommand (supports `--force`, `--save-manifest`)
- `preflight` subcommand on `PP_Apply_Command`
- 29 new PHPUnit tests in `tests/PreflightTest.php`
- `TODOS.md` with deferred P3 items (configurable backup dir, target set command)

### Fixed
- Capability gate in `wp pp apply execute` blocked WP-CLI operator contexts — extracted to helper with CLI bypass (#43)
- Backup creation silently failed in non-writable directories — preflight now detects this before mutation (#46)
- Theme sync had no drift detection — could overwrite live-only fixes without warning (#47)

### Changed
- Replaced 3 copy-pasted capability gates in `PP_Apply_Command` with single `_pp_cli_require_apply_cap()` helper
- Version synced across all sources: style.css, functions.php (PP_VERSION), package.json

### Closes
- #43 — Typed apply CLI path is brittle in live operator workflows
- #44 — Live-site execution target and mutation surface need an explicit source of truth
- #46 — Typed apply backup creation is permission-fragile in live workflows
- #47 — Repo-to-production theme sync can overwrite live-only fixes too easily

---

## [v0.2.5] — 2026-05-08 — Hero composition props: split ratio, content measure, vertical align, proof slot

### Hero sections now support fine-grained composition control

Four new props give the hero component the high-resolution layout primitives it was missing for polished marketing pages:

- **split_ratio** (split variant only): Control the content-to-image balance with `60-40` or `40-60` ratios. CSS Grid `3fr 2fr` / `2fr 3fr` at desktop (1024px+); stacks normally on mobile.
- **content_measure**: Constrain headline/body width to `narrow` (36rem) or `wide` (48rem). Resets to full width on mobile (<768px) so nothing clips.
- **vertical_align**: Pin content `top` or `bottom` within cover and split heroes at desktop (1024px+). Ignored on centered variant. No effect on mobile — content always flows naturally.
- **proof**: HTML slot for trust signals (logos, star ratings, certifications) rendered as a flex-wrap row below the CTA. Sanitized with `wp_kses_post()`. Empty string = no proof div rendered.

All props use the `data-pp-*` attribute pattern. Invalid values fall back to defaults silently. Props that don't apply to a variant (e.g., split_ratio on centered) are omitted from the HTML entirely.

### Added
- `split_ratio` prop on hero (schema + PHP + CSS)
- `content_measure` prop on hero (schema + PHP + CSS)
- `vertical_align` prop on hero (schema + PHP + CSS)
- `proof` HTML slot on hero (schema + PHP + CSS)
- 17 PHPUnit tests covering all prop validation, attribute output, variant gating, and proof sanitization
- AI_CONTEXT.md updated with new hero props

### Fixed
- Cover variant vertical-align CSS was not gated behind `@media (min-width: 1024px)` — could push content off-screen on mobile. Now wrapped in the same desktop-only media query as split variant.

---

## [v0.2.4] — 2026-05-07 — Quality sprint: CSS rhythm, spacing/width props, centered section, styling authority

### Pages look authored, not templated — without changing any composition JSON

Adjacent-sibling CSS rhythm automatically tightens padding between consecutive components at desktop (768px+). The first component (typically hero) keeps its full padding; subsequent components get tighter spacing. This single CSS rule eliminates the biggest visual gap from dogfooding: every multi-section page looked monotonously spaced.

### Spacing and width props on all section-level components

All 7 section-level components (hero, section, grid, cta, stats, logos, embed) now accept `spacing` (compact/default/spacious) and `width` (narrow/default/full) props. Rendered as `data-pp-spacing` and `data-pp-width` attributes — two CSS rules handle all components instead of 14+ BEM classes. Explicit spacing overrides rhythm defaults via compound selector specificity.

### Section centered layout variant

New `layout: centered` option for the section component. Renders heading + body with centered text alignment, constrained to 56rem. Image is suppressed even when `image_url` is provided — centered is a text-only layout by design.

### Admin notice for CSS conflicts on composition pages

`pp_admin_notice_css_conflicts()` renders a dismissible warning on composition edit screens when Custom CSS targets PP component classes. Scoped via `get_current_screen()` to avoid firing on unrelated admin pages.

### Added

- `--space-3xl: 10rem` design token (19th token)
- Adjacent-sibling rhythm rule: `main > [data-pp-component] + [data-pp-component]` at 768px+
- Text-only typography modulation: `.section--text-only .section__title` at 2.25rem
- `spacing` and `width` props on 7 component schemas + PHP templates
- `data-pp-spacing` / `data-pp-width` CSS selectors with specificity override
- `.section--centered` layout variant (CSS + PHP + schema)
- Admin notice hook for CSS conflicts (`functions.php`)
- WP_DEBUG HTML comment for CSS conflicts in `base.php`
- 26 new PHP unit tests (ComponentPropsTest.php + GuardrailsTest.php extensions)
- Updated JS editor test fixtures for centered layout enum

---

## [v0.2.3] — 2026-05-05 — Substrate reliability: stable IDs, split-authority detection, CSS guardrails

### AI agents can no longer write to the wrong surface or target components ambiguously

This release makes the PromptingPress substrate harder to mis-edit. Three structural gaps exposed during dogfooding are now closed: agents writing to WordPress Custom CSS instead of theme tokens, fragile positional selectors (nth-of-type) because components lacked stable identity, and overclaiming success without validation.

### Stable persisted component IDs

Every composition entry now gets a stable `pp-XXXXXXXX` ID auto-assigned at write time. IDs persist across saves, never shift on reorder, and render as HTML `id` attributes. AI agents can now target specific components by ID instead of brittle positional selectors.

### Split visual authority detection

New `wp pp check conflicts` CLI command detects when WordPress Custom CSS overrides theme component classes. Word-boundary-aware selector matching (not naive substring) avoids false positives. `clear_custom_css` typed action lets agents remediate conflicts through the action model.

### CSS guardrails

- Vitest regression guards: no nth-of-type in theme CSS, no modern CSS features (color-mix, :has, @container), no raw hex in components.css
- `wp pp validate site` CLI command runs automated checks across all composition pages
- `pp_validate_composition_styling()` flags duplicate component types without IDs

### Added

- `lib/guardrails.php`: conflict detection + composition validation (~70 lines)
- `clear_custom_css` as 13th typed action in `lib/actions.php`
- `PP_Check_Command` and `PP_Validate_Command` CLI commands in `lib/cli.php`
- Custom CSS conflict warnings wired into AI system prompt via `lib/ai-context.php`
- `data-pp-component="{name}"` attribute on all 11 component root elements
- `"styling"` section in all 11 component schema.json files (root_class, variant_classes, tokens)
- `ai-instructions/website-building.md`: mutation surface map, stable ID contract, escalation triggers
- `ai-instructions/validate-site.md`: CLI checks + rendered review checklist
- 4 new AI_RULES.md invariants (no positional selectors, no modern CSS, no Custom CSS for theme styling, stable IDs)
- Mutation surfaces section in AI_CONTEXT.md
- Hero mobile padding: `var(--space-xl)` at base, `var(--space-2xl)` at 768px+

### Fixed

- 4 components (faq, table, footer, nav) stored IDs in DB but never rendered them in HTML
- Hero CTA not visible without scrolling at 375px viewport (padding was 112px, now 64px)

### Tests

- 273 PHP tests, 829 assertions (was 256 tests, 785 assertions)
- 64 Vitest tests (was 56)
- New: 13 guardrail tests, 4 ID generation tests, 8 CSS lint regression tests

---

## [v0.2.2] — 2026-04-29 — AI Settings UX + error clarity

### Structured settings replace free-text fields

The AI Settings page now uses dropdowns for Provider and Model instead of four raw text inputs. GitHub Models users see three fields (Provider, API Key, Model). Custom/Manual users see four (+ Base URL). Switching providers swaps the model field type instantly without save+reload.

### Added

- Provider dropdown: GitHub Models (default) and Custom / Manual
- Curated model dropdown for GitHub Models (GPT-5 Chat, GPT-5, GPT-4.1) with "Custom model ID..." escape hatch
- Server-side Base URL derivation — you never see or set the endpoint URL for GitHub Models; PHP handles it
- Automatic migration from older settings format (no manual steps needed on upgrade)
- API Key helper text adapts per provider ("GitHub PAT with models:read" vs "Bearer token")
- Test Connection tells you to save first and disables itself until you do
- `pp_ai_get_providers()` single source of truth for provider config
- 13 new tests for migration, provider data, and sanitize callback

### Fixed

- **#17** Test Connection now works with GPT-5 models (was sending a parameter the model rejected)
- When the AI can't reach your provider, the chat now shows a clickable link to AI Settings instead of a raw error
- Clearer error messages for bad API key, wrong model, and rejected requests
- Base URL row hides correctly when GitHub Models is selected (JS selector fix)

### Changed

- Default model updated from `openai/gpt-4o` to `openai/gpt-5-chat`
- Default provider constant from `'GitHub Models'` to `'github_models'`
- Settings section description simplified

### Tests

- 256 tests, 785 assertions (was 243 tests, 730 assertions)

---

## [v0.2.1] — 2026-04-27 — Media-aware page editing

### AI can see and use your images

The AI chat now sees your WordPress media library. When you ask it to build a page or add a component, it picks real images from your uploads instead of hallucinating URLs. The system prompt includes every image's filename, dimensions, alt text, and exact URL, with rules for which components use images as backgrounds vs foreground elements.

### Added

- Media library inventory wired into AI system prompt with per-image filename, dimensions, alt text, and URL
- Image selection rules in system prompt: foreground vs background rendering, alt text requirements, component-specific prop mapping
- Component index in page context so the AI can unambiguously target components by index (`[0] hero | variant: cover`)
- Media URL validation on action execution — rejects hallucinated upload URLs before they hit the database
- Composition normalization (`pp_normalize_composition`) — accepts `"type"` as alias for `"component"` in composition arrays, canonicalizes on input
- Page-existence validation (`_pp_validate_page_exists`) on all 7 page-scoped actions
- Truncation detection (server-side + client-side) — shows informational message when AI response is cut short before proposal JSON
- Component summary helper (`_pp_summarize_component`) for the page context index

### Fixed

- AI-generated pages using `{"type": "hero"}` instead of `{"component": "hero"}` now work (normalization catches the alias)
- Actions against nonexistent page IDs now return clear error messages instead of cryptic failures

### Tests

- 23 new unit tests: 10 for page-existence validation, 8 for composition normalization, 5 for media library context and component summaries

---

## [v0.2.0] — 2026-04-26 — In-admin AI chat

### Talk to your site, change it from the conversation

You can now open **PromptingPress → AI Chat** in the WordPress admin, ask your site questions ("What pages do I have?", "What are my design tokens?"), and request changes ("Add a hero section to the About page", "Change the accent color to orange"). The AI reads your real site state, proposes structured mutations with preview cards, and executes them through the existing action/apply layer when you click Apply.

### Streaming chat with proposal cards

Responses stream token-by-token via SSE. When the AI proposes a change, you see a card with the action name, description, and Apply/Cancel buttons. Multi-step proposals show numbered steps with "Apply All". After applying, the AI knows about its own mutations and can build on them in the same conversation.

### BYOK provider configuration

**PromptingPress → AI Settings** lets you configure any OpenAI-compatible provider. Pre-filled defaults for GitHub Models (`openai/gpt-4o`). Fields: provider name, base URL, API key (server-side only, never sent to browser), model ID. Test Connection button verifies your setup.

### Conversation persistence

Messages persist in localStorage across page reloads. "New Chat" clears the conversation. Internal apply-confirmation messages are stored for AI context but hidden in the display.

### Page lifecycle actions

Three new typed actions: `trash_page` (move to trash, reversible), `restore_page` (restore from trash), `unpublish_page` (revert to draft). All support validate, preview, and execute. Available via WP-CLI, AJAX, and AI chat.

### Security

- API key stored server-side in `wp_options`, never exposed to browser
- Nonce separation: `pp_ai_stream` (read) vs `pp_ai_execute` (mutate)
- Role whitelist: only `user` and `assistant` roles accepted from client conversation
- XSS prevention: all chat rendering uses `textContent`, never `innerHTML`
- Provider error messages sanitized with `wp_strip_all_tags()`
- Capability gates: `manage_options` for settings, `edit_posts` for chat/execute

### 211 unit tests, 684 assertions

69 new tests covering AI context assembly, provider error paths, proposal parsing/validation, page lifecycle actions, nonce separation, and system prompt consistency.

### Known deferrals

- #15 — Markdown rendering in chat messages (content correct, just unformatted)
- #16 — Unit test coverage gaps (pp_ai_coerce_params, AJAX fallback, capability-denial paths)
- #14 — JS/frontend test coverage for chat UI

---

## [v0.1.7] — 2026-04-19 — Bounded design token mutation

### Programmatic write path for the design system

The AI interface can now change how the site looks, not just its content. `pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309'])` changes the accent color and the site visibly reflects it. Backup, verification, and restore are automatic.

### Apply layer (file-based mutations)

New adjacent execution contract in `lib/apply.php` for file-based mutations. Same architectural DNA as the action model, but for files instead of database. Validates params, creates backup, writes the file, verifies the full contract (target changed AND every non-target unchanged), and auto-restores on any violation.

### Safety model

- Backup to `wp-content/pp-backups/` before every write (keeps last 5)
- Verified backup before proceeding
- Full contract verification after write
- Auto-restore from backup on any failure
- Injection prevention: rejects `{`, `}`, `;` in values
- No-op detection: setting a token to its current value returns success with empty changes

### Token type metadata

All 18 design tokens now carry machine-readable type annotations in their CSS comments: `color`, `length`, `font-family`, `duration`, `raw`. Type-specific validation enforces correct CSS values (hex, rgb, rem, font stacks, etc.).

### WP-CLI

```bash
wp pp apply list                                                              # see registered applies
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'  # diff without writing
wp pp apply execute update_design_token --params='{"token":"--color-accent","value":"#b45309"}'  # apply + verify
wp pp apply restore                                                           # undo last change
wp pp apply restore --point=2                                                 # restore specific point
wp pp apply restore --list                                                    # show available points
```

### Richer `pp_design_tokens()` return shape

Returns `['--token' => ['value' => string, 'type' => string|null]]` instead of flat key-value. Only 1 real caller existed (the new apply layer), so zero breakage.

### Browser cache busting

`base.css` enqueue now uses `PP_VERSION.filemtime()` suffix, so token changes are immediately visible without hard refresh.

### 142 unit tests, 509 assertions

57 new tests covering registry, validation (structural + type-specific), injection prevention, preview, execute, contract verification, backup pruning, cache invalidation, restore, and return shape.

---

## [v0.1.6] — 2026-04-18 — Typed action model, WP-CLI, and AJAX refactor

### One write path for everything

All mutations now go through typed actions. The composition editor, WP-CLI, and future AI callers all use the same `pp_execute_action()` layer. Every action validates before writing, returns the same structured result shape, and supports preview (see the diff without writing).

### 9 actions

You can now create pages, update compositions, add/remove/reorder components, update titles, publish pages, and change site options, all through one consistent interface. Each action declares its params, validates inputs, and returns a canonical `{ok, action, scope, target, changes, error}` result.

### WP-CLI interface

```bash
wp pp action list                                    # see all 9 actions
wp pp action preview update_component --params='{}'  # see the diff, never writes
wp pp action execute create_page --params='{"title":"New Page"}'
```

### AJAX handlers are now thin adapters

The 3 mutation AJAX handlers (`pp_save_composition`, `pp_save_title`, `pp_publish_page`) delegate to the action layer. Same POST params, same JSON response shape, zero JS changes. The editor works exactly as before, backed by a canonical architecture.

### Site-state read layer

New `pp_*` functions for querying site state: `pp_get_composition($post_id)` (composition for any page by ID), `pp_composition_pages()` (all composition pages), `pp_design_tokens()` (CSS custom properties from base.css), `pp_site_option($key)` (whitelisted options).

### 85 unit tests, 367 assertions

Full coverage of all 9 actions across validate, preview, and execute paths, plus edge cases (reorder permutation validation, OOB rejection, null-removes-prop, partial merge).

---

## [v0.1.5] — 2026-04-10 — Accordion editor for structured composition editing

### Accordion replaces the reference pane

The three-pane editor layout (JSON | Reference | Preview) is now two panes (Accordion | Preview). The reference pane, which showed static schema info, is gone. In its place, the editor pane defaults to an accordion view that renders each composition component as a collapsible card with typed form fields.

The accordion is a structured lens over the canonical JSON, not a replacement for it. A toolbar toggle switches between accordion and CodeMirror views. Edits in either view sync to the other. JSON remains the single source of truth.

### What the accordion does

- **Collapsible cards** for each component. Header shows component name + first prop value preview (truncated at 40 chars). All cards start collapsed on load.
- **Typed fields**: string inputs, multi-line textareas (for `body`, `content`, `answer`), enum dropdowns, and repeatable array sub-forms with add/remove item buttons.
- **Required field indicators**: red asterisk on labels, red border on blur when empty.
- **Component operations**: insert (dropdown at top and bottom, all 11 components), move up/down, delete. Each operation preserves expand/collapse state across re-renders.
- **JSON toggle round-trip**: accordion to JSON to edit to accordion, no data loss. Invalid JSON keeps you in JSON view with validation errors.

### Accessibility

Full WAI-ARIA accordion pattern: `aria-expanded`, `aria-controls`, `role="region"`, `aria-labelledby` on every card. Screen reader announcements via `aria-live="polite"` region on insert, reorder, and delete. Move/delete buttons have descriptive `aria-label` attributes. ARIA live region uses `.sr-only` clip pattern, invisible to sighted users.

### Pure logic extraction

`buildAccordionData()` and `serializeAccordionData()` added to `pp-editor-logic.js` as pure, testable functions with no DOM dependencies. 56 unit tests pass including round-trip, unknown component, and array field coverage.

### Removed

- Reference pane (`.pp-pane--reference`), component list, schema tab, second resize handle
- `initSidebar()`, `updateSchemaTab()`, `getNearestComponentName()` functions
- ~80 lines of reference pane CSS

---

## [v0.1.4] — 2026-04-04 — Phase 2 component capabilities + design token consistency

### 7 component capabilities added

This release closes the component capability gaps identified during the benchmark sprint. Every change is a reusable first-class addition to the component system, not benchmark-specific polish.

- **Hero dual CTA** — `cta2_text` + `cta2_url` props render a secondary outline button alongside the primary CTA. On `cover` variant, the outline button gets white border/text for visibility over the dark overlay.
- **Nav image logo** — `logo_url` + `logo_alt` props. When `logo_url` is set, renders an `<img>` instead of text. Falls back to `logo_text` when empty.
- **Grid background themes** — `theme` prop (`default`, `dark`, `inverted`) controls background color independently of `variant` (which controls layout). Follows the same dual-axis pattern established by CTA.
- **Grid steps connectors** — `steps` variant now renders `→` arrow pseudo-elements between cards at desktop (≥1024px). Connectors use `--color-muted` and suppress on mobile.
- **Stats background image** — `background_image` prop with the standard overlay pattern (inline style + `.stats__overlay` div + `var(--overlay-bg)`).
- **Logos variants** — `variant` prop (`default`, `dark`, `inverted`) for background control on logo strip sections.

### Design token: `--overlay-bg`

All 4 components with background-image support (hero, section, cta, stats) now reference `var(--overlay-bg)` instead of hardcoded `rgba()` values. This is the 18th design token in `base.css`. A site-builder AI can now control overlay darkness from one place during retheme.

### AI instructions: multilingual orthography verification

New Step 5 in `build-landing-page.md` for verifying diacritics, accent marks, and language-specific punctuation when generating non-English composition content. Cross-referenced from `composition.md`.

### Documentation

- `AI_CONTEXT.md` updated with all new props, dual-axis pattern for grid, background-image recipe for 4 components, and 18-token count
- `composition.md` component reference table updated with all 11 components and correct props
- `retheme.md` and `AI_RULES.md` updated to reflect 18 design tokens

---

## [v0.1.3] — 2026-04-01 — Composition-first page editing + homepage bootstrap

### Composition editor as the page editing experience

PromptingPress treats the composition editor as the page editing experience, not a mode
you opt into per page. This release makes that clearer through the editor's action model.

Draft pages show **Publish** as the primary action and **Save Draft** as secondary.
Published pages show only **Update**. After you publish a draft, the editor switches into
the published state immediately — no page reload.

### Fresh installs get a real Home page

When no valid static front page exists, activating the theme now creates one: a published
page titled "Home", assigned the Composition template, set as the site front page in
Reading Settings. The page appears in the Pages list and is immediately editable through
the composition editor.

Previously, a site with no real front page silently appeared healthy from the front end.
Now, if no static front page is configured, the condition is visible — admins see a
message with a link to fix it.

### Fix: Pages → Add New was restricted to administrators only

The handler that creates a new draft and opens the composition editor was checking
`create_pages`, which is not a real WordPress capability. In practice this restricted the
flow to administrators only. Now correctly checks `edit_pages`.

---

## [v0.1.2] — 2026-03-30 — Section and grid composition primitives

### Added: `section.variant` — per-section background control

Sections can now carry their own background tone, enabling visual rhythm on multi-section pages without touching CSS. Set via `variant` prop in composition JSON:

- `default` — page background (`--color-bg`). No class added. Backward-compatible default.
- `dark` — surface background (`--color-surface`) with a 1px border above and below. Subtle differentiation.
- `inverted` — inverted background (`--color-bg-inverted`). Strong contrast. Full text/heading color override included.

```json
{ "component": "section", "props": { "body": "<p>...</p>", "variant": "dark" } }
```

New design token: `--color-bg-inverted` (8th color token in `base.css`). Set this alongside the other 7 color tokens when rethemeing.

### Added: `grid.variant: "steps"` — numbered process cards

Grid now renders as a numbered step sequence when `variant: "steps"` is set. Use for How-It-Works flows, onboarding sequences, or any ordered process.

- Step number rendered per item (`number` field, or auto-indexed from 1)
- Images suppressed in steps mode — title + text only
- Number styled with `--color-accent` for visual anchor

```json
{ "component": "grid", "props": { "variant": "steps", "items": [
  { "number": "1", "title": "Sign up", "text": "Create your account." }
] } }
```

### Fixed: `pp-section--dark` invisible on light theme

On the default light palette, `--color-surface` (#f9fafb) and `--color-bg` (#ffffff) are nearly identical (1.04:1 contrast). Added 1px `--color-border` top/bottom borders to `.pp-section--dark` so the boundary reads on any palette.

### Added: Bootstrap state contract

`ai-instructions/bootstrap.md` — a machine-readable state contract with WP-CLI verification commands for every required site state (theme, options, homepage, composition data, menus). Lets any AI provision a fresh PromptingPress site from zero without guesswork.

---

## [v0.1.1] — 2026-03-28 — JS test infrastructure + bug fixes

### New: JS unit test suite (Vitest, 38 tests)

Pure-function logic extracted from `pp-admin-editor.js` into `assets/js/pp-editor-logic.js`:
`getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`. All three are
covered by 38 unit tests in `tests/js/pp-editor-logic.test.js` using Vitest 3.x — no bundler,
no build step.

```
npm install
npm test
```

### Fix: Global namespace pollution (ISSUE-002)

The three extracted functions were leaking into `window` scope as bare globals
(`window.getJsonContextFromText` etc.) because they were top-level `function` declarations
in a plain `<script>` tag. Wrapped in an IIFE — functions are now scoped and only
`window.PPEditorLogic` is exported to the browser. Node/CJS path for Vitest is unaffected.

### Fix: afterColon bug in props-key context walker

The original props-key context walker treated every position after a `:` as a value slot,
even after a `,` reset. Cursor placed immediately after a comma (at the start of a new key)
was returning `null` instead of `{ type: 'props-key', componentName }`. Fixed and covered
by tests.

### Fix: Null/false/"" treated as absent for required props

`validateCompositionData` now rejects required props whose value is `null`, `false`, or `""`
in addition to missing keys. This matches the PHP-layer validation contract documented in
`ai-instructions/composition.md`.

### Fix: Array.isArray guard for prop values

`validateCompositionData` now rejects array-typed required prop values that are `[]` (empty).

### Fix: window.module collision

The Node/CJS export guard now checks `process.versions.node` instead of `typeof module`,
preventing WP plugins that define `window.module` from stealing the exports branch.

### Fix: bracketPos guard in getInsertPosition

`getInsertPosition` returns early with `bracketPos: -1` when no `[` is found, rather than
returning `afterIdx: -1` with an empty `itemEnds` that could confuse callers.

---

## [v0.1.0] — 2026-03-28 — Composition Editor beta

### New: In-admin JSON composition workspace

You can now build and edit pages directly from the WordPress admin without touching a file. Any page using the **Composition** template gets a full-screen three-pane editor:

- **Left:** CodeMirror JSON editor with syntax highlighting, real-time validation, and component name autocomplete (Ctrl+Space)
- **Center:** Component reference sidebar — shows all registered components, their props, required/optional status, and types
- **Right:** Live preview iframe — updates as you type (debounced, only on valid JSON)

The editor validates compositions before saving: unknown components, missing required props, and syntax errors are all caught with inline error messages. Invalid compositions are rejected — the database always holds the last valid value.

Keyboard shortcut: **Ctrl+S** saves from anywhere in the editor.

**Files shipped:** `lib/admin.php`, `assets/js/pp-admin-editor.js`, `assets/css/pp-admin-editor.css`, `composition.php`, `templates/composition.php`, `ai-instructions/composition.md`

### Polish: Design review pass on the workspace

Seven contrast, hit-target, and polish issues found and fixed:

- Pane headers, component descriptions, prop types, and schema placeholder text now meet WCAG AA contrast ratios against the dark editor background
- Resize handle hit area expanded from 4px to 20px (±8px pseudo-element) — much easier to grab
- CodeMirror line numbers lightened for better legibility

### Fix: Stale "Fix errors first." message after errors resolve

When a user fixed invalid JSON after a blocked save, the red "Fix errors first." status text stayed visible indefinitely — even with the error bar cleared. It now clears as soon as validation passes.

---

## [v0.0.1] — 2026-03-24 — Foundation

### New: Complete theme foundation

Full WordPress theme with a component system, WP abstraction layer, design token system, and AI context map.

- **Component system:** 8 registered components (hero, section, faq, grid, table, cta, nav, footer) — each with `schema.json`, typed props, and CSS variables only (no raw hex)
- **WP abstraction layer:** `lib/wp.php` with `pp_*` wrappers — templates never call WordPress directly
- **Design tokens:** 16 CSS custom properties in `base.css` control the entire visual system
- **AI_CONTEXT.md:** Machine-readable site map so any AI can orient in seconds

### Design polish

- Nav logo touch target raised to 44px for mobile
- Grid item titles scaled up for clearer hierarchy
- `text-wrap: balance` on all headings
- `prefers-reduced-motion` media query on all animations
- FAQ accordion entrance animation (CSS-only, fade + slide)
- Inner page hero padding reduced for better proportion

### Fix: 404 page with home CTA

Added `404.php` with a helpful error message and a link back home, replacing the bare WordPress default.
