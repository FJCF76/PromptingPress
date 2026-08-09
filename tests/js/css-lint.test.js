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
// EVERY rule in components.css, in source order, each tagged with its @media context
// (null at top level) and its source `index`. `rulesMatching` filters this; the #542
// focus-ring guards consume it whole, because their "no OTHER rule sets outline-color"
// pin has to see rules that do not mention the needle at all. One parser, so a
// media-aware caller and a whole-file caller cannot drift apart.
function parseRules(css = stripComments(COMPONENTS_CSS)) {
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
            // Nearest enclosing @media, looking outward past non-media at-rules.
            const media = [...stack].reverse().find(m => m !== null) ?? null;
            rules.push({ selectors, body: match[4], media, index: match.index });
        } else if (match[0] === '}') {
            stack.pop();
        }
    }
    return rules;
}

function rulesMatching(needle) {
    // Class-boundary match, so `.hero__eyebrow` never swallows `.hero__eyebrow--lg`.
    const boundary = new RegExp(
        needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![\\w-])'
    );
    return parseRules().filter(r => r.selectors.some(s => boundary.test(s)));
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

    test('hero/section/grid/cta schemas declare 174 style slots (subset of the total)', () => {
        // 166 -> 172 (#584): +1 hero heading rhythm, +2 hero primary ring slots,
        // +2 section panel-CTA ring slots, +1 cta heading rhythm.
        // 172 -> 174 (#581): the two state twins — grid's --grid-item-link-hover-color
        // and cta's --cta-button2-shadow.
        expect(allSlots.length).toBe(174);
    });

    allSlots.forEach(({ component, slotName }) => {
        test(`${slotName} is consumed with a fallback (or as a guaranteed-invalid re-point) in components.css`, () => {
            const escaped = slotName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            // The ordinary contract: var(--slot, <fallback>) — the comma is what keeps
            // unset output byte-identical.
            const withFallback = new RegExp(`var\\(${escaped},`);
            // The nested-button isolation idiom (#526 / #530 / #581) is the ONE other way
            // a slot legitimately reaches the cascade with no comma of its own:
            //     --other-slot: var(--this-slot);
            // A var() that cannot substitute makes the DECLARED property guaranteed-invalid,
            // so with this slot unset every downstream var(--other-slot, <fallback>) takes
            // ITS fallback. Unset output is preserved by the mechanism rather than by a
            // local comma — a stronger guarantee, not a weaker one, because it also stops
            // the outer slot inheriting down onto the nested button. The OUTER property must
            // itself be a DECLARED slot, not any custom property: `--anything: var(--x);` on
            // a name no rule ever reads would otherwise satisfy this pin and let a genuinely
            // dead slot through the very check that exists to catch dead slots. Constraining
            // it to the declared set makes the guarantee real, because the outer slot's own
            // case in this same loop independently proves IT is consumed with a fallback.
            const declaredNames = allSlots
                .map(s => s.slotName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
                .join('|');
            const asRepoint = new RegExp(`(?:${declaredNames}):\\s*var\\(${escaped}\\)\\s*;`);
            expect(
                withFallback.test(stripped) || asRepoint.test(stripped),
                `${slotName} is declared by ${component}/schema.json but components.css never ` +
                `consumes it as var(${slotName}, <fallback>) nor re-points another slot at it.`
            ).toBe(true);
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
 * shorthand cannot re-defeat the flat-button slot. Issue 514 extends the rest chain
 * to lead with --hero-button-bg (the hero primary's visible fill winner) with
 * --cta-button-bg still next; both slots must survive in the chain.
 */
describe('CSS lint: premium primary-button fill routes through the fill-slot chain (#412/#514)', () => {
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

    test('every primary-fill background routes through the per-instance fill slot chain', () => {
        // Rest fill leads with --section-panel-cta-bg (issue 536: the section panel CTA's
        // ONLY fill winner — it has no .hero/.cta ancestor, so nothing else can route it),
        // then --hero-button-bg (issue 514) then --cta-button-bg. All three must be present
        // so dropping ANY of them is caught. Hover mirrors that chain since issue 530:
        // --hero-button-hover-bg leads, --cta-button-hover-bg follows. Before #530 the hover
        // branch required only the cta slot, which is why the hover surface could ship a
        // gradient shorthand that masked every per-instance hover fill slot — the guard was
        // satisfied by a chain that had no hero entry and no second-button re-pointing target.
        const offenders = [];
        surfaceRules.forEach(r => {
            const isHover = /:hover\b/.test(r.selector);
            const requiredSlots = isHover
                ? ['--hero-button-hover-bg', '--cta-button-hover-bg', '--btn-hover-bg']
                : ['--section-panel-cta-bg', '--hero-button-bg', '--cta-button-bg'];
            r.decls.forEach(d => {
                // Leading slot must be the outermost required slot.
                const lead = requiredSlots[0];
                if (!new RegExp('background(?:-image)?\\s*:\\s*var\\(\\s*' + lead + '\\b').test(d)) {
                    offenders.push(`${r.selector} { ${d} } (missing leading ${lead})`);
                }
                // Every required slot must appear somewhere in the chain.
                requiredSlots.forEach(slot => {
                    if (!new RegExp('var\\(\\s*' + slot + '\\b').test(d)) {
                        offenders.push(`${r.selector} { ${d} } (missing ${slot} in chain)`);
                    }
                });
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detection proof: a bare gradient shorthand on the surface must be CAUGHT, and a
    // slot-routed one must PASS — so a parser regression can't make the scan vacuous.
    // The scan runs the SAME requiredSlots logic the real guard uses (issue 530): the
    // previous version hardcoded --cta-button-bg, so dropping a slot from requiredSlots
    // left this "anti-vacuity" proof passing — vacuous with respect to the thing it guards.
    const scanWithRequiredSlots = (fixture, requiredSlots) => {
        const rr = /([^{}]+)\{([^{}]*)\}/g;
        let mm, out = [];
        while ((mm = rr.exec(fixture)) !== null) {
            if (!targetsPrimaryFill(mm[1])) continue;
            fillDecls(mm[2]).forEach(d => {
                const lead = requiredSlots[0];
                if (!new RegExp('background(?:-image)?\\s*:\\s*var\\(\\s*' + lead + '\\b').test(d)) {
                    out.push(d);
                    return;
                }
                if (requiredSlots.some(slot => !new RegExp('var\\(\\s*' + slot + '\\b').test(d))) out.push(d);
            });
        }
        return out;
    };

    test('detector flags a bare gradient shorthand but passes a slot-routed one', () => {
        const rest = ['--section-panel-cta-bg', '--hero-button-bg', '--cta-button-bg'];
        const bad = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background: linear-gradient(red, blue); }';
        const good = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { background: var(--section-panel-cta-bg, var(--hero-button-bg, var(--cta-button-bg, linear-gradient(red, blue)))); }';
        expect(scanWithRequiredSlots(bad, rest).length).toBe(1);
        expect(scanWithRequiredSlots(good, rest).length).toBe(0);
    });

    // The hover surface gets its own proof (issue 530): a chain that leads with the cta slot
    // and omits the hero slot is exactly what shipped before #530 and MUST now be caught.
    test('detector flags a hover chain missing the hero hover slot', () => {
        const hover = ['--hero-button-hover-bg', '--cta-button-hover-bg'];
        const preFix = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover { background: var(--cta-button-hover-bg, linear-gradient(red, blue)); }';
        const fixed = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover { background: var(--hero-button-hover-bg, var(--cta-button-hover-bg, linear-gradient(red, blue))); }';
        expect(scanWithRequiredSlots(preFix, hover).length).toBe(1);
        expect(scanWithRequiredSlots(fixed, hover).length).toBe(0);
    });

    // Issue 539 added the GLOBAL tier to the hover branch's requiredSlots. Its own proof:
    // a chain carrying both per-instance hover slots but no --btn-hover-bg is precisely the
    // pre-#539 shape (a site-wide fill retheme reverting to the theme gradient on hover) and
    // MUST now be caught — otherwise dropping the global tier from the chain goes unnoticed.
    test('detector flags a hover chain missing the global --btn-hover-bg tier', () => {
        const hover = ['--hero-button-hover-bg', '--cta-button-hover-bg', '--btn-hover-bg'];
        const preFix = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover { background: var(--hero-button-hover-bg, var(--cta-button-hover-bg, linear-gradient(red, blue))); }';
        const fixed = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover { background: var(--hero-button-hover-bg, var(--cta-button-hover-bg, var(--btn-hover-bg, linear-gradient(red, blue)))); }';
        expect(scanWithRequiredSlots(preFix, hover).length).toBe(1);
        expect(scanWithRequiredSlots(fixed, hover).length).toBe(0);
    });
});

/**
 * The filled premium button's fill and ring SNAP; nothing else about its motion changes (#540).
 *
 * `main .btn` declares a five-property transition. On a FILLED premium button two of those
 * five animate values the author never chose and never saw. The resting fill is a gradient
 * background-IMAGE, which is not interpolable, so it drops to `none` the moment a flat hover
 * slot resolves the shorthand — exposing the background-COLOR underneath, which the
 * HIGHER-specificity component rules `.hero .btn:not(...)` / `.cta .btn:not(...)` [0,5,0]
 * declare and the gradient had been masking. That masked colour is where the tween starts, in
 * full view. Measured: 7-8 frames (~120ms) across seven flashing configurations spanning the
 * five composed button surfaces. The 1px ring rides the same tween one layer out. The fix
 * scopes a `transition-property` to the filled premium selector that drops BOTH, so the two
 * swap instantly between the two authored states, while box-shadow / color / transform ease on.
 *
 * What this guard defends, in order of how easy it is to lose:
 *   1. the property list itself — re-adding background-color or border-color brings the flash
 *      straight back, and no computed-value test would notice (the SETTLED states are and must
 *      stay identical either way, which is exactly why the flash escaped review for so long);
 *   2. the three properties that must KEEP animating — dropping box-shadow/color/transform here
 *      would silently kill the bevel fade, the ink fade and the hover lift;
 *   3. the SCOPE — outline/ghost/secondary must keep the full five-property list. Their fill and
 *      border are visible at rest, so their tweens ramp between two states the author can see.
 *      Widening this rule to reach them (dropping a `:not()`, or writing the same declaration on
 *      a bare `main .btn`) would freeze honest animation across every non-filled button.
 *
 * Two bypasses this guard was rebuilt to close, both reproduced against the shipping tree:
 *   a. a SECOND declaration in the same rule. `transition: all var(--transition)` written under
 *      the narrowed list re-animates everything and fully restores the flash; a guard that reads
 *      only the first declaration stays green. So every declaration on every matching rule is
 *      checked, and the fix site may carry exactly one.
 *   b. a HIGHER-specificity sibling. The filled treatment is not owned by `main .btn:not(...)`
 *      [0,4,1] alone: `.hero .btn:not(...)` and `.cta .btn:not(...)` sit at [0,5,0] and already
 *      own the background-COLOR half (see the two-rule split documented at components.css:820).
 *      A `transition-property` restored on either of THOSE re-animates the fill and outranks the
 *      fix. So the surface is matched by its `.btn` compound and the three variant `:not()`s, in
 *      any ancestor context, not by a literal `main .btn` prefix.
 */
describe('CSS lint: filled premium button snaps fill + ring, keeps bevel/ink/lift (#540)', () => {
    const VARIANTS = ['.btn--outline', '.btn--ghost', '.btn--secondary'];

    // Split a selector into its COMPOUNDS (whitespace-separated, ignoring spaces inside
    // `:not(...)`). Substring matching is not enough: `.a:not(.btn--outline) .b:not(.btn--ghost)
    // .btn:not(.btn--secondary)` contains `.btn` and all three exclusions yet excludes nothing
    // from the button itself, and would be mistaken for the fix site.
    function compounds(sel) {
        const out = [];
        let buf = '', depth = 0;
        for (const ch of sel.trim()) {
            if (ch === '(') depth++;
            else if (ch === ')') depth--;
            if (/\s/.test(ch) && depth === 0) { if (buf) out.push(buf); buf = ''; continue; }
            buf += ch;
        }
        if (buf) out.push(buf);
        return out;
    }
    // A selector targets the FILLED premium surface when SOME SINGLE compound is `.btn`
    // carrying all three variant exclusions — in ANY ancestor context (`main`, `.hero`,
    // `.cta`, a future one). Order-insensitive: the cascade ignores the :not() order too.
    function isFilledPremium(sel) {
        return compounds(sel).some(c =>
            /(^|[^\w-])\.btn(?=[:.]|$)/.test(c) && VARIANTS.every(v => c.includes(`:not(${v})`)),
        );
    }
    // A selector REACHES the transparent variants when it targets the shared `.btn` surface
    // without excluding them. The boundary matters: `main .btn__icon` is an inner element, not
    // a button surface, and flagging it would train contributors to weaken this guard.
    function reachesVariants(sel) {
        const btnCompounds = compounds(sel).filter(c => /(^|[^\w-])\.btn(?=[:.]|$)/.test(c));
        if (!btnCompounds.length) return false;
        return btnCompounds.some(c => !VARIANTS.every(v => c.includes(`:not(${v})`)));
    }

    // Property names a `transition` / `transition-property` value sets: the first token of each
    // comma-separated part (everything after it is duration/timing/delay).
    function propsOf(decl) {
        return decl
            .replace(/^transition(?:-property)?\s*:/i, '')
            .split(',')
            .map(part => (part.trim().split(/\s+/)[0] || '').toLowerCase())
            .filter(Boolean);
    }
    function transitionDecls(body) {
        return (body.match(/(?<![-a-z])transition(?:-property)?\s*:[^;}]+/gi) || []).map(d => d.trim());
    }
    // Built on the SHARED parseRules(), not a private regex: it is media-aware, and
    // components.css already redeclares `main .btn` inside a max-width block. A media-blind
    // scan would mis-attribute a mobile-scoped rule to the top-level surface.
    function collectTransitionRules(rules) {
        const out = [];
        rules.forEach(r => {
            const decls = transitionDecls(r.body);
            if (decls.length) out.push({ selectors: r.selectors, media: r.media, decls });
        });
        return out;
    }
    // Same collection from raw CSS text, for the synthetic fixtures in the detector self-proof.
    const collectFromCss = css => collectTransitionRules(parseRules(stripComments(css)));

    const transitionRules = collectTransitionRules(parseRules());
    const filledRules = transitionRules.filter(r => r.selectors.some(isFilledPremium));

    // Guard against a vacuous pass: delete the declaration and every assertion below would
    // iterate an empty array and pass. Exactly one rule carries the narrowed list today.
    test('exactly one rule declares a transition list on the filled premium surface', () => {
        expect(filledRules.length).toBe(1);
    });

    // Bypass (a): one declaration per rule, so a later shorthand cannot quietly win.
    test('the fix site carries exactly one transition declaration', () => {
        expect(filledRules[0].decls.length).toBe(1);
    });

    // Every declaration on every filled-premium rule, in every media context — this is what
    // closes bypasses (a) and (b) together.
    const filledProps = filledRules.flatMap(r => r.decls.flatMap(propsOf));

    test('no filled premium transition list animates background-color or border-color', () => {
        expect(filledProps).not.toContain('background-color');
        expect(filledProps).not.toContain('border-color');
        // `all` / `background` would re-animate the fill through the back door.
        expect(filledProps).not.toContain('all');
        expect(filledProps).not.toContain('background');
    });

    test('the filled premium list keeps box-shadow, color and transform animating', () => {
        expect(filledProps).toContain('box-shadow');
        expect(filledProps).toContain('color');
        expect(filledProps).toContain('transform');
    });

    // The shared `main .btn` list is what outline/ghost/secondary inherit. If the fill/ring were
    // dropped THERE instead, the filled-button pins above would still pass while every
    // transparent variant silently lost its honest tween.
    test('the shared .btn transition still animates background-color and border-color', () => {
        const shared = transitionRules.filter(r =>
            r.selectors.some(sel => /(^|\s)main\s+\.btn\s*$/.test(sel.trim())),
        );
        expect(shared.length).toBeGreaterThanOrEqual(1);
        const props = shared.flatMap(r => r.decls.flatMap(propsOf));
        expect(props).toContain('background-color');
        expect(props).toContain('border-color');
    });

    test('no rule reaching outline/ghost/secondary narrows the transition away from the fill', () => {
        const offenders = [];
        transitionRules.forEach(r => {
            r.selectors.forEach(sel => {
                if (!reachesVariants(sel)) return;
                r.decls.forEach(d => {
                    const props = propsOf(d);
                    // A rule that names properties but omits the fill/ring would strip the
                    // transparent variants' tween. `all` is fine here — it covers both.
                    if (props.includes('all')) return;
                    if (!props.includes('background-color') || !props.includes('border-color')) {
                        offenders.push(`${sel.trim()} { ${d} }`.slice(0, 140));
                    }
                });
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detector self-proof. Each shape below is a way the fix can be silently undone; the
    // last two are the bypasses that got past the first version of this guard.
    test('detector catches the pre-fix, over-broad, and both bypass shapes', () => {
        const SEL = 'main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)';
        const SHARED = 'main .btn { transition: background-color 1ms, border-color 1ms, box-shadow 1ms, color 1ms, transform 1ms; }';
        const filledOf = css => collectFromCss(css).filter(r => r.selectors.some(isFilledPremium));

        // pre-fix: no list on the filled surface at all -> the vacuous-pass guard fires.
        expect(filledOf(SHARED).length).toBe(0);

        // fixed: exactly one filled rule, one declaration, fill and ring absent.
        const fixed = filledOf(`${SHARED}\n${SEL} { transition-property: box-shadow, color, transform; }`);
        expect(fixed.length).toBe(1);
        expect(fixed[0].decls.length).toBe(1);
        expect(fixed.flatMap(r => r.decls.flatMap(propsOf))).not.toContain('background-color');

        // over-broad: the same narrowing on the SHARED selector reaches outline/ghost/secondary.
        const broad = collectFromCss('main .btn { transition-property: box-shadow, color, transform; }')
            .filter(r => r.selectors.some(reachesVariants) &&
                r.decls.some(d => !propsOf(d).includes('background-color')));
        expect(broad.length).toBe(1);

        // bypass (a): a second declaration on the fix site re-animates everything.
        const twoDecls = filledOf(`${SEL} { transition-property: box-shadow, color, transform; transition: all 1ms; }`);
        expect(twoDecls[0].decls.length).toBe(2);
        expect(twoDecls.flatMap(r => r.decls.flatMap(propsOf))).toContain('all');

        // bypass (b): the fill re-animated on the [0,5,0] component twin, which OUTRANKS the fix.
        const sibling = filledOf(
            '.hero .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary) { transition-property: background-color, border-color, box-shadow, color, transform; }',
        );
        expect(sibling.length).toBe(1);
        expect(sibling.flatMap(r => r.decls.flatMap(propsOf))).toContain('background-color');

        // and a button INNER ELEMENT is not mistaken for the shared button surface.
        expect(reachesVariants('main .btn__icon')).toBe(false);

        // Compound-awareness: exclusions scattered across ANCESTOR compounds exclude nothing
        // from the button itself, so this is NOT the filled premium surface.
        expect(isFilledPremium('.a:not(.btn--outline) .b:not(.btn--ghost) .btn:not(.btn--secondary)')).toBe(false);
        expect(isFilledPremium('main .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)')).toBe(true);
        expect(isFilledPremium('.cta .btn:not(.btn--ghost):not(.btn--secondary):not(.btn--outline):hover')).toBe(true);
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
                // The cta's SECOND button (issue 474) is its own composed-primary fill
                // surface in CTA context: it sets a background-color longhand and routes
                // --cta-button2-bg. The `.cta__button` alternative above cannot match it
                // (that pattern needs `:not(` immediately after the class, and this
                // selector carries the `--secondary` modifier there), so without this
                // line the #420 guard has a hole exactly where the newest fill rule is.
                || /(^|\s)\.cta__button--secondary:not\(\.btn--outline\)/.test(s)
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
            // The cta's SECOND button (issue 474) is the same fill surface but owns a
            // SEPARATE slot family: --cta-button2-* is what an author sets there, and
            // routing it through --cta-button-* would re-couple it to the primary (the
            // exact leak the isolation rule exists to kill). Expect the slot that
            // actually belongs to the surface, so the guard polices both buttons.
            const isSecond = /\.cta__button--secondary\b/.test(r.selector);
            const family = isSecond ? '--cta-button2' : '--cta-button';
            const slot = isHover ? `${family}-hover-bg` : `${family}-bg`;
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

    test('rest rule: fill routes through --cta-button-bg then the global --btn-bg, border through --cta-button-border / --cta-accent / --btn-border-color then the fill', () => {
        const body = bodyOf(REST_SEL);
        expect(body).not.toBeNull();
        // #458: the global --btn-bg / --btn-border-color knobs sit between the per-component
        // slots and the --color-accent literal. Border still FOLLOWS the fill chain (so a flat
        // --cta-button-bg OR a global --btn-bg keeps no accent ring, issue 420 preserved).
        expect(body).toMatch(/background-color:\s*var\(--cta-button-bg,\s*var\(--cta-accent,\s*var\(--btn-bg,\s*var\(--color-accent\)\)\)\)/);
        // #564 flipped TWO link pairs in this chain, deliberately (issuecomment-5106604500):
        // --cta-accent now outranks the global --btn-border-color (so a narrower authored band
        // accent is not defeated by a broader site-wide knob, matching the hero and the fill
        // side), and consequently also outranks --cta-button-bg — #538's Option 2, which #538
        // reserved to the maintainer and #564 reopened. This pin previously asserted the old
        // order; it is FLIPPED, not deleted, the #538/#530 pattern. The global knob stays
        // ABOVE the fill link, so #539's authored-beats-inferred rule and #554's coverage
        // contract are untouched, and the chain is now the positional twin of its :hover rule.
        expect(body).toMatch(/border-color:\s*var\(--cta-button-border,\s*var\(--cta-accent,\s*var\(--btn-border-color,\s*var\(--cta-button-bg,\s*var\(--btn-bg,\s*var\(--color-accent\)\)\)\)\)\)/);
    });

    test('hover rule: fill routes through --cta-button-hover-bg, border through --cta-button-hover-border then --cta-accent-hover then the fill', () => {
        const body = bodyOf(HOVER_SEL);
        expect(body).not.toBeNull();
        // #539 completes the rest chain's shape above at the hover tier: --btn-hover-bg and
        // --btn-hover-border-color take exactly the positions --btn-bg and --btn-border-color
        // hold at rest — between the per-component slots and the --color-accent-hover literal.
        // Border still FOLLOWS the fill chain after honouring its own global knob.
        expect(body).toMatch(/background-color:\s*var\(--cta-button-hover-bg,\s*var\(--cta-accent-hover,\s*var\(--btn-hover-bg,\s*var\(--color-accent-hover\)\)\)\)/);
        // #548 flipped ONE link pair in the border chain: --cta-accent-hover now outranks
        // --cta-button-hover-bg, the order button2 has used since #538. #564 flipped a second
        // pair: --cta-accent-hover now also outranks the global --btn-hover-border-color, so a
        // site-wide ring knob no longer defeats an authored band accent (the reported defect).
        // Both pins were flipped deliberately, not deleted, exactly as #538 flipped #530's.
        // The fill stays IN the chain (a fill-only recolor still rings itself), the global knob
        // stays ABOVE the fill link, and the terminal is unchanged.
        expect(body).toMatch(/border-color:\s*var\(--cta-button-hover-border,\s*var\(--cta-accent-hover,\s*var\(--btn-hover-border-color,\s*var\(--cta-button-hover-bg,\s*var\(--btn-hover-bg,\s*var\(--color-accent-hover\)\)\)\)\)\)/);
    });
});

describe('CSS lint: grid--steps only declared inside the COMPONENT: grid block (#56)', () => {
    // Regression guard: before #56, `.grid--steps .grid__item` and
    // `.grid--steps .grid__step-number` were each declared a SECOND time,
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

    test.each(['.grid--steps .grid__item', '.grid--steps .grid__step-number'])(
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

describe('CSS lint: grid steps numeral color routes through --grid-step-text-color (#473)', () => {
    // Regression guard for the #302/#305 dead-slot class. Before #473 the steps
    // badge numeral was `color: var(--color-bg)` — hardcoded, no slot — so a
    // light-fill badge (a lime --grid-step-bg) could not get ink numerals and
    // dropped to ~1.9:1 contrast. #473 added --grid-step-text-color (default
    // var(--color-bg), so unset is byte-identical). This pin proves every `color`
    // declaration on the numeral (the base rule and any responsive variant) routes
    // through the slot, so a future bare `color: var(--color-bg)` cannot silently
    // re-kill it. The fill (`background`) is pinned to --grid-step-bg for symmetry.
    const stripped = stripComments(COMPONENTS_CSS);
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;

    // Every innermost rule whose selector targets the numeral badge.
    function numeralRules() {
        const out = [];
        let m;
        while ((m = ruleRe.exec(stripped)) !== null) {
            const sel = m[1].replace(/\s+/g, ' ').trim();
            if (/\.grid--steps\s+\.grid__step-number$/.test(sel)) out.push({ sel, body: m[2] });
        }
        return out;
    }

    test('finds the numeral badge rule(s)', () => {
        // Base rule + the <=767px responsive variant = at least one that sets color.
        expect(numeralRules().length).toBeGreaterThanOrEqual(1);
    });

    test('every numeral `color` declaration routes through var(--grid-step-text-color …)', () => {
        const offenders = [];
        numeralRules().forEach(({ sel, body }) => {
            (body.match(/(?<![-a-z])color\s*:[^;}]+/gi) || []).forEach((d) => {
                if (!/color\s*:\s*var\(\s*--grid-step-text-color\b/.test(d.trim())) {
                    offenders.push(`${sel} { ${d.trim()} }`);
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    test('numeral fill stays routed through var(--grid-step-bg …)', () => {
        const offenders = [];
        numeralRules().forEach(({ sel, body }) => {
            (body.match(/(?<![-a-z])background(?:-color)?\s*:[^;}]+/gi) || []).forEach((d) => {
                if (!/background(?:-color)?\s*:\s*var\(\s*--grid-step-bg\b/.test(d.trim())) {
                    offenders.push(`${sel} { ${d.trim()} }`);
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detection proof: a bare `color: var(--color-bg)` must be CAUGHT and a
    // slot-routed one must PASS, so a parser regression can't make the scan vacuous.
    test('detector flags a bare numeral color but passes a slot-routed one', () => {
        const scan = (fixture) => {
            const rr = /([^{}]+)\{([^{}]*)\}/g;
            let mm; const out = [];
            while ((mm = rr.exec(fixture)) !== null) {
                if (!/\.grid--steps\s+\.grid__step-number\s*$/.test(mm[1].replace(/\s+/g, ' ').trim())) continue;
                (mm[2].match(/(?<![-a-z])color\s*:[^;}]+/gi) || []).forEach((d) => {
                    if (!/color\s*:\s*var\(\s*--grid-step-text-color\b/.test(d.trim())) out.push(d);
                });
            }
            return out;
        };
        expect(scan('.grid--steps .grid__step-number { color: var(--color-bg); }').length).toBe(1);
        expect(scan('.grid--steps .grid__step-number { color: var(--grid-step-text-color, var(--color-bg)); }').length).toBe(0);
    });
});

describe('CSS lint: inline-items separator color routes through --section-separator-color (#475)', () => {
    // The body_items middot separator is a `li::before` pseudo-element (on EVERY
    // item since #489's hanging-separator clip; line-leading ones are clipped) whose
    // color MUST route through the --section-separator-color slot at every
    // declaration site (the base rule + the bg-image overlay re-route), so a future
    // bare `color: var(--color-muted)` cannot silently re-kill the slot. The base
    // default is --color-muted; the overlay default is --color-bg (that band does
    // not remap --color-muted). Both are valid slot-routed fallbacks.
    const stripped = stripComments(COMPONENTS_CSS);
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;

    // Every innermost rule whose selector targets the separator pseudo-element.
    function separatorRules() {
        const out = [];
        let m;
        while ((m = ruleRe.exec(stripped)) !== null) {
            const sel = m[1].replace(/\s+/g, ' ').trim();
            if (/\.section__inline-items li::before$/.test(sel)) out.push({ sel, body: m[2] });
        }
        return out;
    }

    test('finds the separator rule(s) — base + overlay', () => {
        // Base rule + the .section--has-bg-image overlay re-route = at least two.
        expect(separatorRules().length).toBeGreaterThanOrEqual(2);
    });

    test('every separator `color` declaration routes through var(--section-separator-color …)', () => {
        const offenders = [];
        separatorRules().forEach(({ sel, body }) => {
            (body.match(/(?<![-a-z])color\s*:[^;}]+/gi) || []).forEach((d) => {
                if (!/color\s*:\s*var\(\s*--section-separator-color\b/.test(d.trim())) {
                    offenders.push(`${sel} { ${d.trim()} }`);
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detection proof: a bare separator color must be CAUGHT and a slot-routed one
    // must PASS, so a parser regression can't make the scan vacuous.
    test('detector flags a bare separator color but passes a slot-routed one', () => {
        const scan = (fixture) => {
            const rr = /([^{}]+)\{([^{}]*)\}/g;
            let mm; const out = [];
            while ((mm = rr.exec(fixture)) !== null) {
                if (!/\.section__inline-items li::before\s*$/.test(mm[1].replace(/\s+/g, ' ').trim())) continue;
                (mm[2].match(/(?<![-a-z])color\s*:[^;}]+/gi) || []).forEach((d) => {
                    if (!/color\s*:\s*var\(\s*--section-separator-color\b/.test(d.trim())) out.push(d);
                });
            }
            return out;
        };
        expect(scan('.section__inline-items li::before { color: var(--color-muted); }').length).toBe(1);
        expect(scan('.section__inline-items li::before { color: var(--section-separator-color, var(--color-muted)); }').length).toBe(0);
    });
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
        { el: '.grid__heading', slot: '--grid-heading-color', themeVar: '--pp-grid-heading-theme-color', desktop: true },
        { el: '.grid__subheading', slot: '--grid-subheading-color', themeVar: '--pp-grid-subheading-theme-color', desktop: false },
        { el: '.section__title', slot: '--section-heading-color', themeVar: '--pp-section-title-theme-color', desktop: true },
        { el: '.section__content', slot: '--section-body-color', themeVar: '--pp-section-text-theme-color', desktop: true },
        { el: '.cta__body', slot: '--cta-body-color', themeVar: '--pp-cta-body-theme-color', desktop: true },
        // faq (issue 581): it implements the identical three-tier chain — the base and the
        // >=768px premium rule both read
        // var(--faq-heading-color, var(--pp-faq-heading-theme-color, var(--color-text)))
        // with the plumbing declared on .faq--inverted — but it was never listed here, so
        // the one mechanism most likely to regress was the one nothing pinned.
        { el: '.faq__heading', slot: '--faq-heading-color', themeVar: '--pp-faq-heading-theme-color', desktop: true },
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
        { variant: '.grid--inverted', vars: ['--pp-grid-heading-theme-color', '--pp-grid-subheading-theme-color'] },
        { variant: '.pp-section--inverted', vars: ['--pp-section-title-theme-color', '--pp-section-text-theme-color'] },
        { variant: '.cta--inverted', vars: ['--pp-cta-body-theme-color'] },
        { variant: '.section--has-bg-image', vars: ['--pp-section-title-theme-color', '--pp-section-text-theme-color'] },
        { variant: '.cta--has-bg-image', vars: ['--pp-cta-body-theme-color'] },
        { variant: '.faq--inverted', vars: ['--pp-faq-heading-theme-color'] },
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

    // Inverted grid CARDS keep a light background (`--grid-item-bg: var(--color-bg)`),
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
        // The heading-color rule for this variant sets `color: var(--section-heading-color, ...)`
        // and targets the variant's headings. Find every rule that both scopes the variant
        // and colours a heading through the title slot.
        const headingRules = rules.filter((r) =>
            r.selector.includes(variant) &&
            /\bh[23]\b/.test(r.selector) &&
            /color\s*:\s*var\(--section-heading-color/.test(r.body)
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
 * Featured grid card honors the --grid-item-border-color style slot (#226).
 *
 * The first card of a `layout: cards` grid gets an unconditional "featured"
 * treatment. Its sibling slot --grid-item-bg is routed through
 * `var(--grid-item-bg, <default>)` so an author's declared value wins, but the
 * border was hardcoded to an accent token — so a declared --grid-item-border-color
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
describe('CSS lint: featured grid card honors --grid-item-border-color (#226)', () => {
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

    test('every border-color on the featured first card routes through --grid-item-border-color', () => {
        const offenders = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/border-color\s*:\s*var\(\s*--grid-item-border-color\b/.test(d)) {
                    offenders.push(d.trim());
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Fallback integrity: the slot must fall back to an accent token, never to a
    // neutral border, or unset compositions would lose the featured look. Both
    // accent tokens the two rules historically used are acceptable fallbacks.
    test('--grid-item-border-color falls back to an accent token, preserving the default look', () => {
        const bad = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/var\(\s*--grid-item-border-color\s*,\s*var\(\s*--color-(?:border-accent|accent-strong)\s*\)\s*\)/.test(d)) {
                    bad.push(d.trim());
                }
            });
        });
        expect(bad).toEqual([]);
    });
});

/**
 * Non-featured grid cards honor the --grid-item-border-color style slot (#292).
 *
 * Regression of #226, inverted: the #226 fix routed the featured FIRST card's
 * border-color through --grid-item-border-color, but the "premium" cascade rules that
 * re-declare border-color for ALL cards (`main > .grid .grid__item`, specificity
 * [0,2,1], which beats the base `.grid__item` rule [0,1,0]) kept a bare
 * `var(--color-border)`. So a declared --grid-item-border-color silently no-opped on
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
describe('CSS lint: non-featured grid cards honor --grid-item-border-color (#292)', () => {
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

    test('every border-color on the all-cards rule routes through --grid-item-border-color', () => {
        const offenders = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/border-color\s*:\s*var\(\s*--grid-item-border-color\b/.test(d)) {
                    offenders.push(d.trim());
                }
            });
        });
        expect(offenders).toEqual([]);
    });

    // Fallback integrity: the non-featured card border falls back to the NEUTRAL
    // --color-border, never an accent token — cards 2..N must not adopt the
    // featured accent look when --grid-item-border-color is unset.
    test('--grid-item-border-color falls back to the neutral --color-border on all cards', () => {
        const bad = [];
        borderBodies.forEach(body => {
            const decls = body.match(/border-color\s*:[^;}]+/g) || [];
            decls.forEach(d => {
                if (!/var\(\s*--grid-item-border-color\s*,\s*var\(\s*--color-border\s*\)\s*\)/.test(d)) {
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
 * --section-padding-*, --grid-padding-*, --cta-padding-*, --section-heading-size,
 * --grid-heading-size, or --section-body-measure validated, reported success, and
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
    test('section title premium rule routes font-size through --section-heading-size', () => {
        assertPropRoutesThroughSlot('main > .section .section__title', 'font-size', '--section-heading-size', 1);
    });

    test('grid heading premium rule routes font-size through --grid-heading-size', () => {
        assertPropRoutesThroughSlot('main > .grid .grid__heading', 'font-size', '--grid-heading-size', 1);
    });

    // ---- Section body width slot ----
    test('.section__body caps max-width through --section-body-measure (fallback 40rem)', () => {
        const bodies = bodiesForExactSelector('.section__body');
        const widthBodies = bodies.filter(b => /max-width\s*:/.test(b));
        expect(widthBodies.length).toBeGreaterThanOrEqual(1);
        widthBodies.forEach(body => {
            (body.match(/max-width\s*:[^;}]+/g) || []).forEach(d => {
                expect(d).toMatch(/max-width\s*:\s*var\(\s*--section-body-measure\s*,\s*40rem\s*\)/);
            });
        });
    });

    // ---- Section body type slots (issue 470) ----
    // font-size / font-weight for the section body must route through
    // --section-body-size / --section-body-weight at EVERY declaration site: the
    // base .section__content rule (in-block, keystone consumption) plus the desktop
    // premium (main > .section .section__content[, ... p]) and mobile rules. A bare
    // literal at any site would defeat the slot (the #302/#305 dead-slot class).
    // The base .section__content rule (in-block, keystone consumption).
    test('base .section__content routes font-size/weight through the body slots', () => {
        assertPropRoutesThroughSlot('.section__content', 'font-size', '--section-body-size', 1);
        assertPropRoutesThroughSlot('.section__content', 'font-weight', '--section-body-weight', 1);
    });

    // Both breakpoints declare the section body via the grouped
    // `main > .section .section__content, main > .section .section__content p` list.
    // The `p` selector is the one that terminates each rule (the bare container
    // selector only ever appears mid-list, never immediately before `{`), and it
    // shares the rule body with the container, so pinning it covers both. Presence 2
    // = desktop premium block + mobile block.
    test('section body premium + mobile rules route font-size through --section-body-size', () => {
        assertPropRoutesThroughSlot('main > .section .section__content p', 'font-size', '--section-body-size', 2);
    });

    test('section body premium + mobile rules route font-weight through --section-body-weight', () => {
        assertPropRoutesThroughSlot('main > .section .section__content p', 'font-weight', '--section-body-weight', 2);
    });

    // INVERTED by issue 578 (A-5 part 2). The mobile fallback used to CHAIN through
    // --cta-body-size, so an unset section body followed a CTA slot — a cta authoring
    // surface acting as a section slot. The chain is severed and the literal is the
    // fallback directly. Byte-identical: --cta-body-size renders on the CTA component
    // root, so it could never resolve on a .section subtree; its unset result was always
    // 1rem. This pin now guards the severance, so a re-introduced chain fails here.
    test('#578 mobile section body size no longer chains through the cta-body-size leak', () => {
        const bodies = bodiesForExactSelector('main > .section .section__content p');
        const sizeDecls = bodies
            .flatMap(b => b.match(/font-size\s*:[^;}]+/g) || [])
            .filter(d => /--section-body-size/.test(d));
        expect(sizeDecls.length).toBeGreaterThanOrEqual(1);
        // No section body size declaration may name a cta slot at all.
        sizeDecls.forEach(d => expect(d).not.toMatch(/--cta-body-size/));
        // The mobile rule falls back to the literal the chain used to terminate in.
        expect(sizeDecls.some(d => /var\(\s*--section-body-size\s*,\s*1rem\s*\)/.test(d))).toBe(true);
    });

    // The other half of the same severance: grid cards and faq answers took their mobile
    // body size from --cta-body-size too. They now carry the literal; only cta reads the
    // slot cta owns.
    test('#578 grid/faq mobile body size no longer reads the cta body-size slot', () => {
        const gridBodies = bodiesForExactSelector('main > .grid .grid__item-text');
        const faqBodies = bodiesForExactSelector('main > .faq .faq__answer');
        expect(gridBodies.length + faqBodies.length).toBeGreaterThanOrEqual(2);
        [...gridBodies, ...faqBodies]
            .flatMap(b => b.match(/font-size\s*:[^;}]+/g) || [])
            .forEach(d => expect(d).not.toMatch(/--cta-body-size/));
        // cta keeps the slot it owns.
        const ctaDecls = bodiesForExactSelector('main > .cta .cta__body')
            .flatMap(b => b.match(/font-size\s*:[^;}]+/g) || []);
        expect(ctaDecls.some(d => /var\(\s*--cta-body-size\s*,/.test(d))).toBe(true);
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
            '(?:^|[}{;])\\s*main\\s*>\\s*\\.hero\\[data-pp-spacing="' +
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
        const compactIdx = mobile.search(/main\s*>\s*\.hero\[data-pp-spacing="compact"\]/);
        const spaciousIdx = mobile.search(/main\s*>\s*\.hero\[data-pp-spacing="spacious"\]/);
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
    // carries its root class AND slot prefix. Since #576 the two differ ONLY for table:
    // its slots are `--table-*` (the canonical vocabulary) while its root class stays
    // `.table-section` (deliberately unchanged — `.table` is already the inner data-table
    // block), so a naive `--${comp}-` derivation would still assert against a nonexistent
    // `.table` selector.
    const BAND_COMPONENTS = [
        { comp: 'section', cls: '.section', slot: '--section' },
        { comp: 'grid', cls: '.grid', slot: '--grid' },
        { comp: 'cta', cls: '.cta', slot: '--cta' },
        { comp: 'stats', cls: '.stats', slot: '--stats' },
        { comp: 'faq', cls: '.faq', slot: '--faq' },
        { comp: 'testimonials', cls: '.testimonials', slot: '--testimonials' },
        { comp: 'table', cls: '.table-section', slot: '--table' },
        { comp: 'logos', cls: '.logos', slot: '--logos' },
        { comp: 'embed', cls: '.embed', slot: '--embed' },
        // hero joins the ADJACENT-TOP contract only (issue 577), and it is the ONE
        // member whose fallback is not the shared band tier — hence `adjacentFallback`
        // instead of the implicit --pp-band-padding-adjacent-top, and `ownRhythm: false`
        // to exempt it from pin 1.
        //
        // WHY THE EXCEPTION EXISTS, so the next person does not "fix" it back:
        // hero deliberately opts OUT of the shared symmetric band rhythm — it is the
        // page opener, and its own base rules use --space-xl / --space-2xl, not
        // --pp-band-padding. Before #577 the generic adjacent catch-all
        // (`main > [data-pp-component] + [data-pp-component]`, [0,2,1]) outranked
        // `.hero` ([0,1,0]) at BOTH breakpoints, so --hero-padding-top was dead on the
        // adjacent edge AND the catch-all silently dragged an adjacent hero onto the
        // band rhythm. #577 routes the slot and restores hero's own opener rhythm as
        // the fallback, which is a deliberate, registered render change. Pointing this
        // fallback at --pp-band-padding-adjacent-top "for consistency" would re-break
        // the opt-out.
        {
            comp: 'hero',
            cls: '.hero',
            slot: '--hero',
            ownRhythm: false,
            adjacentFallback: ['--space-2xl', '--space-xl'], // desktop, mobile
        },
    ];

    /** Pin 1 applies only to the bands that consume the SHARED rhythm definition. */
    const OWN_RHYTHM_COMPONENTS = BAND_COMPONENTS.filter(b => b.ownRhythm !== false);

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
    test.each(OWN_RHYTHM_COMPONENTS)('$comp own padding routes through its slot to var(--pp-band-padding)', ({ cls, slot }) => {
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
    test.each(BAND_COMPONENTS)('adjacent $comp routes top-padding through its slot at both breakpoints', ({ cls, slot, adjacentFallback }) => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + ' + cls);
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        // Desktop + mobile adjacent rules both exist.
        expect(decls.length).toBeGreaterThanOrEqual(2);

        if (!adjacentFallback) {
            // The nine shared-rhythm bands: slot -> --pp-band-padding-adjacent-top.
            decls.forEach(d => {
                expect(d).toMatch(new RegExp('padding-top\\s*:\\s*var\\(\\s*' + slot + '-padding-top\\s*,\\s*var\\(\\s*--pp-band-padding-adjacent-top\\s*\\)'));
            });
            return;
        }

        // hero, the documented exception: the slot is routed exactly the same way, but
        // the fallback is hero's OWN opener rhythm and DIFFERS BY BREAKPOINT. Assert
        // every declaration uses one of the two, and that BOTH actually appear — so
        // dropping a breakpoint, or collapsing the pair onto one value, fails here.
        const seen = new Set();
        decls.forEach(d => {
            const m = d.match(new RegExp('padding-top\\s*:\\s*var\\(\\s*' + slot + '-padding-top\\s*,\\s*var\\(\\s*(--space-[a-z0-9]+)\\s*\\)'));
            expect(m, `adjacent ${cls} declaration must route ${slot}-padding-top to a --space-* fallback: ${d}`).not.toBeNull();
            expect(adjacentFallback).toContain(m[1]);
            seen.add(m[1]);
        });
        adjacentFallback.forEach(f => {
            expect(seen, `adjacent ${cls} is missing the ${f} breakpoint fallback`).toContain(f);
        });

        // And it must NOT have been "corrected" onto the shared band tier.
        decls.forEach(d => {
            expect(d).not.toMatch(/--pp-band-padding-adjacent-top/);
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

    // 3b. The generic adjacent catch-all also consumes the shared adjacent-top, not a
    //     literal, so nothing routes rhythm outside the one definition. Since issue 577
    //     no band falls through to it (hero was the last one, after issue 438 gave
    //     table/logos/embed their own padding slots) — it is now the shared DEFINITION
    //     the per-component rules fall back to rather than the rule any band lands on,
    //     and it must keep consuming the shared prop either way.
    test('the generic adjacent-sibling rule routes through --pp-band-padding-adjacent-top', () => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + [data-pp-component]');
        expect(bodies.length).toBeGreaterThanOrEqual(2); // desktop + mobile
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        expect(decls.length).toBeGreaterThanOrEqual(2);
        decls.forEach(d => {
            expect(d).toMatch(/padding-top\s*:\s*var\(\s*--pp-band-padding-adjacent-top\s*\)/);
        });
    });

    // 3c. SOURCE ORDER IS LOAD-BEARING for hero's DESKTOP adjacent rule (issue 577).
    //     `main > [data-pp-component] + .hero` and the desktop restatement of
    //     `main > [data-pp-component][data-pp-spacing="compact"|"spacious"]` are BOTH
    //     [0,2,1], so whichever comes last wins. An explicit compact/spacious override
    //     must keep governing BOTH edges of an adjacent hero (issue 434), so hero's
    //     desktop rule has to sit ABOVE the spacing restatement — which is exactly why
    //     it lives in the SHARED: Adjacent-Sibling Rhythm block instead of down with the
    //     other nine per-component adjacent rules. Moving it "for consistency" would
    //     silently shave a spaced hero's top edge again.
    //
    //     Mobile needs no equivalent pin: that block already restates the spacing rules
    //     AFTER its per-component list, so hero's mobile rule sits with its siblings.
    test('hero adjacent-top is declared ABOVE the desktop data-pp-spacing restatement', () => {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const heroAdjacent = css.indexOf('main > [data-pp-component] + .hero {');
        const spacingRestatement = css.indexOf('main > .hero[data-pp-spacing="compact"] {');

        expect(heroAdjacent, 'no `main > [data-pp-component] + .hero` rule found').toBeGreaterThan(-1);
        expect(spacingRestatement, 'no `main > .hero[data-pp-spacing="compact"]` restatement found').toBeGreaterThan(-1);
        // Both indexes are the FIRST (= desktop) occurrence of each rule.
        expect(
            heroAdjacent,
            'hero\'s desktop adjacent-top rule moved BELOW the desktop data-pp-spacing restatement — '
            + 'a compact/spacious hero placed after another band will now be shaved on its top edge only (issue 434 regression)'
        ).toBeLessThan(spacingRestatement);
    });

    // 3d. The LEFT variant carries its own adjacent-top fallback (issue 577). OQ-1 (ii)
    //     ruled that hero keeps ITS OWN opener rhythm on the adjacent edge; .hero--left's
    //     own rhythm is --space-xl on both edges ("inner pages; compact vertical
    //     rhythm"), so applying the centered hero's --space-2xl there would follow the
    //     wrong variant's rhythm and render 112px top against a 64px bottom. The twin
    //     must (a) exist at desktop, where the two rhythms diverge, (b) fall back to
    //     --space-xl, and (c) sit AFTER the plain `.hero` rule at equal specificity so
    //     it actually wins. Mobile needs no twin — both variants resolve --space-xl
    //     there through the one rule.
    test('the hero--left adjacent-top twin exists, uses --space-xl, and out-orders the .hero rule', () => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + .hero--left');
        expect(bodies.length, 'no `main > [data-pp-component] + .hero--left` rule found').toBeGreaterThanOrEqual(1);
        bodies.forEach(b => {
            const decls = b.match(/padding-top\s*:[^;}]+/g) || [];
            expect(decls.length).toBeGreaterThanOrEqual(1);
            decls.forEach(d => {
                expect(d).toMatch(/padding-top\s*:\s*var\(\s*--hero-padding-top\s*,\s*var\(\s*--space-xl\s*\)/);
                expect(d).not.toMatch(/--space-2xl/);
            });
        });

        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        const plainHero = css.indexOf('main > [data-pp-component] + .hero {');
        const leftHero = css.indexOf('main > [data-pp-component] + .hero--left {');
        expect(plainHero).toBeGreaterThan(-1);
        expect(leftHero).toBeGreaterThan(-1);
        expect(
            leftHero,
            'the .hero--left twin must come AFTER the plain .hero adjacent rule — equal specificity means source order decides, and the left variant has to win'
        ).toBeGreaterThan(plainHero);
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
        // '.section--text-only .section__title' was DELETED in issue 581 (A-28): it
        // re-declared the base rule verbatim at higher specificity. The equivalence that
        // made the deletion safe is pinned structurally by
        // 'every .section__title font-size declaration is the same declaration' below.
        { selectors: ['.section__title', 'main > .section .section__title'], slot: '--section-heading-size' },
        { selectors: ['.grid__heading', 'main > .grid .grid__heading'], slot: '--grid-heading-size' },
        { selectors: ['.cta__title'], slot: '--cta-heading-size' },
        { selectors: ['.faq__heading', 'main > .faq .faq__heading'], slot: '--faq-heading-size' },
        { selectors: ['.stats__heading'], slot: '--stats-heading-size' },
        { selectors: ['.table-section__heading'], slot: '--table-heading-size' },
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
        expect(stripped).not.toMatch(/font-size\s*:\s*var\(\s*--section-heading-size\s*,\s*2\.25rem\s*\)/);
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
 * Schema/token OWNERSHIP pin (#581). The #438 scan above proves a listed token is
 * consumed SOMEWHERE in the theme; it cannot tell whether the COMPONENT THAT LISTS IT
 * can ever reach that consumption. That gap shipped a real falsehood: #438's rationale
 * for the whole-theme scan was that logos legitimately listed --space-2xl/--space-3xl
 * because the shared `[data-pp-spacing]` rules consumed them — but those rules were
 * later narrowed to `.hero[data-pp-spacing=…]`, and only hero.php emits the attribute.
 * Ten schemas were left advertising spacing tokens no rule of theirs could read.
 *
 * The expectation is DERIVED FROM THE SCHEMA ITSELF, never a second hand-maintained
 * list: a component owns a selector when any class in it belongs to one of that
 * component's own BEM blocks, and the block set comes from `component` + `root_class`
 * + the blocks of the template-verified `variant_classes`.
 *
 * Blocks are compared as CLASS TOKENS, split on the BEM separators — not as substrings.
 * Substring matching is the trap here: `.section__grid` contains "grid" but belongs to
 * section, and `.grid__item-body` contains "item" but belongs to grid. Tokenizing first
 * is what keeps this a real ownership test instead of a spelling coincidence.
 */
describe('CSS lint: schema styling.tokens are reachable BY THE COMPONENT THAT LISTS THEM (#581)', () => {
    // The BEM block a class token belongs to: everything before the first `__` or `--`.
    const blockOf = (cls) => cls.split(/__|--/)[0];

    const classesIn = (selector) =>
        (selector.match(/\.([A-Za-z][\w-]*)/g) || []).map(c => c.slice(1));

    const componentsDir = path.resolve(__dirname, '../../components');
    const components = fs.readdirSync(componentsDir, { withFileTypes: true })
        .filter(d => d.isDirectory())
        .map(d => d.name)
        .filter(name => fs.existsSync(path.join(componentsDir, name, 'schema.json')))
        .map(name => {
            const schema = JSON.parse(fs.readFileSync(path.join(componentsDir, name, 'schema.json'), 'utf-8'));
            const styling = schema.styling || {};
            const blocks = new Set([name, styling.root_class || name]);
            (styling.variant_classes || []).forEach(v => blocks.add(blockOf(v)));
            return { name, blocks, tokens: styling.tokens || [] };
        });

    const rules = parseRules();

    // Fail-closed floor: if schema discovery or the rule parser breaks, the per-token
    // loop would pass vacuously over an empty list.
    test('discovery finds components, tokens and parsed rules', () => {
        expect(components.length).toBeGreaterThanOrEqual(10);
        expect(components.reduce((n, c) => n + c.tokens.length, 0)).toBeGreaterThanOrEqual(10);
        expect(rules.length).toBeGreaterThan(200);
    });

    const cases = components.flatMap(c => c.tokens.map(token => ({ component: c.name, token })));

    test.each(cases)('$component can actually reach the $token it lists', ({ component, token }) => {
        const { blocks } = components.find(c => c.name === component);
        const consumes = new RegExp('var\\(\\s*' + token.replace(/[-]/g, '\\$&') + '\\s*[,)]');
        const owned = rules.filter(r =>
            consumes.test(r.body) &&
            r.selectors.some(sel => classesIn(sel).some(cls => blocks.has(blockOf(cls))))
        );
        expect(
            owned.length,
            `${component}/schema.json lists ${token}, but no rule in components.css that ` +
            `${component} can match consumes it. Either the schema advertises a styling ` +
            `surface the component cannot reach (remove the token), or a rule that used to ` +
            `serve this component was narrowed to another one (restore the routing).`
        ).toBeGreaterThanOrEqual(1);
    });

    // Detection proof: the exact defect this pin exists to catch must FAIL, and a
    // genuinely-owned token must PASS, so a parser or tokenizer regression cannot make
    // the scan vacuous.
    test('detector rejects a token only another component consumes, and accepts an owned one', () => {
        const blocks = new Set(['logos']);
        const owns = (selector) => classesIn(selector).some(cls => blocks.has(blockOf(cls)));
        // The real #581 defect: logos listed a token only a hero rule reads.
        expect(owns('main > .hero[data-pp-spacing="spacious"]')).toBe(false);
        // The substring trap: `.section__grid` must not read as the grid component.
        expect(new Set(['grid']).has(blockOf(classesIn('.section__grid')[0]))).toBe(false);
        expect(owns('.logos--inverted .logos__heading')).toBe(true);
    });
});

/**
 * Structural proof for the #581 (A-28) deletion of
 * `.section--text-only .section__title { font-size: … }`.
 *
 * That rule re-declared the base `.section__title` rule VERBATIM at higher specificity
 * with no comment. Deleting a HIGHER-specificity rule is the kind of change that
 * usually needs a rendered check, so the byte-identity claim is proven here instead of
 * asserted: if EVERY font-size declaration that can land on a `.section__title` is the
 * SAME declaration, then no specificity ordering and no source order can resolve to a
 * different value, and removing one redundant declaration site cannot move a pixel.
 *
 * This also guards the future: re-introducing a `.section__title` font-size that
 * differs from the shared routing fails here, which is exactly when a duplicate would
 * stop being redundant.
 */
describe('CSS lint: every .section__title font-size is the same declaration (#581)', () => {
    const titleRules = rulesMatching('.section__title');

    test('the base .section__title rule still declares a font-size', () => {
        const base = titleRules.filter(r =>
            r.media === null && r.selectors.some(s => s === '.section__title')
        );
        expect(base.length, 'the base .section__title rule vanished').toBeGreaterThanOrEqual(1);
        expect(base.some(r => /font-size\s*:/.test(r.body))).toBe(true);
    });

    test('all of them route the slot to the shared scale, with no second value', () => {
        const decls = titleRules
            .flatMap(r => r.body.match(/font-size\s*:[^;}]+/g) || [])
            .map(d => d.replace(/\s+/g, ' ').trim());
        expect(decls.length, 'no .section__title font-size found at all').toBeGreaterThanOrEqual(1);
        expect([...new Set(decls)]).toEqual([
            'font-size: var(--section-heading-size, var(--pp-band-heading-size))',
        ]);
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
        { selector: '.table-section__heading', slot: '--table-heading-color', fallback: '--color-text' },
        { selector: '.logos__heading', slot: '--logos-heading-color', fallback: '--color-text' },
        { selector: '.embed__heading', slot: '--embed-heading-color', fallback: '--color-text' },
        { selector: '.logos--inverted .logos__heading', slot: '--logos-heading-color', fallback: '--color-bg' },
        { selector: '.embed--inverted .embed__heading', slot: '--embed-heading-color', fallback: '--color-bg' },
        // Widened in issue 581 (A-29). The original list named only the three components
        // issue 438 had just given a heading-color slot; every OTHER band heading whose
        // inverted rule is not already covered by the #222 theme-variant guard above was
        // left unpinned. stats and testimonials carry the same base + inverted pair, and
        // faq's inverted rule sits outside the three-tier chain the #222 guard checks.
        // section / grid / cta need no entry here: the #222 THEMED list covers them.
        { selector: '.stats__heading', slot: '--stats-heading-color', fallback: '--color-text' },
        { selector: '.stats--inverted .stats__heading', slot: '--stats-heading-color', fallback: '--color-bg' },
        { selector: '.testimonials__heading', slot: '--testimonials-heading-color', fallback: '--color-text' },
        { selector: '.testimonials--inverted .testimonials__heading', slot: '--testimonials-heading-color', fallback: '--color-bg' },
        { selector: '.faq--inverted .faq__heading', slot: '--faq-heading-color', fallback: '--color-bg' },
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
    // Since #439, cta.body and testimonials.quote render an inline-HTML subset
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
        // #551 carved the panel CTA out of the band-wide anchor rule (the panel is a
        // LIGHT surface). The on-inverted ROUTING this describe pins is unchanged — only
        // the selector's reach narrowed, so the pin follows the selector.
        '.pp-section--inverted a:not(.section__panel-cta)',
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

    test('the global HOVER knobs register as unset-by-default too (#539)', () => {
        // #539 completed #530's rest/hover parity at the GLOBAL tier. Both hover knobs must
        // register `initial` for the same reason their resting counterparts do: unset, every
        // consuming hover rule resolves its own literal and the button renders byte-identically;
        // set, they override. A concrete default here would repaint every hover on every site.
        const root = firstRootBlock(BASE_CSS);
        expect(root).toMatch(/--btn-hover-bg:\s*initial/);
        expect(root).toMatch(/--btn-hover-border-color:\s*initial/);
    });

    /*
     * PARSER TRAP GUARD — a COMMENT inside :root must never contain `--token: value` syntax.
     *
     * The design-token registry is derived by regex (_pp_read_tokens_from_file in lib/apply.php
     * and pp_design_tokens in lib/wp.php) running `/(--[\w-]+)\s*:\s*([^;]+);/` over the raw
     * :root block with comments NOT stripped. So prose like "set --btn-shadow: none to flatten"
     * inside a comment registers a phantom token whose "value" is the rest of the sentence.
     * It is invisible when the real declaration happens to come later (last-wins overwrites it)
     * and corrupts the registry when it does not — the token then reports a garbage value and
     * update_design_token validates against it.
     *
     * That is why every token comment in this file writes `--btn-shadow = none`, with an equals
     * sign, not a colon. #539 broke the convention and this guard exists so the next one cannot.
     */
    test('no :root comment contains --token: syntax that the registry regex would eat', () => {
        const root = firstRootBlock(BASE_CSS);
        const comments = root.match(/\/\*[\s\S]*?\*\//g) || [];
        const offenders = [];
        comments.forEach(c => {
            const hits = c.match(/--[\w-]+\s*:\s*[^;\n]+;/g);
            if (hits) offenders.push(...hits.map(h => h.trim()));
        });
        expect(offenders).toEqual([]);
    });

    /*
     * STRUCTURAL GUARD — comment delimiters must balance, and :root must contain only
     * declarations once comments are stripped.
     *
     * The trap this catches is a premature `*​/` in the middle of one of this file's long
     * docblocks: everything after it becomes RAW CSS inside :root, the browser's error
     * recovery swallows whatever declaration follows, and a token silently stops existing.
     * A text-matching lint sees nothing wrong, because the prose it was checking is simply no
     * longer inside a comment. It happened once during #539 and was caught by an outside
     * reviewer rather than by this suite, so the suite gets the guard.
     */
    test('base.css and components.css have balanced comment delimiters', () => {
        [['base.css', BASE_CSS], ['components.css', COMPONENTS_CSS]].forEach(([name, css]) => {
            const stripped = css.replace(/\/\*[\s\S]*?\*\//g, '');
            expect(stripped.includes('*/'), `${name} has a stray */ (comment closed early)`).toBe(false);
            expect(stripped.includes('/*'), `${name} has an unclosed /*`).toBe(false);
            expect(css.split('{').length, `${name} brace balance`).toBe(css.split('}').length);
        });
    });

    test(':root contains only declarations once comments are stripped', () => {
        const bare = firstRootBlock(BASE_CSS).replace(/\/\*[\s\S]*?\*\//g, '');
        const stray = bare
            .split(';')
            .map(s => s.trim())
            .filter(Boolean)
            // A real declaration is `prop: value` (custom property or otherwise).
            .filter(s => !/^[-a-zA-Z][\w-]*\s*:/.test(s));
        expect(stray).toEqual([]);
    });

    test('detector catches prose left raw in :root by an early comment close', () => {
        // Anti-vacuity for the guard above: the exact #539 shape must be caught.
        const broken = ':root {\n  /* explains a thing */\n  Deliberately NOT mirrored: hover ink\n  and elevation. */\n  --btn-bg: initial;\n}';
        const stripped = broken.replace(/\/\*[\s\S]*?\*\//g, '');
        expect(stripped.includes('*/')).toBe(true);
    });

    test('detector catches a --token: colon form planted inside a :root comment', () => {
        // Anti-vacuity: prove the guard above actually fires, so a regex slip can't make it
        // silently pass on a file that really does carry the trap.
        const planted = ':root {\n  /* set --btn-shadow: none; to flatten */\n  --btn-bg: initial;\n}';
        const comments = firstRootBlock(planted).match(/\/\*[\s\S]*?\*\//g) || [];
        const hits = comments.flatMap(c => c.match(/--[\w-]+\s*:\s*[^;\n]+;/g) || []);
        expect(hits).toHaveLength(1);
        expect(hits[0]).toContain('--btn-shadow');
    });

    test('the global hover knobs carry their annotated type comment (#539)', () => {
        // Same contract as the resting knobs: pp_design_tokens() derives the type from the
        // `/* type: ... */` comment, and without it update_design_token cannot validate an
        // authored value. This is the annotation the PHP authoring-path test exercises.
        expect(BASE_CSS).toMatch(/--btn-hover-bg:[^;]*;\s*\/\*\s*color:/);
        expect(BASE_CSS).toMatch(/--btn-hover-border-color:[^;]*;\s*\/\*\s*color:/);
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

describe('CSS lint: bg-image band accent routes through --color-accent-on-overlay (#461)', () => {
    // A bg-image band lays a dark rgba(0,0,0,.55) overlay over an ARBITRARY image.
    // The light-surface accent (--color-accent) is only 1.16:1 over the overlay-over-
    // white worst case and fails WCAG AA. #461 routes the default accent on ALL THREE
    // bg-image variants (section link, cta body link, stats number) through the overlay
    // accent role — NOT --color-accent-on-inverted (tuned to the solid inverted bg, not
    // the arbitrary-image overlay). The per-instance slot must still win. These pins
    // guard against a regression back to the bare accent OR to the inverted role.
    const stripped = stripComments(COMPONENTS_CSS);
    const rules = [];
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(stripped)) !== null) {
        rules.push({ selector: m[1].trim(), body: m[2] });
    }
    const ruleFor = (sel) => rules.find(r => r.selector.split(',').some(s => s.trim() === sel));

    // Each entry: selector, the slot it must route through, and the overlay role fallback.
    const ROUTES = [
        // #551 carved the panel CTA out of the band-wide anchor rule (the panel is a LIGHT
        // surface). The overlay ROUTING pinned here is unchanged — only the selector's reach
        // narrowed, so the pin follows the selector.
        { sel: '.section--has-bg-image a:not(.section__panel-cta)', slot: '--section-body-link-color', role: '--color-accent-on-overlay' },
        { sel: '.section--has-bg-image a:not(.section__panel-cta):hover', slot: '--section-body-link-hover-color', role: '--color-accent-on-overlay-hover' },
        { sel: '.cta--has-bg-image .cta__body a', slot: '--cta-body-color', role: '--color-accent-on-overlay' },
        { sel: '.cta--has-bg-image .cta__body a:hover', slot: '--cta-body-color', role: '--color-accent-on-overlay-hover' },
        { sel: '.stats--has-bg-image .stats__number', slot: '--stats-number-color', role: '--color-accent-on-overlay' },
    ];

    ROUTES.forEach(({ sel, slot, role }) => {
        test(`${sel} routes through ${slot} then ${role}`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            const re = new RegExp(
                'color\\s*:\\s*var\\(\\s*' + slot.replace(/[-]/g, '\\-') +
                '\\s*,\\s*var\\(\\s*' + role.replace(/[-]/g, '\\-') + '\\s*\\)\\s*\\)'
            );
            expect(rule.body).toMatch(re);
        });

        // Regression guard: the bg-image accent must NOT fall back to the bare accent
        // (the 1.16:1 bug) or the on-inverted role (wrong surface).
        test(`${sel} does not fall back to bare --color-accent or on-inverted`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).not.toMatch(/var\(\s*--color-accent\s*\)/);
            expect(rule.body).not.toMatch(/--color-accent-on-inverted/);
        });
    });

    // The overlay tokens must be declared in base.css with a type comment so the AI
    // token validator can reason about them, exactly like the on-inverted pair.
    test('base.css declares the overlay accent tokens with color type comments', () => {
        expect(BASE_CSS).toMatch(/--color-accent-on-overlay:[^;]*;\s*\/\*\s*color:/);
        expect(BASE_CSS).toMatch(/--color-accent-on-overlay-hover:[^;]*;\s*\/\*\s*color:/);
    });
});

describe('CSS lint: bg-image band title-accent + markers route through --color-accent-on-overlay (#463)', () => {
    // #461 routed the default LINK/NUMBER on the three bg-image bands through the overlay
    // accent role. #463 closes the remaining bare-accent surfaces on those same overlay
    // bands: the accented title substring (which paints its OWN color and does NOT inherit
    // the near-white band title, so it hit --color-accent at 1.16:1), the section body list
    // markers, and .hero--cover's title-accent (same --overlay-bg scrim idiom). Each default
    // routes through --color-accent-on-overlay — NOT the bare accent (the 1.16:1 bug) and NOT
    // --color-accent-on-inverted (tuned to the solid inverted bg). Per-instance slots still win.
    const stripped = stripComments(COMPONENTS_CSS);
    const rules = [];
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(stripped)) !== null) {
        rules.push({ selector: m[1].trim(), body: m[2] });
    }
    const rulesFor = (sel) => rules.filter(r => r.selector.split(',').some(s => s.trim() === sel));

    // The four accented title substrings on overlay bands. Each carries its own `color`
    // rule that must route slot → overlay role.
    const TITLE_ROUTES = [
        { sel: '.section--has-bg-image .section__title-accent', slot: '--section-heading-accent-color' },
        { sel: '.cta--has-bg-image .cta__title-accent', slot: '--cta-heading-accent-color' },
        { sel: '.stats--has-bg-image .stats__heading-accent', slot: '--stats-heading-accent-color' },
        { sel: '.hero--cover .hero__title-accent', slot: '--hero-heading-accent-color' },
    ];

    TITLE_ROUTES.forEach(({ sel, slot }) => {
        test(`${sel} routes through ${slot} then --color-accent-on-overlay`, () => {
            const matches = rulesFor(sel);
            expect(matches.length).toBeGreaterThan(0);
            const re = new RegExp(
                'color\\s*:\\s*var\\(\\s*' + slot.replace(/[-]/g, '\\-') +
                '\\s*,\\s*var\\(\\s*\\-\\-color\\-accent\\-on\\-overlay\\s*\\)\\s*\\)'
            );
            expect(matches.some(r => re.test(r.body))).toBe(true);
        });

        // Regression guard: must NOT fall back to bare --color-accent or the inverted role.
        test(`${sel} does not fall back to bare --color-accent or on-inverted`, () => {
            const matches = rulesFor(sel);
            expect(matches.length).toBeGreaterThan(0);
            const rule = matches.find(r => /color\s*:/.test(r.body));
            expect(rule).toBeDefined();
            expect(rule.body).not.toMatch(/var\(\s*--color-accent\s*\)/);
            expect(rule.body).not.toMatch(/--color-accent-on-inverted/);
        });
    });

    // Section body list markers on the overlay band: --pp-list-marker-color is re-mapped
    // to the overlay role. The selector also carries the near-white color rule, so find the
    // declaration that actually assigns the marker variable.
    test('.section--has-bg-image .section__content re-maps --pp-list-marker-color to the overlay role', () => {
        const rule = rulesFor('.section--has-bg-image .section__content')
            .find(r => /--pp-list-marker-color\s*:/.test(r.body));
        expect(rule).toBeDefined();
        expect(rule.body).toMatch(
            /--pp-list-marker-color\s*:\s*var\(\s*--section-body-marker-color\s*,\s*var\(\s*--color-accent-on-overlay\s*\)\s*\)/
        );
        // Regression guard: the marker default must not be the bare accent (1.16:1) here.
        expect(rule.body).not.toMatch(/--pp-list-marker-color\s*:\s*var\(\s*--section-body-marker-color\s*,\s*var\(\s*--color-accent\s*\)\s*\)/);
    });
});

describe('CSS lint: dark-band buttons route through the AA accent roles (#535)', () => {
    /*
     * #474 routed the cta's SECOND button; #535 closes the rest of the same class:
     * the PRIMARY outline/ghost button on both cta dark bands, the hero's primary AND
     * default second CTA on the `.hero--cover` scrim, and the filled primary's missing
     * separation ring on the two OVERLAY bands.
     *
     * Measured before -> after (rendered, worst-case composites):
     *   cta inverted outline/ghost       3.23 -> 8.33  (--color-accent-on-inverted)
     *   cta bg-image outline/ghost       1.17 -> 4.59  (--color-accent-on-overlay)
     *   hero cover outline / ghost ~3.6 / 1.17 -> 4.59 (--color-accent-on-overlay)
     *   hero cover cta2 outline/ghost   ~3.6 -> 4.59  (--color-accent-on-overlay)
     *   filled button ring, overlay bands: fill under 2:1, ring -> 4.59
     *
     * on-inverted is tuned to the SOLID inverted background and only reaches ~2.2:1 over
     * the arbitrary-image scrim, so the two roles are never interchangeable — every route
     * below pins which role its band must use, and guards against a regression to the
     * bare light-surface accent.
     */
    const css = stripComments(COMPONENTS_CSS);
    const rules = [];
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(css)) !== null) {
        rules.push({ selector: m[1].trim(), body: m[2], index: m.index });
    }
    const rulesForAll = (sel) => rules.filter(r => r.selector.split(',').some(s => s.trim() === sel));
    const ruleFor = (sel) => rulesForAll(sel)[0];

    // selector -> { slot, role, border } . `border: true` means the ring is routed too
    // (outline paints a visible ring; ghost's border stays transparent by design).
    const ROUTES = [
        // cta PRIMARY, solid inverted band.
        { sel: '.cta--inverted .cta__button.btn--outline', slot: '--cta-button-color', borderSlot: '--cta-button-border', role: '--color-accent-on-inverted' },
        { sel: '.cta--inverted .cta__button.btn--ghost', slot: '--cta-button-color', borderSlot: null, role: '--color-accent-on-inverted' },
        // cta PRIMARY, bg-image (overlay) band.
        { sel: '.cta--has-bg-image .cta__button.btn--outline', slot: '--cta-button-color', borderSlot: '--cta-button-border', role: '--color-accent-on-overlay' },
        { sel: '.cta--has-bg-image .cta__button.btn--ghost', slot: '--cta-button-color', borderSlot: null, role: '--color-accent-on-overlay' },
        // hero PRIMARY on the cover scrim (slot is --hero-heading-color, the surface
        // `.hero .btn--outline` already reads — see the rule comment).
        { sel: '.hero--cover .btn--outline', slot: '--hero-heading-color', borderSlot: '--hero-heading-color', role: '--color-accent-on-overlay' },
        { sel: '.hero--cover .btn--ghost', slot: '--hero-heading-color', borderSlot: null, role: '--color-accent-on-overlay' },
        // hero SECOND CTA on the cover scrim (#535 Q3).
        { sel: '.hero--cover .hero__cta-group .hero__cta--secondary.btn--outline', slot: '--hero-button2-color', borderSlot: '--hero-button2-border', role: '--color-accent-on-overlay' },
        { sel: '.hero--cover .hero__cta-group .hero__cta--secondary.btn--ghost', slot: '--hero-button2-color', borderSlot: null, role: '--color-accent-on-overlay' },
    ];

    const esc = (s) => s.replace(/[-]/g, '\\-');

    ROUTES.forEach(({ sel, slot, borderSlot, role }) => {
        test(`${sel} routes ink through ${slot} then ${role}`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).toMatch(new RegExp(
                'color\\s*:\\s*var\\(\\s*' + esc(slot) + '\\s*,\\s*var\\(\\s*' + esc(role) + '\\s*\\)\\s*\\)'
            ));
        });

        if (borderSlot) {
            test(`${sel} routes its ring through ${borderSlot} then ${role}`, () => {
                const rule = ruleFor(sel);
                expect(rule).toBeDefined();
                expect(rule.body).toMatch(new RegExp(
                    'border-color\\s*:\\s*var\\(\\s*' + esc(borderSlot) + '\\s*,\\s*var\\(\\s*' + esc(role) + '\\s*\\)\\s*\\)'
                ));
            });
        } else {
            test(`${sel} does not paint a ring (ghost stays borderless)`, () => {
                const rule = ruleFor(sel);
                expect(rule).toBeDefined();
                expect(rule.body).not.toMatch(/border-color\s*:/);
            });
        }

        test(`${sel} does not fall back to the bare accent or the wrong role`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            // The 3.23:1 / 1.17:1 bug: a bare light-surface accent default.
            expect(rule.body).not.toMatch(/var\(\s*--color-accent\s*\)/);
            // Wrong surface: on-inverted is ~2.2:1 over the arbitrary-image scrim, and
            // on-overlay is not the tuned choice for the solid inverted band.
            const wrongRole = role === '--color-accent-on-overlay'
                ? '--color-accent-on-inverted'
                : '--color-accent-on-overlay';
            expect(rule.body).not.toMatch(new RegExp(esc(wrongRole)));
        });
    });

    // ── The filled primary's separation ring, OVERLAY bands only ──────────────
    /*
     * The ring rules replace ONLY the terminal fallback of the chain they override. Every
     * authored link ahead of the role token must survive verbatim, or an author who
     * already coloured this ring (via --cta-accent, --hero-button-bg, the global
     * --btn-* knobs) silently gets a near-white ring instead of theirs. `leading` is the
     * chain the corresponding base rule declares, in order; `bottom` is the role token
     * that replaces the base rule's own terminal --color-accent.
     *
     * Both hover twins exist because the base `:hover` rules are [0,6,0] and outrank the
     * [0,5,0] rest rings: without them the ring appeared at rest and dissolved back into
     * the band the moment the pointer landed, which is the state a user is most likely
     * looking at (WCAG 1.4.11 covers hover too).
     */
    /*
     * #564 narrowed the "verbatim, only the terminal changes" contract above to "verbatim
     * MINUS the global RING knob", and #565 completed the narrowing to "verbatim MINUS the
     * whole GLOBAL tier" by removing --btn-bg / --btn-hover-bg too. On these bands the terminal
     * is not an ordinary default but a measured 4.59:1 separation role. --btn-border-color /
     * --btn-hover-border-color sitting above it let a site-wide RING retheme defeat the
     * guarantee directly; --btn-bg / --btn-hover-bg defeated the same role indirectly, through
     * the border-follows-fill link (#535), so a site that set only a global button FILL
     * repainted every unauthored photo-band ring to a colour never measured against the scrim.
     * Each knob is REMOVED rather than demoted because --color-accent-on-overlay is declared at
     * :root (base.css) and therefore always set — any link below it is dead code. Per-instance
     * slots stay above everything as the escape hatch, which is exactly why the PER-INSTANCE
     * fill link survives below while the global one does not: #535's matching-ring promise was
     * written for an author who flattens THIS band, and it still holds for them.
     * Both halves of each twin lost each knob together: dropping one from hover only would
     * re-open the rest->hover ring flip these twins exist to prevent.
     * These `leading` arrays are the deliberate positive pins of that contract, FLIPPED rather
     * than deleted (the #538/#530 pattern) — see the #565 decision comment (2026-07-29).
     */
    const RINGS = [
        {
            sel: '.cta--has-bg-image .cta__button:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)',
            // #564 also moved --cta-accent above --cta-button-bg here, mirroring the base rest
            // chain, so this rule and its :hover twin below are positional twins. #565 dropped
            // the trailing --btn-bg; --cta-button-bg, the per-instance link, stays.
            leading: ['--cta-button-border', '--cta-accent', '--cta-button-bg'],
        },
        {
            sel: '.cta--has-bg-image .cta__button:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover',
            // --cta-accent-hover sits ahead of --cta-button-hover-bg since #548, and since #564
            // the rest twin uses the SAME order rather than the opposite one — the #538 Option-3
            // asymmetry is retired, not preserved. #565 dropped the trailing --btn-hover-bg, so
            // the twins stay positional: same links, same order, both states.
            leading: ['--cta-button-hover-border', '--cta-accent-hover', '--cta-button-hover-bg'],
        },
        {
            sel: '.hero--cover .hero__cta:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)',
            // #584 put the hero primary's OWN ring slot at the head, matching the cta rows
            // above. This rule is the live border winner on a cover hero ([0,5,0], same as
            // `.hero .btn:not3`, but later in source order), so a slot missing from THIS
            // chain is dead on the one band where a per-instance ring matters most — the
            // asymmetry this row used to encode, now closed.
            leading: ['--hero-button-border', '--hero-accent', '--hero-button-bg'],
        },
        {
            sel: '.hero--cover .hero__cta:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover',
            leading: ['--hero-button-hover-border', '--hero-accent-hover', '--hero-button-hover-bg'],
        },
    ];

    RINGS.forEach(({ sel, leading }) => {
        test(`${sel} bottoms the ring out at the overlay role`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).toMatch(/border-color\s*:/);
            expect(rule.body).toMatch(/--color-accent-on-overlay/);
            // Wrong surface: on-inverted is only ~2.2:1 over the arbitrary-image scrim.
            expect(rule.body).not.toMatch(/--color-accent-on-inverted/);
            // The role must be the LAST link, not an early one that pre-empts a slot.
            const chain = rule.body.match(/border-color\s*:([^;}]+)/)[1];
            const tokens = chain.match(/--[a-z0-9-]+/g);
            expect(tokens[tokens.length - 1]).toBe('--color-accent-on-overlay');
        });

        test(`${sel} preserves every authored slot ahead of the role, in order`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            const chain = rule.body.match(/border-color\s*:([^;}]+)/)[1];
            const tokens = chain.match(/--[a-z0-9-]+/g).filter(t => t !== '--color-accent-on-overlay');
            expect(tokens).toEqual(leading);
        });

        test(`${sel} does not bottom out at the bare accent (the pre-#535 behaviour)`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            const chain = rule.body.match(/border-color\s*:([^;}]+)/)[1];
            expect(chain).not.toMatch(/var\(\s*--color-accent\s*\)/);
            expect(chain).not.toMatch(/var\(\s*--color-accent-hover\s*\)/);
        });
    });

    /*
     * NEGATIVE pin (#535 Q2). The INVERTED filled primary measures 3.23:1 fill-vs-band,
     * which already clears the 3:1 non-text bar, so it is deliberately NOT ringed. If a
     * future edit adds an on-inverted ring here it is a gratuitous visual change to every
     * dark-band CTA with no measured defect behind it — fail instead.
     */
    test('the INVERTED filled primary is not ringed (3.23:1 already clears the 3:1 bar)', () => {
        const offenders = rules.filter(r =>
            /\.cta--inverted\b/.test(r.selector)
            && /:not\(\.btn--outline\)/.test(r.selector)
            && /border-color\s*:/.test(r.body)
        );
        expect(offenders.map(r => r.selector)).toEqual([]);
    });

    /*
     * SOURCE-ORDER pins. Three of these rule groups carry the SAME specificity as the
     * rule they must override, so they win only by following it. This is not a style
     * preference — `.hero--cover .btn--outline` used to sit ABOVE `.hero .btn--outline`
     * (both [0,2,0]) and was therefore DEAD, which is precisely how the near-black-ink
     * defect shipped. Moving any of these up silently restores the bug.
     */
    const ORDER = [
        {
            later: '.hero--cover .btn--outline',
            earlier: '.hero .btn--outline',
            why: 'both [0,2,0]; the cover rule was dead above it (the #535 near-black-ink defect)',
        },
        {
            later: '.hero--cover .btn--ghost',
            earlier: '.btn--ghost',
            why: 'both paint ink; the cover rule is [0,2,0] vs the shared [0,1,0]',
        },
        {
            later: '.hero--cover .hero__cta-group .hero__cta--secondary.btn--outline',
            earlier: '.hero .hero__cta-group .hero__cta--secondary.btn--outline',
            why: 'both [0,4,0]',
        },
        {
            later: '.hero--cover .hero__cta-group .hero__cta--secondary.btn--ghost',
            earlier: '.hero .hero__cta-group .hero__cta--secondary.btn--ghost',
            why: 'both [0,4,0]',
        },
        {
            later: '.hero--cover .hero__cta:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)',
            earlier: '.hero .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)',
            why: 'both [0,5,0]',
        },
        {
            later: '.hero--cover .hero__cta:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover',
            earlier: '.hero .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover',
            why: 'both [0,6,0]; without this the ring dissolves again on hover',
        },
        {
            later: '.cta--has-bg-image .cta__button:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)',
            earlier: '.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary)',
            why: 'both [0,5,0]',
        },
        {
            later: '.cta--has-bg-image .cta__button:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover',
            earlier: '.cta .btn:not(.btn--outline):not(.btn--ghost):not(.btn--secondary):hover',
            why: 'both [0,6,0]; without this the ring dissolves again on hover',
        },
        /*
         * A cta can carry BOTH classes: cta.php emits the theme class and the bg-image
         * class independently, so `theme: "inverted"` + `background_image` renders
         * `.cta--inverted.cta--has-bg-image` with the scrim painted over the inverted
         * background. Both routing rules then match at [0,3,0] and only source order
         * decides. The overlay role must win — on-inverted is only ~2.2:1 over the scrim.
         */
        {
            later: '.cta--has-bg-image .cta__button.btn--outline',
            earlier: '.cta--inverted .cta__button.btn--outline',
            why: 'both [0,3,0]; an inverted band WITH a background_image must resolve to the overlay role',
        },
        {
            later: '.cta--has-bg-image .cta__button.btn--ghost',
            earlier: '.cta--inverted .cta__button.btn--ghost',
            why: 'both [0,3,0]; same combined-band case',
        },
        {
            later: '.cta--has-bg-image .cta__buttons .cta__button--secondary.btn--outline',
            earlier: '.cta--inverted .cta__buttons .cta__button--secondary.btn--outline',
            why: 'both [0,4,0]; the #474 button2 pair has the identical combined-band dependency',
        },
    ];

    ORDER.forEach(({ later, earlier, why }) => {
        test(`${later} comes after ${earlier} (${why})`, () => {
            const l = ruleFor(later);
            const e = ruleFor(earlier);
            expect(l).toBeDefined();
            expect(e).toBeDefined();
            expect(l.index).toBeGreaterThan(e.index);
        });
    });

    /*
     * UNIQUENESS. The order pins above compare the FIRST rule carrying each selector, so
     * on their own they cannot catch the regression the CSS comments actually warn about:
     * a DUPLICATE rule appended further down the file wins the cascade while every order
     * pin still passes (confirmed by mutation — appending
     * `.hero--cover .btn--outline { color: red }` left the whole suite green). Requiring
     * exactly one rule per routed selector closes that hole.
     */
    const UNIQUE_SELECTORS = ROUTES.map(r => r.sel)
        .concat(RINGS.map(r => r.sel))
        .concat(ORDER.map(o => o.later));

    [...new Set(UNIQUE_SELECTORS)].forEach(sel => {
        test(`${sel} is declared exactly once (a later duplicate would silently win)`, () => {
            expect(rulesForAll(sel).length).toBe(1);
        });
    });

    /*
     * The cta PRIMARY rules must stay BELOW the #474 button2 rules in specificity, not
     * just in source order: the second button also carries `.cta__button`, so an equal
     * or higher primary rule would repaint it and break the #474/#526/#530 pins.
     */
    test('the cta primary dark-band rules do not outrank the button2 rules', () => {
        // [0,3,0] vs [0,4,0]: the primary selector must NOT carry the --secondary class,
        // and the button2 selector must carry one more class than the primary one.
        const primary = ruleFor('.cta--inverted .cta__button.btn--outline');
        const second = ruleFor('.cta--inverted .cta__buttons .cta__button--secondary.btn--outline');
        expect(primary).toBeDefined();
        expect(second).toBeDefined();
        const classCount = (sel) => (sel.match(/\.[a-zA-Z][\w-]*/g) || []).length;
        expect(classCount(second.selector)).toBeGreaterThan(classCount(primary.selector));
    });

    /*
     * HOVER leak guard. The rest rules tie on specificity with the `:hover` rules they
     * follow (a pseudo-class counts as a class), so source order made the routed
     * dark-band ink win on hover too — landing it on a fill it was never measured
     * against (on-inverted ink over the accent fill is 2.58:1; on-overlay ink over the
     * near-white ghost hover fill is effectively invisible). The restoration rules must
     * exist AND must not themselves carry a role token: on hover each variant paints its
     * own contrasting fill, so the correct ink is the variant's original hover value.
     */
    // `source` is the shared rule whose hover ink each restoration copies verbatim. If the
    // shared rule's value is ever changed, the copy must move with it — so assert the
    // declarations are IDENTICAL rather than merely present, which is what makes a silent
    // divergence fail here instead of shipping.
    const HOVER_RESTORED = [
        { sel: '.cta--inverted .cta__button.btn--outline:hover', source: '.cta__button.btn--outline:hover' },
        { sel: '.cta--has-bg-image .cta__button.btn--outline:hover', source: '.cta__button.btn--outline:hover' },
        { sel: '.cta--inverted .cta__button.btn--ghost:hover', source: '.cta__button.btn--ghost:hover' },
        { sel: '.cta--has-bg-image .cta__button.btn--ghost:hover', source: '.cta__button.btn--ghost:hover' },
        { sel: '.hero--cover .btn--ghost:hover', source: '.btn--ghost:hover' },
    ];

    // `color:` alone also matches `border-color:` / `background-color:`, which would let a
    // restoration rule that dropped its ink declaration entirely still pass.
    const inkDecl = (body) => {
        const m = body.match(/(?:^|[;{\s])color\s*:([^;}]+)/);
        return m ? m[1].replace(/\s+/g, ' ').trim() : null;
    };

    HOVER_RESTORED.forEach(({ sel, source }) => {
        test(`${sel} restores exactly the ink ${source} declares`, () => {
            const rule = ruleFor(sel);
            const src = ruleFor(source);
            expect(rule).toBeDefined();
            expect(src).toBeDefined();
            expect(inkDecl(rule.body)).not.toBeNull();
            expect(inkDecl(rule.body)).toBe(inkDecl(src.body));
        });

        test(`${sel} carries no dark-band role token (on hover the fill contrasts, not the band)`, () => {
            const rule = ruleFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).not.toMatch(/--color-accent-on-inverted|--color-accent-on-overlay/);
        });

        test(`${sel} follows its rest rule in source order`, () => {
            const restSel = sel.replace(/:hover$/, '');
            const hover = ruleFor(sel);
            const rest = ruleFor(restSel);
            expect(hover).toBeDefined();
            expect(rest).toBeDefined();
            expect(hover.index).toBeGreaterThan(rest.index);
        });
    });

    test('the roles these rules depend on are declared in base.css :root', () => {
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted:\s*#[0-9a-fA-F]{6}/);
        expect(BASE_CSS).toMatch(/--color-accent-on-overlay:\s*#[0-9a-fA-F]{6}/);
    });
});

/**
 * The isolation re-pointing declarations depend on GUARANTEED-INVALID custom properties
 * (#514/#526/#474/#530).
 *
 * `.hero__cta--secondary { --hero-button-bg: var(--hero-button2-bg); }` works because, with
 * --hero-button2-bg unset, the var() cannot substitute and --hero-button-bg becomes
 * guaranteed-invalid — so every downstream `var(--hero-button-bg, <fallback>)` takes its
 * fallback and an unset button renders byte-identically.
 *
 * Registering ANY of these four names with `@property` (or CSS.registerProperty) destroys
 * that: a registered property with an initial value is never guaranteed-invalid, so the
 * failed substitution would resolve to the registered initial instead of falling through.
 * Every unset second button would silently flip off the premium gradient. The invariant is
 * load-bearing and otherwise invisible, so it gets its own guard.
 */
describe('CSS lint: fill-slot re-pointing targets are never @property-registered (#530)', () => {
    const GUARANTEED_INVALID_SLOTS = [
        // Re-pointing TARGETS: the properties the isolation rules declare.
        '--hero-button-bg',
        '--hero-button-hover-bg',
        '--cta-button-bg',
        '--cta-button-hover-bg',
        // Re-pointing SOURCES: equally load-bearing. `--hero-button-bg: var(--hero-button2-bg)`
        // is only guaranteed-invalid because --hero-button2-bg is ITSELF unregistered. Register
        // the source and the var() always substitutes (to the registered initial), so the
        // target is never invalid and every unset second button silently leaves the premium
        // gradient for that initial value.
        '--hero-button2-bg',
        '--hero-button2-hover-bg',
        '--cta-button2-bg',
        '--cta-button2-hover-bg',
    ];

    test('no shipped stylesheet registers a re-pointing target with @property', () => {
        const offenders = [];
        // Scan every shipped stylesheet, not just components.css: an @property block
        // anywhere in the cascade would break the invariant.
        Object.entries({
            'components.css': COMPONENTS_CSS,
            'base.css': BASE_CSS,
            'utilities.css': UTILITIES_CSS,
        }).forEach(([name, css]) => {
            const stripped = stripComments(css);
            GUARANTEED_INVALID_SLOTS.forEach((slot) => {
                const re = new RegExp('@property\\s+' + slot + '\\b');
                if (re.test(stripped)) offenders.push(`${name} registers ${slot}`);
            });
        });
        expect(offenders).toEqual([]);
    });

    // Detection proof: the scan must actually catch a registration.
    test('detector flags an @property registration of a re-pointing target', () => {
        const bad = '@property --hero-button-hover-bg { syntax: "<color>"; inherits: true; initial-value: red; }';
        const hits = GUARANTEED_INVALID_SLOTS.filter((slot) =>
            new RegExp('@property\\s+' + slot + '\\b').test(bad),
        );
        expect(hits).toEqual(['--hero-button-hover-bg']);
    });
});

/**
 * The filled SECOND button's separation ring on the two OVERLAY bands (#543).
 *
 * #535 gave the ring to the filled PRIMARY only. The second button's own rules are one
 * class higher ([0,6,0] rest / [0,7,0] hover vs the primary ring's [0,5,0]/[0,6,0]) and
 * bottomed out at the bare --color-accent, so a `primary` + `primary` pair on a photo
 * band rendered ONE button with a visible edge next to one dissolving into the scrim.
 *
 * These pins mirror #535's RINGS/ORDER contract at the second button's specificity, with
 * three differences that matter:
 *
 *   1. Media-aware (parseRules, the #542 idiom). #535's block uses a media-blind local
 *      parser; a ring wrapped in a never-matching @media would read as green there.
 *   2. Both twins are REQUIRED, not just the rest one. #538 made the base hover ring
 *      follow the hover fill, so the last incidental fill-vs-ring edge on these bands is
 *      gone — a rest-only ring dissolves again under the pointer (WCAG 1.4.11 covers
 *      hover), which is exactly the defect #535's rendered pass caught on the primary.
 *   3. The hover chain's ORDER is #538's Option 3 (accent knob AHEAD of the fill), and since
 *      #564 the REST chain uses that order too — the two are positional twins. `leading` now
 *      encodes the PARITY rather than the old asymmetry, so a future edit that re-splits them
 *      fails here. Reintroducing the split would repaint authored --cta-accent /
 *      --hero-accent rings and restore the rest->hover flip #564 retired
 *      (issuecomment-5106604500); it is a maintainer decision, not a cleanup.
 *
 * The base (non-overlay) rules keep their own --color-accent terminals, pinned below:
 * that is what makes every light band byte-identical.
 */
describe('CSS lint: the filled second button is ringed on overlay bands (#543)', () => {
    const rules = parseRules();
    const rulesForAll = (sel) => rules.filter(r => r.selectors.includes(sel));
    const ruleFor = (sel) => rulesForAll(sel)[0];

    const NOT3 = ':not(.btn--outline):not(.btn--ghost):not(.btn--secondary)';
    const CTA2_BASE = '.cta .cta__buttons .cta__button--secondary' + NOT3;
    const CTA2_RING = '.cta--has-bg-image .cta__buttons .cta__button--secondary' + NOT3;
    const HERO2_BASE = '.hero .hero__cta-group .hero__cta--secondary' + NOT3;
    const HERO2_RING = '.hero--cover .hero__cta-group .hero__cta--secondary' + NOT3;

    // `leading` = the authored links that MUST survive ahead of the role token, in order.
    // Each is its base rule's chain MINUS the whole GLOBAL tier, with the role terminal
    // (ring knobs removed by #564, fill knobs by #565).
    // #554 had added the global tier here on the reasoning that a cover band must not be the
    // one band a site-wide retheme fails to reach; #564 and #565 record the counterweight — on
    // THESE bands the terminal carries a measured 4.59:1 separation guarantee, so a broader
    // default must not sit above it. The RING knobs defeated it directly; the FILL knobs
    // defeated it through the border-follows-fill link (#535), which is why they had to go too.
    // Removed, not demoted: --color-accent-on-overlay is a :root token (base.css) and always
    // set, so a link below it is dead code. Rest and hover lost each knob together, or the
    // twins would disagree across the pointer transition.
    // The PER-INSTANCE fill link stays in every row below — that is the narrowing, not a
    // reversal: an author who flattens THIS band still gets a matching ring (#535).
    const RINGS = [
        { sel: CTA2_RING, base: CTA2_BASE, terminal: '--color-accent',
          // #564 also lifted --cta-accent above --cta-button2-bg, mirroring the base rest chain.
          leading: ['--cta-button2-border', '--cta-accent', '--cta-button2-bg'] },
        { sel: CTA2_RING + ':hover', base: CTA2_BASE + ':hover', terminal: '--color-accent-hover',
          // #538's Option-3 order (--cta-accent-hover ahead of the hover fill) survives, and
          // since #564 the rest row uses it too, so the two rows are positional twins.
          leading: ['--cta-button2-hover-border', '--cta-accent-hover', '--cta-button2-hover-bg'] },
        { sel: HERO2_RING, base: HERO2_BASE, terminal: '--color-accent',
          leading: ['--hero-button2-border', '--hero-accent', '--hero-button2-bg'] },
        { sel: HERO2_RING + ':hover', base: HERO2_BASE + ':hover', terminal: '--color-accent-hover',
          leading: ['--hero-button2-hover-border', '--hero-accent-hover', '--hero-button2-hover-bg'] },
    ];

    const chainOf = (rule) => {
        const decl = rule.body.match(/border-color\s*:([^;}]+)/);
        expect(decl).not.toBeNull();
        return decl[1];
    };

    RINGS.forEach(({ sel, base, terminal, leading }) => {
        test(`${sel} exists, at top level, exactly once`, () => {
            const found = rulesForAll(sel);
            // Exactly one: a duplicate later in the file wins the cascade while every
            // order pin below still passes (the hole #535's uniqueness pin closes).
            expect(found.length).toBe(1);
            // Top level: a ring that only paints inside an @media is not a ring.
            expect(found[0].media).toBeNull();
            expect(found[0].body).toMatch(/border-color\s*:/);
        });

        test(`${sel} bottoms the ring out at the overlay role`, () => {
            const tokens = chainOf(ruleFor(sel)).match(/--[a-z0-9-]+/g);
            expect(tokens[tokens.length - 1]).toBe('--color-accent-on-overlay');
            // on-inverted is only ~2.2:1 over the arbitrary-image scrim — never here.
            expect(tokens).not.toContain('--color-accent-on-inverted');
        });

        test(`${sel} preserves every authored slot ahead of the role, in order`, () => {
            const tokens = chainOf(ruleFor(sel))
                .match(/--[a-z0-9-]+/g)
                .filter(t => t !== '--color-accent-on-overlay');
            expect(tokens).toEqual(leading);
        });

        test(`${sel} no longer bottoms out at the bare accent`, () => {
            const chain = chainOf(ruleFor(sel));
            expect(chain).not.toMatch(/var\(\s*--color-accent\s*\)/);
            expect(chain).not.toMatch(/var\(\s*--color-accent-hover\s*\)/);
        });

        test(`${sel} follows ${base} in source order (equal specificity, order decides)`, () => {
            const ring = ruleFor(sel);
            const baseRule = ruleFor(base);
            expect(ring).toBeDefined();
            expect(baseRule).toBeDefined();
            expect(ring.index).toBeGreaterThan(baseRule.index);
        });

        test(`${base} keeps its own ${terminal} terminal (light bands stay byte-identical)`, () => {
            const tokens = chainOf(ruleFor(base)).match(/--[a-z0-9-]+/g);
            expect(tokens[tokens.length - 1]).toBe(terminal);
            expect(tokens).not.toContain('--color-accent-on-overlay');
        });

        /*
         * The order pin above compares the FIRST rule carrying each selector, so uniqueness
         * has to hold on BOTH sides of the comparison. #535's uniqueness section proved by
         * mutation that a duplicate appended later wins the cascade while every order pin
         * stays green; it pinned that for the ring selectors only. A duplicate of the BASE
         * rule placed AFTER the ring is the mirror image of that hole — equal specificity,
         * later in source order, so it takes the ring back off — and it is invisible to
         * every assertion above. Same reason the media context is pinned: a base rule
         * re-declared inside an @media block would outrank the top-level ring at one
         * breakpoint only, which is exactly the class of bug a media-blind scan misses.
         */
        test(`${base} is itself declared exactly once, at top level`, () => {
            const found = rulesForAll(base);
            expect(found.length).toBe(1);
            expect(found[0].media).toBeNull();
        });
    });

    /*
     * NEGATIVE pin, the button2 half of #535 Q2. The SOLID inverted filled button measures
     * 3.23:1 fill-vs-band, clearing the 3:1 non-text bar, so it is deliberately NOT
     * ringed — for the second button exactly as for the primary. #535's own negative pin
     * already scans every `.cta--inverted` + `:not(.btn--outline)` rule; this one names
     * the button2 selector explicitly so the refusal is legible at the specificity a
     * future edit would actually reach for.
     *
     * SOLID is the operative word. A cta can carry BOTH classes — cta.php emits the theme
     * class and the bg-image class independently — and `.cta--inverted.cta--has-bg-image`
     * DOES get the ring, correctly: the scrim sits over the inverted background, and
     * on-inverted is only ~2.2:1 over an arbitrary image. This pin scans for a rule whose
     * SELECTOR names `.cta--inverted`, so the combined band (which matches the ring rule
     * through `.cta--has-bg-image`) is untouched by it. The rendered half of that case is
     * pinned in style-render.spec.ts, the same way #535 pins it for the primary.
     */
    test('the SOLID inverted filled second button is not ringed (Q2 refusal, unchanged)', () => {
        const offenders = rules.filter(r =>
            r.selectors.some(s =>
                /\.cta--inverted\b/.test(s)
                && /\.cta__button--secondary\b/.test(s)
                && /:not\(\.btn--outline\)/.test(s))
            && /border-color\s*:/.test(r.body));
        expect(offenders.map(r => r.selectors.join(', '))).toEqual([]);
    });

    /*
     * BOUNDARY pin. The `.cta--dark` theme block carries a NOTE telling future editors not
     * to add a competing `.cta--inverted` / `.cta--has-bg-image` button rule down there,
     * because it would win on source order and silently defeat the routing above. The ring
     * comment leans on that NOTE as its guardrail, so the guardrail has to be a test: a
     * comment cannot fail CI. Two halves — the ring rules must sit ABOVE the theme block,
     * and nothing below it may declare border-color on a cta button on either dark band.
     *
     * This is the one pin that catches a competing rule spelled DIFFERENTLY from the ring
     * selectors (`.cta--dark .cta__button--secondary:not(...)`, or a `.cta--has-bg-image`
     * rule that drops the `.cta__buttons` link). The uniqueness pins only match exact
     * selector strings, so such a rule evades them entirely while winning the cascade.
     */
    test('the ring rules sit ABOVE the .cta--dark theme block', () => {
        const themeBlock = ruleFor('.cta--dark');
        expect(themeBlock).toBeDefined();
        [CTA2_RING, CTA2_RING + ':hover'].forEach(sel => {
            expect(ruleFor(sel).index).toBeLessThan(themeBlock.index);
        });
    });

    test('no rule below .cta--dark sets a cta button border on either dark band', () => {
        const themeBlock = ruleFor('.cta--dark');
        const offenders = rules.filter(r =>
            r.index > themeBlock.index
            && /border-color\s*:/.test(r.body)
            && r.selectors.some(s =>
                /\.cta--(dark|inverted|has-bg-image)\b/.test(s) && /\.cta__button\b/.test(s)));
        expect(offenders.map(r => r.selectors.join(', '))).toEqual([]);
    });

    /*
     * SCOPE pin: the ring stays on the FILLED variant. outline and secondary paint a real
     * ring of their own and ghost's border bottoms out at `transparent`, so routing the
     * fill chain there would repaint an authored edge or ADD one to a borderless button —
     * the same scoping #538 pins for the hover fill-follow. Dropping any :not() from a
     * ring selector is the mechanical way that happens, so pin all three.
     */
    RINGS.forEach(({ sel }) => {
        test(`${sel} excludes all three transparent-fill variants`, () => {
            ['.btn--outline', '.btn--ghost', '.btn--secondary'].forEach(v => {
                expect(ruleFor(sel).selectors[0]).toContain(`:not(${v})`);
            });
        });
    });
});

/**
 * The FOCUS RING on dark bands (#542).
 *
 * `main .btn:focus, main .btn:focus-visible` paints the live focus indicator for every
 * composed button, and `outline-offset` puts it OUTSIDE the button — on the BAND. The
 * bare light-surface --color-accent measured there:
 *
 *   --color-bg-inverted (theme:"inverted")                  3.23:1
 *   --overlay-bg scrim over a WHITE image, rgb(115,115,115)  1.17:1  <- 1.4.11 failure
 *   same scrim over a mid-grey image, rgb(58,58,58)          2.06:1  <- 1.4.11 failure
 *
 * Both bands now bottom out at the role token base.css defines for them (8.33:1 and
 * 4.59:1). The routing is COLOUR-ONLY: width, style, offset and the box-shadow glow all
 * stay with the base rules, so light bands are byte-identical and no ring is revealed
 * that was not already painted.
 *
 * SECTION bands are deliberately absent from ROUTES. A section's only rendered button is
 * `.section__panel-cta`, which sits inside `.section__panel` — a LIGHT surface
 * (--color-surface) with --space-lg padding, so the ring lands on the panel, not the band.
 * Routing it measured 5.18:1 -> 2.02:1 / 1.04:1, i.e. worse than the bug. Same carve-out
 * #424/#463 made for the panel's list markers. The NEGATIVE pin below keeps it that way.
 */
describe('CSS lint: dark-band focus ring routes through the AA accent roles (#542)', () => {
    // Media-aware and whole-file: a routed block wrapped in a never-matching @media, or a
    // reverting rule declared inside one, must not read as green (see parseRules).
    const rules = parseRules();
    // A routed BLOCK is one rule with a grouped selector, so look it up by any member.
    const blocksFor = (sel) => rules.filter(r => r.selectors.includes(sel));
    const blockFor = (sel) => blocksFor(sel)[0];
    const esc = (s) => s.replace(/[-]/g, '\\-');

    const INVERTED_SELECTORS = [
        '.cta--inverted .btn:focus',
    ];
    const OVERLAY_SELECTORS = [
        '.hero--cover .btn:focus',
        '.cta--has-bg-image .btn:focus',
    ];
    const ROUTES = INVERTED_SELECTORS.map(sel => ({ sel, role: '--color-accent-on-inverted' }))
        .concat(OVERLAY_SELECTORS.map(sel => ({ sel, role: '--color-accent-on-overlay' })));

    ROUTES.forEach(({ sel, role }) => {
        test(`${sel} routes the ring through ${role}`, () => {
            const rule = blockFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).toMatch(new RegExp(
                'outline-color\\s*:\\s*var\\(\\s*' + esc(role) + '\\s*\\)'
            ));
        });

        test(`${sel} does not fall back to the bare accent or the wrong role`, () => {
            const rule = blockFor(sel);
            expect(rule).toBeDefined();
            // The 3.23:1 / 1.17:1 bug: the bare light-surface accent.
            expect(rule.body).not.toMatch(/var\(\s*--color-accent\s*\)/);
            // Wrong surface: on-inverted is only 2.21:1 over the arbitrary-image scrim,
            // and on-overlay is not the tuned choice for the solid inverted band.
            const wrongRole = role === '--color-accent-on-overlay'
                ? '--color-accent-on-inverted'
                : '--color-accent-on-overlay';
            expect(rule.body).not.toMatch(new RegExp(esc(wrongRole)));
        });

        /*
         * COLOUR-ONLY. Re-declaring the `outline` SHORTHAND here would reset offset to its
         * initial 0 and collapse the ring onto the button edge; re-declaring width or style
         * would reveal a ring on surfaces that deliberately suppress one. Width, style,
         * offset and the box-shadow glow must all stay upstream.
         */
        test(`${sel} overrides ONLY the outline colour`, () => {
            const rule = blockFor(sel);
            expect(rule).toBeDefined();
            const props = rule.body
                .split(';')
                .map(d => d.split(':')[0].trim())
                .filter(Boolean);
            expect(props).toEqual(['outline-color']);
        });

        /*
         * A DUPLICATE rule appended later wins the cascade while every other pin here still
         * passes — the same hole the #535 uniqueness pin closes.
         */
        test(`${sel} is declared exactly once (a later duplicate would silently win)`, () => {
            expect(blocksFor(sel).length).toBe(1);
        });

        // A routed block moved into a media block applies only at that width; every other
        // pin here still reads green because the rule text is unchanged.
        test(`${sel} is declared at the top level, not inside a media block`, () => {
            expect(blockFor(sel).media).toBeNull();
        });
    });

    /*
     * SEMANTIC PRECEDENCE, not formatting. ONE root can carry an inverted class AND a
     * bg-image class: components/cta/cta.php:75 concatenates `pp_theme_class($theme,'cta')`
     * and `cta--has-bg-image` independently (section.php:186 does the same, which is why
     * section bands would have had the identical dependency had they been routed). The two
     * blocks then match at [0,3,0] and only source order decides. The OVERLAY role must
     * win: on-inverted is only 2.21:1 over the worst-case scrim, so the combined band would
     * otherwise get a ring that fails 1.4.11 harder than the bug this fixes.
     */
    test('the overlay block follows the inverted block (combined inverted + bg-image cta)', () => {
        const inverted = blockFor('.cta--inverted .btn:focus');
        const overlay = blockFor('.cta--has-bg-image .btn:focus');
        expect(inverted).toBeDefined();
        expect(overlay).toBeDefined();
        expect(overlay.index).toBeGreaterThan(inverted.index);
    });

    /*
     * These rules win on SPECIFICITY, not source order: [0,3,0] (band class + .btn +
     * :focus) against the [0,2,1] of `main .btn:focus`. Losing a class from a routed
     * selector would silently hand the ring back to the bare accent. Specificity is
     * computed from the selector AS PARSED OUT OF components.css, never from this file's
     * own constants — otherwise the assertion can only fail when the test is edited.
     */
    test('every routed selector outranks the `main .btn:focus` winner, as parsed from the CSS', () => {
        // [classes+pseudo-classes, type selectors] — enough to compare these shapes.
        const specificity = (sel) => [
            (sel.match(/[.:][a-zA-Z][\w-]*/g) || []).length,
            (sel.match(/(?:^|\s)[a-zA-Z][\w-]*/g) || []).length,
        ];
        const base = blockFor('main .btn:focus');
        expect(base).toBeDefined();
        const [baseCls, baseType] = specificity('main .btn:focus');
        ROUTES.forEach(({ sel }) => {
            const parsed = blockFor(sel).selectors.find(s => s === sel);
            expect(parsed).toBe(sel);
            const [cls, type] = specificity(parsed);
            expect(cls > baseCls || (cls === baseCls && type > baseType)).toBe(true);
        });
    });

    /*
     * LIGHT-BAND BYTE IDENTITY. The three base focus rules must keep their exact
     * declarations — a light band matches none of the routed selectors, so its focus
     * rendering is unchanged if and only if these are untouched.
     */
    const BASE_FOCUS = [
        { sel: '.btn:focus-visible', offset: '3px' },
        { sel: 'main .btn:focus-visible', offset: '4px' },
        { sel: 'main .btn:focus', offset: '4px' },
    ];
    BASE_FOCUS.forEach(({ sel, offset }) => {
        test(`${sel} still declares the unrouted accent ring at ${offset} (light bands unchanged)`, () => {
            const rule = blockFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).toMatch(/outline\s*:\s*2px\s+solid\s+var\(\s*--color-accent\s*\)/);
            expect(rule.body).toMatch(new RegExp('outline-offset\\s*:\\s*' + offset));
        });
    });

    /*
     * CLOSED SET, over the SHORTHAND as well as the longhand. Any rule outside the routed
     * blocks that paints an outline colour is either a band this issue did not measure or a
     * light-band regression — and the shorthand is the sneaky half: `.btn:focus { outline:
     * 2px solid red }` appended anywhere reverts the light-band ring while a longhand-only
     * scan stays green. `:focus-visible` twins of the routed selectors are offenders for the
     * same reason: they tie at [0,3,0], win on source order for exactly the keyboard users
     * this issue is about, and are not one of the three documented base rules.
     */
    const BASE_FOCUS_SELECTORS = BASE_FOCUS.map(b => b.sel);
    const ROUTED_SELECTORS = ROUTES.map(r => r.sel);
    test('no rule outside the routed blocks and the three base rules paints an outline colour', () => {
        const offenders = rules
            .filter(r => /(?:^|[;{\s])outline(-color)?\s*:/.test(r.body))
            .filter(r => !r.selectors.every(s =>
                ROUTED_SELECTORS.includes(s) || BASE_FOCUS_SELECTORS.includes(s)))
            .map(r => r.selectors.join(', '));
        expect(offenders).toEqual([]);
    });

    /*
     * NEGATIVE PIN — section bands stay unrouted (see the describe docblock and the CSS
     * comment). `.section__panel-cta` is the only button a section renders and it sits on
     * the LIGHT `.section__panel`, so routing its ring measured 5.18:1 -> 2.02:1 / 1.04:1.
     * A future edit "completing the set" is a WCAG regression, not a consistency fix.
     */
    test('no section band routes a focus ring (the panel CTA sits on a LIGHT panel)', () => {
        const offenders = rules
            .filter(r => /outline(-color)?\s*:/.test(r.body))
            .filter(r => r.selectors.some(s => /\.(pp-)?section--(inverted|has-bg-image)\b/.test(s)))
            .map(r => r.selectors.join(', '));
        expect(offenders).toEqual([]);
    });

    test('the roles these rules depend on are declared in base.css :root', () => {
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted:\s*#[0-9a-fA-F]{6}/);
        expect(BASE_CSS).toMatch(/--color-accent-on-overlay:\s*#[0-9a-fA-F]{6}/);
    });
});

/**
 * CSS lint: the GLOBAL button hover tier (#539).
 *
 * #458 gave the theme four site-wide button knobs; #530 gave every filled surface a
 * per-instance HOVER fill slot. Between them sat the gap this issue closes: the global tier
 * was resting-state only, so an operator who rethemed every button with --btn-bg /
 * --btn-border-color got their brand at rest and the theme's premium accent gradient back the
 * moment a pointer touched any button on the site.
 *
 * The fix is deliberately NOT "add the tier to the two shared premium hover rules". That
 * would not work. The premium hover rule owns background-IMAGE, but the component hover
 * rules outrank it on background-COLOR:
 *
 *     .hero .btn:not(x):not(y):not(z):hover          [0,6,0]  <- decides background-color
 *     .cta  .btn:not(x):not(y):not(z):hover          [0,6,0]  <- decides background-color
 *     main  .btn:not(x):not(y):not(z):hover          [0,5,1]  <- decides background-image
 *
 * Today the gradient image masks whatever the component rules compute. The instant
 * --btn-hover-bg resolves the premium shorthand to a flat colour, background-image becomes
 * `none` and the component declaration becomes the visible pixel — still resolving to
 * --color-accent-hover if the tier is missing there. So the knob must appear in EVERY hover
 * chain whose resting twin routes the global resting tier, at the SAME relative position.
 * These are exact-value pins: the whole contract is chain ORDER, so a reorder must fail.
 */
describe('CSS lint: global button hover tier (#539)', () => {
    // Comments are stripped first: several of these rules carry long docblocks directly
    // above them, which defeats a "preceded by } or start-of-file" selector match.
    const NO_COMMENTS = COMPONENTS_CSS.replace(/\/\*[\s\S]*?\*\//g, '');
    const bodiesFor = (sel) => {
        const want = sel.replace(/\s+/g, ' ').trim();
        const out = [];
        const re = /([^{}]+)\{([^{}]*)\}/g;
        let m;
        while ((m = re.exec(NO_COMMENTS)) !== null) {
            if (m[1].replace(/\s+/g, ' ').trim() === want) out.push(m[2]);
        }
        return out;
    };
    // Several selectors (notably the premium primary) are declared TWICE — a superseded rule
    // and the "true final cascade" winner. `last` is the live winner in every such pair.
    const bodyFor = (sel) => {
        const all = bodiesFor(sel);
        return all.length ? all[all.length - 1] : null;
    };
    const NOT3 = ':not(.btn--outline):not(.btn--ghost):not(.btn--secondary)';

    // Each entry: the hover rule, and the EXACT chains it must declare. Written out in full
    // rather than assembled, so the pin is readable as the contract itself.
    const CHAINS = [
        {
            what: 'bare .btn (the live hover winner OUTSIDE main — header/footer buttons)',
            sel: '.btn:hover',
            decls: [
                'background-color: var(--btn-hover-bg, var(--color-accent-hover));',
                'border-color: var(--btn-hover-border-color, var(--color-accent-hover));',
            ],
        },
        {
            what: 'hero primary (background-COLOR winner at [0,6,0])',
            sel: '.hero .btn' + NOT3 + ':hover',
            decls: [
                'background-color: var(--hero-button-hover-bg, var(--hero-accent-hover, var(--btn-hover-bg, var(--color-accent-hover))));',
                // #584 put --hero-button-hover-border at the head, matching --cta-button-hover-border's
                // position on the cta row below. The global tier keeps its place BELOW the
                // per-instance ring and the band accent, and ABOVE the border-follows-fill link —
                // the property this table exists to pin.
                'border-color: var(--hero-button-hover-border, var(--hero-accent-hover, var(--btn-hover-border-color, var(--hero-button-hover-bg, var(--btn-hover-bg, var(--color-accent-hover))))));',
            ],
        },
        {
            what: 'cta primary (background-COLOR winner at [0,6,0])',
            sel: '.cta .btn' + NOT3 + ':hover',
            decls: [
                'background-color: var(--cta-button-hover-bg, var(--cta-accent-hover, var(--btn-hover-bg, var(--color-accent-hover))));',
                // #548: --cta-accent-hover ahead of --cta-button-hover-bg. #564: and ahead of
                // --btn-hover-border-color too, so a site-wide ring knob no longer defeats an
                // authored band accent. The global tier still sits BELOW the per-instance ring
                // slot and ABOVE the fill link — the property this table exists to pin — it
                // simply no longer outranks the band accent.
                'border-color: var(--cta-button-hover-border, var(--cta-accent-hover, var(--btn-hover-border-color, var(--cta-button-hover-bg, var(--btn-hover-bg, var(--color-accent-hover))))));',
            ],
        },
        {
            what: 'cta second button (isolated from the primary since #530)',
            sel: '.cta .cta__buttons .cta__button--secondary' + NOT3 + ':hover',
            decls: [
                'background-color: var(--cta-button2-hover-bg, var(--cta-accent-hover, var(--btn-hover-bg, var(--color-accent-hover))));',
            ],
        },
        {
            what: 'hero second button (joined the tier in #554, closing the last filled surface)',
            sel: '.hero .hero__cta-group .hero__cta--secondary' + NOT3 + ':hover',
            decls: [
                'background-color: var(--hero-button2-hover-bg, var(--hero-accent-hover, var(--btn-hover-bg, var(--color-accent-hover))));',
                // --hero-accent-hover leads, matching the hero PRIMARY's hover ring — and since
                // #564 the cta family leads with its accent too, so this row no longer differs
                // from the cta rows above (see the pair-structure test).
                // #538's Option-3 accent-above-fill order survives between the global links.
                'border-color: var(--hero-button2-hover-border, var(--hero-accent-hover, var(--btn-hover-border-color, var(--hero-button2-hover-bg, var(--btn-hover-bg, var(--color-accent-hover))))));',
            ],
        },
        {
            what: 'the shared premium hover rule (background-IMAGE winner; the panel CTA\'s only fill winner)',
            sel: 'main .btn' + NOT3 + ':hover',
            decls: [
                'var(--hero-button-hover-bg, var(--cta-button-hover-bg, var(--btn-hover-bg,',
            ],
        },
    ];

    CHAINS.forEach(({ what, sel, decls }) => {
        test(`${what}: routes the global hover tier below every per-instance slot`, () => {
            const body = bodyFor(sel);
            expect(body).not.toBeNull();
            decls.forEach(d => expect(body.replace(/\s+/g, ' ')).toContain(d.replace(/\s+/g, ' ')));
        });
    });

    /*
     * Cross-property precedence, pinned in the LOSING direction (red-team finding).
     *
     * In every border chain --btn-hover-border-color sits below the per-instance hover BORDER
     * slot and ABOVE the per-instance hover FILL slot. That asymmetry is deliberate — an
     * explicitly authored global ring beats a ring merely inferred from someone's fill, and it
     * mirrors --btn-border-color at rest — but it is exactly the kind of ordering a later
     * "make the globals sit below every per-instance slot" tidy-up would silently invert,
     * which would hand authored fills their ring back and change shipped renders.
     */
    const BORDER_ORDER = [
        { sel: '.cta .btn' + NOT3 + ':hover', fill: '--cta-button-hover-bg', own: '--cta-button-hover-border' },
        { sel: '.cta .cta__buttons .cta__button--secondary' + NOT3 + ':hover', fill: '--cta-button2-hover-bg', own: '--cta-button2-hover-border' },
        // Gained its own hover ring slot in #584, so it joins the "own slot outranks the
        // global knob" half of this pin instead of being the one row that could not assert it.
        { sel: '.hero .btn' + NOT3 + ':hover', fill: '--hero-button-hover-bg',
          own: '--hero-button-hover-border' },
        // Joined the tier in #554, so it joins this precedence pin too.
        { sel: '.hero .hero__cta-group .hero__cta--secondary' + NOT3 + ':hover',
          fill: '--hero-button2-hover-bg', own: '--hero-button2-hover-border' },
    ];

    BORDER_ORDER.forEach(({ sel, fill, own }) => {
        test(`${sel}: the global hover ring outranks the per-instance hover FILL link`, () => {
            const body = bodyFor(sel);
            expect(body).not.toBeNull();
            const chain = body.match(/border-color\s*:([^;]+)/)[1];
            const order = chain.match(/--[a-z0-9-]+/g);
            const iGlobal = order.indexOf('--btn-hover-border-color');
            const iFill = order.indexOf(fill);
            expect(iGlobal).toBeGreaterThan(-1);
            expect(iFill).toBeGreaterThan(-1);
            // Global ring BEFORE the border-follows-fill link.
            expect(iGlobal).toBeLessThan(iFill);
            // ...but AFTER the button's own hover border slot, where it has one.
            if (own) {
                const iOwn = order.indexOf(own);
                expect(iOwn).toBeGreaterThan(-1);
                expect(iOwn).toBeLessThan(iGlobal);
            }
        });
    });

    test('BOTH premium hover rules carry the tier, so the superseded one cannot drift', () => {
        // #514/#530 keep the superseded and the live premium rules uniform on purpose: the
        // superseded one is the shape a reader hits first, so a drifted copy teaches the wrong
        // chain. Two `background:` declarations must route --btn-hover-bg.
        // Matched against the comment-stripped source: these rules carry docblocks that quote
        // chain shapes verbatim, so counting against the raw file would trip on documentation.
        const hits = NO_COMMENTS.match(/background:\s*var\(--hero-button-hover-bg,\s*var\(--cta-button-hover-bg,\s*var\(--btn-hover-bg,/g);
        expect(hits).not.toBeNull();
        expect(hits.length).toBe(2);
    });

    /*
     * The global hover RING reaches the outline variant, and that is load-bearing to pin.
     * `.btn--outline:hover` repaints background-color and color but declares NO border-color,
     * so `.btn:hover`'s border-color is its ring. That mirrors REST exactly (`.btn--outline`
     * also declares no border-color, so --btn-border-color already rings it), which is the
     * whole justification for putting the knob in the shared rule. If someone gives
     * `.btn--outline:hover` its own border-color, or moves it ABOVE `.btn:hover`, the global
     * ring silently stops reaching outline buttons site-wide and no other assertion notices.
     */
    test('the outline variant inherits the global hover ring from .btn:hover', () => {
        const outlineHover = bodyFor('.btn--outline:hover');
        expect(outlineHover).not.toBeNull();
        // It must NOT declare its own border-color, or it would shadow the global ring.
        expect(outlineHover).not.toMatch(/border-color\s*:/);
        // ...and it must FOLLOW `.btn:hover` in source order (equal specificity [0,2,0]).
        const iBase = NO_COMMENTS.indexOf('.btn:hover');
        const iOutline = NO_COMMENTS.indexOf('.btn--outline:hover');
        expect(iBase).toBeGreaterThan(-1);
        expect(iOutline).toBeGreaterThan(iBase);
    });

    test('the global hover fill NEVER enters a border chain in the premium rule', () => {
        // Border is INDEPENDENT of the fill in `main .btn:not(...)` at rest (it routes
        // --btn-border-color but deliberately does not follow --btn-bg, matching the bare .btn
        // primitive). The hover twin must keep that independence, or a fill-only site retheme
        // silently starts moving the premium ring too.
        const body = bodyFor('main .btn' + NOT3 + ':hover');
        expect(body).not.toBeNull();
        const border = body.match(/border-color\s*:([^;]+)/)[1];
        expect(border).toContain('--btn-hover-border-color');
        expect(border).not.toContain('--btn-hover-bg');
    });

    /*
     * REST/HOVER SYMMETRY on the hero's SECOND cta — the pin #539 left behind, now flipped
     * positive by #554.
     *
     * #539 shipped this as a NEGATIVE pin: hero cta2's own chains carried NEITHER global tier,
     * and the pin asserted that absence, because wiring only the HOVER half would have made a
     * site setting both knobs render --color-accent at rest and FLASH to the operator's colour
     * on hover. Symmetry was the invariant; absence was merely how it was satisfied then.
     *
     * #554 satisfies the same invariant from the other side: both halves are now wired. The
     * assertion is therefore inverted, deliberately, and the invariant it protects is
     * unchanged — rest and hover must never disagree about whether the global tier is routed.
     *
     * Why this mattered enough to fix rather than leave: the tier ALREADY reached this button's
     * background-IMAGE through the shared premium rule (a flat --btn-bg resolves the
     * `background` shorthand and clears the gradient) while its own [0,7,0] background-COLOR
     * rule kept painting --color-accent. A rethemed cta2 rendered a FLAT ACCENT pill beside a
     * brand-coloured primary — half-stripped, not merely unthemed. Verified in a browser before
     * and after, both states, both bands.
     */
    test('hero cta2 own chains route the global tier in BOTH states, never one alone', () => {
        const restBody = bodyFor('.hero .hero__cta-group .hero__cta--secondary' + NOT3);
        const hoverBody = bodyFor('.hero .hero__cta-group .hero__cta--secondary' + NOT3 + ':hover');
        expect(restBody).not.toBeNull();
        expect(hoverBody).not.toBeNull();

        // Rest routes both resting knobs (fill chain and ring chain respectively).
        expect(restBody).toContain('--btn-bg');
        expect(restBody).toContain('--btn-border-color');
        // ...and therefore hover routes both hover knobs. Asserting the pair TOGETHER is the
        // point: either one alone is the rest-vs-hover flash #539 refused to ship.
        expect(hoverBody).toContain('--btn-hover-bg');
        expect(hoverBody).toContain('--btn-hover-border-color');
    });

    /*
     * PAIR STRUCTURE (#554) — the hero's two filled buttons resolve their fill and ring through
     * chains of the same SHAPE, and so do the cta's two.
     *
     * This is the property the issue actually established. The original defect was not "a token
     * is missing" but "the two buttons of one band answer to different sources", which is
     * invisible to any single-chain assertion: every chain was individually well-formed.
     *
     * Same shape is not same colour. Each button still reads its OWN per-instance slots, so the
     * pair matches wherever the winning link is a shared knob (--hero-accent, --btn-bg) and
     * differs wherever it is a per-button one (--hero-button2-bg). What must never return is the
     * pair disagreeing about WHICH KINDS of link participate at all.
     */
    const PAIRS = [
        {
            what: 'hero, rest fill',
            primary: '.hero .btn' + NOT3,
            second: '.hero .hero__cta-group .hero__cta--secondary' + NOT3,
            prop: 'background-color',
            shared: ['--btn-bg'],
        },
        {
            what: 'hero, rest ring',
            primary: '.hero .btn' + NOT3,
            second: '.hero .hero__cta-group .hero__cta--secondary' + NOT3,
            prop: 'border-color',
            shared: ['--hero-accent', '--btn-border-color', '--btn-bg'],
        },
        {
            what: 'hero, hover fill',
            primary: '.hero .btn' + NOT3 + ':hover',
            second: '.hero .hero__cta-group .hero__cta--secondary' + NOT3 + ':hover',
            prop: 'background-color',
            shared: ['--btn-hover-bg'],
        },
        {
            what: 'hero, hover ring',
            primary: '.hero .btn' + NOT3 + ':hover',
            second: '.hero .hero__cta-group .hero__cta--secondary' + NOT3 + ':hover',
            prop: 'border-color',
            shared: ['--hero-accent-hover', '--btn-hover-border-color', '--btn-hover-bg'],
        },
        // The cta pair is asserted too, not assumed. Each cta rule is pinned individually
        // elsewhere, but nothing compared the two to each other — so a COORDINATED reorder of
        // both (exactly the "make it consistent" cleanup this block exists to stop) passed.
        {
            what: 'cta, rest fill',
            primary: '.cta .btn' + NOT3,
            second: '.cta .cta__buttons .cta__button--secondary' + NOT3,
            prop: 'background-color',
            shared: ['--btn-bg'],
        },
        {
            what: 'cta, rest ring',
            primary: '.cta .btn' + NOT3,
            second: '.cta .cta__buttons .cta__button--secondary' + NOT3,
            prop: 'border-color',
            // #564: --cta-accent moved above --btn-border-color, matching the hero rows above.
            shared: ['--cta-accent', '--btn-border-color', '--btn-bg'],
        },
        {
            what: 'cta, hover fill',
            primary: '.cta .btn' + NOT3 + ':hover',
            second: '.cta .cta__buttons .cta__button--secondary' + NOT3 + ':hover',
            prop: 'background-color',
            shared: ['--btn-hover-bg'],
        },
        {
            what: 'cta, hover ring',
            primary: '.cta .btn' + NOT3 + ':hover',
            second: '.cta .cta__buttons .cta__button--secondary' + NOT3 + ':hover',
            prop: 'border-color',
            // #564: --cta-accent-hover moved above --btn-hover-border-color, matching the hero.
            shared: ['--cta-accent-hover', '--btn-hover-border-color', '--btn-hover-bg'],
        },
    ];

    /*
     * Resolved through the media-aware parseRules(), NOT the flat bodyFor() the rest of this
     * describe uses. bodyFor returns the LAST textual match and cannot see @media, so a
     * regression that strips the tier from the top-level rule and re-adds it inside a
     * never-matching @media reads as green — verified by mutation. Uniqueness and top-level
     * placement are asserted here rather than assumed, the #542 idiom.
     */
    const pairRules = parseRules();
    const uniqueTopLevelRule = (sel) => {
        const found = pairRules.filter(r => r.selectors.includes(sel));
        expect(found.length, `${sel} must be declared exactly once`).toBe(1);
        expect(found[0].media, `${sel} must be top level, not inside @media`).toBeNull();
        return found[0].body;
    };
    const chainTokens = (body, prop, map = {}) => {
        const m = body.match(new RegExp(prop + '\\s*:([^;}]+)'));
        expect(m, `${prop} not declared`).not.toBeNull();
        return m[1].match(/--[a-z0-9-]+/g).map(t => map[t] || t);
    };

    PAIRS.forEach(({ what, primary, second, prop, shared }) => {
        test(`${what}: the second button routes every shared link its primary does`, () => {
            const pChain = chainTokens(uniqueTopLevelRule(primary), prop);
            const sChain = chainTokens(uniqueTopLevelRule(second), prop);
            shared.forEach(tok => {
                expect(pChain, `primary lost ${tok}`).toContain(tok);
                expect(sChain, `second button lost ${tok} — the #554 split`).toContain(tok);
            });
            // Relative ORDER of the shared links must agree too. A site setting two of them at
            // once is exactly where a reordered chain splits the pair again. Until #564 the two
            // families disagreed about accent-vs-global-knob (the hero put its accent first, the
            // cta family put the global ring knob first) and each pair was only internally
            // consistent; #564 moved the cta onto the hero's order, so the shared links now
            // agree ACROSS the components as well as within each pair.
            const idx = (chain) => shared.map(t => chain.indexOf(t));
            const pOrder = idx(pChain), sOrder = idx(sChain);
            const rank = (a) => a.map((_, i) => i).sort((x, y) => a[x] - a[y]).join(',');
            expect(rank(sOrder), `${what}: shared links rank differently on the two buttons`)
                .toBe(rank(pOrder));
        });
    });

    /*
     * The cta component is the stated parity model (#554): after this change hero cta2 and cta
     * button2 must carry structurally EQUIVALENT chains — the same kinds of link, in the same
     * roles. They are not token-identical (each reads its own component's slots), so the
     * comparison is made on the chain's SHAPE with the component-specific names normalised.
     */
    test('hero cta2 and cta button2 carry structurally equivalent chains', () => {
        const norm = chainTokens;
        const heroMap = {
            '--hero-button2-border': 'OWN-BORDER', '--hero-button2-bg': 'OWN-FILL',
            '--hero-accent': 'ACCENT',
            '--hero-button2-hover-border': 'OWN-HOVER-BORDER', '--hero-button2-hover-bg': 'OWN-HOVER-FILL',
            '--hero-accent-hover': 'ACCENT-HOVER',
        };
        const ctaMap = {
            '--cta-button2-border': 'OWN-BORDER', '--cta-button2-bg': 'OWN-FILL',
            '--cta-accent': 'ACCENT',
            '--cta-button2-hover-border': 'OWN-HOVER-BORDER', '--cta-button2-hover-bg': 'OWN-HOVER-FILL',
            '--cta-accent-hover': 'ACCENT-HOVER',
        };
        const heroRest = uniqueTopLevelRule('.hero .hero__cta-group .hero__cta--secondary' + NOT3);
        const ctaRest = uniqueTopLevelRule('.cta .cta__buttons .cta__button--secondary' + NOT3);
        const heroHover = uniqueTopLevelRule('.hero .hero__cta-group .hero__cta--secondary' + NOT3 + ':hover');
        const ctaHover = uniqueTopLevelRule('.cta .cta__buttons .cta__button--secondary' + NOT3 + ':hover');

        // Fill chains are identical in shape, both states.
        expect(norm(heroRest, 'background-color', heroMap))
            .toEqual(norm(ctaRest, 'background-color', ctaMap));
        expect(norm(heroHover, 'background-color', heroMap))
            .toEqual(norm(ctaHover, 'background-color', ctaMap));

        // Ring chains carry the same SET of links, and since #564 the same ORDER as well. The
        // divergence this pin used to document — the hero hoisting its accent above the global
        // ring knob while the cta family ranked the knob first — was the mechanism behind the
        // reported defect: on cta bands a site-wide --btn-hover-border-color defeated an
        // authored --cta-accent-hover, and on overlay bands it defeated the measured 4.59:1
        // separation role. #564 moved the cta family onto the hero's order, so the two families
        // now converge instead of each being merely self-consistent.
        const setOf = (a) => [...a].sort().join(',');
        expect(setOf(norm(heroRest, 'border-color', heroMap)))
            .toBe(setOf(norm(ctaRest, 'border-color', ctaMap)));
        expect(setOf(norm(heroHover, 'border-color', heroMap)))
            .toBe(setOf(norm(ctaHover, 'border-color', ctaMap)));

        const REST_SHAPE = ['OWN-BORDER', 'ACCENT', '--btn-border-color', 'OWN-FILL', '--btn-bg', '--color-accent'];
        expect(norm(heroRest, 'border-color', heroMap)).toEqual(REST_SHAPE);
        expect(norm(ctaRest, 'border-color', ctaMap)).toEqual(REST_SHAPE);

        // The hover shape is the rest shape with every knob swapped for its hover twin, on
        // BOTH components — the positional-twin property #564 established. It is what makes a
        // rest->hover ring flip impossible in any authoring configuration, on either family.
        const HOVER_SHAPE = ['OWN-HOVER-BORDER', 'ACCENT-HOVER', '--btn-hover-border-color',
            'OWN-HOVER-FILL', '--btn-hover-bg', '--color-accent-hover'];
        expect(norm(heroHover, 'border-color', heroMap)).toEqual(HOVER_SHAPE);
        expect(norm(ctaHover, 'border-color', ctaMap)).toEqual(HOVER_SHAPE);
    });
});

/**
 * CSS lint: band-wide link ink must not reach the LIGHT panel CTA (#551).
 *
 * `.section--has-bg-image a` and `.pp-section--inverted a` are ON-BAND roles — they exist
 * because the band is a dark surface. But the selector is band-WIDE, and `.section__panel`
 * is a self-contained LIGHT surface (--color-surface, #f4f7fb) sitting on that dark band.
 * The section renderer emits exactly ONE anchor inside it (section.php:270,
 * `.section__panel-cta`), so the band role painted a transparent panel button's label onto
 * the light panel:
 *
 *     .btn--outline / .btn--ghost / .btn--secondary      [0,1,0]
 *     .section--has-bg-image a                           [0,1,1]  <- outranked them
 *     .section--has-bg-image a:not(.section__panel-cta)  [0,2,1]  <- after the carve-out
 *
 * Measured on the light panel (rendered, headless Chromium, worst-case white bg image):
 *   bg-image  outline/ghost/secondary  rest 1.04:1  |  hover ghost 1.07, secondary 1.33
 *   inverted  outline/ghost/secondary  rest 1.99:1  |  hover ghost 1.46, secondary 1.18
 * After the carve-out every band matches the default-band control (5.14 / 16.52 at rest).
 *
 * Same carve-out class as #424 (panel heading out of the band h2/h3 rules), #463 (panel
 * markers stay bare accent by scoping the remap to .section__content) and #542 (section
 * bands deliberately not routed for the focus ring, because that ring lands on the panel).
 *
 * The DURABILITY pin is the closed-set scan at the bottom: it fails on any FUTURE band-WIDE
 * anchor rule that sets `color` without the carve-out. That scan — not the selector shape —
 * is what makes this survive the next band rule someone adds.
 */
describe('CSS lint: band link ink is carved out of the light panel CTA (#551)', () => {
    const rules = parseRules();
    const blocksFor = (sel) => rules.filter(r => r.selectors.includes(sel));
    const blockFor = (sel) => blocksFor(sel)[0];
    const esc = (s) => s.replace(/[-]/g, '\\-');

    const CARVE = ':not(.section__panel-cta)';

    // Every band-WIDE anchor ink rule, with the slot + role it must keep routing.
    const BAND_LINK_RULES = [
        { sel: `.section--has-bg-image a${CARVE}`, slot: '--section-body-link-color', role: '--color-accent-on-overlay' },
        { sel: `.section--has-bg-image a${CARVE}:hover`, slot: '--section-body-link-hover-color', role: '--color-accent-on-overlay-hover' },
        { sel: `.pp-section--inverted a${CARVE}`, slot: '--section-body-link-color', role: '--color-accent-on-inverted' },
        { sel: `.pp-section--inverted a${CARVE}:hover`, slot: '--section-body-link-hover-color', role: '--color-accent-on-inverted-hover' },
    ];

    BAND_LINK_RULES.forEach(({ sel, slot, role }) => {
        test(`${sel} carries the panel-CTA carve-out`, () => {
            const rule = blockFor(sel);
            expect(rule, `missing rule: ${sel}`).toBeDefined();
        });

        // The carve-out must NARROW the reach without changing the on-band routing —
        // that is the whole "byte-identical where the rule still reaches" claim.
        test(`${sel} still routes through ${slot} then ${role}`, () => {
            const rule = blockFor(sel);
            expect(rule).toBeDefined();
            expect(rule.body).toMatch(new RegExp(
                'color\\s*:\\s*var\\(\\s*' + esc(slot) + '\\s*,\\s*var\\(\\s*' + esc(role) + '\\s*\\)\\s*\\)'
            ));
        });

        test(`${sel} is declared exactly once (a later duplicate would silently win)`, () => {
            expect(blocksFor(sel).length).toBe(1);
        });

        // A rule moved into a media block applies only at that width; every other pin
        // here still reads green because the rule text is unchanged.
        test(`${sel} is declared at the top level, not inside a media block`, () => {
            expect(blockFor(sel).media).toBeNull();
        });
    });

    // The UNCARVED forms must be gone. Without this, someone re-adding the bare rule
    // later (which would win on source order at lower specificity for non-CTA anchors,
    // and re-break the CTA) passes every pin above.
    ['.section--has-bg-image a', '.pp-section--inverted a',
     '.section--has-bg-image a:hover', '.pp-section--inverted a:hover'].forEach(bare => {
        test(`the uncarved \`${bare}\` rule no longer exists`, () => {
            expect(blocksFor(bare)).toEqual([]);
        });
    });

    /*
     * CLOSED-SET DURABILITY PIN — the point of this describe.
     *
     * A band-WIDE anchor selector is one whose LAST compound is a bare `a` sitting
     * DIRECTLY under a band class, with no intervening container compound. Those are
     * exactly the selectors that can reach inside `.section__panel`. A rule already
     * scoped to a band-only container (`.section--has-bg-image .section__content a`)
     * is NOT band-wide and correctly needs no carve-out — so this scan does not
     * overfire on legitimate scoped rules.
     *
     * `:is()` / `:where()` / `:matches()` wrapping an `a` counts too: `:where(a)` has
     * the same reach as a bare `a` and would otherwise slip past a naive check.
     */
    // Properties that paint anchor INK. -webkit-text-fill-color wins over `color` on
    // Blink/WebKit, so a rule using it would defeat the carve-out on the majority engine.
    const INK_PROP = /(?<![-a-z])(?:color|-webkit-text-fill-color)\s*:/i;

    // A band class appearing ANYWHERE in a compound (`.pp-section.pp-section--inverted`
    // and `main .pp-section--inverted` both carry the band).
    const BAND_IN_COMPOUND = /\.(pp-section--inverted|section--has-bg-image)(?![\w-])/;

    // Compounds that are band-ONLY containers. `.section__content` / `.section__body` /
    // `.section__header` and friends live in the text column, a SIBLING of
    // `.section__panel` (section.php:231-236 vs 237), so an anchor scoped under one of
    // them cannot reach the panel CTA and correctly needs no carve-out.
    //
    // This list is deliberately an ALLOWLIST, so the scan fails CLOSED: an unknown
    // intervening compound (`.container`, `.section__grid`, `.section__panel` itself)
    // counts as panel-reaching and gets flagged. Adding to this list is a deliberate act.
    const BAND_ONLY_CONTAINERS = [
        '.section__content', '.section__body', '.section__header',
        '.section__inline-items', '.section__title', '.section__subheading',
        '.section__eyebrow',
    ];

    // `:is(.a, .b) a` has exactly the reach of `.a a` and `.b a`, and de-duping the two
    // band rules into one `:is()` is the single most likely future edit to these lines.
    // Expand one level of :is()/:where()/:matches() groups into concrete selectors.
    const expandGroups = (sel) => {
        const m = sel.match(/:(?:is|where|matches)\(([^()]*)\)/);
        if (!m) return [sel];
        return m[1].split(',').flatMap(alt =>
            expandGroups(sel.slice(0, m.index) + alt.trim() + sel.slice(m.index + m[0].length))
        );
    };

    // Split a selector into descendant compounds, treating >, + and ~ as separators
    // (a child combinator does not make a rule safe).
    const compounds = (sel) => sel.replace(/\s*[>+~]\s*/g, ' ').trim().split(/\s+/).filter(Boolean);

    // Does this compound match an <a>? A bare `a` type selector, with or without pseudos.
    // `:not(...)` contents are stripped first so `a:not(.x)` still reads as an anchor.
    const isAnchorCompound = (c) => /(?:^|[^\w.#-])a(?![\w-])/.test(' ' + c.replace(/:not\([^)]*\)/g, ''));

    // The carve-out, matched as a precise token — a substring test would accept
    // `:not(.section__panel-cta-x)`, a class that does not exist.
    const CARVED = /:not\(\s*\.section__panel-cta\s*\)/;

    /**
     * Every selector that can paint ink on `.section__panel-cta` without the carve-out.
     * Scans components.css AND base.css AND utilities.css — a band anchor rule added to
     * any sheet reaches the same element.
     */
    // parseRules splits a grouped selector on every comma, which also splits INSIDE
    // `:is(a, b)`. Rejoin fragments until their parentheses balance, so a functional
    // pseudo-class group survives as one selector.
    const rejoinGroups = (selectors) => {
        const out = [];
        let buf = '';
        selectors.forEach(part => {
            buf = buf ? `${buf}, ${part}` : part;
            const open = (buf.match(/\(/g) || []).length;
            const close = (buf.match(/\)/g) || []).length;
            if (open === close) {
                out.push(buf);
                buf = '';
            }
        });
        if (buf) out.push(buf);
        return out;
    };

    const uncarvedPanelReachingInkRules = (sheets) => {
        const offenders = [];
        sheets.forEach(css => {
            parseRules(stripComments(css)).forEach(r => {
                if (!INK_PROP.test(r.body)) return;
                rejoinGroups(r.selectors).forEach(rawSel => {
                    expandGroups(rawSel).forEach(sel => {
                        const parts = compounds(sel);
                        const bandAt = parts.findIndex(p => BAND_IN_COMPOUND.test(p));
                        if (bandAt === -1) return;                 // not a band rule
                        const last = parts[parts.length - 1];
                        if (bandAt === parts.length - 1) return;   // band compound IS the target
                        if (!isAnchorCompound(last)) return;       // not anchor ink
                        if (CARVED.test(last)) return;             // carved out — fine
                        // Scoped under a container that cannot contain the panel?
                        const between = parts.slice(bandAt + 1, parts.length - 1);
                        if (between.some(p => BAND_ONLY_CONTAINERS.some(c => p.startsWith(c)))) return;
                        offenders.push(rawSel);
                    });
                });
            });
        });
        return [...new Set(offenders)];
    };

    test('no band rule paints uncarved ink on an anchor that can reach the panel CTA', () => {
        expect(uncarvedPanelReachingInkRules([COMPONENTS_CSS, BASE_CSS, UTILITIES_CSS])).toEqual([]);
    });

    /*
     * The detector is the durability claim, so it gets its own tests — and they call the
     * REAL detector, never a copy. (A re-implemented copy silently drifts from the thing
     * it claims to prove, and both then read green forever.)
     *
     * Every DANGEROUS case below reaches `.section__panel-cta` and paints ink on it.
     */
    const DANGEROUS = [
        // The literal bug this issue fixes.
        { sel: '.section--has-bg-image a', prop: 'color' },
        { sel: '.pp-section--inverted a:hover', prop: 'color' },
        // De-duping the two band rules into one :is() — the most plausible future edit.
        { sel: ':is(.section--has-bg-image, .pp-section--inverted) a', prop: 'color' },
        { sel: ':where(.pp-section--inverted) a', prop: 'color' },
        // The panel itself, and any container that CONTAINS the panel.
        { sel: '.section--has-bg-image .section__panel a', prop: 'color' },
        { sel: '.section--has-bg-image > .container a', prop: 'color' },
        { sel: '.section--has-bg-image .section__grid a', prop: 'color' },
        // Band class in a compound, or with an ancestor prefix.
        { sel: '.pp-section.pp-section--inverted a', prop: 'color' },
        { sel: 'main .pp-section--inverted a', prop: 'color' },
        // Anchor inside a group.
        { sel: '.pp-section--inverted :is(a, button)', prop: 'color' },
        // A carve-out that names a class which does not exist.
        { sel: '.section--has-bg-image a:not(.section__panel-cta-x)', prop: 'color' },
        // The Blink/WebKit ink property, which wins over `color` on the majority engine.
        { sel: '.section--has-bg-image a', prop: '-webkit-text-fill-color' },
    ];

    // Every SAFE case either cannot reach the panel CTA or is correctly carved out.
    const SAFE = [
        '.section--has-bg-image a:not(.section__panel-cta)',
        '.pp-section--inverted a:not(.section__panel-cta):hover',
        '.section--has-bg-image .section__content a',   // text column, sibling of the panel
        '.section--has-bg-image .section__body a:hover',
        '.section--has-bg-image .section__title-accent', // not an anchor
        '.cta--has-bg-image .cta__body a',               // different component entirely
    ];

    DANGEROUS.forEach(({ sel, prop }) => {
        test(`detector FLAGS a panel-reaching band ink rule: \`${sel}\` via ${prop}`, () => {
            expect(uncarvedPanelReachingInkRules([`${sel} { ${prop}: red; }`])).toEqual([sel]);
        });
    });

    SAFE.forEach(sel => {
        test(`detector PASSES a rule that cannot reach the panel CTA: \`${sel}\``, () => {
            expect(uncarvedPanelReachingInkRules([`${sel} { color: red; }`])).toEqual([]);
        });
    });

    // Anti-vacuity: the real detector must be looking at real rules. Strip the carve-out
    // from the shipped stylesheet and the scan must light up on all four.
    test('the scan is not vacuous — removing the carve-out flags all four shipped rules', () => {
        // Mutate ONLY the four selectors under test, not every carve-out in the sheet:
        // a global replace could stay green off some unrelated rule's carve-out.
        const reverted = BAND_LINK_RULES.reduce(
            (css, { sel }) => css.split(sel).join(sel.replace(CARVE, '')),
            COMPONENTS_CSS
        );
        expect(uncarvedPanelReachingInkRules([reverted]).sort()).toEqual([
            '.pp-section--inverted a',
            '.pp-section--inverted a:hover',
            '.section--has-bg-image a',
            '.section--has-bg-image a:hover',
        ]);
    });

    test('the roles these rules depend on are declared in base.css :root', () => {
        expect(BASE_CSS).toMatch(/--color-accent-on-overlay:\s*#[0-9a-fA-F]{6}/);
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted:\s*#[0-9a-fA-F]{6}/);
    });
});


/**
 * Per-instance button slots never reach an author-written nested `.btn` (#545).
 *
 * The per-instance filled-button slot families are emitted on the COMPONENT ROOT and consumed
 * by selector shapes that match ANY composed button — `main .btn:not(...)`, `.hero .btn:not(...)`
 * and `.cta .btn:not(...)`. Custom properties inherit, so before #545 those slots repainted a
 * `.btn` an author hand-writes into a wp_kses_post rich-text prop (`section.body`, `hero.proof`).
 * The fix neutralises them (`--slot: initial`, the guaranteed-invalid value) on every composed
 * button that is not a renderer-owned button element.
 *
 * The load-bearing, otherwise-invisible half is COMPLETENESS, and this pin derives it
 * STRUCTURALLY rather than from a name pattern:
 *
 *   leak-capable  =  { schema-declared style_slot }  ∩  { slot read by a rule whose selector
 *                     mentions .btn and does NOT require an owned button class }
 *
 * Every leak-capable slot must then be either NEUTRALISED by the rule or on the short,
 * documented list of band-level slots we deliberately let reach a nested button. A new slot in
 * a new family is caught by construction — a prefix regex would not have caught it, which was
 * the whole justification for preferring this mechanism over per-slot private twins.
 */
describe('CSS lint: per-instance button slots are neutralised on non-owned buttons (#545)', () => {
    const fsq = require('fs');
    const pathq = require('path');

    // The renderer-owned button elements: hero.php:126,130 (.hero__cta, incl. the cta2
    // modifier), cta.php:112,116 (.cta__button, incl. the button2 modifier),
    // section.php:270 (.section__panel-cta).
    const OWNED_BUTTON_CLASSES = ['.hero__cta', '.cta__button', '.section__panel-cta'];

    /**
     * Band-level slots that are leak-capable AND deliberately NOT neutralised. They colour every
     * accented element in the band by design, and a nested button is one of those elements.
     * Adding to this list is a conscious product call, which is exactly the point: a NEW BUTTON
     * slot cannot be added here by reflex without someone reading this comment.
     */
    const INTENTIONALLY_REACHING = [
        '--cta-accent',        // band accent: fills/rings every accented element in the cta
        '--cta-accent-hover',
        '--hero-accent',       // same, on the hero band
        '--hero-accent-hover',
        '--hero-heading-color',         // band ink: the outline variant's foreground
        '--hero-bg',           // the outline variant's hover ink, per hero/schema.json
    ].sort();

    const STRIPPED = stripComments(COMPONENTS_CSS);

    /** Every style slot every component schema declares, mapped to its component. */
    function schemaSlots() {
        const out = {};
        const dir = path.resolve(__dirname, '../../components');
        fsq.readdirSync(dir).forEach((name) => {
            const file = pathq.join(dir, name, 'schema.json');
            if (!fsq.existsSync(file)) return;
            const schema = JSON.parse(fsq.readFileSync(file, 'utf-8'));
            const slots = (schema.styling && schema.styling.style_slots) || {};
            Object.keys(slots).forEach((slot) => {
                out[slot] = name;
            });
        });
        return out;
    }

    /**
     * Slots a NON-OWNED composed button can inherit: read inside a rule whose selector mentions
     * `.btn` without requiring one of the owned classes. Takes css + slot map as arguments so
     * the detection proofs below can run it against a mutated stylesheet/schema pair.
     */
    function leakCapableSlots(css, slots) {
        const found = new Set();
        const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
        let m;
        while ((m = ruleRe.exec(css)) !== null) {
            // Evaluate each ARM of a selector list separately. Testing the whole string would
            // skip `.section .btn, .hero__cta { … }` entirely because one arm names an owned
            // class, silently blessing the leaking arm.
            const leaks = m[1]
                .split(',')
                .map((arm) => arm.trim())
                .some((arm) =>
                    arm.includes('.btn') && !OWNED_BUTTON_CLASSES.some((cls) => arm.includes(cls)),
                );
            if (!leaks) continue;
            const varRe = /var\(\s*(--[a-z0-9-]+)/g;
            let v;
            while ((v = varRe.exec(m[2])) !== null) {
                if (Object.prototype.hasOwnProperty.call(slots, v[1])) found.add(v[1]);
            }
        }
        return [...found].sort();
    }

    /** The neutralisation rule's selector and the properties it sets to `initial`. */
    function neutralisationRule(css) {
        const blocks = css.match(/main\s+\.btn(?::not\(\.[a-z0-9_-]+\))+\s*\{[^}]*\}/gi);
        if (!blocks) return null;
        const hit = blocks.find((block) => {
            const body = block.slice(block.indexOf('{') + 1, -1).trim();
            return (
                body.length > 0 &&
                body
                    .split(';')
                    .map((d) => d.trim())
                    .filter(Boolean)
                    .every((d) => /^--[a-z0-9-]+:\s*initial$/.test(d))
            );
        });
        if (!hit) return null;
        return {
            selector: hit.slice(0, hit.indexOf('{')).trim(),
            props: [...hit.matchAll(/(--[a-z0-9-]+):\s*initial/g)].map((x) => x[1]).sort(),
        };
    }

    test('the neutralisation rule exists and excludes exactly the owned button classes', () => {
        const rule = neutralisationRule(STRIPPED);
        expect(rule, 'no `main .btn:not(...) { --slot: initial }` rule found').not.toBeNull();

        const excluded = [...rule.selector.matchAll(/:not\((\.[a-z0-9_-]+)\)/g)]
            .map((m) => m[1])
            .sort();
        expect(excluded).toEqual([...OWNED_BUTTON_CLASSES].sort());
    });

    test('the derivation finds the shipped families (the pin is not vacuous)', () => {
        const leaky = leakCapableSlots(STRIPPED, schemaSlots());
        ['--hero-button-bg', '--hero-button-hover-bg', '--cta-button-bg', '--cta-button-hover-bg',
            '--section-panel-cta-bg'].forEach((slot) => {
            expect(leaky, `${slot} must be visible to the derivation`).toContain(slot);
        });
    });

    test('every leak-capable schema slot is neutralised, or deliberately left reaching', () => {
        const rule = neutralisationRule(STRIPPED);
        const stillReaching = leakCapableSlots(STRIPPED, schemaSlots())
            .filter((slot) => !rule.props.includes(slot));

        expect(
            stillReaching,
            'these slots still inherit onto an author-written nested .btn — neutralise them, or '
            + 'add them to INTENTIONALLY_REACHING with a reason',
        ).toEqual(INTENTIONALLY_REACHING);
    });

    test('it neutralises nothing outside the per-instance button families', () => {
        const rule = neutralisationRule(STRIPPED);
        const strays = rule.props.filter(
            (p) => !/^--(?:hero-button|hero-button2|cta-button|cta-button2|section-panel-cta)-/.test(p),
        );
        expect(strays, 'the global --btn-* tier and band accents must keep reaching nested buttons')
            .toEqual([]);
    });

    test('the global button tier is deliberately NOT neutralised', () => {
        const rule = neutralisationRule(STRIPPED);
        ['--btn-bg', '--btn-text', '--btn-border-color', '--btn-shadow', '--btn-hover-bg',
            '--btn-hover-border-color'].forEach((token) => {
            expect(rule.props).not.toContain(token);
        });
    });

    test('detection proof: a slot in a BRAND-NEW family left out of the rule is caught', () => {
        // The case a name-prefix guard would miss entirely: a future component ships its own
        // button slot under a prefix nobody has seen, and wires it into a leak-capable chain.
        const rule = neutralisationRule(STRIPPED);
        // Global replace: the FIRST occurrence lives in the owned `.section__panel-cta` rule,
        // which the derivation correctly skips, so a first-match mutation would prove nothing.
        const mutatedCss = STRIPPED.split('var(--section-panel-cta-bg')
            .join('var(--faq-answer-cta-bg, var(--section-panel-cta-bg');
        const mutatedSlots = Object.assign(schemaSlots(), { '--faq-answer-cta-bg': 'faq' });
        const stillReaching = leakCapableSlots(mutatedCss, mutatedSlots)
            .filter((slot) => !rule.props.includes(slot));
        expect(stillReaching).toContain('--faq-answer-cta-bg');
    });

    test('detection proof: a leaking arm of a selector LIST is not blessed by a sibling arm', () => {
        // The grouped-selector blind spot: if the scan tested the whole selector string, one arm
        // naming an owned class would hide the leaking arm next to it.
        const rule = neutralisationRule(STRIPPED);
        const mutatedCss = STRIPPED
            + '\n.section__panel-cta, .section__content .btn { color: var(--faq-answer-cta-color); }';
        const mutatedSlots = Object.assign(schemaSlots(), { '--faq-answer-cta-color': 'faq' });
        const stillReaching = leakCapableSlots(mutatedCss, mutatedSlots)
            .filter((slot) => !rule.props.includes(slot));
        expect(stillReaching).toContain('--faq-answer-cta-color');
    });

    test('detection proof: dropping an exclusion is caught', () => {
        const rule = neutralisationRule(STRIPPED);
        const broken = neutralisationRule(
            STRIPPED.replace(rule.selector, 'main .btn:not(.hero__cta):not(.cta__button)'),
        );
        const excluded = [...broken.selector.matchAll(/:not\((\.[a-z0-9_-]+)\)/g)]
            .map((m) => m[1])
            .sort();
        // Re-run the PRODUCTION assertion against the mutation, not a restatement of it: the
        // shipped test must be what goes red, or this proves only that a string lost a substring.
        expect(() => expect(excluded).toEqual([...OWNED_BUTTON_CLASSES].sort())).toThrow();
    });

    test('no per-instance button slot is read outside components.css', () => {
        // The derivation scans components.css. A family slot consumed from base.css or
        // utilities.css would satisfy it while still leaking, so pin that it cannot happen.
        const FAMILY = /var\(\s*--(?:hero-button|hero-button2|cta-button|cta-button2|section-panel-cta)-/;
        expect(FAMILY.test(stripComments(BASE_CSS))).toBe(false);
        expect(FAMILY.test(stripComments(UTILITIES_CSS))).toBe(false);
    });
});

/**
 * CSS lint: the per-instance RING slots for the hero primary and the section panel CTA (#584).
 *
 * Two filled surfaces reached their ring only through a knob that also moves something else:
 * the hero primary through the BAND accent (--hero-accent repaints every accented element in
 * the band) or the site-wide --btn-border-color; the panel CTA through --btn-border-color
 * alone, because it is the one filled surface that had NEITHER of the two tiers its siblings
 * carry above the global knob. #584 gives each its own ring slot at the HEAD of the chain,
 * in exactly the position --cta-button-border holds on the cta primary, plus the hover twin
 * (P6: a control added without a state twin is a future flip bug).
 *
 * Three properties are pinned, and each is a different failure this file has actually seen:
 *
 *   1. HEAD POSITION. A slot anywhere below the band accent or the global knob is a slot the
 *      site-wide retheme defeats — the #564 defect, one tier down.
 *   2. POSITIONAL TWIN. The hover chain is the rest chain with every knob swapped for its
 *      hover equivalent. A rest-only ring dissolves under the pointer (#535).
 *   3. THE PANEL CTA IS ROUTED IN BOTH PLACES. Its section-block keystone is [0,4,0] and the
 *      shared premium winner is [0,4,1] (hover: [0,5,0] vs [0,5,1]), so the keystone NEVER
 *      decides its border. Route only there and the slot is dead; route only in the premium
 *      block and StyleSlotContractTest's in-block consumption contract breaks. #536 shipped
 *      --section-panel-cta-bg exactly this way — this pin holds the ring to the same shape,
 *      because "tidy up the duplicate" is precisely the edit that would silently kill it.
 *
 * Media-aware (parseRules), so a ring wrapped in a never-matching @media reads as red, and
 * uniqueness is asserted rather than assumed (the #542 idiom).
 */
describe('CSS lint: per-instance ring slots for the hero primary and panel CTA (#584)', () => {
    const ringRules = parseRules();
    const NOT3 = ':not(.btn--outline):not(.btn--ghost):not(.btn--secondary)';

    const uniqueTopLevel = (sel) => {
        const found = ringRules.filter((r) => r.selectors.includes(sel));
        expect(found.length, `${sel} must be declared exactly once`).toBe(1);
        expect(found[0].media, `${sel} must be top level, not inside @media`).toBeNull();
        return found[0].body;
    };
    // The premium primary is declared TWICE (a superseded rule and the "true final cascade"
    // winner). Source order breaks the specificity tie, so the LAST one is the live winner and
    // the only one this pin may read.
    const lastTopLevel = (sel) => {
        const found = ringRules.filter((r) => r.selectors.includes(sel) && r.media === null);
        expect(found.length, `${sel} must exist at top level`).toBeGreaterThan(0);
        return found[found.length - 1].body;
    };
    const borderChain = (body) => {
        const m = body.match(/border-color\s*:([^;}]+)/);
        expect(m, 'border-color not declared').not.toBeNull();
        return m[1].match(/--[a-z0-9-]+/g);
    };

    // Each row: where the ring is decided, the slot that must LEAD it, and the full chain.
    const RINGS = [
        {
            what: 'hero primary, rest (its own [0,5,0] rule outranks the premium winner)',
            body: () => uniqueTopLevel('.hero .btn' + NOT3),
            slot: '--hero-button-border',
            chain: ['--hero-button-border', '--hero-accent', '--btn-border-color',
                '--hero-button-bg', '--btn-bg', '--color-accent'],
        },
        {
            what: 'hero primary, hover',
            body: () => uniqueTopLevel('.hero .btn' + NOT3 + ':hover'),
            slot: '--hero-button-hover-border',
            chain: ['--hero-button-hover-border', '--hero-accent-hover', '--btn-hover-border-color',
                '--hero-button-hover-bg', '--btn-hover-bg', '--color-accent-hover'],
        },
        {
            what: 'panel CTA, rest — the LIVE winner, in the shared premium block',
            body: () => lastTopLevel('main .btn' + NOT3),
            slot: '--section-panel-cta-border',
            chain: ['--section-panel-cta-border', '--cta-button-border', '--cta-accent',
                '--btn-border-color', '--section-panel-cta-bg', '--color-accent-strong'],
        },
        {
            what: 'panel CTA, hover — the LIVE winner, in the shared premium block',
            body: () => lastTopLevel('main .btn' + NOT3 + ':hover'),
            slot: '--section-panel-cta-hover-border',
            chain: ['--section-panel-cta-hover-border', '--cta-button-hover-border',
                '--cta-accent-hover', '--btn-hover-border-color', '--color-accent'],
        },
        {
            what: 'panel CTA, rest — the section-block keystone (masked, but the slot contract)',
            body: () => uniqueTopLevel('.section__panel-cta' + NOT3),
            slot: '--section-panel-cta-border',
            chain: ['--section-panel-cta-border', '--btn-border-color', '--section-panel-cta-bg',
                '--btn-bg', '--color-accent'],
        },
        {
            what: 'panel CTA, hover — the section-block keystone (masked, but the slot contract)',
            body: () => uniqueTopLevel('.section__panel-cta' + NOT3 + ':hover'),
            slot: '--section-panel-cta-hover-border',
            chain: ['--section-panel-cta-hover-border', '--btn-hover-border-color',
                '--color-accent-hover'],
        },
    ];

    RINGS.forEach(({ what, body, slot, chain }) => {
        test(`${what}: ${slot} LEADS the border chain`, () => {
            expect(borderChain(body())[0]).toBe(slot);
        });

        test(`${what}: the whole chain, in order`, () => {
            // Exact, not "contains": the contract IS the order, so a reorder must fail.
            expect(borderChain(body())).toEqual(chain);
        });
    });

    test('rest and hover are positional twins on both new families', () => {
        // Every knob swapped for its hover equivalent, same positions. This is what makes a
        // rest->hover ring flip impossible in any authoring configuration.
        // Explicit rest-knob -> hover-knob map rather than a name transform: these names are
        // not mechanically derivable from one another (--hero-accent -> --hero-accent-hover but
        // --hero-button-border -> --hero-button-hover-border), and a clever regex that got one
        // of them wrong would make this pin assert the wrong contract while still going green.
        const TWIN = {
            '--hero-button-border': '--hero-button-hover-border',
            '--hero-accent': '--hero-accent-hover',
            '--btn-border-color': '--btn-hover-border-color',
            '--section-panel-cta-border': '--section-panel-cta-hover-border',
            '--color-accent': '--color-accent-hover',
        };
        const hover = (t) => {
            expect(TWIN, `no hover twin recorded for ${t}`).toHaveProperty(t);
            return TWIN[t];
        };
        const pairs = [
            ['.hero .btn' + NOT3, '.hero .btn' + NOT3 + ':hover', uniqueTopLevel],
            ['.section__panel-cta' + NOT3, '.section__panel-cta' + NOT3 + ':hover', uniqueTopLevel],
        ];
        pairs.forEach(([restSel, hoverSel, get]) => {
            const rest = borderChain(get(restSel));
            const hov = borderChain(get(hoverSel));
            // The panel CTA's rest chain carries a fill link (--section-panel-cta-bg / --btn-bg)
            // that has no hover counterpart: #536 shipped it resting-state-only and #584 does
            // not revisit that. Drop the fill links before comparing, then the ring skeletons
            // must match knob for knob.
            const FILL = /-(bg)$/;
            expect(rest.filter((t) => !FILL.test(t)).map(hover))
                .toEqual(hov.filter((t) => !FILL.test(t)));
        });
    });

    test('the panel CTA ring is routed in BOTH the keystone and the premium winner', () => {
        // The whole reason the duplication exists. Losing either half is silent: the keystone
        // alone is a dead slot, the premium alone breaks the in-block slot contract.
        ['--section-panel-cta-border', '--section-panel-cta-hover-border'].forEach((slot) => {
            const hits = stripComments(COMPONENTS_CSS).match(
                new RegExp(`var\\(${slot},`, 'g'),
            );
            expect(hits, `${slot} must be routed`).not.toBeNull();
            expect(hits.length, `${slot} must be routed in the keystone AND the premium winner`)
                .toBe(2);
        });
    });

    test('the hero primary ring slots never reach the second CTA (#526 isolation)', () => {
        // Hero style slots land on the .hero ROOT, so --hero-button-border inherits onto the
        // second CTA exactly as --hero-button-bg does. #526 re-points the fill/ink/shadow
        // slots on the cta2 element for that reason; the ring slots are kept out of cta2's
        // chains instead, because cta2 has its own --hero-button2-*border pair. Isolation
        // currently holds because cta2's rule outranks the primary's — pinned here so a
        // future specificity change fails loudly rather than leaking the primary's ring.
        const CTA2 = '.hero .hero__cta-group .hero__cta--secondary' + NOT3;
        [CTA2, CTA2 + ':hover'].forEach((sel) => {
            const chain = borderChain(uniqueTopLevel(sel));
            expect(chain).not.toContain('--hero-button-border');
            expect(chain).not.toContain('--hero-button-hover-border');
            expect(chain.some((t) => /^--hero-button2-(hover-)?border$/.test(t))).toBe(true);
        });
    });

    test('NO band-accent tier and NO hover FILL slot were added to the panel CTA', () => {
        // The narrowing this issue committed to: the panel CTA reaches its third tier through
        // the ring slot, not through an accent tier, and #536's resting-only FILL posture stands.
        const css = stripComments(COMPONENTS_CSS);
        expect(css).not.toContain('--section-button-accent');
        expect(css).not.toContain('--section-panel-cta-accent');
        expect(css).not.toContain('--section-panel-cta-hover-bg');
    });
});
