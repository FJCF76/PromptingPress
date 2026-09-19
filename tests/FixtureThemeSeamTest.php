<?php
/**
 * THE #1025 FIXTURE SEAM, AND THE INVISIBILITY THAT MAKES IT SAFE.
 *
 * `ppfixture` exists so the SLOT-ENGINE suites stop being re-homed to a different real
 * component at every v2 rebuild (hero -> section at #986, section -> stats at #1023,
 * and stats -> ? was due here at #1066 PR2). The full reasoning, and the date this
 * fixture is deleted, are in tests/fixtures/components/ppfixture/README.md.
 *
 * WHAT THIS FILE GUARDS is the one thing that could make the seam worse than the
 * treadmill it replaces: a fixture component leaking into a registry read that is meant
 * to see only shipped components. The theme has many registry-ITERATING tests - "every
 * component declares a required prop", "every component's slots are documented in its
 * README", the AI-facing catalog, the CLI schema command - and every one of them would
 * silently start making assertions about a component that does not ship. The failure
 * would not look like a leak; it would look like those suites getting stricter.
 *
 * So the invisibility is pinned from BOTH sides, and neither side is sufficient alone:
 *  - a read with no opt-in must not contain it (the production shape), and
 *  - a read after deactivate() must not contain it either (the leak-between-suites shape,
 *    which is the one that actually bites, because PHPUnit runs every class in one
 *    process and a suite that forgot its tearDown would poison every later class).
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;
use PromptingPress\Tests\Support\FixtureTheme;

class FixtureThemeSeamTest extends TestCase
{
    protected function tearDown(): void
    {
        // Belt and braces: if an assertion below fails mid-test, the root must still be
        // restored or every later CLASS in the process inherits the fixture root.
        unset($GLOBALS['_pp_test_template_dir']);
        FixtureTheme::invalidate();
        parent::tearDown();
    }

    /** The production shape: nobody opted in, so the fixture does not exist. */
    public function testTheFixtureIsInvisibleWithoutTheOptIn(): void
    {
        $components = pp_get_registered_components();

        $this->assertArrayNotHasKey(
            FixtureTheme::COMPONENT,
            $components,
            'ppfixture must not appear in a registry read that did not opt in — every ' .
            'registry-iterating test in the theme would start asserting about a component ' .
            'that does not ship'
        );
        // Not a vacuous pass on an empty registry: the real components must be there.
        $this->assertArrayHasKey('grid', $components, 'the un-opted-in registry must still be the real one');
        $this->assertGreaterThanOrEqual(10, count($components));
    }

    /** The opt-in shape: the fixture appears, and does not displace anything real. */
    public function testOptingInAddsTheFixtureAndKeepsEveryRealComponent(): void
    {
        $before = pp_get_registered_components();
        FixtureTheme::activate();
        try {
            $after = pp_get_registered_components();
        } finally {
            FixtureTheme::deactivate();
        }

        $this->assertArrayHasKey(FixtureTheme::COMPONENT, $after);
        $this->assertSame(
            count($before) + 1,
            count($after),
            'the fixture root must be the real components PLUS one — a root holding only ' .
            'the fixture would make the cross-component error-context assertions pass for ' .
            'the wrong reason'
        );
        foreach (array_keys($before) as $real) {
            $this->assertArrayHasKey($real, $after, "opting in dropped the real component {$real}");
        }
    }

    /** The leak shape, which is the one that actually bites in a single-process run. */
    public function testDeactivateRestoresTheRealRegistry(): void
    {
        FixtureTheme::activate();
        try {
            $this->assertArrayHasKey(FixtureTheme::COMPONENT, pp_get_registered_components());
        } finally {
            FixtureTheme::deactivate();
        }
        $this->assertArrayNotHasKey(
            FixtureTheme::COMPONENT,
            pp_get_registered_components(),
            'deactivate() must restore the real root — PHPUnit runs every class in one ' .
            'process, so a fixture left active poisons every later class'
        );
    }

    /** Nesting must restore the PREVIOUS root, not merely unset the global. */
    public function testActivateNestsRatherThanClobbering(): void
    {
        $GLOBALS['_pp_test_template_dir'] = '/some/other/root';
        FixtureTheme::activate();
        try {
            // nothing to assert mid-flight: the claim is about what deactivate() restores.
            $this->assertSame(FixtureTheme::root(), $GLOBALS['_pp_test_template_dir']);
        } finally {
            FixtureTheme::deactivate();
        }
        $this->assertSame(
            '/some/other/root',
            $GLOBALS['_pp_test_template_dir'],
            'deactivate() must restore the root that was in force, not unset it — a suite ' .
            'that already swapped the root (PreflightTest does) would lose its own fixture'
        );
        unset($GLOBALS['_pp_test_template_dir']);
    }

    /** The fixture has to carry the SHAPES the slot-engine suites exercise. */
    public function testTheFixtureCarriesARepresentativeSlotSet(): void
    {
        FixtureTheme::activate();
        try {
            $slots = pp_get_style_slots(FixtureTheme::COMPONENT);

            $this->assertGreaterThanOrEqual(
                8,
                count($slots),
                'the fixture stands in for a slot-bearing v1 component; too few slots and the ' .
                'suites it hosts stop exercising the shapes they used to'
            );

            // A CONDITIONAL slot, which is the shape the inert-slot advisory needs.
            $conditional = array_filter($slots, static fn($s) => !empty($s['applies_when']));
            $this->assertGreaterThanOrEqual(
                3,
                count($conditional),
                'at least three slots must carry applies_when, or the conditional-slot and ' .
                'inert_slot suites have no real condition to exercise'
            );

            // The two types with special rejection paths.
            $types = array_column($slots, 'type');
            $this->assertContains('gradient', $types, 'the gradient type has rejection messages the suites assert on');
            $this->assertContains('length-or-none', $types, 'the only type with a keyword alternative');

            // And it must be a REAL registry citizen: composable, with a required prop.
            $schema = pp_get_registered_components()[FixtureTheme::COMPONENT];
            $required = array_filter($schema['props'] ?? [], static fn($p) => !empty($p['required']));
            $this->assertNotEmpty(
                $required,
                'every composable component declares a required prop (SchemaValidationTest ' .
                'pins that invariant) — a fixture that broke it would fail the very suites ' .
                'it is meant to host'
            );
        } finally {
            FixtureTheme::deactivate();
        }
    }

    /**
     * SOURCE TRIPWIRE: every suite that activates the fixture must also deactivate it.
     *
     * THIS CAUGHT A REAL LEAK THE HOUR IT WAS WRITTEN, which is why it exists as a
     * tripwire rather than as advice in the README. Three suites were re-homed onto the
     * fixture by a script that inserted `activate()` into `setUp()` and `deactivate()`
     * into `tearDown()` — and those three had NO tearDown at all, so the insertion found
     * nothing and silently did half the job. The fixture root then stayed in force for
     * every later class in the process.
     *
     * THE SYMPTOM DID NOT LOOK LIKE A LEAK, which is the whole argument for pinning it:
     * UdcEngineTest, UdcPresetStateMotionTest and UdcTruthSpineTest started failing, and
     * they read like the UDC engine breaking rather than like a fixture bleeding in. What
     * actually happened is that a registry-iterating suite met a component with no
     * `roles` block. Total assertions dropped by ~4,900 and 30 extra tests failed.
     *
     * Asserted on the SOURCE rather than on behaviour because behaviour cannot see it:
     * a leak only manifests in whatever class PHPUnit happens to run next, so the
     * behavioural version of this test would be order-dependent and would pass whenever
     * the leaking suite ran last.
     */
    public function testEverySuiteThatActivatesTheFixtureAlsoDeactivatesIt(): void
    {
        // RECURSIVE SINCE THE #1066 PR2 REVIEW. A flat scandir() of tests/ skipped
        // tests/Support/ — which is exactly where a shared base class would live, and a
        // base class activating for its subclasses is the highest-leverage place for this
        // leak to appear. Nothing there activates today; the point is that it would be
        // unguarded if it did, and a floor of five activators would not have noticed.
        // FixtureTheme.php itself is excluded: it DEFINES activate(), so every scan would
        // report the helper as an unpaired caller of its own method.
        $dir        = __DIR__;
        $activators = [];
        $files      = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || basename($path) === 'FixtureTheme.php') {
                continue;
            }
            $entry = ltrim(str_replace($dir, '', $path), '/');
            $src   = file_get_contents($path);
            if (strpos($src, 'FixtureTheme::activate()') === false) {
                continue;
            }
            $activators[] = $entry;
            $this->assertStringContainsString(
                'FixtureTheme::deactivate()',
                $src,
                "{$entry} activates the fixture theme root but never deactivates it. PHPUnit " .
                'runs every class in one process, so the root stays in force for every LATER ' .
                'class — which surfaces as the UDC suites failing, not as a leak. Add a ' .
                'tearDown() that calls FixtureTheme::deactivate().'
            );
        }

        // AND THE PAIRING MUST BE EXCEPTION-SAFE, not merely present. A suite that calls
        // activate() inside a TEST METHOD and deactivate() at the end of it leaks the
        // fixture root whenever an assertion in between fails — PHPUnit aborts the method,
        // the trailing call never runs, and every later class inherits the root. That is
        // the same leak as a missing tearDown, reached by a different door, and it is
        // invisible on a green run: it only bites once something else is already failing,
        // which is exactly when a confusing second failure is most expensive.
        //
        // Surfaced by the outside review pass on #1066 PR2, which predicted it from the
        // helper's shape before it had caused anything. One real instance existed
        // (StoredStyleAndItemsRenderGuardTest's merged-attribute control, in a file with no
        // tearDown at all) and is now wrapped.
        //
        // setUp() is exempt: tearDown() runs even when a test fails, so the ordinary
        // setUp/tearDown pairing is already safe.
        foreach ($activators as $entry) {
            $src   = file_get_contents($dir . '/' . $entry);
            $lines = explode("\n", $src);
            $fn    = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/function (\w+)\s*\(/', $line, $m)) {
                    $fn = $m[1];
                }
                // THE ANCHOR IS A SUFFIX TEST, and it has been wrong twice — both times
                // in the direction that makes this guard assert on NOTHING, which is the
                // failure mode a tripwire can least afford.
                //
                // First it was a substring match, which also hit this guard's OWN search
                // expression a few lines up: the scanner reported itself. Then it was
                // `trim($line) !== 'FixtureTheme::activate();'`, exact-match — which
                // skipped any call not alone on its line and, worse, skipped a
                // fully-qualified `\PromptingPress\Tests\Support\FixtureTheme::activate();`
                // that the file-level check above still counts, so such a file became an
                // "activator" silently exempt from this half. The fix after that was a
                // regex, and the regex was MANGLED by PHP's single-quote escaping into
                // `[^\w\]` — an unterminated character class that made preg_match() return
                // false for every line, so the loop below ran zero times and the whole
                // guard passed on an empty set. Two planted defects went undetected before
                // the assertion count gave it away.
                //
                // A suffix test needs no escaping, covers the qualified form for free, and
                // cannot match this comment or the line below it.
                $stripped = trim(preg_replace('#//.*$#', '', $line));
                if (!str_ends_with($stripped, 'FixtureTheme::activate();')) {
                    continue;
                }
                if ($fn === 'setUp') {
                    continue;
                }

                // TWO CONDITIONS, AND THE FIRST ONE IS THE ONE THE SECOND-PASS REVIEW
                // ADDED. Asking only "is there a finally somewhere below" passed on the
                // exact leak this guard exists for:
                //
                //     FixtureTheme::activate();
                //     $this->assertTrue(…);          // <- UNPROTECTED. If it fails, the
                //     try { … } finally { … }        //    finally below never runs.
                //
                // So the `try {` must OPEN IMMEDIATELY, on the next statement. Anything
                // between activate() and the try is outside the protected region, which is
                // the whole defect.
                $next = '';
                for ($k = $i + 1; $k < count($lines); $k++) {
                    $candidate = trim(preg_replace('#//.*$#', '', $lines[$k]) ?? '');
                    if ($candidate === '') {
                        continue;
                    }
                    $next = $candidate;
                    break;
                }
                $this->assertSame(
                    'try {',
                    $next,
                    "{$entry}::{$fn}() runs `{$next}` between FixtureTheme::activate() and its "
                    . '`try {`. Anything there is outside the protected region: if it throws '
                    . 'or fails, the `finally` never runs and the fixture root leaks into '
                    . 'every later class. Open the try immediately after activate().'
                );

                // AND THE CLOSER MUST BE `finally`, NOT `catch`. The first version of this
                // accepted `try { … } catch (\Throwable $e) { … } deactivate();`, which is
                // WORSE than the bug it guards: a bare catch also swallows PHPUnit's own
                // ExpectationFailedException, so the test reports green while the assertion
                // inside it never held. Only `finally` runs on both paths without changing
                // what a failure means.
                //
                // THE METHOD BOUNDARY IS `function `, NOT `public function ` — the review
                // defeated the narrower form with a `private function` helper further down
                // the file carrying a decoy `finally { deactivate(); }`, which the search
                // then found on behalf of an unprotected test method above it.
                $rest = implode("\n", array_slice($lines, $i + 1, 120));
                $body = preg_split('/\n\s*(?:public |private |protected |static )*function /', $rest)[0];
                $this->assertMatchesRegularExpression(
                    '/\bfinally\s*\{[^}]*FixtureTheme::deactivate\(\);/s',
                    $body,
                    "{$entry}::{$fn}() activates the fixture inside a test method without a " .
                    '`finally { FixtureTheme::deactivate(); }` of its own. A failing assertion ' .
                    'would skip a trailing deactivate() and leak the fixture root into every ' .
                    'later class. A `catch` is not a substitute — it would also swallow the ' .
                    'assertion failure. Wrap the body: activate(); try { … } finally { ' .
                    'deactivate(); }'
                );
            }
        }

        // Fail-closed: if the scan stops finding activators, the loop above passes on
        // nothing and this guard silently retires.
        // THE FLOOR TRACKS THE REAL COUNT, at roughly the one-fifth headroom this PR's
        // emit tests use. It was 5 against an actual 16 — so eleven suites could have
        // stopped opting in, or the scan could have lost two thirds of its reach, with
        // this guard still green. That is the same understated-floor defect the review
        // found in the emit tests, in the file that fixed them.
        $this->assertGreaterThanOrEqual(
            13,
            count($activators),
            'the scan found almost no suites opting into the fixture — either the opt-in was ' .
            'renamed or this directory scan broke, and either way the pairing is unguarded'
        );
    }

    /** It stands in for a v1 component, so it must NOT look like a v2 one. */
    public function testTheFixtureIsAv1ComponentAndDeclaresNoRoles(): void
    {
        FixtureTheme::activate();
        try {
            $this->assertFalse(
                pp_udc_is_v2_component(FixtureTheme::COMPONENT),
                'ppfixture hosts the SLOT engine, so it must stay on slots — a fixture with ' .
                'roles would be testing the system that replaced the one under test'
            );
            $this->assertSame([], pp_udc_component_roles(FixtureTheme::COMPONENT));
        } finally {
            FixtureTheme::deactivate();
        }
    }
}
