<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for pp_theme_class() — the single source of truth for the band
 * `theme` modifier class and the deprecated `dark` -> `muted` alias (#442).
 *
 * The alias must map BOTH ways to the SAME legacy `--dark` CSS class so existing
 * pages storing `theme: "dark"` render byte-identically while new pages use `muted`.
 * The genuinely dark band is `--inverted`. Anything unexpected coerces to default
 * (empty string), matching the historical non-strict accept-and-coerce behavior.
 */
class ThemeClassHelperTest extends TestCase
{
    public function testDefaultEmitsNoModifier(): void
    {
        $this->assertSame('', pp_theme_class('default', 'cta'));
    }

    public function testMutedEmitsLegacyDarkClass(): void
    {
        // muted is the canonical value; it renders under the legacy `--dark` class.
        $this->assertSame(' cta--dark', pp_theme_class('muted', 'cta'));
    }

    public function testDeprecatedDarkAliasEmitsSameClassAsMuted(): void
    {
        // Both values resolve to the identical class — the alias, proven both ways.
        $this->assertSame(
            pp_theme_class('muted', 'grid'),
            pp_theme_class('dark', 'grid'),
            'dark must be a byte-identical alias of muted at the class level'
        );
        $this->assertSame(' grid--dark', pp_theme_class('dark', 'grid'));
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
