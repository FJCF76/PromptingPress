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

### 2. PLAN
Map the brief to PromptingPress components:
- Hero, section, grid, CTA, FAQ, stats, footer, etc.
- Choose variants and layouts based on the brief's intent
- Plan the composition array (component order, props)
- Declare planned file mutations (e.g., `assets/css/base.css` if token changes needed)

### 3. EDIT
Execute actions in order:
1. `add_page` — Create the page with composition template
2. `set_composition` — Set the full composition array
3. Any token updates if the brief requires brand changes

### 4. PREFLIGHT
Run `wp pp apply preflight --planned-files='["assets/css/base.css"]'` if any file-based applies are needed. If only DB actions were used, a plain `wp pp apply preflight` suffices.

### 5. APPLY
Execute any file-based applies (e.g., `wp pp apply execute --apply=update_design_token --params='...'`).

If no file-based applies are needed (all changes were DB actions), skip to SCREENSHOT.

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
| nav_footer_present | Navigation and footer render correctly | soft | desktop |

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
