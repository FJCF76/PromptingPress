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
    /** Components that declare style_slots and own a block in components.css. */
    private const COMPONENTS = ['hero', 'section', 'grid', 'cta'];

    private string $themeRoot;
    private string $css;

    protected function setUp(): void
    {
        $this->themeRoot = dirname(__DIR__);
        $this->css       = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertNotEmpty($this->css, 'components.css should be readable.');
    }

    /** 1. Every declared slot is consumed in its own component block. */
    public function testEverySlotConsumedInComponentBlock(): void
    {
        foreach (self::COMPONENTS as $component) {
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
        foreach (self::COMPONENTS as $component) {
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
                if (strpos($selector, $selectorToken) === false) {
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
    ];

    /**
     * Property → compatible slot types. Properties absent from this map are
     * treated as compatible (lenient) so the test never false-fails on novel CSS.
     */
    private const PROPERTY_TYPES = [
        'box-shadow'                 => ['shadow'],
        'color'                      => ['color'],
        'background'                 => ['color'],
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
