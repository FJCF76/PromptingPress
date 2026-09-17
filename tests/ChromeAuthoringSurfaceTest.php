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

    /**
     * A-21, REPRICED BY RULING A1 — the over-reach it documented is gone, because
     * the mechanism that caused it is gone.
     *
     * The four disclosure tests that used to live here pinned prose warning an
     * operator that one chrome option silently painted more than its name said:
     * `--header-bg` also filled two menu panels (with two DIFFERENT fallbacks),
     * `--footer-text` also coloured headings and the bottom-bar note,
     * `--footer-link-color` also coloured the contact block's mailto:/tel: links.
     * Six colours reaching sixteen surfaces, and the only fix available then was to
     * write the coupling down.
     *
     * Every one of those surfaces is now its OWN declared role with its OWN
     * selector, so the reach is not documented — it is DECLARED, and the schema is
     * the disclosure. This test is the replacement invariant: the specific surfaces
     * that used to be silently coupled must each be separately addressable, or the
     * coupling is back under a new name.
     */
    public function testEverySurfaceTheOldChromeOptionsSilentlyCoupledIsNowItsOwnRole(): void
    {
        $expected = [
            'nav' => [
                'menu'         => '.nav__menu',        // the mobile disclosure panel
                'submenu'      => '.nav__menu .sub-menu', // the desktop dropdown, once the same knob
                'link'         => '.nav__menu ul li a',
                // THE CHILD COMBINATOR IS PART OF THE SURFACE (#994, ruling D4). The
                // descendant form this pinned until then also reached every link in a
                // CURRENT parent's dropdown — harmless while the role carried no
                // default, a visible regression the moment the retirement gave it one
                // (a current "Services" page would have turned its whole submenu bold
                // and accent-coloured). The role-selector charset was widened to admit
                // `>` so the role can say what the retired CSS said.
                'link-current' => '.nav__menu ul li.current-menu-item > a',
                'logo'         => '.nav__logo',
                'toggle'       => '.nav__toggle',
            ],
            'footer' => [
                'heading'      => '.site-footer__heading',
                'note'         => '.site-footer__note',
                'copyright'    => '.site-footer__copyright',
                'address-link' => '.site-footer__address a',
                'social-link'  => '.site-footer__social-link',
                'link'         => '.site-footer__nav ul li a',
            ],
        ];

        foreach ($expected as $component => $roles) {
            $declared = pp_udc_component_roles($component);
            foreach ($roles as $role => $selector) {
                $this->assertArrayHasKey(
                    $role,
                    $declared,
                    "{$component}.{$role} used to be reachable only as a side effect of another "
                    . 'option; it must be its own role now, not unreachable.'
                );
                $this->assertSame(
                    $selector,
                    $declared[$role]['selector'] ?? '',
                    "{$component}.{$role} must target the element it claims to"
                );
            }
        }
    }

    /**
     * HOVER IS REACHABLE NOW, and this test is the inverse of the one it replaces.
     *
     * The old test pinned the DISCLOSURE that hover was pinned to the global accent
     * on six chrome surfaces and reachable from none of them — an honest statement of
     * a real limitation, because a chrome option could only ever be a resting colour.
     * Ruling A3 gave the engine `:hover` / `:focus-visible` / `:active` as value
     * dimensions and ruling A1 put chrome on that engine, so the limitation is gone.
     * Asserting the old disclosure would now pin a lie.
     */
    public function testChromeStatesAreReachableThroughTheEngine(): void
    {
        $states = array_keys(pp_udc_states());
        foreach ([':hover', ':focus-visible', ':active'] as $state) {
            $this->assertContains($state, $states);
        }

        // And it actually paints: a hover colour on a nav link reaches the stylesheet.
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            'nav' => ['link' => ['typography' => [':hover' => ['color' => '#ffd43b']]]],
        ]);
        $this->assertStringContainsString(
            '[data-pp-chrome="nav"] .nav__menu ul li a:hover{color:#ffd43b;}',
            pp_udc_chrome_authored_css()
        );
    }






    public function testBothREADMEsCarryTheReachTable(): void
    {
        // The READMEs are where a human looks. Each must carry the same disclosure
        // the schema does, or the two surfaces drift and the schema wins silently.
        // The READMEs are where a human looks, so each must list the roles its
        // schema declares — the drift this guards is a README describing a styling
        // surface the component no longer has.
        foreach (['nav', 'footer'] as $component) {
            $readme = $this->readme($component);
            foreach (array_keys(pp_udc_component_roles($component)) as $role) {
                $this->assertStringContainsString(
                    $role,
                    $readme,
                    "{$component}/README.md must name the `{$role}` role"
                );
            }
            $this->assertStringContainsString(
                'pp_site_udc',
                $readme,
                "{$component}/README.md must name the option its styling actually comes from"
            );
        }
    }

    /**
     * The AI-FACING docs enumerate every chrome role too (#991).
     *
     * WRITTEN BECAUSE THE DRIFT IT GUARDS ACTUALLY HAPPENED, in the change that added
     * this test. The `container` role landed in the schema, in both READMEs and in
     * AI_CONTEXT.md's component table — and was missed by the SECOND role enumeration
     * in AI_CONTEXT.md and by the one in ai-instructions/set-logo.md. Both files then
     * contradicted themselves, and the full suite stayed green: the README reach-table
     * above is derived from `pp_udc_component_roles()`, but nothing was derived from it
     * for the two surfaces the authoring MODEL actually reads.
     *
     * That is the I43 class exactly — "AI-facing documentation states the real contract,
     * derived from or checked against the registry/source, never hand-maintained prose
     * that drifts". A capability the model is never told about is a capability the
     * system does not have in practice, however correct the schema is.
     *
     * Presence-only, deliberately. It asserts the role NAME appears, never how it is
     * described, so rewording stays free and only a missing role fails.
     *
     * MATCHED AS A BACKTICKED WHOLE WORD SINCE #994, because a bare substring made the
     * guard satisfiable by accident. `social` is a substring of `social-link` and of
     * "social-icon row"; `contact` is a substring of `pp_footer_contact` and "contact
     * block" — strings both surfaces carried already — so two of the seven roles #994
     * adds were reported covered whether or not anyone had enumerated them. `menu` in
     * `submenu`, `bottom` in `bottom-row` and `link` in `social-link` have the same
     * property. The docs write every role in backticks, so requiring that form costs
     * nothing and makes the check mean what it says.
     */
    public function testAiFacingDocsEnumerateEveryChromeRole(): void
    {
        $surfaces = [
            'AI_CONTEXT.md'                => $this->repoFile('AI_CONTEXT.md'),
            'ai-instructions/set-logo.md'  => $this->repoFile('ai-instructions/set-logo.md'),
        ];

        foreach ($surfaces as $label => $contents) {
            // Fail closed: an unreadable or emptied surface must not pass vacuously.
            $this->assertNotSame('', trim($contents), "{$label} is empty or unreadable");

            foreach (['nav', 'footer'] as $component) {
                foreach (array_keys(pp_udc_component_roles($component)) as $role) {
                    if ($role === '_band') {
                        // `_band` is the engine's implicit root role, spelled the same on
                        // every component; it is covered by the chrome paragraphs' prose
                        // rather than by a per-component enumeration.
                        continue;
                    }
                    $this->assertStringContainsString(
                        '`' . $role . '`',
                        $contents,
                        "{$label} must name the `{$role}` role that {$component}'s schema declares — "
                        . 'a role the authoring model is never told about cannot be authored'
                    );
                }
            }
        }
    }

    /**
     * EVERY WORKED EXAMPLE OBEYS THE ONE PAIRING THE DOCS CALL MANDATORY (#994/#995).
     *
     * #994 fixed #992, so almost all the old pairing advice became optional — a
     * resting colour no longer cancels its own hover. Exactly one pairing survived as
     * REQUIRED, and it is the one no default can cover: the dropdown chevron is a
     * SIBLING of the nav link, not a child, so nothing makes `submenu-toggle` follow
     * `link`'s colour. Style a header dark without it and the chevron sits at the
     * ambient ink against the new background, invisible — which is #995.
     *
     * WHY THIS NEEDS A TEST RATHER THAN CARE. Every AI-facing surface stated the rule
     * and then broke it in its own example: `set_logo.md`'s canonical dark-header
     * copy-paste, the `update_site_option` description, and the runtime chrome
     * paragraph all set `link` with no `submenu-toggle`, each one sitting beside its
     * own "STILL MANDATORY" sentence. A model pattern-matches the JSON over the prose,
     * so those examples were instructions to reproduce the defect. Stating a rule is
     * not enforcing it; this is the enforcement.
     */
    public function testEveryNavExampleThatSetsLinkAlsoSetsSubmenuToggle(): void
    {
        $surfaces = [
            'ai-instructions/set-logo.md'      => $this->repoFile('ai-instructions/set-logo.md'),
            'components/nav/README.md'         => $this->repoFile('components/nav/README.md'),
            'lib/actions.php'                  => $this->repoFile('lib/actions.php'),
            'lib/ai-context.php'               => $this->repoFile('lib/ai-context.php'),
        ];

        $checked = 0;

        foreach ($surfaces as $label => $contents) {
            $this->assertNotSame('', trim($contents), "{$label} is empty or unreadable");

            // A nav example is a `"nav"` object literal. Escaped quotes appear in the
            // shell-command examples, plain ones in the JSON blocks and PHP strings, so
            // both spellings are matched.
            foreach (['"nav"', '\\"nav\\"'] as $needle) {
                $offset = 0;
                while (($at = strpos($contents, $needle, $offset)) !== false) {
                    $offset = $at + 1;
                    // THE EXAMPLE RUNS TO THE NEXT BLANK LINE, not to the end of its
                    // own line. The first draft of this sweep assumed one example per
                    // line and immediately failed on components/nav/README.md, whose
                    // JSON block spreads one `"nav"` map over five lines with
                    // `submenu-toggle` on the last — a false positive against a doc
                    // that was already correct. A paragraph is the unit every surface
                    // here actually uses to delimit an example.
                    $end     = strpos($contents, "\n\n", $at);
                    $example = substr($contents, $at, ($end === false ? strlen($contents) : $end) - $at);

                    if (!str_contains($example, 'link')) {
                        continue; // not a styling example, or sets no ink at all
                    }
                    $checked++;
                    $this->assertTrue(
                        str_contains($example, 'submenu-toggle'),
                        "{$label} has a nav example that sets `link` without `submenu-toggle`. "
                        . 'That is the one pairing #994 leaves mandatory: the chevron is the '
                        . "link's SIBLING, so no role default can make it follow the link "
                        . 'colour, and a dark header without it renders the chevron invisible '
                        . "(#995). The example a model copies has to obey the rule the prose "
                        . 'states. Offending example: ' . substr($example, 0, 160)
                    );
                }
            }
        }

        $this->assertGreaterThan(2, $checked, 'the sweep must actually reach the nav examples');
    }

    /** Read a repo-root-relative file, for the doc surfaces this class pins. */
    private function repoFile(string $relative): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    // ══════════════════════════════════════════════════════════════════════
    //  A-21 rows 41 + 42 — the two literals that can only get a stated reason
    // ══════════════════════════════════════════════════════════════════════

    /**
     * THE REOPENING CONDITION FIRED, AND THIS IS THE OTHER SIDE OF IT (#994).
     *
     * A-21 row 41 required the footer blurb's 32ch cap to carry a stated reason AND
     * the condition under which it would be revisited, because chrome could never
     * give it an authoring surface. The condition it named was "the chrome model's
     * own boundary moving", and #994 moved it: the cap is `blurb.sizing.max-width`
     * now, reachable like any other role value.
     *
     * So the invariant is asserted at its destination rather than deleted. A stated
     * reason for an unreachable literal and a reachable default are answers to the
     * same question — "can an author change this?" — and the test has to follow the
     * answer, or a future reader sees a retired requirement and assumes it lapsed.
     */
    public function testFooterBlurbMeasureCapIsReachableNowThatItsReopeningConditionFired(): void
    {
        $blurb = pp_udc_component_roles('footer')['blurb'];

        $this->assertContains('sizing', $blurb['groups'], 'the cap needs a group to live in');
        $this->assertSame(
            '32ch',
            $blurb['defaults']['sizing']['max-width'] ?? null,
            'the 32ch measure cap must survive the move, not be rounded off in it'
        );

        // The literal really left the stylesheet — a copy in both places is the
        // split authority the §2 boundary exists to end.
        $this->assertStringNotContainsString('max-width: 32ch', $this->css());

        // And the REASON survives with it: a `ch` unit stays short at any type size,
        // which is why it was chosen over a pixel width. The schema is where an author
        // reads it now, so that is where it has to be said.
        $this->assertMatchesRegularExpression(
            '/\bch\b/',
            $blurb['description'],
            'say why the unit is `ch` where the author will actually read it'
        );
    }

    public function testDropdownPanelFloorWidthIsGeometryAndStatesWhyItStayed(): void
    {
        $comment = $this->commentAtLiteral('min-width: 12rem;');

        $this->assertNotSame('', $comment, '.nav__menu .sub-menu\'s 12rem floor has no comment at the literal.');
        $this->assertStringContainsString('582', $comment);

        // THE DISPOSITION CHANGED WITH #994, so the assertion does. The old pin
        // demanded a REOPENING CONDITION, because the floor width was a literal chrome
        // could never make authorable. That is no longer why it is here: `submenu` is a
        // role with a `sizing` group, so an authored `sizing.min-width` reaches this
        // panel and outranks the line. It stays in the stylesheet because it is wrapper
        // GEOMETRY — the panel's floor width, not its look — which is the §2 boundary's
        // own category, and the comment has to say that rather than plead unreachability.
        $this->assertMatchesRegularExpression('/GEOMETRY/i', $comment);
        $this->assertStringContainsString('`submenu`', $comment);
        $this->assertStringContainsString('sizing.min-width', $comment);

        // Pin the OTHER half of the claim too. Prose that names the panel's surface
        // guards against deletion but not against becoming false: re-point the
        // background at a different token and the comment silently lies. The surface
        // is role defaults now, so that is where the agreement is checked.
        $submenu = pp_udc_component_roles('nav')['submenu']['defaults'];
        $this->assertSame('@color-surface', $submenu['background']['fill']['d'] ?? null);
        $this->assertSame('@radius', $submenu['border']['radius']['d'] ?? null);

        // And the floor width is still STRUCTURAL, in the media query that owns it.
        $this->assertStringContainsString('min-width: 12rem;', $this->css());
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
            // THE RATIONALE CHANGED WITH #994, in the same direction for both twins.
            // It used to be "chrome is template-owned with zero style slots, so a
            // literal is the only option" — which stopped being true when chrome got
            // roles. The cap stays for a better reason: it bounds the box the image
            // lays out in, which is wrapper GEOMETRY and belongs to the stylesheet by
            // the §2 boundary. Both twins must say the same thing, which is what this
            // test has always actually been about.
            $this->assertMatchesRegularExpression(
                '/geometry/i',
                $comment,
                "The {$which} logo cap must state WHY it stayed: it is wrapper geometry, "
                . 'not a look — an authored sizing.max-height still overrides it.'
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
            // The honest home for chrome's styling surface is now its ROLES, and
            // chrome_custom_properties is gone with the options that filled it.
            $this->assertArrayNotHasKey(
                'chrome_custom_properties',
                $schema['styling'],
                "{$component} no longer has inline chrome custom properties to list"
            );
            $this->assertNotEmpty(
                $schema['roles'] ?? [],
                "{$component} declares UDC roles — that is its styling surface now"
            );
            $this->assertSame(
                array_keys($schema['roles']),
                $schema['styling']['udc_roles'] ?? [],
                "{$component}'s styling block must name the same roles the schema declares"
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
