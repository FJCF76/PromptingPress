import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

// ── Fixture helpers ──────────────────────────────────────────────────────────

/** Create a page via WP-CLI inside wp-env. Returns the post ID. */
function createPage(title: string, template = 'composition.php'): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=draft --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  if (template) {
    execSync(
      `npx wp-env run cli wp post meta update ${id} _wp_page_template ${template}`,
      { cwd: process.cwd() },
    );
  }
  return id;
}

/** Delete a page via WP-CLI inside wp-env. */
function deletePage(id: number): void {
  execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
}

/** Set CodeMirror value via page.evaluate (CM fires change events). */
async function setCM(page, json: string): Promise<void> {
  await page.evaluate((val: string) => {
    const cmEl = document.querySelector('.CodeMirror') as any;
    cmEl?.CodeMirror?.setValue(val);
  }, json);
}

// ── Fixture JSON ─────────────────────────────────────────────────────────────

const HERO_SECTION_JSON = JSON.stringify([
  { component: 'hero', props: { title: 'E2E Test Hero' } },
  { component: 'section', props: { body: '<p>E2E section content.</p>' } },
]);

const HERO_ONLY_JSON = JSON.stringify([
  { component: 'hero', props: { title: 'Original Title' } },
]);

const INVALID_COMP_JSON = JSON.stringify([
  { component: 'nonexistent_widget', props: { title: 'Bad' } },
]);

const BROKEN_JSON = '[{broken json!!!';

/** Navigate to the composition workspace and wait for editor to be ready. */
async function openWorkspace(page, postId: number): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=pp-composition&post=${postId}`);
  await expect(page.locator('#pp-workspace')).toBeVisible();
  // Wait for editor JS to initialize (CM is inside hidden #pp-json-view, so check DOM presence)
  await page.waitForSelector('.CodeMirror', { state: 'attached', timeout: 10000 });
}

/** Switch to JSON view (from accordion) and wait for toggle to confirm. */
async function switchToJsonView(page): Promise<void> {
  await page.locator('#pp-view-toggle').click();
  // Button text changes from "JSON" to "Accordion" when toggle succeeds
  await expect(page.locator('#pp-view-toggle')).toHaveText('Accordion', { timeout: 5000 });
}

// ── Tests ────────────────────────────────────────────────────────────────────

test.describe('Composition Editor', () => {
  let pageId: number;

  test.afterEach(async () => {
    if (pageId) {
      try { deletePage(pageId); } catch { /* already cleaned up */ }
      pageId = 0;
    }
  });

  // ── Test 1: Workspace visibility ──────────────────────────────────────────

  test('workspace loads and initializes for composition page @smoke', async ({ page }) => {
    pageId = createPage('E2E Workspace Test');
    await openWorkspace(page, pageId);

    // Verify key workspace elements rendered
    await expect(page.locator('#pp-view-toggle')).toBeVisible();
    await expect(page.locator('#pp-save-btn')).toBeVisible();
    await expect(page.locator('#pp-publish-btn')).toBeVisible();
    await expect(page.locator('#pp-preview-frame')).toBeAttached();

    // Verify page title is populated
    await expect(page.locator('#pp-page-title')).toHaveValue('E2E Workspace Test');
  });

  // ── Test 2: Preview updates with valid JSON (CodeMirror path) ─────────────

  test('preview updates after valid JSON edit', async ({ page }) => {
    pageId = createPage('E2E Preview Test');
    await openWorkspace(page, pageId);

    // Switch to JSON view
    await switchToJsonView(page);

    // Set valid composition
    await setCM(page, HERO_SECTION_JSON);

    // Preview iframe should update with the hero title
    const preview = page.frameLocator('#pp-preview-frame');
    await expect(preview.locator('.hero__title')).toContainText('E2E Test Hero', { timeout: 10000 });
  });

  // ── Test 3: Save rejected with invalid composition ────────────────────────

  test('save blocked on invalid composition', async ({ page }) => {
    pageId = createPage('E2E Save Reject Test');
    await openWorkspace(page, pageId);

    // Switch to JSON view
    await switchToJsonView(page);

    // Set invalid composition (unknown component)
    await setCM(page, INVALID_COMP_JSON);

    // Wait for validation to run (debounced)
    await expect(page.locator('#pp-error-bar')).not.toBeEmpty({ timeout: 5000 });

    // Click save
    await page.locator('#pp-save-btn').click();

    // Assert save status shows error
    const status = page.locator('#pp-save-status');
    await expect(status).toContainText('Fix errors first.');
    await expect(status).toHaveClass(/is-error/);
  });

  // ── Test 4: Autosave skipped with invalid JSON ────────────────────────────

  test('Ctrl+S autosave skipped with broken JSON', async ({ page }) => {
    pageId = createPage('E2E Autosave Skip Test');
    await openWorkspace(page, pageId);

    // Switch to JSON view
    await switchToJsonView(page);

    // Set broken JSON (not parseable)
    await setCM(page, BROKEN_JSON);

    // Press Ctrl+S
    await page.keyboard.press('Control+s');

    // Wait 2 seconds, then assert status does NOT contain "Saved"
    await page.waitForTimeout(2000);
    const statusText = await page.locator('#pp-save-status').textContent();
    expect(statusText).not.toContain('Saved');
    expect(statusText).not.toContain('Draft saved');
  });

  // ── Test 5: Front-end renders components after publish ────────────────────

  test('front-end renders components in correct order after publish @smoke', async ({ page }) => {
    pageId = createPage('E2E Render Test');
    await openWorkspace(page, pageId);

    // Switch to JSON view and set composition
    await switchToJsonView(page);
    await setCM(page, HERO_SECTION_JSON);

    // Wait for preview to confirm composition is valid
    const preview = page.frameLocator('#pp-preview-frame');
    await expect(preview.locator('.hero__title')).toContainText('E2E Test Hero', { timeout: 10000 });

    // Publish
    await page.locator('#pp-publish-btn').click();

    // Wait for publish confirmation (button text changes or status updates)
    await expect(page.locator('#pp-save-status')).toContainText(/published|updated/i, { timeout: 10000 });

    // Navigate to front-end
    await page.goto(`/?page_id=${pageId}`);

    // Assert hero comes before section in DOM and content matches
    const hero = page.locator('.hero');
    const section = page.locator('.section');
    await expect(hero).toBeVisible();
    await expect(section).toBeVisible();
    await expect(hero.locator('.hero__title')).toContainText('E2E Test Hero');
    await expect(section).toContainText('E2E section content.');

    // Verify order: hero appears before section
    const heroBox = await hero.boundingBox();
    const sectionBox = await section.boundingBox();
    expect(heroBox!.y).toBeLessThan(sectionBox!.y);
  });

  // ── Test 6: Accordion edit round-trip ─────────────────────────────────────

  test('accordion edit round-trip persists through publish', async ({ page }) => {
    pageId = createPage('E2E Accordion Test');
    await openWorkspace(page, pageId);

    // Seed composition via JSON view
    await switchToJsonView(page);
    await setCM(page, HERO_ONLY_JSON);

    // Wait for preview to confirm it's valid
    const preview = page.frameLocator('#pp-preview-frame');
    await expect(preview.locator('.hero__title')).toContainText('Original Title', { timeout: 10000 });

    // Switch to accordion view
    await page.locator('#pp-view-toggle').click();
    await expect(page.locator('#pp-accordion-view')).toBeVisible();

    // Expand the first card
    await page.locator('.pp-accordion-toggle').first().click();

    // Find the title field and change it
    const titleField = page.locator('[data-comp="0"][data-field="title"]');
    await expect(titleField).toBeVisible();
    await titleField.fill('Updated By Accordion');

    // Signal-based wait: preview iframe should reflect the updated title
    // (syncAccordionToJson debounce is 300ms, then preview AJAX fires)
    await expect(preview.locator('.hero__title')).toContainText('Updated By Accordion', { timeout: 10000 });

    // Publish
    await page.locator('#pp-publish-btn').click();
    await expect(page.locator('#pp-save-status')).toContainText(/published|updated/i, { timeout: 10000 });

    // Navigate to front-end and verify
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.hero__title')).toContainText('Updated By Accordion');
  });

  // ── Serialization Invariant Gate Tests ──────────────────────────────────

  // Fixture: a component without a props key — triggers invariant drift because
  // the accordion round-trip adds `props: {}`. That is the ONLY drift class the
  // gate can see, and it requires a component with no required prop.
  //
  // Until #223 this was `[{ component: 'footer' }]`: nav/footer were the only two
  // zero-required-prop components, so the fixture both validated and drifted. They
  // are template-owned chrome now and rejected from compositions, so a drifting
  // composition is necessarily INVALID — `hero` omits its required `title`.
  // SchemaValidationTest::testEveryComposableComponentDeclaresARequiredProp()
  // pins that premise; if it ever fails, revisit Test 9 and Test 10 below.
  const DRIFT_NO_PROPS_JSON = JSON.stringify([{ component: 'hero' }]);

  // Fixture: valid, and stable across the accordion round-trip.
  // HERO_ONLY_JSON (module scope) is exactly that — reuse it rather than minting
  // a second hero fixture that has to be kept in step with the schema.
  const CLEAN_JSON = HERO_ONLY_JSON;

  /**
   * Set composition via WP-CLI post meta (bypasses editor validation).
   * Uses wp db query to insert raw JSON, avoiding sanitize_meta filter
   * which would reject a JSON string without the expected structure.
   */
  function setCompositionMeta(postId: number, json: string): void {
    // Use wp option as a staging mechanism: write to a temp option, then copy to post meta
    // This avoids shell quoting issues with complex JSON
    const b64 = Buffer.from(json).toString('base64');
    execSync(
      `npx wp-env run cli wp eval 'update_post_meta(${postId}, "_pp_composition", base64_decode("${b64}"));'`,
      { cwd: process.cwd() },
    );
  }

  // ── Test 7: Happy path — valid composition loads accordion normally ───

  test('invariant gate: valid composition renders accordion normally', async ({ page }) => {
    pageId = createPage('E2E Invariant Happy');

    // Inject a valid composition that passes invariant check
    setCompositionMeta(pageId, CLEAN_JSON);

    // Open workspace — invariant passes, accordion should render
    await openWorkspace(page, pageId);

    // Verify: accordion visible, toggle visible, no serialization error notice
    await expect(page.locator('#pp-accordion-view')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).toBeVisible();
    await expect(page.locator('.pp-serialization-error')).not.toBeAttached();
  });

  // ── Test 8: Blocked path — missing props triggers invariant gate ──────

  test('invariant gate: missing props key blocks accordion', async ({ page }) => {
    pageId = createPage('E2E Invariant Blocked');

    // Inject composition with missing props key via WP-CLI
    setCompositionMeta(pageId, DRIFT_NO_PROPS_JSON);

    // Open workspace — invariant check runs at boot
    await openWorkspace(page, pageId);

    // Verify: accordion hidden, toggle hidden
    await expect(page.locator('#pp-accordion-view')).not.toBeVisible();
    await expect(page.locator('#pp-view-toggle')).not.toBeVisible();

    // Verify: notice panel is present with expected content
    const notice = page.locator('.pp-serialization-error');
    await expect(notice).toBeVisible();
    await expect(notice.locator('.pp-serialization-error__header')).toContainText('Accordion unavailable');
    await expect(notice.locator('.pp-serialization-error__subtext')).toContainText('Edit JSON directly below');

    // Verify: diff table shows the props addition
    await expect(notice.locator('table')).toBeVisible();
    await expect(notice.locator('td code').first()).toContainText(/props/);
    await expect(notice.locator('.pp-diff-badge--added').first()).toBeVisible();

    // Verify: JSON editor is visible and editable
    // Note: #pp-json-view has display:block but 0 height because CodeMirror
    // inside uses position:absolute filling .pp-pane-body directly
    await expect(page.locator('.CodeMirror')).toBeVisible();

    // Verify: Copy as GitHub Issue button present
    await expect(notice.locator('.pp-copy-issue-btn')).toBeVisible();
  });

  // ── Test 9: Saving a drifted composition is refused ───────────────────
  //
  // Pre-#223 this test asserted the opposite: save normalized the drift away and
  // unlocked the accordion. That path is gone. Drift requires a component with no
  // required prop, chrome was the last such component, so every drifting
  // composition is now invalid and doSaveDraft() refuses it before it reaches the
  // server. The author's recourse is the JSON editor, which the notice points at.

  test('invariant gate: save is refused while the composition is drifted', async ({ page }) => {
    pageId = createPage('E2E Invariant Save Refused');

    // Inject composition with missing props key
    setCompositionMeta(pageId, DRIFT_NO_PROPS_JSON);

    // Open workspace — should be blocked
    await openWorkspace(page, pageId);
    await expect(page.locator('.pp-serialization-error')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).not.toBeVisible();

    // Save — client validation rejects it: drift implies a missing required prop.
    await page.locator('#pp-save-btn').click();

    await expect(page.locator('#pp-save-status')).toContainText('Fix errors first.', { timeout: 10000 });
    await expect(page.locator('#pp-error-bar')).toContainText('required prop');

    // Verify: still blocked — no silent unlock, no partial write.
    await expect(page.locator('.pp-serialization-error')).toBeVisible();
    await expect(page.locator('#pp-accordion-view')).not.toBeVisible();
    await expect(page.locator('#pp-view-toggle')).not.toBeVisible();
  });

  // ── Test 9b: Fixing the JSON unlocks the accordion ────────────────────

  test('invariant gate: repairing the composition restores the accordion', async ({ page }) => {
    pageId = createPage('E2E Invariant Save Unlock');

    // Inject composition with missing props key
    setCompositionMeta(pageId, DRIFT_NO_PROPS_JSON);

    // Open workspace — should be blocked
    await openWorkspace(page, pageId);
    await expect(page.locator('.pp-serialization-error')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).not.toBeVisible();

    // Repair the JSON in the editor, the way the notice instructs.
    await setCM(page, CLEAN_JSON);

    // Save — now valid, server adds props.id, CM refreshes, invariant re-checks.
    await page.locator('#pp-save-btn').click();

    // Wait for "Drift resolved" feedback
    await expect(page.locator('#pp-save-status')).toContainText('Drift resolved', { timeout: 10000 });

    // Verify: notice removed, accordion restored, toggle visible
    await expect(page.locator('.pp-serialization-error')).not.toBeAttached();
    await expect(page.locator('#pp-accordion-view')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).toHaveText('JSON');
  });

  // ── Test 10: Publish unlocks accordion ────────────────────────────────

  test('invariant gate: publish resolves drift and restores accordion', async ({ page }) => {
    pageId = createPage('E2E Invariant Publish Unlock');

    // Inject composition with missing props key
    setCompositionMeta(pageId, DRIFT_NO_PROPS_JSON);

    // Open workspace — should be blocked
    await openWorkspace(page, pageId);
    await expect(page.locator('.pp-serialization-error')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).not.toBeVisible();

    // Repair first: doPublishOrUpdate() validates exactly like doSaveDraft(), and
    // a drifted composition is always invalid now (#223), so publishing a drifted
    // page is refused rather than normalized.
    await setCM(page, CLEAN_JSON);

    // Publish — server adds props.id, CM refreshes, invariant re-checks
    await page.locator('#pp-publish-btn').click();

    // Wait for "Drift resolved" feedback
    await expect(page.locator('#pp-save-status')).toContainText('Drift resolved', { timeout: 10000 });

    // Verify: notice removed, accordion restored, toggle visible
    await expect(page.locator('.pp-serialization-error')).not.toBeAttached();
    await expect(page.locator('#pp-accordion-view')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).toBeVisible();
    await expect(page.locator('#pp-view-toggle')).toHaveText('JSON');
  });

  // ── Test 11: Copy as GitHub Issue ─────────────────────────────────────

  test('invariant gate: copy as GitHub issue produces valid markdown', async ({ page, context }) => {
    pageId = createPage('E2E Copy Issue');

    // Inject composition with missing props key
    setCompositionMeta(pageId, DRIFT_NO_PROPS_JSON);

    // Grant clipboard permissions (Chromium-based)
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);

    // Open workspace — should be blocked
    await openWorkspace(page, pageId);
    await expect(page.locator('.pp-serialization-error')).toBeVisible();

    // Click "Copy as GitHub Issue"
    await page.locator('.pp-copy-issue-btn').click();

    // Wait for "Copied!" feedback
    await expect(page.locator('.pp-copy-success')).toContainText('Copied!', { timeout: 5000 });

    // Read clipboard content
    const clipboardContent = await page.evaluate(() => navigator.clipboard.readText());

    // Verify markdown content includes expected fields
    expect(clipboardContent).toContain('E2E Copy Issue');
    expect(clipboardContent).toContain('Component 0');
    expect(clipboardContent).toContain('props');
    expect(clipboardContent).toContain('added');
  });

  // ── Test 12: Optimistic-locking conflict on concurrent edit (#13) ─────────

  test('editor save is rejected when the page changed elsewhere (#13) @smoke', async ({ page }) => {
    pageId = createPage('E2E CAS Conflict');
    await openWorkspace(page, pageId);

    // First save from the editor establishes version 1 and advances the editor's baseline.
    await switchToJsonView(page);
    await setCM(page, HERO_ONLY_JSON);
    await page.locator('#pp-save-btn').click();
    await expect(page.locator('#pp-save-status')).toContainText('Draft saved', { timeout: 10000 });

    // An external writer (agent/CLI/another tab) mutates the same page, bumping the marker
    // to version 2 while this editor still holds version 1.
    execSync(
      `npx wp-env run cli wp eval "pp_update_composition(${pageId}, [['component' => 'hero', 'props' => ['title' => 'Changed by CLI']]]);"`,
      { cwd: process.cwd() },
    );

    // The editor's next save carries its stale expected_version → the CAS rejects it and the
    // editor surfaces the reload prompt instead of clobbering the external change.
    await setCM(page, JSON.stringify([{ component: 'hero', props: { title: 'Stale editor edit' } }]));
    await page.locator('#pp-save-btn').click();

    const status = page.locator('#pp-save-status');
    await expect(status).toHaveClass(/is-error/, { timeout: 10000 });
    await expect(status).toContainText(/changed elsewhere|Reload/i);

    // The external change survived — the stale editor write did not overwrite it.
    const stored = execSync(
      `npx wp-env run cli wp post meta get ${pageId} _pp_composition`,
      { cwd: process.cwd(), encoding: 'utf-8' },
    );
    expect(stored).toContain('Changed by CLI');
    expect(stored).not.toContain('Stale editor edit');
  });
});

// ── Preview / front-end cascade parity (v2 UDC, boundary-review B1) ──────────

/**
 * THE PREVIEW MUST RANK THE TWO UDC LAYERS THE WAY THE PAGE DOES.
 *
 * The front end emits a component's role DEFAULTS before the theme stylesheets
 * (inline on the `pp-base` handle) and each band's AUTHORED values after them
 * (inline on `pp-utilities`). Both layers are zero-or-low specificity by
 * construction, so those two positions ARE the ranking — there is nothing else
 * expressing it.
 *
 * The preview builds its own <head> and used to emit both layers concatenated in
 * ONE block after all three stylesheets. That inverts the ranking: a root-level
 * role default emits inside `:where(...)` at zero specificity, exactly like the
 * shared adjacent-band rhythm rule in components.css, so whichever prints later
 * wins. Printed last, the component's own default started beating the design
 * system — in the preview only. The operator was shown a page the site will
 * never render.
 *
 * PreviewCascadeParityTest pins the ORDER in PHP. Only a browser can pin the
 * CONSEQUENCE, which is this test: for one fixture, every measured value must
 * agree between the preview iframe and the real page. The fixture is built to
 * exercise all four ranks at once, because a single authored/default pair would
 * have passed against the broken preview:
 *
 *   band 1  unauthored, first        → component default, unopposed
 *   band 2  unauthored, adjacent     → SHARED rhythm rule beats the root default
 *   band 3  authored,   adjacent     → authored beats the shared rule AND the default
 *   quote   authored on band 3 only  → element-level default vs authored value
 *
 * Band 2 is the one that diverged. It is also the one a one-pair fixture misses.
 */
test.describe('UDC preview cascade parity', () => {
  let pageId: number;

  const BAND_1 = 'pp-11aa22bb';
  const BAND_2 = 'pp-33cc44dd';
  const BAND_3 = 'pp-55ee66ff';

  /** One testimonials band; `udc` omitted entirely when nothing is authored. */
  function band(id: string, udc?: Record<string, unknown>) {
    const item: Record<string, unknown> = {
      component: 'testimonials',
      id,
      props: { items: [{ quote: 'Parity is not a matter of opinion.', author: 'Ada' }] },
    };
    if (udc) item.udc = udc;
    return item;
  }

  const FIXTURE = [
    band(BAND_1),
    band(BAND_2),
    band(BAND_3, {
      _band: { spacing: { 'padding-top': '37px' } },
      quote: { typography: { size: '23px' } },
    }),
  ];

  /**
   * Every value whose rank the two surfaces must agree on, as one flat map.
   *
   * Takes the `body` LOCATOR rather than a page or a frame, because those two
   * have no common evaluate(): a FrameLocator addresses elements and cannot run
   * script, while a Page can. A Locator can, on both, so one function measures
   * the preview iframe and the real page identically — which is the whole point
   * of the comparison. (Caught by running this locally: the first draft called
   * evaluate() on the FrameLocator and threw.)
   */
  async function measure(body: any): Promise<Record<string, string>> {
    return body.evaluate((root: HTMLElement, ids: string[]) => {
      const out: Record<string, string> = {};
      // The breakpoint each surface actually resolved at. Reported as a measured
      // VALUE rather than assumed, because it is the one way this comparison can
      // report a difference that is not a cascade difference — see the width
      // handling in the test body.
      out['@breakpoint'] = window.matchMedia('(max-width: 767px)').matches
        ? 'p'
        : window.matchMedia('(max-width: 1023px)').matches
          ? 't'
          : 'd';
      for (const id of ids) {
        const band = root.querySelector(`[data-pp-band="${id}"]`);
        if (!band) {
          out[`${id}.missing`] = 'true';
          continue;
        }
        out[`${id}.padding-top`] = getComputedStyle(band).paddingTop;
        const quote = band.querySelector('.testimonials__quote');
        if (quote) out[`${id}.quote-size`] = getComputedStyle(quote).fontSize;
      }
      return out;
    }, [BAND_1, BAND_2, BAND_3]);
  }

  /**
   * WHAT THIS TEST DELIBERATELY DOES NOT CLAIM, and why.
   *
   * There are three ranks in the cascade — the design system's baseline, its
   * CONTEXTUAL rules, and the author — and only two of them can be told apart by
   * measurement today. base.css pins `--pp-band-padding-adjacent-top` to
   * `var(--pp-band-padding)` so the shared adjacent-band rhythm "can never
   * diverge from the band's own edges", and testimonials' `_band` default is
   * `@pp-band-padding` — the same token. The shared rule and the component's own
   * root default therefore compute the SAME length, so which of the two wins is
   * invisible in a rendered value. That is exactly why the tier inversion could
   * sit in the preview for a sprint without anyone seeing it, and why the
   * boundary review said it "diverges visibly the moment hero lands": a second
   * v2 component with its own padding is what separates them.
   *
   * Separating them here by retuning the adjacent-top design token was tried and
   * does not work, for a reason worth recording: the preview does not emit the
   * site's design-token overrides at all, so the override moved the front end
   * and left the preview on the stock value. That is a real preview-fidelity
   * defect and it is filed; it is NOT this cascade, and folding a fix for it
   * into this change would have made the test pass for the wrong reason.
   *
   * So the ranks this test proves are the ones that are genuinely observable —
   * authored over default, at the band root and at an element role — plus the
   * ORDER itself, which PreviewCascadeParityTest pins in PHP where it is not
   * hostage to two tiers sharing a value.
   *
   * MEASURED, NOT ASSUMED: the computed-value comparison was run against the
   * pre-fix preview (both layers concatenated after all three stylesheets) and
   * PASSED, because the two ranks coincide in value today. So that half is a
   * forward parity guard — it catches the day the coincidence ends, when a second
   * v2 component lands with its own root padding.
   *
   * The HEAD-ORDER assertion added alongside it is the regression proof the
   * value comparison cannot be: the pre-fix head has no `pp-udc-defaults`
   * element at all, so it fails structurally whatever the computed values do.
   * Both are kept — one pins the mechanism, the other pins the outcome.
   */

  test.afterEach(async () => {
    if (pageId) {
      try { deletePage(pageId); } catch { /* already cleaned up */ }
      pageId = 0;
    }
  });

  test('the preview and the front end compute the same values for every cascade rank', async ({ page }) => {
    pageId = createPage('E2E UDC Cascade Parity', '');
    execSync(`npx wp-env run cli wp post update ${pageId} --post_status=publish`, { cwd: process.cwd() });
    execSync(`npx wp-env run cli wp post meta update ${pageId} _wp_page_template composition.php`, { cwd: process.cwd() });
    const json = JSON.stringify(FIXTURE).replace(/'/g, "'\\''");
    execSync(`npx wp-env run cli wp post meta update ${pageId} _pp_composition '${json}'`, { cwd: process.cwd() });

    // A WIDE browser, so the preview iframe itself lands at the DESKTOP
    // breakpoint. The preview pane is one column of the workspace and keeps
    // whatever is left of the viewport — about 700px at the default 1280, which
    // is the PHONE band. The shared adjacent-band rule lives inside
    // `@media (min-width: 768px)`, so below that width it does not apply at all
    // and the rank this test exists to check is simply absent. Desktop is where
    // the two tiers meet, so desktop is where this has to be measured.
    await page.setViewportSize({ width: 2400, height: 900 });

    // The editor preview of the stored composition, measured FIRST — because it
    // is the surface whose width we do not control, and the front end must then
    // be matched to it. Comparing the two at different widths compares media
    // queries, not cascade tiers: the first run of this test reported a
    // 76.8px/53.6px difference on band 2 that was entirely the breakpoint.
    await openWorkspace(page, pageId);
    const preview = page.frameLocator('#pp-preview-frame');
    await expect(preview.locator(`[data-pp-band="${BAND_3}"]`)).toBeVisible({ timeout: 15000 });
    const previewed = await measure(preview.locator('body'));

    // THE STRUCTURAL PROOF, which does not depend on the two tiers differing in
    // value. The computed-value comparison below cannot go red against the
    // pre-fix preview today (the ranks coincide — see the docblock), but the
    // HEAD ITSELF is different: the pre-fix preview emitted one
    // `<style id="pp-udc-bands">` after all three links, so there is no
    // `pp-udc-defaults` element at all and this assertion fails. It also fails
    // against a bypass that rebuilds the head inline inside the AJAX handler.
    const headOrder = await preview.locator('body').evaluate(() => {
      const nodes = Array.from(document.head.children);
      const at = (sel: string) => nodes.findIndex((n) => n.matches(sel));
      return {
        defaults:   at('style#pp-udc-defaults'),
        components: at('link[href*="components.css"]'),
        utilities:  at('link[href*="utilities.css"]'),
        authored:   at('style#pp-udc-authored'),
        legacy:     at('style#pp-udc-bands'),
      };
    });

    expect(headOrder.legacy, 'the single concatenated block must be gone').toBe(-1);
    expect(headOrder.defaults, 'the preview must emit a defaults block').toBeGreaterThanOrEqual(0);
    expect(headOrder.authored, 'the preview must emit an authored block').toBeGreaterThanOrEqual(0);
    expect(
      headOrder.defaults,
      'the defaults layer must print before components.css, or it outranks the design system',
    ).toBeLessThan(headOrder.components);
    expect(
      headOrder.authored,
      'the authored layer must print after utilities.css, or structural CSS can outrank it',
    ).toBeGreaterThan(headOrder.utilities);

    // So the real page is rendered at the width the preview actually resolved at.
    const previewWidth = await preview.locator('body').evaluate(() => window.innerWidth);
    expect(previewWidth, 'the preview iframe must have a real width').toBeGreaterThan(200);
    const viewport = page.viewportSize();
    await page.setViewportSize({ width: previewWidth, height: viewport ? viewport.height : 720 });

    // Plain permalinks are wp-env's default, so address the page by id rather
    // than by slug (no permalink flush, no afterAll restore).
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(`[data-pp-band="${BAND_3}"]`)).toBeVisible();
    const frontEnd = await measure(page.locator('body'));

    // Guard the fixture itself: a measurement that found nothing agrees trivially.
    expect(Object.keys(frontEnd).length).toBeGreaterThanOrEqual(7);
    for (const key of Object.keys(frontEnd)) {
      expect(key.endsWith('.missing'), `front end is missing ${key}`).toBe(false);
    }

    // Same breakpoint on both sides, or the comparison below is meaningless.
    // Asserted separately so a width drift reports itself as a width drift
    // rather than as a cascade failure.
    expect(
      previewed['@breakpoint'],
      'preview and front end must resolve the same breakpoint before their values can be compared',
    ).toBe(frontEnd['@breakpoint']);

    // The parity claim.
    expect(previewed).toEqual(frontEnd);

    // THE RANKS MUST BE GENUINELY DISTINCT, or "the two surfaces agree" is a
    // statement about two identical numbers. Each assertion below names the tier
    // it proves, and each one is measured on BOTH surfaces.
    for (const [surface, m] of [['front end', frontEnd], ['preview', previewed]] as const) {
      // An AUTHORED band value beats everything below it — the shared adjacent
      // rule included, since band 3 is adjacent to band 2. Before Sprint 0's
      // cascade fix this rendered the shared value instead.
      expect(m[`${BAND_3}.padding-top`], `${surface}: authored beats the shared rule and the default`)
        .toBe('37px');

      // And it is a real override, not a coincidence: the unauthored bands sit
      // on the default rhythm, which is some other value.
      expect(m[`${BAND_1}.padding-top`], `${surface}: band 1 is unauthored`).not.toBe('37px');
      expect(m[`${BAND_2}.padding-top`], `${surface}: band 2 is unauthored`).not.toBe('37px');

      // The same rank on an element-level role rather than the band root: these
      // two emit at different weights and from different layers, so a preview
      // that mis-ordered the layers would move one of them.
      expect(m[`${BAND_3}.quote-size`], `${surface}: authored quote size wins`).toBe('23px');
      expect(m[`${BAND_1}.quote-size`], `${surface}: the unauthored quote keeps the role default`)
        .not.toBe('23px');
    }
  });
});
