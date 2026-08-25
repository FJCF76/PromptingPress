import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * AI Chat streaming/apply E2E coverage (#14).
 *
 * Scoped to the SSE streaming + proposal/apply state machine in
 * assets/js/pp-ai-chat.js, using a deterministic mocked `ai-stream.php`
 * (and the `admin-ajax.php` actions it falls back to / depends on for
 * proposal previews and batch apply) — no real AI provider is ever called.
 *
 * Wire formats mocked here mirror ai-stream.php exactly:
 *   - success: `data: {"content": "..."}\n\n` chunks, then
 *     `data: {"done": true, "proposal"?: {...}, "truncated"?: true}\n\n`,
 *     then `data: [DONE]\n\n`.
 *   - mid-stream error: `data: {"error": "..."}\n\n` (still followed by a
 *     `done` + `[DONE]` event, matching the real transport).
 *   - not-configured: plain-text HTTP 400 (no SSE framing at all) — this is
 *     what actually drives the client into ajaxFallback(), not a mocked
 *     SSE error event.
 *
 * Not covered here (out of scope per the issue's own diagnostic comment,
 * to avoid "scope creep into full UI coverage"): provider/model selection,
 * page-switch suggestions, composition diff rendering, localStorage restore.
 * Those are exercised elsewhere (unit tests) or are follow-up issues.
 */

function wpCli(cmd: string): string {
  return execSync(`npx wp-env run cli ${cmd}`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  }).trim();
}

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(
    execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(),
    10,
  );
  execSync(
    `npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`,
    { cwd: process.cwd() },
  );
  wpCli(`wp post meta update ${id} _pp_composition '[{"component":"hero","props":{"title":"Chat E2E"}}]'`);
  return id;
}

function deletePage(id: number): void {
  execSync(`npx wp-env run cli wp post delete ${id} --force`, {
    cwd: process.cwd(),
  });
}

/**
 * Reads a page's current composition CAS version the same way the real server does
 * when it emits the SSE `done` event's page_baseline (pp_get_composition_marker()
 * returns `(int) get_post_meta($id, '_pp_composition_version', true)`; #404). Seeding
 * `_pp_composition` via a direct `wp post meta update` (as these specs do) bypasses
 * pp_update_composition, so it never bumps the version — an unwritten meta reads as 0,
 * the legitimate version-0 baseline the server preserves. Reading the true value (vs
 * hardcoding 0) keeps the fixture correct for any starting version.
 */
function compositionVersion(id: number): number {
  const raw = wpCli(
    `wp eval 'echo (int) get_post_meta(${id}, "_pp_composition_version", true);'`,
  );
  // `wp eval` prints just the integer on its own line. Match a line that is ENTIRELY an
  // integer (last one wins) so any wp-env banner line — which echoes the command back,
  // including this post id, plus timing digits — can never be mistaken for the version.
  const lines = raw.split('\n').map((l) => l.replace(/\x1b\[[0-9;]*m/g, '').trim());
  for (let i = lines.length - 1; i >= 0; i--) {
    if (/^-?\d+$/.test(lines[i])) {
      return parseInt(lines[i], 10);
    }
  }
  return 0;
}

type SseEvent = Record<string, unknown>;

function sseBody(events: SseEvent[]): string {
  const frames = events.map((e) => `data: ${JSON.stringify(e)}\n\n`);
  frames.push('data: [DONE]\n\n');
  return frames.join('');
}

async function mockStream(page: Page, events: SseEvent[]): Promise<void> {
  await page.route('**/ai-stream.php', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/event-stream',
      body: sseBody(events),
    });
  });
}

async function mockStreamNotConfigured(page: Page): Promise<void> {
  await page.route('**/ai-stream.php', async (route) => {
    await route.fulfill({
      status: 400,
      contentType: 'text/plain',
      body: 'AI provider not configured. Check Settings > Connectors.',
    });
  });
}

async function mockStreamDropped(page: Page): Promise<void> {
  await page.route('**/ai-stream.php', async (route) => {
    await route.abort('failed');
  });
}

/** One handler covers every admin-ajax.php action this suite mocks. */
async function mockAjax(
  page: Page,
  handlers: Record<string, (postData: string) => Record<string, unknown>>,
): Promise<void> {
  await page.route('**/admin-ajax.php', async (route, request) => {
    const postData = request.postData() || '';
    const actionName = Object.keys(handlers).find((name) =>
      postData.includes(name),
    );
    if (!actionName) {
      return route.continue();
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(handlers[actionName](postData)),
    });
  });
}

function previewOkResponse() {
  return {
    success: true,
    data: { changes: [{ path: 'props.title', from: 'Chat E2E', to: 'Updated Title' }] },
  };
}

async function gotoChat(page: Page, pageId: number): Promise<void> {
  await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
  await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
  await page.selectOption('#pp-ai-page-select', String(pageId));
}

test.describe('AI Chat — streaming & apply (mock SSE)', () => {
  let pageId: number;

  test.afterEach(async () => {
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  test('streams content and renders a single-step proposal', async ({ page }) => {
    pageId = createPage('E2E Chat Single Step');
    await gotoChat(page, pageId);
    await mockStream(page, [
      { content: 'Sure, ' },
      { content: 'here is the change.' },
      {
        done: true,
        proposal: {
          steps: [
            { type: 'action', name: 'update_component', description: 'Update hero title', params: { post_id: pageId, component_index: 0, props: { title: 'Updated Title' } } },
          ],
        },
      },
    ]);
    await mockAjax(page, { pp_ai_preview: previewOkResponse });

    await page.fill('#pp-ai-input', 'Update the hero title');
    await page.click('#pp-ai-send');

    await expect(page.locator('.pp-ai-msg-user .pp-ai-msg-body').last()).toHaveText('Update the hero title');
    await expect(page.locator('.pp-ai-msg-assistant .pp-ai-msg-body').last()).toHaveText(
      'Sure, here is the change.',
      { timeout: 10000 },
    );
    await expect(page.locator('.pp-ai-msg-assistant .pp-ai-msg-streaming')).toHaveCount(0);

    await expect(page.locator('.pp-ai-proposal-step-label')).toHaveText('1. Update hero title');
    const applyBtn = page.locator('.pp-ai-proposal-apply');
    await expect(applyBtn).toBeVisible({ timeout: 10000 });
    await expect(applyBtn).toHaveText('Apply');
  });

  test('renders Apply All for a multi-step proposal and applies via one atomic batch request', async ({ page }) => {
    pageId = createPage('E2E Chat Multi Step');
    await gotoChat(page, pageId);
    await mockStream(page, [
      {
        done: true,
        proposal: {
          steps: [
            { type: 'action', name: 'update_component', description: 'Update hero title', params: { post_id: pageId, component_index: 0, props: { title: 'A' } } },
            { type: 'action', name: 'style_component', description: 'Add hero shadow', params: { post_id: pageId, component_index: 0, style: { '--hero-shadow': 'var(--shadow-md)' } } },
          ],
        },
      },
    ]);

    let executeBody = '';
    await mockAjax(page, {
      pp_ai_preview: previewOkResponse,
      pp_ai_execute_batch: (postData) => {
        executeBody = postData;
        return {
          success: true,
          data: {
            ok: true,
            steps: [
              { ok: true, validation: { ok: true, warnings: [], errors: [] }, stale_warnings: null },
              { ok: true, validation: { ok: true, warnings: [], errors: [] }, stale_warnings: null },
            ],
            failed_at: null,
            rolled_back: false,
          },
        };
      },
    });

    await page.fill('#pp-ai-input', 'Update the title and add a shadow');
    await page.click('#pp-ai-send');

    const applyBtn = page.locator('.pp-ai-proposal-apply');
    await expect(applyBtn).toBeVisible({ timeout: 10000 });
    await expect(applyBtn).toHaveText('Apply All');

    await applyBtn.click();

    // On success the proposal card is replaced wholesale with a post-apply
    // summary (buildPostApplyCard) — the per-step "executing" elements are
    // gone, not just re-classed, so assert on that summary rather than on
    // transient per-step classes.
    await expect(
      page.locator('.pp-ai-proposal-card .pp-ai-step-done', { hasText: 'All changes applied successfully.' }),
    ).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.pp-ai-proposal-card .pp-ai-status', { hasText: 'Applied: Update hero title' })).toBeVisible();
    await expect(page.locator('.pp-ai-proposal-card .pp-ai-status', { hasText: 'Applied: Add hero shadow' })).toBeVisible();

    // #137: one atomic batch call carrying both steps, not two sequential
    // pp_ai_execute calls — the "steps" field is valid JSON with both entries.
    const stepsField = /name="steps"\r?\n\r?\n(\[.*?\])\r?\n/s.exec(executeBody);
    expect(stepsField).not.toBeNull();
    expect(JSON.parse(stepsField![1])).toHaveLength(2);
  });

  test('remove_component proposal then Undo restores the removed section (#133)', async ({ page }) => {
    pageId = createPage('E2E Chat Undo');
    // Seed a two-component composition so the removed section is observable and
    // its return after Undo is unambiguous.
    wpCli(`wp post meta update ${pageId} _pp_composition '[{"component":"hero","props":{"title":"Keep Me"}},{"component":"section","props":{"title":"Remove Me","body":"section body"}}]'`);

    // Capture the page's current CAS version so the mocked SSE `done` event can carry a
    // page_baseline exactly like the real ai-stream.php does (#404). Without it the mock
    // stores no baseline, and the fail-closed baseline mandate rejects both the apply and
    // the Undo (restore_composition) writes, timing the spec out. Reading the true value
    // (rather than assuming 0) mirrors what the server would compute at stream time.
    const baselineVersion = compositionVersion(pageId);

    await gotoChat(page, pageId);
    await mockStream(page, [
      {
        done: true,
        // page_baseline is what the real server captures for the page the model read and
        // threads back on write (assets/js/pp-ai-chat.js storePageBaseline). The client
        // sends it as the batch CAS baseline on apply, then refreshes from the batch
        // response's `versions` map so the Undo threads the post-apply version (#404).
        page_baseline: { post_id: pageId, version: baselineVersion },
        proposal: {
          steps: [
            { type: 'action', name: 'remove_component', description: 'Remove the section', params: { post_id: pageId, component_index: 1 } },
          ],
        },
      },
    ]);
    // NOTE: no admin-ajax mock — preview, execute, and restore_composition all run
    // against real WordPress, so the composition actually changes and Undo actually
    // restores it (that is the behavior under test, #133 acceptance criterion #4). The
    // Undo only succeeds if the client refreshed its baseline from the apply response,
    // so a green Undo also proves the real post-#404 CAS refresh path works.

    await page.fill('#pp-ai-input', 'Remove the section');
    await page.click('#pp-ai-send');

    const applyBtn = page.locator('.pp-ai-proposal-apply');
    await expect(applyBtn).toBeVisible({ timeout: 10000 });
    await applyBtn.click();

    // The post-apply card renders the "Undo these changes" affordance (#133). It is
    // the last link in the container (after "View Page"); grab it by position so the
    // locator survives the label change on click.
    const undoLink = page.locator('.pp-ai-post-apply-links a').last();
    await expect(undoLink).toHaveText('Undo these changes', { timeout: 10000 });

    // After the apply the section is gone (one component left).
    const afterRemove = JSON.parse(wpCli(`wp post meta get ${pageId} _pp_composition`));
    expect(afterRemove).toHaveLength(1);
    expect(afterRemove[0].component).toBe('hero');

    // Click Undo → restore_composition walks the history ring back → section reappears.
    await undoLink.click();
    await expect(undoLink).toHaveText('Changes undone ✓', { timeout: 10000 });

    const afterUndo = JSON.parse(wpCli(`wp post meta get ${pageId} _pp_composition`));
    expect(afterUndo).toHaveLength(2);
    expect(afterUndo.map((c: { component: string }) => c.component)).toEqual(['hero', 'section']);
    expect(afterUndo[1].props.title).toBe('Remove Me');
  });

  test('Cancel discards a previewed proposal without applying', async ({ page }) => {
    pageId = createPage('E2E Chat Cancel');
    await gotoChat(page, pageId);
    await mockStream(page, [
      {
        done: true,
        proposal: {
          steps: [
            { type: 'action', name: 'update_component', description: 'Update hero title', params: { post_id: pageId, component_index: 0, props: { title: 'A' } } },
          ],
        },
      },
    ]);
    await mockAjax(page, { pp_ai_preview: previewOkResponse });

    await page.fill('#pp-ai-input', 'Update the hero title');
    await page.click('#pp-ai-send');

    const cancelBtn = page.locator('.pp-ai-proposal-cancel');
    await expect(cancelBtn).toBeVisible({ timeout: 10000 });
    await cancelBtn.click();

    await expect(page.locator('.pp-ai-proposal-card.pp-ai-proposal-cancelled')).toBeVisible();
    await expect(page.locator('.pp-ai-status', { hasText: 'Proposal cancelled.' })).toBeVisible();
    await expect(page.locator('.pp-ai-proposal-apply')).toBeDisabled();
  });

  test('no API key configured: stream 400s and the AJAX fallback surfaces a Connectors error', async ({ page }) => {
    pageId = createPage('E2E Chat No Key');
    await gotoChat(page, pageId);
    await mockStreamNotConfigured(page);
    await mockAjax(page, {
      pp_ai_chat: () => ({
        success: false,
        data: 'AI provider not configured. Check Settings > Connectors.',
      }),
    });

    await page.fill('#pp-ai-input', 'Hello');
    await page.click('#pp-ai-send');

    await expect(page.locator('.pp-ai-status', { hasText: 'Streaming unavailable' })).toBeVisible({ timeout: 10000 });
    const errBody = page.locator('.pp-ai-msg-assistant .pp-ai-msg-error');
    await expect(errBody).toContainText('AI provider not configured.');
    await expect(errBody.locator('a')).toHaveText('Settings > Connectors');
  });

  test('invalid API key mid-stream: SSE error event shows a Connectors link', async ({ page }) => {
    pageId = createPage('E2E Chat Invalid Key');
    await gotoChat(page, pageId);
    await mockStream(page, [
      { error: 'AI provider rejected the API key. Check Settings > Connectors.' },
    ]);

    await page.fill('#pp-ai-input', 'Hello');
    await page.click('#pp-ai-send');

    const errBody = page.locator('.pp-ai-msg-assistant .pp-ai-msg-error');
    await expect(errBody).toContainText('rejected the API key', { timeout: 10000 });
    await expect(errBody.locator('a')).toHaveText('Settings > Connectors');
  });

  test('rate limited mid-stream: SSE error event shows no Connectors link', async ({ page }) => {
    pageId = createPage('E2E Chat Rate Limited');
    await gotoChat(page, pageId);
    await mockStream(page, [
      { error: 'Rate limited. Try again in a moment.' },
    ]);

    await page.fill('#pp-ai-input', 'Hello');
    await page.click('#pp-ai-send');

    const errBody = page.locator('.pp-ai-msg-assistant .pp-ai-msg-error');
    await expect(errBody).toContainText('Rate limited', { timeout: 10000 });
    await expect(errBody.locator('a')).toHaveCount(0);
  });

  test('quota exhausted mid-stream: mentions API key but still suppresses the Connectors link', async ({ page }) => {
    pageId = createPage('E2E Chat Quota');
    await gotoChat(page, pageId);
    await mockStream(page, [
      { error: 'Your API key has no remaining credits. Add billing at your provider, or switch to a different provider above.' },
    ]);

    await page.fill('#pp-ai-input', 'Hello');
    await page.click('#pp-ai-send');

    const errBody = page.locator('.pp-ai-msg-assistant .pp-ai-msg-error');
    await expect(errBody).toContainText('no remaining credits', { timeout: 10000 });
    await expect(errBody.locator('a')).toHaveCount(0);
  });

  test('genuine network failure during streaming falls back to the AJAX chat endpoint', async ({ page }) => {
    pageId = createPage('E2E Chat Connection Drop');
    await gotoChat(page, pageId);
    await mockStreamDropped(page);
    await mockAjax(page, {
      pp_ai_chat: () => ({
        success: true,
        data: { content: 'Here is my reply via the fallback endpoint.' },
      }),
    });

    await page.fill('#pp-ai-input', 'Hello');
    await page.click('#pp-ai-send');

    await expect(page.locator('.pp-ai-status', { hasText: 'Streaming unavailable' })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.pp-ai-msg-assistant .pp-ai-msg-body').last()).toHaveText(
      'Here is my reply via the fallback endpoint.',
    );
  });

  test('truncated response without a proposal surfaces a retry hint', async ({ page }) => {
    pageId = createPage('E2E Chat Truncated');
    await gotoChat(page, pageId);
    await mockStream(page, [
      { content: 'Sure, let me think about this.' },
      { done: true, truncated: true },
    ]);

    await page.fill('#pp-ai-input', 'Make a change');
    await page.click('#pp-ai-send');

    await expect(
      page.locator('.pp-ai-status', { hasText: 'was cut short before the proposal could be generated' }),
    ).toBeVisible({ timeout: 10000 });
  });
});

/**
 * Preview-error card typography and overflow (#662, #666).
 *
 * The rendered half of the contract. tests/js/pp-ai-chat-error-card-typography.test.js
 * scans the source for enumeration drift; only a browser can answer the question those
 * two issues actually asked, because both defects are CASCADE facts, not source facts.
 *
 * The DOM this depends on, and why the font reset has to land on the CONTAINER rather
 * than on the prose children, is diagrammed once in assets/css/pp-ai-chat.css, above the
 * `.pp-ai-step-diff` font reset. Not restated here: two live copies of one renderer's
 * shape drift apart independently.
 *
 * The short version, because it is what these assertions turn on: #662's own proposed
 * fix (`font-family: inherit` on the message) does not work, because `inherit` resolves
 * against `.pp-ai-step-diff` — the monospace being escaped. A computed-style assertion
 * is the only thing that can tell the working fix from the broken one; a source scan
 * sees a `font-family` declaration on the message either way and calls it fixed.
 *
 * Every case here drives the REAL renderer through the REAL stylesheet: the payloads go
 * in through the mocked `pp_ai_preview` response exactly as the server would send them,
 * and `ppChatGetErrorStepClass()` picks the step class itself.
 */
test.describe('AI Chat — preview-error card typography and overflow (#662, #666)', () => {
  let pageId: number;

  test.afterEach(async () => {
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  // PP_REFLECTED_NAME_MAX (lib/ai-chat.php) bounds a reflected name at 256 characters,
  // and _pp_clean_reflected_text() strips \p{Cc}\p{Cf} — newlines included — so this is
  // the longest unbroken run the disclosure can actually be asked to render. It is
  // interpolated into user_message AND raw_error, because since #661 the sentence
  // samples slot names too, so both elements carry a token with nothing to break on.
  const LONG_SLOT = `--hero-${'x'.repeat(249)}`;

  /**
   * The three arms of ppChatGetErrorStepClass(), each reached by its own payload
   * rather than by writing the class name onto the step from the test — the mapping
   * from error code to class is part of what is being pinned.
   */
  const CASES = [
    {
      label: 'fixable (a mistyped slot name — the common landing state since #625)',
      stepClass: 'pp-ai-step-fixable',
      isRed: false,
      payload: {
        error_code: 'invalid_style_slot',
        user_message: `I tried to set "${LONG_SLOT}" on the hero component, but it doesn't support that style setting. The full list is in the details below.`,
        alternatives: ['--hero-bg', '--hero-heading-color'],
        cross_component_hints: { '--grid-gap': { component: 'grid', slot: '--grid-gap', match: 'exact' } },
        raw_error: `Component "hero" has no style slot "${LONG_SLOT}".`,
      },
    },
    {
      label: 'impossible (the component declares no style slots at all)',
      stepClass: 'pp-ai-step-impossible',
      isRed: false,
      payload: {
        error_code: 'no_style_slots',
        user_message: `The nav component doesn't support style customization, so "${LONG_SLOT}" has nowhere to go.`,
        alternatives: [],
        raw_error: `Component "nav" declares no style slots; rejected "${LONG_SLOT}".`,
      },
    },
    {
      label: 'failed (the default arm — an error code the class reader does not know)',
      stepClass: 'pp-ai-step-failed',
      isRed: true,
      payload: {
        error_code: 'some_unmapped_error',
        user_message: `Something went wrong applying "${LONG_SLOT}".`,
        alternatives: [],
        raw_error: `Unmapped failure for "${LONG_SLOT}".`,
      },
    },
  ];

  for (const c of CASES) {
    test(`#662/#666 ${c.label}: prose reads as prose, raw_error stays monospace, nothing overflows at 375px`, async ({
      page,
    }) => {
      // 375px is the stress width: the card is capped at max-width 90% of the message
      // pane, so this is where an unbreakable token has the least room to fit.
      await page.setViewportSize({ width: 375, height: 800 });

      pageId = createPage('E2E Chat Preview Error Typography');
      await gotoChat(page, pageId);
      await mockStream(page, [
        {
          done: true,
          proposal: {
            steps: [
              {
                type: 'action',
                name: 'style_component',
                description: 'Set a style on the hero',
                params: { post_id: pageId, component_index: 0, style: { [LONG_SLOT]: 'red' } },
              },
            ],
          },
        },
      ]);
      await mockAjax(page, {
        pp_ai_preview: () => ({ success: false, data: c.payload }),
      });

      await page.fill('#pp-ai-input', 'Change a style');
      await page.click('#pp-ai-send');

      const step = page.locator(`.pp-ai-proposal-step.${c.stepClass}`);
      await expect(step).toBeVisible({ timeout: 10000 });

      const message = step.locator('.pp-ai-preview-error-message');
      const detailBody = step.locator('.pp-ai-preview-error-detail > div');
      const summary = step.locator('.pp-ai-preview-error-detail summary');
      await expect(message).toBeVisible();
      await expect(summary).toBeVisible();

      // Open the disclosure before measuring anything. Chromium leaves a closed
      // <details>'s content out of an ancestor's scrollWidth, so with it shut the pane
      // measurement below would be answered by `user_message` alone and would stay
      // green with the disclosure body's wrap deleted. Open is also the state #666 was
      // filed about: the author expands the technical details, and the pane grows a
      // scrollbar.
      await step
        .locator('details.pp-ai-preview-error-detail')
        .evaluate((d: HTMLDetailsElement) => {
          d.open = true;
        });
      await expect(detailBody).toBeVisible();

      const fontOf = (loc: ReturnType<typeof page.locator>) =>
        loc.evaluate((el) => getComputedStyle(el).fontFamily);

      // ── #662: prose in the UI font, machine text in monospace ──────────────
      // Asserted RELATIONALLY, against the step's own font, rather than by looking for
      // the word "monospace" in the computed stack. Two reasons. The admin font is
      // WordPress's to choose and changes between releases, so no literal stack can be
      // hardcoded. And a stack that is monospace WITHOUT saying so — `Consolas`,
      // `Menlo`, `Courier New`, `ui-monospace` — would sail past a `/monospace/` regex
      // while the prose rendered exactly as wrongly as before. The step container is
      // outside the diff element's monospace, so it IS the admin font by construction:
      // prose must equal it, machine text must not.
      const stepFont = await step.evaluate((el) => getComputedStyle(el).fontFamily);
      expect(await fontOf(message), `${c.stepClass}: the message reads as prose`).toBe(stepFont);
      expect(await fontOf(summary), `${c.stepClass}: the disclosure label reads as prose`).toBe(stepFont);
      expect(await fontOf(detailBody), `${c.stepClass}: raw_error reads as code`).not.toBe(stepFont);
      // ...and specifically the monospace this stylesheet declares for it.
      expect(await fontOf(detailBody), `${c.stepClass}: raw_error keeps its own monospace`).toMatch(
        /monospace/,
      );

      // The hint is prose by the same construction, and only rendered when the payload
      // carries cross_component_hints. Its font is pinned but not its wrap: the only
      // value it interpolates is `hint.component`, a registered component name from the
      // theme's own registry, so it cannot carry the unbounded reflected token #666 is
      // about the way the message and the disclosure body can.
      if (await step.locator('.pp-ai-preview-error-hint').count()) {
        expect(
          await fontOf(step.locator('.pp-ai-preview-error-hint')),
          `${c.stepClass}: the cross-component hint reads as prose`,
        ).toBe(stepFont);
      }

      // ── #662 regression guard: the rule split must not move the failed palette ──
      // Widening the original rule (instead of splitting out only the font-family)
      // would have painted the grey and amber cards red.
      const diffColor = await step
        .locator('.pp-ai-step-diff')
        .evaluate((el) => getComputedStyle(el).color);
      if (c.isRed) {
        expect(diffColor, 'the failed card keeps its red').toBe('rgb(214, 54, 56)');
        expect(
          await step.locator('.pp-ai-step-diff').evaluate((el) => getComputedStyle(el).fontSize),
        ).toBe('13px');
      } else {
        expect(diffColor, `${c.stepClass} must not inherit the failed card's red`).not.toBe(
          'rgb(214, 54, 56)',
        );
      }

      // ── #666: the long token wraps instead of widening the pane ────────────
      // The contract, asserted exactly: the two elements that carry the 256-char token
      // must contain it. This is the part of #666 that is about wrapping, and it is
      // strict — scrollWidth may not exceed clientWidth by so much as a pixel.
      for (const [name, loc] of [
        ['message', message],
        ['disclosure body', detailBody],
      ] as const) {
        const box = await loc.evaluate((el) => ({
          scrollWidth: el.scrollWidth,
          clientWidth: el.clientWidth,
        }));
        expect(box.scrollWidth, `${c.stepClass}: the ${name} wraps the long slot name`).toBeLessThanOrEqual(
          box.clientWidth,
        );
      }

      // And the consequence #666 actually complained about: the token no longer drags
      // a horizontal scrollbar across the whole conversation. Before the fix this
      // measured 1657 against a 359px pane at this viewport.
      //
      // One pixel of slack, and it is a measured allowance rather than a fudge factor.
      // `.pp-ai-proposal-card` is `box-sizing: content-box` with `max-width: 90%` plus
      // 16px of padding and a 1px border on each side, so once its content reaches the
      // cap its BORDER box is 90% + 34px — 335.5px inside a 335px pane at 375px wide.
      // That half pixel is the card's box model, not this token: every element from the
      // step inward measures 275/275 here, and the same card overflows by the same
      // amount for any content that reaches the cap. Filed as #779.
      const paneWidths = () =>
        page.locator('.pp-ai-chat-messages').evaluate((el) => ({
          scrollWidth: el.scrollWidth,
          clientWidth: el.clientWidth,
        }));
      const pane = await paneWidths();
      expect(
        pane.scrollWidth,
        `${c.stepClass}: the chat pane must not scroll horizontally (${pane.scrollWidth} vs ${pane.clientWidth})`,
      ).toBeLessThanOrEqual(pane.clientWidth + 1);

      // At a width where the card's 90% cap leaves room for its own 34px of chrome, the
      // #779 allowance does not apply and the pane must be exactly clean. Without this,
      // the suite would never assert zero overflow anywhere, and #779's half pixel could
      // grow to a whole one with nothing noticing.
      await page.setViewportSize({ width: 1280, height: 800 });
      const widePane = await paneWidths();
      expect(
        widePane.scrollWidth,
        `${c.stepClass}: no horizontal overflow at 1280px (${widePane.scrollWidth} vs ${widePane.clientWidth})`,
      ).toBeLessThanOrEqual(widePane.clientWidth);
    });
  }
});
