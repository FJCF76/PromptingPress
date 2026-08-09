<?php
/**
 * tests/ChromeAuthoringSurfaceTest.php
 *
 * Issue 582 (A-21 / A-23) — the template-owned chrome pair (`nav`, `footer`)
 * tells the truth about what it can and cannot be told to do.
 *
 * WHY THIS SUITE EXISTS. Chrome is rendered once by templates/base.php, never
 * composed (#223), and declares ZERO style slots by ratified contract. That makes
 * its schemas and READMEs the ONLY place an operator or an agent can learn what a
 * chrome option actually reaches — there is no slot list to consult and no page to
 * experiment on. Two failure classes follow, and this file guards both:
 *
 *   A-23  A prop that LOOKS writable and is not. nav/footer schemas declared
 *         logo_text / logo_id / logo_alt with no hint that no supported surface
 *         passes them, and nav's logo_id described its image-attachment check "as
 *         if it were writable" — but a composition naming nav is rejected outright,
 *         so that prop-level check can never run. The rule is enforced on the
 *         pp_logo_id SITE OPTION instead.
 *
 *   A-21  A custom property that does MORE than its name says. --header-bg also
 *         paints two menu panels (with two different fallbacks); --footer-text
 *         also colours headings and the bottom-bar note; --footer-link-color also
 *         colours the contact block's mailto:/tel: links; hover is pinned to the
 *         global accent on six surfaces and reachable from none of them.
 *
 * WHAT THESE ASSERTIONS PIN, AND WHAT THEY DO NOT. They pin that the DISCLOSURE
 * exists and names the surface it is about — a selector, a property, an option
 * key. They deliberately do NOT pin prose: rewording a sentence must stay free,
 * or the docs calcify and nobody improves them. What must fail here is DELETION —
 * a literal losing its stated reason, a prop losing its template-contract note.
 *
 * The two CSS literals (`32ch`, `12rem`) are a special case. Chrome declares zero
 * style slots, so a slot is out of the question for them and the only disposition
 * available is a stated reason WITH a reopening condition. Both are pinned to the
 * comment block that precedes the literal, at the literal.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class ChromeAuthoringSurfaceTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
    }

    private function schema(string $component): array
    {
        $decoded = json_decode(
            file_get_contents($this->themeRoot . "/components/{$component}/schema.json"),
            true
        );
        $this->assertIsArray($decoded, "{$component}/schema.json must be valid JSON.");
        return $decoded;
    }

    private function readme(string $component): string
    {
        return file_get_contents($this->themeRoot . "/components/{$component}/README.md");
    }

    private function css(): string
    {
        return file_get_contents($this->themeRoot . '/assets/css/components.css');
    }

    /** A single CSS declaration block by selector, anchored at a line start. */
    private function cssRuleBody(string $selector): ?string
    {
        $pattern = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
        return preg_match($pattern, $this->css(), $m) ? $m[1] : null;
    }

    /**
     * The comment block immediately preceding a CSS declaration, plus the rule
     * itself — the "at the literal" surface the issue names for a stated reason.
     *
     * Anchors on the declaration text so it keeps working when the rule moves,
     * and walks BACKWARD to the nearest comment close so an unrelated comment
     * elsewhere in the file can never satisfy the pin.
     */
    private function commentAtLiteral(string $declaration): string
    {
        $css = $this->css();
        $at  = strpos($css, $declaration);
        $this->assertNotFalse($at, "The declaration `{$declaration}` is gone from components.css.");

        $before      = substr($css, 0, $at);
        $commentEnd  = strrpos($before, '*/');
        if ($commentEnd === false) {
            return '';
        }
        $commentOpen = strrpos(substr($before, 0, $commentEnd), '/*');
        if ($commentOpen === false) {
            return '';
        }
        // Reject a comment that is not actually adjacent: if another rule closes
        // between the comment and the declaration, the comment documents THAT rule.
        $between = substr($before, $commentEnd);
        if (str_contains($between, '}')) {
            return '';
        }
        return substr($before, $commentOpen);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  A-23 — unreachable props state their template-contract status
    // ══════════════════════════════════════════════════════════════════════

    public static function unreachablePropProvider(): array
    {
        return [
            'nav logo_text'    => ['nav', 'logo_text'],
            'nav logo_id'      => ['nav', 'logo_id'],
            'footer logo_text' => ['footer', 'logo_text'],
        ];
    }

    /**
     * @dataProvider unreachablePropProvider
     */
    public function testUnreachablePropStatesItsTemplateContract(string $component, string $prop): void
    {
        $description = (string) ($this->schema($component)['props'][$prop]['description'] ?? '');
        $this->assertNotSame('', $description, "{$component}.{$prop} must have a description.");
        $this->assertStringContainsString(
            'TEMPLATE CONTRACT',
            $description,
            "{$component}.{$prop} is not reachable from any supported surface, but its schema "
            . 'description does not say so. The READMEs have always said it; the schemas are '
            . 'what an agent reads. State the contract status in the description (issue 582).'
        );
    }

    public function testNavLogoIdNoLongerDescribesItsValidationAsIfThePropWereWritable(): void
    {
        $description = (string) $this->schema('nav')['props']['logo_id']['description'];

        // The defect: "a non-image or non-existent ID is rejected when the action is
        // validated" reads as a promise about THIS PROP. A composition naming nav is
        // rejected outright, so that check never runs on the prop — it runs on the
        // pp_logo_id site option. The description must point at the option instead.
        $this->assertStringContainsString(
            'pp_logo_id',
            $description,
            'nav.logo_id must name the site option that is actually validated.'
        );
        $this->assertStringNotContainsString(
            'is rejected when the action is validated (same rule as the pp_logo_id site option)',
            $description,
            'This phrasing describes a prop-level rejection that can never fire on chrome. '
            . 'Describe the SITE OPTION as the validated surface (issue 582).'
        );
    }

    /**
     * logo_alt is the one that CHANGED class in this issue: it was unreachable, and
     * A-22 made it template-supplied. Its description must say the new thing, not
     * carry the old "not reachable" framing forward.
     */
    public function testLogoAltIsDocumentedAsTemplateSuppliedFromTheSiteOption(): void
    {
        foreach (['nav', 'footer'] as $component) {
            $description = (string) $this->schema($component)['props']['logo_alt']['description'];
            $this->assertStringContainsString(
                'pp_logo_alt',
                $description,
                "{$component}.logo_alt is supplied by base.php from the pp_logo_alt site option; "
                . 'its description must name that option as the write surface (issue 582).'
            );
            $this->assertStringContainsString(
                'never empty',
                $description,
                "{$component}.logo_alt must state that the resolved alt is never empty, so an "
                . 'agent knows the option is an override and not a requirement.'
            );
            $this->assertMatchesRegularExpression(
                '/WHITESPACE-ONLY/i',
                $description,
                "{$component}.logo_alt must state that a whitespace-only value counts as "
                . 'unprovided (issue 582 maintainer ruling).'
            );
        }
    }

    public function testFooterLogoAltDisclosesThatItSharesTheHeadersOption(): void
    {
        // The consequence worth stating: with a pp_footer_logo_id override in play,
        // one site-wide pp_logo_alt still wins over THAT attachment's own alt.
        $description = (string) $this->schema('footer')['props']['logo_alt']['description'];
        $this->assertStringContainsString('pp_footer_logo_id', $description);
        $this->assertMatchesRegularExpression(
            '/no pp_footer_logo_alt/i',
            $description,
            'State that the footer has no alt option of its own, so the shared behaviour is '
            . 'read as intentional rather than discovered as a surprise.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    //  A-21 — the chrome custom properties disclose their real reach
    // ══════════════════════════════════════════════════════════════════════

    public function testHeaderBgDisclosesBothMenuPanelsAndTheirDifferentFallbacks(): void
    {
        $description = (string) $this->schema('nav')['props']['bg']['description'];

        foreach (['mobile', 'dropdown'] as $panel) {
            $this->assertStringContainsString(
                $panel,
                strtolower($description),
                "--header-bg also paints the {$panel} panel; the schema must say so (issue 582)."
            );
        }
        // The two panels fall back DIFFERENTLY when the option is unset. That is the
        // part nobody could have guessed from the name.
        $this->assertStringContainsString('--color-bg', $description);
        $this->assertStringContainsString('--color-surface', $description);

        // And the fact itself must still be true in the CSS. Whitespace-tolerant so a
        // reformat cannot red the suite without a behaviour change.
        $this->assertMatchesRegularExpression(
            '/background:\s*var\(\s*--header-bg\s*,\s*var\(\s*--color-bg\s*\)\s*\)/',
            $this->css()
        );
        $this->assertMatchesRegularExpression(
            '/background:\s*var\(\s*--header-bg\s*,\s*var\(\s*--color-surface\s*\)\s*\)/',
            $this->css()
        );
    }

    public function testHeaderLinkColorDisclosesItsRestingFallbackNotOnlyTheActiveOne(): void
    {
        $description = (string) $this->schema('nav')['props']['link_color']['description'];

        // Only the active-link half was documented; the resting half falls back to
        // --color-text and was invisible.
        $this->assertStringContainsString('--color-text', $description);
        $this->assertStringContainsString('--color-accent', $description);
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(\s*--header-link-color\s*,\s*var\(\s*--color-text\s*\)\s*\)/',
            $this->css()
        );
    }

    public function testFooterTextDisclosesTheHeadingAndNoteSurfaces(): void
    {
        $description = (string) $this->schema('footer')['props']['text']['description'];

        $this->assertStringContainsString('heading', strtolower($description));
        $this->assertStringContainsString('note', strtolower($description));
        // True in the CSS: both surfaces route --footer-text.
        $this->assertMatchesRegularExpression(
            '/\.site-footer__heading\s*\{[^}]*var\(--footer-text/s',
            $this->css()
        );
        $this->assertMatchesRegularExpression(
            '/\.site-footer__note\s*\{[^}]*var\(--footer-text/s',
            $this->css()
        );
    }

    public function testFooterLinkColorDisclosesTheContactBlockLinks(): void
    {
        $description = (string) $this->schema('footer')['props']['link_color']['description'];

        $this->assertStringContainsString('contact', strtolower($description));
        $this->assertMatchesRegularExpression(
            '/\.site-footer__address a\s*\{[^}]*var\(--footer-link-color/s',
            $this->css()
        );
    }

    public function testHoverIsDisclosedAsUnreachableOnBothChromeComponents(): void
    {
        // Six surfaces hover to the global --color-accent and NONE of them is
        // reachable from a chrome option. Documented for nav links only before this.
        $nav    = json_encode($this->schema('nav')['props']);
        $footer = json_encode($this->schema('footer')['props']);

        foreach ([['nav', $nav], ['footer', $footer]] as [$name, $blob]) {
            $this->assertMatchesRegularExpression(
                '/hover/i',
                $blob,
                "{$name}'s schema must disclose that hover is pinned to the global accent."
            );
            $this->assertStringContainsString(
                'update_design_token',
                $blob,
                "{$name}'s schema must name the ONLY surface that can change the hover colour: "
                . 'the global accent design token. Giving chrome a hover option would mean '
                . 'giving chrome a style slot, which #223 rules out.'
            );
        }

        // The pins are only worth anything while the CSS still does this.
        $css = $this->css();
        foreach ([
            '.nav__logo:hover',
            '.nav__toggle:hover',
            // The nav LINK hover is the surface nav/schema.json's link_color
            // description makes its "hover keeps --color-accent" claim about, so it
            // is the one that most needs pinning — it was the omission this suite's
            // own comment ("six surfaces") did not cover.
            '.nav__menu ul li a:hover',
            '.site-footer__nav ul li a:hover',
            '.site-footer__address a:hover',
            '.site-footer__social-link:hover',
        ] as $selector) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($selector, '/') . '\s*\{[^}]*var\(--color-accent\)/s',
                $css,
                "{$selector} is documented as hovering to the global accent."
            );
        }
    }

    public function testBothREADMEsCarryTheReachTable(): void
    {
        // The READMEs are where a human looks. Each must carry the same disclosure
        // the schema does, or the two surfaces drift and the schema wins silently.
        $this->assertStringContainsString('--header-bg', $this->readme('nav'));
        $this->assertStringContainsString('--header-link-color', $this->readme('nav'));
        $this->assertStringContainsString('--footer-text', $this->readme('footer'));
        $this->assertStringContainsString('--footer-link-color', $this->readme('footer'));
        foreach (['nav', 'footer'] as $component) {
            $this->assertMatchesRegularExpression(
                '/hover/i',
                $this->readme($component),
                "{$component}/README.md must disclose the unreachable hover colour."
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  A-21 rows 41 + 42 — the two literals that can only get a stated reason
    // ══════════════════════════════════════════════════════════════════════

    public function testFooterBlurbMeasureCapCarriesAStatedReasonAndReopeningCondition(): void
    {
        $comment = $this->commentAtLiteral('max-width: 32ch;');

        $this->assertNotSame('', $comment, '.site-footer__blurb\'s 32ch cap has no comment at the literal.');
        $this->assertMatchesRegularExpression(
            '/REOPENING CONDITION/i',
            $comment,
            'The footer measure cap is the only literal of its kind and chrome can never give '
            . 'it a slot, so the disposition must be a stated reason WITH the condition under '
            . 'which it is revisited (issue 582).'
        );
        $this->assertMatchesRegularExpression('/\bch\b/', $comment, 'Say why the unit is `ch`.');
        $this->assertStringContainsString('582', $comment);
    }

    public function testDropdownPanelFloorWidthCarriesAStatedReasonAndReopeningCondition(): void
    {
        $comment = $this->commentAtLiteral('min-width: 12rem;');

        $this->assertNotSame('', $comment, '.nav__menu .sub-menu\'s 12rem floor has no comment at the literal.');
        $this->assertMatchesRegularExpression('/REOPENING CONDITION/i', $comment);
        $this->assertStringContainsString('582', $comment);
        // The panel is PARTLY token-reachable already; say so, or the next reader
        // concludes the whole panel is off-limits to authoring.
        $this->assertStringContainsString('--header-bg', $comment);
        $this->assertStringContainsString('--radius', $comment);

        // Pin the OTHER half of the claim too. Asserting only that the comment names
        // those properties guards the prose against deletion but not against becoming
        // false: re-point the panel's background at a different token and the comment
        // silently lies while this test stays green. Assert the rule body agrees.
        $rule = $this->cssRuleBody('.nav__menu .sub-menu');
        $this->assertNotNull($rule, '.nav__menu .sub-menu rule missing from components.css');
        $this->assertStringContainsString('var(--header-bg', $rule);
        $this->assertStringContainsString('var(--radius', $rule);
    }

    public function testNavLogoCapMirrorsTheFooterTwinsRationale(): void
    {
        // The footer twin has carried the full rationale since #299; the nav twin
        // carried none, so the pair read as one deliberate cap and one magic number.
        // Route both twins through the SAME adjacency-checked helper the other literal
        // pins use. A bare strrpos('/*') walk-back would let an unrelated earlier
        // comment satisfy the assertion after the real rationale was deleted — and the
        // dropdown-panel comment added by this very issue now contains the phrases
        // being matched, so that failure mode is live, not hypothetical.
        foreach ([
            ['nav',    '.nav__logo-image {'],
            ['footer', '.site-footer__logo-image {'],
        ] as [$which, $anchor]) {
            $comment = $this->commentAtLiteral($anchor);
            $this->assertNotSame('', $comment, "The {$which} logo cap has no comment at the literal.");
            $this->assertMatchesRegularExpression(
                '/template-owned|zero style slots/i',
                $comment,
                "The {$which} logo cap must state WHY a literal is the only option: chrome is "
                . 'template-owned with zero style slots.'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  The invariants none of the above may weaken
    // ══════════════════════════════════════════════════════════════════════

    public function testNeitherChromeComponentDeclaresAStyleSlot(): void
    {
        foreach (['nav', 'footer'] as $component) {
            $schema = $this->schema($component);
            $this->assertArrayNotHasKey(
                'style_slots',
                $schema,
                "Chrome declares ZERO style slots by ratified contract (#223). Documenting what "
                . "the {$component}'s custom properties reach must never become a reason to "
                . 'declare one.'
            );
            $this->assertNotEmpty(
                $schema['styling']['chrome_custom_properties'] ?? [],
                "{$component} keeps its chrome_custom_properties list (issue 581) — the honest "
                . 'home for properties that are not design tokens and not style slots.'
            );
        }
    }

    public function testChromeStaysNonComposable(): void
    {
        $composable = pp_composable_components();
        foreach (['nav', 'footer'] as $component) {
            $this->assertArrayNotHasKey($component, $composable);
            $this->assertContains($component, pp_template_owned_components());
        }
    }

    /**
     * The AI-facing surfaces carry the same claims and were the ONLY ones unpinned.
     *
     * AI_CONTEXT.md, lib/ai-context.php and ai-instructions/set-logo.md are what an
     * agent actually reads at runtime. Before this pin, the two schemas and two
     * READMEs were guarded and those three were not, so the next change to the alt
     * chain would have updated four files under test and left three prose copies
     * telling an agent something false.
     */
    public function testAiFacingSurfacesAgreeThatChromeLogosAreSetBySiteOption(): void
    {
        foreach ([
            'AI_CONTEXT.md',
            'lib/ai-context.php',
            'ai-instructions/set-logo.md',
        ] as $surface) {
            $text = file_get_contents($this->themeRoot . '/' . $surface);
            $this->assertNotFalse($text, "{$surface} must be readable.");

            $this->assertStringContainsString(
                'pp_logo_alt',
                $text,
                "{$surface} must name the site option that sets the chrome logo alt."
            );
            $this->assertMatchesRegularExpression(
                '/never empty/i',
                $text,
                "{$surface} must state that the resolved alt is never empty, so an agent "
                . 'treats pp_logo_alt as an override and not a requirement.'
            );
            // The accepted-input nuance has to be legible from the surface, not only
            // from the resolver: an agent that writes ' ' to "clear" the alt would
            // otherwise be surprised when the value is ignored.
            $this->assertMatchesRegularExpression(
                '/whitespace[- ]only/i',
                $text,
                "{$surface} must state that a whitespace-only pp_logo_alt counts as "
                . 'unprovided and falls through the chain.'
            );
        }

        // The runtime catalog must NOT still advertise the chrome logo as a component
        // PROP an agent can set — that was the pre-582 defect on this surface.
        $catalog = file_get_contents($this->themeRoot . '/lib/ai-context.php');
        $this->assertMatchesRegularExpression(
            '/nav\/footer logos are NOT props/i',
            $catalog,
            'lib/ai-context.php listed nav/footer logo_id + logo_alt among props an agent '
            . 'may set on components. Chrome takes no props from any supported surface.'
        );
    }

    public function testChromeTemplatesStillDoNotReadAnIdProp(): void
    {
        // Pinned at #581 and re-pinned here: reading $props['id'] would be the first
        // step toward a composable header, which the chrome contract rules out. The
        // A-23 description rewrites in this issue touch the same schemas, so keep
        // the guard next to them.
        foreach (['nav/nav.php', 'footer/footer.php'] as $template) {
            $code = file_get_contents($this->themeRoot . '/components/' . $template);
            $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $code);
            $this->assertDoesNotMatchRegularExpression(
                "/\\\$props\\['id'\\]/",
                $code,
                "{$template} must not read an `id` prop (issue 581)."
            );
        }
    }
}
