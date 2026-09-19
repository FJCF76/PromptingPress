<?php
/**
 * A RETIRED NAME MAY BE MENTIONED, BUT NEVER OFFERED.
 *
 * WHY THIS EXISTS, and it is a defect this PR actually shipped before the review caught
 * it. `lib/ai-context.php` built the runtime prompt that reaches the authoring model, and
 * one line of it read:
 *
 *     - stats: `background_image`
 *
 * under the heading "Background images". The prop had been retired in the same change and
 * is refused with `retired_prop`. Its two siblings on that list, `section` and `cta`, had
 * both been rewritten at their own rebuilds to say "NOT a prop since the v2 rebuild…";
 * stats' one-liner was simply missed, and nothing in the suite could see it. Four more of
 * the same class were sitting in `ai-instructions/`, one of them two paragraphs from a
 * correction made in this very PR.
 *
 * THE ASYMMETRY THAT MAKES THIS WORTH A TEST. A retired name must stay mentionable — an
 * author who meets `--stats-max-width` on a page written last year needs to find out what
 * happened to it, and deleting every trace is how a migration doc becomes useless. So the
 * rule cannot be "never say the name". It is "say it with its ending attached": any
 * mention must sit within a retirement marker's reach, so the same sentence that names it
 * says it is gone.
 *
 * WHAT IS CHECKED, and the scoping differs by surface because the surfaces differ:
 *
 *   AI-FACING DOCS           paragraph-scoped. Markdown paragraphs are the unit a reader
 *                            takes in, and a marker anywhere in one reaches the whole of
 *                            it.
 *   THE RUNTIME PROMPT       sentence-scoped. `pp_ai_system_prompt()` is a handful of
 *                            enormous strings with no paragraph structure, so a
 *                            paragraph rule would be satisfied by one "retired" thousands
 *                            of characters away.
 *
 * WHAT IS NOT CHECKED, stated so the gap is deliberate rather than assumed: retired PROP
 * names. Every attempt at a textual rule for them produced false positives (a paragraph
 * about `cta.title` matching `cta` near a `theme` two clauses later), and a guard that
 * cries wolf gets suppressed rather than fixed. The prop half is covered from the other
 * direction instead — `SchemaValidationTest` pins that every `retired_props` entry names a
 * real replacement route, and `DocumentedUdcSnippetsTest` pins that every documented `udc`
 * map is accepted — so a doc teaching a retired prop with a code example still fails.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class RetiredNamesAreMarkedRetiredTest extends TestCase
{
    /**
     * A phrase that tells the reader the name is no longer live.
     *
     * Deliberately generous. The cost of a marker this misses is a false failure on true
     * prose, which is loud and gets fixed; the cost of a marker too narrow is a guard
     * nobody trusts. Every entry here appears in prose already shipped.
     */
    private const RETIREMENT_MARKERS =
        '/(retir|no longer (exists|declared|a slot|carries)|does not exist|never existed|'
        . 'was the last|left (at|with) #|went (with|at) #|is gone|are gone|\bGONE\b|'
        . 'v1 spelled|HISTORY|deleted with|not declared|no_style_slots|is moot)/i';

    /**
     * How far from the name a marker may sit and still be read as attached to it.
     *
     * A WINDOW, NOT A CONTAINER, and that is the second-pass review's correction. The
     * first version asked only whether a marker appeared anywhere in the same paragraph
     * (or prompt sentence) — and the longest "sentence" the prompt splitter produced was
     * 4001 characters, which is precisely the "a `retired` thousands of characters away"
     * problem the splitting was chosen to avoid. 400 characters is about a long paragraph
     * either side: close enough that a reader meeting the name also meets the marker.
     */
    private const MARKER_WINDOW = 400;

    /** Components that declare ZERO style slots — every `--<name>-*` is therefore retired. */
    private function zeroSlotComponents(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            if (($schema['styling']['style_slots'] ?? []) === []) {
                $out[] = basename(dirname($file));
            }
        }
        sort($out);
        return $out;
    }

    /** The AI-facing prose an authoring agent is pointed at. */
    private function docFiles(): array
    {
        $root = dirname(__DIR__);
        return array_merge(
            glob($root . '/ai-instructions/*.md') ?: [],
            [$root . '/AI_CONTEXT.md', $root . '/README.md']
        );
    }

    /**
     * Every retired slot name in `$haystack`, with a marker within MARKER_WINDOW of it.
     *
     * @return array<int,array{0:string,1:string}>  [name, the window around it] for offenders
     */
    private function unmarkedMentions(string $haystack, array $components, string $unitDelimiter): array
    {
        $offenders = [];
        foreach ($components as $component) {
            if (!preg_match_all('/--' . $component . '-[a-z0-9-]+/', $haystack, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [$name, $offset]) {
                // TWO CONSTRAINTS, INTERSECTED, and the second one is the second-pass
                // review's other correction. A 400-character window ALONE was defeated in
                // the runtime prompt: retirement language is dense enough there that an
                // injected offer always had some unrelated "retired" within reach. So the
                // window is CLIPPED TO THE ENCLOSING UNIT — the markdown paragraph in a
                // doc, the single `$parts[]` line in the prompt — and a marker in the
                // neighbouring bullet no longer vouches for this one.
                //
                // The unit alone is not enough either, which is why both apply: one
                // `$parts[]` entry runs to 4001 characters, so "somewhere in this line" is
                // as loose as "somewhere in this paragraph" was.
                $unitStart = strrpos(substr($haystack, 0, $offset), $unitDelimiter);
                $unitStart = $unitStart === false ? 0 : $unitStart + strlen($unitDelimiter);
                $unitEnd   = strpos($haystack, $unitDelimiter, $offset);
                $unitEnd   = $unitEnd === false ? strlen($haystack) : $unitEnd;

                $start = max($unitStart, $offset - self::MARKER_WINDOW);
                $end   = min($unitEnd, $offset + strlen($name) + self::MARKER_WINDOW);
                $window = substr($haystack, $start, $end - $start);

                if (!preg_match(self::RETIREMENT_MARKERS, $window)) {
                    $offenders[] = [$name, $window];
                }
            }
        }
        return $offenders;
    }

    /** Count every retired slot name mentioned, marked or not — the fail-closed denominator. */
    private function countMentions(string $haystack, array $components): int
    {
        $n = 0;
        foreach ($components as $component) {
            $n += preg_match_all('/--' . $component . '-[a-z0-9-]+/', $haystack, $ignored);
        }
        return $n;
    }

    public function testNoAiFacingDocOffersARetiredStyleSlotAsLive(): void
    {
        $components = $this->zeroSlotComponents();
        $this->assertNotEmpty($components, 'no zero-slot components found — the scan is inert');

        $mentions = 0;
        foreach ($this->docFiles() as $file) {
            $text      = (string) file_get_contents($file);
            $mentions += $this->countMentions($text, $components);

            foreach ($this->unmarkedMentions($text, $components, "\n\n") as [$name, $window]) {
                $this->fail(sprintf(
                    "%s names %s with nothing within %d characters saying the name is "
                    . "retired. That component declares zero style slots, so writing the name "
                    . "is refused with `no_style_slots` — named neutrally, it reads as an "
                    . "offer.\n\n…%s…",
                    basename($file),
                    $name,
                    self::MARKER_WINDOW,
                    preg_replace('/\s+/', ' ', $window) ?? ''
                ));
            }
        }

        // FAIL-CLOSED, AT THE REAL COUNT. These names SHOULD still appear (a migration doc
        // that deleted them would be useless), so a scan finding few has broken rather than
        // succeeded. The floor was 10 against an actual count in the dozens, which the
        // second-pass review defeated by deleting every doc but one and still passing.
        $this->assertGreaterThan(
            55,
            $mentions,
            'the scan found far fewer retired slot names than the docs carry — the glob or '
            . 'the naming convention changed, and either way this guard has lost its reach'
        );
    }

    public function testTheRuntimePromptNeverOffersARetiredStyleSlotAsLive(): void
    {
        $components = $this->zeroSlotComponents();
        $prompt     = pp_ai_system_prompt();
        $this->assertNotSame('', $prompt);

        // THE SAME WINDOWED RULE, NOT A SENTENCE SPLITTER. The prompt is a handful of very
        // long strings, and splitting it produced a 4001-character "sentence" — a container
        // so large that a marker anywhere in it satisfied the rule, which is exactly what
        // the splitting was supposed to prevent. A character window does not care about
        // punctuation and behaves identically on both surfaces.
        foreach ($this->unmarkedMentions($prompt, $components, "\n") as [$name, $window]) {
            $this->fail(sprintf(
                "pp_ai_system_prompt() names %s with nothing within %d characters saying it "
                . "is retired. This is the text the authoring model reads: a slot named "
                . "neutrally there is an instruction to write it, and the write is refused "
                . "with `no_style_slots`.\n\n…%s…",
                $name,
                self::MARKER_WINDOW,
                preg_replace('/\s+/', ' ', $window) ?? ''
            ));
        }

        $this->assertGreaterThan(
            3,
            $this->countMentions($prompt, $components),
            'the prompt names almost no retired slot — which would be a change of policy '
            . '(it deliberately names them so an author meeting one on an aged page is not '
            . 'left guessing), so check that rather than lowering this'
        );
    }
}
