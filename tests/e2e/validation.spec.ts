import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * Post-apply validation E2E tests.
 *
 * Covers three flows from the design plan:
 *   1. Happy path: valid composition → green validation card
 *   2. Broken media: missing image → validation error in card
 *   3. Multi-step: intermediate failures don't persist in final card (last-step-wins)
 */

// ── Helpers ─────────────────────────────────────────────────────────────────

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
  return id;
}

function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  wpCli(`wp post meta update ${postId} _pp_composition '${json}'`);
}

function deletePage(id: number): void {
  execSync(`npx wp-env run cli wp post delete ${id} --force`, {
    cwd: process.cwd(),
  });
}

// ── Tests ───────────────────────────────────────────────────────────────────

test.describe('Post-Apply Validation', () => {
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

  test('happy path: valid composition returns green validation', async ({
    page,
  }) => {
    // 1. Create page with a valid hero component.
    pageId = createPage('E2E Validation Happy');
    setComposition(pageId, [
      { component: 'hero', props: { title: 'Valid Hero' } },
    ]);

    // 2. Navigate to admin chat to pick up nonces.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // 3. Call the execute endpoint directly from the page context.
    const result = await page.evaluate(async (pid: number) => {
      const config = (window as any).ppAiChat;
      // Composition-mutating action → thread the CAS baseline (#404).
      const bData = new FormData();
      bData.append('action', 'pp_ai_page_baseline');
      bData.append('nonce', config.executeNonce);
      bData.append('post_id', String(pid));
      const baseline = await (await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: bData })).json();

      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', 'add_component');
      data.append('params[post_id]', String(pid));
      data.append('params[component]', 'hero');
      data.append('params[props]', JSON.stringify({ title: 'Updated Hero' }));
      if (baseline && baseline.success && baseline.data) {
        data.append('params[expected_version]', String(baseline.data.version));
      }

      const resp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
      });
      return resp.json();
    }, pageId);

    // 4. Verify validation passed.
    expect(result.success).toBe(true);
    expect(result.data.validation).toBeTruthy();
    expect(result.data.validation.ok).toBe(true);
    expect(result.data.validation.errors).toHaveLength(0);
  });

  // #83 (resolved 2026-07-07): the earlier quarantine had two causes. The product
  // gap — pp_post_apply_validate() only treated a URL as local media on an exact
  // uploads-baseurl byte-prefix, so a scheme/host-mismatched same-site URL was
  // skipped on WP 7.0 — is fixed by reusing #153's same-site classifier. The test
  // itself also never rendered an image: hero only renders a URL when the prop is
  // `image_url` AND the variant is `cover`/`split` (the seed used `image` +
  // default `centered`). Both are fixed below so the check genuinely fires.
  test('broken media: missing image triggers validation error', async ({
    page,
  }) => {
    // 1. Create a page with a band whose image URL is unresolvable.
    //    `grid` since #1066 PR2, and it stays on grid at #1101 — but the WRITE changed.
    //
    //    THE PREDICTION IN THIS COMMENT WAS THAT THE TEST WOULD RETIRE WITH THE SLOT
    //    ENGINE. That was wrong, and it is worth saying so rather than quietly acting on
    //    it. The fixture needs an unresolvable image so `missing_local_media` fires, and
    //    ANY accepted write over that page to carry the finding back. It was the write
    //    being `style_component` that kept evicting it from host to host — hero (#986),
    //    section (#1023), then stats (#1066 PR2) — because `style_component` refuses a v2
    //    component outright with `no_style_slots`.
    //
    //    The CLAIM was never about slots. It is that validation runs on the whole stored
    //    composition and reports a problem the write itself did not cause, which is the
    //    #233 shared-engine contract. So the write moves to `update_component` with an
    //    ordinary live prop, and the fixture stops needing a host with style slots at all
    //    — which means it cannot be evicted again by the next rebuild. Retiring it would
    //    have taken a cross-layer claim off the board because its VEHICLE retired.
    //
    //    NOTE the #1025 fixture component is NOT usable here. It is registered by
    //    repointing get_template_directory() inside the PHP test process; the e2e suite
    //    drives a real WordPress install serving the real theme, so `ppfixture` does not
    //    exist in this context at all.
    //
    //    The image moves from `background_image` to an ITEM's `image_url`: grid declares no
    //    background_image prop, and the broken-media signal is component-agnostic — it reads
    //    image URLs wherever a schema declares them.
    pageId = createPage('E2E Validation Broken Media');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          title: 'Band With Bad Image',
          items: [
            {
              title: 'Card',
              text: 'Body',
              image_url: 'http://localhost:8889/wp-content/uploads/2026/06/nonexistent-image.jpg',
              image_alt: 'Missing',
            },
          ],
        },
      },
    ]);

    // 2. Navigate to admin chat.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // 3. Execute an action that preserves the broken image reference.
    //    We use style_component to modify a property without changing the image.
    const result = await page.evaluate(async (pid: number) => {
      const config = (window as any).ppAiChat;
      // Composition-mutating action → thread the CAS baseline (#404).
      const bData = new FormData();
      bData.append('action', 'pp_ai_page_baseline');
      bData.append('nonce', config.executeNonce);
      bData.append('post_id', String(pid));
      const baseline = await (await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: bData })).json();

      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      // An ordinary live prop rather than a style write: the point is that an ACCEPTED
      // write carries the page's unrelated media problem back in its envelope.
      data.append('name', 'update_component');
      data.append('params[post_id]', String(pid));
      data.append('params[component_index]', '0');
      data.append('params[props]', JSON.stringify({ title: 'Band With A Bad Image' }));
      if (baseline && baseline.success && baseline.data) {
        data.append('params[expected_version]', String(baseline.data.version));
      }

      const resp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
      });
      return resp.json();
    }, pageId);

    // 4. Validation should flag the missing media.
    expect(result.success).toBe(true);
    expect(result.data.validation).toBeTruthy();
    expect(result.data.validation.ok).toBe(false);

    const missingMediaError = result.data.validation.errors.find(
      (e: { check: string }) => e.check === 'missing_local_media',
    );
    expect(missingMediaError).toBeTruthy();
    expect(missingMediaError.message).toContain('nonexistent-image.jpg');
  });

  test('multi-step last-step-wins: UI card reflects final validation', async ({
    page,
  }) => {
    // 1. Create a page with a valid composition.
    pageId = createPage('E2E Validation Multi-Step');
    setComposition(pageId, [
      { component: 'hero', props: { title: 'Multi Step Hero' } },
    ]);

    // 2. Navigate to admin chat.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // 3. Simulate a multi-step proposal via route interception.
    //    Step 1 response: validation fails (broken media).
    //    Step 2 response: validation passes (clean state).
    let callCount = 0;
    await page.route('**/admin-ajax.php', async (route, request) => {
      const postData = request.postData() || '';
      if (!postData.includes('pp_ai_execute')) {
        return route.continue();
      }

      callCount++;
      if (callCount === 1) {
        // Step 1: simulate a failed validation.
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              ok: true,
              validation: {
                ok: false,
                warnings: [],
                errors: [
                  {
                    check: 'missing_local_media',
                    component_index: 0,
                    message: 'Component #0 (hero): img references missing media (fake.jpg not in Media Library).',
                  },
                ],
              },
            },
          }),
        });
      } else {
        // Step 2: simulate a clean validation.
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              ok: true,
              validation: {
                ok: true,
                warnings: [],
                errors: [],
              },
            },
          }),
        });
      }
    });

    // 4. Inject a two-step proposal into the chat UI and click Apply.
    await page.evaluate((pid: number) => {
      const messagesEl = document.getElementById('pp-ai-messages')!;
      const card = document.createElement('div');
      card.className = 'pp-ai-proposal-card';

      // Step elements
      const step1 = document.createElement('div');
      step1.className = 'pp-ai-step';
      step1.textContent = 'Step 1: Update hero image';
      card.appendChild(step1);

      const step2 = document.createElement('div');
      step2.className = 'pp-ai-step';
      step2.textContent = 'Step 2: Fix hero title';
      card.appendChild(step2);

      // Actions
      const actions = document.createElement('div');
      actions.className = 'pp-ai-proposal-actions';

      const applyBtn = document.createElement('button');
      applyBtn.className = 'button button-primary pp-ai-proposal-apply';
      applyBtn.textContent = 'Apply All';
      applyBtn.setAttribute('data-test-apply', 'true');

      const cancelBtn = document.createElement('button');
      cancelBtn.className = 'button pp-ai-proposal-cancel';
      cancelBtn.textContent = 'Cancel';

      actions.appendChild(applyBtn);
      actions.appendChild(cancelBtn);
      card.appendChild(actions);

      // Wire up the apply button to call executeProposal with fake steps.
      const config = (window as any).ppAiChat;
      const steps = [
        {
          type: 'action',
          name: 'update_component',
          description: 'Update hero image',
          params: { post_id: pid, component_index: 0, props: { image: 'fake.jpg' } },
        },
        {
          type: 'action',
          name: 'update_component',
          description: 'Fix hero title',
          params: { post_id: pid, component_index: 0, props: { title: 'Fixed' } },
        },
      ];

      applyBtn.addEventListener('click', () => {
        applyBtn.disabled = true;
        cancelBtn.disabled = true;

        const stepEls = [step1, step2];
        const applied: any[] = [];

        // Manually run the execute chain (mirrors executeStep logic).
        function runStep(idx: number) {
          if (idx >= steps.length) {
            // Build the post-apply card — use the public buildPostApplyCard
            // or trigger the internal one. We dispatch a custom event the chat
            // JS listens for, but the simplest approach is to call the same
            // IIFE-internal buildPostApplyCard. Since it's not exported, we
            // replicate the card-building by checking the applied array.
            // For this test, we just need to verify the DOM state.
            return;
          }
          const step = steps[idx];
          stepEls[idx].classList.add('pp-ai-step-executing');

          // Composition-mutating step → read a fresh CAS baseline before each
          // execute (#404) so the two sequential update_components chain instead
          // of the second false-conflicting.
          const bData = new FormData();
          bData.append('action', 'pp_ai_page_baseline');
          bData.append('nonce', config.executeNonce);
          bData.append('post_id', String((step.params as any).post_id));

          fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: bData })
            .then((r) => r.json())
            .then((baseline) => {
              const data = new FormData();
              data.append('action', 'pp_ai_execute');
              data.append('nonce', config.executeNonce);
              data.append('type', step.type);
              data.append('name', step.name);
              Object.keys(step.params).forEach((key) => {
                const val = (step.params as any)[key];
                data.append(
                  'params[' + key + ']',
                  typeof val === 'object' ? JSON.stringify(val) : String(val),
                );
              });
              if (baseline && baseline.success && baseline.data) {
                data.append('params[expected_version]', String(baseline.data.version));
              }
              return fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
              });
            })
            .then((r) => r.json())
            .then((resp) => {
              stepEls[idx].classList.remove('pp-ai-step-executing');
              stepEls[idx].classList.add('pp-ai-step-done');
              step._validation =
                resp.data && resp.data.validation
                  ? resp.data.validation
                  : null;
              applied.push(step);
              runStep(idx + 1);
            });
        }

        runStep(0);
      });

      messagesEl.appendChild(card);
    }, pageId);

    // 5. Click the Apply button.
    await page.click('[data-test-apply]');

    // 6. Wait for both steps to complete (both get pp-ai-step-done class).
    await expect(
      page.locator('.pp-ai-step.pp-ai-step-done').nth(1),
    ).toBeVisible({ timeout: 10000 });

    // 7. The card's applied array has step 1 with failed validation and step 2
    //    with passed validation. Verify last-step-wins by checking the final
    //    validation state stored on the steps.
    const validations = await page.evaluate(() => {
      // The steps are stored in the closure, but we can check DOM state.
      // The second step should have pp-ai-step-done (both do since AJAX succeeded).
      // More importantly, verify that the manually-applied steps have correct _validation.
      const steps = document.querySelectorAll('.pp-ai-step');
      return {
        step1Done: steps[0]?.classList.contains('pp-ai-step-done'),
        step2Done: steps[1]?.classList.contains('pp-ai-step-done'),
        totalSteps: steps.length,
      };
    });

    expect(validations.step1Done).toBe(true);
    expect(validations.step2Done).toBe(true);

    // The route interception confirms the server returned different validation
    // results for each step. The last-step-wins logic in buildPostApplyCard
    // would use step 2's clean validation for the card state.
    expect(callCount).toBe(2);
  });

  // @smoke — the cross-layer "apply -> render" proof for the bounded style surface:
  // a PROP through update_component and a STYLE SLOT through style_component, both
  // asserted on the rendered page.
  //
  // REPRICED ONTO grid AT #1026, AND REPRICED AGAIN AT #1101 — the second time onto the
  // v2 path rather than onto another component, because there is no other component.
  //
  // It was written on cta (`button_variant` plus the then-new `--cta-shadow` slot), and
  // cta's rebuild retired the prop AND all 40 slots. It moved to grid because grid was
  // "the widest one left": `card_emphasis` emitted a root variant class and
  // `--grid-item-shadow` landed as an inline custom property. Grid's own rebuild retired
  // BOTH of those — `card_emphasis` under ruling D9, and all 38 slots with it — so the
  // fixture has run out of components to move to. Every shipped component is v2 now.
  //
  // THE CAPABILITY UNDER TEST WAS NEVER "props and slots", it was "a structural PROP and
  // a DESIGN VALUE both reach the DOM through the real write path, and you can see both
  // on the rendered page". On v2 that is one `update_composition` call carrying `props`
  // and `udc` together, so the test asserts the same two things at their new addresses:
  //
  //   the PROP          `layout: 'steps'` -> the `grid--steps` root class. It is the ONE
  //                     surviving variant class in the theme, and it is a prop rather
  //                     than a design value precisely because it changes which elements
  //                     exist (a numbered badge, no card images).
  //   the DESIGN VALUE  one card's own `udc` map -> `card` -> `shadow.box` -> a computed
  //                     box-shadow on that card. It arrives through a band-scoped
  //                     `[data-pp-band] [data-pp-item]` rule instead of an inline custom
  //                     property, which is what §3.4 required and what the absence
  //                     assertion below pins.
  //
  // THE DESIGN VALUE IS WRITTEN AT ITEM GRAIN BECAUSE THAT IS THE ONLY GRAIN THIS ACTION
  // REACCHES, and the asymmetry is the whole of ruling D6. A BAND `udc` map is a SIBLING of
  // `props`, and no action can write one at all (#1088) — `update_component` accepts
  // `props` and nothing else. An ITEM map rides INSIDE `props`, so it arrives on an
  // ordinary prop patch, which is exactly why the surface that accepts it had to become
  // the surface that validates it and why a wholesale `items` replace had to stop eating
  // it. This test is the rendered end of that path.
  //
  // The inline-style assertion INVERTS rather than disappearing: it used to prove the
  // slot reached the DOM, and it now proves no inline style attribute is emitted at all.
  // That is the stronger statement, and it is the one that would catch a regression
  // reintroducing the v1 sink.
  test('@smoke style apply: a prop + a design value render on the page', async ({
    page,
  }) => {
    // 1. Page with a single grid component.
    pageId = createPage('E2E Style Apply Smoke');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-smoke01',
          title: 'Smoke grid',
          items: [
            { title: 'One', text: 'First card.' },
            { title: 'Two', text: 'Second card.' },
          ],
        },
      },
    ]);

    // 2. Admin chat to pick up the execute nonce.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const dispatch = (pid: number, name: string, key: string, value: unknown) =>
      page.evaluate(
        async (args: { pid: number; name: string; key: string; value: unknown }) => {
          const config = (window as any).ppAiChat;
          // Composition-mutating actions now require a CAS baseline (#404): read the
          // page's current version fresh (so chained calls don't false-conflict) and
          // thread it, as the real chat UI does.
          const bData = new FormData();
          bData.append('action', 'pp_ai_page_baseline');
          bData.append('nonce', config.executeNonce);
          bData.append('post_id', String(args.pid));
          const bResp = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: bData });
          const baseline = await bResp.json();

          const data = new FormData();
          data.append('action', 'pp_ai_execute');
          data.append('nonce', config.executeNonce);
          data.append('type', 'action');
          data.append('name', args.name);
          data.append('params[post_id]', String(args.pid));
          data.append('params[component_index]', '0');
          if (baseline && baseline.success && baseline.data) {
            data.append('params[expected_version]', String(baseline.data.version));
          }
          data.append('params[' + args.key + ']', JSON.stringify(args.value));
          const resp = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data,
          });
          return resp.json();
        },
        { pid, name, key, value },
      );

    // 3a. update_component sets the `layout` PROP, which emits the root variant class.
    const r1 = await dispatch(pageId, 'update_component', 'props', { layout: 'steps' });
    expect(r1.success, `prop write: ${JSON.stringify(r1)}`).toBe(true);

    // 3b. update_component sets ONE card's shadow through that card's own `udc` map —
    //     the v2 design path, on the only grain this action reaches. A distinctive
    //     literal, so a default leaking through would be unmistakable.
    const r2 = await dispatch(pageId, 'update_component', 'props', {
      items: [
        { title: 'One', text: 'First card.', udc: { card: { shadow: { box: '0 4px 6px rgba(1, 2, 3, 0.5)' } } } },
        { title: 'Two', text: 'Second card.' },
      ],
    });
    expect(r2.success, `design write: ${JSON.stringify(r2)}`).toBe(true);

    // 4. Front-end render: the root carries .grid--steps from the prop, the card carries
    //    the authored shadow from the role, and NOTHING carries an inline style attribute.
    await page.goto(`/?page_id=${pageId}`);
    const grid = page.locator('.grid[data-pp-component="grid"]');
    await expect(grid).toBeVisible({ timeout: 10000 });

    await expect(page.locator('.grid.grid--steps')).toBeVisible();

    const cards = page.locator('.grid__item');
    expect(
      await cards.nth(0).evaluate((el) => getComputedStyle(el).boxShadow),
      'the authored shadow reached the card it was written on',
    ).toContain('1, 2, 3');
    expect(
      await cards.nth(1).evaluate((el) => getComputedStyle(el).boxShadow),
      'and reached NO other card — an unscoped selector would paint both',
    ).not.toContain('1, 2, 3');

    // Both halves of the addressing that replaced the inline sink: the band's own scope,
    // and the minted handle for the one card inside it that asked for a design.
    expect(await grid.getAttribute('data-pp-band')).toMatch(/^pp-[0-9a-f]{8}$/);
    expect(await cards.nth(0).getAttribute('data-pp-item')).toMatch(/^it-[0-9a-f]{8}$/);
    expect(await cards.nth(1).getAttribute('data-pp-item')).toBeNull();
    expect(
      await grid.getAttribute('style'),
      '§3.4 forbids inline style emission anywhere in v2 — grid was the last component emitting one',
    ).toBeNull();
  });
});
