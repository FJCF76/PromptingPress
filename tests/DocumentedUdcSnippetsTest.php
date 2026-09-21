<?php
/**
 * EVERY `udc` MAP THE DOCUMENTATION TELLS AN AUTHOR TO WRITE IS ACCEPTED BY THE WRITE PATH.
 *
 * WHY THIS EXISTS. A component's README and its migration how-to are the only place an
 * author learns the shape of a role write. Both are prose, both are hand-edited at every
 * rebuild, and nothing checked that the JSON in them still validates. The failure is
 * silent and it lands on the author, not on us: they copy a documented block, the write is
 * refused, and the refusal names a role or a group the doc just told them to use.
 *
 * It is not hypothetical. A rebuild moves a parameter between groups, narrows a role's
 * `groups` list, or renames a role, and the doc keeps the old spelling until somebody reads
 * it closely. The READMEs for the eleven v2 components carry dozens of these blocks.
 *
 * WHAT IT DOES NOT DO. It does not check the doc is GOOD advice — that the value is the
 * one an author wants, or that the resulting band is legible. It checks only that the
 * engine would accept it, which is the half that can be checked mechanically and the half
 * a reader cannot check for themselves without a WordPress install.
 *
 * THE ONE CARVE-OUT, AND IT IS NARROWED RATHER THAN WAIVED. `background.image` takes a
 * Media Library attachment id, and the validator resolves it against the real library — so
 * every documented `"image": "42"` would be refused in a unit run for a reason that has
 * nothing to do with the doc. Rather than skipping those blocks, the id is REMOVED before
 * validation and the role is separately required to permit `background.image` at all, so a
 * doc that tells an author to set an image on a role that cannot take one still fails.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class DocumentedUdcSnippetsTest extends TestCase
{
    /** Component name -> the docs that teach it. */
    private function docsByComponent(): array
    {
        $root = dirname(__DIR__);
        $map  = [];

        foreach (glob($root . '/components/*/README.md') as $readme) {
            $component = basename(dirname($readme));
            $map[$component][] = $readme;
        }
        // `a` OR `an` — ENGLISH BROKE THIS GUARD. The glob was `howto-migrate-a-*` and the
        // pattern `howto-migrate-a-(…)`, which silently skipped
        // `howto-migrate-an-embed-band-to-v2.md`: six of the seven shipped how-tos were
        // walked and embed's was not checked against the write path at all. Found by the
        // pre-landing review, and the miss is doubly pointed — embed's how-to is the one
        // this PR edits, and a silently-skipped input is exactly the vacuity this file
        // exists to prevent. The fail-closed floors below are what would eventually have
        // caught it; the article is what caused it.
        foreach (glob($root . '/docs/howto-migrate-a*-band-to-v2.md') as $howto) {
            if (preg_match('/howto-migrate-an?-([a-z0-9-]+)-band-to-v2\.md$/', $howto, $m)) {
                $map[$m[1]][] = $howto;
            }
        }

        // THE TUTORIAL WAS NOT WALKED, AND IT IS THE FIRST DOC AN AUTHOR READS (#1079).
        //
        // The same vacuity the article bug above caused, arriving by a different route:
        // this guard's whole claim is that a `udc` map a human can copy is a map the write
        // path accepts, and the tutorial is nothing BUT maps a human is invited to copy —
        // it is the one doc written to be typed out verbatim. It was skipped because the
        // globs were named after the surfaces that existed when the guard was written.
        //
        // It is keyed to `testimonials` because that is the component the tutorial builds
        // on; a snippet there that names another component's role would fail here, which
        // is the correct outcome for a tutorial.
        $tutorial = $root . '/docs/tutorial-style-a-band-on-the-design-contract.md';
        if (is_file($tutorial)) {
            $map['testimonials'][] = $tutorial;
        }

        return $map;
    }

    /** Every ```json fenced block in a file, decoded. Undecodable blocks are reported. */
    /** Documented `--params` payloads the lifter could not read, collected per walk. */
    private array $shellPayloadDrops = [];

    private function jsonBlocks(string $path, bool $includeShellParams = false): array
    {
        $text = (string) file_get_contents($path);
        preg_match_all('/```json\n(.*?)```/s', $text, $m);
        $raws = $m[1];

        // THE MOST-COPIED EXAMPLES ARE NOT IN A ```json FENCE, and that is where the one
        // defect these walks exist to catch actually survived (#1087).
        //
        // A whole-page example is a COMMAND — `wp pp action execute create_page --params='{…}'`
        // — so it is written in a ```bash fence, and every JSON guard in this file skipped it.
        // composition.md's flagship `create_page` example is exactly that shape, and it carried
        // an undeclared `subtitle` prop on its hero band through this entire suite: the parse
        // check never saw the block, the `udc` walk never saw the block, and a planted
        // regression in it produced a PASS. It is also the single most likely block in the
        // corpus to be copied verbatim by an agent.
        //
        // So the payload of a `--params='…'` argument is lifted out of bash fences and walked
        // like any other block.
        //
        // ONLY PAYLOADS THAT PARSE ARE LIFTED, and that restraint is deliberate rather than
        // lazy. Two shapes here are not defects and must not fail: a doc legitimately ELIDES
        // part of a long command (`--params='{"title":"Product Launch", ... }'`), which is
        // clearer prose and not valid JSON; and this extractor reads the payload with a lazy
        // match to the next single quote, which truncates a multi-line payload that contains
        // an apostrophe. Failing on either would be asserting about this regex rather than
        // about the docs. What is lifted is a strict ADDITION of subjects — composition.md's
        // 648-byte flagship `create_page` command among them, which is the block the
        // `subtitle` defect lived in.
        if ($includeShellParams) {
            $raws = array_merge($raws, $this->shellParamPayloads($path, $text));
        }

        $out = [];
        foreach ($raws as $i => $raw) {
            $raw     = trim($raw);
            $decoded = json_decode($raw, true);

            // A FRAGMENT IS A LEGITIMATE DOC STYLE and must not read as broken JSON.
            // Several READMEs show two or three keys of a larger map rather than a whole
            // document (`"_band": { … }, "heading": { … }`), which is clearer prose and
            // invalid JSON on its own. Completing it with braces is the same thing a
            // reader does in their head. A block that parses neither way IS broken, and
            // the assertion below still catches it.
            if ($decoded === null && $raw !== 'null') {
                $decoded = json_decode('{' . $raw . '}', true);
            }

            // TWO MORE LEGITIMATE DOC STYLES, both found in the shipped instruction files
            // when this walk was widened to cover them (#1087). Neither doc is wrong; the
            // extractor was narrow, and a block it cannot read is a block nothing checks.
            //
            // A BLOCKQUOTED FENCE. style-component.md puts a `udc` example inside a `>`
            // quote to set it off from the surrounding prose. The `>` prefixes are markdown,
            // not JSON.
            if ($decoded === null && str_starts_with($raw, '>')) {
                $unquoted = preg_replace('/^>[ \t]?/m', '', $raw) ?? $raw;
                $decoded  = json_decode($unquoted, true) ?? json_decode('{' . $unquoted . '}', true);
            }

            // A LIST FRAGMENT. composition.md shows two sibling bands, comma-separated,
            // without the enclosing brackets — the same abbreviation the `{}` completion
            // above already accepts for an object fragment, one container up.
            if ($decoded === null) {
                $decoded = json_decode('[' . $raw . ']', true);
            }

            $out[] = ['index' => $i, 'raw' => $raw, 'json' => $decoded];
        }
        return $out;
    }

    /**
     * Pull every (component, udc map) pair out of one decoded block.
     *
     * A documented block is one of three shapes and all three appear in the shipped docs:
     * a bare `udc` map, a single band (`{component, props, udc}`), or a whole composition
     * (a LIST of bands). A list is walked so a multi-band example is covered too — the
     * `$fallback` component only applies to the bare-map shape, where the file says which
     * component it is about and the JSON does not.
     */
    private function udcMapsIn($json, string $fallback): array
    {
        if (!is_array($json)) {
            return [];
        }

        if (array_is_list($json)) {
            $found = [];
            foreach ($json as $entry) {
                if (is_array($entry) && isset($entry['udc']) && is_array($entry['udc'])) {
                    $component = is_string($entry['component'] ?? null) ? $entry['component'] : $fallback;
                    $found[]   = [$component, $entry['udc']];
                }
            }
            return $found;
        }

        if (isset($json['udc']) && is_array($json['udc'])) {
            $component = is_string($json['component'] ?? null) ? $json['component'] : $fallback;
            return [[$component, $json['udc']]];
        }

        // A band shape with no `udc` key is a props example, not a styling example.
        if (isset($json['component']) || isset($json['props'])) {
            return [];
        }

        // THE CHROME SHAPE. nav's and footer's styling is the `pp_site_udc` site option,
        // which is keyed BY COMPONENT (`{"nav": {...}, "footer": {...}}`) rather than
        // being one band's map. Their READMEs document it in that shape, so a block whose
        // top-level keys are all component names is unwrapped one level. Detected by the
        // registry rather than by a hard-coded pair, so a third chrome component would be
        // covered the day it lands.
        $keys = array_keys($json);
        if ($keys !== [] && !array_filter($keys, static fn ($k) => pp_udc_component_roles((string) $k) === [])) {
            $found = [];
            foreach ($json as $componentKey => $componentMap) {
                if (is_array($componentMap)) {
                    $found[] = [(string) $componentKey, $componentMap];
                }
            }
            return $found;
        }

        // Anything else MAY be a bare `udc` map for the file's own component — the docs
        // show them that way constantly — but a component doc also carries CLI envelopes
        // (`{"action": "update_component", "style": {...}}`, the route for clearing a
        // retired slot off an aged page) and token maps, which are not role writes and
        // must not be judged as if they were.
        //
        // "NAMES AT LEAST ONE REAL ROLE" WAS NOT ENOUGH, and the second-pass review proved
        // it. A SINGLE-ROLE example — the commonest doc shape there is — whose one role
        // name is misspelt names no real role at all, so the block was skipped entirely
        // and the typo went unseen. That hole is the exact converse of the one the rule
        // was chosen to avoid: requiring EVERY key to be a role would skip a map naming
        // `heading` beside a misspelt `headding`, and requiring ONE would skip a map whose
        // only key is `headding`.
        //
        // So a block is skipped only when it is RECOGNISABLY NOT a role map — every key is
        // a known non-role shape. Component docs carry two: CLI envelopes
        // (`{"action": "update_component", "style": {…}}`, the documented route for
        // clearing a retired slot off an aged page) and design-token maps (`--token`
        // keys). Everything else is judged, so `{"nunber": {…}}` now fails by name rather
        // than vanishing.
        $roles        = pp_udc_component_roles($fallback);
        $envelopeKeys = [
            'action', 'post_id', 'component_index', 'style', 'recipe', 'params',
            'run_id', 'key', 'value', 'expected_version',
        ];

        $recognisedNonRole = true;
        foreach (array_keys($json) as $key) {
            $key = (string) $key;
            if (isset($roles[$key])) {
                return [[$fallback, $json]];
            }
            if (!in_array($key, $envelopeKeys, true) && !str_starts_with($key, '--')) {
                $recognisedNonRole = false;
            }
        }

        return $recognisedNonRole ? [] : [[$fallback, $json]];
    }

    /**
     * Strip attachment ids out of `background.image` and report which roles carried one.
     *
     * @return array{0:array,1:array<int,array{0:string,1:string}>}  [cleaned map, [role, group] pairs]
     */
    private function stripBackgroundImages(array $map): array
    {
        $carried = [];
        foreach ($map as $role => $groups) {
            if (!is_array($groups)) {
                continue;
            }
            if (isset($groups['background']['image'])) {
                $carried[] = [(string) $role, 'background'];
                unset($map[$role]['background']['image']);
                if ($map[$role]['background'] === []) {
                    unset($map[$role]['background']);
                }
            }
        }
        return [$map, $carried];
    }

    public function testEveryDocumentedJsonBlockIsValidJson(): void
    {
        $checked = 0;
        foreach ($this->docsByComponent() as $component => $files) {
            foreach ($files as $file) {
                foreach ($this->jsonBlocks($file) as $block) {
                    $this->assertNotNull(
                        $block['json'],
                        sprintf(
                            "%s block %d is fenced as ```json and does not parse:\n%s",
                            basename($file),
                            $block['index'],
                            substr($block['raw'], 0, 400)
                        )
                    );
                    $checked++;
                }
            }
        }
        // 51 today (47 before the `an-embed` glob fix restored the seventh how-to); see
        // the sibling floor below for why these track the real count.
        $this->assertGreaterThan(44, $checked, 'the doc walk stopped finding JSON blocks');
    }

    public function testEveryDocumentedUdcMapIsAcceptedByTheWritePath(): void
    {
        $checked = 0;

        foreach ($this->docsByComponent() as $fallback => $files) {
            foreach ($files as $file) {
                foreach ($this->jsonBlocks($file) as $block) {
                    foreach ($this->udcMapsIn($block['json'], $fallback) as [$component, $map]) {
                        // A doc for a component still on style slots has no roles to
                        // validate against; the UDC engine refuses the component itself,
                        // which is a different (and already-tested) claim.
                        if (pp_udc_component_roles($component) === []) {
                            continue;
                        }

                        [$clean, $withImages] = $this->stripBackgroundImages($map);

                        foreach ($withImages as [$role, $group]) {
                            $permitted = pp_udc_component_roles($component)[$role]['groups'] ?? [];
                            $this->assertContains(
                                $group,
                                $permitted,
                                sprintf(
                                    '%s block %d tells an author to set a background image on '
                                    . '`%s` -> `%s`, a group that role does not permit',
                                    basename($file),
                                    $block['index'],
                                    $role,
                                    $group
                                )
                            );
                        }

                        if ($clean === []) {
                            continue;
                        }

                        $result = pp_udc_validate_map($clean, $component);
                        $this->assertNull(
                            $result,
                            sprintf(
                                "%s block %d documents a `%s` write the engine REFUSES: %s\nMap: %s",
                                basename($file),
                                $block['index'],
                                $component,
                                $result instanceof \WP_Error ? $result->get_error_message() : 'unknown',
                                json_encode($clean)
                            )
                        );
                        $checked++;
                    }
                }
            }
        }

        // Fail-closed. A walk that stops finding documented maps — a fence style changing,
        // a docs directory moving — must not read as compliance.
        // FAIL-CLOSED AT THE REAL COUNT (48 today), not at a token floor. 15 was low enough
        // that two thirds of the corpus could stop being scanned unnoticed — the
        // understated-floor defect this PR fixed in the emit tests and then repeated here.
        // The `45` this comment carried was measured before the bash-fence lifter landed.
        $this->assertGreaterThan(
            45,
            $checked,
            'the doc walk stopped finding `udc` maps; it is passing on a fraction of the corpus'
        );
    }

    // ── The model-facing surfaces (#1087) ──────────────────────────────────────
    //
    // The walk above covers the surfaces a HUMAN reads: component READMEs, the migration
    // how-tos, the tutorial. It never covered the two surfaces a MODEL reads — the runtime
    // system prompt and the shipped instruction files — which is the larger blind spot,
    // because a refused example in those is followed by an agent rather than a person.

    /** The instruction files a filesystem-capable agent executes. */
    private function instructionFiles(): array
    {
        $files = glob(dirname(__DIR__) . '/ai-instructions/*.md') ?: [];
        $this->assertNotEmpty($files, 'the ai-instructions directory is the model-facing corpus');
        return $files;
    }

    /**
     * EVERY JSON BLOCK IN THE INSTRUCTION FILES PARSES (#1087).
     *
     * A block that does not parse is a block nothing downstream can check, so this is the
     * gate that makes the validation below meaningful rather than optimistic.
     */
    public function testEveryInstructionFileJsonBlockParses(): void
    {
        $checked = 0;
        foreach ($this->instructionFiles() as $file) {
            foreach ($this->jsonBlocks($file, true) as $block) {
                $this->assertNotNull(
                    $block['json'],
                    sprintf(
                        "%s block %d is fenced as ```json and parses in none of the documented "
                        . "shapes (whole document, object fragment, blockquoted, list fragment):\n%s",
                        basename($file),
                        $block['index'],
                        substr($block['raw'], 0, 400)
                    )
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(54, $checked, 'the instruction-file walk stopped finding blocks'); // 57 today
    }

    /**
     * EVERY `udc` MAP AN INSTRUCTION FILE TELLS AN AGENT TO WRITE IS ACCEPTED (#1087).
     *
     * Only SELF-IDENTIFYING maps are validated — a block that names its component. An
     * instruction file is not about one component the way a README is, so there is no
     * honest fallback to attribute a bare map to, and guessing one would produce refusals
     * that say more about the guess than about the doc.
     */
    public function testEveryInstructionFileUdcMapIsAcceptedByTheWritePath(): void
    {
        $checked = 0;
        foreach ($this->instructionFiles() as $file) {
            foreach ($this->jsonBlocks($file, true) as $block) {
                foreach ($this->selfIdentifyingUdcMaps($block['json']) as [$component, $map]) {
                    if (\pp_udc_component_roles($component) === []) {
                        continue;
                    }
                    [$clean] = $this->stripBackgroundImages($map);
                    if ($clean === []) {
                        continue;
                    }
                    $result = \pp_udc_validate_map($clean, $component);
                    $this->assertNull(
                        $result,
                        sprintf(
                            "%s block %d documents a `%s` write the engine REFUSES: %s\nMap: %s",
                            basename($file),
                            $block['index'],
                            $component,
                            $result instanceof \WP_Error ? $result->get_error_message() : 'unknown',
                            json_encode($clean)
                        )
                    );
                    $checked++;
                }
            }
        }
        // 19 self-identifying maps today, and the floor sits just under it rather than at a
        // token value. The nineteenth arrived when the extractor learned to read a
        // `--params='…'` payload out of a ```bash fence, which is where composition.md's
        // flagship whole-page `create_page` example lives.
        //
        // WHY IT MOVED FROM 6, and it is the reason this floor matters more than most: the
        // walk this test consumes was widened in #1087 from two levels to a full recursive
        // descent, so it now reaches the bands nested under a `composition` key — the shape a
        // whole-page `create_page` example uses, which is where the richest maps live. MEASURED
        // both ways over the SAME corpus: the widened walk finds 18, the old two-level walk
        // finds 14. With the floor at `> 6`, reverting the widening — silently losing
        // composition.md's flagship example and build-landing-page.md's five-band recipe, the
        // exact maps it was written to reach — left this suite GREEN. A floor has to sit close
        // enough to the real count to notice the change it is guarding.
        $this->assertGreaterThan(17, $checked, 'the instruction-file `udc` walk lost subjects');
    }

    /**
     * JSON payloads lifted out of `--params='…'` inside ```bash fences.
     *
     * THE MOST-COPIED EXAMPLES ARE NOT IN A ```json FENCE, and that is where the one defect
     * these walks exist to catch actually survived (#1087). A whole-page example is a COMMAND
     * — `wp pp action execute create_page --params='{…}'` — so it is written in a bash fence,
     * and every JSON guard in this file skipped it. composition.md's flagship `create_page`
     * example is exactly that shape and carried an undeclared `subtitle` prop on its hero band
     * through the entire suite. It is also the single most likely block in the corpus to be
     * copied verbatim by an agent.
     *
     * OPT-IN at the call site, because it is only sound where attribution is. The
     * instruction-file walks take SELF-IDENTIFYING bands, so a lifted payload is either a band
     * or ignored. The per-component doc walk instead attributes a bare map to the component
     * its file is about — and a lifted `import_media` payload (`{"url": "…"}`) attributed to
     * `cta` produced a confident refusal about a doc that is correct.
     *
     * ONLY PAYLOADS THAT PARSE ARE RETURNED. A doc legitimately ELIDES part of a long command
     * (`{"title":"Product Launch", ... }`), which is clearer prose and not valid JSON. A
     * payload that fails to parse with NO elision truncated instead — the capture is lazy to
     * the next single quote, so an apostrophe in the copy cuts it short, and that same
     * apostrophe breaks the documented command for anyone who runs it. Those are collected in
     * $shellPayloadDrops and asserted on, because a silently skipped example is exactly the
     * blind spot this lifter was added to remove.
     */
    private function shellParamPayloads(string $path, string $text): array
    {
        $payloads = [];
        preg_match_all("/```bash\n(.*?)```/s", $text, $shell);
        foreach ($shell[1] as $script) {
            if (!preg_match_all("/--params='(.*?)'/s", $script, $params)) {
                continue;
            }
            foreach ($params[1] as $payload) {
                if (is_array(json_decode($payload, true))) {
                    $payloads[] = $payload;
                    continue;
                }
                $elided = str_contains($payload, '...') || str_contains($payload, "\u{2026}");
                if (!$elided) {
                    $this->shellPayloadDrops[] = basename($path) . ': a `--params` payload '
                        . 'does not parse and carries no elision, so it truncated — almost '
                        . 'always at an apostrophe in the copy, which ALSO breaks the '
                        . 'documented command for anyone who runs it. Payload: ' . $payload;
                }
            }
        }
        return $payloads;
    }

    /**
     * Component-attributed maps in one decoded block, at ANY depth.
     *
     * ONE WALKER, TWO KEYS. The `udc` and `props` walks were written as separate copies of
     * the same recursive descent, and the copies bought nothing: byte-identical logic fails
     * together rather than independently, and every message is built at the call site, not
     * here. What they bought was independent DRIFT — which is precisely the defect this walk
     * was widened to fix. The `udc` walk went from two levels to full recursion because the
     * richest examples sit nested under a `composition` key, and the second walk then had to
     * be hand-copied to match. A third would have to be too.
     *
     * WALK THE WHOLE DOCUMENT, not just its first two levels (#1087). Taking the block and,
     * if it is a LIST, its entries, reaches a lone band object and a bare composition array —
     * and misses the shape a doc most naturally uses to show a WHOLE PAGE: the params object
     * for `create_page`. A planted `"nonesuch"` role in build-landing-page.md passed the
     * suite, which is how this was found.
     *
     * The predicate is what keeps a recursive walk honest: an entry must carry BOTH a string
     * `component` and an array under the requested key before it is taken, so descending into
     * unrelated structure yields nothing rather than guesses. A taken node is still descended
     * into, so no role or group may be named `component` or `udc` — measured: zero
     * within-block duplicate takes across the corpus.
     */
    private function selfIdentifying($json, string $key): array
    {
        $found = [];
        $take  = static function ($entry) use (&$found, $key) {
            if (is_array($entry)
                && isset($entry['component'], $entry[$key])
                && is_string($entry['component'])
                && is_array($entry[$key])) {
                $found[] = [$entry['component'], $entry[$key]];
            }
        };
        $walk = static function ($node) use (&$walk, $take) {
            if (!is_array($node)) {
                return;
            }
            $take($node);
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($json);
        return $found;
    }

    /** Component-attributed `udc` maps in one decoded block, at any depth. */
    private function selfIdentifyingUdcMaps($json): array
    {
        return $this->selfIdentifying($json, 'udc');
    }

    /** Component-attributed `props` maps in one decoded block, at any depth. */
    private function selfIdentifyingBands($json): array
    {
        return $this->selfIdentifying($json, 'props');
    }

    /**
     * EVERY `udc` EXAMPLE IN THE RUNTIME PROMPT IS ACCEPTED BY THE WRITE PATH (#1087).
     *
     * The prompt carries its examples as INLINE backticked JSON rather than fenced blocks,
     * so it needs its own extractor — which is why nothing validated them before. They are
     * the highest-stakes examples in the repo: the chat AI has no tools, cannot read a
     * schema, and copies the shape it was shown.
     */
    public function testEveryRuntimePromptUdcExampleIsAcceptedByTheWritePath(): void
    {
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $prompt  = \pp_ai_system_prompt();
        $checked = 0;

        foreach ($this->promptUdcExamples($prompt) as [$component, $map, $raw]) {
            [$clean] = $this->stripBackgroundImages($map);
            if ($clean === []) {
                continue;
            }
            $result = \pp_udc_validate_map($clean, $component);
            $this->assertNull(
                $result,
                sprintf(
                    "the runtime prompt shows a `%s` example the engine REFUSES: %s\nExample: %s",
                    $component,
                    $result instanceof \WP_Error ? $result->get_error_message() : 'unknown',
                    $raw
                )
            );
            $checked++;
        }

        // 4 attributable examples today.
        $this->assertGreaterThan(3, $checked, 'the prompt example extractor lost subjects');
    }

    /**
     * The `udc` examples in the prompt, each attributed to a component.
     *
     * Attribution is the hard half: the prompt is one long string, so an example's subject
     * comes from the roles it names. A map is attributed to the component that declares
     * EVERY role in it, and skipped when that is ambiguous or unknown — a placeholder like
     * `{"<role>": {"<group>": ...}}` must not be read as a real example.
     *
     * @return array<int, array{0: string, 1: array, 2: string}>
     */
    private function promptUdcExamples(string $prompt): array
    {
        // TWO SHAPES, because the prompt writes its examples both ways: a whole object
        // (`{"nav": {...}}`) and a bare key-and-value (`"udc": {...}`). The second was the
        // one the first cut missed, and it is the shape MOST of the band examples use — so
        // a pattern that only matched a leading brace validated the chrome example and
        // almost nothing else.
        preg_match_all('/`((?:"udc":\s*)?\{.*?\})`/s', $prompt, $m, PREG_SET_ORDER);

        // A THIRD SHAPE: BARE, UNFENCED CHROME MAPS. The prompt is assembled from more than
        // its own prose — action descriptions in lib/actions.php are embedded verbatim, and
        // one of them carries a chrome example written without backticks. It was therefore
        // invisible here while being fully visible to the model, and it shipped the exact
        // defect this class exists to catch: no `submenu` (dropdown links at 1.01:1) and
        // `@color-accent` on three roles over a dark header (3.21:1, which the prompt's own
        // neighbouring prose names as under AA). A guard that reads a subset of what the
        // model reads certifies the subset.
        //
        // Anchored on the two chrome component names rather than on a brace, because a bare
        // `{` in prose is not a JSON boundary and this must not start guessing. BRACE-BALANCED
        // rather than regex: a lazy `.*?` stops at the first `}` and truncates every one of
        // these (measured: four matches, none of them valid JSON, all silently skipped —
        // which is how the first attempt at this check passed while catching nothing).
        foreach (['{"nav":', '{"footer":'] as $needle) {
            $from = 0;
            while (($at = strpos($prompt, $needle, $from)) !== false) {
                $depth = 0;
                $end   = null;
                for ($i = $at, $n = strlen($prompt); $i < $n; $i++) {
                    if ($prompt[$i] === '{') {
                        $depth++;
                    } elseif ($prompt[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }
                if ($end === null) {
                    break;
                }
                $m[]  = [null, substr($prompt, $at, $end - $at + 1)];
                $from = $end + 1;
            }
        }

        $out = [];
        foreach ($m as $hit) {
            $raw     = preg_replace('/^"udc":\s*/', '', $hit[1]) ?? $hit[1];
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            // The chrome shape is keyed by component; a band shape is a bare role map.
            $candidates = isset($decoded['udc']) && is_array($decoded['udc'])
                ? [$decoded['udc']]
                : [$decoded];
            foreach ($candidates as $map) {
                foreach ($this->attribute($map) as $pair) {
                    $out[] = [$pair[0], $pair[1], $hit[1]];
                }
            }
            unset($raw);
        }
        return $out;
    }

    /** @return array<int, array{0: string, 1: array}> */
    private function attribute(array $map): array
    {
        // Chrome: `{"nav": {...}, "footer": {...}}`.
        $chrome = [];
        foreach ($map as $key => $value) {
            if (is_string($key) && is_array($value) && \pp_udc_is_chrome($key)) {
                $chrome[] = [$key, $value];
            }
        }
        if ($chrome !== []) {
            return $chrome;
        }

        $roles = array_keys($map);
        if ($roles === []) {
            return [];
        }
        // A placeholder, not an example.
        foreach ($roles as $role) {
            if (!is_string($role) || !preg_match('/^[a-z_][a-z0-9-]*$/', $role)) {
                return [];
            }
        }

        // AND EVERY VALUE MUST LOOK LIKE A GROUP MAP. This is the guard that separates a
        // real example from a FRAGMENT that happens to use a role name as a key — the
        // prompt shows `{"columns": 4}` and `{"columns": {"d": 4, "p": 1}}` to explain the
        // `columns` PARAMETER, and `columns` is also a role on `section`. Attributing those
        // to section and validating them produces a refusal that says nothing about the
        // documentation and everything about the extractor.
        $groupKeys = array_keys(\pp_udc_groups());
        foreach ($map as $groups) {
            if (!is_array($groups) || $groups === []) {
                return [];
            }
            foreach (array_keys($groups) as $group) {
                $known = in_array($group, $groupKeys, true)
                    || in_array($group, ['_preset', '_css'], true)
                    || (is_string($group) && str_starts_with($group, ':'));
                if (!$known) {
                    return [];
                }
            }
        }

        $owners = [];
        foreach (array_keys(\pp_get_registered_components()) as $component) {
            $declared = \pp_udc_component_roles($component);
            if ($declared === []) {
                continue;
            }
            $all = true;
            foreach ($roles as $role) {
                if (!array_key_exists($role, $declared)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $owners[] = $component;
            }
        }
        // Ambiguous (several components declare all these roles) or unknown: skip rather
        // than guess. A wrong attribution produces a refusal that says nothing about the doc.
        return count($owners) === 1 ? [[$owners[0], $map]] : [];
    }

    /**
     * A DOCUMENTED EXAMPLE THAT DARKENS A SURFACE MUST STILL CLEAR AA (#1087).
     *
     * The regression this exists for shipped in the runtime prompt itself: a chrome example
     * with a `#101828` fill and `@color-accent` on three states, 3.21:1 against its own
     * fill. The rest states passed at 16.70:1, so it read as correct — a contrast defect in
     * an example is invisible to every check that asks only whether the write is accepted.
     *
     * SCOPED TO WHAT IS DECIDABLE. Only a map that sets BOTH a literal `_band` fill and a
     * text colour in the SAME map is checked, because only then is the pairing stated rather
     * than inferred. `@token` references are resolved against the real token registry;
     * anything that does not resolve to a hex is skipped rather than guessed at.
     */
    public function testNoDocumentedExamplePutsTextUnderTheContrastFloorOnItsOwnFill(): void
    {
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $tokens  = \pp_design_tokens();
        $checked = 0;

        $sources = [['the runtime prompt', $this->promptUdcExamples(\pp_ai_system_prompt())]];
        foreach ($this->instructionFiles() as $file) {
            $maps = [];
            foreach ($this->jsonBlocks($file, true) as $block) {
                foreach ($this->selfIdentifyingUdcMaps($block['json']) as [$component, $map]) {
                    $maps[] = [$component, $map, 'block ' . $block['index']];
                }
            }
            $sources[] = [basename($file), $maps];
        }

        foreach ($sources as [$label, $examples]) {
            foreach ($examples as [$component, $map, $raw]) {
                $bandFill = $this->hex($map['_band']['background']['fill'] ?? null, $tokens);
                foreach ($map as $role => $groups) {
                    if ($role === '_band' || !is_array($groups)) {
                        continue;
                    }
                    // THE ROLE'S OWN FILL WINS. Measuring every role's ink against the BAND
                    // fill is wrong in both directions, and the red-team pass proved both with
                    // plants. False positive: a dark band with a light-filled button and dark
                    // ink ON that button — the shape components/cta/README.md documents — was
                    // failed at a bogus 1.00:1, which would have made the first realistic
                    // dark-band example in the prose PR impossible to write without weakening
                    // this guard. False negative: a role that sets its own fill and an
                    // illegible ink on it, with no `_band` fill anywhere, was skipped entirely.
                    //
                    // AND WHERE THE ROLE SETS NONE, THE NEAREST ANCESTOR ROLE'S FILL WINS
                    // OVER THE BAND'S — including a fill the ancestor never had to be given,
                    // because it ships one as a schema DEFAULT. The red team found the gap
                    // with the flagship dark-band example in style-component.md: it darkened
                    // `_band` and re-inked `quote`, `author` and `meta`, but `card`
                    // (`.testimonials__item`) DEFAULTS to `background.fill: @color-surface`
                    // and physically contains all three. Rendered, the quote measured
                    // 1.01:1 — near-white on near-white — and this walk called it 16.70:1
                    // because it measured against the band. A guard that resolves the wrong
                    // surface is worse than no guard: it certifies the defect.
                    $fill = $this->hex($groups['background']['fill'] ?? null, $tokens)
                        ?? $this->ancestorRoleFill($component, $role, $map, $tokens)
                        ?? $bandFill;
                    if ($fill === null) {
                        continue;
                    }
                    foreach ($this->inksIn($groups['typography'] ?? []) as $rawInk) {
                        $ink = $this->hex($rawInk, $tokens);
                        if ($ink === null) {
                            continue;
                        }
                        $checked++;
                        $ratio = self::contrastRatio($ink, $fill);
                        $this->assertGreaterThanOrEqual(
                            4.5,
                            $ratio,
                            sprintf(
                                '%s documents a %s example putting %s on %s for role `%s` — '
                                . '%.2f:1, under the 4.5:1 AA floor. Example: %s',
                                $label,
                                $component,
                                $ink,
                                $fill,
                                $role,
                                $ratio,
                                is_string($raw) ? $raw : json_encode($map)
                            )
                        );
                    }
                }
            }
        }

        // 26 pairings today. This number has now been wrong twice in one PR, both times in
        // the same direction, so it is worth saying why: `21` was the count measured while
        // `ancestorRoleFill()` was STUBBED during a probe, and it was left standing as
        // "today's" — which put the floor at `> 19`, BELOW the stubbed count, so stubbing the
        // helper dropped the class to 21 and still passed. A floor picked against a number
        // taken with the mechanism disabled cannot detect the mechanism being disabled.
        // Measured with everything live, and the floor sits just under it.
        $this->assertGreaterThan(
            24,
            $checked,
            'the contrast walk found fewer background+ink pairings than the corpus carries; '
            . 'the extractor or the ink resolver stopped reaching most of its subjects'
        );
    }

    /**
     * The fill of the role that renders this one inside it, where one exists.
     *
     * DECLARED, NOT INFERRED FROM SELECTORS. Guessing containment from a shared BEM prefix
     * treated cta's `button` as an ancestor of cta's `heading` — they are siblings — and
     * measured the heading's ink against the button's fill at a bogus 2.11:1. Selector text
     * cannot prove containment; only the markup can, so every entry below was read out of the
     * component's own PHP.
     *
     * KEYED BY THE CONTAINED ROLE, innermost container FIRST. table's `header` sits inside
     * `head` which sits inside `table`, and all three declare a fill — so an outer-first walk
     * answered with the wrong surface, certifying a dark table head at 16.13:1 while it
     * rendered at 1.08:1. The surface a role sits on is the NEAREST one that has a fill.
     *
     * Only containers that BOTH wrap text roles AND carry a fill need listing: a leaf that
     * happens to declare one (an `eyebrow` pill, an outline button) contains nothing, and
     * `_band` is already the caller's fallback.
     */
    private const ROLE_CONTAINERS = [
        // component => [contained role => [containers, innermost first]]
        'testimonials' => [
            'quote' => ['card'], 'author' => ['card'], 'meta' => ['card'],
            'attribution' => ['card'], 'avatar' => ['card'],
        ],
        'section' => [
            'panel-heading' => ['panel'], 'panel-body' => ['panel'], 'panel-row' => ['panel'],
            'panel-row-label' => ['panel'], 'panel-row-value' => ['panel'], 'panel-cta' => ['panel'],
            'panel-list' => ['panel'],
        ],
        'faq' => [
            'question' => ['item'], 'question-open' => ['item'],
            'answer' => ['item'], 'answer-link' => ['item'],
        ],
        'table' => [
            'header' => ['head', 'table'],
            'row' => ['table'], 'cell' => ['table'], 'cell-link' => ['table'],
        ],
        'nav' => ['link' => ['submenu'], 'link-current' => ['submenu']],
    ];

    /** The nearest containing role's fill: authored map first, else its schema default. */
    private function ancestorRoleFill(string $component, string $role, array $map, array $tokens): ?string
    {
        $containers = self::ROLE_CONTAINERS[$component][$role] ?? [];
        $roles      = \pp_udc_component_roles($component);

        foreach ($containers as $container) {
            // An authored fill wins over that container's default: an author who filled the
            // card has already answered the question this is asking. But an OUTER container
            // never outranks an inner one, authored or not — hence innermost-first, returning
            // on the first container that resolves to anything at all.
            $authored = $this->hex($map[$container]['background']['fill'] ?? null, $tokens);
            if ($authored !== null) {
                return $authored;
            }
            $default = $this->hex($roles[$container]['defaults']['background']['fill'] ?? null, $tokens);
            if ($default !== null) {
                return $default;
            }
        }
        return null;
    }

    /**
     * The contrast model resolves the surface a role ACTUALLY sits on (#1087).
     *
     * Pinned directly because the corpus cannot pin it: stubbing ancestorRoleFill() to return
     * null dropped this class from 26 subjects to 21 and cleared every floor, so the 1.01:1
     * regression it was written to catch was reintroducible with the suite green. A helper
     * whose failure only SHRINKS a count needs its own assertions.
     *
     * @dataProvider ancestorFillProvider
     */
    public function testTheContrastModelResolvesTheNearestContainingFill(
        string $component,
        string $role,
        array $map,
        ?string $expected,
        string $why
    ): void {
        // Real tokens, because the defaults this resolves are `@token` references and the
        // whole point of the helper is what they resolve TO.
        $this->assertSame(
            $expected,
            $this->ancestorRoleFill($component, $role, $map, \pp_design_tokens()),
            $why
        );
    }

    public static function ancestorFillProvider(): array
    {
        return [
            'a contained role falls back to its container DEFAULT' => [
                'testimonials', 'quote', [], '#f4f7fb',
                'the quote renders inside `card`, which ships @color-surface as its own default '
                . '— this is the exact resolution the 1.01:1 defect needed',
            ],
            'an authored container fill beats the default' => [
                'testimonials', 'quote', ['card' => ['background' => ['fill' => '#1d2939']]], '#1d2939',
                'an author who filled the card has answered the question',
            ],
            'the INNERMOST container wins over an outer one' => [
                'table', 'header', [], '#f4f7fb',
                'header sits in head (@color-surface) which sits in table (@color-bg); an '
                . 'outer-first walk answered #ffffff and certified a dark table head at 16:1',
            ],
            'an outer container cannot outrank an inner one even when authored' => [
                'table', 'header', ['table' => ['background' => ['fill' => '#000000']]], '#f4f7fb',
                'the surface is still `head`',
            ],
            'a sibling is not a container' => [
                'cta', 'heading', ['button' => ['background' => ['fill' => '#9dafee']]], null,
                'cta`s button and heading are siblings — the prefix heuristic read this as '
                . 'containment and failed a correct example at 2.11:1',
            ],
            'a role with no container resolves to nothing' => [
                'testimonials', 'heading', [], null,
                'the caller then falls back to the band fill',
            ],
            'an unknown component resolves to nothing' => [
                'grid', 'heading', [], null,
                'grid declares no roles at all',
            ],
        ];
    }

    /**
     * EVERY ink a typography map declares, at any depth (#1087).
     *
     * The first cut of the contrast walk read exactly two places: the resting `color` and
     * `:hover.color`. That is half-blind, and the pre-landing testing specialist proved it
     * by planting a 1.3:1 `:focus-visible` colour on the prompt's own dark-band example —
     * the example whose prose calls dark-on-dark "the single most common way this goes
     * wrong" — and watching the whole suite stay green.
     *
     * A colour is ink wherever it is declared. The contract permits three states, and a
     * breakpoint map on every value, so the only honest walk is recursive.
     *
     * NOT array_walk_recursive, and the reason is worth keeping: that helper visits LEAVES
     * only, so a breakpoint map under `color` (`"color": {"d": "#111", "p": "#222"}`) is
     * descended INTO and its members arrive keyed `d` and `p` — never as ink. The first
     * version of this fix used it and silently skipped every responsive colour, which the
     * unchanged assertion count is what exposed. This walk tests the KEY on the way down and
     * flattens a map found there into its members, so each breakpoint's colour is measured
     * on its own.
     *
     * @return array<int, mixed> Raw values; the caller resolves and filters them.
     */
    private function inksIn($typography): array
    {
        if (!is_array($typography)) {
            return [];
        }
        $inks = [];
        foreach ($typography as $key => $value) {
            if ($key === 'color') {
                // A literal, or a breakpoint map whose members are each a colour.
                foreach (is_array($value) ? $value : [$value] as $member) {
                    if (!is_array($member)) {
                        $inks[] = $member;
                    }
                }
                continue;
            }
            // A state map (`:hover`, `:focus-visible`, `:active`) or any future nesting.
            if (is_array($value)) {
                $inks = array_merge($inks, $this->inksIn($value));
            }
        }
        return $inks;
    }

    /**
     * EVERY PROP AN INSTRUCTION FILE TELLS AN AGENT TO WRITE IS DECLARED (#1087).
     *
     * The sibling check above runs each documented band's `udc` map through the write path.
     * Nothing ran its `props`, and the gap was not theoretical: this PR's own rewrite of
     * composition.md's flagship whole-page example wrote `"subtitle"` on a hero band. hero
     * declares `subheading`; `subtitle` is a retired legacy key with no alias surface (#604),
     * so the documented command is refused with `unknown_prop` and creates nothing — and the
     * suite was green twice over, because the block's `udc` map was valid AND the block sits
     * in a ```bash fence that no walk here used to read.
     *
     * A documented command that cannot run is worse than a missing one: an agent executes it,
     * gets a refusal for a shape the docs handed it, and cannot tell whether the doc or the
     * engine is wrong.
     */
    public function testEveryInstructionFilePropKeyIsDeclaredByItsComponent(): void
    {
        $checked = 0;
        $errors  = [];

        foreach ($this->instructionFiles() as $file) {
            foreach ($this->jsonBlocks($file, true) as $block) {
                foreach ($this->selfIdentifyingBands($block['json']) as [$component, $props]) {
                    // RESOLVE THROUGH THE REGISTRY, not by pasting a documented string into a
                    // path. `$component` comes out of decoded markdown with only an is_string()
                    // check, so `"component": "../ai-instructions"` would resolve outside
                    // components/. The realism is nil — repo-controlled input, CI-only, and the
                    // file is merely json_decode()d — but every sibling helper in this suite
                    // goes through the registry and this was the one place that did not.
                    if (!\pp_component_exists($component)) {
                        continue;
                    }
                    $path = dirname(__DIR__) . "/components/{$component}/schema.json";
                    if (!is_file($path)) {
                        continue;
                    }
                    $schema = json_decode((string) file_get_contents($path), true);
                    if (!is_array($schema) || !isset($schema['props']) || !is_array($schema['props'])) {
                        continue;
                    }
                    $declared = array_keys($schema['props']);
                    $retired  = array_keys(\pp_component_retired_props($component));
                    foreach (array_keys($props) as $key) {
                        $checked++;
                        if (in_array($key, $declared, true)) {
                            continue;
                        }
                        $errors[] = sprintf(
                            '%s block %d: `%s` documents prop `%s`, which the component %s. Declared: %s',
                            basename($file),
                            $block['index'],
                            $component,
                            $key,
                            in_array($key, $retired, true)
                                ? 'RETIRED (the write is refused with `retired_prop`)'
                                : 'does not declare (the write is refused with `unknown_prop`)',
                            implode(', ', $declared)
                        );
                    }
                }
            }
        }

        $this->assertSame([], $errors, "an instruction file documents a prop the write path refuses:\n"
            . implode("\n", $errors));

        $this->assertSame([], $this->shellPayloadDrops, "a documented command's `--params` "
            . "payload could not be read, and an apostrophe is why:\n"
            . implode("\n", $this->shellPayloadDrops));

        // FAIL-CLOSED, with the floor under the measured count so a walk that stops finding
        // bands cannot pass by asserting on nothing.
        // 98 prop keys across the documented bands today, and the floor sits just under it.
        // `> 80` let the whole bash-fence lifter — which contributes 17 of those 98 — be
        // reverted and land on 81, passing by one.
        $this->assertGreaterThan(95, $checked, 'the documented-prop walk lost its subjects');
    }


    /**
     * The self-identifying walk reaches a band at ANY depth (#1087).
     *
     * Pinned directly, for the same reason the ink walk below is: a regression here does not
     * move any count the corpus-walking tests report. MEASURED: the old two-level walk finds
     * 14 maps in today's corpus and the widened one finds 18, so with the floor where it was
     * a full revert of this walk left every downstream test green. The floor is tightened now,
     * but a floor is a smoke alarm — this is the pin that says what the walk must actually do.
     *
     * FOUR PROPERTIES ACROSS SIX CASES: the nested `composition` shape this widening exists
     * for, the two shapes that already worked (a bare band, a bare list), the predicate's
     * refusal of a malformed entry (two cases — a non-string `component`, a non-array `udc`),
     * and the no-double-count property (a taken node is still descended into, so a role or
     * group named `component` or `udc` would be the way that could break).
     *
     * @dataProvider selfIdentifyingWalkProvider
     */
    public function testTheSelfIdentifyingWalkReachesABandAtAnyDepth(array $doc, array $expected, string $why): void
    {
        $this->assertSame($expected, $this->selfIdentifyingUdcMaps($doc), $why);
    }

    public static function selfIdentifyingWalkProvider(): array
    {
        $map = ['heading' => ['typography' => ['color' => '#111111']]];
        return [
            'nested under composition (the create_page shape the two-level walk missed)' => [
                ['title' => 'A page', 'composition' => [['component' => 'hero', 'props' => [], 'udc' => $map]]],
                [['hero', $map]],
                'a band one level down under `composition` must be found: this is the shape '
                . 'composition.md and build-landing-page.md use for a whole-page example',
            ],
            'a bare band object (already worked)' => [
                ['component' => 'cta', 'udc' => $map],
                [['cta', $map]],
                'the block IS the band',
            ],
            'a bare list of bands (already worked)' => [
                [['component' => 'hero', 'udc' => $map], ['component' => 'cta', 'udc' => $map]],
                [['hero', $map], ['cta', $map]],
                'a composition array at the top level',
            ],
            'component is not a string' => [
                ['component' => ['hero'], 'udc' => $map],
                [],
                'the predicate takes only a STRING component, so descending into unrelated '
                . 'structure yields nothing rather than a guess',
            ],
            'udc is not an array' => [
                ['component' => 'hero', 'udc' => 'none'],
                [],
                'the predicate takes only an ARRAY udc',
            ],
            'one band yields exactly one pair' => [
                ['composition' => [['component' => 'hero', 'udc' => $map]]],
                [['hero', $map]],
                'the walk descends into a node it has already taken, so a band must not be '
                . 'counted twice',
            ],
        ];
    }

    /**
     * The ink walk sees every state and every breakpoint (#1087).
     *
     * Pinned directly because the shipped corpus happens to declare its example inks only at
     * rest — so a regression here would not move any count in the walk above, and the guard
     * would go quietly blind again exactly as it was found.
     *
     * @dataProvider inkWalkProvider
     */
    public function testTheInkWalkSeesEveryStateAndBreakpoint(array $typography, array $expected, string $why): void
    {
        $this->assertSame($expected, $this->inksIn($typography), $why);
    }

    public static function inkWalkProvider(): array
    {
        return [
            'resting colour' => [['color' => '#111111'], ['#111111'], 'the simple case'],
            'hover' => [[':hover' => ['color' => '#222222']], ['#222222'], 'a hover colour is ink'],
            'focus-visible' => [
                [':focus-visible' => ['color' => '#2a2a2a']], ['#2a2a2a'],
                'the state the first cut was blind to — a 1.3:1 plant here stayed green',
            ],
            'active' => [[':active' => ['color' => '#333333']], ['#333333'], 'and the third state'],
            'breakpoint map' => [
                ['color' => ['d' => '#111111', 'p' => '#222222']], ['#111111', '#222222'],
                'each breakpoint is its own colour on its own band; array_walk_recursive missed both',
            ],
            'breakpoint map inside a state' => [
                [':hover' => ['color' => ['d' => '#444444', 'p' => '#555555']]], ['#444444', '#555555'],
                'both dimensions at once, which is what the contract actually permits',
            ],
            'everything at once' => [
                ['color' => '#f7f8fa', ':active' => ['color' => '#333333'], ':hover' => ['color' => ['d' => '#111111', 'p' => '#222222']]],
                ['#f7f8fa', '#333333', '#111111', '#222222'],
                'no ink left behind',
            ],
            'non-colour parameters are not ink' => [
                ['size' => '19px', 'weight' => '600'], [], 'only colours are measured for contrast',
            ],
            'not an array' => [[], [], 'a role with no typography contributes nothing'],
        ];
    }

    /**
     * THE CONTRAST MODEL, pinned in BOTH directions (#1087).
     *
     * The red-team pass found the walk measuring every role's ink against the BAND fill and
     * ignoring a fill the role declares itself, which is wrong twice over. Both of its plants
     * ship here as fixtures, because a guard whose false-POSITIVE rate is unmeasured is the
     * one that gets weakened later to make a legitimate example pass.
     *
     * The must-pass case matters most: it is the shape components/cta/README.md already
     * documents, and it is the first realistic dark-band example the prose PR has to write.
     * Under the old model that example failed at a bogus 1.00:1, so the only ways forward
     * would have been weakening this guard, deleting the example, or contorting it.
     *
     * @dataProvider contrastModelProvider
     */
    public function testTheContrastModelResolvesTheFillPerRole(array $map, ?string $expectFailRole, string $why): void
    {
        $tokens = \pp_design_tokens();
        $bandFill = $this->hex($map['_band']['background']['fill'] ?? null, $tokens);
        $failed = null;
        foreach ($map as $role => $groups) {
            if ($role === '_band' || !is_array($groups)) {
                continue;
            }
            $fill = $this->hex($groups['background']['fill'] ?? null, $tokens) ?? $bandFill;
            if ($fill === null) {
                continue;
            }
            foreach ($this->inksIn($groups['typography'] ?? []) as $rawInk) {
                $ink = $this->hex($rawInk, $tokens);
                if ($ink !== null && self::contrastRatio($ink, $fill) < 4.5) {
                    $failed = (string) $role;
                }
            }
        }
        $this->assertSame($expectFailRole, $failed, $why);
    }

    public static function contrastModelProvider(): array
    {
        return [
            'light button on a dark band is LEGIBLE' => [
                [
                    '_band'   => ['background' => ['fill' => '#0a0a12']],
                    'heading' => ['typography' => ['color' => '#f2eee5']],
                    'button'  => ['background' => ['fill' => '#f2eee5'], 'typography' => ['color' => '#0a0a12']],
                ],
                null,
                'dark ink on the BUTTON\'s own light fill is correct; the old model called it 1.00:1',
            ],
            'role-own fill with illegible ink is CAUGHT even with no band fill' => [
                ['button' => ['background' => ['fill' => '#ff5c2e'], 'typography' => ['color' => '#f2eee5']]],
                'button',
                'a real 2.66:1 pairing the old model skipped entirely, because no `_band` fill existed',
            ],
            'band fill still governs a role that declares none' => [
                [
                    '_band' => ['background' => ['fill' => '#101828']],
                    'body'  => ['typography' => ['color' => '#1a1f2e']],
                ],
                'body',
                'the original behaviour must survive: a role with no fill of its own sits on the band',
            ],
            'a state ink is measured too' => [
                [
                    '_band' => ['background' => ['fill' => '#101828']],
                    'link'  => ['typography' => ['color' => '#f7f8fa', ':focus-visible' => ['color' => '#141a28']]],
                ],
                'link',
                'the state the walk was blind to before, on the fill the role actually sits on',
            ],
        ];
    }

    /** A literal hex, or a hex an `@token` resolves to. Null when it is neither. */
    private function hex($value, array $tokens): ?string
    {
        // A BREAKPOINT MAP IS A REAL FILL, and returning null for one made the ancestor
        // resolution silently inert exactly where it was most needed. nav's `submenu` ships
        // `{"d": "@color-surface", "p": "transparent"}`, so a dark-header example that
        // re-inks `link` renders its dropdown links at 1.01:1 on desktop — the same defect,
        // the same number, as the testimonials card — and the container map entry for it
        // existed but never fired because the fill was a map rather than a string.
        //
        // The DESKTOP tier is the one to resolve: it is the base, carries no media query, and
        // is the tier every documented example is written against. A tier that resolves to
        // `transparent` is correctly not a fill and falls through to null.
        if (is_array($value)) {
            $value = $value['d'] ?? null;
        }
        if (!is_string($value)) {
            return null;
        }
        if (str_starts_with($value, '@')) {
            $value = $tokens['--' . substr($value, 1)]['value'] ?? '';
        }
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null;
    }

    private static function contrastRatio(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $out = 0.0;
            foreach ([[0, 0.2126], [2, 0.7152], [4, 0.0722]] as [$offset, $weight]) {
                $channel = hexdec(substr($hex, $offset, 2)) / 255;
                $channel = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
                $out += $channel * $weight;
            }
            return $out;
        };
        $one = $lum($a);
        $two = $lum($b);
        return $one > $two ? ($one + 0.05) / ($two + 0.05) : ($two + 0.05) / ($one + 0.05);
    }
}
