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
 * about: "a site-wide button retheme reaches every filled surface" is now a property of a
 * single-link chain, and the surviving premium-fill pins in this file read `--btn-bg` /
 * `--btn-hover-bg` / `--btn-border-color` / `--btn-hover-border-color` directly. What is
 * gone is the ORDERING half, which only had meaning while something sat above the knob.
 *
 * #412/#514's MECHANISM survives too, and outlived its slots: a flat value resolves the
 * `background` SHORTHAND to `background: <color>`, which resets `background-image` and
 * clears the premium gradient. A role's `background.fill` emits that same shorthand by
 * construction (pp_udc_groups(): "fill MUST stay first"), so the masking fix is now a
 * property of the emitter rather than of a hand-written fallback chain. The surviving
 * shorthand in components.css carries that note where a future longhand edit would break it.
 */

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
        // Section's two variants are gone (#1023), the way hero's row went at #986: the
        // `theme` prop and the `background_image` prop both retired, so neither
        // `.pp-section--inverted` nor `.section--has-bg-image` is emitted any more. A dark
        // or image-backed section band is the `_band` role's `background` group, and its
        // text colours are `typography.color` on the text roles.
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
        ['main > [data-pp-component] + .faq', '--faq-padding-top'],
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
        { comp: 'stats', cls: '.stats', slot: '--stats' },
        { comp: 'faq', cls: '.faq', slot: '--faq' },
// testimonials is absent from this table: it is a v2 component whose CSS block is
        // structural only, so it routes nothing through a slot. The value this row used to
        // guard is now a role default in components/testimonials/schema.json.
                { comp: 'table', cls: '.table-section', slot: '--table' },
        { comp: 'logos', cls: '.logos', slot: '--logos' },
        { comp: 'embed', cls: '.embed', slot: '--embed' },
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
        { selectors: ['.faq__heading', 'main > .faq .faq__heading'], slot: '--faq-heading-size' },
        { selectors: ['.stats__heading'], slot: '--stats-heading-size' },
        { selectors: ['.table-section__heading'], slot: '--table-heading-size' },
        { selectors: ['.logos__heading'], slot: '--logos-heading-size' },
        { selectors: ['.embed__heading'], slot: '--embed-heading-size' },
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
        // testimonials is absent: it is a v2 component whose CSS block is structural
        // only. Its heading colour is the `heading` role's typography.color default.
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

    v2Components.forEach(component => {
        test(`${component}'s CSS block declares only structure`, () => {
            const block = componentBlock(component);
            expect(block, `no CSS banner block found for ${component}`).not.toBe('');
            const rules = parseRules(block);
            // Floor: the slice must actually contain the sub-element rules, or every
            // check below reads an empty list and passes for the wrong reason.
            expect(rules.length).toBeGreaterThan(5);
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

    function designOffencesIn(rules) {
        const offences = [];
        rules.forEach(rule => {
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

    /*
     * SEMANTIC PRECEDENCE, not formatting. ONE root can carry an inverted class AND a
     * bg-image class: the section root in components/cta/cta.php concatenates
     * `pp_theme_class($theme,'cta')` and `$bg_image_class` independently (the section root
     * in section.php does the same, which is why section bands would have had the
     * identical dependency had they been routed). Named by construct, not by line: both
     * files carry long guard blocks that shift these roots on every fix. The two
     * blocks then match at [0,3,0] and only source order decides. The OVERLAY role must
     * win: on-inverted is only 2.21:1 over the worst-case scrim, so the combined band would
     * otherwise get a ring that fails 1.4.11 harder than the bug this fixes.
     */
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