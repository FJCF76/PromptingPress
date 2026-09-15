<?php
/**
 * tests/ChromeUdcTest.php
 *
 * Ruling A1 — site chrome on the Universal Design Contract.
 *
 * REPLACES HeaderChromeTest, and the replacement is the point rather than an
 * accident of refactoring. That suite pinned a surface built on the premise that
 * chrome could only ever be three colours: `pp_header_bg`, `pp_header_text` and
 * `pp_header_link_color`, emitted as an inline `style` attribute of custom
 * properties. Every one of its 26 tests was about that surface, and the surface is
 * gone — chrome is now styled through the `pp_site_udc` container, which reaches
 * every role nav and footer declare, through the same engine and grammar a band's
 * `udc` map uses.
 *
 * WHAT THIS FILE HAS TO PROVE, in the order the claims are made:
 *
 *   1. SAME ENGINE. Not a chrome-shaped copy of it. A refusal an author would get
 *      on a band — unknown role, unknown group, bad value, banned url() — is the
 *      refusal they get on chrome, from the same code.
 *   2. THE CONTAINER'S OWN RULES. Which keys may appear, and that an unknown one
 *      REFUSES rather than being accepted and dropped. Per-page chrome is out of
 *      scope by ruling, and "out of scope" has to mean a refusal, not a silent
 *      no-op that reports success.
 *   3. CAS (invariant I8). A stale baseline is refused, a matching one lands, and
 *      the version moves. This is the guarantee ruling A1 named and the codebase
 *      did not have for any site option.
 *   4. FAIL-CLOSED READS (I9, I17). This row is autoloaded and read on every
 *      front-end request; a corrupt one must yield no styling, not a fatal and not
 *      a half-map.
 *   5. EMISSION. Scoped to [data-pp-chrome], in the two ruled tiers, and NOT gated
 *      on the page composition — chrome renders on 404 and search too.
 *
 * AUTHORING-PATH MANDATE (Section 14.1): the acceptance cases go through
 * pp_execute_action('update_site_option'), the real write surface, not a raw
 * update_option(). Raw seeding is used only where the point IS stored-but-never-
 * validated data (the corrupt-read tests), which is the one case the real surface
 * cannot produce.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ChromeUdcTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
    }

    /** The real write surface, as an author or the model reaches it. */
    private function write(array $map, ?int $expected = null): array
    {
        $params = ['key' => PP_SITE_UDC_OPTION, 'value' => (string) wp_json_encode($map)];
        if ($expected !== null) {
            $params['expected_version'] = $expected;
        }
        return pp_execute_action('update_site_option', $params);
    }

    /** Validation only, for the refusal cases. */
    private function validate(array $map)
    {
        return pp_validate_site_option_value(PP_SITE_UDC_OPTION, (string) wp_json_encode($map));
    }

    // ── 1. The same engine ──────────────────────────────────────────────────

    public function testChromeIsWhitelistedAsItsOwnTypeAndNotAsFreeText(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertArrayHasKey(PP_SITE_UDC_OPTION, $allowed);
        $this->assertSame('udc_map', $allowed[PP_SITE_UDC_OPTION]);
        // The retired six are gone from the whitelist, so a write naming one is
        // refused by the existing not-whitelisted rule rather than quietly stored.
        foreach ([
            'pp_header_bg', 'pp_header_text', 'pp_header_link_color',
            'pp_footer_bg', 'pp_footer_text', 'pp_footer_link_color',
        ] as $retired) {
            $this->assertArrayNotHasKey($retired, $allowed, "{$retired} must not survive ruling A1");

            // AND THE REFUSAL ROUTES. A retired key was a shipped, documented surface,
            // so an agent working from older instructions will reach for it. "Not
            // whitelisted" plus a list of twenty keys makes them guess which one
            // replaced it; naming the replacement is the route back invariant I24 asks
            // for, and it is what makes the removal honest rather than merely complete.
            $result = pp_execute_action('update_site_option', ['key' => $retired, 'value' => '#101828']);
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('no longer exists', $result['error']);
            $this->assertStringContainsString(PP_SITE_UDC_OPTION, $result['error']);
        }
    }

    public function testBothChromeComponentsDeclareRolesAndAreOnTheEngine(): void
    {
        foreach (['nav', 'footer'] as $name) {
            $this->assertTrue(pp_udc_is_chrome($name));
            $this->assertTrue(pp_udc_is_v2_component($name));
            $roles = pp_udc_component_roles($name);
            $this->assertArrayHasKey('_band', $roles, "{$name} needs the implicit root role");
            $this->assertNotSame([], $roles);
        }
    }

    /**
     * Chrome role defaults are EMPTY, deliberately (see the schemas).
     *
     * Chrome's resting appearance is owned by components.css, and this pin is what
     * keeps the two systems from fighting over the same elements.
     *
     * CORRECTED (#991). This docblock used to say a role default would "lose to it on
     * specificity", which is true of only HALF the defaults tier and pointed at the
     * wrong risk. Measured in Chromium against a temporary schema patch:
     *
     *   - the ROOT `_band` default emits into `@layer pp-zero` and does lose — a
     *     planted `#3d0066` header background never painted;
     *   - an ELEMENT role default (`link`, `link-current`, …) emits UNLAYERED and
     *     WINS — planted defaults rendered rgb(0,160,160) and rgb(255,102,0), beating
     *     components.css outright.
     *
     * So the danger is the opposite of "paints nothing": a non-empty element default
     * would silently ERASE the stylesheet rules it sits above — the hover accents, the
     * current-page accent, the mobile panel. That is why this pin is all-or-nothing.
     * If a future change gives chrome real defaults it must retire the corresponding
     * CSS in the SAME change (nav and footer together — see #994), and this test is
     * the tripwire that forces that pairing. Do not relax it as a staging step.
     */
    public function testChromeRolesShipNoDefaults(): void
    {
        foreach (['nav', 'footer'] as $name) {
            foreach (pp_udc_component_roles($name) as $role => $definition) {
                $this->assertSame(
                    [],
                    $definition['defaults'] ?? [],
                    "{$name}.{$role} declares a default that components.css would outrank"
                );
            }
        }
    }

    /**
     * The header ROW is reachable (#991).
     *
     * `.nav__container` carries the two designable declarations that decide the header
     * bar's proportions — `min-height` (the row's height) and `gap` (the space between
     * logo, hamburger and menu) — and before this role neither was reachable from any
     * authoring surface at all: nav declares zero style slots, and the row is not the
     * `_band` (that is the `<header>` itself, whose background and border are a
     * different question from how tall the row inside it stands).
     *
     * Pinned by NAME and SELECTOR rather than by counting roles, so a rename or a
     * removal fails here rather than silently taking the capability away again. The
     * groups assertion is the part that matters most: a role whose permitted groups
     * omitted `sizing` or `spacing` would exist and still not reach the two values it
     * was added for.
     */
    public function testTheHeaderRowIsReachableThroughTheContainerRole(): void
    {
        $roles = pp_udc_component_roles('nav');

        $this->assertArrayHasKey('container', $roles, 'nav must declare the header-row role');
        $this->assertSame('.nav__container', $roles['container']['selector']);

        foreach (['sizing', 'spacing'] as $group) {
            $this->assertContains(
                $group,
                $roles['container']['groups'],
                "the container role must permit {$group} — it is why the role exists"
            );
        }

        // It is chrome, so it ships no defaults like every other chrome role. Asserted
        // here too, not only in the sweep above, because this role is the newest and
        // the likeliest place for a well-meaning default to be added.
        $this->assertSame([], $roles['container']['defaults'] ?? []);
    }

    public function testAValidChromeMapIsAcceptedThroughTheRealWriteSurface(): void
    {
        $result = $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $stored = pp_udc_site_map();
        $this->assertSame(1, $stored['version'], 'the first write initializes the baseline');
        $this->assertSame(
            ['background' => ['fill' => '#101828']],
            $stored['chrome']['nav']['_band']
        );
    }

    public function testAnUnknownRoleIsRefusedByTheSameEngineABandUses(): void
    {
        $error = $this->validate(['nav' => ['sidebar' => ['background' => ['fill' => '#fff']]]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        // ONE VOCABULARY ON BOTH SURFACES. The same mistake on a band reports
        // `unknown_udc_role`; flattening it to `invalid_option_value` here would mean
        // a caller could not branch on the same code for the same error, on a surface
        // whose whole claim is that it runs the same engine.
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        $this->assertSame(
            $error->get_error_code(),
            pp_udc_validate_map(['sidebar' => []], 'nav')->get_error_code(),
            'chrome and band must report the same code for the same mistake'
        );
        $this->assertStringContainsString('has no UDC role', $error->get_error_message());
        $this->assertStringContainsString('sidebar', $error->get_error_message());
    }

    public function testAnUnknownGroupIsRefusedAndNamesWhatTheRolePermits(): void
    {
        $error = $this->validate(['footer' => ['link' => ['shadow' => ['box' => 'none']]]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unknown_udc_group', $error->get_error_code());
        // "shadow" alone proves only that the message echoes the input. The half that
        // makes this a route back rather than a dead end is the list of what the role
        // DOES permit, so assert that.
        $this->assertStringContainsString('Permitted groups:', $error->get_error_message());
        $this->assertStringContainsString('typography', $error->get_error_message());
    }

    public function testABadValueIsRefusedNamingChromeRoleGroupAndParameter(): void
    {
        $error = $this->validate(['nav' => ['link' => ['typography' => ['size' => 'enormous']]]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $message = $error->get_error_message();
        foreach (['nav', 'link', 'typography', 'size'] as $locator) {
            $this->assertStringContainsString($locator, $message, "the refusal must name {$locator}");
        }
    }

    /**
     * THE url() BAN DOES NOT GET A CHROME EXEMPTION.
     *
     * A2 lets the ENGINE build a url() from an attachment id. Nothing lets an
     * AUTHOR write one, and a new surface is exactly where such an exemption would
     * slip in unnoticed.
     */
    public function testAnAuthorWrittenUrlIsStillRefusedInsideAChromeValue(): void
    {
        $error = $this->validate([
            'nav' => ['_band' => ['background' => ['fill' => 'url(https://evil.example/x.png)']]],
        ]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('url()', $error->get_error_message());
    }

    public function testPresetsAndStatesWorkOnChromeBecauseItIsTheSameEngine(): void
    {
        $result = $this->write([
            'nav' => [
                'link' => [
                    'typography' => [
                        'color'   => '#f7f8fa',
                        ':hover'  => ['color' => '#ffd43b'],
                    ],
                    'motion'     => ['transition-duration' => '200ms'],
                ],
            ],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $css = pp_udc_chrome_authored_css();
        $this->assertStringContainsString('[data-pp-chrome="nav"] .nav__menu ul li a{', $css);
        $this->assertStringContainsString(':hover{color:#ffd43b;}', $css);
        $this->assertStringContainsString('transition-duration:200ms', $css);
        // The engine's reduced-motion guard follows its own motion value onto
        // chrome, exactly as it does on a band.
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function testMintingWorksOnChromeAndProducesBandIdenticalTokenNames(): void
    {
        $result = $this->write([
            'nav' => ['link' => ['typography' => ['size' => ['d' => '16px', 'p' => '14px']]]],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $stored = pp_udc_site_map();
        $this->assertArrayHasKey('_tokens', $stored['chrome']['nav']);
        $this->assertArrayHasKey('link-typography-size-d', $stored['chrome']['nav']['_tokens']);
        $this->assertSame('16px', $stored['chrome']['nav']['_tokens']['link-typography-size-d']);
    }

    // ── #993: the chrome findings channel ───────────────────────────────────

    /**
     * THE DISCLOSURE RIDES THE REAL ENVELOPE, not a helper.
     *
     * The test above proves minting HAPPENS on chrome. This proves the author is TOLD.
     * Before #993 that same write returned `ok: true` with the two literals silently
     * rewritten into token references and no `findings` key at all — §3.1's no-coercion
     * promise, unkept on the one surface that had no channel to keep it on.
     *
     * Driven through pp_execute_action() rather than pp_udc_site_findings(), per 14.1:
     * the producer being right is not the same fact as the envelope carrying it.
     */
    public function testAChromeWriteDisclosesItsMintingOnTheWriteEnvelope(): void
    {
        $result = $this->write([
            'nav' => ['link' => ['typography' => ['size' => ['d' => '19px', 'p' => '15px']]]],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayHasKey('findings', $result, 'a chrome write carries findings since #993');

        $minted = array_values(array_filter(
            $result['findings'],
            static fn (array $f): bool => $f['type'] === 'udc_token_minted'
        ));
        $this->assertCount(2, $minted, 'one per minted token, exactly as a band write reports');

        foreach ($minted as $finding) {
            $this->assertSame('warning', $finding['severity'],
                'every generic consumer branches on severity; a chrome row without it renders as neither');
            $this->assertNull($finding['index'],
                'a chrome entry has no band offset — claiming index 0 would be a fabricated locator (I26)');
            $this->assertStringContainsString('Component "nav"', $finding['message']);
        }
        // The author's literal is what the disclosure names, not the minted reference.
        $this->assertStringContainsString('19px', $minted[0]['message']);
    }

    /** The unused-token disclosure reaches chrome by the same route. */
    public function testAnUnreferencedChromeBandTokenIsDisclosedToo(): void
    {
        $result = $this->write([
            'nav' => [
                '_tokens' => ['nobody-references-me' => '4px'],
                'link'    => ['typography' => ['color' => '#ffffff']],
            ],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $types = array_column($result['findings'], 'type');
        $this->assertContains('udc_unused_band_token', $types);
    }

    /** A clean chrome write reports an empty array, which is a real answer. */
    public function testACleanChromeWriteReportsNoFindingsRatherThanNoKey(): void
    {
        $result = $this->write(['nav' => ['link' => ['typography' => ['color' => '#ffffff']]]]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayHasKey('findings', $result);
        $this->assertSame([], $result['findings']);
    }

    /**
     * The key is attached for `pp_site_udc` and for nothing else.
     *
     * The other whitelisted site options are not `udc` documents, so a findings array on
     * their envelope would be a report about a thing the write did not touch.
     */
    public function testANonUdcSiteOptionWriteGainsNoFindingsKey(): void
    {
        $result = pp_execute_action('update_site_option', ['key' => 'pp_logo_alt', 'value' => 'Acme']);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayNotHasKey('findings', $result);
    }

    /**
     * REPORT-ONLY MUST NOT BE ABLE TO TAKE DOWN THE WRITE IT REPORTS ON (I17).
     *
     * This runs after the row has been written, so anything that throws here turns a
     * change that HAPPENED into a failed response, and a client that retries on failure
     * would write it twice. The producer reads through the fail-closed site reader, so a
     * container that will not decode yields no findings rather than a TypeError.
     */
    public function testTheFindingsProducerDegradesOnACorruptContainerRatherThanThrowing(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{not json at all';
        $this->assertSame([], pp_udc_site_findings());

        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode(['_version' => 1]);
        $this->assertSame([], pp_udc_site_findings(), 'a container with no chrome entries reports nothing');

        unset($GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION]);
        $this->assertSame([], pp_udc_site_findings(), 'an absent row is not a finding');
    }

    /**
     * THE REFUSAL HALF ALREADY WORKED, AND THIS PINS IT so #993's fix cannot be read as
     * having introduced it.
     *
     * pp_udc_validate_site_map() hands every entry to pp_udc_validate_map(), so the whole
     * preset refusal set — the T2 empty-intersection refusal included — has always been
     * shared with bands. What chrome lacked was the DISCLOSURE, not the refusal.
     */
    public function testAPresetRefusalOnChromeIsTheSameRefusalABandGets(): void
    {
        $dangling = $this->validate(['nav' => ['link' => ['_preset' => 'no-such-preset']]]);
        $this->assertInstanceOf(WP_Error::class, $dangling);
        $this->assertStringContainsString('no-such-preset', $dangling->get_error_message());

        $unpermitted = $this->validate(['nav' => ['logo' => ['shadow' => ['_preset' => 'button']]]]);
        $this->assertInstanceOf(WP_Error::class, $unpermitted);
        $this->assertSame('unknown_udc_group', $unpermitted->get_error_code());
    }

    /**
     * ONE ENGINE, NOT TWO — the property that makes the preset-skip disclosure reach
     * chrome even though no shipped preset can demonstrate it there yet.
     *
     * Measured across every nav and footer role, all three system presets apply IN FULL
     * (`button` declares typography/spacing/background/border/sizing/motion; every chrome
     * role permits all six), so no intersection is currently non-empty-but-partial on
     * chrome and `udc_preset_groups_skipped` cannot be provoked end-to-end. Introducing a
     * skipping preset would need a registry seam, which is #974 and is not this change.
     *
     * So the claim being pinned is structural: pp_udc_site_findings() has no walk of its
     * own — it delegates to pp_udc_composition_findings(). Whatever that engine emits for
     * a role reaches chrome by construction, including a disclosure that does not exist
     * yet. A second copy of the walk is exactly what T2 clause 4 forbids, and a source
     * pin is the only thing that catches someone adding one.
     */
    public function testTheChromeFindingsProducerHoldsNoSecondCopyOfTheEngine(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        // The public entry is a Throwable guard around the body, so the walk lives in
        // _pp_udc_site_findings_unguarded(). Both halves are pinned: the entry must
        // delegate to the body, and the body must delegate to the one engine.
        $entry_start = strpos($source, 'function pp_udc_site_findings(');
        $this->assertNotFalse($entry_start, 'the producer must exist to be pinned');
        $entry_end = strpos($source, "\n}\n", $entry_start);
        $this->assertNotFalse($entry_end);
        $this->assertStringContainsString(
            '_pp_udc_site_findings_unguarded()',
            substr($source, $entry_start, $entry_end - $entry_start),
            'the guarded entry must delegate rather than hold a copy of the walk'
        );

        $start = strpos($source, 'function _pp_udc_site_findings_unguarded(');
        $this->assertNotFalse($start, 'the producer body must exist to be pinned');
        $end = strpos($source, "\n}\n", $start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('pp_udc_composition_findings(', $body,
            'the producer must delegate to the one engine');
        foreach (['udc_preset_groups_skipped', 'udc_token_minted', '_pp_udc_split_preset_by_permitted'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                'a chrome-local copy of any part of the walk is the fork clause 4 forbids');
        }

        // And the delegation is real, not just present: every disclosure the engine
        // produces for a chrome-shaped item survives the wrap.
        $item = pp_udc_normalize_band([
            'component' => 'nav',
            'udc'       => ['link' => ['typography' => ['size' => ['d' => '19px', 'p' => '15px']]]],
        ]);
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] =
            (string) wp_json_encode(['_version' => 1, 'nav' => $item['udc']]);

        $this->assertSame(
            array_column(pp_udc_composition_findings([$item]), 'message'),
            array_column(pp_udc_site_findings(), 'message'),
            'chrome and band must produce the identical message set for the identical map'
        );
    }

    /**
     * A truncated chrome report names a command chrome actually has.
     *
     * The shared tail hardcoded `wp pp check page --post_id=<id>`, written when every
     * caller described a page. Chrome has no page, so printing that would be a route to
     * nowhere. The budget is reachable here: the container may hold 64 KB, which is far
     * more than 100 minted tokens' worth of map.
     */
    public function testATruncatedChromeReportPointsAtACommandChromeHas(): void
    {
        $bounded = _pp_bounded_findings(
            array_fill(0, PP_WRITE_FINDINGS_BUDGET + 5, [
                'type' => 'udc_token_minted', 'severity' => 'warning', 'message' => 'x', 'index' => null,
            ]),
            null,
            PP_WRITE_FINDINGS_BUDGET,
            'wp pp operate inspect'
        );

        $tail = end($bounded);
        $this->assertSame('findings_truncated', $tail['type']);
        $this->assertStringContainsString('wp pp operate inspect', $tail['message']);
        $this->assertStringNotContainsString('post_id', $tail['message'],
            'a chrome tail must not send an operator to a page-scoped command');

        // The page wording is untouched for every caller that did not ask for an override.
        $page_bounded = _pp_bounded_findings(
            array_fill(0, PP_WRITE_FINDINGS_BUDGET + 5, [
                'type' => 'x', 'severity' => 'warning', 'message' => 'x', 'index' => null,
            ]),
            77
        );
        $page_tail = end($page_bounded);
        $this->assertStringContainsString('wp pp check page --post_id=77', $page_tail['message']);
    }

    public function testABackgroundImageResolvesOnChromeToo(): void
    {
        $GLOBALS['_pp_test_store']['posts'][42]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = true;

        $result = $this->write([
            'footer' => ['_band' => ['background' => [
                'fill'    => '#101828',
                'image'   => 42,
                'overlay' => 'rgba(0,0,0,0.55)',
            ]]],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $css = pp_udc_chrome_authored_css();
        $this->assertStringContainsString('background-image:linear-gradient(', $css);
        $this->assertStringContainsString('url("https://example.com/wp-content/uploads/image-42.jpg")', $css);
    }

    // ── 2. The container's own rules ────────────────────────────────────────

    public function testAnUnknownTopLevelKeyIsRefusedRatherThanIgnored(): void
    {
        $error = $this->validate(['sidebar' => ['_band' => []]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('no chrome component "sidebar"', $error->get_error_message());
    }

    /**
     * AC5 — per-page chrome overrides are out of scope, and a key shaped like one
     * REFUSES. Accepting and dropping it would tell an author their per-page header
     * landed when nothing had changed.
     */
    public function testAPerPageChromeKeyIsRefusedAndSaysWhy(): void
    {
        foreach ([['42' => ['nav' => []]], ['pages' => ['42' => ['nav' => []]]]] as $map) {
            $error = $this->validate($map);
            $this->assertInstanceOf(WP_Error::class, $error);
            $this->assertStringContainsString(
                'no per-page chrome override',
                $error->get_error_message(),
                'the refusal must say per-page chrome is not a thing, not just "unknown key"'
            );
        }
    }

    public function testMalformedJsonIsRefusedNamingTheOption(): void
    {
        $error = pp_validate_site_option_value(PP_SITE_UDC_OPTION, '{"nav": ');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('did not parse', $error->get_error_message());
    }

    public function testANonObjectValueIsRefused(): void
    {
        foreach (['"a string"', '[1,2,3]', 'null', '42'] as $json) {
            $error = pp_validate_site_option_value(PP_SITE_UDC_OPTION, $json);
            $this->assertInstanceOf(WP_Error::class, $error, "{$json} must be refused");
        }
    }

    public function testAnOversizeValueIsRefusedBeforeItIsParsed(): void
    {
        $error = pp_validate_site_option_value(
            PP_SITE_UDC_OPTION,
            '{"nav":"' . str_repeat('x', PP_SITE_UDC_MAX_BYTES) . '"}'
        );
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('the limit is', $error->get_error_message());
    }

    /**
     * THE CEILING IS MEASURED ON WHAT LANDS, NOT ON WHAT ARRIVES.
     *
     * Found by the performance specialist on this branch, and it was the sharpest
     * defect in the change. Validation checks the SUBMITTED bytes, but storage holds
     * a re-encode: normalization adds `_version` and MINTS tokens, so a responsive
     * value becomes an `@name` reference PLUS a `_tokens` entry and the stored string
     * is strictly larger. A map validating just under the ceiling could normalize
     * past it, be written, be reported `ok: true` — and then read back as corrupt
     * forever, because the reader enforces the same ceiling before it decodes. Every
     * page would silently lose its chrome on the strength of a successful write.
     */
    public function testAMapThatOnlyExceedsTheCeilingAfterMintingIsRefused(): void
    {
        // Responsive values are what expand: each becomes a token reference plus a
        // stored token. Build a map that fits on the way in and would not on the way
        // out, and assert the write refuses rather than certifying an unreadable row.
        // Many breakpoint-keyed values across every footer role: each one mints.
        $footer = [];
        foreach (array_keys(pp_udc_component_roles('footer')) as $role) {
            $footer[$role] = ['spacing' => [
                'padding-top'    => ['d' => '11.111111px', 'p' => '22.222222px'],
                'padding-bottom' => ['d' => '33.333333px', 'p' => '44.444444px'],
                'margin-top'     => ['d' => '55.555555px', 'p' => '66.666666px'],
            ]];
        }
        $submitted = (string) wp_json_encode(['footer' => $footer]);
        $this->assertLessThanOrEqual(
            PP_SITE_UDC_MAX_BYTES,
            strlen($submitted),
            'the fixture must FIT on the way in, or it proves nothing about minting'
        );

        $result = pp_execute_action('update_site_option', [
            'key' => PP_SITE_UDC_OPTION, 'value' => $submitted,
        ]);

        // Either it fits after minting (fine — then the row must be readable) or it
        // does not (then the write must refuse). What must never happen is a
        // successful write whose stored row reads back as corrupt.
        if ($result['ok']) {
            $this->assertFalse(
                pp_udc_site_map()['corrupt'],
                'a write reported ok must not leave a row the reader calls corrupt'
            );
        } else {
            $this->assertStringContainsString('normalized', $result['error']);
            $this->assertArrayNotHasKey(
                PP_SITE_UDC_OPTION,
                $GLOBALS['_pp_test_store']['options'],
                'a refused write must store nothing'
            );
        }
    }

    public function testTheVersionKeyMustBeAWholeNumber(): void
    {
        $error = $this->validate([PP_SITE_UDC_VERSION_KEY => 'seven', 'nav' => []]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('must be a whole number', $error->get_error_message());
        // And it points at the field that IS the baseline, so a caller who confused
        // the two does not walk away with no concurrency protection.
        $this->assertStringContainsString('expected_version', $error->get_error_message());
    }

    // ── 3. CAS (invariant I8) ───────────────────────────────────────────────

    public function testAStaleBaselineIsRefusedAndNothingIsOverwritten(): void
    {
        $first = $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertTrue($first['ok']);
        $this->assertSame(1, pp_udc_site_map()['version']);

        // A second author lands a write while the first is still holding v1.
        $this->assertTrue($this->write(['nav' => ['_band' => ['background' => ['fill' => '#222222']]]], 1)['ok']);
        $this->assertSame(2, pp_udc_site_map()['version']);

        // The first author now writes against the baseline they read: REFUSED.
        $stale = $this->write(['nav' => ['_band' => ['background' => ['fill' => '#ff0000']]]], 1);
        $this->assertFalse($stale['ok']);
        $this->assertSame('site_option_conflict', $stale['error_code']);
        $this->assertStringContainsString('has changed since you read it', $stale['error']);

        // And the landed write is intact — a refusal that still overwrote would be
        // the exact failure I8 exists to prevent.
        $this->assertSame('#222222', pp_udc_site_map()['chrome']['nav']['_band']['background']['fill']);
        $this->assertSame(2, pp_udc_site_map()['version'], 'a refused write must not move the version');
    }

    public function testAMatchingBaselineIsAcceptedAndMovesTheVersion(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertTrue($this->write(['nav' => ['_band' => ['background' => ['fill' => '#333333']]]], 1)['ok']);
        $this->assertSame(2, pp_udc_site_map()['version']);
    }

    public function testANullBaselineSkipsTheCompareExactlyAsTheCompositionCasDoes(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertTrue($this->write(['nav' => ['_band' => ['background' => ['fill' => '#444444']]]])['ok']);
        $this->assertSame(2, pp_udc_site_map()['version']);
    }

    /**
     * A baseline on a key that has no CAS is REFUSED, not ignored.
     *
     * A caller who sends one believes the write is concurrency-protected. Accepting
     * it on `blogname` would report a guarantee that was never applied.
     */
    public function testABaselineOnANonVersionedOptionIsRefused(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key' => 'blogname', 'value' => 'Acme', 'expected_version' => 3,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_param_value', $result['error_code']);
        $this->assertStringContainsString('takes no expected_version', $result['error']);
    }

    // ── 4. Fail-closed reads (I9, I17) ──────────────────────────────────────

    public function testAnAbsentRowReadsAsNoChromeStylingAndVersionZero(): void
    {
        $this->assertSame(['version' => 0, 'chrome' => [], 'corrupt' => false], pp_udc_site_map());
        $this->assertSame('', pp_udc_chrome_authored_css());
        $this->assertSame('', pp_udc_chrome_defaults_css());
    }

    /**
     * A corrupt row degrades to "no chrome styling" and NEVER fatals. This row is
     * autoloaded and read on every front-end request, so a TypeError here is a white
     * screen on every page of the site rather than one broken band.
     */
    public function testACorruptRowDegradesAndNeverFatals(): void
    {
        foreach ([
            'not json at all',
            '{"nav": ',
            '"a string"',
            '[1,2,3]',
            'null',
            '{"nav": "not an object"}',
            '',
        ] as $corrupt) {
            $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = $corrupt;
            $map = pp_udc_site_map();
            $this->assertSame(0, $map['version'], "corrupt value should not report a real baseline: {$corrupt}");
            $this->assertSame([], $map['chrome'], "corrupt value must yield no chrome: {$corrupt}");
            $this->assertSame('', pp_udc_chrome_authored_css());
            // Corrupt is a statement about the CONTAINER. An empty string is absent;
            // a well-formed object with a junk member read fine and is not corrupt.
            $expected_corrupt = !in_array($corrupt, ['', '{"nav": "not an object"}'], true);
            $this->assertSame(
                $expected_corrupt,
                $map['corrupt'],
                "absent / readable-but-useless / unreadable must not all report the same thing: {$corrupt}"
            );
        }
    }

    /**
     * ABSENT AND CORRUPT ARE DIFFERENT FACTS, and only one is safe to overwrite blind.
     *
     * Caught by the Codex adversarial pass on this branch, and it was real: an
     * unreadable row reported version 0 for styling purposes, which is ALSO what a
     * never-written row reports. A caller holding `expected_version: 0` — an
     * ordinary baseline, earned by reading an option that looked empty — therefore
     * passed the CAS and overwrote bytes the operator might want recovered. That is
     * invariant I9's "a failed read is never mapped to a valid answer", with the
     * valid answer being "the empty site".
     *
     * Every BASELINED write over a corrupt row now refuses, whatever the baseline.
     */
    public function testNoBaselinedWriteCanOverwriteACorruptRowBlind(): void
    {
        foreach ([0, 1, 4] as $baseline) {
            $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{corrupt-bytes';
            $result = $this->write(['nav' => ['_band' => ['background' => ['fill' => '#000000']]]], $baseline);
            $this->assertFalse($result['ok'], "baseline {$baseline} must not overwrite a corrupt row");
            $this->assertSame('site_option_corrupt', $result['error_code']);
            $this->assertStringContainsString('could not be read', $result['error']);
            $this->assertSame(
                '{corrupt-bytes',
                $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION],
                'the unreadable bytes must survive the refusal'
            );
        }
    }

    /**
     * ...and the recovery route stays open. A write with NO baseline is the caller
     * saying "I know what is there and I am replacing it", which is exactly how an
     * operator fixes a corrupt row. Refusing that too would strand the site.
     */
    public function testAnUnbaselinedWriteCanStillReplaceACorruptRow(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{corrupt-bytes';
        $result = $this->write(['nav' => ['_band' => ['background' => ['fill' => '#000000']]]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(1, pp_udc_site_map()['version'], 'a replaced corrupt row starts the count fresh');
    }

    /** The read itself reports WHICH it saw, so no caller has to infer it. */
    public function testTheReaderDistinguishesAbsentFromCorrupt(): void
    {
        $this->assertFalse(pp_udc_site_map()['corrupt'], 'an absent row is not corrupt');

        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{corrupt';
        $this->assertTrue(pp_udc_site_map()['corrupt']);
        $this->assertSame(0, pp_udc_site_map()['version']);

        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] =
            '{"nav":"' . str_repeat('x', PP_SITE_UDC_MAX_BYTES) . '"}';
        $this->assertTrue(pp_udc_site_map()['corrupt'], 'oversize is unreadable, not empty');
    }

    public function testAnOversizeStoredRowIsNotParsedAtRenderTime(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] =
            '{"nav":"' . str_repeat('x', PP_SITE_UDC_MAX_BYTES) . '"}';
        $map = pp_udc_site_map();
        $this->assertSame(0, $map['version']);
        $this->assertSame([], $map['chrome']);
        $this->assertTrue($map['corrupt'], 'oversize is unreadable, not empty');
    }

    // ── 5. Emission ─────────────────────────────────────────────────────────

    public function testAuthoredChromeEmitsUnderTheChromeScope(): void
    {
        $this->write(['footer' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $css = pp_udc_chrome_authored_css();
        $this->assertStringContainsString('[data-pp-chrome="footer"]{background:#101828;}', $css);
        // And never under a band scope: chrome has no band id and never will.
        $this->assertStringNotContainsString('data-pp-band', $css);
    }

    /**
     * SOURCE-PINNED, because the output cannot carry this one.
     *
     * Chrome ships no role defaults, so pp_udc_chrome_defaults_css() returns '' —
     * which it would ALSO return if the `:where()` wrapper were deleted. An
     * output assertion here is green either way and proves nothing, which is what
     * the testing specialist caught. The claim is about the CALL, so the call is
     * what gets pinned: a root-scoped chrome default must sit at zero specificity
     * or it outranks the structural rule it is supposed to yield to, and that only
     * becomes visible the day someone adds a default.
     */
    public function testTheChromeDefaultsTierIsEmittedAtZeroRootSpecificity(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertSame('', pp_udc_chrome_defaults_css(), 'no chrome defaults ship today');

        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/_pp_udc_render_blocks\(\s*\\\$compiled,\s*\\\$scope,\s*':where\('/",
            $source,
            'the chrome defaults tier must pass a :where()-wrapped root scope, so a chrome role '
            . 'default can never outrank the structural CSS it yields to'
        );
    }

    /**
     * REPLACE, NOT MERGE — and it is destructive, so it is a pinned decision.
     *
     * A write names the components it carries and the normalizer writes only those,
     * so styling the footer alone discards the header. That is defensible (one
     * option, one document) but it is the kind of semantic that should be chosen
     * rather than emerge, and a future change to merge-instead-of-replace has to
     * break this test to happen.
     */
    public function testAWriteReplacesTheWholeContainerRatherThanMergingIntoIt(): void
    {
        $this->assertTrue($this->write([
            'nav'    => ['_band' => ['background' => ['fill' => '#101828']]],
            'footer' => ['_band' => ['background' => ['fill' => '#222222']]],
        ])['ok']);
        $this->assertSame(['nav', 'footer'], array_keys(pp_udc_site_map()['chrome']));

        // Now write the footer alone, carrying the baseline the round trip gives.
        $this->assertTrue($this->write(
            ['footer' => ['_band' => ['background' => ['fill' => '#333333']]]],
            1
        )['ok']);

        $stored = pp_udc_site_map()['chrome'];
        $this->assertArrayNotHasKey('nav', $stored, 'a component left out of a write is dropped');
        $this->assertSame('', pp_udc_chrome_css('nav', 'authored'));
    }

    /**
     * THE ROUND TRIP IS PROTECTED WITHOUT ASKING. Reading the map, editing a role and
     * sending the whole thing back carries the `_version` the engine wrote — so that
     * value is taken as the baseline. Accepting it and discarding it, which is what
     * this did first, left the caller believing a guarantee that never ran.
     */
    public function testAVersionCarriedInThePayloadActsAsTheBaseline(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertTrue($this->write(['nav' => ['_band' => ['background' => ['fill' => '#222222']]]], 1)['ok']);
        $this->assertSame(2, pp_udc_site_map()['version']);

        // A stale round trip: the whole document as it was read at version 1.
        $stale = pp_execute_action('update_site_option', [
            'key'   => PP_SITE_UDC_OPTION,
            'value' => (string) wp_json_encode([
                PP_SITE_UDC_VERSION_KEY => 1,
                'nav' => ['_band' => ['background' => ['fill' => '#ff0000']]],
            ]),
        ]);
        $this->assertFalse($stale['ok'], 'a stale round trip must be refused, not silently applied');
        $this->assertSame('site_option_conflict', $stale['error_code']);
        $this->assertSame('#222222', pp_udc_site_map()['chrome']['nav']['_band']['background']['fill']);
    }

    /** Two disagreeing baselines is a caller confusion, not something to resolve quietly. */
    public function testTwoDisagreeingBaselinesAreRefused(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $result = pp_execute_action('update_site_option', [
            'key'              => PP_SITE_UDC_OPTION,
            'expected_version' => 1,
            'value'            => (string) wp_json_encode([
                PP_SITE_UDC_VERSION_KEY => 7,
                'nav' => ['_band' => ['background' => ['fill' => '#ff0000']]],
            ]),
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('two different baselines', $result['error']);
    }

    /**
     * CHROME STYLING CAN BE REMOVED. Until the clear path existed there was no
     * reachable way: '' failed as unparseable JSON and '{}' failed the container
     * shape check, so an option documented as Optional could not be un-set.
     */
    public function testChromeStylingCanBeClearedAndTheRowGoesAway(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertNotSame('', pp_udc_chrome_authored_css());

        foreach (['', '{}'] as $clear) {
            $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
            $result = pp_execute_action('update_site_option', [
                'key' => PP_SITE_UDC_OPTION, 'value' => $clear,
            ]);
            $this->assertTrue($result['ok'], "'{$clear}' must clear: " . ($result['error'] ?? ''));
            $this->assertSame('', pp_udc_chrome_authored_css());
            $this->assertSame(
                ['version' => 0, 'chrome' => [], 'corrupt' => false],
                pp_udc_site_map(),
                'a cleared row reads as ABSENT, not as an empty-but-versioned container'
            );
        }
    }

    /**
     * I1 ON THE CLEAR ARM. The write arm has reported a refused write since #981;
     * the clear arm eight lines away returned the closure's bare `true` whatever
     * the store did, so a clear against an unwritable store reported success and
     * left the chrome exactly where it was.
     *
     * Pinned through the real action surface, and with the STORED STATE asserted
     * as well as the envelope: a refusal that reports honestly but silently
     * removed the row would pass an envelope-only assertion.
     */
    public function testAClearTheStoreRefusesIsReportedInsteadOfSucceedingOverIt(): void
    {
        foreach (['', '{}'] as $clear) {
            $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
            $GLOBALS['_pp_test_unwritable_options'][PP_SITE_UDC_OPTION] = true;

            $result = pp_execute_action('update_site_option', [
                'key' => PP_SITE_UDC_OPTION, 'value' => $clear,
            ]);

            unset($GLOBALS['_pp_test_unwritable_options'][PP_SITE_UDC_OPTION]);

            $this->assertFalse(
                $result['ok'],
                "'{$clear}' against a store that refused the delete must not report success"
            );
            $this->assertSame('site_option_write_failed', $result['error_code']);
            $this->assertStringContainsString(
                PP_SITE_UDC_OPTION,
                $result['error'],
                'the refusal must name the option it could not clear'
            );
            $this->assertArrayHasKey(
                'nav',
                pp_udc_site_map()['chrome'],
                'the chrome is still stored, which is what makes the success report a lie'
            );
            $this->assertNotSame('', pp_udc_chrome_authored_css());
        }
    }

    /**
     * THE INSTRUMENT, NOT THE OUTCOME. The unit harness has no `notoptions` cache,
     * so a get_option() read-back and a row read behave identically here and no
     * behavioural test can tell them apart. In production they do not: core's
     * delete_option() poisons `notoptions` UNCONDITIONALLY, before the `if ($result)`
     * that returns false, and get_option() short-circuits on `notoptions` before it
     * reaches the DB — so a get_option() read-back answers "gone" for exactly the
     * refused delete the branch above exists to catch.
     *
     * That is not a hypothetical: the first cut of this fix used get_option() and was
     * green on the whole suite while being inert in production. A behavioural pin
     * cannot fail on it, so this one reads the source.
     */
    public function testTheClearArmConfirmsTheRemovalAgainstTheRowNotTheOptionCache(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/wp.php');
        $start  = strpos($source, 'function _pp_update_site_udc(');
        $this->assertNotFalse($start, '_pp_update_site_udc() must exist');
        $clear = substr($source, $start, strpos($source, '// THE WRITE PATH', $start) !== false
            ? strpos($source, '// THE WRITE PATH', $start) - $start
            : 4000);

        $this->assertStringContainsString(
            '_pp_read_site_udc_locked($wpdb)',
            $clear,
            'the clear arm must confirm the removal through the row-authoritative reader'
        );
        $this->assertStringNotContainsString(
            'get_option(PP_SITE_UDC_OPTION',
            $clear,
            'a get_option() read-back here is defeated by the notoptions cache core '
            . 'poisons before delete_option() returns false'
        );
    }

    /**
     * I1's second clause on the same arm: clearing a row that is ALREADY absent is
     * a success, not a failure. delete_option() returns false for both "refused"
     * and "there was nothing there", and only the first is a failure.
     */
    public function testClearingChromeThatIsAlreadyAbsentSucceeds(): void
    {
        $this->assertSame(['version' => 0, 'chrome' => [], 'corrupt' => false], pp_udc_site_map());

        $result = pp_execute_action('update_site_option', [
            'key' => PP_SITE_UDC_OPTION, 'value' => '',
        ]);

        $this->assertTrue($result['ok'], 'nothing to remove is not a failure to remove');
    }

    /** A corrupt MARKER on a readable map: the chrome still loads, the baseline does not. */
    public function testANonNumericVersionOnAReadableMapZeroesTheBaselineWithoutLosingTheChrome(): void
    {
        foreach (['"seven"', '-3', '1.5'] as $bad) {
            $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] =
                '{"' . PP_SITE_UDC_VERSION_KEY . '":' . $bad
                . ',"nav":{"_band":{"background":{"fill":"#101828"}}}}';
            $map = pp_udc_site_map();
            $this->assertSame(0, $map['version'], "a {$bad} marker must not be read as a baseline");
            $this->assertFalse($map['corrupt'], 'the CONTAINER read fine; only the marker is junk');
            $this->assertArrayHasKey('nav', $map['chrome'], 'the styling still loads');
            // And a caller holding a real baseline is refused rather than clobbering.
            $this->assertSame(
                'site_option_conflict',
                $this->write(['nav' => ['_band' => ['background' => ['fill' => '#000']]]], 5)['error_code']
            );
        }
    }

    public function testChromeCssIsNotGatedOnThePageComposition(): void
    {
        $this->write(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        // pp_udc_current_composition() answers [] off a singular page — a 404, a
        // search results page, an archive. Chrome renders on all of them, so its CSS
        // must not vanish with the composition.
        $this->assertSame([], pp_udc_current_composition());
        $this->assertNotSame('', pp_udc_chrome_authored_css());
    }

    public function testAnUnknownChromeNameEmitsNothing(): void
    {
        $this->assertSame('', pp_udc_chrome_css('sidebar', 'authored'));
        $this->assertSame('', pp_udc_chrome_css('testimonials', 'authored'));
    }

    public function testBothChromeTemplatesCarryTheScopeAttributeAndNoStyleAttribute(): void
    {
        foreach (['nav', 'footer'] as $name) {
            $template = file_get_contents(dirname(__DIR__) . "/components/{$name}/{$name}.php");
            $this->assertStringContainsString('data-pp-chrome="' . $name . '"', $template);
            $this->assertStringNotContainsString('pp_chrome_style_attr', $template);
        }
    }

    /**
     * THE REMOVAL IS DISCLOSED TO AN INSTALL THAT ALREADY HOLDS THE ROWS.
     *
     * A write to a retired key is refused with a route. An install that already SET
     * one gets no write to refuse: the row stays in the database, drops off the
     * whitelist, stops being read, and the site's dark header reverts to stock on
     * upgrade. There is no migration by directive — but this branch added an advisory
     * for a dropped background image on the reasoning that a silent drop is the
     * reported-success-without-effect class, and its own removal is held to that.
     */
    public function testAnInstallStillHoldingARetiredChromeOptionIsTold(): void
    {
        $this->assertSame([], pp_check_retired_chrome_options(), 'a clean install reports nothing');

        $GLOBALS['_pp_test_store']['options']['pp_header_bg']   = '#101828';
        $GLOBALS['_pp_test_store']['options']['pp_footer_text'] = '#e8e8f0';

        $rows = pp_check_retired_chrome_options();
        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['severity'], 'advisory only — never blocks a mutation');
        $this->assertTrue($rows[0]['acknowledgeable']);
        $this->assertStringContainsString('pp_header_bg', $rows[0]['message']);
        $this->assertStringContainsString('pp_footer_text', $rows[0]['message']);
        $this->assertStringContainsString(PP_SITE_UDC_OPTION, $rows[0]['message']);
        $this->assertStringContainsString(PP_SITE_UDC_OPTION, $rows[0]['next_action']);

        // And it reaches the operator through preflight, like its siblings.
        $checks = pp_preflight()['checks'];
        $this->assertNotSame(
            [],
            array_filter($checks, static fn(array $c): bool => ($c['check'] ?? '') === 'retired_chrome_options'),
            'the advisory must be spliced into preflight, or nobody sees it'
        );
    }

    /**
     * A ROLLBACK IS NEVER BLOCKED BY CURRENT VALIDATION RULES (#233/#281).
     *
     * This invariant came from the deleted HeaderChromeTest and had no successor —
     * caught by the testing specialist. It matters MORE on pp_site_udc than it did on
     * a colour: this key's validator is far stricter (byte cap, JSON container shape,
     * the whole role/group/value grammar), so a restore that routed through it could
     * fail to roll chrome back AND leave the version baseline desynced. The restore
     * path replays a captured baseline VERBATIM, and that has to stay true.
     */
    public function testARestoreReplaysAChromeBaselineThatTodaysRulesWouldRefuse(): void
    {
        // A baseline today's validator rejects outright: an unknown chrome component.
        $legacy = '{"_version":4,"sidebar":{"_band":{"background":{"fill":"#101828"}}}}';
        $this->assertInstanceOf(
            WP_Error::class,
            pp_validate_site_option_value(PP_SITE_UDC_OPTION, $legacy),
            'the fixture must be something the write path would REFUSE, or it proves nothing'
        );

        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{"_version":9,"nav":{}}';
        // A full-shaped snapshot bundle; only the site_options arm is under test.
        $report = _pp_restore_batch_snapshot_report([
            'posts'               => [],
            'compositions'        => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'redirects'           => [],
            'redirects_written'   => [],
            'unreadable'          => [],
            'site_options'        => [PP_SITE_UDC_OPTION => ['exists' => true, 'value' => $legacy]],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => ['locations' => []],
        ]);

        $this->assertSame(
            $legacy,
            get_option(PP_SITE_UDC_OPTION),
            'the captured baseline must be replayed verbatim, not re-validated'
        );
        $this->assertSame([], $report['errors'] ?? [], 'a faithful restore reports no error');
    }

    /** The retired helper is gone, not merely unused. */
    public function testTheInlineChromeStyleHelperNoLongerExists(): void
    {
        $this->assertFalse(
            function_exists('pp_chrome_style_attr'),
            'an inline chrome style attribute would outrank the UDC block it replaced'
        );
    }
}
