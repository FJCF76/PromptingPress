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

/**
 * One component schema, by name.
 *
 * Three separate inline `JSON.parse(fs.readFileSync(...))` blocks appeared in this file
 * when #994 moved chrome's designable values out of the stylesheet and several pins had
 * to follow them into the schema. Three hand-rolled copies of one read is how the fourth
 * one ends up pointing at the wrong path.
 */
function readSchema(component) {
    return JSON.parse(
        fs.readFileSync(path.resolve(__dirname, `../../components/${component}/schema.json`), 'utf-8'),
    );
}

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
    // THE STACK KEEPS THE AT-RULE NAME, not just its media text (#1046). It used to
    // push `null` for every non-media at-rule, which was enough to find the nearest
    // enclosing @media and threw away the one other thing a caller needs: WHICH at-rule
    // a rule sits inside. faq's `@keyframes faq-open` is the case that needed it — its
    // `from`/`to` blocks parse as ordinary rules carrying `opacity`, and `opacity` is
    // ALWAYS_DESIGN, so the v2 boundary rule flagged a keyframe body as an authorable
    // value with nowhere to be authored.
    const pattern = /@([\w-]+)([^{;]*)\{|([^{}]+)\{([^{}]*)\}|\}|;/g;
    const stack = [];
    let match;
    while ((match = pattern.exec(css)) !== null) {
        if (match[1] !== undefined) {
            stack.push({ at: match[1], media: match[1] === 'media' ? match[2].trim() : null });
        } else if (match[3] !== undefined) {
            const selectors = match[3].split(',').map(s => s.trim().replace(/\s+/g, ' '));
            // Nearest enclosing @media, looking outward past non-media at-rules.
            const media = [...stack].reverse().find(f => f.media !== null)?.media ?? null;
            rules.push({
                selectors,
                body: match[4],
                media,
                atRules: stack.map(f => f.at),
                index: match.index,
            });
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

// Issue 355, REPRICED BY RULING A1 (#976). The original pin required the active/current
// header link to route its COLOR through --header-link-color so an operator's
// pp_header_link_color reached it. That option is gone and chrome is styled through
// pp_site_udc, where the active link is its own `link-current` role — so the routing
// is not just unnecessary, it no longer exists, and asserting it would pin a lie.
//
// The DEFECT #355 found still has to stay fixed, and it was never really about the
// custom property: the active link had no colour surface of its own at all.
//
// REPOINTED AGAIN BY #994, because the surface moved rather than changed. The three
// stylesheet rules that carried the accent and the bold weight are gone; they are
// the `link-current` role's DEFAULTS now. Asserting them against components.css
// would pin their absence, so the assertions follow the values to the schema — the
// guarantee is identical and it is checked where the values actually live.
//
// THE SELECTOR IS HALF THE GUARANTEE and gets its own assertion. `current-menu-item`
// is a strict superset of the other two markers (WordPress only adds
// `current_page_item` inside the branch that already added `current-menu-item`, and
// `aria-current="page"` derives from the same flag), so ONE selector replaces three
// — but only in the CHILD form. The descendant form would also paint every link in
// a current parent's dropdown, which is the regression ruling D4 widened the
// role-selector charset to avoid.
describe('CSS lint: #355 the active header link is its own colour surface', () => {
    const linkCurrent = readSchema('nav').roles['link-current'];

    test('the link-current role exists and reaches the current item as a CHILD', () => {
        expect(linkCurrent).toBeTruthy();
        expect(linkCurrent.selector).toBe('.nav__menu ul li.current-menu-item > a');
    });

    test('link-current carries its own colour default and the bold weight', () => {
        expect(linkCurrent.defaults.typography.color).toBe('@color-accent');
        expect(linkCurrent.defaults.typography.weight).toBe('700');
    });

    test('the retired stylesheet rules really are gone, not duplicated', () => {
        const css = stripComments(COMPONENTS_CSS);
        expect(css).not.toMatch(/\.nav__menu ul li\.current_page_item/);
        expect(css).not.toMatch(/\.nav__menu ul li a\[aria-current="page"\]/);
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
 * pins that the panel declares a background at all (so it is
 * readable over page content, and so the `menu` UDC role has something to override) and the aria-expanded-driven icon swap (the close
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

    test('the panel declares its own background, as a phone-scoped `menu` role default', () => {
        // Was: routes the --header-bg chrome slot, then: declares `background` here.
        // Both homes are gone — the slot with the pp_header_bg option (#976, ruling
        // A1), the declaration with #994's retirement. What must stay true has never
        // changed: the panel has a fill AT ALL, because an out-of-flow panel over page
        // content without one is unreadable, and that is the regression this test
        // exists to catch.
        //
        // THE BREAKPOINT IS PART OF THE ASSERTION. The default is keyed `p`, and its
        // `d` counterpart is `transparent`: the engine's `d` breakpoint carries no
        // media query, so a fill declared only as `d` would paint the desktop menu — a
        // bar-coloured rectangle behind the desktop links — instead of the phone panel.
        const fill = readSchema('nav').roles.menu.defaults.background.fill;
        expect(fill.p).toBe('@color-bg');
        expect(fill.d).toBe('transparent');
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

  test('the contact <address> resets italic and its links carry no slot-defeating literal', () => {
    // BOTH HALVES MOVED TO THE SCHEMA (#994), so both assertions follow them. The
    // <address> italic reset is `address`'s `typography.style`, and the link ink is
    // `address-link`'s `typography.color`. What each guards is unchanged: an
    // <address> renders italic by UA default and footer contact info is not italic,
    // and the link colour must be a TOKEN rather than a hardcoded literal — a
    // literal would still work and would still be wrong, because retuning the
    // palette would leave these two links behind every other muted surface.
    const footerSchema = readSchema('footer');
    expect(footerSchema.roles.address.defaults.typography.style).toBe('normal');
    const linkColor = footerSchema.roles['address-link'].defaults.typography.color;
    expect(linkColor).toBe('@color-muted');
    expect(linkColor).not.toMatch(/^#[0-9a-f]{3,8}$/i);
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
    const SCHEMA_COMPONENTS = ['hero', 'section', 'grid', 'cta'];  // v2 members contribute zero
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

    test('this group\'s schemas declare 38 style slots (subset of the total)', () => {
        // 166 -> 172 (#584): +1 hero heading rhythm, +2 hero primary ring slots,
        // +2 section panel-CTA ring slots, +1 cta heading rhythm.
        // 172 -> 174 (#581): the two state twins — grid's --grid-item-link-hover-color
        // and cta's --cta-button2-shadow.
        // 174 -> 125 (#986): hero left the v1 styling system. Its 49 slots are not
        // renamed or relocated — they are GONE, replaced by 13 roles in its schema.
        // 125 -> 78 (#1023): section left it too, the same way — its 47 slots are gone,
        // replaced by 17 roles.
        // 78 -> 38 (#1026): cta left, its 40 slots replaced by 11 roles. ONE of this
        // group's four components is still on slots, so the name says "this group" rather
        // than naming them — the roster above is the fact. The count stays a count rather
        // than being deleted with each rebuild, because drift in the components STILL on
        // slots is exactly what this pin catches; keeping the v2 members in
        // SCHEMA_COMPONENTS is deliberate, so a rebuilt component that quietly re-grew a
        // slot map would push this number up and fail here.
        expect(allSlots.length).toBe(38);
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

/* RETIRED (#1026): the two remaining per-instance BUTTON-SLOT blocks, because the slots
 * they pin no longer exist on any component:
 *
 *   'premium primary-button fill routes through the fill-slot chain (#412/#514)'
 *   'global button hover tier (#539)'
 *
 * Both pinned the ORDER of links inside the shared premium button's `var()` chains —
 * which per-instance slot led, where the global `--btn-*` tier sat relative to it, and that
 * the second button routed every shared link its primary did. `--hero-button-*` left at
 * #986, `--section-panel-cta-*` at #1023, `--cta-button*-*` at #1026, so each chain is now
 * one global knob and one literal, and two links that cannot both exist cannot be
 * mis-ordered. A replacement pin would pass vacuously.
 *
 * #539's CONTRACT SURVIVES AND IS STILL CHECKED, which is the part worth being precise
 * about — including precise about WHERE, because this file is not the place. "A site-wide
 * button retheme reaches every filled surface" is now a property of a single-link chain,
 * and it is pinned as RENDERED computed style, not as CSS text: see style-render.spec.ts's
 * `#458 the global button surface is a real one-knob` ('setting --btn-* at :root restyles
 * every composed primary incl. section-panel + shadow') for the rest half, and its `#539
 * the global button surface survives a hover` ('setting --btn-hover-bg /
 * --btn-hover-border-color reaches every filled surface') for the hover half. Those read
 * all four knobs against real buttons, which is strictly stronger than the text pin that
 * used to live here. What is gone is the ORDERING half, which only had meaning while
 * something sat above the knob.
 *
 * #412/#514's MECHANISM survives too, and outlived its slots: a flat value resolves the
 * `background` SHORTHAND to `background: <color>`, which resets `background-image` and
 * clears the premium gradient. A role's `background.fill` emits that same shorthand by
 * construction (pp_udc_groups(): "fill MUST stay first"), so the masking fix is now a
 * property of the emitter rather than of a hand-written fallback chain. The surviving
 * shorthand in components.css carries that note where a future longhand edit would break it.
 */

describe('CSS lint: global button hover tier (#539), narrowed to its surviving subjects', () => {
    // RESTORED, NARROWED (#1026). The original describe was deleted whole on the reading that
    // #539's contract had moved to the rendered e2e pins. Three of its six tests had SURVIVING
    // subjects and no replacement in either unit suite, and the e2e block does not cover them:
    // it hovers three FILLED buttons inside `main` and never touches `.btn:hover` or any
    // outline variant. Proven by planting: stripping both global knobs out of `.btn:hover`
    // left 5086 PHPUnit + 1525 vitest tests green.
    //
    // What is gone for good is the ORDERING half — the parameterised per-instance chains
    // (`--hero-button-hover-bg`, `--cta-button-hover-bg`, `--section-panel-cta-hover-*`). Those
    // retired with their components at #986 / #1023 / #1026, and two links that cannot both
    // exist cannot be mis-ordered. Everything below reads a chain that still ships.
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
    // The premium primary is declared TWICE — a superseded rule and the true cascade winner.
    // `last` is the live winner in that pair.
    const bodyFor = (sel) => {
        const all = bodiesFor(sel);
        return all.length ? all[all.length - 1] : null;
    };
    const NOT3 = ':not(.btn--outline):not(.btn--ghost):not(.btn--secondary)';

    /*
     * The bare `.btn:hover` is the live hover winner OUTSIDE `main` — every header and footer
     * button — so it is the one rule that carries the whole global hover surface for them.
     * Both knobs, both falling through to today's literal so unset stays byte-identical.
     */
    test('.btn:hover routes both global hover knobs', () => {
        const body = bodyFor('.btn:hover');
        expect(body).not.toBeNull();
        expect(body).toContain('background-color: var(--btn-hover-bg, var(--color-accent-hover));');
        expect(body).toContain('border-color: var(--btn-hover-border-color, var(--color-accent-hover));');
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
        expect(outlineHover).not.toMatch(/border-color\s*:/);
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

    test('BOTH premium hover rules carry the tier, so the superseded one cannot drift', () => {
        // #514/#530 keep the superseded and the live premium rules uniform on purpose: the
        // superseded one is the shape a reader hits first, so a drifted copy teaches the wrong
        // chain. The chain head was `--cta-button-hover-bg` until #1026 retired it; the global
        // knob leads now, and BOTH copies must still route it.
        const hits = NO_COMMENTS.match(/background:\s*var\(--btn-hover-bg,/g);
        expect(hits).not.toBeNull();
        expect(hits.length).toBe(2);
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
 *      [0,4,1] alone: `.hero .btn:not(...)` and `.cta .btn:not(...)` SAT at [0,5,0] and owned
 *      the background-COLOR half until they were deleted (hero's at #986, cta's at #1026);
 *      the premium `main .btn:not(...)` rules and the global `--btn-*` tier own it now. The
 *      "two-rule split" this used to cite at components.css:820 is not documented there any
 *      more — that line is a `.hero--cover[data-pp-vertical-align]` alignment rule.
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

/* RETIRED (#1026): FOUR MORE cta-class blocks, for the same reason as the three above —
 * `.cta--inverted` and `.cta--has-bg-image` derive from `theme` and `background_image`, and
 * both props retired with the rebuild, so these selectors can no longer be written:
 *
 *   'inverted dark-band links route through the on-inverted accent role (#437)'
 *   'bg-image band accent routes through --color-accent-on-overlay (#461)'
 *   'bg-image band title-accent + markers route through --color-accent-on-overlay (#463)'
 *   'primary-button background-color routes through --cta-button-bg (#420)'
 *
 * The first three pinned AA ink corrections on a dark band: the body link, its hover, and
 * the accented heading substring. #420 pinned that the composed primary's background-COLOR
 * winner routed through the per-instance fill slot, which was the fix for the gradient
 * masking a flat fill — a slot that no longer exists on any component.
 *
 * #461's and #463's pins each had a NEGATIVE half worth naming before it goes: they asserted
 * the chain did NOT bottom out at the bare `--color-accent`, because that is 1.16:1 over the
 * worst-case scrim and the whole point of the on-overlay role. That guarantee is now the
 * author's, on `typography.color` for the role over the scrim, and it is the loss cta's
 * README records rather than one this deletion hides.
 *
 * #420's mechanism did not die with its slot: `background.fill` emits the `background`
 * SHORTHAND, which resets `background-image` and therefore clears the premium gradient the
 * same way a flat slot value did. That is stated at the surviving shorthand in
 * components.css, where a future edit to longhands would break it.
 */

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

// The #475 inline-items separator COLOUR block was deleted at #1023. Its four rules
// were section's `--section-separator-color` routing, on a `::before`/`::after` glyph.
// Section is a v2 component now: the glyph is a shared mechanism (see the SHARED GLYPH
// AND PROSE MECHANISMS block in components.css) and its colour is NOT authorable,
// because ruling A3 defers pseudo-elements. The rule reads --pp-list-marker-color, which
// nothing declares and nothing can write (#1028), so its fallback is what renders: the
// SEPARATOR takes currentColor and follows its row, the two list MARKERS take
// var(--color-accent) — the exact value their slots defaulted to.
// Nothing replaced this block: there is no slot left to route.
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
        // Section's two entries left this list at #1023 and cta's at #1026, each with the
        // `theme` prop itself. The three-tier slot -> theme-var -> token chain has no v2
        // analogue: a band's text colour is `typography.color` on the `heading` / `body`
        // roles, and a dark band is the `_band` role's `background`, so there is no variant
        // rule for a desktop rule to lose to. The cascade defect this guard exists for
        // cannot recur on a v2 component — role defaults emit unlayered, above every rule in
        // this file. cta's departure also took the last `main > .cta` premium-typography
        // rules with it; their VALUES survive as the `body` role's breakpoint maps, which is
        // the only reason the phone tier still differs from the two wider ones.
        // faq (issue 581): it implements the identical three-tier chain — the base and the
        // >=768px premium rule both read
        // var(--faq-heading-color, var(--pp-faq-heading-theme-color, var(--color-text)))
        // with the plumbing declared on .faq--inverted — but it was never listed here, so
        // the one mechanism most likely to regress was the one nothing pinned.
        // faq's row left at #1046 with its rebuild. The three-tier chain has no v2
        // analogue for the same reason section's and cta's did not: a role default is
        // emitted UNLAYERED, above every rule in this stylesheet, so nothing here can
        // outrank it and there is no theme variable left to sit between the slot and the
        // token. grid keeps the row because grid keeps the chain.
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
        // Section's two variants are gone (#1023), the way hero's row went at #986: the
        // `theme` prop and the `background_image` prop both retired, so neither
        // `.pp-section--inverted` nor `.section--has-bg-image` is emitted any more. A dark
        // or image-backed section band is the `_band` role's `background` group, and its
        // text colours are `typography.color` on the text roles.
        // `.faq--inverted` left at #1046 with the `theme` prop that emitted it.
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

// The #424 dark-band heading carve-out block was deleted at #1023. Its two variants
// (`.pp-section--inverted`, `.section--has-bg-image`) and its `--section-panel-text`
// assertion were all section's, and none of that surface exists now. The panel is the
// `panel` role with its own `typography.color`, and it is no longer at risk of being
// repainted by a band-wide heading rule, because there is no band-wide heading rule:
// the `heading` role paints `.section__title` and nothing else.
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
    //
    // REWRITTEN, NOT RETIRED (#986). The invariant is unchanged — an eyebrow must read
    // as a pill and not as a full-width band — but hero is a v2 component, so its
    // padding and background are the `eyebrow` ROLE's defaults rather than declarations
    // in this stylesheet. Asserting them here would now assert the opposite of the §2
    // boundary; asserting them in the schema keeps the same property true at the place
    // that owns it. The geometry half of the pill (align-self, no width) stays in CSS
    // and stays pinned by its neighbours.
    test('the eyebrow keeps its pill styling, as role defaults', () => {
        const schema = JSON.parse(fs.readFileSync(
            path.resolve(__dirname, '../../components/hero/schema.json'), 'utf-8'));
        const eyebrow = schema.roles.eyebrow;
        expect(eyebrow, 'hero must declare an `eyebrow` role').toBeDefined();
        expect(eyebrow.defaults.spacing.padding, 'the pill needs padding').toBeTruthy();
        expect(eyebrow.defaults.background.fill, 'the pill needs a fill').toBeTruthy();
        // …and the stylesheet must NOT have taken them back.
        const rule = rulesMatching(BASE).find(r => r.selectors.includes(BASE) && !r.media);
        expect(rule.body).not.toMatch(/padding\s*:/);
        expect(rule.body).not.toMatch(/background\s*:/);
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

    test('grid heading premium rule routes font-size through --grid-heading-size', () => {
        assertPropRoutesThroughSlot('main > .grid .grid__heading', 'font-size', '--grid-heading-size', 1);
    });

    // ---- SECTION'S FIVE ROWS LEFT THIS BLOCK AT #1023 ----
    //
    // The heading font-size pin, the `.section__body` measure pin, and the three
    // `.section__content` type pins (base, desktop premium, mobile) all described
    // slot ROUTING through rules this rebuild deleted. Section declares no slots, so
    // there is nothing left to route: the values are the `heading` and `body` roles'
    // defaults in components/section/schema.json, responsive where v1 split them
    // across breakpoints, and the boundary test enforces that this stylesheet may not
    // declare any of those properties for section at all. grid, cta, faq and stats
    // keep their rows below — the dead-slot class this block guards is still live for
    // every component still on slots.


    // The #578 section half of the cta-body-size severance went with section's mobile
    // body rule at #1023. It guarded that an unset section body did not chain through a
    // slot cta owns; section has no body-size slot and no rule in this file at all now,
    // so there is no chain left to sever. The grid/faq half is below and still live.

    // The other half of the same severance: grid cards and faq answers took their mobile
    // body size from --cta-body-size too. They now carry the literal.
    // THE "cta KEEPS THE SLOT IT OWNS" HALF WENT AT #1026, and its absence is the point of
    // the severance rather than a hole in it. cta's own rules are gone with its slot map,
    // so there is no longer any reader of `--cta-body-size` anywhere in this file — which
    // is what makes the grid/faq assertion below unconditional now. #578 severed a borrowed
    // slot; the rebuild removed the lender.
    test('#578 grid/faq mobile body size no longer reads the cta body-size slot', () => {
        const gridBodies = bodiesForExactSelector('main > .grid .grid__item-text');
        const faqBodies = bodiesForExactSelector('main > .faq .faq__answer');
        expect(gridBodies.length + faqBodies.length).toBeGreaterThanOrEqual(2);
        [...gridBodies, ...faqBodies]
            .flatMap(b => b.match(/font-size\s*:[^;}]+/g) || [])
            .forEach(d => expect(d).not.toMatch(/--cta-body-size/));
        // And nothing anywhere in the sheet reads it, which a stricter check can now make.
        expect(stripComments(COMPONENTS_CSS)).not.toMatch(/--cta-body-size/);
    });

    // ---- Stats contained-card capability (issue 383), REPRICED AT #1066 PR2 ----
    //
    // The slots this pinned (`--stats-radius`, `--stats-max-width`) retired with stats'
    // rebuild, and the claim they carried moves to the `_band` role rather than dying with
    // them. The claim was never really about two slots: it was that an author can make the
    // band a CONTAINED ROUNDED CARD instead of a full-bleed strip, and that an unset band
    // stays byte-identically full-bleed and square.
    //
    // THE HALF THAT IS EASY TO LOSE is the centring. A capped band only sits in the middle
    // because of auto side margins - `margin-inline: auto` in v1, and the two
    // `spacing.margin-*: auto` defaults on `_band` now. They are INERT at the default
    // `max-width: none` (measured: auto computes to 0 on a full-bleed band), which is
    // exactly why they are easy to drop as "doing nothing" and why their absence would not
    // show up until someone capped a band and found it pinned to the left edge. Same defect
    // class as #367 one element down.
    test('the stats band can still become a contained rounded card, and stays centred (#383)', () => {
        const schema = JSON.parse(
            fs.readFileSync(
                path.resolve(__dirname, '../../components/stats/schema.json'), 'utf-8',
            ),
        );
        const band = schema.roles._band;
        expect(band).toBeDefined();
        // The two capabilities the retired slots expressed.
        expect(band.groups).toContain('sizing');
        expect(band.groups).toContain('border');
        // An unset band stays full-bleed and square: neither is DEFAULTED, only permitted.
        expect((band.defaults.sizing || {})['max-width']).toBeUndefined();
        expect((band.defaults.border || {}).radius).toBeUndefined();
        // And the centring that makes a cap meaningful ships as a default.
        expect([band.defaults.spacing['margin-left'], band.defaults.spacing['margin-right']])
            .toEqual(['auto', 'auto']);
    });

    // ---- Own section/grid/cta padding (desktop + mobile) ----

    test('every .grid padding declaration routes through --grid-padding-*', () => {
        assertPropRoutesThroughSlot('.grid', 'padding-top', '--grid-padding-top', 2);
        assertPropRoutesThroughSlot('.grid', 'padding-bottom', '--grid-padding-bottom', 2);
    });

    // cta's padding pin left at #1026 with its slot map. Its band padding is the `_band`
    // role's `spacing.padding-top` / `padding-bottom`, defaulting to the same shared
    // `@pp-band-padding` this guard pins for every component still on slots — and the
    // adjacent-sibling rhythm reaches it through the zero-specificity baseline rather than a
    // per-component rule, because `_band` defaults emit into `pp-zero`, below this
    // stylesheet. Identical to section's departure at #1023.
    // (The page-specific `#*-cta` padding pins were removed with the demo-ID eviction
    //  in #412: those ID-scoped closers no longer ship.)

    // ---- Adjacent-sibling rhythm routes through the shared def ----
    // The flat premium override was DELETED; the remaining rules for this exact
    // selector route their top through --pp-band-padding-adjacent-top (issue 431),
    // which is itself pinned to --pp-band-padding (issue 430 symmetry). Neither
    // may re-introduce the flattening clamp() literal in components.css.
    test('adjacent-sibling rhythm no longer flattened by a bare clamp()', () => {
        const bodies = bodiesForExactSelector(':where(main > [data-pp-component] + [data-pp-component]:not(.hero))');
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
        // section is absent from this table since #1023, for the same reason
        // testimonials is: its CSS block is structural only, so it routes nothing
        // through a slot. Its adjacent top edge comes from the zero-specificity
        // baseline rule above, which its `pp-zero` band default yields to by design.
        ['main > [data-pp-component] + .grid', '--grid-padding-top'],
        ['main > [data-pp-component] + .stats', '--stats-padding-top'],
        // faq's adjacent row left at #1046: with no slot to keep live, the rule was
        // deleted and the zero-specificity baseline serves the edge directly.
// testimonials is absent from this table: it is a v2 component whose CSS block is
        // structural only, so it routes nothing through a slot. The value this row used to
        // guard is now a role default in components/testimonials/schema.json.
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

    // THE TWO #434 RESTATEMENT PINS ARE RETIRED, AND SO ARE THEIR HELPERS (#986).
    //
    // What they pinned: an explicit `data-pp-spacing` override (hero only) had to win
    // BOTH edges at EVERY breakpoint, because before #434 the mobile @media block had
    // no restatement and the generic mobile adjacent rule shaved a spaced hero's top
    // edge alone. `spacing` was a bundle of two padding values, which the UDC expresses
    // directly, so the prop, its data attribute and the rules are all gone.
    //
    // Three helpers went with them — mediaBlocks(), spacingRuleBody() and
    // assertSymmetricTier() — rather than being left with no callers. The last targeted
    // `main > .hero[data-pp-spacing="…"]`, a selector nothing can emit now, so keeping
    // it would have preserved the shape of a test without its subject.
    //
    // The symmetry they protected — a spaced band is never shaved on one edge — is now
    // structural: an author sets `_band` padding-top and padding-bottom themselves, and
    // no rule in this stylesheet touches hero's padding at all.
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
        // section left this list at #1023 and cta at #1026. Each band's padding is the `_band` role's
        // spacing default, falling back to the same shared `@pp-band-padding` this guard
        // pins for every component still on slots — and the adjacent-sibling rhythm
        // reaches it through the zero-specificity baseline rather than a per-component
        // rule, because `_band` defaults emit into `pp-zero`, below this stylesheet.
        { comp: 'grid', cls: '.grid', slot: '--grid' },
        // stats left this table at #1066 PR2 — its band rhythm is the `_band` role's
        // spacing default, resolving to the same shared `@pp-band-padding`. The behavioural
        // pin that replaces it is the e2e #431 nine-band equality test, where stats is
        // STILL a member: the shared value is asserted on the rendered page rather than on
        // the stylesheet text. stats is the row that used to make this list look like it
        // governed the whole theme; grid is the ONLY member left.
        // faq left this table at #1046 — its band rhythm is the `_band` role's spacing
        // default, resolving to the same shared `@pp-band-padding`. The behavioural pin
        // that replaces it is the e2e #431 nine-band equality test, where faq is STILL a
        // member: the shared value is now asserted on the rendered page rather than on
        // the stylesheet text.
// testimonials is absent from this table: it is a v2 component whose CSS block is
        // structural only, so it routes nothing through a slot. The value this row used to
        // guard is now a role default in components/testimonials/schema.json.
        // table left this table at #1066 — its band rhythm is the `_band` role's spacing
        // default, resolving to the same shared `@pp-band-padding`, and both of its
        // per-component adjacent rules went with the slot they existed to keep alive.
        // The behavioural pin that replaces it is the e2e #431 nine-band equality test,
        // where table is STILL a member: the shared value is now asserted on the rendered
        // page rather than on the stylesheet text.
        // logos left this table at #1066 PR2 with stats, for the same reason and with the
        // same replacement: `_band` -> `spacing.padding-top` / `padding-bottom`, both
        // `@pp-band-padding`, pinned on the rendered page by the e2e #431 nine-band
        // equality test where logos is STILL a member.
        // embed left this table at #1066 with table — its band rhythm is the `_band`
        // role's spacing default, resolving to the same shared `@pp-band-padding`, and
        // both of its per-component adjacent rules went with the slot they kept alive.
        // The behavioural pin that replaces it is the e2e #431 nine-band equality test,
        // where embed is STILL a member.
        // hero is absent from this table (#986): it is a v2 component whose CSS block
        // is structural only, so it routes nothing through a slot and declares no
        // per-component adjacent rule. Its opt-out from the shared band rhythm survives
        // as the `_band` role's own defaults (--space-2xl / --space-xl), and what keeps
        // the shared catch-all off it is the `:not(.hero)` pinned further down.
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
        const bodies = bodiesForExactSelector(':where(main > [data-pp-component] + [data-pp-component]:not(.hero))');
        expect(bodies.length).toBeGreaterThanOrEqual(2); // desktop + mobile
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        expect(decls.length).toBeGreaterThanOrEqual(2);
        decls.forEach(d => {
            expect(d).toMatch(/padding-top\s*:\s*var\(\s*--pp-band-padding-adjacent-top\s*\)/);
        });
    });

    // 3b-ii. The catch-all's ZERO SPECIFICITY is itself load-bearing (v2 Sprint 0).
    //     It is the design system's baseline rhythm — a DEFAULT — and §3.4 ranks an
    //     authored v2 band value above a default. While it was a bare
    //     `main > [data-pp-component] + [data-pp-component]` [0,2,1] it outranked the
    //     authored band block `[data-pp-band="<id>"]` [0,1,0], and an authored
    //     padding-top on an adjacent band rendered the shared value instead — silently,
    //     because the write itself succeeded. Unwrapping the `:where()` reinstates that
    //     bug, so the wrapper is pinned here rather than left to a comment.
    test('the generic adjacent-sibling catch-all contributes ZERO specificity', () => {
        expect(bodiesForExactSelector(':where(main > [data-pp-component] + [data-pp-component]:not(.hero))').length)
            .toBeGreaterThanOrEqual(2); // desktop + mobile, both wrapped
        // And the unwrapped form is gone from both tiers.
        expect(bodiesForExactSelector('main > [data-pp-component] + [data-pp-component]:not(.hero)'))
            .toHaveLength(0);
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
    // 3c/3d. HERO'S TWO ADJACENT-TOP ORDERING PINS ARE RETIRED (#986).
    //
    //     Both refereed hero's per-component adjacent rules — the desktop one against
    //     the [data-pp-spacing] restatement, and the .hero--left twin against the plain
    //     .hero rule. Hero is a v2 component now: `spacing` is gone as a prop, so the
    //     restatement is gone, and hero's padding on BOTH edges is the `_band` role's
    //     default, so neither per-component rule exists to be ordered.
    //
    //     What replaced them is one exclusion, pinned here: the shared catch-all reads
    //     `:not(.hero)`. That is what makes hero's role default reachable — the
    //     catch-all and the defaults tier are both zero-specificity and this stylesheet
    //     prints later, so without the exclusion the catch-all would silently defeat a
    //     declared default (the I35 class, and the same defect issue 577 fixed from the
    //     other side).

    test('the shared adjacent-top catch-all excludes hero, whose padding is its role default', () => {
        const css = stripComments(COMPONENTS_CSS).replace(/\s+/g, ' ');
        expect(
            css,
            'hero must be excluded from the shared adjacent-top catch-all, or its `_band` role default cannot paint'
        ).toContain(':where(main > [data-pp-component] + [data-pp-component]:not(.hero))');
        // …and no per-component hero adjacent rule may come back alongside it.
        expect(css).not.toContain('main > [data-pp-component] + .hero {');
        expect(css).not.toContain('main > [data-pp-component] + .hero--left {');
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
        // section's row is gone (#1023) and cta's at #1026: the shared band-heading scale reaches each as the
        // `heading` role's `typography.size` default (@pp-band-heading-size), which is
        // the same token this guard pins for every component still on slots.
        { selectors: ['.grid__heading', 'main > .grid .grid__heading'], slot: '--grid-heading-size' },
        // faq's row left at #1046: the heading size is the `heading` role's
        // `typography.size`, referencing the same shared `@pp-band-heading-size`.
        // stats' row left at #1066 PR2 with its slot; the size is the `heading` role's
        // typography.size default now (@pp-band-heading-size, the same shared token),
        // pinned against the EMITTED declaration in StatsRoleDefaultsEmitTest rather than
        // against this stylesheet's text.
        // table's row left at #1066 with its slot; the size is the `heading` role's
        // typography.size default now, pinned against the EMITTED declaration in
        // TableRoleDefaultsEmitTest rather than against this stylesheet's text.
        // logos' row left at #1066 PR2 with stats'; same replacement, pinned in
        // LogosRoleDefaultsEmitTest. GRID IS THE ONLY MEMBER LEFT — this roster began as
        // the whole theme's band headings and is now one component, which is the shape
        // every one of these v1-era guards is converging on.
        // embed's row left at #1066 with its slot; the size is the `heading` role's
        // typography.size default now, pinned against the EMITTED declaration in
        // EmbedRoleDefaultsEmitTest.
// testimonials is absent from this table: it is a v2 component whose CSS block is
        // structural only, so it routes nothing through a slot. The value this row used to
        // guard is now a role default in components/testimonials/schema.json.
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
            // A v2 component's tokens are consumed by ROLE DEFAULTS (`@token-name`),
            // not by `var()` in a stylesheet — its CSS block is structural only. So the
            // consumption this test looks for lives in the schema, and the schema text
            // rides along as a second place to find it. Same contract, two vocabularies:
            // a listed token must be a token something actually reads.
            const roleText = JSON.stringify(schema.roles || {});
            tokens.forEach(token => schemaTokens.push({ component: d.name, token, roleText }));
        });

    // Fail-closed floor: if discovery breaks (moved dir, renamed schemas), the
    // per-token loop would pass vacuously over an empty list.
    test('discovery finds schema tokens to check', () => {
        expect(schemaTokens.length).toBeGreaterThanOrEqual(10);
    });

    test.each(schemaTokens)('$component token $token is consumed as var(--…) or by a role default', ({ token, roleText }) => {
        // Every token is a custom property; assert it appears in a var() consumption
        // (var(--token) or var(--token, fallback)) somewhere in the theme CSS…
        const re = new RegExp('var\\(\\s*' + token.replace(/[-]/g, '\\$&') + '\\s*[,)]');
        // …or, for a v2 component, as an `@name` reference in a role default, which is
        // where its designable values live instead.
        const ref = '"@' + token.replace(/^--/, '') + '"';
        expect(
            re.test(ALL_CSS) || roleText.includes(ref),
            `${token} is listed in a schema but neither consumed in the theme CSS nor referenced by a role default`
        ).toBe(true);
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
            // A v2 component reaches its tokens through ROLE DEFAULTS (`@token-name`),
            // not through `var()` in a stylesheet — its CSS block is structural only.
            // Same contract, different vocabulary: a token a schema LISTS must be a
            // token that component actually uses, or the list is decoration.
            const roleDefaults = JSON.stringify(schema.roles || {});
            return { name, blocks, tokens: styling.tokens || [], roles: schema.roles || null, roleDefaults };
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
        const entry = components.find(c => c.name === component);
        const { blocks } = entry;

        // CHROME TAKES THE ROLE-DEFAULTS PATH TOO SINCE #994. It used to be carved
        // out here for the same reason it was carved out of the structural boundary:
        // ruling A1 as issued kept its resting appearance in components.css, so it
        // shipped EMPTY role defaults and consumed its tokens the way a v1 component
        // does, through `var(--token)` in its own block. The retirement moved those
        // values into defaults, so the branch below is now the right one for every
        // component that declares roles, with no exception to maintain. The
        // reachability question never changed — only which half of the system
        // answers it.
        if (entry.roles) {
            // `--color-text` is referenced as `@color-text` in a role default.
            const ref = '"@' + token.replace(/^--/, '') + '"';
            expect(
                entry.roleDefaults.includes(ref),
                `${component} lists ${token} in styling.tokens but no role default references it as @${token.replace(/^--/, '')}`
            ).toBe(true);
            return;
        }

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
// The #581 `.section__title` font-size block was deleted at #1023. It pinned that every
// declaration of that property was the one base rule routing through
// --section-heading-size. Section declares no slots now; the `heading` role's
// typography.size default (@pp-band-heading-size) is the single declaration, emitted by
// the engine, and the schema is where it is pinned. The equivalent guard for a v2
// component is the boundary test above: the stylesheet may not declare font-size at all.
/**
 * #438's band heading-color guard RETIRED AT #1066 PR2, and retiring it is what the
 * block itself instructed rather than a decision taken over its head.
 *
 * The note it carried read: "four rows remain - logos and stats, base and inverted each.
 * Both leave together in #1066's second half, at which point the roster is EMPTY and this
 * whole block retires rather than being narrowed to nothing." That is this change.
 *
 * WHY NARROWING WAS NOT AN OPTION, in the block's own words: a `test.each` over an empty
 * array fails the run with "No test found in suite", so an emptied roster could not have
 * been left behind quietly - it would have failed CI as a broken suite rather than as a
 * retired claim, and the next reader would have fixed the symptom.
 *
 * WHAT THE CLAIM WAS: every band heading routed `color` through a per-component slot with
 * a fallback that preserved unset output - `@color-text` on the base rule, `@color-bg` on
 * the `--inverted` twin. Six components carried rows here over its life.
 *
 * WHERE THE CLAIM LIVES NOW, per surviving subject rather than per issue number (#1038):
 * every one of those components is on the Universal Design Contract, where a heading's
 * colour is the `heading` role's `typography.color` default. For stats and logos that
 * default is `currentColor`, which is the shape this guard structurally CANNOT express -
 * it asserts a slot-and-fallback chain, and a role default is neither. The replacement
 * pins are per component and assert the EMITTED declaration instead of this stylesheet's
 * text: StatsRoleDefaultsEmitTest and LogosRoleDefaultsEmitTest here, with their
 * table/embed/faq/cta siblings already in place.
 *
 * AND THE BEHAVIOUR THE TWO INVERTED ROWS GUARDED is not lost with them: a dark band's
 * heading following the band's ink is asserted on the RENDERED page in
 * tests/e2e/style-render.spec.ts, which is a stronger claim than the stylesheet text ever
 * made - it survives a cascade change that leaves the declaration intact.
 */


/**
 * THE v2 STRUCTURAL-CSS BOUNDARY (docs/v2/BUILD-SPEC-sprint0.md §2).
 *
 * A component rebuilt on the Universal Design Contract may keep exactly three
 * things in assets/css/: layout scaffolding, wrapper geometry, and accessibility
 * affordances. Everything value-styled — colour, type, size, spacing, border,
 * shadow — belongs to a role in that component's schema and is emitted by the
 * engine into a band-scoped block in the document head.
 *
 * THIS RULE IS WHAT MAKES THE BOUNDARY REAL. Without it the boundary is a
 * sentence in a spec, and the first hurried fix that adds `font-size: 0.9rem`
 * back into the stylesheet silently re-creates the split authority the whole
 * program exists to end: a value in CSS that no role owns is a value no author
 * can reach and no envelope can report on — which is exactly the #901 defect.
 *
 * Scoped to the components that have actually been rebuilt, discovered from the
 * schemas rather than hardcoded, so each later sprint's component joins the rule
 * on the day it declares roles.
 */
describe('CSS lint: v2 components keep NO designable value in their stylesheet', () => {
    const componentsDir = path.resolve(__dirname, '../../components');
    const v2Components = fs.readdirSync(componentsDir, { withFileTypes: true })
        .filter(d => d.isDirectory())
        .map(d => d.name)
        .filter(name => {
            const file = path.join(componentsDir, name, 'schema.json');
            if (!fs.existsSync(file)) return false;
            const schema = JSON.parse(fs.readFileSync(file, 'utf-8'));
            if (!(schema.roles && Object.keys(schema.roles).length)) return false;
            // THE CHROME CARVE-OUT LAPSED HERE (#994), on the condition it named.
            //
            // It read: chrome is on the engine but keeps its resting appearance in
            // this file (#976, ruling A1 as issued), because moving ~88 declarations
            // into role defaults is a change with its own visual risk on every page of
            // every site — not something to smuggle in behind a lint. And it carried
            // its own lapse condition: "retire chrome's CSS block in the same change,
            // or not at all... Remove this filter then." #994 retired nav's and
            // footer's blocks together, so the filter is removed rather than relaxed.
            //
            // WHY BOTH AT ONCE WAS THE RULE. An ELEMENT-level role default emits
            // UNLAYERED while this stylesheet sits in `@layer pp-v1`, so a non-empty
            // chrome default outranks the matching rule here at any specificity. A
            // half-retirement would have left the other half's hover, current-page and
            // mobile rules alive in the file and dead in the browser.
            return true;
        });

    // Fail-closed: if discovery breaks, every check below would pass vacuously.
    test('discovery finds the rebuilt components', () => {
        expect(v2Components).toContain('testimonials');
    });

    /**
     * THE CARVE-OUT'S LAPSE, PINNED (#994).
     *
     * Deleting a filter is invisible: nothing fails if someone re-adds it, and the
     * discovery above would go quietly back to skipping the two components with the
     * largest CSS blocks in the file. Naming them here means a re-exemption has to
     * argue with a test rather than with a comment.
     */
    test('chrome joins the boundary rule like any other v2 component', () => {
        expect(v2Components).toContain('nav');
        expect(v2Components).toContain('footer');
    });

    /** Section joined at #1023, the first v2 component carrying authored rich text. */
    test('section joins the boundary rule', () => {
        expect(v2Components).toContain('section');
    });

    /**
     * cta joined at #1026. Added in that change's own review, which found it MISSING: every
     * prior rebuild had left a one-line pin here and cta's had not been written, so the
     * component with the largest slot retirement to date (40 slots, 798 stylesheet lines down
     * to 158) was the one component whose structural-CSS boundary nothing enforced.
     */
    test('cta joins the boundary rule', () => {
        expect(v2Components).toContain('cta');
    });

    // STRUCTURE, not design. Layout scaffolding (how boxes relate), wrapper
    // geometry (how wide the column is), and the resets a component needs so a
    // role's value lands predictably.
    const STRUCTURAL = new Set([
        'display', 'position', 'top', 'right', 'bottom', 'left', 'z-index',
        'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
        'align-items', 'align-self', 'align-content', 'justify-content', 'justify-items', 'justify-self',
        'grid-template-columns', 'grid-template-rows', 'grid-template-areas',
        'grid-column', 'grid-row', 'grid-area', 'grid-auto-flow', 'grid-auto-rows', 'grid-auto-columns',
        'max-width', 'min-width', 'width', 'max-height', 'min-height', 'height',
        'margin', 'margin-left', 'margin-right', 'margin-inline', 'margin-block',
        'overflow', 'overflow-x', 'overflow-y', 'object-fit',
        'box-sizing', 'list-style', 'border-left', 'border-right', 'border-top', 'border-bottom',
        'padding', 'content', 'visibility', 'pointer-events', 'order', 'isolation',
        // Accessibility affordances.
        'scroll-margin-top', 'outline', 'outline-offset', 'clip', 'clip-path', 'white-space',
        // `overflow-wrap` sits beside `white-space` for the same reason and was found by
        // the fail-closed arm at #1023, working exactly as intended. It is the affordance
        // that lets a long unbroken token fit its track instead of overflowing it — the
        // repo already relies on it globally in base.css, and CSS grid needs BOTH
        // minmax(0,..) on the track and this on the text for content to stay inside.
        // There is no UDC group for it and there should not be: an author choosing
        // "let long words overflow my layout" is not a design decision anyone wants.
        'overflow-wrap',
        // THE THREE PROPERTIES CHROME'S RETIREMENT FOUND HOMELESS (#994, rulings
        // H1/H2/H3). Same discipline as `aspect-ratio` above and the opposite
        // outcome: these three have no place in the UDC taxonomy and should not get
        // one, so the boundary claims them rather than leaving them in neither set,
        // where the fail-closed arm below would make them unauthorable AND
        // un-keepable — a capability deletion.
        //
        // `cursor` — an interaction affordance, the tier `outline` and
        // `pointer-events` already belong to. There is no UDC group for it and one
        // property on two buttons does not justify opening ruling A3's taxonomy.
        //
        // `transition` / `transition-property` — the motion group carries
        // `transition-duration` and `timing-function` and deliberately NOT the
        // property list (ruling A3, "exactly two params"). lib/udc.php states the
        // arrangement this classification completes: a role whose STRUCTURAL css
        // sets the `transition` shorthand keeps its property LIST while an authored
        // `motion.transition-duration` overrides the timing. Moving the shorthand
        // into a role would silently widen the animated list from `color` to CSS's
        // initial `all` — a behaviour change wearing a migration's clothes.
        //
        // `transform` — nav's open chevron rotation. Its selector needs a child
        // combinator AND an ancestor state (`.is-open` on a forebear); the UDC has
        // no ancestor-state dimension (deliberately, see pp_udc_states()), so no
        // role could express it at any value.
        'cursor', 'transition', 'transition-property', 'transform',
        // `animation` — faq's 150ms disclosure reveal (#1046). Same discipline as the
        // three above and the same outcome: the motion group carries two params by
        // ruling A3 and an animation NAME is neither, a keyframe body has no role
        // address at all (see isKeyframeBody below), and base.css already collapses
        // animation durations globally under `prefers-reduced-motion` — so the
        // accessibility case is covered without a per-component control. Leaving it
        // unclassified would have made faq's reveal un-keepable AND un-authorable,
        // because the unlisted-property arm below is fail-closed.
        'animation',
        // ── THE FIVE TABLE-MARKUP PROPERTIES (#1066) ────────────────────────────
        //
        // Same discipline as `cursor`/`transition`/`transform` (#994), `overflow-wrap`
        // (#1023) and `animation` (#1046), and found the same way: `table` is the first
        // v2 component with TABLE markup, so it is the first to reach any of these. All
        // five were in NO set, and the unlisted-property arm below is fail-closed — so
        // until they are classified, table's structural CSS can be neither kept here nor
        // authored through a role. That is a capability deletion, which is the #901 class.
        //
        // `border-collapse` — selects the table's border BOX MODEL (separate or collapsed
        // edges). It sets no border VALUE and no group emits it; the analogy is
        // `box-sizing`, already structural here. Note it is not inert: the collapsed model
        // is what makes `row`'s separator and `header`'s bottom rule share one edge, which
        // is why the schema documents the CSS 2.1 17.6.2 conflict order rather than the
        // cascade for those two roles.
        //
        // `caption-side` — WHERE the caption box sits, top or bottom. Placement, the
        // `position`/`order` tier, not a value an author retunes for design.
        //
        // `overscroll-behavior-x` and `-webkit-overflow-scrolling` — scroll affordances,
        // siblings of `overflow-x` which is already structural three lines up. They are
        // what make `.table-wrap` a contained scroller instead of a rubber-banding one
        // that steals the page's horizontal gesture.
        //
        // `vertical-align` — THE ONE JUDGMENT CALL HERE, said plainly rather than filed
        // under the other four. It could be read as design, because `text-align` is in
        // ALWAYS_DESIGN below. The distinction taken: `text-align` is design precisely
        // BECAUSE `typography.align` exists to carry it, and no group emits this one; and
        // what it does — align a cell's content against its SIBLING cells in the same row
        // — is the `align-items` tier rather than the type tier. If a later ruling gives
        // the sizing group a `vertical-align` param, this moves to ALWAYS_DESIGN in the
        // SAME commit, per the one-home rule stated on `object-position` below.
        'border-collapse', 'caption-side', 'vertical-align',
        'overscroll-behavior-x', '-webkit-overflow-scrolling',
    ]);

    // ── THE LAYOUT GROUP'S FIVE, AND THE ONE-HOME AMENDMENT (#1084) ──────────
    //
    // These five are in STRUCTURAL above AND owned by the `layout` registry group
    // (lib/udc.php). That is the ONLY place in this file where a property has two
    // homes, the one-home rule stated on `object-position` and `aspect-ratio` says
    // never two, and this is the argument for the exception rather than a note that
    // one was taken.
    //
    // WHAT THE ONE-HOME RULE IS FOR. Read its own words at the top of this
    // describe: "a value in CSS that no role owns is a value no author can reach
    // and no envelope can report on — which is exactly the #901 defect." The rule
    // protects REACHABILITY. A structural rule for a property the registry OWNS is
    // reachable by definition: an authored band block is unlayered, this stylesheet
    // is in `@layer pp-v1`, so the author's value beats it at any specificity, in
    // any variant, at any tier. The harm the rule names cannot occur here.
    //
    // WHY MOVING THEM INSTEAD WOULD DELETE A CAPABILITY. ~35 of the shipped
    // declarations of these five are variant- or attribute-scoped mechanism —
    // `.cta--inline .cta__inner`, `.hero--split[data-pp-split-ratio="60-40"]`,
    // `.section--text-panel .section__grid`, `.testimonials--stack`. A role's
    // `defaults` carry a breakpoint dimension and a state dimension and NO variant
    // dimension, so none of those rules has a role address at any value. Worse, a
    // default emits UNLAYERED: moving `.cta__inner { flex-direction: column }` into
    // a default would not replace the variant rule, it would OUTRANK it, and
    // `.cta--inline` would silently stop being a row. That is ruling D5's collision
    // arriving from the other side.
    //
    // THE EXCEPTION CARRIES ITS OWN CONDITION so it cannot be copied for
    // convenience. A property may hold both homes only when BOTH hold:
    //   (1) the registry owns it — so every occurrence here is overridable by an
    //       authored value; and
    //   (2) its group declares NO defaults for it, which makes this stylesheet the
    //       only possible home for the UNAUTHORED behaviour. Deleting a rule here
    //       would then delete behaviour rather than move it.
    //
    // Condition 2 is what does the work, and it is asserted rather than described —
    // by the registry-derived test in tests/UdcLayoutGroupTest.php, which walks
    // every component's role defaults for these five PROPERTIES (not parameter
    // names: `typography.align` emits `text-align` and legitimately defaults on
    // `logos.label`). The moment the Layout group takes a default, its properties
    // stop satisfying the condition and belong in ALWAYS_DESIGN like everything
    // else. An earlier draft of this comment claimed condition 2 was "every
    // occurrence is variant-conditioned"; that is FALSE and the file disproves it —
    // `.hero__cta-group { flex-wrap: wrap }` and `.cta__buttons { justify-content:
    // flex-start }` are unconditioned base values, and they stay here precisely
    // BECAUSE no default may hold them.
    //
    // A property in a group that DOES default still has exactly one home. Nothing
    // about the ordinary boundary case changes.
    //
    // NOT ON THIS LIST, DELIBERATELY: `gap`/`row-gap`/`column-gap` stay in
    // ALWAYS_DESIGN. The `spacing` group has owned them since Sprint 0, a
    // stylesheet gap is still split authority, and the coverage table's "Layout:
    // …gap…" is already satisfied there. `display` is not on it either: no group
    // emits it, and the `display: grid` the engine pairs with an authored
    // `layout.columns` is an engine companion, not a parameter.
    // SIX, not five: `align-self` is owned by `sizing` rather than by `layout`
    // (a box placing ITSELF is the sizing group's subject), and it is in exactly the
    // same position — STRUCTURAL here, registry-owned there, defaulted nowhere.
    // Leaving it off this list would have let a `sizing.align-self` default slip past
    // the condition and outrank `.hero--centered .hero__eyebrow { align-self: center }`.
    const REGISTRY_OWNED_STRUCTURAL = new Set([
        'grid-template-columns', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items',
        'align-self',
    ]);

    // Properties that are NEVER structural, whatever value they carry. A
    // structural property with a designable value (`margin: 0 auto` vs
    // `margin-bottom: 2rem`) is caught by the value check below instead.
    const ALWAYS_DESIGN = new Set([
        'color', 'background', 'background-color', 'background-image', 'box-shadow',
        'font-size', 'font-family', 'font-weight', 'font-style', 'line-height',
        'letter-spacing', 'text-transform', 'text-decoration', 'text-decoration-line',
        'border-radius', 'border-width', 'border-color', 'border', 'gap', 'row-gap', 'column-gap',
        'opacity', 'text-align',
        // object-position joined the engine as `sizing.object-position` (#1023), found
        // by section's rebuild exactly as `aspect-ratio` was found by hero's:
        // `--section-image-position` set it on the content image, no group emitted it,
        // and the fail-closed arm below meant a component declaring roles could neither
        // author it nor keep it here.
        //
        // IT MOVES OUT OF `STRUCTURAL` IN THE SAME COMMIT AS THE PARAM, for the reason
        // the aspect-ratio note above states: the property has exactly one home at
        // every commit — never zero, and never two. It sat in both for the length of
        // one commit on the #1023 branch, which is how the rule got tested.
        'object-position',
        // aspect-ratio joined the engine as `sizing.aspect-ratio` (ruling D1, #986),
        // so it is designable and belongs to a role, never to this stylesheet.
        //
        // IT WAS IN NEITHER SET BEFORE, WHICH IS THE WHOLE POINT. The unlisted-property
        // arm below is fail-closed ("unrecognised property — classify it"), so while
        // `aspect-ratio` had no engine param it had no legal home at all: a component
        // declaring roles could neither author it nor keep it here. Classifying it
        // lands in the SAME commit as the param, so the property has exactly one home
        // at every commit — never zero, and never two.
        //
        // Legacy components are untouched: this set is scoped to the v2 boundary rule,
        // and section/grid keep their own `aspect-ratio` rules until their rebuild.
        'aspect-ratio',
    ]);

    // The box-spacing family is judged BY VALUE, not by name. `margin: 0 auto` centres a
    // wrapper and `margin-top: 0` resets a UA default — both geometry. `margin-bottom:
    // 2rem` is rhythm an author should own. The same declaration name is structural or
    // designable depending on what it says, which is why it cannot live in either set.
    const SPACING_FAMILY = new Set([
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'margin-inline', 'margin-block',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    ]);
    // A NEGATIVE SPACING VALUE IS MECHANISM, NOT DESIGN (#1023).
    //
    // `0` and `auto` were the whole admitted set, on the principle that any real
    // length in a padding or margin is separation — and separation is what the
    // `spacing` group owns. A NEGATIVE value cannot be separation: there is no
    // design idea "less than touching". It can only pull a box out of its own flow
    // line, which is the same category as `position`/`inset`, already structural
    // here. So it is admitted on that argument rather than on convenience — the
    // same shape as #994 claiming `cursor`, `transition` and `transform` into
    // STRUCTURAL with a stated reason instead of leaving them homeless.
    //
    // THE MOTIVATING CASE is the #489 hanging-separator clip:
    //   .section__inline-item { margin-left: calc(-1 * (var(--space-sm) + var(--space-xs))) }
    // Each item is pulled left by exactly the separator's occupied width, so a
    // line-leading separator lands outside the row's content box and is clipped by
    // `overflow: hidden`. Delete the pull and a middot dangles at the start of every
    // wrapped line. It is not a value an author would ever want to retune; it is the
    // mechanism's arithmetic.
    //
    // THE ADMISSION IS SIGNED, NOT ABSOLUTE. A positive length is still an offence,
    // pinned directly below, so this cannot be read as "spacing is allowed after all".
    const ZERO_OR_AUTO = /^(0|auto)(\s+(0|auto))*$/;
    // Negative, by the argument above: an explicit leading `-`, or a calc() whose
    // result is negated (`calc(-1 * ...)`, `calc(-1 * (...))`). A calc() that merely
    // CONTAINS a minus — `calc(100% - 1rem)` — is positive and stays an offence.
    // Tightened in review. `calc(\s*-` admitted ANY calc() whose text merely STARTS with a
// minus, so `calc(-0px + var(--space-lg))` and `calc(-1px + 3rem)` were waved through as
// "geometry" while computing to real positive separation. The rationale above is
// specifically that a NEGATIVE value cannot be separation — it can only pull a box out of
// its own flow line — and a calc() that opens negative and adds its way positive is not
// that. The negating form is what the argument covers.
const NEGATIVE_PULL = /^(-[\d.]|calc\(\s*-\s*[\d.]+\s*\*)/;
    // A whole-value test FIRST, because a calc() carries spaces of its own and must
    // not be split into meaningless parts. Only a space-separated shorthand of simple
    // parts falls through to the per-part pass.
    const GEOMETRY_ONLY = (value) => {
        const v = value.trim();
        if (ZERO_OR_AUTO.test(v) || NEGATIVE_PULL.test(v)) return true;
        if (v.includes('(')) return false; // any other function value is a real length
        return v.split(/\s+/).every(part => part === '0' || part === 'auto' || NEGATIVE_PULL.test(part));
    };

    // The component's OWN banner block, sliced the way the PHP contract test does it.
    // rulesMatching('.testimonials') is the wrong tool here: it is a class-BOUNDARY
    // match, so it sees `.testimonials` and never `.testimonials__quote` — the rule
    // would pass while every sub-element declaration went unread. (Caught by planting
    // a `font-size` on `.testimonials__quote p` and watching the rule stay green.)
    const componentBlock = (component) => {
        // Slice the RAW css: the banner that delimits a block is itself a comment, so
        // stripping comments first erases the very markers the slice needs. Strip
        // afterwards, on the slice.
        const css = COMPONENTS_CSS;
        const start = css.indexOf(`COMPONENT: ${component}`);
        if (start === -1) return '';
        const bodyStart = css.indexOf('*/', start);
        const next = css.indexOf('/* =====', bodyStart);
        return stripComments(css.slice(bodyStart + 2, next === -1 ? undefined : next));
    };

    // A v2 component may legitimately reach ZERO structural rules, and embed is the first
    // one that has (#1066): every rule it had was a value a role owns now, and it has no
    // layout to scaffold and no affordance of its own. That is the end state this whole
    // boundary is aiming at, so the rule has to be able to express it — but it must not
    // become an escape hatch, and it must not let a BROKEN SLICER look like compliance
    // (which is the entire reason the floor below exists). So: the rule-free components
    // are named here, and a named one is asserted to have EXACTLY zero rules while every
    // other v2 component keeps the original floor. A rule creeping back into embed's block
    // fails; a slicer that stops finding any block fails for every other component.
    const INTENTIONALLY_RULE_FREE = ['embed'];

    v2Components.forEach(component => {
        test(`${component}'s CSS block declares only structure`, () => {
            const block = componentBlock(component);
            expect(block, `no CSS banner block found for ${component}`).not.toBe('');
            const rules = parseRules(block);
            if (INTENTIONALLY_RULE_FREE.includes(component)) {
                expect(
                    rules,
                    `${component}'s block is recorded as rule-free: every value it had belongs ` +
                    'to a role. A rule here means either a designable value came back or a ' +
                    'structural need appeared that the schema does not record.'
                ).toEqual([]);
                return;
            }
            // Floor: the slice must actually contain the sub-element rules, or every
            // check below reads an empty list and passes for the wrong reason.
            //
            // RECORDED PER COMPONENT SINCE #1066 PR2, because a single `> 5` encoded an
            // assumption the rebuilds keep falsifying: that a v2 block always has several
            // structural rules left. stats keeps TWO (the band rule went entirely — its
            // padding, background, max-width, auto margins and radius are all `_band`
            // parameters now) and logos keeps FOUR. Under the old floor both would have
            // failed for being CORRECT, and the tempting fix — lowering the number until
            // the suite goes green — would have retired the anti-vacuity guard for every
            // component at once.
            //
            // So the floor is now each component's own shipped count, and it is an EXACT
            // match rather than a minimum: a block that shrinks has had a rule deleted and
            // a block that grows has had one added, and both are decisions that belong in
            // a diff someone reads. The `__` sub-element assertion below is the other half
            // — it proves the slicer reached real content rather than a banner comment,
            // which is the failure the original floor was actually written against.
            const STRUCTURAL_RULE_COUNT = {
                hero: 35, section: 13, faq: 11, table: 6, cta: 9,
                nav: 18, footer: 16, testimonials: 10,
                stats: 2, logos: 4,
            };
            const expected = STRUCTURAL_RULE_COUNT[component];
            expect(
                expected,
                `${component} is on the UDC but has no recorded structural-rule count. Add it to ` +
                'STRUCTURAL_RULE_COUNT with the number it actually ships, so a later shrink or ' +
                'growth is reviewed rather than silent.'
            ).toBeDefined();
            expect(
                rules.length,
                `${component}'s structural block has ${rules.length} rules, recorded as ${expected}. ` +
                'If a rule was deliberately added or removed, update the recorded count in the same ' +
                'commit so the change is reviewed; if it was not, a designable value has moved into ' +
                'or out of the stylesheet.'
            ).toBe(expected);
            expect(
                rules.some(r => r.selectors.some(sel => sel.includes('__'))),
                'the slice must reach the component\'s sub-element rules'
            ).toBe(true);

            const offences = designOffencesIn(rules);
            expect(
                offences,
                `${component} is on the Universal Design Contract, so every designable value belongs to a role in ` +
                `components/${component}/schema.json — not to assets/css/components.css. Offending declarations:\n  ` +
                offences.join('\n  ')
            ).toEqual([]);
        });
    });

    /**
     * THE AMENDMENT'S OWN GUARDS (#1084). Three claims the comment above makes
     * that a reader should not have to take on trust.
     */
    test('the registry-owned five keep exactly one classification here, and gap is not among them', () => {
        REGISTRY_OWNED_STRUCTURAL.forEach(prop => {
            expect(STRUCTURAL.has(prop), `${prop} must stay STRUCTURAL: the stylesheet holds the unauthored behaviour`).toBe(true);
            expect(ALWAYS_DESIGN.has(prop), `${prop} must not be in ALWAYS_DESIGN too — that is two homes in ONE set`).toBe(false);
        });
        // The line the amendment must not be read as crossing: `spacing` owns the
        // gap family, so a gap in the stylesheet is still split authority.
        ['gap', 'row-gap', 'column-gap'].forEach(prop => {
            expect(ALWAYS_DESIGN.has(prop), `${prop} belongs to the spacing group, not to this stylesheet`).toBe(true);
            expect(REGISTRY_OWNED_STRUCTURAL.has(prop), `${prop} is not part of the Layout amendment`).toBe(false);
        });
        // `display` is an engine companion, never a parameter, so it is plain
        // structure with no amendment attached.
        expect(STRUCTURAL.has('display')).toBe(true);
        expect(REGISTRY_OWNED_STRUCTURAL.has('display')).toBe(false);
    });

    /**
     * REGRESSION PIN (#1084 amends this file; #986 wrote the rule it must not
     * widen). The layout-modifier carve-out admits `text-align` on a `--variant`
     * selector and NOTHING else. An amendment that accidentally generalised it to
     * "layout properties on any selector" would silently reopen the split
     * authority the whole boundary exists to close.
     */
    test('regression: the text-align carve-out stayed exactly as narrow as #986 left it', () => {
        // Still exempt: text-align on a pure modifier rule.
        expect(designOffencesIn(parseRules('.hero--centered .hero__inner { text-align: center }'))).toEqual([]);
        // Still an offence: the same property on a ROLE selector.
        expect(designOffencesIn(parseRules('.hero__title { text-align: center }'))).not.toEqual([]);
        // Still an offence: a designable value riding a modifier selector.
        expect(designOffencesIn(parseRules('.hero--centered .hero__title { font-size: 2rem }'))).not.toEqual([]);
        // And the carve-out did NOT generalise to the five: a designable family is
        // still caught on a modifier selector.
        expect(designOffencesIn(parseRules('.cta--inline .cta__inner { gap: 2rem }'))).not.toEqual([]);
    });

    /**
     * DETECTION PROOF. A boundary rule that never fires is indistinguishable from
     * one that cannot fire, and this one guards a stylesheet that is currently
     * clean — so its power has to be proven against planted violations rather
     * than inferred from a green run. One per designable family the boundary
     * exists to keep out.
     */
    test('detection proof: each designable family is caught', () => {
        const cases = {
            'typography': 'font-size: 0.9rem',
            'colour':     'color: #333333',
            'spacing':    'gap: 1rem',
            'rhythm':     'margin-bottom: 2rem',
            'border':     'border-radius: 8px',
            'shadow':     'box-shadow: 0 2px 4px #0001',
            'background': 'background: #ffffff',
            'custom property': '--testimonials-quote-color: #111111',
            // Ruling D1 (#986): the family that had no home until `sizing.aspect-ratio`
            // shipped. Planted here so the boundary's power over it is proven rather
            // than inferred — the same discipline as every other family above.
            'aspect ratio': 'aspect-ratio: 16 / 9',
            // #1023: the param section's rebuild added. Planted for the same reason,
            // and because this property spent one commit in BOTH sets.
            'object position': 'object-position: top center',
        };
        // A four-selector rule entirely inside ONE component must NOT be exempt.
        const wide = parseRules(
            '.testimonials__quote, .testimonials__author, .testimonials__meta, .testimonials__eyebrow { color: #333; }'
        );
        expect(
            designOffencesIn(wide),
            'a designable value hidden in a 4-selector single-component rule must still be caught'
        ).not.toEqual([]);
        Object.entries(cases).forEach(([family, declaration]) => {
            const planted = parseRules(`.testimonials__quote p { margin: 0; ${declaration}; }`);
            expect(
                designOffencesIn(planted),
                `a planted ${family} declaration (${declaration}) must be caught`
            ).not.toEqual([]);
        });
    });

    /**
     * THE #1046 CARVE-OUTS, PROVEN IN BOTH DIRECTIONS.
     *
     * Two things were admitted for faq's accordion: `animation` into STRUCTURAL, and a
     * keyframe BODY out of the sweep entirely. An admission nobody can see the edge of
     * is indistinguishable from a removed rule, so both edges are pinned here.
     */
    test('faq joins the boundary rule', () => {
        expect(v2Components).toContain('faq');
    });

    test('a keyframe body is exempt; the same declarations outside one are not', () => {
        // The real shape, as it ships.
        const real = parseRules(
            '@keyframes faq-open { from { opacity: 0; transform: translateY(-4px) } to { opacity: 1 } }'
        );
        expect(
            designOffencesIn(real),
            'a keyframe body has no role address at any value and must not be flagged'
        ).toEqual([]);

        // THE EDGE: the identical declarations one level out are still design.
        const outside = parseRules('.faq__answer { opacity: 0; transform: translateY(-4px); }');
        expect(
            designOffencesIn(outside),
            'the exemption must be the keyframe body, not the properties'
        ).not.toEqual([]);

        // AND THE EDGE THE NARROWING EXISTS FOR: a real selector nested inside a
        // keyframes block is not a keyframe body and must still be judged in full.
        // (No browser honours this, which is precisely why it would be a quiet place
        // to park a value if the exemption keyed on the at-rule alone.)
        const smuggled = parseRules('@keyframes x { .faq__answer { color: #333 } }');
        expect(
            designOffencesIn(smuggled),
            'only from/to/percentage selectors are keyframe bodies'
        ).not.toEqual([]);
    });

    test('animation is structural; the values it animates are still judged', () => {
        const anim = parseRules('.faq__item[open] > .faq__answer { animation: faq-open 150ms ease; }');
        expect(designOffencesIn(anim)).toEqual([]);

        // It buys `animation` and nothing adjacent: a font-size beside it still fails,
        // so this is a classification rather than a rule-level bypass.
        const beside = parseRules('.faq__item[open] > .faq__answer { animation: faq-open 150ms ease; font-size: 2rem; }');
        expect(designOffencesIn(beside)).not.toEqual([]);
    });

    /**
     * THE #1066 CARVE-OUTS, PROVEN IN BOTH DIRECTIONS.
     *
     * table is the first v2 component with TABLE markup, so it is the first to reach any
     * of five properties that were in NO set. The unlisted-property arm is fail-closed,
     * which is what makes an unclassified property a capability DELETION rather than a
     * missing convenience — it can be neither kept here nor authored through a role.
     *
     * An admission nobody can see the edge of is indistinguishable from a removed rule
     * (the #1046 lesson), so this pins the admission AND its edge.
     */
    test('table joins the boundary rule', () => {
        expect(v2Components).toContain('table');
    });

    test('embed joins the boundary rule, with an empty block', () => {
        expect(v2Components).toContain('embed');
        // BOTH HALVES. Membership alone would pass if the banner were deleted outright,
        // and emptiness alone would pass if the component fell out of discovery.
        expect(componentBlock('embed')).not.toBe('');
        expect(parseRules(componentBlock('embed'))).toEqual([]);
    });

    test('the five table-markup properties are structural, and buy nothing adjacent', () => {
        // The real shapes, as they ship in the `COMPONENT: table` block.
        const real = parseRules(
            '.table { border-collapse: collapse; }' +
            '.table__caption { caption-side: bottom; }' +
            '.table__cell { vertical-align: top; }' +
            '.table-wrap { overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; }'
        );
        expect(
            designOffencesIn(real),
            'table\'s five structural table-markup properties must be admitted'
        ).toEqual([]);

        // THE EDGE, one per property: the classification buys that property and nothing
        // beside it. A rule-level bypass would pass all five of these.
        const beside = {
            'border-collapse': '.table { border-collapse: collapse; background: #fff; }',
            'caption-side':    '.table__caption { caption-side: bottom; color: #333; }',
            'vertical-align':  '.table__cell { vertical-align: top; font-size: 0.9rem; }',
            'overscroll':      '.table-wrap { overscroll-behavior-x: contain; border-radius: 8px; }',
            'webkit-scroll':   '.table-wrap { -webkit-overflow-scrolling: touch; box-shadow: 0 2px 4px #0001; }',
        };
        Object.entries(beside).forEach(([name, css]) => {
            expect(
                designOffencesIn(parseRules(css)),
                `${name} must be a classification, not a rule-level bypass`
            ).not.toEqual([]);
        });
    });

    test('the fail-closed arm still catches a property in no set', () => {
        // THE POINT OF THE WHOLE EXERCISE. Classifying five properties must not be
        // mistaken for relaxing the arm that FOUND them — the next v2 component with
        // unfamiliar markup has to hit the same wall table did.
        const unknown = parseRules('.table__cell { text-orientation: upright; }');
        const offences = designOffencesIn(unknown);
        expect(offences, 'an unclassified property must still be reported').not.toEqual([]);
        expect(
            offences.join('\n'),
            'and it must say what to do about it'
        ).toContain('classify it');
    });

    /** …and the structural declarations it must NOT flag. */
    test('detection proof: real structural declarations are not flagged', () => {
        const structural = parseRules(
            '.testimonials__list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(20rem, 100%), 1fr)); }' +
            '.testimonials__item { display: flex; flex-direction: column; margin: 0; }' +
            '.testimonials--stack .testimonials__list { max-width: 42rem; margin-left: auto; margin-right: auto; }' +
            '.testimonials__avatar { object-fit: cover; flex-shrink: 0; }' +
            '.testimonials__subheading { margin-top: 0; }'
        );
        expect(designOffencesIn(structural)).toEqual([]);
    });

    /**
     * THE NEGATIVE-SPACING ADMISSION IS SIGNED, NOT ABSOLUTE (#1023).
     *
     * GEOMETRY_ONLY admits a negative margin because a negative margin cannot
     * express separation — it can only pull a box out of its flow line. That
     * argument says nothing about POSITIVE spacing, and an admission nobody can
     * see the edge of is indistinguishable from a removed rule. So both sides are
     * pinned here: the #489 clip that motivated it passes, and every positive
     * spelling of the same property still fails.
     */
    test('negative spacing is admitted; positive spacing still is not', () => {
        const pull = parseRules(
            '.section__inline-item { margin-left: calc(-1 * (var(--space-sm) + var(--space-xs))); }'
        );
        expect(designOffencesIn(pull)).toEqual([]);

        const centred = parseRules('.section__inline-items { margin: 0 auto; }');
        expect(designOffencesIn(centred)).toEqual([]);

        [
            // A calc() that merely OPENS with a minus and adds its way positive is real
            // separation, not a pull, and must still fail. The first cut of NEGATIVE_PULL
            // matched `calc(\s*-` and waved both of these through (review finding).
            '.section__inline-items { margin-top: calc(-0px + var(--space-lg)); }',
            '.section__panel { padding: calc(-1px + 3rem); }',
            '.section__inline-items { margin-top: var(--space-md); }',
            '.section__panel { padding: var(--space-lg); }',
            '.section__content { margin: 0 0 1rem; }',
            '.section__body { padding-left: calc(100% - 1rem); }',
        ].forEach(css => {
            expect(
                designOffencesIn(parseRules(css)),
                `a positive spacing value must still be an offence: ${css}`
            ).not.toEqual([]);
        });
    });

    // A KEYFRAME BODY HAS NO ROLE ADDRESS AT ANY VALUE (#1046).
    //
    // `@keyframes faq-open` is the first keyframes block inside a v2 component's slice,
    // and it exposed a gap the property Sets cannot close. parseRules emits `from` and
    // `to` as ordinary rules whose bodies carry `opacity` and `transform`; `opacity` is
    // ALWAYS_DESIGN, so the boundary flagged them — correctly by the letter of the rule
    // and wrongly by its purpose. The purpose is that every designable value must be
    // AUTHORABLE through a role, and a keyframe body cannot be: ruling A3 gives the
    // motion group exactly two params (`transition-duration`, `timing-function`) and an
    // animation's keyframes are neither. Moving `opacity` out of ALWAYS_DESIGN would
    // hole the boundary for every v2 component; leaving the block flagged would make the
    // 150ms reveal unkeepable AND unauthorable, which is the capability deletion the
    // #901 class names.
    //
    // So the boundary claims it, the same way #994 claimed `cursor`, `transition` and
    // `transform`, and #1046 claims `animation` beside them in STRUCTURAL.
    //
    // THE EXEMPTION IS AS NARROW AS THE CASE. Both conditions must hold: the rule sits
    // inside a `@keyframes` at-rule, AND every one of its selectors is a keyframe
    // SELECTOR (`from`, `to`, or a percentage). A rule that merely appears after a
    // keyframes block, or a real class selector nested inside one, is still judged in
    // full — so this cannot become a place to park design values.
    const KEYFRAME_SELECTOR = /^(from|to|\d+(?:\.\d+)?%)$/;
    const isKeyframeBody = (rule) =>
        (rule.atRules || []).includes('keyframes')
        && rule.selectors.every(sel => KEYFRAME_SELECTOR.test(sel.trim()));

    function designOffencesIn(rules) {
        const offences = [];
        rules.forEach(rule => {
                if (isKeyframeBody(rule)) return;
                // The shared scroll-margin list names many components at once; it is
                // an accessibility affordance and belongs to no single one. Scoped to
                // that ACTUAL case — selectors spanning more than one component — so
                // it cannot become a blanket bypass: a designable declaration hidden
                // in a four-selector rule entirely inside one v2 component was
                // previously never reported.
                const blocks = new Set(
                    rule.selectors
                        .map(sel => (sel.match(/\.([A-Za-z][\w-]*)/) || [])[1])
                        .filter(Boolean)
                        .map(cls => cls.split(/__|--/)[0])
                );
                if (rule.selectors.length > 3 && blocks.size > 1) return;

                rule.body.split(';').forEach(decl => {
                    const [rawProp, ...rest] = decl.split(':');
                    const prop = (rawProp || '').trim();
                    const value = rest.join(':').trim();
                    if (!prop || !value) return;
                    if (prop.startsWith('--')) {
                        offences.push(`${rule.selectors.join(', ')} { ${prop}: ${value} }  (a v2 component declares no custom properties in CSS)`);
                        return;
                    }
                    // TEXT ALIGNMENT DRIVEN BY A LAYOUT MODIFIER IS GEOMETRY (#986).
                    //
                    // `text-align` is value styling when a role declares it — that is
                    // why it is in ALWAYS_DESIGN and why `typography.align` exists. It
                    // is NOT value styling when a LAYOUT VARIANT declares it: "centered"
                    // means the content is centred, and a layout that centres the boxes
                    // (align-items, justify-content — both already STRUCTURAL here) while
                    // leaving the text ragged-left is not the layout it advertises. The
                    // modifier owns the arrangement; this is part of the arrangement.
                    //
                    // Deliberately narrow, so it cannot become a bypass: EVERY selector
                    // in the rule must target a `--variant` modifier class. A role
                    // selector, a bare block selector, or a mixed rule is still an
                    // offence. An authored `typography.align` still overrides it — the
                    // authored tier is unlayered and the stylesheet is in `pp-v1`.
                    const everySelectorIsLayoutModifier = rule.selectors.every(
                        sel => /\.[A-Za-z][\w-]*--[\w-]+/.test(sel),
                    );
                    if (prop === 'text-align' && everySelectorIsLayoutModifier) {
                        return;
                    }
                    if (ALWAYS_DESIGN.has(prop)) {
                        offences.push(`${rule.selectors.join(', ')} { ${prop}: ${value} }`);
                        return;
                    }
                    if (!STRUCTURAL.has(prop) && !SPACING_FAMILY.has(prop)) {
                        offences.push(`${rule.selectors.join(', ')} { ${prop}: ${value} }  (unrecognised property — classify it)`);
                        return;
                    }
                    if (SPACING_FAMILY.has(prop) && !GEOMETRY_ONLY(value)) {
                        offences.push(`${rule.selectors.join(', ')} { ${prop}: ${value} }  (a non-zero ${prop} is spacing, not geometry)`);
                    }
                });
        });
        return offences;
    }
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

    // THE STYLESHEET ARM IS EMPTY SINCE #1066 PR2, AND THAT IS ASSERTED RATHER THAN
    // LEFT TO PASS VACUOUSLY. `.stats__heading` was this scan's non-vacuity anchor and
    // its only member; stats' rebuild moved the cap, the centring and the auto margins
    // into the `heading` role's defaults, so components.css now declares no centred,
    // capped content block at all. An empty `centeredCapped` makes the offender filter
    // below pass on nothing, which is exactly the failure the old anchor existed to
    // prevent - so the emptiness is pinned as a FACT here, and the claim itself moves to
    // the role arm below rather than being deleted with its last stylesheet member.
    test('no centered, capped content block is left in the stylesheet', () => {
        expect(centeredCapped.map(([sel]) => sel)).toEqual([]);
    });

    // THE OFFENDER SCAN THAT USED TO SIT HERE IS RETIRED (#1066 PR2 review), and the
    // reason is the pairing, not the emptiness. It filtered `centeredCapped` for members
    // lacking an auto margin — over a list the test directly above asserts is EMPTY, so it
    // could never fail. Worse, the two INVERT each other: the day someone adds a CORRECT
    // centred, capped block to the stylesheet (one that does declare its auto margins),
    // this scan would still pass and the emptiness assertion above would go red, reporting
    // a correct block as a defect.
    //
    // Keeping a guard that cannot fail beside one that fails on the right answer is worse
    // than keeping neither. The emptiness pin above stays, because "components.css
    // declares no centred, capped block" is a real fact worth knowing when it changes. The
    // #367 CLAIM — that such a block needs its auto margins — moves to the role arm below,
    // which is where every such block now lives and which is derived rather than listed.

    // ── THE #367 CLAIM, IN THE VOCABULARY IT LIVES IN NOW ────────────────────────
    //
    // The defect #367 recorded is not about a stylesheet: a block with a max-width cap
    // inside a wider container does NOT centre just because its text is centred - the cap
    // pins the box to the container's left edge and `text-align: center` only centres
    // WITHIN that left-pinned box. The auto side margins are what deliver the centring,
    // and they are the half a reader forgets because the text already looks centred.
    //
    // That is now a claim about ROLE DEFAULTS, so it is asserted against the schemas. Any
    // role that defaults BOTH a `sizing.max-width` and `typography.align: center` must
    // also default the two auto margins, or it ships the #367 defect at the one tier where
    // the cap actually binds. Derived from the schemas rather than listed, so a role added
    // in a later rebuild is covered the moment it lands.
    //
    // MEASURED, so this is not a rule invented from the declaration: stats' heading box at
    // 1280 is x=320 w=640 inside a container at x=64 w=1152 - both centred on 640 - and
    // `margin-left` COMPUTES to 224px there and 0 at 375. Porting the computed number
    // instead of `auto` would have frozen the centring at one viewport.
    const v2Schemas = fs.readdirSync(path.resolve(__dirname, '../../components'), { withFileTypes: true })
        .filter(d => d.isDirectory())
        .map(d => d.name)
        .map(name => {
            const file = path.join(path.resolve(__dirname, '../../components'), name, 'schema.json');
            if (!fs.existsSync(file)) return null;
            const schema = JSON.parse(fs.readFileSync(file, 'utf-8'));
            return schema.roles ? { name, roles: schema.roles } : null;
        })
        .filter(Boolean);

    const cappedCentredRoles = v2Schemas.flatMap(({ name, roles }) =>
        Object.entries(roles)
            .filter(([, role]) => {
                const d = role.defaults || {};
                return (d.sizing || {})['max-width'] !== undefined
                    && (d.typography || {}).align === 'center';
            })
            .map(([roleName, role]) => ({ component: name, roleName, defaults: role.defaults || {} })),
    );

    test('the role arm finds the capped, centred roles it governs', () => {
        // Fail-closed: schema discovery breaking would make the sweep below vacuous, and
        // this guard exists precisely because its stylesheet twin just went empty.
        expect(v2Schemas.length).toBeGreaterThanOrEqual(9);
        expect(cappedCentredRoles.map(r => `${r.component}.${r.roleName}`)).toContain('stats.heading');
    });

    test.each(cappedCentredRoles)(
        '$component.$roleName caps and centres, so it must default auto inline margins (#367)',
        ({ component, roleName, defaults }) => {
            const spacing = defaults.spacing || {};
            expect(
                [spacing['margin-left'], spacing['margin-right']],
                `${component}.${roleName} defaults a sizing.max-width AND typography.align: center, ` +
                'but not both auto inline margins. A capped box inside a wider container does not ' +
                'centre without them - the cap pins it left and only the text centres (#367).'
            ).toEqual(['auto', 'auto']);
        },
    );

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
        // section's row is gone (#1023): the `--inverted` class died with the `theme`
        // prop in its v2 rebuild, exactly as testimonials' did. Body links are the
        // `body-link` role now, and that role carries its own `:hover` — which is the
        // §1b requirement that a role's states move with its resting values.
        // EMBED'S ROW WENT AT #1066, AND IT WAS THE LAST ONE. This roster is now empty,
        // and that is the honest end of the AUTOMATIC remap rather than a gap: no
        // component emits an `--inverted` class any more, so there is no rule left for
        // any row to name. The `forEach` below therefore runs zero times, which would be
        // vacuous on its own — so the capability's survival is asserted directly,
        // immediately after it, rather than left to an empty loop to imply.
        //
        // WHAT WAS LOST AND WHAT REPLACED IT, stated because this is a real capability
        // change and not a rename: v1 remapped a dark band's body links AUTOMATICALLY,
        // keyed on the theme class. v2 has no automatic remap — the author writes it —
        // and the address is a `*-link` role with EMPTY defaults: `body-link` on section
        // and cta, `content-link` on embed, `cell-link` on table. Empty defaults are what
        // keep a role from outranking the premium button rules for an author-written
        // `<a class=\"btn\">` (#545 through a role selector) while still giving the author
        // somewhere to aim. A container role's ink cannot substitute: it reaches a link
        // only by inheritance, and base.css's `a` rule is a direct declaration on the
        // element (measured, #1069).
        // cta's row went at #1026, exactly as section's went at #1023 and testimonials'
        // in Sprint 0. `.cta--inverted` died with the `theme` prop, so the selector this
        // row named no longer exists. A cta body link is the `body-link` role now, which
        // carries its own `:hover` — the §1b requirement that a role's states move with
        // its resting values, and the reason the v2 route cannot half-apply.
        // testimonials is absent. The `--inverted` class died with the `theme` prop in
        // the v2 rebuild, so the selector this row named no longer exists — and the
        // standing rule is that contrast fixes belong in token values chosen by the
        // authoring layer, never baked into component CSS. A dark v2 band sets its
        // link and text role colours to meet contrast; the AI-facing docs say so.
    ];

    function ruleBody(selector) {
        const esc = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        // `a\s*\{` so the color rule is matched, never the sibling `a:hover {`.
        const m = css.match(new RegExp(esc + '\\s*\\{([^}]*)\\}'));
        return m ? m[1] : null;
    }

    // THE ROSTER'S EMPTINESS IS ASSERTED, NOT ASSUMED (#1045's roster-count shape).
    // `HEADING_COLOR_RULES` gets this for free because `test.each` refuses an empty
    // array; a `forEach` does not — it just contributes no tests, silently. The
    // docblock above says the roster is empty and says why, but a comment is not a
    // gate: without this line the block's deadness is an accident that reads exactly
    // like coverage. If a future rebuild re-introduces an `--inverted` link rule, this
    // fails and sends the author to the two replacement tests below rather than
    // letting a half-live loop run beside them.
    test('the automatic dark-band link remap has no rows left, and that is asserted', () => {
        expect(DARK_BAND_LINK_VARIANTS).toHaveLength(0);
    });

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

    // THE EMPTIED ROSTER'S REPLACEMENT CLAIM. Read the long note on
    // DARK_BAND_LINK_VARIANTS first: the automatic remap is gone, and what has to stay
    // true is that every rich-text surface still has a LINK ADDRESS with EMPTY defaults.
    // Without this the empty roster above would simply be an absence nothing notices,
    // which is the #1038 shape.
    test('every rich-text surface still declares a link role, and none of them defaults', () => {
        const componentsDir = path.resolve(__dirname, '../../components');
        // component => the role that addresses a link inside its rich text.
        const LINK_ROLES = {
            section: 'body-link',
            cta: 'body-link',
            embed: 'content-link',
            table: 'cell-link',
        };
        Object.entries(LINK_ROLES).forEach(([component, role]) => {
            const schema = JSON.parse(
                fs.readFileSync(path.join(componentsDir, component, 'schema.json'), 'utf-8'),
            );
            const declared = schema.roles && schema.roles[role];
            expect(declared, `${component} must declare a \`${role}\` role`).toBeTruthy();
            expect(
                declared.defaults,
                `${component}.${role} must declare NO defaults: a default outranks the premium ` +
                'button rules for an author-written .btn (#545 through a role selector)',
            ).toEqual({});
            expect(
                declared.groups,
                `${component}.${role} must permit typography, or the author has no ink to set`,
            ).toContain('typography');
        });
    });

    /**
     * THE STATE HALF, WHICH THE ROSTER ABOVE DOES NOT COVER (#1066).
     *
     * The retired `.embed--inverted a` rule was a PAIR: a resting colour and a `:hover`
     * routed through `--color-accent-on-inverted-hover`. Emptying DARK_BAND_LINK_VARIANTS
     * deleted TWO generated tests per row, and repricing only the resting one is the
     * #1046 both-halves defect — the one this block is most exposed to, because the
     * resting half is the half the rendered contrast runner exercises.
     *
     * A link role that could not carry a state would silently cap every dark band's hover
     * at base.css's `@color-accent-hover` (about 2.6:1 on `@color-bg-inverted`, against
     * 11.4:1 for the on-inverted-hover token). So the capability is asserted here, and the
     * rendered proof that an authored `:hover` actually EMITS lives in style-render.spec.ts.
     */
    test('a link role can carry its states, so a dark band\'s hover is authorable', () => {
        const componentsDir = path.resolve(__dirname, '../../components');
        const LINK_ROLES = {
            section: 'body-link',
            cta: 'body-link',
            embed: 'content-link',
            table: 'cell-link',
        };
        // The engine's state dimension is a sub-map INSIDE a group, so "can carry a state"
        // is exactly "declares the group the state would live in". Asserting the group is
        // therefore the whole claim, not a proxy for it.
        Object.entries(LINK_ROLES).forEach(([component, role]) => {
            const schema = JSON.parse(
                fs.readFileSync(path.join(componentsDir, component, 'schema.json'), 'utf-8'),
            );
            const declared = schema.roles[role];
            expect(
                declared.groups,
                `${component}.${role} must permit typography so an author can write a :hover ` +
                'colour — v1 remapped the dark-band hover automatically and that rule retired',
            ).toContain('typography');
        });

        // AND THE TOKEN THE HOVER ROUTES TO MUST STILL EXIST. It lost its last
        // component-level reader when `.embed--inverted a:hover` was deleted at #1066, so
        // without this nothing would notice it being dropped from base.css.
        expect(
            BASE_CSS,
            '--color-accent-on-inverted-hover is what a dark band\'s link hover routes to',
        ).toMatch(/--color-accent-on-inverted-hover:\s*#[0-9a-fA-F]{6}/);
    });

    // THE STATS-NUMBER ROW RETIRED AT #1066 PR2 with the `.stats--inverted` class that
    // carried it, and what replaces it is a CAPABILITY assertion rather than a rule scan.
    //
    // WHAT THE CLAIM WAS: on a dark band the large figure defaulted to
    // `--color-accent-on-inverted` (8.33:1) instead of the light-surface `--color-accent`,
    // which measures only 3.23:1 there - a value that clears the 3:1 LARGE-text bar and
    // fails the 4.5:1 one, so it was readable only by virtue of being big.
    //
    // WHY THERE IS NO REPLACEMENT DEFAULT: stats has no `theme` prop any more, so there is
    // no dark band for a default to be conditional ON. `number` defaults `@color-accent`,
    // which is the measured-correct value on every band v1 could render unthemed, and an
    // author who darkens `_band` owes `number` a write - stated in the role description and
    // in the retired-prop route, and pinned as rendered contrast in style-render.spec.ts.
    // The token must still exist for that write to have somewhere to point, which the
    // sibling test below is what guards.
    test('stats.number can carry the dark-band ink the retired rule used to supply (#437)', () => {
        const schema = JSON.parse(
            fs.readFileSync(
                path.resolve(__dirname, '../../components/stats/schema.json'), 'utf-8',
            ),
        );
        const number = schema.roles.number;
        expect(number).toBeDefined();
        expect(number.groups).toContain('typography');
        expect(number.defaults.typography.color).toBe('@color-accent');
    });

    test('the on-inverted accent tokens are defined in base.css :root', () => {
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted:\s*#[0-9a-fA-F]{6}/);
        expect(BASE_CSS).toMatch(/--color-accent-on-inverted-hover:\s*#[0-9a-fA-F]{6}/);
    });
});

describe('CSS lint: bg-image band accent routes through --color-accent-on-overlay (#461)', () => {
    // A bg-image band lays a dark rgba(0,0,0,.55) overlay over an ARBITRARY image.
    // The light-surface accent (--color-accent) is only 1.16:1 over the overlay-over-
    // white worst case and fails WCAG AA. #461 routed the default accent on all three
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
        // section's two rows are gone (#1023): `.section--has-bg-image` is not emitted
        // any more. A v2 band carrying a background image owns its own contrast — the
        // author sets `typography.color` on `body-link`, including its `:hover`.
        // cta's two rows went at #1026, the same way section's went at #1023 and for the
        // same reason: `.cta--has-bg-image` is no longer emitted, so there is no rule left
        // to pin. A v2 band carrying a background image owns its own contrast.
        // STATS IS THE LAST ROW, and it is why this block survives its siblings: stats is
        // still a v1 component that routes the overlay accent AUTOMATICALLY, so the
        // regression this guards (falling back to the bare accent at 1.16:1, or to the
        // on-inverted role tuned to the wrong surface) is still reachable there.
        { sel: '.stats--has-bg-image .stats__number', slot: '--stats-number-color', role: '--color-accent-on-overlay' },
    ];

    // THE ROSTER EMPTIED AT #1066 PR2 AND ITS STYLESHEET ARM RETIRES WITH IT. stats was
    // the last member - section's row went at #1023 and cta's at #1026 - and stats'
    // rebuild retires the `background_image` prop that emitted `.stats--has-bg-image`,
    // so there is no rule left anywhere for this selector-and-fallback check to read.
    //
    // WHAT THE CLAIM WAS: a bg-image band lays a dark scrim over an ARBITRARY image, where
    // the light-surface `--color-accent` measures 1.16:1 over the worst case (a white
    // image) and fails AA outright. #461 routed the DEFAULT through the overlay accent
    // role on all three bg-image bands, keeping the per-instance slot ahead of it.
    //
    // WHY IT IS NOT REPLACED BY AN EQUIVALENT ROLE DEFAULT, which is the part worth being
    // explicit about rather than quietly dropping: a v2 band has NO automatic remap. The
    // same is true of hero, section and cta, and it is deliberate - the engine cannot know
    // that a background image is dark, so a default that assumed it would be wrong on a
    // light image. Setting a background does not recolour anything; the author writes
    // `typography.color` on each role over the scrim. The role-capability half of the old
    // claim - that an author CAN reach those roles - is asserted below, and the rendered
    // contrast of an authored over-scrim band is pinned in style-render.spec.ts.
    test('the roles a stats bg-image band must re-ink can all carry typography (#461)', () => {
        const schema = JSON.parse(
            fs.readFileSync(
                path.resolve(__dirname, '../../components/stats/schema.json'), 'utf-8',
            ),
        );
        ['heading', 'heading-accent', 'number', 'label'].forEach(role => {
            expect(
                schema.roles[role],
                `stats.${role} must exist: it is one of the roles an author has to re-ink ` +
                'over a background image now that the automatic remap has retired',
            ).toBeDefined();
            expect(
                schema.roles[role].groups,
                `stats.${role} must permit typography, or the author cannot write the ink ` +
                'the retired --has-bg-image rules used to supply',
            ).toContain('typography');
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
        // section's row is gone (#1023), for the same reason hero's went at #986.
        // cta's row is gone (#1026), for the same reason section's went at #1023 and
        // hero's at #986. Stats keeps its row and is now the only one: it still re-colours
        // its heading-accent from a band class, so the bare-accent regression is still
        // reachable on it and still worth a pin.
        { sel: '.stats--has-bg-image .stats__heading-accent', slot: '--stats-heading-accent-color' },
        // hero's row is gone (#986): `.hero--cover` no longer re-colours the accent.
        // A v2 band carrying a background image owns its own contrast — the author sets
        // `typography.color` on the `title-accent` role, which is what the AI-facing
        // docs already require of every v2 dark band.
    ];

    // THE ROSTER EMPTIED AT #1066 PR2. `.stats--has-bg-image .stats__heading-accent` was
    // its ONLY member, and the class retires with stats' `background_image` prop.
    //
    // WHAT THE CLAIM WAS, and why it was a separate issue from #461: the accented heading
    // substring paints its OWN colour at (0,1,0) and does NOT inherit the near-white ink
    // the band's heading rule supplies, so on a scrim band it rendered bare
    // `--color-accent` at 1.16:1 even though the heading around it was readable. That is
    // the trap this issue existed to record, and it is worth keeping in words because the
    // v2 shape has the SAME hazard: re-inking `heading` on a dark band does not reach
    // `heading-accent`, which is stated in the role's own description and asserted against
    // the emitted CSS in StatsRoleDefaultsEmitTest.
    //
    // The token itself is still pinned in base.css by #461's surviving token test, and the
    // rendered proof of an authored over-scrim accent lives in style-render.spec.ts.
    test('stats.heading-accent is reachable and does not inherit the heading ink (#463)', () => {
        const schema = JSON.parse(
            fs.readFileSync(
                path.resolve(__dirname, '../../components/stats/schema.json'), 'utf-8',
            ),
        );
        expect(schema.roles['heading-accent']).toBeDefined();
        expect(schema.roles['heading-accent'].groups).toContain('typography');
        // Its own default is the light-surface accent, which is exactly why a dark or
        // scrim band owes it a write: the value is correct on the band v1 could render
        // and wrong on the one v2 makes easy.
        expect(schema.roles['heading-accent'].defaults.typography.color).toBe('@color-accent');
    });

    // Section body list markers on the overlay band: --pp-list-marker-color is re-mapped
    // to the overlay role. The selector also carries the near-white color rule, so find the
    // declaration that actually assigns the marker variable.
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



/* RETIRED (#1026): THREE WHOLE BLOCKS, because all three were about cta's variant classes
 * and its per-instance button slots, and cta's rebuild removed both.
 *
 *   'dark-band buttons route through the AA accent roles (#535)'
 *   'the filled second button is ringed on overlay bands (#543)'
 *   'per-instance button slots are neutralised on non-owned buttons (#545)'
 *
 * #535 and #543 pinned the AA routings keyed on `.cta--inverted` and `.cta--has-bg-image`:
 * on-inverted / on-overlay ink for the outline and ghost variants, and a separation ring
 * that stopped a filled button dissolving into a scrim. Both classes derive from `theme`
 * and `background_image`, which retired with the rebuild, and the button VARIANTS they
 * addressed retired with `button_variant` / `button2_variant` — so the selectors they pin
 * cannot be written any more, in any configuration. This follows #986's ruling for
 * `.hero--cover` verbatim ("v2 has no variant-scoped role defaults and does not guess"),
 * and the same change there deleted the identical `.hero--cover` separation ring.
 *
 * #545's block pinned the neutralisation RULE, which is gone for the reason the rule itself
 * is gone: it reset per-instance button slot families to `initial` on a composed `.btn` the
 * renderer does not own, and no component declares such a family any longer.
 *
 * WHAT COVERS THE GROUND NOW, so this reads as a move rather than a deletion:
 *   - A v2 band OWNS ITS OWN CONTRAST. A dark fill or a scrim is an authored value, and the
 *     text roles over it take `typography.color` from the same map. The AI-facing docs and
 *     components/cta/README.md both say so in those words.
 *   - The ONE affordance that stayed automatic is the FOCUS RING over a scrim, because #986
 *     gave it an engine-emitted trigger instead of a class: `[data-pp-band-overlay]`, which
 *     cta now emits. Its pins are in the #542 block below, narrowed rather than retired.
 *   - The nested-author-button guarantee (#545) holds by construction: nothing is emitted on
 *     the band root, so nothing inherits down to an author-written `.btn`.
 *
 * WHAT IS GENUINELY LOST, stated rather than buried: the separation ring's measured 4.59:1
 * on an overlay band, and the on-inverted ink's 8.33:1, were AUTOMATIC. They are now an
 * author's job on the role. That is the same trade #986 made for the hero and it is recorded
 * in cta's README under "Two things `theme` and `background_image` took with them".
 */

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

    // EMPTY SINCE #1026, and empty is the honest state rather than a gap. The only routed
    // inverted selector was `.cta--inverted .btn:focus`, and `theme` retired with cta's
    // rebuild, so no band can carry that class. It was NOT re-keyed the way the overlay half
    // was, because there is nothing to key it on: an overlay is a fact the ENGINE knows (it
    // composes the scrim, which is why `[data-pp-band-overlay]` can exist), while "this band
    // is dark" is a colour an author chose and the engine does not interpret colours.
    // Detecting darkness would mean parsing an authored value — `@token` references,
    // gradients and `color-mix()` included — and guessing, which is exactly the
    // variant-scoped guessing #986 removed from the hero.
    // THE COST, stated: an inverted cta used to get an 8.33:1 focus ring automatically and
    // now gets the 3.23:1 bare accent until its `button` role says otherwise. 3.23:1 still
    // clears the 3:1 non-text bar (#535 Q2 used that same figure), so this is a loss of
    // margin rather than a new failure, and cta's README records it.
    const INVERTED_SELECTORS = [];
    const OVERLAY_SELECTORS = [
        // The ENGINE's hook (#986), and the one that matters most now: ruling A2 made
        // a band background image authorable on every layout, so the overlay stopped
        // being something a layout class could describe. Emitted only when an image
        // and an overlay are both present — see pp_udc_promote_band_identity().
        '[data-pp-band-overlay] .btn:focus',
        // faq's <summary>, added at #1046 and THE FIRST ENTRY HERE THAT IS NOT A BUTTON.
        // faq renders no `.btn` at all, so the row above could never reach its focusable
        // control. That was not a gap while faq was a v1 component — v1 faq declared no
        // `background_image` prop, so a scrim was not a state it could reach — and the
        // rebuild creates the case by giving `_band` the `background.image` +
        // `background.overlay` pair. Caught before it shipped rather than filed after,
        // which is the difference from #1035 (section and testimonials, which reached the
        // case and kept the 1.17:1 bare accent).
        '[data-pp-band-overlay] .faq__question:focus',
        // The v1 classes, still correct for the components that express the case that
        // way. `.hero--cover` is now redundant with the attribute on a v2 hero and is
        // kept deliberately: it costs nothing and it keeps the rule true for a hero
        // rendered from stored data that predates band ids.
        // `.cta--has-bg-image` LEFT AT #1026: the `background_image` prop that derived it
        // retired, cta now emits `data-pp-band-overlay`, and a class term that can never
        // match is worse than an absent one — it is a roster pretending to still be true.
        '.hero--cover .btn:focus',
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

    // RETIRED (#1026): the source-order pin between the inverted and overlay blocks. It
    // existed because ONE cta root could carry both classes — cta.php concatenated the theme
    // class and the bg-image class independently — so the two blocks tied at [0,3,0] and only
    // order decided which role won. Both classes are gone, so the tie cannot arise: the
    // overlay case is the engine's attribute and there is no inverted case at all. The
    // precedence it protected (overlay must beat inverted, because on-inverted is 2.21:1 over
    // the worst-case scrim) is not re-litigated — it has no second claimant left.

    /*
     * These rules win on SPECIFICITY, not source order: [0,3,0] (band class + .btn +
     * :focus) against the [0,2,1] of `main .btn:focus`. Losing a class from a routed
     * selector would silently hand the ring back to the bare accent. Specificity is
     * computed from the selector AS PARSED OUT OF components.css, never from this file's
     * own constants — otherwise the assertion can only fail when the test is edited.
     */
    test('every routed selector outranks the `main .btn:focus` winner, as parsed from the CSS', () => {
        // [classes+pseudo-classes+ATTRIBUTES, type selectors] — enough to compare these
        // shapes. Attribute selectors carry class-level weight in CSS and are counted
        // here since #986 put the engine's `[data-pp-band-overlay]` hook in this list;
        // without them `[data-pp-band-overlay] .btn:focus` (really [0,3,0]) read as
        // having no type selector and lost to `main .btn:focus` ([0,2,1]) on this
        // approximation alone, while winning in every browser.
        const specificity = (sel) => [
            (sel.match(/[.:][a-zA-Z][\w-]*/g) || []).length
                + (sel.match(/\[[^\]]+\]/g) || []).length,
            // An attribute selector's contents must not be mistaken for a type
            // selector, so strip them before counting bare element names.
            (sel.replace(/\[[^\]]+\]/g, ' ').match(/(?:^|\s)[a-zA-Z][\w-]*/g) || []).length,
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
 * CSS lint: the per-instance RING slots for the hero primary and the section panel CTA (#584).
 *
 * Two filled surfaces reached their ring only through a knob that also moves something else:
 * the hero primary through the BAND accent or the site-wide --btn-border-color; the panel CTA
 * through --btn-border-color alone, because it is the one filled surface that had NEITHER of
 * the two tiers its siblings carry above the global knob. Only the panel CTA is still on this
 * surface — hero left the slot system in #986 — so this block pins the panel CTA and keeps
 * the hero half only as the history that explains the shape. #584 gives each its own ring slot at the HEAD of the chain,
 * in exactly the position --cta-button-border holds on the cta primary, plus the hover twin
 * (P6: a control added without a state twin is a future flip bug).
 *
 * Three properties are pinned, and each is a different failure this file has actually seen:
 *
 *   1. HEAD POSITION. A slot anywhere below the band accent or the global knob is a slot the
 *      site-wide retheme defeats — the #564 defect, one tier down.
 *   2. POSITIONAL TWIN. The hover chain is the rest chain with every knob swapped for its
 *      hover equivalent. A rest-only ring dissolves under the pointer (#535). Asserted for
 *      the panel CTA; hero's half went with its rows (#986), since hero declares no ring
 *      slots and its CTA ring is now its `cta` / `cta-secondary` role's border.
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
// The #584 panel-CTA ring-slot block was deleted at #1023. Both of its cases were
// section's own (`--section-panel-cta-border` and `--section-panel-cta-hover-border`
// leading the border chain). The `panel-cta` ROLE replaces them: a ring is its
// `border` group and a hover ring is that group's `:hover`, both authored rather than
// slot-ordered, so there is no chain left to pin an order in. Hero's equivalent rows
// left the same way at #986.
/**
 * THE LAYOUT GROUP'S EXPOSURE ROSTER (#1084).
 *
 * `layout` is what a CONTAINER does to its children, so the roles that expose it
 * are decided by a fact about the box rather than by a judgement about the
 * component — and a fact can be derived. This reads the shipped stylesheet and
 * the shipped schemas and asserts they agree, in both directions:
 *
 *   clause 1  the role's own selector is declared `display: flex|grid` (either
 *             tier) somewhere in its component's block, and
 *   clause 2  that `display` is NOT a visibility switch — no `display: none` on
 *             the selector, and no attribute-qualified form of it carrying a
 *             display at all.
 *
 * CLAUSE 2 EXISTS BECAUSE OF A VERIFIED HAZARD, not for symmetry. `nav` uses
 * `display` as behaviour: `.nav__toggle` is `display: none` from 768px, and
 * `.nav__menu[hidden] { display: flex }` at 768px sits against the JS that adds
 * and removes `hidden` on mobile. The `hidden` attribute is honoured only by the
 * UA stylesheet — ANY author declaration outranks it, and base.css carries no
 * `[hidden]` rule of its own — so an authored `layout.columns` on `nav.menu`
 * would emit an unlayered `display: grid` companion (lib/udc.php,
 * _pp_udc_grid_columns_companion) and PIN AN OPEN MOBILE MENU OPEN. That is a
 * styling write defeating a keyboard and screen-reader affordance, which is not a
 * thing to document — it is a thing to make unreachable.
 *
 * FAIL-CLOSED, because a selector parse that quietly degrades would pass
 * vacuously: the derived set is compared against a RECORDED roster, the same
 * anti-vacuity shape STRUCTURAL_RULE_COUNT uses above. A parse that stops
 * matching fails loudly instead of agreeing with an empty schema walk.
 */
describe('CSS lint: the Layout group is exposed by box fact, not by judgement', () => {
    const componentsDir = path.resolve(__dirname, '../../components');
    const CONTAINER = /^(flex|inline-flex|grid|inline-grid)$/;
    const norm = (s) => s.trim().replace(/\s+/g, ' ');

    /** Every `display` value declared against each normalized selector in a block. */
    const displaysIn = (block) => {
        const map = new Map();
        const rules = /([^{}]+)\{([^{}]*)\}/g;
        let rule;
        while ((rule = rules.exec(block)) !== null) {
            const selectors = rule[1].trim();
            if (selectors.startsWith('@')) continue; // the media wrapper itself
            const decls = /(^|;)\s*display\s*:\s*([^;]+)/g;
            let decl;
            while ((decl = decls.exec(rule[2])) !== null) {
                selectors.split(',').forEach(sel => {
                    const key = norm(sel);
                    if (!map.has(key)) map.set(key, new Set());
                    map.get(key).add(decl[2].trim());
                });
            }
        }
        return map;
    };

    const componentBlock = (component) => {
        const css = COMPONENTS_CSS;
        const start = css.indexOf(`COMPONENT: ${component}`);
        if (start === -1) return '';
        const bodyStart = css.indexOf('*/', start);
        const next = css.indexOf('/* =====', bodyStart);
        return stripComments(css.slice(bodyStart + 2, next === -1 ? undefined : next));
    };

    // THE RECORDED ROSTER. Derived from the two clauses at #1084 and written down,
    // so a schema change and a stylesheet change each have to meet it.
    const RECORDED = {
        cta: ['inner', 'buttons'],
        faq: ['list', 'question'],
        footer: ['inner', 'columns', 'brand', 'social', 'social-link', 'nav-list', 'bottom-row'],
        hero: ['inner', 'content', 'cta-group', 'proof', 'surface'],
        logos: ['list', 'item'],
        nav: ['container', 'logo', 'menu-list'],
        section: ['columns', 'inline-items', 'panel-row'],
        stats: ['list', 'item'],
        table: [],
        testimonials: ['list', 'card', 'attribution'],
        embed: [],
    };

    const derive = (component) => {
        const block = componentBlock(component);
        const schema = JSON.parse(
            fs.readFileSync(path.join(componentsDir, component, 'schema.json'), 'utf-8'),
        );
        const displays = displaysIn(block);
        const eligible = [];
        const excluded = [];
        Object.entries(schema.roles || {}).forEach(([role, def]) => {
            const selector = norm(def.selector || '');
            if (selector === '') return; // `_band` has no element selector
            let container = false;
            let switched = false;
            displays.forEach((values, sel) => {
                if (sel === selector) {
                    values.forEach(v => {
                        if (CONTAINER.test(v)) container = true;
                        if (v === 'none') switched = true;
                    });
                } else if (sel.includes(selector + '[')) {
                    // An attribute-qualified form of the same box carrying a
                    // display: that IS the visibility switch, whatever its value.
                    switched = true;
                }
            });
            if (container && !switched) eligible.push(role);
            if (container && switched) excluded.push(role);
        });
        return { eligible, excluded, schema };
    };

    const v2Components = Object.keys(RECORDED);

    test('the roster derivation reads real CSS and real schemas', () => {
        // Anti-vacuity: the parse must find containers at all, and must find the
        // component blocks it is slicing.
        const { eligible } = derive('hero');
        expect(eligible.length, 'the display parse found no container in hero — it is reading nothing').toBeGreaterThan(3);
        expect(componentBlock('nav'), 'the block slicer lost nav').not.toBe('');
    });

    v2Components.forEach(component => {
        test(`${component}'s layout exposure matches the box facts`, () => {
            const { eligible, schema } = derive(component);
            const declared = Object.entries(schema.roles || {})
                .filter(([, def]) => (def.groups || []).includes('layout'))
                .map(([role]) => role);

            expect(
                eligible.sort(),
                `${component}'s derived container roles no longer match the recorded roster. If the ` +
                'stylesheet deliberately made a box a container (or stopped), update RECORDED in the ' +
                'same commit so the change is reviewed rather than silent.',
            ).toEqual([...RECORDED[component]].sort());

            expect(
                declared.sort(),
                `${component}'s schema exposes layout on roles that are not containers, or omits one ` +
                'that is. Exposure is a box fact: a container role must declare the group, and a ' +
                'non-container role must not (a justify-content on a block box paints nothing at any ' +
                'value, which is the #1006 inert class).',
            ).toEqual([...RECORDED[component]].sort());
        });
    });

    /**
     * THE HAZARD ITSELF, PINNED. Not "nav.menu is absent from a list" — the
     * mechanism that makes its absence necessary, so the next person who reads
     * `RECORDED` and wonders why the menu is missing finds the answer in a test
     * rather than in a git log.
     */
    test('a role whose display is a visibility switch is excluded, and nav is why', () => {
        const { excluded, schema } = derive('nav');
        expect(excluded.sort()).toEqual(['menu', 'toggle']);
        ['menu', 'toggle'].forEach(role => {
            expect(
                (schema.roles[role].groups || []).includes('layout'),
                `nav.${role} must NOT expose layout: an authored columns value emits an unlayered ` +
                'display:grid companion, which outranks the UA stylesheet\'s [hidden] rule and pins ' +
                'an open mobile menu open — a styling write breaking a keyboard/AT affordance.',
            ).toBe(false);
        });

        // The mechanics the exclusion depends on, asserted rather than assumed:
        // the menu's hidden state really is attribute-driven, and the theme really
        // does not carry its own [hidden] rule that would survive an author value.
        expect(COMPONENTS_CSS).toContain('.nav__menu[hidden]');
        expect(BASE_CSS.includes('[hidden]')).toBe(false);
    });
});
