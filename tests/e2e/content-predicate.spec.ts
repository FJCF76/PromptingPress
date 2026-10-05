import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

/**
 * Layer 3A's live pins (#1242 T3a; docs/v2/LAYER-3-CONTRACT.md §10).
 *
 * The PHPUnit suite runs the predicate on a VENDORED copy of WordPress 7.0's HTML API.
 * These pins run it on LIVE core and in the pinned Chromium, so drift in either is read
 * and ruled, never absorbed:
 *
 *   T-1   the stored snapshot of core `post` (lib/content-tables/core-post-wp-7.0.json)
 *         equals live `wp_kses_allowed_html('post')`.
 *   P-16  the parser-bail set (tests/fixtures/content/p16-bail-set-wp-<version>.json, the live core's version) equals what live
 *         WP_HTML_Processor bails on.
 *   E11   the stored clobbering-name list equals a fresh probe of window/document.
 *   T-9   mutation-XSS idempotence: sanitize -> browser parse -> sanitize is a fixed
 *         point, the browser DOM holds no excluded construct, the next band's sentinel is
 *         intact, and each entry's expected survivor is present (an over-stripping or
 *         empty sanitizer cannot pass). M-19's adoption condition.
 */

const root = path.resolve(__dirname, '..', '..');

function probe(payload: unknown): any {
  const b64 = Buffer.from(JSON.stringify(payload), 'utf-8').toString('base64');
  const php = `require get_template_directory() . "/tests/e2e/fixtures/content-probe.php"; echo pp_t3a_probe("${b64}");`;
  const out = execSync(`npx wp-env run cli wp eval '${php}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
    maxBuffer: 64 * 1024 * 1024,
  }).trim();
  const start = out.indexOf('{') >= 0 && (out.indexOf('[') < 0 || out.indexOf('{') < out.indexOf('[')) ? out.indexOf('{') : out.indexOf('[');
  return JSON.parse(out.slice(start));
}

function judge(inputs: string[], sink = 'rich'): { clauses: string[]; html: string }[] {
  return probe({ op: 'judge', sink, inputs });
}

const WRAP: Record<string, [string, string]> = {
  rich: ['<div><div id="sink">', '</div></div>'],
  rich_cell: ['<div><table><tbody><tr><td id="sink">', '</td></tr></tbody></table></div>'],
};

async function parseInBrowser(page: Page, html: string, sink: string) {
  const [open, close] = WRAP[sink];
  await page.setContent(
    `<!DOCTYPE html><html><head></head><body><main><section>${open}${html}${close}</section><p id="sentinel">s</p></main></body></html>`,
  );
  return page.evaluate(() => {
    const sinkEl = document.getElementById('sink');
    const bad: string[] = [];
    // Foreign elements keep their own case in tagName (svg `style`, `foreignObject`), so
    // every comparison upper-cases first.
    const excluded = ['SCRIPT', 'IFRAME', 'FRAME', 'FRAMESET', 'EMBED', 'APPLET', 'NOSCRIPT', 'TEMPLATE', 'BASE',
      'META', 'LINK', 'SLOT', 'FOREIGNOBJECT', 'ANIMATE', 'ANIMATECOLOR', 'ANIMATEMOTION', 'ANIMATETRANSFORM',
      'SET', 'DISCARD', 'IMAGE', 'FEIMAGE', 'MGLYPH', 'MALIGNMARK', 'MACTION', 'ANNOTATION-XML', 'OBJECT'];
    for (const el of Array.from(document.body.querySelectorAll('*'))) {
      if (el.id === 'sentinel') continue;
      const name = el.tagName.toUpperCase();
      if (excluded.includes(name)) bad.push(el.tagName);
      if (name === 'STYLE' && el.closest('#sink')) bad.push(el.tagName);
      for (const a of Array.from(el.attributes)) {
        if (/^on/i.test(a.name)) bad.push(`${el.tagName}[${a.name}]`);
        if (['href', 'src', 'action', 'xlink:href', 'formaction', 'poster', 'data'].includes(a.name.toLowerCase())) {
          try {
            if (new URL(a.value, location.href).protocol === 'javascript:') bad.push(`${el.tagName}[${a.name}=javascript]`);
          } catch {
            /* not a URL */
          }
        }
      }
    }
    const sentinel = document.getElementById('sentinel');
    return {
      inner: sinkEl ? sinkEl.innerHTML : null,
      text: sinkEl ? sinkEl.textContent : '',
      bad,
      sentinelOk:
        !!sentinel &&
        sentinel.parentElement?.tagName === 'MAIN' &&
        sentinel.textContent === 's' &&
        sentinel.children.length === 0 &&
        !sentinel.closest('#sink'),
    };
  });
}

test.describe('Layer 3A content predicate — live pins', () => {
  test('T-1: the stored core `post` snapshot equals live core', () => {
    const live = probe({ op: 'core_post' });
    const file = live.wp_version === '7.0'
      ? path.join(root, 'lib/content-tables/core-post-wp-7.0.json')
      : path.join(root, `tests/fixtures/content/core-post-wp-${live.wp_version}.json`);
    expect(fs.existsSync(file), `a core \`post\` snapshot exists for live WordPress ${live.wp_version}`).toBe(true);
    const stored = JSON.parse(fs.readFileSync(file, 'utf-8'));
    expect(live.tags).toEqual(stored.tags);
  });

  test('P-16: the parser-bail set equals live WP_HTML_Processor', () => {
    // The snapshot of the LIVE core's version: the refusal follows the runtime parser.
    const version = probe({ op: 'core_post' }).wp_version as string;
    const file = path.join(root, `tests/fixtures/content/p16-bail-set-wp-${version}.json`);
    expect(fs.existsSync(file), `a P-16 snapshot exists for live WordPress ${version}`).toBe(true);
    const snapshot = JSON.parse(fs.readFileSync(file, 'utf-8'));
    const inputs: string[] = snapshot.cases.map((c: { input: string }) => c.input);
    const results = judge(inputs);
    snapshot.cases.forEach((c: { input: string; bails: boolean }, i: number) => {
      expect(results[i].clauses.includes('P-16'), `bail-set drift on ${c.input}`).toBe(c.bails);
    });
  });

  // The E11 probe, verbatim from the one that generated lib/content-tables/dom-clobber-*.json.
  async function clobberProbe(page: Page) {
    await page.route('http://probe.test/', (r) =>
      r.fulfill({ body: '<!DOCTYPE html><html><head><title>p</title></head><body></body></html>', contentType: 'text/html' }),
    );
    await page.goto('http://probe.test/');
    return page.evaluate(() => {
      const valid = (n: string) => /^[A-Za-z_$][\w$]*$/.test(n);
      const chain = (o: any) => {
        const s = new Set<string>();
        while (o) {
          Object.getOwnPropertyNames(o).forEach((n) => s.add(n));
          o = Object.getPrototypeOf(o);
        }
        return s;
      };
      const w = window as any;
      const d = document as any;
      const winNames = [...new Set([...Object.getOwnPropertyNames(window), ...chain(Object.getPrototypeOf(window))])].filter(valid);
      const docNames = [...new Set([...Object.getOwnPropertyNames(document), ...chain(Object.getPrototypeOf(document))])].filter(valid);
      const formNames = [...chain(document.createElement('form'))].filter(valid);
      const windowId: string[] = [];
      const documentName: string[] = [];
      const formControl: string[] = [];
      for (const n of winNames) {
        const el = document.createElement('div');
        el.id = n;
        document.body.appendChild(el);
        try { if (w[n] === el) windowId.push(n); } catch { /* unreadable */ }
        el.remove();
      }
      for (const n of docNames) {
        const el = document.createElement('img');
        el.name = n;
        document.body.appendChild(el);
        try { if (d[n] === el) documentName.push(n); } catch { /* unreadable */ }
        el.remove();
      }
      const f = document.createElement('form');
      document.body.appendChild(f);
      for (const n of formNames) {
        const el = document.createElement('input');
        el.name = n;
        f.appendChild(el);
        try { if ((f as any)[n] === el) formControl.push(n); } catch { /* unreadable */ }
        el.remove();
      }
      return { windowId: windowId.sort(), documentName: documentName.sort(), formControl: formControl.sort() };
    });
  }

  test('E11: the clobber tables equal a fresh probe of the pinned browser', async ({ page }) => {
    const fresh = await clobberProbe(page);
    const stored = JSON.parse(fs.readFileSync(path.join(root, 'lib/content-tables/dom-clobber-names.json'), 'utf-8'));
    expect(fresh.windowId, 'an element id shadows no window property').toEqual([]);
    expect(fresh.documentName).toEqual(stored.document_builtins);
    expect(fresh.formControl).toEqual(stored.form_builtins);
  });

  test('E11 red-proof: ordinary ids (top, title) are admitted and work; a named-access name is refused', async ({ page }) => {
    const judged = judge([
      '<div id="top">Top</div><h2 id="title">T</h2><svg aria-labelledby="title"><title id="t2">Logo</title></svg><a href="#top">back to top</a>',
      '<img alt="" src="/a.png" name="forms">',
      '<object type="application/pdf" data="/wp-content/uploads/a.pdf" id="forms"></object>',
    ]);
    expect(judged[0].clauses).toEqual([]);
    expect(judged[1].clauses).toContain('E11');
    expect(judged[2].clauses).toContain('E11');
    await page.route('http://probe.test/', (r) =>
      r.fulfill({
        body: `<!DOCTYPE html><html><head><title>doc</title></head><body><main>${judged[0].html}<div style="height:3000px"></div><a id="go" href="#top">go</a></main></body></html>`,
        contentType: 'text/html',
      }),
    );
    await page.goto('http://probe.test/');
    await page.click('#go');
    const state = await page.evaluate(() => ({
      target: document.querySelector(':target')?.id ?? null,
      topIsWindow: window.top === window,
      titleIsString: typeof (document as any).title === 'string' && document.title === 'doc',
      titleElement: document.getElementById('title')?.tagName ?? null,
    }));
    expect(state).toEqual({ target: 'top', topIsWindow: true, titleIsString: true, titleElement: 'H2' });
  });

  test('trust tier: a writer without unfiltered_html gets exactly what live core kses keeps', async () => {
    const styles = ['color: red', 'color: red; display: grid', 'text-align: center', 'position: fixed', 'cursor: pointer',
      '--brand: red', 'background-image: url(/a.png)', 'margin: 0 auto; padding: 1em'];
    const filtered: string[] = probe({ op: 'safecss', inputs: styles });
    const judged = probe({ op: 'judge', sink: 'rich', tier: 'core', inputs: styles.map((st) => `<p style="${st}">x</p>`) });
    // Kept WHOLE: the declarations core returns equal those sent, whitespace-normalised.
    const norm = (css: string) =>
      css.split(';').filter((d) => d.trim() !== '').map((d) => {
        const at = d.indexOf(':');
        return `${d.slice(0, at).trim().toLowerCase()}:${d.slice(at + 1).trim().replace(/\s+/g, ' ')}`;
      });
    styles.forEach((st, i) => {
      const whole = JSON.stringify(norm(filtered[i])) === JSON.stringify(norm(st));
      expect(judged[i].clauses.includes('unfiltered_html'), `${st} -> "${filtered[i]}"`).toBe(!whole);
    });
    const core = probe({ op: 'judge', sink: 'rich', tier: 'core', inputs: ['<svg></svg>', '<p class="a">x <a href="/y">y</a></p>'] });
    expect(core[0].clauses).toContain('unfiltered_html');
    expect(core[1].clauses).toEqual([]);
  });

  test('Δ5 descoped by the owner (2026-10-05): a form and its controls are refused on the live core', () => {
    const shapes = [
      '<form action="/s"><input type="text" name="q"></form>',
      '<form action="https://evil.example/x" method="post"><input type="password" name="pw"></form>',
      '<form action="/wp-login.php"><input type="hidden" name="log" value="a"></form>',
      '<input type="radio" name="plan" value="a">',
      '<select name="s"><option>a</option></select>',
      '<textarea name="t">x</textarea>',
    ];
    for (const j of judge(shapes)) {
      expect(j.clauses).toContain('D5');
    }
    expect(judge(['<button popovertarget="p">o</button><div id="p" popover>x</div>'])[0].clauses).toEqual([]);
  });

  // [input, sink, expected survivor text (null: a whole-prop loss renders nothing), refused at write]
  const corpus: [string, string, string | null | 'VERSIONED', boolean][] = [
    ['<p>keep</p><svg><style><img src=x onerror=alert(1)></style></svg>', 'rich', 'keep', true],
    ['<p>keep</p><math><mtext><table><mglyph><style><img src=x onerror=alert(1)>', 'rich', null, true],
    ['<p>keep</p><noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', 'rich', 'keep', true],
    ['<p>keep</p><template><img src=x onerror=alert(1)></template>', 'rich', 'keep', true],
    ['<p>keep</p><!--><img src=x onerror=alert(1)>-->', 'rich', 'keep', true],
    ['<p>keep</p><svg><![CDATA[><img src=x onerror=alert(1)>]]></svg>', 'rich', null, true],
    ['<p>keep</p><svg><title><img src=x onerror=alert(1)></title></svg>', 'rich', 'keep', true],
    // The runtime parser decides (ruling on T3a 7A question 3): WordPress 7.0 drops the img
    // inside <option> (P-16, whole prop), 7.1 builds it and E1 judges it (the render view
    // keeps "keep").
    ['<p>keep</p><select><option><img src=x onerror=alert(1)></option></select>', 'rich', 'VERSIONED', true],
    ['<p>keep</p><a href="java&#x09;script:alert(1)">x</a>', 'rich', 'keep', true],
    ['<p>keep</p><form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>', 'rich', null, true],
    ['<p>keep</p><svg><a xlink:href="javascript:alert(1)"><text>t</text></a><animate attributeName="href" to="javascript:alert(1)"/></svg>', 'rich', 'keep', true],
    ['<p>keep</p><svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg>', 'rich', 'keep', true],
    ['<p>keep</p><textarea></textarea><img src=x onerror=alert(1)>', 'rich', 'keep', true],
    ['<p>keep</p><svg><title><body onload=alert(1)></title></svg>', 'rich', 'keep', true],
    ['<p>keep</p></div><img src=x onerror=alert(1)>', 'rich', null, true],
    ['<p><a href="#">keep</p>', 'rich', null, true],
    ['<p>keep</p><form action="https://example.com/c">', 'rich', null, true],
    ['<b>keep</b></td><td onclick="alert(1)">x', 'rich_cell', null, true],
    ['<b>keep</b> <em>cell</em>', 'rich_cell', 'keep', false],
    ['<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs><path d="M0 0h24v24H0z" fill="url(#g)"/><text><tspan>keep</tspan></text></svg>', 'rich', 'keep', false],
    ['<form action="/s" method="post"><label for="e">keep</label><input id="e" type="email" name="email"><button>Go</button></form>', 'rich', 'keep', false],
    ['<picture><source srcset="/a.avif" type="image/avif"><img src="/a.jpg" alt="keep"></picture><p>keep</p>', 'rich', 'keep', false],
    ['<p style="font-family:&quot;Inter&quot;, sans-serif; color: rgb(0 0 0 / .5)">keep &amp; &lt;go&gt;</p>', 'rich', 'keep', false],
    ['<details><summary>keep</summary><p>a<p>b</details><ul><li>x<li>y</ul>', 'rich', 'keep', false],
  ];

  test('T-9: sanitize -> browser parse -> sanitize is a fixed point with no excluded construct', async ({ page }) => {
    // Each `wp eval` through wp-env costs seconds, so the PHP side is batched: one judge
    // call per sink per pass, three passes in all.
    test.setTimeout(240_000);
    const liveVersion = probe({ op: 'core_post' }).wp_version as string;
    for (const sink of ['rich', 'rich_cell']) {
      const entries = corpus.filter((c) => c[1] === sink);
      const first = judge(entries.map((c) => c[0]), sink);
      const inners: string[] = [];
      const kept: number[] = [];
      for (let i = 0; i < entries.length; i++) {
        const [input, , listed, refused] = entries[i];
        const survivor = listed === 'VERSIONED' ? (liveVersion === '7.0' ? null : 'keep') : listed;
        expect(first[i].clauses.length > 0, `the write gate ${refused ? 'refuses' : 'admits'} ${input}`).toBe(refused);
        const parsed = await parseInBrowser(page, first[i].html, sink);
        expect(parsed.bad, `excluded construct in the browser DOM for ${input}`).toEqual([]);
        expect(parsed.sentinelOk, `next band's sentinel intact for ${input}`).toBe(true);
        if (survivor === null) {
          expect(first[i].html, `fail-closed (whole-prop loss) for ${input}`).toBe('');
          continue;
        }
        expect(parsed.text, `expected survivor for ${input}`).toContain(survivor);
        inners.push(parsed.inner as string);
        kept.push(i);
      }
      const second = judge(inners, sink);
      for (let j = 0; j < kept.length; j++) {
        const input = entries[kept[j]][0];
        expect(second[j].clauses, `the browser's re-serialization re-judged clean for ${input}`).toEqual([]);
        const reparsed = await parseInBrowser(page, second[j].html, sink);
        expect(reparsed.inner, `fixed point for ${input}`).toBe(inners[j]);
      }
    }
  });
});
