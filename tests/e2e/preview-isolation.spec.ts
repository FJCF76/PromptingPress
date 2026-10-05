import { test, expect, Page, Frame } from '@playwright/test';
import { execFileSync } from 'child_process';

/**
 * The editor preview renders content in an isolated origin (LAYER-3-CONTRACT §8.3).
 *
 * The preview iframe is sandboxed with `allow-scripts` and WITHOUT
 * `allow-same-origin`, so its srcdoc document has an opaque origin. That broke the
 * old refresh, which reached into the frame's document to swap its body and read
 * its scroll position. The refresh now rebuilds the whole document and the frame
 * reports its scroll position by message.
 *
 * This spec is the browser half of the proof (tests/PreviewFrameIsolationTest.php
 * pins the attribute; tests/js/pp-editor-preview-isolation.test.js pins the
 * message channel):
 *
 *   1. the frame really is an opaque origin, and the editor really cannot reach it;
 *   2. the preview still renders a composition, with the theme's stylesheets;
 *   3. an edit rebuilds the document — head included, so an authored style change
 *      shows — and the reader stays where they had scrolled.
 *
 * Compositions are typed into the editor (the real authoring surface of the
 * preview); nothing is saved.
 */

const CWD = process.cwd();
// WP-CLI through wp-env, each argument its own argv entry (no shell).
const wp = (...args: string[]): string =>
  (execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string);

function createPage(title: string): number {
  const id = parseInt(wp('post', 'create', '--post_type=page', '--post_status=draft', '--post_author=1', `--post_title=${title}`, '--porcelain'), 10);
  wp('post', 'meta', 'update', String(id), '_wp_page_template', 'composition.php');
  return id;
}

async function openWorkspace(page: Page, postId: number): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=pp-composition&post=${postId}`);
  await expect(page.locator('#pp-workspace')).toBeVisible();
  await page.waitForSelector('.CodeMirror', { state: 'attached', timeout: 10000 });
  await page.locator('#pp-view-toggle').click();
  await expect(page.locator('#pp-view-toggle')).toHaveText('Accordion', { timeout: 5000 });
}

async function setComposition(page: Page, composition: unknown[]): Promise<void> {
  await page.evaluate((val: string) => {
    (document.querySelector('.CodeMirror') as any).CodeMirror.setValue(val);
  }, JSON.stringify(composition));
}

async function previewFrame(page: Page): Promise<Frame> {
  const frame = await (await page.locator('#pp-preview-frame').elementHandle())!.contentFrame();
  expect(frame, 'the preview iframe must host a document').not.toBeNull();
  return frame as Frame;
}

/**
 * Record every message the editor window receives, after the editor's own
 * listener (same dispatch, registered later), so a test can wait for a message
 * to have been handled instead of sleeping.
 */
async function logMessages(page: Page): Promise<void> {
  await page.evaluate(() => {
    (window as any).__ppSeen = [];
    window.addEventListener('message', (e) => { (window as any).__ppSeen.push(e.data && e.data.y); });
  });
}

async function seen(page: Page): Promise<unknown[]> {
  return page.evaluate(() => (window as any).__ppSeen);
}

const LONG = '<p>' + 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. '.repeat(30) + '</p>';

function composition(lastBody: string, ink: string): unknown[] {
  const bands: unknown[] = [{ component: 'hero', props: { title: 'Isolation Hero' } }];
  for (let i = 1; i <= 8; i++) {
    bands.push({ component: 'section', props: { title: `Section ${i}`, body: LONG } });
  }
  bands.push({
    component: 'section',
    // A band id, so the authored colour is emitted (keyed to the band) in the
    // document's <head>.
    id: 'pp-9e1a7c0d',
    props: { title: 'Last section', body: `<p>${lastBody}</p>` },
    udc: { body: { typography: { color: ink } } },
  });
  return bands;
}

test.describe('Editor preview isolation', () => {
  let pageId = 0;

  test.afterEach(() => {
    if (pageId) {
      try { wp('post', 'delete', String(pageId), '--force'); } catch { /* already gone */ }
      pageId = 0;
    }
  });

  test('the preview renders in an opaque origin the editor cannot reach @smoke', async ({ page }) => {
    pageId = createPage('E2E Preview Isolation Origin');
    await openWorkspace(page, pageId);
    await setComposition(page, composition('First text', '#7a3e9d'));

    const preview = page.frameLocator('#pp-preview-frame');
    await expect(preview.locator('.hero__title')).toContainText('Isolation Hero', { timeout: 15000 });

    await expect(page.locator('#pp-preview-frame')).toHaveAttribute('sandbox', 'allow-scripts');

    const frame = await previewFrame(page);
    expect(await frame.evaluate(() => self.origin), 'an opaque origin serializes as "null"').toBe('null');
    expect(
      await frame.evaluate(() => { try { return (window.parent as Window).document.title; } catch (e) { return `blocked:${(e as Error).name}`; } }),
      'the preview must not reach the admin document',
    ).toBe('blocked:SecurityError');

    const fromEditor = await page.evaluate(() => {
      const f = document.getElementById('pp-preview-frame') as HTMLIFrameElement;
      let reach = 'none';
      try { reach = String((f.contentWindow as Window).document.body.innerHTML.length); } catch (e) { reach = `blocked:${(e as Error).name}`; }
      return { contentDocument: f.contentDocument === null, reach };
    });
    expect(fromEditor.contentDocument, 'the editor gets no contentDocument').toBe(true);
    expect(fromEditor.reach, 'the editor must not reach the preview document').toBe('blocked:SecurityError');

    // Script in the preview may open no connection. The site's REST index answers
    // an opaque ("null") origin, so without the preview's content policy this
    // fetch succeeds (measured: 200); with it, the browser refuses it as a
    // connect-src violation. ?rest_route= works under plain permalinks too.
    const connection = await frame.evaluate(async (url) => {
      const violations: string[] = [];
      document.addEventListener('securitypolicyviolation', (e) => violations.push(e.effectiveDirective));
      let outcome = 'connected';
      try { await fetch(url); } catch (e) { outcome = `refused:${(e as Error).name}`; }
      await new Promise((r) => setTimeout(r, 50));
      return { outcome, violations };
    }, 'http://localhost:8889/?rest_route=/');
    expect(connection.outcome, 'an in-preview fetch must be refused').toBe('refused:TypeError');
    expect(connection.violations).toContain('connect-src');

    // Still a real preview: the theme stylesheets load and apply in the opaque document.
    const styled = await frame.evaluate(() => ({
      sheets: Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .filter((l) => (l as HTMLLinkElement).sheet !== null).length,
      heroPadding: getComputedStyle(document.querySelector('.hero') as Element).paddingTop,
    }));
    expect(styled.sheets, 'base, components and utilities must all load').toBeGreaterThanOrEqual(3);
    expect(styled.heroPadding, 'theme CSS must apply inside the preview').not.toBe('0px');
  });

  test('an edit rebuilds the preview, refreshes its styles and keeps the scroll position', async ({ page }) => {
    pageId = createPage('E2E Preview Isolation Refresh');
    await openWorkspace(page, pageId);
    await setComposition(page, composition('First text', 'rgb(122, 62, 157)'));

    const preview = page.frameLocator('#pp-preview-frame');
    const last = preview.locator('.section').last().locator('.section__content');
    await expect(last).toContainText('First text', { timeout: 15000 });
    await expect(last).toHaveCSS('color', 'rgb(122, 62, 157)');

    // Scroll the preview well down, and let the frame report it.
    await logMessages(page);
    let frame = await previewFrame(page);
    const scrollable = await frame.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
    expect(scrollable, 'the fixture must be taller than the preview pane').toBeGreaterThan(1000);
    const target = Math.min(1200, scrollable);
    await frame.evaluate((y) => {
      window.scrollTo({ top: y, left: 0, behavior: 'instant' as ScrollBehavior });
      (window as any).__ppBeforeEdit = true;
    }, target);
    // Wait until the frame's report has been handled by the editor.
    await expect.poll(() => seen(page), { timeout: 10000 }).toContain(target);

    // Edit body text AND the authored colour: the colour lives in the document's
    // <head> (#pp-udc-authored), which a body-only refresh would leave stale.
    await setComposition(page, composition('Second text', 'rgb(20, 110, 60)'));
    await expect(last).toContainText('Second text', { timeout: 15000 });
    // The rebuilt document was handed the reported position (a clearer failure
    // than a scroll mismatch if the report never arrived).
    expect(await page.locator('#pp-preview-frame').getAttribute('srcdoc')).toContain(`var y=${target};`);
    await expect(last).toHaveCSS('color', 'rgb(20, 110, 60)');

    frame = await previewFrame(page);
    expect(await frame.evaluate(() => (window as any).__ppBeforeEdit === true), 'the refresh must be a new document').toBe(false);
    // Read once the load event has passed, so a late adjustment is included.
    await frame.waitForLoadState('load');
    const y = await frame.evaluate(() => window.pageYOffset);
    expect(Math.abs(y - target), `the preview must reopen at ${target}px, got ${y}px`).toBeLessThanOrEqual(2);
  });

  test('the editor honours only a scroll position, and only from the preview itself', async ({ page }) => {
    pageId = createPage('E2E Preview Isolation Messages');
    await openWorkspace(page, pageId);
    await setComposition(page, composition('First text', 'rgb(122, 62, 157)'));
    const last = page.frameLocator('#pp-preview-frame').locator('.section').last().locator('.section__content');
    await expect(last).toContainText('First text', { timeout: 15000 });

    // Establish a known position from the preview, through the real bridge.
    await logMessages(page);
    let frame = await previewFrame(page);
    await frame.evaluate(() => window.scrollTo({ top: 700, left: 0, behavior: 'instant' as ScrollBehavior }));
    await expect.poll(() => seen(page), { timeout: 10000 }).toContain(700);

    // Then, from inside the preview: messages that carry more than a position.
    await frame.evaluate(() => {
      window.parent.postMessage({ type: 'pp-preview:scroll', y: 41, extra: true }, '*');
      window.parent.postMessage({ type: 'pp-preview:scroll', y: 42, action: 'publish' }, '*');
      window.parent.postMessage({ type: 'publish', y: 43 }, '*');
    });
    // And a well-formed message from a second sandboxed frame (its origin is
    // "null" too; only the sender check tells it apart).
    await page.evaluate(() => {
      const other = document.createElement('iframe');
      other.setAttribute('sandbox', 'allow-scripts');
      other.id = 'pp-e2e-other-frame';
      other.srcdoc = '<script>parent.postMessage({type:"pp-preview:scroll",y:44},"*")<\/script>';
      document.body.appendChild(other);
    });
    // Every refused message has reached the editor before the next refresh.
    await expect.poll(() => seen(page), { timeout: 10000 }).toEqual(expect.arrayContaining([41, 42, 43, 44]));

    await setComposition(page, composition('Second text', 'rgb(122, 62, 157)'));
    await expect(last).toContainText('Second text', { timeout: 15000 });
    expect(await page.locator('#pp-preview-frame').getAttribute('srcdoc')).toContain('var y=700;');
    frame = await previewFrame(page);
    await frame.waitForLoadState('load');
    expect(Math.abs((await frame.evaluate(() => window.pageYOffset)) - 700)).toBeLessThanOrEqual(2);
  });

  test('the scroll position survives a refresh while lazy, unsized images are still arriving', async ({ page }) => {
    // Images rendered from a raw URL carry loading="lazy" and no dimensions. In
    // the rebuilt document they arrive late (delayed here, as on a slow host), so
    // the page is too short when the restore runs, keeps growing after `load`,
    // and grows ABOVE the restored position. The restore must reach the target,
    // hold it while content above settles, and the editor must not adopt a clamp.
    pageId = createPage('E2E Preview Isolation Lazy Images');
    await openWorkspace(page, pageId);
    await page.route('**/screenshot.png*', async (route) => {
      if (route.request().url().includes('v=slow')) await new Promise((r) => setTimeout(r, 1500));
      await route.continue();
    });
    const bands = (label: string, v: string): unknown[] => [
      { component: 'hero', props: { title: 'Lazy Hero' } },
      ...Array.from({ length: 30 }, (_, n) => ({
        component: 'section',
        props: {
          title: `Image band ${n}`,
          body: '<p>Lazy image band.</p>',
          layout: 'image-left',
          image_url: `http://localhost:8889/wp-content/themes/PromptingPress/screenshot.png?band=${n}&v=${v}`,
          image_alt: `Band ${n}`,
        },
      })),
      { component: 'section', props: { title: 'Lazy tail', body: `<p>${label}</p>` } },
    ];
    await setComposition(page, bands('First text', 'fast'));
    const tail = page.frameLocator('#pp-preview-frame').locator('.section').last().locator('.section__content');
    await expect(tail).toContainText('First text', { timeout: 15000 });

    // Walk the first preview down until every image has loaded and the height is stable.
    await logMessages(page);
    let frame = await previewFrame(page);
    // The server markup is lazy and unsized (the bridge then loads it eagerly).
    const markup = (await page.locator('#pp-preview-frame').getAttribute('srcdoc')) || '';
    const lazy = (markup.match(/<img[^>]*loading="lazy"[^>]*>/g) || []).filter((tag) => !/\sheight=/.test(tag)).length;
    expect(lazy, 'the fixture must render lazy, unsized images').toBeGreaterThanOrEqual(30);
    const full = await frame.evaluate(async () => {
      let last = -1;
      for (let i = 0; i < 120; i++) {
        window.scrollTo({ top: document.documentElement.scrollHeight, left: 0, behavior: 'instant' as ScrollBehavior });
        await new Promise((r) => setTimeout(r, 120));
        const h = document.documentElement.scrollHeight;
        if (h === last && Array.from(document.images).every((im) => im.complete)) break;
        last = h;
      }
      return document.documentElement.scrollHeight - window.innerHeight;
    });
    const target = Math.floor(full * 0.8);
    await frame.evaluate((y) => window.scrollTo({ top: y, left: 0, behavior: 'instant' as ScrollBehavior }), target);
    await expect.poll(() => seen(page), { timeout: 10000 }).toContain(target);

    // Rebuild with slow images.
    await setComposition(page, bands('Second text', 'slow'));
    await expect(tail).toContainText('Second text', { timeout: 15000 });
    frame = await previewFrame(page);
    // Wait until the images that will load (those near the restored position;
    // the rest stay lazy) have arrived and the layout has stopped changing.
    await frame.evaluate(async () => {
      let stable = 0;
      let last = '';
      for (let i = 0; i < 60 && stable < 3; i++) {
        await new Promise((r) => setTimeout(r, 1000));
        const now = `${document.documentElement.scrollHeight}:${window.pageYOffset}:${Array.from(document.images).filter((im) => im.complete).length}`;
        stable = now === last ? stable + 1 : 0;
        last = now;
      }
    });
    const y = await frame.evaluate(() => window.pageYOffset);
    expect(Math.abs(y - target), `the preview must hold ${target}px through the growth, got ${y}px`).toBeLessThanOrEqual(2);

    // And the editor did not adopt a clamp or a drift: the next refresh opens at the same place.
    await setComposition(page, bands('Third text', 'slow'));
    await expect(tail).toContainText('Third text', { timeout: 15000 });
    expect(await page.locator('#pp-preview-frame').getAttribute('srcdoc')).toContain(`var y=${target};`);
  });

  test('the scroll position survives a refresh when unsized lazy images lie far ABOVE it', async ({ page }) => {
    // The large-gap case: the remembered position is deep in the page and most of
    // the height above it is lazy, unsized images. In the rebuilt document those
    // images are nowhere near the viewport, so lazily they would never load and
    // the page would never reach the coordinate the reader was at. The preview
    // must still open at the same band.
    pageId = createPage('E2E Preview Isolation Large Gap');
    await openWorkspace(page, pageId);
    await page.route('**/screenshot.png*', async (route) => {
      if (route.request().url().includes('v=slow')) await new Promise((r) => setTimeout(r, 150));
      await route.continue();
    });
    const bands = (label: string, v: string): unknown[] => [
      ...Array.from({ length: 30 }, (_, n) => ({
        component: 'section',
        props: {
          title: `Gap band ${n}`,
          body: '<p>x</p>',
          layout: 'image-left',
          image_url: `http://localhost:8889/wp-content/themes/PromptingPress/screenshot.png?gap=${n}&v=${v}`,
          image_alt: `Gap ${n}`,
        },
      })),
      { component: 'section', props: { title: 'Gap tail', body: `<p>${label}</p>` } },
    ];
    await setComposition(page, bands('First text', 'fast'));
    const tail = page.frameLocator('#pp-preview-frame').locator('.section').last().locator('.section__content');
    await expect(tail).toContainText('First text', { timeout: 15000 });

    // Read the whole first preview top to bottom, as a reader would, so every
    // image above the target has really loaded (a lazy image that has not been
    // requested still reports complete === true, so check naturalWidth), then put
    // band 20 at the top.
    await logMessages(page);
    let frame = await previewFrame(page);
    const target = await frame.evaluate(async () => {
      for (let i = 0; i < 400; i++) {
        const atBottom = window.pageYOffset + window.innerHeight >= document.documentElement.scrollHeight - 1;
        const loaded = Array.from(document.images).every((im) => im.naturalWidth > 0);
        if (atBottom && loaded) break;
        window.scrollBy({ top: Math.floor(window.innerHeight / 2), left: 0, behavior: 'instant' as ScrollBehavior });
        await new Promise((r) => setTimeout(r, 80));
      }
      const band = Array.from(document.querySelectorAll('.section__title')).find((t) => t.textContent === 'Gap band 20') as HTMLElement;
      return Math.floor(band.getBoundingClientRect().top + window.pageYOffset);
    });
    await frame.evaluate((y) => window.scrollTo({ top: y, left: 0, behavior: 'instant' as ScrollBehavior }), target);
    await expect.poll(() => seen(page), { timeout: 10000 }).toContain(target);

    // Rebuild: every image is new (and slower), none is sized.
    await setComposition(page, bands('Second text', 'slow'));
    await expect(tail).toContainText('Second text', { timeout: 15000 });
    frame = await previewFrame(page);
    await frame.evaluate(async () => {
      let stable = 0;
      let last = '';
      for (let i = 0; i < 60 && stable < 3; i++) {
        await new Promise((r) => setTimeout(r, 1000));
        const now = `${document.documentElement.scrollHeight}:${window.pageYOffset}`;
        stable = now === last ? stable + 1 : 0;
        last = now;
      }
    });
    const after = await frame.evaluate(() => {
      const titles = Array.from(document.querySelectorAll('.section__title'));
      const atTop = titles.filter((t) => t.getBoundingClientRect().top <= 5).pop();
      return { y: window.pageYOffset, band: atTop ? atTop.textContent : null };
    });
    expect(after.band, `the preview must reopen at band 20 (y=${target}), got ${after.band} at y=${after.y}`).toBe('Gap band 20');
    expect(Math.abs(after.y - target)).toBeLessThanOrEqual(2);

    // The editor still remembers the reader's place, not a clamp.
    await setComposition(page, bands('Third text', 'slow'));
    await expect(tail).toContainText('Third text', { timeout: 15000 });
    expect(await page.locator('#pp-preview-frame').getAttribute('srcdoc')).toContain(`var y=${target};`);
  });

  test('a reader who jumps from the top to deep in the page keeps their place across a refresh', async ({ page }) => {
    // The preview opens at the top; the reader jumps (End key) straight past the
    // images in between. The coordinate they report must be measured in the same
    // layout the next refresh restores into, or it lands on different content.
    pageId = createPage('E2E Preview Isolation Jump Scroll');
    await openWorkspace(page, pageId);
    const bands = (label: string): unknown[] => [
      ...Array.from({ length: 30 }, (_, n) => ({
        component: 'section',
        props: {
          title: `Jump band ${n}`,
          body: '<p>x</p>',
          layout: 'image-left',
          image_url: `http://localhost:8889/wp-content/themes/PromptingPress/screenshot.png?jump=${n}&l=${label.length}`,
          image_alt: `Jump ${n}`,
        },
      })),
      { component: 'section', props: { title: 'Jump tail', body: `<p>${label}</p>` } },
    ];
    await setComposition(page, bands('First text'));
    const tail = page.frameLocator('#pp-preview-frame').locator('.section').last().locator('.section__content');
    await expect(tail).toContainText('First text', { timeout: 15000 });
    await logMessages(page);

    const settleLayout = async () => (await previewFrame(page)).evaluate(async () => {
      let stable = 0;
      let last = '';
      for (let i = 0; i < 60 && stable < 3; i++) {
        await new Promise((r) => setTimeout(r, 500));
        const now = `${document.documentElement.scrollHeight}:${window.pageYOffset}`;
        stable = now === last ? stable + 1 : 0;
        last = now;
      }
    });
    const bandAtTop = async () => (await previewFrame(page)).evaluate(() => {
      const titles = Array.from(document.querySelectorAll('.section__title'));
      const atTop = titles.filter((t) => t.getBoundingClientRect().top <= 5).pop();
      return { y: window.pageYOffset, band: atTop ? atTop.textContent : null };
    });

    // Jump from the top to the end with the keyboard.
    await page.locator('#pp-preview-frame').click({ position: { x: 20, y: 200 } });
    await page.keyboard.press('End');
    await settleLayout();
    const before = await bandAtTop();
    expect(before.y, 'the jump must land deep in the page').toBeGreaterThan(3000);
    await expect.poll(() => seen(page), { timeout: 10000 }).toContain(before.y);

    await setComposition(page, bands('Second text'));
    await expect(tail).toContainText('Second text', { timeout: 15000 });
    await settleLayout();
    const after = await bandAtTop();
    expect(after.band, `the preview must reopen at ${before.band} (y=${before.y}), got ${after.band} at y=${after.y}`).toBe(before.band);
  });
});
