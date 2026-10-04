<?php
/**
 * tests/AiContextStoredShapeTest.php
 *
 * #1163: the per-band summary line of the chat context (`_pp_summarize_component()`,
 * lib/ai-context.php) reads STORED props. Stored data is not always what the write path
 * accepts: `restore_composition` reports without blocking (#233), older compositions
 * predate the rules, and a raw `_pp_composition` meta write is not validated. The
 * summarizer gated typed built-ins with `!empty()` only, so a stored array `title`,
 * `image_url` or `background_image` reached `mb_strlen()` / `basename()` and threw a
 * TypeError, and the chat context for the whole page failed to build. Array `layout`,
 * `theme`, component name and `props.id` warned "Array to string conversion".
 *
 * THE FIX (recorded in #1163's body). Every stored value the line interpolates goes
 * through the family idiom (`is_scalar ? (string) : ''`), and a value that is not a
 * scalar is LEFT OUT of the line. No placeholder text is written in its place: the
 * index line only carries what the store actually holds in a readable form, and the
 * composition JSON printed under the index still shows the stored value verbatim, so the
 * model sees the atypical value where it is, not a made-up stand-in. The lines for the
 * retired `theme` and `background_image` props are dropped outright: no component
 * declares either since the v2 rebuild, so a summary naming them described paint
 * nothing renders.
 *
 * WHAT THIS FILE PINS:
 *   1. the summarizer, unit level, over each non-scalar shape: exact line, no warning;
 *   2. the retired props are not summarized, and well-formed scalar values summarize as
 *      before (truncation, basename, an int title);
 *   3. END TO END: `pp_ai_format_messages()` over a stored page carrying each shape
 *      builds, lists every band, keeps the stored bytes untouched, and shows the stored
 *      value in the composition JSON.
 *
 *   4. a list entry that is not an object at all is listed at its index as unreadable,
 *      naming only its stored type (the ruling recorded in #1163's body).
 *
 * SCOPE, stated so it is not mistaken for a gap: a non-string `component` fails earlier
 * on the same path, in `pp_inspect_composition()` (lib/operate.php). That surface is
 * outside this change by the same ruling, so the end-to-end matrix does not include it;
 * the component-name guard is pinned at unit level.
 */

use PHPUnit\Framework\TestCase;

class AiContextStoredShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    /**
     * Runs $fn with every PHP warning/notice captured instead of printed. A TypeError is
     * NOT caught: it propagates and errors the test, which is the red this file proves.
     *
     * @return array{0: mixed, 1: list<string>} the return value and "message @file:line"
     */
    private static function capture(callable $fn): array
    {
        $seen = [];
        set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) use (&$seen): bool {
            $seen[] = $msg . ' @' . basename($file) . ':' . $line;
            return true;
        });
        try {
            $out = $fn();
        } finally {
            restore_error_handler();
        }
        return [$out, $seen];
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonScalarShapes(): array
    {
        return [
            'list'        => [['Our services']],
            'locale map'  => [['en' => 'Our services', 'es' => 'Nuestros servicios']],
            'nested'      => [[['text' => 'Our services']]],
        ];
    }

    // ── 1. The summarizer, over each non-scalar shape ─────────────────────────

    /** @dataProvider nonScalarShapes */
    public function testANonScalarTitleIsLeftOutOfTheLine(mixed $bad): void
    {
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => 'section', 'props' => ['title' => $bad, 'layout' => 'image-left']]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame('section | layout: image-left', $line, 'no title segment, and nothing written in its place');
    }

    /** @dataProvider nonScalarShapes */
    public function testANonScalarImageUrlIsLeftOutOfTheLine(mixed $bad): void
    {
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => 'section', 'props' => ['image_url' => $bad, 'layout' => 'cover']]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame('section | layout: cover', $line);
    }

    /** @dataProvider nonScalarShapes */
    public function testANonScalarLayoutIsLeftOutOfTheLine(mixed $bad): void
    {
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => 'grid', 'props' => ['layout' => $bad, 'title' => 'Steps']]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame('grid | title: "Steps"', $line);
    }

    /** @dataProvider nonScalarShapes */
    public function testANonStringComponentNameReadsAsUnknown(mixed $bad): void
    {
        // `unknown` is the summarizer's existing word for a band with no readable
        // component name (it is what a band with no `component` key already shows).
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => $bad, 'props' => ['title' => 'Kept']]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame('unknown | title: "Kept"', $line);
    }

    /** @dataProvider nonScalarShapes */
    public function testANonScalarComponentIdFromTheInspectTargetIsLeftOut(mixed $bad): void
    {
        // The inspect target's `component_id` is the band's STORED `props.id`
        // (pp_inspect_composition), so it is stored data like the rest of the line.
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => 'section', 'props' => ['title' => 'About']],
            ['component_id' => $bad]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame('section | title: "About"', $line);
    }

    /** @dataProvider nonScalarShapes */
    public function testANonScalarRecipeFromTheInspectTargetIsLeftOut(mixed $bad): void
    {
        // The inspect target's `active_recipe` is the band's STORED `style.__recipe`.
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => 'section', 'props' => ['title' => 'About']],
            ['active_recipe' => $bad]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame('section | title: "About"', $line, 'no Style line, and nothing written in its place');
    }

    /** @dataProvider nonScalarShapes */
    public function testANonScalarStyleSlotValueIsLeftOut(mixed $bad): void
    {
        // A slot's `current` is the band's stored style value. No shipped component
        // declares a slot today, so this is driven through a target directly.
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
            ['component' => 'section', 'props' => ['title' => 'About']],
            ['style_slots' => [
                ['slot' => 'gap', 'default' => '1rem', 'current' => $bad],
                ['slot' => 'pad', 'default' => '1rem', 'current' => '2rem'],
            ]]
        ));
        $this->assertSame([], $warnings);
        $this->assertSame("section | title: \"About\"\n      Style: pad: 2rem", $line, 'the scalar override is still shown');
    }

    public function testAScalarRecipeIsStillShown(): void
    {
        $line = _pp_summarize_component(
            ['component' => 'section', 'props' => ['title' => 'About']],
            ['active_recipe' => 'bold']
        );
        $this->assertSame("section | title: \"About\"\n      Style: recipe: bold", $line);
    }

    public function testAScalarComponentIdIsStillShown(): void
    {
        $line = _pp_summarize_component(
            ['component' => 'section', 'props' => ['title' => 'About']],
            ['component_id' => 'about']
        );
        $this->assertSame('section (about) | title: "About"', $line);
    }

    public function testNonArrayPropsSummarizeTheNameOnly(): void
    {
        foreach (['a string', 42, true] as $props) {
            [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(
                ['component' => 'section', 'props' => $props]
            ));
            $this->assertSame([], $warnings, var_export($props, true));
            $this->assertSame('section', $line, var_export($props, true));
        }
    }

    public function testAMissingOrScalarNonStringComponentNameReadsAsUnknown(): void
    {
        // The name guard is `is_string`, not `is_scalar`: a stored int/float/bool name is
        // not a component name either, so it reads as `unknown` like a missing key does.
        foreach ([[], ['component' => 7], ['component' => 1.5], ['component' => true], ['component' => null]] as $item) {
            $label = var_export($item, true);
            [$line, $warnings] = self::capture(static fn () => _pp_summarize_component($item + ['props' => ['title' => 'Kept']]));
            $this->assertSame([], $warnings, $label);
            $this->assertSame('unknown | title: "Kept"', $line, $label);
        }
    }

    public function testAStyleSlotKeepsItsOwnGate(): void
    {
        // A slot override is shown whenever its scalar value differs from the default,
        // falsy or not; a value equal to the default, and a null (never overridden), are
        // left out.
        $line = _pp_summarize_component(
            ['component' => 'section'],
            ['style_slots' => [
                ['slot' => 'gap',    'default' => '1rem', 'current' => '1rem'],
                ['slot' => 'pad',    'default' => '1rem', 'current' => null],
                ['slot' => 'radius', 'default' => '4px',  'current' => '0'],
            ]]
        );
        $this->assertSame("section\n      Style: radius: 0", $line);
    }

    public function testAnEmptyInspectTargetAddsNoStyleLine(): void
    {
        $this->assertSame('section', _pp_summarize_component(['component' => 'section'], ['style_slots' => []]));
    }

    // ── 2. Retired props, and the scalar baseline ────────────────────────────

    public function testTheRetiredThemeAndBackgroundImageAreNotSummarized(): void
    {
        $line = _pp_summarize_component(['component' => 'cta', 'props' => [
            'title'            => 'Talk to us',
            'theme'            => 'inverted',
            'background_image' => 'https://example.com/wp-content/uploads/hero-bg.jpg',
        ]]);
        $this->assertSame('cta | title: "Talk to us"', $line);
    }

    public function testRetiredPropsOfAnyShapeAreNotReadAtAll(): void
    {
        [$line, $warnings] = self::capture(static fn () => _pp_summarize_component(['component' => 'stats', 'props' => [
            'title'            => 'Numbers',
            'theme'            => ['muted'],
            'background_image' => ['https://example.com/bg.jpg'],
        ]]));
        $this->assertSame([], $warnings);
        $this->assertSame('stats | title: "Numbers"', $line);
    }

    public function testScalarValuesSummarizeAsBefore(): void
    {
        $this->assertSame('hero | title: "2024"', _pp_summarize_component(['component' => 'hero', 'props' => ['title' => 2024]]));
        $this->assertSame(
            'hero | layout: split | title: "This is a very long title that should..." | photo.jpg',
            _pp_summarize_component(['component' => 'hero', 'props' => [
                'layout'    => 'split',
                'title'     => 'This is a very long title that should be truncated at forty characters',
                'image_url' => 'https://example.com/wp-content/uploads/photo.jpg',
            ]])
        );
    }

    public function testFalsyScalarsStayOutOfTheLineAsBefore(): void
    {
        // The `!empty()` half of the guard keeps the gate the line always had: '', '0', 0,
        // 0.0 and false add no segment (no `title: "0"`, no `layout: 0`, no `(0)`).
        foreach (['', '0', 0, 0.0, false] as $falsy) {
            $label = var_export($falsy, true);
            $this->assertSame('section', _pp_summarize_component(['component' => 'section', 'props' => [
                'title' => $falsy, 'layout' => $falsy, 'image_url' => $falsy,
            ]], ['component_id' => $falsy, 'active_recipe' => $falsy]), $label);
        }
    }

    // ── 3. End to end: the chat context for a stored page ─────────────────────

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function storedPropShapes(): array
    {
        $cases = [];
        foreach (self::nonScalarShapes() as $shape => [$bad]) {
            foreach (['title', 'image_url', 'background_image', 'layout', 'theme', 'id'] as $prop) {
                $cases["{$prop}, {$shape}"] = [$prop, $bad];
            }
        }
        return $cases;
    }

    /** @dataProvider storedPropShapes */
    public function testTheChatContextBuildsForAStoredPageCarryingTheShape(string $prop, mixed $bad): void
    {
        $page = 41;
        $GLOBALS['_pp_test_store']['posts'][$page] = [
            'post_type'   => 'page',
            'post_title'  => 'Stored shape page',
            'post_status' => 'publish',
        ];
        $props = ['title' => 'Before', 'layout' => 'image-left'];
        $props[$prop] = $bad;
        // A RAW meta write: the route that stores what the write path would refuse.
        $raw = wp_json_encode([
            ['component' => 'section', 'props' => $props],
            ['component' => 'hero', 'props' => ['title' => 'After']],
        ]);
        $GLOBALS['_pp_test_store']['post_meta'][$page]['_pp_composition'] = $raw;

        [$messages, $warnings] = self::capture(static fn () => pp_ai_format_messages('System', [], $page));
        $system = $messages[0]['content'];

        $own = array_values(array_filter($warnings, static fn (string $w): bool => str_contains($w, '@ai-context.php:')));
        $this->assertSame([], $own, 'the context builder raises nothing of its own over this stored shape');

        $this->assertMatchesRegularExpression('/^  \[0\] section\b/m', $system, 'the band is still indexed');
        $this->assertMatchesRegularExpression('/^  \[1\] hero \| title: "After"/m', $system, 'and the loop carries on to the next band');
        preg_match('/^  \[0\] (.*)$/m', $system, $m);
        $this->assertStringNotContainsString('Our services', $m[1], 'the atypical value is not flattened into the index line');
        $this->assertStringNotContainsString('Array', $m[1]);

        $this->assertStringContainsString(
            'Composition:',
            $system,
            'the stored composition is still printed under the index'
        );
        $this->assertStringContainsString('Our services', $system, 'and it shows the stored value where it is stored');
        $this->assertSame($raw, $GLOBALS['_pp_test_store']['post_meta'][$page]['_pp_composition'], 'building the context writes nothing');
    }

    public function testTheChatContextBuildsForAStoredPageWhoseRecipeIsNotAScalar(): void
    {
        $page = 42;
        $GLOBALS['_pp_test_store']['posts'][$page] = [
            'post_type'   => 'page',
            'post_title'  => 'Stored recipe page',
            'post_status' => 'publish',
        ];
        $raw = wp_json_encode([
            ['component' => 'section', 'style' => ['__recipe' => ['Our services']], 'props' => ['title' => 'Before']],
            ['component' => 'hero', 'props' => ['title' => 'After']],
        ]);
        $GLOBALS['_pp_test_store']['post_meta'][$page]['_pp_composition'] = $raw;

        [$messages, $warnings] = self::capture(static fn () => pp_ai_format_messages('System', [], $page));
        $system = $messages[0]['content'];

        $own = array_values(array_filter($warnings, static fn (string $w): bool => str_contains($w, '@ai-context.php:')));
        $this->assertSame([], $own);
        $this->assertStringNotContainsString('recipe: Array', $system);
        $this->assertMatchesRegularExpression('/^  \[1\] hero \| title: "After"/m', $system);
        $this->assertSame($raw, $GLOBALS['_pp_test_store']['post_meta'][$page]['_pp_composition']);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function nonArrayEntries(): array
    {
        return [
            'string' => ['oops', 'string'],
            'int'    => [7, 'int'],
            'float'  => [1.5, 'float'],
            'true'   => [true, 'bool'],
            'null'   => [null, 'null'],
        ];
    }

    /**
     * A composition list entry that is not an object at all. The summary loop passed it
     * to `_pp_summarize_component(array $item)` and the signature threw a TypeError, so
     * the whole chat context failed to build. Per the ruling recorded in #1163's body it
     * is DISCLOSED AS ATYPICAL at its own index: the line says what is stored there (its
     * type) and invents nothing, the bands around it keep their indexes, and the stored
     * bytes are left as they are.
     *
     * @dataProvider nonArrayEntries
     */
    public function testANonArrayEntryIsListedAsUnreadableAtItsIndex(mixed $entry, string $type): void
    {
        $page = 43;
        $GLOBALS['_pp_test_store']['posts'][$page] = [
            'post_type'   => 'page',
            'post_title'  => 'Stored entry page',
            'post_status' => 'publish',
        ];
        $raw = wp_json_encode([
            ['component' => 'section', 'props' => ['title' => 'Before']],
            $entry,
            ['component' => 'hero', 'props' => ['title' => 'After']],
        ]);
        $GLOBALS['_pp_test_store']['post_meta'][$page]['_pp_composition'] = $raw;

        [$messages, $warnings] = self::capture(static fn () => pp_ai_format_messages('System', [], $page));
        $system = $messages[0]['content'];

        $own = array_values(array_filter($warnings, static fn (string $w): bool => str_contains($w, '@ai-context.php:')));
        $this->assertSame([], $own);
        $this->assertMatchesRegularExpression('/^  \[0\] section \| title: "Before"/m', $system);
        $this->assertMatchesRegularExpression(
            '/^  \[1\] \(unreadable entry: a stored ' . $type . ', not a component object\)$/m',
            $system,
            'the entry keeps its index and is described by what is stored, nothing more'
        );
        $this->assertMatchesRegularExpression('/^  \[2\] hero \| title: "After"/m', $system, 'the numbering after it is unchanged');
        $this->assertSame($raw, $GLOBALS['_pp_test_store']['post_meta'][$page]['_pp_composition'], 'building the context writes nothing');
    }
}
