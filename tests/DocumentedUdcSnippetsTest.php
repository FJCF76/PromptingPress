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
    private function jsonBlocks(string $path): array
    {
        $text = (string) file_get_contents($path);
        preg_match_all('/```json\n(.*?)```/s', $text, $m);

        $out = [];
        foreach ($m[1] as $i => $raw) {
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
        // FAIL-CLOSED AT THE REAL COUNT (45 today; 42 before the `an-embed` glob fix), not
        // at a token floor. 15 was low enough that two thirds of the corpus could stop
        // being scanned unnoticed — the understated-floor defect this PR fixed in the emit
        // tests and then repeated here.
        $this->assertGreaterThan(
            38,
            $checked,
            'the doc walk stopped finding `udc` maps; it is passing on a fraction of the corpus'
        );
    }
}
