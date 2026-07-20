/**
 * CSS Lint Regression Guards
 *
 * Scans theme CSS files for patterns that should never appear:
 * - nth-of-type / nth-child positional selectors
 * - Modern CSS features with poor browser support
 * - Raw hex color values in components.css (must use tokens)
 */

const fs = require('fs');
const path = require('path');

const COMPONENTS_CSS = fs.readFileSync(
    path.resolve(__dirname, '../../assets/css/components.css'),
    'utf-8'
);

const BASE_CSS = fs.readFileSync(
    path.resolve(__dirname, '../../assets/css/base.css'),
    'utf-8'
);

const UTILITIES_CSS = fs.readFileSync(
    path.resolve(__dirname, '../../assets/css/utilities.css'),
    'utf-8'
);

// Strip CSS comments for cleaner matching.
function stripComments(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

// Declarations that can restore an eyebrow band even with the alignment intact: an
// explicit width/min-width, a flex shorthand that sets a basis, or place-self/align-self
// overriding the cross-axis. Guarding the alignment alone is not enough — `width: 100%`
// in a media block reintroduces the band with every alignment pin still green.
//
// Shared by the #225 (hero, flex) and #255 (cta, grid) guards: the ways a pill can be
// re-stretched do not depend on which layout mode promoted it.
const BAND_RESTORING = /(?:^|[;{\s])(?:min-)?width\s*:|(?:^|[;\s])flex\s*:|place-self\s*:|align-self\s*:/;

// Every rule targeting `needle` as a whole class, in source order, each tagged with
// the @media condition wrapping it (null at top level). Media context is part of the
// identity of a rule: `.hero__eyebrow` inside `@media (max-width: 767px)` is a
// DIFFERENT rule from the base one, and a stretch declared there would restore the
// band at mobile while a media-blind scan reported green. components.css already
// redeclares `.hero__content` inside a max-width block, so this is a live pattern.
//
// Shared by the #225 (hero) and #255 (cta) eyebrow guards below — both need to reason
// about "every rule touching this class, in every media context", and a second copy of
// this parser would be a place for the two to silently drift apart.
function rulesMatching(needle) {
    const css = stripComments(COMPONENTS_CSS);
    // Class-boundary match, so `.hero__eyebrow` never swallows `.hero__eyebrow--lg`.
    const boundary = new RegExp(
        needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![\\w-])'
    );
    const rules = [];
    // A stack, not a depth counter: components.css already nests at-rules (@keyframes
    // at :909), and any nested block — @supports, @keyframes, an inner @media — emits
    // a closing brace of its own. A counter mistakes that brace for the media block's
    // and reports every following rule as top-level, which is exactly how a
    // mobile-scoped regression would hide from a media-aware-looking scan.
    const pattern = /@([\w-]+)([^{;]*)\{|([^{}]+)\{([^{}]*)\}|\}|;/g;
    const stack = [];
    let match;
    while ((match = pattern.exec(css)) !== null) {
        if (match[1] !== undefined) {
            stack.push(match[1] === 'media' ? match[2].trim() : null);
        } else if (match[3] !== undefined) {
            const selectors = match[3].split(',').map(s => s.trim().replace(/\s+/g, ' '));
            if (selectors.some(s => boundary.test(s))) {
                // Nearest enclosing @media, looking outward past non-media at-rules.
                const media = [...stack].reverse().find(m => m !== null) ?? null;
                rules.push({ selectors, body: match[4], media });
            }
        } else if (match[0] === '}') {
            stack.pop();
        }
    }
    return rules;
}

describe('CSS lint: positional selectors', () => {
    test('components.css has no nth-of-type selectors', () => {
        const matches = stripComments(COMPONENTS_CSS).match(/nth-of-type/g);
        expect(matches).toBeNull();
    });

    test('components.css has no nth-child selectors', () => {
        const matches = stripComments(COMPONENTS_CSS).match(/nth-child/g);
        expect(matches).toBeNull();
    });
});

// Issue 355: the active/current header link must route its COLOR through
// --header-link-color (falling back to --color-accent) instead of hard-coding the
// accent, so an operator's pp_header_link_color reaches the active link too. The
// bold weight (the emphasis) must stay. The e2e render pin proves the current-menu-item
// path in a real browser; this static pin also covers the aria-current declaration
// (which WP sets on the same element, so the render pin can't isolate it) and guards
// against a regression back to the bare `color: var(--color-accent)`.
describe('CSS lint: #355 active header link honors --header-link-color', () => {
    const css = stripComments(COMPONENTS_CSS);
    const ACTIVE_COLOR = 'color: var(--header-link-color, var(--color-accent))';
    const BARE_ACCENT = /color:\s*var\(--color-accent\)\s*;/;

    test('current-menu-item / current_page_item link routes color through --header-link-color, keeping bold weight', () => {
        const rule = css.match(
            /\.nav__menu ul li\.current-menu-item > a,\s*\.nav__menu ul li\.current_page_item > a\s*\{([^}]*)\}/,
        );
        expect(rule).not.toBeNull();
        expect(rule[1]).toContain('font-weight: 700');
        expect(rule[1]).toContain(ACTIVE_COLOR);
        expect(rule[1]).not.toMatch(BARE_ACCENT);
    });

    test('aria-current="page" link routes color through --header-link-color, keeping bold weight', () => {
        const rule = css.match(/\.nav__menu ul li a\[aria-current="page"\]\s*\{([^}]*)\}/);
        expect(rule).not.toBeNull();
        expect(rule[1]).toContain('font-weight: 700');
        expect(rule[1]).toContain(ACTIVE_COLOR);
        expect(rule[1]).not.toMatch(BARE_ACCENT);
    });
});

/**
 * Mobile nav menu is an out-of-flow PANEL, not a squeezed flex item (#426).
 *
 * The shipped bug: `.nav__menu` (width:100%) was a THIRD item in the header's
 * nowrap flex row on mobile, so opening it crushed the menu into a ~94px column
 * at the right edge and grew the sticky header 65px -> 229px. The fix takes the
 * menu OUT of flex flow at mobile (position:absolute below the header row), so the
 * logo/toggle row is byte-identical open vs closed. This pin locks that MECHANISM:
 * a refactor that drops `position: absolute` from the mobile `.nav__menu` rule (or
 * moves it back into the flow) must fail here, not just in a nightly E2E. It also
 * pins the panel background to the --header-bg chrome slot (so a themed header
 * carries into the panel) and the aria-expanded-driven icon swap (the close
 * affordance). The layout rule lives in a max-width:767px block, so the media
 * context is part of its identity (a desktop-scoped copy would not satisfy this).
 */
describe('CSS lint: mobile nav menu is an out-of-flow panel (#426)', () => {
    // Body of the FIRST `.nav__menu { ... }` rule declared inside a
    // `@media (max-width: 767px)` block, brace-matched so nested rules don't
    // truncate it. Returns null when no such rule exists (a vacuous-pass guard).
    function mobileNavMenuBody(css) {
        const stripped = stripComments(css);
        const opener = /@media\s*\(max-width:\s*767px\)\s*\{/g;
        let m;
        while ((m = opener.exec(stripped)) !== null) {
            // Brace-match the media block.
            let depth = 1;
            let i = opener.lastIndex;
            while (i < stripped.length && depth > 0) {
                if (stripped[i] === '{') depth++;
                else if (stripped[i] === '}') depth--;
                i++;
            }
            const block = stripped.slice(opener.lastIndex, i - 1);
            // `.nav__menu {` as a whole-class rule (not `.nav__menu ul`, `.nav__menu[...]`).
            const rule = /(?:^|[}{])\s*\.nav__menu\s*\{([^}]*)\}/.exec(block);
            if (rule) return rule[1];
        }
        return null;
    }

    const body = mobileNavMenuBody(COMPONENTS_CSS);

    test('a mobile-scoped `.nav__menu` rule exists (guards against a vacuous pass)', () => {
        expect(body).not.toBeNull();
    });

    test('the mobile `.nav__menu` is taken out of flex flow (position: absolute)', () => {
        expect(body).toMatch(/position\s*:\s*absolute/);
    });

    test('the panel background routes the --header-bg chrome slot', () => {
        expect(body).toMatch(/background\s*:\s*var\(\s*--header-bg\b/);
    });

    // Detection proof: the mechanism pin must CATCH an in-flow regression and PASS
    // the out-of-flow panel — so a parser drift can't make the scan vacuous.
    test('detector flags an in-flow mobile menu but passes an absolute panel', () => {
        const inFlow =
            '@media (max-width: 767px) { .nav__menu { width: 100%; } }';
        const panel =
            '@media (max-width: 767px) { .nav__menu { position: absolute; top: 100%; ' +
            'background: var(--header-bg, var(--color-bg)); } }';
        expect(/position\s*:\s*absolute/.test(mobileNavMenuBody(inFlow) || '')).toBe(false);
        expect(/position\s*:\s*absolute/.test(mobileNavMenuBody(panel) || '')).toBe(true);
    });

    // The open-state affordance (#426): the same toggle button swaps hamburger <-> X,
    // driven purely off its aria-expanded. Pin all three rules so a refactor can't
    // silently drop the close icon (leaving the "no way to close" symptom).
    describe('toggle icon swaps on aria-expanded', () => {
        const stripped = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');

        function ruleBody(selector) {
            const re = new RegExp(
                '(?:^|[}{])\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}'
            );
            const m = re.exec(stripped);
            return m ? m[1] : null;
        }

        test('the close icon is hidden by default', () => {
            const b = ruleBody('.nav__toggle-icon--close');
            expect(b).not.toBeNull();
            expect(b).toMatch(/display\s*:\s*none/);
        });

        test('an open toggle hides the hamburger and shows the close icon', () => {
            const openHidden = ruleBody('.nav__toggle[aria-expanded="true"] .nav__toggle-icon--open');
            const closeShown = ruleBody('.nav__toggle[aria-expanded="true"] .nav__toggle-icon--close');
            expect(openHidden).not.toBeNull();
            expect(openHidden).toMatch(/display\s*:\s*none/);
            expect(closeShown).not.toBeNull();
            expect(closeShown).toMatch(/display\s*:\s*(flex|block|inline-flex)/);
        });
    });
});

/**
 * Footer baseline layout + #382 landing slot (#427).
 *
 * The footer went from three loosely floating flex blocks to a deliberate column
 * grid: `.site-footer__columns` is a grid that, at desktop (>=1024px), uses
 * `grid-auto-flow: column` + `grid-auto-columns: minmax(0, 1fr)` so it makes
 * exactly one equal top-aligned track per PRESENT column (a sparse footer degrades
 * without phantom empty tracks). These static pins lock that MECHANISM so a refactor
 * that drops the grid (or the auto-flow-column degradation) fails here, not just in a
 * nightly E2E. They also pin the reserved `.site-footer__social` landing slot for the
 * #382 social-icon row (built later, into this designed home) and the actionable
 * `<address>` contact styling (italic reset + link color routed through the chrome slot).
 */
describe('CSS lint: footer column grid + #382 landing slot (#427)', () => {
  const stripped = stripComments(COMPONENTS_CSS);

  function ruleBody(selector) {
    const re = new RegExp(
      '(?:^|[}{])\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}',
    );
    const m = re.exec(stripped);
    return m ? m[1] : null;
  }

  // The `.site-footer__columns` rule declared inside a `@media (min-width: 1024px)`
  // block, brace-matched. Returns null when no such rule exists (vacuous-pass guard).
  function desktopColumnsBody() {
    const opener = /@media\s*\(min-width:\s*1024px\)\s*\{/g;
    let m;
    while ((m = opener.exec(stripped)) !== null) {
      let depth = 1;
      let i = opener.lastIndex;
      while (i < stripped.length && depth > 0) {
        if (stripped[i] === '{') depth++;
        else if (stripped[i] === '}') depth--;
        i++;
      }
      const block = stripped.slice(opener.lastIndex, i - 1);
      const rule = /(?:^|[}{])\s*\.site-footer__columns\s*\{([^}]*)\}/.exec(block);
      if (rule) return rule[1];
    }
    return null;
  }

  test('.site-footer__columns is a grid (the stack/column mechanism)', () => {
    const body = ruleBody('.site-footer__columns');
    expect(body).not.toBeNull();
    expect(body).toMatch(/display\s*:\s*grid/);
  });

  test('desktop columns use grid-auto-flow: column with equal minmax(0,1fr) tracks, tops aligned', () => {
    const body = desktopColumnsBody();
    expect(body).not.toBeNull();
    expect(body).toMatch(/grid-auto-flow\s*:\s*column/);
    expect(body).toMatch(/grid-auto-columns\s*:\s*minmax\(0,\s*1fr\)/);
    expect(body).toMatch(/align-items\s*:\s*start/);
  });

  test('the #382 social landing slot exists and is a flex row', () => {
    const body = ruleBody('.site-footer__social');
    expect(body).not.toBeNull();
    expect(body).toMatch(/display\s*:\s*flex/);
  });

  test('the contact <address> resets italic and routes --footer-link-color (no slot-defeating literal)', () => {
    const addr = ruleBody('.site-footer__address');
    expect(addr).not.toBeNull();
    expect(addr).toMatch(/font-style\s*:\s*normal/);
    const link = ruleBody('.site-footer__address a');
    expect(link).not.toBeNull();
    expect(link).toMatch(/color\s*:\s*var\(--footer-link-color,\s*var\(--color-muted\)\)/);
  });
});

describe('CSS lint: no modern CSS features', () => {
    const MODERN_FEATURES = [
        // color-mix() intentionally allowed — used for token-adaptive button shadows/focus rings.
        { name: 'backdrop-filter', pattern: /backdrop-filter\s*:/ },
        { name: 'mask-image', pattern: /mask-image\s*:/ },
        { name: ':has()', pattern: /:has\s*\(/ },
        { name: '@container', pattern: /@container\b/ },
    ];

    const allCss = stripComments(COMPONENTS_CSS + '\n' + BASE_CSS);

    MODERN_FEATURES.forEach(({ name, pattern }) => {
        test(`theme CSS does not use ${name}`, () => {
            expect(allCss).not.toMatch(pattern);
        });
    });
});

describe('CSS lint: style slot fallback patterns', () => {
    const SCHEMA_COMPONENTS = ['hero', 'section', 'grid', 'cta'];
    const stripped = stripComments(COMPONENTS_CSS);

    // Load all style slots from schema.json files.
    const allSlots = [];
    SCHEMA_COMPONENTS.forEach(component => {
        const schemaPath = path.resolve(__dirname, `../../components/${component}/schema.json`);
        const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf-8'));
        const slots = schema.styling?.style_slots || {};
        Object.keys(slots).forEach(slotName => {
            allSlots.push({ component, slotName });
        });
    });

    test('hero/section/grid/cta schemas declare 144 style slots (subset of the total)', () => {
        expect(allSlots.length).toBe(144);
    });

    allSlots.forEach(({ component, slotName }) => {
        test(`${slotName} has var(${slotName}, ...) fallback in components.css`, () => {
            // Look for var(--slot-name, at least once in the CSS.
            // The slot name appears inside a var() with a comma (indicating a fallback).
            const pattern = new RegExp(`var\\(${slotName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')},`);
            expect(stripped).toMatch(pattern);
        });
    });
});

describe('CSS lint: secondary/ghost buttons never get a filled gradient', () => {
    // Regression guard for the secondary-CTA contrast bug: the "premium CTA"
    // and "elevation correction" cascade blocks apply a gradient background to
    // `main .btn`. Because `main` is a type selector, `main .btn` and
    // `main .btn--outline` have IDENTICAL specificity (0,1,1), so a later
    // bare-`main .btn` gradient rule re-fills the transparent outline/ghost
    // variants by source order — orange text on an orange fill, ~1.3:1.
    // Any rule that sets a gradient background and matches a bare `main .btn`
    // MUST exclude the transparent variants.
    test('no bare `main .btn` gradient rule catches outline/ghost/secondary', () => {
        const css = stripComments(COMPONENTS_CSS);
        // Match innermost rules: selectors { body-without-braces }.
        const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
        const offenders = [];
        let m;
        while ((m = ruleRe.exec(css)) !== null) {
            const selector = m[1];
            const body = m[2];
            const setsGradient = /background(-image)?\s*:\s*[^;]*gradient/i.test(body);
            if (!setsGradient) continue;
            // Does any selector in the list target a bare `main .btn` (not the
            // outline/ghost/secondary variant, and without a :not() exclusion)?
            const catchesBareMainBtn = selector.split(',').some(sel => {
                const s = sel.trim();
                if (!/(^|\s)main\s+\.btn(\b|:|$)/.test(s)) return false;
                if (/\.btn--(outline|ghost|secondary)/.test(s)) return false; // targets a variant explicitly
                if (/:not\(\.btn--(outline|ghost|secondary)\)/.test(s)) return false; // excludes them
                return true;
            });
            if (catchesBareMainBtn) offenders.push(selector.trim().split('\n')[0].slice(0, 80));
        }
        expect(offenders).toEqual([]);
    });
});

/**
 * Premium primary-button fill can be flattened via the documented slot (#412).
 *
 * The premium filled treatment paints the primary button with a `background:`
 * SHORTHAND carrying a gradient background-IMAGE layer. That layer sits above the
 * `background-color` the cta block routes through --cta-button-bg, so a bare
 * shorthand here silently re-kills the slot (Symptom 1: --cta-button-bg does nothing
 * on the default variant) — the #226/#302 dead-slot class, evading the same-property
 * #305 guard through a DIFFERENT cascade layer. This guard extends the slot contract
 * to that layer-defeat: every rule targeting the primary-button surface
 * (`main .btn:not(...)`, the composed winner) that sets `background`/`background-image`
 * MUST route through var(--cta-button-bg / --cta-button-hover-bg), so a future
 * shorthand cannot re-defeat the flat-button slot.
 */
describe('CSS lint: premium primary-button fill routes through --cta-button-bg (#412)', () => {
    const css = stripComments(COMPONENTS_CSS);
    // Innermost rules: `selectors { body-without-braces }`.
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;

    // A rule targets the composed primary-button FILL surface when a selector is
    // `main .btn:not(.btn--outline)...` — the filled treatment that excludes the
    // transparent variants. `.btn--outline` appears here only INSIDE the `:not()`, so
    // key on that exact shape rather than a bare `.btn--outline` substring (which would
    // wrongly reject the very selector we want, the trap the #305-era gradient guard hit).
    function targetsPrimaryFill(selector) {
        return selector.split(',').some(sel =>
            /(^|\s)main\s+\.btn:not\(\.btn--outline\)/.test(sel.trim())
        );
    }

    // background declarations (shorthand OR background-image), excluding background-color.
    function fillDecls(body) {
        return (body.match(/(?<![-a-z])background(?:-image)?\s*:[^;}]+/gi) || [])
            .map(d => d.trim());
    }

    const surfaceRules = [];
    let m;
    while ((m = ruleRe.exec(css)) !== null) {
        if (!targetsPrimaryFill(m[1])) continue;
        const decls = fillDecls(m[2]);
        if (decls.length) surfaceRules.push({ selector: m[1].replace(/\s+/g, ' ').trim(), body: m[2], decls });
    }

    // Guard against a vacuous pass: the filled treatment (rest + hover) is at least two rules.
    test('finds the premium primary-fill rules that set a background', () => {
        expect(surfaceRules.length).toBeGreaterThanOrEqual(2);
    });

    test('every primary-fill background routes through --cta-button-bg / --cta-button-hover-bg', () => {
        const offenders = [];
        surfaceRules.forEach(r => {
            const isHover = /:hover\b/.test(r.selector);
            const slot = isHover ? '--cta-button-hover-bg' : '--cta-button-bg';
            r.decls.forEach(d => {
                if (!new RegExp('background(?:-image)?\\s*:\\s*var\\(\\s*' + slot + '\\b').test(d)) {
                    offenders.push(`${r.selector} { ${d} }`);
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detection proof: a bare gradient shorthand on the surface must be CAUGHT, and a
    // slot-routed one must PASS — so a parser regression can't make the scan vacuous.
    test('detector flags a bare gradient shorthand but passes a slot-routed one', () => {
        const bad = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background: linear-gradient(red, blue); }';
        const good = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background: var(--cta-button-bg, linear-gradient(red, blue)); }';
        const scan = (fixture) => {
            const rr = /([^{}]+)\{([^{}]*)\}/g;
            let mm, out = [];
            while ((mm = rr.exec(fixture)) !== null) {
                if (!targetsPrimaryFill(mm[1])) continue;
                fillDecls(mm[2]).forEach(d => {
                    if (!/background(?:-image)?\s*:\s*var\(\s*--cta-button-bg\b/.test(d)) out.push(d);
                });
            }
            return out;
        };
        expect(scan(bad).length).toBe(1);
        expect(scan(good).length).toBe(0);
    });
});

/**
 * Primary-button FILL also honors the slot through the background-COLOR longhand (#420).
 *
 * The #412 guard above covers the `background`/`background-image` layer-defeat but
 * EXCLUDES `background-color` (it targets the premium `main .btn` gradient shorthand).
 * A different, HIGHER-specificity rule — `.cta .btn:not(...)` [0,5,0] — sets the fill via
 * the `background-color` LONGHAND and outranks BOTH the slot block `.cta__button:not(...)`
 * [0,4,0] and the premium winner `main .btn:not(...)` [0,4,1]. A bare accent there silently
 * re-killed --cta-button-bg for the composed primary button (the #412 trust class survived
 * because that layer routes `background`, not `background-color`). This guard closes the
 * property gap: every CTA-context primary-fill rule that sets `background-color` MUST route
 * through --cta-button-bg (rest) / --cta-button-hover-bg (hover).
 *
 * Scope note: `.hero .btn:not(...)` sets `background-color` too but routes the SEPARATE
 * --hero-accent slot (hero is its own component context), so it is deliberately NOT matched.
 */
describe('CSS lint: primary-button background-color routes through --cta-button-bg (#420)', () => {
    const css = stripComments(COMPONENTS_CSS);
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;

    // CTA-context composed-primary-fill surfaces that route through --cta-button-bg.
    // `.hero .btn` routes --hero-accent (a different slot) and must NOT match.
    // The `main .btn:not(...)` premium winner sets `background` (shorthand), never the
    // `background-color` longhand, so it contributes ZERO to the bgColorDecls scan today
    // (the #412 guard owns that layer). It is kept in the matcher as a forward-guard: if a
    // future edit adds a `background-color` longhand to that winner, this guard requires it
    // to route the slot too — the property gap #420 slipped through must not reopen.
    function targetsCtaPrimaryFill(selector) {
        return selector.split(',').some(sel => {
            const s = sel.trim();
            return /(^|\s)\.cta\s+\.btn:not\(\.btn--outline\)/.test(s)
                || /(^|\s)\.cta__button:not\(\.btn--outline\)/.test(s)
                || /(^|\s)main\s+\.btn:not\(\.btn--outline\)/.test(s);
        });
    }

    // background-COLOR longhand only (the gap the #412 scanner leaves open).
    function bgColorDecls(body) {
        return (body.match(/(?<![-a-z])background-color\s*:[^;}]+/gi) || []).map(d => d.trim());
    }

    const surfaceRules = [];
    let m;
    while ((m = ruleRe.exec(css)) !== null) {
        if (!targetsCtaPrimaryFill(m[1])) continue;
        const decls = bgColorDecls(m[2]);
        if (decls.length) surfaceRules.push({ selector: m[1].replace(/\s+/g, ' ').trim(), decls });
    }

    // Guard against a vacuous pass: rest + hover for BOTH `.cta .btn` and `.cta__button`.
    test('finds the CTA primary-fill rules that set a background-color', () => {
        expect(surfaceRules.length).toBeGreaterThanOrEqual(4);
    });

    test('every CTA primary-fill background-color routes through --cta-button-bg / --cta-button-hover-bg', () => {
        const offenders = [];
        surfaceRules.forEach(r => {
            const isHover = /:hover\b/.test(r.selector);
            const slot = isHover ? '--cta-button-hover-bg' : '--cta-button-bg';
            r.decls.forEach(d => {
                if (!new RegExp('background-color\\s*:\\s*var\\(\\s*' + slot + '\\b').test(d)) {
                    offenders.push(`${r.selector} { ${d} }`);
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detection proof: a bare accent background-color on the surface must be CAUGHT, a
    // slot-routed one must PASS, and a `.hero .btn` accent must be IGNORED (different slot).
    test('detector flags a bare accent background-color, passes a slot-routed one, ignores hero', () => {
        const bad = '.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background-color: var(--cta-accent, blue); }';
        const good = '.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background-color: var(--cta-button-bg, var(--cta-accent, blue)); }';
        const hero = '.hero .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background-color: var(--hero-accent, blue); }';
        const scan = (fixture) => {
            const rr = /([^{}]+)\{([^{}]*)\}/g;
            let mm, out = [];
            while ((mm = rr.exec(fixture)) !== null) {
                if (!targetsCtaPrimaryFill(mm[1])) continue;
                bgColorDecls(mm[2]).forEach(d => {
                    if (!/background-color\s*:\s*var\(\s*--cta-button-bg\b/.test(d)) out.push(d);
                });
            }
            return out;
        };
        expect(scan(bad).length).toBe(1);
        expect(scan(good).length).toBe(0);
        expect(scan(hero).length).toBe(0); // hero never enters the scan
    });

    // Behavioral pin BOTH ways on the exact declarations: slot set → var() resolves the
    // slot (slot wins); slot unset → the byte-identical prior accent fallback chain.
    // Border honors --cta-button-border / --cta-button-hover-border when set (the answered
    // #420 review decision, Option B), then FOLLOWS the fill slot, then the accent chain —
    // so a flat button (only --cta-button-bg set) keeps no accent ring, while an explicit
    // border slot is respected on the composed primary button.
    function bodyOf(selectorNeedle) {
        const rr = /([^{}]+)\{([^{}]*)\}/g;
        let mm;
        while ((mm = rr.exec(css)) !== null) {
            if (mm[1].replace(/\s+/g, ' ').trim() === selectorNeedle) return mm[2];
        }
        return null;
    }
    const REST_SEL = '.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)';
    const HOVER_SEL = '.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover';

    test('rest rule: fill routes through --cta-button-bg then the global --btn-bg, border through --cta-button-border / --btn-border-color then the fill, accent chain as fallback', () => {
        const body = bodyOf(REST_SEL);
        expect(body).not.toBeNull();
        // #458: the global --btn-bg / --btn-border-color knobs sit between the per-component
        // slots and the --color-accent literal. Border still FOLLOWS the fill chain (so a flat
        // --cta-button-bg OR a global --btn-bg keeps no accent ring, issue 420 preserved).
        expect(body).toMatch(/background-color:\s*var\(--cta-button-bg,\s*var\(--cta-accent,\s*var\(--btn-bg,\s*var\(--color-accent\)\)\)\)/);
        expect(body).toMatch(/border-color:\s*var\(--cta-button-border,\s*var\(--btn-border-color,\s*var\(--cta-button-bg,\s*var\(--cta-accent,\s*var\(--btn-bg,\s*var\(--color-accent\)\)\)\)\)\)/);
    });

    test('hover rule: fill routes through --cta-button-hover-bg, border through --cta-button-hover-border then the fill, accent-hover chain as fallback', () => {
        const body = bodyOf(HOVER_SEL);
        expect(body).not.toBeNull();
        expect(body).toMatch(/background-color:\s*var\(--cta-button-hover-bg,\s*var\(--cta-accent-hover,\s*var\(--color-accent-hover\)\)\)/);
        expect(body).toMatch(/border-color:\s*var\(--cta-button-hover-border,\s*var\(--cta-button-hover-bg,\s*var\(--cta-accent-hover,\s*var\(--color-accent-hover\)\)\)\)/);
    });
});

describe('CSS lint: grid--steps only declared inside the COMPONENT: grid block (#56)', () => {
    // Regression guard: before #56, `.grid--steps .grid__item` and
    // `.grid--steps .pp-step-number` were each declared a SECOND time,
    // scattered elsewhere in the file as undocumented, unscoped "rescue"
    // overrides with raw rgba magic-number colors — one of which set
    // `overflow: hidden` and silently clipped the arrow connector. The
    // canonical block stayed weak while real page defaults quietly diverged
    // from it. Every declaration of these selectors must live inside the
    // COMPONENT: grid block (responsive variants of the SAME rule, e.g. a
    // max-width media query tweak, are fine) — none may leak outside it.
    const stripped = stripComments(COMPONENTS_CSS);
    // Locate the block against the RAW css — the "COMPONENT: grid" marker
    // lives inside a comment, so it would vanish if matched post-strip.
    const blockMatch = COMPONENTS_CSS.match(/COMPONENT:\s*grid\b([\s\S]*?)(?=\/\*\s*={5,}[\s\S]*?COMPONENT:|$)/);
    const gridBlock = stripComments(blockMatch ? blockMatch[1] : '');

    test.each(['.grid--steps .grid__item', '.grid--steps .pp-step-number'])(
        '%s is never declared outside the COMPONENT: grid block',
        (selector) => {
            const pattern = new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{', 'g');
            const totalCount = (stripped.match(pattern) || []).length;
            const inBlockCount = (gridBlock.match(pattern) || []).length;
            expect(inBlockCount).toBeGreaterThan(0);
            expect(totalCount).toBe(inBlockCount);
        }
    );
});

describe('CSS lint: theme variants survive the desktop typography cascade (#222)', () => {
    // Regression guard for the inverted dark-on-dark bug. The "Premium body-section
    // typography" media block declares `color` on `main > .grid .grid__heading` etc.
    // Those selectors are [0,2,1]; every theme variant (`.grid--inverted ...`) is at
    // most [0,2,0], so the theme can NEVER win this by specificity. Before #222 the
    // desktop rules fell back straight to a global token (var(--color-text)), which
    // silently overrode the theme's own fallback and painted dark text on the theme's
    // dark background above 768px — while mobile, which has no such rule, rendered
    // correctly. A screenshot-only mobile check would pass.
    //
    // The fix is cascade-independent: the theme variant sets an inheritable
    // *-theme-color default, and every non-variant color declaration resolves
    //     slot -> theme default -> global token
    // so whichever selector wins, the theme still supplies the right default and the
    // per-instance style slot still takes precedence over both (#86's contract).
    //
    // Asserting the theme var merely APPEARS is not enough — a malformed chain
    // (theme var first, or global token before the theme var) would still contain the
    // string. So pin the ORDER.
    const stripped = stripComments(COMPONENTS_CSS);

    const THEMED = [
        // `desktop` = the element also carries a color declaration inside the >=768px
        // typography block, i.e. it is exposed to the cascade defect. .grid__subheading
        // has no desktop color rule; it was broken at every viewport for a different
        // reason (no inverted rule existed at all), so it is pinned at the base rule only.
        { el: '.grid__heading', slot: '--grid-heading-color', themeVar: '--grid-heading-theme-color', desktop: true },
        { el: '.grid__subheading', slot: '--grid-subheading-color', themeVar: '--grid-subheading-theme-color', desktop: false },
        { el: '.section__title', slot: '--section-title-color', themeVar: '--section-title-theme-color', desktop: true },
        { el: '.section__content', slot: '--section-text', themeVar: '--section-text-theme-color', desktop: true },
        { el: '.cta__body', slot: '--cta-body-color', themeVar: '--cta-body-theme-color', desktop: true },
    ];

    // Theme-variant rules (`.grid--inverted .grid__heading`) and page-specific ID
    // overrides declare a color for one specific theme on purpose — they are not
    // the general-purpose declaration this guard governs.
    const isVariantOrIdRule = (selector) =>
        /--inverted|--dark|--has-bg-image|#/.test(selector);

    // Brace-match every `@media (min-width: 768px)` block so a declaration can be
    // located as inside-desktop or not. Pinning "the chain appears somewhere in the
    // file" is not enough: the bug lives specifically in the desktop rule, and if that
    // rule's selector were reshaped so the element filter stopped matching it, the
    // base-rule declaration alone would keep the suite green while the bug returned.
    const desktopRanges = [];
    const mediaRe = /@media\s*\(min-width:\s*768px\)\s*\{/g;
    let mm;
    while ((mm = mediaRe.exec(stripped)) !== null) {
        let depth = 1;
        let i = mm.index + mm[0].length;
        while (i < stripped.length && depth > 0) {
            if (stripped[i] === '{') depth++;
            else if (stripped[i] === '}') depth--;
            i++;
        }
        desktopRanges.push([mm.index, i]);
    }
    const inDesktopBlock = (index) =>
        desktopRanges.some(([start, end]) => index > start && index < end);

    // Collect innermost rules: `selector { body-without-braces }`. Rules nested in a
    // media query still match, with the @media prelude left outside the capture.
    const rules = [];
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(stripped)) !== null) {
        rules.push({ selector: m[1].trim(), body: m[2], index: m.index });
    }

    // Match the element as a whole token anywhere in a comma-part, not just at the end:
    // `main > .section .section__content p` and `.grid__heading:hover` target the same
    // element and must be held to the same chain. `endsWith` would silently skip them.
    const targetsElement = (selector, el) =>
        selector.split(',').some(s => new RegExp(`\\${el}(?![-\\w])`).test(s.trim()));

    // Every `color:` in the body, not just the first — a later duplicate is what wins.
    const colorValues = (body) =>
        [...body.matchAll(/(?:^|;)\s*color\s*:\s*([^;]+)/g)].map(c => c[1].trim());

    THEMED.forEach(({ el, slot, themeVar, desktop }) => {
        // Every general-purpose `color:` declaration for this element — the base rule
        // AND the desktop typography rule — must resolve slot -> theme -> global.
        const chainRe = new RegExp(
            `^var\\(${slot},\\s*var\\(${themeVar},\\s*var\\(--color-[a-z0-9-]+\\)\\)\\)$`
        );

        const declarations = rules
            .filter(r => !isVariantOrIdRule(r.selector))
            .filter(r => targetsElement(r.selector, el))
            .flatMap(r => colorValues(r.body).map(value => ({
                selector: r.selector,
                value,
                desktop: inDesktopBlock(r.index),
            })));

        test(`${el} has at least one themed color declaration`, () => {
            // If this fails the element was renamed or its color rule dropped — the
            // chain assertions below would then vacuously pass.
            expect(declarations.length).toBeGreaterThan(0);
        });

        if (desktop) {
            test(`${el} is still colored inside the >=768px typography block`, () => {
                // The exact rule that caused #222. If it stops matching, the guard below
                // is no longer guarding anything.
                expect(declarations.filter(d => d.desktop).length).toBeGreaterThan(0);
            });
        }

        test(`${el} color always resolves ${slot} -> ${themeVar} -> global token`, () => {
            const offenders = declarations
                .filter(d => !chainRe.test(d.value))
                .map(d => `${d.selector.split('\n').pop().trim()} { color: ${d.value} }`);
            expect(offenders).toEqual([]);
        });
    });

    // The other half of the contract: every variant that paints a DARK surface must
    // actually SET the defaults, or the chains above silently fall through to the
    // light-theme global token and the dark-on-dark bug returns.
    //
    // The --has-bg-image variants are dark surfaces too: they lay a dark overlay
    // (var(--overlay-bg)) over the image, and they lose to the very same desktop
    // typography rules. They shipped the identical defect (issue 248) and are fixed
    // by the same mechanism, so they are pinned here alongside the inverted variants.
    // The --dark variants are deliberately absent: they use a light surface
    // (--color-surface) with dark text, so they must NOT set a theme text default.
    const VARIANT_DECLARES = [
        { variant: '.grid--inverted', vars: ['--grid-heading-theme-color', '--grid-subheading-theme-color'] },
        { variant: '.pp-section--inverted', vars: ['--section-title-theme-color', '--section-text-theme-color'] },
        { variant: '.cta--inverted', vars: ['--cta-body-theme-color'] },
        { variant: '.section--has-bg-image', vars: ['--section-title-theme-color', '--section-text-theme-color'] },
        { variant: '.cta--has-bg-image', vars: ['--cta-body-theme-color'] },
    ];

    VARIANT_DECLARES.forEach(({ variant, vars }) => {
        vars.forEach(v => {
            test(`${variant} declares ${v}`, () => {
                const block = rules.find(r =>
                    r.selector.split(',').some(s => s.trim() === variant)
                );
                expect(block).toBeDefined();
                expect(block.body).toMatch(new RegExp(`${v}\\s*:`));
            });
        });
    });

    // Inverted grid CARDS keep a light background (`--grid-card-bg: var(--color-bg)`),
    // so their text must stay DARK. Theming it would be the inverse of #222: an
    // inverted grid would render light-on-light card text. Pin both halves of that.
    // The fallback must be a FIXED global token (never a theme-swapped var): a bare
    // `--color-*`, OR the `--text-meta-color` / `--text-kicker-color` role tokens used
    // by the #349 role-vs-slot companion rules (`.grid__item-text.text-meta/.text-kicker`).
    // Those two are aliases defined once in base.css :root (→ --color-muted / --color-accent)
    // and are never redefined under any inverted/bg-image/theme scope, so they stay dark
    // on a light card exactly like a bare --color-* fallback.
    test('inverted grid card text resolves to a global token, never a theme var', () => {
        const cardDecls = rules
            .filter(r => targetsElement(r.selector, '.grid__item-title') ||
                         targetsElement(r.selector, '.grid__item-text'))
            .flatMap(r => colorValues(r.body).map(value => ({ selector: r.selector, value })));

        expect(cardDecls.length).toBeGreaterThan(0);
        const offenders = cardDecls
            .filter(d => !/^var\(--grid-item-(title|text)-color,\s*var\(--(color-[a-z0-9-]+|text-(meta|kicker)-color)\)\)$/.test(d.value))
            .map(d => `${d.selector.split('\n').pop().trim()} { color: ${d.value} }`);
        expect(offenders).toEqual([]);
    });

    test('.grid--inverted declares no --grid-item-* default (cards must not be themed)', () => {
        const block = rules.find(r => r.selector.split(',').some(s => s.trim() === '.grid--inverted'));
        expect(block).toBeDefined();
        expect(block.body).not.toMatch(/--grid-item-[a-z-]*\s*:/);
    });
});

describe('CSS lint: dark-band heading rules carve out the self-contained panel heading (#424)', () => {
    // A text-panel is a light surface on a DARK band (inverted, or bg-image overlay).
    // Its heading is `<h3 class="section__panel-heading">`, whose own rule (0,1,0)
    // routes color through --section-panel-text. The dark-band heading rules paint
    // ALL headings via a bare `h2,h3` selector (0,1,1) that outranks the panel rule,
    // so the panel heading rendered in the band's LIGHT title color — light-on-light,
    // invisible on the light panel (#424). The fix scopes each bare heading branch
    // with :not(.section__panel-heading) so the panel's slot wins inside the panel.
    //
    // Asserting "the :not appears somewhere" is not enough: if a band rule kept a bare
    // `h3` branch (no exclusion), the panel heading would regress while the suite stayed
    // green. So for each dark-band variant, pin that EVERY heading-element branch that
    // is not the shared `.section__title` carries the exclusion.
    const stripped = stripComments(COMPONENTS_CSS);

    const rules = [];
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(stripped)) !== null) {
        rules.push({ selector: m[1].trim(), body: m[2] });
    }

    // The two dark-band variants whose bare heading rule defeats the panel slot.
    const DARK_BAND_VARIANTS = ['.pp-section--inverted', '.section--has-bg-image'];

    DARK_BAND_VARIANTS.forEach((variant) => {
        // The heading-color rule for this variant sets `color: var(--section-title-color, ...)`
        // and targets the variant's headings. Find every rule that both scopes the variant
        // and colours a heading through the title slot.
        const headingRules = rules.filter((r) =>
            r.selector.includes(variant) &&
            /\bh[23]\b/.test(r.selector) &&
            /color\s*:\s*var\(--section-title-color/.test(r.body)
        );

        test(`${variant} has a heading-color rule (guard is not vacuous)`, () => {
            expect(headingRules.length).toBeGreaterThan(0);
        });

        // Every comma-part of that rule that targets a bare `h2`/`h3` element (i.e. not the
        // `.section__title` class branch) must exclude `.section__panel-heading`.
        test(`${variant} excludes .section__panel-heading from every bare h2/h3 branch`, () => {
            const offenders = [];
            headingRules.forEach((r) => {
                r.selector.split(',').forEach((part) => {
                    const p = part.trim();
                    // Only heading-element branches are the trap; the `.section__title`
                    // class branch legitimately has no bare element to carve out.
                    if (!/\bh[23]\b/.test(p)) return;
                    if (!/:not\(\.section__panel-heading\)/.test(p)) {
                        offenders.push(p);
                    }
                });
            });
            expect(offenders).toEqual([]);
        });
    });

    // The other half of the contract: the panel heading's OWN rule must route color
    // through the panel slot, so once the band rule is carved out the panel heading
    // resolves to the panel's (dark) text authority rather than an inherited light band.
    test('.section__panel-heading colors through --section-panel-text', () => {
        const rule = rules.find((r) =>
            r.selector.split(',').some((s) => s.trim() === '.section__panel-heading')
        );
        expect(rule).toBeDefined();
        expect(rule.body).toMatch(/color\s*:\s*var\(--section-panel-text\b/);
    });
});

/**
 * Featured grid card honors the --grid-card-border style slot (#226).
 *
 * The first card of a `layout: cards` grid gets an unconditional "featured"
 * treatment. Its sibling slot --grid-card-bg is routed through
 * `var(--grid-card-bg, <default>)` so an author's declared value wins, but the
 * border was hardcoded to an accent token — so a declared --grid-card-border
 * silently no-opped on card 1 while `style_component` reported success.
 *
 * TWO rules set border-color on the featured first card and BOTH must route
 * through the slot: the later one wins the cascade (equal specificity), and the
 * earlier one is the base featured rule. If either keeps a bare token, the slot
 * is ignored on that path. The keystone StyleSlotContractTest only proves the
 * slot is consumed *somewhere* in the grid block (the base `.grid__item` rule
 * satisfies it), so it cannot catch this first-child-specific gap — hence this
 * targeted pin.
 */
describe('CSS lint: featured grid card honors --grid-card-border (#226)', () => {
    // The featured first-card rules carry a :not(.grid--uniform) guard so the
    // `card_emphasis: uniform` prop can opt out of the whole treatment (#226).
    const SELECTOR = 'main > .grid:not(.grid--steps):not(.grid--uniform) .grid__item:first-child';

    // Brace-matched extraction of every rule whose selector is EXACTLY this
    // (whitespace-normalized). `::before` / descendant rules share the prefix
    // but have more text before `{`, so `\s*\{` never matches them.
    function bodiesForExactSelector(selector) {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const re = new RegExp(
            selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{',
            'g'
        );
        const bodies = [];
        let match;
        while ((match = re.exec(css)) !== null) {
            let i = re.lastIndex;
            let depth = 1;
            const start = i;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            bodies.push(css.slice(start, i - 1));
        }
        return bodies;
    }

    const bodies = bodiesForExactSelector(SELECTOR);
    const borderBodies = bodies.filter(b => /border-color\s*:/.test(b));

    // Guard against the selector silently drifting: a zero-match scan would make
    // every assertion below vacuously pass.
    test('finds the featured first-card rules that set border-color', () => {
        expect(borderBodies.length).toBeGreaterThanOrEqual(2);
    });

    test('every border-color on the featured first card routes through --grid-card-border', () => {
        const offenders = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/border-color\s*:\s*var\(\s*--grid-card-border\b/.test(d)) {
                    offenders.push(d.trim());
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Fallback integrity: the slot must fall back to an accent token, never to a
    // neutral border, or unset compositions would lose the featured look. Both
    // accent tokens the two rules historically used are acceptable fallbacks.
    test('--grid-card-border falls back to an accent token, preserving the default look', () => {
        const bad = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/var\(\s*--grid-card-border\s*,\s*var\(\s*--color-(?:border-accent|accent-strong)\s*\)\s*\)/.test(d)) {
                    bad.push(d.trim());
                }
            });
        });
        expect(bad).toEqual([]);
    });
});

/**
 * Non-featured grid cards honor the --grid-card-border style slot (#292).
 *
 * Regression of #226, inverted: the #226 fix routed the featured FIRST card's
 * border-color through --grid-card-border, but the "premium" cascade rules that
 * re-declare border-color for ALL cards (`main > .grid .grid__item`, specificity
 * [0,2,1], which beats the base `.grid__item` rule [0,1,0]) kept a bare
 * `var(--color-border)`. So a declared --grid-card-border silently no-opped on
 * cards 2..N while `style_component` reported success.
 *
 * TWO all-cards rules set border-color and BOTH must route through the slot: the
 * later one wins the cascade (equal specificity), the earlier one is the base
 * all-cards rule. If either keeps a bare token, the slot is ignored on that path.
 * Unlike the featured card, the fallback here is the NEUTRAL --color-border (the
 * default card border), never an accent token — cards 2..N are not featured.
 * The keystone StyleSlotContractTest only proves the slot is consumed *somewhere*
 * in the grid block (the base `.grid__item` rule satisfies it), so it cannot catch
 * this all-cards-specific gap — hence this targeted pin.
 */
describe('CSS lint: non-featured grid cards honor --grid-card-border (#292)', () => {
    const SELECTOR = 'main > .grid .grid__item';

    // Brace-matched extraction of every rule whose selector is EXACTLY this
    // (whitespace-normalized). `:not(.grid--steps)` / `:first-child` / `::before`
    // and comma-group rules share the prefix but have more text before `{`, so
    // `\s*\{` never matches them.
    function bodiesForExactSelector(selector) {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const re = new RegExp(
            selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{',
            'g'
        );
        const bodies = [];
        let match;
        while ((match = re.exec(css)) !== null) {
            let i = re.lastIndex;
            let depth = 1;
            const start = i;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            bodies.push(css.slice(start, i - 1));
        }
        return bodies;
    }

    const bodies = bodiesForExactSelector(SELECTOR);
    const borderBodies = bodies.filter(b => /border-color\s*:/.test(b));

    // Guard against the selector silently drifting: a zero-match scan would make
    // every assertion below vacuously pass.
    test('finds the all-cards grid rules that set border-color', () => {
        expect(borderBodies.length).toBeGreaterThanOrEqual(2);
    });

    test('every border-color on the all-cards rule routes through --grid-card-border', () => {
        const offenders = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/border-color\s*:\s*var\(\s*--grid-card-border\b/.test(d)) {
                    offenders.push(d.trim());
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Fallback integrity: the non-featured card border falls back to the NEUTRAL
    // --color-border, never an accent token — cards 2..N must not adopt the
    // featured accent look when --grid-card-border is unset.
    test('--grid-card-border falls back to the neutral --color-border on all cards', () => {
        const bad = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/var\(\s*--grid-card-border\s*,\s*var\(\s*--color-border\s*\)\s*\)/.test(d)) {
                    bad.push(d.trim());
                }
            });
        });
        expect(bad).toEqual([]);
    });
});

/**
 * Grid desktop column layout (#224).
 *
 * The desktop column count is driven by the `data-pp-count` attribute that
 * grid.php emits on `.grid__list`. Asserting a rule merely *exists* would not
 * prove it wins the cascade: the generic `@media (min-width: 768px)` rule sets
 * `repeat(2, 1fr)`, so a count rule only takes effect from inside the
 * `min-width: 1024px` block that follows it. These pins therefore check
 * containment in that block, not just presence in the file.
 */
describe('CSS lint: grid desktop columns by item count', () => {
    // Extract the bodies of every `@media (min-width: 1024px)` block by brace matching.
    function desktopBlocks(css) {
        const blocks = [];
        const opener = /@media\s*\(min-width:\s*1024px\)\s*\{/g;
        let match;
        while ((match = opener.exec(css)) !== null) {
            let depth = 1;
            let i = opener.lastIndex;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            blocks.push(css.slice(opener.lastIndex, i - 1));
        }
        return blocks;
    }

    const desktop = desktopBlocks(stripComments(COMPONENTS_CSS)).join('\n');

    // Return the LAST grid-template-columns declared for `selector` across the
    // desktop blocks — the cascade winner, not the first textual match. A rule
    // added later that re-overrides the same selector must fail the pin rather
    // than hide behind an earlier one. The selector is anchored to a rule start
    // (`}` or start-of-input) so it cannot bind to a longer selector that merely
    // ends with the same text.
    function rulesFor(selector) {
        const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const pattern = new RegExp(`(?:^|\\})\\s*${escaped}\\s*\\{([^}]*)\\}`, 'g');
        const bodies = [];
        let match;
        while ((match = pattern.exec(desktop)) !== null) {
            bodies.push(match[1]);
            // Re-scan from the rule's own closing brace so back-to-back rules
            // are not skipped by the leading `}` this pattern consumes.
            pattern.lastIndex -= 1;
        }
        return bodies;
    }

    function columnsFor(selector) {
        const bodies = rulesFor(selector);
        if (bodies.length === 0) return null;
        const winner = bodies
            .map(body => /grid-template-columns\s*:\s*([^;]+);/.exec(body))
            .filter(Boolean)
            .pop();
        return winner ? winner[1].trim() : null;
    }

    test('a 3-item cards grid gets 3 columns at desktop', () => {
        expect(desktop).not.toEqual('');
        expect(columnsFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="3"]'))
            .toBe('repeat(3, minmax(0, 1fr))');
    });

    test('a 3-item cards grid spans the container (no narrowing max-width)', () => {
        const bodies = rulesFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="3"]');
        expect(bodies.length).toBeGreaterThan(0);
        bodies.forEach(body => expect(body).not.toMatch(/max-width\s*:/));
    });

    test('the 3-item rule is declared exactly once (no later re-override)', () => {
        expect(rulesFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="3"]'))
            .toHaveLength(1);
    });

    // Scope guard (#224 changed the 3-item case only; #303 aligned the 2-item
    // case). The 4-item case is deliberately still narrowed — if a future change
    // generalizes it, this pin should be revisited deliberately, not broken
    // silently.
    test('a 4-item cards grid still lays out 2 x 2', () => {
        expect(columnsFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="4"]'))
            .toBe('repeat(2, minmax(0, 1fr))');
    });

    test('a 2-item cards grid still lays out 2 across', () => {
        expect(columnsFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="2"]'))
            .toBe('repeat(2, minmax(0, 1fr))');
    });

    // #303: the 2-item row must span the container so it aligns with the section
    // rail (heading x=176), not sit on a narrower centered rail (x=304). The cap
    // came from a `max-width` + auto inline margins, so assert neither survives —
    // and guard the adjacent width/inline-size levers so a future re-narrowing
    // through any of them fails this pin (not just a literal `max-width`).
    test('a 2-item cards grid spans the container (no narrowing, no auto-centering)', () => {
        const bodies = rulesFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="2"]');
        expect(bodies.length).toBeGreaterThan(0);
        bodies.forEach(body => {
            expect(body).not.toMatch(/max-width\s*:/);
            expect(body).not.toMatch(/max-inline-size\s*:/);
            expect(body).not.toMatch(/\bwidth\s*:/);
            expect(body).not.toMatch(/margin(-left|-right|-inline[a-z-]*)?\s*:\s*[^;]*\bauto\b/);
        });
    });

    test('the 2-item rule is declared exactly once (no later re-override)', () => {
        expect(rulesFor('main > .grid:not(.grid--steps) .grid__list[data-pp-count="2"]'))
            .toHaveLength(1);
    });

    test('the steps layout keeps its own 3-column rule', () => {
        expect(columnsFor('.grid--steps .grid__list')).toBe('repeat(3, 1fr)');
    });
});

/**
 * Grid single-item column layout (#297).
 *
 * A one-item grid falls through to the generic `@media (min-width: 768px)` rule
 * that sets `repeat(2, 1fr)`, so the lone card sits in the left column with dead
 * space on the right from 768px up. The fix is a `data-pp-count="1"` rule that
 * takes effect from the SAME 768px breakpoint (not 1024px like the count-2/3/4
 * family), because the stranding starts at the first two-column breakpoint. So
 * these pins extract the `min-width: 768px` blocks, not the 1024px ones, and
 * also assert the rule is declared exactly once across the whole file (a later
 * 1024px re-override would silently reintroduce the bug at desktop).
 */
describe('CSS lint: single-item grid column (#297)', () => {
    // Extract the bodies of every `@media (min-width: 768px)` block by brace matching.
    function tabletBlocks(css) {
        const blocks = [];
        const opener = /@media\s*\(min-width:\s*768px\)\s*\{/g;
        let match;
        while ((match = opener.exec(css)) !== null) {
            let depth = 1;
            let i = opener.lastIndex;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            blocks.push(css.slice(opener.lastIndex, i - 1));
        }
        return blocks;
    }

    const COUNT1 = 'main > .grid:not(.grid--steps) .grid__list[data-pp-count="1"]';

    // Return the bodies of every rule matching COUNT1 in the given CSS scope,
    // anchored to a rule start so it cannot bind to a longer selector ending
    // with the same text.
    function count1Rules(scope) {
        const escaped = COUNT1.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        // Anchor on start-of-scope OR either brace: a rule that is the first in a
        // `@media { ... }` block is preceded by `{`, while back-to-back rules are
        // preceded by `}`. Both must count so the whole-file "declared once" scan
        // sees the media-nested rule. The anchor still prevents binding to a
        // longer selector that merely ends with the same text.
        const pattern = new RegExp(`(?:^|[}{])\\s*${escaped}\\s*\\{([^}]*)\\}`, 'g');
        const bodies = [];
        let match;
        while ((match = pattern.exec(scope)) !== null) {
            bodies.push(match[1]);
            pattern.lastIndex -= 1;
        }
        return bodies;
    }

    const stripped = stripComments(COMPONENTS_CSS);
    const tablet = tabletBlocks(stripped).join('\n');

    test('a 1-item cards grid gets a single full-width track at tablet/desktop', () => {
        expect(tablet).not.toEqual('');
        const bodies = count1Rules(tablet);
        expect(bodies.length).toBeGreaterThan(0);
        const columns = bodies
            .map(body => /grid-template-columns\s*:\s*([^;]+);/.exec(body))
            .filter(Boolean)
            .pop();
        expect(columns && columns[1].trim()).toBe('minmax(0, 1fr)');
    });

    test('a 1-item cards grid spans the container (no narrowing, no auto-centering)', () => {
        const bodies = count1Rules(tablet);
        expect(bodies.length).toBeGreaterThan(0);
        bodies.forEach(body => {
            expect(body).not.toMatch(/max-width\s*:/);
            expect(body).not.toMatch(/max-inline-size\s*:/);
            expect(body).not.toMatch(/\bwidth\s*:/);
            expect(body).not.toMatch(/margin(-left|-right|-inline[a-z-]*)?\s*:\s*[^;]*\bauto\b/);
        });
    });

    // The rule must be declared exactly once across the WHOLE file, so no later
    // block (e.g. the 1024px count family) re-overrides count-1 back to two
    // columns at desktop while the tablet pin above stays green.
    test('the 1-item rule is declared exactly once (no later re-override)', () => {
        expect(count1Rules(stripped)).toHaveLength(1);
    });

    // Selector-agnostic backstop: the "declared exactly once" pin above matches
    // only the exact count-1 selector string, so a re-override that reintroduces
    // the bug with a functionally-equivalent but textually-different selector
    // (e.g. dropping `:not(.grid--steps)`, or a broader
    // `main > .grid .grid__list[data-pp-count="1"]`) would slip past it. So also
    // assert that EVERY rule whose selector targets count-1 and sets a column
    // track resolves to the single full-width track — no multi-column
    // reintroduction anywhere in the file, whatever the selector prefix.
    test('no count-1 selector anywhere sets a multi-column track', () => {
        const rulePattern = /([^{}]*\[data-pp-count="1"\][^{}]*)\{([^}]*)\}/g;
        let match;
        let seen = 0;
        while ((match = rulePattern.exec(stripped)) !== null) {
            const cols = /grid-template-columns\s*:\s*([^;]+);/.exec(match[2]);
            if (cols) {
                seen++;
                expect(cols[1].trim()).toBe('minmax(0, 1fr)');
            }
        }
        expect(seen).toBeGreaterThan(0);
    });
});

/**
 * Grid explicit column-count override (#379).
 *
 * The `columns` prop (integer 1-4) emits `data-pp-columns` on `.grid__list`. Its
 * CSS must (a) force the matching track count at >=768px, (b) span the container
 * (reset the count-4 narrowing), (c) be scoped to cards, and — the load-bearing
 * one — (d) sit in SOURCE ORDER after the auto `data-pp-count` rules so it wins
 * the cascade at equal specificity. A "the rule exists" pin would miss (d); these
 * pin containment in a `min-width: 768px` block AND relative source position.
 */
describe('CSS lint: grid explicit column-count override (#379)', () => {
    const stripped = stripComments(COMPONENTS_CSS);

    // Bodies of every `@media (min-width: 768px)` block, brace-matched.
    function tabletBlocks(css) {
        const blocks = [];
        const opener = /@media\s*\(min-width:\s*768px\)\s*\{/g;
        let match;
        while ((match = opener.exec(css)) !== null) {
            let depth = 1;
            let i = opener.lastIndex;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            blocks.push(css.slice(opener.lastIndex, i - 1));
        }
        return blocks;
    }

    const tablet = tabletBlocks(stripped).join('\n');

    function bodyFor(selector, scope) {
        const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const pattern = new RegExp(`(?:^|[}{])\\s*${escaped}\\s*\\{([^}]*)\\}`, 'g');
        const bodies = [];
        let match;
        while ((match = pattern.exec(scope)) !== null) {
            bodies.push(match[1]);
            pattern.lastIndex -= 1;
        }
        return bodies;
    }

    const CASES = [
        { n: 1, cols: 'minmax(0, 1fr)' },
        { n: 2, cols: 'repeat(2, minmax(0, 1fr))' },
        { n: 3, cols: 'repeat(3, minmax(0, 1fr))' },
        { n: 4, cols: 'repeat(4, minmax(0, 1fr))' },
    ];

    test('the 768px scope is found (guards against a vacuous pass)', () => {
        expect(tablet).not.toEqual('');
    });

    CASES.forEach(({ n, cols }) => {
        const selector = `main > .grid:not(.grid--steps) .grid__list[data-pp-columns="${n}"]`;

        test(`columns=${n} forces ${cols} at >=768px, scoped to cards`, () => {
            const bodies = bodyFor(selector, tablet);
            expect(bodies.length).toBe(1);
            expect(/grid-template-columns\s*:\s*([^;]+);/.exec(bodies[0])[1].trim()).toBe(cols);
        });

        test(`columns=${n} spans the container (no narrowing, no auto-centering)`, () => {
            const bodies = bodyFor(selector, tablet);
            expect(bodies.length).toBeGreaterThan(0);
            bodies.forEach(body => {
                // Explicitly neutralize the count-4 max-width + auto margins so a
                // forced count is uniform regardless of item count.
                expect(body).toMatch(/max-width\s*:\s*none/);
                expect(body).not.toMatch(/margin(-left|-right|-inline[a-z-]*)?\s*:\s*[^;]*\bauto\b/);
            });
        });
    });

    // The cascade winner is decided by source order at equal (0,4,1) specificity.
    // The forced-columns rules MUST appear after the LAST auto data-pp-count rule,
    // or a 6-item grid with columns=3 would still render the count-derived 2-up.
    test('the override rules sit after the auto data-pp-count rules in source order', () => {
        const lastCount = stripped.lastIndexOf('[data-pp-count=');
        const firstColumns = stripped.indexOf('[data-pp-columns=');
        expect(lastCount).toBeGreaterThan(-1);
        expect(firstColumns).toBeGreaterThan(-1);
        expect(firstColumns).toBeGreaterThan(lastCount);
    });

    // Scope guard: no forced-columns rule may target steps — steps keeps its fixed
    // process grain, so every data-pp-columns rule must carry :not(.grid--steps).
    test('no data-pp-columns rule applies to the steps layout', () => {
        const rulePattern = /([^{}]*\[data-pp-columns="\d"\][^{}]*)\{/g;
        let match;
        let seen = 0;
        while ((match = rulePattern.exec(stripped)) !== null) {
            seen++;
            expect(match[1]).toMatch(/:not\(\.grid--steps\)/);
        }
        expect(seen).toBe(4);
    });
});

/**
 * Grid item image icon treatment (#380).
 *
 * `image_treatment: "icon"` emits the `grid--image-icon` variant class on the
 * grid section. Its CSS must (a) drop the 16:9 crop (aspect-ratio: auto) and size
 * the image wrap by the --grid-item-icon-size slot, (b) contain (not cover) the
 * image so a logo/glyph shows whole, and — load-bearing for the acceptance's
 * "mobile <768px verified" — (c) NOT be nested in a min-width media block, so the
 * icon stays icon-sized on phones too. The default `.grid__item-image-wrap` must
 * keep its 16:9 banner untouched.
 */
describe('CSS lint: grid item image icon treatment (#380)', () => {
    const stripped = stripComments(COMPONENTS_CSS);

    // Body of the first rule matching `selector { ... }` at top level (no nested braces).
    function bodyFor(selector) {
        const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const m = new RegExp(`(?:^|[}{])\\s*${escaped}\\s*\\{([^}]*)\\}`).exec(stripped);
        return m ? m[1] : null;
    }

    test('default .grid__item-image-wrap keeps the 16:9 banner (unset = byte-identical)', () => {
        const body = bodyFor('.grid__item-image-wrap');
        expect(body).not.toBeNull();
        expect(/aspect-ratio\s*:\s*16\s*\/\s*9/.test(body)).toBe(true);
    });

    test('grid--image-icon sizes the wrap via --grid-item-icon-size and drops the crop', () => {
        const body = bodyFor('.grid--image-icon .grid__item-image-wrap');
        expect(body).not.toBeNull();
        expect(/aspect-ratio\s*:\s*auto/.test(body)).toBe(true);
        // width AND height both route through the slot (length-typed, default 48px).
        expect(/width\s*:\s*var\(--grid-item-icon-size,\s*48px\)/.test(body)).toBe(true);
        expect(/height\s*:\s*var\(--grid-item-icon-size,\s*48px\)/.test(body)).toBe(true);
    });

    test('grid--image-icon image is contained, not cover-cropped', () => {
        const body = bodyFor('.grid--image-icon .grid__item-image');
        expect(body).not.toBeNull();
        expect(/object-fit\s*:\s*contain/.test(body)).toBe(true);
    });

    test('the icon box follows --grid-item-text-align via the shared #361 companion', () => {
        // The fixed-width icon is a flex child; it reuses the SAME derived
        // --pp-grid-link-align companion the card link follows, so a centered card
        // centers its icon too. Fallback flex-start keeps unset cards left (#380 7A).
        const body = bodyFor('.grid--image-icon .grid__item-image-wrap');
        expect(body).not.toBeNull();
        expect(/align-self\s*:\s*var\(--pp-grid-link-align,\s*flex-start\)/.test(body)).toBe(true);
    });

    test('the icon rules apply at all breakpoints (not nested in a min-width block)', () => {
        // Find every @media (min-width: ...) block body by brace matching, and assert
        // no grid--image-icon rule lives inside one — otherwise mobile would keep the
        // banner-sized image below the breakpoint.
        const opener = /@media\s*\(min-width:[^)]*\)\s*\{/g;
        let match;
        let insideCount = 0;
        while ((match = opener.exec(stripped)) !== null) {
            let depth = 1;
            let i = opener.lastIndex;
            while (i < stripped.length && depth > 0) {
                if (stripped[i] === '{') depth++;
                else if (stripped[i] === '}') depth--;
                i++;
            }
            const block = stripped.slice(opener.lastIndex, i - 1);
            if (block.includes('.grid--image-icon')) insideCount++;
        }
        expect(insideCount).toBe(0);
        // Guard against a vacuous pass: the rule must exist somewhere in the file.
        expect(stripped.includes('.grid--image-icon .grid__item-image-wrap')).toBe(true);
    });
});

/**
 * Hero eyebrow stays a pill (#225).
 *
 * `.hero__eyebrow` declares `display: inline-block`, but it is a direct child of
 * `.hero__content`, which is a flex column. Flex items are blockified, so the
 * declared inline-block computes to block and the default `stretch` alignment
 * spans the eyebrow across the full content width — a band, not a pill. The only
 * thing holding the pill together is the cross-axis alignment, so that is what
 * these pins assert.
 *
 * Asserting `align-self` merely *appears* somewhere would not prove the pill
 * survives: the four benchmark pages carry an ID-specificity `.hero__eyebrow`
 * block that outranks every class rule here. It does not set `align-self` today,
 * and the pin below is what keeps it that way.
 */
describe('CSS lint: hero eyebrow is a pill, not a full-width band', () => {
    // The cascade winner among equal-specificity rules is the LAST one, not the first.
    // Taking the first match would let a duplicate appended later silently shadow the
    // pin while it stayed green.
    function alignSelfFor(selector, media = null) {
        const decls = rulesMatching('.hero__eyebrow')
            .filter(r => r.media === media && r.selectors.includes(selector))
            .map(r => /align-self\s*:\s*([^;}]+)/.exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    const BASE = '.hero__eyebrow';
    const CENTERED = '.hero--centered .hero__eyebrow';
    const COVER = '.hero--cover .hero__eyebrow';

    test('the eyebrow opts out of the flex stretch that would blockify it', () => {
        expect(alignSelfFor(BASE)).toBe('flex-start');
    });

    // The pill is padding + background, not `display`. Pinning `inline-block` would be
    // vacuous — the flex parent blockifies it regardless, which is the whole bug.
    test('the eyebrow keeps its pill styling', () => {
        const rule = rulesMatching(BASE).find(r => r.selectors.includes(BASE) && !r.media);
        expect(rule.body).toMatch(/padding\s*:/);
        expect(rule.body).toMatch(/background\s*:/);
    });

    // The pill must be sized by its text. A width on the base rule would defeat
    // align-self without touching it.
    test('the base rule sizes the pill by its content, not a width', () => {
        const rule = rulesMatching(BASE).find(r => r.selectors.includes(BASE) && !r.media);
        expect(rule.body).not.toMatch(/(?:^|[;\s])(?:min-)?width\s*:/);
    });

    // The two center-aligned layouts re-center the pill, matching the treatment their
    // CTA groups already get. Both outrank the base rule on specificity (0,2,0 vs
    // 0,1,0), so source order cannot flip these.
    test('the centered layout centers the pill', () => {
        expect(alignSelfFor(CENTERED)).toBe('center');
    });

    test('the cover layout centers the pill', () => {
        expect(alignSelfFor(COVER)).toBe('center');
    });

    // Scope guard: left/split are left-aligned layouts and inherit the base flex-start.
    // An override here would be a silent behavior change.
    test('the left and split layouts inherit the base flex-start (no override)', () => {
        expect(alignSelfFor('.hero--left .hero__eyebrow')).toBeNull();
        expect(alignSelfFor('.hero--split .hero__eyebrow')).toBeNull();
    });

    // The cascade risks that would restore the band while every pin above stayed green:
    // the benchmark pages' ID-specificity block (which outranks all four class rules),
    // and any media-scoped or duplicate rule. Only the three known rules may size or
    // align the eyebrow — everything else must leave it alone, in every media context.
    test('no other eyebrow rule anywhere sizes or re-aligns the pill', () => {
        const owners = [BASE, CENTERED, COVER];
        rulesMatching(BASE)
            .filter(r => r.media || !r.selectors.every(s => owners.includes(s)))
            .forEach(r => expect(r.body).not.toMatch(BAND_RESTORING));
    });

    // The fix only matters while the parent is a flex column. If any rule, in any media
    // context, makes it a row or a non-flex box (a grid parent re-points align-self at
    // the block axis and lets justify-self stretch the item), align-self stops
    // controlling the eyebrow's width and every pin above becomes dead code. Matching
    // any selector ending in .hero__content catches layout-scoped overrides too.
    test('the eyebrow parent is a flex column in every media context', () => {
        const contentRules = rulesMatching('.hero__content');
        const base = contentRules.find(r => r.selectors.includes('.hero__content') && !r.media);
        expect(base.body).toMatch(/display\s*:\s*flex\s*;/);
        expect(base.body).toMatch(/flex-direction\s*:\s*column\s*;/);
        contentRules
            .filter(r => r !== base)
            .forEach(r => {
                expect(r.body).not.toMatch(/display\s*:(?!\s*flex\s*;)/);
                expect(r.body).not.toMatch(/flex-direction\s*:(?!\s*column\s*;)/);
            });
    });
});

/**
 * Hero flex rows declare their packing (#338).
 *
 * `.hero__proof` was a flex container with NO justify-content, so its items packed at the
 * initial `flex-start` — left-aligned, inside a hero the operator asked to be centered.
 * `text-align: center` is inherited onto the row and has no say in where a flex container
 * places its items, so nothing in the computed styles looked wrong.
 *
 * These are DECLARATION pins and they are deliberately not the proof. The bug was
 * invisible at the declaration level, which is exactly how it shipped; the rendered proof
 * lives in tests/e2e/style-render.spec.ts (#338), which measures where the glyphs actually
 * land. What these add is cheap coverage of the cascade risk the rendered pins cannot see
 * on a fixture page: any ID-specificity (1,1,0) rule re-declaring the packing would beat
 * the fix on real pages while every E2E fixture stayed green. (The demo-ID
 * `#home-hero .hero__proof` rules that once posed this risk were evicted in #412, and
 * the #412 ID-selector lint now forbids their return; this stays the property-level
 * backstop for any equivalent high-specificity re-justification.)
 */
describe('CSS lint: hero flex rows declare their justification', () => {
    // Last match wins among equal-specificity rules, same as the #225 guard above.
    function justifyFor(needle, selector, media = null) {
        const decls = rulesMatching(needle)
            .filter(r => r.media === media && r.selectors.includes(selector))
            .map(r => /justify-content\s*:\s*([^;}]+)/.exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    // Both hero rows that pack items along the inline axis. The proof row is the one that
    // shipped the bug; the cta group hid the identical hole behind `align-self`, which
    // shrink-wraps its box until the buttons wrap.
    const ROWS = ['.hero__proof', '.hero__cta-group'];

    test.each(ROWS)('%s declares its base packing instead of inheriting flex-start', row => {
        expect(justifyFor(row, row)).toBe('flex-start');
    });

    // The two center-aligned layouts pack their rows to match, driven by the layout
    // variant class — not by a new style slot the operator would have to set after
    // already having said the hero is centered.
    test.each(ROWS)('the centered layout centers %s', row => {
        expect(justifyFor(row, `.hero--centered ${row}`)).toBe('center');
    });

    test.each(ROWS)('the cover layout centers %s', row => {
        expect(justifyFor(row, `.hero--cover ${row}`)).toBe('center');
    });

    // Scope guard: left and split are left-aligned layouts and must inherit the base
    // flex-start. An override here would silently center a proof line that should hug the
    // leading edge — the mirror-image regression of the bug being fixed.
    test.each(ROWS)('the left and split layouts inherit the base flex-start for %s', row => {
        expect(justifyFor(row, `.hero--left ${row}`)).toBeNull();
        expect(justifyFor(row, `.hero--split ${row}`)).toBeNull();
    });

    // The cascade risk that would defeat the fix with every pin above still green: an
    // ID-specificity rule (the #412 lint now forbids any ID selector in shipped CSS) or a
    // media-scoped rule re-declaring the packing. Only the three known class rules may
    // justify these rows.
    test.each(ROWS)('no other rule anywhere re-justifies %s', row => {
        const owners = [row, `.hero--centered ${row}`, `.hero--cover ${row}`];
        rulesMatching(row)
            .filter(r => r.media || !r.selectors.every(s => owners.includes(s)))
            .forEach(r => expect(r.body).not.toMatch(/justify-content\s*:/));
    });

    // ...and "anywhere" has to mean anywhere, not just components.css. A justify-content on
    // these rows from ANY other enqueued stylesheet would beat the fix on real pages with
    // every pin above green — the cascade does not care which file a rule was authored in.
    // These rows are components.css's to own, so no other sheet may name them at all.
    test.each(ROWS)('no other stylesheet declares %s', row => {
        const dir = path.resolve(__dirname, '../../assets/css');
        for (const file of fs.readdirSync(dir).filter(f => f.endsWith('.css'))) {
            if (file === 'components.css') continue;
            const css = stripComments(fs.readFileSync(path.join(dir, file), 'utf-8'));
            expect(css).not.toMatch(new RegExp(row.replace('.', '\\.') + '(?![\\w-])'));
        }
    });

    // The rows only pack along the inline axis while they are flex ROWS. If any rule made
    // one a column, justify-content would silently start controlling the BLOCK axis and
    // every pin above would become dead code — the same "the mechanism moved out from
    // under the pin" failure the #225 parent-is-a-flex-column guard exists for.
    test.each(ROWS)('%s is a flex row in every media context', row => {
        const rules = rulesMatching(row);
        const base = rules.find(r => r.selectors.includes(row) && !r.media);
        // Without this, a renamed or media-scoped base rule fails as a TypeError on
        // `base.body` instead of naming the regression.
        expect(base).toBeDefined();
        expect(base.body).toMatch(/display\s*:\s*flex\s*;/);
        rules.forEach(r => expect(r.body).not.toMatch(/flex-direction\s*:\s*column/));
    });
});

/**
 * Premium layer honors padding / heading-size / body-width style slots (#302).
 *
 * Same dead-slot class as #226/#292 but on the padding + typography axis: the
 * "premium" cascade re-declared padding, heading font-size, and section body
 * width with bare literals at [0,1,0]-or-higher specificity / later source
 * order, outranking the base rules that DO route through the slot. So a declared
 * --section-padding-*, --grid-padding-*, --cta-padding-*, --section-title-size,
 * --grid-heading-size, or --section-body-width validated, reported success, and
 * changed nothing. The fix routes every premium re-declaration through
 * var(--slot, <literal>) with the literal as the fallback (unset output
 * unchanged), and restores the base adjacent-sibling rhythm (now the shared
 * --pp-band-padding-adjacent-top tier) that the premium layer had flattened to
 * a uniform clamp().
 *
 * These pins mirror the #226/#292 guards: assert every declaration of the
 * property on the target selector routes through the slot, plus a presence guard
 * so a selector rename can't make the assertions vacuously pass. faq keeps its
 * literals (no padding/heading-size slot yet, issue 304) and is intentionally
 * NOT asserted here.
 */
describe('CSS lint: premium layer honors padding/type/width slots (#302)', () => {
    // Brace-matched extraction of every rule whose selector is EXACTLY this
    // (whitespace-normalized), across all media contexts. Trailing `\s*\{` blocks
    // longer selectors (`.section__body` never matches `.section`); the leading
    // selector-list boundary (`{`, `}`, `;`, or `,`) blocks the reverse — a short
    // selector must not suffix-match a descendant rule (`.section__body` must not
    // match `.section--centered .section__body`).
    function bodiesForExactSelector(selector) {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const re = new RegExp(
            '[{};,]\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{',
            'g'
        );
        const bodies = [];
        let match;
        while ((match = re.exec(css)) !== null) {
            let i = re.lastIndex;
            let depth = 1;
            const start = i;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            bodies.push(css.slice(start, i - 1));
        }
        return bodies;
    }

    // Assert every `prop:` declaration inside `selector`'s rules routes through
    // `var(--slot, ...)`. `presence` is the minimum rule count expected so a
    // selector drift can't vacuously pass.
    function assertPropRoutesThroughSlot(selector, prop, slot, presence) {
        const bodies = bodiesForExactSelector(selector);
        const propBodies = bodies.filter(b => new RegExp(prop + '\\s*:').test(b));
        expect(propBodies.length).toBeGreaterThanOrEqual(presence);
        const offenders = [];
        propBodies.forEach(body => {
            const decls = body.match(new RegExp(prop + '\\s*:[^;}]+', 'g')) || [];
            decls.forEach(d => {
                if (!new RegExp(prop + '\\s*:\\s*var\\(\\s*' + slot + '\\b').test(d)) {
                    offenders.push(d.trim());
                }
            });
        });
        expect(offenders).toEqual([]);
    }

    // ---- Heading font-size slots (title-size / heading-size) ----
    test('section title premium rule routes font-size through --section-title-size', () => {
        assertPropRoutesThroughSlot('main > .section .section__title', 'font-size', '--section-title-size', 1);
    });

    test('grid heading premium rule routes font-size through --grid-heading-size', () => {
        assertPropRoutesThroughSlot('main > .grid .grid__heading', 'font-size', '--grid-heading-size', 1);
    });

    // ---- Section body width slot ----
    test('.section__body caps max-width through --section-body-width (fallback 40rem)', () => {
        const bodies = bodiesForExactSelector('.section__body');
        const widthBodies = bodies.filter(b => /max-width\s*:/.test(b));
        expect(widthBodies.length).toBeGreaterThanOrEqual(1);
        widthBodies.forEach(body => {
            (body.match(/max-width\s*:[^;}]+/g) || []).forEach(d => {
                expect(d).toMatch(/max-width\s*:\s*var\(\s*--section-body-width\s*,\s*40rem\s*\)/);
            });
        });
    });

    // ---- Stats contained-card slots (issue 383) ----
    // The band's radius + max-width must route through the slots with byte-identical
    // unset fallbacks ('0' / 'none'), so an unset stats band stays full-bleed with
    // square corners exactly as before 383. The rendered proof lives in style-render.spec.ts.
    test('.stats radius + max-width route through --stats-radius / --stats-max-width', () => {
        const bodies = bodiesForExactSelector('.stats');
        expect(bodies.length).toBeGreaterThanOrEqual(1);
        const radiusBodies = bodies.filter(b => /border-radius\s*:/.test(b));
        const widthBodies = bodies.filter(b => /max-width\s*:/.test(b));
        expect(radiusBodies.length).toBeGreaterThanOrEqual(1);
        expect(widthBodies.length).toBeGreaterThanOrEqual(1);
        radiusBodies.forEach(body => {
            (body.match(/border-radius\s*:[^;}]+/g) || []).forEach(d => {
                expect(d).toMatch(/border-radius\s*:\s*var\(\s*--stats-radius\s*,\s*0\s*\)/);
            });
        });
        widthBodies.forEach(body => {
            (body.match(/max-width\s*:[^;}]+/g) || []).forEach(d => {
                expect(d).toMatch(/max-width\s*:\s*var\(\s*--stats-max-width\s*,\s*none\s*\)/);
            });
        });
    });

    // ---- Own section/grid/cta padding (desktop + mobile) ----
    test('every .section padding declaration routes through --section-padding-*', () => {
        assertPropRoutesThroughSlot('.section', 'padding-top', '--section-padding-top', 2);
        assertPropRoutesThroughSlot('.section', 'padding-bottom', '--section-padding-bottom', 2);
    });

    test('every .grid padding declaration routes through --grid-padding-*', () => {
        assertPropRoutesThroughSlot('.grid', 'padding-top', '--grid-padding-top', 2);
        assertPropRoutesThroughSlot('.grid', 'padding-bottom', '--grid-padding-bottom', 2);
    });

    test('every .cta padding declaration routes through --cta-padding-*', () => {
        assertPropRoutesThroughSlot('.cta', 'padding-top', '--cta-padding-top', 2);
        assertPropRoutesThroughSlot('.cta', 'padding-bottom', '--cta-padding-bottom', 2);
    });

    // (The page-specific `#*-cta` padding pins were removed with the demo-ID eviction
    //  in #412: those ID-scoped closers no longer ship, so the generic `.cta` slot
    //  rules above are now the whole padding surface.)

    // ---- Adjacent-sibling rhythm routes through the shared def ----
    // The flat premium override was DELETED; the remaining rules for this exact
    // selector route their top through --pp-band-padding-adjacent-top (issue 431),
    // which is itself pinned to --pp-band-padding (issue 430 symmetry). Neither
    // may re-introduce the flattening clamp() literal in components.css.
    test('adjacent-sibling rhythm no longer flattened by a bare clamp()', () => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + [data-pp-component]');
        expect(bodies.length).toBeGreaterThanOrEqual(2);
        bodies.forEach(body => {
            expect(body).not.toMatch(/clamp\(\s*4\.25rem/);
        });
    });

    // Each slot-bearing component's adjacent rule exists at BOTH breakpoints, and
    // BOTH fall back to the ONE shared adjacent-top definition (issue 431). The old
    // per-breakpoint literals (desktop var(--space-lg), mobile 3.35rem) collapsed
    // into --pp-band-padding-adjacent-top, now pinned to --pp-band-padding (issue
    // 430) so the adjacent-top tracks the band's own edges per breakpoint — a
    // single fallback token, not two literals. Every declaration must still route
    // through the component slot (slot wins), and testimonials must be present
    // (its adjacent rule was missing before issue 431).
    test.each([
        ['main > [data-pp-component] + .section', '--section-padding-top'],
        ['main > [data-pp-component] + .grid', '--grid-padding-top'],
        ['main > [data-pp-component] + .cta', '--cta-padding-top'],
        ['main > [data-pp-component] + .stats', '--stats-padding-top'],
        ['main > [data-pp-component] + .faq', '--faq-padding-top'],
        ['main > [data-pp-component] + .testimonials', '--testimonials-padding-top'],
    ])('adjacent %s routes top-padding through the slot to the shared def at both breakpoints', (selector, slot) => {
        const bodies = bodiesForExactSelector(selector);
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        // Desktop + mobile = two declarations minimum.
        expect(decls.length).toBeGreaterThanOrEqual(2);
        decls.forEach(d => {
            expect(d).toMatch(new RegExp('padding-top\\s*:\\s*var\\(\\s*' + slot + '\\b'));
            // The fallback is the ONE shared adjacent-top definition — never a bare
            // literal, on either breakpoint.
            expect(d).toMatch(new RegExp('var\\(\\s*' + slot + '\\s*,\\s*var\\(\\s*--pp-band-padding-adjacent-top\\s*\\)'));
        });
    });

    // An explicit data-pp-spacing override (hero only) must win BOTH edges at EVERY
    // breakpoint (#434). The desktop restatement `main > [data-pp-component]
    // [data-pp-spacing="…"]` [0,2,1] out-orders the desktop adjacent rule; before #434
    // the mobile @media block had no such restatement, so the generic mobile adjacent
    // rule [0,2,1] shaved a spaced hero's top edge alone (top=band rhythm / bottom=
    // spacing value) when the hero followed another band. The fix mirrors the desktop
    // restatement into the mobile block.
    //
    // This pin is breakpoint- AND source-order-aware, not just "a rule with this selector
    // exists somewhere" (which two duplicated DESKTOP bodies would satisfy). It asserts,
    // per breakpoint media block: (1) the restatement lives INSIDE that block, (2) at
    // mobile it appears AFTER the generic adjacent rule so source order wins both edges,
    // (3) both edges resolve to the SAME token (symmetry), and (4) the token is the tier
    // intended for that breakpoint. Tier is asserted by token NAME, not px value, so a
    // retune of the --space-* scale in base.css doesn't churn this pin — only a deliberate
    // mis-mapping (e.g. mobile spacious regressing to the desktop --space-3xl tier) fails.
    function mediaBlocks(css, queryRe) {
        const stripped = stripComments(css);
        const opener = new RegExp('@media\\s*\\(' + queryRe + '\\)\\s*\\{', 'g');
        const blocks = [];
        let m;
        while ((m = opener.exec(stripped)) !== null) {
            let depth = 1;
            let i = opener.lastIndex;
            while (i < stripped.length && depth > 0) {
                if (stripped[i] === '{') depth++;
                else if (stripped[i] === '}') depth--;
                i++;
            }
            blocks.push(stripped.slice(opener.lastIndex, i - 1));
        }
        return blocks;
    }
    // Returns the body of the restatement rule for `spacing` inside `block`, or null.
    function spacingRuleBody(block, spacing) {
        const re = new RegExp(
            '(?:^|[}{;])\\s*main\\s*>\\s*\\[data-pp-component\\]\\[data-pp-spacing="' +
            spacing + '"\\]\\s*\\{([^}]*)\\}'
        );
        const m = re.exec(block);
        return m ? m[1] : null;
    }
    function assertSymmetricTier(body, selectorLabel, expectedToken) {
        expect(body, `${selectorLabel} restatement missing`).not.toBeNull();
        const top = body.match(/padding-top\s*:\s*var\(\s*(--space-[a-z0-9]+)\s*\)/);
        const bot = body.match(/padding-bottom\s*:\s*var\(\s*(--space-[a-z0-9]+)\s*\)/);
        expect(top, `${selectorLabel} must set padding-top to a --space-* token`).not.toBeNull();
        expect(bot, `${selectorLabel} must set padding-bottom to a --space-* token`).not.toBeNull();
        // Symmetry: both edges resolve to the same scale step (never shaved).
        expect(top[1], `${selectorLabel} not symmetric: top=${top[1]} bottom=${bot[1]}`).toBe(bot[1]);
        // Tier: the token intended for this breakpoint.
        expect(top[1], `${selectorLabel} on wrong tier`).toBe(expectedToken);
    }

    test('#434 mobile block restates data-pp-spacing AFTER the adjacent rule, both edges, correct tier', () => {
        // The one max-width:767px block that carries the restatement.
        const mobile = mediaBlocks(COMPONENTS_CSS, 'max-width:\\s*767px').find(b => spacingRuleBody(b, 'compact') !== null);
        expect(mobile, 'expected a max-width:767px block containing the spacing restatement').toBeTruthy();
        // Source order: the generic adjacent rule must precede BOTH restatements so the
        // equal-specificity [0,2,1] restatements win padding-top on a spaced adjacent hero.
        const adjIdx = mobile.indexOf('[data-pp-component] + [data-pp-component]');
        const compactIdx = mobile.search(/main\s*>\s*\[data-pp-component\]\[data-pp-spacing="compact"\]/);
        const spaciousIdx = mobile.search(/main\s*>\s*\[data-pp-component\]\[data-pp-spacing="spacious"\]/);
        expect(adjIdx, 'mobile generic adjacent rule missing').toBeGreaterThanOrEqual(0);
        expect(compactIdx, 'mobile compact restatement must follow the adjacent rule').toBeGreaterThan(adjIdx);
        expect(spaciousIdx, 'mobile spacious restatement must follow the adjacent rule').toBeGreaterThan(adjIdx);
        // Mobile tier: compact=--space-lg, spacious=--space-2xl (NOT the desktop --space-3xl).
        assertSymmetricTier(spacingRuleBody(mobile, 'compact'), 'mobile compact', '--space-lg');
        assertSymmetricTier(spacingRuleBody(mobile, 'spacious'), 'mobile spacious', '--space-2xl');
    });

    test('#434 desktop restatement still present, both edges, correct tier', () => {
        // The min-width:768px block that carries the restatement (unchanged by #434, pinned
        // so a mobile-focused edit can't silently drop the desktop side it mirrors).
        const desktop = mediaBlocks(COMPONENTS_CSS, 'min-width:\\s*768px').find(b => spacingRuleBody(b, 'compact') !== null);
        expect(desktop, 'expected a min-width:768px block containing the spacing restatement').toBeTruthy();
        // Desktop tier: compact=--space-lg, spacious=--space-3xl (the larger open-air tier).
        assertSymmetricTier(spacingRuleBody(desktop, 'compact'), 'desktop compact', '--space-lg');
        assertSymmetricTier(spacingRuleBody(desktop, 'spacious'), 'desktop spacious', '--space-3xl');
    });
});

/**
 * Structural pin: the six section-level band components share ONE rhythm
 * definition (#431).
 *
 * Before #431 each band (section, grid, cta, stats, faq, testimonials) carried
 * its own vertical-padding literal, duplicated per component per media block, so
 * the defaults drifted (stats/testimonials off-tier, cta's mobile bottom off,
 * testimonials missing from both adjacent routing lists). The fix defines the
 * rhythm once in base.css — `--pp-band-padding` (a band's own top/bottom) and
 * `--pp-band-padding-adjacent-top` (a band that follows another band) — and routes
 * every band's padding fallback through it.
 *
 * These pins enforce that model structurally so a seventh band can't re-introduce
 * drift by copying a literal:
 *   1. every own-padding decl on each band's root routes var(--<comp>-padding-*,
 *      var(--pp-band-padding));
 *   2. every adjacent-top decl routes var(--<comp>-padding-top,
 *      var(--pp-band-padding-adjacent-top)) — testimonials included;
 *   3. the shared definition is the ONLY rhythm value source: the band literals
 *      (clamp(4.25rem, 6vw, 5rem) and 3.35rem) appear nowhere in components.css;
 *   4. the shared props are actually defined in base.css, with a mobile override,
 *      so a rename breaks these pins loudly instead of silently no-op'ing.
 */
describe('CSS lint: section-level bands share one rhythm definition (#431)', () => {
    // Nine bands (issue 438 folded table/logos/embed into the contract). Each entry
    // carries its root class AND slot prefix because table's class (.table-section)
    // and slot prefix (--table-section) differ from the component name — a naive
    // `--${comp}-` derivation would assert against a nonexistent `.table` selector.
    const BAND_COMPONENTS = [
        { comp: 'section', cls: '.section', slot: '--section' },
        { comp: 'grid', cls: '.grid', slot: '--grid' },
        { comp: 'cta', cls: '.cta', slot: '--cta' },
        { comp: 'stats', cls: '.stats', slot: '--stats' },
        { comp: 'faq', cls: '.faq', slot: '--faq' },
        { comp: 'testimonials', cls: '.testimonials', slot: '--testimonials' },
        { comp: 'table', cls: '.table-section', slot: '--table-section' },
        { comp: 'logos', cls: '.logos', slot: '--logos' },
        { comp: 'embed', cls: '.embed', slot: '--embed' },
    ];

    // Brace-matched extraction of every rule whose selector is EXACTLY `selector`
    // (whitespace-normalized), across all media contexts. Same technique as the
    // #302 helper; re-declared here so this suite is self-contained.
    function bodiesForExactSelector(selector) {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const re = new RegExp(
            '[{};,]\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{',
            'g'
        );
        const bodies = [];
        let match;
        while ((match = re.exec(css)) !== null) {
            let i = re.lastIndex;
            let depth = 1;
            const start = i;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            bodies.push(css.slice(start, i - 1));
        }
        return bodies;
    }

    // 1. Own padding: every padding-top/bottom on each band's root routes through
    //    the component slot AND falls back to the shared --pp-band-padding.
    test.each(BAND_COMPONENTS)('$comp own padding routes through its slot to var(--pp-band-padding)', ({ cls, slot }) => {
        const bodies = bodiesForExactSelector(cls);
        ['padding-top', 'padding-bottom'].forEach(prop => {
            const slotName = `${slot}-${prop}`;
            const decls = bodies.flatMap(b => b.match(new RegExp(prop + '\\s*:[^;}]+', 'g')) || []);
            // At least the base rule declares each edge — a drift to a different
            // selector can't make this vacuously pass.
            expect(decls.length).toBeGreaterThanOrEqual(1);
            decls.forEach(d => {
                expect(d).toMatch(new RegExp(prop + '\\s*:\\s*var\\(\\s*' + slotName + '\\s*,\\s*var\\(\\s*--pp-band-padding\\s*\\)'));
            });
        });
    });

    // 2. Adjacent-top: every band (testimonials included) routes its adjacent-top
    //    edge through the slot to the shared --pp-band-padding-adjacent-top, at both
    //    breakpoints. The dead --testimonials-padding-top slot is resurrected here.
    test.each(BAND_COMPONENTS)('adjacent $comp routes top-padding to var(--pp-band-padding-adjacent-top) at both breakpoints', ({ cls, slot }) => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + ' + cls);
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        // Desktop + mobile adjacent rules both exist.
        expect(decls.length).toBeGreaterThanOrEqual(2);
        decls.forEach(d => {
            expect(d).toMatch(new RegExp('padding-top\\s*:\\s*var\\(\\s*' + slot + '-padding-top\\s*,\\s*var\\(\\s*--pp-band-padding-adjacent-top\\s*\\)'));
        });
    });

    // 3. The shared definition is the ONLY rhythm value source. The former band
    //    literals must appear NOWHERE in components.css declarations — so a seventh
    //    band (or a future edit) that pastes a literal instead of consuming the
    //    shared prop fails this pin.
    test('the band rhythm literals live only in the shared definition, not in components.css', () => {
        const stripped = stripComments(COMPONENTS_CSS);
        expect(stripped).not.toMatch(/clamp\(\s*4\.25rem\s*,\s*6vw\s*,\s*5rem\s*\)/);
        expect(stripped).not.toContain('3.35rem');
    });

    // 3b. The generic adjacent catch-all (the only slot-less band left is hero, after
    //     issue 438 gave table/logos/embed their own padding slots) also consumes the
    //     shared adjacent-top, not a literal, so nothing routes rhythm outside the one
    //     definition.
    test('the generic adjacent-sibling rule routes through --pp-band-padding-adjacent-top', () => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + [data-pp-component]');
        expect(bodies.length).toBeGreaterThanOrEqual(2); // desktop + mobile
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        expect(decls.length).toBeGreaterThanOrEqual(2);
        decls.forEach(d => {
            expect(d).toMatch(/padding-top\s*:\s*var\(\s*--pp-band-padding-adjacent-top\s*\)/);
        });
    });

    // 4. The shared props are actually defined in base.css — a desktop value in
    //    :root and a mobile override — so the routing above resolves and a rename
    //    breaks the pins loudly instead of silently no-op'ing.
    test('base.css defines the shared rhythm props with a mobile override', () => {
        const base = stripComments(BASE_CSS);
        // The band's own top/bottom rhythm on desktop.
        expect(base).toMatch(/--pp-band-padding\s*:\s*clamp\(\s*4\.25rem\s*,\s*6vw\s*,\s*5rem\s*\)/);
        // A mobile @media block redefines --pp-band-padding to the uniform mobile
        // tier. It does NOT redefine adjacent-top — that tracks --pp-band-padding
        // automatically (issue 430 symmetry), so a stray mobile adjacent-top
        // literal that could reintroduce asymmetry must not exist.
        const mobileRoot = base.match(/@media\s*\(\s*max-width:\s*767px\s*\)\s*\{\s*:root\s*\{([^}]*)\}/);
        expect(mobileRoot, 'expected a @media (max-width: 767px) :root override in base.css').not.toBeNull();
        expect(mobileRoot[1]).toMatch(/--pp-band-padding\s*:\s*3\.35rem/);
        expect(mobileRoot[1]).not.toMatch(/--pp-band-padding-adjacent-top/);
    });

    // 5. Symmetry pin (issue 430): the adjacent-top tier is pinned to the band's
    //    own padding, so a band that follows another band gets the SAME top as
    //    its bottom — every stacked band is a centered block, never top-cramped /
    //    bottom-heavy. This is a TEXT guarantee (the fallback token IS
    //    --pp-band-padding); the E2E computed-rhythm spec proves the cascade
    //    actually resolves top == bottom. A future edit that re-splits the tier
    //    (e.g. back to var(--space-lg)) fails here loudly.
    test('the adjacent-top tier is pinned to --pp-band-padding (symmetric bands)', () => {
        const base = stripComments(BASE_CSS);
        expect(base).toMatch(/--pp-band-padding-adjacent-top\s*:\s*var\(\s*--pp-band-padding\s*\)/);
        // And nowhere in base.css does adjacent-top get a bare rhythm literal or
        // the old tighter --space-lg tier that made bands asymmetric.
        expect(base).not.toMatch(/--pp-band-padding-adjacent-top\s*:\s*var\(\s*--space-lg\s*\)/);
        expect(base).not.toMatch(/--pp-band-padding-adjacent-top\s*:\s*\d/);
    });
});

/**
 * Structural pin: every band-level heading shares ONE responsive scale (#436),
 * the typography analog of the #431 rhythm pin above.
 *
 * Before #436, band titles had no working default size below 768px: section /
 * grid / cta used `font-size: var(--slot, inherit)` (collapses to 16px body size
 * on mobile; cta collapsed at every viewport), while faq / stats / table / logos /
 * embed / testimonials used divergent literals (1.875rem or the flat h2 element
 * rule). The fix defines the scale once in base.css — `--pp-band-heading-size`
 * (a fluid clamp from a ~28px mobile floor to the ~42px desktop ceiling) — and
 * routes every band heading's font-size fallback through it.
 *
 * These pins enforce that model structurally so a heading can't re-introduce the
 * collapse by pasting a literal or falling back to `inherit`:
 *   1. every band-heading font-size declaration routes var(--<comp>-*-size,
 *      var(--pp-band-heading-size)) — slot still wins, shared scale is the floor;
 *   2. `inherit` never appears as a heading font-size fallback (the exact
 *      regression that shipped the 16px collapse);
 *   3. the shared prop is actually defined in base.css as a fluid clamp, so the
 *      routing resolves and a rename breaks these pins loudly.
 */
describe('CSS lint: band-level headings share one responsive scale (#436)', () => {
    // Brace-matched extraction of every rule whose selector is EXACTLY `selector`
    // (whitespace-normalized), across all media contexts. Same technique as the
    // #431 helper; re-declared here so this suite is self-contained.
    function bodiesForExactSelector(selector) {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const re = new RegExp(
            '[{};,]\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{',
            'g'
        );
        const bodies = [];
        let match;
        while ((match = re.exec(css)) !== null) {
            let i = re.lastIndex;
            let depth = 1;
            const start = i;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            bodies.push(css.slice(start, i - 1));
        }
        return bodies;
    }

    // Each band heading: its exact selector(s) and the size slot the font-size
    // must route through. section/grid/faq carry BOTH a base rule and a desktop
    // premium rule ([0,2,1]); both must route the slot to the shared scale, so a
    // multi-selector entry pins every declaration site.
    const BAND_HEADINGS = [
        { selectors: ['.section__title', '.section--text-only .section__title', 'main > .section .section__title'], slot: '--section-title-size' },
        { selectors: ['.grid__heading', 'main > .grid .grid__heading'], slot: '--grid-heading-size' },
        { selectors: ['.cta__title'], slot: '--cta-title-size' },
        { selectors: ['.faq__heading', 'main > .faq .faq__heading'], slot: '--faq-heading-size' },
        { selectors: ['.stats__heading'], slot: '--stats-title-size' },
        { selectors: ['.table-section__heading'], slot: '--table-section-heading-size' },
        { selectors: ['.logos__heading'], slot: '--logos-heading-size' },
        { selectors: ['.embed__heading'], slot: '--embed-heading-size' },
        { selectors: ['.testimonials__heading'], slot: '--testimonials-heading-size' },
    ];

    // 1. Every band-heading font-size routes through its slot AND falls back to the
    //    shared --pp-band-heading-size — at every declaration site (base + premium).
    test.each(BAND_HEADINGS)('$slot heading routes font-size through its slot to var(--pp-band-heading-size)', ({ selectors, slot }) => {
        // Per-selector (not summed): EACH listed selector must carry at least one
        // font-size declaration AND every such declaration must route the slot to
        // the shared scale. A summed count would let one selector's two decls mask
        // another selector that dropped its font-size entirely.
        selectors.forEach(sel => {
            const decls = bodiesForExactSelector(sel).flatMap(b => b.match(/font-size\s*:[^;}]+/g) || []);
            expect(decls.length, `${sel} has no font-size declaration`).toBeGreaterThanOrEqual(1);
            decls.forEach(d => {
                expect(d).toMatch(new RegExp('font-size\\s*:\\s*var\\(\\s*' + slot + '\\s*,\\s*var\\(\\s*--pp-band-heading-size\\s*\\)'));
            });
        });
    });

    // 2. `inherit` never appears as a heading font-size fallback anywhere in
    //    components.css — this is the exact regression that shipped the 16px
    //    collapse. Body-copy slots (e.g. --cta-body-size) may still inherit;
    //    this pin is scoped to the band-heading slots above.
    test('no band-heading font-size falls back to inherit', () => {
        const stripped = stripComments(COMPONENTS_CSS);
        const headingSlots = BAND_HEADINGS.map(h => h.slot);
        headingSlots.forEach(slot => {
            const re = new RegExp('font-size\\s*:\\s*var\\(\\s*' + slot + '\\s*,\\s*inherit\\s*\\)');
            expect(stripped).not.toMatch(re);
        });
    });

    // 3. The shared scale is actually defined in base.css as a fluid clamp with a
    //    mobile floor >= 1.5rem (never body size) and the prior desktop ceiling,
    //    so the routing resolves and a rename breaks these pins loudly.
    test('base.css defines --pp-band-heading-size as a fluid clamp', () => {
        const base = stripComments(BASE_CSS);
        const m = base.match(/--pp-band-heading-size\s*:\s*clamp\(\s*([0-9.]+)rem\s*,[^,]+,\s*([0-9.]+)rem\s*\)/);
        expect(m, 'expected --pp-band-heading-size: clamp(floor, preferred, ceiling) in base.css').not.toBeNull();
        // Mobile floor never collapses into body size.
        expect(parseFloat(m[1])).toBeGreaterThanOrEqual(1.5);
        // Ceiling stays at the prior desktop-clamp ceiling.
        expect(parseFloat(m[2])).toBeCloseTo(2.62, 2);
    });

    // 4. The former per-component heading literals are gone from components.css —
    //    a heading that pastes a literal instead of consuming the shared scale
    //    fails this pin. (2.25rem lived on section text-only + the desktop clamp
    //    floor; 1.875rem on faq/stats.) The clamp(2.25rem,3vw,2.62rem) desktop
    //    fallback is likewise fully replaced by the shared prop.
    test('former per-heading size literals no longer appear in components.css', () => {
        const stripped = stripComments(COMPONENTS_CSS);
        expect(stripped).not.toMatch(/clamp\(\s*2\.25rem\s*,\s*3vw\s*,\s*2\.62rem\s*\)/);
        expect(stripped).not.toMatch(/font-size\s*:\s*var\(\s*--[a-z-]+-(?:title|heading)-size\s*,\s*1\.875rem\s*\)/);
        expect(stripped).not.toMatch(/font-size\s*:\s*var\(\s*--section-title-size\s*,\s*2\.25rem\s*\)/);
    });
});

/**
 * Schema/token truthfulness pin (#438). Every global token a component schema
 * lists in `styling.tokens` must actually be CONSUMED as `var(--token …)`
 * somewhere in the theme CSS — otherwise the schema documents a styling surface
 * the renderer never reads. The #438 audit found logos already truthful (it lists
 * --space-2xl/3xl, consumed by the shared data-pp-spacing rules, not its own
 * block), so the check is a GLOBAL consumption scan across components/base/
 * utilities CSS, not block-scoped. A schema that adds a token no rule reads, or a
 * CSS edit that drops the last consumer of a listed token, fails here loudly.
 */
describe('CSS lint: schema styling.tokens resolve to a consumed var (#438)', () => {
    const ALL_CSS = stripComments(COMPONENTS_CSS) + '\n' +
        stripComments(BASE_CSS) + '\n' + stripComments(UTILITIES_CSS);

    // Auto-discover every component schema so a new component is covered without
    // touching this list (same posture as StyleSlotContractTest discovery).
    const componentsDir = path.resolve(__dirname, '../../components');
    const schemaTokens = [];
    fs.readdirSync(componentsDir, { withFileTypes: true })
        .filter(d => d.isDirectory())
        .forEach(d => {
            const schemaPath = path.join(componentsDir, d.name, 'schema.json');
            if (!fs.existsSync(schemaPath)) return;
            const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf-8'));
            const tokens = schema.styling?.tokens || [];
            tokens.forEach(token => schemaTokens.push({ component: d.name, token }));
        });

    // Fail-closed floor: if discovery breaks (moved dir, renamed schemas), the
    // per-token loop would pass vacuously over an empty list.
    test('discovery finds schema tokens to check', () => {
        expect(schemaTokens.length).toBeGreaterThanOrEqual(10);
    });

    test.each(schemaTokens)('$component token $token is consumed as var(--…) in the theme CSS', ({ token }) => {
        // Every token is a custom property; assert it appears in a var() consumption
        // (var(--token) or var(--token, fallback)) somewhere in the theme CSS.
        const re = new RegExp('var\\(\\s*' + token.replace(/[-]/g, '\\$&') + '\\s*[,)]');
        expect(ALL_CSS).toMatch(re);
    });
});

/**
 * Band heading-color slot routing pin (#438). table/logos/embed gained a
 * --<comp>-heading-color slot. The keystone StyleSlotContractTest only proves the
 * slot is consumed SOMEWHERE in the component block (satisfied by the base rule),
 * and the #61 dark-surface guard's variant whitelist excludes logos/embed — so a
 * revert of the INVERTED-variant heading color to a hardcoded literal would leave
 * every other test green while silently killing the slot on the inverted variant.
 * This pins both the base rule (fallback var(--color-text), the h2 default, so unset
 * output is unchanged) AND the inverted-variant rules (fallback var(--color-bg)).
 */
describe('CSS lint: band heading-color slots route through the slot (#438)', () => {
    // Brace-matched extraction of every rule whose selector is EXACTLY `selector`.
    // Same technique as the #431 suite; the `[{};,]` prefix isolates the base
    // `.logos__heading` rule from the descendant `.logos--inverted .logos__heading`.
    function bodiesForExactSelector(selector) {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const re = new RegExp(
            '[{};,]\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{',
            'g'
        );
        const bodies = [];
        let match;
        while ((match = re.exec(css)) !== null) {
            let i = re.lastIndex;
            let depth = 1;
            const start = i;
            while (i < css.length && depth > 0) {
                if (css[i] === '{') depth++;
                else if (css[i] === '}') depth--;
                i++;
            }
            bodies.push(css.slice(start, i - 1));
        }
        return bodies;
    }

    // selector, its heading-color slot, and the fallback that preserves unset output.
    const HEADING_COLOR_RULES = [
        { selector: '.table-section__heading', slot: '--table-section-heading-color', fallback: '--color-text' },
        { selector: '.logos__heading', slot: '--logos-heading-color', fallback: '--color-text' },
        { selector: '.embed__heading', slot: '--embed-heading-color', fallback: '--color-text' },
        { selector: '.logos--inverted .logos__heading', slot: '--logos-heading-color', fallback: '--color-bg' },
        { selector: '.embed--inverted .embed__heading', slot: '--embed-heading-color', fallback: '--color-bg' },
    ];

    test.each(HEADING_COLOR_RULES)('$selector routes color through $slot to var($fallback)', ({ selector, slot, fallback }) => {
        const bodies = bodiesForExactSelector(selector);
        expect(bodies.length, `no exact rule for ${selector}`).toBeGreaterThanOrEqual(1);
        const colorDecls = bodies.flatMap(b => b.match(/color\s*:[^;}]+/g) || [])
            .filter(d => /^\s*color\s*:/.test(d)); // exclude background-color etc.
        expect(colorDecls.length, `${selector} declares no color`).toBeGreaterThanOrEqual(1);
        colorDecls.forEach(d => {
            expect(d).toMatch(new RegExp('color\\s*:\\s*var\\(\\s*' + slot + '\\s*,\\s*var\\(\\s*' + fallback + '\\s*\\)'));
        });
    });
});

describe('CSS lint: no raw hex in components.css', () => {
    test('components.css has no raw hex color values', () => {
        const stripped = stripComments(COMPONENTS_CSS);
        // Match #rgb, #rrggbb, #rrggbbaa patterns in property values.
        // Exclude selectors (lines starting with . # or element names).
        const lines = stripped.split('\n');
        const hexInValues = lines.filter(line => {
            const trimmed = line.trim();
            // Skip selectors, empty lines, closing braces, media queries.
            if (!trimmed || trimmed.startsWith('.') || trimmed.startsWith('#') ||
                trimmed === '}' || trimmed === '{' || trimmed.startsWith('@') ||
                trimmed.startsWith('/*') || trimmed.startsWith('*')) {
                return false;
            }
            // Look for hex values in property declarations.
            return /:\s*.*#[0-9a-fA-F]{3,8}\b/.test(trimmed);
        });
        expect(hexInValues).toEqual([]);
    });
});

/**
 * Centered content blocks carry auto inline margins (#367, class of #354).
 *
 * A block-level content element (a heading/title/body/content wrapper) that
 * carries BOTH `text-align: center` AND a `max-width` cap but NO auto inline
 * margins is falsely centered: the block fills to its cap and pins to its
 * container's LEFT edge, and text-align only centers the text WITHIN that
 * left-pinned box. That is exactly how `.stats__heading` shipped (#367).
 *
 * This is a DECLARATION-LEVEL backstop, not a cascade-aware layout engine. It
 * aggregates per exact selector string and is blind to specificity, source
 * order, media context, and INHERITED (undeclared) text-align. The authoritative
 * proof lives in the rendered e2e pins (#354, #367 in style-render.spec.ts),
 * which measure real boxes under the full cascade. What this adds cheaply: the
 * next centered heading that declares a cap + center on one selector but forgets
 * its auto margins fails here before it reaches a browser.
 *
 * Coverage note (why #354 needs its OWN targeted pin below, not the class scan):
 * the class scan only fires when cap AND center land on the SAME selector key.
 * `.stats__heading` does (cap @ the shared rule, center @ its own rule), so the
 * scan catches #367. The #354 selector `.section--centered .section__content`
 * does NOT — its centering is INHERITED from `.section--centered .section__body`,
 * never declared on `.section__content`, so declaration aggregation can never
 * mark it center=true. It is pinned directly instead.
 *
 * Scope is the content-block naming (trailing `__heading` / `__title` /
 * `__body` / `__content` element), which is the surface this defect lives on.
 * Controls are deliberately out: `main .btn` also matches text-align:center +
 * max-width, but its box is positioned by its flex parent (display:inline-flex,
 * justify-content:center) and text-align centers its own LABEL — auto margins
 * are neither the mechanism nor expected there. Excluding it by name (a button
 * is not a `__heading`) is correct, not a carve-out. The known live tradeoff: a
 * future content block centered by a flex/grid PARENT (the `.btn` mechanism
 * under a content-block name) would be a spurious offender; whitelist it here
 * with a comment if one ever appears.
 */
describe('CSS lint: centered content blocks carry auto inline margins (#367)', () => {
    const stripped = stripComments(COMPONENTS_CSS);

    // selector -> { maxWidth, center, autoMargin } aggregated across every rule.
    const AUTO_MARGIN =
        /margin(-inline(-start|-end)?|-left|-right)?\s*:\s*[^;}]*\bauto\b/;
    const agg = new Map();
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(stripped)) !== null) {
        const body = m[2];
        const hasMax = /(max-width|max-inline-size)\s*:/.test(body);
        const hasCenter = /text-align\s*:\s*center/.test(body);
        const hasAuto = AUTO_MARGIN.test(body);
        if (!hasMax && !hasCenter && !hasAuto) continue;
        m[1].split(',').forEach(raw => {
            const sel = raw.trim().replace(/\s+/g, ' ');
            if (!sel || sel.startsWith('@')) return;
            const cur = agg.get(sel) || { maxWidth: false, center: false, autoMargin: false };
            cur.maxWidth = cur.maxWidth || hasMax;
            cur.center = cur.center || hasCenter;
            cur.autoMargin = cur.autoMargin || hasAuto;
            agg.set(sel, cur);
        });
    }

    // A content-block selector = the element it TARGETS (the trailing compound of
    // a descendant selector) is a __heading/__title/__body/__content element. Test
    // only the last space-separated compound so `.foo__heading .child` (targets
    // `.child`) is NOT pulled in, while `.section--centered .section__body` (targets
    // `.section__body`) is. Word-boundaried so `.stats__heading-accent` never matches.
    const isContentBlock = (sel) => {
        const target = sel.split(' ').pop();
        return /__(heading|title|body|content)(?![\w-])/.test(target);
    };

    const centeredCapped = [...agg.entries()]
        .filter(([sel, p]) => isContentBlock(sel) && p.maxWidth && p.center);

    // Not vacuous: `.stats__heading` (cap+center on one key) MUST be a member, or a
    // selector rename would make the offender scan below silently pass on nothing.
    test('finds the centered, max-width-capped content blocks it governs', () => {
        expect(centeredCapped.length).toBeGreaterThan(0);
        expect(centeredCapped.map(([sel]) => sel)).toContain('.stats__heading');
    });

    test('every centered, capped content block also declares an auto inline margin', () => {
        const offenders = centeredCapped
            .filter(([, p]) => !p.autoMargin)
            .map(([sel]) => sel);
        expect(offenders).toEqual([]);
    });

    // Targeted regression pin for the exact #367 selector: it must keep BOTH the cap+center
    // that make centering meaningful AND the auto margin that delivers it. Removing the
    // auto margins (the bug) fails here even if the class scan above were ever narrowed.
    test('.stats__heading is centered, capped, and carries an auto inline margin', () => {
        const p = agg.get('.stats__heading');
        expect(p).toBeDefined();
        expect(p.maxWidth).toBe(true);
        expect(p.center).toBe(true);
        expect(p.autoMargin).toBe(true);
    });

    // Targeted regression pin for the landed #354 fix, which the class scan cannot see
    // (its centering is inherited, never declared on this selector). The auto side-margins
    // are the whole fix — if they are ever removed, the centered-layout body copy left-pins
    // again. The #354 e2e pin proves the rendered geometry; this guards the declaration.
    test('.section--centered .section__content carries an auto inline margin (#354)', () => {
        const p = agg.get('.section--centered .section__content');
        expect(p).toBeDefined();
        expect(p.autoMargin).toBe(true);
    });
});

/**
 * No ID selectors in shipped stylesheets (#412).
 *
 * Shipped component CSS must contain no page-specific styling. An ID selector
 * (`#home-cta`, `#home-hero`, ...) matches a demo/starter page by its authored id
 * at [1,x,y] specificity that no real site can see or override, and some base
 * behaviors used to work ONLY via those selectors. This guard evicts the class once
 * and forbids its return: any `#id` in the SELECTOR text of components.css / base.css
 * / utilities.css fails the build.
 *
 * Parses SELECTORS, not a bare `#` grep: hex color values (`#fcfdff`) live in
 * declaration bodies, and `#anchor` fragments live inside attribute selectors
 * (`[href="#top"]`) — both are stripped before matching so they never false-positive.
 *
 * The waiver ledger is SHRINK-ONLY and expected EMPTY. If a genuinely irreducible ID
 * selector is ever proven necessary (none today), add it here with a citing comment
 * and update the size pin in the same change — a count RISE or a NEW entry fails.
 */
describe('CSS lint: no ID selectors in shipped stylesheets (#412)', () => {
    // Every `#id` appearing in a SELECTOR (never a value). Walks the CSS, captures the
    // prelude of each style rule, drops at-rule preludes (@media/@supports/@keyframes —
    // none carry an id selector), strips attribute selectors so `[href="#x"]` /
    // `[style*="#fff"]` never match, then flags `#<ident>` not escaped as `\#`.
    function idSelectors(css) {
        const stripped = stripComments(css);
        const offenders = [];
        // Innermost-or-nested style rules and at-rule preludes, mirroring rulesMatching's
        // tokenizer: group 1 = at-rule name (prelude is NOT a selector), group 3 = a style
        // rule's selector list.
        const pattern = /@([\w-]+)([^{;]*)\{|([^{}]+)\{([^{}]*)\}|\}|;/g;
        let match;
        while ((match = pattern.exec(stripped)) !== null) {
            if (match[3] === undefined) continue; // at-rule prelude, closing brace, or `;`
            const selectorList = match[3]
                // Drop attribute selectors: `[href="#top"]`, `[style*="#fff"]`.
                .replace(/\[[^\]]*\]/g, '')
                // Neutralize escaped hashes `\#` (a literal `#` in an ident, not an id).
                .replace(/\\#/g, '');
            selectorList.split(',').forEach(sel => {
                const s = sel.trim();
                // After attribute selectors and escaped `\#` are removed above, a `#` in
                // SELECTOR text can only begin an id selector — `#` never appears elsewhere
                // in a standard selector (combinators, pseudo-classes, and nesting `&` use
                // no `#`). So match an id token ANYWHERE in the compound, not only when it
                // leads (a trailing id like `.btn#home-cta` or `a#x` must fail too — the
                // guard's contract is "any #id in the selector text").
                if (/#[A-Za-z_-]/.test(s)) {
                    offenders.push(s.replace(/\s+/g, ' '));
                }
            });
        }
        return offenders;
    }

    // SHRINK-ONLY waiver ledger — expected empty. A remaining, genuinely-irreducible id
    // selector would be listed here (verbatim, normalized) with a citing comment.
    const ID_SELECTOR_WAIVERS = [];

    test.each([
        ['components.css', COMPONENTS_CSS],
        ['base.css', BASE_CSS],
        ['utilities.css', UTILITIES_CSS],
    ])('%s contains no ID selectors', (name, css) => {
        const offenders = idSelectors(css).filter(s => !ID_SELECTOR_WAIVERS.includes(s));
        expect(offenders).toEqual([]);
    });

    test('the waiver ledger is empty (shrink-only; a new entry must be justified + pinned)', () => {
        expect(ID_SELECTOR_WAIVERS.length).toBe(0);
    });

    // Detection proof (mirrors StyleSlotContractTest's testGuardDetectsTheDeadSlotClass):
    // the parser must CATCH an id selector and must NOT be fooled by hex values or
    // attribute-embedded fragments. Without this, a parser regression could pass the
    // real-file scans vacuously.
    test('detector flags an id selector but ignores hex values and href fragments', () => {
        expect(idSelectors('#home-cta { color: red; }')).toEqual(['#home-cta']);
        expect(idSelectors('.cta__button:not(.btn--ghost) #x .y { top: 0; }')).toEqual([
            '.cta__button:not(.btn--ghost) #x .y',
        ]);
        // Trailing-id compounds (id NOT first in the simple-selector sequence) must fail too.
        expect(idSelectors('.btn#home-cta { top: 0; }')).toEqual(['.btn#home-cta']);
        expect(idSelectors('a#x { top: 0; }')).toEqual(['a#x']);
        expect(idSelectors('main .cta__button:not(.btn--outline)#foo { top: 0; }')).toEqual([
            'main .cta__button:not(.btn--outline)#foo',
        ]);
        // Hex color value in a body — not a selector.
        expect(idSelectors('.a { color: #fcfdff; background: #fff; }')).toEqual([]);
        // Anchor fragment inside an attribute selector — not an id selector.
        expect(idSelectors('a[href="#top"] { color: blue; }')).toEqual([]);
        expect(idSelectors('[style*="#fff"] { border: 0; }')).toEqual([]);
        // Nested in @media — still caught.
        expect(idSelectors('@media (min-width: 768px) { #home-hero .btn { width: auto; } }'))
            .toEqual(['#home-hero .btn']);
    });
});

describe('CSS lint: inverted dark-band links route through the on-inverted accent role (#437)', () => {
    // The light-surface accent (--color-accent) measures only 3.23:1 on the dark
    // inverted band and fails WCAG AA for body text. Every inverted variant whose
    // links sit DIRECTLY on the dark band must remap `a` color to the
    // --color-accent-on-inverted role (hover → --color-accent-on-inverted-hover).
    //
    // Deliberately NOT enumerated here:
    //  - Light card/panel inverted variants (grid, faq, testimonials-grid) keep their
    //    light `.grid__item`/`.faq__item`/`.testimonials__item` background, so links
    //    there stay on --color-accent (already AA on a light card). Routing them
    //    through the light on-inverted tint would drop them to ~2:1. The
    //    rendered-contrast E2E covers those directly.
    //  - grid cards and the testimonials GRID layout keep their light card even on
    //    the inverted band, so their body links stay on --color-accent (AA on the
    //    light card). Routing them through the on-inverted tint would drop them to
    //    ~2:1. The rendered-contrast E2E covers those directly.
    //
    // Since #439, cta.text and testimonials.quote render an inline-HTML subset
    // (a/strong/em/br), so both CAN now carry a real body link. Where that link
    // sits DIRECTLY on the dark band it must be remapped: cta__body always sits on
    // the band, and the testimonials quote sits on the band in the STACK layout
    // (transparent card). The CTA button (.cta__button) is untouched — the remap is
    // scoped to .cta__body, and the premium `main .btn` cascade out-orders it anyway.
    const css = stripComments(COMPONENTS_CSS);

    // The dark-band inverted variants that actually render body-link markup
    // (wp_kses_post body/content): the link color routes through the on-inverted role
    // (hover → on-inverted-hover). Buttons (.btn) are never affected — the premium
    // `main .btn` cascade out-orders these (0,1,1) rules.
    const DARK_BAND_LINK_VARIANTS = [
        '.pp-section--inverted a',
        '.embed--inverted a',
        // #439: inline-HTML supporting-text surfaces that sit directly on the dark band.
        '.cta--inverted .cta__body a',
        '.testimonials--inverted.testimonials--stack .testimonials__quote a',
    ];

    function ruleBody(selector) {
        const esc = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        // `a\s*\{` so the color rule is matched, never the sibling `a:hover {`.
        const m = css.match(new RegExp(esc + '\\s*\\{([^}]*)\\}'));
        return m ? m[1] : null;
    }

    DARK_BAND_LINK_VARIANTS.forEach(selector => {
        test(`${selector} remaps link color to --color-accent-on-inverted`, () => {
            const body = ruleBody(selector);
            expect(body).not.toBeNull();
            expect(body).toMatch(/color:\s*var\([^;]*--color-accent-on-inverted\b/);
            // Must NOT fall back to the bare light-surface accent as the default.
            expect(body).not.toMatch(/var\(\s*--color-accent\s*[,)]/);
        });

        test(`${selector} defines a hover routed through --color-accent-on-inverted-hover`, () => {
            const hoverBody = ruleBody(`${selector}:hover`);
            expect(hoverBody).not.toBeNull();
            expect(hoverBody).toMatch(/--color-accent-on-inverted-hover\b/);
        });
    });

    test('inverted stats numbers route the fallback through --color-accent-on-inverted', () => {
        const esc = '.stats--inverted .stats__number'.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const m = css.match(new RegExp(esc + '\\s*\\{([^}]*)\\}'));
        expect(m).not.toBeNull();
        // Slot still wins; the DEFAULT (fallback) is the on-inverted role, not the
        // failing --color-accent.
        expect(m[1]).toMatch(/var\(\s*--stats-number-color\s*,\s*var\([^;]*--color-accent-on-inverted\b/);
    });

    test('the on-inverted accent tokens are defined in base.css :root', () => {
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted:\s*#[0-9a-fA-F]{6}/);
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted-hover:\s*#[0-9a-fA-F]{6}/);
    });
});

/**
 * Token contract: the global button surface must not drift (#441).
 *
 * The `--btn-*` tokens are consumed by the base `.btn` rules and the CTA/hero
 * fallback chains, but for a long time only `--btn-padding-*` / `--btn-radius`
 * were REGISTERED in base.css's first `:root` block — the block that
 * `pp_design_tokens()` parses and that the AI reads as the authorable token
 * registry (lib/wp.php:608, lib/ai-context.php:152). A consumed-but-unregistered
 * token is contract drift in the discoverable direction: the color surface exists
 * and works, but no AI or rethemer can find it, so they fall back to per-component
 * `--cta-button-*` rescues.
 *
 * This guard binds the two sets together: EVERY `--btn-*` custom property consumed
 * anywhere in the theme CSS must be either (a) registered in the FIRST `:root`
 * token block of base.css (the public token contract) or (b) `--pp-`-prefixed (the
 * internal-token convention, issue 431). A future `.btn` refactor that introduces
 * a new `--btn-foo` without registering or `--pp-`-prefixing it fails here, at
 * lint time, instead of silently leaving the contract behind.
 *
 * `registeredTokensFromFirstRoot` mirrors the PHP parser's scope exactly: the
 * regex `/:root\s*\{([^}]+)\}/` in pp_design_tokens() matches the FIRST `:root`
 * block up to the first `}`, so the later `--pp-band-*` :root block (deliberately
 * internal) is out of scope for both the parser and this test.
 */
describe('CSS lint: #441 global button token contract (consumed ⊆ registered∪--pp-)', () => {
    // Mirror pp_design_tokens(): the first `:root { ... }` block, up to its first `}`.
    // Comments are NOT stripped first — the PHP parser also runs against the raw
    // block — but a `}` never appears inside a token comment, so the boundary holds.
    function firstRootBlock(css) {
        const m = css.match(/:root\s*\{([^}]+)\}/);
        return m ? m[1] : '';
    }

    // Custom-property NAMES declared in a block: `--foo:` (a declaration, not a var() use).
    function declaredNames(block) {
        const names = new Set();
        const re = /(--[\w-]+)\s*:/g;
        let m;
        while ((m = re.exec(block)) !== null) names.add(m[1]);
        return names;
    }

    // Every `--btn-*` custom property NAME that appears in real CSS (comments stripped),
    // whether as a declaration or inside a var() reference.
    function consumedBtnNames(...cssSources) {
        const names = new Set();
        for (const css of cssSources) {
            const re = /--btn-[\w-]+/g;
            let m;
            while ((m = re.exec(stripComments(css))) !== null) names.add(m[0]);
        }
        return names;
    }

    const registered = declaredNames(firstRootBlock(BASE_CSS));
    const consumed = consumedBtnNames(BASE_CSS, COMPONENTS_CSS, UTILITIES_CSS);

    test('the first :root block is found and non-trivial (guards against a vacuous pass)', () => {
        expect(registered.size).toBeGreaterThan(10);
        expect(consumed.size).toBeGreaterThan(0);
    });

    test('every consumed --btn-* token is registered in the first :root block or --pp--prefixed', () => {
        const orphans = [...consumed].filter(
            name => !registered.has(name) && !name.startsWith('--pp-'),
        );
        expect(orphans).toEqual([]);
    });

    test('the global button surface tokens are registered as unset-by-default knobs (#458)', () => {
        // #458 rerouted the premium/.cta/.hero primary-button cascade through --btn-*. For a
        // SET token to override those rules while an UNSET one stays byte-identical, the token
        // must fall through — so --btn-bg / --btn-border-color / --btn-shadow register as
        // `initial` (each consuming rule then resolves its own literal until the token is set).
        // This supersedes #441's concrete registration, which assumed the premium cascade was
        // NOT rerouted. --btn-text keeps its concrete default because its value already equals
        // the universal ink literal every button rule falls back to, so it is byte-identical
        // AND overridable without flipping to `initial`.
        const root = firstRootBlock(BASE_CSS);
        expect(root).toMatch(/--btn-bg:\s*initial/);
        // The intentional inversion coupling: button ink defaults to the PAGE background.
        expect(root).toMatch(/--btn-text:\s*var\(--color-bg\)/);
        expect(root).toMatch(/--btn-border-color:\s*initial/);
        expect(root).toMatch(/--btn-shadow:\s*initial/);
    });

    test('the registered button color tokens carry their annotated type comment', () => {
        // pp_design_tokens() derives each token's type from a `/* type: ... */` comment;
        // without it the token exposes a null type and the AI cannot validate authored values.
        expect(BASE_CSS).toMatch(/--btn-bg:[^;]*;\s*\/\*\s*color:/);
        expect(BASE_CSS).toMatch(/--btn-text:[^;]*;\s*\/\*\s*color:/);
        expect(BASE_CSS).toMatch(/--btn-border-color:[^;]*;\s*\/\*\s*color:/);
        expect(BASE_CSS).toMatch(/--btn-shadow:[^;]*;\s*\/\*\s*shadow:/);
    });

    // Detection proof: the contract check must CATCH an unregistered --btn-* and PASS
    // a registered one, so a parser drift can't make the scan silently vacuous.
    test('detector flags an unregistered --btn-* and passes a registered/--pp- one', () => {
        const root = ':root { --btn-bg: var(--color-accent); }';
        const reg = declaredNames(firstRootBlock(root));

        // An orphan consumed token (used in a rule, never registered) is caught.
        const withOrphan = consumedBtnNames('.btn { color: var(--btn-unregistered, red); }');
        const orphans = [...withOrphan].filter(n => !reg.has(n) && !n.startsWith('--pp-'));
        expect(orphans).toEqual(['--btn-unregistered']);

        // A registered token and a --pp--prefixed token both pass.
        const okConsumed = consumedBtnNames('.btn { background: var(--btn-bg); padding: var(--pp-btn-x); }');
        const okOrphans = [...okConsumed].filter(n => !reg.has(n) && !n.startsWith('--pp-'));
        expect(okOrphans).toEqual([]);
    });
});
