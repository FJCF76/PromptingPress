import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';

/**
 * The editor endpoints refuse a non-string `composition` instead of fataling (#935).
 *
 * A client sending `composition[]=x` puts an ARRAY in $_POST. The save, preview and publish
 * handlers guarded the field with isset() only, so stripslashes() threw a TypeError and the
 * request died with a 500 and WordPress's critical-error page, which the editor cannot read.
 * Each now answers with the refusal every other malformed body gets:
 * {"success":false,"data":"Invalid JSON."}. Publish in particular must refuse, not read the
 * malformed field as "nothing to save" and publish the stored composition.
 *
 * tests/TypedInputRefusalTest.php pins the save handler and the shared reader at unit level;
 * the preview and publish handlers are add_action closures, so this real-WordPress pass is
 * the only place their bodies run.
 */

const CWD = process.cwd();
const wp = (...args: string[]): string =>
  (execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string);

let pageId = 0;

test.describe('editor endpoints refuse a non-string composition (#935)', () => {
  test.beforeAll(() => {
    pageId = parseInt(wp('post', 'create', '--post_type=page', '--post_status=draft', '--post_title=typed-input-refusal', '--porcelain'), 10);
    wp('post', 'meta', 'update', String(pageId), '_wp_page_template', 'composition.php');
    // A real stored composition, so "unchanged" below compares something rather than two blanks.
    wp('post', 'meta', 'update', String(pageId), '_pp_composition', '[{"component":"section","props":{"title":"Seeded","body":"<p>Kept.</p>"}}]');
  });

  test.afterAll(() => {
    if (pageId) {
      try {
        wp('post', 'delete', String(pageId), '--force');
      } catch {
        /* already gone */
      }
    }
  });

  test('save, preview and publish answer composition[]=x with Invalid JSON.', async ({ page }) => {
    const storedBefore = wp('post', 'meta', 'get', String(pageId), '_pp_composition');
    await page.goto(`/wp-admin/admin.php?page=pp-composition&post=${pageId}`);
    await page.waitForFunction(() => Boolean((window as any).ppAdminEditor && (window as any).ppAdminEditor.nonce));

    const answers = await page.evaluate(async (pid: number) => {
      const config = (window as any).ppAdminEditor;
      const out: Record<string, { status: number; body: string }> = {};
      for (const action of ['pp_save_composition', 'pp_preview_composition', 'pp_publish_page']) {
        const data = new FormData();
        data.append('action', action);
        data.append('nonce', config.nonce);
        data.append('post_id', String(pid));
        data.append('composition[]', 'x');
        const res = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
        out[action] = { status: res.status, body: await res.text() };
      }
      return out;
    }, pageId);

    for (const action of ['pp_save_composition', 'pp_preview_composition', 'pp_publish_page']) {
      const answer = answers[action];
      expect(answer.status, `${action}: no 500 — ${answer.body.slice(0, 200)}`).toBe(200);
      const own = (key: string, obj: Record<string, unknown>) => Object.prototype.hasOwnProperty.call(obj, key);
      const parsed = JSON.parse(answer.body) as Record<string, unknown>;
      expect(own('success', parsed) && own('data', parsed), `${action}: a JSON envelope`).toBe(true);
      expect(parsed.success, `${action}: refused`).toBe(false);
      expect(parsed.data, `${action}: with the named refusal`).toBe('Invalid JSON.');
    }

    // The refusals are refusals: the page was not published around them, and its stored
    // composition is byte-identical to before the three requests.
    expect(wp('post', 'get', String(pageId), '--field=post_status')).toBe('draft');
    expect(wp('post', 'meta', 'get', String(pageId), '_pp_composition')).toBe(storedBefore);
  });
});
