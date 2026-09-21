<?php
/**
 * tests/PreviewErrorActionabilityTest.php — the chat preview's failure payload
 * tells the author whether there is a move left (#625).
 *
 * The chat paints a failed step with one of three classes and writes a matching
 * sentence in the status bar (ppChatGetErrorStepClass / ppChatGetStatusMessage,
 * assets/js/pp-ai-chat.js). `pp-ai-step-impossible` is a claim about CAPABILITY —
 * "there is nothing to change here" — so it must only be painted when the payload
 * names no next action at all. Before #625 it was painted on ANY `invalid_style_slot`
 * without a cross-component hint, which is where a mistyped slot name lands: the
 * author was told the change wasn't possible while the slot they meant sat in
 * `alternatives` in the very same response.
 *
 * WHAT #1101 LEFT OF THAT. The style-slot engine was retired with its last consumer,
 * so the near-miss branch this file was written around — a mistyped slot name on a
 * component that declares slots — has no reachable input any more and its three tests
 * went with it. What REMAINS is the half that got sharper rather than smaller: the two
 * branches that return BEFORE any slot work, and which are now the only branches a real
 * `style_component` call can reach.
 *
 *   pp_preview_action('style_component')                  [lib/actions.php]
 *     ├─ target unresolvable  → component_not_found   ─┐  the two REACHABLE outcomes;
 *     └─ component declares 0 slots → no_style_slots  ─┘  no component declares a slot,
 *                                                         so nothing gets past them
 *                                        │
 *   _pp_build_friendly_error()                            [lib/ai-chat.php]
 *     └─ alternatives = []  → and the JS must NOT read that as "impossible"
 *
 * THE JS CLAIM IS UNCHANGED AND IS THE REASON THIS FILE SURVIVES. `pp-ai-step-impossible`
 * must still be reserved for a payload with no next action, and `no_style_slots` is not
 * one: its message names the component's roles and the two actions that carry a `udc`
 * map, which is a very actionable answer delivered with an empty `alternatives` list.
 * An empty list is exactly what #625 taught the JS not to read as a dead end.
 *
 * Pages are authored through the real surfaces (pp_create_page + the
 * update_composition ACTION), and the rejection is produced by the real preview
 * action, paired with the builder exactly as the AJAX handler pairs them — the
 * handler body is a closure registered through add_action, a no-op in this bootstrap.
 */

use PHPUnit\Framework\TestCase;

class PreviewErrorActionabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // NO FIXTURE THEME, AND THAT IS THE POINT (#1101). Both surviving tests run
        // against the SHIPPED registry, because both outcomes are now reachable there:
        // every composable component declares zero style slots, so `no_style_slots` is
        // what an ordinary `hero` answers. Until #1101 this file needed two different
        // fixture roots — `ppfixture` to produce a slot rejection at all, and a synthetic
        // zero-slot component on top of it, because the only shipped components with no
        // slots were nav and footer, which the composition validator refuses to place.
        // The retirement removed the need for both.
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    /**
     * THE TARGET-NOT-FOUND ARM OF _pp_build_friendly_error(), RE-HOMED AT #1101.
     *
     * It came from tests/FriendlyErrorSlotContextTest.php, which this PR deleted whole on
     * the reasoning that every method there was about `pp_rejected_slot_context()`. This one
     * never called it: it hand-builds the WP_Error and needs no slot-declaring component, so
     * the "provably dead by its own guard" argument did not reach it. Deleting it left the
     * arm mutable with the whole suite green — `if (false && _pp_component_target_not_found(…))`
     * broke nothing. Caught by a review specialist that mutation-proved the gap.
     *
     * This file is the right home: it already owns the claim that a stale id is reported as
     * `component_not_found` rather than as a styling problem, and this is the same claim one
     * layer down, at the reporting surface rather than the action.
     *
     * WHY THE CODE IS `component_not_found` AND NOT WHAT THE PRODUCER STAMPED. The arm used
     * to echo `invalid_style_slot` back. That was harmless while the code meant one thing.
     * It stopped being harmless when #1101 narrowed the code to mean an AGED BAND, because
     * the chat's status bar reads the code and would then tell an author whose id was stale
     * to clear styling off a band that does not exist.
     */
    public function testAnUnresolvableIdIsReportedAsNotFoundRatherThanAsStyling(): void
    {
        $post_id = $this->authorPage('No context, bad id', [
            ['component' => 'section', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hi', 'body' => 'Body text']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "section" has no style slot "--section-bgs".'),
            ['post_id' => $post_id, 'component_id' => 'pp-nosuchid', 'style' => ['--section-bgs' => '#111']]
        );

        $this->assertStringContainsString('couldn\'t find that component', $friendly['user_message']);
        $this->assertSame([], $friendly['alternatives']);
        $this->assertSame(
            'component_not_found',
            $friendly['error_code'],
            'the reported code must be what happened, not what the producer stamped — the chat '
            . 'status bar reads it, and `invalid_style_slot` now means an aged band\'s stored map'
        );
        $this->assertStringNotContainsString(
            'old system',
            $friendly['user_message'],
            'and it must not offer the aged-band repair for a band that was never found'
        );
    }

    /**
     * AN OUT-OF-RANGE INDEX IS A DIFFERENT DOOR TO THE SAME PROBLEM, and it does NOT reach
     * the arm above — the target-not-found answer fires only for a bad `component_id`. So
     * this rejection reaches the message composer with nothing resolved, and what it must
     * not do is make a confident claim about a component that does not exist.
     *
     * Re-homed from tests/FriendlyErrorMessageBoundTest.php, also deleted whole, also
     * unrecorded. The guard hole itself is pre-existing and still open; what is pinned here
     * is that the message does not exploit it.
     */
    public function testAnOutOfRangeIndexIsNotToldAnythingConfidentAboutTheBand(): void
    {
        $post_id = $this->authorPage('Empty map', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "ghost" has no style slot "--ghost-zzz".'),
            ['post_id' => $post_id, 'component_index' => 7, 'style' => ['--ghost-zzz' => '#111']]
        );

        $this->assertSame([], $friendly['alternatives']);
        $this->assertStringContainsString('couldn\'t tell which component', $friendly['user_message']);
        // It must not quote the rejected slot name back as something a band refused, and it
        // must not name a band, because no band was resolved.
        $this->assertStringNotContainsString('"--ghost-zzz"', $friendly['user_message']);
        $this->assertStringNotContainsString('"ghost" band', $friendly['user_message']);
        $this->assertLessThan(600, mb_strlen($friendly['user_message']), 'and it stays readable in the chat column');
    }

    /**
     * Authors a page through the real write path: pp_create_page for the post,
     * the update_composition ACTION (schema-validated) for the composition.
     */
    private function authorPage(string $title, array $composition): int
    {
        $post_id = pp_create_page($title);
        $this->assertIsInt($post_id, 'Page creation must succeed.');

        $written = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $composition,
        ]);
        $this->assertTrue($written['ok'], 'Fixture composition must pass the authoring surface: ' . ($written['error'] ?? ''));

        return $post_id;
    }


    // ── Why "names nothing" is not where a real rejection lands ───────────

    public function testAComponentWithNoStyleSlotsReportsNoStyleSlotsInstead(): void
    {
        // ON A SHIPPED COMPONENT SINCE #1101, and the swap is not cosmetic. This used to
        // build a synthetic `plainbox` with an empty slot map, because no PLACEABLE
        // shipped component had one — and a synthetic component declares no `roles`
        // either, so _pp_no_style_slots_clause() answered it with the bare
        // "Available slots: (none)" branch. That is the branch for a component with no
        // styling surface at all, and it is NOT what any real author now meets.
        //
        // `hero` is a real v2 band, so this exercises the branch that actually ships: the
        // one that names the component's own roles and the two actions that carry a `udc`
        // map. Asserting that is the whole reason the empty `alternatives` list below is
        // safe for the JS to receive.
        $post_id = $this->authorPage('No slots', [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hi']],
        ]);
        $this->assertSame([], pp_get_style_slots('hero'), 'premise: no shipped component declares a style slot');

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => '#111111'],
        ];

        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('no_style_slots', $error->get_error_code());

        $friendly = _pp_build_friendly_error($error, $params);
        $this->assertSame('no_style_slots', $friendly['error_code']);
        $this->assertSame([], $friendly['alternatives']);

        // THE ANTI-VACUITY HALF. An empty `alternatives` list is only acceptable because
        // the message itself carries the next action; without this, the assertion above
        // would be satisfied by a refusal that told the author nothing at all — which is
        // precisely the "impossible" reading #625 exists to prevent.
        $this->assertStringContainsString('`udc` map', $friendly['raw_error']);
        $this->assertStringContainsString('update_composition', $friendly['raw_error']);
        $this->assertStringContainsString('cta', $friendly['raw_error'], 'it names the component\'s own roles');
    }

    public function testAStaleComponentIdIsReportedAsComponentNotFoundNotAsAnInvalidSlot(): void
    {
        // #625 reads the target-not-found branch of _pp_build_friendly_error()'s
        // invalid_style_slot case as a second instance of the mislabelling. It is not
        // reachable from the chat: the target is resolved before any slot work, and a
        // stale id fails with its own code, which is painted pp-ai-step-failed — never
        // the grey "impossible". That branch answers only unstamped, hand-built errors
        // (pinned in tests/FriendlyErrorSlotContextTest.php).
        $post_id = $this->authorPage('Stale id', [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hi']],
        ]);

        $params = [
            'post_id'      => $post_id,
            'component_id' => 'pp-nosuchid',
            'style'        => ['--hero-bg' => '#111111'],
        ];

        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame(
            'component_not_found',
            $error->get_error_code(),
            'A stale id fails with its own code, never invalid_style_slot.'
        );

        // Carried through the reporting layer, because that is where the class is
        // decided: `component_not_found` falls to the builder's default branch, which
        // the chat paints pp-ai-step-failed — the generic red, never the grey the
        // issue reads this case as getting.
        $friendly = _pp_build_friendly_error($error, $params);
        $this->assertSame('component_not_found', $friendly['error_code']);
        $this->assertSame([], $friendly['alternatives']);
        $this->assertSame([], (array) $friendly['cross_component_hints']);
        $this->assertStringNotContainsString(
            'couldn\'t find that component',
            $friendly['user_message'],
            'The invalid_style_slot target-not-found branch is not on this path.'
        );
    }
}
