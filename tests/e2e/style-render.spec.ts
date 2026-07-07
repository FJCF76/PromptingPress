import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * Rendered-proof E2E for the safe-surface sprint.
 *
 * The static StyleSlotContractTest proves the CSS *consumes* a slot var; it cannot
 * prove the browser actually *renders* the slot value once the full cascade (media
 * queries, specificity) is applied. These tests close that gap with getComputedStyle:
 *
 *   #86 — a per-instance `--grid-heading-color` must win at the 1280px DESKTOP
 *         breakpoint, where the old `main > .grid .grid__heading { color: var(--color-text) }`
 *         rule used to clobber it (mobile always passed).
 *   #24 — a per-instance `--hero-surface-*` slot must reach the rendered `.hero__surface`
 *         shell (previously hardcoded, uncontrollable through safe surfaces).
 */

// ── Helpers (mirrors validation.spec.ts) ────────────────────────────────────

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, {
    cwd: process.cwd(),
  });
  return id;
}

function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp post meta update ${postId} _pp_composition '${json}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

function deletePage(id: number): void {
  execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
}

/** Dispatch a style_component action via the admin AJAX endpoint (picks up the nonce). */
async function styleComponent(page: any, postId: number, style: Record<string, unknown>) {
  return page.evaluate(
    async (args: { pid: number; style: Record<string, unknown> }) => {
      const config = (window as any).ppAiChat;
      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', 'style_component');
      data.append('params[post_id]', String(args.pid));
      data.append('params[component_index]', '0');
      data.append('params[style]', JSON.stringify(args.style));
      const resp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
      });
      return resp.json();
    },
    { pid: postId, style },
  );
}

// ── Tests ───────────────────────────────────────────────────────────────────

test.describe('Safe-surface rendered proof', () => {
  let pageId: number;

  test.afterEach(async () => {
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  // @smoke — #86: the grid heading-color slot must win on DESKTOP, not just be present
  // in the CSS. Regression proof for the cross-block override that buried it at >=768px.
  test('#86 grid heading honors --grid-heading-color at 1280px desktop @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Heading Color');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'What makes the site AI-operable',
          items: [{ title: 'One', text: 'First' }],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A vivid color no theme token uses, so a clobber by --color-text would be obvious.
    const res = await styleComponent(page, pageId, { '--grid-heading-color': '#ff0080' });
    expect(res.success).toBe(true);

    // Desktop viewport: this is the breakpoint where #86 manifested.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const heading = page.locator('.grid__heading');
    await expect(heading).toBeVisible({ timeout: 10000 });

    const color = await heading.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(255, 0, 128)');
  });

  // #24: a hero-surface slot must reach the rendered inner shell (.hero__surface only
  // renders for the split variant with proof markup).
  test('#24 hero surface honors --hero-surface-border-width', async ({ page }) => {
    pageId = createPage('E2E Hero Surface Slot');
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          layout: 'split',
          title: 'Hero',
          proof: '<p>Product workflow surface</p>',
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Distinct from the 1px default, so honoring the slot is unambiguous.
    const res = await styleComponent(page, pageId, { '--hero-surface-border-width': '7px' });
    expect(res.success).toBe(true);

    await page.goto(`/?page_id=${pageId}`);

    const surface = page.locator('.hero__surface');
    await expect(surface).toBeVisible({ timeout: 10000 });

    const borderWidth = await surface.evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(borderWidth).toBe('7px');
  });
});
