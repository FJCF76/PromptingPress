<?php
/**
 * tests/EscapingStubContractTest.php
 *
 * #709 — the harness must not be STRICTER than production.
 *
 * WHY THIS FILE EXISTS. tests/bootstrap.php stubs esc_html/esc_attr/esc_url/wp_kses_post.
 * Until #709 all four declared a TYPED `string` first parameter; WordPress core declares
 * all four UNTYPED, and the theme runs COERCIVE (no declare(strict_types) in components/,
 * lib/, templates/ or functions.php). The stubs therefore raised a TypeError on shapes a
 * real site renders happily. That is the DANGEROUS direction of harness drift: it does not
 * hide defects, it MANUFACTURES them. A probe asking "which stored prop shapes fatal the
 * public page?" — the exact question #705/#706/#708 are built on — read those TypeErrors
 * as production 500s, and a render guard written to satisfy one is a guard against nothing.
 *
 * WHAT IS PINNED HERE. Not the stubs' sanitization (deliberately simplified — see below),
 * but their TYPE CONTRACT: for every input shape, does core coerce it or fatal on it, and
 * with which Throwable. That is core's OBSERVABLE coercion/fatal boundary on this theme's
 * supported runtime (readme.txt: WordPress 7.0+, PHP 8.0+; CI runs phpunit on PHP 8.3, and
 * every construct involved dates to PHP 8.0) — not a claim about every WP or PHP version.
 * The matrix below was measured against real WordPress 7.0 on PHP 8.3.31 in the wp-env
 * container the E2E suite runs, ONE FRESH PROCESS PER CASE:
 *
 *   input                 esc_html / esc_attr     esc_url               wp_kses_post
 *   --------------------  ----------------------  --------------------  --------------------
 *   string/int/float/bool coerced, returned       coerced, returned     coerced, returned
 *   null                  ''                      '' (+deprecation)     '' (+deprecation)
 *   array (any)           'Array' (+warning)      FATAL TypeError       FATAL TypeError
 *   object, no __toString FATAL Error             FATAL TypeError       FATAL TypeError
 *   object w/ __toString  coerced                 coerced               coerced
 *
 * The null-row deprecations are PHP 8.1+; on the PHP 8.0 floor the same calls are silent,
 * which is why they are documented here but deliberately not asserted.
 *
 * ONE FRESH PROCESS PER CASE IS LOAD-BEARING. A single-process sweep mis-reports
 * wp_kses_post's array row as a clean pass-through. The mechanism is a filter that
 * de-registers itself and never comes back (wp-includes/formatting.php:5220):
 *
 *   function wp_pre_kses_block_attributes( $content, $allowed_html, $allowed_protocols ) {
 *       remove_filter( 'pre_kses', 'wp_pre_kses_block_attributes', 10 );   // :5225
 *       $content = filter_block_content( ... );                            // :5226  THROWS
 *       add_filter( 'pre_kses', 'wp_pre_kses_block_attributes', 10, 3 );   // :5227  never runs
 *
 * filter_block_content() is where str_contains() rejects the array. The re-add on the
 * next line is skipped by the unwind, so for the REST of that PHP request the pre_kses
 * callback is simply gone and every later wp_kses_post() call returns its argument
 * untouched. Measure a fatal-bearing matrix in one process and everything after the
 * first throw is a false negative. (It is not WP_Hook nesting state: apply_filters()
 * re-seeds its iteration state on every entry and recovers cleanly from an unwind.)
 *
 * A CONSEQUENCE THE #705/#706/#708 FAMILY MUST NOT MISS: this stub is STATELESS, so it
 * fatals on every array. Core fatals ONCE per request and then silently degrades — with
 * block-attribute KSES removed. So catching a wp_kses_post() TypeError and continuing is
 * NOT a safe degrade in production: it leaves that sanitization filter de-registered for
 * every later wp_kses_post() call in the same request. Guard BEFORE the call (the D-B
 * idiom), never try/catch around it.
 *
 * THE ISSUE'S OWN RATIONALE WAS HALF WRONG, AND THE CORRECTION MATTERS DOWNSTREAM.
 * #709 states that only the theme's OWN typed helpers and builtins like count() are
 * genuine production fatals. The measurement above refutes that for two of the four:
 * esc_url() reaches ltrim() and wp_kses_post() reaches preg_replace()/str_contains()
 * before any sanitization decision, and those internal functions reject arrays and
 * objects in coercive mode exactly as they would on a real site. So a naive "just cast
 * everything to string" widening would have swapped a false-fatal generator for a
 * false-SAFETY generator — strictly worse, because it would hide two live defect classes.
 * The stubs reproduce each column with the SAME PHP construct core reaches, so the
 * Throwable class and message a probe sees are the ones a visitor's 500 would carry.
 *
 * WHAT IS DELIBERATELY NOT MODELLED. The stubs are type-faithful, not byte-faithful.
 * esc_url() does not reproduce core's full character-stripping and wp_kses_post() does not
 * normalize entities or apply the allowlist; those simplifications predate #709 and are
 * unchanged by it. The allowlist SHAPE is modelled by the wp_kses() stub, and the real
 * security boundary is pinned in the E2E suite that renders on a real WordPress.
 *
 * NO declare(strict_types) IN THIS FILE, ON PURPOSE. It models the production call site,
 * which is coercive. With an untyped parameter the caller's strictness no longer changes
 * the outcome at all — which is itself part of the fix, and is why the reflection test
 * below guards the untypedness directly rather than trusting a behavioural sample.
 */

use PHPUnit\Framework\TestCase;

/**
 * An object that CAN become a string — core coerces it through every one of the four.
 *
 * Guarded with class_exists() to match the repo convention for global test helpers
 * (tests/CliGateTest.php:39, tests/ReadinessFindingsTest.php:29): PHPUnit loads all of
 * tests/ into one process, so an unguarded declaration turns any future double-include
 * into a collection-time fatal that takes down the whole suite, not one test.
 */
if (!class_exists('PPEscapingStubStringable')) {
    class PPEscapingStubStringable
    {
        public function __toString(): string
        {
            return 'obj-to-string';
        }
    }
}

class EscapingStubContractTest extends TestCase
{
    /** The four stubs #709 widened. */
    private const STUBS = ['esc_html', 'esc_attr', 'esc_url', 'wp_kses_post'];

    /**
     * Run $fn($value) with PHP diagnostics captured instead of escalating.
     *
     * PHPUnit's error handler would turn core's legitimate "Array to string conversion"
     * warning into a suite-level warning, so a test that ASSERTS the warning would also
     * pollute the run's counters. Capture and restore around the single call.
     *
     * The handler swallows EVERY diagnostic, not just that warning, and $diagnostic keeps
     * only the LAST one. That is why the null row's PHP 8.1+ deprecation is documented in
     * the matrix but not asserted: it is version-conditional (silent on the PHP 8.0 floor
     * this theme supports), so pinning it would fail the supported range rather than
     * defend the contract.
     *
     * @return array{0: mixed, 1: ?Throwable, 2: string} [return value, throwable, diagnostic]
     */
    private function callStub(string $fn, $value): array
    {
        $diagnostic = '';
        set_error_handler(static function (int $errno, string $errstr) use (&$diagnostic): bool {
            $diagnostic = $errstr;
            return true; // handled; do not reach PHPUnit's handler
        });

        try {
            $returned = $fn($value);
            return [$returned, null, $diagnostic];
        } catch (\Throwable $e) {
            return [null, $e, $diagnostic];
        } finally {
            restore_error_handler();
        }
    }

    // ── The regression guard proper ──────────────────────────────────────────

    /**
     * THE defect #709 fixes, guarded structurally rather than behaviourally.
     *
     * Re-adding `string` to any of these four parameters re-arms the false-fatal
     * generator, and it would do so INVISIBLY for scalars: a typed parameter still
     * coerces an int when the caller is coercive, so a behavioural sample over scalar
     * inputs stays green. Reflection is the only assertion that cannot be fooled.
     */
    public function testAllFourStubsDeclareAnUntypedFirstParameter(): void
    {
        foreach (self::STUBS as $fn) {
            $param = (new \ReflectionFunction($fn))->getParameters()[0];
            $this->assertFalse(
                $param->hasType(),
                "{$fn}() must declare its first parameter UNTYPED, as WordPress core does. "
                . 'A typed parameter makes the harness stricter than production and turns '
                . 'stored-shape render probes into false-fatal reports (#709).'
            );
        }
    }

    /**
     * wp_kses_post()'s OMITTED return type is deliberate and documented in
     * tests/bootstrap.php, so it needs the same structural defence as the parameter.
     * preg_replace() can return null; a declared `: string` would convert that into a
     * return-type TypeError the real function never raises. Without this assertion the
     * omission can be "tidied up" invisibly.
     */
    public function testWpKsesPostDeclaresNoReturnTypeJustLikeCore(): void
    {
        $this->assertFalse(
            (new \ReflectionFunction('wp_kses_post'))->hasReturnType(),
            'wp_kses_post() must declare no return type, matching core.'
        );
    }

    // ── Scalars and null: core coerces, so the harness must too ──────────────

    /**
     * @dataProvider coercedScalarProvider
     *
     * WHAT "AS CORE DOES" MEANS HERE, precisely: the value COERCES instead of fataling.
     * It does NOT mean the bytes match core. They do not, and not only on the one row
     * that needs an override — esc_url()'s stub models no scheme completion at all, so
     * core answers 'http://42' where the stub answers '42', and core answers
     * 'http://plain-text' where the stub answers 'plain-text'. Those byte-level
     * simplifications predate #709 and are listed in tests/bootstrap.php under "NOT
     * modelled". The expectations below are therefore the STUB's contract for coercion,
     * and the fidelity claim they carry is fatal-vs-coerce only.
     *
     * $escUrlExpected exists for the one row where the stub's own idiom diverges from
     * the other three: esc_url() ends in `filter_var(...) ?: ''`, and the string "0" is
     * falsy in PHP, so a stored 0 comes back as '' rather than '0'.
     */
    public function testEveryStubCoercesScalarsAndNullWithoutFataling(
        string $label,
        $value,
        string $expected,
        ?string $escUrlExpected = null
    ): void {
        foreach (self::STUBS as $fn) {
            [$returned, $thrown] = $this->callStub($fn, $value);
            $this->assertNull(
                $thrown,
                "{$fn}() must not fatal on {$label}: WordPress core coerces it and renders."
            );
            $this->assertIsString(
                $returned,
                "{$fn}() must return a string for {$label}."
            );
            $this->assertSame(
                $fn === 'esc_url' ? ($escUrlExpected ?? $expected) : $expected,
                $returned,
                "{$fn}() must coerce {$label} the way core does."
            );
        }
    }

    public static function coercedScalarProvider(): array
    {
        return [
            // Plain text carries no character the simplified stubs transform, so one
            // expectation holds across all four.
            'a plain string'      => ['a plain string', 'plain-text', 'plain-text'],
            'an empty string'     => ['an empty string', '', ''],
            'a stored int'        => ['a stored int', 42, '42'],
            'a stored zero'       => ['a stored zero', 0, '0', ''],
            'a stored float'      => ['a stored float', 4.5, '4.5'],
            'a stored true'       => ['a stored true', true, '1'],
            'a stored false'      => ['a stored false', false, ''],
            // null is the shape the OLD typed stubs got most wrong: a non-nullable
            // string parameter rejects null even in coercive mode, so all four fataled
            // on a value core turns into ''.
            'a stored null'       => ['a stored null', null, ''],
        ];
    }

    public function testEveryStubCoercesAnObjectThatCanBecomeAString(): void
    {
        foreach (self::STUBS as $fn) {
            [$returned, $thrown] = $this->callStub($fn, new PPEscapingStubStringable());
            $this->assertNull($thrown, "{$fn}() must not fatal on a __toString object.");
            $this->assertSame('obj-to-string', $returned, "{$fn}() must use __toString.");
        }
    }

    // ── Arrays: the two-way split that the old stubs flattened ───────────────

    /**
     * @dataProvider arrayShapeProvider
     */
    public function testEscHtmlAndEscAttrRenderAnArrayInsteadOfFataling(string $label, array $value): void
    {
        foreach (['esc_html', 'esc_attr'] as $fn) {
            [$returned, $thrown, $diagnostic] = $this->callStub($fn, $value);
            $this->assertNull(
                $thrown,
                "{$fn}() must NOT fatal on {$label}. Core's wp_check_invalid_utf8() opens "
                . 'with `$text = (string) $text;`, which yields the literal "Array" and a '
                . 'warning. A probe that sees a TypeError here is reading a harness artifact, '
                . 'not a production 500 (#709).'
            );
            $this->assertSame('Array', $returned, "{$fn}() must return core's 'Array' literal.");
            $this->assertStringContainsString(
                'Array to string conversion',
                $diagnostic,
                "{$fn}() must raise core's warning, not swallow it."
            );
        }
    }

    /**
     * The correction to #709's stated rationale: these two DO fatal on a stored array,
     * on a real site, today. Any guard the #705/#706/#708 family writes must treat this
     * as a live defect class rather than a harness artifact.
     *
     * @dataProvider arrayShapeProvider
     */
    public function testEscUrlAndWpKsesPostStillFatalOnAnArrayBecauseCoreDoes(
        string $label,
        array $value
    ): void {
        $expected = [
            // esc_url() -> ltrim($url): internal, string-only, so an array is a TypeError.
            'esc_url'      => 'ltrim():',
            // wp_kses_post() -> wp_kses_no_null()'s preg_replace() ACCEPTS an array subject
            // and returns an array, which then dies in the pre_kses filter's str_contains().
            'wp_kses_post' => 'str_contains():',
        ];

        foreach ($expected as $fn => $expectedFragment) {
            [, $thrown] = $this->callStub($fn, $value);
            $this->assertInstanceOf(
                \TypeError::class,
                $thrown,
                "{$fn}() must still fatal on {$label} — WordPress core does, so a stored "
                . 'array in a prop this reaches really does 500 the public page (#709).'
            );
            $this->assertStringContainsString(
                $expectedFragment,
                $thrown->getMessage(),
                "{$fn}() must fatal through the same builtin core reaches, so the message a "
                . "probe records matches the production stack."
            );
        }
    }

    public static function arrayShapeProvider(): array
    {
        return [
            'a stored list array'  => ['a stored list array', ['a', 'b']],
            'a stored assoc array' => ['a stored assoc array', ['url' => 'x']],
            'a stored empty array' => ['a stored empty array', []],
        ];
    }

    // ── Objects without __toString: every one of the four fatals ─────────────

    public function testEveryStubFatalsOnAnObjectThatCannotBecomeAString(): void
    {
        // The class SPLIT is the point, so assert it per stub rather than settling for a
        // shared Throwable. esc_html/esc_attr die in the (string) cast, which raises a
        // plain Error; esc_url and wp_kses_post die inside an internal function, which
        // raises a TypeError. Asserting only Throwable would let all four drift to a
        // hand-rolled exception of the wrong class while the matrix above still claimed
        // the split — the most specific claim in the docblock would be the one nothing
        // defends.
        $expected = [
            'esc_html'     => [\Error::class,     'could not be converted to string'],
            'esc_attr'     => [\Error::class,     'could not be converted to string'],
            'esc_url'      => [\TypeError::class, 'ltrim():'],
            'wp_kses_post' => [\TypeError::class, 'preg_replace():'],
        ];

        foreach ($expected as $fn => [$expectedClass, $expectedFragment]) {
            [, $thrown] = $this->callStub($fn, new \stdClass());
            // assertSame on the concrete class, NOT assertInstanceOf: TypeError EXTENDS
            // Error, so an instanceof check against Error would happily accept a TypeError
            // and the split this test exists to defend would go unpinned.
            $this->assertNotNull($thrown, "{$fn}() must fatal on an object with no __toString.");
            $this->assertSame(
                $expectedClass,
                $thrown::class,
                "{$fn}() must fatal with the same Throwable class core raises."
            );
            $this->assertStringContainsString(
                $expectedFragment,
                $thrown->getMessage(),
                "{$fn}() must fatal through the same construct core reaches."
            );
        }
    }
}
