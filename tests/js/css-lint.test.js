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

    test('hero/section/grid/cta schemas declare 124 style slots (subset of the 169 total)', () => {
        expect(allSlots.length).toBe(124);
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
    test('inverted grid card text resolves to a global token, never a theme var', () => {
        const cardDecls = rules
            .filter(r => targetsElement(r.selector, '.grid__item-title') ||
                         targetsElement(r.selector, '.grid__item-text'))
            .flatMap(r => colorValues(r.body).map(value => ({ selector: r.selector, value })));

        expect(cardDecls.length).toBeGreaterThan(0);
        const offenders = cardDecls
            .filter(d => !/^var\(--grid-item-(title|text)-color,\s*var\(--color-[a-z0-9-]+\)\)$/.test(d.value))
            .map(d => `${d.selector.split('\n').pop().trim()} { color: ${d.value} }`);
        expect(offenders).toEqual([]);
    });

    test('.grid--inverted declares no --grid-item-* default (cards must not be themed)', () => {
        const block = rules.find(r => r.selector.split(',').some(s => s.trim() === '.grid--inverted'));
        expect(block).toBeDefined();
        expect(block.body).not.toMatch(/--grid-item-[a-z-]*\s*:/);
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
    const SELECTOR = 'main > .grid:not(.grid--steps) .grid__item:first-child';

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
 * on a fixture page: the four benchmark IDs carry `#home-hero .hero__proof` rules whose
 * (1,1,0) specificity outranks every class rule below, so a justify-content declared there
 * would beat the fix on real pages while every E2E fixture stayed green.
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
    // ID-specificity rule (the benchmark pages already own `#home-hero .hero__proof` for
    // margin/width) or a media-scoped rule re-declaring the packing. Only the three known
    // class rules may justify these rows.
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
 * CTA eyebrow stays a pill, above the title (#255).
 *
 * Same visual failure as #225, different mechanism. `#home-cta .cta__text` is
 * `display: contents` at >=768px, which dissolves its box and promotes the eyebrow
 * into `#home-cta.cta--inline .cta__inner` — a GRID. Grid blockifies the eyebrow's
 * inline-block, and with no placement of its own it auto-flows past every
 * explicitly-placed sibling (title col1/rows1-2, body col2/row1, button col2/row2)
 * into the first free cell — row 3, column 1 — and stretches it there: a band the
 * full width of the title column, rendered BELOW the button.
 *
 * So the fix has two halves, and both need pinning: `justify-self: start` sizes the
 * pill to its text, and `grid-row: 1` puts it back above the title. A pin on the
 * width alone would stay green while the eyebrow rendered under the button.
 */
describe('CSS lint: cta eyebrow is a pill above the title (#255)', () => {
    const DESKTOP = '(min-width: 768px)';
    const BASE = '.cta__eyebrow';
    const PLACED = '#home-cta.cta--inline .cta__eyebrow';

    // The cascade winner among equal-specificity rules is the LAST declaration, not the
    // first — and these selectors really are declared more than once: `.cta__inner` is
    // styled by the shared four-CTA block AND by a #home-cta-specific override further
    // down, both at >=768px. Taking the first match reads the wrong rule, and a
    // duplicate appended later would shadow a pin while it stayed green.
    function declFor(needle, selector, prop, media) {
        const decls = rulesMatching(needle)
            .filter(r => r.media === media && r.selectors.includes(selector))
            .map(r => new RegExp(prop + '\\s*:\\s*([^;}]+)').exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    // The pill is padding + background, not `display` — pinning `inline-block` would be
    // vacuous, since the grid parent blockifies it regardless. That is the whole bug.
    test('the base rule keeps its pill styling', () => {
        const rule = rulesMatching(BASE).find(r => r.selectors.includes(BASE) && !r.media);
        expect(rule.body).toMatch(/padding\s*:/);
        expect(rule.body).toMatch(/background\s*:/);
    });

    // A width on the base rule would defeat justify-self without touching it.
    test('the base rule sizes the pill by its content, not a width', () => {
        const rule = rulesMatching(BASE).find(r => r.selectors.includes(BASE) && !r.media);
        expect(rule.body).not.toMatch(/(?:^|[;\s])(?:min-)?width\s*:/);
    });

    // Half one of the fix: the pill is sized to its text instead of stretching across
    // the 36-40rem title column.
    test('the promoted eyebrow opts out of the grid stretch', () => {
        expect(declFor(BASE, PLACED, 'justify-self', DESKTOP)).toBe('start');
    });

    // Half two: without an explicit row it auto-places into row 3, i.e. below the
    // button. This is the pin that a width-only guard would miss.
    test('the promoted eyebrow is placed in row 1 of the title column', () => {
        expect(declFor(BASE, PLACED, 'grid-column', DESKTOP)).toBe('1');
        expect(declFor(BASE, PLACED, 'grid-row', DESKTOP)).toBe('1');
    });

    // Row 1 belongs to the eyebrow. If the title ever reclaims it, the two collide in
    // the same cell and the eyebrow renders on top of the headline.
    test('the title starts at row 2, leaving row 1 to the eyebrow', () => {
        expect(declFor('.cta__title', '#home-cta .cta__title', 'grid-row', DESKTOP))
            .toBe('2 / span 2');
    });

    /*
     * Scope guard — the reason the placement rule carries `.cta--inline`.
     *
     * `layout` accepts 'full-width' (the DEFAULT) and 'inline'. The grid only exists
     * for `.cta--inline`; in the full-width layout `.cta__inner` stays a flex COLUMN,
     * where the cross axis is horizontal and `align-self: end` pushes the pill to the
     * right edge (measured: x=1110 vs 611 in a 1280px viewport). Grid placement only
     * means "grid", so a bare `#home-cta .cta__eyebrow` selector would fix the inline
     * layout by breaking the default one.
     */
    test('the placement rule is scoped to the inline layout, never bare #home-cta', () => {
        const placement = /grid-(?:row|column)\s*:|justify-self\s*:|align-self\s*:/;
        rulesMatching(BASE)
            .filter(r => placement.test(r.body))
            .forEach(r =>
                r.selectors
                    .filter(s => s.includes('.cta__eyebrow'))
                    .forEach(s => expect(s).toContain('.cta--inline'))
            );
    });

    // The placement rules are dead code unless the parent is still a grid and
    // .cta__text still dissolves into it. If either stops being true, the eyebrow is no
    // longer a grid item and every pin above silently stops describing the render.
    const INNER = '#home-cta.cta--inline .cta__inner';

    test('the eyebrow is only a grid item because .cta__text dissolves into a grid', () => {
        expect(declFor('.cta__inner', INNER, 'display', DESKTOP)).toBe('grid');
        // Two tracks, not merely "some template". `grid-column: 1` on the eyebrow and
        // `grid-column: 2` on the body/button only mean anything against a two-column
        // track; a collapse to one column would leave every placement pin green while
        // describing a layout that no longer exists.
        expect(declFor('.cta__inner', INNER, 'grid-template-columns', DESKTOP))
            .toMatch(/minmax\(.+\)\s+minmax\(.+\)/);
        expect(declFor('.cta__text', '#home-cta .cta__text', 'display', DESKTOP))
            .toBe('contents');
    });

    /*
     * The empty row 1 collapses only while the row gap is 0 — and EVERY shipped page is
     * in that state, because none of them sets an eyebrow. This is the highest-blast-
     * radius way the fix can regress: a gap above the title on every live CTA.
     *
     * Reading the last `row-gap` declaration is not enough. `.cta__inner`'s base rule
     * declares the `gap` SHORTHAND (`gap: var(--cta-inner-gap, ...)`), and a shorthand
     * re-declared inside the desktop block would reset row-gap back to a non-zero value
     * while a `row-gap`-only scan still reported 0. So pin both: row-gap is 0, and no
     * shorthand reintroduces one. (`column-gap` is fine — the hyphen means the
     * word-boundary below never matches it.)
     */
    test('row-gap stays 0 so the empty row collapses when no eyebrow is set', () => {
        expect(declFor('.cta__inner', INNER, 'row-gap', DESKTOP)).toBe('0');
    });

    test('no gap shorthand in the desktop block can reset that row-gap', () => {
        rulesMatching('.cta__inner')
            .filter(r => r.media === DESKTOP && r.selectors.includes(INNER))
            .forEach(r => expect(r.body).not.toMatch(/(?:^|[;{\s])gap\s*:/));
    });

    /*
     * Half (a) of the bug — the stretch — can return without touching the placement at
     * all. Two distinct guards, because the two declaration families have different
     * legitimacy:
     *
     * Sizing (width / min-width / flex basis / place-self) is NEVER legitimate on the
     * pill, in ANY rule, including the two owners. `width: 100%` added to the placement
     * rule itself restores the band, and a guard that skipped the owners would sail
     * straight past it.
     */
    const SIZE_RESTORING = /(?:^|[;{\s])(?:min-)?width\s*:|(?:^|[;\s])flex\s*:|place-self\s*:/;

    test('no eyebrow rule anywhere gives the pill a width', () => {
        rulesMatching(BASE).forEach(r => expect(r.body).not.toMatch(SIZE_RESTORING));
    });

    // Alignment IS legitimate — but only on the two owner rules. Anywhere else (a
    // media-scoped override, a duplicate appended later, a new ID-specificity block) it
    // can re-stretch the pill or drag it out of column 1 while every pin above stays green.
    test('only the two owner rules may align the pill', () => {
        const owners = [BASE, PLACED];
        rulesMatching(BASE)
            .filter(r => !r.selectors.every(s => owners.includes(s)))
            .forEach(r => expect(r.body).not.toMatch(BAND_RESTORING));
    });
});

/*
 * #258: the inline CTA grid activates at 768px, but the content box it gets there is
 * only ~40rem — the container's padding plus .cta__inner's own clamp(2rem, 4vw, 2.6rem)
 * padding and border come out of the viewport first. The old floors demanded
 * 36rem + 18rem + a >=3rem gap = ~57rem, so the grid could not shrink and scrolled the
 * page sideways from 768px to ~1030px.
 *
 * The rendered proof lives in the E2E suite (scrollWidth <= viewport). These pins guard
 * the two structural properties that proof silently depends on, and that a later edit
 * could break while the CSS still looked reasonable.
 */
describe('CSS lint: the inline cta grid can shrink to its breakpoint (#258)', () => {
    const DESKTOP = '(min-width: 768px)';
    const INNER = '#home-cta.cta--inline .cta__inner';

    function columnsFor(selector, media) {
        const decls = rulesMatching('.cta__inner')
            .filter(r => r.media === media && r.selectors.includes(selector))
            .map(r => /grid-template-columns\s*:\s*([^;}]+)/.exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    // Read the LAST declaration, i.e. the cascade winner: .cta__inner's tracks are set by
    // the shared four-CTA block AND re-set by the #home-cta override after it. A pin that
    // matched the first hit would keep passing while a later rule reintroduced fixed floors.
    const columns = () => columnsFor(INNER, DESKTOP);

    /*
     * The whole fix. A track whose MIN is a fixed length cannot shrink below it, which is
     * precisely how the bug worked. Column 1 must therefore floor at 0, and column 2 may
     * only floor at the button's min-width (below).
     */
    test('column 1 floors at 0 so the grid can shrink below its content', () => {
        expect(columns()).toMatch(/^minmax\(\s*0\s*,/);
    });

    /*
     * Column 2's floor is not an arbitrary number — it is .cta__button's min-width. The
     * button is the one item in that column that refuses to get narrower, so if the floor
     * ever drops below the button's min-width the button overflows its own track and the
     * page scrolls sideways again. Derive both from the stylesheet and compare, so moving
     * either one without the other fails here instead of in someone's browser.
     */
    test('column 2 floors at exactly the cta button min-width', () => {
        // The cascade winner AT THE BREAKPOINT, which is not simply the last unscoped
        // rule: #home-cta .cta__button is declared min-width twice outside any media
        // query, and a `@media (min-width: 768px)` rule for these buttons already exists.
        // A min-width added to that media rule would beat both base declarations exactly
        // where this grid is live, so consider unscoped and desktop rules together and
        // take the last. Reading only the unscoped rules would compare the track floor
        // against a value the browser no longer uses, and pass while the button overflows.
        const buttonMinWidth = rulesMatching('.cta__button')
            .filter(r => (r.media === null || r.media === DESKTOP)
                && r.selectors.includes('#home-cta .cta__button'))
            .map(r => /min-width\s*:\s*([^;}]+)/.exec(r.body))
            .filter(Boolean)
            .pop();

        expect(buttonMinWidth).toBeTruthy();

        const floor = /minmax\(\s*([^,]+),[^)]*\)\s*$/.exec(columns());
        expect(floor).toBeTruthy();
        expect(floor[1].trim()).toBe(buttonMinWidth[1].trim());
    });

    /*
     * The 0 floor shrinks the TRACK, but it does not make the title's text fit inside it:
     * a long unbreakable word would simply paint outside the narrowed column and scroll
     * the page anyway. base.css's `overflow-wrap: break-word` on `p, h1..h6` is what lets
     * the word wrap mid-word instead (measured: forcing `overflow-wrap: normal` on the
     * title at 768px puts ~97px back outside the viewport). Nothing in components.css says
     * so, so a base.css edit could reopen #258 from a distance. The E2E long-word case
     * proves the render; this pin names the cross-file dependency.
     */
    test('the title can still break a long word (base.css keeps the 0 floor effective)', () => {
        // Scoped, not a file-wide substring search: `overflow-wrap: break-word` narrowed
        // to some unrelated selector would leave a bare /break-word/ match green while the
        // CTA title regained a min-content floor — the exact edit that reopens #258. The
        // title renders as an <h2> and the body as a <p>, so assert the rule that actually
        // covers them still carries the declaration.
        const wrapping = stripComments(BASE_CSS)
            .split('}')
            .map(chunk => chunk.split('{'))
            .filter(([, body]) => body && /overflow-wrap\s*:\s*break-word/.test(body))
            .map(([selectors]) => selectors.split(',').map(s => s.trim()));

        expect(wrapping.length).toBeGreaterThan(0);

        const covered = wrapping.flat();
        expect(covered).toContain('h2');
        expect(covered).toContain('p');
    });
});

/*
 * #265: the SHARED four-CTA inline grid rule can shrink to its breakpoint.
 *
 * #258 fixed only #home-cta, whose tracks are re-set by a #home-cta-specific override.
 * #how-cta / #agencies-cta / #implementers-cta never reach that override — they are sized
 * by the shared four-CTA rule, which had the same fixed floors (32.5rem + 14rem + gap) and
 * scrolled the page sideways at 768..912px. The rendered proof lives in the E2E suite
 * (scrollWidth <= viewport, now parametrized over all four ids); these pins guard the two
 * structural properties that proof silently depends on for the shared-rule ids.
 */
describe('CSS lint: the shared inline cta grid can shrink to its breakpoint (#265)', () => {
    const DESKTOP = '(min-width: 768px)';
    // Every id NOT carrying a later same-selector override — i.e. the three sized only by
    // the shared rule. #home-cta is excluded: its own override is the cascade winner and is
    // already pinned by the #258 block above.
    const SHARED_IDS = ['how-cta', 'agencies-cta', 'implementers-cta'];

    function columnsFor(selector, media) {
        const decls = rulesMatching('.cta__inner')
            .filter(r => r.media === media && r.selectors.includes(selector))
            .map(r => /grid-template-columns\s*:\s*([^;}]+)/.exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    for (const id of SHARED_IDS) {
        const INNER = `#${id}.cta--inline .cta__inner`;
        const columns = () => columnsFor(INNER, DESKTOP);

        /*
         * The whole fix: a fixed track minimum cannot shrink below itself, which is exactly
         * how the bug worked. Column 1 must floor at 0 so the grid can shrink below its
         * content at the 768px breakpoint.
         */
        test(`#${id} column 1 floors at 0 so the grid can shrink below its content`, () => {
            expect(columns()).toMatch(/^minmax\(\s*0\s*,/);
        });

        /*
         * Column 2's floor is not arbitrary — it is .cta__button's min-width. The button is
         * the one item in that column that refuses to get narrower, so if the floor drops
         * below the button min-width the button overflows its own track and the page scrolls
         * sideways again. Derive both from the stylesheet and compare, so moving either one
         * without the other fails here instead of in someone's browser.
         */
        test(`#${id} column 2 floors at exactly the cta button min-width`, () => {
            const buttonMinWidth = rulesMatching('.cta__button')
                .filter(r => (r.media === null || r.media === DESKTOP)
                    && r.selectors.includes(`#${id} .cta__button`))
                .map(r => /min-width\s*:\s*([^;}]+)/.exec(r.body))
                .filter(Boolean)
                .pop();

            expect(buttonMinWidth).toBeTruthy();

            const floor = /minmax\(\s*([^,]+),[^)]*\)\s*$/.exec(columns());
            expect(floor).toBeTruthy();
            expect(floor[1].trim()).toBe(buttonMinWidth[1].trim());
        });
    }
});

/*
 * #257: the full-width cta centers its body and button.
 *
 * `#home-cta .cta__text` is `display: contents` at >=768px, but the grid it feeds is
 * `.cta--inline` only. `layout` defaults to 'full-width', where `.cta__inner` stays a
 * flex COLUMN (align-items: center). grid-column/grid-row/justify-self are all inert on
 * a flex item, so they are safe to declare bare — but `align-self` is NOT. A bare
 * `#home-cta .cta__body { align-self: end }` and `#home-cta .cta__button { align-self:
 * start }` therefore leaked into the full-width column and shoved the body to the right
 * edge and the button to the left, while the eyebrow and title stayed centered.
 *
 * The fix scopes those two `align-self` declarations to `#home-cta.cta--inline`. The
 * rendered proof (both boxes centered within .cta__inner) lives in the E2E suite; these
 * pins guard the scope, which a later edit could quietly widen back to bare #home-cta.
 */
describe('CSS lint: the full-width cta centers its body and button (#257)', () => {
    const DESKTOP = '(min-width: 768px)';

    // The cross-axis values a flex column reads as horizontal placement. The shared
    // four-CTA block legitimately sets `align-self: center` / `flex-start` on these same
    // classes (centered / left in BOTH layouts), so a blanket "no align-self bare" pin
    // would be wrong. `start` / `end` — the grid-cross-axis values that break the
    // full-width column — must never sit on a bare #home-cta selector, and neither may
    // `flex-end` (its column-axis synonym for `end`, which nothing here uses legitimately).
    // `flex-start` is deliberately NOT in this set: the shared block declares it bare at
    // the base breakpoint, so banning it would flag correct, pre-existing CSS.
    const LEAK = /align-self\s*:\s*(?:start|end|flex-end)\b/;

    test('no bare #home-cta body/button rule sets align-self:start or :end', () => {
        ['.cta__body', '.cta__button'].forEach(cls =>
            rulesMatching(cls)
                .filter(r => LEAK.test(r.body))
                .forEach(r =>
                    r.selectors
                        .filter(s => s.includes('#home-cta') && s.includes(cls))
                        .forEach(s => expect(s).toContain('.cta--inline'))
                )
        );
    });

    // The positive half: the inline grid still gets its intended placement. Deleting the
    // scoped rule would center the inline body/button too, silently undoing the grid
    // layout while the leak guard above stayed green (nothing bare, nothing to catch).
    function scopedAlignSelf(cls) {
        const decls = rulesMatching(cls)
            .filter(r => r.media === DESKTOP
                && r.selectors.includes(`#home-cta.cta--inline ${cls}`))
            .map(r => /align-self\s*:\s*([^;}]+)/.exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    test('the inline body keeps align-self:end, scoped to .cta--inline', () => {
        expect(scopedAlignSelf('.cta__body')).toBe('end');
    });

    test('the inline button keeps align-self:start, scoped to .cta--inline', () => {
        expect(scopedAlignSelf('.cta__button')).toBe('start');
    });

    // The bare rules still describe the inline grid (grid-column:2). If the grid
    // placement is gone, the scoped align-self pins above still pass while describing a
    // layout that no longer exists.
    function bareDecl(cls, prop) {
        const decls = rulesMatching(cls)
            .filter(r => r.media === DESKTOP && r.selectors.includes(`#home-cta ${cls}`))
            .map(r => new RegExp(prop + '\\s*:\\s*([^;}]+)').exec(r.body))
            .filter(Boolean);
        return decls.length ? decls[decls.length - 1][1].trim() : null;
    }

    test('the bare desktop body/button rules keep their grid column', () => {
        expect(bareDecl('.cta__body', 'grid-column')).toBe('2');
        expect(bareDecl('.cta__button', 'grid-column')).toBe('2');
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
 * unchanged), and restores the base two-tier adjacent-sibling rhythm that the
 * premium layer had flattened to a uniform clamp().
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

    // ---- Page-specific CTA ids (#home-cta ...) ----
    // The benchmark closers carry ID-specificity ([1,0,0]) padding rules that
    // outrank the generic .cta slot rule, so they must ALSO route through the
    // slot or --cta-padding-* stays dead on exactly the pages that ship a CTA.
    test('every #*-cta padding declaration routes through --cta-padding-*', () => {
        const SEL = '#home-cta, #how-cta, #agencies-cta, #implementers-cta';
        const bodies = bodiesForExactSelector(SEL);
        const topDecls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        const botDecls = bodies.flatMap(b => b.match(/padding-bottom\s*:[^;}]+/g) || []);
        // Desktop + both mobile blocks each declare top and bottom.
        expect(topDecls.length).toBeGreaterThanOrEqual(2);
        expect(botDecls.length).toBeGreaterThanOrEqual(2);
        topDecls.forEach(d => expect(d).toMatch(/padding-top\s*:\s*var\(\s*--cta-padding-top\b/));
        botDecls.forEach(d => expect(d).toMatch(/padding-bottom\s*:\s*var\(\s*--cta-padding-bottom\b/));
    });

    // ---- Two-tier adjacent-sibling rhythm restored ----
    // The flat premium override was DELETED; the remaining rules for this exact
    // selector are the base (var(--space-lg), desktop) and the uniform mobile
    // literal. Neither may re-introduce the flattening clamp().
    test('adjacent-sibling rhythm no longer flattened by a bare clamp()', () => {
        const bodies = bodiesForExactSelector('main > [data-pp-component] + [data-pp-component]');
        expect(bodies.length).toBeGreaterThanOrEqual(2);
        bodies.forEach(body => {
            expect(body).not.toMatch(/clamp\(\s*4\.25rem/);
        });
    });

    // Each slot-bearing component's adjacent rule exists at BOTH breakpoints:
    // desktop restores the two-tier rhythm (var(--space-lg) fallback), mobile
    // keeps the uniform default (3.35rem fallback). Every declaration routes
    // through the slot; the two fallbacks must both be present so neither
    // breakpoint silently drops the slot.
    test.each([
        ['main > [data-pp-component] + .section', '--section-padding-top'],
        ['main > [data-pp-component] + .grid', '--grid-padding-top'],
        ['main > [data-pp-component] + .cta', '--cta-padding-top'],
    ])('adjacent %s routes top-padding through the slot at both breakpoints', (selector, slot) => {
        const bodies = bodiesForExactSelector(selector);
        const decls = bodies.flatMap(b => b.match(/padding-top\s*:[^;}]+/g) || []);
        // Desktop (two-tier) + mobile (uniform) = two declarations minimum.
        expect(decls.length).toBeGreaterThanOrEqual(2);
        decls.forEach(d => {
            expect(d).toMatch(new RegExp('padding-top\\s*:\\s*var\\(\\s*' + slot + '\\b'));
        });
        // Desktop two-tier restore: at least one falls back to var(--space-lg).
        expect(decls.some(d => new RegExp('var\\(\\s*' + slot + '\\s*,\\s*var\\(\\s*--space-lg\\s*\\)').test(d))).toBe(true);
        // Mobile uniform default: at least one falls back to the 3.35rem literal.
        expect(decls.some(d => new RegExp('var\\(\\s*' + slot + '\\s*,\\s*3\\.35rem\\s*\\)').test(d))).toBe(true);
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
