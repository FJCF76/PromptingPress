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
        //
        // IT USED TO BE A HAND-MAINTAINED FLOOR (`>= 13`, tracking an actual 16) and that
        // was wrong in a way only a mass deletion reveals: #1101 retired the style-slot
        // engine, five suites stopped opting into the fixture, and the floor went RED FOR
        // BEING CORRECT. A number that has to be re-measured every time the truth changes
        // is a number that will eventually be re-measured wrongly — the tempting fix in
        // that moment is to lower it, which is exactly how an anti-vacuity guard dies.
        //
        // SO THE GUARD IS SELF-REFERENTIAL NOW, and it cannot drift. THIS FILE activates
        // the fixture inside a test method, so a working scan MUST find this file. If the
        // opt-in is renamed, or if the directory scan breaks, this file drops out of the
        // list with everything else and there is no count to adjust to make the symptom go
        // away. (It does NOT cover a broken method-boundary parse — membership is decided
        // by a strpos over the whole file, so a file stays listed while the per-method walk
        // silently stops matching. The old floor had exactly the same blind spot; this
        // change removes the drift, not that gap.)
        $this->assertContains(
            basename(__FILE__),
            $activators,
            'the scan did not find THIS file, which activates the fixture inside a test '
            . 'method a few lines below. Either the opt-in was renamed, this directory scan '
            . 'broke, or the method-boundary parse stopped matching — and in every one of '
            . 'those cases the pairing guard above is running over an empty list and '
            . 'proving nothing. Fix the scan; do not relax this.'
        );
    }

    /**
     * IT DECLARES NO ROLES, AND THE REASON CHANGED AT #1101 WITHOUT THE ASSERTION MOVING.
     *
     * It used to read "it stands in for a v1 component, so it must not look like a v2 one" —
     * true while the fixture hosted the slot engine. The slot engine is retired and the
     * fixture no longer stands in for anything; what it hosts now is a claim whose subject
     * the shipped registry happens not to declare (see its README).
     *
     * So the claim is no longer "stay on the old system" but "carry no styling surface at
     * all". A `roles` block would make this a UDC host, and every UDC claim has ten shipped
     * components to run against — so a role here would be coverage invented for a component
     * nobody uses, which is the one thing the fixture's README forbids.
     */
    public function testTheFixtureDeclaresNoRolesAndSoHostsNoStylingClaims(): void
    {
        FixtureTheme::activate();
        try {
            $this->assertFalse(
                pp_udc_is_v2_component(FixtureTheme::COMPONENT),
                'ppfixture must carry no styling surface: a `roles` block would make it a UDC ' .
                'host, and every UDC claim has ten shipped components to run against instead'
            );
            $this->assertSame([], pp_udc_component_roles(FixtureTheme::COMPONENT));
        } finally {
            FixtureTheme::deactivate();
        }
    }
}
