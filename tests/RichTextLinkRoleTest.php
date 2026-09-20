<?php
/**
 * tests/RichTextLinkRoleTest.php — the `*-link` role contract, as one claim (#1069).
 *
 * A v2 rich-text surface renders author markup through a kses sanitizer, so it can carry
 * an `<a>` the component never wrote. The container role cannot reach that anchor: its
 * selector matches the WRAPPER, so a colour set there arrives by INHERITANCE, and
 * base.css's `a { color: var(--color-accent) }` matches the anchor DIRECTLY. A direct
 * declaration beats an inherited one whatever the cascade layer, so the unlayered authored
 * block never enters the contest. That is why each such surface needs its own `*-link`
 * role, and why faq's schema claiming otherwise was a measured 3.21:1 defect rather than a
 * wording slip.
 *
 * WHY THIS FILE EXISTS RATHER THAN SEVEN MORE PER-COMPONENT ASSERTIONS. The rule is a
 * CLASS rule — "every rich-text surface has a link address, and that address emits nothing
 * until written to" — and #1069 shipped only after its roster was corrected TWICE: once
 * from "faq is the only surface missing one" and once from "five surfaces" to six, when
 * `hero.proof` was found. A claim that is discovered by enumeration is a claim that must be
 * enforced by enumeration, in one place, or the seventh surface ships without an address
 * exactly as the sixth nearly did.
 *
 * THE THREE HALVES, all of which have to hold together:
 *   1. the address EXISTS for every surface (else an author has nowhere to put a colour);
 *   2. it declares NO DEFAULTS (a default is unlayered and outranks the premium button
 *      rules, repainting an author-written `<a class="btn">` — #545 through a role
 *      selector, and every sanitizer here admits `class`);
 *   3. it EMITS when written to (a role that emitted nothing when authored would be the
 *      write-accept-paint-nothing class I19 forbids and #1048 is open against — and an
 *      absence-only test cannot tell that apart from correctness).
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class RichTextLinkRoleTest extends TestCase
{
    /**
     * Every v2 rich-text surface, its link role, and a fixture that renders the surface
     * with an anchor inside it.
     *
     * THE FIXTURE IS PART OF THE CLAIM, not a convenience. `UdcEngineTest`'s selector
     * sweep checks that a role's trailing bare element renders, but it matches against the
     * WHOLE rendered html rather than under the role's own ancestor — so `hero.proof-link`
     * passed that sweep with no anchor in the proof row at all, because hero renders its
     * CTAs as `<a class="btn">`. The scoped check below is what actually proves the
     * selector can match. (The unscoped sweep is a separate, pre-existing weakness and is
     * filed rather than fixed here.)
     */
    private function surfaces(): array
    {
        return [
            'section' => ['role' => 'body-link',    'selector' => '.section__content a', 'ancestor' => 'section__content',
                          'props' => ['title' => 'T', 'body' => '<p>See the <a href="/docs">docs</a>.</p>']],
            'cta'     => ['role' => 'body-link',    'selector' => '.cta__body a',        'ancestor' => 'cta__body',
                          'props' => ['title' => 'T', 'body' => 'See the <a href="/docs">docs</a>.', 'button_text' => 'Go', 'button_url' => '#']],
            'faq'     => ['role' => 'answer-link',  'selector' => '.faq__answer a',      'ancestor' => 'faq__answer',
                          'props' => ['title' => 'T', 'items' => [['question' => 'Q?', 'answer' => '<p>A <a href="/docs">link</a>.</p>']]]],
            'hero'    => ['role' => 'proof-link',   'selector' => '.hero__proof a',      'ancestor' => 'hero__proof',
                          'props' => ['title' => 'T', 'layout' => 'centered', 'proof' => '<p>Used by <a href="/customers">teams</a></p>']],
            'embed'   => ['role' => 'content-link', 'selector' => '.embed__content a',   'ancestor' => 'embed__content',
                          'props' => ['content' => '<p>See the <a href="/docs">docs</a>.</p>']],
            'table'   => ['role' => 'cell-link',    'selector' => '.table__cell a',      'ancestor' => 'table__cell',
                          'props' => ['headers' => ['H'], 'rows' => [['<a href="/docs">docs</a>']]]],
        ];
    }

    /**
     * THE ROSTER IS DERIVED FROM THE SCHEMAS, so a rich-text surface added later cannot
     * ship without a link address by simply not being added to the list above.
     *
     * Any role whose selector ENDS IN a bare `a` is a link role; every component that
     * declares one must appear in surfaces(), and every entry in surfaces() must name a
     * role its component really declares. Both directions, because each catches a
     * different mistake: a new component with a link role and no entry here would go
     * unchecked, and an entry naming a role that was renamed would pass vacuously.
     */
    public function testEveryDeclaredLinkRoleIsCoveredHereAndViceVersa(): void
    {
        // CHROME IS EXCLUDED, and the reason is worth stating because nav's roles match the
        // same selector shape. `nav.link` selects `.nav__menu ul li a` — a bare trailing
        // anchor, exactly like a rich-text link role — but it is not one: nav renders no
        // author markup, its anchors are the theme's own menu items, and #994 gave those
        // roles real DEFAULTS on purpose (a resting colour that no longer cancels the hover
        // or the you-are-here accent). Sweeping them in here would assert rule 2 against a
        // role whose defaults are the ruled behaviour. The distinction is "does this surface
        // render author markup", not "does the selector end in an anchor".
        $declared = [];
        foreach (array_keys(pp_get_registered_components()) as $component) {
            if (pp_udc_is_chrome($component)) {
                continue;
            }
            foreach (pp_udc_component_roles($component) as $role => $definition) {
                if (preg_match('/(?:^|[\s>])a\s*$/', (string) ($definition['selector'] ?? ''))) {
                    $declared[$component][] = $role;
                }
            }
        }

        $covered = [];
        foreach ($this->surfaces() as $component => $surface) {
            $covered[$component][] = $surface['role'];
        }

        ksort($declared);
        ksort($covered);
        $this->assertSame(
            $covered,
            $declared,
            'the link-role roster moved: a v2 rich-text surface either gained a link role '
            . 'with no coverage here, or lost one this file still claims. Both directions '
            . 'matter — #1069 shipped only after its roster was corrected twice.'
        );
    }

    /**
     * RULE 2: NO DEFAULTS. A default here is unconditional, unlayered, and outranks the
     * shared premium `main .btn:not(...)` rules — so it repaints an author-written
     * `<a class="btn">` inside the surface, which is #545 reintroduced through a role
     * selector. Every sanitizer on these six surfaces admits `class`, so that anchor is
     * reachable on all of them.
     */
    public function testNoLinkRoleDeclaresADefaultAndNoneEmitsAtRest(): void
    {
        foreach ($this->surfaces() as $component => $surface) {
            $roles = pp_udc_component_roles($component);
            $this->assertArrayHasKey($surface['role'], $roles, "{$component} must declare {$surface['role']}");
            $this->assertSame(
                [],
                $roles[$surface['role']]['defaults'] ?? null,
                "{$component}.{$surface['role']} gained a default and will repaint author "
                . '.btn anchors inside this surface (#545 through a role selector)'
            );
            $this->assertStringNotContainsString(
                $surface['selector'],
                pp_udc_component_defaults_css($component),
                "{$component}.{$surface['role']} must emit nothing at rest: base.css already "
                . 'gives every anchor its treatment, and restating it unlayered takes the '
                . 'premium button rules with it'
            );
        }
    }

    /**
     * RULE 3: IT EMITS WHEN WRITTEN TO, in both states.
     *
     * The hover half is not a nicety. base.css gives every anchor an accent hover, so a
     * dark surface that re-inks only the rest state flips back to the accent under the
     * cursor — the same shape as the #992 chrome defect, on an author's own write.
     */
    public function testAnAuthoredValueOnEveryLinkRoleReachesThePageInBothStates(): void
    {
        foreach ($this->surfaces() as $component => $surface) {
            $css = pp_udc_band_css([
                'component' => $component,
                'id'        => 'pp-a11a11a1',
                'props'     => [],
                'udc'       => [$surface['role'] => ['typography' => [
                    'color'  => '#ffd479',
                    ':hover' => ['color' => '#ffffff'],
                ]]],
            ]);
            $this->assertStringContainsString(
                '[data-pp-band="pp-a11a11a1"] ' . $surface['selector'] . '{color:#ffd479;}',
                $css,
                "an authored colour on {$component}.{$surface['role']} must reach the page — "
                . 'a role that accepts a write and emits nothing is the class #1048 is open against'
            );
            $this->assertStringContainsString(
                '[data-pp-band="pp-a11a11a1"] ' . $surface['selector'] . ':hover{color:#ffffff;}',
                $css,
                "the hover half of {$component}.{$surface['role']} must reach the page too"
            );
        }
    }

    /**
     * 14.1 AUTHORING-PATH MANDATE — the roles must be WRITABLE, not merely declared.
     *
     * Every other test here reads the schema or the emitter. None of them went through the
     * gate an author's write actually traverses, so all of them would have stayed green if
     * `pp_validate_composition()` refused the new roles outright — the emit side cannot
     * tell an accepted role from a refused one. Found by the pre-landing testing pass,
     * which measured that `_css` had this proof and the roles did not.
     */
    public function testEveryLinkRoleIsWritableThroughTheRealCompositionPath(): void
    {
        foreach ($this->surfaces() as $component => $surface) {
            $composition = [[
                'component' => $component,
                'id'        => 'pp-1069feed',
                'props'     => $surface['props'],
                'udc'       => [$surface['role'] => ['typography' => [
                    'color'  => '#ffd479',
                    ':hover' => ['color' => '#ffffff'],
                ]]],
            ]];
            $this->assertTrue(
                pp_validate_composition($composition),
                "{$component}.{$surface['role']} must be writable through the gate every "
                . 'ingress path traverses'
            );

            $composition[0]['udc'] = [
                $surface['role'] . '-nope' => ['typography' => ['color' => '#ffd479']],
            ];
            $this->assertNotTrue(
                pp_validate_composition($composition),
                'red-proofed: a role that does not exist is still refused there, so the '
                . 'assertion above is not passing because the gate accepts everything'
            );
        }
    }

    /**
     * THE SELECTOR CAN ACTUALLY MATCH, checked UNDER THE ROLE'S OWN ANCESTOR.
     *
     * This is the check `hero.proof-link` would have passed vacuously: the engine's own
     * sweep looks for a bare `<a` anywhere in the rendered html, and hero renders its CTAs
     * as anchors. Scoping it to the ancestor's own markup is what proves the address is
     * reachable rather than merely that the component contains an anchor somewhere.
     */
    public function testEveryLinkRoleSelectorMatchesAnAnchorInsideItsOwnSurface(): void
    {
        foreach ($this->surfaces() as $component => $surface) {
            ob_start();
            try {
                pp_get_component($component, $surface['props']);
            } finally {
                $html = ob_get_clean();
            }

            $ancestor = preg_quote($surface['ancestor'], '/');
            // From the ancestor's class attribute to the end of that element's own start
            // tag and past it: a non-greedy reach to the first anchor, bounded by the next
            // occurrence of the ancestor so a second surface cannot lend its link.
            $this->assertMatchesRegularExpression(
                '/' . $ancestor . '[^>]*>(?:(?!' . $ancestor . ').)*?<a\b/is',
                $html,
                "{$component}.{$surface['role']} selects an anchor inside .{$surface['ancestor']}, "
                . 'but the rendered surface contains none — the role would emit a block '
                . 'matching nothing. Note the engine\'s own selector sweep does NOT catch '
                . 'this: it looks for a bare `<a` anywhere in the html, which a button satisfies.'
            );
        }
    }
}
