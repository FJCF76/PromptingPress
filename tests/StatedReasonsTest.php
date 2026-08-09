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
            'cta inner-gap disclosure'  => ['components/cta/schema.json', 'It does NOT govern the space BETWEEN the two buttons', 'cta'],

            // ── grid (rows 15-20 + C-6 connector) ───────────────────────────
            'grid hover lift'           => ['components/grid/README.md', 'translateY(-2px)', 'grid'],
            'grid muted featured lift'  => ['components/grid/README.md', 'translateY(-0.18rem)', 'grid'],
            'grid stripe period'        => ['components/grid/README.md', '2.75rem', 'grid'],
            'grid four-card cap'        => ['components/grid/README.md', 'max-width: 58rem', 'grid'],
            'grid steps four-card cap'  => ['components/grid/README.md', '56rem', 'grid'],
            'grid step-title cap'       => ['components/grid/README.md', 'max-width: 17rem', 'grid'],
            'grid steps connector'      => ['components/grid/README.md', '`::after` between badges', 'grid'],
            'grid texture period slot'  => ['components/grid/schema.json', 'repeat PERIOD', 'grid'],

            // ── testimonials (rows 21-26) ───────────────────────────────────
            'testimonials avatar size'  => ['components/testimonials/README.md', '2.75rem', 'testimonials'],
            'testimonials avatar shape' => ['components/testimonials/README.md', 'border-radius: 50%', 'testimonials'],
            'testimonials avatar crop'  => ['components/testimonials/README.md', 'object-fit: cover', 'testimonials'],
            'testimonials stack type'   => ['components/testimonials/README.md', '1.375rem', 'testimonials'],
            'testimonials stack measure'=> ['components/testimonials/README.md', 'max-width: 42rem', 'testimonials'],
            'testimonials quote mark'   => ['components/testimonials/schema.json', 'GLYPH and its SIZE are stated defaults', 'testimonials'],

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
     * A reason without a reopening condition is a closed door with no handle: it tells
     * an agent the value is deliberate but never what evidence would change it. Every
     * component README that carries a "Stated defaults" section must offer one.
     *
     * @dataProvider statedDefaultsSurfaceProvider
     */
    public function testStatedDefaultsSectionOffersAReopeningCondition(string $surface): void
    {
        $text = $this->doc($surface);
        $this->assertStringContainsString(
            'Stated defaults',
            $text,
            "{$surface} lost its \"Stated defaults\" section."
        );
        $this->assertMatchesRegularExpression(
            '/(What would reopen it|[Rr]eopening condition)/',
            $text,
            "{$surface} states defaults without offering a reopening condition. The bar for "
            . 'adding a control is a NAMED INCIDENT, so the doc has to say what incident '
            . 'would qualify — otherwise the default reads as permanent rather than as ratified.'
        );
    }

    public static function statedDefaultsSurfaceProvider(): array
    {
        return array_map(
            static fn ($c) => ["components/{$c}/README.md"],
            ['hero', 'section', 'cta', 'grid', 'testimonials', 'faq', 'stats', 'table', 'logos', 'embed']
        );
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
