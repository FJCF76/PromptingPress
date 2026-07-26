# Changelog

All notable changes to PromptingPress are documented here.

---

## [v1.7.9] — 2026-07-26 — native screenshot readiness is a definitive available / unavailable / broken state, never an ambient warning (#497)

**Native screenshot capture depends on a browser command you configure (`PP_BROWSER_CMD`). On installs where it was never set up, every visual-verification step emitted the same "not configured" warning forever, so operators routed around the product's own evidence path with external tooling. Screenshot readiness now reports exactly one definitive state — available (configured and a real capture succeeded), unavailable (not configured, with candidate binaries found on your `$PATH` and the one-line setup step), or broken (configured but failing, with the concrete error). `wp pp screenshot doctor` is the in-band setup-and-diagnose surface: it probes by default, so "configured" always means "actually captured a screenshot," not "resolves on paper."**

`doctor` now runs a real minimal capture by default (pass `--no-probe` for a fast capability-only check) and, when nothing is configured, lists candidate browser binaries it found on `$PATH` as discovery hints — clearly marked as needing to be wrapped to the adapter contract, since PromptingPress blesses no specific tool. `wp pp apply preflight` surfaces the same tri-state as a non-blocking capability finding: `unavailable` and `broken` render distinctly instead of collapsing into one repeated warning, each pointing at `wp pp screenshot doctor`. Preflight stays read-only and never launches a browser — it does the cheap check (does the command resolve, is its binary on `$PATH`) and leaves capture-verification to `doctor`. A configured command whose binary is missing is reported `broken` without ever running a process, but a working setup that uses a shell form the cheap check can't resolve (an env-var prefix, a bare name on a different runtime `$PATH`) is proven by the probe rather than mislabeled. A capture that "succeeds" but writes zero bytes is treated as broken, so the operating loop can never claim native `VERIFIED` off a non-working browser.

### Fixed
- Screenshot readiness (`pp_screenshot_readiness()`, `wp pp screenshot doctor`, and the preflight `screenshot_readiness` finding) now reports an explicit `state` of `available` / `unavailable` / `broken` instead of a single not-configured warning. A stable unconfigured install reports `unavailable` as a capability STATE, not a per-run nag; `unavailable` and `broken` render as distinct capability-class findings in preflight, each with `next_action: wp pp screenshot doctor` (#497).

### Added
- `wp pp screenshot doctor` probes by default (runs a real minimal capture so `available` vs `broken` is definitive) and, when `PP_BROWSER_CMD` is unconfigured, detects candidate browser binaries on `$PATH` as setup hints. `--no-probe` keeps the fast, no-browser-launch capability check. It stays read-only: the probe writes a temp file it deletes immediately (#497).

### Docs
- `docs/screenshot-setup.md` documents the tri-state, the default-probe behavior, `--no-probe`, and the candidate-binaries aid. `docs/running-an-ai-agent.md` gains a "configure it once" section so operators find the setup path where they look, and `docs/reference-apply-cli.md` plus `ai-instructions/operating-loop.md` describe the preflight tri-state finding (#497).

### Tests
- `tests/ScreenshotTest.php`: the three states through `pp_screenshot_readiness()` (unconfigured → `unavailable`; configured-but-binary-missing → `broken` with no process launched; a fake adapter that writes a PNG → capture-verified `available` with a byte count; a failing probe → `broken`), a regression that a shell-form command whose first token isn't on `$PATH` is `broken` on the cheap check but arbitrated to `available` by the probe, candidate detection returns a list, quote-aware first-token parsing, and the preflight `screenshot_readiness` finding carrying `unavailable` / `broken` distinctly without preflight launching a browser (#497).

## [v1.7.8] — 2026-07-26 — readiness warnings are now classified, actionable, and acknowledgeable (#496)

**Preflight and readiness warnings used to arrive as an undifferentiated pile: theme-file drift, a site-configuration gap, and a missing environment tool all looked the same and persisted run after run, so operators learned to ignore all of them — which masks the one that matters. Every finding now carries a class (integrity, configuration, or capability) and a sanctioned next action. Integrity drift has a one-command re-baseline that records the installed release, so drift always means "changed since this release." A deliberate configuration gap (a purposely menu-less footer, say) can be acknowledged as intentional and stops showing as a warning, reversibly. A completed operation now reports zero unexplained warnings.**

Preflight output gains a `findings` block that groups the warning-grade rows by class, each with a per-finding `next_action`. A new `wp pp readiness` command family is the operator surface: `status` (read-only) prints the grouped findings with active-vs-acknowledged counts; `rebaseline` re-snapshots the deployment manifest against the currently-installed release and records its version; `acknowledge` / `unacknowledge` record a configuration finding as intentional and reverse it. Read-only semantics are preserved — status, inspect, and preflight never mutate; only the three explicit commands write. Only configuration findings are acknowledgeable (integrity is resolved by re-baselining, capability by installing the tool), and a finding can only be acknowledged if it is currently present, so the acknowledgement store cannot fill with stale keys. The classification also flows into the post-apply validation report, where an acknowledged finding drops out of the warning list entirely.

### Added
- Readiness/preflight findings are classified into **integrity** (theme-file drift vs the recorded release baseline), **configuration** (site-state gaps like an unassigned menu location), and **capability** (a missing environment tool such as a screenshot browser). Preflight and `wp pp operate inspect` output carry a top-level `findings` block grouping the warning-grade rows by class, each with a sanctioned `next_action`; the drift result and the deployment manifest now record the installed `release_version` (#496).
- New `wp pp readiness` command family: `status` (read-only grouped findings + active/acknowledged counts), `rebaseline` (re-baseline the deployment manifest against the installed release — the sanctioned reconciliation for integrity drift), `acknowledge <finding-key> [--note]`, and `unacknowledge <finding-key>` (record a configuration finding as intentional, reversibly). Only currently-present configuration findings are acknowledgeable; status/inspect/preflight never mutate (#496).

### Docs
- `docs/reference-apply-cli.md` documents the `findings` block, the `release_version` field, and the full `wp pp readiness` command family. `ai-instructions/operating-loop.md` and `ai-instructions/validate-site.md` describe the class model, the acknowledge/rebaseline flow, and the new commands; `AI_CONTEXT.md` points the runtime agent at them (#496).

### Tests
- `tests/ReadinessFindingsTest.php`: a new suite covering the classification helpers (grouping, acknowledgement enrichment, currently-present configuration keys), each class through `pp_preflight` (integrity drift with and without a recorded release version, unassigned/unregistered/empty-menu and non-image-logo configuration findings, the missing- and ready-capability screenshot cases), the manifest recording its release version, and the real `wp pp readiness` CLI surfaces (status is read-only, acknowledge/unacknowledge round-trip and reject invalid keys, rebaseline records the release and clears drift until a genuine post-baseline change). `tests/PostApplyValidateTest.php`: an acknowledged configuration finding is suppressed from the post-apply warning list (#496).

## [v1.7.7] — 2026-07-26 — a corrupt homepage is never silently overwritten by the blank-page safeguard (#506)

**A homepage whose stored composition became unreadable used to be destroyed the moment anyone viewed it. The homepage template seeded a default composition whenever its read came back empty — but a corrupted composition (truncated JSON, wrong shape) reads back empty too, so the very next page view, even from a logged-out visitor, overwrote the recoverable stored bytes with defaults. The corruption looked "healed" while the real content, and any chance to recover it, was gone. This release makes the safeguard classify the stored composition before it ever writes: it seeds only when the page is genuinely blank, and a corrupt page renders a safe, non-destructive diagnostic while leaving the stored bytes exactly as they were.**

The safeguard now reads through the state-classifying decoder that already backs `inspect`, so it can tell a genuinely-absent composition apart from a corrupt one. Only genuine absence seeds the default homepage, and that seed goes through the versioned composition writer (version marker plus history ring), never a raw meta write — the same path theme activation already uses. A corrupt composition performs zero writes: the recoverable payload stays intact for restore, and `inspect` keeps reporting the exact decode error, so the render surface and the diagnostic surface finally agree. The "a newly created page is never blank" promise still holds for the genuinely-absent case. On a corrupt homepage, logged-in admins see a diagnostic notice pointing at `wp pp operate inspect` and history restore; anonymous visitors see the site chrome with an empty body rather than a page rebuilt from data loss — the deliberate tradeoff for never destroying recoverable content.

### Fixed
- The homepage blank-page safeguard no longer overwrites a corrupt or undecodable stored composition on render. It classifies the composition first (via `pp_get_composition_result()`), seeds the default only when the composition is genuinely absent, and routes that seed through the versioned writer instead of a raw `update_post_meta()`. A corrupt homepage renders a non-destructive diagnostic with zero writes, so the recoverable bytes survive and `inspect` still reports the corruption (#506).

### Tests
- `tests/FrontPageSafeguardTest.php`: a new front-page safeguard suite — a corrupt composition (undecodable JSON, a JSON object, and a bare scalar) renders the safe fallback with the whole post-meta store byte-identical afterwards and `inspect` still reporting the error; genuinely-absent meta seeds once through the versioned writer (version marker set to 1, seed validates through `pp_validate_composition()`) and never re-seeds on a second render; a stored empty `[]` renders blank without being re-seeded; a lock-failed seed still renders the defaults while leaving the meta absent for a retry; and the render path resolves legacy CTA props exactly as `pp_composition()` does (#506).

## [v1.7.6] — 2026-07-26 — pre-1.0 pages stay editable: a bounded legacy CTA-prop compatibility map (#495)

**A page built before v1.0 could be locked out of a simple, safe edit. Early CTA blocks stored `cta_text`/`cta_url`; v1 renamed those props to `button_text`/`button_url` and the strict validator (which rejects unknown props to stop phantom fields) then refused the WHOLE page — so a targeted edit to an unrelated section was rejected because some other CTA still carried the old names. This release ships a bounded, audited compatibility map for exactly those pre-1.0 CTA renames. Validation and rendering now accept the old names, and any edit that touches a component rewrites its props to the current names, so the page heals one edit at a time. Genuinely unknown props are still rejected exactly as before.**

The map is deliberately small and closed: only the CTA block, only `cta_text`→`button_text` and `cta_url`→`button_url`. It is per-component on purpose — the hero block uses `cta_text`/`cta_url` as its own current props, and those are never touched. A targeted single-component edit heals only the component it touches and leaves every other component's stored shape alone, so there is no surprise whole-page rewrite; a full-page save (or an undo/restore) heals the whole composition at once. A page that still stores the old names renders its real button now instead of a default label. A CI guard makes this the last such surprise: any future change that renames or removes a component prop without shipping a compatibility entry (or an explicit migration note) fails the test suite, so the "add compatibility when you rename" rule is enforced by the build, not by memory.

### Fixed
- A targeted safe edit to any component on a pre-1.0 page no longer fails because an unrelated component still carries the old CTA prop names. Validation and rendering accept the bounded legacy names (`cta.cta_text`/`cta.cta_url`), and a write that touches a component canonicalizes its props to `button_text`/`button_url`; untouched components keep their stored shape and heal on their next edit. Unknown, non-mapped props are still rejected with the same error (#495).
- A pre-1.0 CTA that still stores `cta_text`/`cta_url` now renders its authored button label and link on the front end instead of the renderer's default (#495).

### Tests
- `tests/ActionsTest.php`: legacy-shaped compositions exercised through the real action surface — a targeted edit succeeds while an untouched legacy CTA is present (and keeps its stored shape), a write that touches the CTA heals it to canonical, a legacy-named edit to an already-healed component lands instead of being silently dropped, canonical-wins on conflicting old+new props, `hero`'s current `cta_text`/`cta_url` are never rewritten, `add_component`/`update_composition`/`create_page` heal on write, unknown non-mapped props still reject, `reorder` preserves untouched legacy props, and `restore_composition` heals a legacy snapshot (#495).
- `tests/ComponentPropsTest.php`: a resolved legacy CTA renders the authored button label and URL, not the default (#495).
- `tests/WpAbstractionTest.php`: `pp_composition()` resolves legacy CTA props for rendering without mutating stored data, and leaves `hero`'s current props untouched (#495).
- `tests/SchemaValidationTest.php`: the legacy-alias inventory is pinned, the schema-rename drift-catcher fails a simulated rename that ships no compatibility entry (and a symmetric guard forces new props into the pinned baseline), and the alias helper's malformed-input guards are covered (#495).

## [v1.7.5] — 2026-07-23 — the default homepage seed is now a curated branded multi-band starter (#512)

**A fresh PromptingPress activation used to seed a three-component homepage (hero, one section, one CTA) that proved the composition system worked but presented the product like a rough default. `pp_default_homepage_composition()` now returns a curated six-band branded starter: a dark split hero with a workflow proof surface, an audience/problem band, a files-vs-WordPress mechanism band, a speed/trust card grid, a maintainability/proof band, and a closing CTA. A new install now opens on a page that reads as a polished branded product, not a placeholder, so a first-time operator immediately understands what PromptingPress is, who it is for, and why structured composition matters.**

The upgrade is composition data only — the branded look (warm-cream surfaces, ink darks, a restrained orange accent) is expressed entirely through VALIDATED per-component style slots and native component features (the hero's own `hero__surface-*` proof classes, a native `text-panel` monospace spec panel, grid cards with checklist bullets, and `body_items` meta strips), never one-off inline HTML, homepage-only shared CSS (#72), or render-time mutation. The whole composition passes `pp_validate_composition()`. Activation still seeds it once through the real composition writer and never overwrites an existing valid static front page, so this changes only future FRESH installs — an already-configured live site keeps its own homepage. Note: on a fresh install the hero's primary button uses outline styling because a filled hero button's accent fill is driven by the global `--color-accent` design token, which a fresh install does not override (tracked separately in #514); the one filled orange button is the closing CTA.

### Changed
- `pp_default_homepage_composition()` (`lib/wp.php`) now returns a curated six-band branded starter composition (split hero, audience section, mechanism `text-panel`, speed/trust grid, proof section, closing CTA) styled through validated per-component style slots, replacing the minimal three-component seed. It remains the single source of truth for activation seeding and the blank-page fallback (#512).

### Docs
- `AI_CONTEXT.md`: the `pp_default_homepage_composition()` reference row now describes the multi-band branded starter instead of the old `(hero, section, cta)` shape (#512).

### Tests
- `tests/SetupTest.php`: added an activation-path test that runs `pp_setup_homepage()` on a fresh state and asserts it creates the published `Home` page, assigns `composition.php`, writes the branded multi-band seed through the composition writer (freshness marker initialized), and sets `show_on_front`/`page_on_front`; plus an idempotent-guard test proving an existing valid static front page is never overwritten (#512).
- `tests/SchemaValidationTest.php`: the default-homepage guard now asserts the seed is a multi-band composition (hero/grid/cta present, 5+ bands) and that every band's style slots survive the render boundary, so a regression to a thin stub or a silently-dropped style value fails the suite (#512).

## [v1.7.4] — 2026-07-23 — the theme now ships its Appearance → Themes card image (#511)

**Installed under Appearance → Themes, PromptingPress showed a blank theme card: the packaged theme carried no root-level `screenshot.png`, the static image WordPress renders for the theme tile and details view. A theme can seed a flawless homepage and still look broken in the one screen every operator sees first. The distributable ZIP now includes a real `1200×900` `screenshot.png` — a branded capture of the live PromptingPress homepage (the dark hero: "Turn AI-assisted site drafts into maintainable WordPress composition", the run/brief/props panel, the GitHub CTA) — so the card reads as a polished product the moment the theme is installed.**

The image is a genuine homepage render, not a placeholder gradient or checkerboard, so the tile communicates real product quality at a glance. A packaging guard now backs it: the package smoke test asserts `promptingpress/screenshot.png` is present in the built ZIP alongside `style.css`, `index.php`, and the other required files, so a future release cannot silently drop the card image again. This is the WordPress theme-card screenshot only — it is a separate concern from the operational `wp pp screenshot` verify-with-evidence capture, which is unchanged.

### Fixed
- The packaged theme now includes a root-level `screenshot.png` (`1200×900`, a real branded PromptingPress homepage capture), so Appearance → Themes renders the theme card and details image instead of a blank tile (#511).

### Tests
- `tests/js/package.test.js`: the required-files assertion now includes `promptingpress/screenshot.png`, so `npm run package` (and CI) fails if the theme-card image is ever dropped from the distributable ZIP (#511).

## [v1.7.3] — 2026-07-22 — `import_media` can now import a server-local file, not only a remote URL (#490)

**Brand assets — a logo lockup, a favicon, the 1200×630 OG card — normally live on the operator machine or in a brand kit, not at a public URL. `import_media` only accepted a remotely-fetchable `url`, so the one write every `pp_logo_id` / `site_icon` / `pp_og_image` setup depends on was the one write the operating loop had no typed path for: operators fell back to raw `wp media import`, which bypasses preflight, the run token, and the apply journal entirely. `import_media` now also accepts a `file` param — a server-local absolute path — mutually exclusive with `url`. The whole brand-asset flow (import → set the ID) stays inside the typed, journalled, preflighted action layer, and returns the same `{attachment_id, url}` envelope so the site options consume it directly.**

The `file` path is the twin of the URL path: it is the same registered apply, so it inherits identical run-token gating, `uploads_writable` preflight, and apply-journal treatment (the attachment is additive — a batch rollback keeps it, exactly like the URL path). It reads a server-local path by design — the operator CLI already runs with admin rights, so there is no staging-directory ceremony. Validation resolves symlinks with `realpath()` before any check, requires a readable regular file within the 10MB cap, and demands a GENUINE image: `getimagesize()` and WordPress's own filetype check must agree on the MIME, so a non-image named `.png` or a JPEG wearing a `.png` extension is rejected regardless of extension. The operator's source file is never consumed — `media_handle_sideload()` moves its input into uploads, so the apply copies the source to a staging temp file and sideloads the copy, then re-verifies the exact bytes being imported so nothing outside the jpg/png/gif/webp allowlist can reach the sideload. Error envelopes never disclose the operator's path.

### Fixed
- `import_media` (`lib/apply.php`) now accepts `file` (a server-local absolute path) as an alternative to `url`, mutually exclusive with it, closing the chicken-and-egg where importing a local brand asset required publishing it to a URL first or stepping outside the typed action layer via raw `wp media import` (#490).

### Docs
- `AI_CONTEXT.md`, `lib/ai-context.php`, `ai-instructions/website-building.md`, `ai-instructions/composition.md`, and `docs/reference-apply-cli.md` now describe the `url`/`file` choice, the server-local-by-design `file` source, the genuine-image + copy-not-consume guarantees, and that `file` feeds `pp_logo_id` / `site_icon` / `pp_og_image` the same way (#490).

### Tests
- `ApplyTest.php`: valid file import through the real `pp_execute_apply('import_media')` surface (envelope + alt + no source-URL marker); source file not consumed and the sideload receives a staged copy; non-image masquerading as `.png` rejected (getimagesize disagreement); extension/content mismatch rejected; missing / relative / unreadable / oversized paths rejected with the standard envelope; `url`+`file` and neither-set both rejected; UTF-8 filename + alt round-trip; preview verifies without sideloading and discloses no path; and a batch-rollback parity test proving the file import is additive like the URL path (#490).
- `tests/bootstrap.php`: a `wp_tempnam()` stub so the copy-then-sideload staging path is exercised without real WordPress (#490).

## [v1.7.2] — 2026-07-22 — a section can be a `body_items`-only trust strip: `body` is now optional (#488)

**A `section` whose whole content is its `body_items` row — a slim "trust strip" like "SOC 2 · 99.99% uptime · GDPR" with no heading and no paragraph — is now a first-class band. `body` was a required prop, so the only way to author such a strip was to pass an empty `body: ""` placeholder purely to satisfy the validator, and the row then paid a body-relative top margin that pushed it below the band's optical centre. Now `body` is optional: author the strip with `body_items` alone, and on a body-less strip the row's top margin drops to zero so the band's own symmetric padding centres it. A section still cannot be empty — it must carry at least one of `body`, `body_items`, or panel content, so a genuinely contentless band is still rejected at write time.**

`section.body` moved from `required: true` to optional, and the emptiness guarantee is now carried by a schema-level content requirement (`content_requirement.any_of`) enforced in the shared composition validator — the same generic, schema-driven path as the existing numeric-bounds, strict-enum, and bounded-array rules, with no per-component branch and no second validator. A section that authors none of `body` / `body_items` / `panel_heading` / `panel_body` / `panel_items` / a panel CTA is rejected with `invalid_composition`; a `title` alone does not count. The check is loose about type (it asks "did the author put content here?", not "is this well-typed?"), so a present-but-malformed content prop still surfaces its own precise type error instead of a generic "no content" message. The body-less flush-top is keyed on the trimmed body, so a whitespace-only body is treated as body-less too, and the render guards a non-string body against a fatal. The row's separator-clip geometry from #489 is unchanged, and the flush-top was rendered and inspected at 375 and 1280 in a body-less state (pipeline §14.2).

Every composition that authors real body copy renders byte-identically. Body-less strips (including the prior `body: ""` workaround) intentionally shift their row's top margin from `var(--space-md)` to `0` and gain a `section__inline-items--flush-top` class — that vertical re-centering is the second half of the fix.

### Fixed
- `section.body` is now optional (`components/section/schema.json`): a `body_items`-only or panel-only band no longer needs a `body: ""` placeholder (#488).
- A body-less `body_items` strip no longer pays a body-relative top margin: `section.php` adds `section__inline-items--flush-top` when no body copy precedes the row, and `assets/css/components.css` zeroes its `margin-top`, so the band's symmetric padding centres it (#488).
- A section carrying no `body`, `body_items`, or panel content is rejected honestly at write time via the new schema-level `content_requirement.any_of` gate in the shared validator (`lib/admin.php`), so a bare `{"component":"section"}` can no longer validate and drift the editor accordion (#488).
- The render guards a non-string `body` before `trim()`, so a malformed/legacy/restore-path body cannot fatal the section (#488, adversarial review finding).

### Docs
- `ai-instructions/composition.md`, `components/section/README.md`, `README.md`, and `AI_CONTEXT.md` now describe `body` as optional and state the "at least one of body / body_items / panel content" requirement; the AI component catalog (`lib/ai-context.php`) surfaces the content requirement so an all-optional prop list doesn't read as "empty is valid" (#488).

### Tests
- `SchemaValidationTest.php`: a content-requirement matrix (body-only, body_items-only, panel-only, body+items, and the `body: ""` workaround all accept; no-props, empty/whitespace body, empty items array, and title-only reject), a malformed-content-prop test proving the precise type error wins, and — per pipeline §14.1 — a test that authors a `body_items`-only band through the REAL `create_page` / `update_composition` surface (#488).
- The composable-component invariant test now verifies every composable component rejects the bare no-props shape through either a required prop or a `content_requirement` (#488).
- `SectionInlineItemsTest.php`: render pins for the `--flush-top` modifier (present body-less, absent with body, present for whitespace-only body) and a CSS source pin; `tests/e2e/style-render.spec.ts` (`@smoke`) asserts the computed top margin is `0` body-less and `16px` after body at 375 and 1280, and that the #489 clip still holds on a body-less wrapping strip (#488).
- `AiContextTest.php`: pins that the condensed schema surfaces `content_requirement` (#488).

## [v1.7.1] — 2026-07-22 — section `body_items` separator no longer dangles a middot at the start of wrapped lines (#489)

**The section `body_items` "trust strip" (a centered row of short items with a middot between each) drew its separator as a `li + li::before` glyph — one leading middot before every item except the first. On mobile, where a 4–5 item strip wraps, whichever item began a new line still painted its `::before`, so a stray `·` hung in the left margin at the start of every wrapped line and pulled that line off-center. This was live on the prod mobile homepage. The separator is now a hanging-separator clip: it renders before EVERY item, each item is pulled left by exactly the separator's occupied width, and the row clips its overflow, so any separator that would begin a visual line falls outside the box and is never painted. No middot dangles at any width; a mid-line separator still shows normally.**

Per the recorded decision (#489), the fix is pure CSS in `assets/css/components.css` — no markup change, so the shipped `role="list"` semantics, the screen-reader-quiet `content: "·" / ""` syntax, and the `--section-separator-color` style slot are all preserved exactly. The separator's left-pull is an exact token sum (`--space-sm` box + `--space-xs` right margin), so it can never clip item text or leak a sliver of glyph, and a fixed-width inline-block box makes the pull independent of the font's middot advance. The edge-clip is geometrically incompatible with per-line centering, so the row is now left-packed and centered as a block via `width: fit-content` + auto margins: a single-line strip still reads centered (the common desktop case), while a wrapped strip fills the width and its lines pack from the left. A pathological single unbreakable label now wraps instead of being clipped by the new `overflow: hidden`. The mechanism was prototyped and rendered at 320/375/768 before implementation (pipeline §14.3), and the wrapped state was inspected directly (§14.2).

### Fixed
- Section `body_items` separator no longer paints a leading middot at the start of a wrapped line: `.section__inline-items li::before` renders on every item, each `.section__inline-item` is pulled left by `calc(-1 * (var(--space-sm) + var(--space-xs)))`, and the row is `overflow: hidden` so line-leading separators are clipped (#489).
- The row is left-packed and block-centered (`justify-content: flex-start` + `width: fit-content` + auto side margins) so single-line strips stay centered while wrapped lines pack from the left with no dangling glyph (#489).
- A single unbreakable `body_item` label wraps (`min-width: 0` + `overflow-wrap: break-word`) instead of being clipped on the right by the new row overflow (#489, adversarial review finding).

### Docs
- `ai-instructions/composition.md` no longer calls the strip "a single centered row"; it notes the separator never dangles at a wrapped-line start and that wrapped lines pack from the left (#489).

### Tests
- New `#489` Playwright test (`tests/e2e/style-render.spec.ts`, `@smoke`) authors a wrapping strip and asserts the clip geometry at 320/375/768 — the row is `overflow: hidden`, the per-item left-pull equals the separator's occupied width to the pixel, every line-leading item hangs into the clipped region, and a single-line strip is block-centered — with screenshots of the stressed wrapped state attached (#489).
- `SectionInlineItemsTest.php` and `tests/js/css-lint.test.js` pins updated to the `li::before` selector shape and the new clip declarations (overflow, per-item pull, fixed-box separator) (#489).

## [v1.7.0] — 2026-07-22 — actions/integrity hardening: the v1.7.0 gate is closed (#141)

**This is a gate rollup marker: the four changes of this release shipped in working versions 1.6.3–1.6.6 below, and this entry carries no new code beyond the five-file version bump and doc freshness. What 1.7.0 marks is the close of the actions/integrity hardening gate (#141, Part 1.96) — the mutation and validation trust layer tightened after the brand-fidelity feature run. Batch rollback now restores what was actually there: site-option snapshots capture presence separately from value, so an absent option rolls back to absent and an explicit-empty to empty (#291). The media-URL security gate derives its prop coverage from component schemas via \"format\": \"image_url\" — a new image prop is covered the day its schema declares it — with an un-droppable two-name floor so the gate can never fail open on registry failure (#154). Page actions that assume a materialized page (publish, trash, slug, SEO) reject auto-draft phantoms with a clear envelope while the Add-New-Page first-save promotion path stays intact, and a garbage-collected editor URL redirects instead of dying (#160). And the length validator's grammar closed its malformed-shape holes — no digitless or multi-dot numbers, no whitespace before units, proper paren nesting, no top-level calc commas — with the signed #467 grammar preserved and the accepted residuals documented (#151).**

This release bumps the version across the five synced files from 1.6.6 to 1.7.0 and updates README.md's project-status strings. Release evidence (recorded on #141): full suites green per issue; three of the four issues produced pre-landing decision escalations (fail-closed floor, four-action reject set, walker-vs-count guard) — the gate hardened itself while hardening the theme. No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.7.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.7.0 =` rollup entry.

## [v1.6.6] — 2026-07-22 — `_pp_validate_length()` rejects malformed calc()/clamp() and simple-length shapes it used to persist as broken CSS (#151)

**The design-token/style-slot length validator accepted several malformed values that the browser silently drops: a unit with no digit (`.em`), a number with multiple dots (`1.2.3rem`), whitespace before the unit (`1.2 rem`), an unbalanced or improperly-nested `calc()` body (`calc(1))`, `calc()1,2()`), and comma misuse inside `calc()` (`calc(1,2,3)`). Each one validated and was written to a custom property, then the browser threw the whole declaration away — a broken style the operator or AI thought they had set. The length grammar now rejects these up front. Every well-formed length still validates unchanged: all units, signed/negative lengths (#467), leading-dot numbers, and nested `clamp()`/`calc()` expressions.**

Per the recorded decision on #151 this buys the cheap, concrete correctness wins (targeted checks, Option C) plus the pure newline fix (Option 4) without building a full CSS `calc()`/`clamp()` grammar parser. The simple-length number body was tightened from the loose `[\d.]+\s*` to a single well-formed number (at least one digit, at most one dot, unit directly attached), preserving the #467 signed-length grammar exactly. The `calc()`/`clamp()` branch gained a proper left-to-right paren-nesting check (which subsumes the count-based balance check the decision named and closes a comma-guard bypass an adversarial review found), a top-level-comma rejection scoped to `calc()` (leaving `clamp()`'s legitimate 3-argument comma form intact), and the `/s` (dotall) modifier on the outer extraction so a legal newline inside the expression extracts instead of failing outright. The injection guards (`{};<>` stripping, the alpha-word-must-be-a-unit check, and the character-class whitelist) are untouched — these were never security holes, only correctness gaps that degraded to dropped CSS. A handful of contrived shapes remain accepted as documented residuals (bare unitless operands like `calc(1)`/`clamp(1,2,3)`, doubled operators `calc(1++2rem)`, two-operand-no-operator `calc(1 1)`); they too only degrade to a dropped declaration, never injection.

### Fixed
- `_pp_validate_length()` (`lib/apply.php`) now rejects malformed simple lengths (`.em`, `1.2.3rem`, `1.2 rem`), improperly-nested or unbalanced `calc()`/`clamp()` bodies (`calc(1))`, `calc()1,2()`, `calc(1)+(2rem)`), and top-level commas inside `calc()` (`calc(1,2,3)`), instead of persisting them as broken CSS the browser drops (#151).
- The tightened simple-length number body avoids a quadratic-backtracking (ReDoS) shape a first-cut regex introduced; the shipped form is linear on long inputs (#151, adversarial review finding).

### Docs
- No AI-facing documentation changed: the accepted length grammar is unchanged (only malformed shapes are now rejected), so `ai-instructions/*` and `lib/ai-context.php` remain accurate (#151).

### Tests
- Added an accept/reject matrix in `tests/ApplyTest.php` covering every named malformed class, every documented valid shape (all units, signed values, leading-dot, nested clamp/calc), the paren-nesting bypass cases, the newline forms, the documented residuals, a linear-time regression guard, and a Section 14.1 authoring-path test through `update_design_token` with one malformed and one valid value (#151).

## [v1.6.5] — 2026-07-22 — page lifecycle actions reject unsaved auto-draft targets; stale editor URLs redirect instead of dead-ending (#160)

**A page that was started with "Add New Page" but never saved is a hidden `auto-draft` — it is not in the AI's page inventory and WordPress deletes it on its own after about a week. Until now, an action that knew (or guessed) that page's post ID could still publish it, trash it, or set its slug/SEO on it, materializing a phantom page the tools said didn't exist. Publishing, trashing, and slug/SEO edits now reject an `auto-draft` target with a clear error telling the caller to save the page first. Separately, a bookmarked composition-editor URL for a page that WordPress has since garbage-collected now redirects to the Pages list instead of hitting a hard "Page not found." dead end.**

The status gate lands on exactly the four page actions that assume a real, materialized page and must not create one on the fly: `publish_page`, `trash_page`, `update_page_slug`, and `update_seo_meta`. Each rejects an `auto-draft` target up front with the standard `auto_draft`-coded error envelope, before any write. The two actions that legitimately operate on a brand-new page's own auto-draft — `update_composition` and `update_page_title`, the first-save path that promotes an auto-draft to a real draft — are deliberately untouched, so "Add New Page" and its first save keep working exactly as before. `restore_page` is likewise unchanged: it still restores trashed pages, and `trash` was never gated. One accepted consequence of the gate: a slug or SEO write no longer incidentally promotes an auto-draft to a draft (an undocumented, untested side effect that only ever fired if a client wrote metadata before the page had a title or content) — save the page first, then set its slug or SEO. For the stale-URL case, the redirect runs before the editor page renders, so a garbage-collected bookmark lands somewhere useful rather than dead-ending. Auto-draft cleanup itself depends on WP-Cron firing; the README now documents that so a `DISABLE_WP_CRON` or very-low-traffic install knows abandoned auto-drafts can accumulate (harmless, hidden housekeeping, mirroring WordPress core's own new-post flow).

### Fixed
- `publish_page`, `trash_page`, `update_page_slug`, and `update_seo_meta` now reject a target whose `post_status` is `auto-draft` with a clear `auto_draft` error, instead of acting on a page the AI's inventory doesn't list (#160). `update_composition` and `update_page_title` (the #121 promote-on-write first-save path) and `restore_page` (trash-gated) are unaffected.
- A composition-editor URL for a garbage-collected or missing page now redirects to the Pages list before the page renders, instead of a hard `wp_die('Page not found.')` dead end (#160).

### Docs
- README setup notes now document that abandoned `auto-draft` cleanup relies on WP-Cron, so `DISABLE_WP_CRON` or very-low-traffic installs may accumulate hidden auto-drafts (#160).

### Tests
- PHPUnit `ActionsTest`: authoring through the real `pp_execute_action()` surface, each of the four gated actions rejects an `auto-draft` target with `ok:false` and `error_code: auto_draft`; the same actions still succeed on `draft` and `publish` targets; `update_composition` and `update_page_title` still promote an auto-draft on save (existing pins hold); `restore_page` still restores a trashed page; and `_pp_reject_auto_draft()` is defensive against a `0` id.
- PHPUnit `AdminTest`: `pp_composition_missing_post_redirect_url()` returns the Pages-list URL for a missing/GC'd post and for a non-page post, and `null` for a real page and for an absent post id.

## [v1.6.4] — 2026-07-22 — media-URL validation allowlist is schema-driven, not hardcoded (#154)

**The media-library check that stops the AI from pointing an image prop at a missing or non-image attachment (#124/#153) used to key off a hardcoded `['image_url','background_image']` array in `lib/actions.php`, kept in sync with the component schemas by hand. It now derives that prop set from the schemas themselves: each image-URL prop declares `"format": "image_url"`, and the validator walks the registered component schemas to build its list. A future component that adds a new image prop is media-validated the moment its schema declares the format, with no second allowlist to forget.**

Coverage is unchanged today — the eight image-URL props across seven components (hero/section `image_url`, cta/stats/section `background_image`, and the nested `items[].image_url` on logos/grid/testimonials) are exactly what the old hardcoded pair covered, so nothing an author can do behaves differently. What changes is that the two lists can no longer silently drift apart. `_pp_schema_image_url_props()` stays purely schema-derived so a drift-catcher test can pin it; the consumer, `_pp_extract_urls_from_params()`, then merges the historical `['image_url','background_image']` pair back in as a fail-closed floor. That floor is a safety net, not a maintained allowlist: new props still flow from the schema walk, but the media gate can never fall below its pre-#154 coverage if the component registry is ever transiently empty (a broken `components/` directory, or a static cache poisoned by a wrong `get_template_directory()`) — which would otherwise make `_pp_validate_media_urls_in_params()` fail open through its `empty($urls) → true` early-out, the opposite of the fail-closed rule established in #153.

### Fixed
- The image-URL prop allowlist in `_pp_extract_urls_from_params()` is now derived from each component schema's `"format": "image_url"` annotation instead of a hardcoded array in a second file, so a new image-bearing prop cannot silently ship without the #124/#153 media-existence and image-type checks (#154).
- The media gate is now fail-closed against an empty component registry: `['image_url','background_image']` is retained as an un-droppable floor so coverage never regresses below the pre-#154 baseline even if schema derivation yields nothing (#154, #153 precedent).

### Tests
- PHPUnit `ActionsTest`: the derived prop set is pinned to `['background_image','image_url']` (drift-catcher on any new format-annotated name); every canonical-named or image-described or `*_image`-suffixed schema prop must declare `format: image_url` (forgotten-annotation catcher, non-vacuous ≥8-prop guard); no `format: image_url` prop nests deeper than one `items[]` level (depth guard for the walker); and all eight existing image props still extract (parity).
- PHPUnit `MediaUrlSchemaDrivenTest`: authors through the real `pp_execute_action('update_component')` surface with a synthetic component declaring a novel `poster_url` image prop — a non-image URL is rejected purely because the schema annotation put it in the derived set (no edit to the extractor), a real image passes, and the fail-closed floor still extracts the canonical props when the registry is empty.

## [v1.6.3] — 2026-07-22 — rollback restores a never-set site option by deleting it (#291)

**When a multi-step AI edit fails partway and rolls back, site options that had no value before the run are now restored to having no value, instead of being left as an empty string. An option that genuinely held an empty string before the run stays an empty string. The batch snapshot records whether each option existed, not just what it held, so a rollback puts the site back exactly as it was.**

Before this, the rollback snapshot stored only an option's value, so "this option was never set" and "this option was set to an empty string" both looked identical at restore time — a single captured `''`. Today every whitelisted option reads back as `''` when unset and normalizes booleans, so the collapse was harmless in practice, but it was a latent fidelity gap: the moment a site-option surface arrives where an existing-but-empty row means something different from an absent row, a rollback would silently restore the wrong one. The snapshot now captures presence and value as a per-key `{exists, value}` pair. On rollback an absent baseline deletes the option's row, an explicit empty-string baseline writes `''`, and any other value is written back as-is. Capture stays scoped to the option whitelist (an unrelated core option is never read into the snapshot), the restore never re-runs current write-time validation on a trusted pre-run baseline (the #281/#233 rule), and the newest option types added since #281 — the social row, the Open Graph / Twitter defaults, the attachment-ID options — all flow through this one generic path. A pre-#291 value-only snapshot, should one ever be replayed, degrades to the previous behavior rather than erroring.

### Fixed
- Site-option batch rollback now distinguishes an absent baseline (restored by deleting the option) from an explicit empty-string baseline (restored as `''`), where both previously collapsed to the same empty restore (#291).

### Tests
- PHPUnit `ActionsTest`: snapshot captures `{exists:false}` for an absent whitelisted option, `{exists:true, value:''}` for an explicit empty row, and the stored value otherwise; a non-whitelisted key is recorded absent-shaped without reading (or string-casting) its value; restore deletes an absent baseline, writes `''` for an explicit-empty baseline, writes a value baseline verbatim, leaves non-whitelisted keys untouched, degrades a legacy value-only snapshot to the #281 rule, and an end-to-end batch rolls an absent `pp_footer_social` back to deleted.


**A slim post-hero band of short items with a colored dot between them ("No credit card · Cancel anytime · 30-day guarantee") used to be inexpressible: the only route was separator characters typed into body text, and those cannot be recolored because inline `style` spans are (correctly) stripped by the sanitizer. Sections gain a `body_items` prop — a row of short plain-text items rendered as a single centered row below the body, with a CSS-generated middot separator between each. The separator is a real presentational element, so it takes a color from the new `--section-separator-color` style slot and stays silent to screen readers. The items inherit the section body type, so the same `--section-body-size`/`--section-body-weight` slots that set the body's size and weight also set the strip's, and the original brand band (15px/600 text with a lime dot) is now fully expressible with no parent-theme edits.**

`body_items` is a list of plain-text strings, escaped at output like every other text field: at most 8 items, each at most 80 characters, and a write that exceeds either bound or passes a non-string entry is rejected up front instead of silently truncated. The row renders only when it has content, after the body when both are set, and the page is byte-identical when it is unset. The separator color defaults to the muted text color and, on the inverted and background-image bands, follows the same light on-dark text color as its sibling text, so a dark band never gets an invisible dot. The row wraps to more centered rows at narrow widths, so it needs no mobile-specific rule. The bounds check is a generic, schema-driven rule in the shared write-time validator, so it holds for every write path (add/update component, update composition, create page) without a second validator.

### Added
- `section.body_items` — a centered row of up to 8 short plain-text items (≤80 chars each) rendered below the body with a CSS-generated middot separator between each, escaped at output; over-bound or non-string entries are rejected at write time (#475).
- `--section-separator-color` style slot — colors the `body_items` separator; defaults to the muted text color, and follows the light on-inverted / on-overlay text color on dark and image bands like sibling text (#475).

### Tests
- PHPUnit `SchemaValidationTest`: `body_items` bounds (≤8 items, ≤80 chars, non-string and non-array rejected, unset sentinels accepted), the generic check not rippling to `panel_items`, and unknown-prop staying strict alongside a valid `body_items`.
- PHPUnit `SectionInlineItemsTest`: the row renders only when set (byte-identical when unset), `esc_html` on each item, `<li>` count, ordering after the body, rendering across layouts, and CSS pins for the flex row, the body-type-slot inheritance, and the separator routing (base + overlay).
- E2E `style-render.spec.ts` at 375 + 1280: computed separator color (muted default, slot override, inverted routing), wrap at narrow width, and the brand strip (15px/600 body type + a lime separator) computing correctly.
- css-lint: every separator `color` declaration routes through `var(--section-separator-color …)` at both declaration sites.

### Docs
- `ai-instructions/composition.md` (section props row + a `body_items` worked example), `components/section/README.md`, the section `schema.json` prop and slot descriptions, and the `AI_CONTEXT.md` / `README.md` style-slot counts (215 → 216) document the new capability (#475).

## [v1.6.1] — 2026-07-22 — social-share cards you can actually set (#468)

**Sharing a PromptingPress page used to produce a bare link: the theme emitted zero Open Graph or Twitter tags, and `update_seo_meta` only reached meta description, title, and canonical. There was no way to set a share image or the card text short of editing the parent theme or bolting on an SEO plugin. This release adds a first-class social-meta surface. Four site options declare the defaults — `pp_og_image` (a Media Library image attachment, the same typed rule as the logo), `pp_og_site_name`, `pp_og_default_description`, and `pp_twitter_card` (summary or summary_large_image) — and `update_seo_meta` gains per-page `og_title` and `twitter_title` overrides. The theme renders the full `og:*`/`twitter:*` set in the page head, resolved through page → site → WordPress fallback chains.**

The renderer is theme-owned chrome beside the existing SEO emission, and it holds two lines hard: a tag is emitted only when its resolved value is non-empty (whitespace counts as empty), and every value is escaped at the sink (`esc_url` for URLs, `esc_attr` for text) because every input is operator-settable and reaches raw head output. The share image is re-checked against the image-attachment rule at render time, so an image deleted after it was set drops the image tags instead of emitting a broken URL, and `og:image:width`/`height` are emitted only when the attachment metadata carries them. `og:title` falls back og_title → seo_title → post title; `twitter:title` falls back through that same chain; both descriptions fall back page meta_description → site default → omitted. The per-page titles round-trip non-ASCII through the same store the description fix hardened, and the batch snapshot/rollback covers the new site options like their siblings. Social tags render on singular pages only, where there is a single post to describe.

### Added
- Four site options for site-wide social defaults — `pp_og_image` (image attachment ID, feeds `og:image`/`twitter:image` plus width/height/alt), `pp_og_site_name`, `pp_og_default_description` (≤320 chars), and `pp_twitter_card` (`summary`\|`summary_large_image`, default `summary_large_image`) — set through `update_site_option` (#468).
- Per-page `og_title` and `twitter_title` overrides on `update_seo_meta` (validated like `seo_title`), for a page that needs a share title distinct from its `<title>` (#468).
- Theme rendering of the full Open Graph + Twitter Card tag set in `wp_head` (`og:type`/`site_name`/`locale`/`url`/`title`/`description`/`image` + dimensions/alt, `twitter:card`/`title`/`description`/`image`), page → site → WP fallback chains, no empty tags, all values escaped (#468).

### Tests
- PHPUnit `SocialMetaTest`: the four site-option types on the whitelist, the image-attachment rule (image accepted, non-image/missing/empty rejected), the twitter_card closed enum (accept/clear/reject + case-normalizing round-trip), the description 320 cap and free-text site name, the per-page keys (accept, 200 cap, non-ASCII round-trip), every fallback chain at each level, non-singular/no-post omission, stale-attachment and missing-metadata omission, alt-omit-when-empty, escaping of quotes/brackets, whitespace-only-as-empty, and batch snapshot/rollback for both the site option and the per-page titles.

### Docs
- `AI_CONTEXT.md` (site-options list, capability table, `pp_get_seo_meta`/`update_seo_meta` shapes), `ai-instructions/website-building.md` (a social-cards content-model row), and the `update_site_option` / `update_seo_meta` action descriptions now document the social-meta surface (#468).

## [v1.6.0] — 2026-07-22 — brand-fidelity release: the v1.6.0 gate is closed (#141)

**This is a gate rollup marker: the seven changes of this release shipped in working versions 1.5.6–1.5.12 below, and this entry carries no new code beyond the five-file version bump and doc freshness. What 1.6.0 marks is the close of the brand-fidelity gate (#141, Part 1.95), opened by a real brand build on v1.5.3 whose spec kept hitting inexpressible defaults. The through-line: precise brand type, color, and layout intent is now reachable through the documented surfaces instead of parent-theme edits. Heading tracking is a real token — `--letter-spacing-heading`, settable to the negative values brands actually use, with the shared length validator learning the signed grammar (#467). Step badges pair fill with authorable ink via `--grid-step-text-color`, so a light badge gets dark numerals (#473). Section body text gained its missing `--section-body-size`/`--section-body-weight` slots (#470). A split hero's media can now track the headline column height with `vertical_align: stretch` — one asset balances any headline length (#477). The footer grew its second menu column (`footer_secondary` + label option) and the social-icon row (`pp_footer_social`, a closed eight-network set with bundled inline SVGs) — both byte-identical when unset (#469, #382). And the in-admin chat's page context now annotates consecutive same-background components with the band-fusing hint, the runtime half of the #377 heuristic (#378).**

This release bumps the version across the five synced files from 1.5.12 to 1.6.0 and updates README.md's project-status strings. Release evidence (recorded on #141): a brand-shaped rendered page at 375 and 1280 — tracking token overriding live, lime step badges with ink numerals, a 15px/600 body strip, stretch heroes measuring content==media height at both 5-line (479px) and 2-line (353px) headlines, and the full four-area footer with secondary Legal column and social row. No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.6.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.6.0 =` rollup entry.

## [v1.5.12] — 2026-07-22 — the chat now sees which touching bands share a background (#378)

**The in-admin chat AI used to receive each component's styling on its own, so the common "two touching bands in the same color" case, the one where a stray gap opens a wrong-color seam between them, had to be inferred from the composition. The page context now spells it out: after the component index it lists every consecutive pair whose resolved background matches, with a pointer to zero the facing paddings/margins. The chat gets the adjacency as a fact, not a deduction, so fixing or avoiding a same-color seam is reliable instead of hit-or-miss.**

A component's resolved background is read from the same inputs the context already carries: a per-instance `--<component>-bg` override wins, otherwise the `theme` band (`inverted`, or `muted` and its deprecated `dark` alias). Bands that inherit the default body background, and image-backed bands, are skipped so the hint only fires where two flat colors actually meet. This is the runtime companion to the v1.4.20 documentation of the band-fusing heuristic (#377); it is context-only and changes no action, prop, validator, or composition data. The match is a best-effort string comparison (whitespace runs and hex case are normalized), not a full CSS-equivalence check, so `#fff` and `#ffffff` are treated as different by design.

### Added
- Chat page context now annotates consecutive components whose resolved background matches, pointing at the facing paddings/margins that control the seam (#378).

### Tests
- 15 context-assembly tests covering style-override matches, theme-prop matches, the muted/dark alias, override-beats-theme precedence, whitespace/case normalization, and the skip cases (default, differing, image-backed, transparent, non-consecutive, single component, malformed item), plus an exact-wording snapshot.

## [v1.5.11] — 2026-07-22 — the footer can now carry a social-icon row (#382)

**The footer had no surface for social profile links, so the near-universal footer social-icon row was inexpressible and dogfoods had to approximate it with a plain text line. There is now a `pp_footer_social` site option: a JSON list of `{network, url}` entries from a closed set of known networks (x, linkedin, facebook, instagram, youtube, github, tiktok, mastodon), rendered under the brand blurb as accessible inline-SVG icon links whose color follows `pp_footer_link_color`. Set it with `update_site_option`; unknown networks, non-URL values, and non-http(s) schemes are rejected with the standard envelope, and an unset option leaves the footer byte-identical.**

The row is theme-owned chrome, delivered through the same whitelisted site-option surface as the rest of the footer, never a composable section. Its network set is closed and each network ships a minimal single-path SVG glyph inline with the theme, so there are no arbitrary icon URLs, no icon fonts, and no external requests at render. One map (`pp_footer_social_networks()`) is the single source of truth for both which networks validate and which glyph each renders, so an accepted network can never be un-renderable and adding a network later is one additive entry. Each link carries an `aria-label` (the network name) and a decorative `aria-hidden` SVG; the href is escaped with `esc_url` and the link opens the external profile with `rel="noopener noreferrer"`. The row lands in the `.site-footer__social` slot the footer rebuild reserved, inside the brand column, and its conditional markup sits at column-zero indentation so an unset row leaks no template whitespace.

### Added
- `pp_footer_social` site option (a new `social` slot type): a JSON list of `{network, url}` from a closed network set (x, linkedin, facebook, instagram, youtube, github, tiktok, mastodon), rendered as accessible inline-SVG icon links under the footer brand blurb, color following `pp_footer_link_color`. Set/clear it through `update_site_option`; the value is validated (unknown network, non-URL, non-http(s) scheme, malformed JSON, or over-length all rejected) and canonicalized on write. Snapshot/rollback covers it like every other `pp_footer_*` option. Documented in the footer schema/README, `README.md`, `AI_CONTEXT.md`, and the `ai-instructions/` how-tos (#382).

### Tests
- PHPUnit: `FooterChromeTest` adds the whitelist type for `pp_footer_social`, the closed-network-map single-source guard, validation cases (valid set accepted and round-tripped, empty clears, unknown network / non-URL / non-http(s) / protocol-relative / malformed-JSON / object-vs-list / missing-key / over-count all rejected, external profile URL not treated as off-site), write-path canonicalization (extra keys stripped, URL trimmed), a snapshot/rollback restore case, render cases (correct hrefs, per-link `aria-label`, decorative `aria-hidden` SVG, brand-column placement, defensive skip of unknown networks, esc_url/esc_attr source contract), and a byte-identical-when-unset guard with a whitespace-artifact assertion. A Playwright E2E renders the icon row at 1280 and 375 with the right links, labels, and decorative glyphs (#382).

### Docs
- `README.md`, `AI_CONTEXT.md`, `components/footer/README.md` + `schema.json`, and `ai-instructions/website-building.md` document the `pp_footer_social` option, its closed network set, and the accessible icon-row render (#382).

## [v1.5.10] — 2026-07-22 — the footer can now carry a second menu column (e.g. a distinct Legal column) alongside the primary footer menu (#469)

**The footer offered exactly one menu column, so a brand that needed a separate Legal column (Aviso legal / Privacidad / Cookies) next to its main footer links had nowhere to put it. There is now a second footer menu location, `footer_secondary`: assign a menu to it (with `assign_menu_location` or `set_menu`) and it renders as an extra footer column, with `pp_footer_secondary_label` as its optional heading. The name is generic on purpose — a "Legal" column is one use of a second footer menu, not the capability. With no menu assigned to `footer_secondary`, the footer is byte-identical to the single-menu layout.**

The second column is theme-owned chrome, delivered through the same surface as the rest of the footer: a registered menu location plus a whitelisted site option, never a composable section. It renders only when a menu is actually assigned to `footer_secondary`, as a real `<nav>` landmark with a distinct `aria-label` ("Footer secondary navigation") so assistive tech can tell the two footer menus apart. Its heading follows the same headless-when-unset rule as the existing column labels. The desktop column grid already lays out one equal track per present column, so 3-column and 4-column footers both work with no CSS change, and the mobile stack keeps reading order (brand → primary menu → secondary menu → contact). An unassigned secondary location adds zero output — the footer template keeps its conditional column at column-zero indentation so an unrendered column leaks no whitespace.

### Added
- Second footer menu location `footer_secondary` and its optional heading option `pp_footer_secondary_label`. Assign a menu to the location (`assign_menu_location` / `set_menu`) to render a second footer menu column; set `pp_footer_secondary_label` (`update_site_option`) for its heading. Unassigned = the footer renders exactly as before. Documented in the footer schema/README, `AI_CONTEXT.md`, and the `ai-instructions/` how-tos (#469).

### Tests
- PHPUnit: `FooterChromeTest` adds the whitelist type for `pp_footer_secondary_label`, render cases proving the second column appears only when a menu is assigned (distinct `aria-label`, headless-when-unset heading, escaping, DOM order between the primary menu and contact), a byte-identical-when-unassigned guard (including a whitespace-artifact assertion so an unrendered column can never leak template whitespace), and pins that `base.php` maps the option and `functions.php` registers the location. A Playwright E2E renders an assigned second column at 1280 (four equal columns, distinct landmark) and confirms it stacks after the primary menu at 375 (#469).

### Docs
- `README.md`, `AI_CONTEXT.md`, `components/footer/README.md` + `schema.json`, and the `ai-instructions/` footer how-tos (`set-logo.md`, `website-building.md`, `composition.md`) document the second footer menu column, the `footer_secondary` location, and the `pp_footer_secondary_label` option (#469).

## [v1.5.9] — 2026-07-22 — a split hero can now stretch its media to the headline's height, so one asset balances any headline length (#477)

**A `split` hero puts the headline in the left column and media in the right, but the media only offered `top` / `center` / `bottom` alignment at a fixed size — so a 4–5 line headline left a small card floating below center beside a huge headline, and the only fix was hand-authoring a taller asset per page. `vertical_align` now takes a fourth value, `stretch`: the media column fills the content column's height (equal-height columns) and the image sizes to that box with `object-fit: cover`. One asset balances any headline length. Set `vertical_align: "stretch"` on a split hero; the existing `top` / `center` / `bottom` output is byte-identical.**

This extends the existing `vertical_align` enum — no new prop, no new style slot. On a split hero, `stretch` makes both grid tracks equal the row height (which the taller content column defines), and the media track fills it. The crop idiom is `cover`, the same default the hero's background-image and aspect-ratio paths already use; there is no separate fit control, so a stretched image crops to the box. The value is scoped to the exact `stretch` attribute, so the three existing alignment cases are untouched, and all of it lives in the desktop (`≥1024px`) block, so the stacked mobile split is unchanged. On the `cover` layout `stretch` renders like `center`.

### Added
- `stretch` value on the hero `vertical_align` enum. On a `split` layout it makes the media column track the content column's height (equal-height columns) with `object-fit: cover`; on `cover` it renders like `center`. Documented in the hero schema, README, and `AI_CONTEXT.md` (#477).

### Tests
- PHPUnit: `HeroCompositionTest` adds enum-acceptance + attribute-emission cases — `stretch` survives validation and emits `data-pp-vertical-align="stretch"` on split and cover, and is ignored on non-split/non-cover layouts. A Playwright E2E renders two split heroes with the same tall multi-line headline and the same wide, short image at 1280: the `stretch` hero's media wrap tracks the content column height (ratio ~1.0), while the default `center` hero's fixed-aspect card stays well under the issue's measured 69–80% ceiling — proving the fix changed the geometry (#477).

## [v1.5.8] — 2026-07-21 — a section's body text size and weight are now style slots, so you can set a deliberate type step (#470)

**The `section` component's body copy took whatever the built-in body scale gave it — there was no way to ask for a compact utility band at, say, 15px/600, an emphasis paragraph, or dense spec text through the documented style surface. There are now two style slots, `--section-body-size` (length) and `--section-body-weight` (number), that the body/content text consumes at every breakpoint. Set them for a deliberate size/weight step; leave them unset and every section renders byte-identically to before. Naming follows the existing `--cta-body-size` idiom.**

This is a slot-parity change only, in the same class as the CTA body-size and hero title-weight slots that already exist — no new component, no restyled defaults (#336 precedent: make it expressible, don't retune). The slots are routed through every place the section body font-size/weight is declared: the base `.section__content` rule and both the desktop and mobile typography rules. The mobile size fallback chains through `var(--cta-body-size, 1rem)`, the exact value that rule used before, so unset output is unchanged even when a composition also sets `--cta-body-size`. The separator/glyph half of the original brand-band report is tracked separately and is not part of this change.

### Added
- `--section-body-size` (length) and `--section-body-weight` (number) style slots on the `section` component. The body/content text consumes `var(--section-body-size, …)` / `var(--section-body-weight, …)` at the base rule and at both the desktop premium and mobile typography rules, with today's literals as the fallbacks, so an unset section is byte-identical. Surfaced through `pp_get_style_slots()` and the schema `style_slots`; the AI slot reference is schema-derived, so both appear automatically (#470).

### Tests
- PHPUnit: `SchemaValidationTest` moves the section slot count (36→38) and the schema-derived doc-count sync (213→215). The keystone `StyleSlotContractTest` auto-discovers both slots and proves the CSS consumes each on a type-compatible property. css-lint routing guards require every section body `font-size`/`font-weight` declaration to route through the slot at the base rule and both media rules, plus a pin that the mobile size fallback chains through `--cta-body-size`. A Playwright E2E renders a section whose body is set to 22px/850 at 1280 and 375 and asserts an unset section stays weight 430 at the historical rem size (#470).

### Docs
- `AI_CONTEXT.md` and `README.md` style-slot totals move 213→215, and the `AI_CONTEXT.md` per-component breakdown reorders (`section (38)` now above `grid (37)`) (#470).

## [v1.5.7] — 2026-07-21 — a light-fill steps badge can now get ink numerals: the step number color is its own style slot (#473)

**The numbered `grid` steps badge fills through a slot (`--grid-step-color`), but its numeral color was hard-coded to `var(--color-bg)`. On a light accent (say a lime brand green), the badge filled correctly while the numeral stayed light and dropped to about 1.9:1 contrast, with no way to make it ink. There is now a `--grid-step-text-color` (color) style slot that the numeral consumes, defaulting to `var(--color-bg)` so an unset badge renders byte-identically. A steps block can now pair a light fill with ink numerals, the same fill+ink control the CTA button already exposes through `--cta-button-bg` / `--cta-button-color`.**

The slot is card-scoped (item-eligible), mirroring its `--grid-step-color` fill sibling: the numeral renders on the `.pp-step-number` child of each step card, so the color is settable grid-wide or per card. Nothing else about the steps layout changed — the badge fill, sizing, and the desktop connector line are untouched, and an unset numeral resolves through exactly the same `var(--color-bg)` it always did.

### Added
- `--grid-step-text-color` (color, default `var(--color-bg)`, item-eligible) on the `grid` component. The steps badge numeral consumes `var(--grid-step-text-color, var(--color-bg))`, so a light-fill badge can carry ink numerals; unset output is byte-identical. Documented in the grid schema, `ai-instructions/composition.md`, and `AI_CONTEXT.md` (#473).

### Changed
- The `--grid-step-color` slot description now names the numeral color as a separate slot instead of stating the numeral always renders in `--color-bg` (#473).

### Tests
- PHPUnit: `ComponentPropsTest` pins the schema declaration (type `color`, default `var(--color-bg)`, item-eligible); `SchemaValidationTest` adds the slot to the item-eligible pin and the per-card acceptance case, and its grid slot count (36→37) and schema-derived doc-count sync (212→213) move with it. The keystone `StyleSlotContractTest` auto-discovers the slot and proves the CSS consumes it. A css-lint routing guard (#473) requires every numeral `color` declaration to route through the slot and carries a detector self-test so a regex regression can't pass vacuously. A Playwright E2E renders an ink numeral on a light lime fill at 1280 and 375, and asserts an unset badge is byte-identical to an explicit `var(--color-bg)` (#473).

## [v1.5.6] — 2026-07-21 — heading letter-spacing is now a design token, so a brand can set its own heading tracking (#467)

**Heading tracking was the one heading typography value you couldn't reach: `--font-heading`, `--font-weight-heading`, and `--line-height-heading` were tokens, but `letter-spacing` was hard-coded to `-0.03em` on the shared `h1–h6` rule. A brand adopting a different display face that needs looser or tighter tracking (say `-0.01em`) had no way to set it without editing the parent theme. There is now a `--letter-spacing-heading` token (length, default `-0.03em`) that the `h1–h6` rule consumes, so `update_design_token` can set heading tracking site-wide. An unset site renders byte-identically — the computed tracking on every heading is unchanged.**

Because heading tracking is naturally negative, the shared `length` validator now accepts negative values (an optional leading minus on a simple length, and signed operands inside `calc()`/`clamp()`), which it previously rejected for every length token. This models the CSS `<length>` grammar correctly — `letter-spacing`, margins, and `text-indent` legitimately go negative — so a value like `-0.01em` validates through the write path. The validators were always grammar guards, not per-property semantic guards, so a value that is inert for a given property (a negative radius, say) still simply drops per CSS; the trust posture is unchanged. `"-"` alone, a double minus, and any injection characters stay rejected.

### Added
- `--letter-spacing-heading` (length, default `-0.03em`) registered in `assets/css/base.css`, joining `pp_design_tokens()` and the `update_design_token` write path. The shared `h1–h6` rule consumes `var(--letter-spacing-heading)` instead of a literal, so setting the token retracks every heading at once; unset output is byte-identical. Documented in the AI token reference (`AI_CONTEXT.md`) (#467).

### Changed
- The shared length validator (`_pp_validate_length`) now accepts grammar-valid negative lengths: a single leading minus on a simple length (e.g. `-0.01em`, `-.5rem`) and signed operands inside `calc()`/`clamp()`. This widens every `length` token, not just heading tracking; injection guards and the calc/clamp positive-pattern are untouched (#467).

### Tests
- PHPUnit: a `pp_design_tokens()` assertion that `--letter-spacing-heading` is exposed as a `length` with default `-0.03em`; a css-source pin that the `h1–h6` rule routes through the token and no bare `letter-spacing: -0.03em` remains; and validator coverage for the signed grammar — `-0.03em` / `-.5rem` / `-2px` and a signed `calc()` term accept, `"-"` alone / `--0.03em` / `-em` reject, plus an end-to-end `update_design_token` accept of `-0.01em`. A Playwright E2E asserts the default tracking renders on a band heading (`ratio ~ -0.03`) and that a real `:root` token override changes it, at 1280 and 375 (#467).

## [v1.5.5] — 2026-07-21 — a Spanish (or any non-ASCII) meta description sets and renders correctly now: accents and em-dashes stop turning into literal `u00e1` (#471)

**Setting a page's `meta_description` or `seo_title` to text with accents, ñ, or an em-dash through `update_seo_meta` corrupted every non-ASCII character: `prueba áéíóú ñ —guion` was stored and rendered as `prueba u00e1u00e9u00edu00f3u00fa u00f1 u2014guion`. A non-English site could not set a correct meta description, the exact job the action exists for. The stored value and the rendered `<meta name="description">` now carry the real UTF-8 text, and `seo_title` overrides the `<title>` with its real characters too. Pages already saved with the mangled text heal the moment their SEO metadata is set again.**

The action JSON-encoded the metadata before writing it, and WordPress's meta layer then stripped the backslashes off the resulting `\uXXXX` escapes, leaving `á` as the literal `u00e1`. The write now stores the metadata as raw UTF-8 and shields it from that stripping pass, the same storage approach the composition (page content) path has always used, so accents, ñ, em-dashes, CJK, emoji, quotes, and backslashes all round-trip byte-for-byte. Nothing else about the action changed: patch semantics, the length caps, canonical-URL validation, and clearing a field with `""` all behave exactly as before.

### Fixed
- `update_seo_meta` (and `pp_update_seo_meta()`) now store `meta_description` and `seo_title` as raw UTF-8, so non-ASCII text is preserved end-to-end instead of being mangled into literal `uXXXX` sequences in the stored `_pp_seo_meta` and in the rendered `<meta name="description">` / `<title>` tags. Values that were already corrupted are plain (wrong) strings that read back without error and heal on the next write; no migration is needed (#471).

### Tests
- New PHPUnit round-trip regressions assert byte-identical read-back for Spanish accents, the em-dash, CJK, a non-BMP emoji, double quotes, a literal backslash, and a literal `á`-looking string, plus a patch-merge case and rendered-tag checks for both the meta description and the `seo_title` override. A Playwright E2E drives the real `wp pp action execute update_seo_meta` path on WordPress and asserts the rendered `<head>` shows the correct UTF-8. The PHPUnit harness now models WordPress's unslash-on-write so this class of corruption can no longer pass the suite silently (#471).

## [v1.5.4] — 2026-07-20 — the full `wp pp operate inspect` field reference lands in the CLI docs, including `composition_decode_error` (#216)

**`docs/reference-apply-cli.md` described `wp pp operate inspect` but only documented the appended `run_id` — the rest of the inspect JSON contract was undocumented in the CLI reference, and the corruption-vs-empty signal `composition_decode_error` (added in #144) lived only in `AI_CONTEXT.md`. The inspect section now carries a complete field table: every top-level field `pp_inspect_site()` returns (`target`, `pages`, `drift`, `preflight`, `tokens`, `conflicts`, `smells`, `token_smells`, `composition_decode_error`) plus the CLI-appended `run_id`, each with its shape and meaning, and a dedicated table for `composition_decode_error`'s `decode_error` / `unexpected_shape` / `null` cases. Documentation only — no output shape or behavior changed.**

An agent inspecting a page before a mutation could not learn from the CLI reference how to tell a genuinely blank page apart from a corrupted one, or what the other inspect fields mean, without reading the source. The new reference enumerates the contract against `pp_inspect_site()` in `lib/operate.php` (the source of truth), so `composition_decode_error` is documented alongside `smells` with the precise rule: a corrupt row surfaces `decode_error` or `unexpected_shape` (never a misleading empty smell list), while an absent, blank, or valid page reads `null`. A doc-drift test pins the reference to the code, so adding a field to inspect now fails CI until the reference documents it.

### Docs
- `docs/reference-apply-cli.md` gains a `wp pp operate inspect` section with a full field-reference table for every top-level `pp_inspect_site()` field (`lib/operate.php`) plus the CLI-appended `run_id`, and a detail table documenting `composition_decode_error`'s `decode_error` / `unexpected_shape` / `null` semantics against the `pp_get_composition_result()` decode contract (`lib/wp.php`) (#216).

### Tests
- A doc-drift pin in `tests/js/docs-lint.test.js` extracts the top-level keys from `pp_inspect_site()`'s return array (plus the `run_id` the CLI appends in `lib/cli.php`) and asserts `docs/reference-apply-cli.md` names each one, with a no-op guard so a broken extraction fails loudly instead of passing vacuously — the reference can no longer silently rot when the inspect output shape grows (#216).

## [v1.5.3] — 2026-07-20 — the rest of the accent on a background-image band is readable now too: highlighted title words and list bullets clear WCAG AA over any image (#463)

**v1.5.2 (#461) fixed the default link and stat number on a background-image band, but the other accent-colored things on the same dark scrim were left on the light-surface brand accent — only 1.16:1 over a bright photo, effectively invisible. The highlighted word inside a band heading (`title_accent`), the check/dash/arrow bullets in a section body list, and a full-cover hero's highlighted title word all now route their default through the same `--color-accent-on-overlay` role, so they stay legible no matter what image sits underneath. Set a per-instance color slot and it still wins, exactly as before, and a plain (non-image) band is untouched.**

The accented substring inside a heading paints its own color, so it never inherited the near-white heading color the band already sets — it kept painting brand-blue straight onto the scrim at 1.16:1, failing even the 3:1 large-text bar. The same was true of a non-disc body list marker and of a `cover`-layout hero's title accent, which sits on the identical `--overlay-bg` scrim over an arbitrary image. All of these now fall back to `--color-accent-on-overlay` (near-white, ≥ 4.5:1 against the scrim-over-white worst case) introduced in #461, so no new color ships and the whole guarantee is uniform across every overlay band. Panel list markers stay on the bare accent because a background-image section's panel is a self-contained light surface, not on the scrim.

### Fixed
- The `title_accent` highlighted word on a `.section--has-bg-image`, `.cta--has-bg-image`, or `.stats--has-bg-image` band, and on a full-cover hero (`.hero--cover`), now routes its default through `--color-accent-on-overlay` instead of the light-surface `--color-accent`, so it clears WCAG AA over any background image rather than failing at 1.16:1. Section body list markers (check/dash/arrow) on an image band get the same readable default. Per-instance `--section-title-accent-color` / `--cta-title-accent-color` / `--stats-title-accent-color` / `--hero-title-accent-color` and `--section-body-marker-color` slots still win, and plain non-image bands render byte-identically (#463).

### Docs
- `ai-instructions/retheme.md` now states that every accent surface on a background-image band — links, stat numbers, the `title_accent` substring, and section body list markers — routes through `--color-accent-on-overlay`, and that a full-cover hero shares the same overlay-accent guarantee (#463).

### Tests
- The rendered Playwright E2E (`tests/e2e/style-render.spec.ts`) extends #461's contrast spec to the three title-accent spans, the section list marker glyph, and the hero-cover title accent, proving each clears 4.5:1 against the rendered scrim-over-white composite at 375px and 1280px and that a per-instance slot still wins. css-lint pins lock the routing on every surface and guard against a regression back to the bare accent or the inverted role (#463).

## [v1.5.2] — 2026-07-20 — links and stat numbers on background-image bands are readable again: a new overlay accent role clears WCAG AA over any image (#461)

**A section, CTA, or stats band with a `background_image` lays a dark scrim over an arbitrary photo, and its default link (or stat number) used the light-surface brand accent — only 1.16:1 over the scrim when the photo is bright, effectively invisible and far below the WCAG AA 4.5:1 bar. Those bands now route their default accent through a new `--color-accent-on-overlay` role that is guaranteed readable no matter what image sits underneath. Set a per-instance color slot and it still wins, exactly as before.**

The overlay sits over an unknown image, so the role's default is chosen against the worst case: the scrim composited over a pure-white image (an effective background near `rgb(115,115,115)`). Contrast there tops out at 4.74:1 for any color, so the only values that clear AA are near-white — `--color-accent-on-overlay` ships at `#fafbff` (4.59:1), with `--color-accent-on-overlay-hover` at pure white. The name describes the role, not the hue: a link on a photo band is carried by its underline, and near-white is the sole choice that stays legible over a bright image. This is a separate role from the inverted-band `--color-accent-on-inverted` (#437), which is tuned to the solid dark inverted background and only reaches ~2.2:1 over the arbitrary-image scrim. The new role joins the accent token family, so rethemeing `--color-accent` auto-derives a matching on-overlay tint and a pinned override that diverges is flagged by the same stale-warning machinery as every other derived token (#386).

### Fixed
- Default links on a `.section--has-bg-image` band and default numbers on a `.stats--has-bg-image` band now route through `--color-accent-on-overlay` instead of the light-surface `--color-accent`, so they clear WCAG AA over any background image rather than failing at 1.16:1. A CTA band with a `background_image` gains the same guarantee: a link written into the CTA body now has an explicit readable default where it previously fell back to the unreadable light accent. Per-instance `--section-accent` / `--stats-number-color` / `--cta-body-color` slots still win on all three bands (#461).

### Docs
- `ai-instructions/retheme.md` documents the overlay accent role and its pairing contract (the default must stay ≥ 4.5:1 against the scrim-over-white worst case, which is why it is intentionally near-white), and both it and `AI_CONTEXT.md` now list all eight auto-derived accent-family tokens (#461).

### Tests
- A rendered Playwright E2E (`tests/e2e/style-render.spec.ts`) seeds all three background-image bands with a white image fixture at 375px and 1280px and proves each accent surface clears 4.5:1 against the rendered scrim-over-white composite, and that a per-instance color slot still wins. css-lint pins lock the routing on every band (and guard against a regression back to the bare accent or the inverted role), and a `pp_token_families()` test pins the new roles as registered, auto-derived, divergence-tracked family members (#461).

## [v1.5.1] — 2026-07-20 — the global button tokens become a real one-knob: `--btn-bg` / `--btn-text` at `:root` now restyle every composed button (#458)

**Setting `--btn-bg`, `--btn-text`, `--btn-border-color`, or `--btn-shadow` at `:root` now recolors every primary button on a composed page — the section-panel CTA, the CTA-block button, and the hero button together. #441 registered these four global button tokens so the AI could discover them, but on a real page the premium `main .btn` cascade and the `.cta` / `.hero` button rules routed their fill, border, ink, and shadow through per-component slots, not through `--btn-*`, so the "global button" knob was discoverable yet nearly inert. It is now a genuine site-wide knob: change one token and every button follows, while a button that a component already restyled keeps its own look.**

The four tokens are rerouted through the actual cascade winners for every composed primary button, so they finally reach rendered `<main>` output. Unset output is byte-identical to before: three of the tokens (`--btn-bg` / `--btn-border-color` / `--btn-shadow`) register as `initial`, so each button rule resolves its own historical literal until you set the token, and `--btn-text` keeps its `--color-bg` default (the intentional light-on-accent inversion). Per-component slots still win where set: `--btn-*` sits between the per-instance `--cta-button-*` / `--cta-accent` / `--hero-accent` slots and the literal fallback, so a site-wide `--btn-bg` recolors only the buttons a component has not individually styled. Fill and border stay independent knobs, matching the bare `.btn` primitive — set `--btn-border-color` too when you want borders to move with a recolored fill on section-panel buttons.

### Fixed
- The global button tokens (`--btn-bg` / `--btn-text` / `--btn-border-color` / `--btn-shadow`) now restyle composed primary buttons site-wide. The premium `main .btn` primary cascade, the `.cta .btn` fill/border winner, and the `.hero .btn` primary rule route their fallbacks through `--btn-*`, bottoming out at today's literals so an unset button renders identically to before (#458).

### Docs
- `ai-instructions/retheme.md` and `ai-instructions/style-component.md` now describe `--btn-*` as the working site-wide button surface (with the per-component-slot precedence and the fill/border independence), replacing the earlier note that documented them as inert baseline defaults masked by the premium cascade (#458).

### Tests
- A rendered Playwright E2E (`tests/e2e/style-render.spec.ts`) proves the one-knob end to end at 375px and 1280px: an unset composed CTA, hero, hero-secondary, and section-panel primary render byte-identically; `--btn-bg` / `--btn-text` / `--btn-shadow` at `:root` restyle all of them including the section-panel button and its shadow; and a per-component `--cta-button-bg` still beats the global `--btn-bg`. css-lint and `pp_design_tokens()` pins track the `initial` registration (#458).

## [v1.5.0] — 2026-07-20 — default-quality release: the v1.5.0 gate is closed (#141)

**This is a gate rollup marker: the fifteen changes of this release shipped in working versions 1.4.6–1.4.20 below, and this entry carries no new code beyond the five-file version bump and doc freshness. What 1.5.0 marks is the close of the default-quality gate (#141, Part 1.9), opened by the 2026-07-19 comprehensive audit of what PromptingPress renders when an AI ships a page with no overrides at all. The through-line: unset output is now structurally, typographically, chromatically, and semantically sound, so normal AI-created pages no longer need per-page rescues for basics. Band headings share one fluid scale at every viewport — mobile section/grid/CTA titles render as headings again instead of body-size text, and CTA titles are real headings on desktop too (#436). The three holdout bands (table, logos, embed) joined the shared rhythm and slot contract, so all nine bands render symmetric, equal, slot-tunable padding (#438), a spaced hero keeps its symmetry after another band on mobile (#434), and the band after a FAQ resolves the adjacent rhythm correctly now that the JSON-LD script lives inside the FAQ's own section (#432). Dark bands carry an on-inverted accent role, so default links and stats numbers on inverted surfaces meet WCAG AA instead of 3.23:1 (#437), the inverted text-panel heading is legible on its light panel — on the background-image variant too (#424), and the misleading `theme:\"dark\"` value is superseded by the truthful `muted`, with `dark` rendering identically forever through a renderer alias (#442). Supporting-text props share one documented inline-markup contract (links in a CTA body render as links, sanitized, with their dark-band contrast shipped in the same change) (#439), an image-less split hero degrades to a single column with a composition smell instead of reserving an empty half-band (#440), and the raw-hex guards stopped false-positiving on issue refs in comments via one shared checker (#289). The customization contract got truthful end to end: stats gained its radius and max-width slots (#383), the FAQ heading gap its missing slot (#352), all nine band schemas state the same real padding default (#446), the global `--btn-*` button tokens are registered and documented with their honest reach (#441), and the styling docs finally state which slot types accept `var()` plus how to fuse adjacent bands (#377).**

This release bumps the version across the five synced files from 1.4.20 to 1.5.0 and updates README.md's project-status strings. Release evidence (recorded on #141): the audit's rendered pass re-run on a seeded ten-band default page at 375/768/1280 — every band symmetric and equal per breakpoint, every band h2 on the shared scale (28px mobile floor, no body-size headings), every dark-band link computing 8.33:1, the CTA body link rendering as a real sanitized anchor, the image-less split hero collapsing to one column, and the inverted panel heading plus items dark-on-light at 16.5:1. No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.5.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.5.0 =` rollup entry.

## [v1.4.20] — 2026-07-20 — the styling docs finally say which slot types accept `var()`, and how to fuse two same-background bands (#377)

**`ai-instructions/style-component.md` documented `var()` only for the `color` type and only ever warned against `var()` *nested* inside `clamp()`/`calc()`, so the site-building AI reasonably inferred a bare `var(--space-lg)` was fine in a length slot — and got rejected. The slot-type table now states the negative outright: only `color`, `gradient`, `shadow`, and `font-family` accept a `var()` reference, each in a bounded way, and `length`, `number`, `duration`, `position`, and `ratio` are literal-only. The "don't nest `var()`" bullet no longer reads as permission for the bare form. A new spacing heuristic explains how to make two adjacent same-background components read as one seamless colored band, and names the margin-collapse seam that bites when you forget the last step.**

Documentation only — no validator or rendering behavior changed. The engine has always rejected `var()` in `length`/`number`/`duration`/`position`/`ratio` slots (`_pp_validate_length()` and friends in `lib/apply.php`); the docs simply never said so, and the lone length-adjacent `var()` note pointed the wrong way. The same explicit rule is now also in the runtime chat prompt (`lib/ai-context.php`), which had the identical gap. The adjacency heuristic is written for today's symmetric `--pp-band-padding` rhythm (bands ship non-zero, symmetric vertical padding by default), so fusing two bands is a deliberate act: match the backgrounds, zero the facing paddings, and zero the trailing element's bottom margin — otherwise that margin escapes the zero-padding edge and shows the page background as a thin seam.

### Docs
- `ai-instructions/style-component.md` adds an explicit "which types accept `var()`" note beside the slot-type table (`color`/`gradient`/`shadow`/`font-family` accept it in bounded forms; `length`/`number`/`duration`/`position`/`ratio` are literal-only), rewrites the `clamp()`/`calc()` bullet so it can no longer be read as permitting a bare `var()`, and adds a "Fusing adjacent components into one colored band" section that names the margin-collapse seam failure mode. `lib/ai-context.php`'s runtime style-slot rules gain the same literal-only statement (#377).

### Tests
- `tests/AiContextTest.php` adds `testSystemPromptStatesLiteralOnlySlotTypesRejectVar`, pinning that the assembled chat system prompt names which types accept `var()` and states that `length`/`number`/`duration`/`position`/`ratio` are literal-only, so the guidance cannot silently regress (#377).

## [v1.4.19] — 2026-07-20 — the global button color tokens join the documented token contract (#441)

**The shared `.btn` primitive reads four color custom properties — `--btn-bg`, `--btn-text`, `--btn-border-color`, `--btn-shadow` — but only `--btn-padding-*` and `--btn-radius` were registered in `base.css`'s first `:root` token block. The four color tokens were consumed everywhere and documented nowhere: not in the token registry `pp_design_tokens()` parses, not in `ai-instructions/`, not in `AI_CONTEXT.md`. That is contract drift in the discoverable direction — the real button surface and the documented one were different sets — so the site-building AI could not find the `.btn` primitive's color defaults and fell back to per-component `--cta-button-*` rescues. The four tokens are now registered (so they surface through `pp_design_tokens()` to the AI), documented with their true reach, and bound to the CSS by a test so the sets cannot drift apart again.**

Registration is byte-identical when unset: each token is registered at the exact value its consuming `var()` already fell back to (`--btn-bg: var(--color-accent)`, `--btn-text: var(--color-bg)`, `--btn-border-color: var(--color-accent)`, `--btn-shadow: none`), so `var(--btn-bg, var(--color-accent))` resolves through the token to the same computed value. The docs tell the truth about reach rather than overselling a one-knob: `--btn-text` is the fallback in every button's ink rule (base `.btn`, `.cta .btn`, hero cta2), but on a composed page every primary button renders inside `<main>`, where the higher-specificity premium `main .btn` cascade governs fill/border/ink through `--cta-*` / `--hero-*` / `--color-bg`, not `--btn-*`. So these are the shared primitive's baseline defaults, not a site-wide button-restyle knob; to recolor buttons site-wide, change `--color-accent` or the per-component slots. The `--btn-text` → `--color-bg` inversion coupling (button ink follows the page background, not a text token) is now documented explicitly.

### Docs
- `assets/css/base.css` registers `--btn-bg`, `--btn-text`, `--btn-border-color`, `--btn-shadow` in the first `:root` token block with the standard annotated `/* type: … */` comments, so they become part of `pp_design_tokens()` and reach the AI via `lib/ai-context.php`. `ai-instructions/retheme.md` and `ai-instructions/style-component.md` document the four tokens, the `--btn-text` → `--color-bg` inversion coupling, and the premium-cascade masking (baseline defaults, not a site-wide knob). `AI_CONTEXT.md`'s token list and the `components.css` `.btn` comment are updated to match (#441).

### Tests
- `tests/js/css-lint.test.js` adds a token-contract drift guard: every `--btn-*` custom property consumed anywhere in the theme CSS must be registered in `base.css`'s first `:root` block or `--pp-`-prefixed, with a detection proof that an unregistered `--btn-*` fails and a registered/`--pp-` one passes. `tests/ActionsTest.php` asserts `pp_design_tokens()` exposes the four tokens with the correct `color`/`shadow` types and their historical-fallback values. `tests/e2e/style-render.spec.ts` pins the byte-identical unset output at the one composed-page site `--btn-text` reaches (the hero secondary CTA ink) (#441).

## [v1.4.18] — 2026-07-20 — every band schema now states the same truthful padding default (#446)

**The six older band schemas (`section`, `grid`, `cta`, `stats`, `faq`, `testimonials`) still advertised `"default": "var(--space-xl)"` on their `--*-padding-top/bottom` slots, but the CSS has routed those slots through the shared `--pp-band-padding` rhythm since #431 — so the default the site-building AI read (~64px) disagreed with what actually renders (the fluid symmetric band rhythm). The three slots minted by #438 (`table`, `logos`, `embed`) already stated the truthful `var(--pp-band-padding)`, so the band family contradicted itself about the same fact. All nine band schemas now state the same, truthful default, and a test keeps the padding-slot family consistent so it cannot drift apart again.**

This is a metadata-only change to schema `default` fields (descriptive text the AI reads to predict unset output). It is not a styling change: the `default` field is never emitted as CSS — `pp_render_style_vars()` reads only a slot's `type` and emits only explicitly-set overrides — so an unset band renders byte-identically to before, falling through the stylesheet's own `var(--x-padding-top, var(--pp-band-padding))` chain. The six schemas' padding slot entries (default plus description) are now byte-identical to the #438 three. `hero` is deliberately left alone: its CSS falls back to `var(--space-xl)`/`var(--space-2xl)`, not the band rhythm, so its `var(--space-xl)` default is truthful and it is not a band.

### Fixed
- `components/{section,grid,cta,stats,faq,testimonials}/schema.json` — the `--*-padding-top/bottom` style slots now declare `"default": "var(--pp-band-padding)"` with the shared band-rhythm description, matching the already-truthful `table`/`logos`/`embed` schemas (#438). The stale `var(--space-xl)` default no longer teaches the AI the wrong geometry for these bands. Zero rendered-output difference (#446).

### Tests
- `tests/SchemaThemeConsistencyTest.php` adds `testBandPaddingSlotDefaultsAreUniformAndTruthful`, which asserts that all nine band components' `--*-padding-top/bottom` slot `default` fields are uniform and equal `var(--pp-band-padding)`, and guards that `hero` (a non-band) does not adopt the band default on either edge. The test fails if any band diverges again. `tests/ActionsTest.php` refreshes the illustrative slot description in the `_pp_suggest_alternative_value` padding example to the current band wording (#446).

## [v1.4.17] — 2026-07-20 — the `faq` header rhythm is now fully authorable (#352)

**The gap below a `faq` heading was the one band-heading rhythm the site-building AI could not touch. Every other header-bearing band (`section`, `grid`, `testimonials`) already routed its heading's bottom margin through a style slot; `faq` alone kept a bare literal, so "tighten this FAQ header" was inexpressible. A new `--faq-heading-margin-bottom` slot closes that gap. Set it to pull the accordion list closer to the heading or push it further away; leave it unset and the band renders exactly as before.**

`faq` renders no subheading, so the slot governs the space between the heading and the accordion list. It follows the `--<component>-heading-margin-bottom` pattern established for `section` and `grid`, and it is length-typed and validated by the shared style engine. The slot is wired at all three places the heading's bottom margin is declared (the base rule plus the desktop and mobile premium-typography rules), each keeping today's literal as the fallback, so an unset `faq` heading computes the same margin it always has (26.4px desktop, 20px mobile). `faq` now has 18 style slots; the theme total is 212.

### Added
- `--faq-heading-margin-bottom` (length, default `var(--space-lg)`) style slot on the `faq` component, settable via `style_component` or a composition `style` block. It controls the space below the FAQ heading, before the accordion list, so the header rhythm is fully slot-driven like the other band components. Unset, the heading keeps its current bottom margin at every breakpoint (#352).

### Tests
- `tests/e2e/style-render.spec.ts` adds rendered proof: a set `--faq-heading-margin-bottom` wins on the `.faq__heading` at 375px and 1280px (both premium-typography breakpoints), while an unset `faq` heading computes the unchanged literal (26.4px desktop, 20px mobile). The schema entry and the routing of all three CSS declarations through the slot are enforced by the keystone `StyleSlotContractTest` (#352).

## [v1.4.16] — 2026-07-20 — a `stats` band can now be a contained, rounded metrics card (#383)

**The `stats` component rendered only as a full-width band. It exposed color, padding, and typography slots but no way to round its corners or cap its width, so the common marketing treatment of a contained, rounded metrics card (a rounded navy panel inset from the page edges) was inexpressible. Two new style slots fix that: `--stats-radius` rounds the band and `--stats-max-width` caps and centers it. Set both and the band becomes a card; leave them unset and the band renders exactly as before.**

Both slots follow the namespaced `*-radius` pattern the hero, section, and cta bands already use, and both are length-typed and validated by the shared style engine. Their defaults are inert: `--stats-radius` defaults to `0` and `--stats-max-width` to `none`, and the centering `margin-inline: auto` collapses to zero at full width, so an unset `stats` band is byte-identical to the previous full-bleed render. To widen a capped band back out, set `--stats-max-width` to `100%`.

### Added
- `--stats-radius` (length, default `0`) and `--stats-max-width` (length, default `none`) style slots on the `stats` component, settable via `style_component` or a composition `style` block. Together they turn a full-width stats band into a contained, rounded metrics card: `--stats-max-width` caps the band and centers it with automatic side margins, and `--stats-radius` rounds its background. Unset, the band spans full width with square corners unchanged (#383).

### Docs
- `ai-instructions/style-component.md` documents the two new slots and how to combine them for a contained card (and that `--stats-max-width: 100%` is how to remove the cap, since the type has no `none` input). `AI_CONTEXT.md` and `README.md` now report 211 style slots (stats: 12) (#383).

### Tests
- `tests/e2e/style-render.spec.ts` adds rendered-proof coverage: `--stats-radius` reaches the band at 375px and 1280px, `--stats-max-width` caps and centers the band at 1280px with equal side gutters, an unset band computes `border-radius: 0px` / `max-width: none` at full width, and a 640px cap never overflows at 375px. `tests/js/css-lint.test.js` pins that both declarations route through their slots with the exact `0` / `none` fallbacks. The schema entries are auto-picked up by the keystone `StyleSlotContractTest` (#383).

## [v1.4.15] — 2026-07-20 — a `split` hero with no image no longer reserves an empty half-band (#440)

**A `hero` with `layout: "split"` but no image and no `proof` used to keep the two-column split grid, squeezing the headline into the left half with dead whitespace across the rest of the band. That is a common authoring state — split chosen before media is imported, or an image prop dropped in an edit. Now that state degrades to the single-column `left` layout so the text uses the full content width and no empty column is reserved, and `wp pp check page` surfaces a `hero_split_no_media` advisory so the author knows to add media (or switch layouts). A `split` hero with an image, or with `proof` filling the second column, renders exactly as before.**

The degradation is render-time only: the stored `layout` value is untouched, so importing an image later restores the split with no re-authoring. A resolvable `image_id` now counts as the split's image even when `image_url` is empty (the renderer resolves the attachment), so an id-only split renders its image instead of an empty column. The warning is deliberately advisory, not a hard failure, because the media may arrive in a following step; it rides the existing composition-smell surface, not a new channel.

### Fixed
- A `hero` with `layout: "split"` and no image and no `proof` now renders as the single-column `left` layout (text at full content width) instead of reserving an empty right column. Splits with an image (`image_url` or a resolvable `image_id`) or with `proof` are unchanged and render byte-identically. A split whose only image is an `image_id` now renders that attachment rather than leaving the second column empty (#440).

### Added
- A `hero_split_no_media` composition-smell warning: `wp pp check page` (and the shared advisory surface) flags a `split` hero that has no image and no `proof` — the state that degrades to a single column — so authors are nudged to add media or pick `centered`/`left`. Advisory only; it never blocks a write (#440).

### Docs
- `components/hero/schema.json`, `AI_CONTEXT.md`, `lib/ai-context.php`, and `ai-instructions/website-building.md` now state that a `split` hero needs an image or `proof` and otherwise degrades to `left`, so the AI stops offering an image-less `split` as a fix for an unbalanced hero (#440).

### Tests
- New PHPUnit coverage pins the three acceptance states (`split` + image unchanged, `split` + `proof` unchanged, `split` + neither → `hero--left` fallback with no image wrap or surface), the `image_id`-only and non-numeric-`image_id` cases, and the degradation dropping split-only geometry attributes. `tests/GuardrailsTest.php` pins that `hero_split_no_media` fires for a media-less split and stays silent when an image, `image_id`, or `proof` is present, including the non-numeric-`image_id` alignment with the renderer. `tests/e2e/style-render.spec.ts` adds the computed-geometry test that would have caught the bug: at 1280px the degraded hero's `.hero__inner` has no reserved second grid track (#440).

## [v1.4.14] — 2026-07-20 — supporting text can carry a link, and every text prop now states whether it accepts HTML (#439)

**Text props had an inconsistent, undocumented markup contract. `section.body` and `faq.answer` accepted HTML, but `cta.text`, `grid.items[].text`, and `testimonials.items[].quote` escaped it — so a link written into a CTA body or a card rendered as visible `<a href=...>` source on the page, while the same content in a section body worked. Nothing in the schemas said which prop did which. Now there is one predictable rule: those three supporting-text props accept a bounded inline HTML subset (`a`, `strong`, `em`, `br`), short label-class props (titles, eyebrows, button text, stat labels) stay plain text, and every text prop's schema description states its contract in plain words.**

A link or light emphasis in supporting copy is normal marketing content, so those props now sanitize it through a shared allowlist (`pp_kses_inline`) instead of escaping it. The allowlist is deny-by-default: block elements (`<p>`, `<ul>`, `<h2>`), `<script>`, event handlers, and `javascript:` URLs are always stripped, whoever authored the content. Because an inverted CTA or a stacked testimonial can now carry a real link on the dark band, those links are colored for WCAG AA contrast at the same time (matching the dark-band link treatment shipped in #437).

**Behavior change:** on existing pages, a `cta.text` / `grid.items[].text` / `testimonials.items[].quote` value that already contains raw markup (e.g. an `<a>` tag) now renders that markup instead of showing it as escaped source. This is the intended fix. Content that was previously written as escaped entities (`&lt;a&gt;`) stays literal text — it is not reactivated.

### Changed
- `cta.text`, `grid.items[].text`, and `testimonials.items[].quote` now accept an inline HTML subset (`a`, `strong`, `em`, `br`) via the shared `pp_kses_inline()` helper, sanitized with an explicit allowlist. A link in supporting copy renders as a working anchor instead of escaped source. All other text props (titles, eyebrows, button text, `stats.items[].label`, and every URL) stay plain-text escaped, unchanged (#439).

### Fixed
- An inverted (dark-band) CTA body link and an inverted stacked-testimonial quote link now meet WCAG AA contrast: they route through the on-inverted accent role instead of the light-surface accent that measures only 3.23:1 on the dark band. Links inside light cards (grid cards, the testimonials grid layout) keep the standard accent, which is already AA on the light card (#439).

### Docs
- `ai-instructions/composition.md` now documents the text content model once: a table of which props are Rich HTML, Inline HTML, or Plain text. Every affected schema `description` (across `cta`, `grid`, `testimonials`, `stats`, `section`, `faq`) states its markup contract explicitly. `ai-instructions/add-component.md` and `docs/AI_IMPLEMENTATION_RECIPES.md` list `pp_kses_inline` alongside `esc_html` / `wp_kses_post` (#439).

### Tests
- New PHPUnit coverage (`tests/TextPropMarkupContractTest.php`) pins the contract per prop: allowlisted tags survive, `<script>`/block tags are stripped, plain text is unchanged, non-string input coerces safely, pre-escaped content stays literal, and plain-text props still escape byte-identically — including the case that would have caught this bug, a link in `cta.text` that must not render as escaped source. A doc-contract test enumerates every text prop and fails if a description drops its markup statement. `tests/e2e/style-render.spec.ts` proves a seeded CTA link renders as a clickable anchor with no literal `<a` in the band text, and extends the dark-band contrast suite to the CTA and stacked-testimonial link surfaces (>= 4.5:1 at 375 and 1280 px). `tests/js/css-lint.test.js` pins the two new dark-band link remaps (#439).

## [v1.4.13] — 2026-07-20 — the surface-tint band theme is now named `muted`, and the name matches what it renders (#442)

**The band `theme` value that paints a light surface-tinted band was named `dark` — a misnomer, because it renders a near-white `--color-surface` band, not a dark one. Anyone (or any AI) who asked for a "dark band" and reached for the obvious value got a pale tint; the genuinely dark band is `inverted`. On top of that, the eight band components disagreed with each other about what the value meant, so the answer depended on which schema was read last. The value is renamed to `muted` across all eight components with one identical description, and `inverted` is documented as the dark band. Pages authored before this release keep rendering exactly as they did: the old `dark` value is still accepted and renders byte-identically as a deprecated alias of `muted`.**

The rename lives where it matters — the component schemas (the authoring contract the AI reads) and a single shared renderer helper, `pp_theme_class()`, so the eight components can never drift apart again. New-page guidance offers only `muted`, with a note to use `inverted` when you actually want a dark band. Stored `dark` values are never migrated or flagged: they render the same light band forever, preflight and validation leave them alone, and the composition editor preserves a legacy `dark` value on re-save instead of quietly snapping it back to the default.

### Changed
- The `theme` enum on all eight band components (`cta`, `section`, `faq`, `grid`, `embed`, `logos`, `stats`, `testimonials`) now advertises `default | muted | inverted` with a single byte-identical description across all eight. `muted` is the light `--color-surface` tinted band with framing borders (the treatment previously mis-named `dark`); `inverted` is the genuinely dark band (#442).

### Fixed
- The misnamed `dark` theme value no longer misleads authors into a light band when they wanted a dark one. It is retained as a deprecated renderer-level alias of `muted` so every existing page renders byte-identically, but it is no longer offered for new pages. The composition editor now keeps a stored legacy `dark` value selected on re-save instead of silently resetting it to `default` (#442).

### Docs
- `AI_CONTEXT.md`, `ai-instructions/composition.md`, `ai-instructions/website-building.md`, `ai-instructions/style-component.md`, and `README.md` now offer `muted` (not `dark`) for new pages and carry a "for a dark band use `inverted`" warning (#442).

### Tests
- A schema-consistency test fails if any two components that share an enum (same value set) describe it differently — the exact drift that let `theme`'s value be documented three ways. PHPUnit pins the shared `pp_theme_class()` helper and the render layer: `muted` and legacy `dark` both emit the surface-band class byte-identically, `inverted` stays inverted, and invalid values coerce to the default. A vitest test proves the editor round-trips a legacy `dark` value and refuses to reflect a malformed one. `tests/e2e/style-render.spec.ts` asserts the computed band background per theme value matches its documented meaning (#442).

## [v1.4.12] — 2026-07-20 — the heading of a text-panel on a dark band is legible again (#424)

**A `section` with `theme: inverted` (or a background image) renders a light panel box on the dark band. The panel's list items were dark and readable, but its heading came out light-on-light and effectively invisible. The dark-band styling colors every heading in the section with the band's light title color, and that rule outranked the panel's own text color — so the heading, but not the list below it, took the wrong color. The panel is a self-contained light surface with its own text color, so its heading now stays with the panel and reads in the panel's dark text, while the main on-band section title stays light exactly as before.**

The panel heading and the on-band section title are now independently controllable: the panel heading follows the panel's own `--section-panel-text` (set it, and only the heading and panel body move), and the band title follows `--section-title-color` (set it, and only the title moves). Neither bleeds into the other. The same fix applies to a text-panel placed on a background-image section, which is the identical dark-surface case. Default light-band output is unchanged.

### Fixed
- The heading of a `layout: text-panel` panel on an inverted or background-image `section` now renders in the panel's own text color (`--section-panel-text`, dark by default on the light panel) instead of inheriting the dark band's light title color, so it is legible on the light panel. The on-band section title still uses `--section-title-color`, and the two color slots stay independently authorable — setting one no longer affects the other (#424).

### Tests
- `tests/e2e/style-render.spec.ts` adds a text-panel legibility suite that proves, at 375px and 1280px, the panel heading computes the panel's dark text (matching the panel list items and clearing WCAG AA against the light panel) while the on-band title stays light — for both an inverted band and a background-image band — plus slot-independence: an explicit `--section-panel-text` moves the panel heading and items together while leaving the band title, and an explicit `--section-title-color` moves only the band title. `tests/js/css-lint.test.js` pins that both dark-band heading rules (`.pp-section--inverted` and `.section--has-bg-image`) carve `.section__panel-heading` out of their bare `h2,h3` branches, and that the panel heading routes color through `--section-panel-text` (#424).

## [v1.4.11] — 2026-07-20 — links on inverted (dark) bands now meet WCAG AA by default (#437)

**The default accent color is tuned for light surfaces, where it clears the WCAG AA contrast bar. On the dark "inverted" band it did not: a link in an inverted section or embed rendered at about 3.2:1 against the dark background, below the 4.5:1 minimum for body text, and inverted stats numbers looked dim for the same reason. The theme now carries a surface-paired accent — a lighter accent tint used only on inverted bands, where it measures about 8.3:1. Links in inverted sections and embeds, and inverted stats numbers, pick it up automatically. Links that sit on the light cards inside an inverted band (grid cards, FAQ panels) keep the normal accent, which is already AA on those light surfaces, so nothing there changes.**

The new color is a derived token, `--color-accent-on-inverted` (plus a brighter `--color-accent-on-inverted-hover`), computed from `--color-accent` the same way the other accent-family tints are. Change your accent and the on-inverted tint re-derives with it; pin it to a custom value and, like every other derived token, you get a divergence warning if a later accent change would no longer reach it. Existing per-band color slots still win when set, and default light-band output is byte-identical.

### Fixed
- Body links inside inverted `section` and `embed` bands, and the accent-colored numbers in an inverted `stats` band, now route through a light on-inverted accent tint that meets WCAG AA against the dark band background (4.5:1 for text, well above the 3:1 large-text bar for the stats numbers) instead of the light-surface accent that measured only 3.23:1 there. Links on the light cards of an inverted grid, FAQ, or testimonials band are unchanged — the accent is already AA on those light surfaces (#437).

### Added
- `--color-accent-on-inverted` and `--color-accent-on-inverted-hover` design tokens: a surface-paired accent for the dark inverted band, auto-derived from `--color-accent` and participating in the derived-token divergence warnings. Retheme contract: if you change `--color-accent` or `--color-bg-inverted`, keep `--color-accent-on-inverted` at ≥ 4.5:1 on the inverted background (#437).

### Docs
- `ai-instructions/retheme.md` documents the surface-paired accent and its ≥ 4.5:1 pairing contract; the auto-derived accent-family count (four → six) is updated in `retheme.md` and `AI_CONTEXT.md` (#437).

### Tests
- `tests/e2e/style-render.spec.ts` seeds a body link in every inverted band that renders one and asserts the computed WCAG contrast against the link's real rendered background at 375px and 1280px: dark-band links (section, embed) clear 4.5:1, inverted stats numbers clear 3:1, and light-card links (grid, FAQ) stay on the accent and remain AA. `tests/js/css-lint.test.js` pins that each dark-band inverted variant routes its link color through the on-inverted role, and `tests/ApplyTest.php` proves the two new tokens are registered derived colors that auto-derive from `--color-accent` and surface in the masked-derived-override machinery (#437).

## [v1.4.10] — 2026-07-20 — a band placed right after an FAQ picks up the normal between-band spacing again (#432)

**Every band-level component that follows another band gets a tighter, tuned top edge so stacked sections read as evenly spaced blocks. One placement quietly missed out: a band placed immediately after an FAQ. The FAQ component emits an invisible FAQPage structured-data `<script>` tag for search engines, and it was being written just after the FAQ section rather than inside it. That stray tag sat between the FAQ and the next band, and the between-band spacing rule only reaches a band whose immediate previous neighbor is another band — so the band after an FAQ fell back to its own larger top padding. The script now lives inside the FAQ section, so the FAQ is once again the next band's immediate neighbor and the spacing rule applies. Search-engine output is unchanged: the same FAQ rich-result markup is still emitted, just one line earlier in the document, which Google reads identically.**

The structured-data script is metadata that is valid anywhere in the page body, so moving it inside the FAQ section has no SEO cost and no visible effect of its own. The spacing difference was masked on current default pages because the between-band top tier is presently pinned equal to a band's own top tier, so the two resolved to the same value — but the selector was still missing the band, so any future retune of the between-band tier, or any per-band top-padding override on the band after an FAQ, would have silently taken the wrong path. This restores the correct path.

### Fixed
- The FAQPage JSON-LD `<script>` now renders inside the FAQ `<section>` instead of as a trailing sibling after it, so a band-level component placed immediately after an FAQ receives the shared adjacent-top rhythm (and honors its own `--*-padding-top` override on that edge) exactly like a band placed after any other band (#432).

### Tests
- `tests/ComponentPropsTest.php` asserts the rendered FAQ places its JSON-LD script before `</section>` with nothing following the section, and that the schema payload is unchanged. `tests/e2e/style-render.spec.ts` adds a band-after-FAQ suite that un-pins the two shared spacing tiers and proves the band after an FAQ resolves the adjacent-top tier at 1280px (its own tier on mobile), and confirms the script is no longer an element sibling of the following band. The obsolete "keep faq last" workaround comments in the #430/#431/#436 suites were removed (#432).

## [v1.4.9] — 2026-07-20 — a compact or spacious hero placed after another band renders even top and bottom padding on mobile again (#434)

**A hero carrying `spacing: compact` or `spacing: spacious` is supposed to read as a centered block: the same padding on top as on the bottom. That held on desktop, but on phones (viewports up to 767px) it broke whenever the hero followed another band. The top edge got shaved down to the normal between-band rhythm while the bottom kept the full spacing value, so a spacious hero rendered about 54px on top and 112px on the bottom — visibly bottom-heavy. Now the explicit spacing override wins both edges at every breakpoint, exactly as it always did on desktop, so a spaced hero is symmetric no matter where it sits on the page.**

The desktop stylesheet already restated the spacing override at higher specificity so it beat the between-band rhythm; the mobile stylesheet never did, so the generic mobile rhythm rule won the top edge alone. The fix mirrors the desktop restatement into the mobile breakpoint, setting both edges to the mobile spacing tier (compact stays 32px, spacious stays 112px). Nothing about how you configure pages changed: only a `compact`/`spacious` hero placed after another band on mobile is affected, and only its top edge moves — up, to match its own bottom.

### Fixed
- On mobile (≤767px), a hero with `spacing: compact` or `spacing: spacious` placed after another band-level component now renders equal top and bottom padding instead of a shaved top edge, matching desktop behavior and the symmetric intent of the spacing override (#434).

### Tests
- `tests/e2e/style-render.spec.ts` adds a rendered-symmetry case for a spaced hero in the adjacent (after-another-band) position, asserting exact per-breakpoint padding values (compact 32px, spacious 112px at 375px / 160px at 1280px) so a "both edges wrong" regression can't pass on symmetry alone. `tests/js/css-lint.test.js` pins the mobile spacing restatement inside the `max-width:767px` block, after the adjacent-rhythm rule, on the correct per-breakpoint tier (#434).

## [v1.4.8] — 2026-07-20 — the last three band components join the shared spacing contract, so table, logos, and embed bands stop rendering lopsided (#438)

**Three band-level components — the data `table`, the `logos` strip, and the `embed` block — were the last holdouts that hardcoded their own 64px vertical padding instead of consuming the theme's shared band rhythm. So while every other band rendered as a symmetric, centered block, a table/logos/embed placed after another band rendered top-heavy (about 77px on top, 64px on the bottom at desktop) — the exact lopsided shape v1.4.5 removed everywhere else. They were also the only bands an AI couldn't tune through the documented `--*-padding-*` slots, because they had none. Now all nine band components share one spacing model and one styling surface: table, logos, and embed render symmetric padding by default, gain authorable padding and heading-color style slots, and are covered by the same structural and rendered-rhythm test suites as the other six, so this exclusion can't quietly return.**

This is a deliberate change to unstyled output, the same trade v1.4.4/v1.4.5 made for the other six bands: the default vertical padding of an unstyled table, logos, or embed band grows from a flat 64px to the shared symmetric tier (roughly 68–80px on desktop, ~54px on mobile), so these bands line up with their neighbors instead of sitting shorter and off-balance. Nothing about how you configure pages changed — the new slots are all optional and default to the shared rhythm. Table, logos, and embed now expose `--table-section-padding-top/bottom`, `--logos-padding-top/bottom`, and `--embed-padding-top/bottom` to set a band's own edges, each of which also governs the band's adjacent-top edge, plus `--table-section-heading-color`, `--logos-heading-color`, and `--embed-heading-color` to recolor the band heading. (The table component's slots follow its root class `.table-section`, matching the `--table-section-heading-size` slot the component already shipped.) Unset, every one of these resolves to exactly the previous color and to the shared rhythm, so a page that sets none of them changes only in the deliberate padding retune above.

### Fixed
- The `table`, `logos`, and `embed` bands now take their default vertical rhythm from the shared band-padding definition instead of a hardcoded 64px literal, so an unstyled table/logos/embed placed after another band renders equal top and bottom padding at every breakpoint instead of the old top-heavy shape. All nine band components now share one spacing model (#438).

### Added
- Authorable `--table-section-padding-top/bottom`, `--logos-padding-top/bottom`, and `--embed-padding-top/bottom` style slots, so the vertical padding of the table, logos, and embed bands can be set per instance (own edges and adjacent-top edge) like the other six band components. Authorable `--table-section-heading-color`, `--logos-heading-color`, and `--embed-heading-color` slots recolor each band's heading, defaulting to the theme text color (and to the inverted-background text color on the `dark`/`inverted` variants) so unset output is unchanged. Total per-instance style slots: 209 across 10 components (#438).

### Docs
- `AI_CONTEXT.md` and `README.md` updated to the new 209-slot / 10-component totals (#438).

### Tests
- `tests/js/css-lint.test.js` widens the shared-band-rhythm structural suite from six to nine components (own-edge and adjacent-top routing for table, logos, embed), adds a schema-token truthfulness pin (every token a schema documents resolves to a consumed CSS variable), and pins the base and inverted-variant heading-color routing for the three components. `tests/e2e/style-render.spec.ts` widens the computed symmetry and cross-component equality suites to all nine bands at 1280 and 375, and adds render proof that `--table-section-padding-top`, `--logos-padding-top`, and `--embed-padding-top` each win on the adjacent-top edge at both breakpoints (#438).

## [v1.4.7] — 2026-07-20 — band headings share one responsive scale, so section/grid/CTA titles stop collapsing to body size on mobile (#436)

**Below 768px the theme had no working default size for band headings: section, grid, and CTA titles rendered at 16px — exactly body size — on every default page, and CTA titles were body-sized at every breakpoint. The `font-size: var(--slot, inherit)` pattern behind those headings had no base value, so with no per-page override the heading silently inherited body text and the visual hierarchy vanished on phones. Now every band title (section, grid, cta, faq, stats, table, testimonials, logos, embed) draws its default size from one shared, fluid scale defined once in `base.css`, so headings scale down on small screens but never collapse into body copy, and headings at the same structural level render as peers.**

The scale is a single `clamp()` from a ~28px mobile floor to the ~42px ceiling of the prior desktop-only rule, consumed as the fallback of each component's existing `--*-title-size` / `--*-heading-size` slot. This is a deliberate change to unstyled output, and it moves default sizes in three ways: (1) on mobile, section/grid/cta titles rise from 16px to ~28px and CTA rises at every width — the core fix; (2) on desktop, stats, table, testimonials, logos, and embed headings grow from a flat 30px to the shared step (up to ~38–42px), joining the same tier as section/grid/faq; (3) for section/grid/faq specifically, the desktop size is preserved at the 1280px breakpoint (~38.4px) and at the ceiling, but softens by ~3.5px across the 768–1071px tablet band (the old rule was floored flat at 36px there; the fluid scale ramps ~32.5px→36px), the deliberate cost of putting all bands on one fluid scale. Per-page intent is untouched: every `--*-title-size` / `--*-heading-size` slot still wins at every breakpoint, and `--cta-title-size: 60px` overrides the scale exactly as before. Table, logos, embed, and testimonials headings gain a `--*-heading-size` slot so their size is now authorable too, matching the other band components.

### Fixed
- Band-level headings (section, grid, cta, faq, stats, table, testimonials, logos, embed) now have a real default size at every viewport instead of collapsing to body size below 768px. Section, grid, and CTA titles no longer render at 16px on mobile, and CTA titles are no longer body-sized on desktop. All band titles at the same structural level now compute the same size per breakpoint, so stacked sections read as peers (#436).
- The `font-size: var(--slot, inherit)` anti-pattern is removed from every band heading; each now falls back to the shared `--pp-band-heading-size` scale, which is fluid at every viewport and never resolves to the body font size (#436).

### Added
- Authorable `--table-section-heading-size`, `--logos-heading-size`, `--embed-heading-size`, and `--testimonials-heading-size` style slots, so the heading size of the table, logos, embed, and testimonials components can be set per instance like the other band components. Total per-instance style slots: 200 across 10 components (#436).

### Docs
- `AI_CONTEXT.md` and `README.md` updated to the new 200-slot / 10-component totals; `ai-instructions/add-component.md` documents routing a new band heading's `font-size` through its size slot to the shared `--pp-band-heading-size` scale (never `inherit`, never a literal) (#436).

### Tests
- `tests/js/css-lint.test.js` gains a structural suite pinning that every band heading `font-size` routes through its slot and falls back to the shared scale, that `inherit` never appears as a heading font-size fallback, and that the shared clamp is defined with a floor ≥1.5rem and the prior ceiling. `tests/e2e/style-render.spec.ts` gains viewport-typography coverage: at 375/768/1280 every band heading is an `<h2>`, computes the same size per breakpoint, clears the 1.5rem mobile floor, and never equals the body font size, plus render proof that the existing and newly-minted heading-size slots override the scale at mobile and desktop; the core and webfiable-shape scenarios are tagged `@smoke`. `tests/AiContextTest.php` pins that the newly styleable band components surface their heading-size slot in the AI prompt (#436).

## [v1.4.6] — 2026-07-20 — the "no raw hex" guard stops tripping on issue references written in CSS comments (#289)

**The check that keeps hardcoded hex colors out of `components.css` used to flag a GitHub issue reference like `(#226)` written inside a `/* ... */` comment, because `#226` reads as a three-digit hex string. That forced an awkward "write `issue 226`, never `(#226)`" convention in CSS comments and had already broken two builds. Now both the local test guard and the CI check strip `/* ... */` comments before scanning, so a comment can cite `(#226)` freely while a real hardcoded color in a declaration (`color: #226;` or `color: #ff0000;`) still fails exactly as before. The two guards now run the same code, so a comment that passes locally can't fail CI or the reverse.**

The rule itself is unchanged: component CSS must use CSS variables from `base.css`, never raw hex color values. Only the false positive on comment text is fixed. The local `tests/InvariantTest.php` guard and the CI `ai-ready` workflow now both call a single shared checker, `scripts/check-raw-hex.php`, which blanks comments (preserving line numbers and without fusing tokens across a comment boundary) and then applies the same hex pattern as before. The comment stripping is deliberately lexical, not a full CSS parser, so a `/* ... */` byte sequence inside a CSS string literal is also treated as a comment; such text is not a hardcoded color value, so exempting it does not weaken the guard.

### Fixed
- A GitHub issue reference inside a `/* ... */` comment in `assets/css/components.css` (for example `/* fixes overflow (#226) */`) no longer trips the raw-hex guard, in both the local PHPUnit test and the CI `ai-ready` check. A genuine raw hex color in a declaration still fails both. The two guards share one implementation (`scripts/check-raw-hex.php`) so they can never disagree (#289).

### Tests
- New `tests/RawHexGuardTest.php` proves both directions: real hex (`#ff0000`, `#226`) is detected, comment-context issue refs (single-line, multi-line, trailing) pass, a real hex on a line that also carries a comment is still caught, two-digit refs like `#24` stay below the pattern, line numbers stay accurate across multi-line comments and CRLF input, a comment never fuses tokens across its boundary, and the CLI exits 1 on real hex / 0 on a comment-only ref / 2 on a missing file. `tests/InvariantTest.php` now exercises the shared checker against the real `components.css` (#289).

## [v1.4.5] — 2026-07-19 — stacked section bands are now vertically symmetric, so pages stop looking top-cramped and bottom-heavy (#430)

**Every section-level band that followed another band used to render with a tight ~32px top and a much larger ~77px bottom on desktop — a lopsided block. On pages where backgrounds alternate (a dark stats band, an inverted CTA, a tinted section — the normal marketing pattern), the heading hugged the top edge while a large empty gap sat under the last element. Now a band's top padding equals its bottom padding: every stacked band reads as a centered block at every breakpoint, with no per-section `--*-padding-*` tuning. This is the companion retune to v1.4.4's shared spacing model.**

This is a deliberate change to unstyled output: the default top padding of any band that follows another band grows to match its bottom, so contrasting-background bands stop looking off-balance. Two bands that share a background now show more combined space between them, which is the accepted trade for symmetry that can't paint a lopsided edge onto a contrasting band. Nothing about how you configure pages changed — the per-component `--section|grid|cta|stats|faq|testimonials-padding-top/bottom` slots you already use are untouched and still win on every edge and breakpoint, including a band's adjacent top edge. The hero keeps its own larger, already-symmetric page-opening rhythm when it leads the page (its own padding is not retuned); like any band, a component placed after another band takes the shared symmetric top. `data-pp-spacing` compact and spacious stay symmetric.

### Fixed
- A section-level band (section, grid, cta, stats, faq, testimonials) that follows another band now renders equal top and bottom padding by default at every breakpoint, instead of the old tight-top / heavy-bottom shape. Pages with alternating backgrounds no longer look top-cramped and bottom-heavy, and no per-section padding override is needed to balance them (#430).

### Tests
- `tests/js/css-lint.test.js` now pins the adjacent-top rhythm to the band's own padding (the symmetry guarantee) and asserts the mobile block no longer carries a separate adjacent-top value. `tests/e2e/style-render.spec.ts` gains computed-rhythm coverage: every stacked band reports exactly equal top and bottom padding at 1280 and 375, `data-pp-spacing` compact/spacious stay symmetric, and a webfiable-shaped sequence (hero → stats → grid → cta → grid → section → cta) shows no band with the old 32px-top / 77px-bottom shape; the core symmetry scenario and the webfiable-shape scenario are tagged `@smoke` (#430).

## [v1.4.4] — 2026-07-19 — every stacked section now shares one spacing model, so bands stop disagreeing about their own padding (#431)

**The six section-level components — section, grid, CTA, stats, FAQ, and testimonials — used to each carry their own copy of the vertical-padding values, and the copies had drifted apart. Stats and testimonials bands rendered shorter than their neighbors on desktop, a CTA's bottom padding didn't match the plain sections next to it on mobile, and a testimonials band that followed another band ignored a `--testimonials-padding-top` you set on it. Now all six read their default rhythm from a single shared definition, so an unstyled stack lines up: every band agrees on its top and bottom padding at every breakpoint, and every band's padding slot is live on every edge. Setting `--testimonials-padding-top` on an adjacent testimonials band now works, the same as it already did for the other components.**

This is a defaults-and-consistency change: the per-component `--section|grid|cta|stats|faq|testimonials-padding-top/bottom` slots you already use are untouched and still win over the shared default on every edge and breakpoint. Stats, testimonials, and CTA bands change their unset appearance to join the shared tier (stats and testimonials get slightly more vertical padding on desktop; CTA's mobile bottom padding matches its neighbors), which is the point — they stop being the odd ones out. The current top-tighter-than-bottom desktop rhythm is preserved as-is; a companion change will retune those values. Adding a new section-level component now means consuming the shared definition rather than pasting padding numbers, so this class of drift can't quietly come back.

### Fixed
- The six section-level components (section, grid, cta, stats, faq, testimonials) now take their default vertical rhythm from one shared definition instead of per-component literal copies, so an unstyled stack renders identical top and bottom padding across all of them at every breakpoint. Stats and testimonials bands are no longer shorter than their neighbors on desktop, and a CTA's mobile bottom padding matches the plain sections around it (#431).
- A `--testimonials-padding-top` set on a testimonials band that follows another band now takes effect on the top edge at every breakpoint (it was silently ignored before), matching how the other section-level components already behaved (#431).

### Tests
- `tests/js/css-lint.test.js` gains a structural suite that enumerates the six band components and pins every padding declaration (base, premium, adjacent, and mobile contexts) to route through its component slot and fall back to the one shared definition, with a guard that the former literal values live nowhere else in the component stylesheet — so a seventh band copying numbers fails the build. `tests/e2e/style-render.spec.ts` adds computed-rhythm coverage: all six bands report identical unset padding at 1280 and 375, the resurrected testimonials adjacent-edge slot override wins at both breakpoints, and overriding the shared definition at `:root` moves every band's every edge together; the cross-component equality scenario is tagged `@smoke` (#431).

## [v1.4.3] — 2026-07-19 — the footer grows up: a real column grid, an accessible nav landmark, and contact details you can tap (#427)

**The footer used to render as a few text blocks floating in a wide empty band, with a screen-reader nav that was indistinguishable from the header's and a contact block that was dead text. Now the brand blurb, footer menu, and contact block lay out as a deliberate three-column grid on desktop (aligned tops, consistent gaps) and stack cleanly brand → menu → contact → bottom bar on mobile. The footer menu is a proper labelled navigation landmark ("Footer navigation"), so assistive tech no longer confuses it with the header. Email addresses and international phone numbers in your contact block become tappable `mailto:`/`tel:` links inside a semantic `<address>`, while everything else stays exactly as you typed it. A fresh install with only the copyright line still looks intentional, not broken.**

None of this changes how you configure the footer. The same `pp_footer_*` site options and the footer menu drive everything — no new options, no new surface to learn. The contact linker is deliberately conservative: it only links a phone number that carries an explicit international `+` prefix, so order numbers, postcodes, and dates are never mistaken for phone numbers, and any text that isn't a recognizable email or phone passes through untouched. Column headings stay at one consistent level, and a column with no heading set simply renders without one rather than inventing a label. The footer also reserves a designed home for the social-icon row that lands in a future release, so that feature drops in without another layout change.

### Changed
- The footer brand, menu, and contact columns now render on a real grid: three top-aligned columns with consistent gaps on desktop (≥1024px) and a clean brand → menu → contact → bottom-bar stack on narrower screens. Sparse footers degrade gracefully — a footer with only a menu fills the row instead of stranding empty columns, and no configured option ever leaves an empty block or orphaned separator behind (#427).
- The footer navigation is now a labelled `<nav aria-label="Footer navigation">` landmark, distinct from the header's "Main navigation", and column headings render at one consistent level (#427).
- Contact details render inside a semantic `<address>`, with recognizable email addresses linked as `mailto:` and international (`+`-prefixed) phone numbers linked as `tel:`. The `pp_footer_contact` option stays free text; non-matching text is byte-identical to before (#427).

### Tests
- New footer render pins in `tests/FooterChromeTest.php` cover the nav landmark's aria-label, consistent heading levels, the column grouping and DOM order, sparse-config degradation (no empty structural columns), the `<address>` contact wrapper, and the email/phone linkifier — including its conservative boundaries (an identifier-glued `+`-number, a letter-suffixed number, and digits split across newlines are all left untouched). `tests/js/css-lint.test.js` pins the grid/stack mechanism, the reserved social landing slot, and the `<address>` link-color routing. A new `tests/e2e/footer.spec.ts` proves the desktop column geometry, the mobile stack order and delimited bottom bar, and the cleared-config minimal footer in a real browser, with the structural scenario tagged `@smoke` (#427).

## [v1.4.2] — 2026-07-19 — the mobile menu opens as a real panel below the header instead of crushing itself into the header row (#426)

**On a phone, tapping the hamburger used to break the header instead of showing a menu: the menu tried to squeeze in beside the logo and toggle, rendered as a ~94px sliver jammed against the right edge, and stretched the sticky header from 65px to 229px so it covered the page. The toggle also gave no sign it could close anything. Now the menu drops down as a full-width, left-aligned panel below the header row, the logo and toggle stay exactly where they were whether the menu is open or closed, and the hamburger swaps to an X while open so it reads as the close button. Tap outside, tap a link, press Escape, or rotate/resize back to a wide screen and the menu closes cleanly. Desktop navigation and the dropdown submenus are untouched.**

The menu had been a third item in the header's flex row, so opening it competed for space with the logo and toggle. Pulling it out of that row (it now sits below as its own panel) means opening it can never reflow the logo/toggle row or grow the sticky header. The panel matches your header colors (it follows the same `pp_header_bg` you set for the header). Without JavaScript the menu still shows, now as that same panel rather than a broken row. This is layout and behavior only: the header stays theme-owned chrome, so nothing about how you customize it changed (logo, header colors, and menus work exactly as before).

### Fixed
- The mobile navigation menu now opens as a full-width panel below the header instead of squeezing into the header's flex row. Opening it leaves the logo/toggle row byte-identical and no longer distorts the sticky header, and the toggle shows a close (X) icon while open. Closes on toggle re-click, Escape (focus returns to the toggle), link click, and tap outside; resizing or rotating across the tablet breakpoint resets it to closed. Desktop navigation and the submenu dropdowns are unchanged (#426).

### Tests
- New `tests/js/main-nav-toggle.test.js` unit suite covers every open/close path, `aria-expanded` truthfulness, focus return, and the breakpoint state reset. `tests/js/css-lint.test.js` pins the panel layout mechanism and the icon swap so a refactor can't silently regress to the in-row squeeze. A new `tests/e2e/nav-mobile.spec.ts` proves the open-vs-closed row geometry, every close path, and the desktop-unaffected + submenu behavior in a real browser, with the core geometry scenario tagged `@smoke` (#426).

## [v1.4.1] — 2026-07-19 — the nightly full E2E suite is green again: two non-smoke specs caught up to shipped contracts (#423)

**The scheduled nightly E2E run executes the FULL Playwright suite, but PR and push CI run only the `@smoke` subset, so two non-smoke specs that had drifted from already-shipped behavior went red on the nightly without any gating check ever seeing them. Both were test drift, not product regressions: the runtime contracts they pin are correct as landed. This release realigns the tests to those contracts and quiets a stream of expected-absence teardown noise that was burying real failures in the nightly log. Nothing in the shipped theme changed — this is a test-only fix, verified by running the affected specs to green against a live WordPress 7.0 and a full-suite manual run.**

Two specs had fallen behind. The preflight fail-closed test still expected the pre-#409 blanket "Could not record PREFLIGHT state" message, but #409's move to the options-table run-state store split that into distinct causes — an unminted run token now reports the precise "No run state found" not-found class, which the test now pins. The AI-chat Undo test drove a mocked chat stream that never supplied the page baseline the real UI carries, so #404's fail-closed baseline mandate correctly rejected the mocked Undo and the spec timed out; the mock fixture now threads a `page_baseline` exactly the way the real streaming endpoint does, so the test exercises the true post-#404 apply-then-undo path instead of a shortcut. An audit of the remaining non-smoke specs found no further drift. Separately, the token-concurrency spec read `pp_token_overrides` with a command that errors when the option is simply absent (the normal pre-write state), flooding the nightly log with "Does it exist?" noise; it now reads through a default so expected absence stays quiet while genuine failures still surface.

### Fixed
- The token-concurrency E2E spec no longer prints "Could not get 'pp_token_overrides' option. Does it exist?" to the log on every expected-absent read (the normal pre-write / post-cleanup state). It now reads the option through `get_option(..., array())` so an absent option returns empty quietly, while a real read failure still throws and stays visible in the nightly log (#423).

### Tests
- `tests/e2e/actions.spec.ts` pins the post-#409 not-found run-state message class (`No run state found for run token` + `never minted on this install`) for an unminted run token, replacing the stale pre-#409 blanket assertion, so the distinct-cause behavior is what the test guards (#423).
- `tests/e2e/ai-chat.spec.ts` threads a `page_baseline` into the mocked SSE `done` event the way the real streaming endpoint does, so the `remove_component` + Undo spec exercises the real post-#404 composition-CAS apply/refresh/undo path instead of timing out against the fail-closed baseline mandate. A helper reads the page's current composition version the same way the server computes it (#423).

## [v1.4.0] — 2026-07-19 — operator-trust release: the v1.4.0 gate is closed (#141)

**This is a gate rollup marker: the six changes of this release shipped in working versions 1.3.1–1.3.6 below, and this entry carries no new code beyond the five-file version bump and doc freshness. What 1.4.0 marks is the close of the operator-trust gate (#141, Part 1.8), opened by the first external-site dogfood of v1.2.0 in a containerized WP-CLI environment. The through-line: surfaces that lied to or locked out a real operator now tell the truth. Run-token state moved from process-local temp files to a durable options-table store — the operating loop now works when every CLI call is an ephemeral container, with distinct not-found/expired errors and the file flock retired for the shared bounded GET_LOCK engine (#409). Shipped component CSS lost all 284 starter-content ID-selector lines and the dead demo-only class family, enforced forever by a selector-parsing lint, while the premium button treatment now routes through the documented slots — a flat primary button is finally reachable via `--cta-button-bg`/`--cta-button-color`/`--cta-button-shadow`, with the border honoring `--cta-button-border` and following the fill otherwise (#412, #420). Param-type validation failures return the standard `ok:false` envelope instead of a bare stderr Error (#385). A base design-token change masked by divergent derived-family overrides now warns in the apply result and surfaces as an inspect smell, via one shared divergence detector that retired the old hue heuristic (#386). And the site icon/favicon is settable through the typed `update_site_option` path, validated like the logo (#414).**

This release bumps the version across the five synced files from 1.3.6 to 1.4.0 and updates README.md's project-status strings. Release evidence (recorded on #141): a two-process TMPDIR repro proving run-state durability, rendered flat-button + demo-ID-parity verification on dev (which caught and closed the #420 residual), and a typed favicon set end-to-end with core's rendered link tags. No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.4.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.4.0 =` rollup entry.

## [v1.3.6] — 2026-07-19 — the flat-button fill slot `--cta-button-bg` now actually works on the default CTA button; its border slot works too (#420)

**Setting `--cta-button-bg` on a default (primary) CTA button did nothing to the FILL. The premium flat-button slots landed in #412 flattened the gradient, bevel, and ink, but the button kept rendering the site accent, not the slot color — so the documented "flat primary" was only partly reachable, exactly the operator-trust class the v1.4.0 gate is closing. Cause: a pre-existing rule, `.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)`, sets `background-color`/`border-color` from the accent with specificity [0,5,0] — higher than both the `.cta__button` slot block [0,4,0] and the premium `main .btn:not(...)` winner [0,4,1] — so for those two longhands it silently outranked the slot routing. #412's layer-defeat guard did not catch it because that guard covers the `background` shorthand / `background-image` layer, not the `background-color` longhand this rule uses. The fix routes this actual cascade winner through the slots: fill through `--cta-button-bg` / `--cta-button-hover-bg`, border through `--cta-button-border` / `--cta-button-hover-border`, each falling back to the exact prior accent chain. An unset button is byte-identical to before.**

The border routing was the one open call, and it was settled by maintainer decision during review (the recorded 7A): the border honors its own `--cta-button-border` slot when set (matching the sibling `.cta__button` block and the premium winner, and the color slot the schema + AI docs already advertise), and when that slot is unset the border FOLLOWS the fill (`var(--cta-button-bg, ...)`) so a flat button keeps no leftover accent ring. The alternative — routing the border only through the fill slot — was rejected because it would have left the documented `--cta-button-border` slot silently inert on the exact variant this fix is about. The #412 slot-contract lint is extended to the property gap this rule slipped through: every CTA-context primary-fill rule that sets the `background-color` longhand must now route `--cta-button-bg` (rest) / `--cta-button-hover-bg` (hover), with `.hero .btn` deliberately excluded because hero buttons route their own `--hero-accent` slot.

### Fixed
- The default-variant CTA button's fill now honors `--cta-button-bg` / `--cta-button-hover-bg` and its border honors `--cta-button-border` / `--cta-button-hover-border`, instead of hardcoding the site accent. The `.cta .btn:not(...)` rule — the [0,5,0] cascade winner for the `background-color`/`border-color` longhands, which outranked both the `.cta__button` slot block and the premium `main .btn:not(...)` winner — is now routed through those slots with the previous accent chain preserved as the fallback, so a flat primary button (`--cta-button-bg` + `--cta-button-color` + `--cta-button-shadow: none`) renders correctly and an unset button is byte-identical (#420).

### Tests
- `tests/js/css-lint.test.js` adds a #420 guard extending the #412 slot contract to the `background-color` longhand: every CTA-context primary-fill selector (`.cta .btn`, `.cta__button`, and the `main .btn` premium winner as a forward-guard) that sets `background-color` must route `--cta-button-bg` / `--cta-button-hover-bg`, with a detector-proof case (bad accent flagged, slot-routed passes, `.hero .btn` ignored) and exact-declaration behavioral pins both ways for the rest and hover rules (slot set → slot wins; unset → byte-identical accent fallback chain, border layered `--cta-button-border` → `--cta-button-bg` → accent). `tests/ComponentPropsTest.php` updates the `var(--cta-button-bg)` consumption count (6 → 8) for the new fill + border-fallback routing and now strips CSS comments before counting so a comment that names the slot is not miscounted as a consumption (#420).

## [v1.3.5] — 2026-07-19 — you can now set the browser-tab favicon / app icon through the same typed action as the logo (#414)

**Setting a site's favicon had no in-contract path. `update_site_option` let you set the header/footer logo (`pp_logo_id`, a Media Library image attachment) but not the browser-tab favicon or app/OS icon, so an operator working through `wp pp` actions could not complete a basic part of a brand setup: the tab kept the default icon with no typed way to fix it. `site_icon` is now a whitelisted `update_site_option` key. Point it at a Media Library image attachment ID — the same shape and the same image validation as `pp_logo_id` — and WordPress core renders the `<link rel="icon">` and apple-touch-icon tags automatically, no page composition needed. A non-image attachment, a URL, or a bogus ID is rejected with the standard envelope, exactly like the logo.**

`site_icon` is WordPress core's own option, so once it is set the favicon is emitted by core in `wp_head` with no theme rendering code. Setting it through this action writes the attachment directly and does not run the Customizer's square-crop step, so core renders the image as-is: pass a roughly square source (ideally 512px or larger) for a clean icon across the tab, home screen, and app-icon sizes. Any image is accepted with no square/size rejection (a hard size gate would be a rule the logo keys do not have), and like every attachment-ID option, clearing it with an empty value or `0` is rejected rather than treated as an unset — the favicon and the logo stay independent assets set by separate keys.

### Added
- `update_site_option` now whitelists WordPress core's `site_icon` key (an image Media Library attachment ID, validated by the same image-attachment rule as `pp_logo_id`), so the browser-tab favicon and app/OS icon are settable through the same typed, validated action as the site logo. Core's `wp_site_icon()` then emits the favicon / apple-touch-icon tags automatically; a non-image, nonexistent, or `0`/empty value is rejected with the standard error envelope (#414).

### Docs
- The `update_site_option` action description/semantics, `AI_CONTEXT.md`, `ai-instructions/set-logo.md` (new favicon section + options-table row), and `ai-instructions/website-building.md` now document `site_icon`: an image attachment ID, rendered as-is on a direct write (no auto-crop, so supply a square source), emitted by core in `wp_head` (#414).

### Tests
- `tests/LogoTest.php` pins the new key through the shared validation and write path: whitelist membership as `attachment_id`, `pp_validate_site_option_value` accepting a real image and rejecting a non-image / nonexistent / `0` attachment, `pp_update_site_option` writing and normalizing the stored ID, and the `update_site_option` action accepting a valid image and rejecting a non-image with the standard envelope (#414).

## [v1.3.4] — 2026-07-19 — changing a base color token now warns when a stale derived override would mask it, at APPLY and at INSPECT (#386)

**Setting `--color-accent` could report `ok:true` and change nothing you can see. `update_design_token` auto-derives the accent/text family (`--color-accent-hover/-strong`, `--color-border-accent`, `--color-surface-accent`, `--color-text-secondary`) but deliberately PRESERVES any derived override you already set, so a pinned value survives your intent. The failure mode from the neocompute dogfood: an earlier palette left an orange `--color-accent-strong` override in place, you set `--color-accent` to blue, the apply succeeded, and the CTA stayed orange because the preserved override still won the `.btn` gradient. The base change had no visible effect and nothing said so. Now the apply result carries a `stale_warnings` entry for any preserved derived override that DIVERGES from the value the new base would derive, naming the masking token so you know your change may not show where it applies. A coherent override (one that already equals the derivable value) stays silent. No token value is ever changed by this: the result is still `ok:true` and the warning is advisory. Deliberately pinned derived values still survive untouched.**

The same masking condition is now also surfaced at INSPECT, so you catch it before making a change, not only after. `wp pp operate inspect` returns a `token_smells` array flagging `masked_derived_override` for every derived-family override that diverges from its base token's current value, quiet on a coherently themed site. Both surfaces run through one shared divergence engine (`pp_masked_derived_overrides()`), replacing an older hue-drift heuristic that only warned when a derived override's hue swung more than 30 degrees from the base — the divergence check is the actual masking condition, so a same-hue-but-off value that still masks the base is now caught too. This makes the state visible; it never recomputes or clobbers your derived overrides. Recompute-on-base-change would be a separate, destructive decision and is not in scope.

### Fixed
- `update_design_token` on a base color token whose derived family has a preserved override that diverges from the new base now returns a `stale_warnings` entry naming the masking token(s), instead of reporting `ok:true` with no hint that the change may not be visible where the override applies — the neocompute "blue accent, orange CTA" masking class. The result stays `ok:true` and no token value is changed; a coherent (equal-to-derivable) override does not warn (#386).

### Changed
- The token-coherence warning is now a divergence check shared by the apply result and a new INSPECT smell (one engine, two surfaces), superseding the previous hue-drift-over-30-degrees heuristic. `wp pp operate inspect` output gains a `token_smells` array (`masked_derived_override`) that reports the same masking condition before a change is made (#386).

### Docs
- `AI_CONTEXT.md` (function reference, smell inventory, token-families section, chat-card note) and `ai-instructions/retheme.md` now state that a base-token change never touches an existing derived override, and describe the divergence `stale_warnings` / INSPECT `token_smells` surfaces that make a masked change visible (#386).

### Tests
- `tests/ApplyTest.php` pins both directions through `pp_execute_apply` (divergent preserved override → `stale_warnings` naming it and preserving its value; coherent override, including a case/shorthand-different hex, → no warning), the shared `_pp_colors_equivalent` normalizer, non-family and non-hex bases, a non-hex `rgba()` pin surfaced as masking, and corrupt non-string override/base values tolerated without a fatal; `tests/OperateTest.php` asserts `pp_inspect_site()` exposes `token_smells`, populated on divergence and empty when coherent (#386).

## [v1.3.3] — 2026-07-18 — a param-type rejection on `wp pp action execute` now returns the ok:false envelope on stdout, not a bare stderr Error (#385)

**A batch that drives `wp pp action execute` and parses stdout for the `{"ok": ...}` envelope could silently miss a rejection. When an action's params failed structural validation — the canonical case being `update_site_option` with a numeric `value` (`{"key":"pp_logo_id","value":164}`) for a string-typed param — the CLI printed a bare `Error: ...` to stderr and exited non-zero, but emitted no envelope on stdout. A consumer grepping stdout saw nothing and read the step as a no-op success; in the neocompute dogfood this left `pp_logo_id` at its old value while the operator believed the batch had applied it. Param-type mismatches, missing required params, and semantic rejections at `action execute`'s pre-mutation gate now surface as the same canonical `ok:false` envelope (with `error_code`) on stdout that every other validation failure and `apply execute` already return, and the command still exits 1. Nothing about which values are accepted changed: a numeric `value` is still rejected (loudly, now), and `"164"` still works.**

The gate that runs before the preflight check (so a malformed action never demands a preflight it can't satisfy) previously surfaced its `WP_Error` through `WP_CLI::error()` — WP-CLI's stderr-and-exit path — which dropped the machine-readable `error_code` and bypassed the JSON channel entirely. It now renders the envelope directly from the `WP_Error` already in hand via a shared `_pp_action_validation_error_envelope()` helper (also used by `pp_execute_action()`), so the two surfaces can never drift and the error path stays pure: it never re-runs the state-dependent validator, so a concurrent DB change can't flip a rejection into an ungated mutation. This is error transport only; the shared validators (`pp_validate_action`) are untouched.

### Fixed
- `wp pp action execute` emits the canonical `ok:false` envelope (with `error_code`) on stdout and exits 1 when an action's params fail structural or semantic validation, instead of printing a bare `Error:` to stderr with no envelope — so a batch consumer parsing stdout can no longer miss a param-type rejection (e.g. `update_site_option` with a numeric `value`) and read the failed step as a silent success (#385).

### Tests
- `tests/CliGateTest.php` drives the real `PP_Action_Command::execute()` through an envelope-capturing WP-CLI stub: the numeric-`value` `update_site_option` repro asserts an `ok:false` stdout envelope with `error_code: invalid_param_type`, message preserved, exit 1, and no bare-stderr path; a second validation class (missing required param → `missing_param`) pins that both failures share the identical envelope shape (#385).

## [v1.3.2] — 2026-07-18 — a flat primary CTA button is finally reachable, and every page renders through the same base rules regardless of its id (#412)

**Shipped component CSS carried page-specific styling, and it leaked into real sites two ways. A brand that wanted a flat primary CTA button (flat fill, ink text, no gradient, no bevel, no drop shadow) had no way to get one: `--cta-button-bg` and `--cta-button-color` silently did nothing on the default button, because a later premium rule painted a gradient background-image over them, and the only route to a flat fill was the wrong-named `secondary` variant. And a page authored with an ordinary id like `home-hero` or `home-cta` inherited hidden demo-page styling at id specificity that no operator could see or override. Both are gone. The premium button treatment now routes its gradient, bevel, shadow, and text color through the documented `--cta-button-*` slots with the premium look as the fallback, so setting `--cta-button-bg` to a flat color (and `--cta-button-shadow: none`) produces a flat primary button on the DEFAULT variant, on any id. Every page-specific ID selector (about 284 lines) and a dead `.pp-workflow-*` decoration family were removed from the shipped stylesheet, so demo/starter pages render through the exact same base rules and style slots as every real site. Unset output is byte-identical: a page that set no slots looks exactly as it did before.**

Two things that used to depend on a reserved demo id now work in the base rules for everyone. A full-width `cta` authored with a normal id centers its title and body at the default `--cta-content-width` instead of leaving the title pinned to the left of a full-width text block (previously only the reserved `home-cta`/`how-cta`/... ids centered, and the workaround was to widen a max-width until the text filled the box). And a hero or CTA authored with `home-hero`/`home-cta` renders identically to the same component with any other id. A new css-lint guard parses selectors (not a bare `#` grep, so hex color values never false-positive) and fails the build on any ID selector in `components.css`/`base.css`/`utilities.css`, and the slot contract now covers the background-image/shorthand cascade layer so a future shorthand can never silently re-kill the flat-button slot again.

### Added
- `--cta-button-shadow` style slot on the `cta` component: the primary button's gradient bevel and drop shadow, removable per instance. Set it to `none` for a flat button (flattens rest and hover); unset renders the premium bevel byte-identically.

### Fixed
- The premium primary-button treatment (gradient fill, bevel, drop shadow, ink text) is routed through the documented `--cta-button-bg`/`--cta-button-color`/`--cta-button-border`/`--cta-button-shadow` slots (and their `-hover-` twins) with the premium literals as fallbacks, so a flat primary button is reachable on the DEFAULT variant instead of only through the `secondary` variant workaround (#412).
- A full-width `cta` with any authored id centers its title and body at the default `--cta-content-width` in the base rules — the `.cta__text` wrapper is capped and centered instead of stretching to full width and left-pinning the title (#412).
- Every page-specific ID selector (`#home-*`, `#how-*`, `#agencies-*`, `#implementers-*`, ~284 lines) and the unreferenced `.pp-workflow-*` decoration family were evicted from the shipped stylesheet, so a page authored with a colliding id (e.g. `home-hero`, `home-cta`) renders identically to the same page with any other id, through the same base rules and style slots as every real site (#412).

### Docs
- `ai-instructions/style-component.md` documents the flat primary button recipe (`--cta-button-bg` + `--cta-button-color` + `--cta-button-shadow: none`) on the default variant; `AI_CONTEXT.md` and `README.md` reflect the new style-slot total (196, cta now 31) (#412).

### Tests
- A selector-parsing css-lint guard fails the build on any ID selector in the three theme stylesheets (with negative controls proving it catches id-first and trailing-id shapes while ignoring hex values and `[href="#..."]` fragments), plus a layer-defeat guard requiring every premium primary-button `background` to route through `--cta-button-bg`; the E2E suite gains a full-width-CTA centering render pin on a non-reserved id and repurposes the reserved-id hero eyebrow pins as parity guards; the demo-ID grid/eyebrow pins that tested the evicted decoration were removed (#412).

## [v1.3.1] — 2026-07-18 — the operating loop now works when every `wp` call runs in its own container (#409)

**Operating a site whose WP-CLI runs as one fresh container per command (the standard `wordpress:cli` image, `docker run --rm --volumes-from`) was impossible: `wp pp operate inspect` minted a run token, but the very next gated command could not find its state and every mutation failed at the gate. Run state was written to the system temp dir, and each container has its own private `/tmp`, so the token's state landed in one container and vanished before the next command ran. Run state now lives in a per-run row in the site's options table, which every CLI process on the install shares. The full inspect → preflight → execute loop completes across separate ephemeral containers, exactly as it always did on a single long-lived host. Nothing changes for hosts where every `wp` call already shared a filesystem; the gate stays fail-closed, run state still expires after two hours, and completed or expired runs are removed so rows never pile up.**

When a gated command still cannot record its state, the error now names the real cause instead of the old catch-all "state file may be missing or expired": a token that was never minted here (the container case) reads as "no run state found," a genuinely stale one as expired, a token from another install as foreign, an unreadable row as corrupt, and a live run whose write did not land tells you to retry. The two-hour TTL, the site-identity binding, and the step-ordering guarantees are unchanged.

### Fixed
- Run-token state moved from a `sys_get_temp_dir()` JSON file to a per-run, non-autoloaded `wp_options` row, so the operating loop completes across ephemeral/containerized WP-CLI invocations that do not share a `/tmp` (#409).
- The PREFLIGHT-record failure is reported with its precise cause (not-found / expired / foreign / corrupt / write-failed) instead of the single misleading "State file may be missing or expired" message, so a token that landed in another container is no longer misdiagnosed as an expired one (#409).
- Concurrent CLI writers to the same run serialize through the existing MySQL advisory lock (the same bounded-wait `GET_LOCK` engine behind token and composition writes) instead of file locking; expired and corrupt run rows are swept when a new run starts, and completed runs are removed at HANDOFF, so run rows stay bounded (#409).

### Docs
- `docs/operating-loop-safety.md`, `docs/reference-apply-cli.md`, `ai-instructions/operating-loop.md`, and `README.md` now describe run state as an options-table store shared across CLI processes and document the split not-found/expired failure messages (#409).

### Tests
- PHP pins for the options-backed store: a two-process repro that mints a run under one `TMPDIR` and completes the gated PREFLIGHT record under a different one (proving the store no longer depends on the process temp dir), state classification (ok / invalid / not-found / corrupt / expired / foreign), fail-closed mutation on every non-live state, idempotent no-op writes, `run_status` reaping expired/corrupt rows while keeping live and foreign ones, garbage collection of abandoned rows, and CLI pins that the PREFLIGHT-record message differs per cause (#409).

## [v1.3.0] — 2026-07-18 — first post-1.0 feature release: grid columns, icon image treatment, dropdown menus (#141)

**This is a gate rollup marker: the three features of this release shipped in working versions 1.2.1–1.2.3 below, and this entry carries no new code beyond the five-file version bump and doc freshness. What 1.3.0 marks is the close of the feature gate (#141, Part 1.75) — the capability gaps that most limited fidelity in the neocompute.com benchmark dogfood, implemented as generic capabilities per the detects-not-specifies rule: an explicit grid `columns` control (1–4, opt-in, auto grain byte-identical when unset, #379); a grid `image_treatment` option rendering item images at icon scale via the `--grid-item-icon-size` slot instead of the 16:9 banner, with the icon following `--grid-item-text-align` (#380); and hierarchical menus — `set_menu` accepts `children`, rendered as WAI-ARIA disclosure dropdowns with full keyboard support and progressive enhancement (#381). The release bar was a rendered-evidence dogfood of all three capabilities on dev at both viewports (see #141), passed on RC v1.2.3.**

This release bumps the version across the five synced files from 1.2.3 to 1.3.0 and updates README.md's project-status strings. No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.3.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.3.0 =` rollup entry.

## [v1.2.3] — 2026-07-18 — navigation menus can have one-level dropdown submenus (#381)

**Menus were flat: `set_menu` built a single row of links with no way to nest, so a "Servicios" item with a dropdown of sub-links was inexpressible through the operator surface. Each `set_menu` item now accepts an optional `children` array of the same `{page_id}` or `{url, label}` shape, and the theme renders a nested group as an accessible dropdown: hover-or-keyboard on desktop, expand-in-place in the mobile menu. Nesting is one level deep — a child with its own `children` is rejected loudly. Leave `children` off and nothing changes: a flat menu renders byte-identical. This is the third and final item of the v1.3.0 feature gate (#141, Part 1.75); the gate-close/tag is a separate release.**

The dropdown follows the WAI-ARIA disclosure navigation pattern, not a menubar: the parent link stays independently clickable, and JavaScript injects a separate toggle button (`aria-expanded`, `aria-controls`) that owns open/collapse. Keyboard users open a group with `Enter`/`Space` or `ArrowDown` (which moves focus to the first child), and `Escape` closes it and returns focus to the toggle. Without JavaScript the submenu stays visible (expanded on mobile, revealed on hover on desktop), so the menu never becomes unusable. Menu snapshot/restore already round-tripped nesting, so authoring a dropdown and rolling it back preserves the structure exactly. Depth beyond one level, a malformed child, or more than 50 children in a group are all rejected at write time with the standard error envelope.

### Added
- `set_menu` items accept an optional `children` array (same `{page_id}` or `{url, label}` shape) that renders as a one-level dropdown submenu; the nav template, CSS, and `main.js` render it as an accessible disclosure dropdown on desktop and an expand-in-place group in the mobile menu (#381).

### Fixed
- `set_menu` validation rejects a child with its own `children` (`nesting_too_deep`), a non-array `children` value (`invalid_children`), a group over the child cap (`too_many_children`), and malformed children with child-scoped error paths, instead of silently building a broken menu; a child creation failure mid-apply rolls the menu back to its previous items exactly like a top-level failure (#381).

### Docs
- `AI_CONTEXT.md`, `ai-instructions/website-building.md`, `components/nav/README.md`, and the `set_menu` action description/semantics (which the runtime AI context surfaces) document the `children` grammar, the one-level limit, and the disclosure keyboard behavior (#381).

### Tests
- PHP pins for `set_menu` children (parent + children created with correct `menu_item_parent` and author order; depth>1, non-array children, missing link/label, and over-cap all rejected; child-failure mid-apply restores the previous items for an existing menu and deletes a half-built new menu; set_menu-authored nesting round-trips through batch rollback) and JS pins for the disclosure enhancement (labelled toggle injected with `aria-controls`, click toggles `aria-expanded`/`is-open`, `ArrowDown` opens and focuses the first child, `Escape` closes and restores focus, click-outside closes, flat menus left untouched) (#381).

## [v1.2.2] — 2026-07-18 — grid card images can render as small icons, not just 16:9 banners (#380)

**A grid card's `image_url` always rendered inside a full-width 16:9 cover wrap, so the extremely common icon + title + text feature card was inexpressible: a ~45px logo or glyph got blown up into a cropped banner. The grid now accepts an optional `image_treatment` prop (`banner` default / `icon`) that renders each card image at a small fixed icon size instead — un-cropped, above the title, sized by the new `--grid-item-icon-size` style slot (default 48px). Leave it unset and nothing changes: the banner rendering is byte-identical. This is the second item of the v1.3.0 feature gate (#141, Part 1.75); the gate-close/tag is a separate release.**

`image_treatment` is an opt-in structural prop, set through `create_page` / `update_component` like `layout`, `card_emphasis`, and `columns` (not `style_component`). Under `icon`, the image is `object-fit: contain` (the whole glyph shows) inside a square box the `--grid-item-icon-size` slot controls, at every breakpoint (the icon stays icon-sized on mobile; the sub-768px single-column collapse is unchanged). The icon FOLLOWS the card's `--grid-item-text-align` — a centered card centers its icon, its text, and its Read-more link together — reusing the same derived companion the link already follows (#361), so there is no second alignment slot. Invalid values (`card`, `Icon`, `thumbnail`, numbers) are rejected at write time with the standard `invalid_prop_value` envelope, never silently coerced. The treatment is a `cards` concept and is inert on the `steps` layout, which renders no item images. The default `banner` treatment is unchanged.

### Added
- `grid` accepts an optional `image_treatment` prop (`banner` / `icon`) that renders card images at a small fixed icon size instead of the 16:9 cover banner, with the unset default byte-identical (#380).
- New `--grid-item-icon-size` grid style slot (length, default 48px, item-eligible) sizing the icon box under `image_treatment: icon`; the icon also follows the card's `--grid-item-text-align` via the shared #361 companion (#380).

### Fixed
- Composition writes now validate schema-declared strict enums: an enum prop that opts in with `strict: true` (today only `grid.image_treatment`) is rejected with `invalid_prop_value` when given a value outside its closed set, instead of being silently coerced at render. Existing enums without the flag (`layout`/`theme`/`card_emphasis`/`heading_align`) keep their historical accept-and-coerce behavior, so no other prop's validation changed (#380).

### Docs
- `components/grid/README.md`, `ai-instructions/composition.md` + `style-component.md`, `AI_CONTEXT.md`, the README component table, and `lib/ai-context.php` document `image_treatment` and the icon-size slot; the runtime AI context surfaces the prop automatically from the schema. `AI_RULES.md`'s anti-slop rule was clarified so a real-logo icon grid is not mistaken for decorative "icon-in-circle" slop.

### Tests
- PHP validation pins for `grid.image_treatment` (accepts `banner`/`icon` and the unset sentinels; rejects unknown/case-mismatch/numeric/whitespace with `invalid_prop_value`), a non-ripple pin (an invalid `layout`/`theme` still validates), renderer pins (emits `grid--image-icon` only for `icon`, byte-identical when unset/banner, coerces raw-invalid state, inert on `steps`, composes with theme/emphasis/bullets, follows `--grid-item-text-align`), and CSS-lint pins (icon sizing via `var(--grid-item-icon-size, 48px)`, `object-fit: contain`, default 16:9 intact, rules not min-width-gated so mobile keeps the icon size, and `align-self` via the shared companion).

## [v1.2.1] — 2026-07-18 — grid gains an explicit desktop column-count control (#379)

**A `cards` grid used to derive its desktop column count from the number of items — good defaults, but with no way to override them, so a 6-item grid was locked to 2x3 when you wanted 3-across x 2-rows, and a 4-item grid could not choose 4-across over 2x2. The grid now accepts an optional `columns` prop (integer 1-4) that forces the desktop (768px+) column count. Leave it unset and nothing changes: the auto-by-count default renders byte-identical. This is the first item of the v1.3.0 feature gate (#141, Part 1.75); the gate-close/tag is a separate release.**

`columns` is an opt-in structural prop, set through `create_page` / `update_component` like `layout` and `card_emphasis` (not `style_component`). A set value forces that many equal-width tracks at desktop, spanning the container regardless of item count, and keeps the single-column collapse below 768px intact; a forced count with a non-multiple item count simply wraps the remainder onto the last row without overflow. Out-of-range or non-integer values (0, 5, 2.5, text) are rejected at write time with the standard `invalid_prop_value` envelope, never silently clamped. The control is a `cards` concept and is ignored on the `steps` layout, which keeps its fixed process grain. The auto-derivation defaults shipped by earlier work are unchanged.

### Added
- `grid` accepts an optional `columns` prop (integer 1-4) that forces the desktop column count, overriding the item-count auto-derivation while leaving the unset default byte-identical (#379).

### Fixed
- Composition writes now validate schema-declared integer bounds on props: a bounded numeric prop supplied out of range or as a non-integer is rejected with `invalid_prop_value` instead of being coerced at render time (the shared validator gained a generic min/max check; today only `grid.columns` declares bounds) (#379).

### Docs
- `components/grid/README.md`, `ai-instructions/composition.md`, `AI_CONTEXT.md`, `AI_RULES.md`, and the README component table document the new `columns` prop; the runtime AI context surfaces it automatically from the schema.

### Tests
- PHP validation pins for `grid.columns` (accepts 1-4 and the unset sentinels; rejects 0, 5, negative, 2.5, and text with `invalid_prop_value`), renderer pins (emits `data-pp-columns` only for a valid 1-4 value, omits it when unset or on `steps`, coerces raw out-of-range state to no attribute), and CSS-lint pins that the four override rules force the right track count, span the container, stay scoped to cards, and sit after the auto count rules in source order.

## [v1.2.0] — 2026-07-18 — chat writes gain compare-and-swap conflict protection: the v1.2.0 gate is closed (#141)

**This is a gate rollup marker: the single feature of this release — composition CAS baselines threaded through the AI chat's single and batch executors (#404, working version 1.1.1) — shipped below, and this entry carries no new code beyond the five-file version bump and doc freshness. What 1.2.0 marks is the close of the chat CAS gate (#141, Part 1.6), the two-phase gate opened by the 2026-07-16 complexity audit: #392 designed the baseline lifecycle (context-read derivation, fail-closed mandates on both chat entry points, per-page batch baseline map with server-side chaining, envelope version refresh, Re-read & re-preview conflict UX), and #404 implemented it. The release bar was rendered CONFLICT evidence, not a feature dogfood, and it was met on dev under v1.1.1: an editor-vs-chat interleaved write and a chat-vs-chat interleaved write were both rejected in the real chat UI with the conflict card and a usable Re-read & re-preview retry (the retried apply preserved both writers' changes — no lost update), and a three-step batch mutating the same page did not false-conflict against its own writes (version advanced exactly +3). The docs claim corrected in #389 — that every agent-driven write opts into the CAS — is now true for the chat surface.**

This release bumps the version across the five synced files from 1.1.1 to 1.2.0 and updates README.md's project-status strings. Remaining tracked gap outside this gate: apply/token mutation reversibility outside CLI runs (#393, needs-design). No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.2.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.2.0 =` rollup entry.

## [v1.1.1] — 2026-07-18 — chat composition writes gain fail-closed CAS conflict protection (#404)

**Chat was the one composition writer that never threaded a version baseline, so a chat-driven write could silently clobber a concurrent editor, CLI, or chat change. It now opts into the write-time compare-and-swap (#13) — mandatorily and fail-closed — on both chat entry points, implementing the accepted v1.2.0-gate design (#392). This is the implementation phase of the v1.2.0 chat-CAS gate (#141, Part 1.6); the gate-close/tag is a separate release.**

When the model reads a page, the chat backend captures that page's `composition_version` as the conversation's per-page baseline and threads it back on write. `wp_ajax_pp_ai_execute` rejects a composition-mutating action with no baseline (`missing_expected_version`) before executing; `wp_ajax_pp_ai_execute_batch` rejects the whole batch before any step runs if any composition-mutating step's target page lacks a baseline (nothing executes, so there is nothing to roll back). A batch supplies a per-`post_id` baseline map and the executor chains each write's server-derived post-write version into the next mutating step on that page, so a multi-step proposal never false-conflicts against its own earlier writes; a page created mid-batch joins the map at the writer's version-0 semantics with no browser baseline. On `composition_conflict` the handlers return the structured envelope (`error_code` plus `expected_version`/`current_version`), and the chat UI shows one affordance — **Re-read & re-preview** — which re-reads the page for a fresh baseline and re-previews the proposal before the user confirms again, never a blind retry. The writer (`pp_update_composition`) and the action registry are untouched; the mandate lives in the chat entry points' contracts. Applies/token writes stay out of scope (#393), and the editor's opt-in posture is unchanged.

### Fixed
- Chat single-execute (`wp_ajax_pp_ai_execute`) and batch (`wp_ajax_pp_ai_execute_batch`) now thread `expected_version` into composition-mutating writes and reject fail-closed when a baseline is missing, closing the last composition writer that skipped the write-time CAS (#404).
- A composition-mutating action's `ok:true` envelope and the batch success envelope now carry the post-write `composition_version`(s), so the chat UI refreshes its per-page baseline from every success instead of a second read.
- After a rolled-back batch, the chat UI re-reads the baseline for every touched page, so the version churn the rollback introduces can't spuriously conflict the next apply.

### Docs
- `docs/operating-loop-safety.md` (chat CAS gate row), `docs/reference-apply-cli.md`, `AI_CONTEXT.md`, `ai-instructions/website-building.md`, and the runtime AI context (`lib/ai-context.php`, which now surfaces the concurrency baseline in the page context the model reads) updated to describe chat writes as CAS-protected.

### Tests
- Integrated-path PHP pins through the real handler functions and the writer CAS: editor-vs-chat and chat-vs-editor (both directions), chat-vs-chat, batch no-false-conflict, stale-initial-baseline rollback, multi-page per-page chaining, mid-batch create_page (version-0), fail-closed mandates (single + batch), success-envelope version refresh, legacy version-0 page, and a guarantee regression that a review-gap external write survives a CAS-rejected batch rollback.
- New vitest pins for the JS baseline map, version-map refresh, and conflict detection helpers.

---

## [v1.1.0] — 2026-07-17 — executor-level safety hardening: the v1.1.0 gate is closed (#141)

**This is a gate rollup marker, not a feature release: every change shipped in the v1.0.1–v1.0.7 working versions below, and this entry carries no new code beyond the five-file version bump and a documentation-freshness pass. What 1.1.0 marks is the close of the executor-level safety-hardening gate (#141, Part 1.5) opened by the 2026-07-16 fragile-complexity audit — seven issues, milestone v1.1.0 at zero open. The through-line of the gate: data-safety invariants now live at shared choke points instead of only in WP-CLI wrappers. The operating-loop gates are classified loop-discipline vs data-safety with the stale chat-CAS docs claim corrected (#389); the CLI gate stack's fail-closed branches are unit-pinned via extracted pure predicates (#390); `operate patch` routes through the real registered action instead of a hand-rolled synthetic gate (#391); the `composition_required` precondition (#358) moved into the shared validator so every `pp_execute_action()` caller — chat AJAX, batch, admin, CLI — inherits it (#387); the retired `variant` prop is rejected at write time while restore/read paths keep migrating (#388); `operate patch` reports `not_found` for nonexistent pages in parity with `action execute` (#399); and the front-end renderer migrates legacy `variant` at read time, closing the last read path that bypassed the shared migration engine (#400).**

This release bumps the version across the five synced files (style.css, package.json, functions.php `PP_VERSION`, the README badge, and readme.txt's Stable tag) from 1.0.7 to 1.1.0, adds the readme.txt rollup line, and corrects the two stale theme-version strings in README.md's project-status section that still read v1.0.2. Per the gate's release condition, this is a trust/guardrail release verified by the full test suites (1914 PHP / 575 JS green on every landing, plus main CI ai-ready and E2E under WordPress 7.0) — no feature dogfood, because no product feature was added. The caller-specific gaps the gate documented but deliberately did not fix remain tracked: chat-write CAS threading (#392, the v1.2.0 gate) and out-of-CLI apply/token reversibility (#393). No behavior changed in this entry itself.

### Docs

- README.md's project-status section now reads v1.1.0 in the release-history range and the "What exists today" heading. readme.txt gains its `= 1.1.0 =` rollup entry.

## [v1.0.7] — 2026-07-17 — the front-end renderer migrates legacy `variant` at read time, closing the last read path that bypassed it (#400)

**The public front-end renderer `pp_composition()` json-decoded a page's stored `_pp_composition` raw and returned it, without running the legacy `variant` -> `layout`/`theme` migration that every other read path applies (the editor decode, `restore_composition`, and inspect/check via `pp_get_composition_result()`). A page that still carried a pre-#69 `variant` prop — never re-saved, never opened in the editor, never restored — rendered on the public front end with `variant` silently ignored, so its structural/tone setting was lost only on the one reader that skipped the shared migration. `pp_composition()` now routes its decoded items through the same shared `pp_migrate_stored_composition()` -> `pp_migrate_legacy_variant_keys()` engine, so a stored legacy composition renders with `variant` honored as `layout`/`theme`. Modern compositions carry no legacy key, so the migration is a no-op and they render byte-identically.**

This is the seventh and final item of the executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. No second migration was written: the renderer reuses the single shared engine every other read path already calls (`pp_migrate_legacy_variant_keys()` in `lib/admin.php`, reached through the `pp_migrate_stored_composition()` wrapper in `lib/wp.php`), so the render boundary is no longer the odd reader that bypasses it. The change is read-time only and stays consistent with #388: write paths still reject `props.variant` with `unknown_prop` while restore/read paths migrate it. The renderer keeps its own defensive decode (it does not add list-shape enforcement, which is `pp_get_composition_result()`'s job per #144); the deferred history-ring bulk migration and #69 shim deletion (the #388 recorded decision) are untouched. Verified anchors: `pp_composition()` and `pp_migrate_stored_composition()` in `lib/wp.php`, `pp_migrate_legacy_variant_keys()` in `lib/admin.php`.

### Fixed

- `pp_composition()` (`lib/wp.php`), the in-loop front-end renderer used by `templates/composition.php` and `templates/front-page.php`, now runs its decoded composition through the shared `pp_migrate_stored_composition()` read-path shim. A stored composition still carrying legacy `props.variant` renders with the value migrated to `layout` (structural components) or `theme` (tone components), matching the editor/restore/inspect read paths; a modern `layout`/`theme` composition is unchanged because the migration is a no-op (#400). The stale code comment claiming the front-end renderer bypasses the shim is corrected.

### Tests

- `tests/WpAbstractionTest.php` pins the renderer read path: legacy structural `variant` → `layout`, legacy tone `variant` → `theme`, a modern-shape composition returned with strict identity (byte-identical no-op), a mixed `variant`+`layout` collision (explicit modern key wins, legacy key dropped), a malformed scalar-`props` item passed through without crashing, a non-list object shape returned unchanged, and absent/invalid-JSON meta rendering as an empty composition. Full suite: 1914 PHP tests green (up from 1906), 575 JS tests green.

## [v1.0.6] — 2026-07-17 — `operate patch` reports `not_found` for a nonexistent page, matching `action execute` (#399)

**`wp pp operate patch` never checked that its target page existed before running the composition-presence precondition. A numeric-but-nonexistent post id fell through to that precondition and surfaced `composition_required` ("post N has none yet") — a misleading message for a page that does not exist, while `wp pp action execute` correctly reported `not_found` for the same id. Both paths already failed closed, so this was error-message parity, not a safety hole: post-#391 the two CLI entry points are meant to share one gate story, and this was the last observable divergence. `pp_patch_composition()` now runs the shared `_pp_validate_page_exists()` predicate before any composition access, so a nonexistent page reports `not_found` and a non-page post reports `not_a_page` — the same error classes `action execute` produces — on both the mutating and `--preview` paths. An existing page with no composition still gets the clear `composition_required`, and valid preflighted patches are unaffected.**

This is the sixth item of the executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. No surface-specific validator was added: the fix reuses the same shared page-existence engine `pp_validate_action()` already guards its precondition with, so the patch path and `action execute` now share one enforcement rule end to end. The check sits at the top of `pp_patch_composition()`, ahead of selector parsing and the step-2a precondition, so the misleading error can no longer leak before the page is known to exist. `pp_patch_composition()` already routed its write through `pp_execute_action('update_component')` — whose validator rejects non-page targets with `not_a_page` — so this only moves that identical rejection earlier and adds no new page-only constraint. Verified anchors: `pp_patch_composition()` in `lib/operate.php`, `_pp_validate_page_exists()` and `pp_validate_action()` in `lib/actions.php`.

### Fixed

- `pp_patch_composition()` (`lib/operate.php`) now validates page existence with the shared `_pp_validate_page_exists()` before the composition-presence precondition. A nonexistent post id reports `not_found` and a non-page post reports `not_a_page` — parity with `wp pp action execute` — instead of the misleading `composition_required`. Existing composition-less pages still report `composition_required`; the `--preview` path reports the same errors as the mutating path (#399).

### Tests

- `tests/CliGateTest.php` extends the operate-patch ordering pins to cover all three cases: nonexistent id → `not_found` (mutating and `--preview`), non-page post → `not_a_page` (mutating and `--preview`), and existing + composition-less → `composition_required` (the pre-existing pin, now with the target page registered as a real page so it exercises the intended path). Full suite: 1906 PHP tests green (up from 1904), 575 JS tests green.

## [v1.0.5] — 2026-07-17 — split the retired `variant` shim: write paths reject it, restore/read paths keep migrating (#388)

**v1.0.0 shipped with the #69 `variant` shim still accepting the retired prop at write time. `pp_normalize_composition()` ran the `variant` -> `layout`/`theme` migration on every path, so a new write carrying `props.variant` through `create_page`/`update_composition` was silently rewritten instead of rejected — contradicting the recorded #69 decision ("clean rename before v1, NO permanent alias"). But the shim could not simply be deleted: `restore_composition` (#233) runs long-lived history-ring snapshots through the same normalizer, and a live install can still hold pre-#69 snapshots keyed on `variant`. This change splits the shim by path. The write-path normalizer no longer migrates, so `variant` now falls through to the shared `unknown_prop` gate and the write is rejected; the restore and read paths call `pp_migrate_legacy_variant_keys()` explicitly, so stored legacy shapes still decode. v1's public API accepts `layout`/`theme` only, and no stored composition breaks.**

This is the fifth and final item of the v1.0.1 executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. No new validator was added — the fix removes migration from the write choke point and lets the existing `unknown_prop` rule in `pp_validate_composition()` do the rejecting, keeping all validation in the shared engines (`add_component`/`update_component` already validated without migrating, so they rejected `variant` already; they are now pinned). The migration helper is unchanged and stays permanent until an explicit history-ring migration ships; the "REMOVE AT v1.0.0 TAG" plan recorded only in code comments — the reason this slipped past the tag — is retired, and every comment saying so (in `lib/admin.php`, `lib/wp.php`, and two test files) is corrected. Restore keeps its #233 contract: it decodes a legacy snapshot, reports findings on the migrated shape, and never rejects. Verified anchors: `pp_normalize_composition()` and `pp_migrate_legacy_variant_keys()` in `lib/admin.php`, the restore preview/execute paths in `lib/actions.php`, and the read-path shim in `lib/wp.php`.

### Fixed

- `pp_normalize_composition()` (`lib/admin.php`) no longer migrates the retired `variant` prop. On the write paths (`create_page`, `update_composition`) a composition carrying `props.variant` is now rejected with `unknown_prop` pointing at `layout`/`theme`, instead of being silently rewritten (#388). `add_component`/`update_component` validate without normalizing and already rejected `variant`; the contract is now identical across all four write paths.
- `restore_composition` (`lib/actions.php`) migrates legacy `variant` snapshots by calling `pp_migrate_legacy_variant_keys()` explicitly on both the preview and execute paths, so pre-#69 history-ring entries still restore and normalize to `layout`/`theme` (#233 decision unchanged). The read-path shim (`lib/wp.php`, editor decode) is untouched, so stored legacy compositions still decode on read.

### Docs

- `AI_CONTEXT.md` now states the write-time contract explicitly: `props.variant` is rejected as `unknown_prop` on every write path (`layout`/`theme` only), while a stored pre-rename snapshot still decodes on restore/read. The `lib/admin.php`, `lib/wp.php`, and two test-file comments that still described the shim as "remove at the v1.0.0 tag" are corrected to "restore/read-path compatibility, permanent until a history-ring migration ships."

### Tests

- `tests/ActionsTest.php` adds write-reject pins for all four write paths (`create_page`, `update_composition`, `add_component`, `update_component` with `props.variant` → `ok:false`, `unknown_prop`), a pin that `pp_normalize_composition()` leaves `variant` untouched, and restore preview + execute pins that a decoded legacy `variant` is not falsely flagged `unknown_prop`. The existing migration unit tests are retargeted to `pp_migrate_legacy_variant_keys()` (the function now actually under test), and `testRestoreNormalizesLegacyVariantSnapshot` keeps passing. Full suite: 1904 PHP tests green (up from 1898), 575 JS tests green.

## [v1.0.4] — 2026-07-17 — enforce the composition_required precondition in the shared executor, not just the WP-CLI gate (#387)

**The "component actions require an existing composition" precondition (#358) was enforced only in the WP-CLI gate, so every other caller of the shared executor bypassed it. Through the in-admin AI chat — which calls `pp_execute_action()` directly — `add_component` on a page created empty by `create_page` returned `ok: true` and wrote the first component, exactly the state #358 forbids. This change moves the single precondition predicate (`pp_action_composition_precondition()`) into the shared validator `pp_validate_action()`, so every executor caller inherits it: chat AJAX, WP-CLI, the batch executor, and `pp_patch_composition()`. The duplicate WP-CLI-layer call is removed, leaving exactly one enforcement point. Component-level actions on a composition-less page now fail closed with `composition_required` no matter which surface calls them; populate/lifecycle/metadata actions (`update_composition`, `trash_page`, restore) and site-scoped actions are unaffected, and the operator-facing WP-CLI behavior is unchanged.**

This is the fourth item of the v1.0.1 executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. It corrects the same anti-pattern #124 already fixed for media-URL validation: a data-safety invariant belongs at the shared validator every writer passes through, never in one entry point's wrapper. The precondition runs after structural and media/logo validation but before each action's own semantic checks, and only for a page that actually exists — a nonexistent or non-page target still reports its own `not_found`/`not_a_page` rather than a misleading "populate it first," and site-scoped actions are keyed out by scope so a stray `post_id` can never trip the guard. `pp_patch_composition()` resolves its target component before routing through `update_component`, so it invokes the same predicate early to fail closed with the clear error instead of a late `component_not_found`. The WP-CLI `action execute` path already validated before its gate, so its `composition_required` message is byte-identical; `docs/operating-loop-safety.md`'s gate-classification table now records the precondition as living at the shared choke point rather than as a caller-specific gap. Verified anchors: `pp_validate_action()` in `lib/actions.php`, `pp_action_composition_precondition()` and `pp_patch_composition()` in `lib/operate.php`, and the reduced gate in `lib/cli.php`.

### Fixed

- `pp_validate_action()` (`lib/actions.php`) now enforces the #358 composition precondition, so the in-admin AI chat and the batch executor — both of which reach `pp_execute_action()` directly — can no longer add the first component to a composition-less page. The precondition is gated on the target page existing and on non-site scope, so nonexistent-page (`not_found`) and site-scoped actions are unaffected. The now-duplicate call in `_pp_cli_require_preflight_for_action()` (`lib/cli.php`) is removed; the WP-CLI gate keeps coverage + scope-consistency only, and the operator sees the identical `composition_required` message via the validator.
- `pp_patch_composition()` (`lib/operate.php`) invokes the same shared predicate before it resolves the target component, so patching a composition-less page (including `--preview`) fails closed with `composition_required` instead of a confusing `component_not_found`.

### Docs

- `docs/operating-loop-safety.md`'s two-classes-of-gate table now records the `composition_required` precondition (#358) as enforced at the shared choke point (`pp_validate_action`) inherited by every executor caller, closing the caller-specific gap it previously flagged for #387.

### Tests

- `tests/OperateTest.php` (+5 tests) pins the executor-level enforcement without going through the CLI gate: `add_component` on a composition-less page fails with `composition_required` via `pp_execute_action()` directly and writes nothing; the same call succeeds once the page has content; a nonexistent page still reports `not_found` (not `composition_required`); a site-scoped action with a stray `post_id` still validates; and a `--preview` patch of an empty page fails closed. `tests/CliGateTest.php` and `tests/ActionsTest.php` are updated so the relocation is pinned at the new choke point rather than the old CLI location. Full suite: 1898 PHP tests green (up from 1893), 575 JS tests green.

**`wp pp operate patch` hand-rolled a second copy of the mutation gate instead of routing through the same per-action gate every typed action uses. It built a synthetic `['mutates_composition' => true]` action array and called the preflight-coverage check directly, so by construction it skipped the scope-consistency assertion and the composition precondition (#358) that `wp pp action execute` enforces. The invariant held only by accident — patching an empty composition failed later, deeper inside the write. This change routes patch execution through the shared `_pp_cli_require_preflight_for_action()` against the real registered `update_component` action, so both CLI mutation paths now pass through one gate. Valid, preflighted component edits behave exactly as before; the only visible difference is that patching a composition-less page now fails closed early with a clear `composition_required` error instead of a late, confusing failure.**

This is the third item of the v1.0.1 executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. It builds directly on #390, which extracted the gate-stack decisions into pure predicates and pinned their fail-closed branches so this refactor could be proven behavior-preserving. The freshness gate (#113) and baseline refresh are unchanged: the real `update_component` registration carries `mutates_composition => true` just as the synthetic array did, so the write-time compare-and-swap still threads through. The composition precondition the patch path now honors was already the documented per-action contract for `update_component` in `ai-instructions/operating-loop.md` (#358); this change makes the patch path conform to it rather than being an accidental exception. Verified anchors: the gate stack and `patch()` in `lib/cli.php`, the `update_component` registration in `lib/actions.php`, and `pp_action_composition_precondition()` in `lib/operate.php`.

### Fixed

- `wp pp operate patch` now routes its mutation gate through the shared `_pp_cli_require_preflight_for_action()` against the real `update_component` action registration instead of a synthetic partial action array (`lib/cli.php`). Patching a composition-less page fails closed early with `composition_required` rather than failing late inside the write. Valid, preflighted single-field patches are unchanged. The action resolution and its fail-closed null guard live inside the mutating branch, so the read-only `--preview` path stays ungated.

### Tests

- `tests/CliGateTest.php` (+4 tests, 34 total) pins the refactored patch path: the `update_component` registration still carries the scope/`mutates_composition`/`requires_composition` metadata the shared gate depends on; the shared gate rejects a composition-less patch target with `composition_required`; a preflighted edit on a populated, unchanged page passes the full non-preview gate stack (coverage + precondition + freshness) and returns the CAS baseline; and a source-level tripwire fails if `patch()` ever reverts to the hand-rolled synthetic gate. Full suite: 1893 PHP tests green (up from 1889), 575 JS tests green.

## [v1.0.2] — 2026-07-17 — unit coverage for the WP-CLI gate stack fail-closed branches (#390)

**The WP-CLI gate stack is load-bearing safety logic, but the composed fail-closed decisions in its wrappers (`_pp_cli_require_run_id`, `_pp_cli_require_preflight_for_action`, `_pp_cli_require_preflight_covers`, `_pp_cli_require_composition_fresh`) had no direct unit coverage, because each wrapper calls `WP_CLI::error()` — a process exit — inline with its branch logic. That left the safety decisions both unshared and untested. This change extracts the decision of each gate into a pure predicate (a message-or-null return, or a discriminated result for the freshness gate) and adds `tests/CliGateTest.php`, which pins every fail-closed branch at both the predicate and the wrapper level. The wrappers stay thin emit shims; every user-facing message, return value, and branch ordering is byte-identical to before, so no CLI behavior changed.**

This is the second item of the v1.0.1 executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. Pinning the composition first means later gate-stack refactors (the operate-patch unification in #391) can move this logic without silently changing a fail-closed decision. Coverage spans the eight branches the issue names: missing and invalid run ID, missing preflight coverage, unrecognized action scope, a page/section action without `post_id`, a site action carrying `post_id`, a missing composition-freshness baseline, and a stale composition marker — plus the accept and no-op paths. The composition-precondition branch (#358) is deliberately excluded: #387 relocates it into the shared validator, so pinning it here as CLI behavior would churn when that lands. The new test defines a `WP_CLI` stub whose `error()` throws a catchable exception in place of `exit(1)`, faithfully modeling the fail-closed semantics without a live WP-CLI runtime.

### Tests

- `tests/CliGateTest.php` (new, 30 tests) covers the WP-CLI gate composition without relying on interactive `WP_CLI::error()` exits: each fail-closed branch is asserted on its exact user-facing message, and each accept/no-op path on its exact return value, at both the extracted pure predicate and the thin wrapper. Full suite: 1889 PHP tests green (up from 1859), 575 JS tests green.

### Fixed

- The four WP-CLI gate wrappers in `lib/cli.php` now delegate their fail-closed decision to a pure, testable predicate (`_pp_cli_run_id_error`, `_pp_cli_preflight_target_error`, `_pp_cli_preflight_coverage_error`, `_pp_cli_composition_fresh_decision`), so the composed safety logic is unit-testable instead of trapped behind `WP_CLI::error()`. Behavior, messages, and ordering are unchanged; the `composition_required` precondition (#358) is untouched.

## [v1.0.1] — 2026-07-17 — classify operating-loop gates and correct the stale chat-CAS claim (#389)

**The operating-loop safety docs claimed every agent-driven or editor-driven composition write opts into the write-time compare-and-swap (CAS). The code is narrower than that: only the WP-CLI operate loop and the dashboard editor's save/publish AJAX thread `expected_version` into the write. The chat AI path (`wp_ajax_pp_ai_chat` → `pp_execute_action()`) does not, so a chat-driven composition write is not CAS-protected. This documentation-only patch corrects that over-claim in both `docs/operating-loop-safety.md` and `docs/reference-apply-cli.md`, and adds an explicit classification of the current gates into loop-discipline (run-token ordering, INSPECT/PREFLIGHT/HANDOFF choreography — legitimately CLI-specific) versus data-safety invariants (preconditions, freshness, CAS, rollback — which belong at shared choke points or must be named as caller-specific gaps). No runtime behavior changed.**

This is the first item of the v1.0.1 executor-level safety-hardening gate (#141, Part 1.5) from the 2026-07-16 fragile-complexity audit. Mapping the gates first means later fixes in the gate do not blindly relocate loop-choreography into shared executors. The docs now cross-reference the remaining data-safety gaps that stay outside v1.0.1 — chat-path CAS (#392, v1.0.2) and reversible apply/token mutation outside CLI runs (#393) — and note that the `composition_required` chat bypass (#387) is closed within v1.0.1 itself. The verified code anchors: CAS opt-in threading at `lib/cli.php` and `lib/admin.php`, the choke point `pp_update_composition` (`lib/wp.php`), the un-threaded chat handler at `lib/ai-chat.php`, and the CLI-only `composition_required` precondition (`lib/operate.php`).

### Docs

- `docs/operating-loop-safety.md` no longer claims chat-driven composition writes have CAS protection; it now names exactly which writers opt in (CLI + editor) and which do not (chat), adds a "Two classes of gate" section with a per-gate classification table, and states that v1.0.1 is executor-level hardening, not a redesign of the operate loop.
- `docs/reference-apply-cli.md` corrects the same over-claim in the `expected_version` reference: the CLI agent path and dashboard editor supply it; the AI chat action path does not yet (tracked in #392).

## [v1.0.0] — 2026-07-15 — first stable release: the v1.0.0 acceptance gate is closed (#141)

**PromptingPress reaches 1.0.0. This is a milestone marker, not a feature release: every capability shipped in the 0.16.x train below, and this entry carries no new code beyond the five-file version bump and a documentation-freshness pass. What 1.0.0 marks is the close of the v1.0.0 acceptance gate (#141) — the milestone reached zero open issues, and three independent benchmark dogfoods verified the product materially credible with all trust-class defects resolved. The 0.16.x train is what earned the tag: the composition/file authority model, the shared validation engines (no surface-specific second validator), write-time compare-and-swap on composition writes (#13), composition history and restore (#133/#233), the typed action/apply layer with preview and rollback, the per-instance style-slot contract enforced by static and rendered-cascade guards, and the runtime AI action catalog. 1.0.0 does not add to that surface; it certifies it.**

This release bumps the version across the five synced files (style.css, package.json, functions.php `PP_VERSION`, the README badge, and readme.txt's Stable tag) from 0.16.136 to 1.0.0, and corrects the two stale theme-version strings in README.md's project-status section (the release-history range and the "What exists today" heading) that still read v0.16.99. A full audit of the documentation surfaces (README.md, readme.txt, AI_CONTEXT.md, AI_RULES.md, docs/, ai-instructions/) found no other stale theme-version string or stale feature claim: the style-slot count (194) and its per-component breakdown are test-guarded against the schemas (`SchemaValidationTest::testDocsStyleSlotCountMatchesSchema`) and remain current, and the per-post composition-freshness version references (the #13 compare-and-swap counter) are a distinct value from the theme version and were correctly left untouched. No behavior changed.

### Docs

- README.md's project-status section now reads v1.0.0 instead of v0.16.99 in the release-history range and the "What exists today" heading. All other documented counts, claims, and grammars were audited and confirmed current — no other changes were needed.

## [v0.16.136] — 2026-07-15 — add_component now accepts a per-instance style, so you can add a styled component in one call instead of two (#368)

**The `add_component` action took `component`, `props`, and `position` but had no `style` param. Because composition items already carry a per-instance `items[].style` map, an operator would naturally pass `style` to `add_component` too — and the action returned `ok: true` while silently dropping the styling. That is the #147 trust class: a mutating action reports success while ignoring an input that had visible intent. Per-instance styling only landed through a separate `style_component` call or a full composition write. This adds an optional `style` param to `add_component` that is written onto the new composition item and validated by the exact same shared engine as `items[].style`, so a styled component can be added in one call — and an invalid style is now rejected instead of silently accepted.**

The `style` map routes through the composition validator that `add_component` already calls: the new item is built with its `style` key and passed to `pp_validate_composition()`, whose per-item pass validates the style against the component's schema-declared `style_slots` via the same `_pp_validate_style_slot_map` engine that validates `items[].style` (issues #306/#323) and `style_component` — no surface-specific second validator. An unknown slot is rejected with `invalid_style_slot` and an out-of-type value with `invalid_style_value`, identical to every other style surface. The capability is opt-in: an `add_component` call with no `style` (or an empty `style` map, matching `update_component`'s treatment) leaves the stored item byte-identical to before. `style` combines with `position`, so a styled component can be inserted at any index. The chat AI learns the new param automatically — the runtime action catalog in `lib/ai-context.php` generates the signature and description from the registry — and the static `AI_CONTEXT.md` and `ai-instructions/composition.md` name it in the same change.

### Fixed

- `add_component` now honors an optional per-instance `style` map (item-scoped style slots, the same map and shared validator as composition `items[].style` and `style_component`), writing it onto the new component in one call. Previously a `style` key passed to `add_component` was silently dropped behind `ok: true`; now a valid style is applied and an invalid one is rejected with the same `invalid_style_slot` / `invalid_style_value` errors as every other style surface. An `add_component` call without `style` is unchanged.

### Docs

- The `add_component` action description (surfaced to the chat AI at runtime via `lib/ai-context.php`), the `AI_CONTEXT.md` action table, and `ai-instructions/composition.md` all describe the new `style` param and note it is item-scoped, same as `items[].style`. The `AI_CONTEXT.md` `update_component` row is also corrected to list its existing `style` param, so the two actions read consistently.

### Tests

- New `tests/ActionsTest.php` cases pin the honored path (a valid style persists onto the new item and combines with `position`), the rejection path (invalid slot value and unknown slot fail through the shared engine with the same error codes as `items[].style`), the no-mutation guarantee (a rejected style does not append or partially write), the opt-in guarantee (empty style omits the key), and the #368 regression itself (valid style honored, invalid style rejected — never silently dropped).

## [v0.16.135] — 2026-07-15 — eyebrow letter-casing is now authorable, so a kicker can be sentence case instead of forced uppercase (#370)

**The eyebrow/kicker pill baked `text-transform: uppercase` into all six section-header components (hero, section, faq, grid, cta, testimonials) with no slot to change it. The pill already exposed color, background, radius, and border slots, but its casing was a fixed opinion: a design that shows the kicker in sentence case, lowercase, or title case was inexpressible through the documented surface. This adds a per-component `--<component>-eyebrow-text-transform` style slot, consumed as `text-transform: var(--slot, uppercase)`, so an operator can author the casing. The default stays `uppercase`, so unset rendering is byte-identical.**

The casing values route through a new generic `text-transform` slot value type, added to the shared validator exactly like the `align` type from #357: `_pp_validate_text_transform` accepts a closed, case-insensitive keyword set (`none`, `uppercase`, `lowercase`, `capitalize`) and is dispatched from `_pp_validate_token_value` (no second validator). The type rejects everything outside that set, including the CJK-typography values `full-width`/`full-size-kana` (form conversion, not case control, the same way `align` omits `match-parent`) and the CSS-wide `unset`/`initial`/`inherit` keywords, so a slot value that would silently do nothing is caught at write time. A typed `text-transform` slot is honored at the render boundary for free, because `pp_render_style_value_allowed()` delegates to the same engine. The slot name contains `text-transform`, not `border-width`/`border-color`, so it does not trip the #332 core-border-trigger guard. The chat AI learns the new type and slots automatically: the runtime type list and slot catalog in `lib/ai-context.php`, the type-help hint in `lib/ai-chat.php`, and the static `AI_CONTEXT.md` / `ai-instructions` docs all name the casing slot and its keyword set in the same change.

### Fixed

- The eyebrow/kicker pill casing is now settable per component via the `--<component>-eyebrow-text-transform` style slot on hero, section, faq, grid, cta, and testimonials (`none` for sentence case as authored, or `uppercase`/`lowercase`/`capitalize`). Previously the pill was hardcoded to `uppercase` with no override. Unset rendering is byte-identical: the eyebrow still renders uppercase.

### Docs

- `AI_CONTEXT.md`, `ai-instructions/style-component.md`, and `ai-instructions/composition.md` describe the `text-transform` slot type and the per-component eyebrow casing slot; the style-slot total is updated to 194. `lib/ai-context.php` lists `text-transform` in the declared-type vocabulary and describes the eyebrow casing slot, and each component's `schema.json` documents its new slot.

### Tests

- New validator unit tests pin the `text-transform` type's accepted keyword set and its rejections (CJK/exotic values, CSS-wide keywords, `align` keywords, junk, empty). A rendered computed-style pin in `tests/e2e/style-render.spec.ts` proves the eyebrow computes `uppercase` when the slot is unset and `none` when the slot is set, including the ID-specificity benchmark hero, red then green. Slot-count and grid card-scope ledgers are updated to 194.

## [v0.16.134] — 2026-07-15 — button corner radius is now its own design token, so you can pill a CTA without rounding every card (#369)

**The button corner radius was unreachable through the design-token surface. `components.css` advertised a `--btn-radius` hook (`border-radius: var(--btn-radius, var(--radius))`), but `--btn-radius` was never a registered token, so `update_design_token --token=--btn-radius` was rejected as unregistered. The only radius lever that WAS registered, `--radius`, is global: setting it to `100px` to pill a CTA also pills every card and panel. Worse, the winning cascade rule for composed buttons (`main .btn`, the premium-CTA treatment) hardcoded `border-radius: 4px`, so the button radius never even followed `--radius` — it was a fixed 4px with no operator control at all. A pill CTA over square cards was inexpressible. This registers `--btn-radius` as a `length` design token (default `4px`, the button's actual current radius) and routes the winning rule through it, so an operator can set the button radius on its own.**

`--btn-radius` is declared in `assets/css/base.css` `:root` with the same `/* length: ... */` type-comment convention every other token uses, so `pp_design_tokens()` registers it and `update_design_token` validates its value through the shared `_pp_validate_token_value` length engine (no second validator). The premium-CTA block that decides the rendered button radius now reads `border-radius: var(--btn-radius, 4px)`, so setting `--btn-radius` to `100px` pills the button while `--radius` and every card/panel that reads it stay put. The registered default is `4px` (the value composed buttons already rendered), not `var(--radius)`: because declaring the token in `:root` defines the property globally, the fallback never fires, so the default has to match today's rendered value to keep unset output byte-identical. Every composed button (cta, hero, section) renders inside `main` and was already 4px, so unset rendering is unchanged. The token surfaces to the chat AI automatically (the runtime token catalog in `lib/ai-context.php` enumerates `pp_design_tokens()`), and the static catalog and retheme guide are updated to name it. `--btn-radius` contains `radius`, not `border-width`/`border-color`, so it does not trip the #332 core-border-trigger guard.

### Fixed

- Button corner radius is now settable on its own via `update_design_token --token=--btn-radius` (e.g. `--value=100px` for a pill CTA). Previously the only radius token an operator could set was the global `--radius`, which rounds cards and panels too, and the composed button radius was hardcoded at 4px and ignored it entirely. Cards and panels keep reading `--radius`, so button and card radius are now independent. Unset rendering is byte-identical: composed buttons still render at 4px.

### Docs

- `ai-instructions/retheme.md` no longer tells the AI to use `--radius` for "pill-shaped buttons" (an impossible instruction — `--radius` is global and never reached the button). It now points at `--btn-radius` as the per-button lever and reserves `--radius` for cards, panels, and surfaces. `AI_CONTEXT.md` lists `--btn-radius` in the Button token group.

### Tests

- `tests/ApplyTest.php` pins that `--btn-radius` registers as a `length` token defaulting to `4px`, that `update_design_token --token=--btn-radius --value=100px` validates through the shared engine, and that a non-length value is rejected with `invalid_length`. `tests/e2e/style-render.spec.ts` adds two rendered-proof pins driving the real `update_design_token` apply: setting `--btn-radius=100px` computes the composed button's `border-radius` to `100px` while `:root`'s `--radius` stays `0.375rem` and the card stays `4px` (decoupling), and unset computes the button at `4px` (byte-identical). Both proven red→green: removing the token registration reverts the apply to "not a registered design token", and reverting the cascade route leaves the button at 4px instead of 100px.

## [v0.16.133] — 2026-07-15 — a stats heading with a long title now centers on the page instead of sitting left of center (#367)

**A `stats` component's heading rendered left of page center on desktop. `.stats__heading` carries `text-align: center` and the shared `max-width: var(--cta-content-width, 40rem)` cap, but shipped with no auto side-margins. A block `<h2>` fills to that 40rem cap inside the wider centered `.container`, so the heading box pinned to the container's left edge (measured x 96-736 in a 1280px viewport, where the centered position is ~288-928) and `text-align: center` only centered the text inside that left-pinned box. The heading sat left of page center for any title long enough to reach the cap. This adds auto side-margins so the heading box centers under the already-centered stats row.**

Same class of bug as #354, on the stats component. The fix is `margin-left: auto; margin-right: auto` on `.stats__heading`, mirroring #354's `.section--centered .section__content` fix and the file's centering convention. Both sides auto, so the box centers regardless of writing direction and collapses to zero margin when the heading is as wide as the container (narrow viewports), introducing no band. The stats component is already centered end to end (`.stats__list` uses `justify-content: center`, `.stats__item` uses `align-items: center`), so centering the heading is consistent with the rest of the component. A pure alignment fix: no prop, schema, style slot, validator, or AI-facing behavior changes.

### Fixed

- A `stats` heading now centers within its container on desktop instead of pinning to the left edge. Previously a long stats title sat left of page center because the 40rem-capped heading box was left-aligned with only its text centered inside it. A heading narrower than the cap and narrow-viewport rendering are unaffected.

### Tests

- `tests/e2e/style-render.spec.ts` adds a rendered-geometry pin: at 1280px the stats heading box center-x equals its containing `.container` center-x (and the centered `.stats__list` beneath it), with real free space between them so the equality is a genuine reposition, not a fill artifact. Proven red→green: reverting the CSS left-pins the box and fails the assertion by ~224px. `tests/js/css-lint.test.js` adds a declaration-level guard that aggregates per selector and asserts any centered, max-width-capped content-block selector (`__heading`/`__title`/`__body`/`__content`) also declares an auto inline margin, plus targeted pins for `.stats__heading` (#367) and `.section--centered .section__content` (#354).



**#357 made a grid card's TEXT content (title, text, bullets) alignable through the `align`-typed `--grid-item-text-align` slot, but the `Read more` link stayed pinned left. `.grid__item-link` is a content-width flex item placed by `align-self: flex-start`, and per the #338 flex trap `text-align` cannot move a flex item's box — so a centered contact card (the webfiable use case #357 cites) centered its emoji/label but left the link flush left, only half-expressible. This makes the link follow the same slot: the operator still sets ONE value and both the text and the link align together, so a centered card is fully centered and a right-aligned card is fully right-aligned.**

`align-self` accepts `start`/`end`/`center` but not `left`/`right`/`justify`, so a bare `align-self: var(--grid-item-text-align)` would silently drop `right` (invalid value) and render it left — a real keyword map is required. Rather than a second schema slot or inline-style substring matching (the #332 hazard), `grid.php` derives an internal plumbing custom property `--pp-grid-link-align` from the same slot value through `pp_grid_link_align_decl()` (`lib/wp.php`), mirroring the existing `--pp-list-marker-color` internal-plumbing pattern. The map is physical (LTR theme, no `rtl.css`, mirroring #357's own default): `left`/`start`/`justify` → `flex-start`, `center` → `center`, `right`/`end` → `flex-end`. The CSS reads `align-self: var(--pp-grid-link-align, flex-start)`. A companion is emitted at BOTH grid level (on the section) and per card (on the `.grid__item`), and for every recognized value including `left` → `flex-start`, so a per-card override resets a grid-level companion the card inherits by cascade proximity. The value passes the SAME render boundary the text-align slot itself passes (#330/#233), so a stored value the shared engine would reject derives no companion. An UNSET slot emits nothing, so the `flex-start` fallback keeps every existing card byte-identical to today's left-pinned link. No second schema slot, no new public surface.

### Fixed

- A grid card's link/button now follows `--grid-item-text-align`: setting the slot to `center` centers the link, `right`/`end` right-aligns it, and `left`/`start`/`justify` (and unset) keep it left-pinned. The slot is emitted grid-wide or per-card, so one value aligns the whole card's content — a fully centered contact card is now expressible. Unset is byte-identical to prior rendering.

### Docs

- The `--grid-item-text-align` slot description in `components/grid/schema.json` (surfaced to the chat AI at runtime via `lib/ai-context.php`), `components/grid/README.md`, `ai-instructions/style-component.md`, `ai-instructions/composition.md`, `AI_CONTEXT.md`, and the `.grid__item-body`/`.grid__item-link` comments in `assets/css/components.css` now state that the slot aligns BOTH the text and the link/button, replacing the prior "the link keeps its own left-anchored position and is not moved by this slot" caveat.

### Tests

- `tests/GridItemStyleTest.php` gains a table-driven pin over all six `align` keywords mapping to the correct `--pp-grid-link-align` companion, a per-card-override-resets-inherited-grid-companion pin, grid-wide companion on the section, and byte-identical unset / render-boundary-drop parity (no companion for an unset or invalid value). `tests/e2e/style-render.spec.ts` rewrites the #357 boundary into a #361 rendered-geometry pin: a centered card's link box is horizontally centered, a right card's link is flush right, and an unset card's link stays flush left, at both mobile and desktop, measuring the link box position (not just the computed property) so a reverted companion goes red.

## [v0.16.131] — 2026-07-14 — create_page no longer strands a composition-less page; a declarative per-action gate keeps component edits closed (#358)

**`create_page` with no `composition` produced a page with no `_pp_composition`, and the preflight `target_page` check (the #96 mutation gate) then rejected EVERY page-scoped action on it — "Post ID N exists but has no composition." Because preflight runs once per target and is action-agnostic (its coverage unlocks all page actions for that post), that rejection blocked `update_composition` (which POPULATES the page) and `trash_page` (which DELETES it), not just component edits. The product's own `create_page` could strand a page that could be neither filled nor removed through the operate surface. This splits the precondition: `target_page` now accepts any existing page, and a new declarative, fail-closed per-action gate enforces the "needs a composition" requirement only where the action is known.**

The gate is split into the two places that can each answer their half correctly. Preflight's `target_page` check (`lib/operate.php`) now passes for any existing page regardless of composition emptiness — a `get_post()` null is still a hard fail, so the fail-closed boundary on non-existent posts is preserved. The per-action requirement moves to `pp_action_composition_precondition()` (`lib/operate.php`), enforced at the CLI per-action gate `_pp_cli_require_preflight_for_action()` (`lib/cli.php`) after coverage, where the action IS known. It reads a new declarative `requires_composition` flag that `pp_register_action()` defaults to `true` (default-deny): component-level actions (`add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) inherit the requirement and stay blocked on a composition-less page; populate, lifecycle, and metadata actions (`update_composition`, `publish_page`, `trash_page`, `restore_page`, `unpublish_page`, `update_page_title`, `update_page_slug`, `update_seo_meta`) opt out with `requires_composition => false`. `restore_composition` opts out too: per #233 restore is never blocked by current validation — its precondition is "the history ring has a target," enforced by its own validate, not "the current composition is non-empty." An un-annotated action inherits `true`, so the gate fails closed by construction. `create_page`'s output is unchanged (seeding an empty `[]` would not have fixed the gate anyway, since `!empty([])` is false).

### Fixed

- A page created by `create_page` with no composition is no longer stranded. Preflight's `target_page` check accepts any existing page, so `update_composition` can populate it and `trash_page` can delete it through the operate surface. Component-level actions (`add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) still fail closed with `composition_required` on a composition-less page — the #96 gate stays closed for them. The requirement is declarative and default-deny: `pp_register_action()` defaults `requires_composition` to `true`, so an un-annotated action is gated by construction.

### Docs

- The `create_page`, `update_composition`, and `trash_page` action descriptions in `lib/actions.php` (surfaced to the chat AI at runtime via `lib/ai-context.php`) now state that an empty page is populatable and deletable, and that only component-level edits require an existing composition. `ai-instructions/operating-loop.md` and `docs/operating-loop-safety.md` no longer describe the `target_page` preflight check as "post exists AND has a composition" — the check now only verifies the page exists, and the composition precondition is documented as a per-action gate. `ai-instructions/playbook-create-page.md` already documented the create→populate flow this fix restores.

### Tests

- `tests/OperateTest.php` adds eight pins: `target_page` passes for an empty-composition page and still fails for a non-existent post; the positive end-to-end path (create empty → `update_composition` populates → `trash_page` deletes); the negative/security path (all five component-level actions blocked with `composition_required` on a composition-less page, allowed once populated); the declarative default (an un-annotated action inherits `requires_composition=true` and fails closed); the registration default and the opt-out set (`restore_composition` included, per #233); and the site-scoped no-op.

## [v0.16.130] — 2026-07-14 — a grid can now render a uniform card row, opting the first card out of the featured treatment (#226)

**A `cards`-layout grid always emphasized its first card — an accent top bar, a tinted fill, a larger title, extra top-padding, and (on the dark theme) a slight lift — with no way to turn it off. That made a symmetric card row inexpressible: three equal specification cards rendered with the first one larger and pushed ~36px lower than its peers, so their checklists could not line up. This adds `card_emphasis`, a grid prop that takes `featured` (the default) or `uniform`. Set `uniform` and every card renders identically. Leave it unset or `featured` and the grid renders exactly as before, down to the byte.**

`card_emphasis` is a generic enum prop (a uniform card row is a normal marketing need), matching the existing class-emitting enum convention (`layout`, `theme`, `heading_align`): `uniform` emits a `grid--uniform` modifier class; the default `featured` emits no class. Rather than an override block that re-resets each featured property, the whole featured treatment is guarded — every `main > .grid:not(.grid--steps) .grid__item:first-child` rule in `assets/css/components.css` gains a `:not(.grid--uniform)`, so under `uniform` the first card matches no featured rule and falls through to the shared all-cards rules, rendering identically to its siblings by construction. Because the default emits no class, the guard never matches an existing page: adding `:not(.grid--uniform)` only raises each featured rule's specificity by one class column, uniformly, with no competing rule in the flip band, so unset output is byte-identical. The prop flows through the schema-driven prop-key gate (#147) and the shared composition validator — no grid-specific second validator. It is `cards`-layout emphasis; on `steps` the class is inert (the featured rules already carry `:not(.grid--steps)`).

### Added

- `grid` accepts a `card_emphasis` prop (`featured` default / `uniform`). `uniform` opts the first card out of the entire featured treatment — accent bar, tinted fill, larger title, extra top-padding, and dark-theme lift — so a symmetric/peer card row renders equal cards. Unset or `featured` is byte-identical to before. Invalid values coerce to `featured`, matching how `layout`/`theme` handle unknown enum values.

### Docs

- `components/grid/schema.json` (new `card_emphasis` enum + `grid--uniform` variant class), `components/grid/README.md` (new prop row + "Card emphasis" section), `AI_CONTEXT.md`, `README.md`, `ai-instructions/composition.md` (worked JSON example), and `ai-instructions/style-component.md` now document the prop and when to use it — a symmetric/peer card row vs the featured default — and note it is a prop (set via `create_page`/`update_component`), not a style slot, and drops more of the featured treatment than the slot-level `uniform-cards` recipe can reach. The runtime AI context (`lib/ai-context.php`) renders the enum and its values automatically.

### Tests

- `tests/ComponentPropsTest.php` adds PHP render pins for the class emission, the byte-identical `featured`/absent default, invalid-value coercion, and composition with `theme`/`steps` classes. `tests/e2e/style-render.spec.ts` adds computed-style pins proving a `uniform` grid's first card equals its siblings (body `padding-top`, title `font-size`, no `::before` accent bar, shadow, border) at desktop and mobile while a default grid stays featured, plus a dark-theme pin that the first-card lift is neutralized. Proven red→green: neutering the class emission makes the first equality assertion (`padding-top`) fail. `tests/js/css-lint.test.js`'s featured-selector constant is updated to the guarded form so its zero-match drift guard keeps matching.

## [v0.16.129] — 2026-07-14 — a styled header's active nav link now honors its link color, not the accent (#355)

**Setting `pp_header_link_color` recolored the header's nav links, but the active/current link ignored it and stayed the global accent. On a one-page anchor-nav marketing site every link points at the current page, so WordPress marks them all current and the whole menu ignored the option. Now the active/current link color follows `pp_header_link_color` too, falling back to the global accent only when the option is unset. The current item keeps its bold weight, so it stays distinguishable. Leave the option unset and the header renders exactly as before, down to the byte: the active link is still the accent, still bold.**

The active-link rules in `assets/css/components.css` (`.nav__menu li.current-menu-item > a`, `.current_page_item > a`, and `a[aria-current="page"]`) hard-coded `color: var(--color-accent)`, which won over `--header-link-color` (the token set by `pp_header_link_color`, #333). They now resolve `color: var(--header-link-color, var(--color-accent))`, mirroring the base nav-link rule, so an operator's chosen link color reaches the active link while an unset option falls back to the accent exactly as before. The `font-weight: 700` emphasis is unchanged, so the current page stays marked. This adds no site option and no public surface — it is a cascade correction on existing header chrome. On a normal multi-page menu with a styled header, the current item's color now matches its siblings and is distinguished by weight; the accent color highlight yields to the operator's chosen link color by design.

### Fixed

- The header's active/current nav link now follows `pp_header_link_color` (emitted as `--header-link-color`) instead of forcing the global accent over it, so styling the header colors the active link too. It falls back to `--color-accent` when the option is unset, so an unstyled header is byte-identical to before, and the bold weight still marks the current item.

### Docs

- `AI_CONTEXT.md`, `ai-instructions/set-logo.md`, `components/nav/schema.json`, `components/nav/README.md`, and the `update_site_option` action description (`lib/actions.php`) now state that `pp_header_link_color` colors the active/current header link (with the accent as the unset fallback) and that the current item keeps its bold weight. A one-line steer was added that the header should be styled to match the site's real header, not the hero (a dark hero is not a reason for a dark header). The nav README's active-link note was corrected: WordPress marks the current item server-side (`current-menu-item` / `aria-current`), no JS.

### Tests

- `tests/e2e/style-render.spec.ts` gains a `#355` rendered pin that seeds a real WP menu (so the `current-menu-item` is genuine, not a static assertion) and proves the active link computes the operator's `pp_header_link_color` when set and `--color-accent` when unset, `font-weight: 700` in both. Proven red→green: reverting the CSS makes the set-color assertion fail (accent instead of the link color). `tests/js/css-lint.test.js` adds a static pin that both active declarations (including the `aria-current` one the render pin can't isolate) route color through `--header-link-color`, and guards against a regression to the bare `var(--color-accent)`.

## [v0.16.128] — 2026-07-14 — a grid card's text can now be centered, not just left-aligned (#357)

**A `grid` card always rendered its content left-aligned; there was no way to center a card's text, so the centered emoji/label contact-card pattern was simply not authorable. This adds `--grid-item-text-align`, a per-card (and grid-wide) style slot that takes a `text-align` keyword. Leave it unset and every card renders exactly as before, down to the byte: the default is `left`, the theme's historical alignment.**

Text alignment is a generic CSS property, not a product feature, so this exposes it through a new generic `align` slot type on the shared style engine rather than a named "centered-card" variant. The `align` type accepts exactly the closed set of `text-align` keywords — `left`, `right`, `center`, `start`, `end`, `justify` — validated by the same shared engine (`_pp_validate_token_value`) that types every other slot, so the render boundary (#330) rejects an invalid alignment for free. The slot is card-scoped (item-eligible), so it can center one card in a row via `items[].style` or every card via the grid-level style, mirroring how `--grid-item-text-color` and `--grid-item-title-size` are already scoped. It aligns the card's text content — title, text, bullets — which are full-width flex items whose inline content follows `text-align`. The `Read more` link keeps its own left-anchored position (`align-self: flex-start`); centering the link alongside the text is a separate concern tracked in #361.

### Fixed

- `grid` cards now take an authorable text alignment through `--grid-item-text-align` (`left` default / `center` / `right` / `start` / `end` / `justify`), so a centered emoji/label contact card is expressible. Unset, a card emits no inline alignment and renders byte-identically to before (left-aligned).

### Docs

- The new `align` slot type is documented in the AI-facing type list (`lib/ai-context.php`), `ai-instructions/style-component.md`, and the chat AI's invalid-value remediation hint, so the site-building AI knows the type exists and what it accepts. `AI_CONTEXT.md`, `README.md`, `components/grid/README.md`, and `ai-instructions/composition.md` document the new card slot and its scope (text content, not the link); slot counts updated (187 → 188).

### Tests

- `tests/e2e/style-render.spec.ts` gains a rendered computed-style + glyph-geometry pin proving a card's content computes `text-align: center`/`right` when set and `left` when unset (byte-identical), at both mobile and desktop, and pinning the documented boundary that the `Read more` link stays left-anchored under `text-align: center`. Proven red→green: with the CSS rule reverted the center assertion fails (`start` instead of `center`). `tests/ApplyTest.php` adds accept/reject coverage for the `align` validator (the six keywords accepted; `top`, `20%`, `left top`, `unset`, empty rejected); `tests/GridItemStyleTest.php` pins per-card and grid-wide render, byte-identical unset, and render-boundary drop of an invalid value. Slot-type/count pins in `tests/SchemaValidationTest.php`, `tests/StyleSlotContractTest.php`, and `tests/js/css-lint.test.js` updated for the new type and slot.

## [v0.16.127] — 2026-07-14 — an eyebrow pill can now carry an outline, not just a fill (#356)

**The eyebrow pill exposed text color, background, and corner radius, but no border, so an outlined pill was simply not authorable — the only way to fake one was a tinted background. This adds a per-component border to the eyebrow on all six components that render one (hero, section, faq, grid, cta, testimonials) through two style slots: `--<component>-eyebrow-border-width` and `--<component>-eyebrow-border-color`. Leave them unset and every page renders exactly as before, down to the byte: the default is a zero-width transparent border, which draws nothing.**

An outlined pill is a generic capability, not a product feature, so this follows the same shape the component roots already use for their own borders (`border: var(--x-border-width, 0) solid var(--x-border-color, transparent)`) rather than inventing a new grammar. Width is a `length` slot defaulting to `0`; color is a `color` slot defaulting to `transparent`; the border style is a fixed `solid`, consistent with every other border in the theme. The two new slots surface to the site-building AI through the same runtime schema inspection that carries every style slot and the `--<component>-eyebrow-*` wildcard already documented for grid card-scope rejection, so no accepted-grammar description changed. The slot names embed `border-width`/`border-color`, which are WP-core cascade triggers (#332), but their inline custom properties render on the immunized component root, and the #332 rendered-immunity coverage was extended to all twelve new slots so the phantom-border guard stays honest.

### Fixed

- The eyebrow pill on `hero`, `section`, `faq`, `grid`, `cta`, and `testimonials` now takes an authorable border through `--<component>-eyebrow-border-width` and `--<component>-eyebrow-border-color`, so an outlined pill is expressible. Unset, the border is zero-width and transparent — byte-identical to before. On the four benchmark hero pages whose eyebrow re-declares `border-color` at ID specificity, that declaration now routes through the color slot so a set value still reaches the pill.

### Docs

- `AI_CONTEXT.md` and `README.md` slot counts updated (175 → 187). The new slots surface to the chat AI through the existing runtime slot inspection and the documented `--<component>-eyebrow-*` wildcard, so no accepted-grammar description changed.

### Tests

- `tests/e2e/style-render.spec.ts` gains a rendered computed-style pin proving that setting the hero eyebrow border slots renders a real 3px border in the asked-for color (on both a plain id and the ID-specificity benchmark hero, proving the routing), while an unset eyebrow stays at `0px` — byte-identical to before. The #332 `BORDER_TRIGGER_CASES` immunity set is extended to all twelve new border-trigger slots so cascade immunity stays proven. Slot-count pins in `tests/SchemaValidationTest.php`, `tests/OperateTest.php`, and `tests/js/css-lint.test.js` updated, and the two grid eyebrow border slots added to the grid card-scope-ineligible set.

## [v0.16.126] — 2026-07-14 — a centered section now centers its body copy under its heading, not just the heading (#354)

**A `section` with `layout: centered` centered its heading but left the body copy pinned to the left, so the paragraph sat about 112px off-center from the title above it. Now the body block centers under the heading, the way a centered layout is meant to read. Only the `centered` layout is touched: `text-only`, `image-left`, `image-right`, and `text-panel` render exactly as before, down to the byte.**

The centered layout centers an outer wrapper (`.section__body`, capped at the wider 56rem centered measure) but its inner body-copy wrapper (`.section__content`, capped at the narrower 42rem prose measure) had no horizontal centering of its own, so it hugged the left edge of the wider centered block while the title was explicitly centered. The two lines of a "centered" section therefore disagreed with each other. The fix gives the inner wrapper auto side-margins in the centered layout only, so the narrower body column sits centered under the heading. The text inside was already centered; this centers the block it lives in.

### Fixed

- On `section` with `layout: centered`, the body copy block now centers under the heading instead of pinning to the left of the wider centered wrapper. Scoped to the centered layout only; the other four section layouts keep their left-aligned body copy and render byte-identically.

### Tests

- `tests/e2e/style-render.spec.ts` gains a rendered-geometry pin asserting that in a `layout: centered` section the `.section__content` box shares its center-x with the centered `.section__body` (with real free space between them, so the match is a genuine reposition, not a fill artifact), while a `text-only` section in the same page keeps its body copy left-pinned with zero side-margins. Proven red→green: with the fix reverted the centered assertion fails by the ~112px offset.

## [v0.16.125] — 2026-07-14 — a grid card's own text color now holds on mobile, even when the card also carries a meta/kicker role (#349)

**Setting a per-card text color (`--grid-item-text-color`) on a grid card that also used a `meta` or `kicker` typography role worked on desktop but silently reverted to the role's preset color below 768px. The color you authored simply vanished on phones. Now an explicit `--grid-item-text-color` wins over the role's preset at every breakpoint, so the card text renders in the color you set no matter the screen width. Leave the slot unset and every page renders exactly as before, down to the byte, at both breakpoints.**

The role class the card gets from `text_role` (`.text-meta` / `.text-kicker`) sets a color of its own, and because the theme's utility stylesheet loads after the component stylesheet, that role color won the tie against the card's own text-color rule on narrow screens, while a wider-screen rule out-specified it on desktop. The result was a color slot that reported success and rendered nothing on mobile. The fix out-specifies the role color at all breakpoints with the authored slot, falling back to the role's own color when the slot is unset, so nothing changes unless you explicitly set the color. Only `meta` and `kicker` carry a preset color; the `mono` and `label` roles were never affected.

### Fixed

- An explicit per-card `--grid-item-text-color` now overrides a `text_role: meta` or `text_role: kicker` color preset at all breakpoints, mobile included. Previously the slot was honored at ≥768px and silently defeated below it. Unset-slot output is byte-identical to before at both breakpoints; no default color changed.

### Docs

- The grid `text_role` note in `ai-instructions/composition.md`, `ai-instructions/style-component.md`, `components/grid/schema.json`, and `components/grid/README.md` now documents that an explicit `--grid-item-text-color` always wins over a role's preset color, and that the role's non-color typography (size, weight, letter-spacing, transform) always applies.

### Tests

- `tests/e2e/style-render.spec.ts` gains a rendered computed-style pin asserting the slot wins over `meta` and `kicker` at BOTH 375px and 1280px, and that unset cards stay byte-identical (role color on mobile, secondary color on desktop). Proven red→green: with the fix reverted, the mobile set-slot assertion fails. `tests/js/css-lint.test.js` widens its grid-card-text fallback allow-list to accept the role-color tokens, which are fixed global tokens never re-scoped under any theme.

## [v0.16.124] — 2026-07-14 — the header's top gap is authorable now, so "tighten this header" is fully expressible (#343)

**The site-building AI could set the space *below* a component's sub-heading but not the space *above* it, so a request like "tighten this header" was only half-expressible. This adds three style slots — `--section-title-margin-bottom`, `--grid-heading-margin-bottom`, and `--testimonials-heading-margin-bottom` — that make the gap between the title and the sub-heading authorable on section, grid, and testimonials. Leave them unset and every page renders exactly as before, down to the byte: the slots add reach, not a new spacing opinion.**

This is the top-side companion to the sub-heading bottom-margin slots added in #336. The whole header rhythm on these three components is now slot-driven instead of half-hardcoded. The gap is not a single fixed value: on section and grid the responsive "premium typography" rules set it to `1.65rem` on wide screens and `1.25rem` on narrow ones, and both of those declarations now route through the slot with today's literal as the fallback, so a set value wins at every breakpoint and an unset slot changes nothing. Testimonials, which has no responsive override, keeps its `var(--space-lg)` default. The FAQ heading shares the same responsive rule but is out of scope here and keeps its literal spacing, unchanged.

### Fixed

- The title-to-subheading gap on `section`, `grid`, and `testimonials` is now routed through a per-component `--<component>-<title|heading>-margin-bottom` style slot instead of a bare literal, at the base rule and at both responsive breakpoints. Defaults are byte-identical to before.

### Docs

- `AI_CONTEXT.md` and `README.md` slot counts updated (172 → 175). The new slots surface to the chat AI through the existing runtime slot inspection and the `--grid-heading-*` wildcard already documented for grid card-scope rejection, so no accepted-grammar description changed.

### Tests

- `tests/e2e/style-render.spec.ts` gains rendered computed-style pins per component proving the slot's default renders unchanged (26.4px section/grid at ≥768px, 32px testimonials) and that a set value reaches the element under the real cascade, including past the premium-typography override. `tests/StyleSlotContractTest.php` auto-discovers the three new slots and confirms none is bypassed by a literal re-declaration. Slot-count pins in `tests/SchemaValidationTest.php` and `tests/js/css-lint.test.js` updated, and `--grid-heading-margin-bottom` added to the grid card-scope-ineligible set.

## [v0.16.123] — 2026-07-14 — the slot-contract guard can no longer be blindsided by a clobber in another stylesheet (#342)

**`tests/StyleSlotContractTest.php` scanned only `components.css`, so a rule in `base.css` or `utilities.css` that outranks a component's slot rule was invisible to it — which is exactly how #336 hid: `base.css`'s `p:last-child { margin-bottom: 0 }` (specificity (0,1,1)) silently beat a bare `.grid__subheading` (0,1,0) on three components while every unit test stayed green. This adds check 8, a static cross-sheet tripwire that reads `base.css` and `utilities.css` and fails the build when an automatic-match rule (a bare element/pseudo-class selector, the mechanism that matches component elements by tag with no template opt-in) declares a slot-consumed property at a specificity that defeats a bare component class. Every such candidate must be acknowledged in a shrink-only ledger with a justification; a new one fails until a human accounts for it. No product CSS changed — this is guard hardening only.**

The guard is honest about what a static text scan can and cannot prove: it does not resolve the cascade (specificity, source order, and whether two selectors hit the same rendered element are a browser's job), so it does not claim the slot lands. It proves the weaker, load-bearing thing — every cross-sheet rule that *could* clobber a slot is accounted for — and leaves the true cascade proof to the rendered computed-style pins in `tests/e2e/style-render.spec.ts`. This is the same division of labour as the issue-332 immunity check already in the file: the static half keeps the contract honest as the surface grows, the rendered half owns what only a browser can prove. The threshold is load-order-aware (`base.css` before `components.css` so its ties lose; `utilities.css` after so its ties win), and that enqueue order is itself pinned so it cannot silently invert. Opt-in utility classes are deliberately out of scope — they reach a slotted element only when a template adds the class, a visible composition, not a silent cascade defeat.

### Tests

- `tests/StyleSlotContractTest.php` gains check 8: a cross-sheet clobber tripwire (`base.css` + `utilities.css`), a shrink-only `CROSS_SHEET_CLOBBER_LEDGER` with an exact size pin (today: `p:last-child`/`blockquote:last-child` → `margin-bottom`, `a:hover` → `color`), a `functions.php` load-order assertion, and a negative-control test proving the tripwire goes red on a new element/pseudo-class clobber while staying silent on bare element rules, opt-in utility classes, `@media`-nested rules (caught), pseudo-element boxes (skipped), and type selectors inside `:not()` (counted). New CSS-specificity and subject-parsing helpers back the check.

### Docs

- `README.md` and `docs/AI_IMPLEMENTATION_RECIPES.md` note that the slot contract now holds across stylesheets, not just within `components.css`, and that the static guards account for every clobber candidate while the rendered pins own the cascade proof.

## [v0.16.122] — 2026-07-14 — a text-panel can finally hold paired data: label/value rows, a monospace option, and a per-row accent (#334)

**A `text-panel`'s `panel_items` accepted only plain strings, rendered as bullets, so the one thing a content panel most often holds — paired data — had nowhere to go. A pricing summary, a spec sheet, a plan comparison, a stat readout, a config list: all collapsed into flat prose bullets. Now a `panel_items` entry can be a `{ "label": "...", "value": "..." }` object that renders as a two-part row, label left and value right, alongside the existing string bullets in the same list (mix them freely). A new `--section-panel-font` style slot takes `var(--font-mono)` for a monospace panel, and any single row can be emphasised or de-emphasised with a per-row `style` map that sets the panel's own text-colour slot. Leave `panel_items` as strings and every existing panel renders byte-identically.**

The capability is deliberately generic: paired label/value rows, a font slot, and a per-row colour are the parts a spec sheet, a pricing table, and a status readout all share, so none of them is a first-class product feature — each is a composition of these parts. There is no `terminal` or `diagnostic` panel mode, no `ok`/`warn`/`fail` vocabulary, and no meter primitive; a monospace data panel is just `--section-panel-font: var(--font-mono)` plus paired rows plus a dark `--section-panel-bg`. The per-row accent reuses the existing `--section-panel-text` slot rather than inventing a new colour grammar: that slot is now item-eligible, so a per-row `style` map may recolour one row through the same shared style engine and item-scope gate that grid cards use (issue 306/323), with no second validator. Rows are not bullets, so a paired row carries no marker glyph even when the list's string items show a check or dash. Existing all-string `panel_items` arrays render exactly as before, down to the bytes.

### Added

- `section` `panel_items` entries may now be a `{ label, value }` object rendered as a two-part row (label left, value right), mixable with plain-string bullets in one list. Both label and value are plain text, escaped like the string form.
- `--section-panel-font` style slot (default `inherit`) — set it to `var(--font-mono)`, or any font stack, for a monospace content panel. Unset, the panel inherits the page font unchanged.
- Per-row accent: a paired-row entry may carry a `style` map setting the now item-eligible `--section-panel-text` slot, recolouring that one row to emphasise or de-emphasise it. Validated by the shared style engine and item-scope gate — no new colour grammar, no domain vocabulary.

### Docs

- `ai-instructions/composition.md` documents the paired-row grammar with two worked examples (a string panel and a monospace spec panel with a per-row accent); `AI_CONTEXT.md`, `README.md`, and the `section` component README and schema carry the new item shape and font slot. The shared per-item style guidance in `lib/ai-context.php` and the shared validator's scope error message are now component-neutral, since a second component (the panel) now uses that path.

### Tests

- `tests/SectionTextPanelTest.php` pins the paired-row markup, mixed string-and-row lists, the byte-identical string form, per-row style validation (accepted slot, rejected ineligible slot, rejected bad value), the mono-font slot, and the marker suppression on rows. `tests/StyleSlotContractTest.php` extends the issue-332 border-immunity baseline to the per-row surface and pins the new slot's consumer. `tests/e2e/style-render.spec.ts` proves in a real WordPress 7.0 browser that the mono font reaches the panel, a per-row accent recolours only its row, and a paired row shows no marker while a string bullet beside it still does. `tests/SchemaValidationTest.php` and `tests/js/css-lint.test.js` track the new slot.

## [v0.16.121] — 2026-07-14 — check-mark lists are no longer trapped inside grid cards: any section list can carry a marker (#339)

**The orange check-mark bullet existed in exactly one place — a grid card's `bullets` — and nowhere else. A `section` body list and a `text-panel`'s `panel_items` could only render plain grey discs, so the purpose-built "checklist beside a panel" section could not style the checklist it exists to hold. That is fixed: `section` now takes `panel_items_marker` and `body_marker`, each choosing `disc` (the unchanged default), `check`, `dash`, or `arrow`, and the marker colour is authorable through the `--section-panel-marker-color` and `--section-body-marker-color` style slots. The same check-mark treatment the grid always had is now one shared definition reachable from every list-rendering surface. Leave the props unset and every existing list renders exactly as before.**

The capability here is generic on purpose: a list can carry a marker other than a disc, and the marker's glyph and colour are authorable — a disc, a check, a dash, and an arrow are all just marker values, not a bespoke "checklist" widget. The grid's shipped bullet treatment was lifted into a single shared list-marker definition that the grid, the panel list, and the section body list all consume, so there is one place the check mark lives, not three copies. `disc` adds no class at all, which is what keeps every list that does not opt in byte-identical to before, down to the grid's own bullets. The marker colour defaults to the site accent (mirroring the grid's existing bullet colour) and is overridable per instance, so a check-list on a dark panel can be re-coloured without touching CSS. Body markers apply to a list authored as a direct child of the section body, leaving nested lists on their default disc. No colour or size opinion is baked into the component: the marker's look is a style-slot value the site-building AI chooses.

### Added

- `section` prop `panel_items_marker` (`disc` default / `check` / `dash` / `arrow`) — the marker for a text-panel's `panel_items` list. Pair it with the `--section-panel-marker-color` style slot (defaults to the site accent) to colour a check-list on a dark panel.
- `section` prop `body_marker` (`disc` default / `check` / `dash` / `arrow`) — the marker for top-level `<ul>` lists in a section's `body`, coloured through the `--section-body-marker-color` style slot. This makes a check-list expressible in prose without moving the content into a grid or panel.

### Fixed

- The check-mark list treatment was reachable only from grid cards; `section.body` and `text-panel` `panel_items` could produce nothing but grey discs (found in the 1.0-H acceptance dogfood). The treatment is now a shared marker any list-rendering surface can opt into, so the reference "benefits beside a panel" checklist reproduces natively, glyph and colour matching. The grid's existing bullets render byte-identically.

### Docs

- `ai-instructions/composition.md` documents `panel_items_marker` and `body_marker` (with a body check-list example); `AI_CONTEXT.md`, `README.md`, and the `section` component README and schema carry the two new props and colour slots. The site-building AI now knows a check-list is reachable from any section list, not just the grid.

### Tests

- `tests/SectionTextPanelTest.php` pins the marker class wiring for every value, the `disc` default and invalid-value clamp for both props, and the section-block colour-slot mapping. `tests/e2e/style-render.spec.ts` proves the marker actually paints over the disc rules in a real WordPress 7.0 browser: the panel and body check lists compute `list-style: none` with the operator's marker colour, the grid bullet still honours `--grid-bullet-color` after the refactor, a nested body list keeps its disc, and dash and arrow render too. `tests/SchemaValidationTest.php` and `tests/js/css-lint.test.js` track the two new style slots.

## [v0.16.120] — 2026-07-13 — the footer can finally be organised: labelled columns, a delimited bottom bar, and a light logo for a dark band (#335)

**Issue #300 gave the footer a dark marketing tone but no structure, so it rendered as an undifferentiated run of blurb, links, contact string, and copyright. Four new site options add the organisation a marketing footer needs, all through the same `update_site_option` surface, still with no footer builder. `pp_footer_menu_label` and `pp_footer_contact_label` put a heading over the menu and the contact columns. `pp_footer_note` moves the copyright into its own delimited bottom bar and renders a secondary line opposite it. `pp_footer_logo_id` gives the footer its own logo, so a light logo variant can sit on a dark footer while `pp_logo_id` stays the header logo. Every one is optional; unset, the footer renders as it did before — this adds structure, it does not change any default.**

The one design choice worth naming is what triggers the bottom bar. The reference footer keeps its copyright in a delimited band with a secondary note on the opposite side, but a footer that never sets a note should keep rendering its copyright inline exactly as #300 did. So the presence of `pp_footer_note` is the trigger: set it, and the copyright moves out of the main flow into a bordered band with the note beside it; leave it empty, and nothing moves. The copyright string is built once and rendered in exactly one place, whichever place applies, so it can never duplicate or drift. The bottom bar's divider reuses the same `--color-border` token the footer's own top border already uses, and the column headings inherit the footer text colour through `--footer-text` — no colour or size opinion is baked into the component, because a footer's palette is a token choice the site-building AI makes, not something a structural change should decide. The logo override is not a new resolver: the footer already resolved its logo through `logo_id` → `pp_logo_id` → theme-mod → wordmark, and `pp_footer_logo_id` simply feeds the first step, so an unset override falls back to `pp_logo_id` with no new code path. It validates as an image attachment ID under the exact same rule as `pp_logo_id`.

### Added

- `pp_footer_menu_label` and `pp_footer_contact_label` site options — optional headings above the footer navigation menu and the contact block. Empty leaves both columns unlabelled, as before.
- `pp_footer_note` site option — an optional secondary line. When set, the copyright moves into a delimited bottom bar (a top border) and the note renders opposite it; empty keeps the copyright inline exactly as #300 rendered it.
- `pp_footer_logo_id` site option — an optional footer logo override (a Media Library image attachment ID, never a URL, same validation as `pp_logo_id`). It lets a light logo variant serve a dark footer while `pp_logo_id` stays the header logo. Unset, the footer falls back to `pp_logo_id`.

### Docs

- `AI_CONTEXT.md`, `ai-instructions/set-logo.md`, `ai-instructions/website-building.md`, the `footer` component schema and README, the `update_site_option` action description, and `README.md` all document the four new options. The site-building AI is now told it can label the footer columns, delimit the bottom bar, and give the footer its own light logo.

### Tests

- `tests/FooterChromeTest.php` pins the four new options end to end: the whitelist types, the `pp_footer_logo_id` attachment validation and its parity with `pp_logo_id`, the headings rendering only when their label is set, the bottom bar appearing only when a note is set with the copyright moved into it and never duplicated, the logo override resolving and falling back to `pp_logo_id`, escaping of every new string, unset output carrying no new structure, the neutral token-routed CSS, and `templates/base.php` mapping each option onto a footer prop.

## [v0.16.119] — 2026-07-13 — the header can finally carry a colour, and either chrome band can carry a gradient (#333)

**The site header was the one piece of above-the-fold chrome with no styling surface at all. Its background was hard-bound to the global page colour, its logo and links took the global text colour, and there were no header site options — so a dark or gradient marketing header could not be expressed without inverting the entire site's tokens. Three new site options fix that: `pp_header_bg`, `pp_header_text`, and `pp_header_link_color`, the exact mirror of the footer surface shipped in #300. In the same change, `pp_header_bg` and `pp_footer_bg` now accept a CSS gradient as well as a solid colour, so a real gradient band is expressible on either. Unset, the header and footer render exactly as before — this adds a capability, it does not change any default.**

The gradient support is the part worth explaining, because the obvious way to build it was wrong. A gradient is a CSS `<image>`, not a colour, so assigning one to the `background-color` property is invalid and the browser silently drops the whole declaration — the option would have validated on write, survived a snapshot round-trip, and then painted nothing on screen, with every declaration-level test still green. The header and footer bands now route through the `background` shorthand instead, which accepts both a colour and a gradient; the shorthand's reset of the other background longhands is a genuine no-op because those selectors set no other background property. Validation stays in the shared engines only: `pp_header_bg` and `pp_footer_bg` are typed against the same `gradient` grammar every gradient-typed style slot already uses (a bounded `linear-gradient()`/`radial-gradient()` with two or more colour stops, no `var()`/`url()`/`env()` inside, no conic or repeating gradients), and the four text and link options take a plain colour only. Both chrome bands emit their inline custom properties through a single shared helper that reads each value's declared type straight from the site-option whitelist, so the render-time type can never drift from the write-time type — the specific gap that made the footer's own gradient support inexpressible until now.

### Added

- `pp_header_bg`, `pp_header_text`, `pp_header_link_color` site options — the header's colour surface, set through the `update_site_option` action exactly like the footer's. `pp_header_bg` sets the background (a colour or a gradient), `pp_header_text` colours the logo wordmark and the mobile menu toggle, and `pp_header_link_color` colours the nav links. The header is template-owned, so these options are its only styling surface; unset, the header renders byte-identically to before. Hover and current-page links keep the global accent token.
- `pp_header_bg` and `pp_footer_bg` now accept a bounded CSS gradient (`linear-gradient()`/`radial-gradient()`, 2+ colour stops) in addition to every colour they accepted before. This is how a dark or gradient marketing header or footer is built. The four text/link chrome options remain colour-only.

### Fixed

- `pp_footer_bg` could not express a gradient even though the footer chrome was meant for dark marketing bands (#300): it was typed as a plain colour, and the footer's background was painted through `background-color`, which cannot render a gradient. Both are corrected — the option accepts the gradient grammar, and the band paints through the `background` shorthand. A footer that only ever set a solid colour is unaffected.

### Docs

- `AI_CONTEXT.md`, `ai-instructions/set-logo.md`, `ai-instructions/website-building.md`, the `nav` and `footer` component schemas and READMEs, the `update_site_option` action description, and `README.md` all document the header colour surface and the gradient-capable backgrounds. The site-building AI is now told the header is stylable through these options and instructed not to fake a dark header by inverting the site's global tokens.

### Tests

- `tests/e2e/style-render.spec.ts` proves the gradient actually paints, in a real browser, with `getComputedStyle`: a gradient set on `pp_header_bg`/`pp_footer_bg` produces a non-`none` background image, a solid colour still works on the same option, and an unstyled header carries no inline style and no background image. A declaration-level assertion could not catch the underlying bug — the property would be present in the HTML while the browser dropped it — so the pins measure what the cascade actually rendered. The negative control was confirmed by hand: reverting the CSS to `background-color` makes the computed background image `none` and the pin fails.
- `tests/HeaderChromeTest.php` and `tests/FooterChromeTest.php` pin the whitelist types, the shared-engine delegation (gradient accepted on the background options, rejected on the text/link options), the render-boundary drop of an out-of-band or wrong-type value, the fail-closed behaviour of the shared chrome-style helper on an unknown or non-style option key, the `background`-not-`background-color` CSS routing, and byte-identical unset output.

---

## [v0.16.118] — 2026-07-13 — the eyebrow pill, the stats number, and the sub-heading's rhythm are all authorable now (#336)

**Three things the site-building AI could not express through any documented surface. The eyebrow pill had colour and background slots but no radius slot on any of the six components that render one, so it could not be rounded. The stats number had a colour slot and no size slot at all, so the headline figure of the whole component could not be scaled. And the sub-heading on section, grid and testimonials rendered with no bottom spacing at all, colliding with the content beneath it. Ten new style slots close the first two. The third was a cascade bug, and it is fixed. Existing pages will reflow, deliberately: sub-headings now render the spacing they always declared.**

The sub-heading defect is the interesting one, because nothing was missing from the component. `.grid__subheading` declared `margin-bottom: var(--space-lg)`, and `--space-lg` resolved correctly to `2rem`. The declaration simply never reached the page. `assets/css/base.css` carries a global prose reset, `p:last-child { margin-bottom: 0 }`, whose specificity (0,1,1) outranks a bare component class (0,1,0) — and the sub-heading is always the last child of its header block, on every component, in every layout. So the reset won every time, on every page, while the stylesheet read as correct and every unit test stayed green. This was never a grid-only problem: section, grid and testimonials all render their sub-heading as a trailing `<p>`, and all three measured `0px`. The fix owns the spacing at header scope (`.X__header > .X__subheading`, (0,2,0)) so it wins the cascade on merit, routed through a new slot so the operator keeps control. The global reset is deliberately left intact — it correctly serves prose blocks like `.section__content` and `.cta__body`, and weakening it to fix three headers would have traded one silent bug for another. The defaults do not move: the eyebrow still falls back to `3px` and the stats number to `2.5rem`, because a pill radius and a display-scale number are style choices, and style choices belong in the token values the site-building AI picks, not baked into component CSS. The bug was never the default. The bug was that the AI could not express anything else.

### Fixed

- Sub-headings on `section`, `grid` and `testimonials` render their declared bottom rhythm instead of `0px` (#336), so they no longer collide with the content below. **This changes existing pages:** a sub-heading that previously rendered flush against the next block now carries its spacing (16px on section, 32px on grid and testimonials). That spacing is what the components always asked for; it was being silently discarded by a base stylesheet reset that outranked them.
- The `#home-hero`, `#how-hero`, `#agencies-hero` and `#implementers-hero` eyebrow rule re-declared `border-radius` at ID specificity and would have silently bypassed the new radius slot on exactly the four pages that use it. It is routed through the slot, so setting the slot works on those pages too. Same class as #292 and #302: a higher-specificity literal quietly defeating a slot while everything else looks correct.

### Added

- `--hero-eyebrow-radius`, `--section-eyebrow-radius`, `--faq-eyebrow-radius`, `--grid-eyebrow-radius`, `--cta-eyebrow-radius`, `--testimonials-eyebrow-radius` — the eyebrow pill's corner radius, on all six components that render one. Set a large value (`999px`) for a fully rounded pill. Fallback stays `3px`, so unset output is byte-identical.
- `--stats-number-size` — the stat number's font size. Only a colour slot existed before, which meant the largest, most load-bearing text in the component was the one thing that could not be resized. Fallback stays `2.5rem`.
- `--section-subheading-margin-bottom`, `--grid-subheading-margin-bottom`, `--testimonials-subheading-margin-bottom` — the space between a sub-heading and the content below it. Fallbacks are the rhythm each component already declared.
- Style slots: 159 → 169. No slot-contract waivers were added.

### Tests

- `tests/e2e/style-render.spec.ts` pins all three strands with computed style in a real browser, twice each: unset renders the documented default, and setting the slot drives the element. A declaration-level assertion would not have caught this bug — the sub-heading's `margin-bottom` was *declared* the whole time and still computed to `0px` — so the pins measure what the cascade actually produced. Each sub-heading pin also asserts the element really is its header's last child, so the regression stays pinned only while the markup that triggers it still exists. The eyebrow radius is pinned on a hero whose id matches the ID-scoped rule, because a hero with any other id never matches that block and would leave the highest-risk edit unproven.
- The schema, slot-count and CSS-lint pins move with the new slots (169 total), and the grid card-scope pin keeps both new grid slots correctly classified as header-scoped rather than per-card.

---

## [v0.16.117] — 2026-07-13 — the hero proof line now follows the hero's alignment (#338)

**In a centered hero, the proof line under the buttons rendered flush LEFT while the eyebrow, title, subtitle and buttons above it were all centered. The operator had already said the hero was centered, and no style slot existed to correct the proof line, so the layout simply could not be fixed through any documented surface. The proof line now follows the hero's alignment: centered in the centered and cover layouts, left-packed in the left and split layouts. Nothing to set, and no change to compositions that already exist.**

The cause was a flexbox default. `.hero__proof` is a flex container, and its `justify-content` was never declared, so it sat at the initial value and packed its items to the left. The centered hero DOES inherit `text-align: center` onto that row, which is why the styles looked correct on inspection, but a flex container ignores `text-align` when it places its items: the box was centered while the words inside it were not. The left and split heroes wanted left-packing anyway, so the missing declaration read as correct everywhere it was looked at. The hero's flex rows now declare their packing explicitly and take it from the layout variant, which means the proof row and the button row both center in a centered hero and both stay left in a left-aligned one. This is the same class as the hero eyebrow (#225) and the CTA eyebrow (#255): a flexbox default quietly overriding the component's alignment intent.

### Fixed

- The hero proof line follows the hero's alignment instead of always packing left (#338). `.hero__proof` and `.hero__cta-group` now declare `justify-content` explicitly; `.hero--centered` and `.hero--cover` center both rows, while `.hero--left` and `.hero--split` keep them packed to the leading edge. The button row carried the same undeclared default and could left-pack in a centered hero as soon as the buttons wrapped, so it is fixed in the same change. Alignment is driven by the layout the operator already chose, not by a new style slot. Left and split heroes render exactly as before.

### Tests

- `tests/e2e/style-render.spec.ts` pins the behavior in a real browser, measuring where the glyphs actually land rather than what the stylesheet declares — this bug was invisible at the declaration level, which is how it shipped. The proof content is measured with a Range over the row's contents, so the pins cover the bare-text proof (an anonymous flex item with no element to select) as well as element children, across the left, centered and cover layouts at desktop and mobile, plus a wrapped proof row where each flex line is checked on its own. Scope pins prove the fix does not over-apply: a split hero's proof line must stay left-packed, and the cover hero's overlay must still cover its section. A wrapped CTA group is pinned per layout, since that row's fault only becomes visible once the buttons wrap. Every pin carries a non-vacuity floor that fails if the content ever fills its column, where centered and left-packed would render identically.
- `tests/js/css-lint.test.js` adds declaration guards for both hero rows: base packing, the centered/cover overrides, the absence of any left/split override, and — the cascade risk the fixture pages cannot see — that no other rule and no other stylesheet re-justifies these rows, which an ID-scoped rule could otherwise do on real pages while every rendered pin stayed green.

---

## [v0.16.116] — 2026-07-13 — border style slots no longer paint an unwanted 3px border (#332)

**Setting any border-related style slot — even to `0` — could paint a 3px solid border around the whole component that nobody asked for. Thirteen documented slots across six components were affected, on any stock WordPress install. This release makes the component roots immune, so a border slot now produces exactly the border it specifies and nothing else. The slot names are unchanged: existing compositions keep working, and nothing about how you set a border needs to change.**

The cause was outside the theme. WordPress core's global stylesheet ships rules like `html :where([style*="border-width"]) { border-style: solid }`, aimed at the block editor's `style="border-width:2px"`. Style slots render as inline *custom properties*, so the substring core matches on lives in the property NAME — `style="--grid-card-border-width:0px"` matches the rule even though the element sets no border and the border the slot controls belongs to a card inside it. The component root, having declared no border of its own, then inherited core's injected `solid` at its initial `medium` width: a visible 3px border, on a component whose operator had explicitly asked for none. Every element that can carry an inline style slot now declares an explicit border baseline, which outranks core's rule on specificity, so core's injection is a no-op. Components that legitimately draw borders are unaffected, and a component with no slots set renders exactly as before.

### Fixed

- Border style slots no longer trigger an injected 3px solid border on the component root (#332). The 13 affected slots (`--cta-border-color`, `--cta-border-width`, `--faq-border-color`, `--grid-card-border-width`, `--hero-border-color`, `--hero-border-width`, `--hero-surface-border-color`, `--hero-surface-border-width`, `--section-border-color`, `--section-border-width`, `--section-panel-border-color`, `--section-panel-border-width`, `--testimonials-card-border-width`) now produce exactly the border they specify, including when set to `0`. The fix is a border baseline on every element that can carry inline slot properties (the component roots and the per-card grid item), declared above the component rules so the borders components legitimately draw still win. Slot names are unchanged and unset components render byte-identically.

### Docs

- `docs/AI_IMPLEMENTATION_RECIPES.md` (Recipe A) now records that a foreign stylesheet can match a slot on its NAME, and that slot custom properties must only be emitted onto an element the immunity baseline covers (#332).

### Tests

- `tests/e2e/style-render.spec.ts` pins the class in a real browser: for every affected component, setting its border-trigger slots leaves the rendered root with a zero border on all four sides, with a non-vacuity floor that fails if WordPress core ever stops shipping the trigger rule the pin depends on. Coverage of the 13 slots is derived from `schema.json`, so a new border slot that no pin covers fails the build. A per-card pin covers the grid item surface, and positive controls prove a non-zero slot still renders its border and an unstyled grid keeps its default 1px card border.
- `StyleSlotContractTest` gains a third-party cascade-immunity guard (check 7): it discovers every slot whose name embeds a WordPress-core trigger substring (including the per-side variants), asserts the immunity baseline exists at top level with the right values and above the component rules, and asserts every element the renderer emits inline slot properties onto is covered by it. A negative control proves the guard rejects a baseline that is nested in a media query, scoped under an ancestor, or declares a solid/non-zero border.

---

## [v0.16.115] — 2026-07-13 — stored style values are now re-validated at render time, not only at write time (#330)

**Style-slot and footer color values are strictly validated when they are written, but the render path trusted whatever was already in storage and applied only a narrow character check before emitting it into an inline `style` attribute. A value can reach storage without passing current write-time validation — a restored history snapshot (which is never blocked, by design) or a direct database write — so this release re-validates every stored style value at the render boundary as defense-in-depth. A value that would not pass validation is simply left out of the rendered output; the page still renders and any restore still succeeds.**

`pp_render_style_vars()` (used by every component's style slots and by per-item grid card styles) and the footer's color custom properties now re-check each stored value through the same shared validation engine used at write time, keyed by the slot's declared type. There is no second validation grammar and no change to what values are accepted: legitimate values (colors including `transparent`/`currentColor`, `var(--token)` references, validated gradients, lengths, shadows) render exactly as before. Only a value that never passed validation is dropped, and only from the rendered output — write-time rules and the restore-reports-never-blocks principle (#233) are untouched.

### Fixed

- The inline-style render boundary now re-validates each stored style value before emitting it (#330). `pp_render_style_vars()` (all component style slots plus grid `items[].style`) and the footer `--footer-*` color properties delegate to the shared `_pp_validate_token_value` engine per slot type, with a conservative reject fallback for any untyped value. A value that never passed write-time validation is dropped from the rendered output only; sibling declarations still render, and neither the page nor an in-progress restore is blocked.

### Docs

- `docs/AI_IMPLEMENTATION_RECIPES.md` now states that style-slot values are validated at both write time and render time, so contributors adding components know the render boundary re-validates stored values (#330).

### Tests

- `RenderStyleBoundaryTest` pins the render boundary: values that never passed write-time validation are dropped while sibling declarations still render, across the component style sink, the grid per-item style path, and the footer; the full accepted set (colors including `transparent`/`currentColor`, `var(--token)`, validated gradients including radial `at <position>`, lengths, shadows) renders unchanged; the helper's type-delegation and untyped arms are both covered; and filtering a value from output leaves the stored map untouched so restore round-trips are unaffected.

## [v0.16.114] — 2026-07-13 — re-importing the same image URL now reuses the existing attachment instead of creating a duplicate (#298)

**`import_media` is how an AI operator pulls an external image (a brand logo, say) onto the site as a locally-owned asset, and that loop retries and re-runs. Every call used to sideload a fresh copy and mint a new attachment, so importing the same URL three times left three identical files with `-1`/`-2` suffixes and no signal to reuse. This release dedupes by source URL: the first import records where the file came from, and any later import of that exact URL returns the existing attachment instead of downloading it again.**

Each successful import now records its source URL on the attachment. On the next `import_media` for the same URL, the apply returns the existing attachment (result `action: "reused"`) without downloading or creating anything — so retries and multi-run workflows stop accreting duplicate media. A fresh import still returns `action: "import"`. If the previously-imported attachment no longer resolves to a URL (deleted record), the import falls through and brings in a fresh copy rather than handing back a broken link. Dedupe matches the exact source URL and only covers imports made after this release records the marker; it is best-effort under concurrent identical imports (two simultaneous first-time imports of the same URL can still both create a copy).

### Fixed

- `import_media` now reuses an already-imported attachment for a repeat source URL instead of sideloading a duplicate every call (#298). Each import records the source URL as `_pp_import_source_url` attachment meta; a later import of the same URL returns that attachment with change `action: "reused"` (no download, no new attachment). A new source URL still imports fresh (`action: "import"`), and a cached attachment that no longer resolves to a URL is re-imported rather than returned broken.

### Docs

- The runtime AI system prompt (`lib/ai-context.php`), `ai-instructions/composition.md`, `ai-instructions/website-building.md`, and `AI_CONTEXT.md` now document that `import_media` dedupes by source URL and returns `action` `"import"` or `"reused"`, so operators know retrying an import is safe (#298).

### Tests

- `ApplyTest` pins the new behavior: a repeat import of the same URL returns `action: "reused"` with the original attachment id and makes no `download_url`/`media_handle_sideload` call; a fresh import records the `_pp_import_source_url` marker and returns `action: "import"`; a different URL is not deduped; and a cached attachment whose file no longer resolves is re-imported. The test harness stub now registers sideloaded attachments as posts so the `get_posts` dedupe lookup is exercised (#298).

## [v0.16.113] — 2026-07-13 — off-center radial gradients now validate: `radial-gradient(circle at top left, ...)` and other `at <position>` spotlights are accepted for gradient slots (#301)

**Radial gradients are the way to paint an off-center spotlight or corner glow behind a section, but the gradient validator rejected the one piece of syntax that makes that possible: any `at <position>` clause. `radial-gradient(circle, ...)` passed while the identical `radial-gradient(circle at top left, ...)` failed, and the error text claimed radial-gradient was supported, so the failure read as a bug. This release accepts the standard `at <position>` clause so brand backgrounds can place the gradient center where the design wants it.**

The radial branch of the gradient grammar now accepts an optional shape (`circle`/`ellipse`) and/or an optional `at <position>` clause, where `<position>` is one or two tokens, each a placement keyword (`center`, `top`, `bottom`, `left`, `right`) or a non-negative percentage. So `radial-gradient(circle at top left, #2a2145, #1a1a2e)`, `radial-gradient(at 20% 30%, ...)`, and `radial-gradient(ellipse at bottom right, ...)` all validate now. The grammar stays deliberately bounded: radial size keywords like `closest-side`, length positions like `at 10px`, and anything function-shaped (`var()`/`url()`/`env()`) remain rejected, so a value bound for an inline style attribute can still only be keyword and percentage tokens.

### Fixed

- The `gradient` style-slot validator (`_pp_validate_gradient`) now accepts the CSS `radial-gradient(... at <position>)` syntax (placement keywords and non-negative percentages, with or without a leading `circle`/`ellipse`). Off-center and corner-anchored radial gradients previously failed validation even though they are valid CSS; the four forms that already passed still pass, and the bounded grammar continues to reject size keywords, length positions, and function/`var()` tokens (#301).

### Docs

- The runtime AI system prompt (`lib/ai-context.php`) and `ai-instructions/style-component.md` now document that `radial-gradient()` may carry a shape and/or `at <position>` clause, with a worked example and the explicit note that radial size keywords and length positions are not accepted (#301).

### Tests

- `ApplyTest` pins the new accepts (`circle at top left`, `ellipse at bottom right`, shape-less `at top left`, percentage positions `at 20% 30%`), preserves the four prior allowlisted forms, and adds adversarial rejects proving the `at <position>` clause is a closed security boundary: `url(`, `expression(`, `javascript:`, a `;` declaration break, a `/* */` comment sequence, and `var()` inside the position all fail closed, as do radial size keywords (`closest-side`, `farthest-corner`), length positions, and a dangling `at`/`circle at` with no position tokens (#301).

## [v0.16.112] — 2026-07-13 — the footer can now be a dark marketing footer: background/text/link colors, a brand blurb, a contact block, and a custom copyright line, all through site options (#300)

**A dark-branded site always ended on a light footer. The footer is template-owned, so it had no style slots, no brand blurb, no contact column, and a fixed "© <year> <site>. All rights reserved." line you could only change by renaming the whole site. Rebuilding a real marketing footer natively was impossible. This release adds six whitelisted `pp_footer_*` site options — the same safe surface as the footer logo (#234) — so a footer can go dark and carry brand copy without touching theme files.**

Set `pp_footer_bg`, `pp_footer_text`, and `pp_footer_link_color` to any CSS color the style-slot color validator already accepts (hex, `rgb()`/`hsl()`, `transparent`, `currentColor`, or a known color-token reference like `var(--color-accent)`) for a dark band with readable text and links; they render as inline `--footer-*` custom properties, so an unset footer looks exactly as before. Set `pp_footer_blurb` for a short brand line under the logo, `pp_footer_contact` for an address/email block (newlines become line breaks), and `pp_footer_copyright` to replace the default copyright line (empty keeps the default). Colors go through the same shared validation engine as every other slot color — there is no second, footer-specific validator — and every option round-trips through snapshot/restore, so a rollback restores or clears each one cleanly. This is a tight dark-footer surface, not a general footer builder. Logo sizing is unchanged (that was #299).

### Added

- Six `pp_footer_*` site options (`update_site_option`) turn the footer into a dark marketing footer: `pp_footer_bg` / `pp_footer_text` / `pp_footer_link_color` (CSS colors, validated by the shared color engine, emitted as inline `--footer-*` custom properties) and `pp_footer_blurb` / `pp_footer_contact` / `pp_footer_copyright` (text; empty copyright keeps the default line). Unset, the footer renders identically to before (#300).

### Docs

- `AI_CONTEXT.md`, `ai-instructions/set-logo.md` (new dark-footer section + options table), `ai-instructions/website-building.md`, the `update_site_option` action description, `components/footer/schema.json` + `README.md`, and the root `README.md` document the new footer options and that colors reuse the shared style-slot color grammar (#300).

### Tests

- `FooterChromeTest` pins the whitelist + `color` type, color validation delegating to the shared `_pp_validate_color` engine (accepts hex/rgb/hsl/`transparent`/`currentColor`, rejects non-colors), string options, the write + round-trip, the rendered inline `--footer-*` properties, blurb/contact/copyright rendering and escaping, byte-identical unset output (no style attr, default copyright, no `text-muted` class), the CSS `var(--footer-*, <literal>)` consume-plus-fallback contract (which the schema-slot guard does not cover), the untouched #299 logo cap, and that `base.php` maps every option; `ActionsTest` pins the snapshot/restore round-trip with delete-on-empty for an unset footer-color baseline (#300).

## [v0.16.111] — 2026-07-13 — the footer logo is now capped to a sane height, so a real wordmark no longer renders near full size and dominates the footer (#299)

**Setting a real logo image (via `pp_logo_id` + `pp_footer_show_logo`) used to blow up the footer: a 664×150 wordmark rendered at roughly 491×111 px because the footer logo `<img>` had no size cap at all, while the header logo has always been constrained to a sensible height. The footer is template-owned with no style slots, so there was no way to fix it from the outside. This release adds a height cap to the footer logo that matches the header treatment, so any real-world logo aspect ratio renders at a reasonable size.**

The footer logo image is now capped the same way the header logo is (`max-height: 2.5rem`, width auto, aspect preserved). A tall or wide wordmark is scaled down to footer proportions instead of rendering near its intrinsic size. Nothing about how you set the logo changes: `pp_logo_id` still sets the image and `pp_footer_show_logo` still turns the footer logo on. This is a pure styling fix with no new options.

### Fixed

- The footer logo now has a height cap consistent with the header logo, so a real logo image (e.g. a 664×150 wordmark) renders at footer scale instead of near its intrinsic size and dominating the footer (#299).

### Tests

- `LogoTest` pins the footer logo CSS contract: the `.site-footer__logo-image` rule caps height, keeps width auto, and preserves aspect; its cap matches the header logo cap so the two treatments cannot silently drift apart; and the rendered footer `<img>` actually carries the capped class so the rule cannot become dead CSS (#299).

## [v0.16.110] — 2026-07-13 — a `section` can now put text beside a styleable content panel with its own heading, list, and CTA, so the asymmetric "text + supporting card" marketing layout is native (#104)

**`section` could only do two columns as text + image (`layout: image-left`/`image-right`). The high-conversion pattern where a text column sits beside a contained panel — a card with its own sub-heading, checklist, and call-to-action button — had no native form, so multi-element sections collapsed into one stacked centered block, a visible drop in polish. This release adds `layout: "text-panel"`: the left column is the normal section (eyebrow/title/body), the right column is a server-validated content panel built entirely from props — no nested components, so the "components never nest components" rule holds.**

The panel is described with flat props (`panel_heading`, `panel_body`, `panel_items[]`, `panel_cta_text`/`panel_cta_url`, `panel_cta_variant`), validated by the same shared engine as every other component (unknown keys rejected by the prop-key gate, the CTA URL escaped through `esc_url`, list items escaped and non-string entries dropped). The panel renders only when it has real content (a heading, a body, list items, or a complete CTA); otherwise the section degrades to `text-only` so authored content is never silently lost. Six new `--section-panel-*` style slots (background, border color, border width, radius, padding, text color) make the panel styleable per instance — set a dark background and light text for the dark-panel-beside-light-text pattern. The columns sit side by side at 768px and up and stack text-then-panel on mobile. The panel uses its own CSS classes so the desktop premium-typography cascade cannot override its text on a dark panel. The panel CTA reuses the shared button primitive and its `panel_cta_variant` (primary/secondary/outline/ghost); it deliberately does not add its own button-color slots, so the site's primary-button color stays the single source of truth.

### Added

- `section` gains `layout: "text-panel"` — a two-column layout with a styleable content panel on the right, built from props (`panel_heading`, `panel_body`, `panel_items[]`, `panel_cta_text`, `panel_cta_url`, `panel_cta_variant`) with no nested components. Six `--section-panel-*` style slots (bg, border-color, border-width, radius, padding, text) style the panel per instance; the panel falls back to `text-only` when it has no content, stacks text-then-panel on mobile, and keeps its text controllable on a dark panel (#104).

### Docs

- `AI_CONTEXT.md` (section now lists five structural layouts and the panel props; the style-slot total tracks the six new slots), `README.md`, and `ai-instructions/composition.md` (new `text-panel` section with the panel-props table and a worked example) document the layout, its props, and the panel style slots (#104).

### Tests

- `SectionTextPanelTest` pins the render of both columns, the panel list (with non-string/empty entries skipped), the CTA rendering only when both label and URL are set, variant class selection and invalid-variant fallback, URL escaping, heading/body/item escaping, the fallback to `text-only` for heading-only / items-only / CTA-only / body-only / empty panels, non-array `panel_items` coercion, the six panel style slots validating through the shared engine, an unknown panel prop still being rejected, and the CSS contract (every panel slot consumed with a fallback, list markers restored, columns top-aligned at desktop); `SchemaValidationTest` and the CSS-lint slot counts track the new slots (#104).

## [v0.16.109] — 2026-07-13 — per-card grid `style` now rejects the section-scoped slots that silently did nothing on a card, so an override either renders or fails loudly (#323)

**Per-card style (`props.items[].style`, shipped in v0.16.108) accepted every grid style slot, but only the slots consumed on the card itself actually render there. Setting a container or heading slot on one card — `--grid-gap`, `--grid-bg`, `--grid-heading-color`, `--grid-padding-*` — validated, persisted, reported success, and changed nothing, because those slots are read on the section, list, and header, not the card. That is the reported-success-without-effect trap the product works to eliminate. This release restricts `items[].style` to the card-scoped slots and rejects the rest with the existing `invalid_style_slot` error, which now names the card and points you at the grid-level `style` where those slots belong.**

The card-scoped set is declared once in the grid schema (an `item_eligible` flag per slot) and enforced by the same shared validator that already checks per-card style (`_pp_validate_style_slot_map` via `pp_validate_composition`) — no second validator and no new slot grammar, just one membership check on the per-item path. Grid-level `style` is unchanged: the section is where container and heading slots render, so they stay valid there. The card-scoped set covers the card background, border, radius, shadow, padding, gaps, the card/featured bar and texture slots, the item title and text colors, and the bullet, link, and step-badge colors. The AI-facing docs and the runtime AI catalog derive their card-scoped list from the same schema flag, so an operator is told exactly which slots work per card before writing one.

### Fixed

- `items[].style` on a grid card now rejects section-scoped and heading-scoped slots (`--grid-gap`, `--grid-bg`, `--grid-heading-*`, `--grid-eyebrow-*`, `--grid-subheading-color`, `--grid-padding-*`) with `invalid_style_slot`, naming the card and directing you to set them on the grid-level `style`. Only the card-scoped slots — the ones that actually render on a single card — are accepted, so a per-card override can no longer report success while changing nothing (#323).

### Docs

- `components/grid/schema.json` marks each card-scoped slot with `item_eligible` and its `items[].style` description lists exactly the accepted set; `ai-instructions/composition.md` and `ai-instructions/style-component.md` now say container/heading slots are rejected per card rather than merely ineffective; the runtime AI catalog (`lib/ai-context.php`) derives the accepted per-card slot list from the schema flag so its guidance cannot drift (#323).

### Tests

- `SchemaValidationTest` pins that every card-scoped slot is accepted and every container/heading slot is rejected on `items[].style` (both sets derived from the schema), that the rejection names the card and points at grid-level style, that grid-level `style` still accepts container slots, that index 0 (the featured first card) is enforced (strict null-check regression), that an un-annotated component falls back to the pre-323 behavior, and that the schema description stays in sync with the `item_eligible` flags (#323).

## [v0.16.108] — 2026-07-13 — one card in a grid row can finally look different from its siblings: per-card `style` overrides make a dark CTA panel or a green terminal card natively expressible (#306)

**Grid style slots were grid-scoped: `--grid-card-bg`, `--grid-card-border`, the card text colors, and mono styling all applied to every card in the row. There was no way to style one card differently, so a standard marketing pattern — a dark CTA panel beside light checklist cards, or a green-on-dark terminal/code card next to normal content — could not be matched, and operators were pushed toward the workarounds the product forbids. This release adds an optional per-card `style` object on grid items (`props.items[].style`): it accepts the same grid style slots, is validated by the same shared engine, and renders as inline CSS custom properties on that one card so it overrides the grid-level value by cascade proximity.**

Per-card style goes through the one shared validation path (`_pp_validate_token_value` via `pp_validate_composition`) exactly like grid-level slots — unknown slot names and invalid values are rejected the same way, with no second validator and no new slot grammar. A new shared helper (`_pp_validate_style_slot_map`) backs both grid-level and per-item validation, and the detection is schema-driven: a prop declared as an array whose item sub-schema declares a `style` field (today, the grid's `items`) gets per-item validation, so nothing else silently gains the surface. The card renders the map through the existing `pp_render_style_vars` escaping. Use the card-scoped slots (`--grid-card-*`, `--grid-item-*`, bullet/link/step colors); container and heading slots belong on the grid-level `style` because they are read on the section, not the card. Set per-card style through the composition (`update_component` / `update_composition` / `create_page`), not `style_component`, which targets a whole component instance.

### Added

- Grid items accept an optional per-card `style` object (`props.items[].style`) that overrides grid-level style slots for that one card. Accepts the same grid style slots, validated by the same shared engine (unknown slots and invalid values rejected identically), rendered as inline custom properties on the card's `.grid__item`. Makes the dark-panel card and the green terminal card natively expressible in a mixed grid row (#306).

### Docs

- `components/grid/schema.json`, `ai-instructions/composition.md`, and `ai-instructions/style-component.md` document per-card `style`, which slots are card-scoped, and that it is set through the composition rather than `style_component`; `AI_CONTEXT.md` lists `style` in the grid item fields and the runtime AI catalog (`lib/ai-context.php`) surfaces a per-item style note (#306).

### Tests

- `SchemaValidationTest` pins that per-card style accepts known grid slots (including the dark-panel and terminal cases), rejects unknown item slot names (`invalid_style_slot`, naming the card index), rejects invalid and injection values (`invalid_style_value`), and coexists with grid-level style; `GridItemStyleTest` pins that the override renders inline on the correct card only, wins over a grid-level slot, and that unknown/injection values are dropped at render (#306).

## [v0.16.107] — 2026-07-13 — a call-to-action can finally be just a button: `cta.title` is now optional, so a heading-less standalone button is one component away (#294)

**There was no sanctioned way to render a standalone button — a button not attached to a heading. `cta.title` was required, and links written into `section.body` render as plain text with no button treatment, so a common marketing pattern (a centered "closing" button after a steps or feature section) could not be matched. This release makes `cta.title` optional: omit it (and `text`) and the CTA renders just its button row, with no empty heading element in the DOM. `button_text` and `button_url` stay required, and `id`/anchors, `layout`, `theme`, and every style slot keep working unchanged.**

The required-title rule was relaxed in the one shared validation engine (`pp_validate_composition`), driven by the component schema's `required` flag rather than a CTA special case, so there is no second validation path. The CTA template now emits the `.cta__text` wrapper only when an eyebrow, title, or body is present, so a title-less CTA produces no bare `<h2>` and no stray flex gap above the button. A CTA that supplies a title renders byte-identically to before. The change reuses the validated CTA surface and shared engines, so no new component or schema was added.

### Added

- `cta.title` is now optional. A CTA with only `button_text` and `button_url` renders a standalone button row (no heading element), the sanctioned heading-less button pattern for closing buttons after a steps or feature section. `id`/anchor and all CTA style slots still apply (#294).

### Docs

- `AI_CONTEXT.md`, `README.md`, and `ai-instructions/composition.md` now describe `cta.title` as optional and document the title-less CTA as the standalone-button pattern; the runtime AI catalog (`lib/ai-context.php`) reflects the change automatically from the schema (#294).

### Tests

- `ComponentPropsTest` pins the title-less render (button present, no `<h2>`, no `.cta__text` wrapper, `id` anchor preserved) and that an eyebrow-only or titled CTA still renders the wrapper; `SchemaValidationTest` pins that a title-less CTA passes `pp_validate_composition` while a CTA missing `button_text` or `button_url` is still rejected (#294).

## [v0.16.106] — 2026-07-13 — a checklist written in a section body finally renders as a list: markers and indent are restored inside `.section__content` (#295)

**Any `<ul>`/`<ol>` an author or the AI wrote into `section.body` rendered as bare, flush-left, unmarked lines — visually indistinguishable from a stack of one-line paragraphs. The global reset (`base.css` `*{padding:0}` plus `ul,ol{list-style:none}`) stripped both the marker and the indent, and no rule inside the section rich-text surface restored them. Every marketing page with a "why us" checklist hit this in the one long-form body surface the product offers. This release restores default list rendering (disc/decimal markers, indent, and item rhythm) scoped to `.section__content`, where the section body HTML actually renders.**

The fix re-declares `list-style`, `padding-left`, and `margin` for `ul`/`ol`/`li` under `.section__content` — the inner wrapper that carries `wp_kses_post($body)` in `section.php`. The `.section__content ul` selector (specificity 0,1,1) outweighs the base `ul,ol` reset (0,0,1), so the markers come back without `!important`. Indent and rhythm route through the existing `--space-*` tokens (`--space-lg` indent, `--space-md` list margin, `--space-xs` item gap), matching the repo's spacing idiom. The rule is scoped to `.section__content`, which renders only the author rich-text body — no PP component nests there — so there is no blast radius to other components. No style slot governs list markers or padding here, so no slot routing and no waiver-ledger change; no new prop, token, grammar, or action surface was added, and the AI-facing docs describe no list-rendering behavior that this changes.

### Fixed

- Lists authored in `section.body` (`<ul>`/`<ol>`) now render with markers and indent inside `.section__content` instead of as bare flush-left lines. `ul` gets `disc`, `ol` gets `decimal`, with token-based indent and item spacing (#295).

### Tests

- `SectionBodyListTest` pins the restore: `.section__content ul`/`ol` marker declarations (`disc`/`decimal`), token-based `padding-left` indent and `li` rhythm, the `base.css` reset that is the cause, and that the section renderer places body HTML inside `.section__content`. The four fix-pinning assertions are red on the pre-fix tree (#295).

## [v0.16.105] — 2026-07-13 — the hero proof line can finally be lifted off a dark hero: it has its own color slot instead of a hardcoded muted token (#296)

**A hero's proof line — the small trust signals rendered below the CTA — shipped in a hardcoded `var(--color-muted)` with no per-instance color slot. On any dark hero (`--hero-bg` set to a dark color or gradient) that produced a low-contrast, dark-on-dark line that no supported action could fix, while `validate page` and `check page` both passed. This is the same "a literal token defeats per-instance theming" family as #222/#248/#292. This release adds a `--hero-proof-color` style slot so an operator can set the proof color to a light value on a dark hero.**

The base `.hero__proof` rule now routes its color through `var(--hero-proof-color, var(--color-muted))`, the same `var(--slot, <literal>)` idiom the rest of the hero color slots use. Unset output is byte-identical to before — the proof line keeps rendering in `--color-muted` until an operator sets the slot — so nothing about the shipped pages changes on upgrade. The proof line has a single color declaration (no premium, inverted-theme, or media-query rule re-declares it), so the one base rule is the whole surface. The slot is enforced live by the existing style-slot contract test, which auto-discovers every schema slot and fails the build if one is not consumed by the CSS or is bypassed by a literal. No new prop, token, recipe, or action surface was added.

### Fixed

- The `hero.proof` line now honors a `--hero-proof-color` style slot (default `var(--color-muted)`). Set it per instance with `style_component` to give a dark hero a readable, light-colored proof line instead of the fixed dark-on-dark muted token. Byte-identical when unset (#296).

### Docs

- `AI_CONTEXT.md` and `README.md` style-slot totals updated from 152 to 153 (`hero` 36 → 37) to match the schemas (#296).

### Tests

- `StyleSlotContractTest` pins the `var(--hero-proof-color, var(--color-muted))` base-rule fallback for byte-identical unset output; `SchemaValidationTest` and `OperateTest` hero slot-count pins and the `css-lint` subset count bump from 36/111 to 37/112 in lockstep with the new slot (#296).

## [v0.16.104] — 2026-07-13 — the grid's featured first-card remnants (accent top bar, texture stripe, glow) are finally slot-controllable: uniform card rows are one recipe away (#293)

**Every 3-up feature row used to render with an unremovable "first card is special" statement: after #226 and #292 fixed the border and background slots, the featured first card still painted a 4px accent top bar, a blue texture stripe, and an inset blue glow from hardcoded literals no slot could reach. A neutral, uniform card row — the most common marketing section there is — was impossible through supported mechanisms. This release puts all three remnants behind style slots and ships a `uniform-cards` recipe that neutralizes the treatment in one step.**

Four new grid slots follow the `var(--slot, <literal>)` idiom with byte-identical unset output. `--grid-card-bar-color` and `--grid-card-bar-height` control the card top bar on EVERY card with two-tier defaults (2px hairline on cards 2..N, 4px accent gradient on the featured card); they are shared rather than featured-only because the slot-contract guard collapses pseudo-classes onto the base subject, so featured-only bar slots would have flagged the base hairline literals as unwaivable bypasses — height `0` removes the bar everywhere. `--grid-featured-texture-color` slots the texture stripe's line color (`transparent` removes it), and `--grid-featured-shadow` chains ahead of the shared `--grid-card-shadow` at both the desktop and the previously unlisted mobile featured-glow rule, so the slot cannot silently no-op below 768px. The featured rules moved from the late cascade into the `COMPONENT: grid` block (cascade-equivalent — nothing else styles `.grid__item::before`, and the `:first-child` rule outranks the late base rules by specificity in either order) so the guard's in-block consumption check enforces the new slots. The `uniform-cards` recipe pins one shared bar (removed), texture (off on card 1), shadow, border, background, and title size; the residual differences it cannot reach (the faint 0.028-alpha texture on cards 2..N, a 0.25rem featured body padding-top at desktop, the dark-theme lift) are named in the recipe description rather than silently left behind.

### Added

- Grid slots `--grid-card-bar-color`, `--grid-card-bar-height`, `--grid-featured-texture-color`, and `--grid-featured-shadow`, plus the `uniform-cards` recipe. Set the three neutralizers (bar height `0`, texture `transparent`, one shared `--grid-card-shadow`) or apply the recipe for a uniform card row; the featured treatment stays the unset default (#293).

### Docs

- `AI_CONTEXT.md` and `README.md` slot totals updated from 148 to 152 (`grid` 24 → 28, breakdown reordered); `ai-instructions/style-component.md` documents the featured-treatment slots and the new recipe (10 → 11 recipes) (#293).

### Tests

- `SchemaValidationTest` covers the accepted neutralizers and typed values plus four rejected cross-type shapes for the new slots, and pins the `uniform-cards` recipe to declared slots with type-valid values. `StyleSlotContractTest` pins the two-tier fallback literals and the mobile featured-shadow chain. Four rendered E2E tests prove the unset featured defaults survive the rule move, the recipe renders a uniform row at desktop and mobile, `--grid-featured-shadow` has discriminating rendered power at both breakpoints, and the bar slots pin one identical bar on featured and plain cards (#293).

## [v0.16.103] — 2026-07-13 — `stats` and `faq` can finally follow page rhythm and type scale: padding and heading-size slots, matching the other section components (#304)

**The `stats` and `faq` components now expose the same box-model and typography style slots the other section-level components already had, so a full page can be tuned to one consistent vertical rhythm and heading scale. Until now `stats` and `faq` declared color and background slots (added in #100) but no padding or heading-size slots, while `hero`, `section`, `grid`, and `cta` declared all of them. That gap meant any attempt to tighten a page's band spacing or dial its title scale left two components stranded at their built-in values. This release adds `--stats-padding-top`, `--stats-padding-bottom`, `--stats-title-size`, `--faq-padding-top`, `--faq-padding-bottom`, and `--faq-heading-size`, closing the schema asymmetry.**

Each new slot routes through the same `var(--slot, <literal>)` idiom the padding and title-size slots on `section`/`grid`/`cta` already use, at every layer the components render through: the base rule, the desktop premium typography and rhythm rules, the mobile rhythm rules, and the adjacent-sibling spacing rules. Unset output is byte-identical to before, so nothing about the shipped pages changes until an operator sets a slot. Because `stats` and `faq` render their headings at the plain `h2` base size (they have no desktop premium type rule of their own, unlike `section`/`grid`), the two heading-size slots fall back to that `1.875rem` base rather than `inherit`, keeping the resting scale exactly as it renders today. The slots are enforced live by the existing style-slot contract test, which auto-discovers every schema slot and fails the build if one is not consumed by the CSS or is bypassed by a literal re-declaration. No new prop, token, recipe, or action surface was added.

### Added

- `stats` gains `--stats-padding-top`, `--stats-padding-bottom`, and `--stats-title-size`; `faq` gains `--faq-padding-top`, `--faq-padding-bottom`, and `--faq-heading-size`. Set them per instance with `style_component` to control each band's vertical rhythm and heading scale, matching the padding and title-size slots already on `hero`/`section`/`grid`/`cta`. Byte-identical when unset (#304).

### Docs

- `AI_CONTEXT.md` and `README.md` slot totals updated from 142 to 148 (`faq` 10 → 13, `stats` 6 → 9) to match the schemas (#304).

**A `grid` with a single item no longer sits in the left half of a two-column track with empty space on the right. Until now a one-item card fell through to the generic two-column desktop rule, so the lone card rendered at roughly half the container width and stranded a matching gap beside it — the natural "panel" composition (an audience box, a terminal mock, a single highlighted card) always looked half-finished. This release gives the single-item grid its own full-width track, so the card spans the section container and its left edge sits on the same rail as the heading and intro copy above it.**

A new `data-pp-count="1"` rule in `assets/css/components.css` sets one full-width column (`minmax(0, 1fr)`) from the 768px breakpoint up. It takes effect from the first two-column breakpoint rather than only at desktop, because the stranding appears the moment the base rule switches to two columns; nothing above 768px re-declares it, so the full-width track holds through tablet and desktop. This completes the per-count grid family alongside the two-card (#303) and three-card (#224) fixes and follows the same "span the container, align with the section rail" direction. The multi-card layouts are untouched — two cards still lay out two across, three across three, four as a two-by-two — and mobile stays a single stacked column. CSS-only, with no new style slot, prop, token, or schema surface, so no composition, action, or AI-facing behavior changed.

### Fixed

- A single-item `grid` renders one full-width track at tablet and desktop widths instead of a two-column track that stranded the lone card in the left half with dead space on the right, so panel-style single-card grids fill the row and align with the section rail. Completes the per-count grid family alongside #303/#224; CSS-only, no new slot (#297).

### Tests

- `tests/js/css-lint.test.js`: new pins assert the single-item desktop rule resolves to one full-width track (`minmax(0, 1fr)`), spans the container (no `max-width` / `max-inline-size` / `width` narrowing, no `auto` inline margins), is declared exactly once, and — selector-agnostically — that no count-1 rule anywhere reintroduces a multi-column track (#297).
- `tests/ComponentPropsTest.php`: the grid item-count attribute pin now also asserts a one-item grid emits `data-pp-count="1"`, so the CSS rule's matching contract cannot silently break (#297).

## [v0.16.101] — 2026-07-13 — A two-card grid now lines up with the section above it instead of floating in a narrower centered block (#303)

**A `grid` with two cards no longer renders on its own narrower, centered rail. Until now a two-item card row was capped at 832px and centered with automatic side margins, so an intro paragraph on the section rail and its paired two feature cards visibly belonged to two different alignment systems — the cards started well to the right of the heading above them. The three-card row already spanned the full section container (fixed in #224); this release gives the two-card row the same treatment, so "intro text plus two feature cards" reads as one aligned section.**

The two-card desktop rule in `assets/css/components.css` dropped its `max-width: 52rem` cap and its `auto` left/right margins, so the row now fills the section container and its left edge sits on the same rail as the section heading and intro copy. The two-column layout itself is unchanged: two cards still lay out side by side, and the mobile and tablet breakpoints (single column below 768px, two columns from 768px) are untouched — only the desktop centering-and-narrowing was removed. This mirrors the recorded direction on the issue and the shipped #224 three-card fix: a CSS-only change with no new style slot, prop, token, or schema surface, so no composition, action, or AI-facing behavior changed.

### Fixed

- A two-item `grid` card row spans the section container and aligns with the section rail at desktop widths, instead of being capped at 832px and centered — so an intro section and its paired two-card grid read as one aligned block. Mirrors the three-card treatment from #224; CSS-only, no new slot (#303).

### Tests

- `tests/js/css-lint.test.js`: new pins assert the two-item desktop rule spans the container (no `max-width` / `max-inline-size` / `width` narrowing and no reintroduced `auto` inline margins) and is declared exactly once; the existing two-across column pin is retained (#303).

## [v0.16.100] — 2026-07-13 — A rejected write now carries its machine-readable error code on every path, not just some: validate-stage rejections match execute-stage rejections (#312)

**Every action envelope documents an `error_code` alongside the human `error` message so a client can key on the code (`composition_conflict` → reload prompt, `unknown_prop` → point at the field) instead of parsing prose. Execute-stage rejections carried it; the validate-stage early-return in `pp_execute_action()` did not. It hand-built its envelope and omitted `error_code` entirely, so a whole class of rejections — a template-owned component in the body (`template_owned_component`), duplicate component ids (`duplicate_component_id`), a missing required prop (`invalid_composition`), and the new unknown prop key (`unknown_prop`) — reached the dashboard save handler and the AI chat with an empty code. Callers could only string-match the message. This release propagates the validating `WP_Error`'s code into that envelope, so validate-stage rejections now carry the same machine-readable `error_code` as execute-stage rejections, matching the uniform `{ok, error, error_code}` shape the action model already documents.**

Low severity — the human message was always present and clear — but it defeated the machine-readable-code contract for every rejection surfaced by an action's `validate` callback, and the action envelope is a public AI-facing surface worth fixing before v1.0.0 freezes it. The fix is one line: the early-return envelope adds `'error_code' => $validation->get_error_code()`, mirroring the `_pp_action_error()` helper that every other rejection path already routes through. No accepted grammar, action behavior, rendered output, or documented shape changed; the code now matches the canonical shape `AI_CONTEXT.md` already describes.

### Fixed

- `pp_execute_action()`'s pre-execute validation rejection now includes `error_code` in its result envelope, propagated from the validating `WP_Error`, so validate-stage rejections (`template_owned_component`, `duplicate_component_id`, `invalid_composition` for a missing required prop, and `unknown_prop`) carry a machine-readable code to the dashboard save handler and AI chat instead of an empty one — consistent with execute-stage rejections built by `_pp_action_error()` (#312).

### Tests

- `tests/ActionsTest.php`: dedicated coverage asserts `error_code` is populated for each validate-stage rejection class — `template_owned_component`, `duplicate_component_id`, `invalid_composition` (missing-required), and `unknown_prop`; the existing `update_component` / `add_component` / `update_composition` unknown-prop tests were strengthened from asserting the message only to also asserting the code (#312).

## [v0.16.99] — 2026-07-13 — An unknown component prop no longer saves clean and does nothing: the composition validator rejects prop keys not in the component's schema (#147)

**Set a prop that a component does not have — a typo like `titel`, or a field the component never defined — and until now the write reported `ok: true`, the key persisted in `_pp_composition`, and the renderer silently ignored it. The change looked applied and wasn't. That is the same "reported success without real effect" failure the v1.0.0 gate exists to eliminate (issue 302 on the style axis), reachable here on the props axis through every write path. Issue 120 had already closed it for the `wp pp patch <selector>` CLI entry point, but only there. This release closes it at the choke point: `pp_validate_composition()` now rejects any composition whose component carries a prop key not declared in that component's `schema.json` `props`, with a distinct `unknown_prop` error, so `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail loudly instead of persisting a dead key.**

The source of truth is each component's full `schema.json` `props` contract, not the curated `pp_get_component_fields()` CLI-patch editability subset, so real props the subset omits (`cta.theme`, `cta.background_image`, `cta.button_variant`, and the like) are still accepted while a misspelled or invented key is not. The rule lives in the shared validation engine, so it flows automatically into `restore_composition` and preview findings (issue 233): a legacy composition that already carries an unknown key still restores byte-for-byte and reports `unknown_prop` as a finding rather than blocking undo. The `table` component's `schema.json` gained the `id` prop it already renders as an anchor and that the composition writer injects into every component on save — without it, a saved table would have false-rejected on its next validated write; a new invariant test pins that every composable component declares `id` so a future component cannot reintroduce that trap. Every shipped fixture and the default homepage seed pass the new check unchanged; no accepted prop, action behavior, or rendered output changed.

### Fixed

- `pp_validate_composition()` rejects a component prop key not declared in that component's `schema.json` `props`, with the `unknown_prop` error code, across every write path (`create_page`, `update_composition`, `add_component`, `update_component`, dashboard save). An unknown key can no longer persist behind an `ok: true` while the renderer ignores it (#147).
- `components/table/schema.json` declares the `id` prop the `table` component already renders as an anchor id and that `pp_update_composition()` injects on every save, so a saved table no longer false-rejects on its next validated write (#147).

### Docs

- `AI_CONTEXT.md`, `lib/ai-context.php` (runtime AI context), `ai-instructions/composition.md`, and `docs/reference-apply-cli.md` document the prop-key contract: only schema-declared props are accepted, unknown keys are rejected with `unknown_prop`, and restore reports rather than blocks (#147).

### Tests

- `tests/SchemaValidationTest.php`: unknown prop key rejected with `unknown_prop`; every composable component validates with all its declared props; missing-required-prop wins first-error order over unknown-prop; unknown-prop wins over invalid-style-slot; the default homepage seed passes; `table` accepts `id` and still rejects unknown keys; and every composable component declares `id` so the injected id never false-rejects (#147).
- `tests/ActionsTest.php`: `update_component`, `add_component`, `update_composition`, and `create_page` reject an unknown prop key without persisting; `restore_composition` and its preview surface the `unknown_prop` finding without blocking undo (issue 233 rider) (#147).

## [v0.16.98] — 2026-07-13 — The 21 remaining dead style-slot pairs take effect; the issue 309 ledger burns down to 6 documented permanent waivers (#309)

**The audit that shipped with v0.16.97's slot-contract guard found 27 slot/surface pairs (56 literal declarations) across hero, section, grid, cta, and testimonials still dead by the issue 302 mechanism: the slot validated, `style_component` reported Success, and a later or higher-specificity literal re-declaration in `assets/css/components.css` won the cascade anyway — on exactly the rhythm and scale surfaces (card padding, title sizes, grid gap, hero padding, CTA width) the v0.16.94 RC dogfood could not control. This release routes 21 of those pairs (49 declaration instances) through `var(--slot, <literal>)` with the former literal as the fallback, so a declared slot now takes effect while unset output renders byte-identical to before. The other 6 pairs are the two decision-flagged groups the issue 309 decision names as permanent, documented waivers: the grid link `:hover` state (which must visually override the slotted resting color) and the testimonials `--stack` variant resets (a by-design card-less presentation whose padding/background/border/shadow resets exist to neutralize the card slots).**

The fix is the established issue 226/292/302 idiom applied mechanically to every inventoried literal: hero padding and title size at desktop plus content-width and surface-padding at their base/split/mobile rules, the section centered/text-only width and title-size literals (including the 49rem text-only cap that held the inner `.section__content` at 784px in a real browser while the slot said 700px), the premium grid gap/padding/radius/shadow/title-size literals, the per-page `#*-cta` ID rules' title/body/gap/width literals across the four benchmark closers, and the two elevation-correction `border-color` declarations that killed all four CTA button border slots at once through their chained `var()` fallbacks (routed as `var(--cta-button-border, var(--cta-accent, <literal>))` mirroring the base chain). The shrink-only `KNOWN_DEAD_SLOT_WAIVERS` ledger drops from 27 entries/56 instances to 6 entries/7 instances, each surviving entry carrying its design-intent rationale inline; the exact-count pins moved in the same change, and the previously commented-out inner `.section__content` computed-style assertion in the E2E width pin is now enabled. No accepted grammar, action behavior, or schema surface changed.

### Fixed

- Hero `--hero-padding-top/bottom` (desktop), `--hero-title-size` (desktop), `--hero-content-width` (base + split), and `--hero-surface-padding` (mobile) take effect: the bare re-declarations in `assets/css/components.css` route through their slots (#309).
- Section `--section-body-width` on `.section--centered .section__body`, `.section--text-only .section__content`, and the premium 49rem text-only cap, plus `--section-title-size` on the text-only literal, take effect (#309).
- Grid `--grid-gap` (three premium gap literals), `--grid-card-padding` (base tablet + premium desktop/mobile), `--grid-card-radius` (shared 4px rule), `--grid-card-shadow` (all-cards + featured + mobile literals), and `--grid-item-title-size` (five premium literals including the featured 1.22rem) take effect (#309).
- CTA `--cta-title-size`, `--cta-content-width` (shared heading cap + five `#*-cta` literals), `--cta-body-size`, `--cta-inner-gap`, and all four button border slots (`--cta-button-border`, `--cta-button-hover-border`, `--cta-accent`, `--cta-accent-hover` — two shared elevation-correction declarations routed through the chained fallbacks) take effect (#309).

### Tests

- `tests/StyleSlotContractTest.php`: the issue 309 `KNOWN_DEAD_SLOT_WAIVERS` ledger shrinks 27→6 entries (56→7 declaration instances); the 6 survivors are the two decision-flagged permanent-waiver groups, each documented inline with its interaction/variant-semantics rationale; the exact-count pins updated in the same change (#309).
- `tests/e2e/style-render.spec.ts`: the inner `.section__content` assertion in the `--section-body-width` rendered pin is enabled — the content box that measured a dead 784px now must compute to the slotted 700px (#309).

## [v0.16.97] — 2026-07-12 — Slot-contract enforcement in CI: dead style slots now fail the build instead of shipping (#305)

**A schema-declared style slot that silently did nothing shipped three separate times (#226, #292, #302): validation accepted the value, `style_component` reported Success, the inline `style` attribute carried the custom property — and a later literal CSS re-declaration won the cascade anyway. Nothing enforced the slot contract, so every layer stayed individually green while the promise "declared surface → accepted input → reported success → real effect" broke invisibly. This release adds the enforcement: a generalized, auto-derived slot-contract guard in the PHPUnit suite and rendered computed-style proof in the E2E suite. The guard is fail-closed — a new schema slot with no CSS consumer, a literal re-declaration that defeats a consumed slot (including shorthand resets like `padding:` killing a `padding-top` slot), or a stylesheet rule that re-declares a slot custom property now fails CI instead of shipping a fourth incident.**

The unit guard derives its contract from the code, not a hand-maintained list: components are auto-discovered from `schema.json`, every `var(--slot)` consumption in `assets/css/components.css` yields a (subject, property, slot) triple, and every same-subject re-declaration of that property must route through the slot (or a type-compatible sibling slot — the intentional `.faq__item[open]` accent handoff). Run against the pre-#292/#302 tree, the guard reports every slot those issues fixed; a fixture self-test pins the detection of each mechanism (later bare re-declaration, higher-specificity literal, shorthand reset, cross-type laundering) permanently in CI. The audit the guard performed on landing found 27 slot/surface pairs still dead by the same mechanism — filed as issue 309 with per-pair evidence and waived in a shrink-only, exact-count ledger: fixing a pair without removing its waiver fails, adding a waiver without touching the ledger-size pin fails. Four Playwright pins apply real slots through the live `style_component` action and assert `getComputedStyle` — padding, heading size, card border on the featured AND non-featured card (the #292 surface, proven RED on the pre-fix tree), and body width past its wrapper (the parent-cap shape no textual scan can prove). No accepted grammar, action behavior, or rendered output changed; this release is enforcement only.

### Tests

- `tests/StyleSlotContractTest.php` generalizes the keystone: auto-discovered styled components (a new component's slots are checked the moment its schema declares them), the auto-derived bypass guard with the issue 309 shrink-only waiver ledger and exact ledger-size pins, a fixture self-test proving the guard detects the dead-slot class (not just today's instances), a guard against stylesheet rules re-declaring slot custom properties (which would defeat the renderer's inline style), and a fail-fast on `:is()`/`:where()` so the subject parser must be upgraded before those features are introduced (#305).
- `tests/e2e/style-render.spec.ts` adds four `@smoke` computed-style pins that exercise the REAL `style_component` action end-to-end: `--section-padding-top`, `--grid-heading-size`, `--grid-card-border` on both the featured and non-featured card (color, width, and style asserted so an invisible border cannot pass), and `--section-body-width` through the `.section__body` wrapper (#305).

### Docs

- `README.md` no longer overclaims that the contract test "guarantees every style slot actually reaches the page" — it now describes the enforced contract precisely and links the issue 309 ledger of known, tracked gaps (#305).

## [v0.16.96] — 2026-07-12 — Section/grid/CTA padding, heading size, and body width style slots take effect again (#302)

**Setting `--section-padding-*`, `--grid-padding-*`, `--cta-padding-*`, `--section-title-size`, `--grid-heading-size`, or `--section-body-width` on a component validated, reported success, and changed nothing on the rendered page. A late "premium" layout/typography layer in `assets/css/components.css` re-declared each of those properties with a bare literal (a `clamp()`, a `40rem`, a `5.75rem`) at higher specificity or later source order, so it outranked the base rules that already routed through the slot. This generalized the same dead-slot bug fixed for one property in issue 292 (border color) across the whole padding and typography axis of every marketing page, and it blocked an AI operator from tuning vertical rhythm, title scale, or body width even though all three slots reported the change landed. Every premium re-declaration now routes through `var(--slot, <literal>)` with the former literal as the fallback, so a declared value takes effect while an unset slot renders byte-identical to before. The one deliberate visual change: the base two-tier vertical rhythm the premium layer had flattened is restored, so stacked components on desktop sit 32px apart again instead of a uniform 80px.**

The fix is the idiom issue 226 established and issue 292 last applied: route the literal through the style slot with the premium value as the fallback. It covers each in-scope slot at both breakpoints, including the page-specific `#*-cta` id rules that outrank the generic `.cta` slot rule on the pages that ship a CTA, and the mobile adjacent-sibling rule that would otherwise defeat a declared top padding on stacked mobile components. `faq` keeps its literals because it has no padding or heading-size slot yet (issue 304), and the schema defaults were left untouched (they are metadata the AI reads from `schema.json`, which the renderer already advertises). No accepted grammar or public surface changed. The `--section-body-width` slot is the subtle one: the inner `.section__content` already routed through it, but the outer `.section__body` wrapper capped it at a literal `40rem`, so the slot silently no-opped until the wrapper was routed too.

### Fixed

- Section, grid, and CTA `--*-padding-top/bottom` slots now take effect. The premium `.section/.grid/.cta` padding rules, the desktop and mobile adjacent-sibling rhythm rules, and the page-specific `#home-cta`/`#how-cta`/`#agencies-cta`/`#implementers-cta` id rules in `assets/css/components.css` route through their `--*-padding-*` slots instead of bare literals, so a declared value no longer no-ops while the action reports success (#302).
- `--section-title-size` and `--grid-heading-size` now take effect. The premium desktop heading rule routed its `font-size` through the size slots (with the `clamp()` scale as the fallback) instead of a shared literal (#302).
- `--section-body-width` now takes effect. The outer `.section__body` wrapper caps its `max-width` through `var(--section-body-width, 40rem)` instead of a bare `40rem`, so widening the body column past the wrapper cap is finally possible (#302).
- The base two-tier vertical rhythm is restored. The premium layer no longer flattens the adjacent-sibling spacing between stacked components to a uniform value on desktop; unset components sit 32px apart again (#302).

### Tests

- `tests/js/css-lint.test.js` adds regression pins for every fixed slot (padding, title/heading size, body width) plus the `#*-cta` id rules and the desktop/mobile adjacent rhythm. Each asserts the property routes through its slot with the correct fallback and guards against selector drift by requiring a minimum match count, mirroring the issue 292 pin idiom (#302).

## [v0.16.95] — 2026-07-12 — The `--grid-card-border` style slot is honored on every grid card again, not just the featured one (#292)

**Setting a per-instance `--grid-card-border` on a `layout: cards` grid changed only the featured first card and was silently ignored on cards 2..N, the exact inverse of the bug issue 226 fixed. Two late "premium" cascade rules (`main > .grid .grid__item`, specificity [0,2,1]) re-declared `border-color: var(--color-border)` with no slot in the chain, so they outranked the base `.grid__item` rule and clobbered the author's declared value on non-featured cards. `style_component` reported success and `validate page` / `check page` / `validate site` all passed, so an AI operator got a green result with no visible effect. Both all-cards rules now route through `var(--grid-card-border, var(--color-border))`, matching the `:first-child` rule the issue 226 fix already corrected. Every card in a grid now honors a declared card-border color; an unset slot renders byte-identical to before.**

The fix is the same idiom the original issue 226 change established: route the literal re-declaration through the style slot with the neutral `--color-border` as the fallback (the featured `:first-child` rules keep their accent-token fallbacks at higher specificity, so the featured look is unchanged). The `--grid-card-border` slot was already declared in `components/grid/schema.json` with default `var(--color-border)`; this restores the rendered behavior to match that contract. No accepted grammar or public surface changed. A CSS-lint regression pin mirrors the issue 226 guard on the all-cards selector so a future premium-cascade rule cannot re-open the same slot-defeating gap.

### Fixed

- Grid `cards` now honor a per-instance `--grid-card-border` on every card, not only the featured first card. The two `main > .grid .grid__item` cascade rules in `assets/css/components.css` route their `border-color` through `var(--grid-card-border, var(--color-border))` instead of a bare `var(--color-border)`, so a declared card-border color no longer silently no-ops on cards 2..N while the action reports success (#292).

### Tests

- `tests/js/css-lint.test.js` adds a regression pin for the all-cards selector `main > .grid .grid__item`: it asserts every `border-color` on that rule routes through `--grid-card-border` with the neutral `--color-border` fallback, and guards against selector drift by requiring at least two matching rules. Mirrors the existing issue 226 featured-card pin, which it could not cover (#292).

## [v0.16.94] — 2026-07-12 — The runtime AI docs no longer hardcode a stale "47 design tokens" count (#286)

**The chat AI reads `AI_CONTEXT.md`, `AI_RULES.md`, and `ai-instructions/retheme.md` while it operates a site, and all three told it "47 design tokens" (or "47 CSS custom properties") control the visual system. `assets/css/base.css` now defines 51, so the count was four low everywhere the AI actually looks when driving a retheme. This is higher-stakes than the README copy issue #252 fixed: a wrong number in the runtime retheme instructions is a correctness problem in the product's core "an AI agent can retheme by updating tokens" capability, not just marketing prose. All seven occurrences now describe the token layer qualitatively ("A single layer of design tokens controls the entire visual system") instead of counting, so the claim stays true as tokens are added.**

This applies the same resolution as issues #250 and #252: stop asserting a hardcoded number in prose rather than refreshing 47 → 51, which would just reset the drift clock. `lib/ai-context.php` was already count-free (it generates the token inventory dynamically from live values), and the legitimate "8 base color tokens" subset in `retheme.md` Step 1 — which is self-verifying against the eight seed colors listed right below it — was deliberately left intact. A new `docs/`-lint guard pins the rule so the stale count cannot creep back into any of the three runtime docs.

### Docs

- `AI_RULES.md`, `AI_CONTEXT.md`, and `ai-instructions/retheme.md` no longer hardcode the design-token / CSS-custom-property count. All seven "47 design tokens" / "47 CSS custom properties" / "47 tokens" claims were replaced with qualitative descriptions, so the count can no longer drift as tokens are added to `base.css` (#286).

### Tests

- `tests/js/docs-lint.test.js` adds a design-token-count guard over the three runtime AI docs (`AI_RULES.md`, `AI_CONTEXT.md`, `ai-instructions/retheme.md`). Its `TOKEN_COUNT_CLAIM` regex catches total-count claims like "47 design tokens", "51 total design tokens", and "47 CSS custom properties", with `MUST_CATCH` / `MUST_NOT_CATCH` self-checks that keep it from decaying into a no-op and keep the legitimate "8 base color tokens" subset exempt (#286).

## [v0.16.93] — 2026-07-12 — The README no longer asserts stale hardcoded counts for design tokens and AI workflow files (#252)

**The README described the theme with two baked-in numbers that had quietly gone wrong. It claimed "47 CSS custom properties" / "47 design tokens" when `assets/css/base.css` now defines 51, and "13 files" of AI workflow guides when `ai-instructions/` now holds 14. A number baked into prose goes stale the moment a token or a file is added, and the token count is load-bearing for the product pitch ("an AI agent can retheme an entire site by updating tokens"), so being four off read as a credibility problem, not a typo. The README now describes both qualitatively instead of counting: "design tokens in one CSS file", "CSS custom properties", "Task-specific AI workflow guides". The claims stay true as the theme grows.**

This follows the resolution issue #250 took for stale test counts: stop asserting a hardcoded number in prose rather than refreshing it, which would just reset the drift clock. All six "47" occurrences in `README.md` and the one "13 files" count were reworded or dropped. The `docs/`-lint guard's `MUST_NOT_CATCH` list pinned the exact phrase "47 CSS custom properties control the entire visual system" as a known-legitimate non-test number; that phrase no longer exists in the README, so the now-fictional pin was removed. The identical stale "47 design tokens" claim in the runtime AI docs (`AI_CONTEXT.md`, `AI_RULES.md`, `ai-instructions/retheme.md`) was left for a separate issue rather than silently widening this change.

### Docs

- `README.md` no longer hardcodes the design-token / CSS-custom-property count or the `ai-instructions/` file count. Six "47 tokens / 47 CSS custom properties" claims and one "13 files" claim were replaced with qualitative descriptions or dropped, so the counts can no longer drift as tokens and guides are added (#252).

### Tests

- `tests/js/docs-lint.test.js` drops the `MUST_NOT_CATCH` pin for "47 CSS custom properties control the entire visual system"; that phrase was removed from the README, so pinning it as a live known-legitimate string was misleading. The guard's remaining entries still assert the test-count regex does not false-positive on legitimate non-test numbers (#252).

## [v0.16.92] — 2026-07-12 — The operating-loop playbooks now tell the agent where the `--run-id` run token comes from (#228)

**An AI following `playbook-create-page.md` literally could not get past step 3. The playbook's mandatory 8-step loop declares `wp pp apply preflight --run-id=<uuid>` at PREFLIGHT but never said where `<uuid>` comes from. Read as written, `<uuid>` means "any UUID", so an agent generates a fresh UUID v4 — it passes format validation, then PREFLIGHT cannot record run state and the next EDIT step fails. The run token is not a self-generated UUID: it is the `run_id` that `wp pp operate inspect` appends to its JSON. The three loop playbooks (`create-page`, `inspect-fix`, `revise-section`) now say so at INSPECT (capture the `run_id`) and at PREFLIGHT (`<uuid>` is that captured token), note the 2-hour install-scoped TTL, and link `docs/reference-apply-cli.md`. The documented happy path for AI-maintained pages is now executable from the playbook alone.**

The sourcing knowledge already lived in `docs/reference-apply-cli.md` and `AI_CONTEXT.md`; the playbooks just never restated or linked it, so an agent given only the playbook was stuck. `operating-loop.md` already documented the run token (Rule 1 plus the CLI reference table) and needed no change. A grep-based invariant test now pins the rule: it auto-discovers every `ai-instructions/*.md` file, and any doc that instructs `apply preflight --run-id` must also state that the run token comes from `wp pp operate inspect`, so a future playbook cannot reintroduce the same gap.

### Docs

- `ai-instructions/playbook-create-page.md`, `playbook-inspect-fix.md`, and `playbook-revise-section.md` now source the run token: INSPECT captures the `run_id` that `wp pp operate inspect` appends, PREFLIGHT states `<uuid>` is that captured token (not a freshly generated UUID), and both note the 2-hour install-scoped TTL and link `docs/reference-apply-cli.md` (#228).

### Tests

- New `InvariantTest::testLoopPlaybookSourcesRunIdFromInspect` auto-discovers every `ai-instructions/*.md` and asserts that any doc instructing `apply preflight --run-id` also documents that the run token comes from `wp pp operate inspect`, pinning the doc fix against regression (#228).

## [v0.16.91] — 2026-07-12 — The FAQ section can now carry an anchor id, an eyebrow pill, and a dark or inverted theme (#231)

**`faq` was the only heading-bearing component that could not be anchor-linked or given a background tone. Every other section component (`hero`, `section`, `grid`, `cta`, `stats`, `testimonials`, `logos`, `embed`) already accepted an `id`, and the marketing ones an `eyebrow` and a `theme` — `faq` accepted none of them in its schema, so a nav item pointing at `#faq` had no target and the FAQ heading could not sit on a dark band. `faq` now accepts `id` (anchor plus stable component id), `eyebrow` (a kicker pill above the heading), and `theme` (`default` / `dark` / `inverted`), matching the rest of the set. A page with an FAQ section and a nav link to it is finally expressible.**

The theme variant reuses the proven readability pattern from issue 222: the FAQ heading routes through a `--faq-heading-theme-color` fallback so the inverted color survives the high-specificity desktop typography rule instead of rendering dark-on-dark above 768px. Accordion cards keep their light background, so question and answer text stays dark and legible on an inverted band. The eyebrow gains two per-instance style slots (`--faq-eyebrow-color`, `--faq-eyebrow-bg`) so its pill can be recolored like every other component's eyebrow. Unknown `theme` values clamp to `default` at render time, matching `section`/`grid` (composition validation does not check enum values, so the render is the contract). AI-facing docs and the runtime AI context are updated in the same change so the chat and CLI know these props are now accepted.

### Added

- `faq` component now accepts `id`, `eyebrow`, and `theme` (`default` / `dark` / `inverted`) props, matching the other heading-bearing components. `id` anchors the section and becomes its stable component id; `eyebrow` renders as a pill above the heading; `theme` sets the background tone. Two new style slots (`--faq-eyebrow-color`, `--faq-eyebrow-bg`) recolor the eyebrow pill, bringing the FAQ slot count to 10 (#231).

### Docs

- Updated `AI_CONTEXT.md` (faq prop row, the `theme`-supporting component list, and the style-slot totals 140 → 142 / faq 8 → 10), `README.md` (slot totals), `ai-instructions/composition.md`, and `components/faq/README.md` to describe the new `id`/`eyebrow`/`theme` surface. The runtime AI context derives faq's props from its schema, so it picks up the new props automatically (#231).

### Tests

- New `ComponentPropsTest` render pins cover the FAQ `id` anchor, the eyebrow pill (present and absent), the eyebrow color slots, the `dark` and `inverted` theme classes, the default (no-modifier) case, and unknown-theme clamping. `SchemaValidationTest` now asserts `faq` declares a `theme` prop, and `OperateTest` pins `eyebrow` as an editable FAQ field (#231).

## [v0.16.90] — 2026-07-12 — AI-batch rollback restores an unset/empty site-option baseline instead of silently keeping the applied value (#281)

**When an AI batch (`pp_ai_execute_batch`) changed a typed site option and a later step failed, the rollback re-applied every captured baseline through the validating writer `pp_update_site_option()`. An option that was unset before the run is captured as an empty string, and an empty string fails the `attachment_id` and `bool` value rules, so the writer rejected it and left the applied value in place — a rollback that silently did not roll back. Turn on a logo (`pp_logo_id`) or the footer logo (`pp_footer_show_logo`) as part of a batch that later fails, and the option stayed set. The rollback now restores the exact pre-run state: it deletes the option when the captured baseline was unset/empty, and writes any other captured value back verbatim, bypassing only the value validator. This is the same principle already documented for composition restore (issue 233): a restore is never blocked by current validation rules.**

The fix lives in the action layer (`_pp_restore_batch_snapshot()`), not the writer — `pp_update_site_option()` keeps its create-time validation for normal writes. The whitelist boundary stays enforced: the batch snapshotter records every `update_site_option` step's key up front, before execute rejects an unauthorized one, so a non-whitelisted key can appear in the snapshot captured as empty; the restore skips any key that is not in `pp_allowed_site_options()` so it can never delete an unrelated core WordPress option. Only whitelisted options are touched, and only their value validation is bypassed on the restore path.

### Fixed

- AI-batch rollback (`_pp_restore_batch_snapshot()`) now restores an unset/empty typed site-option baseline (e.g. a never-set `pp_logo_id` or `pp_footer_show_logo`) by deleting the option, and re-applies any other captured baseline verbatim, instead of routing it back through the validating writer that rejected the empty value and silently kept the applied change. The site-option whitelist is still enforced on the restore path — a non-whitelisted key captured in the snapshot is never written or deleted (#281).

### Tests

- New `ActionsTest` cases pin the rollback: an unset `attachment_id` (`pp_logo_id`) and an unset `bool` (`pp_footer_show_logo`) baseline both roll back to unset; an explicitly-set typed value round-trips through rollback; an empty-string (`string`-typed) baseline rolls back to the observable empty state; a captured baseline that current validation would reject (issue 233-class) is still restored verbatim; and a non-whitelisted option present in the snapshot is left untouched (never deleted) (#281).

## [v0.16.89] — 2026-07-12 — `pp_footer_show_logo` site option makes the footer logo reachable again (#234)

**#223 made `nav`/`footer` template-owned chrome, so composing a `footer` to pass `show_logo: true` is now rejected — which left the footer logo with no supported surface to turn it on (`footer.show_logo` defaults off, and nothing else set it). This adds `pp_footer_show_logo`, a boolean site option on the same safe `update_site_option` surface as `pp_logo_id`. `templates/base.php` reads it and passes `show_logo` into the footer; when on, the footer resolves the same logo as the header (`pp_logo_id` → `custom_logo` theme-mod → text wordmark). The header logo (`pp_logo_id`) is unchanged and independent.**

The site-option whitelist (`pp_allowed_site_options()`) gains a third value type, `bool`, alongside `string` and `attachment_id`. `pp_validate_site_option_value()` accepts `1`/`0`/`true`/`false` (case-insensitive) and rejects anything else with a clear error — a typo like `flase` fails closed rather than silently coercing to on. The canonical stored form is `'1'` (on) / `'0'` (off), not `''`: both are themselves valid bool inputs, so a stored value survives the round-trip through the validating writer that the snapshot/rollback path (`_pp_restore_snapshot()`) uses to re-apply captured site options. Validation stays in the shared engine — no surface-specific second validator — and every AI-facing doc that described the footer logo as unreachable is corrected in the same change.

### Added

- `pp_footer_show_logo` boolean site option (whitelisted for `update_site_option`) turns the footer logo on/off; `templates/base.php` passes it into the footer component. New `bool` type in `pp_allowed_site_options()` with validation (`1`/`0`/`true`/`false`, case-insensitive) and canonical `'1'`/`'0'` storage via `pp_normalize_bool_option()` (#234).

### Docs

- `ai-instructions/set-logo.md`, `ai-instructions/website-building.md`, `components/footer/README.md`, `AI_CONTEXT.md`, and the `update_site_option` action description now document `pp_footer_show_logo` as the supported footer-logo surface, replacing the "footer logo is currently unreachable / no supported surface" notes left by #223 (#234).

### Tests

- New `LogoTest` cases pin the `bool` site option: the whitelist entry and its type; `pp_validate_site_option_value()` accepts `1`/`0`/`true`/`false` (incl. case + surrounding whitespace) and rejects non-bool values (`maybe`, `flase`, `2`, `''`, `yes`, `on`); `pp_update_site_option()` normalizes true-forms to `'1'` and false-forms to `'0'` and refuses to write a non-bool; the `update_site_option` action path accepts a valid bool and rejects an invalid one; the footer renders the logo when `show_logo` is true and a logo resolves, and omits it when false; and a round-trip guard confirms both stored forms (`'1'`/`'0'`) re-validate through the writer (regression guard for the rejected `''` OFF form) (#234).

## [v0.16.88] — 2026-07-12 — run-scoped `restore-composition` reports current-rule findings instead of a bare success (#236)

**`wp pp apply restore-composition --run-id=<uuid>` reverts every page a run touched back to its PREFLIGHT-frozen composition through `pp_update_composition()`, the deliberately non-validating writer. A snapshot that violates a validation rule which landed after it was frozen (site chrome in the composition, a dangling `var(--token)`, any future rule) was restored verbatim and the command reported an unqualified success with no indication the restored page would not pass current validation. That is the same false-pass class as the per-page `restore_composition` action (#233) and the chrome validators (#223), on the run-scoped CLI surface. The rollback stays permissive — an undo must never be refused by a rule that landed after the snapshot — but each reverted post now carries a `findings` array, and the command warns when any restored composition violates current rules. A clean restore reports `findings: []` and prints no warning; exit codes are unchanged (a partial restore still fails per #242).**

The findings reuse the shared engine `restore_composition` already uses (`_pp_composition_findings()`, which runs `pp_validate_composition_errors()` + `pp_validate_composition_smells()`); no third validator is introduced. `pp_operate_restore_run_compositions()` (`lib/operate.php`) attaches `findings` to each `reverted` entry, computed from the composition re-read via `pp_get_composition()` — the read-path migration shim makes that the canonical shape the validators expect, so the report matches the stored page. A pure predicate `pp_operate_restore_run_finding_count()` counts reverted posts (not total findings, and never skipped posts) so the CLI branch is unit-testable; `PP_Apply_Command::restore_composition()` (`lib/cli.php`) emits a `WP_CLI::warning` naming that count before the #242 completeness gate, to STDERR so the STDOUT JSON stays machine-clean. The revert is never blocked and the exit code never changes because of findings.

### Fixed

- `wp pp apply restore-composition` now attaches a `findings` array to every reverted post and warns (`WP_CLI::warning`) when any restored composition violates current validation rules, instead of reporting a bare `Success:` for a rollback that reintroduces rule-violating state. The rollback is still never blocked by rules that landed after the snapshot, and the exit code is unaffected by findings (a partial restore still fails per #242). Reuses the `restore_composition` action's findings helper; no second validator (#236).

### Docs

- `docs/reference-apply-cli.md` documents the run-scoped `restore-composition` `findings` contract: each reverted post carries `findings` (`[]` when clean), a `Warning:` names how many reverted posts have findings, and findings are advisory (they never change the exit code); skipped posts carry no `findings` key (#236).

### Tests

- New `OperateTest` cases pin the run-scoped findings: a rule-violating snapshot (chrome) is restored verbatim and its reverted entry reports a `template_owned_component` finding; a clean snapshot reports `findings: []`; a mixed run (clean + dirty + a skipped post with no snapshot) reports findings per reverted post, no `findings` key on the skipped post, and a `pp_operate_restore_run_finding_count()` of 1. Unit cases pin the predicate directly: it counts posts not total findings, ignores skipped entries, and returns 0 for a fail-closed report with no `reverted` key (#236).

## [v0.16.87] — 2026-07-12 — `apply preflight` fails closed on an unknown `--apply` name instead of passing clean (#245)

**`wp pp apply preflight --apply=<name>` never validated `<name>` against the apply registry. A typo (`--apply=import_medai`) resolved `pp_get_apply()` to `null` and was treated exactly like "no apply planned": the apply-routed filesystem checks were skipped, preflight passed clean, and a `PREFLIGHT` state was recorded — a green gate asserting a precondition ("no filesystem writes") the operator never earned. That is the same false-pass class as the fail-closed preflight cluster (#200/#207/#212/#227/#229), one level up: a gate must not certify a claim it did not verify. Preflight now emits an error-grade `apply_known` check for any provided-but-unregistered name, so `ok` is `false`, the CLI reports the checks and exits non-zero, and no `PREFLIGHT` is recorded. `wp pp apply execute` already rejected unknown action names; preflight now matches that posture for apply names.**

The guard lives at the single apply-name resolution point in `pp_preflight()` (`lib/operate.php`): when a supplied apply name resolves to no registered definition, an error-grade `apply_known` check is appended (`pass:false`, `Unknown apply: <name>`), which drives the existing `ok = no error-grade failure` logic to `false` and the existing CLI path to report-and-halt without recording state. Presence is tested as a non-empty string (`!== ''`), not `empty()`: PHP's `empty('0')` is `true`, so an `!empty()` gate would let the literal apply name `0` (and a bare `--apply` that WP-CLI coerces to boolean) slip through as "no apply planned" — no registered apply is named `0`, so any provided value that fails the registry lookup fails closed. The CLI boundary in `PP_Apply_Command::preflight()` (`lib/cli.php`) matches: it now routes any non-empty `--apply` value into the preflight context (previously `!empty()` dropped `--apply=0` before it could be validated). An empty `--apply=` remains "no apply planned," identical to omitting the flag.

### Fixed

- `wp pp apply preflight --apply=<name>` now fails closed with an error-grade `apply_known` check when `<name>` is not a registered apply, instead of silently treating an unknown/typo'd name as "no apply planned," skipping the apply-routed filesystem checks, and recording a successful `PREFLIGHT`. Presence is tested as a non-empty string so the falsy literal `--apply=0` is validated (and rejected) rather than dropped by `empty()` (#245).

### Docs

- `docs/reference-apply-cli.md` documents the `apply_known` check (checks table + `--apply` option + a worked "Unknown apply name" note): a provided non-empty apply name that matches no registered apply fails preflight closed; an empty `--apply=` is "no apply planned" (#245).

### Tests

- New `OperateTest` cases pin `pp_preflight()` apply-name validation: an unknown name emits a failing `apply_known` check and makes `ok` false; an unknown name still skips the apply-routed `uploads_writable` check while the overall preflight fails; a registered name and an absent flag emit no `apply_known` check; and the falsy literal `0` is validated and rejected while an empty string is treated as no-apply (#245).

## [v0.16.86] — 2026-07-12 — `restore-composition` fails closed on a partial restore instead of reporting success (#242)

**`wp pp apply restore-composition` printed the run-scoped restore report, warned about any `skipped` posts (a missing pre-run snapshot or a write failure), and then unconditionally ended with `WP_CLI::success` and exit 0. A machine consumer — the documented AI-operator contract — that branches on the exit code therefore read a partial restore as a complete one: if a touched post could not be reverted, the run still reported overall success. The command now fails closed. When any touched post lands in `skipped`, it still prints the JSON report (so you can see which posts were restored versus skipped), but it exits 1 with an explicit `Error: Restore INCOMPLETE` message. Exit 0 now means, and only means, that every touched post was reverted.**

The fix lives entirely in the CLI presentation layer; the report producer `pp_operate_restore_run_compositions()` already reported `reverted` and `skipped` correctly. A new pure verdict helper `pp_operate_restore_run_complete()` in `lib/operate.php` classifies a report as complete only when it has a usable touched-post record (`ok === true`) and an empty `skipped` list. `PP_Apply_Command::restore_composition()` records the APPLY step (the partial revert did run and mutate state), preserves the human-facing skip warning, then branches on the verdict: an incomplete restore errors with a non-zero exit; a complete restore keeps the prior `Success:` messages unchanged. This is the same false-success class already closed for `preflight` (#227) and the token-recording paths (#243/#241): a gate must not report a success it did not achieve.

### Fixed

- `wp pp apply restore-composition` now exits non-zero (`WP_CLI::error`) when the run-scoped restore is incomplete — any touched post skipped for a missing snapshot or a write failure — instead of always exiting 0 with `Success:`. The JSON report (restored vs skipped) and the human skip warning are preserved; exit 0 now guarantees a full restore, so a machine consumer can no longer mistake a partial restore for a complete one (#242).

### Docs

- `docs/reference-apply-cli.md` documents the `restore-composition` exit-code contract: exit 0 only on a full restore, exit 1 with `Error: Restore INCOMPLETE` when any post is skipped, JSON report printed on both paths (#242).

### Tests

- New `OperateTest` cases pin `pp_operate_restore_run_complete()`: a fully-reverted report is complete, a report with any `skipped` entry is incomplete, and an `ok:false` (no usable record) report is incomplete; two end-to-end cases drive the real report producer through a missing-snapshot skip (incomplete) and a clean revert (complete) (#242).

## [v0.16.85] — 2026-07-12 — run-state readers now take a shared lock so they never observe a half-written file (#274)

**The operating loop's writer (`pp_operate_mutate_state()`) takes an exclusive `flock` for its whole read-modify-write, but `flock` is advisory: a reader that does not itself take a shared lock bypasses it entirely. `pp_operate_read_state()` read the run-state file with a bare `file_get_contents()` and no lock, so while the writer was mid-write (a non-atomic `ftruncate(0)` then `fwrite()`) a concurrent reader — `operate inspect`, a step-completion check, a preflight-coverage query, any snapshot getter — could observe an empty or partially written file, fail to decode it, and treat the run as missing. On the same install this surfaced as a spurious "run not found" or a preflight/step gate transiently reading as unsatisfied while another CLI process recorded state. The read now takes a shared lock (`flock LOCK_SH`) and blocks until the writer releases, so it always sees a complete file.**

`pp_operate_read_state()` is the single choke point every run-state getter routes through, so hardening it fixes all readers at once. It now opens the file with `fopen('r')`, acquires `LOCK_SH`, reads the full contents, releases the lock, and closes the handle before decoding — coordinating with the writer's `LOCK_EX` on the same file. A missing file (`fopen 'r'` fails) returns `null`, matching the prior `file_exists()` guard; a transient lock-acquire failure returns `null` in the fail-closed direction (a valid run momentarily reads as unusable, never the reverse), matching the writer's own lock-failure handling. Replacing the `file_exists()` + `file_get_contents()` two-step with a single opened handle also removes a time-of-check/time-of-use window against the TTL `@unlink` path. This is the reader-side counterpart of the fail-closed writer hardening in the #200/#207/#212/#241/#243 concurrency cluster: the writer was already correct, but readers did not participate in the lock.

### Fixed

- `pp_operate_read_state()` now reads the run-state file under a shared lock (`flock LOCK_SH`), so a reader can no longer observe an empty or half-written file while `pp_operate_mutate_state()` holds the exclusive lock during its non-atomic truncate-then-write — eliminating the spurious "run not found" / transiently-unsatisfied-gate class for every getter built on it (`operate inspect`, step checks, preflight-coverage, snapshot getters) (#274).

### Tests

- New `OperateTest` cases pin the #274 behavior: a valid state file still reads correctly under the shared lock, a missing file returns `null` via the `fopen` guard, a corrupt payload returns `null` without truncating or recreating the file, and the shared lock is released after the read so a subsequent `mutate_state` proceeds (no lock leak) (#274).

## [v0.16.84] — 2026-07-12 — the preflight gate can no longer unlock without its composition restore baseline (#241)

**A page-scoped `wp pp apply preflight` used to record its unlock (PREFLIGHT coverage, freshness marker, token snapshot) in one state write and the pre-run composition content snapshot — the baseline `restore-composition` reverts to — in a separate second write. If that second write failed, the run was left fully unlocked with no restore baseline: a parallel agent or an operator reading `operate inspect` could execute a page mutation through the open gate, and the change could never be rolled back. The composition snapshot is now folded into the same locked state write as the PREFLIGHT step, so coverage and its restore baseline are all-or-nothing. A corrupt stored composition now fails the preflight closed instead of freezing an empty baseline that a later restore would replay to blank the page.**

`PP_Apply_Command::preflight()` committed `pp_operate_record_preflight()` first (the atomic unlock) and then called `pp_operate_record_composition_content_snapshot()` as a follow-up write. `pp_operate_preflight_covers()` and `pp_operate_run_rollbackable()` both passed once the first write landed, so a failure of the second left the gate open with no baseline — and because the content snapshot is first-write-wins, an operator who followed the error's advice and re-ran preflight *after* mutating the page would freeze the post-mutation composition as the "pre-run" baseline, making the true baseline unrecoverable. The snapshot now travels into `pp_operate_record_preflight()` as a new parameter and is recorded inside the same `pp_operate_mutate_state()` critical section as the PREFLIGHT step (first-write-wins for the content, last-write-wins for the freshness marker, matching #113). The CLI reads the pre-apply composition through `pp_get_composition_result()` (the fail-closed decoder) rather than `pp_get_composition()` (which coerces a corrupt row to `[]`): a corrupt or undecodable `_pp_composition` row now records nothing and errors, mirroring the token snapshot's fail-closed handling of a corrupt `pp_token_overrides` row (#207). This completes the fail-closed gate principle of #96/#113/#200/#207/#212/#243 for the composition restore path.

### Fixed

- `wp pp apply preflight --post_id=N` now records the PREFLIGHT coverage and the pre-run composition content snapshot in a single locked state write (`pp_operate_record_preflight()` gained a `composition_content` parameter), so the run can never be left unlocked without its restore baseline, and a preflight re-run can never freeze a post-mutation composition as the pre-run baseline (#241).
- A corrupt or undecodable stored page composition now fails the preflight closed (read via `pp_get_composition_result()`), instead of freezing an empty `[]` baseline that a later `restore-composition` would replay to blank the page (#241).

### Docs

- `docs/reference-apply-cli.md` replaces the stale "page-scoped edge case" note (which described the removed two-write gap) with the atomic-unlock guarantee and documents the new corrupt-composition fail-closed preflight error (#241).

### Tests

- New `OperateTest` cases pin the #241 behavior: PREFLIGHT coverage and the content snapshot commit together in one state, the content baseline is first-write-wins across preflight re-runs while the freshness marker is last-write-wins, an empty page records a valid `[]` baseline, and a null content arg (site grain, or a caller that already failed closed on a corrupt row) records no content snapshot (#241).

## [v0.16.83] — 2026-07-12 — a failed run-state write can no longer report success (#243)

**Every recorder behind the operating loop's run token — PREFLIGHT, step completion, token and composition snapshots — persists through one locked write to a JSON state file. That write truncated the file first and then wrote, never checking that the encode or the write actually succeeded, so a full disk or an unencodable payload could leave the state file empty or torn while the recorder still reported success. The write is now fail-closed: it encodes before touching the file, refuses to write if the file cannot be truncated, verifies the whole payload landed, restores the prior bytes if it did not, and returns failure so the caller surfaces the error instead of trusting a destroyed baseline.**

`pp_operate_mutate_state()` did `ftruncate` then `fwrite( wp_json_encode(...) )` and returned `true` unconditionally. `wp_json_encode()` can return `false` (for example on malformed UTF-8), and `fwrite` can short-write on `ENOSPC` — either way the prior state (the INSPECT baseline and any recorded snapshots) was already discarded, yet callers like `wp pp apply preflight` printed `{"ok":true}` for a run whose state file was now empty or partial. The persist now encodes the new state first and aborts untouched on an encode failure; it checks the `ftruncate` result and aborts without writing rather than leaving stale trailing bytes from a longer prior state behind a shorter new one; and after writing it verifies the byte count and flush, restoring the captured prior bytes on a best-effort basis and returning `false` on any seek, write, or flush failure. A failed persistence can no longer truncate the state file yet report a confirmed write.

### Fixed

- `pp_operate_mutate_state()` now fails closed: it encodes the new run state before truncating the file, verifies the full payload was written and flushed, checks the truncate result, restores the prior bytes on a short or failed write, and returns `false` on any encode/truncate/seek/write/flush failure — so a failed write can no longer destroy the run-state baseline while reporting success (#243).

### Tests

- New `OperateTest` cases pin the fail-closed behavior: an encode failure (malformed UTF-8 payload) returns `false` and leaves the prior state file byte-for-byte intact; a shorter payload written after a longer one leaves no stale trailing bytes; and a valid multibyte payload persists in full (guarding the byte-count check against a `strlen`/`mb_strlen` mixup) (#243).

## [v0.16.82] — 2026-07-12 — two components can no longer share the same id (#238)

**A composition could carry two components with the same `id` and still save without complaint, and every id-based action (`update_component`, `remove_component`, `style_component`) then silently targeted whichever one sorted first, reporting success while it changed or deleted the wrong component. Duplicate component ids are now rejected at write time, so the ambiguous state is never persisted. If a duplicate ever reaches a page through a raw write, targeting it fails closed with a clear error instead of guessing.**

Nothing checked id uniqueness before a composition was saved, so `create_page` and `update_composition` accepted two components sharing a non-empty `id` — and duplicate ids also produce invalid duplicate `id` attributes in the rendered HTML, breaking anchor links. Write-time validation now rejects the collision with a `duplicate_component_id` error, distinct from the generic `invalid_composition` code so a caller can tell "that id is duplicated" from "that component is malformed." A page whose stored composition already carries a collision (written before this rule, or through a non-action path) is not silently accepted: `wp pp check page` and `wp pp validate site` report a `duplicate_component_id` smell. As a backstop, the component resolver no longer returns the first match when several components share the requested id — it fails closed with a `component_ambiguous` error listing the colliding indexes, so `update_component` / `remove_component` / `style_component` refuse to mutate rather than guess. Both surfaces share one duplicate-id detector, mirroring the dual error-plus-smell treatment template-owned chrome already gets.

### Fixed

- Composing two components with the same non-empty `id` is now rejected at write time (`create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor save) with a `duplicate_component_id` error, so id-based targeting can never resolve to the wrong component on saved state (#238).
- `wp pp check page` and `wp pp validate site` now report duplicate authored ids as a `duplicate_component_id` composition smell for state that predates the write-time rule (#238).
- `pp_resolve_component_target()` fails closed with a `component_ambiguous` error (listing the colliding indexes) when more than one component matches the requested `component_id`, instead of silently returning the first match (#238).

### Docs

- `ai-instructions/website-building.md`, `ai-instructions/validate-site.md`, and `docs/reference-apply-cli.md` now document that component ids must be unique within a composition, the `duplicate_component_id` write-time rejection and smell, and the `component_ambiguous` resolver backstop (#238).

### Tests

- Write-time rejection is pinned for two- and three-way collisions (one error naming every colliding index), and for the cases that must NOT flag: distinct ids, missing/empty ids, and non-scalar ids. `"0"` is verified to count as a real id (the guard is `=== ''`, not `empty()`), and a numeric `1` and string `"1"` are verified to collide since both render as the same DOM `id`. Resolver ambiguity, the shared detector, and the advisory smell surface each get direct tests (#238).

## [v0.16.81] — 2026-07-12 — the browser-rendered @smoke E2E check now runs on every pull request (#82)

**The `@smoke` end-to-end subset used to run only after a change landed on `main`, so a pull request could be merged without ever seeing the rendered-browser signal that catches CSS cascade regressions unit tests miss. `@smoke` now runs on every pull request against `main` and reports its own check, so the browser evidence is available before merge instead of after. It still runs on push to `main` as a post-merge watcher and as the full suite nightly and on manual dispatch.**

The E2E workflow triggered on `push` to `main` (the `@smoke` subset) plus a nightly full run. That is a signal on the mainline commit, not a gate on the change that introduced a regression. The workflow now also triggers on `pull_request`, running the same fast `@smoke` subset so a check reports on every PR. The pull-request trigger intentionally carries no `paths-ignore` filter: a check that is required but skipped on a docs-only PR would leave that PR blocked on a status that never reports, so `@smoke` always runs and reports on a PR. Making the check actually required is a separate manual repository-admin step (branch protection on `main` requiring `E2E (WordPress 7.0) / e2e` and `ai-ready-check`), which the automation's token cannot set; until that is enabled the PR check is visible but non-blocking.

### Changed

- The `E2E (WordPress 7.0)` workflow now runs the `@smoke` subset on `pull_request` against `main` in addition to `push`, so every pull request gets a browser-rendered check before merge. Nightly and manual runs still execute the full suite (#82).

### Docs

- The README continuous-integration note now states that `@smoke` runs on every pull request and on push to `main`, non-blocking until branch protection marks it a required check (#82).

## [v0.16.80] — 2026-07-12 — the featured first grid card now honors a custom card border color (#226)

**Setting `--grid-card-border` on a `grid` with `layout: "cards"` changed every card's border except the first one. The first card gets a "featured" treatment (accent border, accent top bar, tinted fill), and its border color was pinned to the theme accent, so an author's declared border color silently did nothing on that card while the action still reported success. The featured card now respects `--grid-card-border`, falling back to the accent only when no value is set.**

The featured first card already routed its background through the `--grid-card-bg` slot so an author could override it, but its border color was hardcoded to the accent token in two cascade rules (the later one is what actually rendered). Setting `--grid-card-border` therefore applied to cards 2..N and no-opped on card 1, which reads as a bug because the neighboring background slot did work. Both rules now route the border through `var(--grid-card-border, <accent>)`, matching how `--grid-card-bg` already behaves on the same card. When the slot is unset the featured accent border is unchanged; the top bar and tinted fill of the featured treatment are also unchanged. This closes the border half of #226; the featured treatment itself remains on by default (opting out of it entirely was the alternative the issue listed but did not require).

### Fixed

- The featured first card of a `grid` with `layout: "cards"` now honors the `--grid-card-border` style slot instead of silently forcing the theme accent, so a declared card border color applies uniformly across all cards. Unset compositions keep the featured accent border (#226).

### Tests

- A CSS lint pin extracts every `main > .grid:not(.grid--steps) .grid__item:first-child` rule that sets `border-color` and asserts each routes through `var(--grid-card-border, ...)` with an accent-token fallback; it fails if either featured-card rule reverts to a bare token. A nonzero-match guard prevents the pin from passing vacuously if the selector ever drifts.

## [v0.16.79] — 2026-07-11 — a CTA button label with no spaces can no longer scroll the page sideways (#266)

**A `cta` button whose label had no place to break, a long unbroken string like a run-together phrase or a URL slug, grew wider than its column and pushed the page horizontally off screen at 768px. Normal labels, including long ones with spaces, wrap inside the button and were always fine; only a label with zero break opportunities could force the overflow. Any label now wraps inside the button instead of widening its column, so no author-supplied button text can scroll the page.**

The button on the four inline CTAs (`home-cta`, `how-cta`, `agencies-cta`, `implementers-cta`) sizes itself to its content with a min-width floor and a 20rem cap. A label with spaces wraps at a space and stays inside the button; a label with no spaces cannot wrap, so the button grew toward its 20rem cap, past the grid track it sits in, and the page scrolled. The fix gives the button a last-resort break with `overflow-wrap: anywhere`. Unlike the `break-word` behavior already applied site-wide, `anywhere` lets the browser count a mid-word break as a width the button can shrink to, so the button collapses back to its min-width and the label wraps onto a second line inside it. Short and spaced labels are unchanged: they still size the button to its min-width floor and never trigger the break.

### Fixed

- A `cta` button label with no break opportunities (an unbroken string with no spaces) no longer widens the action column past its grid track and scrolls the page sideways at 768px. The four inline CTA buttons now carry `overflow-wrap: anywhere`, so any label wraps inside the button instead of growing it; short and spaced-label rendering is unchanged (#266).

### Tests

- A rendered end-to-end case sets an unbreakable ~48-character button label on `home-cta` at 768px and asserts the page does not scroll sideways and the button stays inside its container; reverting the CSS turns it red.

## [v0.16.78] — 2026-07-11 — the other three inline CTAs no longer scroll the page sideways on a tablet (#265)

**A `cta` with `layout: "inline"` and the id `how-cta`, `agencies-cta`, or `implementers-cta` pushed the page horizontally off screen from 768px to about 912px, exactly as `home-cta` did before v0.16.76. The two-column grid switches on at 768px, but the columns it asked for (32.5rem for the headline, 14rem for the action copy, and a gap of at least 3rem) could not fit in the roughly 40rem the breakpoint actually leaves. Those were floors, not preferences, so the grid could not shrink and the page scrolled. The columns are now allowed to shrink, and pages using any of the three inline CTA ids fit at every width.**

`home-cta` was fixed in v0.16.76 (#258), but its fix lives in an id-specific override that the other three CTAs never reach. They are sized by the shared four-CTA rule, which still carried the same fixed track floors. The fix floors the headline column at zero (fractional, so it absorbs the slack) and floors the action column at the button's own min-width (14.75rem), the one thing in that column that refuses to get narrower, so the button can never overflow its track. `home-cta` re-sets these same tracks in its own override and is unaffected. On desktop nothing moves: the headline and body already cap themselves well before the tracks stop growing, so the two-column composition renders as before.

### Fixed

- A `cta` with `layout: "inline"` and the id `how-cta`, `agencies-cta`, or `implementers-cta` no longer scrolls the page sideways between 768px and ~912px. The shared four-CTA inline grid rule now uses shrinkable track floors (`minmax(0, 2fr) minmax(14.75rem, 1fr)`), mirroring the `home-cta` fix from #258 (#265).

### Tests

- The rendered end-to-end overflow check is now parametrized over all four inline CTA ids (`home-cta`, `how-cta`, `agencies-cta`, `implementers-cta`) across 768–1024px, with one `@smoke` case per id at the worst-overflowing width; reverting the CSS turns the three new ids red.
- Stylesheet checks assert that the three shared-rule CTAs floor their first column at 0 and their second column at exactly the CTA button's min-width, so the two values cannot silently drift apart.

## [v0.16.77] — 2026-07-11 — the full-width closing CTA now centers its copy and button instead of splitting them to opposite edges (#257)

**A `cta` with the id `home-cta` in its default `full-width` layout rendered broken on desktop: the body copy was jammed against the right edge and the button against the left, while the eyebrow and headline stayed centered above them. The block read as misassembled rather than composed. It now centers all of its content, as a full-width call-to-action should.**

The cause was a set of alignment rules that were only ever meant for the other CTA layout. At 768px and up, `home-cta` in `inline` layout becomes a two-column grid, and its body and button carry `align-self` values that place them within that grid. Those declarations were written without the `inline` scope, so they also reached the default `full-width` layout, where the same block is a centered vertical stack, not a grid. In a vertical stack `align-self` controls the horizontal axis, so "place at the grid's end" became "shove to the right edge" and "place at the grid's start" became "shove to the left edge". The fix scopes those two declarations to the inline layout. In `full-width` the body and button now fall back to the stack's own centering, matching the eyebrow and title.

Every shipped page uses the inline layout, where these rules were always correct, so no live page changed. The bug only appeared for an author who set `id: home-cta` with the default layout, which is now what the release gate guards against.

### Fixed

- A `full-width` `cta` with `id: home-cta` now centers its body copy and button at 768px and up, instead of flushing the body to the right edge and the button to the left. The `align-self` declarations that position those elements in the `inline` grid are now scoped to the inline layout and no longer leak into the default full-width stack (#257).

### Tests

- A rendered end-to-end case pins the full-width `home-cta` body and button centered within the block at 1280px; reverting the CSS pushes them back to opposite edges and turns it red.
- Stylesheet checks assert the leaking `align-self` values (`start`/`end`, and the `flex-end` synonym) never sit on a bare `#home-cta` selector, that the inline layout keeps its intended placement, and that the two-column grid the inline rules describe is still present.

## [v0.16.76] — 2026-07-11 — the homepage closing CTA no longer scrolls the page sideways on a tablet (#258)

**A `cta` with `layout: "inline"` and the id `home-cta` pushed the page horizontally off screen on every viewport from 768px to about 912px. The two-column grid switches on at 768px, but the columns it asked for could not fit in the space that breakpoint actually leaves: 36rem for the headline, 18rem for the action copy and a gap of at least 3rem, against a content box of roughly 40rem. Those were floors, not preferences, so the grid could not shrink and the page scrolled instead. The columns are now allowed to shrink, and the shipped homepage fits at every width.**

The arithmetic that made this invisible is worth naming, because the same trap is set for any component that turns a grid on at a breakpoint. The viewport is not the space the grid gets. By the time the tracks are laid out, `.container` has taken its padding from both sides (and at 768px that padding widens, from `--space-md` to `--space-lg`), and `.cta__inner` has taken its own `clamp(2rem, 4vw, 2.6rem)` of padding plus a border. What is left at the 768px breakpoint is about 40rem. The old tracks demanded about 57rem. A track whose minimum is a fixed length cannot go below it, so the overflow was not a rounding error, it was the layout doing exactly what it was told.

The fix gives each column a minimum it can actually honor. The action column now floors at the width of the button itself, which is the one thing in that column that refuses to get narrower, so the button can never overflow the track it lives in. The headline column floors at zero and takes what remains. On desktop nothing moves: the headline and the body copy already cap themselves well before the tracks stop growing, so the two-column composition renders as it did before.

Two related defects found while fixing this are filed rather than folded in, because they are separate pre-existing bugs: the shared rule behind `#how-cta`, `#agencies-cta` and `#implementers-cta` overflows the same way (#265), and a CTA button label with no place to break still outgrows its column (#266).

### Fixed

- `#home-cta` with `layout: "inline"` no longer scrolls the page horizontally between 768px and ~912px. Its grid tracks are now `minmax(0, 2fr) minmax(14.75rem, 1fr)` instead of `minmax(36rem, 40rem) minmax(18rem, 1fr)`, so the grid fits the content box the breakpoint provides. The desktop two-column composition is unchanged (#258).

### Tests

- Seven rendered end-to-end cases assert the page does not scroll sideways at 768px, 800px, 860px, 912px and 1024px, and cover a headline whose longest word cannot wrap and a button label long enough to need wrapping. Reverting the CSS turns six of them red.
- Three stylesheet checks pin the parts the rendered proof silently depends on: the headline column floors at zero, the action column's floor is read from the button's own `min-width` rather than hardcoded, and `base.css` still lets a long word break (without it, a 0-floored column would let the word paint outside the layout and scroll the page anyway).

## [v0.16.75] — 2026-07-11 — the packaging gate now reads the archive's payload, not just its table of contents (#261)

**A release ZIP whose compressed data was damaged used to pass validation and ship. Every check `scripts/package.sh` ran — the style.css membership test, the top-level-directory test, the size test — read only the archive's central directory, which is its table of contents. Corrupt the payload and leave the index intact and the build validated clean, uploaded itself as a release asset, and failed for the first time in someone's WordPress install. `scripts/validate-zip.sh` now inflates and CRC-checks the payload before a build is allowed out.**

Listing a ZIP is not reading it. `unzip -l` and `unzip -Z1` walk the central directory and report what the archive *claims* to contain; neither one touches a single compressed byte. `unzip -t` is the only check that actually decompresses each entry and verifies it against its stored CRC, so it is the only one that can tell a good build from a byte-damaged one. That is the check that was missing, and the class of failure it catches is the one where CI is green, the release asset is published, and the theme is broken on arrival.

The gate fails closed on any nonzero exit, not just on a hard error. `unzip` reports a *skipped* entry — one whose compression method it does not recognize, for instance — as exit 1, a mere "warning". A skipped entry is never inflated and never CRC-checked: flip one byte of style.css's compression-method field and the archive still lists correctly, still passes the membership test, still reports only a warning, and extracts the stylesheet as **zero bytes**. A theme whose style.css is empty is a dead theme. Treating "I could not verify this" as "this is fine" would have left the headline bug alive in a gate written to catch it, so any nonzero result now rejects the build. The membership check moved ahead of the integrity check to make that possible: an empty archive also exits 1, and letting the style.css test reject it first means the integrity gate never has to tell those two apart and needs no exceptions carved into it.

`scripts/` is excluded from the distributed ZIP, so none of this ships to sites. `package.sh` keeps the same usage, arguments, and output on success.

### Fixed

- `scripts/validate-zip.sh` now verifies the ZIP payload with `unzip -tq` and rejects any archive that does not verify, with a distinct message and the failing entry named. Previously an archive with a damaged payload and an intact central directory passed as a good build and was uploaded by `release.yml` (#261).
- The integrity gate fails closed on any nonzero `unzip -t` exit, so an entry that unzip skips without verifying (and would extract as zero bytes) is rejected rather than waved through as a warning.
- The style.css membership check now runs before the integrity check, so an empty archive is still reported as a missing style.css rather than as corruption.

### Tests

- Five tests in `tests/js/package.test.js` pin the new gate: a corrupt payload behind an intact index; the failing entry is named in the report; style.css intact but a sibling entry damaged; an entry unzip cannot verify (asserting it extracts as zero bytes *and* is rejected); and a missing style.css reported ahead of corruption, which pins the check order.
- Two fixture builders construct the bad archives: one corrupts a named entry's payload in place using its own compressed-size field, the other flips that entry's compression method. Both assert the archive is genuinely bad and that its central directory still reads clean, so neither test can pass against a fixture that quietly stopped being corrupt.

---

## [v0.16.74] — 2026-07-11 — the packaging gate says which failure it hit, and shows you the archive (#260)

**A release build that fails on a bad ZIP now tells you which kind of bad. `scripts/package.sh` reported "style.css not found in ZIP" for three different failures — an archive it could not read, an archive genuinely missing the file, and any other hiccup in the check — and printed nothing about what the archive did contain. An unreadable archive and a missing file are now separate messages, and the missing-file case dumps the archive's entry list.**

The conflation was a shell subtlety. The check was `if ! unzip -l "$ZIP" | grep -q 'promptingpress/style.css'`, and the script runs under `set -o pipefail`. A nonzero `unzip` makes the whole pipeline nonzero, `!` inverts that to true, and the missing-file branch fires — so "I could not open this archive" and "this archive has no style.css" printed the same sentence. When an intermittent CI failure hit that line, the message pointed at the wrong cause and the first diagnosis was wrong. Dumping the listing is the part that pays for itself: the next occurrence names itself instead of starting a hypothesis chain.

The check moves to `scripts/validate-zip.sh` so both failure paths can be exercised by tests, which a real `package.sh` build cannot do — it has no way to produce a corrupt archive on demand. Membership is now an exact match on the archive's entry list rather than a substring search, because the old pattern was an unanchored regex: a ZIP carrying only `promptingpress/style.css.map`, or a stray `foo/promptingpress/style.css`, would have satisfied it and passed a build with no theme stylesheet in it. `scripts/` is excluded from the distributed ZIP, so none of this ships to sites; `package.sh` keeps the same usage, the same arguments, and the same output on success.

### Fixed
- **The ZIP gate distinguishes an unreadable archive from a missing `style.css`** (`scripts/validate-zip.sh`, `scripts/package.sh`). A read failure reports "could not read" and `unzip`'s own error. A missing file reports "style.css missing" and prints the archive's contents. Two guards keep the split honest: a path that is not literally a file is rejected up front, because `unzip` globs its argument and retries it with a `.zip` suffix, so a nonexistent path could otherwise resolve to a different archive and pass; and an empty archive (which `unzip` exits nonzero on) is reported as a missing `style.css`, which is what it is, not as a read failure.
- **The gate no longer accepts a look-alike path.** Membership is an exact entry match, so `promptingpress/style.css.map` and `foo/promptingpress/style.css` are correctly not `promptingpress/style.css`.

### Tests
- `tests/js/package.test.js`: every branch of the validator is pinned — a clean archive; an unreadable one, asserting it reports a read failure and *not* a missing file; a nonexistent path; the glob/suffix resolution that could bless a different archive; a missing `style.css`, asserting the entry listing is actually dumped; an empty archive; both look-alike paths; and the usage errors. One test pins that `package.sh` invokes the validator on its real invocation line, so the suite cannot go green while the old inline check quietly persists.

**Known limitation** (tracked in #261): the check reads the archive's central directory, so a ZIP whose payload is corrupt but whose directory is intact still passes. The previous check had the same blind spot; closing it needs an integrity pass, which is its own change.

---

## [v0.16.73] — 2026-07-11 — the cta eyebrow is a pill above the title, not a band below the button (#255)

**Same defect as v0.16.72, same prop, a different mechanism — and this one moved the eyebrow as well as stretching it. On `#home-cta` at 768px and up, `cta.eyebrow` rendered as a colored band the full width of the title column, sitting underneath the call-to-action button. It now renders where the schema has always said it does: a compact pill, sized to its own text, directly above the headline.**

The hero's band came from a flex column blockifying an `inline-block`. The CTA's came from a grid. At the desktop breakpoint `#home-cta .cta__text` is `display: contents`, which dissolves that wrapper and promotes the eyebrow, title and body into `.cta__inner` — which is a two-column grid. Grid blockifies its items exactly as flex does, so the pill stretched. But the eyebrow was also the only child with no placement of its own, and its three siblings are all explicitly placed: title in column 1 across both rows, body and button stacked in column 2. Auto-placement therefore walked past every occupied cell and dropped the eyebrow into the first free one — row 3, column 1 — which is why a label whose entire job is to sit above the headline was rendering below the button. Sizing it correctly without placing it would have produced a tidy pill in the wrong place, so the fix does both: the eyebrow takes row 1 of the title column, and title, body and button each shift down one row.

Nothing about the authoring surface changes. `cta.eyebrow` already existed, and `components/cta/schema.json` already described it as "a short kicker/label rendered as a pill above the title" — the render simply did not match the contract. This was invisible on the live site for the same reason #225 was: no shipped page sets `cta.eyebrow`, so nothing ever drew the band. The new empty row costs those pages nothing, because the row gap is zero and an empty grid row collapses to nothing — a claim the tests now measure in a browser rather than assume.

### Fixed
- **`cta.eyebrow` renders as a pill above the title on `#home-cta`, not a band below the button** (`assets/css/components.css`). The eyebrow takes `grid-column: 1` / `grid-row: 1` with `justify-self: start`; title, body and button shift to rows 2, 2 and 3. The rule is scoped to `#home-cta.cta--inline` on purpose: `.cta__inner` is a flex column in the default `full-width` layout, where `align-self` controls the horizontal axis and an unscoped rule would flush the pill against the right edge instead.

### Tests
- `tests/e2e/style-render.spec.ts`: rendered-box proof that the pill sits above the title at 1280px, at the 768px breakpoint, and at mobile — position, not just width, because a width-only assertion passes happily while the eyebrow renders under the button. One case pins the state every shipped page is actually in (no eyebrow set) and proves the empty row still collapses; another renders the `full-width` layout and proves the fix stayed scoped to `inline`.
- `tests/js/css-lint.test.js`: pins both halves of the bug — the pill is sized to its content, and it is placed in row 1 — plus the ways either could return while the other pins stayed green: a `gap` shorthand quietly resetting the zero row gap, a width added to any eyebrow rule, an alignment declared outside the two rules allowed to carry one, or the title reclaiming row 1. The rule scanner and the band-restoring guard are now shared with the hero pins rather than duplicated.
- `tests/ComponentPropsTest.php`: pins the eyebrow as a direct child of `.cta__text`. `display: contents` dissolves exactly one box, so wrapping the eyebrow in anything would leave it inside a box that still exists, silently stop it being a grid item, and take the placement rules out of the cascade with every CSS pin still passing.

---

## [v0.16.72] — 2026-07-11 — the hero eyebrow is a pill again, not a colored band (#225)

**`hero.eyebrow` is documented as a pill: a short kicker sized to its own text, sitting above the headline. It rendered as a full-width colored band spanning the entire hero content column. The CSS said `display: inline-block` and meant it, but `.hero__eyebrow` is a direct child of `.hero__content`, which is a flex column, and a flex container blockifies its children — `inline-block` computes to `block`, then the default `stretch` alignment pulls the box across the full width. The eyebrow now shrinks to its text in all four hero layouts, flush with the leading edge on `left` and `split`, centered on `centered` and `cover`.**

Nothing about the fix is new to this codebase. `.hero__cta-group` sits in the same flex column and hit the same wall, and the file already solved it: `align-self: flex-start` on the base rule, `align-self: center` in the two center-aligned layout blocks. The eyebrow now gets the identical treatment a few lines away. `grid.eyebrow` was never affected, because its parent is a plain block container — which is why the same prop rendered as a pill in one component and a band in another.

It stayed invisible for a long time because it is invisible by default. `--hero-eyebrow-bg` falls back to a pale surface tint on a light hero, so the band blends into the page; set a saturated brand color, or use a dark hero, and it is the first thing you see. No page on the shipped site sets `hero.eyebrow`, so nothing ever rendered it. That is the gap this release closes twice: once in the CSS, and once in the tests, which now measure the rendered box in a real browser instead of trusting that a declaration in a stylesheet is what the cascade actually produces.

### Fixed
- **`hero.eyebrow` renders as a pill, not a full-width band** (`assets/css/components.css`). `align-self: flex-start` on `.hero__eyebrow` opts the pill out of the flex stretch that was blockifying it; `.hero--centered` and `.hero--cover` re-center it, matching the alignment their CTA groups already carry. `left` and `split` inherit the leading-edge default.

### Docs
- `README.md` and `AI_RULES.md`: the E2E coverage lists now name rendered-layout proof — geometry and style slots measured in the browser — alongside the editor round-trip, CLI actions, post-apply validation, chat streaming, and token concurrency.

### Tests
- `tests/e2e/style-render.spec.ts`: rendered-box proof for the eyebrow across all four layouts at desktop and mobile. Each asserts the pill is under half the content width and lands on the edge or the center that its layout calls for. A static check on CSS text cannot prove what the cascade renders; these fail with the eyebrow measured at the full content width when the fix is reverted.
- `tests/js/css-lint.test.js`: pins the three alignment declarations and, more usefully, guards the ways the band could come back without touching them — a width on the pill, a duplicate rule later in the cascade, a media-scoped override, or a parent that stops being a flex column. The rule scanner tracks enclosing at-rules, so a rule nested inside `@supports` inside `@media` is still read as media-scoped.
- `tests/ComponentPropsTest.php`: pins the eyebrow as a direct child of `.hero__content` in every layout. The alignment fix depends on that parent-child relationship, so a wrapper element of any kind would quietly kill the pill while every CSS pin stayed green.

---

## [v0.16.71] — 2026-07-11 — three feature cards, three columns, no orphan (#224)

**A `grid` with `layout: "cards"` and three items rendered two cards on the first row and stranded the third, alone and stretched to half width, on a second row. It did this at every desktop width. The three-across feature row is the most common layout on a marketing page, and PromptingPress could not express it: there is no `columns` prop, and switching to `layout: "steps"` to buy the third column forces number badges and drops images, which changes what the section means. A 3-item cards grid now renders three across at 1024px and up, spanning the container exactly as `steps` already did.**

The markup was never the problem. `grid.php` has been emitting `data-pp-count` on the card list for months, and the desktop cascade already used it to special-case two-item and four-item grids. Three was simply skipped, so three-item grids fell through to the generic two-column rule. The docs had already promised the correct behavior — `components/grid/README.md` claimed three columns at desktop and the site-validation checklist told agents to verify a "3-column layout" — which means the code was the thing that was wrong, and the fix makes two existing documents true instead of asking anyone to accept a new rule. Counts other than three keep the layout they had; the responsive table in the component README now spells out what each count actually does, rather than promising one number for all of them.

### Fixed
- `grid` `layout: "cards"` with exactly 3 items now renders 3 columns at 1024px and up, instead of 2 columns plus an orphaned third card (`assets/css/components.css`). The row spans the container like `grid--steps`; the 2-item and 4-item layouts are unchanged.

### Docs
- `components/grid/README.md`: the responsive table now describes desktop layout per item count (2 → 2 up, 3 → 3 across, 4 → 2 x 2, other → 2) instead of claiming 3 columns for every count, and notes that a 4-item `steps` grid lays out 2 x 2.
- `ai-instructions/validate-site.md`: the desktop checklist item now states the per-count layout an agent should expect.

### Tests
- `tests/js/css-lint.test.js`: pins that the 3-item rule declares 3 columns inside the desktop media block, carries no narrowing `max-width`, and is declared exactly once, plus scope guards holding the 2-item, 4-item, and `steps` layouts unchanged.
- `tests/ComponentPropsTest.php`: pins that `grid` emits `data-pp-count`, the attribute the desktop cascade selects on.

---

## [v0.16.70] — 2026-07-11 — the docs stop counting tests, because the count was always going to be wrong (#250)

**`README.md` advertised "34 E2E specs," "1230 PHP tests," and "350 JS tests." The real numbers were 48, 1479, and 409. `AI_RULES.md`, which an AI agent reads as instructions, carried the same wrong E2E figure. The README also told readers that a broken-media validation check was "currently quarantined (issue #83)" — that issue closed on 2026-07-07 and the test has been running ever since. Every hardcoded count is now gone from the live docs, replaced by the coverage areas they were supposed to be summarizing, and a lint guard keeps them from coming back.**

The counts were not wrong because someone was careless. They were accurate the day they were written and went stale the next time anyone added a test, which is the only thing a hardcoded count can do. The proof arrived mid-fix: while this issue sat in the queue the E2E figure drifted again, from 47 to 48, because the token-concurrency repair landed a new test. Refreshing the numbers would have bought a few days and recreated the same defect, so the docs now describe *what* is covered — the serialization gate, the action-layer CLI, post-apply validation, AI chat streaming, concurrent token writes behind a real MySQL advisory lock — and leave the arithmetic to the test runner, which is the only thing that can count correctly.

The stale quarantine claim was the more dangerous half. It is an instruction, not a statistic: an agent reading `README.md` and hitting a genuine broken-media failure had written permission to dismiss it as a known, expected quarantine. That sentence is deleted.

The `Tests` badge is the deliberate exception. It reads "1936+ passing" — a floor claim, not an exact count, so it stays true as tests are added and only needs attention if the suite shrinks. The new lint strips shields.io badge lines before scanning, so the exemption is explicit rather than accidental.

### Fixed
- **`README.md` no longer misstates test coverage.** The three suite ledes describe coverage areas and assert no count; the `Tests` badge is refreshed to a `1936+` floor claim.
- **`README.md` no longer claims a quarantined broken-media check.** #83 closed 2026-07-07 and the test is live (`tests/e2e/validation.spec.ts`).
- **`AI_RULES.md` no longer feeds a wrong E2E count to agents.** The `npm run test:e2e` comment keeps its coverage-area list and drops the number.

### Docs
- The E2E coverage list now names AI chat streaming/apply, which the suite has covered for some time without saying so.

### Tests
- **New: `tests/js/docs-lint.test.js`** — a regression guard, in the same shape as the existing CSS lint. It bans count-shaped claims in `README.md` and `AI_RULES.md`, bans a quarantine claim that names an issue number, and pins that the coverage areas survive, so the count lint cannot be satisfied by gutting the testing docs.
- **The guard proves it can fail.** It self-tests that its own pattern fires on every historical stale string ("1230 PHP tests", "34 E2E specs", "34 specs:") *and* on the phrasings this change introduces ("1479 PHP unit tests"), while staying silent on legitimate numbers like "20 typed actions" and the real "WordPress 7.0" pin. A lint that cannot fail is decoration.
- Historical records are deliberately not linted. The `readme.txt` changelog and `CHANGELOG.md` entries state what shipped at a given tag; their counts were true then, and rewriting them would falsify the record.

## [v0.16.69] — 2026-07-11 — the token-concurrency release gate is green again: a permanently-red E2E test now asserts what it was written to assert (#240)

**The nightly E2E suite carried a deterministically red test — "a writer fails closed while another connection holds the lock" — and it had nothing to do with the behavior under test. The contender died in *setup*, several steps before the contended write it exists to exercise. A release gate that always fails teaches everyone to ignore it, so the signal it was supposed to provide for concurrent token writes was worth nothing. The test now reaches the contended `execute`, and a second test pins the fail-closed preflight behavior that the old test was tripping over by accident.**

The trigger was a correctness fix, not a regression. Since the fail-closed snapshot work, `wp pp apply preflight` reads its pre-apply token baseline through `pp_snapshot_token_overrides()`, which takes the *same* advisory lock the write path takes and fails closed when it is contended. The test preflighted its contender *after* the lock holder had already acquired the lock, so preflight could never succeed and the run aborted with "PREFLIGHT was not recorded." The product was behaving exactly as designed; the test was asking for the impossible. The contender's `operate inspect` and `apply preflight` now run before the holder takes the lock, where the baseline read succeeds. That ordering is load-bearing and commented as such: a run's preflight coverage does not expire when the lock is later taken (run state lives for two hours), and the holder never mutates tokens, so the baseline captured up front is still the correct one at execute time.

The holder's hold duration was also a guess, and a fragile one. It slept for the lock timeout plus three seconds, measured from a marker written *before* the contender's container had even booted — so on any machine where `wp-env run cli` took longer than three seconds to start, the holder released the lock before the contender's `GET_LOCK` wait expired, the contender acquired it cleanly, and the test failed as a *false* failure. The holder now waits for a `done` marker and holds until the contended command has actually resolved. That cannot deadlock, because the contended command completes on its own `GET_LOCK` timeout rather than on the lock being released. The remaining bound exists only to reap a hung contender, and it is derived from the install's real `_pp_token_lock_timeout()` instead of a hardcoded number, so raising `PP_TOKEN_LOCK_TIMEOUT` cannot silently reintroduce the early-release failure.

### Fixed
- **The token-concurrency contention test no longer aborts in setup (#240).** `operate inspect` + `apply preflight` + the baseline read move above the holder's lock acquisition, so the contended `execute` under test is actually reached (`tests/e2e/token-concurrency.spec.ts`).

### Tests
- **The contention test asserts *why* the write failed,** not merely that it failed. It pins the action's stdout contract (`Failed to write token override`) rather than the `lock busy` line, which only exists in an `error_log()` call and would vanish the moment `WP_DEBUG_LOG` redirected it. A bare `ok === false` would also be satisfied by a missing preflight or a bad parameter — the exact false-signal class this test exists to rule out.
- **Deterministic holder handshake.** The guessed fixed sleep is replaced by a `done`-marker wait bounded by `_pp_token_lock_timeout() + 35s`, and the marker is written on every failure path so a failing test cannot leave the holder squatting the install-wide advisory lock and cascade into the next test.
- **New: `apply preflight` fails closed under a held lock.** Pins that a contended preflight reports the atomic-baseline failure, records no PREFLIGHT step, and that a subsequent `execute` is therefore refused and writes nothing. The old test exercised this path by accident; now it is asserted on purpose instead of being lost when the ordering changed.

## [v0.16.68] — 2026-07-11 — `theme: "inverted"` and background-image sections render readable text on desktop (#222, #248)

**`theme: "inverted"` painted a dark background and left the text dark above 768px, so a documented, first-class prop produced an unreadable section at roughly 1.1:1 contrast — and `wp pp validate page` reported success. Mobile rendered correctly, which made the failure invisible to any screenshot check taken at 375px. Sections and CTAs with a `background_image` had the identical defect over their dark overlay, contradicting the schema's promise that "text automatically uses light colors for contrast." Both now render readable text at every viewport, with no per-instance style slots required.**

The cause was a cascade collision, not a color choice. The "Premium body-section typography" block declares `color` on `main > .grid .grid__heading` and its siblings at specificity `[0,2,1]`, which outranks every theme-variant selector (`.grid--inverted .grid__heading` is `[0,2,0]`). Those desktop rules were introduced so per-instance heading-color slots survive them (#86), but they hardcoded `var(--color-text)` as the fallback — silently overriding the theme variant's own `var(--color-bg)` fallback. No selector a theme can write will ever win that comparison, so the fix does not fight the cascade: each dark-surface variant now sets an inheritable, component-scoped `*-theme-color` default, and every color declaration for the affected elements resolves `slot → theme default → global token`. Custom properties resolve at use-time, so whichever selector wins, the theme still supplies the right default and the per-instance slot still beats both. Non-inverted instances are unaffected: the theme variable is unset, and the chain collapses to the exact prior fallback.

`grid.subheading` was broken at *every* viewport, not just desktop — `.grid--inverted` had no subheading rule at all and, unlike `.pp-section--inverted`, did not override `--color-muted`, so it rendered muted-dark on the dark background at roughly 2.4:1. It is fixed here too, which changes rendering below 768px as well. Inverted grid *cards* keep a light background, so their text correctly stays dark and is deliberately excluded from the theme chain; a test pins that, since theming it would be the inverse bug.

Accepted limitation, recorded for the future: an author who sets `theme: "inverted"` and then overrides the background slot (`--grid-bg`, `--cta-bg`) to a *light* color now gets light-on-light text on desktop, matching what mobile already did. Declaring an inverted theme and then inverting its background back is a contradictory instruction; the text slot must be set alongside it. Picking a contrast-safe color automatically is out of scope by repo rule — color values belong in tokens, never baked into component CSS.

### Fixed
- **`theme: "inverted"` renders dark-on-dark text at ≥768px (#222).** `grid.title`, `grid.subheading`, `section.title`, `section.body`, and `cta.body` resolve `slot → theme default → global token`. `.grid--inverted`, `.pp-section--inverted`, and `.cta--inverted` supply the defaults (`assets/css/components.css`).
- **`section.background_image` / `cta.background_image` render dark-on-dark text at ≥768px (#248).** The same desktop rules outranked `.section--has-bg-image` / `.cta--has-bg-image`, so title and body copy fell back to the light-theme token over the dark overlay. Both variants now set the same theme defaults, making the schema's automatic-contrast promise true.
- **`grid.subheading` was unreadable under `theme: "inverted"` at all viewports** — no inverted rule existed for it. Now themed like every other inverted text element.

### Tests
- **Cascade regression guard** (`tests/js/css-lint.test.js`): pins the full ordered fallback chain for all five themed elements at both the base rule and the desktop rule, asserts each dark-surface variant declares its theme defaults, and asserts the desktop typography rule still targets the element — so a selector reshape cannot silently drop the guard. Reverting `components.css` fails 13 of these assertions.
- **Inverse-bug guard:** inverted grid card text must resolve to a global token and never a theme variable, and `.grid--inverted` must declare no `--grid-item-*` default.

## [v0.16.67] — 2026-07-11 — design tokens can reference other tokens: color values accept `transparent`, `currentColor`, and `var(--token)` (#230)

**The color validator rejected `var(--token)`, `transparent`, and `currentColor` — so a design token could not reference another token even though the product's own defaults do (`--text-meta-color` ships as `var(--color-muted)`), and a transparent outline-button background required the `rgba(0, 0, 0, 0)` workaround. Brand colors had to be duplicated as literal hex into every style slot that wanted to follow the accent, turning per-instance `style` blocks into exactly the hidden design layer the token system exists to prevent. Color values now accept the two CSS color keywords and a single bare reference to a registered color-typed token.**

The reference grammar is strict on purpose: `var(--color-accent)` exactly — no fallback, no nesting, no whitespace — so the `var(--x, url(evil))` fallback-smuggling shape structurally cannot validate, and the `{};<>` injection guard upstream is unchanged. A reference must point at a token that exists in the registry AND is itself color-typed: a color slot resolving to `0.25rem` is guaranteed-invalid CSS the browser silently drops, the same class the length validator already rejects. Reference cycles are rejected at both write surfaces — `update_design_token` walks the effective reference chain (defaults merged with overrides) and refuses a value that loops back to the token being set, and `reset_design_token` applies the same walk to the base.css default it would restore, so a reset can never quietly reintroduce a cycle. Both keywords and references apply to design tokens and per-instance component style slots alike; inside `linear-gradient()`/`radial-gradient()` functions `var()` stays rejected. Restore surfaces the new rule through the shared validation engines (#233): restoring a history snapshot with a dangling `var()` reference succeeds and reports an `invalid_style_value` finding instead of blocking undo. Two invariants are now test-pinned: base.css `var()` defaults must be bare, same-type, acyclic references, and component style-slot names must stay disjoint from registry token names.

Known accepted limitations, documented for the record: two concurrent applies can in principle race the cycle check (validation runs outside the advisory write lock; styling-only impact, recoverable with one concrete-value apply), and family derivation (`--color-accent-hover` etc.) silently no-ops for non-hex base values — pre-existing behavior that `rgb()`/`hsl()` values already had.

### Fixed
- **Color values reject `transparent`, `currentColor`, and token references (#230).** `_pp_validate_color()` (`lib/apply.php`) accepts the two CSS color keywords (case-insensitive) and a single bare `var(--token)` reference parsed by the new shared `_pp_parse_token_reference()` helper (`\z`-anchored so a trailing newline cannot slip through) — the referenced token must be registered and color-typed. Product defaults that ship as `var()` (`--text-meta-color`, `--text-kicker-color`) are settable/re-settable again.
- **Reference cycles fail closed at both write surfaces.** `_pp_check_token_reference_cycle()` walks effective values (defaults merged with DB overrides), rejects direct and indirect cycles in `update_design_token`, rejects a `reset_design_token` whose base.css default would reintroduce a cycle, and rejects a reference into a pre-existing foreign cycle instead of looping to true.
- **Gradient stop parser** dropped its now-redundant `transparent` special case (the color validator covers it; `currentColor` stops become valid); `var()` inside gradient functions stays rejected. `invalid_color`/`invalid_gradient` error messages enumerate the new accepted forms.

### Docs
- `lib/ai-context.php` style-slot value rules and `ai-instructions/style-component.md` slot-types table document the new color grammar — the AI chat is told the forms are representable instead of refusing to propose them (the slot table's `var(--color-bg)` example was aspirational before; it is now true).

### Tests
- 31 new PHPUnit tests across `tests/ApplyTest.php`, `tests/ActionsTest.php`, `tests/ComponentPropsTest.php`, `tests/SchemaValidationTest.php`: every accepted/rejected shape from the issue's acceptance criteria (keywords, case variants, fallback/nesting/multiple/case-strict/whitespace/newline rejections, unknown and non-color-token rejections), cycle rejection (direct, indirect via shipped default, through stored overrides, foreign-cycle fail-closed, reset reintroduction, non-cycle chain accepted), style-slot and gradient-union surfaces, render-output pins (`pp_render_style_vars` emits references unchanged), the #233 rider (restore of a dangling-reference snapshot succeeds and reports `invalid_style_value`), and two new library invariants (base.css `var()` defaults bare/same-type/acyclic; slot names disjoint from token names).

## [v0.16.66] — 2026-07-11 — preflight stops claiming "no filesystem writes" for `import_media` — it checks the uploads directory instead (#229)

**`wp pp apply preflight --apply=import_media` reported `theme_writable: pass` with "Skipped: planned applies are database-backed (no filesystem writes)" — about the one apply in the set that writes to the filesystem. `import_media` sideloads into `wp-content/uploads/YYYY/MM/`, and on a host where uploads isn't writable by the CLI user the apply then failed at execute with a raw WordPress error. Preflight exists to tell an operator "it is safe to proceed"; here it made a specific, false claim. Media-target applies now get a real `uploads_writable` check, error-grade, fail-closed.**

The check mirrors execute-time `wp_mkdir_p()` semantics instead of naively testing one directory: it walks from the dated `YYYY/MM` path to the deepest existing ancestor and requires a writable directory there. That makes it right in all four states that a single `is_writable(basedir)` gets wrong: a fresh site whose `uploads/` doesn't exist yet passes (WordPress creates the tree), an existing dated dir is checked directly, an unwritable intermediate (`uploads/2026` rsync'd `0555` under a writable `uploads/`) fails, and a regular file squatting on a path segment fails instead of green-lighting a doomed apply off the writable grandparent. The `theme_writable` skip message no longer asserts "no filesystem writes" when a media apply is planned — it points at `uploads_writable`.

### Fixed
- **Preflight asserted "no filesystem writes" for a filesystem-writing apply (#229).** `pp_preflight()` (`lib/operate.php`) resolves the planned apply's target type once; `media`-target applies route to the new `uploads_writable` check (deepest-existing-ancestor walk, non-directory path-segment detection, `wp_upload_dir()` error propagation, empty-path fail-closed) while `file`-target routing is unchanged.
- **Stale `preflight` command docblock** (`lib/cli.php`) still listed the removed backup-directory check and omitted the surface check; the check list is now accurate and names both writability checks.

### Docs
- `docs/reference-apply-cli.md`: `uploads_writable` row in the preflight checks table, updated `--apply` option description, and a new "Uploads writability (#229)" section documenting the ancestor-walk semantics.

### Tests
- 10 new PHPUnit tests (`tests/OperateTest.php`): writable/unwritable basedir, dated-path precedence (writable and unwritable), unwritable intermediate ancestor, fresh-site missing uploads tree, blocking regular file on a path segment, `wp_upload_dir()` error propagation, unresolved empty path, absence of the check for option-backed/no-apply preflights, and the corrected `theme_writable` message — every failure arm also pins `ok: false` (error-grade).

---

## [v0.16.65] — 2026-07-11 — `apply preflight` stops printing `{"ok": true}` for a preflight that never recorded itself (#227)

**The preflight gate's JSON — the machine-readable contract an AI operator branches on — could report success for a preflight that did not happen. Pass an unminted run token and the command printed `{"ok": true, "checks": [...]}` to stdout, then died with `Error: Could not record PREFLIGHT state`; a consumer parsing the JSON concluded the gate was open and hit a contradictory refusal on the very next command. The success payload is now emitted only after every recording step has succeeded, so `ok: true` means the gate is genuinely unlocked.**

All three post-check failure exits (contended/corrupt token baseline, unrecordable PREFLIGHT state, failed pre-run composition snapshot) now route through one emit path that puts `{"ok": false, "error": "...", "checks": [...]}` on stdout and the human-readable detail on stderr, exit 1. `docs/reference-apply-cli.md` documents stdout as the machine channel; `docs/howto-apply-and-rollback.md` now says what `ok: true` guarantees. Three new E2E tests pin the contract: an unminted UUID and a corrupted `pp_token_overrides` row must both produce parsed `ok: false` JSON and never a success payload; the minted-token test proves `ok: true` by clearing the gate it claims to unlock.

---

## [v0.16.64] — 2026-07-10 — `check page` stops calling auto-generated component ids "stable" — because they aren't (#232)

**Components written without an `id` prop get an auto-generated `pp-<hex8>` id, and a full `update_composition` re-apply from source JSON mints a fresh one every time. A `component_id` you recorded stops resolving, the section-scoped actions' documented targeting key silently rots — and `wp pp check page` reported "all components have stable IDs" anyway. The declarative re-apply workflow is the one `composition.md` advertises as the AI-native path, so this was the validator certifying exactly the state it exists to catch. `check page` now tells the truth: it warns per component whose id is auto-generated, names the fix (author an explicit `id`), and reserves its success line for pages where every component actually has one.**

The duplicate-type ambiguous-targeting check is alive again for the same reason. Id injection at write time fills every persisted entry, so a check keyed on "has an id" could never fire on a real page — two same-type components with only generated ids sailed through as "no ambiguous targeting." Generated-pattern ids no longer count as stable, in `check page` and `wp pp validate site` both.

Nothing about the write path changed: ids are injected exactly as before, in-place actions (`update_component`, `add_component`, reordering) still round-trip them untouched, and a stale id still fails loudly with `component_not_found`. What changed is that every surface describing the contract — action descriptions, `AI_CONTEXT.md`, `website-building.md`, `AI_RULES.md` — now distinguishes authored ids (durable) from auto-generated ones (regenerated on full re-apply), and the `pp-<hex8>` shape is documented as reserved for the generator.

### What changed for you

| Before | After |
|---|---|
| `check page` said "all components have stable IDs" on a page whose ids change every re-apply | It warns per affected component, with the remediation |
| Duplicate same-type components with generated ids passed the ambiguous-targeting check | They are flagged — generated ids don't disambiguate durably |
| Docs said ids "survive reordering, insertion, and deletion" unconditionally | Docs scope the claim: authored ids are durable, generated ids are not |
| No way to tell a generated id from an authored one | `pp-<hex8>` is the reserved generated shape, detected by `pp_is_generated_component_id()` |

### Fixed
- **`wp pp check page` certified non-durable ids as stable (#232).** New `pp_find_generated_component_ids()` (`lib/guardrails.php`) flags components whose persisted id is absent or matches the reserved auto-generated `pp-<hex8>` shape; `check page` (`lib/cli.php`) prints one warning per finding and only prints its success line when styling warnings, smells, and generated-id findings are all empty.
- **`pp_validate_composition_styling()` was unreachable on persisted pages.** Generated-pattern ids now count as "no stable id" via the shared `_pp_component_durable_id()` classifier, so the duplicate-type ambiguous-targeting warning fires again after write-time id injection. Non-scalar `component`/`id` values from corrupt rows are coerced defensively (same guard class as #233) instead of fataling on PHP 8 or interpolating "Array" into CLI output.
- **The generator and detector are pinned together.** The id recipe is extracted to `pp_generate_component_id()` (`lib/wp.php`) and a drift-guard test asserts the production generator's output always matches the detector — a format change on either side fails the suite. The detector regex uses `\z`, not `$`, so a trailing newline can't classify a distinct id as generated.
- **Docs claimed unqualified stability.** `website-building.md` (rewritten "Component IDs: authored vs auto-generated" section with remediation guidance), `AI_CONTEXT.md` (action table, selector table, guardrails inventory), `AI_RULES.md`, `validate-site.md`, `style-component.md`, and the `remove_component`/`update_component` action descriptions now state the durability contract; the misleading write-site comment in `pp_update_composition()` is corrected.

### Tests
- 13 new PHPUnit tests (`tests/GuardrailsTest.php`): detector boundary cases (incl. trailing newline and the `'0'` falsy-id boundary), production-generator drift guard, duplicate-type flagging with generated ids, authored-id regression fixtures, finder shape pins, corrupt-row guards, and an end-to-end write-path test proving a full re-apply regenerates generated ids while authored ids survive.

---

## [v0.16.63] — 2026-07-10 — Restoring an old version of a page now tells you what that version breaks, instead of reporting a clean success (#233)

**Undo always works. That is the point of undo, and it is why `restore_composition` never asks a validator for permission — a rule that landed after you saved a page must not be the reason you can't get that page back. But restore was also silent. It replayed a stored snapshot through the non-validating writer, said `ok: true`, and left you to discover on the next validator run that the page you just restored renders the site header twice. A mutation surface reporting unqualified success for a composition every current rule rejects is the same false pass v0.16.62 closed for `nav`/`footer`. Restore now restores, reports, and normalizes: the write still lands, nothing is stripped from your history, and the result carries a `findings` array describing exactly what today's rules say about what came back.**

The findings come from the two engines that already own the rules, `pp_validate_composition_errors()` and `pp_validate_composition_smells()`. There is no restore-specific validator, so every rule added to those engines from now on is inherited by restore's report for free. That is the actual value here: the rule is written once. `wp pp action preview restore_composition` computes the identical findings and writes nothing, so an agent sees the remediation before it commits to the restore rather than after.

Pages saved before the `variant` → `layout`/`theme` rename are quietly translated to the current shape as they come back. That is decoding, not a rewrite of intent: no component is added, removed, or reordered, and site chrome sitting in an old snapshot is preserved and reported, never silently deleted. Your history is a record, not a draft.

In the AI chat, "Undo these changes" still succeeds on a snapshot current rules reject. It now lists what it found underneath, as warnings. An undo that worked never reports itself as an undo that failed.

### What changed for you

| Before | After |
|---|---|
| Restoring a pre-#223 snapshot returned a bare `ok: true` | The result carries `findings` naming every violation |
| You learned the page was broken on the next `wp pp check page` | You learn it in the restore's own output |
| `preview` gave no hint the restore would produce a broken page | `preview` reports the same findings and writes nothing |
| A pre-rename snapshot came back still keyed on `variant` | It is decoded to `layout`/`theme` on the way in |
| Only the first validation error was ever reported | Every violation is reported in one pass |
| The chat's "Undo" said only "Changes undone" | It lists the issues it found, as warnings, not a failure |

### Fixed
- **`restore_composition` reported success for a composition current validators reject (#233).** Its `execute` and `preview` (`lib/actions.php`) now return a `findings` array: `['type', 'severity' => 'error'|'warning', 'message', 'index']`, empty when the snapshot is clean. `severity: error` means a normal write of this composition would be rejected; `severity: warning` is advisory. The new `_pp_composition_findings()` reads the two shared engines and never derives its own view of the rules. `findings` is a restore-specific key: the canonical `_pp_action_result()` envelope shared by every action is unchanged, and it is deliberately not named `validation`, which the AJAX layer already uses for `pp_post_apply_validate()` output.
- **Only the first validation error was ever reported.** New `pp_validate_composition_errors()` (`lib/admin.php`) collects every violation, at most one per composition item so a malformed item cannot cascade. `pp_validate_composition()` now delegates to it and returns `$errors[0]`, preserving its exact first-error-wins contract, code, message, and document order for the write-time callers that depend on it.
- **A corrupt composition could crash the smell checker.** A row holding an array in its `component` key made `pp_validate_composition_smells()` (`lib/guardrails.php`) emit an `Array to string conversion` warning and then throw a `TypeError`. Because restore's findings run these rules over arbitrary history snapshots, that fatal would have fired *after* the write landed, showing an error for an undo that actually succeeded. Malformed items are now skipped by the smell checker and reported as `Item %d has a non-scalar "component" key.` by the validator, instead of being cast to a component literally named `Array`.

### Changed
- **Legacy shape is canonicalized on restore.** The snapshot passes through `pp_normalize_composition()` before it is written, so `type` → `component` and the pre-v0.16.59 `variant` → `layout`/`theme` are decoded on the way in. `preview`'s `after` field is the normalized composition, so it shows what `execute` would actually persist.

### For contributors
- **Restore reports, never blocks** is now load-bearing invariant 2c in `docs/AI_IMPLEMENTATION_RECIPES.md`, alongside a correction to 2b: `pp_validate_composition()` rejects chrome on every *write-time* path, and `restore_composition` is the one deliberate exception. `pp_update_composition()` (`lib/wp.php`) stays a non-validating writer; the action layer owns validation. Recipe C now warns that the smell checker runs over compositions that never passed write-time validation, so a check declaring `string $component` and receiving an array is a fatal, not a warning.
- The `variant` → `layout`/`theme` shim (`pp_migrate_legacy_variant_keys()`) carries a removal-dependency note. Restore relies on it to decode pre-v0.16.59 snapshots still sitting in live history rings, which v0.16.59's migration plan never covered — it addressed stored `_pp_composition`, not `_pp_composition_history`. Deleting the shim at the v1.0.0 tag without migrating those rings makes an old page come back subtly wrong rather than loudly wrong. `ActionsTest::testRestoreNormalizesLegacyVariantSnapshot()` fails the moment the shim goes.
- `docs/reference-apply-cli.md` documents the `findings` shape and the report-don't-block policy; `docs/howto-apply-and-rollback.md` shows how to read it; `AI_CONTEXT.md` records that the canonical action-result keys are a minimum, not an exhaustive set.
- Follow-up filed, not fixed here: the run-scoped `wp pp apply restore-composition` reverts through the same non-validating writer and reports success the same way (#236).

---

## [v0.16.62] — 2026-07-09 — The site header and footer can no longer be composed into a page, so the validators stop certifying pages that render their chrome twice (#223)

**`nav` and `footer` were registered, documented, composable components. They were also rendered on every page by the base template. Put either one in a page composition and the page rendered its header or footer twice — and `wp pp validate page`, `wp pp check page`, and `wp pp validate site` all reported success. The docs walked you into it: the composable-component table listed `nav` and `footer` with their props, and the AI's own component catalog advertised them. An agent following the shipped playbook produced a visibly broken page, and PromptingPress's rendered-validation gate certified it as correct. That false pass is closed. Site chrome is template-owned now: composing it is rejected at write time with a distinct `template_owned_component` error that names the surfaces that actually work, the AI's catalog no longer lists it, and a page that already contains it fails every validator instead of passing them.**

The rejection lives in `pp_validate_composition()`, the action layer's single validating choke point, so `create_page`, `update_composition`, `add_component`, `update_component`, and the composition editor's save all refuse chrome with the same message. Pages that already stored chrome (written before this rule, or through a non-action path) are not silently accepted either: `wp pp check page` and `wp pp validate site` report a `template_owned_component` composition smell, and `wp pp validate page` fails with a matching error rather than rendering the duplicate and calling it clean. Nav readiness stopped being page-scoped: it used to look for a `nav` component inside a composition, which after this change can never appear, so it now diagnoses the chrome the template actually renders — the `primary` and `footer` menu locations, plus a `pp_logo_id` that points at something that is not an image and therefore falls back silently to a text wordmark. Those rows stay warning-grade and never block a mutation.

### What changed for you

| Before | After |
|---|---|
| `[{"component":"nav"}, ...]` saved fine | Rejected: `"nav" is site chrome rendered by the page template...` |
| Three validators reported success on a duplicated-chrome page | `validate page` errors, `check page` and `validate site` flag it |
| The AI's component catalog listed `nav` and `footer` | The catalog lists only the 10 composable components |
| Nav readiness only fired if a page composed a `nav` | Chrome readiness runs on every preflight, including site-scoped ones |
| A bad `pp_logo_id` silently showed a text wordmark | A `nav_readiness` warning names the attachment and the fallback |

To configure the chrome, use the surfaces that were always the real ones: the site logo through the `pp_logo_id` option, and the menus through `create_menu` / `set_menu` / `assign_menu_location`.

### Fixed
- **Composing `nav`/`footer` duplicated the site chrome while every validator passed (#223).** `pp_validate_composition()` (`lib/admin.php`) now rejects template-owned components with a distinct `template_owned_component` `WP_Error`, so the code is machine-distinguishable from `invalid_composition` ("that name is chrome" vs "that name doesn't exist"). New `pp_template_owned_components()`, `pp_is_template_owned_component()`, and `pp_composable_components()` model the distinction that registered ⊋ composable. `pp_validate_composition_smells()` (`lib/guardrails.php`) gains a `template_owned_component` smell so `wp pp check page`, `wp pp validate site`, and `wp pp operate inspect` flag a stored chrome page; its message names `remove_component` and warns that indices shift on each removal. `pp_post_apply_validate()` (`lib/post-apply-validate.php`) raises a `template_owned_component` error so `wp pp validate page` fails, and skipped chrome is excluded from the component-count reconciliation so one defect yields one error instead of a phantom render failure.
- **The AI was told chrome was composable.** `lib/ai-context.php` builds its component catalog and JSON context from `pp_composable_components()` instead of the raw registry, so `nav`/`footer` no longer appear in what the AI reads. The composition editor's JSON autocomplete hides them, and its client-side validator rejects them with the same message the server would give, rather than a misleading "Unknown component".
- **A non-image `pp_logo_id` failed silently.** `pp_check_nav_readiness()` (`lib/wp.php`) reports a warning when the option holds a positive attachment id that is not an image, which `pp_resolve_logo()` quietly falls through to a text wordmark for. A cleared option (`''`, `'0'`, `0`, `false`) is a deliberate wordmark and reports nothing.

### Changed
- **Nav readiness is site-scoped, not composition-scoped.** `pp_check_nav_readiness()` no longer takes a composition argument. It diagnoses the locations `templates/base.php` renders (`pp_template_owned_menu_locations()`), so it keeps working now that chrome cannot be composed. Preflight check 8 (`lib/operate.php`) runs unconditionally: chrome renders on every page, and a site-scoped action such as `update_site_option` on `pp_logo_id` has no `post_id` yet changes the chrome. A registered location the template never renders stays silent.
- **The footer logo has no supported surface.** `footer.show_logo` was only reachable by composing a `footer`. `components/footer/README.md` and `ai-instructions/set-logo.md` now say so plainly instead of instructing you to compose one. Tracked in #234.

### For contributors
- Docs corrected wherever they still presented chrome as composable: `README.md`, `AI_CONTEXT.md`, `ai-instructions/composition.md` (new "Site chrome" section), `components/nav/README.md`, `components/footer/README.md`, `ai-instructions/set-logo.md`, `ai-instructions/website-building.md`, `ai-instructions/validate-site.md` (nav readiness rewritten as site chrome readiness), `ai-instructions/playbook-create-page.md`, and `docs/reference-apply-cli.md`, which now documents the `template_owned_component` error code alongside `composition_conflict`.
- Two drift guards in `tests/NavReadinessTest.php` read `templates/base.php` back and assert that both the rendered chrome component set and its menu locations match the declared lists — adding a third chrome render to the template without declaring it fails the suite, which is the exact shape of the bug this release fixes. `SchemaValidationTest::testEveryComposableComponentDeclaresARequiredProp()` pins the component-library invariant that the composition editor's serialization-invariant E2E now depends on.
- **Registered ⊋ composable** is now a recorded invariant, not tribal knowledge. `docs/AI_IMPLEMENTATION_RECIPES.md` gains it as load-bearing invariant 2b and folds the composable-vs-chrome decision into Recipe D; `ai-instructions/add-component.md` step 6 warns that calling a component from `templates/base.php` (and only `base.php`) makes it chrome and obliges you to declare it. Auto-loading makes a component renderable, never composable.
- Follow-ups filed, not fixed here: `restore_composition` can replay a snapshot that current validators reject (#233), and the footer logo has no supported surface (#234).

---

## [v0.16.61] — 2026-07-09 — Page content is now reversible: every composition write keeps its prior state, and you can undo one page or a whole run (#133)

**Design-token changes have always been reversible — snapshot at preflight, `wp pp apply restore`. Page compositions, the actual content, had nothing: `update_composition` and `remove_component` overwrote in place, WordPress revisions don't capture the meta they live in, and an AI proposal that replaced a page with a worse one lost the old page for good. That gap is closed. Every composition write now pushes the prior state onto a bounded per-post history ring (last 10) before overwriting, under the same per-post lock that guards the freshness marker, so history never interleaves with a concurrent write. A new `restore_composition` action rewrites one page back to any prior entry; a new `wp pp apply restore-composition` reverts every page a run changed to its pre-run state and leaves other runs' pages alone; and the AI chat shows an "Undo these changes" link after a composition proposal applies. A restore is itself a conflict-checked write that lands its own history entry, so it can be undone in turn.**

The history ring lives in `_pp_composition_history` post meta and is captured inside `pp_update_composition()`'s existing per-post advisory lock, reading the prior stored JSON straight from the database so the captured bytes are exactly what a restore replays (an `update_composition` then `restore_composition` returns the byte-identical prior composition). Run-scoped restore mirrors the token snapshot/restore subsystem: preflight freezes each touched page's pre-apply composition content, `action execute` / `operate patch` record the touched post ids, and `restore-composition` reverts exactly that set, fail-closed if the run state is missing/corrupt/from another install. The `restore_composition` action selects a target with `steps_back` (1 = most recent prior state) or an absolute `history_index`, honors the `expected_version` compare-and-swap (#13), and follows the standard validate / preview(never writes) / execute contract. `wp pp operate composition-history <page>` lists the ring so you know what to restore.

### Added
- **Composition history ring + restore (#133).** `pp_update_composition()` in `lib/wp.php` pushes the prior composition onto a bounded (`pp_composition_history_max()` = 10) `_pp_composition_history` meta before each write, inside the per-post lock; new `pp_get_composition_history()` reads it defensively. A new `restore_composition` action (`lib/actions.php`, page scope, `steps_back` / `history_index` / `expected_version`) rewrites a page to a prior entry through the validate/preview/execute contract. `lib/operate.php` gains run-state `touched_post_ids` + a pre-run `composition_content_snapshot` (both mirroring the token trail) and `pp_operate_restore_run_compositions()`; `lib/cli.php` adds `wp pp apply restore-composition --run-id`, records touched posts on `action execute` / `operate patch`, freezes the content snapshot at preflight, and adds the read-only `wp pp operate composition-history <page>`. The AI chat (`assets/js/pp-ai-chat.js`) renders an "Undo these changes" link after a single-page composition proposal, calling `restore_composition` through the existing `pp_ai_execute` handler. Coverage: byte-identical restore, bounded-ring eviction, action validate/preview/execute + CAS-conflict, run-scoped restore reverting two pages while a different run's page is untouched (unit), the undo-target helper (JS), and a chat remove-component → Undo → section-reappears E2E.

### For contributors
- `docs/reference-apply-cli.md` and `docs/howto-apply-and-rollback.md` document the composition history/restore surface (`restore_composition`, `wp pp apply restore-composition`, `wp pp operate composition-history`, and the chat Undo affordance) alongside the existing token restore. The history ring reuses the #113 marker and the #13 CAS — no separate identity. Run-scoped restore is the composition analogue of `wp pp apply restore`; both share one run token and the same fail-closed snapshot discipline.

## [v0.16.60] — 2026-07-07 — A composition write is now rejected at the moment it lands if the page changed under it, closing the last gap where two writers could clobber each other (#13)

**The preflight freshness gate (#113) checks the page hasn't changed *before* a mutation runs. That leaves a hair-thin window: a second writer could commit in the instant between that check and the actual write, and the first write would still overwrite it. That gap is now closed. Every composition-mutating write threads the version it was based on into the single write choke point, which re-reads the version fresh from the database *inside the per-post advisory lock* and refuses the write with a `composition_conflict` error if it moved. Because the compare and the write happen under the one lock, there is no instant left for a lost update to slip through. The dashboard composition editor now participates too: it sends the version it loaded, refreshes it after each save, and shows a reload prompt instead of silently clobbering a change made by the AI chat, another tab, or a CLI edit while the editor was open.**

`pp_update_composition()` gains an optional `expected_version`: when supplied, it does an atomic compare-and-swap under the existing per-post lock (the fresh in-lock version read added by #113), returning a `composition_conflict` `WP_Error` and writing nothing on a mismatch — neither the composition nor either marker moves. It is optional by design: new-page creation, the homepage seed, and legacy direct callers omit it and write unconditionally (documented back-compat), while the AI CLI agent path, the AI chat, and the composition editor all supply it. The 6 composition-mutating actions accept it as an optional `int` param; the CLI `action execute` and `operate patch` paths pass the run's own recorded freshness baseline so the agent write is a true compare-and-swap rather than check-then-write; the `pp_save_composition` / `pp_publish_page` AJAX handlers read it from the request (hardened to accept only a clean non-negative integer) and thread it, and return the new version so the editor stays in sync. Action results now carry a structured `error_code` so the editor and chat detect a conflict by code, not by parsing the message.

### Added
- **Write-time compare-and-swap on composition writes (#13).** `pp_update_composition($post_id, $composition, $expected_version = null)` in `lib/wp.php` performs the CAS under the per-post lock; the 6 mutating actions in `lib/actions.php` register an optional `expected_version` and thread it (plus a shared `_pp_action_expected_version()` / request-parsing `_pp_expected_version_from_request()` and an `error_code` on action results); `lib/cli.php` and `pp_patch_composition()` in `lib/operate.php` pass the recorded baseline as the CAS version; `lib/admin.php` threads it through both AJAX handlers, localizes the loaded version into the editor, and returns the new version on save/publish; `assets/js/pp-admin-editor.js` sends it, refreshes it, and surfaces a `composition_conflict` as a reload prompt. New coverage: CAS match/mismatch/omitted/legacy-zero and markers-unchanged-on-conflict unit tests, action-layer conflict + back-compat + request-hardening tests, an `operate patch` CAS test, and an editor-conflict E2E proving an external write survives a stale editor save.

### For contributors
- `docs/reference-apply-cli.md`, `docs/operating-loop-safety.md`, and the `AI_CONTEXT.md` function table document the `expected_version` compare-and-swap, its opt-in/back-compat semantics, and the structured `composition_conflict` code. The in-admin AI chat (`pp_ai_execute`) threads `expected_version` when supplied but does not yet auto-populate it from inspect context — a candidate follow-up; the headless CLI agent path is fully covered.

## [v0.16.59] — 2026-07-07 — The composition API now names structure `layout` and color `theme` everywhere — the overloaded `variant` prop is retired before v1.0.0 (#69)

**`variant` used to mean two different things depending on the component. On `hero`, `grid`, `cta`, and `testimonials` it selected a structural mode (`hero.variant: "split"`); on `section`, `stats`, `logos`, and `embed` it selected a color/tone preset (`section.variant: "dark"`). An AI agent authoring a page had to memorize which meaning applied per component. Before the public v1.0.0 API calcifies, the naming is now consistent: structure is always `layout`, color/tone is always `theme`, and no component overloads one key for both. A one-time normalization shim rewrites any legacy `variant` on stored or AI-authored compositions to the new key on read and on save, so existing pages render unchanged — but it is deliberately NOT a permanent alias and is removed at the v1.0.0 tag.**

The rename touches all 8 component `schema.json` contracts and their renderers: `hero` (`layout` = `left|centered|split|cover`), `cta` (`layout` = `full-width|inline`), `testimonials` (`layout` = `grid|stack`), and `grid` (`layout` = `cards|steps` — the legacy structural value `default` is renamed to `cards`) move `variant` to `layout`; `section`, `stats`, `logos`, and `embed` move their tone `variant` to `theme` (matching the `theme` prop `grid`/`cta`/`testimonials` already had). CSS class names are value-derived (`hero--split`, `pp-section--dark`), so `components.css` is untouched. The shim, `pp_migrate_legacy_variant_keys()` in `lib/admin.php`, is component-aware (structural → `layout`, tone → `theme`, `grid`'s `default` → `cards`) and is called from both `pp_normalize_composition()` (write/apply path) and `pp_get_composition_result()` (read/render path), plus the composition editor's pretty-print load, so a pre-rename page is consistent everywhere until its next save. `inspect-composition` output now surfaces structural `layout` and tonal `theme` as distinct lines instead of a single `variant`. Every AI-facing doc (`AI_CONTEXT.md`, `README.md`, all `ai-instructions/*.md`, all 8 component `README.md`, `AI_RULES.md`) is updated so no doc says `variant` means different things per component.

### Changed
- **Composition API: `variant` split into `layout` (structure) + `theme` (color/tone), no permanent alias (#69).** 8 `components/*/schema.json` + renderers, the homepage seed (`pp_default_homepage_composition()`), the hero smell check in `lib/guardrails.php`, `inspect-composition` output in `lib/ai-context.php`, and the front-end templates all use the new keys. New unit tests cover the write-path and read-path migration (structural→`layout`, tone→`theme`, `grid` `default`→`cards`, explicit-new-key-wins) and a schema audit asserting no component declares `variant`; the touched E2E specs and the full suite are green.

### For contributors
- The migration is a **transitional shim, removed at the v1.0.0 tag** — `pp_migrate_legacy_variant_keys()` (`lib/admin.php`) and its read-path delegate `pp_migrate_stored_composition()` (`lib/wp.php`) carry `REMOVE AT v1.0.0 TAG` markers. Migrate the dev/poc site compositions, then delete both with the tag. No `variant` alias ships in the v1 public API.

## [v0.16.58] — 2026-07-07 — A composition mutation is now rejected if the page changed since its preflight, not just if a preflight ran (#113)

**The preflight-before-mutation gate (#96) proved that a preflight covering the target *ran* before an `action execute` or `operate patch`. It did not prove the target was *unchanged since*. Between a covering preflight and the action — or across two actions after one preflight — the composition could change through another path (another CLI run, the dashboard editor, the publish flow), so the mutation landed on a different state than was checked. Composition-mutating actions now carry a freshness marker recorded at preflight and are rejected with a distinct conflict error if the live composition no longer matches. Your own run's sequential edits still flow; only a change from another path trips the gate.**

Every page composition now carries a sibling freshness marker: a monotonic `_pp_composition_version` plus a content hash `_pp_composition_hash`, bumped together inside `pp_update_composition()` under a per-post MySQL advisory lock (the composition-write analogue of the #97 token lock — lock-acquire failure propagates as a `WP_Error` rather than a silent non-atomic write, the #200 lesson). The hash is computed on the canonical pre-stable-id form so the auto-injected `props.id` can't false-conflict a page against itself, and the version is read fresh from the database inside the lock so a warmed meta cache can't cause a lost bump. A page-scoped `wp pp apply preflight --post_id=N` records the live marker into run state; a composition-mutating `action execute` (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) and `operate patch` re-read it and reject a stale target with a `composition_conflict` error naming the recorded and live versions. After a successful mutation the run's baseline refreshes to the new marker, so a run's own sequential edits on the same page keep passing while an external interleaved write conflicts. Preview stays read-only and never consumes or records freshness state. Write-time compare-and-swap (rejecting a write that lands between the check and the write itself) is tracked separately as #13.

### Added
- **Preflight freshness for DB-backed composition mutations (#113).** New `pp_get_composition_marker()` / `pp_composition_content_hash()` and a per-post `_pp_with_composition_lock()` (extracted from the shared `_pp_with_advisory_lock()` the token lock now also uses) in `lib/wp.php`; `pp_operate_record_preflight()` gains an optional composition-marker arg and new `pp_operate_get_composition_snapshot()` / `pp_operate_record_composition_snapshot()` / `pp_composition_marker_matches()` in `lib/operate.php`; the `wp pp action execute` and `wp pp operate patch` CLI paths gain the freshness gate plus a post-write baseline refresh. The homepage seed (`lib/setup.php`) now routes through `pp_update_composition()` so it initializes the marker instead of writing meta directly. 39 new unit tests (marker bump/round-trip stability, lock-failure `WP_Error`, fresh-in-lock version read, run-state snapshot record/get/refresh, the pure marker comparator, the action-flag map) plus 6 E2E tests (stale rejection via a second run, same-run sequential mutations passing, `operate patch` rejection, preview never blocked).

### For contributors
- `docs/reference-apply-cli.md` documents the composition freshness gate: the marker, the `composition_conflict` error, the same-run-refresh semantics, and the read-only preview guarantee.

## [v0.16.57] — 2026-07-07 — A database read failure while snapshotting the token-rollback baseline now fails closed instead of quietly deleting tokens on restore (#212)

**Before a design-token apply records its rollback baseline, it snapshots the current `pp_token_overrides` under the token advisory lock. That snapshot read used `$wpdb->get_var()`, which returns `null` both when the row is genuinely absent AND when the database query itself fails. The absent-row path is correct (`[]` is a valid empty baseline); the failed-read path was silently treated the same way, recording `[]` as the baseline. A later `wp pp apply restore` reverts every touched token off an empty snapshot by deleting it, so a transient DB read error during preflight could turn a restore into silent token loss. This closes the third and final door in the #200 (lock failure) / #207 (corrupt row) / #212 (read failure) fail-closed trilogy.**

The strict in-lock reader `_pp_read_token_overrides_locked_strict()` now distinguishes a database read failure from a genuinely absent row via `$wpdb->last_error`: `wpdb::query()` flushes `last_error` to `''` at the start of every query and repopulates it on error, so a non-empty `last_error` immediately after the option `SELECT` means the read failed. The reader returns `null` in that case (checked before the `$raw === null` absent-row branch, since a failed read also yields `null`), which propagates as a `null` snapshot and makes `wp pp apply preflight` hard-error and record nothing rather than freezing a `[]` baseline. A genuinely absent row (query ran, matched nothing, empty `last_error`) still returns `[]` — the #207 contract is preserved. The writer paths are unchanged: the shared `_pp_read_token_overrides_locked()` wrapper still coerces the strict `null` back to `[]`, so a set/clear on a read failure keeps its pre-existing "start fresh" behavior.

### Fixed
- **A DB read failure while snapshotting the rollback baseline fails closed as `null` instead of recording a destructive `[]` (#212).** `_pp_read_token_overrides_locked_strict()` in `lib/wp.php` guards on `! empty($wpdb->last_error)` after the `pp_token_overrides` `SELECT`, returning `null` on a read failure so `pp_snapshot_token_overrides()` fails closed and `apply preflight` refuses to record a baseline. An absent row with empty `last_error` still records `[]`. Four new unit tests in `tests/TokenLockTest.php` cover the read-failure snapshot, the absent-row-still-`[]` distinction, the writer start-fresh boundary on a read failure, and a contract guard proving a stale prior `last_error` cannot poison an absent-row snapshot (the reliance on wpdb's per-query flush).

### For contributors
- `docs/reference-apply-cli.md`, `docs/operating-loop-safety.md`, and `docs/howto-apply-and-rollback.md` now document the read-failure case as the third cause (alongside #200 lock contention and #207 corrupt row) of a `null` fail-closed baseline in `wp pp apply preflight`.

## [v0.16.56] — 2026-07-07 — A corrupted page composition is now flagged, not silently reported as blank (#144)

**A page whose stored composition is corrupted — truncated JSON, a bad encoding, or a shape that isn't a list — used to look identical to a genuinely empty page in every inspection command. An agent that runs INSPECT before editing a page would see a clean "no smells" report and mutate against corrupt data. Corruption now surfaces as a distinct data-integrity signal in inspect, check, and validate, while page rendering stays defensive and degrades to empty rather than fataling.**

`pp_get_composition()` collapsed four different states — absent meta, an empty `[]`, undecodable JSON, and valid-but-non-list JSON — into the same empty array. Since #119, `pp_inspect_site()` reads through it, so a corrupt page reported a clean INSPECT indistinguishable from a blank one. A new state-classifying accessor, `pp_get_composition_result()`, tells the four apart and becomes the single decode owner: `wp pp operate inspect` now carries a `composition_decode_error` field, and `wp pp check page`, `wp pp validate site`, and `wp pp validate page` report the corruption distinctly instead of "no composition." A JSON object (which decodes to an associative PHP array that a bare `is_array()` check would wrongly accept) is now correctly classified as an unexpected shape via a list check, and the falsy-but-present payload `"0"` is no longer mistaken for an absent page. `pp_get_composition()` becomes a thin wrapper that still degrades any corrupt or non-list row to `[]`, so template and front-page rendering never fatal on a bad row.

### Fixed
- **A corrupted composition is surfaced distinctly from an empty one (#144).** `pp_get_composition_result($post_id)` returns `['ok'=>bool,'composition'=>array,'error'=>?string,'raw'=>?string]`, distinguishing absent meta, empty `[]`, `decode_error` (undecodable JSON), and `unexpected_shape` (valid JSON that isn't a list). `pp_inspect_site()` exposes the error as `composition_decode_error`; `wp pp check page`, `wp pp validate site`, and `wp pp validate page` (`pp_post_apply_validate()`) all report the integrity error rather than treating a corrupt page as blank. Rendering paths are unchanged and still degrade to an empty page on a bad row. 24 new unit tests cover every state, the `array_is_list` shim (`pp_is_list()`, PHP 8.0 compatible), the `"0"` falsy-payload trap, an already-decoded associative-array fixture, and the front-page no-fatal regression.

### For contributors
- New `pp_get_composition_result()` and `pp_is_list()` in `lib/wp.php`; `AI_CONTEXT.md` documents the classifying accessor and when to use it over `pp_get_composition()`.

## [v0.16.55] — 2026-07-07 — AI-chat history is now private to each WordPress user, even on a shared browser (#157)

**Two WordPress admins who share one computer login and browser used to see each other's AI-chat conversation history, because the chat was saved under a key tied only to the site, not the user. It's now saved per site and per user, so your chat history stays yours. If a page ever loads without a valid user id, the chat simply won't persist that session rather than fall back to a shared bucket.**

The AI-chat panel saves your conversation in the browser's `localStorage` so it survives a page reload. That entry was keyed by site URL alone (`pp_ai_chat_<siteUrl>`), so on a shared OS/browser profile a second admin opened the panel and read the first admin's history, including apply-confirmation messages that name specific pages and changes. The conversation is now keyed by site **and** the current WordPress user id, so each user's browser-local history is isolated. Because `wp_localize_script` hands the user id to JavaScript as a string, the client validates it as a positive whole number; if it's missing or invalid — which on this `edit_posts`-gated screen means a broken page config, not a real state — persistence fails closed (the conversation lives in memory for that page load only) rather than writing to a shared, unscoped key. The old site-only key is cleared on load.

### Fixed
- **AI-chat conversation history is scoped to site + WordPress user (#157).** The persisted chat is now keyed `pp_ai_chat_<siteUrl>_<userId>`, so two admins sharing a browser profile can no longer read each other's history. The user id comes from the `ppAiChat` page config; the client accepts it only as a positive decimal integer (`wp_localize_script` delivers it as a string) and, when it's absent or invalid, disables save/load/clear for that page load and logs a one-line console warning instead of persisting to a shared bucket. Your existing single-key chat history is cleared once on upgrade (the legacy `pp_ai_chat_<siteUrl>` entry is removed on load); a fresh per-user history starts empty. 15 new unit tests cover cross-user isolation, same-user restore, legacy-key removal, and the full invalid-id set.

### Security
- **Closes a cross-user information-disclosure path and fails closed.** A missing or malformed user id no longer degrades to a shared, unscoped storage key — the exact bucket that leaked history between users — it disables persistence for that session instead. Note: multiple chat tabs open for the same site and user still share one storage entry and can overwrite each other's saved context (single-active-tab assumption; multi-tab reconciliation is tracked in #205).

**Before you apply a design change, PromptingPress freezes a snapshot of your current tokens so it can undo the change later. If that stored token row was corrupt or hand-edited into garbage, the snapshot came back empty — and undoing against an empty snapshot doesn't restore your tokens, it deletes them. The safety check now refuses to record an empty snapshot for an unreadable row, so a later undo can't quietly wipe your design tokens.**

`pp_snapshot_token_overrides()` reads the `pp_token_overrides` row under a lock to freeze an atomic rollback baseline. A hardening in #200 made it fail closed when the lock is contended. This closes the sibling gap: a corrupt, truncated, or hand-edited row that doesn't decode to an array was silently treated as "no overrides exist," so the run recorded an empty `[]` baseline. Because `apply restore` reverts every touched token off an empty snapshot by deleting it, that empty baseline turned a later undo into silent token loss. The snapshot now returns `null` for an unreadable row exactly as it does for lock contention, so `wp pp apply preflight` errors and records nothing rather than arming a destructive rollback. A genuinely empty override set (a fresh install) still records a valid `[]` baseline — empty and unreadable are no longer confused.

### Fixed
- **A corrupt `pp_token_overrides` row fails the preflight closed (#207).** The pre-apply token snapshot now distinguishes an absent row (records `[]`, proceeds) from an unreadable one (corrupt/truncated/non-array → records nothing, errors), so `apply restore` can never delete your touched tokens by rolling back against a baseline that was empty only because the row couldn't be read. The three token writers (`set` / `clear` / `clear-all`) and the revert path keep their existing "start fresh on a missing row" behavior unchanged — only the rollback-baseline snapshot treats an unreadable row as a hard failure. The `wp pp apply preflight` error now names both causes (lock contention or a corrupt row) and points at the fix for each. 6 new PHPUnit tests cover corrupt/truncated rows, serialized scalars, and a valid empty-array row across the snapshot and writer paths.

### Documentation
- The operating-loop safety explanation, the apply-CLI reference, and the apply/rollback how-to now describe the second fail-closed trigger (an unreadable token row) alongside lock contention, so an operator who hits the preflight error knows a persistent failure means the `pp_token_overrides` option itself needs repair. (#207)

## [v0.16.53] — 2026-07-07 — Post-apply site validation now flags a missing local image in any URL shape (#83)

**After an apply, the site check that catches broken images used to miss a same-site image whenever its rendered URL didn't byte-match the uploads base URL — an `http` vs `https` or host mismatch. So a page could render an unresolvable image and still report "validation passed." It's caught now, in every URL shape.**

`pp_post_apply_validate()` re-renders a page after an apply and flags images that point at missing Media Library files (`missing_local_media`). It only treated a rendered image or `background-image` URL as local to verify when the URL was byte-for-byte prefixed by the resolved uploads base URL. A same-site image whose scheme, host, port, or path shape differed even slightly — the exact situation on some setups where the live base URL comes back `https` while the rendered URL is `http` — was classed "external" and skipped, so an unresolvable image passed silently. This reuses the same-site URL matcher introduced in #153 so the post-apply validator and the action-param validator now agree on what "same-site" means.

### Fixed
- **A missing local image is flagged in any URL shape (#83).** The two rendered-HTML scan sites (`<img src>` and inline `background-image:url()`) now classify a same-site image via the origin-aware matcher instead of an exact base-URL prefix, so an unresolvable image written as an absolute-under-baseurl, site-relative `/wp-content/uploads/…`, protocol-relative, or `http`/`https`-swapped URL all correctly surface `missing_local_media`. A genuinely external image (a different host) stays skipped, unchanged. The relative-path derivation is percent-encoding safe, so a valid file referenced with an encoded uploads segment still resolves instead of false-flagging, and a `?ver=` cachebuster on a valid image no longer reports it missing.

## [v0.16.52] — 2026-07-07 — Media-URL validation now catches same-site images in any URL shape, and no longer fails open (#153)

**A same-site image URL that pointed at a non-image attachment used to slip past validation whenever it wasn't written in the one exact canonical form — a relative `/wp-content/uploads/…` path, a CDN/offloaded URL, an `http`/`https` or `:443` variant. Those are all validated now, and a misconfigured uploads path no longer disables the check entirely.**

`pp_validate_action()` rejects a proposed `image_url`/`background_image` that references the site's Media Library but isn't a real image (#124). That guard only fired when the URL was byte-for-byte prefixed by the uploads base URL, so any same-site image written in a non-canonical shape — a site-relative path, a protocol-relative `//host/…` URL, an `http` vs `https` or default-port mismatch, or a CDN/offloaded URL — was treated as "external" and passed through unchecked. Separately, if the uploads base URL was ever empty or filtered away, the whole check short-circuited to "allow everything." This closes both gaps.

### Fixed
- **Same-site media references are validated in any URL shape (#153).** A same-origin uploads URL is now recognized whether it arrives as a site-relative `/wp-content/uploads/…` path, a protocol-relative `//host/…` URL, an `http`/`https`-swapped or explicit default-port (`:80`/`:443`) variant, or a percent-encoded uploads path — each gets the same existence + image-type check that the canonical absolute form always did. A CDN/offloaded URL that still resolves to an attachment is image-checked too. Genuinely external URLs (a different host that resolves to no attachment) stay allowed, unchanged.

### Security
- **Fail-open closed.** An empty or filtered uploads base URL no longer disables validation — same-site-shaped paths are still resolved and rejected when they don't map to a real image, so a misconfigured install fails closed instead of trusting every URL. The image-type check is gated on whether the URL resolves to an attachment, not on how it's written, so a crafted default-port or percent-encoded path can't smuggle a non-image (PDF/video/SVG) attachment past the guard. 16 new PHPUnit tests cover each shape (relative, protocol-relative, scheme/port variants, encoded path, CDN resolve-through, empty-baseurl) across present-image / non-image / absent / external cases. Surfaced by adversarial review during the #153 backlog loop.

### Documentation
- The `AI_CONTEXT.md` media-URL-validation note now describes the broadened same-site matching and the fail-closed behavior, so the site-building model knows a relative or offloaded uploads URL to a non-image will be rejected. (#153)

## [v0.16.51] — 2026-07-07 — Safe-surface redirects so a renamed page's old URL 301s instead of 404ing (#62)

**Renaming a page's slug used to strand its old URL on the 404 page. Now the AI can record a redirect so the old path 301s to the new one, with no theme-file edits and no open-redirect risk.**

`update_page_slug` (#134) let a page's URL change, but the old URL had nowhere to go — it fell through to the 404 template, and every "redirect" in the codebase was either SSRF-safety terminology or a wp-admin internal navigation, never a front-end 301. This adds the one generic, site-agnostic capability that was missing: a safe-surface redirect. It is DB-backed (survives theme updates), same-site only, and resolves on the 404 path so a redirect can never shadow a live page.

### Added
- **`create_redirect` / `remove_redirect` / `list_redirects` actions** (validate/preview/execute, site scope) record a `from` path → same-site `to` target with a 301 (default) or 302 status code in the `pp_redirects` option. A `template_redirect` resolver applies a matching redirect only on an otherwise-unmatched (404) front-end request, so the old URL 301s to its canonical target instead of 404ing; `remove_redirect` restores the original behavior. Pair `create_redirect` with `update_page_slug` so a renamed page keeps working. The actions auto-surface in the AI system prompt via the registry.

### Security
- **Open-redirect safe by construction.** Targets are validated same-site only at write time (`_pp_validate_redirect_target` rejects external hosts, protocol-relative `//`, and `javascript:`/`data:`/`vbscript:` schemes), and `wp_safe_redirect()` re-validates the host at resolve time as a runtime backstop. `from == to` and any chain that would loop are rejected at creation (`_pp_redirect_would_loop`, hop-capped). Stored paths only ever reach the escaped `Location` header, never HTML. The three mutation actions are admin-gated (`manage_options`, fail-closed). 15 new PHPUnit tests (normalization, target validation, loop/self/external rejection, preview-no-write, execute round-trip, remove restores) plus an end-to-end test asserting 301 → 200 and that removal restores the 404.

### Documentation
- The AI-facing surface now maps the capability: a redirect row in the `AI_CONTEXT.md` mutation-surfaces table and the `ai-instructions/website-building.md` surface-routing table (next to `update_page_slug`, so the model reaches for a redirect after renaming a slug), plus `docs/AI_IMPLEMENTATION_RECIPES.md` Recipe B lists #62 as a worked example. (#62)

## [v0.16.50] — 2026-07-07 — Component logo_id must be an image, rejected at the action boundary (#155)

**A nav or footer `logo_id` pointing at a PDF, video, or bogus attachment ID no longer slips through validation to silently render nothing. It is rejected when the action runs, the same way the site-wide logo already was.**

Setting a logo has two surfaces: the site option (`pp_logo_id`) and a per-component `logo_id` prop on nav/footer. The site option already rejected any attachment that isn't an image. The component prop did not. It only avoided rendering a broken logo because WordPress core happens to return `false` for a non-image attachment, so the resolver quietly fell back to the text wordmark. That is someone else's internal behavior doing our validation for us, and the AI media surface only ever lists images, so a proposed action pointing at a non-image attachment was an unguarded trust-boundary gap. This closes it: a component `logo_id` is now validated as a real Media Library image at the action boundary, and the logo resolver has its own explicit image guard so the wordmark fallback is a deliberate, tested code path rather than an accident of WP internals.

### Fixed
- **Component `logo_id` props are now validated as image attachments.** `update_component`, `add_component`, and `update_composition` (and the CLI/patch paths that share `pp_validate_action()`) reject a `logo_id` that isn't a live Media Library image with a clear `invalid_logo_id` error, mirroring the `pp_logo_id` site-option rule. A non-image (PDF/video), a non-existent or trashed ID, and malformed shapes (`"12abc"`, negatives, arrays, floats) are all rejected; an empty or absent `logo_id` still means "no logo" and passes. Both the action validator and the render-time resolver share one predicate, `pp_is_image_attachment()`, so the definition of "valid image attachment" can never drift between the three enforcement points. The resolver (`pp_resolve_logo()`) now applies that predicate explicitly before resolving a URL, so its wordmark fallback is intentional and tested rather than relying on WP core returning `false`. 15 new PHPUnit tests covering the predicate, the resolver guard (including the `custom_logo` theme-mod path), the value validator, the params walker, and the wiring into action validation. (#155)

### Documentation
- The AI-facing surface now states the constraint so the site-building model gets it right the first time: the nav and footer `schema.json` and `README.md` `logo_id` descriptions, plus `AI_CONTEXT.md`, note that a component `logo_id` must be an image attachment and is rejected at action-validation time (same rule as `pp_logo_id`).

## [v0.16.49] — 2026-07-06 — Fail-closed pre-apply token snapshot on lock contention (#200)

**The pre-apply rollback baseline that `wp pp apply restore` reverts to could be a stale, non-atomic read exactly when a concurrent writer was racing — the one scenario the token lock exists to protect against.**

### Fixed
- **`pp_snapshot_token_overrides()` now fails closed on lock contention.** It read `pp_token_overrides` under the advisory token lock for an atomic baseline, but passed a plain `get_option()` read as the lock-failure fallback. When `GET_LOCK` timed out or errored, it silently returned that stale, non-atomic read, contradicting `lib/cli.php`'s own "read under the lock for an atomic baseline" claim. A `wp pp apply preflight` racing another writer could freeze a baseline that never existed atomically, and a later `apply restore` would silently revert to it. The snapshot now returns `null` on lock failure instead of degrading, and `wp pp apply preflight` treats a `null` snapshot as a hard failure: it records nothing (leaving both the action-execute and apply gates fail-closed) and errors so the operator can retry once contention clears. 4 new PHPUnit tests (in-lock read on success, `null` on lock-busy, `null` on GET_LOCK error, and the load-bearing `[]`-vs-`null` distinction so an empty-but-valid baseline is never misread as contention). Found by adversarial review during #98. (#200)

### Documentation
- New Diataxis docs for the `wp pp apply` command family: [`docs/reference-apply-cli.md`](docs/reference-apply-cli.md) (complete surface — every subcommand, flag, JSON output shape, exit code, and error message, including the new #200 contention behavior) and [`docs/howto-apply-and-rollback.md`](docs/howto-apply-and-rollback.md) (the safe preflight → execute → verify → restore cycle, with a troubleshooting section keyed to the real error messages). Cross-linked with the existing safety-gate explanation (`docs/operating-loop-safety.md`) and surfaced from the README.

## [v0.16.48] — 2026-07-06 — Release-Readiness Audit Fixes: Menu Rollback, Chat Page Targeting, Doc Drift

**A release-readiness audit of v0.16.1 → v0.16.47 found two functional gaps where features that landed in the same sprint never learned about each other, plus accumulated stale counts in the top-level docs.**

### Fixed
- **Batch rollback now covers navigation menus.** `pp_ai_execute_batch()`'s snapshot layer (#137) predates the menu actions (#132), so a failed multi-step proposal containing `create_menu`/`add_menu_item`/`assign_menu_location`/`set_menu` left partial menu state behind while still reporting `rolled_back: true`. The batch snapshot now captures full nav-menu state (menus, items, location assignments) whenever a step is a menu action; rollback deletes menus created during the batch, rebuilds the item lists of pre-existing menus (parents-first, so nested items re-attach correctly; pre-existing menus keep their term ids so restored location assignments stay valid), and restores the location map. 4 new PHPUnit tests, each verified to fail with the restore disabled.
- **Menu rollback restores menus with full fidelity — and leaves untouched menus alone.** Pre-landing review (red team) caught that the restore path dropped a real item's `target`/`classes`/`xfn`/`attr_title`/`description`, froze resolved titles onto items that inherit the linked page's title, and cleared+rebuilt every menu on the site (rewriting all item ids) even when the failed batch never touched them. Restore now carries all decorated fields, writes the raw stored title so page-title inheritance survives, preserves item positions, and skips any menu whose items are identical to the snapshot — with the untouched-menu comparison failing closed (`serialize`, not `json_encode`, so invalid UTF-8 in a legacy title can never make a mutated menu look untouched) and empty menus restoring to zero items. And a rollback that itself cannot fully restore something no longer hides it: `pp_ai_execute_batch()` now returns `rollback_errors` naming anything the restore could not recreate, so `rolled_back: true` is never a silent success flag over a lossy restore. 8 new tests (including the item-creation-failure branch, reachable via a new failure-injection knob in the test stubs).
- **`set_menu` is atomic at every entry point.** Its replace semantics cleared the target menu before adding items, so a mid-loop failure via single-step chat execute or `wp pp action execute` left the menu gutted — only batches were protected by the snapshot layer. `set_menu` now keeps the previous items and restores them itself when an item fails mid-loop — and when it created the menu in the same call, it deletes its own half-built menu instead of leaving it behind. 2 new tests. The menu action-name list is also now a single source of truth (`_pp_is_menu_action()`) shared by the batch snapshot gate and the capability resolver — a mapping pinned by its own test — so a future menu action can't reach one layer and miss the other.
- **AI chat page selection survives reload even before the first message.** The restore path read the persisted page selection only after bailing on an empty conversation, so picking a page and reloading before sending anything silently dropped the selection. The selection is now restored whenever saved state parses; the page-switch suggestion chip also normalizes the id to a number. 2 new tests.
- **`wp pp operate patch` no longer emits "invalid synopsis part" warnings.** The `--run-id` option's docblock description spanned three `: `-prefixed continuation lines, which WP-CLI folds into the generated synopsis as bogus tokens ("composition", "and", "so", "must") and warns about on every invocation. Collapsed to one line; an invariant test now scans lib/cli.php so a wrapped `: ` description can't regress silently.
- **AI chat page targeting survives reload and labels proposals again.** `ppChatFindPageById()` compared page ids with strict `===`, but ids sourced from the page `<select>`'s value or a previously saved localStorage state are strings while `config.pages` ids are ints. Confirmed live on the dev site: the persisted page selection was silently cleared on every reload, the proposal card's "Target page:" label (issue 136's approval-safety affordance) never rendered, and the "switch page?" chip could suggest the already-selected page. `activePageId` is now normalized to a number at the `<select>` boundary and on state restore (which also migrates previously saved string states), and the lookup itself compares numerically. 3 new JS tests.

### Documentation
- README.md: corrected action count (14 → 20, four places), style-slot count (107 → 140), recipe count (9 → 10), test counts (731 PHP/2898 assertions → 1226/4547; 262 JS → 350; badge 970+ → 1550+; status bullet 990+ → 1590+), frontend asset sizes (~75 KB CSS/1.5 KB JS → ~97 KB/3.6 KB; components.css 63 → 84 KB, now "all 12 component styles"; main.js description includes the sticky-header height measurement), and the Project status section (was still titled "What exists today (v0.13.1)" and pointed the changelog range at v0.13.1).
- AI_RULES.md: action count in the file-responsibilities table (14 → 20); E2E section (15 tests → 34 specs, listing the AI chat and token-concurrency suites).
- AI_CONTEXT.md: style-slot inventory ("107 across 4 v1 components" → "140 across 7 components", with per-component counts including testimonials/faq/stats).
- readme.txt: the changelog section stopped at 0.11.0 while the Stable tag said 0.16.x — added curated entries for 0.12.0/0.13.0/0.14.0/0.15.0/0.16.0 and a 0.16-series rollup at 0.16.48.
- ai-instructions/validate-site.md: documented `wp pp validate page` (rendered-HTML validation from the CLI, issue 77) alongside the raw-composition `wp pp check page`, and rerouted the nav-readiness "What to do" guidance from Appearance → Menus to the menu actions that now exist (`set_menu`/`add_menu_item`/`assign_menu_location`, issue 132).
- Focused AI-instruction-surface audit (the docs ARE the product interface):
  - Fixed instructions that named nonexistent actions: `add_page`/`set_composition` in playbook-create-page.md and playbook-revise-section.md → the real `create_page`/`update_composition`/`update_component`, plus the invalid `--apply=<name>` flag syntax (the apply name is positional).
  - Fixed mutating CLI examples that omitted the required `--run-id` (retheme.md, composition.md) — they fail against the run-token gate as written; each now also names the inspect → preflight prerequisite.
  - retheme.md mislabeled `apply restore` as "reset to default" — restore rolls back to the run's pre-apply snapshot; added the actual `apply reset` commands for reset-to-default.
  - Corrected a false capability claim repeated in AI_CONTEXT.md and composition.md: cta does NOT have `subheading`/`heading_align` (it uses `text`); `heading_align` is section/grid/testimonials only. Also fixed "every other component uses variant for color" (hero's variant is its layout; section splits `layout`/`variant`).
  - website-building.md listed `color-mix` as unsupported (AI_RULES explicitly allows it, and components.css uses it) and referenced a nonexistent `content_measure` prop.
  - Completed stale prop inventories: AI_CONTEXT.md component-index rows (faq/grid/cta/footer/stats/testimonials were missing title_accent, eyebrow/subheading/heading_align, button_variant, grid item fields, footer logo props), composition.md (nav `location`, logos item `label`, new `grid items[].text_role` section), embed/logos README.md (`variant` prop row).
  - AI_CONTEXT.md additions: the five view-layer helpers components actually call (`pp_esc_image_src`, `pp_render_heading_with_accent`, `pp_render_responsive_image`, `pp_render_faq_schema`, `pp_comments_template`), mutation-surface rows for menus/SEO/import_media, the batch-rollback scope (now naming menus and the deliberate import_media exception), the menu actions' `edit_theme_options` override in the capability-model paragraph, and the full `wp pp` subcommand list on the lib/cli.php row (also in AI_RULES.md).
  - The doc-contract test guarding these counts now derives its component list from the schemas themselves — it was hardcoded to 4 components and blind to the style slots faq/stats/testimonials gained in v0.16.x.
  - Playbook APPLY steps no longer call applies "file-based" (they are database-backed token/font/media applies).
  - CLAUDE.md: the "checkpoint" skill-routing line pointed at a skill that doesn't exist → routed to context-save/context-restore.
- Per-commit audit-compliance sweep over `docs/` (the developer-AI guides, never covered by the instruction-surface pass):
  - docs/running-an-ai-agent.md still listed the operating loop in the pre-fix order (EDIT before PREFLIGHT) — the exact ordering bug docs/operating-loop-safety.md documents as closed; the loop diagram and the paste-in agent contract now put PREFLIGHT before EDIT and state that every mutating command requires a covering preflight.
  - docs/AI_IMPLEMENTATION_RECIPES.md invariant 7 still claimed the AJAX execute path "is `edit_posts`-only today and under-gated — see #131" (shipped in v0.16.3); it and Recipe B now describe the real `_pp_required_caps_for()` model including the menu (`edit_theme_options`), trash/restore (`delete_post`), and `create_page` (`publish_pages`) overrides and the fail-closed defaults. Recipe A's "already wired for hero/section/grid/cta" list now includes faq/stats/testimonials.
  - docs/IMPLEMENTATION_ORDER.md: added a dated staleness banner — every Tier 0/0b/1 item in the mirrored queue shipped in v0.16.x, so the unchecked boxes must not be used to pick work until the file and pinned issue #141 are re-derived.

## [v0.16.47] — 2026-07-05 — Real WP+MySQL Concurrency Harness for Token Applies

**#97 added a MySQL advisory lock (`GET_LOCK`) around `pp_token_overrides` read-modify-write cycles so concurrent applies (agents parallelizing tool calls) can't silently lose one writer's update. The PHPUnit suite could only prove the lock is invoked inside a single shared PHP process/DB connection — it couldn't prove the lock actually serializes two INDEPENDENT MySQL connections under real concurrent load.**

### For contributors
- New `tests/e2e/token-concurrency.spec.ts`, driven through wp-env's real WordPress + MySQL container rather than the PHPUnit stub harness (which shares one process/connection and can't exercise a genuine cross-connection race):
  - Two genuinely separate `wp-env run cli` processes fire `wp pp apply execute update_design_token` on different tokens at the same time (async `spawn` + `Promise.all`, not sequential `execSync`); both writes must survive with no lost update.
  - The same two-process setup targeting the SAME token proves the lock resolves real key contention to one clean value, not a torn/interleaved write.
  - A third writer that starts while a real, separate connection holds the same named advisory lock must fail closed (`GET_LOCK` times out, explicit failure) rather than silently skip the lock and clobber. Made deterministic via a marker-file handshake rather than a timing guess: the holder verifies its own lock acquisition actually succeeded before signaling ready, then waits for the contender's own readiness signal before holding for the install's real configured lock timeout (read at runtime) plus a margin — never a guessed wall-clock budget.
- `--color-bg` and `--space-md` are used as the concurrent test tokens because neither has a derived token family (see `pp_token_families()`) — an unrelated token's auto-derived writes would otherwise obscure whether the specific keys under test survived.

## [v0.16.46] — 2026-07-05 — Test Coverage: AI Chat Streaming & Apply E2E Flow (Mock SSE)

**The AI chat's streaming/proposal/apply state machine (`assets/js/pp-ai-chat.js`) had no end-to-end coverage — only its pure helper functions were unit-tested. The SSE transport, the AJAX fallback path, and the atomic batch-apply flow were only ever exercised manually.**

### For contributors
- New `tests/e2e/ai-chat.spec.ts`: 9 Playwright specs driving the real chat UI (type → send → observe) against a deterministic mocked `ai-stream.php`, matching its exact wire format (`data: {"content"|"error"|"done"...}\n\n` frames, `data: [DONE]\n\n`) — no real AI provider is ever called.
- Covers: streamed content rendering and a single-step proposal; a multi-step proposal's "Apply All" going through the one atomic `pp_ai_execute_batch` request (#137) rather than N sequential calls; Cancel discarding a previewed proposal; a non-configured provider (plain-text HTTP 400) driving the AJAX fallback; three distinct mid-stream SSE error events (invalid key, rate limit, quota-exhausted) and the Connectors-link suppression rule tied to "no remaining credits"; a genuine dropped connection falling back to the non-streaming `pp_ai_chat` AJAX endpoint; and a truncated response (no proposal) surfacing its retry hint.
- Scoped deliberately to this flow per the issue's own diagnostic recommendation — provider/model selection, page-switch suggestions, composition diff rendering, and localStorage restore are exercised elsewhere and were left out to avoid scope creep into full UI coverage.

## [v0.16.45] — 2026-07-05 — Test Coverage: AI Chat Trust-Boundary Gaps From v0.2.0 Review

**A v0.2.0 code review identified several unit test coverage gaps in the AI chat PHP layer, deferred as non-blocking since live QA had already verified the functional paths end-to-end. Re-auditing against current code found most of the original gaps closed by later work (#131's capability-model tests are a strict superset of what was originally asked); five genuine gaps remained.**

### For contributors
- `pp_ai_coerce_params()`: added tests for int coercion (numeric and non-numeric strings), bool coercion, malformed-JSON array handling, and the unknown-action/apply early return — previously only the valid-JSON array path had a test.
- `pp_ai_media_inventory()`: added a direct empty-library → `[]` assertion and an item-shape test asserting the exact 7-key contract (`id`, `filename`, `url`, `alt`, `mime_type`, `width`, `height`) — existing tests only exercised it indirectly through the rendered system prompt.
- `restore_page` now has a preview-path test (`trash_page`/`unpublish_page` already had one; `restore_page` was the one action missing it).
- `pp_ai_parse_error_response()`: added an HTTP 403 test (same code branch as 401, which was tested; 403 wasn't).
- `pp_ai_validate_proposal()`: added a test for `['steps' => []]` — a genuinely different code path than the already-tested "no `steps` key at all" case.
- New `_pp_ai_chat_fallback_response()` in `lib/ai-chat.php`, extracted from the anonymous `wp_ajax_pp_ai_chat` closure so its guard paths (permission denial, unconfigured provider, empty messages) are directly testable — `add_action()` is a no-op in the test bootstrap, so the closure body was previously unreachable from any test. Same extraction pattern already used for `_pp_required_caps_for()`/`pp_ai_coerce_params()` in this file; the AJAX closure is now a thin adapter translating the result to `wp_send_json_success()`/`wp_send_json_error()`. No behavior change.
- 16 new PHPUnit tests total.

## [v0.16.44] — 2026-07-05 — Fix: Sticky Header Covering Section Headings on Anchor Jump

**A direct link or scripted jump to a section anchor (every content component supports an `id` prop for this) scrolled the target to the very top of the viewport — landing it under the sticky header instead of below it. Worst on mobile, where the header is proportionally taller, this covered the section heading entirely: a page could pass clean overflow/metric checks while still rendering a broken first impression for anyone landing directly on a section.**

### Fixed
- Anchor jumps to any content section (hero, section, cta, grid, faq, stats, logos, embed, testimonials, table) now land with the heading fully visible below the sticky header, confirmed at both 1280px and 375px viewports.
- The mobile menu now closes when a nav link is clicked, so an in-page anchor link tapped while the menu is still open scrolls against the header's real (collapsed) height, not its taller expanded-menu state.

### For contributors
- New `--header-height` CSS custom property (`components.css`), consumed via `scroll-margin-top` on every anchor-able component's root class. Static CSS fallback (65px); `main.js` measures the real rendered `.site-header` height on load, resize, and menu toggle/close, since header height varies with content, breakpoint, and font loading — a hardcoded value would drift.
- Not a design token: computed/measured, not AI-editable via `update_design_token`.
- 5 new Vitest tests. Verified live at both breakpoints on the dev site: a direct anchor jump and pricing screenshots confirm the heading sits fully below the header, not covered by it.

## [v0.16.43] — 2026-07-05 — New: `wp pp validate page` — Rendered-HTML Validation From the CLI

**The rendered-HTML validator that gates the AI chat's success message (render failures, broken images, missing local media, empty links) was wired into the chat's apply flow only. An operator or deployment workflow needed the same check outside the chat — for manual debugging, or a reusable validation hook in Claude Code / CI — but the only CLI validator (`wp pp check page`) checks raw composition data (styling/smells), not the actual rendered output.**

### Added
- `wp pp validate page --post_id=N` (optionally `--component-index=N` to validate a single component) runs the exact same `pp_post_apply_validate()` service the AI chat uses. Human-readable output, exits non-zero on any validation error.

### For contributors
- Thin CLI wrapper — no new validation logic, just a second entry point onto the existing #75 service. Distinct name from `wp pp check page` (raw composition data) since the two check genuinely different things.
- Verified live against a real page on the dev site: passing page reports success; a hero component with a nonexistent local image reports `missing_local_media` with the exact filename and exits 1.

## [v0.16.42] — 2026-07-05 — Fix: Theme Integrity Permanently "Unsafe" on Windows Hosting

**On Windows hosting (IIS/XAMPP — fully supported by WordPress), theme file hashing kept backslash path separators instead of stripping them to forward slashes. Since `integrity-manifest.json` is built on Linux CI with forward-slash paths, every nested file mismatched on Windows — reported as both "missing" and "extra" — which permanently flipped theme integrity to "unsafe" and blocked every theme update, with a persistent "files modified locally" admin notice that could never clear.**

### Fixed
- Both theme-file hashers, and `pp_classify_surface()`'s theme-directory-prefix strip, now normalize backslashes to forward slashes before computing a relative path — regardless of which OS wrote the original absolute path.

### For contributors
- New pure function `_pp_relative_theme_path($theme_path, $pathname)` in `lib/apply.php` centralizes the normalization; both hashers and `pp_classify_surface()` now call it instead of each doing their own `str_replace()`/`ltrim()`.
- Being a pure function (no I/O), it's directly testable with synthetic Windows-style backslash inputs on any OS — the test suite doesn't need to actually run on Windows to catch a regression here.
- 7 new PHPUnit tests, including one asserting `pp_classify_surface()` correctly classifies a Windows-style absolute path end-to-end (theme dir + file path both backslash-separated) and one confirming a real nested file on the filesystem still hashes to a forward-slash key.

## [v0.16.41] — 2026-07-05 — New: AI Chat Can Build Navigation Menus

**Navigation had zero mutation surface — no action could create a menu, add a link, or assign a location. The only nav-related code was a read-only diagnostic that explicitly told the AI to punt to a human: "Assign one under Appearance → Menus," the exact wp-admin surface the product exists to abstract away. A fresh install had no way to get a working nav menu without leaving the chat.**

### Added
- Four new actions: `create_menu`, `add_menu_item` (page link or custom URL + label), `assign_menu_location` (to a registered theme location — `primary`, `footer`), and a declarative `set_menu` that creates-or-reuses a menu by name and replaces its full item list (+ optionally its location) in one call, mirroring `update_composition`'s replace semantics — the friendliest shape for an AI to propose a whole menu at once.
- The AI system prompt now includes a Navigation section next to the Pages inventory: registered locations, each menu's assignment, and its item titles — so menu proposals are grounded against real state instead of guessed.
- The existing nav-readiness diagnostic's messages now point at the new actions (or Appearance → Menus) instead of only the wp-admin escalation.

### For contributors
- New `lib/wp.php` wrappers: `pp_get_menus()`, `pp_create_nav_menu()`, `pp_add_nav_menu_item()`, `pp_clear_nav_menu_items()`, `pp_assign_menu_location()` — the only place these actions touch WordPress core menu functions.
- The page-link param is named `page_id`, not `post_id`: the operate/preflight gate treats a top-level `post_id` action param as "this action mutates that specific post," but a menu's linked page is data referenced by a site-scoped mutation (the menu), not the post being mutated — using `post_id` here would have broken that invariant.
- Menu structure is gated on `edit_theme_options` (mirroring WordPress's own Appearance > Menus capability), not the stricter `manage_options` default for other site-scoped actions.
- 19 new PHPUnit tests. Verified live against real WordPress on the dev site: created a menu, added a page link and a custom link, assigned it to `primary`, confirmed it rendered in the actual frontend nav, then restored the site's original menu assignment.

## [v0.16.40] — 2026-07-05 — New: Multi-Step AI Proposals Apply Atomically

**A multi-step proposal ("add a hero, add a pricing section, update the CTA text") ran as N independent AJAX requests, each committing to the database before the next started. If step 3 failed, steps 1-2 were already permanently applied — the page ended up half-mutated, with no undo and no accurate summary of what actually happened.**

### Added
- Proposal steps now apply in one atomic batch request. Every post, design token, font URL, site option, and Custom CSS value any step could touch is snapshotted before anything runs; if any step fails partway through, every one of those snapshots is restored — the page ends up exactly as it was before the proposal, not half-applied.
- A page created earlier in the same batch (`create_page`) is deleted outright on rollback, since it didn't exist before the batch started.
- The failure message now names the failing step and confirms the revert: "Error on step 3: ... — all changes in this proposal have been reverted."

### For contributors
- New `pp_ai_execute_batch()` in `lib/actions.php`, backing a new `wp_ajax_pp_ai_execute_batch` AJAX handler; the client (`executeProposal()`) now sends the whole step array as one JSON-encoded request instead of recursing through `pp_ai_execute` one step at a time.
- Every step's required capability is checked up front, before any step executes — a permission gap on step 3 blocks the whole batch. Semantic validation is deliberately NOT pre-checked across the whole batch: many real proposals are intentionally interdependent (e.g. "add a component, then style it"), and validating step 3 against pre-batch state would falsely reject a step that only becomes valid once step 1 runs. Each step is still fully validated against the state that exists at the moment it actually runs — a genuinely invalid step is still caught and the batch still rolls back cleanly, just at that step's own turn rather than pre-flighted for all steps.
- `import_media`'s uploaded attachment is deliberately never rolled back — it's additive and non-destructive, unlike overwriting composition/token state.
- 11 new PHPUnit tests covering rollback across every mutation surface (composition, title, slug, status, SEO meta, newly-created pages, design tokens, font URLs, site options, Custom CSS) plus the "later steps never execute after a failure" and per-step post-apply-validation behaviors. Verified live: a real 2-step proposal (add FAQ + rename page) applied both changes atomically end-to-end.

## [v0.16.39] — 2026-07-05 — New: Stop Button and Automatic Fallback for Stuck AI Responses

**A long, wrong, or runaway chat response had no way to be interrupted — the send button and input were simply disabled until the request finished or the 120s server timeout hit. Separately, a proxy/CDN that buffers the whole streaming response (or middleware stripping `text/event-stream`) returns HTTP 200 with no usable stream, which doesn't reject the request — on those hosts the chat would sit in an indefinite "thinking" state, even though a working non-streaming fallback endpoint already existed and was simply never reached.**

### Added
- A Stop button (swapped in for Send while a response streams) aborts the request and finalizes whatever partial text has arrived, leaving it in place with input re-enabled. An intentional stop is treated as a cancellation, not a failure — it never triggers the non-streaming fallback.
- A first-token watchdog: if no token arrives within 15 seconds of a request starting, the client aborts the stalled SSE attempt and automatically retries via the existing non-streaming endpoint, surfacing a "Streaming unavailable — using compatibility mode." note.

### For contributors
- `streamChat()` now creates an `AbortController` per request and a module-level `currentRequestId` counter that every async callback (fetch `.then`/`.catch`, the fallback's response handlers) checks before touching shared state — without it, an aborted-but-still-in-flight request's callback could fire after "New Chat" already reset the conversation, re-populating it with stale partial text.
- 3 new Vitest tests covering the exact race conditions this touches: Stop mid-stream (assert `abort` fired, no fallback), a stalled stream with zero tokens (assert the fallback engages once the watchdog elapses), and the existing happy path (assert nothing changes when tokens arrive normally).
- Verified live on the dev site: Stop button appears/disappears correctly across a real streaming round-trip with no console errors or stuck UI state.

## [v0.16.38] — 2026-07-05 — Fix: AI Chat Now Targets an Explicit, User-Chosen Page

**Which page a chat request mutated was inferred entirely by matching the last message against page titles as substrings — "tell me about pricing" could match a page titled "About", "make the hero bigger" matched nothing and silently reused whatever page was last targeted, and the user had no way to see or correct which page was about to change before a proposal wrote to it.**

### Added
- A page selector in the chat header sets the conversation's target page explicitly; it persists across reloads and is sent with every request. Sending with no page selected is now blocked client-side — a `page_id: null` request is never sent — with an inline prompt directing the user to the selector.
- Title-substring detection still runs on every message, but only as a suggestion: when it disagrees with the current selection, a non-blocking "Switch to it for next message?" chip appears. It never silently retargets the conversation.
- Every proposal card now names its target page ("Target page: <Title>"), captured at the moment the request was sent so it can't drift if the selector changes mid-response.

### For contributors
- `detectPageId` moved out of the chat IIFE into a pure, testable top-level function (`ppChatDetectPageId`), alongside two new helpers (`ppChatFindPageById`, `ppChatShouldSuggestPageSwitch`) — all three exported for tests, following the file's existing `ppChat*` testable-helper convention.
- 18 new Vitest tests for the three pure functions (substring-collision resolution, untitled-page exclusion, suggestion-vs-authority semantics), plus an existing localStorage-restore fixture updated to seed an active page now that sending requires one.
- Verified live: page selector populated from real composition pages, blocked/prompted send with nothing selected, detected-page hint text, explicit-selection-wins-over-detection on send, and the switch-suggestion chip correctly updating the selector on click.

## [v0.16.37] — 2026-07-05 — New: `enqueue_font` Can Now Wire a Font Into the Site's Typography Tokens

**`enqueue_font` loaded a webfont's `<link>` and nothing else — the site's actual typography comes from the `--font-heading`/`--font-body` design tokens, which `enqueue_font` never touched. "Use Poppins for headings" required a second, undocumented `update_design_token` call with the exact CSS family name the stylesheet defined, a coupling an AI could easily get wrong or skip — leaving the font downloaded but invisible.**

### Added
- `enqueue_font` gains two optional params: `family` (the CSS font-family name the stylesheet defines) and `apply_to` (`heading` | `body` | `both`). When both are given, the same call also sets the matching `--font-heading`/`--font-body` token(s) to `"{family}, system-ui, sans-serif"`.
- When `family` is omitted, `enqueue_font` best-effort derives one from the URL's `family=` query parameter (the Google/Bunny Fonts convention) and returns it in the result as `family`/`family_source: "derived"` — a suggestion, with no token written — so the caller can confirm before a follow-up call. `apply_to` without any resolvable family (explicit or derivable) is now a validation error (`missing_family`) instead of a silent no-op.
- Preview shows both the font-URL addition and the token change together, so approving the step reflects the real visual effect, not just "a stylesheet loaded."

### For contributors
- New `_pp_derive_font_family_from_url()` and `_pp_font_apply_to_tokens()` helpers in `lib/apply.php`. The real token names are `--font-heading`/`--font-body` (not `--font-family-*`) — `ai-instructions/website-building.md` and `AI_CONTEXT.md` now name them explicitly for anyone proposing an `apply_to` value.
- No new subsystem: the token write reuses the same `pp_set_token_override()` the `update_design_token` apply already uses, so cache invalidation and the `pp_token_overrides` storage shape are unchanged.
- 11 new PHPUnit tests, including derivation from both the CSS2 weight-axis URL format and `+`-encoded multi-word family names, and confirming preview never writes.

## [v0.16.36] — 2026-07-05 — Regression Test: Preview/Execute Parity for Invalid Media URLs

**Issue 130 asked for proposal previews to reject a hallucinated/typo'd media URL with the same error execute would give, instead of showing a clean diff for a step guaranteed to fail. Investigating found this already true in the current codebase — the earlier #124 media-URL validation work moved the check into the shared `pp_validate_action()` gate that both `pp_preview_action()` and `pp_execute_action()` call, so preview and execute have been failing identically since #124 shipped. What was missing was a regression test actually proving it, and this issue's own acceptance criteria named exactly that test.**

### For contributors
- New `testPreviewRejectsInvalidMediaUrlIdenticallyToExecute` in `tests/ActionsTest.php`: calls `pp_preview_action()` and `pp_execute_action()` with the identical params (an uploads-path `image_url` with no matching attachment) and asserts both reject with the same `invalid_media_url` error and message.
- `AI_CONTEXT.md`'s existing media-URL-validation paragraph now names the preview/execute parity property explicitly.
- No production code changed — this closes out issue 130 with the missing test coverage rather than re-implementing behavior that already existed.

## [v0.16.35] — 2026-07-05 — New: Guardrail Warning for Empty Structured-Content Sections

**A FAQ section with no questions, a grid with no cards, or a table with no rows is schema-valid — every prop passes its own validation — but renders as an obvious dead block on the live page ("No questions yet.", "Nothing here yet."). Nothing in the page check flagged it, so an operator could trust a passing `wp pp check page` result while the frontend still showed a visibly unfinished section.**

### Added
- New `empty_section` composition smell, surfaced by `wp pp check page` alongside the existing smells: fires for `faq`/`grid`/`stats`/`logos`/`table` components whose configured content produces no useful frontend output — an empty `items`/`rows` array, or (for `faq`/`logos`) items present but every one missing the field its render path requires (a FAQ item with no `question`, a logo item with no `image_url`).

### For contributors
- New `_pp_component_is_empty()` helper in `lib/guardrails.php` mirrors each component's own render-time skip logic rather than an independent content check, so the smell only fires exactly when the component's own template would render nothing for that item.
- The warning includes the component's stable ID when one is set, and is non-destructive — it only warns, it never removes or fills content.
- 16 new PHPUnit tests, including one confirming `hero`/`cta`/`section` (which have no items/headers-rows structure) are never misflagged.

## [v0.16.34] — 2026-07-05 — New: Guardrail Warnings for Over-Narrow, Over-Compact Page Rhythm

**A page could be built entirely out of structurally valid components and still land visually weak — three or more sections in a row set to `width: narrow` or `spacing: compact` produce a cramped, memo-like page, and nothing in the system flagged it. The two composition smells `pp_validate_composition_smells()` already caught (`hero_left_no_image`, `consecutive_text_sections`) covered the alignment/rhythm failure modes identified in a live branding pass, but not the over-constraining-via-props pattern.**

### Added
- Two new composition smells, surfaced by `wp pp check page` alongside the existing ones: `consecutive_narrow_width` (3+ consecutive components with `width: narrow`) and `consecutive_compact_spacing` (3+ consecutive components with `spacing: compact`).
- `ai-instructions/website-building.md` gained a "Composition is not a presentation-polish tool" section: `width`/`spacing`/`content_measure` are structural knobs, not fixes for a page that feels cramped or weak — reaching for them repeatedly is the failure mode, not a workaround.

### For contributors
- Both smells share the same counter-and-reset shape as the existing `consecutive_text_sections` check in `lib/guardrails.php` — increment on match, reset on the first component that breaks the run, fire once at 3 and reset again to avoid repeated warnings for the same run.
- Counted across any component type (hero/section/grid all expose `width`/`spacing`), not scoped to one component, since the failure mode is page-level rhythm, not a single component's props.
- 6 new PHPUnit tests. This is the retitled/narrowed remainder of issue 51 — the hero-left and consecutive-text smells it originally proposed shipped earlier; the knob-renaming asks (`width: narrow` → something more specific) are tracked separately in #69.

## [v0.16.33] — 2026-07-05 — Fix: Search Returned One Result's Content Instead of a Results List

**A search request had no dedicated template, so it fell through WordPress's template hierarchy to the single-page template and rendered the first matched result's content as a full standalone page — no "N results for…" heading, no results list, no pagination, and no empty state when nothing matched.**

### Fixed
- Added `templates/search.php` (+ root `search.php` delegator): a heading naming the query and match state, a grid of matching posts/pages with links and excerpts (reusing the archive/blog-listing grid pattern from #126), pagination, and an explicit empty state instead of a blank hero/section.

### For contributors
- New `pp_search_query()` and `pp_result_count()` wrappers in `lib/wp.php`, keeping the "templates call only pp_* functions" invariant; `pp_result_count()` reads `found_posts` off the main query (the full match count, not just the current page's post count).
- Reuses `pp_main_query()`/`pp_the_loop()`/`pp_pagination()` from #126 as-is — WordPress's search query already includes both posts and pages by default, so no new query-building logic was needed.
- 4 new PHPUnit tests; verified live on the dev site with a matching-term search (results + no pagination for 2 results) and a no-match search (empty state).

## [v0.16.32] — 2026-07-05 — Fix: Blog Listing Showed One Post's Content Instead of a Grid

**Visiting the posts page (or any category/tag archive) rendered a single post's title and content as if it were a static page, with no grid, no pagination, and no respect for the category/tag filter — a category archive showed every post on the site regardless of its actual category.**

### Fixed
- `templates/archive.php` now iterates WordPress's own already-filtered main query instead of constructing a fresh `WP_Query` with hardcoded/approximated args — the fresh query is what caused a category archive to show all posts, since it never applied the category filter.
- Added `templates/home.php` (and a root `home.php` delegator) for the dedicated posts-index case (`is_home`), which previously fell through to the single-page template and rendered one post's content in place of a listing.
- Pagination now renders on both the archive and posts-index templates, wired to WordPress's own `paginate_links()` and the real page count.

### For contributors
- New `pp_main_query()` (wraps `global $wp_query`) and `pp_pagination()` (wraps `paginate_links()`) helpers in `lib/wp.php`, preserving the "only lib/wp.php calls WP core" invariant.
- Per-page post count now follows the site's actual "Blog pages show at most" setting (Settings → Reading) rather than a hardcoded number, since it's the real main query rather than a fresh one with its own `posts_per_page` arg.
- 5 new PHPUnit tests; verified live on the dev site with 13 posts across two pages and a dedicated test category to confirm archive filtering.

## [v0.16.31] — 2026-07-05 — New: Edit a Page's URL Without Touching wp-admin

**PromptingPress could create a page, rename its title, publish it, trash it — but never touch its slug. Once a page was created, its URL was frozen forever unless you dropped into wp-admin, the exact surface the product exists to abstract away. Getting `/product/` instead of the title-derived `/how-promptingpress-works/` meant trashing and recreating the page, losing its ID, composition history, and any inbound links.**

### Added
- New `update_page_slug` action: changes a page's slug/permalink through the same preview/validate/execute contract as every other page action.
- `create_page` accepts an optional `slug` param, so the canonical route can be set at creation instead of being derived from the title and then stuck.
- The AI's page inventory now shows each page's real URL, with an explicit instruction to check it before proposing a slug change — no more guessed or hallucinated URLs.

### For contributors
- WordPress de-duplicates a colliding slug internally (`-2`, `-3`, ...) — `pp_update_page_slug()` reads back the actual resulting `post_name` afterward rather than assuming the requested one stuck, and both the action's `changes` and the preview surface it, never silently.
- 9 new PHPUnit tests, including the collision/de-duplication path and the "preview never writes" guarantee.

## [v0.16.30] — 2026-07-05 — New: FAQ Sections Now Generate Rich-Snippet Structured Data

**The FAQ component renders semantically correct, accessible HTML — but Google can't turn that into an FAQ rich snippet without a matching FAQPage JSON-LD block, and PromptingPress had no way to output structured data at all. Every FAQ section was leaving free SERP real estate on the table.**

### Added
- The FAQ component now always emits a `FAQPage` JSON-LD `<script>` block alongside its own markup, generated from `items`. Zero-config — no new prop, no toggle. Nothing is emitted if there are no complete (question + answer) items.

### For contributors
- `question`/`answer` are stripped of HTML via `wp_strip_all_tags()` before encoding — Google's FAQPage schema expects plain text, and this is also the primary defense against a `</script>` breakout (WordPress's `wp_strip_all_tags()` removes `<script>`/`<style>` tags *and* their content via a regex pass before general tag-stripping, so no well-formed tag markup survives into the JSON payload). `wp_json_encode()`'s default forward-slash escaping (never `JSON_UNESCAPED_SLASHES` here) is a second, redundant layer.
- New shared `pp_render_faq_schema()` helper in `lib/wp.php`, following the same "component-owns-its-own-schema-generation" pattern the issue asked for — no composition-level `structured_data` field, no new subsystem.
- 10 new PHPUnit tests, including a dedicated test proving the forward-slash-escaping property in isolation and one proving the tag-stripping property against a real breakout-attempt payload.

## [v0.16.29] — 2026-07-05 — New: Page-Specific SEO Metadata

**Composition-backed pages had no first-class way to set a meta description, override the `<title>` tag, or set a canonical URL — real launch work needed a direct `functions.php` patch to get a page-specific meta description onto the site.**

### Added
- New `update_seo_meta` action: sets `meta_description`, `seo_title` (overrides the rendered `<title>` tag), and `canonical_url` for any page — a patch, so setting one field never touches the others. Set a field to `""` to clear it.

### For contributors
- Storage mirrors `_pp_composition`'s own pattern: one structured post meta key (`_pp_seo_meta`, JSON-encoded), not scattered flat meta keys, and not folded into the composition array — SEO metadata is page-level, not a layout/content concern.
- Output integrates with WordPress core's own mechanisms rather than duplicating them: `seo_title` hooks `pre_get_document_title` (short-circuits `wp_get_document_title()`'s own assembly), `canonical_url` hooks the `get_canonical_url` filter (WordPress's own `rel_canonical()` still does the output and escaping — this only substitutes the value), and `meta_description` is a direct `wp_head` tag. No duplicate `<title>`/canonical tags, no reimplemented escaping.
- `canonical_url` is validated as a real URL; `meta_description`/`seo_title` are length-capped (320/200 chars) — routine input validation, not a new security mechanism.
- 18 new PHPUnit tests across the action's validate/preview/execute paths and all three render-time hook callbacks (including the "no override set" and "not the current page" pass-through cases).

## [v0.16.28] — 2026-07-05 — New: Image Focal Point and Aspect Ratio Control

**Every background image cropped from a hardcoded dead-center `background-position`, and every content image rendered at its natural proportions with no way to force a specific box shape. A photo whose subject wasn't centered had no safe-surface remedy — the crop was simply wrong, on every page, every time.**

### Added
- Image focal point (`background-position`/`object-position`) is now a per-instance style slot on hero (cover variant + split image), section (`background_image` + image-left/image-right image), cta (`background_image`), and stats (`background_image`).
- Content-image aspect ratio (hero's split image, section's image-left/image-right image) is now a per-instance style slot — force a specific crop shape (e.g. `16/9`, `1`) instead of the image's natural proportions.

### For contributors
- Two new bounded style-slot types, following the established `#99` scaffold: `position` (1-2 keyword/length tokens, no functions/`var()`) and `ratio` (`auto`, or a number/fraction, zero and negative rejected). Both wired through the same shared `_pp_validate_token_value()` dispatcher used by every other slot type, so `style_component` gets them for free.
- `ratio` explicitly accepts its own `auto` keyword as a settable value (natural proportions) — the same "slot's own preset is explicitly allowed" pattern `shadow` already uses for `none`. Caught and fixed a related **pre-existing** inaccuracy in the AI system prompt while touching this code path: it claimed CSS keywords like `none`/`auto` are never accepted, which was already false for `shadow`'s `none`.
- Not exposed on logos — its `object-fit: contain` layout is a "fit within, don't crop" model, not a crop model; forcing an aspect-ratio box there doesn't fit the existing behavior.
- Fully backward compatible: every new slot defaults to the exact value already hardcoded in CSS (`center` for position, `auto` for aspect-ratio) — no rendering changes for any composition that doesn't explicitly set one.
- 33 new tests (26 PHPUnit validator/rendering + 7 Vitest per-slot fallback checks, auto-generated from schema.json); 4 existing hardcoded slot-count assertions across PHPUnit and Vitest updated (107 style slots total, up from 100).

## [v0.16.27] — 2026-07-05 — New: Real Responsive Images on Hero, Section, and Logos

**Every image on the site shipped as a single fixed `<img src>` — one resolution to every device, oversized on mobile or blurry if downscaled. A page with a hero image, a section image, and a logo strip produced zero `<picture>` elements and zero images with `srcset`/`sizes`, a real quality and performance gap on any image-heavy page.**

### Added
- Hero's `split` image, section's `image-left`/`image-right` image, and each logos item now accept a companion `image_id` — a Media Library attachment ID (from the `import_media` apply, shipped last release). When it resolves to a real attachment, the image renders responsively via WordPress's own `wp_get_attachment_image()`, with real `srcset`/`sizes` generated from registered image sizes.

### For contributors
- Fully backward compatible: `image_id` is optional and additive. Any composition that only ever set `image_url` (hotlinked or local) keeps rendering the exact same plain `<img>` tag as before — the new `pp_render_responsive_image()` helper in `lib/wp.php` falls back automatically when `image_id` is unset or doesn't resolve (deleted attachment, wrong id), with no error and no visible difference.
- Deliberately scoped to `<img>`-tag image props only (hero/section/logos) — `background_image`/`cover` variants render via CSS `background-image:url()`, a different mechanism (`image-set()`) not touched here.
- 13 new PHPUnit tests: the shared helper's three branches (attachment resolves, attachment doesn't resolve, no id at all) plus one integration test per component confirming both the responsive and plain-`<img>` paths render correctly.

## [v0.16.26] — 2026-07-05 — New: Bring External Images In as Owned Media

**Image props (`image_url`, `background_image`, `logo_url`) only ever accepted a raw external URL. The only way to get a brand's real logo or photo onto the site was to hotlink it — fragile, unoptimized, and broken the moment the source moves — or drop out of the product surface entirely and use wp-admin media upload directly.**

### Added
- New `import_media` apply: sideloads an external HTTPS image URL into the media library and returns `{attachment_id, url}`. Pass the returned `url` into any `image_url`/`background_image`/`logo_url` prop to reference a locally-owned, locally-optimized asset instead of a hotlink.

### For contributors
- SSRF safety is WordPress core's job, not reimplemented: `download_url()` (used internally) fetches via `wp_safe_remote_get()`, which validates the URL **and every redirect hop** against private/reserved IP ranges, non-http(s) schemes, and disallowed ports — the same mechanism WordPress itself uses for oEmbed and update checks. This apply adds three things on top: HTTPS-only + a plausible-extension pre-check (fast fail, no network use for obviously-wrong URLs), a real post-download mime check restricted to images (WordPress's default upload mime allowlist is much broader — PDFs, docs, zips — deliberately narrowed here), and a 10MB size cap that `download_url()` doesn't itself enforce.
- Deliberately scoped narrow: no new host-allowlist subsystem, no generic remote-fetch capability. Gated by the existing blanket `manage_options` requirement for all applies — no new capability logic needed.
- 15 new PHPUnit tests, including SSRF-rejection propagation (a mocked `WP_Error` from `download_url()`/`wp_safe_remote_head()`, simulating a redirect to a private/internal destination), oversized-file rejection, and content-vs-extension type-mismatch rejection.

## [v0.16.25] — 2026-07-05 — Fix: Grid Steps No Longer Look Like a Draft

**The `steps` variant of the grid component — the numbered "how it works" layout every marketing page reaches for — rendered as bare floating numbers over a dead-space background, with an arrow connector that had actually been invisible in production the whole time. Every page using it needed manual CSS rescue to look finished.**

### Fixed
- Steps cards now use the same visible card chrome (background, border, radius) as the default grid variant, closing the gap between "technically has a `steps` option" and "looks intentionally designed."
- The step number is now a filled circular badge instead of a bare number floating in dead space, with a tightened gap to the title/text below it.
- The arrow connector between steps — which had silently stopped rendering after an unrelated later rule clipped it with `overflow: hidden` — is replaced with a subtle horizontal line between badges at desktop, instead of restoring the noisy triangle.

### For contributors
- Root cause: `.grid--steps` had THREE separate declaration sites — the canonical `COMPONENT: grid` block, plus two undocumented, unscoped "rescue" overrides bolted on elsewhere in `components.css` (interleaved with unrelated hero proof-content CSS, using raw rgba magic-number colors instead of tokens). The canonical block stayed weak while the actually-rendered page defaults silently diverged from it — exactly the kind of drift that makes a component's own defaults untrustworthy. Consolidated to a single declaration site inside the canonical block; new css-lint test (`grid--steps only declared inside the COMPONENT: grid block`) guards against this recurring.
- Verified before/after via a real redeploy to the dev environment (release zip → `/var/www/dev-promptingpress`) and browser screenshots of the site's actual "Cómo funciona" 3-step section, both at desktop and mobile, plus the two other live pages using `theme: dark` — not just static CSS reasoning.
- `--grid-step-color`'s description updated to reflect its new role (badge background, not bare number color) — same slot, same default, no schema break.

## [v0.16.24] — 2026-07-05 — New: Testimonials Component

**Testimonials are one of the most common homepage sections, and PromptingPress had no way to build one. The only workaround was embedding raw `<blockquote>` HTML inside a `section` body — no structured attribution, no per-quote styling, no way to reorder or selectively show individual quotes, and a wall of text instead of card separation.**

### Added
- New `testimonials` component: customer quotes with structured attribution (`author`, `role`, `company`, optional avatar). `grid` variant lays out quote cards 1-col mobile / 2-3 col desktop; `stack` variant is a single centered column for one or two large pull-quotes. Same header pattern as grid/section/cta (`eyebrow`, `title`/`title_accent`, `subheading`, `heading_align`) and the same dual-axis `variant` (layout) / `theme` (color) control as grid and CTA.
- Base `blockquote` styling (accent border, italic, muted color) added to `base.css` — it's a standard HTML element other components already pass through `wp_kses_post()` (e.g. FAQ answers), so it should look intentional everywhere, not just inside the new component.

### For contributors
- Quote text and all attribution fields are plain strings run through `esc_html()` individually — no HTML allowlist, consistent with how every other component's text content is handled.
- 12th component; registered in `pp_register_component_fields()` (title/eyebrow/subheading/items[].quote/author/role/company) and `_pp_pick_nested_match_field()` (matches nested items by `quote`, the one required per-item field) for `patch`-command addressability, consistent with existing components.
- 19 new PHPUnit tests. Component count updated in AI_CONTEXT.md and README.md (12, up from 11); style-slot totals tracked by the existing "4 v1 components" tests are unaffected since testimonials sits outside that tracked set, same as faq/stats.

## [v0.16.23] — 2026-07-05 — New: Scannable Checklists Inside Grid Cards

**Feature/benefit sections that feel professional almost always build their cards as an icon, a title, and a short checklist — "✓ SSL/TLS validity," "✓ Clickjacking protection" — not a dense paragraph. Grid cards could only render `text` as one `esc_html()`'d sentence, so the only way to approximate a checklist was to cram items into a comma-separated blob that read as a wall of text.**

### Added
- Grid card items accept a `bullets` array — a list of short lines rendered below `text` as a checklist, each prefixed with a check mark in its own style slot (`--grid-bullet-color`). Per-item icons were already covered by the existing `image_url`/`image_alt` fields.

### For contributors
- Deliberately a structured array of plain strings, not a `wp_kses` HTML-list allowlist: each `bullets` entry is escaped independently via `esc_html()`, and non-string/empty entries are silently skipped rather than rendered — no new HTML-parsing surface. `bullets` coexists with `text` (a card can have both) and is intentionally left out of `pp_register_component_fields()`'s granular `patch` targeting, consistent with the existing `image_url`/`image_alt` per-item fields, which aren't addressable that way either.
- 7 new PHPUnit tests, including an escaping/injection test; 3 existing tests carrying hardcoded slot counts updated (100 total, up from 99).

## [v0.16.22] — 2026-07-05 — New: Highlight One Word in Any Heading

**Professional headlines rarely stay one color end to end — think "Security" in orange, "for your WordPress" in white. Every PromptingPress heading was locked to a single flat color, so that entire style of headline was out of reach.** Now every heading can name one word or phrase to highlight in an accent color.

### Added
- Hero, grid, section, CTA, FAQ, and stats headings all accept a `title_accent` value — the exact word or phrase (from the title) to render in a distinct accent color, with its own color slot per component.

### For contributors
- Deliberately a structured, plain-text mechanism, not an HTML/markup allowlist: `title_accent` must be a literal substring of `title`; the renderer just decides where to split the string and wraps that segment in a `<span>`. Both fragments still go through `esc_html()` exactly as a plain title always did — no new parsing or sanitizer surface, verified directly against injection attempts in both the title and the accent value. Shared `pp_render_heading_with_accent()` helper in `lib/wp.php` avoids duplicating the split-and-escape logic six times.
- Fixed a latent false-positive in `tests/StyleSlotContractTest.php`'s cross-block clobber guard: a plain substring match treated the new `.grid__heading-accent` class as a "clobber" of the unrelated `--grid-heading-color` slot (BEM's hyphenated modifier suffix doesn't create a regex word-boundary). Now boundary-aware; verified it still catches real clobbers by deliberately breaking one.
- 19 new PHPUnit tests; fixed 3 existing tests carrying hardcoded slot counts (99 total, up from 95).

## [v0.16.21] — 2026-07-05 — New: Eyebrow Pills and Subheadings for Every Section

**Professional landing pages almost always lead a section with three things: a small kicker label, a centered heading, and a supporting line underneath. PromptingPress could render none of that — every section, grid, and CTA heading was a lone left-aligned line.** That gap was one of the biggest reasons AI-built pages looked template-y instead of designed. Hero already stored an eyebrow value in some pages, but it never actually showed up.

### Added
- Hero, grid, section, and CTA all support an `eyebrow` — a short label rendered as a styleable pill above the heading (e.g. "NEW", "PLUGIN OFICIAL").
- Grid and section also get a `subheading` (a supporting line under the title) and a `heading_align` option to center just the heading block — independent of the section's overall layout. (Hero and CTA already had equivalent tools: hero's `subtitle` and layout `variant`, CTA's `text` and layout `variant`.)

### Fixed
- Hero's `eyebrow` field now actually renders — previously the composition could store a value there with no visible effect.

### For contributors
- 10 new style slots for eyebrow/subheading colors across the 4 components (95 total, up from 85). New props registered in `pp_register_component_fields()` for `patch`-command addressability, consistent with existing content fields.
- 15 new PHPUnit tests; fixed 5 existing tests carrying hardcoded slot counts, per-component field-index assertions, or doc-sync counts that this change correctly invalidates.

## [v0.16.20] — 2026-07-05 — Fix: Secondary CTA Buttons No Longer Render Invisible

**Set a CTA or hero's secondary button to "outline," "ghost," or "secondary," and it could render as a solid block of color with text you couldn't read — the button's own background silently overpowered the style it was supposed to have.** This wasn't a rare edge case: it happened to any secondary/outline/ghost button on a CTA block, confirmed on real pages during a benchmark build (an orange button on an orange background, effectively unreadable).

### Fixed
- CTA's outline, ghost, and secondary button styles now render correctly — transparent-with-border, borderless text, and muted-surface-fill respectively — instead of all three inheriting the primary button's solid fill color regardless of which style was chosen.

### Added
- Hero's secondary button and CTA's button each get 6 new style slots (background, border, text color, and their hover equivalents) so an author can give the secondary/outline button its own brand color independent of the primary button — without moving the primary's colors along with it.

### For contributors
- Root cause: `.cta .btn` (two classes) has higher CSS specificity than `.btn--outline`/`.btn--ghost`/`.btn--secondary` (one class each), so it always won regardless of source order — the same bug hero had already fixed in an earlier sprint, still present in CTA. Fixed with the same `:not()` exclusion pattern hero already uses. Confirmed the bug and the fix empirically via real-browser computed-style checks (Playwright), not just CSS reasoning.
- The new override slots are targeted via a dedicated `.hero__cta--secondary` class (added in `hero.php`), not a positional `:nth-child` selector — this codebase's CSS lint guard forbids positional selectors on principle (a reordered composition would silently reattach styling to the wrong element), and `cta_text`/`cta2_text` being fixed named props rather than a reorderable array made a dedicated class both safe and simpler anyway.
- 8 new PHPUnit tests, plus fixes to 7 existing tests carrying hardcoded slot counts or exact class-string assertions that this change correctly invalidated (85 total style slots, up from 73).

## [v0.16.19] — 2026-07-05 — Fix: Dark-Surface Sections Can't Silently Lose Their Text Color Anymore

**A previous production build needed a one-off CSS patch after a dark-band heading rendered nearly invisible — later page styling had quietly overridden the color a dark surface needs to stay readable.** The immediate cause (FAQ headings specifically) was already fixed. This closes the door on the whole class of bug: every component's dark-surface text — headings, body copy, links — is now guaranteed to route through a per-instance color slot, everywhere it's rendered, not just in the one place someone happened to test.

### For contributors
- Audited every styled component (hero, cta, grid, section, faq, stats) for any dark-surface (`--inverted`, or a background-image scrim) text-color rule that bypasses its component's style slots. Found none remaining — the FAQ gap #100 already closed was the only real one; grid's apparent gap on non-`--steps` inverted cards turned out to be a correct design (individual cards intentionally stay light-surfaced there, so dark text is correct).
- Added a new keystone test to `tests/StyleSlotContractTest.php` (`testDarkSurfaceVariantsRouteForegroundColorsThroughSlots`) that scans every dark-surface descendant selector in the stylesheet and fails the build if any of them hardcodes a foreground color instead of routing through `var(--{component}-*, ...)` — verified it actually catches a regression by deliberately breaking a real rule and confirming the test failed, then restoring it. This is a durable guard, not a one-time fix: the next component that adds an inverted variant gets this check automatically.
- `--dark` variants are deliberately out of scope for this guard — this theme's actual token values (`--color-surface: #f4f7fb`) show "dark" is really just a barely-tinted near-white surface, not a genuine contrast risk, despite a couple of schemas historically describing it as "dark."

## [v0.16.18] — 2026-07-05 — New: FAQ and Stats Can Finally Match Your Brand

**Every section-level component could be re-colored to fit a page except two: FAQ and the stats row.** Put an FAQ block on a dark band and the heading turned nearly invisible, with no safe way to fix it — the underlying CSS had a comment admitting exactly that: "faq has no heading-color slot, so it keeps the token." Stats had the same problem: the big numbers were locked to the global accent color with no way to match a specific brand's palette for that one section.

### Added
- FAQ gets 7 new style slots: section background, individual question-card background, heading color, question color, answer color, border color, and the open-question accent color (also drives the chevron).
- Stats gets 4 new style slots: background, heading color, number color, and label color.
- Both support gradient backgrounds, matching every other section-level component.

### Fixed
- FAQ headings on a dark surface no longer go invisible with no way to fix it — the exact gap the previous CSS comment called out is closed.

### For contributors
- Followed the established per-instance style-slot pattern (Recipe A in `docs/AI_IMPLEMENTATION_RECIPES.md`) exactly, including the desktop "premium typography" cross-block overrides that previously hardcoded `--color-text`/`--color-text-secondary` for FAQ specifically — those now route through the new slots. `tests/StyleSlotContractTest.php`'s keystone contract now covers faq and stats (previously excluded since they had zero slots) and gained cross-block guard entries for the two FAQ slots at risk of the same clobber class as #86 — a third slot (`--faq-question-color`) is deliberately excluded from that particular guard with an inline explanation, since its open-accordion state intentionally uses a different slot (`--faq-accent`) and the guard's whole-stylesheet class-substring match can't distinguish that from a real bug. 9 new PHPUnit tests, plus a fix to an existing test that would have otherwise regressed (an AI-prompt test using faq as an "unstyled component" example, no longer true).

## [v0.16.17] — 2026-07-05 — New: Hero's Two Buttons Can Now Use Any Button Style

**Hero sections have always had a primary and a secondary button, but the secondary one was locked to an outline style — no way to make it a solid secondary color or a plain borderless "ghost" link, even though CTA blocks already had all four button styles to choose from.** Both hero buttons now pick from the same shared set — primary, secondary, outline, ghost — that CTA already uses, so a hero's two buttons can match whatever pairing the brand actually calls for.

### Added
- Hero's primary and secondary buttons each get their own style choice (`cta_variant` and `cta2_variant`) — primary, secondary, outline, or ghost. The secondary button still defaults to outline, so every existing page renders exactly as before unless you choose otherwise.

### For contributors
- Reuses the shared `.btn`/`.btn--*` primitive from v0.12.0 exactly as CTA does — hero's CSS already anticipated `.btn--secondary`/`.btn--ghost` on its buttons (the accent-fill exclusion selector already listed them), so no CSS changes were needed, only wiring the new props through to the existing markup. Audited grid and section for the same gap (#93's stated scope): grid's only link surface is a plain per-card text link (`.grid__item-link`, no padding/background/border), not a button, and section has no button/link prop at all — neither is an eligible migration target, so neither was touched. 7 new PHPUnit tests.

## [v0.16.16] — 2026-07-05 — New: Gradient Backgrounds Across Hero, CTA, Grid, and Section

**Set a hero, CTA, grid, or section background to a gradient — the kind of treatment that shows up in most real brand books — and it used to just get rejected.** The safe style-surface only accepted flat colors (hex, rgb, hsl), so a request like "make the hero background a dark diagonal gradient" had no way through: you'd have to settle for a flat color and lose the brand's actual look. Several of these same components already render a gradient by *default* — you just couldn't set your own.

### Added
- 9 background slots across hero, CTA, grid, and section (`--hero-bg`, `--hero-surface-bg`, `--hero-overlay-bg`, `--cta-bg`, `--cta-overlay-bg`, `--grid-bg`, `--grid-card-bg`, `--section-bg`, `--section-overlay-bg`) now accept a `linear-gradient()` or `radial-gradient()` in addition to a flat color — including a `transparent` color stop, so image-overlay scrims (`linear-gradient(to bottom, transparent, rgba(0,0,0,0.7))`) work as expected.

### For contributors
- New `gradient` style-slot type in `lib/apply.php`: a bounded grammar (2-20 color stops, optional direction/shape argument, `conic-gradient()`/`repeating-*-gradient()`/`var()`/`url()`/`env()` explicitly excluded) reusing the existing color validator for each stop. Went through `/plan-eng-review` plus a Codex outside-voice pass that caught a real bug before it shipped: two of the nine slots (`--hero-bg`, `--grid-card-bg`) were consumed via `background-color:` in some CSS rules and `background:` shorthand in others — a gradient is invalid CSS for the former — so those rules were normalized to `background:` shorthand everywhere except `--hero-bg`'s one outline-button hover-state text-color use, which is now a documented, narrow exception (falls back to inherited text color when `--hero-bg` is a gradient). Also closed a matching gap for `shadow` in the AI-facing style-slot type documentation. A follow-up adversarial security review (closed-grammar analysis, ReDoS scaling tests, and tracing both inline-style and `:root{}` rendering sinks) found no bypass. 24 new PHPUnit tests.

## [v0.16.15] — 2026-07-04 — Fix: Resetting Design Tokens Is No Longer a One-Way Trip

**Run `wp pp apply reset` to clear a design token override (or wipe them all back to defaults), and there was no way to undo it.** `wp pp apply restore` — the command that's supposed to undo any token change made during a run — had nothing to work with, because `reset` never told it what it had just cleared. Ask an AI agent to reset a token, watch it reset *every* token by mistake, and the only way back was re-entering every value by hand. Every other token mutation (`apply execute`) already recorded what it touched so `restore` could revert it; `reset` was the one gap.

### Fixed
- `wp pp apply reset` (single-token and reset-all) now records what it cleared, the same way `wp pp apply execute` already does — so `wp pp apply restore` can bring a reset back within the same run, instead of being a dead end.
- `wp pp apply reset` now also requires a valid rollback snapshot before it's allowed to run at all, matching `apply execute`'s existing safety check — the same protection that stops an unrecoverable mistake before it happens, not just after.

### For contributors
- 4 new PHPUnit tests covering the full round trip (override → reset → restore) for both single-token and reset-all, plus a test pinning that `reset`'s touched-token recording unions with — rather than overwrites — anything `execute` already recorded earlier in the same run.

## [v0.16.14] — 2026-07-04 — Fix: Inline SVG Images No Longer Vanish From Your Page

**Set an image field to an inline SVG — a `data:image/svg+xml,...` value, the kind an AI naturally produces when generating a quick icon or graphic on the fly — and it used to just disappear.** Hero backgrounds, grid images, logo strips, section images: any component with an image slot would silently render with no image at all, no error, nothing to click on. WordPress's own URL-safety function throws out `data:` URIs entirely, on the reasonable assumption that most of them aren't real image URLs — but that meant a legitimate, self-contained inline image was treated exactly like a broken link. Regular image URLs (`https://...`, uploaded media) were never affected.

### Fixed
- Every component with an image or background-image slot (hero, CTA, grid, section, stats, logo strip) now correctly displays `data:image/*` URIs, including inline SVGs, instead of rendering blank.

### For contributors
- New `pp_esc_image_src()` in `lib/wp.php` replaces `esc_url()` at every image-slot call site. For `data:image/svg+xml` payloads specifically, the SVG markup is parsed with `DOMDocument`/`DOMXPath` and validated against script execution, event handlers, external resource loading, and CSS/XML-level tricks that can quietly re-target a "safe-looking" reference to an attacker-controlled origin — a data URI can be opened as a top-level document from ordinary browser UI (e.g. "open image in new tab"), where SVG script execution is enabled, so this validation is the primary defense, not a backstop. The implementation went through five rounds of specialist and cross-model adversarial review (Claude + Codex, the latter using empirical Playwright/Chromium testing rather than static reasoning alone), each of which found and closed a genuine bypass — full history in the PR. 49 new PHPUnit tests.

## [v0.16.13] — 2026-07-04 — Fix: The CLI Command in the Docs Now Actually Works

**Every doc and example told you to run `wp pp operate inspect-composition`, but that command didn't exist — WordPress registered it with an underscore (`inspect_composition`) instead of the hyphen the docs used everywhere.** A person hits that, reads the "did you mean" suggestion, and moves on in two seconds. An AI agent following the documented operating loop exactly as written can stop cold on it, or worse, quietly decide the tool doesn't support the step at all.

### Fixed
- `wp pp operate inspect-composition` now works exactly as documented. (The old underscore form now correctly points you back to the hyphenated one if you type it out of habit.)

## [v0.16.12] — 2026-07-04 — Fix: Grid Sections Now Show Their Title When Collapsed

**Collapse a hero section in the composition editor and it shows you a preview of what's inside — but collapse a grid section, and it just says "grid," even with a title set.** On a page with three or four grids, that meant opening each one just to figure out which was which. The label was actually being computed correctly for hero; grid (and several other components — FAQ, section headers, stats blocks, tables, logo strips, embeds) just never got the same treatment because the code only looked at a component's *required* fields, and their title is optional by design.

### Fixed
- Collapsed grid rows (and every other component with an optional title field) now show that title as the row label, matching how hero already worked.

### For contributors
- 15 new JS tests, including the exact truncation boundary (a title at exactly the 40-character cutoff) and a documented, intentional edge case: FAQ's built-in default heading ("Frequently Asked Questions") now shows as the label when no custom title is set — verified against the FAQ component's actual front-end render fallback, so the label matches what visitors actually see.
- The label-picking logic moved out of the jQuery-coupled editor file (which has no test harness) into the shared, already-tested editor-logic module, closing a small but real testability gap along the way.

## [v0.16.11] — 2026-07-04 — Fix: Clicking "Add New Page" No Longer Litters Your Pages List

**Click "Add New Page," then change your mind and click Back — and a permanent, empty "(no title)" page used to get left behind in your Pages list anyway, forever.** Every single visit to that screen created a real, visible draft immediately, before you'd typed a word. Over weeks of normal use, that adds up to a Pages list cluttered with junk nobody meant to create — and those phantom pages were showing up in the AI chat's own list of pages it could edit. New pages now start as WordPress's own "not yet saved" placeholder, invisible until you actually save something, and cleaned up automatically by WordPress itself if you never do.

### Fixed
- Visiting "Add New Page" without saving no longer leaves a permanent, visible junk draft — the placeholder is now invisible until your first real save, and WordPress cleans it up on its own if you never save at all.
- The very first save (title or content) on a new page now correctly makes it a normal, visible draft.

### For contributors
- 12 new PHP tests, including the exact regression a review pass caught before ship: the page title field autosaves on blur even when nothing was typed, which would have quietly recreated the same "permanent empty page" bug through a different trigger. That promotion logic — along with the fix for a second review finding, a silently-swallowed database write failure — now lives inside the shared action-execution layer, so it protects every caller (the editor UI, WP-CLI, and the composition-patch tool), not just the two admin-UI save buttons it started in.
- Filed a follow-up (#160) for three lower-priority edge cases a review pass also surfaced: page actions don't yet reject "not yet saved" pages as targets, a bookmarked link to a garbage-collected page hits a dead end instead of a friendly redirect, and the whole cleanup mechanism depends on WordPress's background cron actually running (the same tradeoff WordPress core itself accepts for its own "new post" screen).

## [v0.16.10] — 2026-07-04 — Fix: Style Fixes Now Target the Component You Actually Meant

**Target a component by its stable ID instead of its position on the page — say, the third section down — and a failed style change used to get "helpfully" analyzed against whatever happened to sit first in the list instead.** Ask to fix a typo'd color setting on your hero section, and if something else came first in the page, the error message and auto-repair would talk about *that* component's settings instead of the hero's — or claim the hero "has no settings at all" when it actually just checked the wrong one. Now both the typo-repair logic and the friendly error messages always resolve to the component you actually targeted, and a genuinely unresolvable target gets an honest "couldn't find that component" instead of a misleading "nothing configurable here."

### Fixed
- Style-slot typo repair and friendly error messages now correctly resolve components targeted by their stable ID, not just by page position.
- A component ID that doesn't match anything on the page now says so plainly, instead of silently reporting the wrong component's (empty) settings list.

### For contributors
- 10 new PHP tests, including the exact failure scenario from the bug report (a component with no style options sitting before the real target) and a precedence test proving an explicit ID wins over a stale, conflicting index.
- The id-to-index resolution logic is now shared between the action-validation layer and these chat-side error helpers (previously duplicated, which is exactly how they drifted out of sync in the first place).

## [v0.16.9] — 2026-07-04 — Fix: Reloading the AI Chat No Longer Shows It Talking to Itself

**After the AI chat applied a change, it quietly noted "changes applied" for its own reference on the next turn — but reload the page, and that note could reappear as if the assistant had actually said it out loud, mixed into your real conversation.** Only the plainest version of that note was ever hidden; anything with a warning, a validation failure, or a "some settings didn't carry over" note leaked straight into the visible transcript. Now every version of that internal note stays exactly what it was meant to be — invisible bookkeeping the assistant still remembers, but never something you see it "say."

### Fixed
- Reloading the AI chat no longer shows internal apply-confirmation notes (including the warning and validation-failure variants) as if the assistant said them conversationally.
- Chat histories saved before this fix are covered too — this isn't just a fix for new conversations going forward.

### For contributors
- 7 new JS tests across two files: one exercising every current confirmation shape plus a genuine reply that happens to start with the same words (never suppressed), one specifically covering pre-existing localStorage data that predates today's structural fix (a real regression cross-model review caught before it shipped — the first version of this fix would have un-hidden the one case that already worked).
- `restoreConversation()` also now tolerates a malformed/hand-edited localStorage entry without breaking the rest of the chat widget's setup.
- Filed a follow-up (#157) for a related but separate finding: the chat's local history isn't isolated per browser tab or per WordPress user, which is a pre-existing gap this fix doesn't touch.

## [v0.16.8] — 2026-07-04 — Fix: The AI Chat Can No Longer Turn a PDF Into a "Photo"

**The AI chat's media picker used to tell the model every file in your Media Library — PDFs, videos, audio — was an "available image" and to copy its URL exactly.** Ask for a hero photo, and it could hand back a brochure PDF as `image_url`, which the page would happily try to render as a broken `<img>`. The media list now only ever shows real, displayable images (SVGs included — WordPress doesn't treat those as renderable images either). And the same check that used to live only in the chat's "run this" button now runs everywhere an action can be executed — the WP-CLI automation path and the composition-patch tool included — so a non-image URL gets rejected no matter which door it comes through.

### Fixed
- The AI chat's media inventory only lists genuine, renderable images — no more PDFs, videos, or audio files mislabeled as "available images."
- Any action that would write a non-image URL into an `image_url` or `background_image` field is now rejected before anything is saved, whether it's triggered from the chat, WP-CLI, or the composition-patch tool — not just the chat's own execute button.

### For contributors
- 12 new PHP tests covering the inventory filter, the execute-time image check (including the SVG mismatch case and a direct `pp_execute_action()` call that bypasses the AJAX layer entirely), and a hardened `$wpdb` test stub that actually verifies the queried URL instead of always returning a fixed match.
- Filed follow-ups for three narrower gaps found during review: relative/CDN media URLs bypassing the check entirely (#153), the image-prop allowlist being hand-maintained instead of schema-driven (#154), and the nav/footer logo picker lacking the same explicit image-type check the site-logo option already has (#155).

## [v0.16.7] — 2026-07-04 — Fix: Nonsensical Spacing/Size Values Are Now Actually Rejected

**A design-token or style-slot value like `calc(px)` or `calc((rem) + 1px)` — missing the number that should go with the unit — used to be accepted as "changed successfully," but the browser just silently threw the value away, so nothing on the page actually moved.** The check meant to catch exactly this kind of malformed value existed in the code but was never wired up — it evaluated a condition and then did nothing with the answer. Now it's enforced: a `calc()` or `clamp()` value has to have a real number attached to every size unit, or the change is rejected up front instead of quietly doing nothing.

### Fixed
- Spacing, sizing, and other length-type design token/style-slot values that don't make structural sense (a unit like `rem` or `px` floating with no number attached) are now rejected at validation time instead of silently persisting as CSS the browser drops.

## [v0.16.6] — 2026-07-04 — Fix: Quotes and Apostrophes in Chat Messages No Longer Get Mangled

**If the chat's live-streaming connection dropped and it silently fell back to its backup delivery method, every apostrophe, quote mark, and backslash you'd typed started coming out wrong — and it got worse with every message after that.** WordPress adds a layer of escaping to form submissions that the theme forgot to remove on that one fallback path, so `"It's live!"` would arrive at the AI as `\"It\'s live!\"`, and the mangling compounded each time the fallback was used again in the same conversation.

### Fixed
- The chat's non-streaming fallback and the action/apply preview and execute requests now correctly preserve quotes, apostrophes, and backslashes in your messages and inputs instead of corrupting them.

## [v0.16.5] — 2026-07-04 — Fix: Pages with Accented or Non-English Filenames No Longer Fail Validation

**If an AI-applied change touched an image with an accented or non-English filename — common on Spanish, French, or other non-English sites — the chat reported "Changes applied but rendered page validation failed... missing media" for a page that was actually completely fine.** The validator that checks a page after an edit was misreading the filename's special characters, so it looked for a file that didn't exist under that garbled name instead of the real one already sitting in the Media Library.

### Fixed
- Post-apply page validation now reads image and link filenames correctly regardless of accented characters or other non-ASCII text, eliminating false "missing media" failures after otherwise-successful AI edits.

### For contributors
- Added regression tests covering a multibyte filename and a component whose rendered output could otherwise re-trigger the mis-read.

## [v0.16.4] — 2026-07-04 — Fix: Editing a CTA Button or Card Link Through Chat Actually Works Now

**Asking the AI to change a CTA button's text or URL, or a grid card's link, previously either silently did nothing or told you the field couldn't be edited — for opposite reasons.** The internal map of "which fields can be edited by name" had drifted from the real component props: it listed fields the CTA and grid components don't have (so editing them wrote data nothing ever reads, and reported success) and left out the fields they actually use (so editing the *real* button text or link failed with "not editable"). Both are now correct — CTA's title/text/button text/button URL and grid cards' title/text/link text/link URL are all editable, and the AI's picture of what's currently in those fields is now accurate too.

### Fixed
- The AI chat's and `wp pp operate`'s field-editing map now matches the CTA and grid components' real props, so editing a CTA button or a grid card's link through chat (or the `patch` CLI command) actually changes what's rendered instead of silently no-opping or failing.

### For contributors
- Added a test that checks every component's field-editing map against its `schema.json` automatically, so this class of drift can't ship again.

## [v0.16.3] — 2026-07-04 — Security: Chat Can No Longer Publish, Trash, or Rewrite Your Site as a Contributor

**A Contributor-level user could previously use the AI chat to publish or trash any page, rename the site, wipe Custom CSS, or reset every design token — all through the same "Permission denied" gate that should have stopped them.** The chat's execute/preview endpoints checked only one coarse capability (the ability to edit posts at all) before running *any* registered action or design change, regardless of how privileged that specific change was. Now each action and design change checks the real WordPress capability it actually requires — the same rules the rest of the admin already enforces. A Contributor can still chat and propose changes, but every privileged step now correctly returns "Permission denied" and changes nothing. Editors keep full page-building power through chat; only site-wide settings and design changes stay Administrator-only.

### Fixed
- The AI chat's execute/preview endpoints now check the specific permission each action or design change requires, instead of one blanket "can edit posts" check — closing a path where a lower-privileged user could publish/trash pages, change site settings, or overwrite design tokens through chat.
- Trashing, restoring, or unpublishing a page through chat now confirms the target is actually a page first, closing a related edge case surfaced while hardening the fix above.

### For contributors
- `tests/bootstrap.php`'s `current_user_can()` test stub can now simulate a specific WordPress role via `$GLOBALS['_pp_test_user_caps']`, for tests that need to assert permission-denied behavior.

## [v0.16.2] — 2026-07-04 — Fix: INSPECT Now Actually Sees Composition Smells

**`wp pp operate inspect --post_id=<id>` finally surfaces the composition warnings it always claimed to.** The INSPECT step reads a page's composition to flag layout smells (like a left-aligned hero with no image), but on every real page it silently returned `smells: []` — the code checked `is_array()` on meta that WordPress always stores as a JSON string, so the check never matched. `wp pp check page` caught the same smells correctly (it used the right accessor); INSPECT just never got the memo. Now it does: an agent running the operating loop's INSPECT step sees the same smells a manual `check page` would.

### Fixed
- `pp_inspect_site()` reads composition through the canonical `pp_get_composition()` accessor instead of raw post meta, so page-specific composition smells reach the INSPECT surface.
- `pp_validate_composition_styling()` and `pp_validate_composition_smells()` now skip malformed (non-array) composition entries instead of indexing into them — hardening added because INSPECT now exercises these validators against real page data for the first time.

### For contributors
- Filed #144 to track a related, pre-existing gap: `pp_get_composition()` can't currently distinguish "no composition" from "composition JSON failed to decode," both return `[]`.

## [v0.16.1] — 2026-07-04 — Docs: The AI Now Has an Accurate Map to the Logo Safe Surface

**Patch release so the theme's AI-facing docs correctly describe the v0.16.0 logo surface, plus a step-by-step guide for setting it.** No code change.

When v0.16.0 added `pp_logo_id` / `pp_logo_alt` to the `update_site_option` whitelist, two `AI_CONTEXT.md` reference tables still listed the whitelist as only `blogname, blogdescription`. An agent reading those tables would think it couldn't set the logo through a site option. Both tables now list the logo keys, and the site-options map in `ai-instructions/website-building.md` gains a "Site logo" row.

New `ai-instructions/set-logo.md` is a task-oriented how-to: find the image's Media Library attachment ID, preview, run a site-scoped preflight, execute `update_site_option` with `pp_logo_id`, and verify — plus the resolution fallback (`logo_id` prop → `pp_logo_id` option → WP `custom_logo` → text wordmark), the footer `show_logo` opt-in, and troubleshooting. It's linked from the `AI_CONTEXT.md` orientation list, so an agent finds it the same way it finds every other task guide.

## [v0.16.0] — 2026-06-30 — Brand Book Fidelity: The Site Logo Is Now a Safe Surface

**The site logo can finally be set the safe way: an attachment ID, validated server-side, rendered in the nav and (opt-in) the footer.** Until now the nav only accepted a raw `logo_url`, and there was no safe path for an AI agent to set the site-wide logo at all. You can now point the logo at a Media Library image by ID through the `update_site_option` action (`pp_logo_id`) or a component prop (`logo_id`), and it resolves the same way everywhere.

Setting the logo by attachment ID closes a real gap. A logo set this way gets WordPress' own validation for free: the value must be an actual image attachment, so a PDF, a video, a bogus ID, or a pasted URL is rejected at write time with a clear message instead of silently producing a broken or unsafe `<img>`. Resolution falls back gracefully: the explicit `logo_id`, then the `pp_logo_id` site option, then WordPress' native `custom_logo`, then the text wordmark. Alt text comes from the attachment's own metadata when you don't set it.

The footer can now carry the logo too, opt-in via `show_logo` so existing footers are untouched. Both nav and footer share one resolver, so the fallback chain and alt handling never drift between them.

### Itemized changes

**Added**
- `pp_logo_id` site option (Media Library attachment ID) settable through the `update_site_option` action — the safe surface for the site-wide logo.
- `logo_id` prop on `nav` and `footer`; `show_logo` opt-in on `footer` (default off).
- Server-side validation: a logo value must resolve to a real image attachment, rejected with a clear error otherwise.
- Graceful resolution: explicit `logo_id` → `pp_logo_id` option → WordPress `custom_logo` → text wordmark, with alt text from attachment metadata.

**Changed**
- The logo input is now an attachment ID, never a raw URL. The old literal `logo_url` prop is gone; an arbitrary URL can no longer reach the logo `<img src>`.
- The site-option allowlist is a single source of truth (`pp_allowed_site_options`) shared by every read and write path.

**For contributors**
- New `pp_allowed_site_options()`, `pp_validate_site_option_value()`, and `pp_resolve_logo()` in `lib/wp.php`; `tests/LogoTest.php` (21 cases) covers validation, the write path, the action, resolution, and footer gating.

## [v0.15.1] — 2026-06-29 — Docs: AI Command Reference Now Shows the Run Token Mutations Require

**Patch release so the theme's AI-facing command reference matches the v0.15.0 gate.** No code change.

`AI_CONTEXT.md` ships inside the theme as the reference an AI agent reads to operate the site. Four mutating examples (`action execute` and the `operate patch` apply path) still showed commands without `--run-id` — so an agent copying them after v0.15.0 would hit the preflight gate and fail. The examples now carry `--run-id=<uuid>` and a one-line note that mutations require a completed INSPECT plus a PREFLIGHT covering the target. Preview commands stay shown without a run token, because they are read-only.

### Itemized changes

#### Changed
- `AI_CONTEXT.md`: `action execute` and the mutating `operate patch` examples now include `--run-id=<uuid>` and note the INSPECT + covering-PREFLIGHT requirement; preview examples remain run-token-free. Matches the v0.15.0 preflight-before-mutation gate. (#96)

## [v0.15.0] — 2026-06-29 — Preflight Before Mutation: No DB-Backed Write Lands Before the Safety Gate

**Mutating `wp pp action execute` and `wp pp operate patch` now refuse to run until a preflight covering their target has passed.** Before this release a typed action could write `_pp_composition` to the database before `wp pp apply preflight` ever ran its target, drift, capability, and surface checks. The operating loop even documented it that way, listing EDIT before PREFLIGHT. A production page could be mutated before the gate that was supposed to protect it.

Now the gate comes first. Each successful preflight records which target it covered: a specific `post_id` for page and section work, or the site grain for site-level actions. A mutating action checks that record and is refused, with an actionable error naming the exact preflight command to run, unless the run already preflighted that target. The matching is strict and non-weakening: a site preflight never unlocks a page mutation, and a preflight for post 4 never unlocks a write to post 7.

**`wp pp operate patch` mutations now require a run token.** Patch was a second CLI path that wrote composition with no preflight at all. Its mutating form now takes `--run-id` and sits behind the same INSPECT plus covering-PREFLIGHT discipline as `action execute`. The `--preview` path stays read-only and needs no run token. This is a breaking change for any script that called `wp pp operate patch <page> --target=... --value=...` standalone to mutate.

**The loop is reordered and the recorder is atomic.** PREFLIGHT now precedes EDIT in the operating loop and its docs. The preflight command commits the PREFLIGHT step, the target coverage, and the pre-apply rollback snapshot in a single locked write, so a partial failure leaves the run fail-closed for both the action gate and the apply gate, never half-unlocked.

### Itemized changes

#### Added
- Preflight-before-mutation gate on `wp pp action execute`: a mutating action requires a completed PREFLIGHT covering its target (post or site) and validates the action first so a bad target shows its real error. (#96)
- `--run-id` on the mutating `wp pp operate patch` path, gated on INSPECT + a covering PREFLIGHT; `--preview` stays read-only and ungated. (#96)
- `pp_operate_record_preflight()` (single atomic write: PREFLIGHT step + target coverage + rollback snapshot) and `pp_operate_preflight_covers()` (strict, fail-closed page-vs-site matching).
- Tests: new PHPUnit cases for coverage matching (including the post-4-must-not-unlock-post-7 and site-vs-page false-pass guards), atomic fail-closed behavior, loop-order, and all-registered-action scope consistency; new E2E specs asserting the gate blocks pre-preflight, unlocks after, and leaves previews open.

#### Changed
- Operating loop reordered so PREFLIGHT precedes EDIT; `ai-instructions/operating-loop.md`, the three playbooks, `bootstrap.md`, and `README.md` updated so no doc implies a DB mutation can happen before preflight. (#96)
- `wp pp apply preflight` records the target it covered and folds the rollback snapshot into one atomic write.

#### Fixed
- `pp_preflight()` target_page check read `_pp_composition` as a raw array, but real pages store it as a JSON string, so it reported "no composition" for every real page. Now that preflight gates mutations this would have blocked all page-scoped actions; it now reads through `pp_get_composition()`. (#96)

## [v0.14.0] — 2026-06-28 — True Per-Run Rollback: `apply restore` Undoes a Run, Not the Whole Palette

**`wp pp apply restore --run-id` now reverts exactly what a run changed instead of wiping every override to product defaults.** The old behavior was a reversibility footgun: a single `update_design_token` on `--color-accent` followed by `restore` reset all 14 existing token overrides and erased the operator's dark theme.

`restore` is now a true per-run rollback. Each run freezes a snapshot of the token overrides at its preflight and records the keys every apply touches (the primary token plus any auto-derived family members). `restore` reverts only those touched keys to their snapshot values, removes tokens the run created, and leaves untouched overrides — including a later run's unrelated work — exactly as they were. `--token` narrows the rollback to one token and its derived family. The genuine "reset to product defaults" behavior moves to a new, honestly-named command, so neither verb lies about what it does.

**Fails closed, every time.** If a run's snapshot or touched-key list is missing, expired, corrupt, swept from `/tmp`, or written by a different install, `restore` reports an error and changes nothing — it never falls back to a product-default reset and never partially mutates `pp_token_overrides`. `apply execute` now refuses to mutate a run that has no usable rollback snapshot, and if it can't record what it touched after a write it errors loudly rather than reporting a clean success.

**Cross-install safety.** Run-state files in the shared system temp dir are now bound to a site identity (site URL + database + blog id), so a run-id created against one install cannot drive a restore on another install that shares `/tmp`.

### Itemized changes

#### Added
- `wp pp apply reset --run-id [--token=<name>]` — the explicit "clear token overrides to product defaults" command (the old `restore` behavior, renamed so it no longer implies undo). (#101)
- `pp_revert_tokens()` — a lock-atomic, fail-closed scoped revert primitive that pre-validates the whole scope and aborts with no write on any invalid snapshot value.
- Run-state now records a frozen pre-apply token snapshot, the touched-key set per apply, and a site identity; new `pp_operate_*` helpers read/record them, all null-vs-empty distinct.
- Tests: 44 new PHPUnit cases (no-collateral-wipe regression, touched-key scoping, family cleanup, single-token + derived, empty-snapshot, missing/corrupt/expired/foreign-identity fail-closed, no-partial-mutation, idempotency).

#### Changed
- `wp pp apply restore --run-id [--token=<name>]` is now a per-run rollback to the run's pre-apply snapshot, not a reset to product defaults. (#101)
- `apply execute` gates on rollbackability before mutating and surfaces a loud error if the touched-key trail can't be recorded.
- `ai-instructions/operating-loop.md` and `AI_CONTEXT.md`: document `restore` (per-run rollback) vs `reset` (product defaults), and the short-lived rollback window.

#### Fixed
- Data-loss footgun: `apply restore` no longer discards unrelated token overrides or wipes the palette to product defaults when undoing a run. (#101)

## [v0.13.1] — 2026-06-28 — Docs: Correct the Design-Token Count the AI Reads

**Patch release so the corrected AI-facing docs reach the distributed theme.** No code change.

The AI instruction docs shipped inside the theme described `assets/css/base.css` as having "33 CSS variables" in three places — a stale count that contradicted the "45 design tokens" stated elsewhere in the same files. An AI agent installing v0.13.0 read the wrong number. v0.13.1 carries the fix into the release artifact.

### Itemized changes

#### Changed
- `AI_CONTEXT.md`, `README.md`, `ai-instructions/retheme.md`: corrected the base.css token count (33 → 45 design tokens), matching `AI_RULES.md` and the rest of the docs.

## [v0.13.0] — 2026-06-28 — Brand Book Fidelity via Safe Surfaces: Honored Slots, Token Locking, and Screenshot Readiness

**The release promise — "Brand Book fidelity via safe surfaces" — now holds where it leaked.** An operator or AI can drive per-instance color through typed style slots and have them actually render on every breakpoint, apply design tokens concurrently without losing writes, give the hero's inner surface its own slots, and check screenshot readiness before claiming a change is verified.

This release closes the four defensibility gaps (#84, #24, #86, #97) and the cross-block-override class behind them (#61), then hardens the same class across six more slot/variant locations found by a dev smoke test that replicated a professional benchmark site.

**Style slots: 67 → 73.** The hero's inner proof/artifact surface gains six per-instance slots (`--hero-surface-bg`, `-padding`, `-border-color`, `-border-width`, `-radius`, `-shadow`), each defaulting to its current value (#24). A schema-derived test now fails if the documented slot count ever drifts from the schemas.

**No more silently-lost token writes.** Every writer of `pp_token_overrides` (`pp_set_token_override`, `pp_clear_token_override`, `pp_clear_all_token_overrides`) now serializes its read-modify-write behind an install-scoped MySQL `GET_LOCK`, with a bounded timeout, a cache-authoritative in-lock re-read, and `finally` release. Lock acquisition failure returns an explicit status instead of clobbering a concurrent write (#97).

**Per-instance color slots survive the desktop cascade.** The "premium typography" media rules hardcoded foreground tokens over declared slots at ≥768px, so dark-band headings, body text, and card text rendered with the global token instead of the slot the author set. `--grid-heading-color`, `--section-title-color`, `--section-text`, `--grid-item-title-color`, `--grid-item-text-color`, `--cta-body-color`, and the card-background slot `--grid-card-bg` are now honored at every breakpoint, and a whole-stylesheet contract test fails closed if any rule reclobbers them (#86, #61).

**Screenshot readiness is now checkable and honest.** New `wp pp screenshot doctor [--probe]` resolves `PP_BROWSER_CMD`, reports the context it tested (CLI vs web) with remediation, and `wp pp apply preflight` surfaces a non-blocking readiness warning. A failed native capture returns an explicit status (`SCREENSHOT_FAILED` / `NEEDS_VISUAL_VERIFICATION`) — a missing browser never lets a run claim native `VERIFIED` (#84).

### Itemized changes

#### Added
- `wp pp screenshot doctor [--probe]` — diagnoses capture readiness (shared `PP_BROWSER_CMD` resolver, CLI-vs-web context, remediation). Non-blocking readiness warning added to `wp pp apply preflight`. (#84)
- Six `--hero-surface-*` per-instance style slots on the hero inner surface, defaulting to current values. (#24)
- `docs/screenshot-setup.md` — `PP_BROWSER_CMD` setup and the adapter CLI contract.
- Tests: `TokenLockTest` (GET_LOCK order, acquisition-failure, release-on-throw, cache-authoritative re-read, install-scoped name), `style-render.spec.ts` E2E render-proof, a whole-stylesheet cross-block override guard and a secondary-button gradient guard, and a schema-derived slot-count test.

#### Changed
- Token writes serialized behind an install-scoped `GET_LOCK` with bounded timeout, in-lock authoritative re-read, and `finally` release. (#97)
- `ai-instructions/operating-loop.md`: corrected `apply restore` (resets to product defaults — not a per-change undo) and the `apply execute` description; documented `screenshot doctor`, readiness warning, and status vocabulary.
- `AI_CONTEXT.md` / `README.md`: style-slot count 67 → 73 (hero 24); current test counts.

#### Fixed
- Per-instance color slots clobbered by the desktop typography cascade — heading, body, card-title, card-text, and CTA-body slots now honored at all breakpoints. (#86, #61)
- `--grid-card-bg` ignored on default grids (cards rendered light on dark bands, first card auto-highlighted) — the late `background` shorthand now routes through the slot.
- Secondary/ghost hero CTA rendered as a filled primary (low-contrast accent-on-accent) — the premium-CTA gradient is now scoped away from outline/ghost/secondary variants, and the hero outline foreground tracks `--hero-text` for guaranteed contrast.

## [v0.12.1] — 2026-06-26 — Docs: AI Guides for the New Presentation Controls

**The site-builder AI now ships with guides for the v0.12.0 controls.**
**No code change — this release exists so the documentation reaches the deployed theme.**

The v0.12.0 presentation controls (shadows, button variants, typography roles, nav
diagnostics) shipped before their AI-facing guides were written. This patch carries
those guides into the distributed theme so an AI agent installing PromptingPress
reads accurate instructions for the new controls.

`ai-instructions/style-component.md` gains a worked example that sets a shadow slot
through `style_component` and a `button_variant` prop through `update_component`,
making the props-vs-slots distinction explicit. `ai-instructions/validate-site.md`
documents the navigation readiness diagnostics (what they flag, where they surface,
how to fix). Component READMEs, `AI_CONTEXT.md`, and `AI_RULES.md` are corrected for
the new design-token count (45) and style-slot count (67).

No theme behavior changed. If you already run v0.12.0, the only difference in v0.12.1
is more accurate in-repo documentation for the AI.

### Itemized changes

#### Changed
- `ai-instructions/style-component.md`: added the `shadow` slot type, a props-vs-slots
  note, and a worked example for shadows + button variants + text roles.
- `ai-instructions/validate-site.md`: added a navigation readiness section.
- `ai-instructions/retheme.md`, `AI_CONTEXT.md`, `AI_RULES.md`, `README.md`,
  `components/cta/README.md`, `components/grid/README.md`: documented `button_variant`
  and `text_role`, and corrected the token (45) and style-slot (67) counts.

## [v0.12.0] — 2026-06-26 — Generic Presentation Controls: Bounded Style Flexibility the AI Can Actually Use

**The site-builder AI can now set shadows, button variants, and technical text styles through typed, bounded controls.**
**A contract test guarantees every style slot a component declares actually reaches the page.**

This release widens what the AI can safely style without turning the theme into a
freeform CSS editor. Components gain a bounded `shadow` control (a preset like
`var(--shadow-md)` or a single-layer box-shadow, validated and injection-guarded),
plus consistent border-color, border-width, radius, and shadow slots across the
hero, section, grid, and CTA. The shared button picks up a token contract with two
new variants (`secondary`, `ghost`) selectable per CTA, on top of the existing
`primary` and `outline` — without breaking any composition that already sets a
button color. Typography moves past body and headings: mono, meta, label, and
kicker roles are exposed as tokens and utility classes, and grid cards can tag
their text with a role.

The headline reliability change is a contract test: a style slot a component
declares in its schema must actually be consumed in that component's CSS, on a
property compatible with its type. A slot the AI can set but the renderer silently
ignores now fails the build instead of shipping. Operators also get navigation
readiness diagnostics that flag an empty or unconfigured menu for the locations a
page actually uses, surfaced through the existing preflight and post-apply checks.

### The numbers that matter

Source: the PHPUnit suite (`vendor/bin/phpunit`) and the Vitest suite (`npm test`).

| Metric | Before (v0.11.0) | After (v0.12.0) | Δ |
|---|---|---|---|
| Per-component style slots | 59 | 67 | +8 |
| Slot value types | color / length / number | + shadow | bounded new type |
| Button variants | primary / outline | + secondary / ghost | reusable contract |
| Typography roles | body / heading | + mono / meta / label / kicker | new surface |
| Nav readiness diagnostics | none | preflight + post-apply | new |
| PHP unit tests | 671 | 715 | +44 |

The new `StyleSlotContractTest` is the keystone: it parses each component's CSS
block and proves every declared slot is consumed there on a type-compatible
property, so an accepted-but-dropped slot can never reach production silently.

### What this means for site builders

The AI gets a wider, still-safe palette: drop shadows, outline and ghost buttons,
and mono or label text, all through typed slots and props rather than raw CSS. The
bounds are real (no `inset`, no multi-layer shadows, no arbitrary CSS), so the
flexibility cannot become a foot-gun. Existing sites are unaffected: the shared
button was tokenized to render identically when untouched, and old `--cta-accent`
button colors keep working. If a page renders a nav menu that has no items
assigned, preflight now tells you, instead of shipping an empty menu.

### Itemized changes

#### Added
- Bounded `shadow` style-slot type (`_pp_validate_shadow` in `lib/apply.php`):
  accepts `var(--shadow-none|sm|md|lg)` or `none`, or a single-layer `box-shadow`
  (2-4 px/rem lengths + an rgb/rgba/hsl/hsla color); rejects `inset`, multi-layer,
  `url()`, and arbitrary `var()`. Backed by `--shadow-none|sm|md|lg` tokens.
- Namespaced `border-color`, `border-width`, `radius`, and `shadow` style slots
  across `hero`, `section`, `grid` (card), and `cta`, consumed in `components.css`.
- Shared button token contract (`--btn-bg/-border-color/-text/-radius/-shadow`) with
  new `.btn--secondary` / `.btn--ghost` variants; a `button_variant` prop on CTA
  (primary/secondary/outline/ghost) set via `update_component`.
- Typography roles: `--font-mono` plus meta/label/kicker tokens, `.text-mono` /
  `.text-meta` / `.text-label` / `.text-kicker` utilities, and an optional grid item
  `text_role` reflected in rendered output.
- `pp_check_nav_readiness()` (`lib/wp.php`): warning-grade diagnostics for the nav
  locations a composition references (unassigned menu, empty menu, unregistered
  location), surfaced through `pp_preflight()` and post-apply validation.
- New tests: `StyleSlotContractTest`, `NavReadinessTest`, `TypographyRoleTest`, plus
  shadow, button-variant, and `--cta-accent` regression cases. PHP 671 → 715.

#### Changed
- The shared `.btn` is tokenized so an unstyled button renders byte-identically;
  variant defaults live on the `.btn--*` rules and per-instance CTA color stays on
  the component-scoped `.cta .btn` path, so section styles never leak into buttons.
- `code` / `pre` now consume `var(--font-mono)` instead of a hard-coded stack.

#### Fixed
- `pp_get_composition()` returns an already-decoded array defensively instead of
  calling `json_decode()` on it; nav diagnostics skip non-array items/props.
- The validation-failure helper now suggests a valid value for `shadow` slots.

#### For contributors
- `SchemaValidationTest` enforces the `shadow` type and a common-visual-slot
  conformance check across the four styleable components.
- Test bootstrap gained `get_registered_nav_menus` / `get_nav_menu_locations` /
  `has_nav_menu` / `wp_get_nav_menu_items` stubs.

## [v0.11.0] — 2026-06-24 — Upgrade-Safety Guardrails: Updates Stop Before They Overwrite Your Work

**Theme updates now refuse to run when they'd silently destroy local changes.**
**The integrity warning went from a passive notice to an actual stop sign.**

Before this release, PromptingPress could detect that theme files had drifted from
the shipped baseline, but nothing stopped an update from replacing the whole theme
directory and wiping those edits — the warning only told you after the damage was
done. Now an update to the active PromptingPress theme is blocked before any file is
written if local files are modified, missing, or extra. The block returns a clear
error naming how many files changed, points you at `wp pp integrity check` for the
list, and explains how to keep the changes (move them into design tokens,
compositions, or content) or override the block deliberately.

A daily background check keeps the "theme files modified" status current, so the
admin warning reflects reality without waiting for you to re-activate the theme or
run a CLI command. And because a silent auto-update never shows a live error to a
human, a blocked update is recorded and surfaced on the admin screen: when it
happened, why, and which files caused it.

Alongside the enforcement, the AI guidance was corrected. The theme's
`templates/`, `components/`, and `assets/` directories are release artifacts —
editing them to customize a single site loses the work on the next update. The
docs now treat those paths as inspect-only for site work and route site changes
through design tokens (`update_design_token`), fonts (`enqueue_font`), and
compositions, which survive updates.

### The numbers that matter

Source: the PHPUnit suite (`vendor/bin/phpunit`) and the integrity guard's branch
coverage.

| Metric | Before (v0.10.0) | After (v0.11.0) | Δ |
|---|---|---|---|
| Theme update blocked on local drift | no | yes | new guardrail |
| Integrity status refresh | activation / update / manual | + daily cron | continuous |
| Blocked-update visibility | none | admin notice + stored record | new |
| PHP unit tests | 645 | 671 | +26 |

The 26 new tests cover every branch of the pre-update guard (safe / no-manifest /
corrupt-manifest / modified / missing / extra / bypass), the theme-detection helper
against single, bulk, and auto-update hook shapes, and the cron schedule / clear
lifecycle.

### What this means for site builders

If you customize a PromptingPress site the supported way — tokens, fonts,
compositions — nothing changes and updates keep flowing. If files in the theme
directory have been hand-edited, the next update stops and tells you, instead of
quietly erasing them. To update anyway, restore the files or add a
`pp_allow_unsafe_theme_update` filter returning true.

### Itemized changes

#### Added
- Pre-update guard on `upgrader_pre_install`: blocks updates/installs of the active
  PromptingPress theme when integrity is `unsafe` (modified/missing/extra) or the
  manifest is corrupt; allows when clean or when no manifest exists. Override via the
  `pp_allow_unsafe_theme_update` filter.
- Daily `pp_daily_integrity_check` WP-Cron event running `pp_check_theme_integrity()`,
  scheduled idempotently on theme activation and cleared on theme switch.
- `pp_last_blocked_update` record + a dedicated admin notice surfacing a blocked
  update's date, reason, and affected-file counts.
- `tests/SetupTest.php` — 24 tests for the guard, the theme-detection helper, the
  cron lifecycle, and last-blocked persistence.

#### Changed
- AI docs (`AI_RULES.md`, `AI_CONTEXT.md`, `ai-instructions/retheme.md`,
  `ai-instructions/build-landing-page.md`): parent-theme `templates/`,
  `components/`, and `assets/` are inspect-only for site customization; site styling
  goes through `update_design_token` / `enqueue_font` / compositions. Editing those
  files is framed as release/product development; a governing statement scopes the
  per-component `safe_to_edit` fields the same way.

#### For contributors
- Shared `_pp_is_active_theme_update()` helper detects the active-theme update across
  WordPress's differing `hook_extra` shapes (matches on theme slug, not `type`, so
  bulk updates are covered) and is reused by the existing
  `upgrader_process_complete` handler.
- Test bootstrap gained stateful WP-Cron stubs and an override-aware `apply_filters`
  stub; `lib/setup.php` is now loaded in the test harness.

## [v0.10.0] — 2026-06-22 — Shipping Confidence: Enforced Tests + WP 7.0 E2E

This release hardens how PromptingPress ships rather than adding user-facing surface. The full unit suite (645 PHP + 247 JS) now runs in CI on every push and again as a gate before any release ZIP is built, so a red test can no longer reach a published theme. The end-to-end suite runs against WordPress 7.0 (the version the theme actually requires) instead of 6.7, with a non-blocking smoke check on push and a nightly full run. Destructive AI actions now derive their confirmation warnings from the action/apply registry, so a newly added destructive capability can never silently ship without a warning. Version numbers are kept consistent across all five locations (style.css, functions.php, package.json, README badge, readme.txt) by an enforced check.

No new end-user features. If you build with PromptingPress, the change you feel is fewer broken releases and AI edits that warn before they destroy.

### Changes

- **Added:** CI unit-test gate — `composer test` + `npm test` run on every push to main and as a hard gate in the release workflow before the ZIP is built.
- **Added:** end-to-end CI workflow — `@smoke` subset on push (non-blocking signal) plus a nightly full Playwright run against WordPress 7.0, with traces uploaded on failure.
- **Added:** server-driven destructive-action warnings — the chat UI now reads `impact_warning` strings from the action/apply registries, with a registry-coverage test that fails CI if a known destructive capability lacks a warning. Closes #74.
- **Added:** shared PHP/JS composition-validation contract — golden fixtures asserted by both validators so they cannot silently drift.
- **Changed:** E2E now targets WordPress 7.0 (matching `Requires at least: 7.0`); fixed the action-layer test to pass the now-required `--run-id`. Fixes #79.
- **Changed:** version-consistency check extended from 3 files to 5 (adds README badge + readme.txt Stable tag), enforced in `package.sh`, CI, and release.
- **Fixed:** README/readme.txt version drift (advertised 0.8.4 while the code was 0.9.0).
- **Tests:** 645 PHP + 247 JS passing. One E2E broken-media validation check is quarantined pending investigation (#83).

---

## [v0.9.0] — 2026-06-21 — Editor Serialization Safety Gate

### The composition editor can no longer silently corrupt a page on save

Opening a page in the accordion editor and saving it back used to risk quietly changing the page's structure: optional fields could be materialized, nested arrays dropped, or extra top-level keys like `style` lost. The accordion now runs a round-trip check before it opens. It parses the stored JSON, rebuilds the accordion, serializes it back, and compares. If anything would change, the accordion does not open.

When that happens you get an honest fallback instead of a broken edit. The editor stays in JSON-only mode and shows an "Accordion unavailable for this composition" panel with a per-component diff table: which field, before, after, and the kind of change. A "Copy as GitHub Issue" button turns that diff into a ready-to-file report. Your JSON is untouched and fully editable. Saving or publishing re-runs the check against the server-normalized composition, and the accordion comes back automatically once the round-trip is clean again.

Editing a field in the accordion also updates the live preview again (a regression where preview stopped refreshing after accordion edits is fixed), and empty or brand-new pages no longer trip the gate.

No new commands or settings. The gate runs automatically when you open a composition; the JSON editor is always available as the fallback.

### Changes

- **Added:** serialization round-trip invariant gate in the composition editor — blocks the accordion when opening would alter structure, with a per-component diff table and one-click GitHub issue report; JSON view remains the safe fallback.
- **Added:** save/publish now return the server-normalized composition, so the editor re-checks against persisted state and restores the accordion once drift is resolved.
- **Fixed:** live preview now refreshes when you edit fields in the accordion.
- **Fixed:** empty, new, and whitespace-only compositions no longer trigger a false invariant failure.
- **Fixed:** narrow editor pane now shows the diff as stacked cards (previously the diff disappeared at narrow widths); diff-table labels meet AA contrast.
- **Tests:** 23 new unit tests and 5 new end-to-end tests covering the gate's blocked, save-restore, publish-restore, and copy-issue paths.

---

## [v0.8.4] — 2026-06-17 — Token Family Derivation + Palette Coherence Warnings

### Changing one color now updates related tokens automatically, and warns when old ones look stale

When the AI (or user) changes a base color token like `--color-accent`, four derived tokens (hover, strong, border-accent, surface-accent) are now auto-filled if they don't already have an explicit override. This fixes the stale-token bug where changing a palette direction left related tokens pointing at the old color.

The system respects intentional choices: if a derived token already has an explicit override in the database, it is left alone. Instead of silently overwriting, the apply handler returns advisory "stale warnings" when an existing override's hue drifts more than 30 degrees from the new base. These warnings surface in the chat UI as amber cards and feed into the AI's conversation context, so the next turn can offer to update them.

Button and CTA shadows no longer use hardcoded blue `rgba()` values. Ten instances are converted to `color-mix(in srgb, var(--color-accent-strong) N%, transparent)`, so shadows adapt to whatever palette is active.

No new commands or flags. Token family derivation fires automatically on `update_design_token` applies. Stale warnings appear in the chat UI and feed into the AI conversation for the next turn.

### Changes

- **Token family derivation**: `pp_token_families()`, `pp_derive_family_tokens()`, `pp_check_token_coherence()` in `lib/wp.php`
- **Fallback-only apply handler**: derivation only fills unset tokens; existing overrides preserved (`lib/apply.php`)
- **Stale warning UI**: amber cards in post-apply card, filtered against explicitly-updated tokens in multi-step proposals (`assets/js/pp-ai-chat.js`)
- **Adaptive CSS**: 10 hardcoded blue rgba() values converted to color-mix() with token references (`assets/css/components.css`)
- **CSS lint update**: color-mix() removed from banned modern features list (`tests/js/css-lint.test.js`)
- **10 new tests**: 7 PHP tests (derivation, fallback skip, stale warnings, coherence), 3 existing tests updated

### The 5 numbers that matter

| Metric | Value |
|--------|-------|
| Files changed | 6 (4 source + 2 test) |
| Lines added | 383 |
| Lines removed | 18 |
| PHP tests | 641 passing |
| JS tests | 208 passing |

---

## [v0.8.3] — 2026-06-16 — Schema Awareness + Guided Recovery UX

### The AI checks the schema before proposing, and explains when changes aren't possible

The system prompt now includes a pre-proposal verification checklist: before generating a `style_component` proposal, the LLM must confirm the target component owns the slot, the slot exists in the schema, and the value is representable. If any check fails, the LLM explains conversationally instead of generating a broken proposal.

When validation catches an invalid style slot, the error handler now searches all registered components for a matching slot (exact name first, then prefix-stripped suffix match). Cross-component hints tell the user where the slot actually lives: "This setting exists on the grid component, not the section."

The proposal card distinguishes impossible requests (grey border, neutral background) from fixable ones (amber border, attention background). Each error step shows a plain-language explanation, and a native `<details>` disclosure hides the raw technical details (slot names, alternatives list) behind "Show technical details". The status bar now shows contextual messages derived from the first failed step instead of the generic "Preview failed."

### Changes

- **System prompt hardening**: 3-point pre-proposal verification checklist in `lib/ai-context.php`
- **Cross-component slot search**: exact-name and suffix-match strategies in `lib/ai-chat.php`
- **Guided error card**: `renderPreviewError()` renders structured error with hint, alternatives, and `<details>` disclosure
- **Error state CSS**: `.pp-ai-step-impossible` (grey #8c8f94) and `.pp-ai-step-fixable` (amber #dba617)
- **Contextual status messages**: `getStatusMessage()` returns situation-specific text per error type
- **16 new tests**: 7 PHP tests for cross-component hints, 9 JS tests for error card rendering

### The 5 numbers that matter

| Metric | Value |
|--------|-------|
| Files changed | 6 (4 source + 2 test) |
| Lines added | 472 |
| Lines removed | 46 |
| PHP tests | 614 passing |
| JS tests | 191 passing |

---

## [v0.8.2] — 2026-06-15 — AI Context Quality + Visual Accountability

### The chat now knows what it's editing — and shows you what will change before it happens

The admin AI chat suffered from two blind spots: the LLM couldn't see style slots, recipes, or enum values in the system prompt (forcing it to guess and learn from validation errors), and the proposal card executed mutations without showing before/after state. Both are fixed.

The system prompt now includes per-component style slot inventories, recipe definitions with descriptions, pipe-separated enum values instead of bare type strings, and per-instance inspect data (active recipe, overridden slots, editable fields) in page context. The model proposes correct `style_component` calls on the first attempt instead of round-tripping through validation errors.

The proposal card now fetches a preview before showing Apply — each step displays before/after diffs inline. High-impact actions (`update_composition`, `reset_all_design_tokens`, `clear_custom_css`, `remove_component`) show amber warnings. After applying, a "View Page" link opens the affected page. Single-step token changes get a "Reset to default" shortcut.

When an invalid style slot name is close to a valid one (Levenshtein distance ≤ 3), the validation error now suggests the correct name. CSS keywords like `red` or `bold` get contextual alternatives ("Did you mean `#ff0000`?"). All validation errors return structured objects with `error_code`, `user_message`, `alternatives`, and `raw_error`.

### The 5 numbers that matter

Source: `php vendor/bin/phpunit` + `npx vitest run` on the repo.

| Metric | Before (v0.8.1) | After (v0.8.2) | Delta |
|--------|-----------------|-----------------|-------|
| PHP tests | 572 | 607 | +35 |
| JS tests | 141 | 180 | +39 |
| Style slots | 58 | 59 | +1 |
| Proposal card preview lines | 0 | per-step | new |
| AI context: style slot visibility | none | 59 slots + 9 recipes | new |

### What this means for site builders

Open the AI chat, ask "make the hero section dark with more padding," and the model proposes the right `style_component` call with correct slot names on the first try. The proposal card shows you exactly what will change before you click Apply. If you don't like it, click "View Page" to check, then ask the chat to adjust.

### Added
- Style slot inventories injected into system prompt component catalog (59 slots across 4 components)
- Recipe definitions with descriptions in system prompt (9 recipes across 4 components)
- Enum prop values rendered as `"left"|"centered"|"split"|"cover"` instead of `string` in condensed schemas
- Per-instance inspect data in page context: active recipe, overridden style slots, editable field names per type
- `--grid-heading-max-width` style slot (59th slot) for grid component heading width control
- Style slot value rules injected into system prompt (slot type guidance for the LLM)
- `_pp_attempt_style_repair()` — Levenshtein-based fuzzy matching for misspelled slot names (threshold ≤ 3)
- `_pp_build_friendly_error()` — structured error builder returning `{error_code, user_message, alternatives, raw_error}`
- `_pp_suggest_alternative_value()` — CSS keyword detection with contextual alternative suggestions
- `ppChatRenderPreviewError()` — preview error rendering in proposal card
- Proposal card preview: each step fetches `pp_ai_preview` and displays before/after diffs before Apply is available
- Impact warnings on high-impact actions: amber banner for `update_composition`, `reset_all_design_tokens`, `clear_custom_css`, `remove_component`
- Multi-step proposals (3+ steps) show card-level warning
- "View Page" link after successful apply
- "Reset to default" shortcut after single-step `update_design_token` apply
- ARIA attributes on chat UI: `aria-live="polite"`, `role="status"`, `aria-label` on interactive elements
- Focus management improvements for keyboard navigation in chat
- Empty state guidance message in chat
- Arrow separator between diff from/to values in proposal steps

### Changed
- `pp_ai_condense_schema()` renders enum values as pipe-separated quoted strings
- `pp_ai_system_prompt()` appends style slot and recipe sections per styled component
- `_pp_summarize_component()` includes recipe, overridden slots, and editable fields (balanced verbosity)
- `pp_ai_format_messages()` calls `pp_inspect_composition()` for page context enrichment

### Fixed
- Preview error states disable Apply button for entire proposal (no partial application)
- Error text in failed proposal steps uses prose styling instead of monospace

### Tests
- 607 PHP tests, 2239 assertions (was 572 tests, 2158 assertions)
- 180 JS tests (was 141)
- New PHP: 8 tests for AI context enrichment (style slots in prompt, recipes in prompt, enum rendering, inspect data in page context, graceful error handling)
- New PHP: 27 tests for style repair, friendly errors, and alternative suggestions
- New JS: 38 tests for proposal card (preview fetch, warning map, Apply binding, View Page link, token reset, multi-step warning)

---

## [v0.8.1] — 2026-06-14 — Non-Destructive Dashboard Saves

### Editing one field no longer erases another

A `data-field` attribute mismatch between the accordion editor's render and read paths caused array field content (grid cards, FAQ answers) to silently zero out whenever any scalar field was saved. The render path wrote `data-field="question"` but the read path searched for `data-field="items.question"` — a selector that matched nothing. Every array item read back as `{}`, and the full-composition save persisted the damage.

The fix is a 1-character selector change plus a data-loss guard. The guard detects when ALL DOM-read items are empty objects but the original composition had content, logs a warning, and skips the sync for that field. Partial edits (some items empty, others populated) pass through normally — the guard only fires on total loss.

### The 4 numbers that matter

Source: `npm test` + `php vendor/bin/phpunit` on the repo.

| Metric | Before (v0.8.0) | After (v0.8.1) | Delta |
|--------|-----------------|-----------------|-------|
| JS tests | 136 | 141 | +5 |
| JS assertions (guard) | 0 | 6 | +6 |
| DOM selector tests | 0 | 5 | +5 |
| PHP tests | 572 | 572 | 0 |

### Fixed
- Array field sync selector in `syncAccordionToJson` changed from `field.name + '.' + sk` to `sk` — matches the `data-field` attributes rendered by `buildFieldHtml` (#73)

### Added
- `wouldLoseArrayData(newItems, origItems)` pure guard function in `pp-editor-logic.js` — returns `true` when all new items are empty objects but originals had content
- Guard wiring in `syncAccordionToJson` — logs `console.warn` and preserves original `field.value` when guard fires
- DOM selector alignment test (`pp-editor-dom.test.js`) — jsdom+jQuery round-trip proving fixed selector finds elements and broken selector finds nothing
- 6 unit tests for `wouldLoseArrayData` covering all-empty, normal edit, empty originals, undefined originals, empty array, and partial empty cases
- 2 round-trip tests for grid and hero+grid mixed compositions
- `jquery` and `jsdom` devDependencies for DOM-level testing

### Closes
- #73

---

## [v0.8.0] — 2026-06-14 — Theme Integrity Status

### Know before you update: which shipped files changed on disk

PromptingPress now ships an integrity manifest inside every package. The manifest records the MD5 hash of every file at build time. After installation, `wp pp integrity check` compares live files against that baseline and reports modified, missing, or extra files. A persistent admin notice warns site owners when theme files have been modified locally, because a theme update replaces the entire directory and would silently overwrite those changes.

The check runs automatically on theme activation and after theme updates. Between checks, the admin notice reads from a stored option (no file hashing on every page load). When the theme version changes, stale results clear automatically. The CLI offers two commands: `check` runs a full comparison and updates the stored status, `status` reads the last result without touching the filesystem.

### The 4 numbers that matter

Source: `php vendor/bin/phpunit` on the repo, `wp pp integrity check` on dev site.

| Metric | Before (v0.7.0) | After (v0.8.0) | Delta |
|--------|-----------------|-----------------|-------|
| PHP tests | 546 | 572 | +26 |
| PHP assertions | 2078 | 2158 | +80 |
| Files tracked in manifest | 0 | 95 | +95 |
| CLI exit codes for integrity | 0 | 4 | +4 |

Every file that ships in the package is now tracked. An AI agent, deploy script, or manual edit that modifies a theme file will be caught before the next update overwrites it.

### What this means for site builders

Before updating PromptingPress, check the admin dashboard. If you see a red notice, your theme files have been modified since installation. Run `wp pp integrity check` to see exactly which files changed, which are missing, and which extra files exist that would be lost on update. Move custom work to a child theme or plugin before proceeding. If you see a yellow notice, the manifest itself is unreadable. Restore it from the matching GitHub release.

### Added
- `integrity-manifest.json` generated at build time inside the theme package (95 files hashed)
- `_pp_hash_all_theme_files(string $theme_path)` — extension-agnostic file hasher with `.distignore`-equivalent skip list
- `pp_check_theme_integrity()` — loads manifest, validates JSON + schema, compares hashes, stores result in `pp_theme_integrity` option
- `pp_admin_notice_theme_integrity()` — persistent admin notice: red (`notice-error`) for modified files, yellow (`notice-warning`) for invalid manifest
- `wp pp integrity check` CLI command — full integrity comparison with exit codes: 0 (safe), 1 (unsafe), 2 (invalid manifest), 3 (no manifest)
- `wp pp integrity status` CLI command — reads stored result without file I/O, warns about staleness on version mismatch
- Lifecycle hooks: `after_switch_theme` runs integrity check, `upgrader_process_complete` clears stale results and re-checks, `switch_theme` cleans up option

### Changed
- `scripts/package.sh` generates `integrity-manifest.json` from staged package directory between rsync and ZIP creation
- `.distignore` and `.gitignore` exclude `integrity-manifest.json` (build artifact, not tracked in repo)
- Version mismatch between stored result and `PP_VERSION` auto-clears stale integrity status on admin page load

### Tests
- 572 PHP tests, 2158 assertions (was 546 tests, 2078 assertions)
- New: 8 tests for `_pp_hash_all_theme_files()` (all file types, skip dirs, skip files, skip manifest, skip dotfiles, skip ZIP pattern, sorted keys, unreadable files)
- New: 12 tests for `pp_check_theme_integrity()` (no manifest, invalid JSON, missing schema keys, empty file_hashes, safe match, modified/missing/extra detection, multiple drift types, option storage, error field)
- New: 6 tests for `pp_admin_notice_theme_integrity()` (missing option, safe status, unsafe notice, invalid manifest notice, version mismatch clear, post-clear silence)

---

## [v0.7.0] — 2026-06-13 — Instance-Scoped Style Slots

### Every component instance can now look different without touching CSS

PromptingPress sites no longer all look the same. An AI agent can make this page's hero dark and spacious while that page's hero is tight and accent-bordered, all through the existing composition data model. No CSS file edits, no custom classes, no inline style hacks.

58 style slots across 4 components (hero: 14, section: 13, grid: 16, cta: 15) let agents control padding, colors, typography, borders, and radii per component instance. Each slot is declared in `schema.json`, validated against type-safe rules, stored in composition post meta alongside props, and rendered as CSS custom properties with global token fallbacks. When no override is set, the global design token fires. When an override is set, it wins for that instance only.

Style recipes provide named shorthand: `dark-spacious` expands to `--hero-bg: #1a1a2e; --hero-text: #f0f0f0; --hero-padding-top: 6rem; --hero-padding-bottom: 6rem`. Apply a recipe, then override individual slots. The recipe name tracks in the composition data so `inspect-composition` shows what's active.

Font loading no longer requires editing `functions.php`. Three new applies (`enqueue_font`, `remove_font`, `reset_fonts`) manage Google/Bunny font URLs in the database, max 5, HTTPS-only. Fonts enqueue before `pp-base` automatically.

Surface classification guards against core file edits. `wp pp check surface lib/wp.php` returns `core` with routing guidance toward the correct approved surface. Preflight blocks core-file mutations with actionable error messages.

### The 4 numbers that matter

Source: `php vendor/bin/phpunit` + `npm test` on the repo, `wp pp action` on dev site.

| Metric | Before (v0.6.0) | After (v0.7.0) | Delta |
|--------|-----------------|-----------------|-------|
| Style slots available | 0 | 58 | +58 |
| PHP tests | 485 | 546 | +61 |
| JS tests | 67 | 128 | +61 |
| Schema-declared recipes | 0 | 10 | +10 |

An agent can now achieve 20 distinct visual treatments per page through `style_component` alone. Previously, every page built from the same components looked identical.

### What this means for site builders

Every PromptingPress component is now a canvas, not a stamp. The AI can make a hero feel like a premium landing page (dark background, oversized title, tight content column) or a grid feel like a product showcase (dark section with light cards, generous spacing, rounded corners), all through the operating loop. Run `wp pp operate inspect-composition <page>` to see every available slot with its current value and default.

### Added
- `styling.style_slots` in schema.json for hero (14), section (13), grid (16), cta (15)
- `styling.recipes` in schema.json — 2-3 recipes per component (dark-spacious, accent-bordered, compact, etc.)
- `pp_get_style_slots(string $component)` — reads style slot registry from schema cache
- `pp_get_style_recipes(string $component)` — reads recipe definitions from schema
- `pp_render_style_vars(array $style, string $component)` — validates slots, escapes values, returns CSS custom property string
- `style_component` action — PATCH semantics, validates slot names + values, supports recipe expansion, null removes slots
- `enqueue_font` / `remove_font` / `reset_fonts` applies — database-backed font URL management (max 5, HTTPS-only)
- `pp_get_font_urls()` / `pp_set_font_urls()` — font URL CRUD
- `pp_classify_surface(string $path)` — returns `safe` / `extension` / `core` with routing guidance
- `wp pp check surface <path>` CLI command
- Surface classification check (Check 7) in `pp_preflight()`
- `clamp()`, `calc()`, and unitless `0` support in `_pp_validate_length()` with positive-pattern regex (no nested `var()`)
- CSS fallback pattern for all 58 slots in `components.css` including variant cascade fix (~20 variant rules)
- CSS lint test verifying every slot uses the `var(--slot, fallback)` pattern
- `ai-instructions/style-component.md` workflow guide

### Changed
- Composition entries accept optional `style` key at same level as `props`
- `pp_validate_composition()` validates style keys against component schema
- `pp_normalize_composition()` strips invalid/empty style entries
- `update_component` action accepts optional `style` param (convenience: set props + style in one call)
- `inspect-composition` output includes available style_slots, current values, defaults, active recipe, and available recipes per component
- `templates/composition.php` passes `$item['style']` to components via `$props['__pp_style']`
- `templates/front-page.php` passes `$item['style']` to components via `$props['__pp_style']`
- AJAX preview handler in `lib/admin.php` passes style data to components
- hero.php, section.php, grid.php, cta.php read `$props['__pp_style']` and render as inline CSS custom properties
- `functions.php` enqueues database-backed font URLs before `pp-base`
- `AI_CONTEXT.md` updated with style slot system, new actions, new applies
- `ai-instructions/retheme.md` updated with style slot workflow

### Tests
- 546 PHP tests, 2078 assertions (was 485 tests, 1494 assertions)
- 128 JS tests (was 67)
- New: style slot schema validation, rendering pipeline, injection prevention, style_component action (validate/preview/execute/null-removal), recipe expansion + merge + tracking, font apply lifecycle, surface classification, CSS fallback lint

---

## [v0.6.0] — 2026-06-12 — Semantic Composition Operator

### AI agents can now read and write individual composition fields by name

Two new CLI commands let agents inspect what's editable on a page and patch specific fields without replacing entire compositions. `wp pp operate inspect-composition 74` returns every editable field with its semantic selector and current value. `wp pp operate patch 74 --target=hero.subtitle --value="New Headline"` changes one field through the existing `update_component` action path, with `--preview` for dry runs.

Selectors are human-readable: `hero.subtitle`, `section[title="About"].body`, `grid[title="Features"].items[title="Speed"].text`. The selector parser handles escapes, validates structure, and returns clear errors for malformed input.

Components can now be targeted by stable ID (`pp-a1b2c3d4`) instead of fragile array index. `update_component` and `remove_component` accept `component_id` as an alternative to `component_index`, with ID taking precedence when both are provided. Old index-based callers continue to work unchanged.

### Added
- `wp pp operate inspect-composition <page>` — returns editable targets as JSON with selectors and current values
- `wp pp operate patch <page> --target=<selector> --value=<value> [--preview]` — semantic field patching
- `component_id` parameter on `update_component` and `remove_component` actions
- Selector parser (`pp_parse_composition_selector`) supporting type.field, bracket match, nested items, ID targeting, and escape sequences
- Component field editability map (`pp_register_component_fields` / `pp_get_component_fields`) covering hero, section, grid, faq, and cta
- `pp_resolve_component_target()` — resolves component_id or component_index to a composition entry
- `pp_inspect_composition()` — walks composition and builds selector strings per editable field
- `pp_patch_composition()` — parses selector, resolves target, checks editability, routes through update_component

### Changed
- `update_component` accepts `component_id` (string, optional) alongside `component_index`
- `remove_component` accepts `component_id` (string, optional) alongside `component_index`
- `component_index` is no longer required when `component_id` is provided

### Tests
- 485 tests, 1494 assertions
- New: 53 tests covering selector parsing (valid patterns, edge cases, escapes, invalid input), component resolution (ID, index, out-of-bounds), inspect (nested items, components without titles), patch (preview, apply, rollback, nested items, multi-match rejection, ID-based targeting), and component_id addressing on actions

### Closes
- #66 — inspect-composition CLI command
- #67 — semantic patch CLI command
- #35 — component_id addressing for update and remove actions

---

## [v0.5.0] — 2026-06-10 — Update-Safe Design Token Persistence

### Design token overrides now survive theme updates

Site-specific design token customizations are stored in the database (`pp_token_overrides` option), not in `base.css`. When the theme is updated via ZIP upload or auto-update, `base.css` is replaced with product defaults and the database-backed overrides re-apply automatically via CSS cascade (`wp_add_inline_style`). No migration step required.

### Added
- `pp_get_token_overrides()`, `pp_set_token_override()`, `pp_clear_token_override()`, `pp_clear_all_token_overrides()` — CRUD for database-backed token overrides
- `pp_invalidate_design_tokens_cache()` — resets the merged token static cache
- `reset_design_token` apply — clears a single override, reverting to product default
- `reset_all_design_tokens` apply — clears all overrides
- `number` type validator for unitless numeric tokens (font-weight, line-height)
- 5 new design tokens: `--font-weight-heading` (number), `--line-height-body` (number), `--line-height-heading` (number), `--btn-padding-y` (length), `--btn-padding-x` (length)
- Inline `:root` override block via `wp_add_inline_style('pp-base', ...)` — only emitted when overrides exist
- Override-hash cache busting appended to the `pp-base` version string

### Changed
- `pp_design_tokens()` merges defaults from `base.css` with overrides from `pp_token_overrides` option
- `update_design_token` apply writes to `wp_options` instead of `base.css`
- Apply target model: typed `['type' => 'option', 'key' => 'pp_token_overrides']` replaces string `target_file`
- Preflight `theme_writable` check skipped for database-backed applies
- Token count: 28 → 33 (all global design tokens, no per-page or internal tokens)
- `base.css` is now read-only in production (product defaults only)

### Removed
- `_pp_backup_dir()`, `_pp_create_backup()`, `_pp_prune_backups()`, `pp_restore_points()`, `pp_restore()` — backup/restore system (~160 lines)
- File-based token write path in `update_design_token`
- `target_file` string field from apply definitions

### Tests
- 432 tests, 1324 assertions
- New: override CRUD, merged reading, inline CSS output, typed target, number validation, injection guard (`<>` for XSS), preflight conditional writability

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

### Theme packaging infrastructure

PromptingPress can now be distributed as a ZIP and installed via WordPress Admin > Upload Theme.

#### Added
- `scripts/package.sh` — builds `promptingpress-{version}.zip` with version consistency checks (style.css = functions.php PP_VERSION = package.json), Composer production dependency guard, and size validation (<5MB)
- `.distignore` — comprehensive exclusion patterns for dev artifacts
- `.github/workflows/release.yml` — CI workflow attaches ZIP to GitHub releases with tag-version validation
- `LICENSE` — GPL-2.0-or-later full text + PromptingPress trademark notice
- `readme.txt` — WordPress.org theme readme format
- `screenshot.png` — 1200×900 real screenshot from dev site
- `comments.php` — minimal comments template with `comments_open()` guard, `wp_list_comments()`, `comment_form()`, and password-protected post check
- `pp_comments_template()` wrapper in `lib/wp.php` — maintains the invariant: no raw WP functions in /templates/
- Skip-to-content link in `templates/base.php` with `.skip-link` CSS (visible on :focus)
- `wp_body_open()` hook in `templates/base.php`
- `automatic-feed-links` theme support in `functions.php`
- Packaging smoke test (`tests/js/package.test.js`) — 5 assertions verifying ZIP structure, required files, dev artifact exclusion, and no hidden files
- `npm run package` script in `package.json`

#### Changed
- `style.css` — added Theme URI, License, License URI, Requires at least (7.0), Tested up to (7.0), Requires PHP (8.0), Tags
- `templates/single.php` — wired `pp_comments_template()` after CTA component

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
