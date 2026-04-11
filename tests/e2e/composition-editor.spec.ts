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

/** Read CodeMirror value via page.evaluate. */
async function getCM(page): Promise<string> {
  return page.evaluate(() => {
    const cmEl = document.querySelector('.CodeMirror') as any;
    return cmEl?.CodeMirror?.getValue() ?? '';
  });
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

  test('workspace loads and initializes for composition page', async ({ page }) => {
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

  test('front-end renders components in correct order after publish', async ({ page }) => {
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
});
