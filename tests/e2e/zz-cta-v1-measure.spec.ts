import { test } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

/**
 * #1026 MEASUREMENT PROBE — NOT A PIN. Deleted before the PR.
 *
 * "RENDERED GEOMETRY IS THE AUTHORITY" (#1023's rebuild-class lesson). Every value the
 * cta rebuild carries into a role default has to be read out of a browser first, at all
 * three tiers, in every band configuration that can change it.
 *
 * WHY THERE IS NO INJECTION HERE, and why that does NOT mean the METHODOLOGY.md trap is
 * being ignored. That trap ("your injection can lose to the code you are measuring") bites
 * when you must RECONSTRUCT a rule the tree has already deleted. cta has not been rebuilt
 * yet, so on this commit HEAD *is* v1 — the measurement is a direct read, with nothing
 * injected that could lose. The guarded comparative probe is still owed for the A1
 * baseline half, where "after" does not exist yet; that one runs as a real before/after
 * across the change, and its guard is the diff itself.
 */

const OUT = process.env.PP_MEASURE_OUT || '/tmp/cta-v1-measure.json';

const TIERS = [
  { name: 'p', width: 375, height: 900 },
  { name: 't', width: 768, height: 1000 },
  { name: 'd', width: 1280, height: 1000 },
];

const LONG =
  'Ship the whole operating model in one reviewable change instead of ten unreviewable ones, ' +
  'and keep the handoff your team can still maintain afterwards without a rewrite';

function ctaBand(extra: Record<string, unknown>) {
  return {
    component: 'cta',
    props: {
      eyebrow: 'Operating model',
      title: 'Protect the margin after the first AI draft',
      title_accent: 'margin',
      body: 'Use PromptingPress when client pages need <a href="/x">speed</a> and structure.',
      button_text: 'See the operating model',
      button_url: '/model',
      ...extra,
    },
  };
}

const SCENES: Record<string, Record<string, unknown>> = {
  'fullwidth-default': {},
  'fullwidth-inverted': { theme: 'inverted' },
  'fullwidth-dark': { theme: 'dark' },
  'fullwidth-muted': { theme: 'muted' },
  'fullwidth-bgimage': { background_image: 'https://example.com/bg.jpg' },
  'fullwidth-inverted-bgimage': { theme: 'inverted', background_image: 'https://example.com/bg.jpg' },
  'inline-default': { layout: 'inline' },
  'inline-inverted': { layout: 'inline', theme: 'inverted' },
  'fullwidth-button2': { button2_text: 'Read the docs', button2_url: '/docs' },
  'fullwidth-button2-primary': {
    button2_text: 'Read the docs',
    button2_url: '/docs',
    button2_variant: 'primary',
  },
  'fullwidth-variants-outline': { button_variant: 'outline' },
  'fullwidth-variants-ghost': { button_variant: 'ghost' },
  'fullwidth-variants-secondary': { button_variant: 'secondary' },
  'fullwidth-stressed': { title: LONG, body: LONG + ' ' + LONG, button2_text: 'A second rather long button label', button2_url: '/docs' },
  'fullwidth-buttononly': { eyebrow: '', title: '', title_accent: '', body: '' },
};

const PROBES: Array<{ sel: string; props: string[] }> = [
  {
    sel: '.cta',
    props: [
      'padding-top', 'padding-bottom', 'background-color', 'background-image',
      'background-position', 'background-size', 'background-repeat',
      'border-top-width', 'border-top-style', 'border-top-color',
      'border-bottom-width', 'border-bottom-style', 'border-bottom-color',
      'border-left-width', 'border-left-style', 'border-right-width',
      'border-radius', 'box-shadow', 'position',
    ],
  },
  { sel: '.cta__overlay', props: ['background-color', 'background-image', 'position', 'inset'] },
  { sel: '.cta__inner', props: ['display', 'flex-direction', 'gap', 'align-items', 'justify-content', 'text-align'] },
  { sel: '.cta__text', props: ['max-width', 'margin-left', 'margin-right', 'width'] },
  {
    sel: '.cta__eyebrow',
    props: [
      'display', 'font-size', 'font-weight', 'letter-spacing', 'text-transform',
      'color', 'background-color', 'padding-top', 'padding-right', 'padding-bottom',
      'padding-left', 'margin-bottom', 'border-top-width', 'border-top-style',
      'border-top-color', 'border-radius', 'width',
    ],
  },
  { sel: '.cta__title', props: ['font-size', 'color', 'max-width', 'margin-bottom', 'line-height', 'font-weight', 'letter-spacing', 'width'] },
  { sel: '.cta__title-accent', props: ['color'] },
  { sel: '.cta__body', props: ['color', 'font-size', 'max-width', 'margin-bottom', 'line-height', 'font-weight', 'opacity', 'width'] },
  { sel: '.cta__body a', props: ['color', 'text-decoration-line', 'text-decoration-color'] },
  { sel: '.cta__buttons', props: ['display', 'gap', 'justify-content', 'flex-wrap', 'align-items'] },
  {
    sel: '.cta__button',
    props: [
      'background-color', 'background-image', 'color',
      'border-top-width', 'border-top-style', 'border-top-color', 'border-radius',
      'box-shadow', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
      'min-height', 'font-size', 'font-weight', 'line-height', 'text-decoration-line',
      'transition-duration', 'transition-timing-function', 'transition-property',
      'flex-shrink', 'width', 'outline-color',
    ],
  },
  {
    sel: '.cta__button--secondary',
    props: [
      'background-color', 'background-image', 'color',
      'border-top-width', 'border-top-style', 'border-top-color', 'border-radius',
      'box-shadow', 'padding-top', 'padding-bottom', 'min-height', 'font-size',
      'font-weight', 'line-height', 'transition-duration', 'transition-property', 'width',
    ],
  },
];

/** Hover surfaces read under a REAL pointer, per the paint-first lesson. */
const HOVER_PROBES = ['.cta__button', '.cta__button--secondary', '.cta__body a'];
const HOVER_PROPS = ['background-color', 'background-image', 'color', 'border-top-color', 'box-shadow'];

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, { cwd: process.cwd() });
  return id;
}

function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp post meta update ${postId} _pp_composition '${json}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

test('#1026 measure what v1 cta renders, at three tiers, in every configuration', async ({ page }) => {
  test.setTimeout(20 * 60 * 1000);
  const results: Record<string, any> = {};
  const sceneNames = Object.keys(SCENES);
  const pageIds: Record<string, number> = {};

  for (const scene of sceneNames) {
    const id = createPage(`pp1026-${scene}`);
    setComposition(id, [ctaBand(SCENES[scene])]);
    pageIds[scene] = id;
  }

  try {
    for (const tier of TIERS) {
      await page.setViewportSize({ width: tier.width, height: tier.height });
      for (const scene of sceneNames) {
        await page.goto(`/?page_id=${pageIds[scene]}`, { waitUntil: 'networkidle' });
        const read = await page.evaluate(
          (probes) =>
            probes.map((p) => {
              const el = document.querySelector(p.sel);
              if (!el) return { sel: p.sel, missing: true };
              const cs = getComputedStyle(el);
              const box = el.getBoundingClientRect();
              const out: Record<string, string> = {};
              for (const prop of p.props) out[prop] = cs.getPropertyValue(prop);
              return {
                sel: p.sel,
                cls: (el as HTMLElement).className,
                rect: { w: Math.round(box.width * 100) / 100, h: Math.round(box.height * 100) / 100, x: Math.round(box.x * 100) / 100 },
                css: out,
              };
            }),
          PROBES,
        );

        // Real pointer hover, one surface at a time.
        const hover: Record<string, any> = {};
        for (const sel of HOVER_PROBES) {
          const loc = page.locator(sel).first();
          if ((await loc.count()) === 0) continue;
          try {
            await loc.hover({ timeout: 3000 });
            await page.waitForTimeout(400); // let any transition settle
            hover[sel] = await loc.evaluate((el, props) => {
              const cs = getComputedStyle(el);
              const out: Record<string, string> = {};
              for (const p of props) out[p] = cs.getPropertyValue(p);
              return out;
            }, HOVER_PROPS);
          } catch {
            hover[sel] = { unhoverable: true };
          }
          await page.mouse.move(0, 0);
          await page.waitForTimeout(200);
        }

        // Keyboard focus on the primary button, for the focus-ring read.
        let focus: Record<string, string> | null = null;
        const btn = page.locator('.cta__button').first();
        if ((await btn.count()) > 0) {
          await btn.focus();
          await page.waitForTimeout(200);
          focus = await btn.evaluate((el) => {
            const cs = getComputedStyle(el);
            return {
              'outline-color': cs.outlineColor,
              'outline-width': cs.outlineWidth,
              'outline-style': cs.outlineStyle,
              'outline-offset': cs.outlineOffset,
            };
          });
        }

        // Band-level facts a role default cannot see from CSS alone.
        const meta = await page.evaluate(() => {
          const band = document.querySelector('.cta') as HTMLElement | null;
          const doc = document.documentElement;
          return {
            styleAttr: band ? band.getAttribute('style') : null,
            bandAttr: band ? band.getAttribute('data-pp-band') : null,
            overlayAttr: band ? band.hasAttribute('data-pp-band-overlay') : null,
            classes: band ? band.className : null,
            hasOverlayEl: !!document.querySelector('.cta__overlay'),
            horizontalOverflow: doc.scrollWidth > doc.clientWidth,
            scrollWidth: doc.scrollWidth,
            clientWidth: doc.clientWidth,
          };
        });

        results[`${scene}@${tier.name}`] = { read, hover, focus, meta };
      }
    }
  } finally {
    for (const scene of sceneNames) {
      execSync(`npx wp-env run cli wp post delete ${pageIds[scene]} --force`, { cwd: process.cwd() });
    }
  }

  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(results, null, 1));
  console.log(`WROTE ${OUT} (${Object.keys(results).length} scene/tier reads)`);
});
