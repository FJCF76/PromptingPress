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

    // ── Helpers ───────────────────────────────────────────────────────────────

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
