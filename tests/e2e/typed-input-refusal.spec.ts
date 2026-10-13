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

  /**
   * A sent-but-malformed expected_version is refused by name, never read as "not sent" (#1274).
   * Before the fix `expected_version[]=x` parsed as absent, so save and publish wrote the
   * composition UNCONDITIONALLY: the conflict check the client asked for was skipped.
   * tests/TypedInputRefusalTest.php §5 pins the save handler at unit level; publish is an
   * add_action closure, so this is the only place its refusal runs.
   */
  test('save and publish refuse expected_version[]=x by name and write nothing', async ({ page }) => {
    const storedBefore = wp('post', 'meta', 'get', String(pageId), '_pp_composition');
    await page.goto(`/wp-admin/admin.php?page=pp-composition&post=${pageId}`);
    await page.waitForFunction(() => Boolean((window as any).ppAdminEditor && (window as any).ppAdminEditor.nonce));

    // Four requests: the array shape on save and publish with a composition, a scalar shape on
    // publish, and a publish with NO composition — the case the read placed before the save
    // branch exists for (a malformed baseline must not publish the stored composition).
    const answers = await page.evaluate(async (pid: number) => {
      const config = (window as any).ppAdminEditor;
      const composition = JSON.stringify([{ component: 'section', props: { title: 'Overwritten', body: '<p>No.</p>' } }]);
      const cases: Array<{ label: string; action: string; composition: string | null; field: string; value: string }> = [
        { label: 'save, expected_version[]=x', action: 'pp_save_composition', composition, field: 'expected_version[]', value: 'x' },
        { label: 'publish, expected_version[]=x', action: 'pp_publish_page', composition, field: 'expected_version[]', value: 'x' },
        { label: 'publish, expected_version=12abc', action: 'pp_publish_page', composition, field: 'expected_version', value: '12abc' },
        { label: 'publish, no composition, expected_version[]=x', action: 'pp_publish_page', composition: null, field: 'expected_version[]', value: 'x' },
      ];
      const out: Array<{ label: string; status: number; body: string }> = [];
      for (const c of cases) {
        const data = new FormData();
        data.append('action', c.action);
        data.append('nonce', config.nonce);
        data.append('post_id', String(pid));
        if (c.composition !== null) data.append('composition', c.composition);
        data.append(c.field, c.value);
        const res = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
        out.push({ label: c.label, status: res.status, body: await res.text() });
      }
      return out;
    }, pageId);

    expect(answers).toHaveLength(4);
    for (const answer of answers) {
      expect(answer.status, `${answer.label}: ${answer.body.slice(0, 200)}`).toBe(200);
      const parsed = JSON.parse(answer.body) as { success: boolean; data: { code?: string; message?: string } };
      expect(parsed.success, `${answer.label}: refused`).toBe(false);
      expect(parsed.data.code, `${answer.label}: refused by name`).toBe('invalid_param_type');
      expect(parsed.data.message, `${answer.label}: the refusal names the param`).toContain('"expected_version"');
    }

    // Nothing was written and nothing was published.
    expect(wp('post', 'get', String(pageId), '--field=post_status')).toBe('draft');
    expect(wp('post', 'meta', 'get', String(pageId), '_pp_composition')).toBe(storedBefore);
  });

  /**
   * The honest publish paths still work through the reordered closure (#1274): a well-formed
   * stale baseline is refused with composition_conflict, and a current one publishes. Kept
   * LAST in the file because the final request publishes the page.
   */
  test('publish with a well-formed baseline still runs the conflict check', async ({ page }) => {
    await page.goto(`/wp-admin/admin.php?page=pp-composition&post=${pageId}`);
    await page.waitForFunction(() => Boolean((window as any).ppAdminEditor && (window as any).ppAdminEditor.nonce));

    // One request per call: [fields] → the parsed envelope.
    const send = (fields: Record<string, string>) =>
      page.evaluate(async ({ pid, fields }: { pid: number; fields: Record<string, string> }) => {
        const config = (window as any).ppAdminEditor;
        const data = new FormData();
        data.append('nonce', config.nonce);
        data.append('post_id', String(pid));
        for (const [k, v] of Object.entries(fields)) data.append(k, v);
        const res = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
        return JSON.parse(await res.text()) as { success: boolean; data: any };
      }, { pid: pageId, fields });
    const composition = (title: string) => JSON.stringify([{ component: 'section', props: { title, body: '<p>Body.</p>' } }]);

    // An unconditional save (no baseline) lands and returns the page's current version.
    const seeded = await send({ action: 'pp_save_composition', composition: composition('Current') });
    expect(seeded.success, 'the unconditional save landed').toBe(true);
    const version = Number(seeded.data && seeded.data.version);
    expect(version).toBeGreaterThan(0);

    // A stale baseline is refused AND leaves the page as it was: still a draft, still 'Current'.
    const stale = await send({ action: 'pp_publish_page', composition: composition('Stale'), expected_version: String(version - 1) });
    expect(stale.success, 'a stale baseline is refused').toBe(false);
    expect(stale.data.code).toBe('composition_conflict');
    expect(wp('post', 'get', String(pageId), '--field=post_status')).toBe('draft');
    const afterStale = wp('post', 'meta', 'get', String(pageId), '_pp_composition');
    expect(afterStale).toContain('Current');
    expect(afterStale).not.toContain('Stale');

    // A current baseline publishes.
    const current = await send({ action: 'pp_publish_page', composition: composition('Published'), expected_version: String(version) });
    expect(current.success, 'a current baseline publishes').toBe(true);
    expect(wp('post', 'get', String(pageId), '--field=post_status')).toBe('publish');
    expect(wp('post', 'meta', 'get', String(pageId), '_pp_composition')).toContain('Published');
  });
});
