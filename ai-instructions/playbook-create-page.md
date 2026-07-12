# Playbook: Create Page from Brief

Create a new PromptingPress page from a brief. Full loop, all 8 steps mandatory.

## Preconditions

- A brief describing the page (sections, content, intent)
- PromptingPress site is running and accessible
- Agent has read `operating-loop.md`

## Loop

### 1. INSPECT
Run `wp pp operate inspect`. Review:
- Available design tokens (brand colors, typography)
- Existing composition pages (avoid duplicate slugs)
- CSS conflicts (note any that might affect new components)
- Drift state (note for PREFLIGHT)
- **The `run_id`.** `wp pp operate inspect` appends a `run_id` field (a UUID v4) to its JSON output. Capture it — this is your **run token**, and you pass it via `--run-id` to every mutating command in the steps below (PREFLIGHT, EDIT, APPLY). Generating your own UUID does **not** work: only the token minted by `inspect` can record run state, so a self-generated one passes format validation but then fails PREFLIGHT/EDIT. The token is install-scoped and expires 2 hours after `inspect`; re-run `inspect` if it expires. Full contract: `docs/reference-apply-cli.md`.

### 2. PLAN
Map the brief to PromptingPress components:
- Hero, section, grid, CTA, FAQ, stats, footer, etc.
- Choose layouts and themes based on the brief's intent
- Plan the composition array (component order, props)
- Note any design token changes needed (stored in database, not file-based)

### 3. PREFLIGHT
Run a site-scoped `wp pp apply preflight --run-id=<uuid>` (no `--post_id` — the page does not exist yet). `<uuid>` is the `run_id` you captured from INSPECT, not a freshly generated UUID. This covers the site-scoped page creation in EDIT. Creating a page through `create_page` with its composition inline is a single site-scoped mutation, so the site preflight is sufficient.

### 4. EDIT
Execute actions in order:
1. `create_page` — Create the page, ideally with its full `composition` (and optional `slug`) inline: `wp pp action execute create_page --run-id=<uuid> --params='{"title":"...","composition":[...]}'` (site-scoped; covered by the site PREFLIGHT above)
2. `update_composition` — Only needed if you set the composition as a separate page-scoped step rather than inline at creation. First run `wp pp apply preflight --run-id=<uuid> --post_id=<new_page_id>` once the page exists, since page-scoped mutations require a PREFLIGHT covering that post.

Token applies are database-backed, so no `--planned-files` is needed for design token changes.

### 5. APPLY
Execute any token/font applies the brief requires (e.g., `wp pp apply execute update_design_token --run-id=<uuid> --params='{"token":"--color-accent","value":"#b45309"}'`). These are database-backed applies covered by the site PREFLIGHT from step 3.

If no applies are needed (all changes were page actions), skip to SCREENSHOT.

### 6. SCREENSHOT
Run `wp pp screenshot capture --post_id=<new_page_id> --playbook=create-page`

This captures both desktop (1280px) and mobile (375px) viewports.

### 7. REVIEW
Get checklist: `wp pp operate checklist --playbook=create-page`

Evaluate each item against the screenshots:

| ID | Description | Gate | Viewport |
|---|---|---|---|
| sections_present | All sections from the brief are present | hard | any |
| no_empty_sections | No sections render as empty/blank at 1280px | hard | desktop |
| mobile_readable | All text readable, no horizontal overflow at 375px | hard | mobile |
| no_antislop | No generic placeholder text, lorem ipsum, or AI slop | hard | desktop |
| hero_has_cta | Hero section has a visible call-to-action | hard | desktop |
| brand_tokens_applied | Brand colors and typography match design tokens | soft | desktop |
| images_loaded | All referenced images load without broken placeholders | soft | any |
| nav_footer_present | The template renders the site nav and footer exactly once (never add them to the composition) | soft | desktop |

If any **hard gate** fails: loop back to PLAN. Maximum 2 retries.

### 8. HANDOFF
Produce the handoff report with:
- Status: VERIFIED / NEEDS_VISUAL_VERIFICATION / SCREENSHOT_FAILED
- Page ID and URL
- Composition summary (components used)
- Actions executed
- Screenshot paths
- Checklist results
- Drift state at handoff

## Common Failure Modes

- **Empty hero**: Composition has a hero but no title/subtitle props → appears blank
- **Missing CTA**: Brief mentions CTA but it wasn't mapped to a button prop
- **Text overflow on mobile**: Long headings or wide grid layouts break at 375px
- **Token mismatch**: Brief specifies brand colors but tokens weren't updated
- **AI slop**: Agent generates placeholder text ("Lorem ipsum", "Your tagline here")
