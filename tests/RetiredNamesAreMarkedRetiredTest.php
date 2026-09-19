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
        '/(retir|no longer|does not exist|never existed|was the last|left (at|this|with|the)|'
        . 'went (with|at)|is gone|are gone|\bgone\b|v1 spelled|old page|HISTORY|deleted with|'
        . 'refused|rejected|used to|the old|pre-#|NOT a prop|moot|not declared|had \*\*two\*\*)/i';

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

    public function testNoAiFacingDocOffersARetiredStyleSlotAsLive(): void
    {
        $components = $this->zeroSlotComponents();
        $this->assertNotEmpty($components, 'no zero-slot components found — the scan is inert');

        $scanned = 0;
        foreach ($this->docFiles() as $file) {
            $paragraphs = preg_split('/\n\s*\n/', (string) file_get_contents($file)) ?: [];
            foreach ($paragraphs as $index => $paragraph) {
                foreach ($components as $component) {
                    if (!preg_match_all('/--' . $component . '-[a-z0-9-]+/', $paragraph, $m)) {
                        continue;
                    }
                    $scanned++;
                    $names = implode(', ', array_unique($m[0]));
                    $this->assertMatchesRegularExpression(
                        self::RETIREMENT_MARKERS,
                        $paragraph,
                        sprintf(
                            "%s paragraph %d names %s and nothing in that paragraph says the "
                            . "name is retired. `%s` declares zero style slots, so writing any "
                            . "of these is refused with `no_style_slots` — a doc that names one "
                            . "neutrally reads as an offer.\n\n%s",
                            basename($file),
                            $index,
                            $names,
                            $component,
                            substr(preg_replace('/\s+/', ' ', $paragraph) ?? '', 0, 300)
                        )
                    );
                }
            }
        }

        // Fail-closed: these names SHOULD still appear in the docs (a migration doc that
        // deleted them would be useless), so a scan finding none has broken.
        $this->assertGreaterThan(
            10,
            $scanned,
            'the scan found almost no retired slot names in the docs — the glob or the '
            . 'naming convention changed, and either way this guard is inert'
        );
    }

    public function testTheRuntimePromptNeverOffersARetiredStyleSlotAsLive(): void
    {
        $components = $this->zeroSlotComponents();
        $prompt     = pp_ai_system_prompt();
        $this->assertNotSame('', $prompt);

        // SENTENCE-SCOPED, and only on a full stop. The prompt is a handful of very long
        // strings with no paragraph structure, so a paragraph rule would be satisfied by a
        // "retired" thousands of characters away. Splitting on `;` as well was tried and
        // shreds the legitimate enumeration of retired keys into clauses whose marker sits
        // in the clause before — a false positive caused by the splitter, not the prose.
        $sentences = preg_split('/(?<=\.)\s+|\n/', $prompt) ?: [];
        $scanned   = 0;

        foreach ($sentences as $sentence) {
            foreach ($components as $component) {
                if (!preg_match_all('/--' . $component . '-[a-z0-9-]+/', $sentence, $m)) {
                    continue;
                }
                $scanned++;
                $this->assertMatchesRegularExpression(
                    self::RETIREMENT_MARKERS,
                    $sentence,
                    sprintf(
                        "pp_ai_system_prompt() names %s in a sentence that never says it is "
                        . "retired. This is the prompt the authoring model reads: a slot named "
                        . "neutrally there is an instruction to write it, and the write is "
                        . "refused with `no_style_slots`.\n\n%s",
                        implode(', ', array_unique($m[0])),
                        substr(preg_replace('/\s+/', ' ', $sentence) ?? '', 0, 300)
                    )
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $scanned,
            'the prompt names no retired slot at all — which would be a change of policy '
            . '(it deliberately names them so an author meeting one on an aged page is not '
            . 'left guessing), so check that rather than lowering this'
        );
    }
}
