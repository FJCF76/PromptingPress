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

    /**
     * Hero primary-button fill slots (issue 514): byte-identical-unset fallback pins.
     *
     * The generic keystone checks above prove --hero-button-bg / --hero-button-color /
     * --hero-button-shadow are consumed on type-compatible properties inside the hero
     * block. They do NOT pin the fallback literals, and the VISIBLE winner for the fill
     * lives in the SHARED premium `main .btn:not(...)` cascade (outside the hero block).
     * These pins lock both surfaces so an unset hero button stays byte-identical:
     *   - the premium winner routes each new slot as the OUTERMOST var() with the prior
     *     chain (--cta-button-* / --btn-* / literal) as the fallback, so unset resolves
     *     to today's gradient/ink/bevel; and
     *   - the hero-block keystone wires the ink/elevation at [0,4,0] with a `none` default
     *     (the premium winner supplies the bevel), and the fill/border follow --hero-button-bg.
     */
    public function testIssue514HeroButtonFillSlotFallbacks(): void
    {
        $css = $this->stripComments($this->css);

        // Premium cascade — the VISIBLE winners (shared block, outside COMPONENT: hero).
        // Rest fill: --hero-button-bg outermost, then the pre-#514 --cta-button-bg/--btn-bg
        // chain to the gradient literal (byte-identical unset).
        $this->assertMatchesRegularExpression(
            '/background:\s*var\(--hero-button-bg,\s*var\(--cta-button-bg,\s*var\(--btn-bg,\s*'
            . 'linear-gradient\(180deg,\s*var\(--color-accent-strong\)\s*0%,\s*var\(--color-accent-hover\)\s*100%\)\)\)\)/',
            $css,
            'The premium rest fill must route --hero-button-bg -> --cta-button-bg -> --btn-bg -> gradient (issue 514).'
        );
        // Rest ink: --hero-button-color outermost, prior chain to --color-bg.
        $this->assertStringContainsString(
            'color: var(--hero-button-color, var(--cta-button-color, var(--btn-text, var(--color-bg))))',
            $css,
            'The premium rest ink must route --hero-button-color -> --cta-button-color -> --btn-text -> --color-bg (issue 514).'
        );
        // Rest elevation: --hero-button-shadow outermost, prior chain to the bevel literal.
        $this->assertMatchesRegularExpression(
            '/box-shadow:\s*var\(--hero-button-shadow,\s*var\(--cta-button-shadow,\s*var\(--btn-shadow,\s*'
            . 'inset 0 1px 0 rgba\(255, 255, 255, 0\.16\),\s*'
            . '0 10px 22px color-mix\(in srgb, var\(--color-accent-strong\) 14%, transparent\)\)\)\)/',
            $css,
            'The premium rest elevation must route --hero-button-shadow -> --cta-button-shadow -> --btn-shadow -> bevel (issue 514).'
        );

        // Hero block — the slot-contract keystone at [0,4,0] (below the premium winner).
        $block = $this->stripComments($this->componentBlock('hero'));
        $this->assertMatchesRegularExpression(
            '/\.hero__cta:not\(\.btn--outline\):not\(\.btn--ghost\):not\(\.btn--secondary\)\s*\{\s*'
            . 'color:\s*var\(--hero-button-color,\s*var\(--btn-text,\s*var\(--color-bg\)\)\);\s*'
            . 'box-shadow:\s*var\(--hero-button-shadow,\s*none\);\s*\}/',
            $block,
            'The .hero__cta keystone must wire --hero-button-color (ink) and --hero-button-shadow: none (issue 514).'
        );
        // Fill/border keystone on the higher-specificity hero rule: --hero-button-bg leads
        // the fill; the border honors its own knob then FOLLOWS --hero-button-bg.
        $this->assertStringContainsString(
            'background-color: var(--hero-button-bg, var(--hero-accent, var(--btn-bg, var(--color-accent))))',
            $block,
            'The hero primary background-color must route --hero-button-bg -> --hero-accent -> --btn-bg -> --color-accent (issue 514).'
        );
        $this->assertStringContainsString(
            'border-color: var(--hero-accent, var(--btn-border-color, var(--hero-button-bg, var(--btn-bg, var(--color-accent)))))',
            $block,
            'The hero primary border must follow the fill (--hero-button-bg) when its own knobs are unset (issue 514).'
        );
    }

    /**
     * Hero cta2 slot isolation + premium fill routing (issue 526).
     *
     * Style slots land as inline custom properties on the .hero ROOT, so #514's
     * --hero-button-* slots inherit onto the SECOND CTA too; a cta2 authored as the
     * filled `primary` variant also matches the shared premium `main .btn:not(...)`
     * winner, so the primary's fill/elevation repainted it. The fix re-declares the
     * three slots ON the cta2 element:
     *   - --hero-button-bg: var(--hero-cta2-bg)  — a var() that cannot substitute makes
     *     the property guaranteed-invalid, so UNSET falls through the premium chain to
     *     the gradient (byte-identical, leak killed) and SET resolves the premium
     *     `background:` SHORTHAND to a flat color, clearing the masking gradient;
     *   - --hero-button-color / --hero-button-shadow: `initial` (the guaranteed-invalid
     *     value) so cta2 keeps its own ink rule and the premium bevel.
     * Pin the whole block: dropping any one line silently restores half the bug, and
     * swapping the var() form for `initial` on --hero-button-bg would kill the leak but
     * leave --hero-cta2-bg masked again (the pre-existing half).
     */
    public function testIssue526HeroCta2SlotIsolationAndFillRouting(): void
    {
        $block = $this->stripComments($this->componentBlock('hero'));

        // Isolate the rule whose selector-subject is a BARE .hero__cta--secondary (no
        // :not()/variant qualifier) — that unscoped selector is itself part of the
        // contract: the slots must be unreachable on EVERY cta2 variant, not just the
        // filled one. Grabbing the block first also makes the three declaration
        // assertions order-independent, so reordering them is not a false failure.
        $this->assertMatchesRegularExpression(
            '/(?:^|\})\s*\.hero\s+\.hero__cta-group\s+\.hero__cta--secondary\s*\{([^}]*)\}/',
            $block,
            'The issue 526 cta2 isolation rule is missing, or its selector gained a variant '
            . 'qualifier — it must stay an unqualified .hero__cta--secondary rule so the '
            . 'primary button slots are unreachable on every cta2 variant.'
        );
        preg_match(
            '/(?:^|\})\s*\.hero\s+\.hero__cta-group\s+\.hero__cta--secondary\s*\{([^}]*)\}/',
            $block,
            $m
        );
        $isolation = $m[1] ?? '';

        $this->assertMatchesRegularExpression(
            '/--hero-button-bg:\s*var\(--hero-cta2-bg\)\s*;/',
            $isolation,
            'The isolation rule must re-point --hero-button-bg at --hero-cta2-bg (issue 526): '
            . 'that single declaration both kills the #514 leak (unset -> guaranteed-invalid -> '
            . 'premium fallback) AND routes the cta2 fill into the gradient-clearing chain. '
            . 'Plain `initial` here would fix the leak but leave --hero-cta2-bg masked again.'
        );
        $this->assertMatchesRegularExpression(
            '/--hero-button-color:\s*initial\s*;/',
            $isolation,
            'The isolation rule must reset --hero-button-color on cta2 (issue 526).'
        );
        $this->assertMatchesRegularExpression(
            '/--hero-button-shadow:\s*initial\s*;/',
            $isolation,
            'The isolation rule must reset --hero-button-shadow on cta2 (issue 526).'
        );

        // The cta2 rest rule still consumes --hero-cta2-bg directly (background-color), so
        // the slot keeps its in-block, type-compatible consumption for the keystone checks.
        $this->assertStringContainsString(
            'background-color: var(--hero-cta2-bg, var(--hero-accent, var(--color-accent)))',
            $block,
            'The filled cta2 rest rule must keep routing --hero-cta2-bg (issue 111/526).'
        );
        // Border FOLLOWS the fill when its own knobs are unset — the #514 idiom the primary
        // uses, extended to cta2 by the issue 526 decision. Without --hero-cta2-bg in this
        // chain a fill-only recolor renders a --color-accent ring around a brand-colored
        // button; --hero-cta2-border / --hero-accent still win first, and the chain still
        // bottoms out at --color-accent so an unset cta2 is byte-identical.
        $this->assertStringContainsString(
            'border-color: var(--hero-cta2-border, var(--hero-accent, var(--hero-cta2-bg, var(--color-accent))))',
            $block,
            'The filled cta2 border must FOLLOW --hero-cta2-bg when --hero-cta2-border and '
            . '--hero-accent are unset (issue 526, mirroring the primary at #514).'
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
        'text-align'                 => ['align'],
        'text-transform'             => ['text-transform'],
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
    private const BORDER_IMMUNITY_SELECTORS = ['[data-pp-component]', '.grid__item', '.section__panel-row'];

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
                    || preg_match('/class="[^"]*\bgrid__item\b/', $line) === 1
                    || preg_match('/class="[^"]*\bsection__panel-row\b/', $line) === 1;

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
        // per-card one, and section renders a per-row one (issue 334). If the scan
        // finds nothing, the loop above proved nothing.
        $this->assertGreaterThanOrEqual(
            9,
            $emitted,
            'Found fewer inline slot surfaces than the 9 known today — the template scan is broken.'
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

        // The real shape passes (all three inline-slot surfaces covered: roots,
        // grid card, and the issue-334 panel row).
        $this->assertSame(
            [],
            $this->immunityGaps(
                "[data-pp-component],\n.grid__item,\n.section__panel-row { border-style: none; border-width: 0; }\n"
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
                . ".section__panel-row { border-style: none; border-width: 0; }\n"
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
                . "[data-pp-component], .grid__item, .section__panel-row { border-style: none; border-width: 0; }\n"
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
     * bypass guard, which only inspects consumptions.
     *
     * ISOLATION EXEMPTIONS (issue 526) — the only declarations allowed, listed exactly.
     * The hero's second CTA is a DESCENDANT of the .hero root, so it inherits the
     * PRIMARY button's #514 --hero-button-* slots; a filled (`primary`) cta2 sits in the
     * same shared premium button cascade and was repainted by them. Re-declaring those
     * three slots on the cta2 element is the fix, and it does not deaden anything an
     * author can set THERE: cta2's own author-facing slots are --hero-cta2-*, none of
     * which is declared. --hero-button-bg is re-pointed at --hero-cta2-bg (routing the
     * cta2 fill into the premium gradient-clearing chain); the other two are reset to the
     * guaranteed-invalid `initial`. Any OTHER rule/slot pair still fails, and this list is
     * exact — adding or dropping an entry fails until it is updated deliberately.
     */
    private const SLOT_DECLARATION_EXEMPTIONS = [
        '.hero .hero__cta-group .hero__cta--secondary declares --hero-button-bg',
        '.hero .hero__cta-group .hero__cta--secondary declares --hero-button-color',
        '.hero .hero__cta-group .hero__cta--secondary declares --hero-button-shadow',
    ];

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
        // The issue 526 cta2 isolation rule is the one documented exemption; every entry
        // must still be present (a stale exemption means the fix silently vanished).
        // Compare by COUNT, not by value: array_diff would drop every offender equal to an
        // exemption, so a SECOND rule with the same selector re-declaring the same slot
        // would slip through the slot-deadening guard entirely.
        $seen     = array_count_values($offenders);
        $expected = array_count_values(self::SLOT_DECLARATION_EXEMPTIONS);

        $unexpected = [];
        foreach ($seen as $decl => $count) {
            $allowed = $expected[$decl] ?? 0;
            for ($i = 0; $i < $count - $allowed; $i++) {
                $unexpected[] = $allowed === 0 ? $decl : "{$decl} (declared more than once)";
            }
        }
        $missing = [];
        foreach ($expected as $decl => $count) {
            for ($i = 0; $i < $count - ($seen[$decl] ?? 0); $i++) {
                $missing[] = $decl;
            }
        }

        $this->assertSame(
            [],
            $unexpected,
            "Stylesheet rules re-declare schema slots, overriding the renderer's inline "
            . "style attribute on descendants:\n- " . implode("\n- ", $unexpected)
        );
        $this->assertSame(
            [],
            $missing,
            "STALE exemption: these declarations are listed in SLOT_DECLARATION_EXEMPTIONS "
            . "but no longer exist — the issue 526 cta2 isolation rule was removed or "
            . "renamed (the #514 slot leak would be back). Restore it or drop the entry:\n- "
            . implode("\n- ", $missing)
        );
    }

    /**
     * 8. Cross-sheet silent-clobber guard (issue 342).
     *
     * Checks 1-7 all read ONLY components.css. That single-sheet blind spot is
     * exactly how #336 hid: base.css:187 `p:last-child { margin-bottom: 0 }`
     * (specificity (0,1,1)) outranks a bare `.grid__subheading` (0,1,0), and the
     * subheading is always its header's last child — so the component's declared
     * `margin-bottom` computed to 0px on three components while every unit check
     * here stayed green. The #336 fix out-specified the reset at header scope but
     * added NO structural guard, so the next element/pseudo-class reset added to
     * base.css reopens the class.
     *
     * WHAT THIS PROVES, STATED HONESTLY (issue 342, decision Option 2): a static
     * text scan cannot resolve the cascade — specificity, source order, and
     * whether two selectors match the SAME rendered element are a browser's job.
     * So this guard does NOT claim the cascade resolves correctly. It proves the
     * weaker, still-load-bearing thing: every cross-sheet rule that COULD win the
     * cascade against a bare component class on a slot-consumed property is
     * explicitly ACCOUNTED FOR. A new such rule fails the build until a human
     * acknowledges it with evidence. The TRUE cascade proof lives in the rendered
     * computed-style pins in tests/e2e/style-render.spec.ts (the `#336 …`
     * subheading tests prove the slot actually lands under the real cascade).
     * This is the same division of labour as check 7 (WP-core immunity): the
     * static half keeps the contract honest as the surface grows; the rendered
     * half owns what only a browser can prove.
     *
     * SCOPE — the AUTOMATIC-MATCH class only. A hazard is a rule in a cross-sheet
     * stylesheet (base.css, utilities.css) whose SUBJECT compound carries NO
     * class/id/attribute — a pure element/pseudo-class selector (`p:last-child`,
     * `a:hover`) that matches component-rendered elements BY TAG, with no template
     * opt-in. That is the silent mechanism of #336. Class-subject rules (the
     * `.mb-*`/`.text-*`/`.sr-only` utilities) are deliberately OUT of scope here:
     * they reach a slotted element only when a template explicitly adds the class,
     * a visible, greppable composition rather than a silent cascade defeat. One
     * such opt-in path IS a real, breakpoint-split clobber today — `text_role`
     * adds `.text-meta`/`.text-kicker` (which set `color`) onto `.grid__item-text`,
     * defeating `--grid-item-text-color` below 768px — but it needs a role-vs-slot
     * DESIGN decision, so it is tracked separately as issue #349, with the rendered
     * pins owning its proof. Do not widen this guard to swallow that case without
     * that decision: a guard cannot enforce a contract that is not yet decided.
     *
     * LOAD-ORDER ASYMMETRY (functions.php:88/104/111): base.css → components.css →
     * utilities.css. A base.css rule only beats a bare component class when its
     * specificity is STRICTLY greater than (0,1,0) — an equal (0,1,0) loses on
     * source order to the later component sheet. A utilities.css rule (later than
     * components) wins at >= (0,1,0). testCrossSheetLoadOrderAssumptionHolds pins
     * that ordering so the threshold logic can't silently invert under a reorder.
     *
     * The ledger is SHRINK-ONLY, same discipline as KNOWN_DEAD_SLOT_WAIVERS:
     *   - a new automatic-match hazard fails (acknowledge it with a justification
     *     and, where it maps to a real element, the rendered pin that proves the
     *     slot still lands — or fix the reset and don't add an entry);
     *   - a ledger entry that stops offending fails (remove it with the fix);
     *   - the exact-size pin below must move in the same change, so an entry can
     *     never slip in or drift out through a merge unnoticed.
     */
    private const CROSS_SHEET_CLOBBER_LEDGER = [
        // The #336 prose reset. Auto-matches every <p>, including component
        // subheadings (always their header's last child). SAFE because the three
        // subheading-bearing components out-specify it at header scope
        // `.X__header > .X__subheading` (0,2,0), proven under the real cascade by
        // style-render.spec.ts `#336 <component> subheading keeps its bottom
        // rhythm`. Legitimate for prose blocks (`.section__content`, `.cta__body`)
        // — must NOT be weakened; components out-specify it, they don't delete it.
        'base.css|p:last-child|margin-bottom'          =>
            'issue 336 prose reset; components out-specify at (0,2,0), pinned by style-render.spec.ts #336.',
        // Sibling reset for blockquotes. No component declares a slotted
        // margin-bottom on a blockquote today (exhaustive #336 sweep), so no slot
        // is at risk now; the entry keeps a future blockquote margin slot honest.
        'base.css|blockquote:last-child|margin-bottom' =>
            'issue 336 sibling reset; no component slots margin-bottom on a blockquote today.',
        // Global link hover state. A component that slots a link colour owns its
        // RESTING colour at its own class specificity and hands the hover off
        // intentionally (see KNOWN_DEAD_SLOT_WAIVERS `--grid-link-color` hover
        // entry). The hover MUST visually override the resting slot — routing it
        // through the slot would erase hover feedback. Not a silent clobber.
        'base.css|a:hover|color'                       =>
            'global link hover state; slotted link colours own resting colour and hand off the hover (see grid-link waiver).',
    ];

    /** Front-end stylesheets, besides components.css, that share the component cascade,
     * with whether a same-specificity rule there WINS a tie against a component class
     * (true when the sheet is enqueued AFTER components.css). Admin/chat sheets
     * (pp-admin-editor.css, pp-ai-chat.css) render in a different context and are out
     * of scope. */
    private function crossSheetSpecs(): array
    {
        return [
            ['name' => 'base.css',      'winsTie' => false,
             'css'  => file_get_contents($this->themeRoot . '/assets/css/base.css')],
            ['name' => 'utilities.css', 'winsTie' => true,
             'css'  => file_get_contents($this->themeRoot . '/assets/css/utilities.css')],
        ];
    }

    public function testNoUnacknowledgedCrossSheetClobber(): void
    {
        $watched = $this->watchedSlotProperties();

        // Vacuity floor: the derivation must find a healthy watched-property
        // population, or a parser regression would gut the guard silently.
        $this->assertGreaterThan(
            8,
            count($watched),
            'Watched slot-property derivation collapsed — the cross-sheet guard would pass vacuously.'
        );

        $hazards = $this->crossSheetClobberHazards($this->crossSheetSpecs(), $watched);

        // Fail-closed: the three known automatic-match hazards MUST be found, or the
        // base.css scan / specificity parser regressed and this guard passes vacuously.
        foreach (array_keys(self::CROSS_SHEET_CLOBBER_LEDGER) as $known) {
            $this->assertArrayHasKey(
                $known,
                $hazards,
                "Cross-sheet hazard discovery lost `{$known}` — the scan or specificity "
                . 'parser regressed and this guard would pass vacuously.'
            );
        }

        $failures = [];
        foreach ($hazards as $key => $decls) {
            if (!isset(self::CROSS_SHEET_CLOBBER_LEDGER[$key])) {
                $failures[] = "NEW cross-sheet clobber: `{$key}` declares ["
                    . implode(', ', array_unique($decls)) . '] at a specificity that '
                    . 'defeats a bare (0,1,0) component class. A pure element/pseudo-class '
                    . 'rule matches component elements by tag with no template opt-in — the '
                    . '#336 silent-clobber mechanism. Out-specify the slot in components.css '
                    . '(header-scope the rule), add a rendered pin, and acknowledge it in '
                    . 'CROSS_SHEET_CLOBBER_LEDGER — or remove the reset.';
            }
        }
        foreach (array_keys(self::CROSS_SHEET_CLOBBER_LEDGER) as $key) {
            if (!isset($hazards[$key])) {
                $failures[] = "STALE ledger entry: `{$key}` no longer offends — remove it "
                    . 'from CROSS_SHEET_CLOBBER_LEDGER (and update the size pin).';
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Cross-sheet slot-clobber guard (issue 342):\n- " . implode("\n- ", $failures)
        );
    }

    /** Exact-size pin: any ledger edit in either direction must touch this test, so a
     * cross-sheet acknowledgement can never slip in or drift out through a merge. */
    public function testCrossSheetLedgerOnlyShrinks(): void
    {
        $this->assertSame(
            3,
            count(self::CROSS_SHEET_CLOBBER_LEDGER),
            'The issue 342 cross-sheet ledger changed size. A new automatic-match reset is '
            . 'fixed (out-specify it) or acknowledged with evidence; a fixed one is removed. '
            . 'Update this pin in the same change. The 3 entries are base.css p:last-child + '
            . 'blockquote:last-child (margin-bottom) and a:hover (color).'
        );
    }

    /**
     * Detection proof / negative control (mirrors testGuardDetectsTheDeadSlotClass):
     * the guard must go RED on a NEW cross-sheet clobber and stay silent on the
     * patterns it must NOT flag — proving both its power and its precision, in CI
     * forever, without depending on the real sheets' current contents.
     */
    public function testCrossSheetGuardDetectsANewClobber(): void
    {
        $watched = ['margin-bottom', 'color', 'padding-top'];

        // A NEW element+pseudo-class clobber in an EARLY (base-order) sheet: caught.
        $this->assertArrayHasKey(
            'x.css|li:last-child|margin-bottom',
            $this->crossSheetClobberHazards(
                [['name' => 'x.css', 'winsTie' => false, 'css' => 'li:last-child { margin-bottom: 0; }']],
                $watched
            ),
            'The guard failed to detect a new element/pseudo-class clobber (the #336 mechanism).'
        );

        // A bare element rule (0,0,1) is out-specified by ANY component class → NOT a hazard.
        $this->assertSame(
            [],
            $this->crossSheetClobberHazards(
                [['name' => 'x.css', 'winsTie' => false, 'css' => 'li { margin-bottom: 0; }']],
                $watched
            ),
            'A bare element rule (0,0,1) loses to every component class — it must not be flagged.'
        );

        // A class-subject (opt-in) rule is excluded even when it would win a tie in a
        // later sheet: it only reaches a slotted element if a template adds the class.
        $this->assertSame(
            [],
            $this->crossSheetClobberHazards(
                [['name' => 'u.css', 'winsTie' => true, 'css' => '.mb0 { margin-bottom: 0; }']],
                $watched
            ),
            'An opt-in utility class must not be flagged as a silent automatic-match clobber.'
        );

        // Source-order asymmetry, load-order logic actually bites: an element-less
        // pseudo-class rule at (0,1,0) TIES a bare component class — it loses in an
        // earlier sheet (not a hazard) and wins in a later one (a hazard).
        $this->assertSame(
            [],
            $this->crossSheetClobberHazards(
                [['name' => 'e.css', 'winsTie' => false, 'css' => ':hover { color: red; }']],
                $watched
            ),
            '(0,1,0) in a sheet BEFORE components ties and loses on source order — not a hazard.'
        );
        $this->assertArrayHasKey(
            'l.css|:hover|color',
            $this->crossSheetClobberHazards(
                [['name' => 'l.css', 'winsTie' => true, 'css' => ':hover { color: red; }']],
                $watched
            ),
            '(0,1,0) in a sheet AFTER components ties and wins on source order — a hazard.'
        );

        // Reset-shorthand path: a `margin` shorthand kills a `margin-bottom` slot.
        $this->assertArrayHasKey(
            't.css|p:last-child|margin',
            $this->crossSheetClobberHazards(
                [['name' => 't.css', 'winsTie' => false, 'css' => 'p:last-child { margin: 0; }']],
                ['margin']
            ),
            'A shorthand reset (margin: kills a margin-bottom slot) must be detected.'
        );

        // A hazard nested inside @media is still caught: the innermost-rule parse
        // matches the inner rule on its own; the @media wrapper (its body holds braces)
        // is not lifted out. Fail-closed for hazard detection (Codex outside-voice: the
        // regex must not skip @media-nested resets).
        $this->assertArrayHasKey(
            'm.css|p:last-child|margin-bottom',
            $this->crossSheetClobberHazards(
                [['name' => 'm.css', 'winsTie' => false,
                  'css'  => '@media (min-width: 768px) { p:last-child { margin-bottom: 0; } }']],
                $watched
            ),
            'A clobber nested in @media must still be caught (innermost-rule parse).'
        );

        // A pseudo-ELEMENT subject is a separate box, not the slot-bearing element —
        // it must NOT be flagged even in a later (tie-winning) sheet.
        $this->assertSame(
            [],
            $this->crossSheetClobberHazards(
                [['name' => 'p.css', 'winsTie' => true, 'css' => 'p::first-line { color: red; }']],
                $watched
            ),
            'A pseudo-element box is not the slot-bearing element — must not be flagged.'
        );

        // A type selector inside :not() counts toward specificity: `:hover:not(p)` is
        // really (0,1,1) and beats a bare component class, so it IS a hazard in an
        // early sheet. If the type were dropped it would mis-compute to (0,1,0) and slip.
        $this->assertArrayHasKey(
            'n.css|:hover:not(p)|color',
            $this->crossSheetClobberHazards(
                [['name' => 'n.css', 'winsTie' => false, 'css' => ':hover:not(p) { color: red; }']],
                $watched
            ),
            'A type selector inside :not() must count toward specificity (else a real clobber slips past).'
        );
    }

    /**
     * The threshold logic in specWinsAgainstBareClass depends on base.css loading
     * BEFORE components.css and utilities.css loading AFTER it. Pin that enqueue
     * order so a reorder in functions.php can't silently invert which cross-sheet
     * rules count as hazards (adversarial: swap the enqueues and every base.css
     * tie flips from "loses" to "wins" and vice-versa).
     */
    public function testCrossSheetLoadOrderAssumptionHolds(): void
    {
        $fn = file_get_contents($this->themeRoot . '/functions.php');
        $base  = strpos($fn, 'assets/css/base.css');
        $comps = strpos($fn, 'assets/css/components.css');
        $utils = strpos($fn, 'assets/css/utilities.css');

        $this->assertNotFalse($base,  'functions.php no longer references base.css.');
        $this->assertNotFalse($comps, 'functions.php no longer references components.css.');
        $this->assertNotFalse($utils, 'functions.php no longer references utilities.css.');
        $this->assertTrue(
            $base < $comps && $comps < $utils,
            'functions.php enqueue order changed (expected base.css < components.css < utilities.css). '
            . 'The cross-sheet guard\'s tie-breaking (base ties LOSE, utilities ties WIN) assumes it — '
            . 'update crossSheetSpecs() winsTie flags and this pin together.'
        );
    }

    // ── Issue 342 cross-sheet analyzer ─────────────────────────────────────────

    /**
     * The set of CSS properties actually driven by a component slot (a compatible
     * var(--slot) consumption in components.css), plus every shorthand that RESETS
     * one of them (RESETTING_SHORTHANDS). A cross-sheet rule declaring one of these
     * at a cascade-winning specificity is what can defeat a slot.
     */
    private function watchedSlotProperties(): array
    {
        $slotsByComponent = $this->slotsByComponent();
        $slotToComponent  = [];
        foreach ($slotsByComponent as $component => $slots) {
            foreach ($slots as $slot => $type) {
                $slotToComponent[$slot] = $component;
            }
        }

        $css = $this->stripComments($this->css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $props = [];
        foreach ($rules as $rule) {
            if (!preg_match_all('/([a-z-]+)\s*:\s*([^;{}]*)/i', $rule[2], $decls, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($decls as $decl) {
                $property = strtolower(trim($decl[1]));
                foreach ($slotToComponent as $slot => $component) {
                    if (!preg_match('/var\(\s*' . preg_quote($slot, '/') . '\b/', $decl[2])) {
                        continue;
                    }
                    // Type gate, mirroring check 2 / the #305 derivation: a
                    // type-incompatible appearance is not a real consumption.
                    $compatible = self::PROPERTY_TYPES[$property] ?? null;
                    $slotType   = $slotsByComponent[$component][$slot];
                    if ($compatible !== null && !in_array($slotType, $compatible, true)) {
                        continue;
                    }
                    $props[$property] = true;
                }
            }
        }

        // A cross-sheet shorthand that resets a watched longhand clobbers it too.
        foreach (array_keys($props) as $p) {
            foreach (self::RESETTING_SHORTHANDS[$p] ?? [] as $shorthand) {
                $props[$shorthand] = true;
            }
        }

        return array_keys($props);
    }

    /**
     * Returns "sheet|subject|property" => [declared value, …] for every cross-sheet
     * rule that is an AUTOMATIC-MATCH hazard: its subject compound carries no
     * class/id/attribute (a pure element/pseudo-class selector), it declares a
     * watched property, and its specificity defeats a bare (0,1,0) component class
     * under that sheet's tie-break. Innermost-rule parse (same as checks 3/5) so a
     * hazard nested in an @media block is caught, not lifted out and missed.
     */
    private function crossSheetClobberHazards(array $sheetSpecs, array $watched): array
    {
        $hazards = [];
        foreach ($sheetSpecs as $spec) {
            $css = $this->stripComments($spec['css']);
            preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                foreach (explode(',', $rule[1]) as $part) {
                    $part = trim($part);
                    if ($part === '' || !$this->subjectIsAutomaticMatch($part)) {
                        continue;
                    }
                    if (!$this->specWinsAgainstBareClass($this->selectorSpecificity($part), $spec['winsTie'])) {
                        continue;
                    }
                    foreach ($watched as $prop) {
                        if (!preg_match_all(
                            '/(?<![-a-z])' . preg_quote($prop, '/') . '\s*:\s*([^;}]+)/i',
                            $rule[2],
                            $m
                        )) {
                            continue;
                        }
                        foreach ($m[1] as $value) {
                            $hazards["{$spec['name']}|{$part}|{$prop}"][] = trim($value);
                        }
                    }
                }
            }
        }
        ksort($hazards);
        return $hazards;
    }

    /**
     * True when the SUBJECT (last compound) of a selector part carries no
     * class/id/attribute — a pure element/pseudo-class selector that matches
     * component-rendered elements by tag with no template opt-in. Class-subject
     * rules (utilities) are opt-in and out of the silent-clobber scope (see #349).
     */
    private function subjectIsAutomaticMatch(string $selectorPart): bool
    {
        $part      = trim(preg_replace('/\s*[>+~]\s*/', ' ', $selectorPart));
        $compounds = preg_split('/\s+/', $part);
        $subject   = (string) end($compounds);
        if ($subject === '' || preg_match('/[.#\[]/', $subject)) {
            return false;
        }
        // A pseudo-ELEMENT (::before/::marker/::first-line) is a SEPARATE box, not the
        // element that carries the slot — check 5 tracks it as its own subject for the
        // same reason. A rule on it does not clobber the host element's slot, so it is
        // out of the automatic-match hazard scope (Codex outside-voice finding).
        return !str_contains($subject, '::');
    }

    /**
     * CSS specificity [a=ids, b=classes/attrs/pseudo-classes, c=elements/pseudo-elements]
     * for a single (comma-free) selector. Accurate enough to compare against a bare
     * component class (0,1,0). :not()/:is() add no specificity themselves (their
     * arguments do, and are counted inline); :where() adds nothing — the repo bans
     * :is()/:where() (asserted in slotBypassOffenders), so only :not() is netted out.
     */
    private function selectorSpecificity(string $selector): array
    {
        $s = trim($selector);

        $ids            = preg_match_all('/#[\w-]+/', $s);
        $classes        = preg_match_all('/\.[\w-]+/', $s);
        $attrs          = preg_match_all('/\[[^\]]*\]/', $s);
        $pseudoElements = preg_match_all('/::[\w-]+/', $s);
        $pseudoClasses  = preg_match_all('/(?<!:):(?!:)[\w-]+/', $s);
        $funcKeywords   = preg_match_all('/(?<!:):(?:not|is|where)\(/', $s);

        $b = $classes + $attrs + max(0, $pseudoClasses - $funcKeywords);

        // Elements (type selectors), INCLUDING those inside functional pseudo-class
        // arguments such as :not(a): strip pseudo-class/element NAMES but KEEP their
        // arguments, drop the paren delimiters, then strip class/id/attr and count the
        // bare type names left. Stripping the whole `:not(a)` dropped the `a` type and
        // undercounted specificity, which could let a real base.css clobber like
        // `:hover:not(p)` ((0,1,1)) slip past as (0,1,0) (Codex outside-voice finding).
        $stripped = preg_replace('/::?[\w-]+/', ' ', $s);                         // pseudo NAMES only
        $stripped = str_replace(['(', ')'], ' ', $stripped);                      // keep inner selectors, drop parens
        $stripped = preg_replace('/\.[\w-]+|#[\w-]+|\[[^\]]*\]/', ' ', $stripped); // class/id/attr
        $stripped = preg_replace('/[>+~*,]/', ' ', $stripped);                    // combinators + universal + stray commas
        $elements = preg_match_all('/[a-zA-Z][\w-]*/', $stripped, $mm) ? count($mm[0]) : 0;

        return [$ids, $b, $elements + $pseudoElements];
    }

    /**
     * True when $spec defeats a bare component class (0,1,0): strictly greater when
     * the sheet is enqueued before components (a tie loses on source order), or
     * greater-or-equal when it is enqueued after (a tie wins).
     */
    private function specWinsAgainstBareClass(array $spec, bool $winsTie): bool
    {
        $cmp = ($spec[0] <=> 0) ?: (($spec[1] <=> 1) ?: ($spec[2] <=> 0));
        return $winsTie ? $cmp >= 0 : $cmp > 0;
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
