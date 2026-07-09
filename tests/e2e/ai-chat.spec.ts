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

    await gotoChat(page, pageId);
    await mockStream(page, [
      {
        done: true,
        proposal: {
          steps: [
            { type: 'action', name: 'remove_component', description: 'Remove the section', params: { post_id: pageId, component_index: 1 } },
          ],
        },
      },
    ]);
    // NOTE: no admin-ajax mock — preview, execute, and restore_composition all run
    // against real WordPress, so the composition actually changes and Undo actually
    // restores it (that is the behavior under test, #133 acceptance criterion #4).

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
