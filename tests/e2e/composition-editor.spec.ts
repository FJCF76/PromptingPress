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
