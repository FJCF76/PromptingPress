<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for pp_theme_class() — the single source of truth for the band
 * `theme` modifier class.
 *
 * TWO DIFFERENT THINGS, and keeping them apart is the whole point of this file:
 *   INPUT VALUES  `default | muted | inverted`, and nothing else. The `dark` input
 *                 value was REMOVED in #605 because its name mispredicted its output
 *                 (it rendered a LIGHT band). Anything outside the set — including a
 *                 `dark` still sitting in storage — coerces to default (empty string).
 *   OUTPUT NAME   `muted` still EMITS the legacy `{prefix}--dark` CSS class (#570
 *                 DG-4). That is a class name, not a value anyone can write, and it
 *                 is kept so every stylesheet rule and `variant_classes` declaration
 *                 stays valid. The genuinely dark band is `--inverted`.
 */
class ThemeClassHelperTest extends TestCase
{
    public function testDefaultEmitsNoModifier(): void
    {
        $this->assertSame('', pp_theme_class('default', 'cta'));
    }

    public function testMutedEmitsLegacyDarkClass(): void
    {
        // #570 DG-4 — THE class-name carve-out, unchanged by #605. `muted` is the
        // canonical INPUT value and it still renders under the legacy `--dark` class
        // NAME. Removing `dark` as an input value must never touch this.
        $this->assertSame(' cta--dark', pp_theme_class('muted', 'cta'));
    }

    public function testInvertedEmitsInvertedClass(): void
    {
        $this->assertSame(' cta--inverted', pp_theme_class('inverted', 'cta'));
    }

    public function testPrefixIsRespected(): void
    {
        $this->assertSame(' pp-section--dark', pp_theme_class('muted', 'pp-section'));
        $this->assertSame(' testimonials--inverted', pp_theme_class('inverted', 'testimonials'));
    }

    /**
     * @dataProvider unexpectedValues
     * @param mixed $value
     */
    public function testUnexpectedValuesCoerceToDefault($value): void
    {
        $this->assertSame('', pp_theme_class($value, 'cta'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unexpectedValues(): array
    {
        return [
            // #605 — the cleanest statement of the post-removal contract. `dark` is
            // no longer an accepted input value, so a band STORED with it coerces to
            // the DEFAULT band (no modifier class) exactly like any other unknown
            // value. It does NOT fall back to `muted`: that is the intended,
            // deliberate stale-data breakage, not a regression.
            'stored legacy dark' => ['dark'],
            'unknown string' => ['neon'],
            'empty string'   => [''],
            'null'           => [null],
            'array'          => [['muted']],
            'integer'        => [1],
            'bool'           => [true],
            'uppercase'      => ['MUTED'],
        ];
    }
}
