/**
 * tests/e2e/ack-signing.spec.ts — signed acknowledgement rows against REAL WordPress (#1214).
 *
 * The PHP suite signs through a wp_hash() stub in tests/bootstrap.php. This runs the same path
 * through the WordPress that ships wp_hash() and the real salts: the CLI acknowledge writes a
 * row that verifies and releases the page, and the same row changed by a raw post-meta write
 * stops releasing it and is named as an ignored acknowledgement.
 *
 * CLI-only: no browser is needed, because the claim is about the stored row and the gate.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

function wp(args: string): string {
  return execSync(`npx wp-env run cli wp ${args}`, { cwd: process.cwd(), encoding: 'utf-8' });
}

function createPage(title: string): number {
  const id = parseInt(
    wp(`post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`).trim(),
    10
  );
  wp(`post meta update ${id} _wp_page_template composition.php`);
  return id;
}

/**
 * Writes the composition through the theme's own authoring write (pp_update_composition), the
 * path that mints band ids and stores the shape the findings engine reads. Base64 keeps the
 * JSON clear of shell quoting.
 */
function setComposition(postId: number, composition: unknown[]): void {
  const b64 = Buffer.from(JSON.stringify(composition)).toString('base64');
  const out = wp(`eval "var_export(pp_update_composition(${postId}, json_decode(base64_decode('${b64}'), true)));"`);
  expect(out, 'the authoring write lands').toContain('true');
}

/** The acknowledgement map is stored as an array: --format=json decodes it the way a raw writer would. */
function setAcknowledgements(postId: number, map: unknown): void {
  const json = JSON.stringify(map).replace(/'/g, "'\\''");
  wp(`post meta update ${postId} _pp_acknowledged_advisories '${json}' --format=json`);
}

test.describe('#1214 signed acknowledgement rows, on real WordPress', () => {
  let pageId = 0;

  test.afterEach(() => {
    if (pageId) {
      wp(`post delete ${pageId} --force`);
      pageId = 0;
    }
  });

  test('acknowledge signs a row that releases the page; a tampered row is ignored and named', () => {
    pageId = createPage('E2E signed acknowledgement');
    // An ink set over the eyebrow's own default pill (role-own-surface.spec's live fixture): a
    // judgment call the engine names but cannot measure, so it is acknowledgeable.
    setComposition(pageId, [
      {
        component: 'section',
        props: { id: 'pp-ack1214', eyebrow: 'SECTION', title: 'Dark', body: '<p>b</p>' },
        udc: {
          _band: { background: { fill: '#101828' } },
          eyebrow: { typography: { color: '#ffffff' } },
        },
      },
    ]);

    const before = wp(`pp check page --post_id=${pageId}`);
    const match = before.match(/\[key: (udc_role_ink_over_own_surface:[A-Za-z0-9_-]+:[0-9a-f]{32})\]/);
    expect(match, `premise: the ink finding is keyed\n${before}`).not.toBeNull();
    const key = (match as RegExpMatchArray)[1];

    wp(`pp check acknowledge --post_id=${pageId} --key=${key} --note="measured 9:1 in e2e"`);

    const stored = JSON.parse(wp(`post meta get ${pageId} _pp_acknowledged_advisories --format=json`));
    expect(stored[key].sig, 'the real wp_hash() signed the row').toMatch(/^[0-9a-f]{64}$/);

    const released = wp(`pp check page --post_id=${pageId}`);
    expect(released).toContain('acknowledged as intentional (not failing)');
    expect(released).toContain('no composition smells');
    expect(released).not.toContain(`[key: ${key}]`);
    expect(released).not.toContain('ignored acknowledgement');

    // The same row, its note changed by a raw post-meta write.
    stored[key].note = 'changed by a raw write';
    setAcknowledgements(pageId, stored);

    const tampered = wp(`pp check page --post_id=${pageId}`);
    expect(tampered, 'the finding is listed among the smells again').toContain(`[key: ${key}]`);
    expect(tampered).toContain(`ignored acknowledgement ${key}: its signature does not verify on this site`);
    expect(tampered).not.toContain('acknowledged as intentional (not failing)');
  });

  test('--malformed, parsed by real WP-CLI, removes only malformed rows and takes no value', () => {
    pageId = createPage('E2E malformed acknowledgement');
    setComposition(pageId, [
      {
        component: 'section',
        props: { id: 'pp-ack1214m', eyebrow: 'SECTION', title: 'Dark', body: '<p>b</p>' },
        udc: { _band: { background: { fill: '#101828' } }, eyebrow: { typography: { color: '#ffffff' } } },
      },
    ]);
    const key = (wp(`pp check page --post_id=${pageId}`).match(
      /\[key: (udc_role_ink_over_own_surface:[A-Za-z0-9_-]+:[0-9a-f]{32})\]/
    ) as RegExpMatchArray)[1];
    wp(`pp check acknowledge --post_id=${pageId} --key=${key} --note="measured in e2e"`);

    const stored = JSON.parse(wp(`post meta get ${pageId} _pp_acknowledged_advisories --format=json`));
    stored['x;id #'] = { acknowledged_at: '', note: 'planted' };
    setAcknowledgements(pageId, stored);

    const listed = wp(`pp check page --post_id=${pageId}`);
    expect(listed).toContain('ignored acknowledgement with a malformed key (6 bytes, not shown');
    expect(listed).toContain(`wp pp check unacknowledge --post_id=${pageId} --malformed`);
    expect(listed).not.toContain('x;id #');

    // A value is refused by the real parser and removes nothing.
    expect(() => wp(`pp check unacknowledge --post_id=${pageId} --malformed=false`)).toThrow(/takes no value/);
    expect(() => wp(`pp check unacknowledge --post_id=${pageId} --malformed --key=${key}`)).toThrow(/not both/);
    expect(Object.keys(JSON.parse(wp(`post meta get ${pageId} _pp_acknowledged_advisories --format=json`)))).toContain('x;id #');

    expect(wp(`pp check unacknowledge --post_id=${pageId} --malformed`)).toContain('Removed 1 acknowledgement row(s)');
    const after = JSON.parse(wp(`post meta get ${pageId} _pp_acknowledged_advisories --format=json`));
    expect(Object.keys(after), 'the signed row survives').toEqual([key]);
    expect(wp(`pp check page --post_id=${pageId}`)).toContain('acknowledged as intentional (not failing)');
  });
});
