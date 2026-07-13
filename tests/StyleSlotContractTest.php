<?php
/**
 * tests/StyleSlotContractTest.php
 *
 * KEYSTONE contract test (#92): proves every declared style_slot is actually
 * honored by the renderer's CSS — not just that the slot exists in schema.json.
 *
 * For each styleable component it asserts, scoped to that component's own block
 * in components.css (delimited by the `COMPONENT: <name>` header):
 *   1. the slot is CONSUMED as `var(--slot ...)` inside that block (comments stripped);
 *   2. at least one consumption sits on a property COMPATIBLE with the slot's
 *      declared type (shadow→box-shadow, color→color/background/border-color…,
 *      length→padding/size/radius/border-width…, number→line-height/weight…).
 *
 * A slot accepted by the API but dropped (or mis-wired) by the renderer fails
 * the build. Negative check: delete a `var(--…)` consumption in components.css
 * and testEverySlotConsumedInComponentBlock goes red.
 *
 * Issue 305 added the generalized bypass guard (check 5): components are
 * auto-discovered from schema.json, the slot->subject->property contract is
 * auto-derived from the CSS itself, and any literal re-declaration that defeats
 * a consumed slot fails the build unless explicitly waived in the shrink-only
 * issue 309 ledger. Check 3's hand-maintained map remains as a stricter,
 * value-level pin for the color slots it names.
 *
 * Note: a schema-default == CSS-fallback check was considered (eng-review 7A) but
 * deliberately NOT implemented — slots are consumed multiple times per block with
 * intentionally different fallbacks across theme variants (a slot's base rule may
 * fall back to `inherit` while `.cta--dark`/`.cta--inverted` fall back to a
 * contrasting token). The schema `default` is an AI/human-facing effective-default
 * description, not a literal CSS fallback, so equivalence does not hold by design.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class StyleSlotContractTest extends TestCase
{
    private string $themeRoot;
    private string $css;

    protected function setUp(): void
    {
        $this->themeRoot = dirname(__DIR__);
        $this->css       = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertNotEmpty($this->css, 'components.css should be readable.');
    }

    /**
     * Components that declare style_slots, AUTO-DISCOVERED from components/x/schema.json
     * (issue 305). Previously a hand-maintained list — a NEW component's slots were
     * invisible to every check in this file until someone remembered to add it here.
     * Discovery makes "a new schema slot with no CSS consumer fails out of the box"
     * hold for components that do not exist yet.
     */
    private function styledComponents(): array
    {
        $components = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $schema = json_decode(file_get_contents($schemaFile), true);
            // A malformed schema must fail loudly, not silently exit every check.
            $this->assertNotNull(
                $schema,
                basename(dirname($schemaFile)) . '/schema.json is not valid JSON — discovery would silently skip it.'
            );
            if (!empty($schema['styling']['style_slots'])) {
                $components[] = basename(dirname($schemaFile));
            }
        }
        sort($components);
        return $components;
    }

    /**
     * Fail-closed floor for the discovery itself: if the glob breaks (moved directory,
     * renamed schema files), every discovery-driven check below would pass vacuously
     * over an empty list. Pin the seven components known to declare slots today; new
     * slot-bearing components extend discovery automatically without touching this.
     */
    public function testDiscoveryFindsTheKnownStyledComponents(): void
    {
        $found = $this->styledComponents();
        foreach (['cta', 'faq', 'grid', 'hero', 'section', 'stats', 'testimonials'] as $known) {
            $this->assertContains($known, $found, "Schema discovery lost the {$known} component.");
        }
    }

    /** 1. Every declared slot is consumed in its own component block. */
    public function testEverySlotConsumedInComponentBlock(): void
    {
        foreach ($this->styledComponents() as $component) {
            $block = $this->stripComments($this->componentBlock($component));
            foreach ($this->slots($component) as $slot => $def) {
                $this->assertMatchesRegularExpression(
                    '/var\(\s*' . preg_quote($slot, '/') . '\b/',
                    $block,
                    "Slot {$slot} is declared in {$component}/schema.json but never consumed "
                    . "as var({$slot}) inside the COMPONENT: {$component} block of components.css. "
                    . "Either wire it into the CSS or remove the slot."
                );
            }
        }
    }

    /** 2. Each slot is consumed on a property compatible with its declared type. */
    public function testSlotConsumedOnTypeCompatibleProperty(): void
    {
        foreach ($this->styledComponents() as $component) {
            $block = $this->stripComments($this->componentBlock($component));
            foreach ($this->slots($component) as $slot => $def) {
                $type       = $def['type'] ?? 'length';
                $properties = $this->propertiesConsuming($block, $slot);

                // Only the properties we know how to classify constrain the check;
                // an unmapped property is treated as compatible (lenient), so this
                // never false-fails on novel CSS, only catches clear mismatches.
                $mapped = array_filter($properties, fn ($p) => isset(self::PROPERTY_TYPES[$p]));
                if (empty($mapped)) {
                    continue; // consumed only on unmapped properties — accept.
                }

                $compatible = false;
                foreach ($mapped as $prop) {
                    if (in_array($type, self::PROPERTY_TYPES[$prop], true)) {
                        $compatible = true;
                        break;
                    }
                }

                $this->assertTrue(
                    $compatible,
                    "Slot {$slot} (type {$type}) in {$component} is consumed only on "
                    . "type-incompatible properties [" . implode(', ', array_unique($mapped)) . "]. "
                    . "A {$type} slot must drive a {$type}-compatible property."
                );
            }
        }
    }

    /**
     * 3. Cross-block override guard (#86): a slot can be CONSUMED inside its component
     * block (so checks 1-2 pass) yet still be CLOBBERED by a rule elsewhere in the
     * stylesheet that targets the same element and re-sets the property to a non-slot
     * value. The #86 bug was exactly that — the desktop "premium typography" rule
     * (outside the COMPONENT: grid block) hardcoded `color: var(--color-text)` on
     * `.grid__heading`, burying the dark-band heading on desktop while mobile passed.
     *
     * For each entry in the contract map, scan EVERY rule in the whole stylesheet whose
     * selector targets the mapped class. Any declaration of the mapped property on that
     * selector MUST consume the slot var; a non-slot value fails the build. This is
     * fail-closed: an unexplained hardcoded color on a slotted heading is rejected, not
     * silently allowed.
     *
     * Scope/limitation: keyed on the theme's BEM heading classes (how headings are
     * actually rendered — see grid.php `class="grid__heading"`). Element-only overrides
     * (e.g. a bare `main h2 { color }`) are out of scope by design; widening to element
     * selectors would false-fail on every generic heading rule.
     */
    public function testSlottedPropertyNotClobberedAnywhereInStylesheet(): void
    {
        $css = $this->stripComments($this->css);
        // Innermost rules only: `[^{}]` stops at braces, so rules nested in @media match
        // individually while the @media wrapper (its body holds braces) does not.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        foreach (self::CROSS_BLOCK_SLOT_CONTRACT as $selectorToken => [$slot, $property]) {
            $declsSeen = 0;
            foreach ($rules as $rule) {
                $selector = $rule[1];
                $body     = $rule[2];
                // Boundary-aware match: a plain substring check would treat
                // .grid__heading-accent as a match for the token .grid__heading
                // (BEM's hyphen-based modifier/element separator doesn't create
                // a regex \b word-boundary), incorrectly flagging a deliberately
                // separate element's own, differently-named slot as "clobbering"
                // the token's slot. Require the token NOT be immediately
                // followed by a hyphen or word character.
                if (!preg_match('/' . preg_quote($selectorToken, '/') . '(?![-\w])/', $selector)) {
                    continue;
                }
                // Property declarations in this rule, excluding hyphenated namesakes
                // (so `color` never matches `background-color` / `border-color`).
                if (!preg_match_all(
                    '/(?<![-a-z])' . preg_quote($property, '/') . '\s*:\s*([^;}]+)/i',
                    $body,
                    $decls
                )) {
                    continue;
                }
                $declsSeen += count($decls[1]);
                foreach ($decls[1] as $value) {
                    $consumesSlot = (bool) preg_match(
                        '/var\(\s*' . preg_quote($slot, '/') . '\b/',
                        $value
                    );
                    $this->assertTrue(
                        $consumesSlot,
                        "Rule `" . trim($selector) . "` sets `{$property}` to a value that does NOT "
                        . "consume `{$slot}` (`" . trim($value) . "`). This clobbers the per-instance "
                        . "slot for elements matching `{$selectorToken}` (cross-block override, the #86 "
                        . "class of bug). Either route the value through `var({$slot}, …)` or remove the "
                        . "declaration from this selector."
                    );
                }
            }

            // Fail-closed: the guard is only meaningful if the slot is actually consumed
            // on the mapped selector somewhere. If a refactor removed every consumption,
            // the loop above would pass vacuously — so require at least one declaration.
            $this->assertGreaterThan(
                0,
                $declsSeen,
                "No `{$property}` declaration found on any `{$selectorToken}` rule. The "
                . "cross-block guard for `{$slot}` would pass vacuously — the slot must be "
                . "consumed on its selector, or the contract entry is stale."
            );
        }
    }

    /**
     * 4. Dark-surface foreground authority (#61): a genuinely dark surface
     * (`--inverted`, or `--has-bg-image`'s dark scrim) must not hardcode a
     * foreground `color:` on a specific descendant element — it must route
     * through that component's own `--{component}-*` slot so an AI can fix
     * contrast on that instance without a one-off late-cascade patch (the
     * production incident, PP-004/Ink-2, that #61 was filed from).
     *
     * `--dark` is deliberately EXCLUDED: per this theme's actual token values
     * (base.css), `--color-surface` (#f4f7fb) is a barely-tinted near-white,
     * not a real contrast risk — several schemas even call it "surface
     * background with borders" rather than "dark." Only `--inverted`
     * (`--color-bg-inverted` = #0f172a) and `--has-bg-image` (dark scrim
     * overlay) are genuine low-contrast risks.
     *
     * Scope/limitation: only checks selectors with a DESCENDANT combinator
     * (e.g. `.grid--inverted .grid__heading`) — a bare `.{component}--inverted
     * { color: ... }` sets an ambient/inherited default for whatever isn't
     * otherwise more-specifically slotted (several components additionally
     * redefine a shared token like `--color-muted` at that scope for exactly
     * this purpose), and is not itself the final authority for any specific
     * rendered text role. Requiring it to be slotted too would false-fail on
     * that legitimate pattern without closing any real gap, since the actual
     * rendered elements are covered by their own descendant-selector checks.
     */
    public function testDarkSurfaceVariantsRouteForegroundColorsThroughSlots(): void
    {
        $css = $this->stripComments($this->css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $variantPattern = '/\.(hero|cta|grid|section|faq|stats|pp-section)[a-z_-]*--(inverted|has-bg-image)\S*\s+\S/i';
        $checked = 0;

        foreach ($rules as $rule) {
            $selector = trim($rule[1]);
            $body     = $rule[2];

            if (!preg_match($variantPattern, $selector, $m)) {
                continue; // not a dark-surface descendant selector
            }
            $component = strtolower($m[1]) === 'pp-section' ? 'section' : strtolower($m[1]);

            if (!preg_match('/(?<![-a-z])color\s*:\s*([^;}]+)/i', $body, $colorMatch)) {
                continue; // this rule doesn't set color at all
            }
            $value = trim($colorMatch[1]);
            $checked++;

            $this->assertMatchesRegularExpression(
                '/var\(\s*--' . preg_quote($component, '/') . '-/',
                $value,
                "Rule `{$selector}` sets `color` to `{$value}` on a dark-surface descendant "
                . "without routing through a --{$component}-* slot. Dark-surface foreground "
                . "colors must be fixable via safe surfaces without a late-cascade patch (#61)."
            );
        }

        // Fail-closed: this guard is only meaningful if it actually examined something.
        $this->assertGreaterThan(0, $checked, 'No dark-surface descendant color rules found — the #61 guard would pass vacuously.');
    }

    /**
     * 5. GENERALIZED bypass guard (issue 305): auto-derived, fail-closed successor to
     * check 3's hand-maintained CROSS_BLOCK_SLOT_CONTRACT map. That map was fail-OPEN —
     * the #302 padding/type/width slots and #292's border slot were never added to it,
     * so they shipped dead three separate times while every check here stayed green.
     *
     * How it works (no hand-listed contract):
     *   DERIVE — for every `var(--slot)` consumption in components.css, record the
     *   (subject, property, slot) triple. The subject is each class in the LAST
     *   compound of the selector (the element the rule actually styles), tagged with
     *   its pseudo-element so `::before` boxes are tracked separately from the host
     *   box, and matched to the slot's owning component by BEM block (`.grid__item`
     *   -> grid, `.pp-section--inverted` -> section). Shared primitives styled by a
     *   DIFFERENT component's slot family (`.btn` inside `.hero` rules) derive no
     *   triple — they are per-context surfaces, not the dead-slot class.
     *
     *   ENFORCE — every rule in the whole stylesheet whose subject matches a derived
     *   triple and re-declares its property must route the value through the slot.
     *   A value routing through ANY OTHER declared slot of the same component is
     *   allowed (`.faq__item[open] > .faq__question { color: var(--faq-accent) }` is
     *   an intentional state handoff to a sibling slot, still author-controllable).
     *   Anything else is a bypass: the exact mechanism of #226/#292/#302/#61.
     *
     * KNOWN-DEAD WAIVERS: the audit this guard performed on landing found 27
     * slot/surface pairs (56 declaration instances; chained var() fallbacks make
     * some physical declarations kill several slots at once, accounted per slot)
     * that were ALREADY dead — filed as issue 309 with per-pair evidence. Issue
     * 309 burned that ledger down: 21 pairs (49 declarations across hero, section,
     * grid, and cta) were routed through var(--slot, <literal>) (the #226/#302
     * idiom, unset output byte-identical), so their entries are GONE from this map.
     *
     * The 6 entries below are the two decision-flagged pair GROUPS the issue 309
     * ✅ decision (2026-07-12) singled out: routing them would change intended
     * interaction/variant semantics, so they are PERMANENT, documented waivers —
     * the only entries allowed to survive here. Each is explained inline. No other
     * pair may ever be waived: a new dead slot is fixed or gets its own issue.
     *
     * The ledger is SHRINK-ONLY:
     *   - a waived pair that stops offending fails (remove its entry with the fix);
     *   - a count drop fails (partial fix — shrink the count in the same change);
     *   - a count rise or a new pair fails (a NEW dead slot; fix it or file an issue
     *     and add a waiver citing it — never add one silently);
     *   - the ledger-size pin below must be updated in the same change, so a waiver
     *     can never slip in through a merge unnoticed.
     */
    private const KNOWN_DEAD_SLOT_WAIVERS = [
        // issue 309 PERMANENT WAIVER — grid link hover (decision-flagged group 1).
        // Base .grid__item-link { color: var(--grid-link-color, ...) } is the resting
        // color; .grid__item-link:hover { color: var(--color-accent-hover) } is the
        // hover state, which MUST visually override the slotted resting value.
        // Routing the hover through the slot would make hover render identical to
        // rest whenever an author sets --grid-link-color, destroying the hover
        // feedback — the exact "hover state that must override the slot" case the
        // decision names as a permanent exception.
        '--grid-link-color|.grid__item-link|color'                       => 1,
        // issue 309 PERMANENT WAIVER — testimonials --stack variant resets
        // (decision-flagged group 2). The .testimonials--stack variant is a
        // card-LESS presentation by design (components.css: "single centered column,
        // no card chrome"); its .testimonials__item resets (padding:0, transparent
        // bg, border:none, box-shadow:none) exist specifically to neutralize the card
        // slots. Routing them through the card slots would let a set card slot leak
        // card chrome back into the stack variant, changing what "stack" renders —
        // the "variant reset that exists to neutralize a slot by design" case the
        // decision names as a permanent exception.
        '--testimonials-card-bg|.testimonials__item|background'          => 2,
        '--testimonials-card-border-width|.testimonials__item|border'    => 1,
        '--testimonials-card-border|.testimonials__item|border'          => 1,
        '--testimonials-card-padding|.testimonials__item|padding'        => 1,
        '--testimonials-card-shadow|.testimonials__item|box-shadow'      => 1,
    ];

    public function testDeclaredSlotsNotBypassedByLiteralReDeclarations(): void
    {
        $analysis  = $this->slotBypassOffenders($this->css, $this->slotsByComponent());
        $offenders = $analysis['offenders'];

        // Fail-closed floor: derivation over the real stylesheet must find a healthy
        // triple population, or a parser regression could silently gut the guard.
        // 147 triples today; the floor sits close enough to catch a third of them
        // vanishing while leaving room for ordinary CSS evolution.
        $this->assertGreaterThan(
            130,
            $analysis['tripleCount'],
            'Slot-consumption derivation collapsed — the bypass guard would pass vacuously.'
        );

        $failures = [];

        foreach ($offenders as $key => $decls) {
            $waived = self::KNOWN_DEAD_SLOT_WAIVERS[$key] ?? null;
            if ($waived === null) {
                $failures[] = "NEW dead slot: {$key} is bypassed by " . count($decls)
                    . " literal declaration(s):\n    " . implode("\n    ", $decls)
                    . "\n  Route each through var(<slot>, <literal>) (the #226/#302 idiom), "
                    . "or file an issue and add a waiver citing it.";
            } elseif (count($decls) > $waived) {
                $failures[] = "{$key}: bypass count rose ({$waived} waived, " . count($decls)
                    . " found) — a new literal re-declaration was added:\n    "
                    . implode("\n    ", $decls);
            } elseif (count($decls) < $waived) {
                $failures[] = "{$key}: bypass count dropped ({$waived} waived, " . count($decls)
                    . " found) — a fix landed; shrink this waiver in the same change "
                    . "(the issue 309 ledger is shrink-only).";
            }
        }

        foreach (self::KNOWN_DEAD_SLOT_WAIVERS as $key => $waived) {
            if (!isset($offenders[$key])) {
                $failures[] = "STALE waiver: {$key} no longer offends — remove its entry "
                    . "from KNOWN_DEAD_SLOT_WAIVERS (and update the ledger-size pin).";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Slot-contract bypass guard (issue 305):\n- " . implode("\n- ", $failures)
        );
    }

    /**
     * Ledger-size pin, EXACT: any ledger edit in either direction — adding a
     * waiver (even a remove-one-add-one swap the <= form would let through) or
     * removing one — must also touch this test, so no waiver ever slips in or
     * drifts out through a merge unnoticed.
     */
    public function testWaiverLedgerOnlyShrinks(): void
    {
        $this->assertSame(6, count(self::KNOWN_DEAD_SLOT_WAIVERS),
            'The issue 309 waiver ledger changed size. Fixes shrink it (update this pin in the same change); new dead slots are fixed or get their own issue — never silently waived. The 6 surviving entries are the two decision-flagged permanent-waiver groups (grid-link hover + testimonials --stack resets).');
        $this->assertSame(7, array_sum(self::KNOWN_DEAD_SLOT_WAIVERS),
            'Total waived bypass declarations changed. Update this pin in the same change as the ledger edit it reflects.');
    }

    /**
     * Detection proof (issue 305 acceptance): the guard must catch the CLASS, not just
     * today's instances. This fixture reproduces, in miniature, the exact mechanisms of
     * the three shipped incidents — plus the two intentional patterns the guard must
     * NOT flag — so the detector's power is proven in CI forever, without depending on
     * git history. (The one-time proof against the real pre-#292/#302 tree lives in the
     * PR: run against `git show a6a6bf3:assets/css/components.css`, this guard reports
     * the #302 padding families, --grid-heading-size, the --section-title-size premium
     * clobber, and #292's --grid-card-border as direct unwaived offenders, and goes red
     * on --section-body-width via the ledger. That slot's pre-fix shape — a literal on
     * the never-slotted OUTER .section__body capping the slotted inner .section__content
     * — is a parent-constrains-child bug no same-subject textual scan can prove; the
     * rendered-output E2E pins are the layer that owns that class.)
     */
    public function testGuardDetectsTheDeadSlotClass(): void
    {
        $slots = [
            'grid' => [
                '--grid-padding-top'  => 'length',  // #302 mechanism: later bare re-declaration
                '--grid-card-border'  => 'color',   // #292 mechanism: higher-specificity literal
                '--grid-card-bg'      => 'color',   // negative control: type-compatible alt-slot escape
                '--grid-accent'       => 'color',
                '--grid-heading-size' => 'length',  // laundering probe: length slot on a color property
            ],
        ];

        $fixture = <<<'CSS'
            .grid { padding-top: var(--grid-padding-top, var(--space-xl)); }
            .grid__item { border-color: var(--grid-card-border, var(--color-border)); }
            .grid__item { background: var(--grid-card-bg, transparent); }
            @media (min-width: 768px) {
              .grid { padding-top: clamp(4.25rem, 6vw, 5rem); }          /* #302: bypass */
            }
            main > .grid .grid__item { border-color: var(--color-border); } /* #292: bypass */
            main > .grid--tight { padding: 4rem 0; }                        /* not a subject match */
            .grid { padding: 4rem 0; }                       /* shorthand reset: bypass */
            .grid--featured .grid__item { background: var(--grid-accent, gold); } /* alt-slot, color-on-color: OK */
            .grid--loud .grid__item { border-color: var(--grid-heading-size, red); } /* laundering: length slot on color prop */
            .grid__item::before { background: linear-gradient(red, blue); }  /* pseudo box: OK */
            CSS;

        $offenders = $this->slotBypassOffenders($fixture, $slots)['offenders'];

        $this->assertArrayHasKey(
            '--grid-padding-top|.grid|padding-top',
            $offenders,
            'The guard failed to detect the #302 mechanism (later bare re-declaration).'
        );
        $this->assertArrayHasKey(
            '--grid-padding-top|.grid|padding',
            $offenders,
            'The guard failed to detect a shorthand reset (padding: kills a padding-top slot).'
        );
        $this->assertArrayHasKey(
            '--grid-card-border|.grid__item|border-color',
            $offenders,
            'The guard failed to detect the #292 mechanism (higher-specificity literal).'
        );
        // Laundering: a LENGTH sibling slot on a COLOR property is not a state
        // handoff — the type gate must reject it and report the declaration.
        $launderKey = '--grid-card-border|.grid__item|border-color';
        $this->assertTrue(
            (bool) array_filter($offenders[$launderKey], fn ($d) => str_contains($d, '--grid-heading-size')),
            'The guard let a literal launder through a type-incompatible sibling slot.'
        );
        $this->assertCount(
            3,
            $offenders,
            'The guard over-flagged: the type-compatible alt-slot handoff and the '
            . 'pseudo-element box must not be reported. Got: ' . implode(', ', array_keys($offenders))
        );
    }

    /**
     * Featured first-card remnant slots (issue 293): value-level fallback pins.
     *
     * The generic checks above prove the slots are consumed and unbypassed; they do
     * NOT pin the fallback literals. Byte-identical unset output depends on those
     * literals being exactly the values that used to be hardcoded, in a two-tier
     * shape (base card vs featured first card), plus the mobile featured-shadow
     * chain — a fourth consumer the issue body never listed, where the slot would
     * otherwise silently no-op below 768px.
     */
    public function testIssue293FeaturedRemnantSlotFallbacks(): void
    {
        $block = $this->stripComments($this->componentBlock('grid'));

        // Bar slots, two-tier: base hairline vs featured accent gradient.
        $this->assertStringContainsString('height: var(--grid-card-bar-height, 2px)', $block);
        $this->assertStringContainsString('background: var(--grid-card-bar-color, var(--color-border))', $block);
        $this->assertStringContainsString('height: var(--grid-card-bar-height, 4px)', $block);
        $this->assertStringContainsString(
            'background: var(--grid-card-bar-color, linear-gradient(90deg, var(--color-accent), color-mix(in srgb, var(--color-accent) 18%, transparent)))',
            $block
        );

        // Texture stripe: featured-only color slot over the original 0.055 literal.
        $this->assertStringContainsString(
            'linear-gradient(90deg, var(--grid-featured-texture-color, rgba(37, 99, 235, 0.055)) 1px, transparent 1px)',
            $block
        );

        // Featured glow: featured slot first, shared card shadow second (unchanged
        // semantics), original glow literal last.
        $this->assertMatchesRegularExpression(
            '/box-shadow:\s*var\(--grid-featured-shadow,\s*var\(--grid-card-shadow,\s*inset 0 0 0 1px rgba\(37, 99, 235, 0\.055\),\s*0 18px 42px rgba\(37, 99, 235, 0\.10\)\)\)/',
            $block
        );

        // Mobile featured glow (max-width: 767px, final cascade) re-declares the
        // same chain with its own literal — delete the chain there and the slot
        // reports success while mobile renders the old glow.
        $css = $this->stripComments($this->css);
        $this->assertStringContainsString(
            'box-shadow: var(--grid-featured-shadow, var(--grid-card-shadow, 0 14px 32px rgba(37, 99, 235, 0.09)))',
            $css,
            'The mobile featured-glow rule must route through --grid-featured-shadow (issue 293).'
        );
    }

    /**
     * Hero proof-line color slot (issue 296): byte-identical-unset fallback pin.
     *
     * The base .hero__proof rule hardcoded `color: var(--color-muted)` with no
     * per-instance slot, so every dark hero shipped a dark-on-dark proof line
     * (same literal-token-defeats-theming family as #222/#248/#292). Routing it
     * through --hero-proof-color lets a dark hero lift the proof color. The generic
     * checks above already prove the slot is consumed and unbypassed; this pins the
     * exact fallback literal so unset output stays byte-identical to the old value.
     * The proof line has a single color declaration (no premium/inverted re-declare),
     * so this one rule is the whole surface.
     */
    public function testIssue296HeroProofColorSlotFallback(): void
    {
        $block = $this->stripComments($this->componentBlock('hero'));
        $this->assertStringContainsString(
            'color: var(--hero-proof-color, var(--color-muted))',
            $block,
            'The .hero__proof base rule must route color through --hero-proof-color '
            . 'with --color-muted as the fallback (issue 296, byte-identical unset).'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Cross-block override contract: `selector class token => [slot, property]`.
     * These slots are consumed both inside their COMPONENT block AND in the global
     * "premium typography" media rules, so they are the ones at risk of a cross-block
     * clobber. Extend this map when a new slot becomes reachable outside its block.
     */
    private const CROSS_BLOCK_SLOT_CONTRACT = [
        '.grid__heading'     => ['--grid-heading-color', 'color'],
        '.section__title'    => ['--section-title-color', 'color'],
        // Body/content + card text slots are ALSO re-declared in the desktop
        // "premium typography" media rules. The original #86 fix only covered
        // the two heading slots above; these four were left clobbered (desktop
        // hardcoded a token, ignoring the per-instance slot) until caught by a
        // dev smoke test against a dark-band benchmark. Same cross-block class.
        '.section__content'  => ['--section-text', 'color'],
        '.grid__item-title'  => ['--grid-item-title-color', 'color'],
        '.grid__item-text'   => ['--grid-item-text-color', 'color'],
        '.cta__body'         => ['--cta-body-color', 'color'],
        // faq (#100): this is the exact bug the "premium typography" comment above
        // already documented ("faq has no heading-color slot, so it keeps the token")
        // before #100 added one — .faq__heading/.faq__answer are re-declared in the
        // same desktop media rules as the slots above.
        // NOTE: .faq__question is deliberately NOT in this map. Its open-accordion
        // state (.faq__item[open] > .faq__question) intentionally uses a DIFFERENT
        // slot (--faq-accent, not --faq-question-color) — a real, in-component state
        // change, not a cross-block clobber. This test matches by class substring
        // across the whole stylesheet and can't distinguish "different intentional
        // state" from "accidental override," so .faq__question would false-fail here.
        // The desktop cross-block rule for .faq__question is still fixed (routes
        // through --faq-question-color) — just not covered by this automated guard.
        '.faq__heading'      => ['--faq-heading-color', 'color'],
        '.faq__answer'       => ['--faq-answer-color', 'color'],
    ];

    /**
     * Property → compatible slot types. Properties absent from this map are
     * treated as compatible (lenient) so the test never false-fails on novel CSS.
     */
    private const PROPERTY_TYPES = [
        'box-shadow'                 => ['shadow'],
        'color'                      => ['color'],
        'background'                 => ['color', 'gradient'],
        // Deliberately NOT ['color', 'gradient'] — background-color: cannot hold a
        // gradient in real CSS (that's the exact #99 bug: --hero-bg/--grid-card-bg
        // were consumed via background-color: in some rules, silently invalid for a
        // gradient override). Keeping this list ['color']-only means a future
        // gradient-typed slot wired to background-color: still gets caught here
        // (unless it's ALSO consumed compatibly elsewhere — see the "at least one
        // compatible use" note above).
        'background-color'           => ['color'],
        'border'                     => ['length', 'color'],
        'border-top'                 => ['length', 'color'],
        'border-bottom'              => ['length', 'color'],
        'border-color'               => ['color'],
        'border-top-color'           => ['color'],
        'border-right-color'         => ['color'],
        'border-bottom-color'        => ['color'],
        'border-left-color'          => ['color'],
        'outline-color'              => ['color'],
        'fill'                       => ['color'],
        'stroke'                     => ['color'],
        'padding'                    => ['length'],
        'padding-top'                => ['length'],
        'padding-right'              => ['length'],
        'padding-bottom'             => ['length'],
        'padding-left'               => ['length'],
        'margin'                     => ['length'],
        'margin-top'                 => ['length'],
        'margin-bottom'              => ['length'],
        'gap'                        => ['length'],
        'row-gap'                    => ['length'],
        'column-gap'                 => ['length'],
        'width'                      => ['length'],
        'min-width'                  => ['length'],
        'max-width'                  => ['length'],
        'height'                     => ['length'],
        'font-size'                  => ['length'],
        'border-width'               => ['length'],
        'border-top-width'           => ['length'],
        'border-bottom-width'        => ['length'],
        'border-radius'              => ['length'],
        'letter-spacing'             => ['length'],
        'line-height'                => ['number'],
        'font-weight'                => ['number'],
        'opacity'                    => ['number'],
        'font-family'                => ['font-family'],
    ];

    /**
     * WP core's global stylesheet (block-library / global-styles) ships
     * attribute-SUBSTRING selectors, verified in the rendered page on WP 7.0:
     *
     *   html :where([style*="border-width"])      { border-style: solid }
     *   html :where([style*="border-color"])      { border-style: solid }
     *   html :where([style*="border-top-width"])  { border-top-style: solid }
     *   ... and the right/bottom/left twins for both width and color (11 unscoped
     *   rules in total; every other [style*=…] trigger core ships is scoped to a
     *   .wp-block-* class this theme never emits).
     *
     * Core means the block editor's `style="border-width:2px"`. We render style slots
     * as inline CUSTOM PROPERTIES, so a slot whose NAME embeds any of these substrings
     * makes the selector match our component root on the property name alone — even
     * when the value is 0 and the border lives on a descendant.
     *
     * Matched as a regex, not a fixed 2-item list: a future slot named
     * `--x-border-top-width` trips core's per-side rule while containing NEITHER
     * "border-width" nor "border-color" (adversarial-review finding).
     */
    private const WP_CORE_BORDER_TRIGGER_REGEX = '/border(?:-(?:top|right|bottom|left))?-(?:width|color)/';

    /**
     * The elements that can carry a slot's inline custom properties: every component
     * root (all 12 carry data-pp-component) and the per-card .grid__item of issue 306.
     * These are what the immunity baseline must cover.
     */
    private const BORDER_IMMUNITY_SELECTORS = ['[data-pp-component]', '.grid__item'];

    /**
     * The baseline must DECLARE these longhands with these VALUES. Asserting the value —
     * not merely the property name — is load-bearing: a baseline reading
     * `border-style: solid; border-width: 3px` names both properties and IS issue 332,
     * rendered by our own stylesheet. A name-only check green-lights it
     * (adversarial-review finding 1).
     */
    private const BORDER_IMMUNITY_DECLARATIONS = [
        'border-style' => '/^(none|hidden)$/i',
        'border-width' => '/^0(px|em|rem|%)?$/i',
    ];

    /**
     * 7. Third-party cascade immunity (issue 332).
     *
     * The #305 bypass guard cannot see this class of defect: the slot IS consumed,
     * our CSS text IS correct, and the damage is contributed by a FOREIGN stylesheet
     * at runtime — core's rule lands `border-style: solid` on a root that declared no
     * border, which then computes at the initial `medium` width (3px). The 1.0-H
     * dogfood lost two documented slots to it.
     *
     * Division of labour, stated honestly: only a rendered box under real core CSS can
     * prove the immunity holds, and that pin lives in tests/e2e/style-render.spec.ts
     * (`#332 …`), which asserts the computed border per affected component. THIS check
     * owns the half a browser cannot: it fails when a NEW slot name embeds a core
     * trigger substring while the baseline does not cover the surface that carries it —
     * i.e. it keeps the immunity honest as the slot surface grows, without waiting for
     * someone to notice a 3px border on a page.
     */
    public function testBorderTriggerSlotsHaveCascadeImmunity(): void
    {
        $triggerSlots = $this->borderTriggerSlots();

        // Fail-closed floor: 13 such slots exist today (issue 332). If discovery breaks,
        // every assertion below would pass over an empty list.
        $this->assertGreaterThanOrEqual(
            13,
            count($triggerSlots),
            'Discovery found fewer border-trigger slots than the 13 known at issue 332 — '
            . 'the schema scan is broken and this guard would pass vacuously.'
        );

        $gaps = $this->immunityGaps($this->css);
        $this->assertSame(
            [],
            $gaps,
            "components.css does not carry the issue 332 immunity baseline:\n  - "
            . implode("\n  - ", $gaps)
            . "\n\nSlots whose NAME embeds a WP-core trigger substring (so core's "
            . ":where([style*=…]) matches the element that carries them inline):\n  "
            . implode("\n  ", array_keys($triggerSlots))
        );
    }

    /**
     * Every element that receives a slot's inline custom properties must be covered by
     * the immunity baseline. Today the renderer echoes its `pp_render_style_vars()`
     * output onto exactly two kinds of element: the component root (data-pp-component)
     * and grid's per-card .grid__item. Moving an inline style attribute onto some other
     * element would silently re-open issue 332 on that element — this fails if that happens.
     */
    public function testInlineSlotSurfacesAreCoveredByTheImmunityBaseline(): void
    {
        $emitted   = 0;
        $generated = 0;

        foreach (glob($this->themeRoot . '/components/*/*.php') as $template) {
            $source = file_get_contents($template);

            // Every call that BUILDS inline slot custom properties. Each one must end up
            // echoed onto an immune element — comparing the two counts is what stops a new
            // surface from slipping past the line scan below (Codex outside-voice finding:
            // a regex over echo lines alone is bypassable).
            $generated += preg_match_all('/pp_render_style_vars\s*\(/', $source);

            foreach (file($template) as $i => $line) {
                // Match both the long `echo $x_style_attr;` form and the short-echo
                // `<?=` form, plus any variable whose name carries style+attr.
                // (No literal PHP close tag in this comment — it would end PHP mode.)
                if (!preg_match('/(?:echo|<\?=)\s*\$[a-z_]*style[a-z_]*attr/i', $line)) {
                    continue;
                }
                $emitted++;
                $covered = str_contains($line, 'data-pp-component=')
                    || preg_match('/class="[^"]*\bgrid__item\b/', $line) === 1;

                $this->assertTrue(
                    $covered,
                    basename($template) . ':' . ($i + 1) . " emits inline style slots onto an element "
                    . "that the issue 332 immunity baseline does not cover ("
                    . implode(' / ', self::BORDER_IMMUNITY_SELECTORS) . "). WP core's "
                    . ":where([style*=border-width]) will match it on the slot NAME and inject a "
                    . "3px solid border. Give the element data-pp-component, or extend the baseline "
                    . "in components.css AND self::BORDER_IMMUNITY_SELECTORS.\n  " . trim($line)
                );
            }
        }

        // Fail-closed: 7 styled components render a root style attr, grid renders a
        // per-card one too. If the scan finds nothing, the loop above proved nothing.
        $this->assertGreaterThanOrEqual(
            8,
            $emitted,
            'Found fewer inline slot surfaces than the 8 known today — the template scan is broken.'
        );

        // Every pp_render_style_vars() call must reach an emit site the loop above actually
        // inspected. If a template starts routing one through a helper or a differently
        // named variable, `emitted` drops below `generated` and this trips — instead of the
        // surface going silently unguarded (Codex outside-voice finding).
        //
        // NOT equality: footer.php legitimately emits inline custom properties
        // (--footer-bg/-text/-link-color, from site options) WITHOUT pp_render_style_vars,
        // because footer declares no schema style_slots. It is still an inline
        // custom-property surface, so it is still covered above via data-pp-component —
        // which is exactly the immunity this check exists to enforce. Extra emit sites are
        // fine and get coverage-checked; a MISSING one is the bug.
        $this->assertGreaterThanOrEqual(
            $generated,
            $emitted,
            "pp_render_style_vars() is called {$generated}x but only {$emitted} inline style "
            . 'attribute(s) were found. A slot surface is being emitted by a path this guard '
            . 'cannot see — extend the scan (issue 332).'
        );
    }

    /**
     * Negative control (mirrors testGuardDetectsTheDeadSlotClass): the immunity check
     * must actually FAIL on CSS that lacks the baseline. Without this, a refactor that
     * broke `immunityGaps()` would leave check 7 passing on an empty result forever.
     */
    public function testImmunityGuardDetectsAMissingBaseline(): void
    {
        // No baseline at all.
        $this->assertNotSame([], $this->immunityGaps('.grid { padding: 1rem; }'));

        // Baseline present but only covers the roots — the per-card surface is exposed.
        $this->assertNotSame(
            [],
            $this->immunityGaps('[data-pp-component] { border-style: none; border-width: 0; }')
        );

        // Baseline declares style but not width: core injecting a WIDTH would still land.
        $this->assertNotSame(
            [],
            $this->immunityGaps('[data-pp-component], .grid__item { border-style: none; }')
        );

        // Baseline present but BELOW a component block: the component rules (equal
        // specificity, (0,1,0)) no longer win on source order — it would clobber them.
        $this->assertNotSame(
            [],
            $this->immunityGaps(
                "/* COMPONENT: nav */\n.nav { color: red; }\n"
                . '[data-pp-component], .grid__item { border-style: none; border-width: 0; }'
            )
        );

        // The real shape passes.
        $this->assertSame(
            [],
            $this->immunityGaps(
                "[data-pp-component],\n.grid__item { border-style: none; border-width: 0; }\n"
                . "/* COMPONENT: nav */\n.nav { color: red; }"
            )
        );

        // ...and so does the equivalent split into one rule per surface. The guard checks
        // cascade coverage, not formatting (Codex outside-voice finding: requiring a single
        // combined rule would reject a perfectly valid implementation).
        $this->assertSame(
            [],
            $this->immunityGaps(
                "[data-pp-component] { border-style: none; border-width: 0; }\n"
                . ".grid__item { border-style: none; border-width: 0; }\n"
                . "/* COMPONENT: nav */\n.nav { color: red; }"
            )
        );

        // --- Bypasses closed after adversarial review. Each of these NAMES both longhands
        // --- and would have passed the name-only/regex-parsed guard while shipping the bug.

        // 1. Values are solid/3px: this rule IS issue 332, drawn by our own stylesheet.
        $this->assertNotSame(
            [],
            $this->immunityGaps('[data-pp-component], .grid__item { border-style: solid; border-width: 3px; }'),
            'A baseline declaring solid/3px must NOT count as immunity — it is the defect itself.'
        );

        // 2. Baseline nested in an at-rule: immune above 768px, 3px border on every phone.
        $this->assertNotSame(
            [],
            $this->immunityGaps(
                "@media (min-width: 768px) {\n"
                . "  [data-pp-component], .grid__item { border-style: none; border-width: 0; }\n"
                . "}\n/* COMPONENT: nav */\n.nav { color: red; }"
            ),
            'A baseline inside @media only applies at that breakpoint — it is not immunity.'
        );

        // 3. Ancestor-scoped baseline: roots outside .wrapper stay exposed.
        $this->assertNotSame(
            [],
            $this->immunityGaps('.wrapper [data-pp-component], .wrapper .grid__item { border-style: none; border-width: 0; }'),
            'A descendant-scoped baseline does not immunize roots outside that ancestor.'
        );

        // The at-rule-aware parser must still see a legitimate baseline that merely has an
        // @media block ABOVE it (the regex parser used to lift inner rules out to top level).
        $this->assertSame(
            [],
            $this->immunityGaps(
                "@media (min-width: 768px) { .unrelated { color: red; } }\n"
                . "[data-pp-component], .grid__item { border-style: none; border-width: 0; }\n"
                . "/* COMPONENT: nav */\n.nav { color: red; }"
            )
        );
    }

    /**
     * Core's per-SIDE triggers (`[style*=border-top-width]` → `border-top-style: solid`) mean
     * a slot named `--x-border-top-width` is a trigger while containing neither "border-width"
     * nor "border-color". The old fixed 2-substring list dropped it silently
     * (adversarial-review finding 6). No such slot exists today; the regex is what keeps the
     * discovery honest if one is ever added.
     */
    public function testBorderTriggerDiscoveryCoversPerSideCoreRules(): void
    {
        foreach (
            [
                '--grid-card-border-width'   => true,
                '--faq-border-color'         => true,
                '--x-border-top-width'       => true,  // core: [style*=border-top-width]
                '--x-border-left-color'      => true,  // core: [style*=border-left-color]
                '--grid-card-border'         => false, // no width/color suffix — no core rule
                '--grid-card-radius'         => false,
                '--grid-card-bar-height'     => false, // core's [style*=height] is .wp-block-* scoped
            ] as $slot => $isTrigger
        ) {
            $this->assertSame(
                $isTrigger,
                (bool) preg_match(self::WP_CORE_BORDER_TRIGGER_REGEX, $slot),
                "Border-trigger discovery misclassified `{$slot}`."
            );
        }
    }

    /** Slots (across every component schema) whose NAME embeds a WP-core trigger substring. */
    private function borderTriggerSlots(): array
    {
        $trigger = [];
        foreach ($this->styledComponents() as $component) {
            foreach ($this->slots($component) as $slot => $type) {
                if (preg_match(self::WP_CORE_BORDER_TRIGGER_REGEX, $slot)) {
                    $trigger[$slot] = $component;
                }
            }
        }
        ksort($trigger);
        return $trigger;
    }

    /**
     * TOP-LEVEL rules only: [ ['selector' => …, 'body' => …, 'offset' => …], … ].
     *
     * A brace-counting scan, not a regex. `/([^{}]+)\{([^{}]*)\}/` cannot match a block
     * whose body contains braces, so it SKIPS the `@media` wrapper and lifts the rules
     * inside it out to look top-level. That made a baseline hidden inside
     * `@media (min-width: 768px)` — or `@media print` — read as immune while every phone
     * rendered the 3px border (adversarial-review finding 2). At-rule bodies are stepped
     * over wholesale here, so a baseline nested in one is simply NOT FOUND, and the caller
     * fails closed.
     */
    private function topLevelRules(string $css): array
    {
        $rules  = [];
        $len    = strlen($css);
        $i      = 0;
        $selStart = 0;

        while ($i < $len) {
            $ch = $css[$i];

            if ($ch === '{') {
                $selector = trim(substr($css, $selStart, $i - $selStart));

                // Step over the whole balanced block.
                $depth = 1;
                $bodyStart = $i + 1;
                $j = $bodyStart;
                while ($j < $len && $depth > 0) {
                    if ($css[$j] === '{') {
                        $depth++;
                    } elseif ($css[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }

                // An at-rule (@media/@supports/@layer) is NOT a style rule, and its inner
                // rules are not top-level. Skip the block entirely — do not descend.
                if ($selector !== '' && $selector[0] !== '@') {
                    $rules[] = [
                        'selector' => $selector,
                        'body'     => substr($css, $bodyStart, ($j - 1) - $bodyStart),
                        'offset'   => $selStart,
                    ];
                }

                $i = $j;
                $selStart = $i;
                continue;
            }

            $i++;
        }

        return $rules;
    }

    /** True when this rule is a valid immunity baseline for $surface. */
    private function isBaselineFor(array $rule, string $surface): bool
    {
        // Anchored, not str_contains: `.wrapper [data-pp-component]` CONTAINS the surface
        // but only immunizes roots inside .wrapper, leaving every other root exposed
        // (adversarial-review finding 3). Require the surface to stand alone as one whole
        // comma-separated compound selector.
        $selects = false;
        foreach (explode(',', $rule['selector']) as $part) {
            if (trim($part) === $surface) {
                $selects = true;
                break;
            }
        }
        if (!$selects) {
            return false;
        }

        // VALUES, not just property names — see BORDER_IMMUNITY_DECLARATIONS.
        foreach (self::BORDER_IMMUNITY_DECLARATIONS as $property => $valuePattern) {
            if (!preg_match(
                '/(?<![-a-z])' . preg_quote($property, '/') . '\s*:\s*([^;}]+)/i',
                $rule['body'],
                $m
            )) {
                return false;
            }
            if (!preg_match($valuePattern, trim($m[1]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the reasons the given CSS fails to grant issue 332 immunity ([] = immune).
     *
     * A surface is immune when SOME top-level rule (a) selects exactly that surface,
     * (b) declares border-style:none AND border-width:0, and (c) sits ABOVE the first
     * component rule. (c) is load-bearing: an attribute selector and a class selector both
     * weigh (0,1,0), so a baseline placed below the component rules would win on source
     * order and erase the borders components legitimately draw.
     */
    private function immunityGaps(string $css): array
    {
        $stripped = $this->stripComments($css);
        $rules    = $this->topLevelRules($stripped);
        $gaps     = [];

        $firstComponentRule = $this->firstComponentRuleOffset($rules);

        // Each surface is checked INDEPENDENTLY: one combined rule and one rule per surface
        // are both valid CSS, and the guard must not couple correctness to formatting.
        foreach (self::BORDER_IMMUNITY_SELECTORS as $surface) {
            $baselineOffset = null;

            foreach ($rules as $rule) {
                if ($this->isBaselineFor($rule, $surface)) {
                    $baselineOffset = $rule['offset'];
                    break;
                }
            }

            if ($baselineOffset === null) {
                $gaps[] = "no TOP-LEVEL rule selects exactly `{$surface}` while declaring "
                    . 'border-style:none + border-width:0 — WP core will inject '
                    . '`border-style: solid` at the initial 3px width on any element of this '
                    . 'kind that carries a border-trigger slot. (A baseline nested in an '
                    . '@media/@supports/@layer block, scoped under an ancestor, or declaring '
                    . 'a non-zero/solid value does NOT count.)';
                continue;
            }

            // Source order. Deliberately CONSERVATIVE: the baseline only needs to outrank
            // WP core's (0,0,1) rule, which it does on specificity alone. But the component
            // rules that legitimately draw borders weigh the SAME (0,1,0) as the baseline,
            // so they only beat it by coming later. Requiring "baseline above the component
            // rules" is a sufficient (not necessary) condition for both to hold, and it is
            // the one a human can check by eye.
            if ($firstComponentRule !== null && $baselineOffset > $firstComponentRule) {
                $gaps[] = "the `{$surface}` baseline sits BELOW the first component rule; at "
                    . 'equal (0,1,0) specificity it would override the borders components draw';
            }
        }

        return $gaps;
    }

    /** Offset of the first top-level rule whose subject is a known component root class. */
    private function firstComponentRuleOffset(array $rules): ?int
    {
        foreach ($rules as $rule) {
            $isBaseline = false;
            foreach (self::BORDER_IMMUNITY_SELECTORS as $immune) {
                if ($this->isBaselineFor($rule, $immune)) {
                    $isBaseline = true;
                    break;
                }
            }
            if ($isBaseline) {
                continue; // the baseline itself is not a component rule
            }
            foreach (self::styledComponentRoots() as $root) {
                if (preg_match('/(?<![-\w])' . preg_quote($root, '/') . '(?![-\w])/', $rule['selector'])) {
                    return $rule['offset'];
                }
            }
        }
        return null;
    }

    /** Component root class selectors (`.nav`, `.grid`, …). */
    private static function styledComponentRoots(): array
    {
        return ['.nav', '.hero', '.section', '.faq', '.grid', '.table', '.cta', '.footer', '.stats', '.logos', '.embed', '.testimonials'];
    }

    /**
     * 6. Slots may only be SET by the renderer's inline style attribute
     * (issue 305, review finding): `style_component` writes the custom property
     * on the component root, and descendants inherit it. A stylesheet rule that
     * DECLARES a schema slot (`.grid .grid__item { --grid-card-border: ... }`)
     * beats that inheritance on every matched descendant and deadens the slot
     * while every consumption still routes through var() — invisible to the
     * bypass guard, which only inspects consumptions. No rule declares a schema
     * slot today (theme defaults use separate `-theme-color` variables); keep it
     * that way.
     */
    public function testNoStylesheetRuleDeclaresASchemaSlot(): void
    {
        $css = $this->stripComments($this->css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $allSlots = [];
        foreach ($this->slotsByComponent() as $slots) {
            $allSlots = array_merge($allSlots, array_keys($slots));
        }
        $this->assertNotEmpty($allSlots);

        $offenders = [];
        foreach ($rules as $rule) {
            foreach ($allSlots as $slot) {
                if (preg_match('/(?<![\w-])' . preg_quote($slot, '/') . '\s*:/', $rule[2])) {
                    $offenders[] = trim(preg_replace('/\s+/', ' ', $rule[1])) . " declares {$slot}";
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            "Stylesheet rules re-declare schema slots, overriding the renderer's inline "
            . "style attribute on descendants:\n- " . implode("\n- ", $offenders)
        );
    }

    // ── Issue 305 analyzer ────────────────────────────────────────────────────

    /**
     * Shorthands that RESET a longhand: a later `padding: 2rem` kills a slot
     * consumed via `padding-top:` just as dead as a literal `padding-top:` would
     * (the review's mutation probe demonstrated exactly that hole). For each
     * triple property, ENFORCE also scans these. Partial-axis siblings are
     * deliberately NOT expanded (a `row-gap` does not reset `gap`'s column axis,
     * and #255 intentionally zeroes row-gap on a gap-slotted grid), so the
     * expansion stays reset-only and cannot flag that legitimate pattern.
     */
    private const RESETTING_SHORTHANDS = [
        'padding-top'        => ['padding'],
        'padding-right'      => ['padding'],
        'padding-bottom'     => ['padding'],
        'padding-left'       => ['padding'],
        'margin-top'         => ['margin'],
        'margin-right'       => ['margin'],
        'margin-bottom'      => ['margin'],
        'margin-left'        => ['margin'],
        'border-color'       => ['border'],
        'border-width'       => ['border'],
        'border-top-color'   => ['border-top', 'border-color', 'border'],
        'border-bottom-color'=> ['border-bottom', 'border-color', 'border'],
        'font-size'          => ['font'],
        'line-height'        => ['font'],
    ];

    /** component => [slot name => declared type], for every styled component. */
    private function slotsByComponent(): array
    {
        $map = [];
        foreach ($this->styledComponents() as $component) {
            $map[$component] = array_map(
                fn ($def) => $def['type'] ?? 'length',
                $this->slots($component)
            );
        }
        return $map;
    }

    /**
     * BEM block of a class token, `pp-` prefix normalized:
     * `.grid__item-body` -> grid, `.pp-section--inverted` -> section.
     */
    private static function blockOf(string $class): string
    {
        $c = ltrim($class, '.');
        $c = preg_replace('/^pp-/', '', $c);
        return preg_split('/__|--/', $c)[0];
    }

    /**
     * Subject tokens of one comma-part of a selector: every class in the LAST
     * compound (the element the rule styles), each suffixed with its pseudo-element
     * if present, so `.grid__item::before` is a different box than `.grid__item`.
     * Combinators are normalized to spaces first, so `a>b` and `a > b` agree.
     */
    private static function subjectTokens(string $selectorPart): array
    {
        $part      = trim(preg_replace('/\s*[>+~]\s*/', ' ', $selectorPart));
        $compounds = preg_split('/\s+/', $part);
        $last      = end($compounds);

        $pseudo = '';
        if (preg_match('/::([a-z-]+)/', $last, $pm)) {
            $pseudo = '::' . $pm[1];
        }

        $tokens = [];
        if (preg_match_all('/\.[A-Za-z0-9_-]+/', $last, $m)) {
            foreach ($m[0] as $class) {
                $tokens[] = $class . $pseudo;
            }
        }
        return $tokens;
    }

    /**
     * The issue 305 detector. DERIVE (subject, property, slot) triples from every
     * var(--slot) consumption whose subject belongs to the slot's own component,
     * then ENFORCE: every same-subject re-declaration of that property — or of a
     * shorthand that RESETS it (RESETTING_SHORTHANDS) — must route through the
     * slot, or through a TYPE-COMPATIBLE sibling slot of the same component
     * (the .faq__item[open] color->accent state handoff; a length literal
     * laundered through a color sibling does not qualify).
     *
     * Returns ['offenders' => "slot|subject|property" => [declaration strings],
     *          'tripleCount' => int] — the count rides along so the caller can
     * assert the derivation didn't silently collapse (vacuity floor).
     *
     * Known, deliberate limitations (each owned by another layer):
     *   - class subjects only — a bare element rule (`main section { }`) or a
     *     sibling modifier class on the same element escapes; check 3 documents
     *     the same scope, and the rendered-output E2E pins own that class;
     *   - components.css only — no other stylesheet styles component BEM
     *     classes today;
     *   - pseudo-CLASS states (:hover, :first-child) collapse onto their base
     *     token BY DESIGN: a state literal on a slotted property is treated as
     *     a bypass and belongs in the ledger with evidence (see the issue 309
     *     hover/stack entries) — only ::pseudo-ELEMENTS are separate boxes;
     *   - SAME-type sibling routing is accepted (a color literal routed through
     *     a different color slot is indistinguishable from a legitimate state
     *     handoff without semantics); the type gate only blocks cross-type
     *     laundering.
     */
    private function slotBypassOffenders(string $css, array $slotsByComponent): array
    {
        $strippedCss = $this->stripComments($css);

        // The subject parser splits selector lists on commas; a comma inside
        // :is()/:where() would mis-attribute subjects. Neither exists in this
        // stylesheet (nor may they: css-lint bans modern selector features) —
        // fail fast here so introducing one forces a parser upgrade instead of
        // silently corrupting the guard.
        $this->assertDoesNotMatchRegularExpression(
            '/:(is|where)\s*\(/',
            $strippedCss,
            'components.css uses :is()/:where() — the issue 305 subject parser must be upgraded first.'
        );

        $slotToComponent = [];
        foreach ($slotsByComponent as $component => $slots) {
            foreach ($slots as $slot => $type) {
                $slotToComponent[$slot] = $component;
            }
        }

        // Innermost rules only, same parse as check 3: `[^{}]` stops at braces, so
        // rules inside @media match individually while the wrapper does not.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $strippedCss, $rules, PREG_SET_ORDER);

        // DERIVE.
        $triples = []; // "token|property" => slot
        foreach ($rules as $rule) {
            $selector = $rule[1];
            if (!preg_match_all('/([a-z-]+)\s*:\s*([^;{}]*)/i', $rule[2], $decls, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($decls as $decl) {
                $property = strtolower(trim($decl[1]));
                foreach ($slotToComponent as $slot => $component) {
                    if (!preg_match('/var\(\s*' . preg_quote($slot, '/') . '\b/', $decl[2])) {
                        continue;
                    }
                    // Type gate on derivation, mirroring check 2's contract: a
                    // type-incompatible appearance (a length slot inside a color
                    // property's value) is not a real consumption and must not
                    // claim the (subject, property) triple from the slot that
                    // legitimately owns it.
                    $compatibleTypes = self::PROPERTY_TYPES[$property] ?? null;
                    $slotType        = $slotsByComponent[$component][$slot];
                    if ($compatibleTypes !== null && !in_array($slotType, $compatibleTypes, true)) {
                        continue;
                    }
                    foreach (explode(',', $selector) as $part) {
                        foreach (self::subjectTokens($part) as $token) {
                            if (self::blockOf(preg_replace('/::.*$/', '', $token)) === $component) {
                                // A SET of slots per (subject, property): a shorthand
                                // like `border: var(--w) solid var(--c)` consumes TWO
                                // slots on one property, and last-wins would silently
                                // drop enforcement for one of them.
                                $triples["{$token}|{$property}"][$slot] = true;
                            }
                        }
                    }
                }
            }
        }

        // ENFORCE — each slot of each triple independently, so a shared-property
        // pair (border width + color) cannot shadow one another.
        $offenders = [];
        $pairs     = [];
        foreach ($triples as $key => $slotSet) {
            foreach (array_keys($slotSet) as $slot) {
                $pairs[] = [$key, $slot];
            }
        }
        foreach ($pairs as [$key, $slot]) {
            [$token, $property] = explode('|', $key);
            $component    = $slotToComponent[$slot];
            $siblingSlots = $slotsByComponent[$component];

            // The property itself plus every shorthand that resets it.
            $watchedProperties = array_merge(
                [$property],
                self::RESETTING_SHORTHANDS[$property] ?? []
            );

            foreach ($rules as $rule) {
                $selector = $rule[1];
                $isSubject = false;
                foreach (explode(',', $selector) as $part) {
                    if (in_array($token, self::subjectTokens($part), true)) {
                        $isSubject = true;
                        break;
                    }
                }
                if (!$isSubject) {
                    continue;
                }
                foreach ($watchedProperties as $watched) {
                    // Exclude hyphenated namesakes (`color` never matches `border-color`,
                    // and `padding` never matches `padding-top` thanks to `\s*:`).
                    if (!preg_match_all(
                        '/(?<![-a-z])' . preg_quote($watched, '/') . '\s*:\s*([^;}]+)/i',
                        $rule[2],
                        $matches
                    )) {
                        continue;
                    }
                    foreach ($matches[1] as $value) {
                        if (preg_match('/var\(\s*' . preg_quote($slot, '/') . '\b/', $value)) {
                            continue;
                        }
                        // Sibling escape, type-gated: the value must route through a
                        // same-component slot whose declared type is compatible with
                        // the property (per PROPERTY_TYPES; unmapped properties stay
                        // lenient, mirroring check 2).
                        $compatibleTypes = self::PROPERTY_TYPES[$watched] ?? null;
                        $routesThroughSibling = false;
                        foreach ($siblingSlots as $sibling => $siblingType) {
                            if ($sibling === $slot) {
                                continue;
                            }
                            if ($compatibleTypes !== null && !in_array($siblingType, $compatibleTypes, true)) {
                                continue;
                            }
                            if (preg_match('/var\(\s*' . preg_quote($sibling, '/') . '\b/', $value)) {
                                $routesThroughSibling = true;
                                break;
                            }
                        }
                        if ($routesThroughSibling) {
                            continue;
                        }
                        $offenders["{$slot}|{$token}|{$watched}"][] =
                            trim($value) . '  [' . trim(preg_replace('/\s+/', ' ', $selector)) . ']';
                    }
                }
            }
        }

        ksort($offenders);
        return ['offenders' => $offenders, 'tripleCount' => count($triples)];
    }

    /** Returns the schema style_slots map for a component. */
    private function slots(string $component): array
    {
        $schema = json_decode(
            file_get_contents($this->themeRoot . "/components/{$component}/schema.json"),
            true
        );
        $slots = $schema['styling']['style_slots'] ?? [];
        $this->assertNotEmpty($slots, "{$component} must declare style_slots.");
        return $slots;
    }

    /** Extracts a component's CSS block, delimited by `COMPONENT: <name>` headers. */
    private function componentBlock(string $component): string
    {
        // Match the header line through to the next COMPONENT: header or EOF.
        $pattern = '/COMPONENT:\s*' . preg_quote($component, '/') . '\b(.*?)'
                 . '(?=\/\*\s*={5,}[^*]*?COMPONENT:|\z)/s';
        $this->assertMatchesRegularExpression(
            $pattern,
            $this->css,
            "No COMPONENT: {$component} block found in components.css."
        );
        preg_match($pattern, $this->css, $m);
        return $m[1];
    }

    /** Removes CSS comments so commented mentions don't count as consumption. */
    private function stripComments(string $css): string
    {
        return preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
    }

    /** Returns the set of CSS property names whose value consumes var(--slot ...). */
    private function propertiesConsuming(string $block, string $slot): array
    {
        if (!preg_match_all(
            '/([a-z-]+)\s*:\s*[^;{}]*var\(\s*' . preg_quote($slot, '/') . '\b[^;{}]*/i',
            $block,
            $m
        )) {
            return [];
        }
        return array_map('strtolower', $m[1]);
    }
}
