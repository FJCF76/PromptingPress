<?php
/**
 * tests/ModelFacingRosterTest.php
 *
 * THE ROSTERS A MODEL READS ARE CHECKED AGAINST THE REGISTRY (#1087).
 *
 * WHY THIS SUITE EXISTS, as a measured observation rather than a principle. Across the
 * fourteen shipped instruction files and the runtime prompt, EVERY roster that a test pins
 * is correct, and every roster that is not pinned has drifted. "Seven v2 components"
 * survived in five places two rebuild sprints after the eighth and ninth joined;
 * `validate-site.md` listed fifteen of nineteen retired props. Nothing was wrong with the
 * people who wrote them — a hand-typed roster is correct exactly until the registry moves,
 * and nothing tells anyone it moved.
 *
 * WHAT IS SOUNDLY CHECKABLE, AND WHAT IS NOT. This is the part that decides the design, and
 * getting it wrong produces the failure this repo has already paid for once: a guard whose
 * trigger matched words the docs use constantly, so 46% of its subjects satisfied it by
 * accident and it was asserting on nothing real.
 *
 * A sentence naming three v2 components is NOT necessarily a roster claim. The runtime
 * prompt says "`cta`, `faq`, `table`, `embed` and `stats` have only the band half — none
 * declares a media role", which names five and is a true SUBSET claim. A guard reading
 * "names 3+ components" as "claims to list them all" would demand that sentence name nine
 * and would be wrong. Intent is not recoverable from the names alone.
 *
 * So the completeness check is ANCHORED: a declared phrase marks a sentence that really
 * does claim a full roster, and the guard compares that sentence's names against the
 * registry. The anchors are themselves fail-closed — an anchor that stops matching fails
 * here rather than silently exempting its sentence, which is what stops the guard being
 * dodged by rewording.
 *
 * One further check needs no anchor because it is sound on its own:
 *
 *   REVERSE MEMBERSHIP  a model-facing doc may not describe a v2 component as carrying
 *                       style slots. That is decidable from the registry for any mention.
 *
 * A THIRD CHECK WAS BUILT AND REMOVED, and the reasoning is kept with the method it failed
 * in (see the long comment above the component-name helper). Short version: "a stated count
 * must match the list beside it" sounds sound, fired four times on the shipped corpus, and
 * was wrong all four times — real prose pairs a count with an exclusion, a historical
 * figure, or a different clause. It would also have passed on the exact defect that
 * motivated it. Anchoring is what makes count checking work.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class ModelFacingRosterTest extends TestCase
{
    /**
     * The surfaces a model actually reads. The runtime prompt is added separately because
     * it is assembled rather than read from disk.
     */
    private function modelFacingFiles(): array
    {
        $root  = dirname(__DIR__);
        $files = glob($root . '/ai-instructions/*.md') ?: [];
        foreach (['AI_CONTEXT.md', 'AI_RULES.md'] as $router) {
            if (is_file($root . '/' . $router)) {
                $files[] = $root . '/' . $router;
            }
        }
        $this->assertGreaterThan(10, count($files), 'the model-facing corpus went missing');
        return $files;
    }

    /**
     * Read a file an anchor names, failing with the anchor's own label if it is gone.
     *
     * The anchors build their haystacks EAGERLY, so a renamed or moved file made
     * file_get_contents() return false and the assertion below raise a TypeError under
     * strict_types — still a failure, so the fail-closed property held, but reported as a PHP
     * warning plus a type error instead of the diagnostic the anchor mechanism exists to
     * produce. A guard that fails for the wrong stated reason costs the next reader the time
     * it was built to save.
     */
    private function anchorSource(string $relative, string $label): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        $this->assertFileExists($path, "the anchor \"{$label}\" names {$relative}, which no longer exists");
        return (string) file_get_contents($path);
    }

    /** The composable components on the v2 contract, derived. */
    private function v2Composable(): array
    {
        $names = [];
        foreach (array_keys(\pp_composable_components()) as $component) {
            if (\pp_udc_is_v2_component($component)) {
                $names[] = $component;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * THE ANCHORED COMPLETENESS CHECK.
     *
     * Each anchor is a phrase that genuinely introduces a full v2 roster. The sentence it
     * heads must name every composable v2 component and no component that is not one.
     *
     * ANCHORS ARE FAIL-CLOSED. If an anchor stops matching — the sentence was reworded, the
     * section was moved — this fails rather than passing on zero subjects, which is what
     * stops the guard from being silently disabled by an edit.
     */
    public function testAnchoredV2RostersNameEveryV2Component(): void
    {
        $expected = $this->v2Composable();
        $this->assertGreaterThan(5, count($expected), 'the derived v2 roster is implausibly small');

        $prompt  = \pp_ai_system_prompt();
        $anchors = [
            // Already derived and pinned by DocsCoverageTest; re-checked here so the two
            // guards cannot disagree about what a complete roster is.
            ['the runtime prompt', $prompt, '/ON A v2 COMPONENT \(([^)]+)\)/'],

            // THE PROSE ANCHORS, landing WITH the rewrite exactly as the note below
            // promised. Each of these is a sentence that genuinely introduces a COMPLETE
            // v2 roster — the shape #1045 identified as where drift hides, because a
            // reader takes an enumeration as exhaustive whether or not it is. Every one
            // of the three was undercounting before this rewrite (seven names, or six),
            // and two of them sat in files that named the full nine correctly somewhere
            // else, so the documents disagreed with themselves.
            //
            // Anchored rather than scanned: a roster is only checkable when something
            // marks where it starts and ends, and a phrase that must keep matching is
            // also a phrase an editor cannot quietly delete.
            [
                'AI_CONTEXT.md\'s styling route',
                $this->anchorSource('AI_CONTEXT.md', "AI_CONTEXT.md's styling route"),
                '/For the NINE v2 components \(([^)\n]{0,200})\)/',
            ],
            [
                'AI_CONTEXT.md\'s style-slot exclusion',
                $this->anchorSource('AI_CONTEXT.md', "AI_CONTEXT.md's style-slot exclusion"),
                '/NONE OF THIS APPLIES TO A v2 COMPONENT — all nine of ([^:\n]{0,200}):/',
            ],
            [
                'AI_CONTEXT.md\'s band-background roster',
                $this->anchorSource('AI_CONTEXT.md', "AI_CONTEXT.md's band-background roster"),
                '/Every v2 component is different, and better:\*\* on all nine of ([^\n]{0,200}?) the band background/',
            ],
            [
                // THE FILE EVERY OTHER FILE POINTS AT for styling, and the only complete
                // roster in it — so an omission here reaches a reader who was sent to it
                // precisely because they needed the authoritative list.
                'style-component.md\'s opening roster',
                $this->anchorSource('ai-instructions/style-component.md', "style-component.md's opening roster"),
                '/Nine composable components and both chrome components work this\s+way: ([^.]{0,200}?), plus/s',
            ],
            [
                'retheme.md\'s rhythm tier split',
                $this->anchorSource('ai-instructions/retheme.md', "retheme.md's rhythm tier split"),
                '/\*\*The nine v2 components\*\* \(([^)]{0,200})\)/s',
            ],
            [
                'retheme.md\'s dark-band trap',
                $this->anchorSource('ai-instructions/retheme.md', "retheme.md's dark-band trap"),
                '/All NINE v2 components — (.{0,200}?) — have no `theme` prop/s',
            ],
        ];

        // EVERY ANCHOR MUST BOUND ITS OWN CAPTURE, and the bound is not cosmetic. A
        // negated character class matches newlines, and `.` matches them under `/s`, so an
        // unbounded capture is terminated only by the next occurrence of its closing
        // delimiter ANYWHERE in the file. Measured on the style-slot anchor before this was
        // fixed: replacing the sentence's terminating colon with an em dash — an ordinary
        // prose edit — grew the capture from 86 characters to 701 spanning several unrelated
        // paragraphs, AND THE TEST STILL PASSED, because the wider span happened to contain
        // all nine v2 names and no other component name. The anchor was then pinning a
        // paragraph rather than its sentence, and any roster edit inside that window could be
        // masked by a v2 name mentioned elsewhere in it. Excluding the newline and capping the
        // span at 200 characters keeps each anchor scoped to the sentence it names.
        $checked = 0;
        foreach ($anchors as [$label, $haystack, $pattern]) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $haystack,
                "the roster anchor {$pattern} is gone from {$label}. An anchor that stops "
                . 'matching exempts its sentence from this guard, so it fails here instead'
            );
            preg_match($pattern, $haystack, $m);
            $named = $this->componentNamesIn($m[1]);
            sort($named);
            $this->assertSame(
                $expected,
                $named,
                "{$label}'s v2 roster is stale. It names: " . implode(', ', $named)
                . ' — the registry says: ' . implode(', ', $expected)
            );
            $checked++;
        }
        // SEVEN ANCHORS TODAY, and the floor sits just under it. `> 0` was the token floor
        // this suite rejects everywhere else: four of the five could be deleted from the array
        // and it would still pass, which is the same silent-exemption shape the per-anchor
        // fail-closed match exists to prevent for the prose. Bump it with the array.
        $this->assertGreaterThan(6, $checked, '7 roster anchors today; deleting one exempts its roster from this guard');
    }

    /**
     * THE REVERSE CHECK: no model-facing doc calls a v2 component a slot component.
     *
     * Sound without any anchor, because it is decidable for every mention: a component
     * either declares style slots or it does not. This is the half of the drift that
     * actively misleads — a model told `stats` has style slots writes a `style_component`
     * call and is refused with `no_style_slots`.
     *
     * Scoped to sentences that put the component name and a slot claim together, within one
     * sentence, so ordinary prose about the v1 system near a v2 name does not trip it.
     */
    public function testNoModelFacingDocDescribesAV2ComponentAsCarryingStyleSlots(): void
    {
        $v2       = $this->v2Composable();
        $scanned  = 0;
        $failures = [];

        foreach ($this->modelFacingFiles() as $file) {
            foreach ($this->sentences((string) file_get_contents($file)) as $sentence) {
                // Only sentences that make a POSITIVE slot claim.
                if (!preg_match('/\b(declares?|carries|carry|has|have|its|their)\b[^.]{0,60}\bstyle slots?\b/i', $sentence, $claim)) {
                    continue;
                }
                // A claim that a component declares NO slots is the correct statement, so it
                // is exempt — but the exemption is scoped to THE CLAUSE THAT MAKES THE CLAIM,
                // not to the sentence.
                //
                // Sentence scope is how this guard was found asserting on nothing. Measured
                // over the real sixteen-file corpus: 16 sentences make a positive slot claim,
                // 15 carried a "no" or "not" SOMEWHERE and were exempted, and exactly ONE was
                // ever scanned. A true defect with an unrelated negation later in the
                // sentence — "declares style slots, but not for its background" — was silently
                // exempt. That is the 46%-by-accident shape this repo has already paid for,
                // and the vacuity probe missed it because it counted HITS (zero, correctly)
                // instead of SUBJECTS SCANNED (one).
                if (preg_match('/\b(no|zero|not|never|stopped|retired|gone)\b/i', $claim[0])) {
                    continue;
                }
                $scanned++;
                foreach ($this->componentNamesIn($sentence) as $named) {
                    if (in_array($named, $v2, true)) {
                        $failures[] = basename($file) . ': ' . trim(preg_replace('/\s+/', ' ', $sentence) ?? '');
                    }
                }
            }
        }

        $this->assertSame([], array_unique($failures), "a v2 component is described as carrying style slots:\n"
            . implode("\n", array_unique($failures)));

        // FAIL-CLOSED, BUT GATED. Zero positive slot claims is a legitimate end state once
        // `grid` is rebuilt and nothing has slots to describe — so the floor applies only
        // while a slot-carrying component still exists. Unconditional here would fail for
        // being correct; absent entirely (the first cut) let the guard scan ONE sentence out
        // of sixteen and report success.
        if (\pp_ai_live_slot_types() !== []) {
            $this->assertGreaterThan(
                4,
                $scanned,
                'the reverse check scanned fewer positive slot claims than the corpus carries. '
                . 'RE-MEASURED over the FINISHED corpus (the earlier 8/6/2 in this message was '
                . 'taken after only the first file was rewritten and was stale by six more): '
                . '14 sentences make a positive slot claim, 9 of those claim-clauses say the '
                . 'component declares NO slots (correct, and correctly exempt), and 5 are '
                . 'scanned — down from 16/13/3 before the rewrite, because it removed v1-era '
                . 'claims rather than because the guard narrowed. THIS NUMBER TRACKS THE '
                . 'CORPUS and is expected to fall as the last slot-carrying component is '
                . 'rebuilt; the gate above is what makes zero legitimate then. Re-measure it '
                . 'when the corpus changes rather than loosening it. If it falls without the '
                . 'corpus shrinking, the negation exemption has widened again and the guard is '
                . 'passing on an empty set rather than on clean docs'
            );
        }
    }

    // ── A CHECK THAT WAS BUILT AND REMOVED ─────────────────────────────────────────
    // THE INTERNAL COUNT CHECK WAS BUILT, RUN AGAINST THE REAL CORPUS, AND REMOVED (#1087).
    //
    // Recorded here rather than deleted silently, because "a stated count must match the
    // list beside it" is an obvious-sounding guard that someone will propose again, and it
    // was Codex's strongest plan-review finding — prose counts drift where roster guards do
    // not reach.
    //
    // It was implemented and run. It produced FOUR failures on the shipped corpus and ALL
    // FOUR were false positives, because real prose pairs a count with something the count
    // does not include:
    //
    //   "hero, section and testimonials no longer have it (and cta never did)"
    //        — three is right; the fourth name is an EXCLUSION.
    //   "widened from five components to ten in #579"
    //        — a HISTORICAL count beside a current list.
    //   "a style slot on the one v1 component left (grid's ...)"
    //        — "one ... component" and the list belong to different clauses.
    //
    // A guard that is wrong every time it fires is not a strict guard, it is a tax on
    // correct writing: the only way to satisfy it is to contort sentences that were already
    // true. And it would not have caught the defect that motivated it — "seven v2
    // components" followed by exactly seven names is internally consistent and externally
    // stale, so the check passes on the very drift it was written for.
    //
    // The sound form of this check is the ANCHORED one above, which compares a roster
    // against the REGISTRY rather than against itself. DocsCoverageTest already applies it
    // with a count included (add-component.md's "nineteen keys" plus every key), and that
    // pairing is correct today — which is the evidence that anchoring is what makes count
    // checking work.

    /**
     * PER-ROLE DETAIL IS FETCHED, NOT DUPLICATED (#1087).
     *
     * The ruling's three-way split puts per-component role detail in NEITHER surface: the
     * instruction files must tell an agent to run `wp pp schema <component>` rather than
     * carry a copy. This is the guard for that rule, and the rule is load-bearing rather
     * than tidy — every duplicated claim in this repo's model-facing set that a test did not
     * pin has drifted, and a copy inside an instruction file is a copy with no owner.
     *
     * Scoped to obligation `why` strings because those are the sentences most likely to be
     * helpfully pasted into a how-to: they read like advice. The runtime prompt is where
     * they belong, composed from the registry; a file that repeats one has forked it.
     *
     * The 40-character floor keeps a short shared phrase from reading as a duplication.
     */
    public function testNoInstructionFileDuplicatesADeclaredRoleObligation(): void
    {
        $whys = [];
        foreach (\pp_udc_obligation_groups() as $groups) {
            foreach ($groups as $group) {
                if (strlen($group['why']) >= 40) {
                    $whys[] = $group['why'];
                }
            }
        }
        $this->assertNotEmpty($whys, 'no obligation prose to check — the declarations vanished');

        $failures = [];
        foreach ($this->modelFacingFiles() as $file) {
            $text = (string) file_get_contents($file);
            foreach ($whys as $why) {
                if (str_contains($text, $why)) {
                    $failures[] = basename($file) . ' repeats an obligation the prompt already '
                        . 'derives: "' . substr($why, 0, 70) . '…"';
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "per-role obligation prose is duplicated into an instruction file:\n"
            . implode("\n", $failures)
            . "\n\nThe runtime prompt composes these from the registry. An instruction file "
            . 'should send the agent to `wp pp schema <component>` instead of carrying a copy '
            . 'that nothing keeps true.'
        );
    }

    /** Distinct registered component names mentioned in a string, in registry order. */
    private function componentNamesIn(string $text): array
    {
        $found = [];
        foreach (array_keys(\pp_get_registered_components()) as $component) {
            // Word-boundary with the repo's own spellings: bare, backticked, or quoted.
            // A bare substring test would match `section` inside `subsection` and — the
            // reason this matters here — `table` inside `tables`.
            if (preg_match('/(?<![a-z0-9_-])' . preg_quote($component, '/') . '(?![a-z0-9_-])/i', $text)) {
                $found[] = $component;
            }
        }
        return $found;
    }

    /** Split prose into sentences, keeping markdown list items whole. */
    private function sentences(string $text): array
    {
        $text = preg_replace('/```.*?```/s', ' ', $text) ?? $text;
        // MARKDOWN EMPHASIS SITS BETWEEN THE TERMINATOR AND THE SPACE. A bolded sentence
        // ends `slots.** Everything below...`, so a splitter looking for `[.!?]\s` never
        // breaks there and fuses two sentences into one. That fusion is not cosmetic: it
        // carried the component names of the SECOND sentence into the first one's claim, and
        // produced a false positive against prose that was entirely correct. Allow closing
        // emphasis marks to follow the terminator.
        $parts = preg_split('/(?<=[.!?])[*_`]{0,3}\s+|\n{2,}|\n(?=[-*|#])/', $text) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
