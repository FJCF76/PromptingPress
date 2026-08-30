<?php
/**
 * lib/cli.php — PromptingPress WP-CLI Commands
 *
 * Loaded conditionally in functions.php when WP_CLI is active.
 * Provides `wp pp action` subcommands: list, preview, execute.
 */

if (!class_exists('WP_CLI') || !class_exists('WP_CLI_Command')) {
    return;
}

/**
 * Shared tail for every page-addressing refusal (#685). Always appended
 * mid-sentence after a semicolon, so it stays lowercase — #726 removed the last
 * call site that started a sentence with it and had to ucfirst() it.
 *
 * The side effect is named on purpose: `operate inspect` mints a run token, so
 * an agent that is mid-run and mistypes `--post_id` must not adopt the new id
 * and silently lose its existing PREFLIGHT coverage.
 */
const PP_CLI_PAGE_MAP_HINT = 'run `wp pp operate inspect` for the page map (it mints a new run token).';

/**
 * Every page-addressed `wp pp` command, as its full command path (#685, #726).
 *
 * A command belongs here when it addresses ONE page via `--post_id=<id>`. All
 * of them refuse a positional page argument and an invalid `--post_id` value
 * with ONE idiom (the predicates below), so an operator never has to learn which
 * third of the CLI they are talking to.
 *
 * #685 built the idiom but wired only the three `operate` subcommands to it;
 * `check page`, `validate page`, `apply preflight` and `screenshot capture`
 * predate it and were left on a bare `(int)` cast. #726 finished the wiring —
 * see the scope note on _pp_cli_post_id_arg_error() for the ONE `--post_id`
 * consumer that is deliberately still NOT here.
 *
 * SHAPE CONSTRAINT: every entry is exactly THREE space-separated tokens
 * (`pp <namespace> <subcommand>`), because the positional guard reads the
 * command path from `$args[0..2]` and treats `$args[3]` as the first stray
 * token. A four-token page-addressed command would silently escape the guard,
 * so CliGateTest pins the token count rather than trusting this comment.
 *
 * The pre-dispatch positional guard below and CliGateTest's command provider READ
 * this constant. The in-command gate does NOT — each command PASSES its own path
 * as a string literal, so a literal that drifted from this list would make the
 * pre-dispatch refusal and the in-command refusal name two different commands.
 * CliGateTest::testEveryInCommandGateLiteralIsAListedCommand() reads those
 * literals back out of this file and pins them against this constant.
 *
 * Two more guards spell the `operate` triple
 * out as regex alternations and cannot read it — POSITIONAL_PAGE_ARG_PATTERN in
 * tests/InvariantTest.php and POSITIONAL_PAGE_ARG in tests/js/docs-lint.test.js.
 * Those two stay scoped to the `operate` triple ON PURPOSE: they forbid
 * re-documenting a REMOVED positional form, and only those three ever had one.
 * `wp pp check page` never accepted a positional, and widening the pattern to it
 * would flag ordinary prose ("`wp pp check page` reports the same corruption").
 */
const PP_CLI_PAGE_ADDRESSED_COMMANDS = [
    'pp apply preflight',
    'pp check page',
    'pp operate composition-history',
    'pp operate inspect-composition',
    'pp operate patch',
    'pp screenshot capture',
    'pp validate page',
];

/**
 * Commands whose refusal must name an addressing flag BESIDES `--post_id`.
 *
 * Two commands in the list above are not addressed by a page alone, and a
 * refusal that mentions only `--post_id` misdirects them:
 *
 *  - `screenshot capture` — #685's ruling reads "`--post_id=<id>` ... required
 *    unless the command documents an alternative addressing mode, e.g.
 *    `screenshot capture --capture-url`". Someone who typed a URL into
 *    `--post_id` wants that flag, and sending them to the page map instead is a
 *    wrong turn.
 *  - `apply preflight` — its PRIMARY address is the run token, not the page;
 *    `--post_id` only narrows a preflight to one page. Without this note,
 *    `wp pp apply preflight <uuid>` answers with a page-addressing lecture and
 *    sends the operator to look up a page ID when what they mistyped was the run
 *    token. Confidently wrong beats uselessly vague only when it is right.
 *
 * Each `note` is written to read correctly in BOTH message families (the
 * positional refusal and the `--post_id` value refusal), because both append it.
 * `addresses_page` names the flag ONLY when that flag genuinely addresses the
 * target, so the positional guard can tell "you already addressed this another
 * way" from "you addressed nothing". Data-driven rather than an `if` at each
 * message site so a third such command cannot pick up half the treatment.
 */
const PP_CLI_OTHER_ADDRESSING_FLAGS = [
    'pp apply preflight' => [
        // --run-id is REQUIRED but does not address a PAGE, so a stray positional
        // beside it is still a page-addressing question and `addresses_page` stays
        // null. The note exists only to stop the refusal implying the operator must
        // name a page, when a site-scoped preflight is the documented normal path.
        'addresses_page' => null,
        'note'           => 'This command is addressed by its run token, `--run-id=<uuid>`; `--post_id` only narrows the preflight to one page.',
    ],
    'pp screenshot capture' => [
        // --capture-url genuinely addresses the capture target, so a call carrying
        // it IS addressed and a stray token is not a missing address.
        'addresses_page' => 'capture-url',
        'note'           => 'To capture a URL directly instead of a page, use `--capture-url=<url>`.',
    ],
];

/**
 * Has this call addressed its target through the command's OTHER addressing flag?
 *
 * The mirror of _pp_cli_is_canonical_post_id() for commands with a second door.
 * Without it the positional guard reads
 * `wp pp screenshot capture --capture-url=https://example.com/ stray` as
 * UNaddressed and answers "Address the page with the flag form: `--post_id=<id>`",
 * telling the operator to supply an address they already supplied — the exact
 * mistake the already-addressed branch exists to prevent.
 */
function _pp_cli_addressed_by_other_flag(string $command, array $assoc_args): bool {
    $flag = PP_CLI_OTHER_ADDRESSING_FLAGS[$command]['addresses_page'] ?? null;
    if ($flag === null || !array_key_exists($flag, $assoc_args)) {
        return false;
    }
    $value = $assoc_args[$flag];
    return !is_bool($value) && $value !== null && $value !== '';
}

/**
 * How every refusal renders the command it is refusing: `` `wp pp check page` ``.
 *
 * One function so the seven commands cannot drift into two spellings of the same
 * thing across the two predicates — the whole promise of #726 is that a refusal
 * reads the same wherever it comes from.
 */
function _pp_cli_wp_command(string $command): string {
    return '`wp ' . $command . '`';
}

/**
 * The standing addressing rule, stated once. Every `--post_id` refusal opens or
 * closes with it, so it must not drift between branches that are meant to read
 * as one idiom.
 */
const PP_CLI_POST_ID_RULE = 'Pages are addressed by numeric WordPress post ID';

/**
 * The other-addressing-flag sentence for a command, pre-spaced, or '' when
 * `--post_id` is the command's only addressing flag.
 */
function _pp_cli_other_addressing_flags(string $command): string {
    $note = PP_CLI_OTHER_ADDRESSING_FLAGS[$command]['note'] ?? '';
    return $note === '' ? '' : ' ' . $note;
}

/**
 * Is this raw `--post_id` value the canonical decimal form of a real post ID?
 *
 * THE one definition of "addresses a page", shared by the value gate below and
 * the pre-dispatch positional guard. They disagreed before: the guard treated
 * any non-bool value as an address, so `wp pp check page --post_id=about-us 19`
 * answered "the page is already addressed by --post_id, remove 19" — telling the
 * operator to delete the only correct token they typed, and refusing again on the
 * next run for the value it had just called an address.
 *
 * Accepts ONLY a positive base-10 integer whose decimal form survives a round
 * trip through `(int)`. `is_numeric()` would let `1.5`, `1e3`, ` 19` and `-1`
 * through and then silently `(int)`-truncate them into a different post than the
 * operator typed. `ctype_digit()` alone is not enough either: PHP SATURATES an
 * over-large numeric string, so `--post_id=999999999999999999999999` would cast
 * to PHP_INT_MAX, and it canonicalizes `00019` to `19`. The round-trip
 * comparison rejects both — the accepted string is exactly the ID being used.
 *
 * @param mixed $raw The parsed `--post_id` value.
 */
function _pp_cli_is_canonical_post_id($raw): bool {
    if ($raw === null || is_bool($raw) || $raw === '') {
        return false;
    }
    $raw = (string) $raw;
    return ctype_digit($raw) && (int) $raw >= 1 && (string) (int) $raw === $raw;
}

/**
 * The corrected-command SHAPE every `--post_id` refusal ends with (#726).
 *
 * `<id>` is a placeholder on purpose. An earlier wording said
 * "Use `--post_id=42`", and promoting that into a full "run `wp pp check page
 * --post_id=42`" breadcrumb would read as THE corrected command — but nothing
 * here knows which page the operator meant, least of all when they typed a slug.
 * Only the positional guard below composes a real next command, and only when
 * the stray token is already a canonical ID. Everywhere else we show the shape
 * and point at the page map.
 *
 * @param string $command The page-addressed command path, e.g. `pp check page`.
 * @return string  The shape sentence plus the page-map hint.
 */
function _pp_cli_post_id_shape_hint(string $command): string {
    return 'Use the flag form `wp ' . $command . ' --post_id=<id>` with a numeric page ID; '
        . PP_CLI_PAGE_MAP_HINT
        . _pp_cli_other_addressing_flags($command);
}

/**
 * Pure decision for the `--post_id` addressing gate (#685, widened by #726).
 *
 * Pages are addressed by numeric WordPress post ID and nothing else: no slugs,
 * no URLs, no positional form. WordPress URL-to-post resolution used to sit behind a
 * `is_numeric()` fork here, which made `--post_id` a dishonest name the moment
 * it accepted a slug (same principle as the `*_id`-never-`*_url` naming rule).
 *
 * What counts as a usable value is _pp_cli_is_canonical_post_id() — the same
 * predicate the positional guard consults, so the two can no longer disagree
 * about whether a page has been addressed.
 *
 * THREE BRANCHES, BECAUSE TWO WERE A LIE (#726). Until #726 this helper answered
 * "--post_id is required" for FOUR input shapes: absent, bare `--post_id`
 * (bool true), negated `--no-post_id` (bool false), and `--post_id=` (empty
 * string). Three of those four were SUPPLIED on the command line, so the refusal
 * told the operator to add a flag that was already there. `check page` told the
 * same lie for `--post_id=<slug>`, because it `(int)`-cast the slug to 0 and read
 * 0 as absent. The rule now is: only a flag that was never typed is "required";
 * a flag typed without a usable value is "supplied without a value"; a flag with
 * an unusable value is "invalid", quoted back so the operator sees what was read.
 *
 * `array_key_exists()`, not `isset()`, decides absent-vs-supplied: `isset()` is
 * false for a null value, which would report a programmatically-supplied
 * `['post_id' => null]` as never typed. WP-CLI's own parser cannot produce null
 * (Configurator::extract_assoc yields string, bool true, or bool false), so this
 * only matters to in-process callers — but the gate should not have to rely on
 * that to stay honest.
 *
 * Scope: applied to every command in PP_CLI_PAGE_ADDRESSED_COMMANDS — all SEVEN.
 * Exactly ONE `--post_id` consumer stays on the loose `(int)` cast and is NOT in
 * that list: `operate inspect`, whose subject is the SITE (`--post_id` only adds
 * page-specific smells to a site report, so it is an enrichment filter rather
 * than an address). Calling it page-addressed would be a new posture ruling, not
 * an application of #685's, so it was filed rather than fixed here. Note what it
 * still does: a non-numeric value casts to 0, `pp_inspect_site(0)` reads post 0,
 * and the report answers `smells: []` — a clean result about a page it never
 * looked at.
 *
 * The remaining gap is a consistency gap, not a hole: strict is a superset of
 * loose, so any value a loose command canonicalizes is REJECTED outright here
 * rather than silently accepted.
 *
 * Which branch is live: the "required" branch is defense in depth on ALL seven
 * commands, reachable only by an in-process caller. On the five with a REQUIRED
 * `--post_id=<id>` synopsis, WP-CLI's own parameter check reports a wholly absent
 * flag first, quoting that OPTIONS description. On the two OPTIONAL ones
 * (`apply preflight`, `screenshot capture`) absence is legal, and
 * _pp_cli_optional_post_id_arg() short-circuits it to null BEFORE this gate runs.
 * The valueless and invalid branches are the reachable ones, and they are the
 * refusals WP-CLI cannot make: it has no opinion about what `--post_id=about-us`
 * means, and that is exactly the input the removed slug path used to swallow.
 *
 * Split out of the command bodies so all three refusal branches are assertable
 * without WP_CLI::error()'s exit, matching _pp_cli_preflight_coverage_error().
 *
 * @param array  $assoc_args The command's associative arguments.
 * @param string $command    The command path, for the breadcrumb (`pp check page`).
 * @return string|null  The user-facing refusal, or null to accept.
 */
function _pp_cli_post_id_arg_error(array $assoc_args, string $command): ?string {
    $shape = _pp_cli_post_id_shape_hint($command);

    if (!array_key_exists('post_id', $assoc_args)) {
        return _pp_cli_wp_command($command) . ': --post_id is required. ' . PP_CLI_POST_ID_RULE . '. ' . $shape;
    }

    $raw = $assoc_args['post_id'];

    // WP-CLI's parser yields TWO valueless shapes: bare `--post_id` is bool true,
    // and the negated `--no-post_id` is bool false (Configurator::extract_assoc).
    // Neither addresses a page, and WP-CLI's required-option check passes both
    // through (isset(false) is true), so both land here — as "supplied", because
    // that is what the operator did.
    if ($raw === null || is_bool($raw) || $raw === '') {
        return _pp_cli_wp_command($command) . ': --post_id was supplied without a value. ' . PP_CLI_POST_ID_RULE . '. ' . $shape;
    }

    if (!_pp_cli_is_canonical_post_id($raw)) {
        // Quoted back so the operator sees what was READ — and routed through the file's
        // printable owner first (#647), because what was read is raw argv and this sentence
        // goes to a terminal. A canonical id is byte-identical through it; an ANSI or bidi
        // sequence typed into --post_id is not re-emitted as one.
        $shown = _pp_cli_printable((string) $raw);
        return 'Invalid --post_id "' . $shown . '" for ' . _pp_cli_wp_command($command) . '. ' . PP_CLI_POST_ID_RULE . ' only — slugs and URLs are not resolved. ' . $shape;
    }

    return null;
}

/**
 * Applies the `--post_id` addressing gate and returns the resolved post ID.
 *
 * The entry point for the five commands whose synopsis makes `--post_id`
 * REQUIRED, and also the shared gate body: _pp_cli_optional_post_id_arg()
 * delegates here once it has ruled out legitimate absence, so all seven commands
 * validate a SUPPLIED value through this exact path.
 *
 * @param array  $assoc_args The command's associative arguments.
 * @param string $command    The command path, for the breadcrumb.
 * @return int The validated post ID.
 */
function _pp_cli_require_post_id_arg(array $assoc_args, string $command): int {
    $error = _pp_cli_post_id_arg_error($assoc_args, $command);
    if ($error !== null) {
        WP_CLI::error($error);
    }
    return (int) $assoc_args['post_id'];
}

/**
 * The same gate for a command whose `--post_id` is OPTIONAL (`apply preflight`).
 *
 * A separate entry point rather than a flag on the "require" helper: a function
 * named `require_*` that accepts absence reads as a contradiction at every call
 * site. Absence is legal and returns null; anything else — including a valueless
 * `--post_id` — goes through the identical gate, so an optional flag never gets a
 * second, laxer grammar. Note what this deliberately does NOT do: report a
 * valueless `--post_id` here as "required". On this command that would be false
 * twice over — the flag is optional AND it was typed.
 *
 * @param array  $assoc_args The command's associative arguments.
 * @param string $command    The command path, for the breadcrumb.
 * @return int|null The validated post ID, or null when `--post_id` was not given.
 */
function _pp_cli_optional_post_id_arg(array $assoc_args, string $command): ?int {
    if (!array_key_exists('post_id', $assoc_args)) {
        return null;
    }
    return _pp_cli_require_post_id_arg($assoc_args, $command);
}

/**
 * Pure decision for the positional-page-argument refusal (#685).
 *
 * WHY THIS RUNS BEFORE DISPATCH. Once `<page>` leaves a command's docblock
 * synopsis, WP-CLI's own `Subcommand::validate_args()` rejects the leftover
 * token with `Too many positional arguments: 19` — a message that never names
 * the flag form — and it does so BEFORE the command callable and before any
 * `before_invoke:` hook. `before_run_command` (the first statement of
 * `WP_CLI\Runner::run_command()`) is the only hook that fires early enough to
 * replace that message with a breadcrumb, so the refusal is registered there
 * rather than inside the page-addressed command bodies. WP-CLI's generic refusal stays
 * underneath as the fail-closed backstop: if this hook never fires, a positional
 * is still rejected, just less helpfully.
 *
 * `$args` here carries only the command path plus POSITIONAL tokens —
 * `WP_CLI\Configurator::extract_assoc()` has already split `--flag` and
 * `--flag=value` into $assoc_args — so `wp pp operate patch --post_id=19` has no
 * index 3 and cannot trip this guard. The space form `--post_id 19` DOES trip
 * it (WP-CLI parses that as `post_id => true` plus a positional `19`), which is
 * the right outcome: the breadcrumb names the `=` form the operator meant.
 *
 * COMMAND PATH, NOT `pp operate` (#726). #685 hardcoded the `pp operate` prefix
 * here, so `wp pp check page 234` fell through to WP-CLI's bare
 * `Too many positional arguments: 234`. The guard now matches the whole
 * three-token path against PP_CLI_PAGE_ADDRESSED_COMMANDS, which is why that
 * constant's entries must all be exactly three tokens (a four-token command
 * would put its first stray token at index 4 and escape this check entirely).
 *
 * ERROR PRECEDENCE. This fires before WP-CLI validates the synopsis, so on a
 * call with two mistakes — `wp pp apply preflight 234` is missing its required
 * `--run-id` AND carries a stray positional — the addressing refusal is what the
 * operator sees; the `--run-id` refusal follows on the next attempt. That
 * ordering is deliberate: the positional is the mistake this message can
 * actually explain, and it is the one WP-CLI explains worst.
 *
 * @param array $args       The raw WP-CLI argument vector (command path + positionals).
 * @param array $assoc_args The parsed flags, used only to tell "you addressed the
 *                          page positionally" apart from "you already addressed it
 *                          with --post_id and left a stray token behind".
 * @return string|null  The user-facing refusal, or null to accept.
 */
function _pp_cli_positional_page_arg_error(array $args, array $assoc_args = []): ?string {
    if (!isset($args[3])) {
        return null;
    }

    $command = implode(' ', array_slice($args, 0, 3));
    if (!in_array($command, PP_CLI_PAGE_ADDRESSED_COMMANDS, true)) {
        return null;
    }

    $extra      = (string) $args[3];
    $wp_command = 'wp ' . $command;
    // The stray token is raw argv and every branch below quotes it back to a terminal, one
    // of them twice and ending on an instruction the operator is meant to act on (#647).
    // Stripped ONCE here rather than at each interpolation so no branch can be added later
    // without it. The composed corrected command below deliberately keeps using $extra: it
    // is gated on _pp_cli_is_canonical_post_id(), so only decimal digits can reach it, and
    // the predicate — not this strip — is what makes that line safe to print.
    $shown      = _pp_cli_printable($extra);

    // The page is already addressed, so the stray token is not a page address —
    // do not lecture about --post_id or compose an address out of it, and never
    // echo the typed --post_id value back as if the positional had set it. Both
    // valueless shapes (bare `--post_id` = true, `--no-post_id` = false), and a
    // null from an in-process caller, address nothing, so they stay on the
    // addressing path where the composed breadcrumb is the useful answer.
    // A target addressed through EITHER door counts: `--post_id` with a canonical
    // value, or the command's own alternative flag (`--capture-url`). Otherwise
    // `wp pp screenshot capture --capture-url=https://x/ stray` would be told to
    // "address the page", which it already did.
    $addressed = (array_key_exists('post_id', $assoc_args)
            && _pp_cli_is_canonical_post_id($assoc_args['post_id']))
        || _pp_cli_addressed_by_other_flag($command, $assoc_args);
    if ($addressed) {
        return _pp_cli_wp_command($command) . ' got an unexpected positional argument ("' . $shown . '"). '
            . 'This command takes flags only; the target is already addressed. '
            . 'Remove "' . $shown . '" and re-run.';
    }

    $message = _pp_cli_wp_command($command) . ' takes no positional page argument (got "' . $shown . '"). '
        . 'Address the page with the flag form: `' . $wp_command . ' --post_id=<id>`.';

    // The ONLY place a refusal composes a command the operator is told to RUN, so
    // it is gated on the same canonical predicate the value gate uses: a token that
    // is not already a bare decimal ID can never reach a suggested command line.
    // The composed line corrects the ADDRESSING and nothing else — it cannot supply
    // whatever else the command requires (`--run-id` on preflight, `--target` and
    // `--value` on patch), which is why the other-addressing note rides along.
    if (_pp_cli_is_canonical_post_id($extra)) {
        return $message . ' For this call, the page part is `' . $wp_command . ' --post_id=' . $extra . '`.'
            . _pp_cli_other_addressing_flags($command);
    }

    return $message . ' Slugs and URLs are not resolved; ' . PP_CLI_PAGE_MAP_HINT
        . _pp_cli_other_addressing_flags($command);
}

/**
 * Refuses a positional page argument before WP-CLI's generic synopsis check.
 *
 * @param array $args       The raw WP-CLI argument vector.
 * @param array $assoc_args The parsed flags.
 */
function _pp_cli_reject_positional_page_arg(array $args, array $assoc_args = []): void {
    $error = _pp_cli_positional_page_arg_error($args, $assoc_args);
    if ($error !== null) {
        WP_CLI::error($error);
    }
}

WP_CLI::add_hook('before_run_command', '_pp_cli_reject_positional_page_arg');

/**
 * THE machine-readable stdout sink: every JSON document this file prints (#717).
 *
 * Before #717 each of the 29 emit sites called `json_encode()` inline with a
 * hand-maintained flag list, and two defects rode on all of them.
 *
 *  1. NOTHING CHECKED THE RETURN. `json_encode()` answers `false` — not a
 *     string — on malformed UTF-8, on nesting past 512, on recursion, on
 *     INF/NAN. `WP_CLI::line(false)` prints an EMPTY LINE, and on the write
 *     paths the very next branch still runs `WP_CLI::success(...)`. The caller
 *     got a blank line plus a success message and lost `ok`, `error_code`,
 *     `composition_version`, `changes` and `findings` for a mutation that had
 *     ALREADY PERSISTED. Measured, and reachable without any raw
 *     `_pp_composition` write: `update_site_option` reports `changes[].from`
 *     read straight out of the DB, so one bad byte in a legacy/imported option
 *     value destroys the receipt of a write that landed.
 *
 *  2. `JSON_UNESCAPED_UNICODE` handed the terminal raw U+202E (RLO), U+2066
 *     and U+200B out of stored component names, slot names and prop values.
 *     The read-only diagnostics path already refused to do that
 *     (_pp_cli_finding_line / _pp_cli_printable below), and `operate patch`
 *     already omitted the flag — the surfaces disagreed with each other.
 *
 * THE FLAGS, and why this set. `JSON_INVALID_UTF8_SUBSTITUTE` turns a bad byte
 * into U+FFFD instead of destroying the document: the envelope still names the
 * action, the error code and the version, and the damaged span is visible
 * rather than silent. Dropping `JSON_UNESCAPED_UNICODE` is the SINK-level half
 * of the bidi defense and is LOSSLESS: the escape `\u202e` decodes back to the
 * very same string in any JSON parser, while the emitted bytes stay printable
 * ASCII, so nothing the terminal acts on survives. Stripping the characters
 * instead (the other option #717 floated) would destroy content the operator
 * cannot recover, and bounding what a MESSAGE reflects is a separate recorded
 * axis (#647/#649) — this function is the SINK, not the message.
 *
 * Two ranges JSON does not settle on its own, so this does. Control characters
 * below U+0020 were never at risk: JSON escapes those unconditionally, whatever
 * the flags say, so an ANSI `ESC` has always arrived as the escape `\u001b`.
 * `DEL` (U+007F) is the exception — a control character JSON leaves alone,
 * which dropping `JSON_UNESCAPED_UNICODE` does not touch either, so it is
 * escaped explicitly below. That replace is byte-level and stays correct even
 * under `$raw_unicode`: 0x7F cannot occur inside a UTF-8 multi-byte sequence.
 *
 * `$raw_unicode` is the ONE documented deviation, and `wp pp schema` is its only
 * caller — both of its emits: `pp_component_schema_index()` and
 * `pp_component_schema_report()` (`lib/operate.php`). That surface prints shipped
 * `schema.json` prose (em dashes, typographic quotes) for an agent that cannot
 * open the file, and BOTH builders walk `pp_get_registered_components()` and
 * nothing else. State the rule for future callers precisely, because the loose
 * version ("it does not read the database") claims more than actually holds: the
 * payload must derive SOLELY FROM FILES UNDER THE THEME ROOT, whose integrity is
 * `wp pp integrity check`'s job. Anything that can reach an option, a post meta
 * or a request does not qualify. The count of `true` call sites is pinned in
 * tests/InvariantTest.php, so widening this is a deliberate act, not a quiet one.
 *
 * FAIL PATH. When the encode still fails — the classes SUBSTITUTE cannot fix
 * — emit a MINIMAL document rather than a blank line: every top-level value
 * that is still encodable, plus `envelope_error` naming the encoder's own
 * reason, plus `omitted_keys` naming what did NOT survive.
 *
 * `omitted_keys` is what keeps the fallback honest. `changes` and `findings` are
 * containers, so they are exactly what a failing document drops — and a
 * consumer reading `$r['findings'] ?? []` beside a surviving `ok: true` would
 * otherwise read a clean bill of health for diagnostics that were never encoded.
 * That is the trap `findings_skipped` exists to close on the write path ("a skip
 * is not a clean bill of health"): an absent key here means UNKNOWN, and this
 * field is what says so. Salvaging by TYPE rather than from a list of known field
 * names is deliberate as well — every surface in this file has its own verdict
 * field (`ok`, `valid`, `ready`, `status`, `classification`, `has_drift`), and a
 * hand-maintained list would silently stop covering the next one added.
 *
 * Nothing may escape this function. The fail path runs inside a `try` whose
 * `catch` still prints the pure-ASCII literal: a sink whose whole purpose is that
 * a landed write never loses its receipt must not be able to throw one away.
 *
 * @param mixed $data         The document to print. Every caller passes an array.
 * @param bool  $raw_unicode  True only for `wp pp schema` (see above).
 */
function _pp_cli_emit_json($data, bool $raw_unicode = false): void {
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    if ($raw_unicode) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }

    $json = json_encode($data, $flags);
    if ($json !== false) {
        WP_CLI::line(str_replace("\x7f", '\\u007f', $json));
        return;
    }

    try {
        $reason  = json_last_error_msg();
        $minimal = [];
        $omitted = [];

        // Keep every top-level value that can still be encoded, and NAME the rest.
        // INF/-INF/NAN are scalar and JSON cannot encode them, so the finite check is
        // load-bearing: one of them copied across would fail this second encode too and
        // drop the report to the bare literal, which carries no verdict at all — the
        // original bug, rebuilt inside its own fix.
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($value === null || is_bool($value) || is_string($value) || is_int($value)
                    || (is_float($value) && is_finite($value))) {
                    $minimal[$key] = $value;
                } else {
                    $omitted[] = (string) $key;
                }
            }
        }

        $minimal['envelope_error'] = 'This document could not be encoded as JSON ('
            . $reason . '). The fields above are the ones that survived. A key listed in '
            . 'omitted_keys is UNKNOWN, not empty — do not read an absent "findings" or '
            . '"changes" as a clean result. Any write reported here has already been '
            . 'applied or refused; re-read the target to see its current state.';
        $minimal['omitted_keys'] = $omitted;

        $json = json_encode($minimal, $flags);
        if ($json !== false) {
            WP_CLI::line(str_replace("\x7f", '\\u007f', $json));
            return;
        }
    } catch (\Throwable $e) {
        // Fall through to the literal. Reporting a lost envelope must never be the
        // thing that loses it.
    }

    WP_CLI::line('{"envelope_error":"This document could not be encoded as JSON. Re-read the target to see its current state."}');
}

/**
 * Reports a preflight whose checks passed but whose state could not be
 * recorded (#227). Every post-check failure exit in `apply preflight` goes
 * through here so the single emit path keeps the JSON contract fail-closed:
 * stdout — the machine-readable channel — gets {"ok": false, "error": ...}
 * (with the computed checks for diagnosis), and the human-readable detail
 * goes to STDERR via WP_CLI::error, which exits 1. Never printing
 * {"ok": true} for a preflight that did not complete is the invariant.
 *
 * @param array  $result  The pp_preflight() result whose checks passed.
 * @param string $message The failure detail (also used as the JSON "error").
 */
function _pp_cli_preflight_record_failed(array $result, string $message): void {
    $result['ok']    = false;
    $result['error'] = $message;
    _pp_cli_emit_json($result);
    WP_CLI::error($message);
}

/**
 * Builds the precise operator message when recording PREFLIGHT state fails (#409).
 *
 * The old single message ("State file may be missing or expired") misattributed an
 * environmental storage problem to TTL expiry. The run-state store now reports WHY a
 * record failed (pp_operate_run_status), so this splits the causes into distinct
 * messages: an absent run (the ephemeral-container case) vs a genuinely expired one vs
 * a corrupt/foreign row vs a valid run whose write did not land.
 *
 * @param string $run_id  The run token UUID.
 * @param string $status  A pp_operate_run_status() classification.
 * @return string
 */
function _pp_cli_preflight_record_failed_message(string $run_id, string $status): string {
    switch ($status) {
        case 'not_found':
            return 'No run state found for run token "' . $run_id . '". It was never minted on this install, or it has already been cleaned up. This most often means `wp pp operate inspect` and this command ran in different environments (e.g. separate ephemeral CLI containers). Re-run `wp pp operate inspect` here to start a fresh run.';
        case 'expired':
            return 'Run token "' . $run_id . '" has expired (older than the ' . (int) (PP_OPERATE_RUN_TTL / 3600) . '-hour run TTL). Re-run `wp pp operate inspect` to start a fresh run.';
        case 'foreign':
            return 'Run token "' . $run_id . '" belongs to a different site or install and cannot be used here. Re-run `wp pp operate inspect` on this install.';
        case 'corrupt':
            return 'Run state for run token "' . $run_id . '" is unreadable (corrupt). Re-run `wp pp operate inspect` to start a fresh run.';
        case 'ok':
            return 'Could not persist PREFLIGHT state for run token "' . $run_id . '": the run exists but the options-table write did not complete, so nothing was recorded. Retry `wp pp apply preflight`; if it persists, check the database and wp_options.';
        default: // 'invalid' or unexpected — the run-id was already format-validated upstream.
            return 'Could not record PREFLIGHT state for run token "' . $run_id . '". Re-run `wp pp operate inspect`.';
    }
}

/**
 * Pure decision for the --run-id gate (#390): returns the fail-closed error
 * message, or null when the arg is present and a valid UUID v4. Split out of
 * _pp_cli_require_run_id() so the two rejection branches (missing, invalid) are
 * unit-testable without going through WP_CLI::error()'s process exit. The
 * wrapper below owns the emit; this owns the decision and the message text.
 *
 * @param array $assoc_args The CLI assoc args (expects a 'run-id' key).
 * @return string|null  The user-facing error, or null to accept.
 */
function _pp_cli_run_id_error(array $assoc_args): ?string {
    if (empty($assoc_args['run-id'])) {
        return '--run-id is required. Run `wp pp operate inspect` first to get a run token.';
    }
    if (!pp_operate_valid_run_id($assoc_args['run-id'])) {
        // Raw argv, quoted back to a terminal, and this branch is reached PRECISELY
        // because the value failed the UUID test — so nothing upstream has vouched for
        // its bytes (#647). The `Got:` echo is the whole point of the message, so it is
        // stripped rather than dropped. Every OTHER `$run_id` echo in this file is safe
        // without a guard: they run after pp_operate_valid_run_id() passed, which admits
        // hex and hyphens only.
        return '--run-id must be a valid UUID v4. Got: "' . _pp_cli_printable((string) $assoc_args['run-id']) . '"';
    }
    return null;
}

/**
 * Validates and returns the --run-id from CLI args.
 * Halts with WP_CLI::error if missing or not a valid UUID v4.
 */
function _pp_cli_require_run_id(array $assoc_args): string {
    $error = _pp_cli_run_id_error($assoc_args);
    if ($error !== null) {
        WP_CLI::error($error);
    }
    return $assoc_args['run-id'];
}

/**
 * Preflight-before-mutation gate (#96). Halts with an actionable WP_CLI::error
 * unless the run has a completed PREFLIGHT covering the intended target: a
 * specific $post_id for page/section mutations, or the site grain when $post_id
 * is null. Shared by `action execute` and `operate patch`.
 */
/**
 * Pure decision for the preflight-coverage gate (#96/#390): returns the
 * fail-closed error message when the run has no completed PREFLIGHT covering the
 * intended target, or null when coverage exists. Split out of
 * _pp_cli_require_preflight_covers() so the uncovered branch and its actionable
 * message are unit-testable without WP_CLI::error()'s exit.
 *
 * @param string   $run_id  The run token UUID.
 * @param int|null $post_id The mutation target post, or null for site-scoped.
 * @return string|null  The user-facing error, or null to accept.
 */
function _pp_cli_preflight_coverage_error(string $run_id, ?int $post_id): ?string {
    if (pp_operate_preflight_covers($run_id, $post_id)) {
        return null;
    }
    $target = $post_id !== null ? 'post ' . $post_id : 'site-scoped changes';
    $hint   = 'wp pp apply preflight --run-id=' . $run_id
            . ($post_id !== null ? ' --post_id=' . $post_id : '');
    return 'Run token "' . $run_id . '" has no completed PREFLIGHT covering ' . $target
        . '. Mutating actions require a successful preflight first. Run `' . $hint . '`.';
}

function _pp_cli_require_preflight_covers(string $run_id, ?int $post_id): void {
    $error = _pp_cli_preflight_coverage_error($run_id, $post_id);
    if ($error !== null) {
        WP_CLI::error($error);
    }
}

/**
 * Applies the preflight gate for a named registered action. Resolves the target
 * post from $params (page/section actions carry a required post_id; site actions
 * carry none), asserts that the action's declared scope is consistent with that
 * presence so a misdeclared action can't be mis-gated, then enforces coverage.
 *
 * SINCE #767 THERE IS A THIRD STEP BETWEEN THOSE TWO, and it narrows the gate:
 * `update_composition` / `restore_composition` on a page whose stored composition is
 * already classified corrupt are admitted WITHOUT coverage, because `apply preflight`
 * refuses that page and no preflight can therefore ever cover it. See
 * pp_corrupt_page_repair_carve_out() (lib/operate.php) for maintainer ruling D-1 and its
 * three conditions; the ordering rationale is inline in the body.
 */
/**
 * Pure decision for the scope-consistency guardrail (#390) that resolves an
 * action's preflight target from its declared scope and the presence of a
 * post_id. Returns the fail-closed error message for the three misdeclaration
 * branches, or null when scope and post_id are mutually consistent:
 *   - unrecognized scope (not page/section/site);
 *   - a page/section action with no post_id;
 *   - a site action carrying a post_id.
 * Split out of _pp_cli_require_preflight_for_action() so those branches are
 * unit-testable without WP_CLI::error()'s exit. It does NOT enforce coverage —
 * that stays in the wrapper below. (The #358 composition precondition moved to
 * the shared validator pp_validate_action() in #387; it is no longer a CLI gate.)
 *
 * @param array    $action  The registered action (expects 'scope', 'name').
 * @param int|null $post_id The resolved target post, or null for site scope.
 * @return string|null  The user-facing error, or null to accept.
 */
function _pp_cli_preflight_target_error(array $action, ?int $post_id): ?string {
    $scope = $action['scope'] ?? 'unknown';

    // Fail closed on an unrecognized scope. The page/site assertions below only
    // hold for the known scopes; a missing or mistyped scope would otherwise fall
    // through to post_id-presence keying, letting a misdeclared page action be
    // unlocked by a site-grain preflight. Refuse rather than guess the target.
    if (!in_array($scope, ['page', 'section', 'site'], true)) {
        return 'Action "' . ($action['name'] ?? '?') . '" has an unrecognized scope "'
            . $scope . '"; refusing to resolve a preflight target. This is an action-registration bug.';
    }

    // Scope-consistency guardrail: page/section MUST carry post_id; site MUST NOT.
    $is_page_scope = in_array($scope, ['page', 'section'], true);
    if ($is_page_scope && $post_id === null) {
        return 'Action "' . ($action['name'] ?? '?') . '" is ' . $scope
            . '-scoped but no post_id was provided; cannot resolve a preflight target.';
    }
    if ($scope === 'site' && $post_id !== null) {
        return 'Action "' . ($action['name'] ?? '?') . '" is site-scoped but a post_id '
            . 'was provided; site actions are not page-targeted.';
    }

    return null;
}

function _pp_cli_require_preflight_for_action(string $run_id, array $action, array $params): void {
    $post_id = isset($params['post_id']) ? (int) $params['post_id'] : null;

    $target_error = _pp_cli_preflight_target_error($action, $post_id);
    if ($target_error !== null) {
        WP_CLI::error($target_error);
    }

    // Corrupt-page repair carve-out (#767, ruling D-1). A page the preflight command
    // itself refuses to preflight (lib/cli.php, `apply preflight`: a corrupt composition
    // is not a usable restore baseline) cannot satisfy a preflight-coverage requirement,
    // so demanding one here made the documented repair unreachable from the CLI. The
    // carve-out admits ONLY the two whole-composition verbs, ONLY on that classification,
    // and lifts ONLY this gate and the freshness gate below it — the scope-consistency
    // check above already ran, pp_validate_action() already validated the incoming
    // replacement in `execute`, and the run token / INSPECT ordering is untouched.
    //
    // ORDER MATTERS: the target gate runs FIRST. It catches action-registration bugs
    // (an unrecognized scope, a page action with no post_id), and a misdeclared action
    // must never reach a classification read that could hand it a preflight-free write.
    if (pp_corrupt_page_repair_carve_out($action, $post_id) !== null) {
        return;
    }

    _pp_cli_require_preflight_covers($run_id, $post_id);

    // The #358 composition-presence precondition is NOT enforced here anymore
    // (#387). It moved into the shared validator pp_validate_action() (lib/actions.php)
    // so EVERY executor caller — chat AJAX via pp_execute_action(), WP-CLI, the batch
    // executor, and pp_patch_composition() — is covered by one guard instead of one
    // per entry point. The CLI `action execute` command already calls
    // pp_validate_action() before this gate, so a component action on a
    // composition-less page still fails closed with the same 'composition_required'
    // message via WP_CLI::error() there; the `operate patch` command reaches the
    // guard through pp_patch_composition(). See pp_action_composition_precondition()
    // in lib/operate.php for the single predicate.
}

/**
 * Preflight-freshness gate (#113). For a composition-mutating action, halts with an
 * actionable WP_CLI::error unless the target composition is UNCHANGED since the freshness
 * marker recorded at preflight (or refreshed by this run's own last mutation). Ordering
 * (#96 coverage) proves a preflight ran for the target; this proves the target still
 * matches what that preflight validated — closing the TOCTOU gap where the composition
 * changed through another path between preflight and execute.
 *
 * No-op for actions that don't mutate the composition (title/slug/seo/publish) and for
 * site-scoped actions (no post_id). Fail-closed: a missing recorded baseline blocks —
 * with ONE exception since #767, below. Call AFTER the coverage gate so the two errors
 * stay distinct.
 *
 * Returns the validated baseline version so `execute` can thread it into the action as
 * `expected_version` for an atomic write-time compare-and-swap (#13) — closing the TOCTOU
 * window between this pre-check and the actual write. Returns null for the no-op cases
 * (non-mutating / site-scoped), where no CAS baseline applies.
 *
 * THE #767 CARVE-OUT IS THE THIRD SOURCE OF A VERSION, and it is not a preflight-validated
 * one. For `update_composition` / `restore_composition` on a corrupt-classified page
 * (pp_corrupt_page_repair_carve_out, lib/operate.php) a missing recorded baseline does NOT
 * block: it cannot exist, because it is recorded BY the preflight that page cannot pass.
 * The gate returns ok with a LIVE marker read as the CAS baseline instead, so the write-time
 * compare-and-swap still refuses if the page stopped being corrupt underneath the repair.
 * That substitution is what keeps the data-safety half of this gate intact while the
 * loop-discipline half lifts.
 *
 * @return int|null  The baseline version to use as expected_version, or null (no CAS).
 */
/**
 * Pure decision for the preflight-freshness gate (#113/#390). Reads the recorded
 * and live composition markers and resolves the gate to one of:
 *   ['status' => 'ok',    'version' => int|null]  — accept; version is the CAS
 *        baseline to thread as expected_version, or null for the no-op cases
 *        (non-composition-mutating action, or site-scoped/no post_id);
 *   ['status' => 'error', 'message' => string]    — fail-closed, with the exact
 *        user-facing message for the missing-baseline or stale-marker branch.
 * Split out of _pp_cli_require_composition_fresh() so both rejection branches are
 * unit-testable without WP_CLI::error()'s exit. The single snapshot read here is
 * also reused for the returned version, so the wrapper below never re-reads.
 *
 * @param string   $run_id  The run token UUID.
 * @param array    $action  The registered action (checks 'mutates_composition').
 * @param int|null $post_id The mutation target post, or null.
 * @return array  A discriminated result: {status:'ok', version:int|null} or {status:'error', message:string}.
 */
function _pp_cli_composition_fresh_decision(string $run_id, array $action, ?int $post_id): array {
    if (empty($action['mutates_composition']) || $post_id === null) {
        return ['status' => 'ok', 'version' => null];
    }

    // Corrupt-page repair carve-out (#767, ruling D-1). This gate is not merely waived
    // here, it is UNSATISFIABLE here: its baseline is recorded BY the preflight command,
    // which fails closed on this exact classification, so a carve-out repair can never
    // have one and the fail-closed missing-baseline branch below would re-close the loop
    // the coverage carve-out just opened.
    //
    // The CAS baseline is taken LIVE instead, and it is what keeps condition (1) of the
    // ruling true at the WRITE rather than only at the check: pp_update_composition()
    // re-reads the version from the DB inside its per-post advisory lock and refuses with
    // `composition_conflict` if it moved. So if another writer repairs the page between
    // this decision and the write, the carve-out write is rejected instead of overwriting
    // a now-healthy composition — the page stopped being the corrupt page the hatch was
    // opened for. Retrying is then the ordinary path: the page is healthy, so
    // `apply preflight` works.
    //
    // READ THE MARKER FIRST. THE ORDER OF THESE TWO STATEMENTS IS THE GUARANTEE.
    // Taken the other way round — classify, then read the version — a repair landing
    // BETWEEN them hands back the POST-repair version, the in-lock read matches it, the CAS
    // passes, and the preflight-free write lands on a now-healthy composition: the exact
    // data-loss hole this baseline exists to close, reintroduced by statement order. With
    // the marker read first, every interleaving fails closed:
    //
    //   repair lands BEFORE the marker read      the classification below reads healthy,
    //                                            the carve-out does not apply, refused
    //   repair lands BETWEEN marker and classify same — the classification reads healthy
    //   repair lands AFTER the classification    baseline is pre-repair, in-lock read is
    //                                            post-repair, the CAS refuses
    //
    // Do not "tidy" this into the branch below.
    $live_version = (int) pp_get_composition_marker($post_id)['version'];

    // THIS RE-ASKS rather than reusing the coverage gate's answer, and the second read is
    // deliberate. Each gate answers to the state at its OWN moment; if a repair lands
    // between the two, this one sees a healthy page, the carve-out does not apply, and the
    // fail-closed missing-baseline branch below refuses — which is correct, because a
    // healthy page needs a preflight. Threading one cached classification through both
    // gates would make them agree by construction on a value that had gone stale in
    // between, trading a safe refusal for a preflight-free write. The cost is one extra
    // indexed point lookup per repair command, on the CLI path, once per operator command.
    if (pp_corrupt_page_repair_carve_out($action, $post_id) !== null) {
        return ['status' => 'ok', 'version' => $live_version];
    }

    $recorded = pp_operate_get_composition_snapshot($run_id, $post_id);
    if ($recorded === null) {
        return ['status' => 'error', 'message' => 'Run token "' . $run_id . '" recorded no composition freshness baseline for post '
            . $post_id . '. Re-run `wp pp apply preflight --run-id=' . $run_id . ' --post_id=' . $post_id . '`.'];
    }

    $live = pp_get_composition_marker($post_id);
    if (!pp_composition_marker_matches($recorded, $live)) {
        return ['status' => 'error', 'message' => 'Stale preflight for post ' . $post_id . ': the composition changed since preflight '
            . '(preflight version ' . (int) $recorded['version'] . ', live version ' . (int) $live['version'] . '). '
            . 'Another path (a CLI action, the dashboard editor, or publish flow) modified it. '
            . 'Re-inspect and re-run `wp pp apply preflight --run-id=' . $run_id . ' --post_id=' . $post_id
            . '` before executing. [composition_conflict]'];
    }

    return ['status' => 'ok', 'version' => (int) $recorded['version']];
}

function _pp_cli_require_composition_fresh(string $run_id, array $action, ?int $post_id): ?int {
    $decision = _pp_cli_composition_fresh_decision($run_id, $action, $post_id);
    if ($decision['status'] === 'error') {
        WP_CLI::error($decision['message']);
    }
    return $decision['version'];
}

/**
 * Refreshes a run's composition freshness baseline after a successful in-run mutation
 * (#113). Re-reads the just-written live marker and records it, so the run's OWN next
 * mutation on the same post passes the freshness gate while an external interleaved write
 * still conflicts. Best-effort: a failed refresh is fail-closed — the run's next mutation
 * would just require a fresh preflight — so it warns rather than halting a completed write.
 */
function _pp_cli_refresh_composition_baseline(string $run_id, array $action, ?int $post_id): void {
    if (empty($action['mutates_composition']) || $post_id === null) {
        return;
    }
    if (!pp_operate_record_composition_snapshot($run_id, $post_id, pp_get_composition_marker($post_id))) {
        WP_CLI::warning('Could not refresh the composition freshness baseline for post ' . $post_id
            . ' on run token "' . $run_id . '"; a further mutation on this post in the same run will '
            . 'require a new `wp pp apply preflight`.');
    }
}

/**
 * Parses the --params JSON argument. Shared by action and apply CLI commands.
 */
function pp_cli_parse_params(array $assoc_args): array {
    $raw = $assoc_args['params'] ?? '{}';
    $params = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        WP_CLI::error('Invalid JSON in --params: ' . json_last_error_msg());
    }
    return $params;
}

/**
 * Capability gate for apply commands.
 * In WP-CLI context, capability check is bypassed because WP-CLI already
 * requires server-level access. This follows WP-CLI core conventions
 * (e.g. wp db export). In web/AJAX context, requires manage_options.
 */
function _pp_cli_require_apply_cap(): void {
    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::debug('Capability gate bypassed: WP-CLI context detected.');
        return;
    }
    if (!current_user_can('manage_options')) {
        WP_CLI::error('You need manage_options capability to use apply commands.');
    }
}

/**
 * Action commands intentionally have no CLI capability gate.
 * Actions mutate WordPress data through WP APIs (wp_update_post, update_option)
 * which enforce their own permission model. Apply commands write directly to
 * the filesystem, bypassing WordPress permissions entirely — hence the gate.
 * AJAX surfaces for actions gate on edit_posts separately (see lib/ai-chat.php).
 */
class PP_Action_Command extends WP_CLI_Command {

    /**
     * Lists all registered actions.
     *
     * ## EXAMPLES
     *
     *     wp pp action list
     *
     * @subcommand list
     */
    public function list_actions($args, $assoc_args) {
        $actions = pp_get_registered_actions();
        if (empty($actions)) {
            WP_CLI::warning('No actions registered.');
            return;
        }

        $rows = [];
        foreach ($actions as $name => $def) {
            $params = [];
            foreach ($def['params'] as $pname => $pdef) {
                $label = $pname . ' (' . ($pdef['type'] ?? 'string') . ')';
                if (!empty($pdef['required'])) {
                    $label .= ' *';
                }
                $params[] = $label;
            }
            $rows[] = [
                'name'        => $name,
                'scope'       => $def['scope'],
                'description' => $def['description'] ?? '',
                'params'      => implode(', ', $params),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['name', 'scope', 'description', 'params']);
    }

    /**
     * Previews an action (validates and shows diff, never writes).
     *
     * ## OPTIONS
     *
     * <name>
     * : The action name.
     *
     * --params=<json>
     * : JSON object of action parameters.
     *
     * ## EXAMPLES
     *
     *     wp pp action preview update_component --params='{"post_id":4,"component_index":0,"props":{"title":"New Title"}}'
     *
     */
    public function preview($args, $assoc_args) {
        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_preview_action($name, $params);

        if (is_wp_error($result)) {
            _pp_cli_emit_json(['ok' => false, 'error' => $result->get_error_message()]);
            WP_CLI::halt(1);
            return;
        }

        _pp_cli_emit_json($result);
    }

    /**
     * Executes an action (validates first, then applies).
     *
     * ## OPTIONS
     *
     * <name>
     * : The action name.
     *
     * --params=<json>
     * : JSON object of action parameters.
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * ## EXAMPLES
     *
     *     wp pp action execute create_page --run-id=<uuid> --params='{"title":"New Page"}'
     *     wp pp action execute add_component --run-id=<uuid> --params='{"post_id":4,"component":"hero","props":{"title":"Hello"}}'
     *
     */
    public function execute($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'INSPECT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed INSPECT step. Run `wp pp operate inspect` first.');
        }

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        // Preflight-before-mutation gate (#96). Every `action execute` mutates
        // DB-backed state, so require a completed PREFLIGHT covering this target
        // before the write lands — except the #767 carve-out, which admits
        // `update_composition` / `restore_composition` on a corrupt-classified page
        // that no preflight can cover (see _pp_cli_require_preflight_for_action).
        // Validate first so a malformed/nonexistent
        // target surfaces its real error instead of a confusing "preflight a
        // page that doesn't exist" message, and so the gate only demands a
        // preflight for an action that would actually run. Unknown action names
        // fall through to pp_execute_action's unknown_action error.
        $action = pp_get_action($name);
        if ($action !== null) {
            $validation = pp_validate_action($name, $params);
            if (is_wp_error($validation)) {
                // #385: surface a validation failure (param-type mismatch, missing
                // param, or semantic reject) as the canonical ok:false envelope on
                // stdout — identical to this command's own success/failure emit path
                // below and to `apply execute` — NOT a bare WP_CLI::error on stderr.
                // A batch consumer greps stdout for the envelope; a stderr-only
                // rejection was invisible and silently left the target unchanged
                // (neocompute dogfood: update_site_option with a numeric value left
                // pp_logo_id at its old value while the operator believed it succeeded).
                // Render the envelope from the WP_Error already in hand — do NOT
                // re-run the validator via pp_execute_action(): a concurrent DB change
                // could flip this rejection to a pass and then MUTATE, outside the
                // preflight (#96) and freshness/CAS (#113) gates. Emitting from the
                // WP_Error keeps this path fail-closed and pure. halt(1) preserves the
                // non-zero exit the old WP_CLI::error produced.
                $result = _pp_action_validation_error_envelope($name, $validation);
                _pp_cli_emit_json($result);
                WP_CLI::halt(1);
                return;
            }
            _pp_cli_require_preflight_for_action($run_id, $action, $params);
            // Freshness gate (#113): after coverage, reject a composition-mutating action
            // whose target changed since preflight. Its return is the validated baseline
            // version, which we thread into the action as expected_version so the write is
            // an atomic compare-and-swap (#13) — a live write landing between this gate and
            // pp_update_composition() is caught at write time, not silently clobbered.
            $baseline_version = _pp_cli_require_composition_fresh(
                $run_id, $action, isset($params['post_id']) ? (int) $params['post_id'] : null
            );
            if ($baseline_version !== null) {
                $params['expected_version'] = $baseline_version;
            }
        }

        $result = pp_execute_action($name, $params);

        _pp_cli_emit_json($result);

        if ($result['ok']) {
            // Refresh the freshness baseline (#113) so this run's own next mutation on the
            // same post flows; an external interleaved write still conflicts.
            if ($action !== null) {
                _pp_cli_refresh_composition_baseline($run_id, $action, isset($params['post_id']) ? (int) $params['post_id'] : null);
                // Touched-post tracking (#133): record this post so a run-scoped restore
                // can revert exactly the compositions this run changed. Only for
                // composition-mutating actions targeting a page. Fail loud on a recording
                // failure — a missing touched-post entry silently narrows what restore
                // can undo, the composition analogue of the touched-token contract.
                if (!empty($action['mutates_composition']) && isset($params['post_id'])) {
                    if (!pp_operate_record_touched_post_id($run_id, (int) $params['post_id'])) {
                        WP_CLI::error('Action "' . $name . '" executed, but recording its touched post for run "' . $run_id . '" FAILED. `wp pp apply restore-composition` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect` before making further changes.');
                    }
                }
            }
            WP_CLI::success('Action "' . $name . '" executed.');
        } else {
            WP_CLI::halt(1);
        }
    }

}

WP_CLI::add_command('pp action', 'PP_Action_Command');

// ── Target CLI ──────────────────────────────────────────────────────────────

class PP_Target_Command extends WP_CLI_Command {

    /**
     * Shows the canonical live target (site URL, WP root, theme path, environment).
     *
     * ## EXAMPLES
     *
     *     wp pp target show
     *
     */
    public function show($args, $assoc_args) {
        $target = pp_get_target();

        $warnings = [];
        foreach ($target as $key => $value) {
            if ($value === null) {
                $warnings[] = $key;
            }
        }

        _pp_cli_emit_json($target);

        if (!empty($warnings)) {
            WP_CLI::warning('Could not resolve: ' . implode(', ', $warnings) . '. Verify WordPress is fully loaded.');
        }
    }
}

WP_CLI::add_command('pp target', 'PP_Target_Command');

// ── Schema CLI (#688) ───────────────────────────────────────────────────────

/**
 * Reads a component's declared contract out loud.
 *
 * Addressing note (#685): the page-addressing gate registered above refuses a
 * POSITIONAL argument on the three page-addressed `wp pp operate` subcommands and
 * nothing else — _pp_cli_positional_page_arg_error() returns null unless
 * $args[1] === 'operate'. `wp pp schema hero` addresses a COMPONENT, not a page, so
 * its positional is untouched by that hook and neither the hook nor its scope
 * constant needs widening. A component name is not an address that `--post_id`
 * could ever express.
 */
class PP_Schema_Command extends WP_CLI_Command {

    /**
     * Outputs a component's props, style slots, applies_when conditions and recipes as JSON.
     *
     * Read-only and run-token-free — the same class as `wp pp operate inspect-composition`.
     * It reads declarations off disk, touches no page, and mints nothing, so it is safe to
     * call at any point in an operating run.
     *
     * With no argument it lists every registered component and whether each one is
     * composable (`nav` and `footer` are site chrome the page template renders itself, so
     * they are readable here but may not appear in a composition).
     *
     * Each prop and slot carries its declared keys verbatim plus one derived field,
     * `applies_when_rendered`: the whole condition — `applies_when` clauses and any
     * `conditionality_note`, ANDed — in the same words the runtime AI catalog uses.
     *
     * Scope: props, style slots and recipes. The remaining `styling` declarations
     * (`root_class`, `variant_classes`, `tokens`, `chrome_custom_properties`) are not
     * emitted; see pp_component_schema_report() in lib/operate.php.
     *
     * ## OPTIONS
     *
     * [<component>]
     * : Component name, exactly as spelled (case-sensitive, never canonicalised). Omit to list all registered components.
     *
     * ## EXAMPLES
     *
     *     wp pp schema
     *     wp pp schema hero
     *     wp pp schema section
     *
     */
    public function __invoke($args, $assoc_args) {
        // Docblock constraint (tests/InvariantTest.php): each OPTIONS description
        // must stay on ONE ": " line or WP-CLI folds the continuation into the
        // synopsis and warns "invalid synopsis part" on every run.
        //
        // ONE decision about the argument, taken once: an ABSENT positional means
        // "list everything", and anything present — including an empty string — is a
        // component name the builder judges. Collapsing `""` into "absent" here would
        // give the two layers different answers for the same input, and `wp pp schema ""`
        // is a typo, not an omission. The builder's refusal names all twelve, which is
        // more useful than silently printing the index.
        if (!isset($args[0])) {
            // `true` = keep literal non-ASCII. Same rationale as the report emit below,
            // and the same reason it is safe here: pp_component_schema_index() walks the
            // shipped component registry only.
            _pp_cli_emit_json(['components' => pp_component_schema_index()], true);
            return;
        }

        $report = pp_component_schema_report((string) $args[0]);
        if (is_wp_error($report)) {
            // The message is `Unknown component "%s". Available: ...` (lib/operate.php),
            // built from the UNREGISTERED name — i.e. raw argv, echoed because it matched
            // nothing (#647). The sibling resolver sinks in this file (operate inspect /
            // patch) already wrap for exactly this reason.
            WP_CLI::error(_pp_cli_printable($report->get_error_message()));
        }

        // The `$raw_unicode` argument is the ONE deviation from the escaped-by-default
        // sink (#717), and this command is its only caller. Schema descriptions and
        // conditionality notes are full of em dashes and typographic quotes, and without
        // it every one of them arrives as a numeric \u escape. A surface whose whole point
        // is being read as prose by an agent that cannot open schema.json should hand it
        // the characters, not their codepoints. It is safe here for a reason that has to
        // keep holding: pp_component_schema_report() reads the shipped component registry
        // and nothing else, so no stored byte an operator never validated reaches it.
        _pp_cli_emit_json($report, true);
    }
}

WP_CLI::add_command('pp schema', 'PP_Schema_Command');

// ── Apply CLI ───────────────────────────────────────────────────────────────

class PP_Apply_Command extends WP_CLI_Command {

    /**
     * Lists all registered applies.
     *
     * ## EXAMPLES
     *
     *     wp pp apply list
     *
     * @subcommand list
     */
    public function list_applies($args, $assoc_args) {
        $applies = pp_get_registered_applies();
        if (empty($applies)) {
            WP_CLI::warning('No applies registered.');
            return;
        }

        $rows = [];
        foreach ($applies as $name => $def) {
            $params = [];
            foreach ($def['params'] as $pname => $pdef) {
                $label = $pname . ' (' . ($pdef['type'] ?? 'string') . ')';
                if (!empty($pdef['required'])) {
                    $label .= ' *';
                }
                $params[] = $label;
            }
            $target_label = '';
            if (isset($def['target']['type'])) {
                if ($def['target']['type'] === 'file') {
                    $target_label = 'file:' . ($def['target']['path'] ?? '');
                } elseif ($def['target']['type'] === 'option') {
                    $target_label = 'option:' . ($def['target']['key'] ?? '');
                } elseif ($def['target']['type'] === 'media') {
                    $target_label = 'media library';
                }
            }
            $rows[] = [
                'name'        => $name,
                'domain'      => $def['domain'],
                'target'      => $target_label,
                'description' => $def['description'] ?? '',
                'params'      => implode(', ', $params),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['name', 'domain', 'target', 'description', 'params']);
    }

    /**
     * Previews an apply (validates and shows diff, never writes).
     *
     * ## OPTIONS
     *
     * <name>
     * : The apply name.
     *
     * --params=<json>
     * : JSON object of apply parameters.
     *
     * ## EXAMPLES
     *
     *     wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
     *
     */
    public function preview($args, $assoc_args) {
        _pp_cli_require_apply_cap();

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_preview_apply($name, $params);

        if (is_wp_error($result)) {
            _pp_cli_emit_json(['ok' => false, 'error' => $result->get_error_message()]);
            WP_CLI::halt(1);
            return;
        }

        _pp_cli_emit_json($result);
    }

    /**
     * Executes an apply (validates first, then applies).
     *
     * ## OPTIONS
     *
     * <name>
     * : The apply name.
     *
     * --params=<json>
     * : JSON object of apply parameters.
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * ## EXAMPLES
     *
     *     wp pp apply execute update_design_token --run-id=<uuid> --params='{"token":"--color-accent","value":"#b45309"}'
     *
     */
    public function execute($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        // Rollback-safety pre-gate: refuse to mutate unless this run is reversible
        // (a usable pre-apply snapshot exists for THIS install). Reversibility metadata
        // is a precondition of changing tokens, never an afterthought.
        if (!pp_operate_run_rollbackable($run_id)) {
            WP_CLI::error('Refusing to apply: run "' . $run_id . '" has no usable rollback snapshot, so this change could not be undone. Re-run `wp pp operate inspect` and `wp pp apply preflight`.');
        }

        _pp_cli_require_apply_cap();

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_execute_apply($name, $params);

        _pp_cli_emit_json($result);

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }

        // Record the tokens this apply actually wrote (primary + derived) so restore
        // reverts exactly this run's footprint. The mutation already persisted; if the
        // touched-key trail cannot be recorded, surface a loud error instead of a clean
        // success. Restore reads touched_tokens and fails-closed on null, so a missing
        // trail can never become a silent partial rollback later.
        $touched = array_column($result['changes'], 'token');
        if (!pp_operate_record_touched_tokens($run_id, $touched)) {
            WP_CLI::error('Apply "' . $name . '" persisted, but recording its touched tokens for run "' . $run_id . '" FAILED. `wp pp apply restore` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect` before making further changes.');
        }

        pp_operate_record_step($run_id, 'APPLY');
        WP_CLI::success('Apply "' . $name . '" executed.');
    }

    /**
     * Rolls a run's token changes back to the snapshot taken at its preflight.
     *
     * This is a true per-run rollback, NOT a reset to product defaults. The tokens this
     * run wrote (primary + auto-derived) are reverted to the values they held when the
     * run's preflight ran; tokens the run created are removed; tokens the run never
     * touched are left untouched, so unrelated overrides (including later runs' work)
     * are preserved. To reset tokens to product defaults instead, use `wp pp apply reset`.
     *
     * Fails closed: if the run's frozen snapshot or touched-key list is missing, expired,
     * corrupt, swept, or from a different install, restore reports an error and changes
     * nothing — it never falls back to a product-default reset and never partially mutates.
     *
     * Limitation: a run is fully reversible only if every `apply execute` recorded its
     * touched keys. Token mutation (DB) and touched-key recording (run-state file) are
     * separate stores and cannot be one transaction; if a touched-key write ever fails,
     * `execute` errors loudly at that point, but a later restore replays whatever touched
     * keys WERE recorded and cannot revert a change whose keys never landed. Re-run
     * `wp pp operate inspect` after any such error rather than trusting restore.
     *
     * `wp pp apply reset` records its touched tokens the same way `execute` does, so a
     * reset (single-token or reset-all) within the same run is restorable here too — not
     * just a one-way trip to product defaults.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * [--token=<name>]
     * : Restore a single token and its derived family from the run snapshot. Omit to
     *   restore everything the run touched.
     *
     * ## EXAMPLES
     *
     *     wp pp apply restore --run-id=<uuid> --token=--color-accent
     *     wp pp apply restore --run-id=<uuid>
     *
     */
    public function restore($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        _pp_cli_require_apply_cap();

        // Fail closed: both the frozen snapshot and the touched-key list must be usable
        // for THIS install. Null from either covers missing/expired/corrupt/swept/identity
        // mismatch. Never fall back to a product-default reset.
        $snapshot = pp_operate_get_token_snapshot($run_id);
        $touched  = pp_operate_get_touched_tokens($run_id);
        if ($snapshot === null || $touched === null) {
            WP_CLI::error('Run "' . $run_id . '" has no usable pre-apply snapshot; cannot roll back. The run state may be missing, expired, corrupt, or from a different site. Nothing was changed.');
        }

        // Scope the revert. Default: everything the run touched. With --token: that token
        // plus its derived family, intersected with what the run actually touched.
        if (isset($assoc_args['token'])) {
            $token  = $assoc_args['token'];
            $family = array_keys(pp_token_families()[$token] ?? []);
            $wanted = array_merge([$token], $family);
            $scope  = array_values(array_intersect($touched, $wanted));
            if (empty($scope)) {
                // `$token` is raw argv and this branch is the one where it intersected
                // NOTHING the run touched, so no lookup has vouched for it (#647). A
                // SUCCESS line is still a terminal sink. `$run_id` needs no guard: it is
                // past pp_operate_valid_run_id().
                WP_CLI::success('Token "' . _pp_cli_printable((string) $token) . '" was not changed by run "' . $run_id . '"; nothing to restore.');
                return;
            }
        } else {
            $scope = $touched;
        }

        if (empty($scope)) {
            pp_operate_record_step($run_id, 'APPLY');
            WP_CLI::success('Run "' . $run_id . '" changed no tokens; nothing to restore.');
            return;
        }

        // Compute the effective change for reporting, then revert atomically.
        $before = pp_get_token_overrides();
        if (!pp_revert_tokens($snapshot, $scope)) {
            WP_CLI::error('Could not roll back run "' . $run_id . '": the token lock was unavailable or the snapshot held invalid values. Nothing was changed.');
        }
        $after = pp_get_token_overrides();

        $changed = 0;
        foreach ($scope as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed++;
            }
        }

        pp_operate_record_step($run_id, 'APPLY');
        WP_CLI::success($changed > 0
            ? "Restored $changed token(s) to the pre-run snapshot."
            : 'Tokens already matched the pre-run snapshot; nothing to restore.');
    }

    /**
     * Reverts every page composition a run changed back to its pre-run state (#133).
     *
     * The composition counterpart of `wp pp apply restore` (which reverts tokens). For
     * each post the run mutated (recorded as it ran), rewrites the composition to the
     * content frozen at the run's PREFLIGHT. Scoped strictly to THIS run's touched posts
     * — a page changed by a different run is never touched. Each revert is a real
     * pp_update_composition write (its own lock + marker bump + history entry), so the
     * revert is itself reversible.
     *
     * Fails closed: if the run's touched-post record is missing, expired, corrupt, or
     * from another install, nothing is changed. Per-post snapshot-missing or write
     * failures are reported under `skipped` while the rest proceed.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * ## EXAMPLES
     *
     *     wp pp apply restore-composition --run-id=<uuid>
     *
     * @subcommand restore-composition
     */
    public function restore_composition($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        _pp_cli_require_apply_cap();

        $report = pp_operate_restore_run_compositions($run_id);
        if (!$report['ok']) {
            WP_CLI::error('Run "' . $run_id . '" has no usable touched-post record; cannot revert compositions. The run state may be missing, expired, corrupt, or from a different site. Nothing was changed.');
        }

        _pp_cli_emit_json($report);

        $skipped  = count($report['skipped']);
        if ($skipped > 0) {
            WP_CLI::warning($skipped . ' post(s) skipped (missing snapshot or write failure); see the report above.');
        }

        // issue 236: a run-scoped restore is never blocked by validation rules that
        // landed after the snapshot, so it must not report a bare success when a
        // restored composition violates current rules. Warn (never fail) so the operator
        // sees the gap; the per-post `findings` in the JSON report above name the detail.
        // Emitted before the completeness gate below (which can exit) so it always shows,
        // and to STDERR (WP_CLI::warning) so the STDOUT JSON stays machine-clean.
        $with_findings = pp_operate_restore_run_finding_count($report);
        if ($with_findings > 0) {
            WP_CLI::warning($with_findings . ' reverted post(s) have composition findings under current validation rules (see "findings" in the report above). The rollback was applied as-is; a restore is never blocked by rules that landed after the snapshot.');
        }

        $reverted = count($report['reverted']);
        $changed  = count(array_filter($report['reverted'], static function ($r) { return !empty($r['changed']); }));

        pp_operate_record_step($run_id, 'APPLY');

        // issue 242: a partial restore (any skipped touched post) is INCOMPLETE, not
        // successful. Fail closed with a non-zero exit so a machine consumer branching
        // on the exit code never reads a partial restore as a full one; the JSON report
        // above already lists reverted vs skipped explicitly.
        if (!pp_operate_restore_run_complete($report)) {
            WP_CLI::error("Restore INCOMPLETE: reverted $reverted of " . ($reverted + $skipped)
                . " touched post(s); $skipped could not be reverted (missing snapshot or write failure). See the report above for which posts were restored vs skipped.");
        }

        WP_CLI::success($changed > 0
            ? "Reverted $changed composition(s) to the pre-run state (of $reverted touched)."
            : 'Touched compositions already matched the pre-run state; nothing to revert.');
    }

    /**
     * Resets design tokens to product defaults (NOT a per-run rollback).
     *
     * Clears token overrides so the site reverts to the values shipped in base.css.
     * Use `wp pp apply restore` to undo a specific run instead. Token overrides are
     * stored in the database; this calls the reset_design_token / reset_all_design_tokens
     * applies.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * [--token=<name>]
     * : Reset a single token. Omit to reset all.
     *
     * ## EXAMPLES
     *
     *     wp pp apply reset --run-id=<uuid> --token=--color-accent
     *     wp pp apply reset --run-id=<uuid>
     *
     */
    public function reset($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        // Rollback-safety pre-gate: reset mutates the same token store execute()
        // does (and reset_all_design_tokens is the most destructive apply in the
        // registry), so it gets the identical precondition — refuse to mutate
        // unless this run is reversible.
        if (!pp_operate_run_rollbackable($run_id)) {
            WP_CLI::error('Refusing to reset: run "' . $run_id . '" has no usable rollback snapshot, so this change could not be undone. Re-run `wp pp operate inspect` and `wp pp apply preflight`.');
        }

        _pp_cli_require_apply_cap();

        if (isset($assoc_args['token'])) {
            $result = pp_execute_apply('reset_design_token', ['token' => $assoc_args['token']]);
        } else {
            $result = pp_execute_apply('reset_all_design_tokens', []);
        }

        _pp_cli_emit_json($result);

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }

        // Record the tokens this reset actually cleared so restore can revert
        // exactly this run's footprint — same contract as execute(). Without
        // this, restore's scope stays empty and a reset is unrecoverable
        // through the tooling even though the pre-apply snapshot holds every
        // prior value.
        $touched = array_column($result['changes'], 'token');
        if (!pp_operate_record_touched_tokens($run_id, $touched)) {
            WP_CLI::error('Reset persisted, but recording its touched tokens for run "' . $run_id . '" FAILED. `wp pp apply restore` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect` before making further changes.');
        }

        pp_operate_record_step($run_id, 'APPLY');
        $count = count($result['changes']);
        WP_CLI::success($count > 0 ? "Reset $count token(s) to product defaults." : 'No overrides to reset.');
    }

    /**
     * Validates the execution surface before any mutation.
     *
     * Checks: target resolved, capability OK, drift state, theme writability
     * (file-targeting applies), uploads writability (media-target applies),
     * target page (if applicable), and surface classification.
     * Records PREFLIGHT step in the run state file.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * [--planned-files=<json>]
     * : JSON array of file paths the agent intends to modify.
     *   Enables drift overlap detection. Without this flag, drift is a warning only.
     *
     * [--post_id=<id>]
     * : Target page post ID. Enables target_page check.
     *   Numeric WordPress post ID; slugs and URLs are not resolved.
     *
     * [--apply=<name>]
     * : Named apply definition. Auto-populates planned_files from the apply's target (file-based applies only).
     *   Media-target applies (import_media) enable the uploads_writable check.
     *
     * ## EXAMPLES
     *
     *     wp pp apply preflight --run-id=<uuid>
     *     wp pp apply preflight --run-id=<uuid> --planned-files='["assets/css/base.css"]'
     *     wp pp apply preflight --run-id=<uuid> --apply=update_design_token
     *     wp pp apply preflight --run-id=<uuid> --post_id=42
     *
     */
    public function preflight($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);

        $context = [];

        if (!empty($assoc_args['planned-files'])) {
            $decoded = json_decode($assoc_args['planned-files'], true);
            if (is_array($decoded)) {
                $context['planned_files'] = $decoded;
            }
        }

        $preflight_post_id = _pp_cli_optional_post_id_arg($assoc_args, 'pp apply preflight');
        if ($preflight_post_id !== null) {
            $context['post_id'] = $preflight_post_id;
        }

        // Route any provided --apply value (non-empty string) into the context so an
        // unregistered name fails the apply_known preflight check (issue 245). Guard
        // on `!== ''` rather than !empty(): PHP's empty('0') is true, so an !empty()
        // gate would drop the literal apply name "0" here and let pp_preflight() treat
        // it as "no apply planned" — the exact false-pass the apply_known check closes.
        if (isset($assoc_args['apply']) && $assoc_args['apply'] !== '') {
            $context['apply_name'] = $assoc_args['apply'];
        }

        $result = pp_preflight($context);

        if (!$result['ok']) {
            // Error-grade check failed: report the checks and stop. Nothing is
            // recorded, so downstream gates stay closed.
            _pp_cli_emit_json($result);
            WP_CLI::halt(1);
        }

        // The success JSON is emitted LAST, only after every recording step below
        // has succeeded (#227). Emitting it before recording made the gate fail-open
        // in its reported result: a consumer parsing stdout — the machine-readable
        // contract — saw {"ok": true} for a preflight whose state was never
        // recorded, then hit a contradictory error on the next command. Any
        // recording failure now reports {"ok": false, "error": ...} on stdout
        // (via _pp_cli_preflight_record_failed) so `ok` reflects whether the
        // preflight actually completed, including recording its state.

        // Record PREFLIGHT only on success, in ONE atomic write: the PREFLIGHT
        // step, the target this preflight covered (post_id for page/section work,
        // or the site grain when no post is given), the pre-apply token snapshot
        // that `apply restore` rolls back to, AND — for a page/section preflight —
        // the pre-apply composition content snapshot that `apply restore-composition`
        // reverts to. Committing them together is load-bearing: mutating gates
        // (action execute / operate patch) unlock on the recorded coverage alone,
        // so a partial write must never leave the run unlocked. Folding the
        // composition snapshot into this single write (issue 241) closes the prior
        // two-write gap where a snapshot-write failure left the run unlocked with no
        // restore baseline (and a re-run could freeze a post-mutation baseline).
        // First-write-wins inside the recorder keeps both rollback baselines stable
        // across re-runs.
        //
        // The snapshot is read under the token lock for an atomic baseline. It returns
        // null on any of three fail-closed conditions rather than a baseline that a
        // later `apply restore` would wrongly roll back to:
        //   - lock contended (#200): a stale, non-atomic read,
        //   - unreadable overrides row (#207): a corrupt/hand-edited pp_token_overrides
        //     row that would otherwise be recorded as an empty [] baseline, causing
        //     restore to DELETE the touched tokens instead of restoring them, or
        //   - database read failure (#212): a failed SELECT (non-empty $wpdb->last_error)
        //     that would otherwise be indistinguishable from a genuinely absent row.
        // Either way a null snapshot is a hard preflight failure: record nothing
        // (leaving both gates fail-closed) and surface the cause so the operator can act
        // (retry once contention clears; repair the corrupt row before re-running).
        $token_snapshot = pp_snapshot_token_overrides();
        if ($token_snapshot === null) {
            _pp_cli_preflight_record_failed($result, 'Could not read an atomic pre-apply token baseline for run token "' . $run_id . '": the token lock is contended, or the pp_token_overrides row is corrupt/unreadable. PREFLIGHT was not recorded. Re-run `wp pp apply preflight` once the contention clears; if it persists, inspect and repair the pp_token_overrides option.');
        }
        // Freshness baseline (#113) + run-scoped restore baseline (#133): for a
        // page-scoped preflight, capture the target's composition marker (so a later
        // `action execute` / `operate patch` can reject a composition changed since
        // this preflight) and its pre-apply composition CONTENT (so a run-scoped
        // restore can revert this post to its pre-run state). Both are null for a
        // site-grain preflight.
        $composition_marker  = null;
        $composition_content = null;
        if (isset($context['post_id'])) {
            $pid = (int) $context['post_id'];
            $composition_marker = pp_get_composition_marker($pid);
            // Read the restore baseline via the result-returning decoder so a corrupt
            // or undecodable _pp_composition row FAILS the preflight closed (issue 241)
            // instead of freezing [] as the baseline — which a later run-scoped restore
            // would replay to BLANK the page. Mirrors the token snapshot's fail-closed
            // treatment of a corrupt pp_token_overrides row (#207). pp_get_composition()
            // coerces corruption to [] and must not be used here.
            $composition_result = pp_get_composition_result($pid);
            if (!$composition_result['ok']) {
                // NAMES THE ROUTE THAT RUNS (#767). This refusal is one half of the loop
                // ruling D-1 closed: it used to say "repair the composition first" while
                // the repair itself was refused for want of the preflight this command
                // declines to record. The carve-out makes the two whole-composition verbs
                // reachable on exactly this classification, so the instruction is now
                // executable — and it is spelled out, because an operator reading a
                // fail-closed message should not have to know a carve-out exists.
                _pp_cli_preflight_record_failed($result, 'Could not read a valid pre-apply composition baseline for run token "' . $run_id . '" (post ' . $pid . '): the stored composition is ' . $composition_result['error'] . '. PREFLIGHT was not recorded, so both the action gate and the restore baseline stay fail-closed. ' . pp_corrupt_repair_route_message($pid, $run_id));
            }
            $composition_content = $composition_result['composition'];
        }
        // Single atomic write: PREFLIGHT step + coverage + token snapshot + (for a
        // page-scoped preflight) the composition marker and content baseline. Recording
        // them together means the run can never be left unlocked without its restore
        // baseline; any recording failure records nothing and reports {"ok": false}.
        if (!pp_operate_record_preflight($run_id, $context['post_id'] ?? null, $token_snapshot, $composition_marker, $composition_content)) {
            // Ask the store WHY the record failed so the operator sees a precise cause
            // (absent run vs expired vs corrupt/foreign vs a valid run whose write did
            // not land) rather than the old misleading "missing or expired" (#409).
            _pp_cli_preflight_record_failed($result, _pp_cli_preflight_record_failed_message($run_id, pp_operate_run_status($run_id)));
        }

        _pp_cli_emit_json($result);
    }
}

WP_CLI::add_command('pp apply', 'PP_Apply_Command');

// ── Check / Validate CLI ──────────────────────────────────────────────────

/**
 * The read-only per-page diagnostic bundle shared by `wp pp check page` and
 * `wp pp validate site` (#622).
 *
 * Both commands used to call pp_validate_composition_styling() +
 * pp_validate_composition_smells() and NOTHING else, so a page whose stored
 * composition current write rules reject — a missing required prop, an unknown prop
 * key, a retired enum value — was reported as clean. #604 made that actively wrong:
 * the read path no longer canonicalizes anything, so the diagnostic contradicted both
 * the write path and the renderer, and a stale page looked healthy right up until an
 * edit was refused. Stale-data breakage after the vocabulary freeze is the intended
 * outcome; these are the surfaces that have to REPORT it.
 *
 * Reads _pp_composition_findings() — the same engine restore_composition's `findings`
 * uses — rather than adding a third validator. Every rule that lands in
 * pp_validate_composition_errors() / pp_validate_composition_smells() from now on is
 * inherited by both commands for free. Split by severity here so one function owns the
 * bucketing and both commands render it identically:
 *
 *     errors   severity 'error'    a normal write of this composition would be REJECTED
 *     smells   severity 'warning'  advisory; the write that produced it was accepted
 *     styling  ambiguous targeting (duplicate types without authored ids)
 *
 * Pure: it takes a decoded composition and returns arrays. No WP_CLI, no exit — the
 * commands own presentation and exit codes.
 *
 * DELIBERATELY UNBOUNDED — DO NOT "FINISH" #654 HERE. #654 bounded the three surfaces
 * that ship a findings PAYLOAD to a consumer that has to hold and render it whole
 * (restore preview, restore execute, the run-rollback aggregation). This one is
 * deliberately excluded, by ruling, and the reason is that its own name is the escape
 * hatch every one of those tails points at:
 *
 *     "Showing 100 of 10000 findings ... Run `wp pp check page --post_id=N` for the
 *      complete report."
 *
 * That sentence is ratified #687 contract and it is printed by the write path, by
 * restore and by the rollback. Capping this function at the same 100 would make it
 * false on every surface at once — the operator who follows the breadcrumb would be
 * handed THE SAME FIRST 100 FINDINGS they already had, and the product would have no
 * complete report anywhere. A bound that costs the system its only complete report is
 * not a bound, it is a second truncation wearing the first one's clothes.
 *
 * The asymmetry is principled, not an oversight. `check page` streams lines to stdout
 * for a human at a terminal; it ships nothing to a remote consumer, and — unlike
 * restore, the rollback and the write path — no write has already landed that an OOM
 * here would strand. The cost of the exclusion is a large transient array on a
 * pathological page (measured: 20,001 findings / 22 MB on a 10,000-entry `items`
 * band), which is the price of being the surface that answers completely.
 *
 * Bounding the ENGINE cost of building that report is a different axis and is already
 * paid for: #715's O(N²) locator rescan is gone (see pp_is_list(), lib/wp.php).
 *
 * Pinned by CompositionFindingsBoundsTest so the carve-out survives with evidence
 * rather than as prose — including the exit code, which cannot move: findings arrive
 * errors-then-advisories, so bounding could never empty the `errors` bucket that
 * _pp_cli_page_fails_site_validation() gates on.
 *
 * @param  array $composition  Decoded composition array.
 * @return array{errors: array[], smells: array[], styling: array[]}
 */
function _pp_cli_page_diagnostics(array $composition): array {
    $errors = [];
    $smells = [];

    foreach (_pp_composition_findings($composition) as $finding) {
        if (($finding['severity'] ?? '') === 'error') {
            $errors[] = $finding;
        } else {
            $smells[] = $finding;
        }
    }

    return [
        'errors'  => $errors,
        'smells'  => $smells,
        'styling' => pp_validate_composition_styling($composition),
    ];
}

/**
 * Whether one page's diagnostics make `wp pp validate site` fail (#622).
 *
 * Extracted as a pure predicate for the same reason as the #390 gate predicates: the
 * decision is load-bearing (it is the exit code of the command CI runs) and the command
 * itself ends in WP_CLI::halt(), which is hostile to unit testing. pp_composition_pages()
 * additionally caches statically for the life of the process, so the per-page decision
 * is the only part of that loop a test can address directly.
 *
 * All three buckets fail the gate. Advisory smells already did — this is the
 * "nothing is quietly wrong" command, not a severity filter — and #622 adds the error
 * bucket, which is the stronger claim: that page's next ordinary edit would be REFUSED.
 *
 * @param  array $diagnostics  Result of _pp_cli_page_diagnostics().
 * @return bool                True when this page must fail site validation.
 */
function _pp_cli_page_fails_site_validation(array $diagnostics): bool {
    return $diagnostics['errors'] !== []
        || $diagnostics['styling'] !== []
        || $diagnostics['smells'] !== [];
}

/**
 * Renders one finding as a CLI list line (#622).
 *
 * Errors and smells share one locator format — `[type] index N: message` — so an
 * operator reads both the same way. `index` is null only for a cross-item error
 * (duplicate_component_id), whose message already names every colliding index; the
 * locator is then omitted rather than faked.
 *
 * Control and format characters are stripped from the message before it reaches stdout.
 * Composition error messages quote stored prop keys and values verbatim, and the whole
 * point of #622 is to point these commands at data that never passed write validation —
 * so an ANSI or bidi sequence sitting in a raw-written `_pp_composition` would otherwise
 * reach the operator's terminal intact. Stripping here covers every finding in one place
 * regardless of which engine produced it, and also keeps a list item on one line.
 *
 * @param  array $finding  One entry from _pp_composition_findings().
 * @return string
 */
function _pp_cli_finding_line(array $finding): string {
    $locator = isset($finding['index']) && $finding['index'] !== null
        ? ' index ' . $finding['index']
        : '';

    return '  - [' . _pp_cli_printable((string) ($finding['type'] ?? ''))
        . ']' . $locator . ': ' . _pp_cli_printable((string) ($finding['message'] ?? ''));
}

/**
 * Makes an arbitrary untrusted string safe to print as one CLI line (#622, widened #647).
 *
 * TWO INPUT CLASSES, not one. #622 wrote this for STORED strings — raw-written
 * `_pp_composition` data reaching a terminal. Since #647 it is also the owner for raw
 * CALLER ARGV at the refusal sites that quote a flag value back before anything has
 * vouched for it (`--post_id`, `--run-id`, `--token`, the playbook name, the readiness
 * finding key, the stray positional), and for option values read back as prose. If you
 * are adding a sink that prints text this theme did not author, it belongs here.
 *
 * Strips Unicode control and format characters. Note it STRIPS ONLY — unlike the
 * admin and chat owners it applies no length bound, because a terminal line is not a
 * budgeted payload; callers that need a cap bound before calling.
 *
 * On invalid UTF-8 — where the `/u` pattern refuses to run — it REPAIRS the encoding and
 * re-runs the SAME pattern rather than discarding the string or falling back to a weaker
 * one. Keeping the string matters: the readable part of a finding names the component,
 * the prop and the rule, and throwing all of that away over one bad byte would leave the
 * operator with a diagnostic that diagnoses nothing. Keeping the same PATTERN matters
 * more, and is the #647 correction: this used to fall back to a byte-wise
 * `[\x00-\x1f\x7f]` strip, which removed C0 and DEL but not `\p{Cf}` (the bidi and
 * zero-width set) and not the C1 range including 0x9b, the 8-bit CSI. Since `/u` refuses
 * to run on one malformed byte ANYWHERE in the subject, appending a single 0xff
 * downgraded the guard for the whole string — measured, and pinned in
 * tests/ReflectedTextInventoryTest.php with the two vectors that demonstrated it.
 *
 * All three reflection owners now answer "what is clean" with the same pattern and the
 * same repair, which is the property that makes one definition true rather than aspired to.
 *
 * @param  string $text
 * @return string
 */
function _pp_cli_printable(string $text): string {
    $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text);
    if ($clean === null) {
        // REPAIR AND RE-RUN THE SAME PATTERN, which is what both sibling owners already
        // do (_pp_clean_reflected_text, _pp_schema_value_for_message). This used to fall
        // back to a byte-wise `[\x00-\x1f\x7f]+` strip, and that was a SECOND, WEAKER
        // definition of "clean" on exactly the input that most warrants the strong one:
        // it removed C0 and DEL but not \p{Cf} — the bidi and zero-width set — and not
        // the C1 range, including 0x9b, the 8-bit CSI a terminal honours like ESC-[.
        // `/u` refuses to run on ONE malformed byte ANYWHERE in the subject, so appending
        // a single 0xff to a value downgraded the guard for the whole string.
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8'));
    }

    // Reachable, not ceremony: the retry uses the same /u pattern, so a PCRE failure that
    // is not an encoding problem (backtrack limit) returns null again.
    return $clean === null ? '(unprintable)' : $clean;
}

/**
 * Makes the Custom CSS conflict rows printable as a table (#647).
 *
 * NO NEW SANITIZATION SEMANTICS — the owner is _pp_cli_printable() above and this only
 * applies it to the one column that carries text the theme did not author. It exists at
 * all because the same table is printed by TWO commands (`pp check conflicts` and
 * `pp validate site`), and a second inline copy of the mapping is how two sinks drift
 * into disagreeing about which columns are trusted. Same shape as _pp_cli_finding_line():
 * a renderer that delegates.
 *
 * `selector` is read out of the site's own Custom CSS (pp_check_custom_css_conflicts,
 * lib/guardrails.php), which is stored text under nobody's validation. `component` is
 * resolved from the theme's own class list, so it is theme-authored and passes through.
 *
 * Applied HERE and not in the producer on purpose: the same rows also feed an admin
 * notice, which is an HTML sink with an HTML escape, not a terminal one.
 *
 * @param  array $conflicts  Rows from pp_check_custom_css_conflicts().
 * @return array             The same rows, printable.
 */
function _pp_cli_printable_conflict_rows(array $conflicts): array {
    return array_map(static function (array $row): array {
        $row['selector'] = _pp_cli_printable((string) ($row['selector'] ?? ''));
        return $row;
    }, $conflicts);
}

class PP_Check_Command extends WP_CLI_Command {

    /**
     * Reports Custom CSS selectors that conflict with PP component classes.
     *
     * ## EXAMPLES
     *
     *     wp pp check conflicts
     *
     */
    public function conflicts($args, $assoc_args) {
        $conflicts = pp_check_custom_css_conflicts();

        if (empty($conflicts)) {
            WP_CLI::success('No Custom CSS conflicts detected.');
            return;
        }

        WP_CLI::warning(count($conflicts) . ' conflict(s) found:');
        WP_CLI\Utils\format_items('table', _pp_cli_printable_conflict_rows($conflicts), ['selector', 'component']);
    }

    /**
     * Validates a page's stored composition: write-rule errors, styling, smells.
     *
     * Reports BOTH what current write rules reject (error severity — a normal edit
     * of this composition would be refused) and the advisory findings (#622).
     * Exit code is unchanged: this is the per-page inspector and it never halts.
     * `wp pp validate site` is the gate that exits non-zero.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID. Numeric only; slugs and URLs are not resolved.
     *
     * ## EXAMPLES
     *
     *     wp pp check page --post_id=42
     *
     */
    public function page($args, $assoc_args) {
        $post_id = _pp_cli_require_post_id_arg($assoc_args, 'pp check page');

        $result = pp_get_composition_result($post_id);
        if (!$result['ok']) {
            // Corrupt/undecodable _pp_composition — distinct from a blank page
            // so a data-integrity problem isn't reported as "no composition"
            // (issue #144). The sentence moved to pp_composition_integrity_message()
            // (lib/wp.php) when #725 gave `inspect-composition` the same report: one
            // classification, said one way, wherever it surfaces. These bytes are
            // unchanged and pinned — see CompositionShapeTrustTest.
            WP_CLI::warning(pp_composition_integrity_message($post_id, $result['error']));
            return;
        }
        $composition = $result['composition'];
        if (empty($composition)) {
            WP_CLI::warning('No composition found for page ' . $post_id . '.');
            return;
        }

        $diagnostics = _pp_cli_page_diagnostics($composition);
        $errors      = $diagnostics['errors'];
        $warnings    = $diagnostics['styling'];
        $smells      = $diagnostics['smells'];
        $generated   = pp_find_generated_component_ids($composition);

        // Reuses the same predicate `validate site` gates on, so "which diagnostic
        // buckets count" is owned in one place and a future fourth bucket cannot reach
        // one command and silently skip the other (#622). `$generated` is the one bucket
        // that is genuinely check-page-only.
        if (!_pp_cli_page_fails_site_validation($diagnostics) && empty($generated)) {
            WP_CLI::success('Page ' . $post_id . ': valid under current write rules, all components have explicit stable IDs, no ambiguous targeting, no composition smells.');
            return;
        }

        // Errors first: they are the only findings that say a subsequent edit will be
        // REFUSED. Everything below them is advisory (#622).
        if (!empty($errors)) {
            WP_CLI::warning(count($errors) . ' composition error(s) — a normal write of this composition would be rejected:');
            foreach ($errors as $e) {
                WP_CLI::line(_pp_cli_finding_line($e));
            }
        }

        if (!empty($generated)) {
            WP_CLI::warning(count($generated) . ' component(s) without a durable component_id:');
            foreach ($generated as $g) {
                // The component NAME is a stored string from a composition this command
                // exists to inspect precisely because it never passed write validation
                // (#622), and its neighbours in this same body already route through
                // _pp_cli_finding_line() -> _pp_cli_printable() — the inconsistency was
                // inside one foreach (#647). $index is an int.
                //
                // The id is wrapped for the same reason inspect-composition's static message
                // is: it cannot carry hostile bytes TODAY (pp_find_generated_component_ids()
                // only reports an id that is empty or matches the generated `pp-<hex8>`
                // shape), and the day that classifier widens, this sink must already be the
                // one that strips rather than the one that forwards.
                $name  = _pp_cli_printable((string) $g['component']);
                $shown = $g['id'] !== '' ? _pp_cli_printable((string) $g['id']) : '(none)';
                WP_CLI::line("  - {$name} at index {$g['index']} (id: {$shown}): auto-generated ids are regenerated by a full update_composition re-apply. Add an explicit `id` prop to target this component durably.");
            }
        }

        if (!empty($warnings)) {
            WP_CLI::warning(count($warnings) . ' ambiguous targeting warning(s):');
            $rows = [];
            foreach ($warnings as $w) {
                $rows[] = [
                    // format_items writes straight to stdout, so a table CELL is a terminal
                    // sink like any other line and takes the same owner (#647). Indices are
                    // ints; the issue text is a literal.
                    'component' => _pp_cli_printable((string) $w['component']),
                    'indices'   => implode(', ', $w['indices']),
                    'issue'     => 'Duplicate component type without authored IDs (ambiguous targeting)',
                ];
            }
            WP_CLI\Utils\format_items('table', $rows, ['component', 'indices', 'issue']);
        }

        if (!empty($smells)) {
            WP_CLI::warning(count($smells) . ' composition smell(s):');
            foreach ($smells as $s) {
                WP_CLI::line(_pp_cli_finding_line($s));
            }
        }
    }

    /**
     * Classifies a file path as safe, extension, or core.
     *
     * Reports the surface classification and routing guidance for a given
     * file path. Helps agents understand which files they can edit directly
     * vs. which require approved database-backed surfaces.
     *
     * ## OPTIONS
     *
     * <path>
     * : File path to classify (relative to theme root, or absolute).
     *
     * ## EXAMPLES
     *
     *     wp pp check surface lib/wp.php
     *     wp pp check surface components/hero/hero.php
     *     wp pp check surface assets/css/base.css
     *
     */
    public function surface($args, $assoc_args) {
        $path = $args[0] ?? '';
        if ($path === '') {
            WP_CLI::error('Path argument is required.');
        }

        $result = pp_classify_surface($path);

        _pp_cli_emit_json($result);

        if ($result['classification'] === 'core') {
            WP_CLI::warning('Core file — do not edit directly.');
        } elseif ($result['classification'] === 'extension') {
            WP_CLI::warning('Extension file — prefer database-backed surfaces when possible.');
        } else {
            WP_CLI::success('Safe surface.');
        }
    }
}

WP_CLI::add_command('pp check', 'PP_Check_Command');

class PP_Validate_Command extends WP_CLI_Command {

    /**
     * Runs full site validation battery.
     *
     * Checks: Custom CSS conflicts, composition validity + styling + smells for all
     * pages, and composition data integrity.
     *
     * EXIT CODE (#622). This is the "nothing is quietly wrong" gate and the command CI
     * runs, so it now exits non-zero for a page whose stored composition current write
     * rules REJECT, not only for advisory smells. On content written before the #603/
     * #604/#605/#606 vocabulary freeze that is a deliberate red: the page really would
     * refuse its next edit, and reporting it clean was the bug. The shipped starter
     * composition and freshly authored content are clean and stay exit-0.
     *
     * ## EXAMPLES
     *
     *     wp pp validate site
     *
     */
    public function site($args, $assoc_args) {
        $pass = true;

        // 1. Custom CSS conflicts
        WP_CLI::line('--- Custom CSS conflicts ---');
        $conflicts = pp_check_custom_css_conflicts();
        if (!empty($conflicts)) {
            $pass = false;
            WP_CLI::warning(count($conflicts) . ' conflict(s):');
            WP_CLI\Utils\format_items('table', _pp_cli_printable_conflict_rows($conflicts), ['selector', 'component']);
        } else {
            WP_CLI::line('OK: No Custom CSS conflicts.');
        }

        // 2. Composition validity + styling per page
        WP_CLI::line('');
        WP_CLI::line('--- Composition validity and styling ---');
        $pages = pp_composition_pages();
        if (empty($pages)) {
            WP_CLI::line('No composition pages found.');
        } else {
            foreach ($pages as $page) {
                $post_id     = $page['id'];
                // The post TITLE is stored site data and every branch below prints it (#647).
                // Wrapped once, where it is read, so the three sinks under it cannot disagree
                // — and so an ANSI sequence in a page title cannot dress up the line that
                // says the OTHER pages are fine.
                $title       = _pp_cli_printable((string) ($page['title'] ?? '(untitled)'));
                $result      = pp_get_composition_result($post_id);
                if (!$result['ok']) {
                    // Corrupt row must fail validation, not report clean (issue
                    // #144). This is the command CI runs, so a silent-clean here
                    // would hide data corruption from the pipeline.
                    $pass = false;
                    WP_CLI::warning("Page {$post_id} ({$title}): composition data integrity error ({$result['error']}) — stored _pp_composition is not a valid composition list.");
                    continue;
                }
                $composition = $result['composition'];
                $diagnostics = _pp_cli_page_diagnostics($composition);
                $errors      = $diagnostics['errors'];
                $warnings    = $diagnostics['styling'];
                $smells      = $diagnostics['smells'];

                if (_pp_cli_page_fails_site_validation($diagnostics)) {
                    $pass = false;
                    $issue_count = count($errors) + count($warnings) + count($smells);
                    WP_CLI::warning("Page {$post_id} ({$title}): {$issue_count} issue(s)");
                    // Error-severity findings first — these say a normal write of this
                    // composition would be REJECTED, which is a different claim from the
                    // advisories below them (#622).
                    foreach ($errors as $e) {
                        WP_CLI::line(_pp_cli_finding_line($e) . ' (would be rejected on write)');
                    }
                    foreach ($warnings as $w) {
                        // Stored component name, sitting between two loops whose lines already
                        // go through _pp_cli_finding_line() (#647). Indices are ints.
                        $named = _pp_cli_printable((string) $w['component']);
                        WP_CLI::line("  - {$named} at indices " . implode(', ', $w['indices']) . ' (no authored IDs — ambiguous targeting; add explicit `id` props)');
                    }
                    foreach ($smells as $s) {
                        WP_CLI::line(_pp_cli_finding_line($s));
                    }
                } else {
                    WP_CLI::line("OK: Page {$post_id} ({$title})");
                }
            }
        }

        // 3. Summary
        WP_CLI::line('');
        if ($pass) {
            WP_CLI::success('Site validation passed.');
        } else {
            WP_CLI::warning('Site validation found issues. See above.');
            WP_CLI::halt(1);
        }
    }

    /**
     * Runs the rendered-HTML post-apply validation used to gate the AI
     * chat's success message, outside the chat flow (issue 77).
     *
     * Re-renders the page's composition and inspects the HTML: render
     * failures, empty rendered output, broken/empty image sources, missing
     * local media references, invalid inline background-image URLs, empty
     * links, and component-count mismatches. Distinct from `wp pp check
     * page`, which validates the raw composition data — write-rule errors,
     * styling and smells — not the rendered HTML.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID. Numeric only; slugs and URLs are not resolved.
     *
     * [--component-index=<index>]
     * : Validate only this component (0-based index) instead of the whole page.
     *
     * ## EXAMPLES
     *
     *     wp pp validate page --post_id=42
     *     wp pp validate page --post_id=42 --component-index=2
     *
     */
    public function page($args, $assoc_args) {
        $post_id = _pp_cli_require_post_id_arg($assoc_args, 'pp validate page');

        $target = null;
        if (isset($assoc_args['component-index'])) {
            $target = ['component_index' => (int) $assoc_args['component-index']];
        }

        $result = pp_post_apply_validate($post_id, $target);

        if (!empty($result['warnings'])) {
            WP_CLI::line(count($result['warnings']) . ' warning(s):');
            foreach ($result['warnings'] as $w) {
                // pp_post_apply_validate() builds these messages by interpolating the STORED
                // component name, id and media path into them (lib/post-apply-validate.php),
                // so this line has the same content class as a composition finding and takes
                // the same owner (#647). `check` is a rule literal.
                WP_CLI::line('  - [' . $w['check'] . '] ' . _pp_cli_printable((string) $w['message']));
            }
        }

        if ($result['ok']) {
            WP_CLI::success("Page {$post_id}: rendered validation passed.");
            return;
        }

        WP_CLI::line(count($result['errors']) . ' error(s):');
        foreach ($result['errors'] as $e) {
            // Same content class as the warning loop above (#647).
            WP_CLI::line('  - [' . $e['check'] . '] ' . _pp_cli_printable((string) $e['message']));
        }
        WP_CLI::warning("Page {$post_id}: rendered validation failed.");
        WP_CLI::halt(1);
    }
}

WP_CLI::add_command('pp validate', 'PP_Validate_Command');

// ── Operate CLI ─────────────────────────────────────────────────────────────

class PP_Operate_Command extends WP_CLI_Command {

    /**
     * Returns the full site operating picture as JSON.
     *
     * Used by agents at the INSPECT step of the operating loop.
     * Always generates a run token. Pass the returned run_id to all
     * subsequent mutating CLI commands via --run-id.
     *
     * ## OPTIONS
     *
     * [--post_id=<id>]
     * : Include page-specific composition smells for this post.
     *
     * ## EXAMPLES
     *
     *     wp pp operate inspect
     *     wp pp operate inspect --post_id=42
     *
     */
    public function inspect($args, $assoc_args) {
        $post_id = isset($assoc_args['post_id']) ? (int) $assoc_args['post_id'] : null;
        $result = pp_inspect_site($post_id);

        $run_id = pp_operate_create_run();
        if (is_wp_error($run_id)) {
            WP_CLI::error('Cannot create run token: ' . $run_id->get_error_message());
        }

        $result['run_id'] = $run_id;
        _pp_cli_emit_json($result);
    }

    /**
     * Outputs the structured checklist for a playbook.
     *
     * ## OPTIONS
     *
     * --playbook=<name>
     * : Playbook name: create-page, revise-section, or inspect-fix.
     *
     * ## EXAMPLES
     *
     *     wp pp operate checklist --playbook=create-page
     *
     */
    public function checklist($args, $assoc_args) {
        $playbook = $assoc_args['playbook'] ?? '';
        $checklists = pp_operate_checklists();

        if (!isset($checklists[$playbook])) {
            // The name is quoted back BEFORE any membership test has vouched for it, so it is
            // raw argv reaching a terminal — the same class as the --post_id refusal above,
            // and it takes the same owner (#647). `$available` is theme-authored keys.
            $shown     = _pp_cli_printable((string) $playbook);
            $available = implode(', ', array_keys($checklists));
            WP_CLI::error("Unknown playbook '{$shown}'. Available: {$available}");
        }

        _pp_cli_emit_json($checklists[$playbook]);
    }

    /**
     * Validates a loop run manifest against the operating contract.
     *
     * ## OPTIONS
     *
     * --run=<json>
     * : JSON string of the loop run manifest.
     *
     * ## EXAMPLES
     *
     *     wp pp operate validate --run='{"INSPECT":{"site_state":{}},...}'
     *
     */
    public function validate($args, $assoc_args) {
        $raw = $assoc_args['run'] ?? '{}';
        $run = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_CLI::error('Invalid JSON in --run: ' . json_last_error_msg());
        }

        $result = pp_validate_loop_run($run);
        _pp_cli_emit_json($result);

        if (!$result['valid']) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Returns editable composition targets for a page as JSON.
     *
     * Walks each component, looks up the field editability map, and builds
     * semantic selector strings with current values. Used by agents to
     * discover what can be patched on a page.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID. Run `wp pp operate inspect` for the page map (it mints a new run token).
     *
     * ## EXAMPLES
     *
     *     wp pp operate inspect-composition --post_id=19
     *
     * @subcommand inspect-composition
     */
    public function inspect_composition($args, $assoc_args) {
        $post_id = _pp_cli_require_post_id_arg($assoc_args, 'pp operate inspect-composition');

        $result = pp_inspect_composition($post_id);
        if (is_wp_error($result)) {
            // #725 made this branch reachable (this function could not return a WP_Error
            // before). The message is static today — a fixed sentence plus an int post_id
            // plus the classification literal — so nothing stored reaches the terminal.
            // Wrapped anyway: pp_get_composition_result() carries the undecodable payload
            // in its `raw` key, and the day someone extends the repair tail to show it,
            // this sink must already be the one that strips control and format characters
            // rather than the one that forwards a stored ANSI sequence. Same treatment
            // _pp_cli_finding_line() gives every finding, for the same reason.
            WP_CLI::error(_pp_cli_printable($result->get_error_message()));
        }

        _pp_cli_emit_json($result);
    }

    /**
     * Patches a composition field by semantic selector.
     *
     * Parses the selector, resolves the target component and field, then
     * either previews the diff or applies the change through the
     * update_component action path.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID. Run `wp pp operate inspect` for the page map (it mints a new run token).
     *
     * --target=<selector>
     * : Semantic selector (e.g. hero.subheading, section[title="About"].body).
     *
     * --value=<value>
     * : The new value for the targeted field.
     *
     * [--preview]
     * : Show the diff without writing. Read-only — needs no run token.
     *
     * [--run-id=<uuid>]
     * : Run token from `wp pp operate inspect`. Required for the mutating path (everything except --preview), which must sit behind a completed PREFLIGHT covering this page.
     *
     * ## EXAMPLES
     *
     *     wp pp operate patch --post_id=19 --target=hero.subheading --value="New Subtitle" --preview
     *     wp pp operate patch --post_id=19 --target=hero.subheading --value="New Subtitle" --run-id=<uuid>
     *
     */
    public function patch($args, $assoc_args) {
        // Docblock constraint: each OPTIONS description must stay on ONE
        // ": " line. WP-CLI folds continuation ": " lines into the generated
        // synopsis and warns "invalid synopsis part: <word>" on every run.
        $post_id = _pp_cli_require_post_id_arg($assoc_args, 'pp operate patch');

        $selector = $assoc_args['target'] ?? '';
        $value    = $assoc_args['value'] ?? '';
        $preview  = isset($assoc_args['preview']);

        if ($selector === '') {
            WP_CLI::error('--target is required.');
        }

        // Preflight-before-mutation gate (#96/#391). The mutating path writes
        // _pp_composition through the update_component action, so it routes through
        // the SAME per-action gate stack as `action execute` (a valid run-id, a
        // completed INSPECT, and a PREFLIGHT covering this page) — against the REAL
        // update_component registration, not a synthetic partial action array.
        // Using the real action means the patch path also gets the scope-consistency
        // assertion. The #358 composition precondition is enforced inside
        // pp_patch_composition() itself (via the shared predicate, #387), so patching
        // a composition-less page still fails closed early with a clear
        // composition_required error instead of a late, confusing component_not_found.
        // The --preview path stays read-only and ungated (it never touches the action
        // registry). update_component is composition-mutating, so the freshness gate
        // (#113) and baseline refresh apply on the mutating path.
        $expected_version = null;
        if (!$preview) {
            $run_id = _pp_cli_require_run_id($assoc_args);
            if (!pp_operate_check_step($run_id, 'INSPECT')) {
                WP_CLI::error('Run token "' . $run_id . '" has no completed INSPECT step. Run `wp pp operate inspect` first.');
            }
            // Resolve the REAL registered action the patch writes through, so the gate,
            // freshness, and refresh all key on the real registration rather than a
            // synthetic partial array (#391). Fail closed if it is somehow unregistered;
            // the name is hardcoded, so null is a theme bug, not a user error.
            $patch_action = pp_get_action('update_component');
            if ($patch_action === null) {
                WP_CLI::error('The "update_component" action is not registered; cannot gate the patch write. This is a theme bug.');
            }
            // Shared per-action gate: scope-consistency + preflight coverage (#96),
            // against the real registered action. (The #358 composition precondition
            // now fires inside pp_patch_composition() / the shared validator — #387 —
            // not in this gate.)
            _pp_cli_require_preflight_for_action($run_id, $patch_action, ['post_id' => $post_id]);
            // The freshness gate returns the validated baseline version; thread it into the
            // patch so the apply is an atomic compare-and-swap (#13), not check-then-write.
            $expected_version = _pp_cli_require_composition_fresh($run_id, $patch_action, $post_id);
        }

        $result = pp_patch_composition($post_id, $selector, $value, $preview, $expected_version);
        if (is_wp_error($result)) {
            // These messages interpolate BOTH halves of the untrusted set: the caller's own
            // --target selector ("No component of type \"%s\"...") and stored component ids
            // ("Matching IDs: %s"), from lib/operate.php's resolver. Its sibling sink in this
            // same class — inspect_composition() — already wraps for exactly this reason, and
            // that one only reflects a literal (#647).
            WP_CLI::error(_pp_cli_printable($result->get_error_message()));
        }

        // Refresh the freshness baseline (#113) after a successful apply (not preview).
        if (!$preview && isset($run_id)) {
            _pp_cli_refresh_composition_baseline($run_id, $patch_action, $post_id);
            // Touched-post tracking (#133): patch writes _pp_composition through the
            // update_component action, so a run-scoped restore must be able to revert it
            // too. Same fail-loud contract as `action execute`.
            if (!pp_operate_record_touched_post_id($run_id, $post_id)) {
                WP_CLI::error('Patch applied, but recording its touched post for run "' . $run_id . '" FAILED. `wp pp apply restore-composition` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect`.');
            }
        }

        _pp_cli_emit_json($result);
    }

    /**
     * Lists the composition history ring for a page (#133).
     *
     * Shows the prior-composition snapshots recorded before each write, newest first,
     * with the index and steps_back selector to pass to the restore_composition action.
     * Read-only — needs no run token.
     *
     * THIS COMMAND IS THE RECOVERY PATH FOR A CORRUPT PAGE'S BYTES (#818). A ring slot
     * may hold stored bytes that did not decode to a composition rather than a
     * composition snapshot (a decode_error page, or either sub-case of unexpected_shape —
     * a valid-JSON scalar, or a valid-JSON object) — preserved
     * so that repairing a corrupt page no longer destroys the only copy of what was
     * there. Such a row reports `restorable: false`, a null `components` (there is
     * nothing to count), and THREE views of the bytes. Printing them is the point:
     * restore_composition refuses to replay a raw entry, so this listing is the only
     * shipped surface that hands them back, whole and uncapped — a truncated recovery is
     * not a recovery.
     *
     * `raw_base64` IS THE RECOVERY CHANNEL; `raw` is for reading. That split is forced by
     * the corruption class itself: _pp_cli_emit_json() encodes with
     * JSON_INVALID_UTF8_SUBSTITUTE, so a page corrupted by malformed UTF-8 — one of the
     * causes pp_get_composition_result() names for decode_error — would come back through
     * `raw` with those bytes SUBSTITUTED. Silently lossy, in the one field an operator
     * would copy out to recover with. `raw_base64` is pure ASCII and survives the encoder
     * untouched; `raw_sha256` lets them prove the copy that landed on their disk matches
     * the copy this command read. STATED LIMIT, so the field is not over-trusted: the
     * digest is computed HERE, at read time, over what the ring currently holds. It
     * verifies the transfer, NOT the preservation — it cannot tell you the stored entry
     * still matches the bytes that were pushed, because no digest is recorded at push time.
     *
     * A SECOND LIMIT ON THE SAME FIELD, FOR ONE ROW CLASS (#841). A ring written before
     * #841 filed an OBJECT-shaped prior as a `composition` entry (a JSON object decodes to
     * a PHP associative array, which the old push test accepted). Such a row is
     * reclassified to a raw row by _pp_normalize_history_ring(), so it lists here with
     * `restorable: false` and all three views instead of advertising a replay that fatals —
     * but its bytes are that decoded object RE-ENCODED, because the ring never stored the
     * page's own bytes for this class. `raw_base64` still round-trips exactly what this
     * command read, and `raw_sha256` still proves that transfer; neither is a statement
     * about the original stored bytes for a row of this vintage.
     *
     * SIZE, STATED: a raw row emits the payload twice (`raw` plus `raw_base64` at 4/3) with
     * no cap, by design — a truncated recovery is not a recovery. On a pathologically large
     * corrupt payload that makes this command the memory-heaviest read in the CLI. If it
     * cannot complete, the bytes are still in `_pp_composition_history` post meta and can be
     * read directly; nothing is lost, the convenience surface just cannot render it.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID. Run `wp pp operate inspect` for the page map (it mints a new run token).
     *
     * ## EXAMPLES
     *
     *     wp pp operate composition-history --post_id=19
     *
     * @subcommand composition-history
     */
    public function composition_history($args, $assoc_args) {
        $post_id = _pp_cli_require_post_id_arg($assoc_args, 'pp operate composition-history');

        $history = pp_get_composition_history($post_id);
        $count   = count($history);

        // Render newest-first: the last ring entry is the most recent prior state,
        // reachable as steps_back=1. history_index stays the absolute ring position.
        $rows = [];
        foreach ($history as $index => $entry) {
            $row = [
                'history_index' => $index,
                'steps_back'    => $count - $index,
                'version'       => $entry['version'],
                'timestamp'     => $entry['timestamp'],
            ];
            if (pp_history_entry_is_raw($entry)) {
                // Preserved bytes, not a snapshot (#818). `components` stays in the shape
                // as an explicit null rather than being dropped: a row missing the key
                // reads as a listing bug, a row saying null says "there is nothing here
                // to count", which is the true statement.
                $row['components'] = null;
                $row['restorable'] = false;
                $row['raw_bytes']  = strlen($entry['raw']);
                $row['raw_sha256'] = hash('sha256', $entry['raw']);
                $row['raw_base64'] = base64_encode($entry['raw']);
                $row['raw']        = $entry['raw'];
            } else {
                $row['components'] = count($entry['composition']);
                $row['restorable'] = true;
            }
            $rows[] = $row;
        }
        $rows = array_reverse($rows);

        _pp_cli_emit_json([
            'post_id' => $post_id,
            'max'     => pp_composition_history_max(),
            'count'   => $count,
            'entries' => $rows,
        ]);
    }
}

WP_CLI::add_command('pp operate', 'PP_Operate_Command');

// ── Screenshot CLI ──────────────────────────────────────────────────────────

class PP_Screenshot_Command extends WP_CLI_Command {

    /**
     * Captures a screenshot via PP_BROWSER_CMD.
     *
     * ## OPTIONS
     *
     * [--capture-url=<url>]
     * : URL to capture. Required unless --post_id is given.
     *   Named --capture-url to avoid collision with WP-CLI's global --url flag.
     *
     * [--post_id=<id>]
     * : WordPress post ID. Resolves URL via get_permalink().
     *   Numeric only; slugs and URLs are not resolved — pass a URL as --capture-url.
     *
     * [--width=<px>]
     * : Viewport width in pixels. Default: 1280.
     *
     * [--output=<path>]
     * : Output file path. Uses convention if omitted.
     *
     * [--playbook=<name>]
     * : Generate full spec with both viewports for this playbook.
     *
     * ## EXAMPLES
     *
     *     wp pp screenshot capture --capture-url=https://dev.promptingpress.com/ --width=1280
     *     wp pp screenshot capture --post_id=42 --playbook=create-page
     *
     */
    public function capture($args, $assoc_args) {
        $url = $assoc_args['capture-url'] ?? '';
        // #726: a supplied-but-unusable --post_id is refused by name here rather
        // than (int)-cast to 0 and left to fall through to "Either --capture-url or
        // --post_id is required" below — which was a false statement about a flag
        // the operator had just typed. A bare `--post_id` used to cast to 1 and
        // screenshot post 1. Absence stays legal: --capture-url is the other door,
        // and the joint-requirement message below is honest when NEITHER was given.
        $post_id = _pp_cli_optional_post_id_arg($assoc_args, 'pp screenshot capture') ?? 0;
        $width   = (int) ($assoc_args['width'] ?? 1280);
        $output  = $assoc_args['output'] ?? '';
        $playbook = $assoc_args['playbook'] ?? '';

        // Playbook mode: capture both viewports
        if ($playbook && $post_id) {
            $specs = pp_screenshot_spec($post_id, $playbook);
            $results = [];
            foreach ($specs as $spec) {
                $results[] = pp_screenshot_capture($spec);
            }
            _pp_cli_emit_json($results);
            $any_failed = !empty(array_filter($results, fn($r) => !$r['ok']));
            if ($any_failed) {
                WP_CLI::halt(1);
            }
            return;
        }

        // Single capture mode
        if (!$url && $post_id) {
            $url = get_permalink($post_id);
        }
        if (!$url) {
            WP_CLI::error('Either --capture-url or --post_id is required.');
        }

        if (!$output) {
            $base_dir = pp_screenshot_dir();
            $output = $base_dir . '/' . date('Ymd-His') . '-' . $width . 'px.png';
        }

        $spec = [
            'url'    => $url,
            'width'  => $width,
            'height' => 800,
            'output' => $output,
        ];

        $result = pp_screenshot_capture($spec);
        _pp_cli_emit_json($result);

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Diagnoses screenshot-capture readiness for the current runtime context (#497).
     *
     * Reports one definitive tri-state so native screenshot evidence is never an ambient
     * warning:
     *   - `available`   — PP_BROWSER_CMD resolves and a real probe capture succeeded.
     *   - `unavailable` — not configured; lists candidate browser binaries found on $PATH
     *                     plus the one-line setup pointer.
     *   - `broken`      — configured but failing (binary missing, or the probe capture
     *                     itself failed), with the concrete failure.
     *
     * Reports where PP_BROWSER_CMD resolves from (constant vs env) and in which context
     * (CLI `wp` vs web PHP — they can resolve different config). Probes by default: it
     * attempts a real minimal capture so `available` vs `broken` is definitive. Pass
     * --no-probe for a fast capability-only check (no browser launch). Read-only: it never
     * mutates site state (the probe writes a temp file it deletes). Exits 1 when not ready
     * so scripts and the operating loop can gate visual-proof steps on it.
     *
     * ## OPTIONS
     *
     * [--probe]
     * : Attempt a real minimal capture (this is the DEFAULT; flag kept for explicitness).
     *
     * [--no-probe]
     * : Capability-only check — resolve PP_BROWSER_CMD and check its binary without
     *   launching a browser. Cannot distinguish `available` from a probe-time `broken`.
     *
     * ## EXAMPLES
     *
     *     wp pp screenshot doctor
     *     wp pp screenshot doctor --no-probe
     *
     */
    public function doctor($args, $assoc_args) {
        // Probe by default so the tri-state is definitive; --no-probe opts out. In
        // WP-CLI a negatable flag is read off the base key: --no-probe sets 'probe' to
        // false, --probe sets it true, absent uses the default (true).
        $probe = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'probe', true);
        $readiness = pp_screenshot_readiness($probe, true);
        _pp_cli_emit_json($readiness);
        if (!$readiness['ready']) {
            WP_CLI::halt(1);
        }
    }
}

WP_CLI::add_command('pp screenshot', 'PP_Screenshot_Command');

// ── Sync CLI ────────────────────────────────────────────────────────────────

class PP_Sync_Command extends WP_CLI_Command {

    /**
     * Checks for drift between the deployment manifest and live theme files.
     *
     * Reports modified, added (live-only), and deleted files.
     * Exit 0 if clean, exit 1 if drift detected.
     *
     * Read-only: with no deployment manifest there is no baseline to compare
     * against, so this reports the no-baseline state and exits WITHOUT creating
     * one. Establish a baseline explicitly with `--save-manifest` or
     * `wp pp readiness rebaseline`.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Exit 0 regardless of drift (still prints summary).
     *
     * [--save-manifest]
     * : Save current state as the new deployment manifest.
     *
     * ## EXAMPLES
     *
     *     wp pp sync check
     *     wp pp sync check --force
     *     wp pp sync check --save-manifest
     *
     */
    public function check($args, $assoc_args) {
        $target = pp_get_target();
        if ($target['theme_path'] === null) {
            WP_CLI::error('Cannot resolve theme path. Run wp pp target show to diagnose.');
        }

        $theme_path = $target['theme_path'];
        if (!is_dir($theme_path)) {
            WP_CLI::error(sprintf('Theme path %s does not exist.', $theme_path));
        }

        // Save manifest mode
        if (isset($assoc_args['save-manifest'])) {
            $hashes = _pp_hash_theme_files($theme_path);
            $saved = _pp_save_deployment_manifest($theme_path, $hashes);
            if (!$saved) {
                WP_CLI::error('Failed to save deployment manifest. Check permissions on ' . dirname(_pp_deployment_manifest_path()));
            }
            WP_CLI::success(sprintf('Deployment manifest saved: %d files hashed.', count($hashes)));
            return;
        }

        // Load manifest
        $manifest = _pp_load_deployment_manifest();

        if ($manifest === null) {
            // Read-only invariant: `check` REPORTS drift, it never establishes
            // state. With no baseline there is nothing to compare against, so we
            // report the no-baseline state and return WITHOUT writing a manifest.
            // Recording the current (possibly already-drifted) files here would
            // silently poison the baseline — the drift this command exists to
            // detect would be masked as "clean" on every subsequent check, with
            // no operator intent expressed. Establishing a baseline is the job of
            // the explicit `--save-manifest` / `wp pp readiness rebaseline`
            // commands, never a side effect of reading state (issue #522).
            WP_CLI::warning('No deployment manifest found — no baseline to check drift against. `wp pp sync check` reports drift; it does not create a baseline.');
            WP_CLI::line('To establish a baseline explicitly, run one of:');
            WP_CLI::line('  - `wp pp sync check --save-manifest` — snapshot the current theme files as the baseline.');
            WP_CLI::line('  - `wp pp readiness rebaseline` — snapshot AND record the installed release version (drift then reads as "changed since <release>").');
            return;
        }

        $current_hashes = _pp_hash_theme_files($theme_path);
        $manifest_hashes = $manifest['file_hashes'];

        // Compute drift
        $modified = [];
        $added = [];    // live-only (not in manifest)
        $deleted = [];  // in manifest but not live

        foreach ($current_hashes as $file => $hash) {
            if (!isset($manifest_hashes[$file])) {
                $added[] = $file;
            } elseif ($manifest_hashes[$file] !== $hash) {
                $modified[] = $file;
            }
        }

        foreach ($manifest_hashes as $file => $hash) {
            if (!isset($current_hashes[$file])) {
                $deleted[] = $file;
            }
        }

        $has_drift = !empty($modified) || !empty($added) || !empty($deleted);

        // Report
        $report = [
            'drift'    => $has_drift,
            'modified' => $modified,
            'added'    => $added,
            'deleted'  => $deleted,
            'manifest_timestamp'       => $manifest['timestamp'] ?? 'unknown',
            'manifest_release_version' => $manifest['release_version'] ?? null,
        ];

        _pp_cli_emit_json($report);

        if ($has_drift) {
            if (!empty($added)) {
                WP_CLI::warning(sprintf('%d live-only file(s) not in deployment manifest — a sync would NOT include these.', count($added)));
            }
            if (!empty($modified)) {
                WP_CLI::warning(sprintf('%d file(s) modified since last deployment.', count($modified)));
            }
            if (!empty($deleted)) {
                WP_CLI::warning(sprintf('%d file(s) in manifest no longer present on live.', count($deleted)));
            }

            if (isset($assoc_args['force'])) {
                WP_CLI::line('--force: proceeding despite drift.');
                return;
            }

            WP_CLI::halt(1);
        } else {
            WP_CLI::success('No drift detected. Live theme matches deployment manifest.');
        }
    }
}

WP_CLI::add_command('pp sync', 'PP_Sync_Command');

/**
 * Readiness commands — classify findings and resolve/acknowledge them (#496).
 *
 * Readiness/preflight findings carry a class (integrity | configuration |
 * capability) and a sanctioned next action. This command group is the operator
 * surface for that model:
 *
 *   - `status`        (read-only) groups current findings by class with per-finding
 *                     next actions and acknowledgement state.
 *   - `rebaseline`    (mutating) re-baselines the deployment manifest against the
 *                     currently-installed release — the sanctioned reconciliation
 *                     path for integrity drift.
 *   - `acknowledge`   (mutating) records a configuration finding as intentional, so
 *                     it reports as acknowledged instead of as a warning.
 *   - `unacknowledge` (mutating) reverses an acknowledgement.
 *
 * Read-only-status invariant: `status` never mutates. Re-baseline and
 * (un)acknowledge are the ONLY writers, and each is an explicit command — never
 * a side effect of reading state.
 */
class PP_Readiness_Command {

    /**
     * Shows readiness findings grouped by class, with per-finding next actions.
     *
     * READ-ONLY: computes and prints; never writes the manifest or the
     * acknowledgement store.
     *
     * ## EXAMPLES
     *
     *     wp pp readiness status
     *
     */
    public function status($args, $assoc_args) {
        // pp_preflight() is pure (recording happens in the apply-preflight CLI
        // wrapper, not here), so a site-grain call is a safe read-only source of
        // the classified findings.
        $result   = pp_preflight([]);
        $findings = $result['findings'];

        _pp_cli_emit_json($findings);

        $active = (int) ($findings['active_warnings'] ?? 0);
        $ack    = (int) ($findings['acknowledged'] ?? 0);
        if ($active === 0) {
            WP_CLI::success($ack > 0
                ? 'No active warnings (' . $ack . ' acknowledged as intentional).'
                : 'No active warnings.');
        } else {
            WP_CLI::warning($active . ' active finding(s) — each lists a next_action above (' . $ack . ' acknowledged).');
        }
    }

    /**
     * Re-baselines the deployment manifest against the installed release (#496).
     *
     * The sanctioned reconciliation path for integrity drift: snapshots the
     * currently-installed theme files and records the release version, so drift
     * afterward always means "changed since this release".
     *
     * ## EXAMPLES
     *
     *     wp pp readiness rebaseline
     *
     */
    public function rebaseline($args, $assoc_args) {
        $target = pp_get_target();
        $theme_path = $target['theme_path'];
        if ($theme_path === null || !is_dir($theme_path)) {
            WP_CLI::error('Cannot resolve theme path. Run `wp pp target show` to diagnose.');
        }

        $hashes = _pp_hash_theme_files($theme_path);
        if (!_pp_save_deployment_manifest($theme_path, $hashes)) {
            WP_CLI::error('Failed to save deployment manifest. Check permissions on ' . dirname(_pp_deployment_manifest_path()));
        }

        $rel = defined('PP_VERSION') ? PP_VERSION : 'unknown';
        WP_CLI::success(sprintf(
            'Re-baselined deployment manifest against installed release %s (%d files). Drift now means "changed since %s".',
            $rel, count($hashes), $rel
        ));
    }

    /**
     * Records a configuration finding as intentional (#496).
     *
     * Only configuration-class findings are acknowledgeable, and only findings
     * that currently exist — integrity drift is resolved by `rebaseline`, and
     * capability gaps by installing the tool.
     *
     * ## OPTIONS
     *
     * <finding-key>
     * : The finding_key to acknowledge (see `wp pp readiness status`).
     *
     * [--note=<text>]
     * : Optional note recording why it is intentional.
     *
     * ## EXAMPLES
     *
     *     wp pp readiness acknowledge nav_readiness:footer:no_menu --note="footer is deliberately menu-less"
     *
     */
    public function acknowledge($args, $assoc_args) {
        $key = isset($args[0]) ? (string) $args[0] : '';
        if ($key === '') {
            WP_CLI::error('A finding-key is required. Run `wp pp readiness status` to list acknowledgeable configuration findings.');
        }

        $current = pp_current_configuration_finding_keys();
        if (!in_array($key, $current, true)) {
            $available = empty($current) ? '(none currently present)' : implode(', ', $current);
            // Pre-membership-test echo of raw argv (#647). The SUCCESS message below keeps
            // using $key unwrapped on purpose: it is only reached once the key has matched
            // pp_current_configuration_finding_keys(), i.e. once it is a theme-authored key.
            WP_CLI::error('Not an acknowledgeable configuration finding: "' . _pp_cli_printable($key) . '". '
                . 'Only currently-present configuration findings can be acknowledged (integrity drift → `wp pp readiness rebaseline`; capability gaps → install the tool). '
                . 'Available: ' . $available);
        }

        $note = isset($assoc_args['note']) ? (string) $assoc_args['note'] : '';
        $acks = pp_acknowledged_findings();
        $acks[$key] = ['acknowledged_at' => date('c'), 'note' => $note];
        update_option('pp_acknowledged_findings', $acks);

        WP_CLI::success('Acknowledged configuration finding "' . $key . '" as intentional. '
            . 'It now reports as acknowledged, not a warning. Reverse with `wp pp readiness unacknowledge ' . $key . '`.');
    }

    /**
     * Reverses an acknowledgement (#496).
     *
     * ## OPTIONS
     *
     * <finding-key>
     * : The finding_key to un-acknowledge.
     *
     * ## EXAMPLES
     *
     *     wp pp readiness unacknowledge nav_readiness:footer:no_menu
     *
     */
    public function unacknowledge($args, $assoc_args) {
        $key = isset($args[0]) ? (string) $args[0] : '';
        if ($key === '') {
            WP_CLI::error('A finding-key is required.');
        }

        $acks = pp_acknowledged_findings();
        if (!isset($acks[$key])) {
            // Same pre-membership echo as `acknowledge` (#647): this branch is exactly the
            // one where $key matched nothing, so nothing has vouched for its bytes.
            WP_CLI::error('Finding "' . _pp_cli_printable($key) . '" is not acknowledged; nothing to reverse.');
        }

        unset($acks[$key]);
        update_option('pp_acknowledged_findings', $acks);

        // WRAPPED, unlike `acknowledge`'s success line, and the asymmetry is the point
        // (#647). That one is reached only after $key matched
        // pp_current_configuration_finding_keys() — theme-authored constants. THIS one is
        // vouched for by `isset($acks[$key])` against the pp_acknowledged_findings OPTION,
        // which is stored site data under nobody's validation, so membership here proves
        // the key was stored, not that it is printable.
        WP_CLI::success('Un-acknowledged "' . _pp_cli_printable($key) . '". If the underlying condition still holds it will report as an active warning again.');
    }
}

WP_CLI::add_command('pp readiness', 'PP_Readiness_Command');

/**
 * Theme integrity commands — compare live files against the shipped baseline manifest.
 */
class PP_Integrity_Command {

    /**
     * Run a full integrity check against the shipped manifest.
     *
     * Hashes all current theme files, compares against integrity-manifest.json,
     * and stores the result in the pp_theme_integrity option.
     *
     * ## EXIT CODES
     *
     * 0 — safe (all files match)
     * 1 — unsafe (modified, missing, or extra files detected)
     * 2 — invalid manifest (JSON parse error or schema validation failure)
     * 3 — no manifest found (pre-integrity theme version)
     *
     * @subcommand check
     */
    public function check($args, $assoc_args): void {
        $result = pp_check_theme_integrity();

        if ($result === null) {
            WP_CLI::line('No integrity manifest found. This theme version predates integrity tracking.');
            WP_CLI::halt(3);
            return;
        }

        if ($result['status'] === 'invalid_manifest') {
            WP_CLI::error(sprintf(
                'Integrity manifest is invalid (theme version %s): %s',
                PP_VERSION,
                $result['error'] ?? 'Unknown error'
            ), false);
            WP_CLI::halt(2);
            return;
        }

        // Print the result as formatted JSON.
        _pp_cli_emit_json($result);

        if ($result['status'] === 'safe') {
            WP_CLI::success('All theme files match the shipped manifest.');
            return;
        }

        // Status is 'unsafe'.
        if (!empty($result['modified'])) {
            WP_CLI::warning('Modified files (hash mismatch):');
            foreach ($result['modified'] as $path) {
                WP_CLI::line('  - ' . $path);
            }
        }

        if (!empty($result['missing'])) {
            WP_CLI::warning('Missing files (in manifest but not on disk):');
            foreach ($result['missing'] as $path) {
                WP_CLI::line('  - ' . $path);
            }
        }

        if (!empty($result['extra'])) {
            WP_CLI::warning('Extra files (on disk but not in manifest):');
            foreach ($result['extra'] as $path) {
                WP_CLI::line('  - ' . $path);
            }
            WP_CLI::line('These files are not part of the shipped theme. A theme update or reinstall');
            WP_CLI::line('replaces the entire theme directory and will delete them.');
            WP_CLI::line('Recommendation: move extra files to a child theme, plugin, or wp-content/.');
        }

        WP_CLI::halt(1);
    }

    /**
     * Print the stored integrity status (read-only, no file hashing).
     *
     * Reads the pp_theme_integrity option and prints it. Does NOT run a new
     * check or modify the stored option. If the stored version differs from
     * the current PP_VERSION, prints a staleness warning.
     *
     * @subcommand status
     */
    public function status($args, $assoc_args): void {
        $option = get_option('pp_theme_integrity');

        if (!is_array($option) || empty($option['status'])) {
            WP_CLI::line('No integrity check results stored. Run `wp pp integrity check` first.');
            return;
        }

        // Staleness warning — read-only, does NOT delete or update the option.
        $stored_version = $option['version'] ?? 'unknown';
        if ($stored_version !== PP_VERSION) {
            WP_CLI::warning(sprintf(
                'Results are from version %s, current theme is %s — run `wp pp integrity check` to refresh.',
                // Read straight out of the pp_theme_integrity OPTION and printed as prose
                // (#647). Only PP_VERSION ever writes it, but nothing enforces that shape
                // at READ time, and the comparison above is what makes this line reachable
                // with a value that is not PP_VERSION. PP_VERSION itself is a theme
                // constant and needs no guard.
                _pp_cli_printable((string) $stored_version),
                PP_VERSION
            ));
        }

        _pp_cli_emit_json($option);
    }
}

WP_CLI::add_command('pp integrity', 'PP_Integrity_Command');
