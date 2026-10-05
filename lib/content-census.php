<?php
/**
 * lib/content-census.php — what the Layer-3 render does to STORED content, reported
 * (LAYER-3-CONTRACT.md §2.3 and the P-27 census trio, #1242 T3b).
 *
 * One report, three readers. pp_content_render_report() runs the one predicate over a
 * stored composition with its cross-band context, exactly as the render does
 * (lib/content-render.php), and says what each content prop renders as. From it:
 *
 *   - the findings (`content_stripped_at_render`, `content_duplicate_id`,
 *     `content_inline_style`), joined into _pp_composition_findings(), so `wp pp check
 *     page`, the write envelope's `findings` and the chat's report all carry them;
 *   - the census (`wp pp content census`): every composition page, every prop that renders
 *     empty, as text, or with a construct removed, with its clause. READ-ONLY;
 *   - the admin notice, fed by a SCHEDULED census run (the only writer in this file, see
 *     pp_content_census_record()).
 *
 * WHAT THE CENSUS JUDGES (routed item 7, M-22): the stored bytes, as stored. Shortcodes are
 * NOT expanded: an `embed` band's shortcode output is plugin output at the §7.5 plugin
 * boundary, disclosed by `content_plugin_output`, and is never part of the stored-content
 * judgment.
 *
 * WHEN IT RE-RUNS: `upgrader_process_complete` fires in the request that still has the OLD
 * WordPress HTML API loaded, so judging there would judge with the old parser. The hook only
 * SCHEDULES a single cron event; the census runs on a later request, with the new parser.
 */

/** The scheduled census event. */
const PP_CONTENT_CENSUS_HOOK = 'pp_content_census_run';

/** Why a census run was scheduled (the event's argument), weightiest first. */
const PP_CONTENT_CENSUS_TRIGGERS = ['core-upgrade', 'theme-version', 'content-write'];

/** The option the scheduled census writes (and the admin notice reads). */
const PP_CONTENT_CENSUS_OPTION = 'pp_content_census';

/** A census run in progress: the pages still to read, the members found so far (the cursor). */
const PP_CONTENT_CENSUS_PROGRESS_OPTION = 'pp_content_census_progress';

/** The theme version the census last ran for (a new theme version schedules one). */
const PP_CONTENT_CENSUS_VERSION_OPTION = 'pp_content_census_version';

/** User meta: the census membership the user dismissed the notice for. */
const PP_CONTENT_CENSUS_DISMISSED_META = 'pp_content_census_dismissed';

/** How a construct list is shown in one finding message. */
const PP_CONTENT_REPORT_SHOWN = 3;

/**
 * Every content value of a stored composition, finished as the render finishes it: the
 * render's own budgeted, per-value-tier index (memoized) and one finish per value with its
 * band's cross-band context. The report, the findings and the census all read this one set.
 *
 * @return array<int|string, list<array{label:string, path:string, sink:string, value:string, result:?array, unchecked:bool}>>
 */
function _pp_content_finished_values(array $items, int $post_id = 0): array {
    [$index, $counts] = _pp_content_render_index($items, $post_id);
    $out = [];
    foreach (array_keys($index) as $key) {
        $values = _pp_content_finish_band($items, $key, $index, $counts);
        if ($values !== []) {
            $out[$key] = $values;
        }
    }
    return $out;
}

/**
 * What the render does to each content prop of a stored composition.
 *
 * @param  array      $items     The composition as stored.
 * @param  int        $post_id   Its page (what its gated writes vouched for); 0 = nothing.
 * @param  array|null $finished  _pp_content_finished_values($items, $post_id), when the caller has it.
 * @return list<array{band:int|string, component:string, prop:string, path:string, sink:string, outcome:string, clause:string, losses:list<array>}>
 *         One row per prop that does NOT render as stored: outcome `empty` (a whole-prop
 *         clause: E10, P-16, M-8), `stripped` (constructs removed; clause `unfiltered_html`
 *         for what only a trusted, checked write may carry), `text` (a title or heading
 *         rendered fully escaped: it fails the predicate, clause = its first loss's; or it
 *         holds markup no 3A-gated write admitted, clause `unverified`), `unchecked` (past
 *         the render budget, shown through the pre-Layer-3 path; clause `budget`).
 */
function pp_content_render_report(array $items, int $post_id = 0, ?array $finished = null): array {
    if (!array_is_list($items)) {
        return [];
    }
    $finished ??= _pp_content_finished_values($items, $post_id);
    $vouched = pp_content_vouched($post_id)['h'];
    $rows = [];
    foreach ($finished as $key => $values) {
        $component = is_string($items[$key]['component'] ?? null) ? $items[$key]['component'] : '';
        foreach ($values as $v) {
            // title_accent is never rendered on its own: it only names text to accent.
            if ($v['path'] === 'title_accent') {
                continue;
            }
            $losses = $v['unchecked'] ? [] : $v['result']['losses'];
            $outcome = null;
            $clause = '';
            if ($v['unchecked']) {
                $outcome = 'unchecked';
                $clause = 'budget';
            } elseif ($losses !== []) {
                $whole = array_values(array_filter($losses, static fn ($l) => in_array($l['clause'], PP_CONTENT_WHOLE_PROP_CLAUSES, true)));
                $outcome = $v['sink'] === 'heading' ? 'text' : ($whole !== [] ? 'empty' : 'stripped');
                $clause = ($whole[0] ?? $losses[0])['clause'];
            } elseif ($v['sink'] === 'heading' && strpos($v['value'], '<') !== false
                && !isset($vouched[pp_content_value_hash($v['value'])])) {
                $outcome = 'text';
                $clause = 'unverified';
            }
            if ($outcome !== null) {
                $rows[] = ['band' => $key, 'component' => $component, 'prop' => $v['label'], 'path' => $v['path'],
                    'sink' => $v['sink'], 'outcome' => $outcome, 'clause' => $clause, 'losses' => $losses];
            }
        }
    }
    return $rows;
}

/** A band locator for a message: `#2`, or a quoted key. */
function _pp_content_band_label($key): string {
    return is_int($key) ? '#' . $key : '"' . _pp_content_reflect((string) $key, 64) . '"';
}

/**
 * The §2.3 / §5.1 / Δ1 findings for a stored composition (M-9 codes), all read from what the
 * render emits:
 *   content_stripped_at_render (warning)  a prop the render strips, empties or escapes
 *   content_duplicate_id       (warning)  an id the rendered content of more than one band carries
 *   content_inline_style       (info)     per band: content's style declarations, and popovers
 *
 * @return list<array{type:string, message:string, index:int|string}>
 */
function pp_content_render_findings(array $items, int $post_id = 0): array {
    if (!array_is_list($items)) {
        return [];
    }
    $finished = _pp_content_finished_values($items, $post_id);
    $out = [];
    $unchecked = [];
    foreach (pp_content_render_report($items, $post_id, $finished) as $row) {
        if ($row['outcome'] === 'unchecked') {
            $unchecked[$row['band']] = true;
            continue;
        }
        // A title that renders as text only because no gated write admitted its markup
        // renders exactly as it always did: the census lists it, nothing here asks for work.
        if ($row['losses'] === []) {
            continue;
        }
        $what = [
            'empty'    => 'renders EMPTY',
            'stripped' => 'renders without the refused constructs',
            'text'     => 'renders as plain text (escaped, nothing in it paints as markup)',
        ][$row['outcome']];
        $shown = array_slice($row['losses'], 0, PP_CONTENT_REPORT_SHOWN);
        $more = count($row['losses']) - count($shown);
        $out[] = [
            'type'    => 'content_stripped_at_render',
            'message' => sprintf(
                'Component %s ("%s") prop %s %s: %s%s. The stored content predates the Layer-3 content check; '
                . 'the rest of the band renders normally. Edit the prop to remove %s and save it (LAYER-3-CONTRACT.md §2.3).',
                _pp_content_band_label($row['band']),
                _pp_content_reflect($row['component'], 64),
                $row['prop'],
                $what,
                implode('; ', array_map(static fn ($l) => $l['message'], $shown)),
                $more > 0 ? sprintf('; %d more', $more) : '',
                $row['outcome'] === 'text' ? 'what the predicate refuses (write a literal `<` as `&lt;`)' : 'what it names'
            ),
            'index'   => $row['band'],
        ];
    }

    // One pass over the rendered html of every band: the ids it carries (content_duplicate_id:
    // the first id in document order wins, so another band's fragment links and references
    // bind elsewhere, Δ1), and its style declarations and popovers (content_inline_style, §5.1:
    // a content style outranks the band's own rules on its element; a popover is top layer, §4).
    $holders = [];
    foreach ($finished as $key => $values) {
        $declarations = 0;
        $properties = [];
        $popovers = [];
        $ids = [];
        foreach ($values as $v) {
            if ($v['unchecked'] || $v['result']['html'] === '' || strpos($v['result']['html'], '<') === false) {
                continue;
            }
            $p = new WP_HTML_Tag_Processor($v['result']['html']);
            while ($p->next_tag()) {
                $id = $p->get_attribute('id');
                if (is_string($id) && $id !== '') {
                    $ids[$id] = true;
                }
                $style = $p->get_attribute('style');
                if (is_string($style)) {
                    foreach (_pp_content_normalise_declarations($style) as $decl) {
                        $declarations++;
                        $properties[strstr($decl, ':', true) ?: $decl] = true;
                    }
                }
                if ($p->get_attribute('popover') !== null) {
                    $popovers[] = '<' . strtolower((string) $p->get_tag()) . '>';
                }
            }
        }
        foreach (array_keys($ids) as $id) {
            $holders[(string) $id][] = $key;
        }
        if ($declarations === 0 && $popovers === []) {
            continue;
        }
        $names = array_keys($properties);
        $out[] = [
            'type'    => 'content_inline_style',
            'message' => sprintf(
                'Component %s content carries %d style declaration(s)%s%s. A content style outranks the band\'s own rules on '
                . 'its element (LAYER-3-CONTRACT.md §5.1).',
                _pp_content_band_label($key),
                $declarations,
                $names === [] ? '' : ' setting ' . implode(', ', array_map(static fn ($n) => _pp_content_reflect((string) $n, 64), array_slice($names, 0, 12)))
                    . (count($names) > 12 ? sprintf(' and %d more', count($names) - 12) : ''),
                $popovers === [] ? '' : sprintf('; %d popover element(s) (%s), shown in the top layer', count($popovers),
                    implode(', ', array_slice(array_values(array_unique($popovers)), 0, 4)))
            ),
            'index'   => $key,
        ];
    }
    foreach ($holders as $id => $keys) {
        if (count($keys) < 2) {
            continue;
        }
        $out[] = [
            'type'    => 'content_duplicate_id',
            'message' => sprintf(
                'The id "%s" is authored in the content of %d bands (%s). An id is page-wide: links, label and fragment '
                . 'references bind to the first one, so give each band its own (a band-prefixed id, LAYER-3-CONTRACT.md Δ1).',
                _pp_content_reflect((string) $id, 64),
                count($keys),
                implode(', ', array_map('_pp_content_band_label', $keys))
            ),
            'index'   => $keys[1],
        ];
    }

    // content_not_checked (ruling Q2): the page holds more content than one render checks
    // (PP_CONTENT_RENDER_MAX_BYTES / _VALUES); these bands' remaining props render through
    // the pre-Layer-3 path. Fail visible, never fail slow.
    if ($unchecked !== []) {
        $keys = array_keys($unchecked);
        $out[] = [
            'type'    => 'content_not_checked',
            'message' => sprintf(
                'This page holds more content than one page view checks (%s bytes or %s values), so content in band(s) %s '
                . 'renders through the pre-Layer-3 sanitizer, unchecked by the Layer-3 content rules, and ids or references '
                . 'elsewhere on the page cannot be verified against it. Split the page, or move content to another page '
                . '(LAYER-3-CONTRACT.md §2.3, M-8).',
                number_format(PP_CONTENT_RENDER_MAX_BYTES),
                number_format(PP_CONTENT_RENDER_MAX_VALUES),
                implode(', ', array_map('_pp_content_band_label', $keys))
            ),
            'index'   => $keys[0],
        ];
    }
    return $out;
}

// ── THE CENSUS (P-27) ────────────────────────────────────────────────────────────────

/**
 * The census: every composition page's render report. READ-ONLY: it reads the stored
 * compositions and each page's verified-heading set, and writes nothing.
 *
 * @param  list<int>|null $post_ids  Only these pages; null = every composition page
 *                                   (pp_composition_pages(): every status but the trash).
 * @return list<array{post_id:int, page:string, band:int|string, component:string, prop:string, outcome:string, clause:string, construct:string}>
 */
function pp_content_census(?array $post_ids = null): array {
    if ($post_ids === null) {
        $post_ids = array_map(static fn ($p) => (int) $p['id'], pp_composition_pages(true));
    }
    $rows = [];
    foreach ($post_ids as $post_id) {
        $result = pp_get_composition_result((int) $post_id);
        if (empty($result['ok']) || !is_array($result['composition'] ?? null)) {
            continue;
        }
        $title = (string) get_the_title((int) $post_id);
        foreach (pp_content_render_report($result['composition'], (int) $post_id) as $row) {
            $first = $row['losses'][0] ?? null;
            $rows[] = [
                'post_id'   => (int) $post_id,
                'page'      => $title,
                'band'      => $row['band'],
                'component' => $row['component'],
                'prop'      => $row['prop'],
                'outcome'   => $row['outcome'],
                'clause'    => $row['clause'],
                'construct' => $first === null ? 'markup no checked write admitted' : $first['construct'],
            ];
        }
    }
    return $rows;
}

/** The notice's membership: the props that render EMPTY (P-27's "empty set"), with clause. */
function pp_content_census_members(array $rows): array {
    $members = [];
    foreach ($rows as $row) {
        if ($row['outcome'] === 'empty') {
            $members[] = $row['post_id'] . ':' . $row['band'] . ':' . $row['prop'] . ':' . $row['clause'];
        }
    }
    sort($members);
    return array_values(array_unique($members));
}

/**
 * THE ONLY WRITER: the scheduled census run, in RESUMABLE BATCHES (ruling Q2). Each run walks
 * pages until it has judged PP_CONTENT_RENDER_MAX_BYTES of stored content (always at least
 * one page), keeps its cursor in PP_CONTENT_CENSUS_PROGRESS_OPTION (the pages still to read,
 * the empty-set members found so far, the trigger) and schedules the next batch; the last
 * batch writes the finished state to PP_CONTENT_CENSUS_OPTION: the empty-set membership,
 * its page count, when and why it ran, the WordPress and theme versions, and whether the set
 * GREW against the previous finished run (a member not in it; by membership, never by count).
 * The admin notice reads only a finished state. The rows themselves are not kept: the CLI
 * census lists them on demand.
 *
 * @param string $trigger  Why it ran: one of PP_CONTENT_CENSUS_TRIGGERS, or `continue` for the
 *                         next batch of a run in progress; `direct` for a call outside cron.
 * @return array  The finished state, or the progress record when batches remain.
 */
function pp_content_census_record(string $trigger = 'direct'): array {
    $progress = get_option(PP_CONTENT_CENSUS_PROGRESS_OPTION, []);
    // A run is in progress from its first batch until it writes its finished state, even when
    // no page is left to read (a request that died judging the last page).
    $in_progress = is_array($progress) && is_array($progress['pending'] ?? null);
    if ($trigger === 'continue' && !$in_progress) {
        // A `continue` left queued after its run finished: there is nothing to continue, and
        // only a named trigger starts a run (never a second, unasked-for full census).
        $finished = get_option(PP_CONTENT_CENSUS_OPTION, []);
        return is_array($finished) ? $finished : [];
    }
    if ($in_progress && $trigger !== 'continue' && $trigger !== PP_CONTENT_CENSUS_TRIGGERS[0]) {
        // A lighter trigger while a run is in progress: keep the cursor (restarting on every
        // edit would starve a large site's census) and run again once this one finishes. A
        // core upgrade restarts it: the parser changed under the pages already read.
        $progress['rerun'] = true;
        $trigger = 'continue';
    }
    if ($trigger !== 'continue' || !$in_progress) {
        $progress = [
            'pending' => array_map(static fn ($p) => (int) $p['id'], pp_composition_pages(true)),
            'members' => [],
            'trigger' => $trigger === 'continue' ? 'direct' : $trigger,
            'started' => time(),
            'rerun'   => false,
        ];
    }
    $spent = 0;
    while ($progress['pending'] !== []) {
        $post_id = (int) $progress['pending'][0];
        $raw = get_post_meta($post_id, '_pp_composition', true);
        $size = is_string($raw) ? max(1, strlen($raw)) : 1;
        // The next page must fit what is left of this batch (a batch always reads at least one).
        if ($spent > 0 && $spent + $size > PP_CONTENT_RENDER_MAX_BYTES) {
            break;
        }
        // The cursor moves past the page BEFORE it is judged, and the next batch is already
        // queued (for the last page too, so a run that dies there is still finished): a page
        // that kills the request is skipped, never retried forever, and the run never stops
        // silently.
        array_shift($progress['pending']);
        update_option(PP_CONTENT_CENSUS_PROGRESS_OPTION, $progress, false);
        if (wp_next_scheduled(PP_CONTENT_CENSUS_HOOK, ['continue']) === false) {
            wp_schedule_single_event(time() + 10, PP_CONTENT_CENSUS_HOOK, ['continue']);
        }
        $spent += $size;
        array_push($progress['members'], ...pp_content_census_members(pp_content_census([$post_id])));
        update_option(PP_CONTENT_CENSUS_PROGRESS_OPTION, $progress, false);
    }
    if ($progress['pending'] !== []) {
        return $progress;
    }
    $members = array_values(array_unique($progress['members']));
    sort($members);
    $previous = get_option(PP_CONTENT_CENSUS_OPTION, []);
    $before = is_array($previous) && is_array($previous['members'] ?? null) ? $previous['members'] : [];
    $state = [
        'members'       => $members,
        'pages'         => count(array_unique(array_map(static fn ($m) => strstr($m, ':', true), $members))),
        'grew'          => array_diff($members, $before) !== [],
        'ran_at'        => time(),
        'trigger'       => $progress['trigger'],
        'wp_version'    => (string) get_bloginfo('version'),
        'theme_version' => defined('PP_VERSION') ? PP_VERSION : '',
    ];
    update_option(PP_CONTENT_CENSUS_OPTION, $state, false);
    update_option(PP_CONTENT_CENSUS_PROGRESS_OPTION, [], false);
    // The run is finished: its queued `continue` has nothing left to do.
    wp_clear_scheduled_hook(PP_CONTENT_CENSUS_HOOK, ['continue']);
    update_option(PP_CONTENT_CENSUS_VERSION_OPTION, $state['theme_version'], false);
    if (!empty($progress['rerun'])) {
        pp_content_census_schedule(PP_CONTENT_CENSUS_TRIGGERS[2]);
    }
    return $state;
}
add_action(PP_CONTENT_CENSUS_HOOK, 'pp_content_census_record', 10, 1);

/**
 * Schedules ONE census run on a later request. A pending run for the same or a weightier
 * trigger already covers it (each run is a whole-site walk), so it is not queued twice; a
 * core upgrade still gets its own run, so the notice can say an update made the list longer.
 */
function pp_content_census_schedule(string $trigger): void {
    $rank = array_search($trigger, PP_CONTENT_CENSUS_TRIGGERS, true);
    foreach (PP_CONTENT_CENSUS_TRIGGERS as $n => $pending) {
        if ($rank !== false && $n <= $rank && wp_next_scheduled(PP_CONTENT_CENSUS_HOOK, [$pending]) !== false) {
            return;
        }
    }
    wp_schedule_single_event(time() + 60, PP_CONTENT_CENSUS_HOOK, [$trigger]);
}

/**
 * After a WordPress CORE upgrade: schedule, never run here (this request still has the old
 * HTML API loaded; the parser's bail set is WordPress's, P-16).
 *
 * @param mixed $upgrader
 * @param mixed $hook_extra
 */
function pp_content_census_on_upgrade($upgrader, $hook_extra = []): void {
    if (is_array($hook_extra) && ($hook_extra['type'] ?? null) === 'core') {
        pp_content_census_schedule('core-upgrade');
    }
}
add_action('upgrader_process_complete', 'pp_content_census_on_upgrade', 10, 2);

/**
 * The theme's own upgrade: the first admin request on a new theme version schedules a
 * census (the old theme's code ran its upgrader hook, so the new version notices itself).
 */
function pp_content_census_on_admin_init(): void {
    if (!defined('PP_VERSION') || !current_user_can('manage_options')) {
        return;
    }
    if (get_option(PP_CONTENT_CENSUS_VERSION_OPTION, '') !== PP_VERSION) {
        // Recorded when SCHEDULED, not when the run finishes: a run that dies (a time limit on
        // a very large site) must not be scheduled again on every admin request.
        update_option(PP_CONTENT_CENSUS_VERSION_OPTION, PP_VERSION, false);
        pp_content_census_schedule('theme-version');
    }
}
add_action('admin_init', 'pp_content_census_on_admin_init');

/**
 * After an accepted composition write, while the recorded empty set is non-empty: schedule
 * a re-run, so the notice clears once the owner has fixed the props it names.
 */
function pp_content_census_after_write(): void {
    $state = get_option(PP_CONTENT_CENSUS_OPTION, []);
    if (is_array($state) && !empty($state['members'])) {
        pp_content_census_schedule('content-write');
    }
}

/** The membership key a dismissal records (the exact empty set, clauses included). */
function pp_content_census_membership_key(array $members): string {
    return substr(hash('sha256', implode("\n", $members)), 0, 32);
}

/**
 * The admin notice (P-27 (2)): shown to administrators while the last census found props
 * that render empty, until the user dismisses that exact set; a set that changes (grows,
 * or a clause changes) shows it again.
 */
function pp_admin_notice_content_census(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $state = get_option(PP_CONTENT_CENSUS_OPTION, []);
    if (!is_array($state) || empty($state['members']) || !is_array($state['members'])) {
        return;
    }
    $key = pp_content_census_membership_key($state['members']);
    if (get_user_meta(get_current_user_id(), PP_CONTENT_CENSUS_DISMISSED_META, true) === $key) {
        return;
    }
    $count = count($state['members']);
    $grew = !empty($state['grew']) && ($state['trigger'] ?? '') === PP_CONTENT_CENSUS_TRIGGERS[0]
        ? sprintf(' The list grew after the WordPress update to %s.', (string) ($state['wp_version'] ?? ''))
        : '';
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=pp_content_census_dismiss&key=' . $key), 'pp_content_census_dismiss');
    printf(
        '<div class="notice notice-warning"><p>%s%s %s <a href="%s">%s</a></p></div>',
        esc_html(sprintf(
            'PromptingPress: %d stored content prop(s) on %d page(s) render empty, because their markup escapes its container, the HTML parser cannot verify it, or it is over the size or nesting limit (the Layer-3 content check).',
            $count,
            (int) ($state['pages'] ?? 0)
        )),
        esc_html($grew),
        esc_html('List them with the WP-CLI command `wp pp content census`, then edit each prop it names to remove what it names, and save.'),
        esc_url($dismiss),
        esc_html('Dismiss')
    );
}
add_action('admin_notices', 'pp_admin_notice_content_census');

/** The notice's dismiss link: records the dismissed membership for this user only. */
function pp_content_census_dismiss(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }
    check_admin_referer('pp_content_census_dismiss');
    $key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    if (preg_match('/^[0-9a-f]{32}\z/', $key)) {
        update_user_meta(get_current_user_id(), PP_CONTENT_CENSUS_DISMISSED_META, $key);
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}
add_action('admin_post_pp_content_census_dismiss', 'pp_content_census_dismiss');
