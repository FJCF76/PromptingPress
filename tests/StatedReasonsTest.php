<?php
/**
 * tests/StatedReasonsTest.php
 *
 * Issue 585 (C-6 + the Addendum-#2 ratified keeps) — the DECISION half of the docs
 * contract, as distinct from the derived half in DocsCoverageTest.php.
 *
 * WHY THIS SUITE EXISTS. A literal is a product default only if someone states the
 * reason. Forty CSS literals were ratified as product defaults and eight decorative
 * capabilities were ratified as deliberately unauthorable; thirty-nine of the forty
 * had no stated reason on any surface an authoring agent reads, and the one reason
 * that did exist for the middot separator lived in a CSS comment — which is not an
 * authoring surface, because nothing routes an agent to `components.css`. Without a
 * guard, the next person to tidy a README deletes a paragraph whose absence is
 * invisible until an operator asks "why can't I change this?" and nobody knows.
 *
 * WHAT THESE ASSERTIONS PIN, AND WHAT THEY DO NOT — read this before trusting them.
 * They pin PRESENCE: that the literal is still named on its authoring surface, and
 * that the surface still offers a reopening condition. They do NOT and cannot verify
 * that a reason is CORRECT, well-argued, or still true — a marker assertion proves a
 * paragraph exists, not that it is right. That limit is deliberate and is the same
 * posture ChromeAuthoringSurfaceTest takes: pinning prose would calcify the docs and
 * stop anyone improving them. What must fail here is DELETION.
 *
 * Reasons live in exactly ONE canonical place each, by the routing rule in the
 * gate: schema `description` where a shipped slot sits beside the literal, the
 * component README where the decision is component-specific, and
 * ai-instructions/style-component.md where it is cross-component authoring posture.
 * These assertions follow that routing, so a reason moved to a second home to
 * "make it easier to find" will not satisfy them twice.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class StatedReasonsTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
    }

    private function doc(string $relative): string
    {
        $path = $this->themeRoot . '/' . $relative;
        $this->assertFileExists($path, "Authoring surface {$relative} is missing.");
        return (string) file_get_contents($path);
    }

    /**
     * Every ratified keep and every C-6 decorative boundary, with the authoring
     * surface that owns its stated reason and a needle naming the literal itself.
     *
     * The needle is the literal's VALUE or selector wherever one exists, so a rewrite
     * passes and a deletion fails. A handful of rows have no value to pin — the faq
     * chevron's `currentColor` inheritance and the cta inner-gap disclosure are facts,
     * not numbers — and those carry a short phrase instead. Those are the rows that will
     * need updating if someone rewords them; that is the accepted cost of guarding a
     * disclosure that has no literal of its own.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function statedReasonProvider(): array
    {
        return [
            // ── hero (Addendum #2 rows 1, 3-9) ──────────────────────────────
            'hero title leading'        => ['components/hero/README.md', 'line-height: 1.03', 'hero'],
            'hero subtitle measure'     => ['components/hero/README.md', 'max-width: 40ch', 'hero'],
            'hero cover fold'           => ['components/hero/README.md', 'min-height: 70vh', 'hero'],
            'hero default split ratio'  => ['components/hero/README.md', 'minmax(0, 1.08fr)', 'hero'],
            'hero enum split ratios'    => ['components/hero/README.md', '3fr 2fr', 'hero'],
            'hero unemitted surface'    => ['components/hero/README.md', '.hero__surface-label', 'hero'],

            // ── section (rows 11, 12) — row 11's reason MOVED off the CSS comment
            'section middot glyph'      => ['components/section/schema.json', 'GLYPH is a stated default', 'section'],
            'section paragraph rhythm'  => ['components/section/README.md', 'margin-top: 1.05rem', 'section'],

            // ── cta (row 13) ────────────────────────────────────────────────
            'cta button gap'            => ['components/cta/README.md', 'gap: var(--space-sm)', 'cta'],
            // The disclosure SURVIVED the v2 rebuild and moved with the value it describes
            // (#1026): it is the `inner` role's description now, where `--cta-inner-gap`'s
            // used to be. The needle is upper-cased to match, which is the accepted cost this
            // docblock names for a row whose reason has no literal of its own.
            'cta inner-gap disclosure'  => ['components/cta/schema.json', 'IT DOES NOT GOVERN THE SPACE BETWEEN THE TWO BUTTONS', 'cta'],

            // ── grid (rows 15-20 + C-6 connector) ───────────────────────────
            //
            // SIX ROWS LEFT AT #1101 AND TWO ARRIVED, and the retirement is recorded in
            // full on testTheRetiredGridStatedDefaultsAreGoneOrAuthorable() below rather
            // than here — including the assertion that keeps each one from coming back
            // unexplained. The short version: FOUR of the six literals no longer ship at
            // all (the featured lift, the stripe period, the step-title cap, the texture
            // period slot), and TWO stopped being stated defaults without going anywhere
            // (the 58rem and 56rem four-card caps), because a stated default is a value
            // the theme fixes with NO authoring surface — and `list` -> `sizing.max-width`
            // is an authoring surface.
            'grid hover lift'           => ['components/grid/README.md', 'translateY(-2px)', 'grid'],
            // #601: the row survives and its REASON has been rewritten twice without
            // changing. It first disclosed the connector as reachable through two slots;
            // then, once `.grid__item { overflow: hidden }` was found to clip a rule
            // positioned at `left: 100%`, as a rule that paints nothing. #1101 changed
            // neither fact — `overflow: hidden` is exactly as load-bearing for the
            // banner-image crop as it was — so the v2 README states it as its own section
            // with both issue numbers: the record (#601) and where the revive-or-remove
            // decision belongs (#670). The row keeps its membership of the documented
            // exception class (the faq chevron, the cta inner-gap): its reason has no
            // literal of its own, so a rewording breaks this pin. That is the point — the
            // reason is a CLAIM ABOUT RENDERING, and a claim must not drift silently, least
            // of all one that says a shipped rule is dead.
            'grid steps connector'      => ['components/grid/README.md', 'paints nothing, and it is kept on purpose', 'grid'],
            // The two narrowings the v2 rebuild STATED rather than let a reader discover,
            // which is the same obligation the rows above carry and the reason they belong
            // in this provider: a narrowing nobody wrote down is indistinguishable from a
            // regression when the next person measures it.
            'grid arrow weight'         => ['components/grid/README.md', 'font-weight: 700', 'grid'],
            'grid inset focus ring'     => ['components/grid/README.md', 'outline-offset: -3px', 'grid'],

            // ── testimonials (rows 21-25) ───────────────────────────────────
            // Five rows, not six. A "stated default" is a value the theme fixes with no
            // authoring surface, which is why it has to carry a written reason. On the
            // v2 component most of these stopped being stated defaults and became ROLE
            // DEFAULTS — declared in schema.json, documented in the README, and
            // overridable per band — so the reason each one still needs is "why this
            // value", which is what the README rows below say.
            //
            // THE SIXTH ROW IS GONE BECAUSE THE THING IT EXPLAINED IS GONE: the
            // decorative opening-quote glyph was a designable decoration that no slot
            // could remove, so a quote whose own text already carried typographic
            // quotation marks rendered two opening quotes (#901's closing note). The
            // structural-CSS boundary has no room for it and Sprint 0's taxonomy has no
            // generated-content group, so it was removed rather than reproduced.
            'testimonials avatar size'  => ['components/testimonials/README.md', '2.75rem', 'testimonials'],
            'testimonials avatar shape' => ['components/testimonials/README.md', 'border-radius: 50%', 'testimonials'],
            'testimonials avatar crop'  => ['components/testimonials/README.md', 'object-fit: cover', 'testimonials'],
            'testimonials stack type'   => ['components/testimonials/README.md', '1.375rem', 'testimonials'],
            'testimonials stack measure'=> ['components/testimonials/README.md', 'max-width: 42rem', 'testimonials'],

            // ── faq (rows 27-30 + the C-6 open animation) ───────────────────
            'faq chevron box'           => ['components/faq/README.md', '`10px` box', 'faq'],
            'faq chevron currentColor'  => ['components/faq/schema.json', 'currentColor borders', 'faq'],
            'faq question/answer type'  => ['components/faq/README.md', 'weight (560 vs 430)', 'faq'],
            'faq mobile question type'  => ['components/faq/README.md', '0.98rem', 'faq'],
            'faq open animation'        => ['components/faq/README.md', 'faq-open 150ms ease', 'faq'],

            // ── stats (rows 31-32) ──────────────────────────────────────────
            'stats wrap floor'          => ['components/stats/README.md', 'min-width: 8rem', 'stats'],
            'stats label size'          => ['components/stats/README.md', 'font-size: 0.875rem', 'stats'],
            'stats label size slot'     => ['components/stats/schema.json', "label's SIZE (0.875rem) is a stated default", 'stats'],

            // ── table (rows 33-38) ──────────────────────────────────────────
            'table body type'           => ['components/table/README.md', 'font-size: 0.9375rem', 'table'],
            'table caption size'        => ['components/table/README.md', 'font-size: 0.875rem', 'table'],
            'table header weight'       => ['components/table/README.md', 'font-weight: 700', 'table'],
            'table rule widths'         => ['components/table/README.md', 'header `2px`', 'table'],
            'table cell density'        => ['components/table/README.md', 'var(--space-sm) var(--space-md)', 'table'],
            'table header nowrap'       => ['components/table/README.md', 'white-space: nowrap', 'table'],

            // ── muted-variant framing borders (rows 43-44) ──────────────────
            'embed framing borders'     => ['components/embed/README.md', '`1px solid var(--color-border)`', 'embed'],
            'logos framing borders'     => ['components/logos/README.md', '`1px solid var(--color-border)`', 'logos'],
            'logos fit model'           => ['components/logos/README.md', 'object-fit: contain', 'logos'],

            // ── chrome (rows 41-42) — chrome declares zero slots by contract ─
            'nav dropdown floor'        => ['components/nav/README.md', 'min-width: 12rem', 'nav'],
            'footer blurb measure'      => ['components/footer/README.md', '32ch', 'footer'],

            // ── the eyebrow pill geometry (row 39) — cross-component ────────
            'eyebrow pill geometry'     => ['ai-instructions/style-component.md', 'padding: 0.35rem 0.85rem', 'eyebrow'],
        ];
    }

    /**
     * @dataProvider statedReasonProvider
     */
    public function testRatifiedLiteralKeepsItsStatedReason(string $surface, string $needle, string $subject): void
    {
        $this->assertStringContainsString(
            $needle,
            $this->doc($surface),
            "The stated reason for the {$subject} default \"{$needle}\" is gone from {$surface}. "
            . 'A literal with no stated reason is not a product default, it is an unexamined '
            . 'value — restore the reason rather than deleting the guard.'
        );
    }

    /**
     * THE SIX GRID ROWS THAT LEFT THE PROVIDER AT #1101, AND WHY EACH LEFT.
     *
     * A stated default is a value the theme FIXES WITH NO AUTHORING SURFACE — that is the
     * whole reason it has to carry a written reason, because an operator who meets it has
     * no other way to find out why they cannot change it. Two different things can
     * therefore end a row: the literal stops shipping, or it stops being unauthorable.
     * Four grid rows went the first way and two went the second, and this test asserts
     * which, so neither kind of retirement can be reversed in silence.
     *
     *   GONE FROM THE STYLESHEET ENTIRELY
     *   `translateY(-0.18rem)` — the `.grid--dark :first-child` featured lift. It retired
     *       with the `card_emphasis` prop under ruling D9: v1's featured treatment was
     *       `:first-child`, i.e. ORDINAL styling, and Addendum B exclusion 3 rules that an
     *       item is addressed by its minted id and nothing else so that reordering carries
     *       the styling WITH the card. Measured on the owner's live site first: all 11
     *       production grid bands render `grid--uniform`, so the treatment was already
     *       switched off everywhere it could have applied.
     *   `2.75rem` — the featured card's stripe period, a decoration of the same retired
     *       treatment, and the `repeat PERIOD` schema row was that slot's own description.
     *   `max-width: 17rem` — the steps step-title cap.
     *
     *   STILL SHIPPING, NO LONGER STATED DEFAULTS
     *   `max-width: 58rem` / `56rem` — the centred 2x2 four-card caps. The literals are
     *       still in grid's structural block and still render, but `list` declares the
     *       `sizing` group, so an author caps the track row themselves through
     *       `list` -> `sizing.max-width`; the same is true of the step-title measure
     *       through `card-title`. A value with a role address is a DEFAULT, not a stated
     *       default, and demanding a "why you cannot change this" paragraph for something
     *       an author can change is how a docs guard teaches the wrong thing.
     *
     * Each half is pinned below: the vanished literals must stay out of grid's block, and
     * the surviving ones must keep the role address that is the whole reason their row
     * retired. Put either back the other way and this fails before the provider does.
     */
    public function testTheRetiredGridStatedDefaultsAreGoneOrAuthorable(): void
    {
        $css = $this->doc('assets/css/components.css');
        $this->assertMatchesRegularExpression(
            '/COMPONENT:\s*grid\b(.*?)(?=\/\*\s*={5,}\s*(?:COMPONENT|SHARED):|\z)/s',
            $css,
            'no COMPONENT: grid block — this scan has gone blind and every absence below is worthless'
        );
        preg_match('/COMPONENT:\s*grid\b(.*?)(?=\/\*\s*={5,}\s*(?:COMPONENT|SHARED):|\z)/s', $css, $m);
        $block = preg_replace('/\/\*.*?\*\//s', '', $m[1]) ?? $m[1];

        foreach ([
            'translateY(-0.18rem)' => 'the `card_emphasis` featured lift (ruling D9 — ordinal styling)',
            '2.75rem'              => "the featured card's stripe period",
            '17rem'                => 'the steps step-title cap',
        ] as $literal => $what) {
            $this->assertStringNotContainsString(
                $literal,
                $block,
                "grid's block declares {$literal} again — {$what} retired at #1101, and its "
                . 'stated reason left the README with it. A literal back in the stylesheet '
                . 'with no reason on any authoring surface is exactly what this suite exists '
                . 'to catch: restore the reason and the provider row together.'
            );
        }

        // The other half: the two caps that still ship stopped being STATED defaults
        // because they acquired a role address. If that address goes away they are
        // unauthorable fixed values again, and they owe the README a reason again.
        $this->assertStringContainsString('58rem', $block, 'premise: the four-card cap still ships');
        $this->assertStringContainsString('56rem', $block, 'premise: the steps four-card cap still ships');

        $roles = json_decode($this->doc('components/grid/schema.json'), true)['roles'] ?? [];
        foreach (['list' => 'the four-card track caps', 'card-title' => 'the step-title measure'] as $role => $what) {
            $this->assertContains(
                'sizing',
                $roles[$role]['groups'] ?? [],
                "grid's `{$role}` role no longer permits `sizing`, so {$what} is an "
                . 'unauthorable fixed value again — which makes it a stated default again, '
                . 'and it needs its reason restored to the README and its row to the provider.'
            );
        }

        // And the schema row: `repeat PERIOD` lived on a style slot's `description`, so the
        // surface that carried it is gone rather than merely rewritten. Pinned on the whole
        // declaration, because a re-added slot map is the only way that reason could return.
        $this->assertSame(
            [],
            json_decode($this->doc('components/grid/schema.json'), true)['styling']['style_slots'] ?? [],
            'grid declares style slots again — a slot `description` is where the '
            . '`repeat PERIOD` reason lived, and a new slot needs its own stated reason'
        );
    }

    /**
     * A reason without a reopening condition is a closed door with no handle: it tells
     * an agent the value is deliberate but never what evidence would change it. Every
     * component README that carries a "Stated defaults" section must offer one.
     *
     * DERIVED SINCE #1101, not listed. The provider was ten hand-written component names,
     * which is the #1045 class: it went stale the moment grid's rebuild removed that
     * component's section, and a hand-copied roster cannot tell a deliberate removal from
     * a tidy-up. The provider now walks every COMPOSABLE component README there is, and
     * the test splits on what the README actually says — with the roster of ABSENTEES
     * pinned separately below, so "no section" still has to be argued for once.
     *
     * CHROME IS EXCLUDED, DERIVED FROM pp_udc_chrome_names() RATHER THAN NAMED, and the
     * exclusion is the original list's and not a new convenience: nav and footer are
     * template-owned, are not composable, declare zero style slots by contract, and their
     * two ratified literals (nav's `min-width: 12rem` dropdown floor, footer's `32ch`
     * blurb measure) are already guarded as their own rows in statedReasonProvider. The
     * "Stated defaults" SECTION contract was ratified for the band components.
     *
     * @dataProvider statedDefaultsSurfaceProvider
     */
    public function testStatedDefaultsSectionOffersAReopeningCondition(string $surface): void
    {
        $text = $this->doc($surface);
        if (!str_contains($text, 'Stated defaults')) {
            // Not a silent skip: the absentee roster is asserted, exactly and by name, in
            // testOnlyTheComponentsWithNothingToStateLackAStatedDefaultsSection() below.
            $this->assertContains(
                basename(dirname($surface)),
                self::componentsWithoutAStatedDefaultsSection(),
                "{$surface} lost its \"Stated defaults\" section."
            );
            return;
        }
        $this->assertMatchesRegularExpression(
            '/(What would reopen it|[Rr]eopening condition)/',
            $text,
            "{$surface} states defaults without offering a reopening condition. The bar for "
            . 'adding a control is a NAMED INCIDENT, so the doc has to say what incident '
            . 'would qualify — otherwise the default reads as permanent rather than as ratified.'
        );
    }

    /**
     * THE FAIL-CLOSED HALF OF THE DERIVATION. Without this, a README that quietly lost its
     * section would take the early return above and the suite would report green — the
     * vacuous-pass shape, reintroduced by the very change that made the provider honest.
     *
     * ONE, and it is an argument rather than an omission. `grid` is the #1101 rebuild:
     * four of its six stated defaults no longer ship and two acquired role addresses,
     * which is asserted in full in testTheRetiredGridStatedDefaultsAreGoneOrAuthorable()
     * above — it has nothing left to state, and a section stating nothing is worse than
     * none.
     */
    public function testOnlyTheComponentsWithNothingToStateLackAStatedDefaultsSection(): void
    {
        $missing = [];
        $scanned = 0;
        foreach (self::composableComponentReadmes() as $component => $relative) {
            $scanned++;
            if (!str_contains($this->doc($relative), 'Stated defaults')) {
                $missing[] = $component;
            }
        }
        sort($missing);

        $this->assertGreaterThanOrEqual(
            10,
            $scanned,
            'the README glob found fewer composable components than the theme ships — the '
            . 'roster below would then be a fact about a broken scan'
        );
        $this->assertSame(
            self::componentsWithoutAStatedDefaultsSection(),
            $missing,
            'the set of component READMEs with no "Stated defaults" section changed. That is '
            . 'a documentation decision, not a refactor: say here why the component has '
            . 'nothing left to state, or restore the section.'
        );
    }

    /** @return list<string> */
    private static function componentsWithoutAStatedDefaultsSection(): array
    {
        return ['grid'];
    }

    /**
     * Every composable component's README, keyed by component name.
     *
     * @return array<string, string>  component => path relative to the theme root
     */
    private static function composableComponentReadmes(): array
    {
        // Derived, not named: the chrome roster is the engine's own, so a third chrome
        // component would leave this exclusion correct without an edit here.
        $chrome = pp_udc_chrome_names();

        $out = [];
        foreach (glob(dirname(__DIR__) . '/components/*/README.md') as $file) {
            $component = basename(dirname($file));
            if (in_array($component, $chrome, true)) {
                continue;
            }
            $out[$component] = "components/{$component}/README.md";
        }
        ksort($out);
        return $out;
    }

    public static function statedDefaultsSurfaceProvider(): array
    {
        return array_map(static fn ($relative) => [$relative], self::composableComponentReadmes());
    }

    /**
     * The eyebrow anti-uniformity guidance (OQ-9). The contract requires the authoring
     * surface to say when to leave a product default alone, and before #585
     * style-component.md carried no anti-uniformity guidance of any kind.
     *
     * Pins that guidance exists and that it stays linked to the pill-geometry watch —
     * the first operator told to differentiate an eyebrow finds that colour,
     * background, border, radius and casing all move and the pill geometry does not,
     * which is the fastest-moving reopening condition in the ratified set.
     */
    public function testEyebrowGuidanceShipsWithItsPillGeometryWatch(): void
    {
        $doc = $this->doc('ai-instructions/style-component.md');

        // Pins that the guidance SECTION exists, deliberately not its wording: the line
        // itself is maintainer-reserved, so pinning the prose would make the maintainer's
        // own rewrite fail the build.
        $this->assertMatchesRegularExpression(
            '/^### Eyebrows[:,]/m',
            $doc,
            'The eyebrow anti-uniformity guidance section is gone from style-component.md. '
            . 'The eyebrow family is fully authorable on six components, so what is missing '
            . 'without it is authoring guidance, not a control.'
        );

        $this->assertStringContainsString(
            'padding: 0.35rem 0.85rem',
            $doc,
            'The eyebrow guidance no longer carries the pill-geometry watch. Guidance that '
            . 'tells an operator to differentiate an eyebrow, without saying that the pill '
            . 'geometry is the one property that will not move, sets up the exact surprise '
            . 'it exists to prevent.'
        );

        $this->assertStringContainsString(
            '#574',
            $doc,
            'The guidance no longer points at #574. The eyebrow TYPE triple is a separate '
            . 'needs-design question (the shipped --text-kicker-* family has different '
            . 'values and no consumers) and must stay referenced, not silently absorbed.'
        );
    }

    /**
     * A claim withdrawn at ratification must not reappear. The faq open animation was
     * documented as having "no prefers-reduced-motion guard"; base.css guards
     * `*, *::before, *::after` globally, so the claim was false. It is the kind of
     * assertion that gets copied forward from an old note into a new doc.
     *
     * @dataProvider authoringSurfaceProvider
     */
    public function testWithdrawnReducedMotionClaimIsNotRepeated(string $surface): void
    {
        $text = $this->doc($surface);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(no|without|missing|lacks?)\s+`?prefers-reduced-motion`?\s+guard/i',
            $text,
            "{$surface} repeats the WITHDRAWN claim that something has no "
            . 'prefers-reduced-motion guard. assets/css/base.css guards *, *::before and '
            . '*::after globally — verified at HEAD — so the claim is false.'
        );
    }

    /** The global guard the withdrawn claim denied. Pinned so the refutation stays true. */
    public function testBaseCssStillCarriesTheGlobalReducedMotionGuard(): void
    {
        $base = $this->doc('assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\*,\s*\*::before,\s*\*::after/',
            $base,
            'base.css no longer guards *, *::before and *::after under '
            . 'prefers-reduced-motion. The stated reasons for the faq open animation and '
            . 'the grid hover lift both rest on that global guard existing — if it is gone, '
            . 'those reasons need rewriting, not this assertion relaxing.'
        );
    }

    public static function authoringSurfaceProvider(): array
    {
        $surfaces = [
            'ai-instructions/style-component.md',
            'ai-instructions/composition.md',
            'AI_CONTEXT.md',
            'AI_RULES.md',
        ];
        foreach (['hero', 'section', 'cta', 'grid', 'faq', 'testimonials', 'stats', 'table', 'logos', 'embed'] as $c) {
            $surfaces[] = "components/{$c}/README.md";
        }
        return array_map(static fn ($s) => [$s], $surfaces);
    }

    /**
     * The decoration-vs-content boundary already exists, verbatim, in the same
     * sentence as the ban it qualifies. It is pinned rather than rewritten: the C-6
     * ruling explicitly withdrew the claim that this boundary was undocumented, and
     * a rule that bans decorative icon grids without carving out real content
     * imagery is the version that makes agents refuse legitimate work.
     */
    public function testDecorationBoundaryCarveOutSurvivesBesideItsBan(): void
    {
        $rules = $this->doc('AI_RULES.md');
        $this->assertStringContainsString(
            'This bans decorative filler, not real imagery',
            $rules,
            'AI_RULES.md lost the carve-out that qualifies the icon-grid ban. Without it '
            . 'the rule reads as "no icons ever", and an agent will refuse a real '
            . 'integration/partner logo grid that the image_treatment: "icon" prop exists to serve.'
        );
        $this->assertStringContainsString(
            'image_treatment',
            $rules,
            'The carve-out no longer names the prop that implements it.'
        );
    }
}
