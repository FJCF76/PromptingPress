/**
 * Docs Lint Regression Guards (#250)
 *
 * The live doc prose (README.md, AI_RULES.md) must not assert a hardcoded test
 * count. Any exact number baked into prose re-drifts the moment a test is added:
 * the "34 E2E specs" claim was accurate when written, then drifted to 47, then
 * to 48 while #250 sat in the queue. Describe the coverage areas, not the count.
 *
 * The shields.io "Tests-N+_passing" badge is deliberately EXEMPT. It is a floor
 * claim ("at least N"), so adding tests keeps it true; only removing tests below
 * the floor makes it wrong. Badge lines are stripped before scanning.
 *
 * Historical records are deliberately NOT linted. CHANGELOG.md and readme.txt's
 * "== Changelog ==" entries state what shipped in a given release; their counts
 * were true at that tag and rewriting them would falsify release history.
 */

const fs = require('fs');
const path = require('path');

const read = (f) => fs.readFileSync(path.resolve(__dirname, '../..', f), 'utf-8');

// Strip shields.io badge lines: the Tests badge is an exempt floor claim.
const stripBadges = (md) => md.replace(/^.*img\.shields\.io.*$/gm, '');

const README = stripBadges(read('README.md'));
const AI_RULES = stripBadges(read('AI_RULES.md'));

const LIVE_DOCS = [
    ['README.md', README],
    ['AI_RULES.md', AI_RULES],
];

// Runtime AI-facing docs: the chat AI reads these while operating a site (the
// retheme flow especially). A hardcoded design-token count here is a
// correctness problem, not just stale marketing copy — see issue 286. The
// count drifted 47 -> 51 as tokens were added to base.css; the fix is to stop
// asserting a number, not to refresh it. base.css is the source of truth.
const AI_CONTEXT = stripBadges(read('AI_CONTEXT.md'));
const RETHEME = stripBadges(read('ai-instructions/retheme.md'));

const RUNTIME_AI_DOCS = [
    ['AI_RULES.md', AI_RULES],
    ['AI_CONTEXT.md', AI_CONTEXT],
    ['ai-instructions/retheme.md', RETHEME],
];

// A count-shaped claim: a number, then up to three words, then a test noun.
// Catches "1230 PHP tests", "409 JS unit tests", "48 Playwright E2E specs",
// "34 specs:", "1479 tests, 5496 assertions". Deliberately does NOT match
// legitimate prose like "20 typed actions" or "WordPress 7.0" (no test noun).
// Horizontal whitespace only: \s would span newlines and pair an unrelated
// number with a test noun on the next line (e.g. port "8889" + "npm run test").
const COUNT_CLAIM = /\d[\d,]*[ \t]+(?:[A-Za-z0-9.+/-]+[ \t]+){0,3}(?:tests?|specs?|assertions)\b/i;

describe('docs lint: no hardcoded test counts in live docs', () => {
    test.each(LIVE_DOCS)('%s asserts no hardcoded test count', (name, content) => {
        const hits = content.match(new RegExp(COUNT_CLAIM, 'gi')) || [];
        expect(hits).toEqual([]);
    });
});

// A design-token total-count claim: "47 design tokens", "51 total design
// tokens", "47 CSS custom properties", or a bare "47 tokens". Three arms:
//   1. number + up to 3 qualifier words + "design tokens"  (catches rephrases
//      like "51 total design tokens" / "51 available design tokens")
//   2. number + up to 3 qualifier words + "CSS custom properties"
//   3. number DIRECTLY before "tokens" (0 words between)
// Arm 3 stays at zero intervening words on purpose: it flags a bare count like
// "47 tokens" while leaving the one legitimate subset phrase in these docs,
// "8 base color tokens" (retheme.md Step 1, self-verifying against the 8 seed
// colors listed right below it), untouched — "base color" between the number
// and "tokens" keeps it out of arm 3, and it has no "design"/"CSS custom" to
// trip arms 1-2. The fix for a real regression is to drop the number, not
// refresh 47 -> 51 (issue 286).
const TOKEN_COUNT_CLAIM = new RegExp(
    [
        '\\d[\\d,]*[ \\t]+(?:[A-Za-z-]+[ \\t]+){0,3}design[ \\t]+tokens?\\b',
        '\\d[\\d,]*[ \\t]+(?:[A-Za-z-]+[ \\t]+){0,3}CSS[ \\t]+custom[ \\t]+propert(?:y|ies)\\b',
        '\\d[\\d,]*[ \\t]+tokens?\\b',
    ].join('|'),
    'i',
);

describe('docs lint: no hardcoded design-token count in runtime AI docs', () => {
    test.each(RUNTIME_AI_DOCS)('%s asserts no hardcoded design-token count', (name, content) => {
        const hits = content.match(new RegExp(TOKEN_COUNT_CLAIM, 'gi')) || [];
        expect(hits).toEqual([]);
    });
});

describe('docs lint: the token-count guard is not decoration', () => {
    // If these ever stop matching, the regex has been loosened into a no-op.
    const MUST_CATCH = [
        '47 design tokens control the entire visual system', // AI_RULES original
        'Update the 47 design tokens via update_design_token', // AI_CONTEXT original
        'Design token defaults (47 design tokens)',            // AI_CONTEXT table original
        '47 CSS custom properties control the entire visual system', // AI_CONTEXT original
        'Design token inventory (47 tokens with current effective values)', // inventory original
        'through 47 design tokens.',                           // retheme original
        '51 design tokens',                                    // the refresh we reject
        '51 tokens',
        '51 total design tokens',                              // rephrase with a qualifier word
        '51 available design tokens',                          // rephrase with a qualifier word
    ];
    const MUST_NOT_CATCH = [
        'The 8 base color tokens in assets/css/base.css',      // qualified subset, self-verifying
        'changing --color-accent auto-derives the four accent variants',
        'automatic backup (keeps last 5)',
        'against a live WordPress 7.0 instance',
    ];

    test.each(MUST_CATCH)('catches a hardcoded token count: %s', (s) => {
        expect(TOKEN_COUNT_CLAIM.test(s)).toBe(true);
    });

    test.each(MUST_NOT_CATCH)('does not false-positive on: %s', (s) => {
        expect(TOKEN_COUNT_CLAIM.test(s)).toBe(false);
    });
});

// The inspect field reference in docs/reference-apply-cli.md must name every
// top-level field pp_inspect_site() returns (#216). The doc drifted once already:
// #144 added composition_decode_error and it lived only in AI_CONTEXT.md until #216
// backfilled the CLI reference. This pin extracts the keys from the source of truth
// (the return array in lib/operate.php, plus the run_id the CLI appends in lib/cli.php)
// so ADDING a field to inspect fails this test until the reference documents it —
// the reference can't silently rot when the output shape grows.
const OPERATE_PHP = read('lib/operate.php');
const CLI_PHP = read('lib/cli.php');
const APPLY_CLI_DOC = read('docs/reference-apply-cli.md');

// Capture the `return [ ... ];` block inside pp_inspect_site() and pull the quoted
// top-level keys (each line is `'key' => ...`). Anchored to the function so an
// unrelated array literal elsewhere in the file can't leak keys in.
const inspectReturnKeys = () => {
    const fn = OPERATE_PHP.match(/function pp_inspect_site[\s\S]*?\n {4}return \[\n([\s\S]*?)\n {4}\];/);
    if (!fn) return [];
    return [...fn[1].matchAll(/^ {8}'([a-z_]+)'\s*=>/gm)].map((m) => m[1]);
};

// run_id is appended by the CLI command, not by pp_inspect_site(). Only count it
// when the append line is actually present, so this stays tied to the code.
const cliAppendsRunId = /\$result\['run_id'\]\s*=/.test(CLI_PHP);

describe('docs lint: inspect field reference documents every pp_inspect_site() key (#216)', () => {
    const keys = inspectReturnKeys();
    const allKeys = cliAppendsRunId ? [...keys, 'run_id'] : keys;

    test('the key extraction is not a no-op', () => {
        // If the source shape or the regex changes so nothing is extracted, fail
        // loudly instead of passing vacuously. These four must always be present.
        expect(keys).toContain('target');
        expect(keys).toContain('composition_decode_error');
        expect(keys).toContain('token_smells');
        expect(cliAppendsRunId).toBe(true);
    });

    test.each(allKeys.map((k) => [k]))(
        'reference-apply-cli.md documents the `%s` inspect field',
        (key) => {
            expect(APPLY_CLI_DOC).toContain('`' + key + '`');
        },
    );
});

describe('docs lint: no stale #83 quarantine claim', () => {
    // #83 was resolved 2026-07-07 and the broken-media E2E is de-quarantined.
    // Scoped to a quarantine claim naming an issue, so a future legitimate
    // quarantine rule in prose does not trip the guard.
    test.each(LIVE_DOCS)('%s claims no quarantined test against a closed issue', (name, content) => {
        const hits = content.match(/quarantin\w*[^.\n]*#\d+/gi) || [];
        expect(hits).toEqual([]);
    });
});

describe('docs lint: coverage areas survive count removal', () => {
    // Guards against satisfying the count lint by gutting the testing docs:
    // pin representative coverage areas, not just the headings.
    test('README.md still documents each suite and its coverage areas', () => {
        expect(README).toMatch(/PHP unit tests/);
        expect(README).toMatch(/batch atomicity\/rollback/);
        expect(README).toMatch(/JS unit tests/);
        expect(README).toMatch(/serialization invariant/);
        expect(README).toMatch(/E2E specs/);
        expect(README).toMatch(/MySQL advisory lock/);
    });

    test('AI_RULES.md still documents the E2E suite and its coverage areas', () => {
        expect(AI_RULES).toMatch(/## E2E tests/);
        expect(AI_RULES).toMatch(/npm run test:e2e/);
        expect(AI_RULES).toMatch(/serialization gate/);
        expect(AI_RULES).toMatch(/token concurrency/);
    });
});

describe('docs lint: the guard itself is not decoration', () => {
    // If these ever stop matching, the regex has been loosened into a no-op.
    const MUST_CATCH = [
        '1230 PHP tests',           // the original README claim
        '350 JS tests',             // the original README claim
        '34 E2E specs',             // the original README claim
        '34 specs: editor round-trip', // the original AI_RULES claim
        '1479 tests, 5496 assertions',
        '1479 PHP unit tests',      // the phrasing this change standardized on
        '409 JS unit tests',
        '48 Playwright E2E specs',
        '48 E2E tests',
    ];
    const MUST_NOT_CATCH = [
        'schema validation, 20 typed actions, apply layer',
        'against a live **WordPress 7.0** instance (requires Docker)',
        'boot wp-env container (WordPress 7.0)',
    ];

    test.each(MUST_CATCH)('catches a hardcoded count: %s', (s) => {
        expect(COUNT_CLAIM.test(s)).toBe(true);
    });

    test.each(MUST_NOT_CATCH)('does not false-positive on: %s', (s) => {
        expect(COUNT_CLAIM.test(s)).toBe(false);
    });
});

/**
 * #685 — `--post_id=<id>` is the canonical page address on every page-addressed
 * `wp pp operate` command. The positional form and slug resolution were removed
 * in the same release, so a doc still showing `wp pp operate patch 74 ...`
 * documents a command the CLI now refuses.
 *
 * CHANGELOG.md and readme.txt's "== Changelog ==" rollup are deliberately NOT
 * linted, for the same reason the count guards skip them: they state what
 * shipped at a given tag, and rewriting them would falsify release history.
 *
 * The PHP half of this guard (lib/cli.php docblocks, lib/actions.php action
 * descriptions) lives in tests/InvariantTest.php.
 */
describe('docs lint: page-addressed operate commands use the --post_id flag form (#685)', () => {
    // `wp pp operate <sub>` followed by whitespace and a token that is not a
    // flag. `<id>`-style placeholders inside the flag (`--post_id=<id>`) are
    // matched by the negative lookahead on `--`, so only a bare positional hits.
    const POSITIONAL_PAGE_ARG =
        /wp pp operate (?:inspect-composition|patch|composition-history)[ \t]+(?!--)\S/;

    // Discovered, never hand-listed: a curated allowlist lets a doc added next
    // month ship a positional example without ever meeting this guard.
    const ROOT = path.resolve(__dirname, '../..');
    // Historical records — see the file header. Their examples were true at their tag.
    // readme.txt is handled separately below: only its `== Changelog ==` rollup is
    // historical, and the live prose above it is a shipped, publicly rendered doc.
    const HISTORICAL = new Set(['CHANGELOG.md']);
    const mdIn = (dir) =>
        fs.existsSync(path.join(ROOT, dir))
            ? fs.readdirSync(path.join(ROOT, dir))
                .filter((f) => f.endsWith('.md'))
                .map((f) => (dir ? `${dir}/${f}` : f))
            : [];
    const componentReadmes = fs.existsSync(path.join(ROOT, 'components'))
        ? fs.readdirSync(path.join(ROOT, 'components'), { withFileTypes: true })
            .filter((d) => d.isDirectory() && fs.existsSync(path.join(ROOT, 'components', d.name, 'README.md')))
            .map((d) => `components/${d.name}/README.md`)
        : [];
    const LIVE_DOC_FILES = [
        ...mdIn('').filter((f) => !HISTORICAL.has(f)),
        ...mdIn('docs'),
        ...mdIn('ai-instructions'),
        ...componentReadmes,
    ];

    test.each(LIVE_DOC_FILES)('%s addresses pages with --post_id', (file) => {
        const hits = read(file).match(new RegExp(POSITIONAL_PAGE_ARG, 'g')) || [];
        expect(hits).toEqual([]);
    });

    // readme.txt is not .md, so the walk above never sees it, but its Description
    // and Features prose is live doc that WordPress.org renders. Lint everything
    // above the `== Changelog ==` marker; the rollup below it is release history.
    test('readme.txt live prose addresses pages with --post_id', () => {
        const readme = read('readme.txt');
        const marker = readme.indexOf('== Changelog ==');
        const live = marker === -1 ? readme : readme.slice(0, marker);
        expect(marker).toBeGreaterThan(-1);
        expect(live.match(new RegExp(POSITIONAL_PAGE_ARG, 'g')) || []).toEqual([]);
    });

    // Discovery must actually reach the docs an agent reads. A globbing bug that
    // returned [] would make every per-file assertion above vacuously pass.
    test('discovery reaches the known agent-facing docs', () => {
        for (const expected of [
            'README.md',
            'AI_CONTEXT.md',
            'docs/reference-apply-cli.md',
            'ai-instructions/operating-loop.md',
            'components/hero/README.md',
        ]) {
            expect(LIVE_DOC_FILES).toContain(expected);
        }
        expect(LIVE_DOC_FILES).not.toContain('CHANGELOG.md');
        expect(LIVE_DOC_FILES.length).toBeGreaterThan(15);
    });

    // If this stops matching, the regex has been loosened into a no-op.
    test('the guard is not decoration', () => {
        const MUST_CATCH = [
            'wp pp operate inspect-composition 74',
            'wp pp operate inspect-composition about-us',
            'wp pp operate patch 19 --target=hero.subheading --value="New"',
            'wp pp operate composition-history 42',
            'wp pp operate inspect-composition <page>',
        ];
        const MUST_NOT_CATCH = [
            'wp pp operate inspect-composition --post_id=74',
            'wp pp operate patch --post_id=19 --target=hero.subheading --value="New"',
            'wp pp operate composition-history --post_id=<id>',
            'wp pp operate inspect --post_id=42',
            'wp pp operate inspect 42',
        ];
        for (const s of MUST_CATCH) expect(s).toMatch(POSITIONAL_PAGE_ARG);
        for (const s of MUST_NOT_CATCH) expect(s).not.toMatch(POSITIONAL_PAGE_ARG);
    });
});
