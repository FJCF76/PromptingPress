<?php
/**
 * tests/UdcLayoutGroupTest.php
 *
 * THE LAYOUT GROUP, as executable evidence (#1084, docs/v2/LAYOUT-GROUP-CONTRACT.md).
 *
 * The group is an OVERLAY: five layout properties stay in the stylesheet, where
 * ~35 of their shipped declarations are variant- or tier-scoped mechanism no role
 * address can express, and the group emits AUTHORED values above them. That shape
 * makes three claims this file has to keep honest, because nothing else can:
 *
 *   1. NO ROLE DECLARES A LAYOUT DEFAULT. A default emits unlayered, so it would
 *      not replace `.cta--inline .cta__inner { flex-direction: row }` — it would
 *      OUTRANK it, and the variant would die silently on every page carrying it.
 *      The test is derived from the registry, so a sixth layout parameter added
 *      tomorrow is covered without anyone remembering this file exists.
 *   2. AN AUTHORED COLUMN COUNT IS HONEST AT EVERY TIER. `grid-template-columns`
 *      does nothing to a flex container, and `.section__grid` is flex below 768px,
 *      so the engine emits `display: grid` beside it. Without that the value
 *      validates, stores and paints nothing on phones — and PHP cannot read the
 *      stylesheet, so not even the drop ledger could say so (I19/I35).
 *   3. THE COUNT SURVIVES MINTING. A responsive value is rewritten into band
 *      tokens at write, so the synthesis has to decide from the author's LITERAL
 *      while emitting the reference. Deciding from the CSS text would see
 *      `var(--pp-…)`, skip the synthesis, and ship a bare custom property as a
 *      track list — on exactly the maps most likely to be responsive.
 *
 * TIERS ARE READ FROM THE ENGINE, never hardcoded (#1052): the three shipped
 * RoleDefaultsEmit harnesses each hardcode the media text, so a moved breakpoint
 * asserts the wrong tier and still passes. This file asks pp_udc_breakpoints().
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;

class UdcLayoutGroupTest extends TestCase
{
    /** A section band, the component whose own CSS switches display across tiers. */
    private function band(array $udc, string $id = 'pp-1a2b3c4d'): array
    {
        return [
            'component' => 'section',
            'id'        => $id,
            'props'     => ['title' => 'Tracks', 'body' => 'Copy.'],
            'udc'       => $udc,
        ];
    }

    /** The emitted CSS for one authored band. */
    private function css(array $udc): string
    {
        $css = pp_udc_band_css($this->band($udc));
        $this->assertNotSame('', $css, 'the band emitted nothing at all');
        return $css;
    }

    /** The slice of an emitted block for one breakpoint key, media text from the ENGINE. */
    private function tier(string $css, string $bp): string
    {
        $breakpoints = pp_udc_breakpoints();
        $this->assertArrayHasKey($bp, $breakpoints, 'unknown breakpoint key in the test itself');
        $media = $breakpoints[$bp]['media'];
        if ($media === null) {
            $at = strpos($css, '@media');
            return $at === false ? $css : substr($css, 0, $at);
        }
        $quoted = preg_quote('@media ' . $media . '{', '/');
        if (!preg_match('/' . $quoted . '(.*?)\}\s*(?:@media|$)/s', $css, $m)) {
            $this->fail(sprintf('no %s tier emitted; css was: %s', $bp, $css));
        }
        return $m[1];
    }

    // ── 1. The overlay's non-negotiable: no defaults ─────────────────────────

    /**
     * REGISTRY-DERIVED, and non-vacuous by its own assertions: it proves it found
     * the layout parameters, found real components, and found real defaults of
     * OTHER groups — so a walk that silently stopped matching cannot pass.
     */
    public function testNoRoleAnywhereDeclaresALayoutDefault(): void
    {
        $groups           = pp_udc_groups();
        $layoutParams     = array_keys($groups['layout']['params']);
        $layoutProperties = array_column($groups['layout']['params'], 'property');
        $this->assertNotEmpty($layoutParams, 'the registry has no layout group to check');
        $this->assertContains('grid-template-columns', $layoutProperties, 'the property list is not the one this test thinks it is');

        $componentsSeen = 0;
        $rolesExposing  = 0;
        $otherDefaults  = 0;

        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            if (!is_array($schema) || empty($schema['roles'])) {
                continue;
            }
            $componentsSeen++;
            foreach ($schema['roles'] as $role => $definition) {
                if (in_array('layout', $definition['groups'] ?? [], true)) {
                    $rolesExposing++;
                }
                $defaults = $definition['defaults'] ?? [];
                $otherDefaults += count($defaults);
                $this->assertArrayNotHasKey('layout', $defaults, sprintf(
                    '%s role "%s" declares a layout default. A role default emits UNLAYERED, so it '
                    . 'would outrank the variant-scoped structural rule it appears to replace '
                    . '(.cta--inline, .hero--split, .section--text-panel) and kill that layout on '
                    . 'every band using it. The Layout group is authored-only by ruling.',
                    basename(dirname($file)),
                    $role
                ));
                // The same rule one level down, and it is keyed on the emitted
                // PROPERTY rather than the parameter name — because names are
                // group-scoped and two groups legitimately share one. `typography
                // .align` emits `text-align` and has defaulted on `logos.label`
                // since that rebuild; `layout.align` emits `align-items`. A
                // name-keyed check calls the first one a smuggled layout default,
                // which is how this guard was found to be asking the wrong
                // question. What must never appear in a default is a
                // layout PROPERTY, whatever group carries it.
                foreach ($defaults as $group => $values) {
                    if (!is_array($values) || !isset($groups[$group]['params'])) {
                        continue;
                    }
                    foreach (array_keys($values) as $param) {
                        $property = $groups[$group]['params'][$param]['property'] ?? null;
                        $this->assertNotContains($property, $layoutProperties, sprintf(
                            '%s role "%s" defaults "%s.%s", which emits the layout property "%s"',
                            basename(dirname($file)),
                            $role,
                            $group,
                            $param,
                            (string) $property
                        ));
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(9, $componentsSeen, 'the schema walk found almost no components — it is asserting on an empty set');
        $this->assertGreaterThan(20, $rolesExposing, 'almost no role exposes layout — the roster never landed, so this test proves nothing');
        $this->assertGreaterThan(50, $otherDefaults, 'no role defaults were read at all, so the absence above is not evidence');
    }

    // ── 2. Exposure is declared, and the write gate honours it ───────────────

    public function testAContainerRoleAcceptsLayoutAndANonContainerRoleRefusesItByName(): void
    {
        $this->assertNull(
            pp_udc_validate_map(['columns' => ['layout' => ['columns' => 3]]], 'section'),
            'section\'s `columns` role is the two-column track; it must accept the group'
        );

        $error = pp_udc_validate_map(['heading' => ['layout' => ['justify' => 'center']]], 'section');
        $this->assertInstanceOf(WP_Error::class, $error, 'a heading arranges no children; the group must be refused there');
        $this->assertStringContainsString('layout', $error->get_error_message());
    }

    /**
     * `align-self` is a SIZING parameter, and this is the test that says why: it
     * has to reach a role whose box is a CHILD of a container and not a container
     * itself. `section.panel` is exactly that, and it is #658's subject.
     */
    public function testAlignSelfIsASizingParameterSoItReachesAChildRole(): void
    {
        $this->assertArrayHasKey('align-self', pp_udc_groups()['sizing']['params']);
        $this->assertArrayNotHasKey('align-self', pp_udc_groups()['layout']['params']);

        $this->assertNull(
            pp_udc_validate_map(['panel' => ['sizing' => ['align-self' => 'center']]], 'section'),
            '#658: the panel column must be able to place itself in the row its parent lays out'
        );
        $css = $this->css(['panel' => ['sizing' => ['align-self' => 'center']]]);
        $this->assertStringContainsString('.section__panel', $css);
        $this->assertStringContainsString('align-self:center', $css);
    }

    // ── 3. columns: the synthesis and the companion ──────────────────────────

    public function testAColumnCountBecomesATrackListWithTheOverflowSafeMinimum(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => 4]]]);
        $this->assertStringContainsString('grid-template-columns:repeat(4, minmax(0, 1fr))', $css);
        // `minmax(0, …)` rather than a bare 1fr: a 1fr track has an `auto` minimum,
        // so one unbroken token widens the track and scrolls the page (#1043/#1067).
        $this->assertStringNotContainsString('repeat(4, 1fr)', $css);
    }

    public function testAnAuthoredTrackListIsEmittedAsWritten(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => 'repeat(auto-fit, minmax(20rem, 1fr))']]]);
        $this->assertStringContainsString('grid-template-columns:repeat(auto-fit, minmax(20rem, 1fr))', $css);
        // No second repeat() wrapped around an explicit list.
        $this->assertSame(1, substr_count($css, 'repeat('), 'an explicit track list must not be re-wrapped');
    }

    public function testTheCompanionMakesAnAuthoredCountHonestOnABoxTheStylesheetLaysOutWithFlex(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => 3]]]);
        $this->assertStringContainsString('display:grid', $css,
            'without the companion an authored columns value paints nothing below 768px, where '
            . '.section__grid is display:flex — and no channel could report it');
    }

    public function testTheCompanionYieldsToAnAuthoredDisplayWhateverWroteIt(): void
    {
        $css = $this->css(['columns' => [
            'layout' => ['columns' => 3],
            '_css'   => ['display' => 'inline-grid'],
        ]]);
        $this->assertStringContainsString('display:inline-grid', $css);
        $this->assertStringNotContainsString('display:grid;', $css);
    }

    public function testTheCompanionRidesOnlyTheBucketThatCarriesTheColumns(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => ['p' => 1]]]]);
        $this->assertStringContainsString('display:grid', $this->tier($css, 'p'));
        $this->assertStringNotContainsString('display:grid', $this->tier($css, 'd'));
    }

    /**
     * THE MINTING CASE — the one a synthesis written against the CSS text would
     * fail. A responsive value is stored as band-token references, so the emitted
     * track list is `repeat(var(--pp-…), minmax(0, 1fr))`. Chromium resolves that
     * (verified at 375/768/1280 before this was built, rule 14.3), and the
     * per-tier values differ.
     */
    public function testAResponsiveCountSurvivesMintingAndStillSynthesises(): void
    {
        $band       = $this->band(['columns' => ['layout' => ['columns' => ['d' => 3, 'p' => 1]]]]);
        $normalized = pp_udc_normalize_band($band);

        $stored = $normalized['udc']['columns']['layout']['columns'];
        $this->assertSame('@columns-layout-columns-d', $stored['d'], 'the responsive value must mint');
        $this->assertSame('3', (string) $normalized['udc']['_tokens']['columns-layout-columns-d']);

        $css = pp_udc_band_css($normalized);
        foreach (['d', 'p'] as $bp) {
            $this->assertMatchesRegularExpression(
                '/grid-template-columns:repeat\(var\(--pp-columns-layout-columns-' . $bp . '\), minmax\(0, 1fr\)\)/',
                $this->tier($css, $bp),
                sprintf('the %s tier must synthesise around the minted reference, not around its text', $bp)
            );
        }
    }

    // ── 4. The Layer-2 interaction ───────────────────────────────────────────

    /**
     * Claiming a property types every `_css` write of it. The grammar is therefore
     * wide enough that a value the browser accepts still validates: a refusal here
     * would not fail its own edit alone — update_composition validates the WHOLE
     * composition, so one stored value would lock an unrelated band (#1007).
     */
    public function testAClaimedPropertyIsTypedThroughRawCssWithoutNarrowingWhatCssAccepts(): void
    {
        $this->assertNull(
            pp_udc_validate_map(['columns' => ['_css' => ['align-items' => 'safe center']]], 'section'),
            'safe center is valid CSS and _css accepted it before the registry claimed the property'
        );
        $this->assertNull(
            pp_udc_validate_map(['columns' => ['_css' => ['grid-template-columns' => 'repeat(auto-fit, minmax(20rem, 1fr))']]], 'section'),
            '#905\'s own value must keep working through the raw path it works through today'
        );

        $error = pp_udc_validate_map(['columns' => ['_css' => ['align-items' => 'centre']]], 'section');
        $this->assertInstanceOf(WP_Error::class, $error, 'a claimed property is typed now, so a misspelling is caught at write');
    }

    // ── 5. Scope ─────────────────────────────────────────────────────────────

    public function testALayoutValueEmitsOnItsOwnRoleSelectorAndScopesToTheBand(): void
    {
        $css = $this->css(['inline-items' => ['layout' => ['justify' => 'center', 'wrap' => 'wrap-reverse']]]);
        $this->assertStringContainsString('[data-pp-band="pp-1a2b3c4d"] .section__inline-items', $css);
        $this->assertStringContainsString('justify-content:center', $css);
        $this->assertStringContainsString('flex-wrap:wrap-reverse', $css);
        $this->assertStringNotContainsString('.section__grid', $css, 'a value must not leak to a sibling role');
    }
}
