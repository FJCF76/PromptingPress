<?php
/**
 * tests/BatchRestoreSeoValidationBypassTest.php — the batch rollback's SEO-metadata restore
 * is no longer blocked by current validation rules (#875, per the #233 contract).
 *
 * THE BUG THIS PINS. `_pp_restore_batch_snapshot_report()` (lib/actions.php) restored a
 * page's captured SEO metadata through `pp_update_seo_meta()`, which re-validates its input
 * before writing. A `_pp_seo_meta` row holding a `meta_description` longer than today's
 * 320-character cap — written raw, or written before the cap existed — round-tripped
 * verbatim out of `pp_get_seo_meta()` into the snapshot and was then REFUSED on the way
 * back:
 *
 *     stored (400 chars) ──▶ snapshot ──▶ batch writes 'new' ──▶ step fails
 *                                                                    │
 *                       _pp_validate_seo_meta() says too_long ◀──────┘
 *                                    │
 *                       nothing written; 'new' stays live, and since #857 the
 *                       rollback NAMES the loss ("its SEO metadata was NOT rolled back")
 *
 * Naming it was #857's job and #857 did it. Undoing it is this one's: the value that WAS
 * stored has to be the value that comes back. #233 states the rule for every restore in
 * this theme — a restore replays stored state and is never blocked by current rules — and
 * two sibling arms in the SAME function already apply it (the site_options restore bypasses
 * pp_update_site_option()'s create-time validator; the redirect arm shape-checks its
 * captured row without re-validating it). The SEO arm was the last one that did not.
 *
 * WHAT IS PINNED HERE, IN FOUR KINDS.
 *
 *   RED PROOF — fails against the pre-fix source. Every value rule the restore used to trip
 *   on: the 320-char meta_description cap, the canonical_url URL rule, and the 200-char cap
 *   on each of seo_title / og_title / twitter_title. Each asserts the STORED BYTES came back,
 *   not merely that the report was empty — an empty report with the batch's value still live
 *   is the exact failure #857 removed, and asserting only the report would readmit it.
 *
 *   THE AUTHORING PATH (14.1) — the same restore driven through the real
 *   `pp_ai_execute_batch()` envelope rather than a hand-built bundle, because the bug was
 *   reported from a live release smoke and the envelope is what the smoke saw.
 *
 *   BOTH DIRECTIONS — the half that keeps this a carve-out rather than a hole. A FORWARD
 *   over-length write still refuses, at the writer and at the action, and the bypass is not
 *   reachable by omission: `pp_update_seo_meta()` keeps its two-argument signature and the
 *   third argument is required on the private door.
 *
 *   THE GUARDS THAT STAY — only VALUE validation is bypassed. A baseline naming meta this
 *   theme does not own is still refused (the key allowlist is authorization, not content),
 *   and a page that is not there is still refused. Those are what keep the restore from
 *   being a write primitive into arbitrary post meta.
 */

use PHPUnit\Framework\TestCase;

class BatchRestoreSeoValidationBypassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
        ];
        // The #833 batch gate reads the postmeta row through the handle and fails closed
        // without one, so every batch below needs it. Production always has one.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** A snapshot bundle with every key the restorer reads (mirrors _pp_snapshot_batch_targets()). */
    private function bundle(array $overrides = []): array
    {
        return $overrides + [
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [],
            'redirects_written'   => [],
        ];
    }

    /** A real page with a readable composition, as the snapshotter would find it. */
    private function page(): int
    {
        $id = pp_create_page('Target', 'draft');
        pp_update_page_slug($id, 'target');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Before']]]);
        return $id;
    }

    /** The captured pre-batch state of a page, in the shape the restorer consumes. */
    private function capturedState(int $post_id): array
    {
        $post = get_post($post_id);
        return [
            'title'       => $post->post_title,
            'slug'        => $post->post_name,
            'status'      => $post->post_status,
            'composition' => pp_get_composition($post_id),
            'seo_meta'    => pp_get_seo_meta($post_id),
        ];
    }

    /**
     * Seeds a stored SEO row that today's rules reject, captures it the way the snapshotter
     * does, then lets the "batch" overwrite it — the exact sequence the issue describes.
     *
     * @return array{0:int,1:array} the page id and its captured pre-batch state
     */
    private function pageWithStoredSeo(array $stored): array
    {
        $page = $this->page();
        update_post_meta($page, '_pp_seo_meta', wp_json_encode($stored));
        $state = $this->capturedState($page);
        update_post_meta($page, '_pp_seo_meta', wp_json_encode(['meta_description' => 'what the batch wrote']));
        return [$page, $state];
    }

    // ── 1. red proof: every value rule the restore used to trip on ───────────────

    /**
     * THE SMOKE'S OWN REPRODUCTION. A 500-character staged description is what the v1.19.0
     * release smoke put on a live page; 400 is the same class and the same refusal. Before
     * this issue the rollback reported "its SEO metadata was NOT rolled back" and left the
     * batch's value in place. The report is checked too, because the two claims have to
     * agree: a restore that worked has nothing to say about itself.
     */
    public function testAStoredOverLongDescriptionIsRestoredVerbatim(): void
    {
        $stored = str_repeat('a', 400);
        [$page, $state] = $this->pageWithStoredSeo(['meta_description' => $stored]);

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame($stored, pp_get_seo_meta($page)['meta_description'], 'the stored bytes came back');
        $this->assertSame([], $errors, 'and a restore that worked reports nothing');
    }

    /**
     * THE SECOND RULE THE ISSUE NAMES. A stored canonical_url that filter_var() rejects today
     * is still the URL this page had before the batch. It comes back as the bytes that were
     * stored — and it is still escaped at its sink (core's rel_canonical() runs esc_url()),
     * so restoring it re-creates the pre-batch state rather than opening an output hole.
     */
    public function testAStoredInvalidCanonicalUrlIsRestoredVerbatim(): void
    {
        [$page, $state] = $this->pageWithStoredSeo(['canonical_url' => 'not-a-url']);

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame('not-a-url', pp_get_seo_meta($page)['canonical_url'], 'the stored URL came back');
        $this->assertSame([], $errors, 'and nothing was reported');
    }

    /**
     * THE THREE TITLE CAPS, ALL AT ONCE, because they are one rule applied three times and a
     * bypass that missed any of them would leave that field un-restorable on its own.
     */
    public function testStoredOverLongTitlesAreAllRestoredVerbatim(): void
    {
        $long = str_repeat('t', 300);
        [$page, $state] = $this->pageWithStoredSeo([
            'seo_title'     => $long,
            'og_title'      => $long,
            'twitter_title' => $long,
        ]);

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $seo = pp_get_seo_meta($page);
        $this->assertSame($long, $seo['seo_title'], 'seo_title came back');
        $this->assertSame($long, $seo['og_title'], 'og_title came back');
        $this->assertSame($long, $seo['twitter_title'], 'twitter_title came back');
        $this->assertSame([], $errors, 'and nothing was reported');
    }

    // ── 2. the authoring path (14.1): the real batch envelope ───────────────────

    /**
     * DRIVEN THROUGH `pp_ai_execute_batch()`, not through a hand-assembled bundle, because
     * that is the surface the release smoke exercised and the one an operator meets. A real
     * update_seo_meta step lands, a later step fails, and the rollback has to put the stored
     * 400-character description back — with the envelope's own `rollback_errors` empty, which
     * is the claim the chat card renders.
     */
    public function testTheRealBatchRollbackRestoresAnOverLongDescription(): void
    {
        $stored = str_repeat('a', 400);
        $page   = $this->page();
        update_post_meta($page, '_pp_seo_meta', wp_json_encode(['meta_description' => $stored]));

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_seo_meta', 'params' => [
                'post_id' => $page,
                'meta'    => ['meta_description' => 'Short replacement'],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok'], 'the batch failed');
        $this->assertTrue($batch['rolled_back'], 'and rolled back');
        $this->assertTrue($batch['steps'][0]['ok'], 'the SEO step really did land first');
        $this->assertSame(
            $stored,
            pp_get_seo_meta($page)['meta_description'],
            'the pre-batch description is back on the page'
        );
        $this->assertSame([], $batch['rollback_errors'], 'and the envelope claims a clean revert truthfully');
    }

    // ── 3. both directions: the forward path keeps every rule ───────────────────

    /**
     * THE HALF THAT KEEPS THIS A CARVE-OUT. Offered as NEW input, an over-length description
     * is still refused by the writer — the bypass is not a general relaxation of the rule,
     * it is a restore replaying state this site already held.
     */
    public function testAForwardOverLongDescriptionWriteIsStillRefused(): void
    {
        $page   = $this->page();
        $result = pp_update_seo_meta($page, ['meta_description' => str_repeat('x', 321)]);

        $this->assertInstanceOf('WP_Error', $result, 'the forward write refuses');
        $this->assertSame('meta_description_too_long', $result->get_error_code());
        $this->assertSame('', pp_get_seo_meta($page)['meta_description'], 'and wrote nothing');
    }

    /** The same rule at the action, which is the surface the chat and the CLI reach. */
    public function testTheForwardActionStillRefusesAnInvalidCanonicalUrl(): void
    {
        $page   = $this->page();
        $result = pp_execute_action('update_seo_meta', [
            'post_id' => $page,
            'meta'    => ['canonical_url' => 'not-a-url'],
        ]);

        $this->assertFalse($result['ok'], 'the action refuses');
        $this->assertStringContainsString('canonical_url', $result['error']);
    }

    /**
     * PREVIEW AND EXECUTE MUST STILL AGREE, and this is the pin the second door needs.
     * _pp_validate_seo_meta() takes the bypass flag DEFAULTED, so unlike the writer it can be
     * reached with the bypass by adding one argument — and the update_seo_meta action's
     * validate step is exactly where that argument would do the most damage: preview would
     * answer ok for a value execute then refuses, which is a proposal an operator approves
     * and a batch fails on. Nothing in the suite noticed that until now; every existing
     * preview pin uses a VALID value, so the validate step's value rules had no failure-path
     * test of their own on the preview side.
     */
    public function testPreviewStillRefusesWhatExecuteWouldRefuse(): void
    {
        $page = $this->page();

        // pp_preview_action() returns the WP_Error itself on a validate-stage rejection
        // (it is pp_execute_action that renders the ok:false envelope), so the refusal is
        // the TYPE here, not an 'ok' key.
        $long = pp_preview_action('update_seo_meta', [
            'post_id' => $page,
            'meta'    => ['meta_description' => str_repeat('x', 321)],
        ]);
        $this->assertInstanceOf('WP_Error', $long, 'preview refuses an over-length description');
        $this->assertSame('meta_description_too_long', $long->get_error_code());

        $url = pp_preview_action('update_seo_meta', [
            'post_id' => $page,
            'meta'    => ['canonical_url' => 'not-a-url'],
        ]);
        $this->assertInstanceOf('WP_Error', $url, 'and a malformed canonical_url');
        $this->assertSame('invalid_canonical_url', $url->get_error_code());

        $this->assertSame('', pp_get_seo_meta($page)['meta_description'], 'and previewed nothing into the row');
    }

    /**
     * NOT REACHABLE BY OMISSION. `pp_update_seo_meta()` keeps its two-argument signature, so
     * no existing caller can slide into the bypass by accident, and the private door takes
     * the flag as a REQUIRED argument rather than a defaulted one — a caller has to say it.
     */
    public function testThePublicWriterHasNoBypassArgumentAndThePrivateOneRequiresIt(): void
    {
        $public = new ReflectionFunction('pp_update_seo_meta');
        $this->assertSame(2, $public->getNumberOfParameters(), 'the forward writer takes post_id and meta, nothing else');

        $private = new ReflectionFunction('_pp_write_seo_meta');
        $this->assertSame(3, $private->getNumberOfRequiredParameters(), 'the bypass flag is required, never defaulted');
    }

    /**
     * THE SOURCE TRIPWIRE, over the WHOLE shipped theme rather than one file, because the
     * point is that exactly one call site in production asks for the bypass. A second one is
     * a second restore path at best and a hole at worst, and either way it is a decision
     * somebody has to make on purpose.
     *
     * COUNTED IN CODE, NOT IN PROSE, AND NOT BY MATCHING A CALL SHAPE. Two traps sit either
     * side of this. A regex for the literal `, true)` is defeated by every spelling that is
     * not that — a variable, a constant, a named argument, a multi-line call,
     * call_user_func(), a dynamic name — so it proves nothing. A raw substring count catches
     * all of those, but in a codebase whose house style is dense commentary it also counts
     * every DOCBLOCK that mentions the function, and then a maintainer who merely explains
     * the pair in a new comment gets told they added a route to the bypass. Tokenizing and
     * keeping only T_STRING gives the strict half of each: any executable reference in any
     * spelling lands here, and prose never does.
     */
    public function testOnlyTheBatchRollbackAsksForTheBypass(): void
    {
        $root  = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/templates/*.php') ?: [],
            glob($root . '/components/*/*.php') ?: [],
            [$root . '/functions.php']
        );

        $this->assertSame(
            [
                'actions.php' => 1, // the batch rollback's restore call, and nothing else
                'wp.php'      => 2, // the declaration and pp_update_seo_meta()'s delegation
            ],
            $this->codeReferences($files, '_pp_write_seo_meta'),
            'the function is referenced in code only where it is declared, delegated to, and'
            . ' called by the batch rollback — a new reference is a new route to the bypass'
        );

        // THE BYPASS HAS TWO DOORS, and censusing only the writer would leave the other one
        // unwatched. _pp_validate_seo_meta() takes the same flag DEFAULTED, so unlike the
        // writer it can be reached with the bypass by a caller that merely adds an argument
        // — including the update_seo_meta action's own validate step, where doing so would
        // split preview from execute (preview says ok, execute refuses) without failing a
        // single existing test. Both doors are counted here for that reason.
        $this->assertSame(
            [
                'actions.php' => 1, // the update_seo_meta action's validate step
                'wp.php'      => 2, // the declaration and _pp_write_seo_meta()'s call
            ],
            $this->codeReferences($files, '_pp_validate_seo_meta'),
            'and the validator is referenced only where it is declared and by the two paths'
            . ' that are supposed to reach it'
        );

        $actions = file_get_contents($root . '/lib/actions.php');
        $this->assertSame(
            1,
            preg_match_all('/_pp_write_seo_meta\s*\(\s*\$post_id\s*,\s*\$state\[.seo_meta.\]\s*,\s*true\s*\)/', $actions),
            'and the one call really is the per-page restore, asking for the bypass explicitly'
        );
        $this->assertSame(
            1,
            preg_match_all('/_pp_validate_seo_meta\(\$params\[.meta.\]\)/', $actions),
            'while the action validates with ONE argument — no bypass on the forward path'
        );
    }

    /** Executable references to $needle per file, ignoring comments and docblocks. */
    private function codeReferences(array $files, string $needle): array
    {
        $references = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            $count = 0;
            foreach (token_get_all($source) as $token) {
                // A string LITERAL naming the function counts too: that is how a dynamic
                // call ($fn = '_pp_write_seo_meta'; $fn(...)) or a call_user_func() would
                // spell it, and those are exactly the routes a call-shape regex misses.
                if (!is_array($token)) {
                    continue;
                }
                if (in_array($token[0], [T_STRING, T_CONSTANT_ENCAPSED_STRING], true)
                    && str_contains($token[1], $needle)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $references[basename($file)] = $count;
            }
        }
        ksort($references); // glob order is not the contract; the census is
        return $references;
    }

    // ── 4. the guards that stay: only VALUE validation is bypassed ───────────────

    /**
     * THE AUTHORIZATION HALF, AND WHY IT IS NOT "VALIDATION" IN THE SENSE #233 WAIVES. The
     * key allowlist decides which post meta this theme owns; waiving it would turn the
     * rollback into a write primitive into arbitrary `_pp_seo_meta` fields for any caller
     * that can hand it a bundle. Same posture the site_options arm takes with its whitelist:
     * the guard runs BEFORE anything else and stays.
     *
     * Unreachable through the shipped snapshotter — `pp_get_seo_meta()` intersects against
     * its five defaults — so this drives the hand-built-bundle path the restorer's own
     * docblock supports, which is also what keeps producer (14) of the rollback report
     * honest rather than dead.
     */
    public function testACapturedBaselineNamingUnownedMetaIsStillRefusedAndNamed(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        $state['seo_meta']['not_ours'] = 'value';

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $hits = array_values(array_filter(
            $errors,
            static fn($e) => str_contains($e, "Page {$page}: its SEO metadata was NOT rolled back")
        ));
        $this->assertCount(1, $hits, 'the refused restore is still named — got: ' . var_export($errors, true));
        $this->assertArrayNotHasKey(
            'not_ours',
            json_decode(get_post_meta($page, '_pp_seo_meta', true), true) ?: [],
            'and nothing unowned was written'
        );
    }

    /**
     * THE OTHER GUARD. A page that is not there is not a page this restore may write to.
     * The per-page loop already skips a vanished page before reaching the SEO write (a
     * deleted page is not a survivor, #857), so this asserts the writer's own check rather
     * than the loop's — the guard has to hold on the door, not only on the corridor.
     */
    public function testTheBypassStillRefusesAPageThatDoesNotExist(): void
    {
        $result = _pp_write_seo_meta(999999, ['meta_description' => str_repeat('a', 400)], true);

        $this->assertInstanceOf('WP_Error', $result, 'no page, no write');
        $this->assertSame('invalid_post', $result->get_error_code());
    }

    /**
     * A PAGE THAT HAD NO SEO METADATA GETS ITS EMPTINESS BACK, which is the shape the real
     * snapshotter actually produces for that page: pp_get_seo_meta() answers five EMPTY
     * STRINGS, never an absent key. So the honest assertion is not "the row is left alone" —
     * it is that the batch's description is CLEARED, because '' is what this page held and a
     * restore replays what was held. Getting this backwards would leave the batch's write
     * live on every page that had no SEO metadata before it ran.
     */
    public function testAPageThatHadNoSeoMetadataIsRestoredToEmpty(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page); // five empty strings, as the snapshotter records
        $this->assertSame('', $state['seo_meta']['meta_description'], 'premise: nothing was stored');
        update_post_meta($page, '_pp_seo_meta', wp_json_encode(['meta_description' => 'what the batch wrote']));

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame('', pp_get_seo_meta($page)['meta_description'], 'the batch write is cleared');
        $this->assertSame([], $errors, 'and clearing it back is not a failure');
    }

    /**
     * THE HAND-BUILT EDGE BESIDE IT, kept separate because it is a different claim. A bundle
     * carrying NO seo_meta keys at all cannot come from the snapshotter, and the merge
     * semantics make it a no-op: unspecified keys are left unchanged, so nothing is blanked.
     * Same degrade-don't-destroy posture the sibling arms take on a baseline they cannot use
     * — nothing to restore is never a reason to remove.
     */
    public function testABaselineNamingNoSeoKeysAtAllChangesNothing(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        $state['seo_meta'] = [];
        update_post_meta($page, '_pp_seo_meta', wp_json_encode(['meta_description' => 'left in place']));

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame([], $errors, 'nothing to restore is not a failure to restore');
        $this->assertSame(
            'left in place',
            pp_get_seo_meta($page)['meta_description'],
            'and a baseline that describes no field blanked no field'
        );
    }

    /**
     * THE ONE SHAPE THE BYPASS GENUINELY ADMITS THAT THE VALUE RULES DID NOT: a NON-STRING
     * under an allowed key. pp_get_seo_meta() passes it through (array_intersect_key does
     * not type-check), so a raw-written row can hold one and the snapshot captures it; the
     * forward path refuses it (filter_var says no for canonical_url, and strlen() TypeErrors
     * on the capped fields), and under bypass it is written back verbatim.
     *
     * PINNED AS VERBATIM ON PURPOSE, per #233 policy item 3 — never silently strip or
     * rewrite what a snapshot holds. The value was in this row before the batch and it is in
     * this row after the rollback, which is the whole definition of a restore. It is worth
     * saying plainly that such a row is ALREADY hazardous on the render path
     * (pp_seo_canonical_url_override() and pp_seo_document_title_override() both declare
     * `: string` returns): the restore reproduces that hazard, it does not introduce it, and
     * the forward-path half of the same class is tracked separately as #913.
     */
    public function testANonStringUnderAnAllowedKeyIsRestoredVerbatim(): void
    {
        [$page, $state] = $this->pageWithStoredSeo(['canonical_url' => ['not', 'a', 'string']]);
        $this->assertSame(['not', 'a', 'string'], $state['seo_meta']['canonical_url'], 'premise: it was stored');

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame(
            ['not', 'a', 'string'],
            pp_get_seo_meta($page)['canonical_url'],
            'the row is put back exactly as the page held it before the batch'
        );
        $this->assertSame([], $errors, 'and the restore did not refuse it');
    }
}
