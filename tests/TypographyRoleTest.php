<?php
/**
 * tests/TypographyRoleTest.php
 *
 * Typography role surface (#90): mono/meta/label/kicker roles exposed as
 * design tokens, utility classes, and an optional grid-item `text_role` prop
 * reflected in rendered output.
 */

use PHPUnit\Framework\TestCase;

class TypographyRoleTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    private function render(string $component, array $props): string
    {
        ob_start();
        pp_get_component($component, $props);
        return ob_get_clean();
    }

    // ── Tokens ────────────────────────────────────────────────────────────

    public function testBaseCssDeclaresRoleTokens(): void
    {
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        foreach ([
            '--font-mono',
            '--text-meta-size', '--text-meta-color',
            '--text-label-size', '--text-label-weight', '--text-label-spacing',
            '--text-kicker-size', '--text-kicker-weight', '--text-kicker-spacing', '--text-kicker-color',
        ] as $token) {
            $this->assertStringContainsString($token, $base, "base.css must declare {$token}.");
        }
    }

    public function testCodeAndPreUseMonoToken(): void
    {
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/font-family:\s*var\(--font-mono\)/',
            $base,
            'code/pre should consume var(--font-mono) rather than a hard-coded stack.'
        );
    }

    // ── Heading letter-spacing token (#467) ────────────────────────────────

    public function testBaseCssDeclaresHeadingLetterSpacingToken(): void
    {
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertStringContainsString(
            '--letter-spacing-heading',
            $base,
            'base.css must declare --letter-spacing-heading so a brand can set heading tracking.'
        );
    }

    public function testHeadingRuleRoutesLetterSpacingThroughToken(): void
    {
        // The shared h1-h6 rule must consume the token, not a literal — otherwise the
        // token exists but nothing reads it. Pins that letter-spacing routes through
        // var(--letter-spacing-heading) and no bare `letter-spacing: -0.03em` remains.
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/letter-spacing:\s*var\(--letter-spacing-heading\)/',
            $base,
            'the h1-h6 rule should consume var(--letter-spacing-heading), not a literal.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/letter-spacing:\s*-0\.03em/',
            $base,
            'no heading rule should hard-code letter-spacing: -0.03em once the token exists.'
        );
    }

    // ── Utility classes ───────────────────────────────────────────────────

    public function testUtilitiesDeclareRoleClasses(): void
    {
        $util = file_get_contents($this->themeRoot . '/assets/css/utilities.css');
        foreach (['.text-mono', '.text-meta', '.text-label', '.text-kicker'] as $class) {
            $this->assertStringContainsString($class, $util, "utilities.css must declare {$class}.");
        }
    }

    // ── Grid item text_role (prop, via update_component) ──────────────────

    public function testGridItemTextRoleEmitsClass(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'A', 'text' => 'Body', 'text_role' => 'kicker']],
        ]);
        $this->assertStringContainsString('class="grid__item-text text-kicker"', $html);
    }

    /**
     * The RENDER-side coercion, which #600 narrowed the reach of without removing.
     * The write path now rejects an out-of-set role (the strict gate walks nested
     * enum fields since #600, pinned in SchemaValidationTest and ActionsTest), so
     * this value can only arrive through a NON-validating path: a raw database
     * write, or a restore_composition of an old snapshot, which by rule restores
     * verbatim and never blocks (#233). The allowlist is what keeps an arbitrary
     * stored string out of a class attribute on those paths, so it stays.
     */
    public function testGridItemInvalidTextRoleIgnored(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'A', 'text' => 'Body', 'text_role' => 'bogus']],
        ]);
        $this->assertStringContainsString('class="grid__item-text"', $html);
        $this->assertStringNotContainsString('text-bogus', $html);
    }

    public function testGridItemNoTextRoleIsPlain(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'A', 'text' => 'Body']],
        ]);
        $this->assertStringContainsString('class="grid__item-text"', $html);
    }

    public function testGridSchemaDeclaresTextRoleEnum(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/grid/schema.json'), true);
        $itemDef = $schema['props']['items']['items']['text_role'] ?? null;
        $this->assertNotNull($itemDef, 'grid item contract must declare text_role.');
        $this->assertSame('enum', $itemDef['type']);
        $this->assertSame(['mono', 'meta', 'label', 'kicker'], $itemDef['values']);
        // #600: the declaration is what arms the write-path rule. Without `strict`
        // the nested gate skips the field and the roles below become advisory again.
        $this->assertTrue($itemDef['strict'] ?? false, 'text_role must declare strict (#600)');
    }
}
