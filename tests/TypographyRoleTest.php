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
    }
}
