<?php
/**
 * lib/admin.php — PromptingPress Admin Composition Editor
 *
 * Responsibilities:
 * - pp_get_registered_components()  scan components/ directory
 * - pp_template_owned_components()  components the base template renders itself
 * - pp_validate_composition()       validate a composition array (names the blocking band)
 * - pp_validate_composition_item()  validate ONE item not yet on a page (no band locator)
 * - register_post_meta              declare _pp_composition meta
 * - add_meta_boxes                  "Edit Composition →" link on page edit screen
 * - wp_ajax_pp_save_composition     AJAX save handler
 * - admin page pp-composition       full-screen three-pane composition workspace
 * - admin_enqueue_scripts           load assets on workspace page
 * - wp_ajax_pp_preview_composition  AJAX preview (renders composition as full-page HTML)
 */

// ── Component Registry ──────────────────────────────────────────────────────

/**
 * Scans components/ and returns all registered components with their schemas.
 *
 * @return array  Keyed by component name: ['hero' => ['props' => [...]], ...]
 */
function pp_get_registered_components(): array {
    // Keyed by theme root (issue #576). The cache used to be a single unkeyed slot, so a
    // caller that repointed get_template_directory() kept getting the PREVIOUS root's scan
    // until something set the invalidate flag — and the flag was checked BEFORE the
    // directory was read, so the handshake could not close the gap on its own. In
    // production the theme root is constant per request and this is behaviour-identical;
    // the tests that swap the root (ApplyTest, PreflightTest, SetupTest, ...) are where an
    // empty-registry answer used to leak across classes. That matters more since #576,
    // which consults the registry on every composition read.
    static $cache = [];
    if (!empty($GLOBALS['_pp_registered_components_invalidate'])) {
        $cache = [];
        unset($GLOBALS['_pp_registered_components_invalidate']);
    }

    $base = get_template_directory() . '/components/';
    if (isset($cache[$base])) {
        return $cache[$base];
    }
    $cache[$base] = [];

    if (!is_dir($base)) {
        return $cache[$base];
    }

    foreach (scandir($base) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $php = $base . $name . '/' . $name . '.php';
        if (!file_exists($php)) {
            continue;
        }
        $schema_file = $base . $name . '/schema.json';
        $schema      = [];
        if (file_exists($schema_file)) {
            $decoded = json_decode(file_get_contents($schema_file), true);
            if (is_array($decoded)) {
                $schema = $decoded;
            }
        }
        $cache[$base][$name] = $schema;
    }

    return $cache[$base];
}

/**
 * Components the base template renders itself — site chrome, not page content.
 *
 * These stay in the registry: templates/base.php renders them via
 * pp_get_component(), and the admin preview needs their schemas. They are
 * simply not composable — placing one in _pp_composition would render the
 * chrome twice (issue #223).
 *
 *   templates/base.php
 *     ├── pp_get_component('nav',    ['location' => 'primary'])   ← chrome
 *     ├── <main> … _pp_composition renders here …  </main>        ← content
 *     └── pp_get_component('footer', ['location' => 'footer'])    ← chrome
 *
 * Registered ⊋ composable. Every consumer of that distinction reads this list:
 * pp_validate_composition() (write-time), pp_validate_composition_smells()
 * (stored rows), pp_post_apply_validate() (rendered pages), pp_ai_context()
 * (the catalog the AI reads), and the editor's JS registry.
 *
 * @return string[]  Component names that may not appear in a composition.
 */
function pp_template_owned_components(): array {
    return ['nav', 'footer'];
}

/**
 * Whether a component is site chrome rather than page content.
 *
 * The membership test every consumer needs. Named, so the rule reads the same at
 * each call site and there is one place to change if the list ever stops being a
 * flat array of names.
 *
 * @param  string $name  Component name.
 * @return bool
 */
function pp_is_template_owned_component(string $name): bool {
    return in_array($name, pp_template_owned_components(), true);
}

/**
 * The registered components that may actually appear in a composition.
 *
 * Registered means "the theme can render it". Composable means "a page may
 * declare it". The two are not the same set, and every AI-facing surface must
 * advertise the composable one.
 *
 * @return array  Same shape as pp_get_registered_components(), minus chrome.
 */
function pp_composable_components(): array {
    return array_diff_key(
        pp_get_registered_components(),
        array_flip(pp_template_owned_components())
    );
}

/**
 * Builds the operator-facing reason a template-owned component was rejected.
 *
 * Names the supported surface for each, so the caller (AI or human) is pointed
 * at the path that actually works instead of retrying the composition write.
 *
 * @param  string $name  Component name (assumed template-owned).
 * @return string
 */
function pp_template_owned_component_message(string $name): string {
    $surfaces = [
        'nav'    => 'Set the site logo via the "pp_logo_id" site option, and the navigation menu via the menu actions (create_menu / assign_menu_location).',
        'footer' => 'Set the site logo via the "pp_logo_id" site option, and the footer menu via the menu actions (create_menu / assign_menu_location).',
    ];

    return sprintf(
        '"%s" is site chrome rendered by the page template; it cannot be placed in a page composition. %s',
        $name,
        $surfaces[$name] ?? ''
    );
}

// ── Schema definition surface (issue #575) ───────────────────────────────────

/**
 * The keys a component `schema.json` may declare on a STYLE SLOT definition object.
 *
 * The definition surface is closed (issue #575). An unlisted key is a typo, a
 * half-landed feature, or a second source of truth — all three are the drift the
 * slot surface already suffered, so the schema-shape validator rejects them rather
 * than ignoring them.
 *
 * ENFORCEMENT REACH, and this paragraph was made stale by #1087 — it used to say nothing
 * called this validator on a live request, which was true until the obligation composers
 * began delegating to it. It now runs on the CHAT PATH: `_pp_udc_role_is_composable()` calls
 * it once per role per model-facing composer, measured at 153 invocations per
 * pp_ai_system_prompt() build. The unit cost is 0.0006ms, so today that is ~0.1ms of a
 * ~1.0ms build — but the MULTIPLIER is what matters to the next person here: any bounded-
 * string or regex check added to this function is priced 153x per chat turn. The closed set
 * is still a repo-CI invariant for the SHIPPED schemas (SchemaValidationTest walks all
 * twelve); what changed is that a hand-edited schema on a live install now meets it too,
 * which is the point of the delegation. That is sufficient today because components are discovered
 * only from get_template_directory().'/components/' (pp_get_registered_components,
 * above) — there is no child-theme or plugin registration path, so the only schemas
 * that exist are the ones CI already checks. A hand-edited schema on a live install
 * is NOT validated. Wiring this engine to a runtime findings surface is a candidate
 * follow-up, not a gap in the contract as scoped.
 *
 * @return string[]
 */
function pp_slot_definition_keys(): array {
    return [
        'type',                 // required — the value grammar (color, length, enum, …)
        'default',              // required — the rendered fallback
        'description',          // required — the agent-facing explanation
        'values',               // enum slots only — the bounded value set
        'item_eligible',        // grid per-item scope flag (#323)
        'applies_when',         // #575 — machine-readable conditionality
        'conditionality_note',  // #575 — the prose escape hatch, bounded and named
        'role',                 // #575 — the declared slot-role marker; values bounded by pp_slot_roles() (fill, measure)
    ];
}

/**
 * The keys a component `schema.json` may declare on a PROP definition object.
 *
 * @return string[]
 */
function pp_prop_definition_keys(): array {
    return [
        'type', 'required', 'default', 'description',
        'format', 'values', 'strict',
        'item_type', 'items', 'min', 'max', 'max_items', 'item_max_length',
        'applies_when',         // #575
        'conditionality_note',  // #575
    ];
}

/**
 * The keys a component `schema.json` may declare on a ROLE definition object (#1087).
 *
 * The third definition surface, closed for the same reason as its two siblings above —
 * and it is the one that shipped UNGUARDED. Slot and prop definitions have been a closed
 * set since #575; role definitions were added by the v2 rebuilds with no key check at all,
 * so a typo'd key was accepted by every surface and ignored forever. That is the
 * accepted-stored-ignored class: a declaration the system records and never acts on,
 * reporting nothing — the same shape #1048 names one layer down (a `udc` value stored on a
 * role whose selector the emitter skips) and the shape T8's review found in the Layer-2
 * exclusion set. A guard here is the cheap half of it.
 *
 * Before this gate, all 125 shipped roles declared exactly `selector`, `description`,
 * `groups` and `defaults`, uniformly — verified across every schema before this list was
 * written, so closing the set rejected nothing that existed. They declare `obligations` as
 * well now, which this gate made required.
 *
 * `obligations` is the new one and the reason this function exists now. See
 * pp_udc_obligation_kinds() for what it carries and why it is DECLARED rather than
 * inferred from selectors.
 *
 * @return string[]
 */
function pp_role_definition_keys(): array {
    return [
        'selector',      // required — the CSS selector the role's values emit at
        'description',   // required — MAINTAINER-facing prose; reaches `wp pp schema`, NEVER the prompt
        'groups',        // required — the UDC groups this role permits
        'defaults',      // the role's own default values, per group
        'overlay_defaults', // #1010 — defaults re-lit on a band the engine marks `data-pp-band-overlay`
        'within',        // #1010 review — the roles whose elements enclose this one (a LIST of role names)
        'obligations',   // #1087 — MODEL-facing pairing/contrast obligations, bounded
        'text_content',  // #1125 — true when author text inside this role's element takes the colour set on
                         // this role; set by MEASUREMENT (ink the role alone, look for a glyph in that ink)
    ];
}

/**
 * The bounded value set for a role obligation's `kind` (#1087).
 *
 * WHAT AN OBLIGATION IS. A fact about this role that an author MUST act on when they
 * write to it or to its partner, and that no other channel can tell them. Two shapes
 * ship today, both measured:
 *
 *   outranked_by_default  ANOTHER role's DEFAULT beats a value authored here, because
 *                         that role's selector is a superset of this one's — same
 *                         element, heavier selector. faq's `question` / `question-open`
 *                         is the shipped instance, measured at 3.21:1 (#1059).
 *   reached_only_by_inheritance
 *                         Another role selects a DESCENDANT of this one, so a value
 *                         authored here reaches it only by inheritance and loses to the
 *                         stylesheet's direct rule. THIRTEEN shipped instances: the six
 *                         composable `*-link` pairs inside author-written rich text
 *                         (#1069), plus seven chrome pairs on nav and footer.
 *
 * WHY DECLARED AND NOT INFERRED, stated here because the honest answer is asymmetric and
 * the code must not pretend otherwise. `reached_only_by_inheritance` IS mostly derivable
 * from the selectors — a descendant combinator is a string fact, and the derivation
 * reproduces TWELVE of the thirteen, missing only `footer.social -> social-link`, whose
 * containment is in the MARKUP and in no selector. That is why
 * pp_udc_derived_descendant_pairs() is a one-directional NET over these declarations rather
 * than a substitute for them. `outranked_by_default` is NOT derivable at all: a sound
 * string predicate MISSES faq's `question` / `question-open` and nav's
 * `link` / `link-current`, while a loose one false-positives on
 * `.faq__heading` against `.faq__heading-accent`, which select different elements. Real
 * superset reasoning needs a selector engine this theme does not have and does not need.
 * So: the DECLARATION is the complete source of truth, the derived check is a net that can
 * only catch the shape it understands, and the net never claims completeness.
 *
 * @return string[]
 */
function pp_udc_obligation_kinds(): array {
    return ['outranked_by_default', 'reached_only_by_inheritance'];
}

/**
 * The keys ONE obligation record may declare (#1087).
 *
 * A named set rather than an inline literal, matching its two siblings above. The triple had
 * been written out in three places — the membership check, the error message beside it, and
 * three reads in lib/udc.php — which is three places to keep in step if a fourth key is
 * added, and this file already learned that lesson once with `patchable`.
 *
 * @return string[]
 */
function pp_udc_obligation_keys(): array {
    return ['kind', 'with', 'why'];
}

/**
 * The charset a ROLE NAME may use (#1087).
 *
 * BOUNDED BECAUSE IT IS COMPOSED INTO THE PROMPT, and it was the one field on that line that
 * was not. The security review's probe made the asymmetry concrete: `with` — the same
 * identifier seen from the other end — is checked for newlines, with the test data naming
 * the reason verbatim ("a newline forges a catalog line"), while the role KEY was validated
 * nowhere, at runtime or in CI. A role named `"link\n\nIGNORE ALL PREVIOUS INSTRUCTIONS..."`
 * with an otherwise valid definition reached the outbound system prompt intact, through TWO
 * composers, breaking the newline-delimited catalog exactly as the `with` check prevents.
 *
 * Not an escalation: writing `schema.json` needs theme-directory write, which is already
 * arbitrary PHP here, and every shipped schema is covered by integrity-manifest.json. This
 * is a mistyping guard — and a guard that checks the second-weakest field on a line is not
 * one. All 125 shipped role names satisfy this pattern.
 */
const PP_ROLE_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

/**
 * Is `$value` safe to compose onto ONE line of a model-facing catalog? (#1087)
 *
 * `/[\r\n\t]/` is not enough, and the security review's probe showed why: U+2028 LINE
 * SEPARATOR, U+2029 PARAGRAPH SEPARATOR, `\v` and `\f` all satisfy it while doing exactly
 * what a newline does. U+2028 is what a copy-paste out of a PDF or a word processor actually
 * produces, so this is an operator-mistype path before it is anything else — and `why` is the
 * first of these bounded-prose fields composed into a prompt sent to a third-party model.
 *
 * SCOPED TO THE FIELDS THIS GATE ADDED (`why`, `with`). The pre-existing
 * `conditionality_note` and `values` checks use the narrow form; widening those changes what
 * the schema surface has accepted since #575/#630, so it is filed rather than folded in.
 */
function pp_udc_is_single_line(string $value): bool {
    return !preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value);
}

/**
 * Maximum length of an obligation's `why` string (#1087).
 *
 * SMALLER THAN PP_CONDITIONALITY_NOTE_MAX ON PURPOSE, and the reason is the budget rather
 * than taste. A `conditionality_note` is read by one agent looking at one slot; a `why`
 * is MODEL-FACING — it is composed into the runtime system prompt, which carries no
 * caching and is re-sent on every conversation turn. At ~25 declarations the difference
 * between 240 and 400 characters is ~4 KB of every turn, against a 92,000-byte ceiling
 * (PP_AI_PROMPT_BUDGET) that this gate's own rewrite has to land underneath.
 *
 * THE DIVISION OF LABOUR, because a role now has two prose fields and writing the wrong
 * thing in either is the drift this gate exists to end: `why` says what the author must
 * DO and fits one or two sentences; the role `description` carries the rationale, the
 * ruling references and the history, and never reaches the prompt at all.
 */
const PP_OBLIGATION_WHY_MAX = 240;

/**
 * The bounded value set for the slot definition's `role` marker (issue #575).
 *
 * NOT TO BE CONFUSED WITH A ROLE DEFINITION. `role` here is a KEY on a style-slot
 * definition, whose values are the two markers below. A "role" in the v2 sense is a named
 * part of a component, validated by pp_role_definition_keys() above. The two meanings
 * collide in one word and in one file; the `$kind === 'slot'` guard on the `role` check in
 * pp_schema_definition_errors() is what keeps them apart.
 *
 * `fill` marks a colour slot as a component's BUTTON/SURFACE FILL, so the warning
 * engine can tell a fill slot from any other colour slot. It is a DECLARED key, not
 * a `-bg` / `-hover-bg` name convention: a naming convention is not machine-readable
 * without a second source of truth, which is the defect this whole contract fixes
 * one layer down. #575 landed the field one gate ahead of its consumer; #579 wired
 * that consumer, the `transparent_fill` composition smell. NO SLOT DECLARES THE FIELD ANY
 * MORE: hero's left in #986, section's `--section-panel-cta-bg` in #1023, and cta's four
 * button fills in #1026, so the roster is EMPTY and the advisory is dormant rather than
 * wrong — it can fire on nothing until some component declares a fill slot again (#1036).
 * A v2 button fill is a role's `background.fill`, and the advisory recognises slots by their
 * declared `role: "fill"` marker, which a role parameter does not carry or need.
 *
 * `measure` marks a slot as a TEXT MEASURE — the max-width of a heading, a prose
 * column, or a content column — so the advisory engine can tell one from any other
 * length slot (#578). Same reasoning as `fill`, and the same reason it cannot be a
 * name convention: hero's measure is spelled `--hero-content-width`, so a `-measure`
 * suffix rule would miss the one slot the hero docs point every author at.
 * Its consumer is DEFERRED to issue #610, exactly as `fill` was landed one gate ahead
 * of its own: the advisory ruling 1 describes is unsatisfiable until length slots accept
 * a bare token reference (_pp_validate_length rejects every var() form today), and the
 * smells channel halts `wp pp validate site` — so shipping it now would red a fresh
 * install against the theme's own starter homepage. The MARKER ships here because the
 * measure surface it describes ships here; the warning follows the grammar.
 *
 * @return string[]
 */
function pp_slot_roles(): array {
    return ['fill', 'measure'];
}

/**
 * Maximum length of a `conditionality_note` string.
 *
 * "Bounded prose" has to be bounded by something or the word is decoration. A note
 * is one or two sentences naming a condition the four `applies_when` clause forms
 * cannot express; anything longer belongs in the slot `description` or in a doc.
 */
const PP_CONDITIONALITY_NOTE_MAX = 400;

/**
 * Validates ONE `applies_when` clause against the closed four-form grammar (#575).
 *
 * The grammar is BOUNDED and does not grow in #575. Exactly four forms exist:
 *
 *     { "prop": "<name>", "equals": "<value>" }
 *     { "prop": "<name>", "in": ["<v>", …] }
 *     { "prop": "<name>", "present": true }
 *     { "slot": "--<name>", "present": true }
 *
 * Clauses in an `applies_when` array are ANDed. There is deliberately NO `any_of`
 * clause, NO `context` clause and NO free-form structure: three condition classes
 * stay PROSE in `conditionality_note` precisely so the machine-readable grammar
 * never has to grow to swallow them —
 *
 *   - DISJUNCTION — e.g. a slot that applies on dark bands only, i.e.
 *     `theme: inverted` OR `background_image` present.
 *   - COMPOSED-PAGE CONTEXT — `--grid-item-bar-*` / `--grid-featured-*` (RETIRED #1101; no shipped declaration left) applied only
 *     under a `main >` scope, which is not a prop, not a slot and not a value.
 *   - INTERACTION STATE — a question's open state.
 *
 * (Viewport-scoped behaviour is neither: responsive slot values are out of scope by
 * ruling, and breakpoint families are DEFAULTS, not authored conditions.)
 *
 * If the grammar ever needs to grow, that growth lands HERE, in a future revision of
 * this contract, BEFORE anything populates it.
 *
 * @param  mixed  $clause  One clause from an `applies_when` array.
 * @param  string $label   Caller-supplied context for the error message.
 * @return string[]        Human-readable errors; empty when the clause is valid.
 */
function pp_applies_when_clause_errors($clause, string $label): array {
    if (!is_array($clause) || $clause === []) {
        return ["{$label}: each applies_when clause must be a non-empty object."];
    }

    $errors  = [];
    $subject = null;
    foreach (['prop', 'slot'] as $key) {
        if (array_key_exists($key, $clause)) {
            if ($subject !== null) {
                return ["{$label}: a clause declares exactly one subject — `prop` or `slot`, never both."];
            }
            $subject = $key;
        }
    }
    if ($subject === null) {
        return ["{$label}: a clause must declare a `prop` or a `slot` subject."];
    }
    if (!is_string($clause[$subject]) || $clause[$subject] === '') {
        $errors[] = "{$label}: `{$subject}` must be a non-empty string.";
    }
    if ($subject === 'slot' && is_string($clause['slot']) && strpos($clause['slot'], '--') !== 0) {
        $errors[] = "{$label}: a `slot` subject must be a custom-property name starting with `--`.";
    }

    $predicates = array_values(array_intersect(['equals', 'in', 'present'], array_keys($clause)));
    if (count($predicates) !== 1) {
        // Report the unknown keys TOO, rather than returning early: a clause that
        // misspells its predicate (`{prop, iss}`) would otherwise be told only that
        // a predicate is missing, hiding the actual typo it contains.
        $errors[] = "{$label}: a clause must declare exactly one predicate — `equals`, `in` or `present`.";
        foreach (array_keys($clause) as $key) {
            if (!in_array($key, ['prop', 'slot', 'equals', 'in', 'present'], true)) {
                $errors[] = "{$label}: unknown clause key `{$key}` (the grammar is bounded to prop/slot + equals/in/present).";
            }
        }
        return $errors;
    }
    $predicate = $predicates[0];

    // The sibling-slot form is presence-only: a slot has no comparable authored
    // value at schema-declaration time, only "set or unset".
    if ($subject === 'slot' && $predicate !== 'present') {
        $errors[] = "{$label}: a `slot` subject supports only the `present` predicate.";
    }

    if ($predicate === 'equals'
        && ((!is_string($clause['equals']) && !is_int($clause['equals'])) || $clause['equals'] === '')) {
        $errors[] = "{$label}: `equals` must be a non-empty string or an integer value.";
    }
    // Every string this grammar carries is rendered into the AI catalog inside
    // double quotes, so a quote character in any of them forges catalog syntax.
    foreach (['prop', 'slot', 'equals'] as $quoted) {
        if (isset($clause[$quoted]) && is_string($clause[$quoted]) && strpos($clause[$quoted], '"') !== false) {
            $errors[] = "{$label}: `{$quoted}` must not contain a double quote.";
        }
    }
    if ($predicate === 'in') {
        if (!is_array($clause['in']) || $clause['in'] === [] || !pp_is_list($clause['in'])) {
            $errors[] = "{$label}: `in` must be a non-empty LIST of values.";
        } else {
            foreach ($clause['in'] as $value) {
                if ((!is_string($value) && !is_int($value)) || $value === '') {
                    $errors[] = "{$label}: every `in` member must be a non-empty string or an integer value.";
                    break;
                }
                if (is_string($value) && strpos($value, '"') !== false) {
                    $errors[] = "{$label}: an `in` member must not contain a double quote.";
                    break;
                }
            }
        }
    }
    if ($predicate === 'present' && $clause['present'] !== true) {
        // `present: false` would be a NEGATION — a fifth clause form. Not in this
        // grammar; express the inverse with the positive condition on the sibling.
        $errors[] = "{$label}: `present` accepts only the literal true (there is no negated form).";
    }

    // Closed clause shape: subject + predicate and nothing else.
    $allowed = [$subject, $predicate];
    foreach (array_keys($clause) as $key) {
        if (!in_array($key, $allowed, true)) {
            $errors[] = "{$label}: unknown clause key `{$key}` (the grammar is bounded to prop/slot + equals/in/present).";
        }
    }

    return $errors;
}

/**
 * Evaluates ONE `applies_when` clause against an authored component (issue #580).
 *
 * The EVALUATOR half of the grammar #575 landed. It lives here, immediately below
 * pp_applies_when_clause_errors(), on purpose: a clause form that the validator
 * accepts but the evaluator does not understand is exactly the drift ruling 8
 * forbids, and the two cannot silently diverge when they sit in one block and the
 * evaluator ASKS the validator what a clause is before reading it.
 *
 *     schema.json  styling.style_slots.<slot>.applies_when
 *        │
 *        ├─ BEFORE the write ─► pp_ai_definition_suffix() ──┐
 *        │                        (the AI catalog line)      │
 *        │                                                   ├─► pp_ai_format_applies_when_clause()
 *        └─ AFTER the write ──► pp_applies_when_clause_met()  │      renders the clause as PROSE
 *                                 └─► ..._unmet_clauses() ────┘      for both surfaces
 *                                       └─► inert_slot smell
 *
 * ONE field, two consumers, ONE phrasing, no second condition table.
 *
 * FAIL OPEN is the posture throughout. This function backs a NON-BLOCKING advisory,
 * so every ambiguity resolves to "met" (i.e. stay silent). A warning that fires on a
 * shape the evaluator cannot reason about is worse than no warning: `wp pp validate
 * site` halts on ANY smell (lib/cli.php), so a false positive is an operator-visible
 * failure with no authorable fix — the exact trap that deferred #578's measure
 * advisory to issue #610. Concretely, these all return true:
 *
 *   - a clause the grammar validator rejects (a hand-edited schema; the definition
 *     surface is a repo-CI invariant, not a runtime gate — see pp_slot_definition_keys);
 *   - an `equals`/`in` comparison against a value that is neither a string nor an
 *     int (a bool, an array, null) — there is no defined comparison, so do not invent one;
 *   - an `equals`/`in` on a prop that is absent AND declares no `default`;
 *   - a `present` clause on a prop the author DID set to a value the predicate has no
 *     reading for (a bool, an int, an object). `present` means "non-empty string or
 *     non-empty array", so `show_logo: true` is outside its vocabulary — and reporting
 *     "applies when show_logo is set" about a prop the author just set to true is the
 *     worst kind of false positive, because the advice is visibly wrong. Conditions on
 *     boolean and numeric props therefore ride `conditionality_note` (see the nav/footer
 *     chrome preconditions), and a schema guard keeps every `present` clause on a
 *     string/array prop.
 *
 * `present` on an ABSENT prop is the deliberate exception to fail-open: absent means NOT
 * present, because "the author never set `eyebrow`, so the six eyebrow slots render
 * nothing" is the whole point of the field.
 *
 * DEFAULT RESOLUTION. An absent prop takes its schema `default`, never null. Without
 * this, `{"prop":"card_emphasis","equals":"featured"}` would have reported every grid that
 * omits the prop — i.e. most of them — as inert, since `featured` is the default.
 *
 * @param  mixed  $clause      One clause from an `applies_when` array.
 * @param  array  $props       The component's authored props (defaults NOT applied).
 * @param  array  $prop_defs   The component's schema `props` map (for `default`).
 * @param  array  $style_map   The component's authored style map, canonical slot names.
 * @return bool                True when the clause holds — or cannot be evaluated.
 */
function pp_applies_when_clause_met($clause, array $props, array $prop_defs, array $style_map): bool {
    if (!is_array($clause) || pp_applies_when_clause_errors($clause, 'eval') !== []) {
        return true;
    }

    // Sibling-slot form: presence in the authored style map, nothing else. A slot has
    // no comparable authored value at schema-declaration time, only "set or unset".
    if (array_key_exists('slot', $clause)) {
        $name = $clause['slot'];
        if (!array_key_exists($name, $style_map)) {
            return false;
        }
        $value = $style_map[$name];
        return is_scalar($value) && (string) $value !== '';
    }

    $name  = $clause['prop'];
    $value = array_key_exists($name, $props)
        ? $props[$name]
        : ($prop_defs[$name]['default'] ?? null);

    if (array_key_exists('present', $clause)) {
        // "Non-empty string, or non-empty array" — the definition the grammar ships
        // with (ai-instructions/add-component.md). Two deliberate calls here, both
        // resolving toward silence, because a false positive halts `wp pp validate site`:
        //
        //   NOT PHP TRUTHINESS. `"0"` is falsy in PHP, and the renderers' `if ($eyebrow)`
        //   gates DO drop it — so a `"0"` eyebrow renders nothing while this returns
        //   true. That is a missed warning, never a wrong one.
        //
        //   NOT TRIMMED. The renderers do not trim either (`if ($eyebrow)` on a
        //   whitespace-only string is TRUE and emits a visible pill), so trimming here
        //   would report six eyebrow slots inert on a band whose eyebrow is on screen —
        //   advice the author can see is wrong. Renderer parity wins.
        if (is_string($value)) {
            return $value !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        // Set, but to a shape `present` has no reading for (bool/int/float/object).
        // Fail OPEN like every other unevaluable case: `show_logo: true` is a value the
        // author explicitly wrote, and "applies when show_logo is set" would be advice
        // that is visibly false. Only ABSENT (null) is "not present".
        return $value !== null;
    }

    // equals / in — comparable values only (see FAIL OPEN above).
    if (!is_string($value) && !is_int($value)) {
        return true;
    }
    $value = (string) $value;

    if (array_key_exists('equals', $clause)) {
        return $value === (string) $clause['equals'];
    }
    foreach ($clause['in'] as $candidate) {
        if ($value === (string) $candidate) {
            return true;
        }
    }
    return false;
}


/**
 * Validates ONE slot, prop or role DEFINITION OBJECT from a component `schema.json`.
 *
 * The single shared engine for the definition surface (issue #575) — the schema
 * counterpart of pp_validate_composition_errors(), which validates the documents
 * schemas describe. All three definition surfaces run THIS function;
 * there is deliberately no second, surface-specific definition validator.
 *
 *     schema.json
 *        │
 *        ├── styling.style_slots.<name>  ──┐
 *        │                                 │
 *        ├── props.<name>  ────────────────┼──►  pp_schema_definition_errors()
 *        │                                 │         │
 *        └── roles.<name>  ────────────────┘         ├─ closed key set (rejects unknown keys)
 *            (#1087)                                 ├─ applies_when → pp_applies_when_clause_errors()
 *                                                    ├─ conditionality_note → bounded string
 *                                                    ├─ values → bounded catalog strings (#630)
 *                                                    ├─ role → pp_slot_roles()       (slots only)
 *                                                    └─ obligations → bounded records  (roles only)
 *
 * The `aliases` leg that hung off the prop surface is GONE (#606). It declared legacy
 * VALUES accepted at write and never advertised; with every entry retired (#603/#604/
 * #605) the field itself went, so `aliases` is now an UNKNOWN definition key and fails
 * the closed key set above — on props, on slots, and on nested `items.<sub>` fields,
 * which run through this same engine.
 *
 * `patchable` is unknown here for a different reason: it was never ADDED to the key set.
 * #509 shipped a `"patchable": false` opt-out in pp_component_scalar_type() and documented
 * it in AI_IMPLEMENTATION_RECIPES (v1.8.2), before this engine existed; when #575 closed
 * the key set in v1.12.4 it left `patchable` off, so from that release a schema following
 * those docs failed CI right here while the docs went on instructing it. No schema in this
 * repo ever declared it, in either window.
 * #629 resolved the contradiction by deleting the readers and the instruction rather than
 * widening this list, so `patchable` stays unknown by omission, not by removal.
 *
 * @param  array  $definition  The decoded definition object.
 * @param  string $kind        'slot', 'prop' or 'role' (#1087).
 * @param  string $label       Context for error messages, e.g. 'hero --hero-bg'.
 * @param  array<string, mixed>|null $sibling_roles The component's roles keyed by name; when given, each `within`
 *                                                name must be one of them (#1142 item 2). Null (the runtime
 *                                                composable-role gate) skips that check; the schema CI walk passes it.
 * @return string[]            Human-readable errors; empty when the definition is valid.
 */
function pp_schema_definition_errors(array $definition, string $kind, string $label, ?array $sibling_roles = null): array {
    $errors = [];

    // An explicit dispatch, not a ternary. The two-surface ternary this replaces read
    // `$kind === 'slot' ? slot : prop`, so a THIRD kind would have silently been validated
    // against the prop key set — accepting `type`, `items` and `min` on a role definition
    // and rejecting `selector`. A mistyped kind now has no key set rather than the wrong
    // one, and says so.
    $allowed = match ($kind) {
        'slot' => pp_slot_definition_keys(),
        'prop' => pp_prop_definition_keys(),
        'role' => pp_role_definition_keys(),
        default => null,
    };
    if ($allowed === null) {
        return ["{$label}: unknown definition kind `{$kind}` (expected slot, prop or role)."];
    }

    foreach (array_keys($definition) as $key) {
        if (!in_array($key, $allowed, true)) {
            $errors[] = "{$label}: unknown {$kind} definition key `{$key}`.";
        }
    }

    if (array_key_exists('applies_when', $definition)) {
        $clauses = $definition['applies_when'];
        if (!is_array($clauses) || $clauses === [] || !pp_is_list($clauses)) {
            $errors[] = "{$label}: `applies_when` must be a non-empty ARRAY of clauses (ANDed).";
        } else {
            foreach ($clauses as $i => $clause) {
                $errors = array_merge($errors, pp_applies_when_clause_errors($clause, "{$label} applies_when[{$i}]"));
            }
        }
    }

    if (array_key_exists('conditionality_note', $definition)) {
        $note = $definition['conditionality_note'];
        if (!is_string($note) || trim($note) === '') {
            $errors[] = "{$label}: `conditionality_note` must be a non-empty string.";
        } elseif (mb_strlen($note) > PP_CONDITIONALITY_NOTE_MAX) {
            // Characters, not bytes: the message says "characters" and the field is
            // prose, so a note of accented or non-Latin text must not be rejected at
            // half the stated budget with a count it never had.
            $errors[] = sprintf(
                '%s: `conditionality_note` is bounded prose — %d characters exceeds the %d-character limit.',
                $label,
                mb_strlen($note),
                PP_CONDITIONALITY_NOTE_MAX
            );
        }
        if (is_string($note) && preg_match('/[\r\n\t]/', $note)) {
            // The AI catalog is line-oriented; an embedded newline forges catalog
            // lines. Bound the shape at authoring time, not at the emitter.
            $errors[] = "{$label}: `conditionality_note` must be a single line (no newlines or tabs).";
        }
    }

    // Every `values` member is rendered into the AI catalog INSIDE double quotes:
    // `"cards"|"steps"` in the prop catalog (pp_ai_condense_schema) and in the slot
    // line beside it, both in lib/ai-context.php. A member carrying a double quote
    // forges the value set an agent parses; one carrying a newline forges a whole
    // catalog line. Bound the SHAPE here, at authoring time, never in the emitter.
    //
    // The quote half is the rule the sibling quoted strings already follow —
    // `applies_when`'s `prop`/`slot`/`equals` subjects and its `in` members, above.
    // The single-line half comes from `conditionality_note`, just above; the
    // `applies_when` strings do NOT carry it yet, so this is not a claim of parity
    // across the whole definition surface. Nor does `values` follow `in` on member
    // TYPE: `in` accepts a string OR an integer, `values` accepts strings only,
    // which is what all 31 shipped declarations are.
    //
    // Triggered by PRESENCE, not by `type === 'enum'`: this bounds the FIELD wherever
    // it is declared, the same way `applies_when`, `conditionality_note` and `role` are
    // validated. No shipped schema declares `values` on a non-enum, so it rejects
    // nothing that exists and leaves no unguarded corner if one ever appears.
    //
    // Each violation KIND is reported once. A malformed container reports that and
    // skips the member loop, so a `values: "cards"` never iterates a string.
    //
    // "Non-empty" is literal — `''` is rejected, `' '` is not. A whitespace-only
    // member is a nonsense declaration, but rejecting it is a rule this guard was
    // not asked to add, and `conditionality_note`'s trim() is a bound on PROSE, not
    // the precedent for an identifier-ish value set.
    if (array_key_exists('values', $definition)) {
        $values = $definition['values'];
        if (!is_array($values) || $values === [] || !pp_is_list($values)) {
            $errors[] = "{$label}: `values` must be a non-empty LIST of strings.";
        } else {
            $bad_member = false;
            $has_quote  = false;
            $multiline  = false;
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    $bad_member = true;
                    continue;
                }
                if (strpos($value, '"') !== false) {
                    $has_quote = true;
                }
                if (preg_match('/[\r\n\t]/', $value)) {
                    $multiline = true;
                }
            }
            if ($bad_member) {
                $errors[] = "{$label}: every `values` member must be a non-empty string.";
            }
            if ($has_quote) {
                $errors[] = "{$label}: a `values` member must not contain a double quote.";
            }
            if ($multiline) {
                $errors[] = "{$label}: a `values` member must be a single line (no newlines or tabs).";
            }
        }
    }

    if ($kind === 'slot' && array_key_exists('role', $definition)) {
        if (!is_string($definition['role']) || !in_array($definition['role'], pp_slot_roles(), true)) {
            $errors[] = sprintf(
                '%s: `role` must be one of: %s.',
                $label,
                implode(', ', pp_slot_roles())
            );
        }
    }

    // ROLE SHAPE (#1087). The three structural keys are type-checked, and the red-team pass is
    // why: the composability gate DELEGATES "definition shape" to this function, and for
    // `role` this function checked only the closed key set and the obligations block. So
    // `"groups": "typography"` — brackets forgotten — passed the gate as VALID and then
    // fataled the entire system prompt in a caller's implode(), taking down the in-admin chat
    // surface instead of degrading. A `selector` given as an array emitted eleven
    // "Array to string conversion" diagnostics, which is the exact warning class the
    // fail-safe docblocks cite as the lesson already learned once.
    //
    // Checked here rather than at each reader, because the gate's promise is that a role it
    // approves is SAFE TO COMPOSE — a promise it could not keep while the shape went
    // unexamined.
    if ($kind === 'role') {
        if (array_key_exists('selector', $definition)
            && (!is_string($definition['selector']) || !pp_udc_is_single_line($definition['selector']))) {
            $errors[] = "{$label}: `selector` must be a single-line string.";
        }
        if (array_key_exists('groups', $definition)
            && (!is_array($definition['groups']) || !pp_is_list($definition['groups']))) {
            $errors[] = "{$label}: `groups` must be a LIST of group names.";
        } elseif (isset($definition['groups'])) {
            foreach ($definition['groups'] as $group) {
                if (!is_string($group) || $group === '') {
                    $errors[] = "{$label}: every `groups` member must be a non-empty string.";
                    break;
                }
            }
        }
        // `{}` and `[]` are indistinguishable once decoded, so an EMPTY defaults block is
        // accepted — the same carve-out the container rules elsewhere in this file make for
        // the same reason. Only a POPULATED list is wrong here.
        if (array_key_exists('defaults', $definition)
            && (!is_array($definition['defaults'])
                || ($definition['defaults'] !== [] && pp_is_list($definition['defaults'])))) {
            $errors[] = "{$label}: `defaults` must be a MAP of groups, not a list.";
        }
        if (array_key_exists('overlay_defaults', $definition)
            && (!is_array($definition['overlay_defaults'])
                || ($definition['overlay_defaults'] !== [] && pp_is_list($definition['overlay_defaults'])))) {
            $errors[] = "{$label}: `overlay_defaults` must be a MAP of groups, not a list.";
        } elseif (isset($definition['overlay_defaults']) && is_array($definition['groups'] ?? null)) {
            // Compiled as an AUTHORED map (_pp_udc_overlay_tier_css), so a group the role does
            // not permit would be dropped at render with no message on any surface. Refuse it
            // here, where the schema author will see it.
            foreach ($definition['overlay_defaults'] as $group => $group_map) {
                if (!in_array($group, $definition['groups'], true)) {
                    $errors[] = "{$label}: `overlay_defaults` group `{$group}` is not one of this role's `groups`.";
                    continue;
                }
                // #1142 item 2: a STATE inside the tier would print at (0,3,0) and outrank an author's
                // resting value, contradicting "your value wins"; a key that is no parameter of the group
                // was dropped silently at render.
                // A GROUP must be a map of parameters: a scalar would skip every value check below and still reach
                // `wp pp schema`'s raw-unicode sink whole (PR-2 red team; it needs a schema write).
                if (!is_array($group_map) || ($group_map !== [] && pp_is_list($group_map))) {
                    $errors[] = "{$label}: `overlay_defaults` group `{$group}` must be a MAP of parameters.";
                    continue;
                }
                $group_params = pp_udc_groups()[$group]['params'] ?? null;
                // A group the registry does not know cannot compile, and without its parameter list nothing would
                // check the keys below before `wp pp schema` prints them (PR-2 review cycle 2, security).
                if (!is_array($group_params)) {
                    $errors[] = "{$label}: `overlay_defaults` group `{$group}` is not a UDC group.";
                    continue;
                }
                foreach ($group_map as $key => $value) {
                    $key = (string) $key;
                    if (strncmp($key, ':', 1) === 0) {
                        $errors[] = "{$label}: `overlay_defaults` group `{$group}` must not hold a state (`{$key}`): the tier is a resting default.";
                        continue;
                    }
                    if (!isset($group_params[$key])) {
                        $errors[] = "{$label}: `overlay_defaults` group `{$group}` has no parameter `{$key}`.";
                        continue;
                    }
                    // THE VALUES TOO (PR-2 review, security): printed whole by `wp pp schema` through its raw-unicode
                    // sink, so a value is a single-line string, or a breakpoint map of them, as `defaults` values are.
                    // The SAME standard as `selector` and `description`, deliberately: pp_udc_is_single_line() refuses
                    // line-breaking controls, not format characters (\p{Cf}, e.g. bidi overrides). Schema files live
                    // under the theme root, so what else they carry rests on theme-root integrity, not on this check
                    // (PR-2 review cycle 2, security; option b).
                    $leaves   = is_array($value) ? $value : [$value];
                    $shape_ok = $leaves !== [] && (!is_array($value) || array_diff_key($value, pp_udc_breakpoints()) === []);
                    foreach ($leaves as $leaf) {
                        $shape_ok = $shape_ok && is_string($leaf) && $leaf !== '' && pp_udc_is_single_line($leaf);
                    }
                    if (!$shape_ok) {
                        $errors[] = "{$label}: `overlay_defaults` group `{$group}` parameter `{$key}` must be a single-line string, or a breakpoint map of them.";
                    }
                }
            }
        }
        if (array_key_exists('within', $definition)
            && (!is_array($definition['within']) || !pp_is_list($definition['within'])
                || array_filter($definition['within'], static fn ($r): bool => !is_string($r) || $r === '') !== [])) {
            $errors[] = "{$label}: `within` must be a LIST of role names.";
        } elseif (isset($definition['within']) && $sibling_roles !== null) {
            // #1142 item 2: a `within` name that is no role of the component names a surface that never
            // exists. Checked where the caller knows the component's roster (the schema CI walk).
            foreach ($definition['within'] as $outer) {
                if (!isset($sibling_roles[$outer])) {
                    $errors[] = "{$label}: `within` names `{$outer}`, which is not a role of this component.";
                }
            }
        }
        // `text_content` (#1125): written only where a measurement found a glyph in the role's ink,
        // so `true` is the one meaningful value; anything else is a schema typo, refused here.
        if (array_key_exists('text_content', $definition) && $definition['text_content'] !== true) {
            $errors[] = "{$label}: `text_content` must be true (omit it for a role whose own element renders no author text).";
        }
        if (array_key_exists('description', $definition) && !is_string($definition['description'])) {
            $errors[] = "{$label}: `description` must be a string.";
        }
    }

    // ROLE OBLIGATIONS (#1087) — bounded records, not prose.
    //
    // Triggered by PRESENCE and gated to `role`, matching how `role` is gated to `slot`
    // just above: the key is unknown on the other two surfaces, so the closed set already
    // rejects it there, and validating it anyway would report a second error for the same
    // mistake.
    //
    // REQUIREDNESS IS NOT CHECKED HERE. It is asserted in the schema walk, beside the
    // existing `assertArrayHasKey('type', …)` checks for slots (SchemaValidationTest), which
    // is where every other definition-surface required key is enforced. One pattern, not two.
    // That walk is also the only place a cross-role check is POSSIBLE: this function sees one
    // definition and cannot know whether `with` names a role its component declares.
    //
    // Each violation KIND reports once, and a malformed container skips the member loop, so
    // an `obligations: "none"` never iterates a string. Same posture as `values` above.
    if ($kind === 'role' && array_key_exists('obligations', $definition)) {
        $obligations = $definition['obligations'];
        if (!is_array($obligations) || !pp_is_list($obligations)) {
            $errors[] = "{$label}: `obligations` must be a LIST (use `[]` for a role that carries none).";
        } else {
            $seen        = [];
            $bad_shape   = false;
            $bad_kind    = false;
            $bad_with    = false;
            $bad_why     = false;
            $long_why    = false;
            $multiline   = false;
            $duplicated  = false;
            foreach ($obligations as $entry) {
                if (!is_array($entry) || pp_is_list($entry)) {
                    $bad_shape = true;
                    continue;
                }
                foreach (array_keys($entry) as $key) {
                    if (!in_array($key, pp_udc_obligation_keys(), true)) {
                        $errors[] = sprintf(
                            '%s: unknown obligation key `%s` (expected %s).',
                            $label,
                            $key,
                            implode(', ', pp_udc_obligation_keys())
                        );
                    }
                }
                $entry_kind = $entry['kind'] ?? null;
                $entry_with = $entry['with'] ?? null;
                $entry_why  = $entry['why']  ?? null;

                if (!is_string($entry_kind) || !in_array($entry_kind, pp_udc_obligation_kinds(), true)) {
                    $bad_kind = true;
                }
                // `with` names a SIBLING ROLE. Single-line for the same reason `why` is:
                // both are composed into a line-oriented prompt catalog.
                if (!is_string($entry_with) || trim($entry_with) === '' || !pp_udc_is_single_line($entry_with)) {
                    $bad_with = true;
                }
                if (!is_string($entry_why) || trim($entry_why) === '') {
                    $bad_why = true;
                } else {
                    // Characters, not bytes — the message says characters, and the field is
                    // prose. PP_CONDITIONALITY_NOTE_MAX's own comment records why.
                    if (mb_strlen($entry_why) > PP_OBLIGATION_WHY_MAX) {
                        $long_why = true;
                    }
                    if (!pp_udc_is_single_line($entry_why)) {
                        $multiline = true;
                    }
                    // AND A BYTE BOUND BESIDE THE CHARACTER ONE. The character cap is the
                    // right primary bound for prose (PP_CONDITIONALITY_NOTE_MAX's docblock
                    // argues it: accented or non-Latin text must not be cut at half the
                    // stated budget). But the budget this field is sized against —
                    // PP_AI_PROMPT_BUDGET — is in BYTES, and the two disagree by up to 4x:
                    // the security review measured a 240-CHARACTER emoji `why` at 960 bytes,
                    // which is four times the figure this cap's own budget argument assumes.
                    // So both bounds apply, and the arithmetic in that argument is true again.
                    if (strlen($entry_why) > PP_OBLIGATION_WHY_MAX * 2) {
                        $long_why = true;
                    }
                }
                // DUPLICATE DETECTION on the (kind, with) pair. Two records for the same
                // partner and the same shape are one obligation stated twice: the prompt
                // would compose the roster with a repeated member, which reads to a model as
                // two separate facts about the same pair.
                if (is_string($entry_kind) && is_string($entry_with)) {
                    $fingerprint = $entry_kind . "\0" . $entry_with;
                    if (isset($seen[$fingerprint])) {
                        $duplicated = true;
                    }
                    $seen[$fingerprint] = true;
                }
            }
            if ($bad_shape) {
                $errors[] = "{$label}: every `obligations` member must be an OBJECT with kind, with and why.";
            }
            if ($bad_kind) {
                $errors[] = sprintf(
                    '%s: an obligation `kind` must be one of: %s.',
                    $label,
                    implode(', ', pp_udc_obligation_kinds())
                );
            }
            if ($bad_with) {
                $errors[] = "{$label}: an obligation `with` must be a non-empty single-line role name.";
            }
            if ($bad_why) {
                $errors[] = "{$label}: an obligation `why` must be a non-empty string.";
            }
            if ($long_why) {
                $errors[] = sprintf(
                    '%s: an obligation `why` is bounded prose and exceeds its limit — it reaches '
                    . 'the runtime prompt on every turn, so put the rationale in `description`. '
                    . 'The bound is %d characters AND %d bytes: the character cap is the right '
                    . 'primary bound for prose, and the byte cap exists because the prompt budget '
                    . 'it is sized against is measured in bytes.',
                    $label,
                    PP_OBLIGATION_WHY_MAX,
                    PP_OBLIGATION_WHY_MAX * 2
                );
            }
            if ($multiline) {
                // "Single line" now means no control character and no Unicode line or
                // paragraph separator — see pp_udc_is_single_line().
                $errors[] = "{$label}: an obligation `why` must be a single line (no control "
                    . 'characters, and no U+2028 / U+2029 separators).';
            }
            if ($duplicated) {
                $errors[] = "{$label}: two `obligations` entries share the same `kind` and `with`.";
            }
        }
    }

    // The `aliases` validation block that stood here is GONE (#606). It shaped a field
    // that no longer exists: a declaration is now caught one level up, by the closed key
    // set, as an unknown definition key. That is the STRONGER gate — the old block
    // accepted a well-shaped alias list, where the key set rejects the concept.

    return $errors;
}

// ── Validation ───────────────────────────────────────────────────────────────

/**
 * Normalizes a composition array's ITEM SHAPE. Not a name-alias surface (#604).
 *
 * This function no longer rewrites any key. It strips empty `style` arrays (no
 * overrides = no key) and nothing else. Every name canonicalization that used to
 * live here is GONE:
 *
 *   - the `type` -> `component` item-key alias (#604). `component` is the only
 *     item key that names a component. An item keyed only `type` is rejected by
 *     pp_validate_composition_errors() as `invalid_composition` ("missing the
 *     `component` key"), because absorbing a hallucinated key silently is what
 *     stopped the authoring agent from ever learning the real one.
 *   - the legacy prop-KEY alias map (#495/#576, removed in #604). All 13 retired
 *     prop names now fall through to the strict `unknown_prop` gate.
 *   - the `variant` -> `layout`/`theme` migration (#69/#388/#400, removed in
 *     #604). It was read-path only and never ran here; the write path has
 *     rejected `variant` since #388 and still does.
 *
 * The removals STRENGTHEN validation: a retired name is now a named rejection on
 * every write path instead of a silent, unreported repair. Stale stored documents
 * carrying those names break loudly — the intended outcome, not a regression.
 *
 * @param  array $items  Raw composition array.
 * @return array         Composition array with empty style arrays stripped.
 */
function pp_normalize_composition(array $items): array {
    foreach ($items as $i => $item) {
        // Strip empty style arrays (no overrides = no key).
        if (isset($items[$i]['style']) && is_array($items[$i]['style']) && empty($items[$i]['style'])) {
            unset($items[$i]['style']);
        }
    }
    return $items;
}

/**
 * Renders an `items[]` array KEY as the item locator inside a validation message (#634).
 *
 * The single renderer for the "item N" fragment every nested rule in
 * pp_validate_composition_errors() emits, for the two message builders those rules
 * delegate to (_pp_link_url_error_message, _pp_validate_style_slot_map), and — since
 * #650 — for the BAND locator one level up, which reaches it through
 * _pp_band_index_label(). That last consumer is why the table below says "container
 * array" rather than "items array": the same rule answers "which item?" and "which
 * band?", so the two depths cannot drift into different conventions. Before #634 the
 * SAME function reported the position two ways: six rules rendered the key honestly while
 * the link-URL and per-item-style paths hard-cast it, so a composition whose `items`
 * decoded to a JSON OBJECT rather than a list — `{"aa": {...}}`, reachable from
 * create_page/update_composition, a raw update_post_meta() write, or a history-ring
 * snapshot — reported `item 0`. `(int) "aa"` is 0, and there is no item 0: the message
 * sent the operator to repair an element that does not exist, which is worse than
 * carrying no locator at all.
 *
 * THE CONTAINER IS THE DISCRIMINATOR, NOT THE KEY (#652). Rendering the key honestly was
 * only half the locator: PHP folds a numeric-STRING object key to an integer at decode,
 * so `{"1": ..., "0": ...}` and `["a", "b"]` hand this function the identical argument
 * and it cannot tell a list POSITION from an object KEY by looking at one. When the two
 * disagree the locator names a real key that points at the wrong element — an operator
 * told `item 0` counts to the FIRST card and repairs the one that is fine, while the bad
 * entry sits under key "0" in second place. So the caller passes the CONTAINER and
 * pp_is_list() answers the question the key cannot:
 *
 *   container array          key passed here    container   rendered locator
 *   ["a", "b"]               int 1              list        `1`          (list position)
 *   {"aa": {...}}            string "aa"        object      `key "aa"`
 *   {"5": {...}}             int 5 (folded)     object      `key "5"`
 *   {"1": .., "0": ..}       int 0 (folded)     object      `key "0"`    (#652's repro)
 *   (no container in scope)  either             null        the bare key
 *
 * A LIST CONTAINER IS BYTE-IDENTICAL to every version since #634 — that is deliberate and
 * pinned. Every shipped example, every doc snippet and every existing test authors `items`
 * as a JSON list, so the overwhelmingly common message must not move; only the shape that
 * was already lying changes.
 *
 * THE LIMIT, STATED SO IT IS NOT DISCOVERED LATER: an ORDERED numeric object survives
 * decode as a PHP list. `json_decode('{"0":"a","1":"b"}', true)` is `["a","b"]` — the
 * keys ARE 0..n-1 in order, so pp_is_list() says list and this renders `0`, not
 * `key "0"`. The object/list distinction is destroyed before any PHP here can see it,
 * and no amount of inspection recovers it. That case is also the harmless one: key and
 * position agree, so the locator addresses the right element either way. What this
 * function can fix is exactly the case where they DISAGREE, and it fixes all of it.
 *
 * Typed `int|string` because a PHP array key is exactly that — the callers all read a
 * `foreach` key, so the `gettype()` arm the inline copies carried was unreachable. The
 * signature states the assumption instead of branching on it.
 *
 * THE KEY IS BOUNDED, AND BY THIS FILE'S OWN OWNER (#649). It used to be rendered WHOLE —
 * no truncation, no strip — which left a 300-character key echoed entire and an ANSI byte
 * inside a stored key reaching the action envelope, the editor save response and the chat
 * reply intact (only the CLI was covered, by _pp_cli_finding_line()). It now goes through
 * _pp_schema_value_for_message(), the same helper the VALUES in these messages use, so key
 * and value carry ONE bound and ONE sanitization rule instead of two.
 *
 * THAT HELPER SUPPLIES THE QUOTES, which is why the `sprintf('key "%s"')` literal quotes
 * came off with the conversion rather than being kept around it: `key "aa"` is
 * BYTE-IDENTICAL for every well-formed key — including an integer key from a folded numeric
 * object (`key "5"`) and the degenerate empty key (`key ""`). What changes is only what was
 * never legible anyway: control and format characters are stripped, a key past
 * PP_REFLECTED_VALUE_MAX_LENGTH is cut and marked, and invalid UTF-8 is repaired.
 *
 * WHY NOT _pp_clean_reflected_text(), the shared owner for the same job. The reason USED TO
 * BE reachability: that owner lived in lib/ai-chat.php, which functions.php loads only under
 * is_admin(), while this function runs under WP-CLI too (`wp pp check page` ->
 * pp_validate_composition_errors()) — so the call would have been a fatal undefined function
 * on exactly the never-validated data those commands exist to inspect. #864 moved the owner
 * to lib/wp.php, so that half is now false and calling it here would work.
 *
 * WHAT STILL DECIDES IT IS SEMANTICS, which is the half that was always load-bearing. These
 * messages reflect a single VALUE and want it quoted and short: this helper supplies the
 * quotes itself and bounds at PP_REFLECTED_VALUE_MAX_LENGTH. The shared owner supplies no
 * quotes and bounds at PP_REFLECTED_ERROR_MAX, which is the budget for a whole composed
 * message. Two owners, two jobs, one definition of "clean" underneath neither borrowing the
 * other's bound.
 *
 * STILL NOT ESCAPED, and still on purpose: a key containing a double quote renders
 * ambiguously (`key "a"b"`). Bounding is this axis; QUOTING GRAMMAR is a different one and
 * is not ruled on, so this keeps the family's shared spelling rather than inventing a
 * one-off escape the sibling messages would not share. Pinned as a recorded limitation in
 * tests/DiagnosticReachTest.php::itemIndexLabelProvider().
 *
 * TWO DIFFERENT GUARDS, because there are two different ways to lose the discriminator.
 * A FORGOTTEN argument is caught by the SIGNATURE: `$container` is required and
 * non-defaulted, so omitting it is an ArgumentCountError at the call, not a silent
 * fallback to list semantics. A DELIBERATE `null` is caught by the source tripwire in
 * tests/DiagnosticReachTest.php, which forbids a literal null argument anywhere in this
 * file — every rule here has its container in scope, so passing null would only ever be a
 * shortcut back to the #634 defect. The null ARM still exists for callers outside this
 * file and for the direct unit tests, which legitimately have no container.
 *
 * @param  int|string $index      An `items[]` key, or a composition key when called
 *                                through _pp_band_index_label().
 * @param  array|null $container  The array that key came from, or null when the caller
 *                                genuinely has no view of it.
 * @return string                 The locator fragment, never a fabricated position.
 */
function _pp_item_index_label(int|string $index, ?array $container): string {
    return ($container !== null && !pp_is_list($container))
        ? 'key ' . _pp_schema_value_for_message($index)
        : (string) $index;
}

/**
 * Renders a COMPOSITION offset as the band locator inside a structural message (#650).
 *
 * One level up from _pp_item_index_label(), and deliberately built ON it so the two
 * depths cannot answer "which one?" differently. The two structural rules in
 * pp_validate_composition_errors() — a band with no `component` key, and one whose
 * `component` is non-scalar — used to hard-format the composition key with `%d`. A
 * composition stored as a JSON OBJECT decodes to string keys, so `{"aa": {...}}` reported
 * `Item 0`: `(int) "aa"` is 0, and there is no band 0. The SAME WP_Error disagreed with
 * itself, because _pp_composition_item_error() records `is_int($index) ? $index : null`
 * and honestly carried `index: null` while its own message named a band. Message and
 * payload must agree; a locator is real or absent, never fabricated.
 *
 *   composition            rendered band locator
 *   [band, band]           `Item 0`, `Item 1`      (list positions — byte-identical)
 *   {"aa": {...}}          `Item key "aa"`         (was the fabricated `Item 0`)
 *   {"1": .., "0": ..}     `Item key "1"`, `Item key "0"`
 *
 * THE OBJECT ARM IS NO LONGER REACHED FROM A WRITE (#724), and that is a property of the
 * caller, not of this renderer. Both production call sites sit inside
 * pp_validate_composition_errors()'s per-item loop, and that loop is now preceded by a
 * container gate that refuses a non-list composition outright — so no rejected write can
 * produce `Item key "aa"` for a BAND again. The arm is kept, not deleted: it is what makes
 * this function and _pp_item_index_label() one renderer rather than two, the `items[]`
 * depth below still exercises the key form on every ordinary list-shaped write, and the
 * band-level spelling is unit-pinned in tests/WriteRejectionLocatorTest.php so the
 * contract survives with evidence instead of as prose. Read the table as: rows 2-4 describe
 * what this function returns when asked, not what an operator can still be shown by a write.
 *
 * ALSO THE GATE. _pp_band_named_composition_error() (#642) has to recognise a message
 * that already spells its own band so it does not print the locator twice, and it used to
 * do that by re-spelling `sprintf('Item %d ', $index)` independently. Two spellings of one
 * label is how the divergence this issue closes got in; the gate now compares against THIS
 * function's output, so rewording the label cannot silently defeat the no-stutter branch.
 *
 * @param  int|string $index  A composition array key.
 * @param  array      $items  The composition it came from.
 * @return string             `Item <locator>`, ready to prefix a structural message.
 */
function _pp_band_index_label(int|string $index, array $items): string {
    return sprintf('Item %s', _pp_item_index_label($index, $items));
}

/**
 * Whether an `items[]` entry has the shape a field-bearing rule may read (#643).
 *
 * "Object" here is the JSON sense: a decoded JSON object is an associative array, and a
 * decoded JSON list is a PHP list. The empty array counts as an object because `{}` and
 * `[]` decode identically and the emptier reading is the one that lets the required-field
 * rule speak (an entry with nothing in it is a missing-fields problem, not a shape one).
 *
 * SHARED BY THE TWO RULES THAT ASK IT, which is the point. `item_type: "object"` uses it to
 * REJECT a populated list; the #643 unknown-field rule uses it to STAY SILENT on one,
 * because a list is a shape defect owned by that first rule and reporting `has no field
 * "0"` alongside it names positions instead of the defect (#621's misleading-repair-loop
 * class). Those two answers only stay consistent if they come from one definition — the
 * same reason _pp_schema_scalar_value_is_valid() and _pp_schema_enum_value_is_valid() are
 * shared across depths rather than copied.
 *
 * @param  mixed $entry  One entry of an items[] array, as stored or submitted.
 * @return bool          True for a JSON object (including the empty one), false otherwise.
 */
function _pp_entry_is_object_shape($entry): bool {
    return is_array($entry) && ($entry === [] || !pp_is_list($entry));
}

/**
 * What to say when a component declares NO style slots at all (#1007).
 *
 * "Available slots: (none)" was a dead end that read as "this component can no longer be
 * styled", which is false for the nine composable components it actually fires on — they are the
 * MOST styleable components in the theme, and the only reason they declare no slots is
 * that every designable value moved to the `udc` map.
 *
 * DERIVED, NOT LISTED. `pp_udc_is_v2_component()` already answers "is this component on
 * the new system", and the roles come from the same schema the refusal is about, so this
 * route cannot drift the way a hand-maintained list of 223 retired slot names would. That
 * is why the props needed a registry and the slots did not: every retired slot is
 * replaced by the same thing, and each retired prop by a different one.
 *
 * A component that genuinely has no styling surface at all keeps the old spelling.
 */
/**
 * Can this `refuse_props_when` clause be decided on the data in front of it (#1006)?
 *
 * The shared clause evaluator resolves every ambiguity to "met" because its original
 * consumer is a non-blocking advisory. A REFUSAL cannot inherit that: refusing on a shape
 * nobody can reason about produces a confident message about the wrong prop. This asks the
 * narrower question the refusal needs — is the subject a value this grammar compares? — so
 * an undecidable clause declines to fire and the rule that actually owns the malformed
 * value reports it instead.
 *
 * `present` needs no check: "is there a non-empty value here" is answerable for any shape.
 */
function _pp_refuse_clause_is_decidable($clause, array $props, array $prop_defs): bool {
    if (!is_array($clause) || pp_applies_when_clause_errors($clause, 'eval') !== []) {
        return false;
    }
    if (array_key_exists('present', $clause) || !array_key_exists('prop', $clause)) {
        return true;
    }
    $name  = $clause['prop'];
    $value = array_key_exists($name, $props)
        ? $props[$name]
        : ($prop_defs[$name]['default'] ?? null);

    return $value === null || is_scalar($value);
}

function _pp_no_style_slots_clause(string $component_name): string {
    if (!function_exists('pp_udc_is_v2_component') || !pp_udc_is_v2_component($component_name)) {
        return 'Available slots: (none)';
    }
    $roles = array_keys(pp_udc_component_roles($component_name));

    // CHROME IS NOT A BAND, and routing it to one would be the fabricated route this
    // function exists to replace. nav and footer declare roles, so pp_udc_is_v2_component()
    // is true for them and this clause fires — but they are rendered by the template on
    // every page and cannot be composed, so there is no band to put a `udc` map on and no
    // update_component that could reach them. Their map lives in the `pp_site_udc` site
    // option, exactly as `retired_option` already tells an author who reaches for the
    // retired chrome colour options.
    if (function_exists('pp_udc_is_chrome') && pp_udc_is_chrome($component_name)) {
        return sprintf(
            '"%s" is site chrome on the v2 styling system and declares no style slots: it is not a band, '
            . 'so it has no per-page styling at all. Its `udc` map lives in the "%s" site option, one '
            . 'entry per chrome component, written with update_site_option — the roles are %s.',
            $component_name,
            defined('PP_SITE_UDC_OPTION') ? PP_SITE_UDC_OPTION : 'pp_site_udc',
            implode(', ', $roles) ?: '(none declared)'
        );
    }

    // THE REPAIR SENTENCE IS EXACT, AND IT USED TO BE WRONG (#1101). It said "to clear a
    // stored slot, send it as null" — singular. An author who followed that on a band
    // carrying two stored slots was REFUSED, because this same validator walks the WHOLE
    // merged map and the sibling slot is still undeclared; and the refusal named the
    // sibling, so it read as a new problem rather than as "you have to send them all".
    // Measured: props-only edit REFUSED, one-of-two cleared REFUSED, both cleared in one
    // call ACCEPTED and the `style` key removed entirely. Say that.
    return sprintf(
        '"%s" is on the v2 styling system and declares no style slots: every designable value moved to '
        . 'the band\'s `udc` map. Style it there instead, on one of its roles (%s), through '
        . 'update_component\'s `udc` param (one band, merged by role) or update_composition / '
        . 'create_page (whole bands). If '
        . 'this band was written before the rebuild it still carries a stored `style` map, and that '
        . 'map refuses EVERY update_component edit to the band until it is gone — a props-only edit '
        . 'included. THE SIMPLEST REPAIR IS update_composition: rewrite the band with no `style` key '
        . 'at all and the whole map goes, with nothing to enumerate and nothing to miss. To do it '
        . 'with update_component instead, send `style` with EVERY STORED KEY set to null and `props` '
        . 'as `{}` if you are changing no props — every key, not every slot: a band styled by a v1 '
        . 'RECIPE also stores a `__recipe` key, which is not a slot name, and a clear that omits it '
        . 'unblocks the band but leaves that key stored. A partial clear of the slots is refused '
        . 'outright, naming whichever one you left behind.',
        $component_name,
        implode(', ', $roles) ?: '(none declared)'
    );
}

/**
 * Validates a style-slot override map against a component's declared style slots.
 *
 * The single shared gate for BOTH grid-level component style (`item['style']`) and
 * per-item card style (issue 306, `props.items[].style` — retired at #1101; the per-card surface is `props.items[].udc` now). Both surfaces run the
 * SAME injection guard + typed validators (_pp_validate_token_value) — there is
 * deliberately no second validator. Skips the `__recipe` tracking key (not a CSS
 * property). Returns the first violation so callers keep first-error-wins order.
 *
 * Per-item overrides carry a tighter slot scope (issue 323). Only slots consumed
 * on the .grid__item subtree render when set on one card; container/heading-scoped
 * slots (--grid-gap, --grid-heading-color, --grid-padding-*, ...) are read on the
 * section/list/header and silently no-op on a card — the reported-success-without-
 * effect class the new #306 surface would otherwise inherit. A slot opts into
 * per-item use by carrying `item_eligible` in its style_slots definition. When the
 * per-item path ($item_index !== null) validates a component that declares at least
 * one item_eligible slot, a real-but-ineligible slot is rejected with the same
 * `invalid_style_slot` code, naming the item and pointing at component-level style. The
 * gate is opt-in by presence: a component whose slots carry no item_eligible flag
 * keeps the pre-323 behavior (any declared slot accepted), so this shared engine
 * never over-rejects an un-annotated component.
 *
 * @param  array           $style            Slot => value overrides to validate.
 * @param  array           $available_slots  The component's declared style_slots.
 * @param  string          $component_name   Component name, for the error message.
 * @param  int|string|null $item_index       The items[] KEY when validating a per-item
 *                                            override (a list position or an object key,
 *                                            #634), or null for grid-level component style.
 * @param  array|null      $item_container   The array $item_index came from, so the locator
 *                                            can tell a list position from an object key
 *                                            (#652). Null means the caller has no view of
 *                                            it and the bare key is rendered. DEFAULTED
 *                                            ONLY BECAUSE $item_index above it is optional
 *                                            and PHP cannot require a parameter after an
 *                                            optional one — the default is a silent-drift
 *                                            hazard, not a convenience: a per-item call
 *                                            that omits it renders an object key as a
 *                                            position. The source tripwire in
 *                                            tests/DiagnosticReachTest.php fails any
 *                                            per-item call here that leaves it off.
 * @return WP_Error|null                     A WP_Error on the first bad slot/value, else null.
 */
function _pp_validate_style_slot_map(array $style, array $available_slots, string $component_name, int|string|null $item_index = null, ?array $item_container = null): ?WP_Error {
    // The key is rendered, never cast (#634), and read against its container so a list
    // position and an object key are distinguishable (#652): a string-keyed entry names
    // itself rather than collapsing to the non-existent item 0. Everything below still
    // keys off `!== null`, so a string key is an item exactly like an integer one and the
    // #323 per-item scope gate applies to it unchanged.
    $where = $item_index === null
        ? sprintf('Component "%s"', $component_name)
        : sprintf('Component "%s" item %s', $component_name, _pp_item_index_label($item_index, $item_container));

    // Card-scoped subset for per-item validation (issue 323). A slot opts into
    // per-item use via item_eligible in its style_slots definition. Enforce the
    // tighter scope only on the per-item path AND only when the component actually
    // declares a card-scoped set — otherwise fall back to the full slot set
    // (pre-323 behavior) so a component that gains a per-item style without being
    // annotated is not wholesale rejected by this shared validator. Strict
    // !== null so item index 0 (a falsy int, the featured first card) still enforces.
    // Shared with the RENDER boundary since #579 (pp_item_eligible_slots, lib/wp.php)
    // so "which slots may a single item carry?" has one answer on both paths.
    $item_eligible_slots = pp_item_eligible_slots($available_slots);
    $enforce_item_scope = $item_index !== null && !empty($item_eligible_slots);
    // At item level the operator may only draw from the card-scoped set, so the
    // "available" list in both the unknown-slot and section-scoped errors names the
    // eligible slots, not every declared slot.
    $effective_slots = $enforce_item_scope ? $item_eligible_slots : $available_slots;

    foreach ($style as $slot_name => $slot_value) {
        // Skip __recipe tracking key — not a CSS property. Intentionally allowed on
        // every surface (grid-level and per-item), same as issue 306.
        if ($slot_name === '__recipe') {
            continue;
        }
        if (!isset($available_slots[$slot_name])) {
            $available = implode(', ', array_keys($effective_slots));
            return new WP_Error(
                'invalid_style_slot',
                sprintf(
                    '%s has no style slot "%s". %s',
                    $where,
                    $slot_name,
                    $available !== ''
                        ? 'Available slots: ' . $available
                        : _pp_no_style_slots_clause($component_name)
                )
            );
        }
        // Card-scope check (issue 323): the slot exists but is section/heading-scoped,
        // so it renders nothing on a single card. Runs after the slot-exists check and
        // BEFORE value validation, so a wrong-scope slot reports the real problem
        // (scope) instead of masking it as invalid_style_value.
        if ($enforce_item_scope && !isset($item_eligible_slots[$slot_name])) {
            $available = implode(', ', array_keys($item_eligible_slots));
            return new WP_Error(
                'invalid_style_slot',
                sprintf(
                    '%s style slot "%s" is container-scoped and has no effect on a single item; set it on the component-level "style" instead. Item-scoped slots: %s',
                    $where,
                    $slot_name,
                    $available ?: '(none)'
                )
            );
        }
        // A slot value must be scalar before it can be cast (#622). Every sibling rule in
        // pp_validate_composition_errors() already guards this way; this engine cast
        // blind, so a stored `style: {"--hero-bg": {...}}` emitted an "Array to string
        // conversion" warning and a stored object was an UNCAUGHT Error. #622 routes this
        // engine into `wp pp check page` / `wp pp validate site`, which run over
        // compositions that never passed write validation — a warning injected into an
        // AJAX findings response, or a fatal that kills the command, both defeat the
        // report-don't-die contract (#144) those surfaces exist to honor.
        if (!is_scalar($slot_value)) {
            return new WP_Error(
                'invalid_style_value',
                sprintf(
                    '%s style slot "%s" must be a scalar value; got %s.',
                    $where,
                    $slot_name,
                    gettype($slot_value)
                )
            );
        }
        // Validate value using same injection guard + type validators as tokens.
        // An enum slot carries its bounded value set; pass it so membership is
        // enforced (rejecting anything outside the set) by the shared engine.
        $slot_type    = $available_slots[$slot_name]['type'] ?? null;
        $slot_allowed = $available_slots[$slot_name]['values'] ?? null;
        $validation   = _pp_validate_token_value((string) $slot_value, $slot_type, $slot_allowed);
        if (is_wp_error($validation)) {
            return new WP_Error(
                'invalid_style_value',
                sprintf(
                    '%s style slot "%s": %s',
                    $where,
                    $slot_name,
                    $validation->get_error_message()
                )
            );
        }
    }

    return null;
}

/**
 * The URI schemes accepted by a `format: "link_url"` prop (issue 507).
 *
 * Mirrors the render boundary: the link renderers run esc_url(), which empties
 * any URL whose scheme is not in wp_allowed_protocols(). Using that same list
 * here keeps the write-time accept/reject decision aligned with what esc_url
 * will do at render, so an accepted write renders as authored and a rejected one
 * is exactly the "esc_url would neuter this to a dead button" class.
 *
 * FAIL-CLOSED: if wp_allowed_protocols() is unavailable (e.g. a non-WP unit
 * context) or returns an empty set, fall back to WordPress's own default
 * protocol list rather than an empty set — an empty allow-list would accept
 * NOTHING with a scheme, but more importantly this guarantees the check never
 * silently degrades to "accept every scheme". Callers that want the http/https/
 * mailto/tel/relative/anchor bar the issue names get it from this default.
 *
 * @return string[] Lower-case allowed scheme names.
 */
function pp_link_url_allowed_protocols(): array {
    if (function_exists('wp_allowed_protocols')) {
        $protocols = wp_allowed_protocols();
        if (is_array($protocols) && $protocols !== []) {
            return array_map('strtolower', $protocols);
        }
    }
    // WordPress core defaults (wp-includes/functions.php kses_init / wp_allowed_protocols).
    return [
        'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs',
        'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'svn', 'tel', 'fax',
        'xmpp', 'webcal', 'urn', 'sms',
    ];
}

/**
 * True when $value satisfies a declared scalar schema `type` (issue 507, shared at
 * both depths by issue #614).
 *
 * ONE definition of what `string` and `number` mean at the write path, called from
 * the two places that need it — the #507 generic TOP-LEVEL prop pass and the #579
 * A-27 NESTED items[] field pass. It was inline in the first when the second did not
 * exist; #614 needed the same answer one level down, and a second copy is exactly how
 * two depths start disagreeing about whether "42" is a number.
 *
 *     type      unset sentinel (always valid)   accepted            rejected
 *     ────────  ──────────────────────────────  ──────────────────  ─────────────────
 *     string    null                            is_string()         everything else
 *     number    null, ''                        is_numeric()        everything else
 *     (other)   —                               everything          —
 *
 * `string` MEANS `is_string()` (#707). It used to mean "any scalar", on the reasoning
 * that an int/float/bool coerces to text and renders as authored while an array/object
 * renders as "Array" — so the rule drew its line at non-container. Measured behavior
 * refuted the premise: `create_page` with `image_url: 42` returned `ok:true`, stored
 * `42` RAW as an integer, reported NO finding, and painted `<img src="42">`; a stored
 * `section.panel_cta_url: false` rendered a button with an empty href. The declared
 * type said `string` and the write path enforced something looser, so the stored shape
 * never had to match the declaration. The D-A ruling (canonical text in #724's body)
 * is REJECT, NEVER COERCE: the write path refuses what the declaration does not
 * describe, with the standard `invalid_prop_value` envelope the array rejection
 * already used, and nothing is normalized at write or migrated in storage.
 *
 * `null` STAYS ACCEPTED and is not a hole in that rule: `is_scalar(null)` is false, so
 * null never travelled the scalar arm in the first place. It is the unset sentinel
 * below, it renders exactly as an omitted prop does, and rejecting it would be a
 * different narrowing than the one that was ruled on. The empty string is likewise
 * untouched — `is_string('')` is true, and it was always a valid string value.
 *
 * WHAT THIS DOES NOT DO: the render-side `is_scalar()` guards in `components/*.php`
 * (#641/#705/#706/#708/#730/#739) STAY exactly as they are. They cover what this
 * cannot — compositions authored before the type rules existed, `restore_composition`
 * (which reports and never blocks, #233), and raw `_pp_composition` meta writes — so
 * a stored non-string scalar still renders, coerced, instead of fataling a public page.
 * This closes the front door those guards were built to survive; it does not replace
 * them, and tightening them to `is_string()` would silently drop values production
 * coercion still resolves.
 *
 * `number` is DELIBERATELY UNCHANGED and still accepts a numeric STRING, because a
 * JSON/CLI write sends "3" and the #379 bounds family already accepts that shape for
 * grid.columns. The two arms are asymmetric on purpose: `string` had no reason to
 * accept a foreign shape, `number` has one.
 *
 * The unset sentinels are what keep the rule from over-rejecting: an omitted value
 * must preserve the prop's declared default, and every action validates the WHOLE
 * composition, so a rule that rejected a blank would block edits to unrelated bands
 * on the same page. Returns true for any other declared type (enum, array, object) —
 * those are owned by their own families, so callers can hand it every field.
 *
 * @param string|null $declared_type The schema `type` value, or null when undeclared.
 * @param mixed       $value         Raw authored value.
 */
function _pp_schema_scalar_value_is_valid($declared_type, $value): bool {
    if ($declared_type === 'string') {
        return $value === null || is_string($value);
    }
    if ($declared_type === 'number') {
        return $value === null || $value === '' || is_numeric($value);
    }
    return true;
}

/**
 * True when $value satisfies a declared CONTAINER schema `type` — `array` or
 * `object` (issue #744).
 *
 * The third sibling of _pp_schema_scalar_value_is_valid() and
 * _pp_schema_enum_value_is_valid(), and it exists for the same reason they do:
 * ONE definition of what a declared type accepts, called from the two depths that
 * need it — the #507 generic TOP-LEVEL prop pass and the #579 A-27 NESTED items[]
 * field pass — so the two cannot drift apart about whether `bullets: "text"` is a
 * list. The rule was INLINE in the top-level pass while the nested depth had no
 * container rule at all, and that is exactly the drift this closes: a nested field
 * declaring `type: "array"` and handed a SCALAR was accepted by every one of the
 * five nested rules (RULE 2 walks a bullets array's ENTRIES and never enters on a
 * scalar; RULE 3's fence was scalar types only), so `grid.items[].bullets: "Fast,
 * cheap"` returned ok:true, persisted RAW, and rendered NOTHING —
 * components/grid/grid.php reads it through `is_array(...) ? ... : []`. The author
 * asked for a checklist, was told it worked, and got a card with no checklist: the
 * reported-success-without-effect class, same as #614 and #707 one type over.
 *
 *     type      unset sentinel (always valid)   accepted            rejected
 *     ────────  ──────────────────────────────  ──────────────────  ─────────────────
 *     array     null, ''                        is_array()          every other scalar
 *     object    null, ''                        is_array()          every other scalar
 *     (other)   —                               everything          —
 *
 * `[]` needs no sentinel row: it IS an array, so it is accepted by the test itself.
 * The top-level rule this was extracted from spelled `$value !== []` out anyway; the
 * clause was redundant with `!is_array($value)` and is not carried forward.
 *
 * THE TWO TYPES SHARE ONE ANSWER because PHP has one shape for both: a JSON list and
 * a JSON object both decode to a PHP array under `json_decode($json, true)`. So what
 * this predicate actually decides is "container or scalar?", and the error text
 * ("must be an array" / "must be an object") is NARROWER than what is enforced by
 * exactly one thing: a JSON LIST handed to an `object` field still passes here. That
 * is deliberate, not an oversight. This rule applies the D-A reject-never-coerce
 * ruling (canonical text in #724's body) to the declared CONTAINER types — a scalar
 * is not a container — and it decides nothing about what a container may HOLD.
 * Map-vs-list WAS nobody's rule when this predicate landed; #738 closed half of it and
 * #883 closed the other. A declared `array` must be a JSON list, owned by
 * _pp_schema_list_value_is_valid() just below; a declared `object` must be a map, owned
 * by _pp_schema_object_value_is_valid() below that. Both run as a SECOND STAGE after this
 * one, so a scalar keeps this predicate's message and a wrong-shaped container gets
 * theirs. What is STILL nobody's rule is the CONTENTS of an item `style` object, the same
 * "no decision exists" the RULE 3 comment records; the entry-shape question one level up
 * is owned by `item_type: "object"` (_pp_entry_is_object_shape), which is a different rule
 * with a different message.
 *
 * WHAT THIS PREDICATE STILL DOES NOT DECIDE is unchanged by either narrowing: it answers
 * "container or scalar?" and hands the shape question to the two stages after it. Read the
 * error text ("must be an array" / "must be an object") as the SCALAR refusal it is; the
 * shape refusals have their own sentences.
 *
 * HISTORICAL NOTE, kept because it explains why the `object` leg went unowned so long:
 * both shipped `object` declarations are per-item style maps, and before #883 a LIST
 * reaching one was refused a few rules later by the shared style-slot engine, which reads
 * its keys as slot names — `style: ["#fff"]` returned `invalid_style_slot`, `item 0 has no
 * style slot "0". Available slots: ...`. The authoring contract an operator reads ("a
 * per-item style takes a JSON object") therefore held end to end, but by an accident of
 * who those two consumers are rather than by a rule, which is exactly the argument #883
 * used to close it. That engine still owns slot NAMES; it no longer stands in for a shape
 * rule.
 *
 * The unset sentinels are the same two the top-level array rule already used, kept
 * BECAUSE they are the same: `create_page` and `update_composition` validate the WHOLE
 * composition, so a rule that rejected a blank would block edits to unrelated bands on
 * the same page (since #1007 `update_component` no longer would, but the other two still
 * do and that is enough for this rule to matter), and a
 * nested sentinel that disagreed with the top-level one would re-open the drift this
 * predicate closes. They are also an accepted limitation, stated plainly: `bullets:
 * ""` is still accepted and still renders nothing. It is the shape that keeps an
 * omitted value on the prop's declared default, it is what `null` means everywhere
 * else in this family, and narrowing it is a different ruling than #744's.
 *
 * WHAT THIS DOES NOT DO: nothing is coerced (a scalar is never wrapped in an array)
 * and nothing stored is migrated. The render-side `is_array()` guards in
 * `components/*.php` stay exactly as they are — they cover what a write gate cannot
 * reach (pre-rule compositions, `restore_composition`, raw `_pp_composition` meta
 * writes), and a stored scalar keeps rendering as an empty list rather than fataling
 * a public page. This closes the front door; it does not repair what is already
 * inside (#805 records the editor's read-side handling of exactly that).
 *
 * Returns true for every other declared type — the same not-applicable contract every
 * sibling predicate carries, so a caller may hand it any declaration it walks. Be
 * precise about what that buys today: all three call sites DO pre-classify (the two
 * top-level arms sit inside the `elseif ($declared_type === ...)` chain, RULE 6 gates
 * on `$field_type === 'array' || 'object'`), so the fall-through is not load-bearing
 * in production. It keeps the predicate total for direct tests, and it means a schema
 * that one day declares a type outside the closed six-name set no-ops here instead of
 * throwing — which is the behaviour a not-applicable answer should have.
 *
 * @param string|null $declared_type The schema `type` value, or null when undeclared.
 * @param mixed       $value         Raw authored value.
 */
function _pp_schema_container_value_is_valid($declared_type, $value): bool {
    if ($declared_type === 'array' || $declared_type === 'object') {
        return $value === null || $value === '' || is_array($value);
    }
    return true;
}

/**
 * True when $value satisfies the LIST half of a declared `type: "array"` (issue #738).
 *
 * The fourth sibling of _pp_schema_scalar_value_is_valid(),
 * _pp_schema_enum_value_is_valid() and _pp_schema_container_value_is_valid(), called
 * from the same two depths for the same reason they are: ONE definition, so the
 * top-level prop pass and the nested items[] field pass cannot drift about whether
 * `items: {"first": {...}}` is a list.
 *
 * WHAT IT FINISHES. The container predicate above decides "container or scalar?" and
 * says so in its own docblock: a JSON OBJECT handed to a field declaring `array`
 * passes it, because a JSON list and a JSON object both decode to a PHP array under
 * `json_decode($json, true)`. That was the whole gap. Measured on main before this
 * rule, through the real authoring surface:
 *
 *     write                                                     verdict   stored   rendered
 *     ────────────────────────────────────────────────────────  ────────  ───────  ──────────
 *     grid.items: {"first": {"title": "C1"}, "second": {...}}    ok:true   raw      500
 *     grid.items: [{"title": "C1"}, {...}]                       ok:true   raw      the band
 *
 * The 500 is components/grid/grid.php's card loop: `$item['number'] ?? (string)
 * ($index + 1)` on a string key raises "Unsupported operand types: string + int", and
 * templates/composition.php calls pp_get_component() with no try/catch, so the whole
 * PUBLIC page dies. `??` short-circuits, so a map whose every entry carries `number`
 * renders fine — the page 500s the moment an author deletes one field. That is the
 * v1.16.0 invariant inverted: the write path was manufacturing exactly the stored
 * state the read path calls corrupt (pp_get_composition_result() classifies a decoded
 * non-list as `unexpected_shape`), through the ordinary authoring path.
 *
 *     type      accepted                              rejected
 *     ────────  ────────────────────────────────────  ────────────────────────
 *     array     any NON-array, or pp_is_list()        a non-list array (a map)
 *     object    everything                            —
 *     (other)   everything                            —
 *
 * THERE IS NO SENTINEL COLUMN, deliberately, and the difference from the sibling table
 * one function up is the whole staging contract. That predicate spells `$value === null
 * || $value === ''` in its body, so null and '' are ITS sentinels. This one accepts every
 * non-array — `false`, `42`, `"text"` included — because a scalar is not its business at
 * all. Writing "unset sentinel: null, ''" here would describe a narrower function than
 * the one below and be exactly the contract a direct unit test got written against.
 * `[]` needs no row either: the empty array IS a list, so pp_is_list() accepts it.
 *
 * THE `object` LEG IS NOT THIS PREDICATE'S, and never became it. Refusing a LIST where an
 * `object` is declared was a different ruling on a different shape, so #883 gave it a
 * different function: _pp_schema_object_value_is_valid(), the next one down. Keeping them
 * apart is what lets each stay a one-question predicate — this one asks "is this container
 * a list?", that one asks "is this container a map?" — and it is why this one's `object`
 * row still reads "everything". Do not fold them; a single predicate switching on the
 * declared type is how one message ends up answering for two rules.
 *
 * REJECT, NEVER COERCE (ruling D-A, canonical text in #724's body). No array_values(),
 * no reindexing, no stored-data migration — the same posture #724 applied to the
 * COMPOSITION container one level up, applied here to a declared list one level down.
 * The author's cards are not lost by this refusal; they were lost by the ACCEPTANCE,
 * which stored a shape the renderer cannot walk.
 *
 * AND IT DOES NOT REPLACE THE RENDER GUARD. This closes the front door. What is already
 * stored — pre-rule compositions, `restore_composition` (which reports and never blocks,
 * #233), raw `_pp_composition` meta writes — reaches the renderer regardless, which is
 * why components/grid/grid.php now derives the step ordinal from a positional counter
 * instead of the array key. Two mechanisms, because neither covers the other.
 *
 * THE LIMIT, STATED SO IT IS NOT DISCOVERED LATER, and it is the same one #652 and #724
 * recorded: `json_decode('{"0":"a","1":"b"}', true)` returns a PHP LIST. The keys ARE
 * 0..n-1 in order, so pp_is_list() says list and this accepts it. That case is also the
 * harmless one — key and position agree, so the renderer's arithmetic is correct — and
 * it is not fixable at THIS layer rather than unfixable in principle: separating it
 * would mean inspecting raw JSON TEXT, and every caller reaches pp_execute_action() with
 * an already-decoded PHP array. `{}` decodes identically to `[]` for the same reason, so
 * the empty container is accepted as the empty list it is indistinguishable from.
 *
 * SECOND STAGE, NEVER A STANDALONE `array` VALIDATOR — read this before adding a third
 * call site. It answers ONE question: "given that this IS a container, is it a list?"
 * A non-array returns TRUE here, because rejecting a scalar is
 * _pp_schema_container_value_is_valid()'s job and reporting it twice would give an
 * operator two messages for one defect. So the pair is ordered, not interchangeable:
 *
 *     container predicate FIRST   -> "must be an array; got string"   (a scalar)
 *     this predicate SECOND       -> "must be a list, ... JSON object" (a map)
 *
 * Both shipped call sites run them in exactly that order, which is also what keeps the
 * scalar message byte-identical to every version since #507. A future caller that reached
 * for this one ALONE to enforce `type: "array"` would silently accept every scalar — the
 * #744 defect reintroduced under a newer number. If that ever needs to be one call, merge
 * the two predicates and their two messages deliberately; do not call this one on its own.
 * Pinned by testAScalarIsStillTheContainerRulesToRefuse(), which asserts both halves of
 * the ordering rather than only this one's answer.
 *
 * Returns true for every other declared type — the same not-applicable contract every
 * sibling in this family carries, so a caller may hand it any declaration it walks.
 *
 * @param string|null $declared_type The schema `type` value, or null when undeclared.
 * @param mixed       $value         Raw authored value.
 */
function _pp_schema_list_value_is_valid($declared_type, $value): bool {
    if ($declared_type === 'array') {
        return !is_array($value) || pp_is_list($value);
    }
    return true;
}

/**
 * True when $value satisfies the MAP half of a declared `type: "object"` (issue #883).
 *
 * The mirror of _pp_schema_list_value_is_valid() directly above, and the fifth sibling of
 * the schema-predicate family. It runs as a SECOND STAGE after
 * _pp_schema_container_value_is_valid() for the same reason the list predicate does, so a
 * scalar keeps that predicate's "must be an object; got string" and a LIST gets this one's.
 *
 * WHAT IT FINISHES. The container predicate decides "container or scalar?" and nothing
 * more, because a JSON list and a JSON object both decode to a PHP array under
 * `json_decode($json, true)`. #738 closed the `array` half of that gap; this closes the
 * `object` half, which the two docblocks above it recorded as open until this rule landed
 * (they now record it as closed; the open-gap record lives in #883's body):
 *
 *     _pp_schema_container_value_is_valid('object', ['#fff']);   // true — a container
 *     _pp_schema_object_value_is_valid('object', ['#fff']);      // false — but a LIST
 *
 *     type      accepted                                 rejected
 *     ────────  ───────────────────────────────────────  ────────────────────────
 *     object    any NON-array, [], or a non-list array   a populated list
 *     array     everything                               —
 *     (other)   everything                               —
 *
 * THE EMPTY CONTAINER IS ACCEPTED, and it is not a sentinel row — it is the one shape the
 * two JSON spellings genuinely share. `json_decode('{}', true)` and `json_decode('[]',
 * true)` both return `[]`, so `{}` is indistinguishable from `[]` at this layer and
 * refusing the empty list would refuse the empty object with it. `null` and `''` need no
 * row for the reason the list predicate's table gives: they are not arrays, so the
 * `!is_array($value)` arm accepts them, and rejecting a blank is the container predicate's
 * sentinel decision, not this one's.
 *
 * WHY IT WAS WORTH BUILDING WHEN NOTHING WAS BROKEN, since the honest answer is "nothing
 * is broken today". Both shipped `object` declarations are per-item style maps
 * (grid.items[].style, section.panel_items[].style), and a list reaching either is refused
 * a few rules later by the shared style-slot engine, which reads its keys as slot names
 * (`item 0 has no style slot "0"`). So the authoring contract held end to end — by an
 * accident of who the two consumers are, not by a rule. A future `type: "object"` field
 * routed anywhere else (a metadata bag, an options object) would have accepted a JSON
 * list, persisted it behind ok:true, and rendered whatever its consumer does with a list:
 * the reported-success-without-effect class #614/#707/#744 each closed one type over. This
 * is the same argument #744 used to build its own top-level `object` arm ahead of any
 * shipped declaration — the cheap moment to close a fence is before something lands on it.
 *
 * REJECT, NEVER COERCE (ruling D-A, canonical text in #724's body; ruling T4 for this
 * issue). No array_combine(), no wrapping a list in a synthetic key, no stored-data
 * migration. The refusal names the band and the field and lets the author resend.
 *
 * AND IT DOES NOT REPLACE THE RENDER GUARD, the same two-mechanism split #738 records.
 * What is already stored reaches the renderer regardless (pre-rule compositions,
 * `restore_composition` — which reports and never blocks, #233 — and raw `_pp_composition`
 * meta writes). A stored list-where-object DEGRADES rather than fatals and needs no new
 * guard: pp_render_style_vars() walks the map and hands each key to
 * pp_style_declaration_renders(), whose first act is `isset($slots[$name])`. A list's keys
 * are integers, no component declares a slot named "0", so every declaration is dropped
 * before the value is ever cast — the card renders unstyled instead of taking the page
 * down. Pinned by ObjectShapedPropWriteEnforcementTest §5 so it stays true.
 *
 * THE LIMIT, STATED SO IT IS NOT DISCOVERED LATER, and it is #738's limit seen from the
 * other side: `json_decode('{"0":"a","1":"b"}', true)` returns a PHP LIST. The keys ARE
 * 0..n-1 in order, so pp_is_list() says list and this REFUSES it, even though the author
 * wrote an object. Separating the two would mean inspecting raw JSON TEXT, and every caller
 * reaches pp_execute_action() with an already-decoded PHP array. It costs nothing on the
 * shipped fields — a style map's keys were slot names like `--grid-item-bg` (no schema declares one since #1101), never `0` — and
 * a field that genuinely wants numeric string keys should start them at something other
 * than 0 or declare `array`.
 *
 * SECOND STAGE, NEVER A STANDALONE `object` VALIDATOR — the same warning its sibling
 * carries, for the same reason. It answers ONE question: "given that this IS a container,
 * is it a map?" A non-array returns TRUE, because rejecting a scalar is
 * _pp_schema_container_value_is_valid()'s job and reporting it twice would give an operator
 * two messages for one defect. Both call sites run the pair in that order:
 *
 *     container predicate FIRST   -> "must be an object; got string"    (a scalar)
 *     this predicate SECOND       -> "must be an object, but ... list"  (a list)
 *
 * A future caller reaching for this one ALONE would silently accept every scalar — the #744
 * defect reintroduced under a newer number. Pinned by
 * testAScalarIsStillTheContainerRulesToRefuse(), which asserts both halves of the ordering.
 *
 * Returns true for every other declared type — the same not-applicable contract every
 * sibling in this family carries, so a caller may hand it any declaration it walks.
 *
 * @param string|null $declared_type The schema `type` value, or null when undeclared.
 * @param mixed       $value         Raw authored value.
 */
function _pp_schema_object_value_is_valid($declared_type, $value): bool {
    if ($declared_type === 'object') {
        return !is_array($value) || $value === [] || !pp_is_list($value);
    }
    return true;
}

/**
 * Renders "this one is a JSON object (N entries)." — the one clause every non-list
 * refusal in this file shares (#724 at the composition container, #738 at a declared
 * `array` prop and item field).
 *
 * ONE SPELLING, THREE CALL SITES, for the reason _pp_item_index_label() is one renderer
 * for two locator depths: three literal copies of a sentence and its pluralizer is how
 * three surfaces start describing one shape three ways, which is the drift #650/#652
 * spent a whole iteration undoing. What each caller keeps is only the part that genuinely
 * differs — its own subject ("The composition must be a list of components", `prop "x"
 * must be a list`) and its own guidance sentence, because a composition and a prop take
 * different example payloads.
 *
 * REFLECTS THE ENTRY COUNT AND NOTHING ELSE. No caller-supplied KEY text reaches an
 * operator terminal through this clause, so the #633/#647/#649 reflected-value bounding
 * question does not arise for it at all — exactly the choice #724 made when it wrote the
 * sentence this extracts. An operator who needs the offending keys reads them from the
 * payload they just sent, or from the per-entry findings, which carry bounded locators of
 * their own.
 *
 * @param  array $value The non-list array being refused.
 * @return string       `this one is a JSON object (N entries).`, ready to append.
 */
function _pp_json_object_shape_clause(array $value): string {
    return 'this one is a JSON object ' . _pp_json_entry_count_clause($value) . '.';
}

/**
 * Renders `(N entries)` — the entry-count tail both shape clauses end with.
 *
 * ONE HOME FOR THE PLURALIZER (#883). The two clause renderers either side of this are
 * deliberately separate functions, because each owns a SENTENCE and two depths must not
 * describe one shape two ways. That argument covers the nouns; it does not cover the
 * count-and-pluralize tail, which was written twice the moment the second clause landed
 * and could then drift on its own — one saying `1 entry` and the other `1 entries` is
 * exactly the kind of split this family spends its docblocks preventing.
 *
 * @param  array $value The container being described.
 * @return string       `(N entries)` / `(1 entry)`, with no trailing punctuation.
 */
function _pp_json_entry_count_clause(array $value): string {
    return sprintf('(%d %s)', count($value), count($value) === 1 ? 'entry' : 'entries');
}

/**
 * Renders the "this is an object, not a list" half of a #738 refusal message.
 *
 * Shared by the two depths that emit it so the top-level prop message and the nested
 * item-field message cannot drift into two vocabularies — the same reason
 * _pp_item_index_label() is one renderer for two locator depths. The clause itself is
 * shared one level wider still, with #724's composition-level refusal.
 *
 * @param  array $value The non-list array being refused.
 * @return string       The trailing sentence pair, ready to append to a locator.
 */
function _pp_schema_list_shape_message(array $value): string {
    return 'must be a list, but ' . _pp_json_object_shape_clause($value)
        . ' Send it as an array ([...]), not an object with keys.';
}

/**
 * Renders "this one is a JSON list (N entries)." — the mirror of
 * _pp_json_object_shape_clause() above, for the #883 refusals.
 *
 * ONE SPELLING FOR BOTH DEPTHS, the same reason its sibling is one renderer for three call
 * sites: two literal copies of a sentence and its pluralizer is how a top-level prop
 * message and a nested item-field message start describing one shape two ways.
 *
 * REFLECTS THE ENTRY COUNT AND NOTHING ELSE, exactly as the object clause does. No
 * caller-supplied VALUE reaches an operator terminal through it, so the #633/#647/#649
 * reflected-value bounding question does not arise for it either. An operator who needs the
 * offending entries reads them from the payload they just sent.
 *
 * @param  array $value The list being refused.
 * @return string       `this one is a JSON list (N entries).`, ready to append.
 */
function _pp_json_list_shape_clause(array $value): string {
    return 'this one is a JSON list ' . _pp_json_entry_count_clause($value) . '.';
}

/**
 * Renders the "this is a list, not an object" half of a #883 refusal message — the mirror
 * of _pp_schema_list_shape_message() above.
 *
 * Shared by the two depths that emit it so the top-level prop message and the nested
 * item-field message cannot drift into two vocabularies, the same contract every renderer
 * in this family carries. The guidance sentence names both JSON spellings for the same
 * reason #738's does: an author who sent the wrong bracket needs to be told which bracket,
 * not merely that the shape was wrong.
 *
 * @param  array $value The list being refused.
 * @return string       The trailing sentence pair, ready to append to a locator.
 */
function _pp_schema_object_shape_message(array $value): string {
    return 'must be an object, but ' . _pp_json_list_shape_clause($value)
        . ' Send it as an object with keys ({...}), not an array ([...]).';
}

/**
 * True when $value satisfies a declared STRICT enum definition (issues 380/#579 at
 * the top level, extended to nested items[] fields by #600).
 *
 * ONE definition of enum membership at the write path, called from the two places
 * that need it — the top-level strict-enum pass and the #579 A-27 nested items[]
 * field pass. Same reason _pp_schema_scalar_value_is_valid() exists one rule over:
 * the membership test was inline in the first when the second did not exist, and a
 * second copy is exactly how two depths start disagreeing about whether a trailing
 * space is part of a value.
 *
 *     definition                          verdict
 *     ──────────────────────────────────  ─────────────────────────────────────────
 *     not an enum / no `strict` /         always valid — not this rule's business
 *     no usable `values` list
 *     strict enum, value null or ''       always valid — the unset sentinel
 *     strict enum, any other value        valid iff it is in `values`, compared ===
 *
 * NOT-APPLICABLE RETURNS TRUE, deliberately, so a caller can hand it every prop or
 * field definition it walks without pre-classifying — the same contract the scalar
 * predicate carries for enum/array/object types. `strict` is what arms the rule: an
 * enum that does not declare it is unenforced, and the CI tripwire
 * (SchemaValidationTest::testEveryEnumDeclarationDeclaresStrict) is what keeps that
 * from being a place a new enum can hide, at BOTH depths since #600.
 *
 * The unset sentinel matches the top-level rule it was extracted from, and matters
 * for the same reason it does there: `create_page` and `update_composition` validate the
 * WHOLE composition, so a rule that rejected a blank would block edits to unrelated bands
 * on the page. (`update_component` narrowed to its targeted band at #1007; the other two
 * did not, so the reason holds.)
 *
 * The membership test is `values` and nothing else (#606) — there is no accepted-
 * but-unadvertised tier at either depth, so the error names exactly what the gate
 * accepts. Callers keep their own array_key_exists() check: an ABSENT key is not
 * this predicate's business, and isset() would wrongly swallow the null sentinel.
 *
 * POST-CONDITION BOTH CALLERS DEPEND ON: a `false` return guarantees `values` is a
 * non-empty array, so the error paths implode() it without re-checking. That guard
 * used to sit three lines above the implode() it protects; the extraction moved it
 * here, so a future relaxation (returning false for a malformed `values` so the
 * caller can report it) would turn both rejection paths — the paths least exercised
 * before release — into a TypeError. Widen the callers first if that ever changes.
 *
 * @param mixed $definition The schema prop/field definition, or anything at all.
 * @param mixed $value      Raw authored value.
 */
function _pp_schema_enum_value_is_valid($definition, $value): bool {
    if (!is_array($definition)
        || ($definition['type'] ?? null) !== 'enum'
        || empty($definition['strict'])
        || empty($definition['values'])
        || !is_array($definition['values'])
    ) {
        return true;
    }
    if ($value === null || $value === '') {
        return true; // unset sentinel — keeps the declared default behavior
    }
    return in_array($value, $definition['values'], true);
}

/**
 * Cap for an author-supplied value echoed back inside a validation message. Matches
 * the shown-length bound _pp_link_url_error_message() applies to a rejected URL, so
 * one rejected value cannot bloat an error envelope or a log line.
 */
const PP_REFLECTED_VALUE_MAX_LENGTH = 100;

/**
 * Renders a raw authored value for an error message without casting a container to
 * string (which warns). Scalars are quoted so a stray space or empty string is
 * visible; anything else degrades to its type name.
 *
 * Booleans render as `true`/`false`, NOT as PHP's string cast. `(string) true` is
 * `"1"` and `(string) false` is `""`, so an agent told `must be a number; got "1"`
 * would be told its rejected value looks like a number — the one thing the message
 * exists to deny. This is the only place the two disagree, and the message is the
 * agent's whole repair signal.
 *
 * The value is AUTHOR-SUPPLIED and reaches a terminal (WP_CLI::error writes the
 * validator message raw), an action envelope, and the editor's save response, so it
 * gets the same treatment the file's other reflection helpers give reflected input:
 * control and format characters stripped, length bounded.
 *
 * SAME STRIP as _pp_render_undeclared_prop_keys(), NOT the same bound or the same
 * degenerate spelling, and the difference is worth naming because since #647 the two
 * helpers meet inside a single message. Both strip exactly \p{Cc}\p{Cf}, so "which
 * characters may be echoed back" has one definition. They differ where they should:
 * this one caps at PP_REFLECTED_VALUE_MAX_LENGTH (100), supplies its own quotes and
 * REPAIRS invalid UTF-8; the key renderer caps at PP_UNDECLARED_KEY_MAX_LENGTH (64),
 * emits bare, and degrades an undecodable key to `(unprintable key)`. A value is
 * arbitrary content, a key is an identifier — one rule about characters, two budgets.
 *
 * @param mixed $value Raw authored value.
 */
function _pp_schema_value_for_message($value): string {
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (!is_scalar($value)) {
        return gettype($value);
    }
    $text = (string) $value;
    // Bound the INPUT before scanning it: 4 bytes is the widest UTF-8 encoding of
    // one character, so this always leaves at least the cap's worth of characters.
    if (strlen($text) > PP_REFLECTED_VALUE_MAX_LENGTH * 4) {
        $text = substr($text, 0, PP_REFLECTED_VALUE_MAX_LENGTH * 4);
    }
    $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $text);
    if ($clean === null) {
        // The /u pattern returns null on invalid UTF-8 — which the byte-wise cut
        // above can itself produce by landing mid-sequence. Repair and re-run the
        // SAME pattern rather than falling back to a weaker one.
        $repaired = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $clean    = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $repaired);
        if ($clean === null) {
            return '(unprintable value)';
        }
    }
    if (mb_strlen($clean) > PP_REFLECTED_VALUE_MAX_LENGTH) {
        $clean = mb_substr($clean, 0, PP_REFLECTED_VALUE_MAX_LENGTH) . '...';
    }
    return '"' . $clean . '"';
}

/**
 * True when a `format: "link_url"` value would render as authored (issue 507).
 *
 * The accept bar is "what survives esc_url()": anything without a scheme (an
 * #anchor, a /site-relative path, a //protocol-relative URL, a bare relative
 * path, a ?query) renders as authored, and a scheme-bearing value is accepted
 * only when its scheme is in pp_link_url_allowed_protocols(). A scheme OUTSIDE
 * that set (javascript:, data:, vbscript:, file:, ...) is exactly what esc_url()
 * empties into a dead button, so it is rejected.
 *
 * Non-string values return true here: a link prop is declared type:string, so a
 * non-scalar is already rejected by the generic type pass — this helper must not
 * double-report it (and casting an array to string would warn).
 *
 * SCOPE: this matches esc_url()'s PROTOCOL decision, not its full character
 * cleanup. esc_url() remains the render-time boundary that neutralises obfuscated
 * or malformed URLs (embedded control characters, entity tricks); this write-time
 * check catches the honest dead-button class the issue targets. Leading
 * whitespace/control characters are stripped before the scheme test so a value
 * like " javascript:..." cannot slip through as "no scheme".
 *
 * @param mixed $value Raw prop value.
 */
function _pp_link_url_is_valid($value): bool {
    if (!is_string($value)) {
        return true;
    }
    // Strip ALL control characters (not just leading) before the scheme test.
    // A control character embedded inside an otherwise-recognisable scheme —
    // "java\tscript:", "java\nscript:" — is a classic esc_url-neutered obfuscation:
    // the browser ignores the control char and honours the protocol, esc_url empties
    // it, so it is a dead button. Removing control chars first means the scheme test
    // sees the real protocol and rejects it, instead of mistaking it for a
    // scheme-less relative path. Then trim leading whitespace for the empty check.
    $trimmed = ltrim(preg_replace('/[\x00-\x1f\x7f]+/', '', $value));
    if ($trimmed === null || $trimmed === '') {
        return true; // unset / empty — the button simply does not render
    }
    // A scheme is letter, then letters/digits/+/-/. up to the first colon.
    if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $trimmed, $m)) {
        return in_array(strtolower($m[1]), pp_link_url_allowed_protocols(), true);
    }
    // No scheme: #anchor, /relative, //protocol-relative, ?query, bare path — all
    // survive esc_url() and render as authored.
    return true;
}

/**
 * Builds the rejection message for an invalid `format: "link_url"` value (#507).
 *
 * @param string          $component  Component name.
 * @param string          $prop_name  Top-level prop (e.g. "button_url" or the array prop "items").
 * @param int|string|null $item_index The items[] KEY when the prop is a nested items[] link
 *                                     (a list position or an object key, #634), else null.
 * @param string|null     $field      Item field name (e.g. "link_url") when nested, else null.
 * @param mixed           $value      The rejected value.
 * @param array|null      $item_container  The array $item_index came from, so the locator can
 *                                          tell a list position from an object key (#652).
 *                                          REQUIRED, not defaulted: a default would let a new
 *                                          nested call site omit it, compile, pass every
 *                                          list-shaped test and silently render an object key
 *                                          as a position again. Pass null only on the
 *                                          top-level arm, where there is no items[] key at all.
 */
function _pp_link_url_error_message(string $component, string $prop_name, int|string|null $item_index, ?string $field, $value, ?array $item_container): string {
    // Rendered, never cast (#634), and read against its container (#652) — see
    // _pp_item_index_label().
    $where = ($item_index === null)
        ? sprintf('prop "%s"', $prop_name)
        : sprintf('prop "%s" item %s field "%s"', $prop_name, _pp_item_index_label($item_index, $item_container), (string) $field);
    // The value is always a string here (_pp_link_url_is_valid returns false only
    // for strings), but cast defensively. Strip control characters and cap the
    // length so a pathological URL cannot bloat the error envelope or corrupt logs.
    $shown = is_string($value) ? preg_replace('/[\x00-\x1f\x7f]+/', '', $value) : (string) $value;
    if (mb_strlen($shown) > 100) {
        $shown = mb_substr($shown, 0, 100) . '...';
    }
    return sprintf(
        'Component "%s" %s is not a usable link URL: "%s" uses a disallowed protocol and would render as a dead link. Use an absolute URL (https://...), a site-relative path (/path), an anchor (#id), mailto:, or tel:.',
        $component,
        $where,
        $shown
    );
}

/**
 * Builds a composition error that carries its composition offset (#622).
 *
 * Every rule inside pp_validate_composition_errors()'s per-item loop names the
 * COMPONENT ("Component \"cta\" is missing required prop ...") but not WHICH band
 * on the page it is. On a page with two `cta` bands that is not enough to act on:
 * `_pp_composition_findings()` recorded `'index' => null` for every error-derived
 * finding, so the read-only diagnostics could not say which band was dead while the
 * sibling smells could.
 *
 * The offset rides as WP_Error DATA, not as a message prefix, and the two surfaces that
 * read it render it differently. `_pp_composition_findings()` copies it into the
 * finding's `index`, which every reporting surface prints beside the message. The WRITE
 * path has no such field in a human message, so pp_validate_composition() renders the
 * band INTO the message it returns (#642, _pp_band_named_composition_error()) and the
 * action envelope carries the offset beside `error_code`. The messages built here stay
 * band-free: they are what the reporting surfaces show, and adding a band would print
 * the locator twice there.
 *
 * Cross-item rules (duplicate_component_id) do NOT use this helper: they belong to
 * no single offset and already name every colliding index in the message.
 *
 * `$index` is deliberately untyped: it is the foreach key over a composition that may
 * never have passed validation (a raw meta write or a history-ring snapshot stored as a
 * JSON object yields string keys). Declaring `int` here would turn that into a TypeError
 * fatal on the very read-only diagnostics #622 exists to make survivable. A non-int key
 * records no locator rather than a coerced one — the same honest-null contract
 * pp_composition_error_index() enforces on the way out.
 *
 * @param  mixed  $index    Composition offset of the offending item.
 * @param  string $code     WP_Error code.
 * @param  string $message  Human-readable rejection message.
 * @return WP_Error
 */
function _pp_composition_item_error($index, string $code, string $message): WP_Error {
    return new WP_Error($code, $message, ['index' => is_int($index) ? $index : null]);
}

/**
 * Renders one authored LOCATION inside a composition item as a claim key (#621).
 *
 * The exhaustive per-item reporting pp_validate_composition_errors() does needs a way to
 * say "this exact spot already has a finding" so two rules that judge the SAME authored
 * value cannot both report it (a `columns: "abc"` fails the #379 bounds rule and the
 * #507 `number` type rule; a nested field can fail both #614's scalar type and #600's
 * enum membership). Without it, exhaustiveness would mean "one problem reported twice",
 * which reads as two problems and sends the operator looking for a second repair.
 *
 * The segments are a rule-owned ROLE literal — `prop`, `content`, `style`, `item-style`,
 * `list-shape` (#738), `object-shape` (#883), and whatever a future rule adds; read it as
 * an OPEN set, since a stale enumeration here is how a new rule's collision argument ends
 * up pointing at a list that does not contain it — followed
 * by the locator the message itself names — prop, then items[] entry key, then field —
 * so the claim granularity and the message granularity cannot drift. The role keeps
 * rule-owned locations in their own namespace: `style` is also a real declared items[]
 * field name, and a card's style map must never be able to claim, or be claimed by, a
 * nested field of that name.
 *
 * ENCODING, and why it is not a naive implode. The segments are AUTHOR-SUPPLIED array
 * keys: a prop key may contain `.`, `[`, `]`, `:` or any byte at all, and an items[]
 * entry key comes from a JSON object nobody validated. `implode('.', ...)` would make
 * the prop literally named `items.0` collide with entry 0 of prop `items`, and a
 * collision here SUPPRESSES a real finding — the exact failure this function exists to
 * prevent, wearing the opposite hat. Each segment is therefore length-prefixed, which is
 * injective for arbitrary bytes and, unlike json_encode(), cannot fail on invalid UTF-8
 * (json_encode() returns false there, collapsing every such key onto one bucket).
 *
 * PHP folds a numeric-STRING array key to an integer on the way in, so a stored `"5"`
 * and a list position 5 are the same key before this function ever sees them. Whether
 * those two SHOULD read alike in a message is #652's question about the locator
 * vocabulary; this key inherits whatever that decides and adds no new ambiguity.
 *
 * A non-scalar segment is unreachable from every call site (PHP array keys are int|string
 * and the rule-name segments are literals), but it degrades to a `?`-prefixed type name
 * rather than to a bare `gettype()` string — `?array` cannot be produced by the
 * length-prefixed branch, so even the impossible input stays injective instead of
 * colliding with a prop genuinely named `array`.
 *
 * @param  mixed ...$segments  Locator parts, outermost first (prop, entry key, field).
 * @return string              Collision-free key. Internal only — never rendered.
 */
function _pp_finding_location(...$segments): string {
    $key = '';
    foreach ($segments as $segment) {
        $key .= is_scalar($segment)
            ? strlen((string) $segment) . ':' . $segment
            : '?' . gettype($segment);
    }

    return $key;
}

/**
 * Whether an authored location already carries a finding (#621).
 *
 * The read-only half of the claim set, for a rule that must SKIP work rather than report:
 * the nested items[] walk asks this before judging an entry's fields, because an entry
 * whose SHAPE was already rejected would otherwise have every required field reported as
 * missing on a value whose real problem is that it is not an object at all.
 *
 * @param  array $sink         The finding sink (see _pp_claim_item_finding()).
 * @param  mixed ...$segments  Locator parts for _pp_finding_location().
 * @return bool
 */
function _pp_item_finding_claimed(array $sink, ...$segments): bool {
    return isset($sink['claimed'][_pp_finding_location(...$segments)]);
}

/**
 * Claims one authored location for the first rule that reports it (#621).
 *
 * True the first time a location is claimed, false every time after. Callers report only
 * on true, so FIRST RULE IN TRAVERSAL ORDER WINS a contested location — which preserves
 * the priority the file already documents (the #507 generic type pass deliberately runs
 * AFTER the #379/#380/#475 bounded families so their more precise messages win for the
 * props they cover). The claim set makes that ordering load-bearing rather than
 * incidental: reordering the rule blocks now changes which message an operator sees, so
 * a reorder is a behavior change and must be treated as one.
 *
 * THE SINK ALSO CARRIES THE BUDGET, which is what keeps the write path bounded. Because
 * EVERY report site in pp_validate_composition_errors() passes through this gate, refusing
 * a claim here is enough to stop a WP_Error and its formatted message from ever being
 * built — no loop-control plumbing at twenty sites. pp_validate_composition() sets the
 * budget to 1: it returns errors[0] and discards the rest, so without a budget a caller
 * could hand a write path 200KB of malformed items and make it allocate hundreds of
 * megabytes of findings that nothing reads. A null budget means unbounded, which is what
 * the reporting callers (#233 restore, #236 rollback, the read-only CLI) use.
 *
 * SINCE #643 THE UNBOUNDED CASE IS NO LONGER SCHEMA-BOUNDED. Every other rule emits at
 * most one finding per DECLARED prop, style slot or item field, so the schema caps the
 * list; the nested unknown-FIELD rule emits one per key the AUTHOR supplied, which nothing
 * caps. The write path is unaffected (budget 1), but the reporting callers above read AGED
 * STORED data that earlier versions accepted silently, so their worst case now scales with
 * the stored document rather than with the catalog. Realistically that is a handful of
 * camelCase strays per band; capping the reporting surfaces per entry, if it ever matters,
 * is a change to those callers and not to this gate.
 *
 * @param  array $sink         ['claimed' => array, 'budget' => int|null], by reference.
 *                             `claimed` is reset per item; `budget` spans the composition.
 * @param  mixed ...$segments  Locator parts for _pp_finding_location().
 * @return bool                True when the caller should report this finding.
 */
function _pp_claim_item_finding(array &$sink, ...$segments): bool {
    if ($sink['budget'] !== null && $sink['budget'] < 1) {
        return false;
    }
    $location = _pp_finding_location(...$segments);
    if (isset($sink['claimed'][$location])) {
        return false;
    }
    $sink['claimed'][$location] = true;
    if ($sink['budget'] !== null) {
        $sink['budget']--;
    }

    return true;
}

/**
 * Reads the composition offset stamped by _pp_composition_item_error() (#622).
 *
 * Returns null for a cross-item error (duplicate_component_id) and for any WP_Error
 * built without the stamp, so callers get an honest "no single band owns this"
 * rather than a fabricated 0.
 *
 * @param  WP_Error $error
 * @return int|null
 */
function pp_composition_error_index(WP_Error $error): ?int {
    $data = $error->get_error_data();

    return (is_array($data) && isset($data['index']) && is_int($data['index']))
        ? $data['index']
        : null;
}

/**
 * Lists the prop keys an item carries that its component's schema does not declare.
 *
 * Feeds the missing-required-prop message (#622). Post-#604 there is no alias map and
 * no "formerly known as" list to consult — the schema is the only source of accepted
 * prop names — so the honest help is to name the keys that are actually present and
 * unrecognized. When a value has been authored under a retired name, that name shows
 * up here and the operator can see what to rename.
 *
 * @param  array $item    Composition item.
 * @param  array $schema  The component's registered schema.
 * @return string[]       Undeclared prop keys, in authored order. Empty when clean.
 */
function _pp_undeclared_prop_keys(array $item, array $schema): array {
    if (!isset($item['props']) || !is_array($item['props'])) {
        return [];
    }
    $declared = (isset($schema['props']) && is_array($schema['props'])) ? $schema['props'] : [];
    $unknown  = [];
    foreach ($item['props'] as $prop_name => $ignored) {
        if (!array_key_exists($prop_name, $declared)) {
            $unknown[] = (string) $prop_name;
        }
    }

    return $unknown;
}

/** Most undeclared prop keys named in one missing-required-prop message (#622). */
const PP_UNDECLARED_KEYS_SHOWN = 10;

/** Longest single prop key echoed back in that message, in characters (#622). */
const PP_UNDECLARED_KEY_MAX_LENGTH = 64;

/**
 * Renders undeclared prop keys for inclusion in an error message (#622).
 *
 * These keys come from stored or caller-supplied composition data and travel out through
 * the CLI, the action envelope, the dashboard editor and the AI chat, so they get the
 * same treatment #633 gave the style-slot reflection rather than being echoed raw:
 *
 *   - control and format characters are stripped, so two different keys cannot present
 *     identically to the operator deciding whether the name they typed is the name that
 *     was rejected (and so a stored ANSI/bidi sequence cannot reach a terminal);
 *   - each key is capped, and the list is capped, so a pathological item cannot bloat
 *     the response the way an unbounded echo would.
 *
 * The count in the "and N more" tail is the TRUE total, so a truncated list never reads
 * as a complete one.
 *
 * TWO CALLERS, one bounding discipline: the missing-required-prop hint passes the whole
 * output of _pp_undeclared_prop_keys() (top-level prop keys, #622), and the #643 nested
 * unknown-field rule passes a single-element array holding one items[] FIELD key. Both are
 * caller-or-stored data; neither may be echoed raw. Keep any change safe for a one-key list.
 *
 * @param  string[] $keys  Undeclared key names — the output of _pp_undeclared_prop_keys(),
 *                          or a single items[] field key (#643).
 * @return string          Comma-separated, bounded list. Empty string when $keys is empty.
 */
function _pp_render_undeclared_prop_keys(array $keys): string {
    if ($keys === []) {
        return '';
    }
    $total = count($keys);
    $shown = [];
    foreach (array_slice($keys, 0, PP_UNDECLARED_KEYS_SHOWN) as $key) {
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $key);
        if ($clean === null) {
            $clean = ''; // non-UTF-8 input: report the key as unprintable, never raw
        }
        if (mb_strlen($clean) > PP_UNDECLARED_KEY_MAX_LENGTH) {
            $clean = mb_substr($clean, 0, PP_UNDECLARED_KEY_MAX_LENGTH) . '...';
        }
        $shown[] = $clean === '' ? '(unprintable key)' : $clean;
    }
    $rendered = implode(', ', $shown);
    if ($total > PP_UNDECLARED_KEYS_SHOWN) {
        $rendered .= sprintf(' and %d more', $total - PP_UNDECLARED_KEYS_SHOWN);
    }

    return $rendered;
}

/**
 * Validates a decoded composition array and returns EVERY error it finds.
 *
 * The collect-all engine behind pp_validate_composition(). Both read the same rules;
 * they differ only in how much they report. Write-time callers want the first error
 * (fail fast, one actionable message); reporting callers — restore_composition's
 * `findings` (#233) — need the complete set, or a caller fixes one violation, retries,
 * and discovers the next one only on the following run.
 *
 * EXHAUSTIVE PER AUTHORED LOCATION (#621). Until #621 this function stopped each item at
 * its FIRST failing check (`continue 2` and friends), so a band with a retired prop name
 * AND a dead style slot reported the prop and surfaced the slot only on the next pass —
 * precisely the fix-one-retry-discover-the-next loop a collect-all engine exists to
 * prevent. Every SCHEMA rule now records its finding and keeps validating the rest of
 * the item. The unit of exhaustiveness is the authored LOCATION the message names —
 * a prop, an items[] entry, a nested field, plus two item-scoped locations (`content`
 * for the #488 content requirement and `style` for the band's style map) — claimed
 * through _pp_claim_item_finding() so two rules judging one value report it once, not
 * twice. A rule whose message names only the prop (the #475 "items must be strings"
 * family) therefore reports once per prop rather than once per offending entry: the
 * report is as fine-grained as the locator it can offer, and no finer.
 *
 * A trailing `continue` after a claim means "this rule is done with this location". Some
 * of them are the last statement of their loop body and therefore no-ops today; they are
 * written anyway so appending a rule below one of them cannot silently make the rule run
 * on a value that was already reported. The `continue N` that remain are the four
 * structural checks below plus three inner-loop exits whose depth is stated inline.
 *
 * THE SECOND DELIBERATE LIMIT is the style map: _pp_validate_style_slot_map() returns the
 * FIRST bad slot in a map, so a band declaring two dead slots reports one. Widening that
 * shared engine reaches the style_component write path, which wants a single actionable
 * message, so it stayed out of #621 — what #621 fixed there is the MASKING (the rule was
 * unreachable for any item that tripped an earlier one).
 *
 * FOUR STRUCTURAL CHECKS STILL END THE ITEM, because nothing below them can be judged:
 * a missing `component` key, a non-scalar `component`, an unknown component (there is no
 * schema to validate against) and template-owned chrome (the band's identity is invalid,
 * so its props would be judged against a contract the operator must not use). Those four
 * are the only `continue`s to the next item left in this loop.
 *
 * NO CASCADE: a malformed parent is reported once by the rule that owns it and skipped by
 * the rules underneath. Mostly the existing guards already did that — every rule reads its
 * value through array_key_exists() or an is_array() gate, so `props: "oops"` belongs to
 * the required-prop rule, a scalar `items` to the #507 type pass, and a scalar ENTRY to
 * the item_type:"object" rule. One shape needed a real guard: a JSON LIST entry
 * (`items: [["/a.png","Alt"]]`) passes is_array(), so the nested field walk asks
 * _pp_item_finding_claimed() whether the entry's shape was already reported before
 * judging its fields. Without that, one malformed entry produced its shape error plus one
 * "missing required field" per declared field — misleading enough to send an authoring
 * agent adding keys to a list that can never satisfy the shape rule.
 *
 * errors[0] IS UNCHANGED. Rule order is untouched, a claim can never suppress an item's
 * FIRST finding, and everything exhaustiveness adds is APPENDED after the error that was
 * already there — so pp_validate_composition() rejects every write on exactly the rule,
 * the value and the band it always did. What it returns is no longer errors[0] itself:
 * since #642 it re-renders that error's MESSAGE to name the band (the write path has no
 * second field to print a locator into, the way every reporting surface does). Same
 * code, same stamped offset, same verdict; see _pp_band_named_composition_error(), and
 * testFirstCollectedErrorIsExactlyWhatValidateReturns() for the pinned difference.
 *
 * Every per-item error carries its composition offset as WP_Error data (#622); read it
 * with pp_composition_error_index(). Cross-item errors (duplicate_component_id) carry
 * none — they belong to no single band and name every colliding index in the message.
 *
 * SCOPING THE PER-ITEM RULES WITHOUT SCOPING THE ENGINE (#1007). `$only_index` narrows
 * the PER-ITEM loop to one band and leaves the CROSS-ITEM passes running over the whole
 * composition. It exists because `update_component` writes one band and was validating
 * every band, so a retired prop anywhere refused every edit to the page — including the
 * null-clear that is the documented way out, which on a page with retired props on two
 * bands could therefore never succeed at all.
 *
 * THE SPLIT IS THE WHOLE POINT, and the naive version of this change is a real bug. A
 * "validate only the targeted band" shortcut would also skip
 * pp_find_duplicate_component_ids(), and `props.id` is a declared prop that
 * update_component merges verbatim — so one call could set band 1's id to band 0's and
 * persist exactly the wrong-targetable state #238 closed. Cross-item rules are genuinely
 * page-level and stay unconditional; per-item rules are genuinely per-item and stop
 * speaking for bands the caller did not touch.
 *
 *     $only_index = null          $only_index = 2
 *     ┌──────────────────┐        ┌──────────────────┐
 *     │ per-item: 0,1,2  │        │ per-item: 2      │  ← narrowed
 *     │ cross-item: all  │        │ cross-item: all  │  ← unchanged
 *     └──────────────────┘        └──────────────────┘
 *
 * ONE GATE, NOT TWO (invariant I4). This is a parameter on the single validating engine
 * every ingress traverses, not a surface-specific second validator: the rules, their
 * order, their codes and their locators are identical either way, and an index that is
 * not a key of $items narrows to nothing rather than silently widening back.
 *
 * @param  array    $items  Decoded composition array.
 * @param  int|null $limit  Stop building findings after this many (null = every one).
 *                          Only pp_validate_composition() passes a value; see below.
 * @param  int|null $only_index  Run the per-item rules for this offset only (null = all).
 *                          Cross-item rules always run over the whole composition.
 * @return WP_Error[]       Empty when the composition is valid.
 */
function pp_validate_composition_errors(array $items, ?int $limit = null, ?int $only_index = null): array {
    // THE CONTAINER, JUDGED BEFORE ANY BAND (#724).
    //
    // A composition is a LIST. A JSON object decodes to an associative PHP array that
    // is_array() accepts, so `{"1": hero, "3": section}` used to walk straight into the
    // per-item loop below, satisfy every per-item rule (both bands were well-formed) and
    // return NO errors — `update_composition` answered ok:true, bumped the version, and
    // replaced a five-band page with two bands. The read path had always called that same
    // stored value corrupt: pp_get_composition_result() (lib/wp.php) classifies a decoded
    // non-list as `unexpected_shape` and hands back an EMPTY composition, and `wp pp check
    // page` says "treat as corrupted, not empty". The write path could manufacture exactly
    // the state the diagnostics exist to detect.
    //
    //   composition passed here     verdict
    //   [band, band]                LIST — the per-item loop below, byte-identical
    //   []                          LIST — a valid empty composition, unchanged
    //   {"1": .., "3": ..}          OBJECT — refused here, nothing below runs (#724's repro)
    //   {"hero": ..}                OBJECT — refused here
    //   {"0": .., "1": ..}          decodes AS A LIST; accepted. See THE LIMIT below.
    //
    // REJECT, NEVER COERCE (ruling D-A, canonical text in #724's body). No array_values(),
    // no reindexing, no normalization: the standing no-migration posture means a shape the
    // read path calls corrupt is refused, not quietly repaired into something else. The
    // caller's bands are not lost by this refusal — they were lost by the ACCEPTANCE.
    //
    // WHY IT RETURNS INSTEAD OF COLLECTING. Every rule below asks "which band?", and inside
    // a container that is not a composition there is no honest answer — that is the
    // fabricated-locator failure #634/#650/#652 closed one layer down, and #621's
    // misleading-repair-loop failure wearing a second hat. The read path models this
    // exactly: a non-list yields `composition: []` and says nothing whatsoever about its
    // contents. One container, one fact. (In the measured repro the two bands were VALID,
    // so a collect-all pass would have appended nothing anyway.)
    //
    // CODE `unexpected_shape` IS BORROWED ON PURPOSE. It is the read path's own spelling
    // (lib/wp.php, `composition_decode_error` in `operate inspect`, the two CLI integrity
    // warnings, docs/reference-apply-cli.md). One state, one word, on both sides of the
    // write. Coining a fourth spelling here is the drift #650/#652 spent a whole iteration
    // undoing. No `index` is stamped: this error belongs to no single band, exactly like
    // duplicate_component_id below.
    //
    // THE LIMIT, STATED SO IT IS NOT DISCOVERED LATER. `json_decode('{"0":a,"1":b}', true)`
    // returns a PHP LIST — the keys ARE 0..n-1 in order, and the object/list distinction is
    // destroyed before any PHP here can see it. That case is also the harmless one (key and
    // position agree, so nothing is silently dropped), and it is the same limit #652
    // recorded for item locators. Enforcing it would mean validating raw JSON TEXT, but
    // every caller reaches pp_execute_action() with a decoded PHP array — there is no raw
    // payload at this layer to inspect. `{}` decodes identically to `[]` for the same
    // reason and is accepted as the empty composition it is indistinguishable from.
    //
    // The message names the CONTAINER and never a band, and reflects only the entry COUNT —
    // no caller-supplied key text reaches an operator terminal, so the #633/#647 bounding
    // question does not arise here at all.
    if (!pp_is_list($items)) {
        return [new WP_Error(
            'unexpected_shape',
            // The leading clause comes from the shared renderer since #738, which added the
            // same sentence one level down for a declared `array` prop. Two literal copies
            // of one refusal spelling is the drift #650/#652 spent an iteration undoing;
            // only the GUIDANCE half differs, because a composition and a prop take
            // different example payloads. Byte-identical to every version since #724.
            'The composition must be a list of components, but ' . _pp_json_object_shape_clause($items)
            . ' Send the components as an array ([{"component": "hero", "props": {...}}, ...]), not an object.'
        )];
    }

    $registered = pp_get_registered_components();
    $errors     = [];
    // One sink for the whole composition: per-item claims plus the shared budget.
    $sink = ['claimed' => [], 'budget' => $limit];

    foreach ($items as $i => $item) {
        // Authored locations inside THIS item that already carry a finding (#621).
        // Reset per item: two bands may each report their own `prop / title`. The budget
        // is NOT reset — it spans the composition (see _pp_claim_item_finding()).
        $sink['claimed'] = [];

        // THE TWO STRUCTURAL CHECKS RUN FOR EVERY BAND, SCOPE OR NO SCOPE (#1007), and
        // they sit ABOVE the scope gate on purpose. They are per-item in FORM and
        // page-level in CONSEQUENCE, because they are the shapes the composition WRITER
        // indexes into: pp_update_composition()'s props.id loop is deliberately
        // non-defensive (#946) and fatals on a band that is a scalar, or whose `props` is
        // not an array. Skipping them for untargeted bands would hand the writer a shape
        // it cannot survive — a 500 or a WP-CLI fatal on a page the classifier calls
        // HEALTHY, which is exactly what I16 and I17 forbid — where the old whole-page
        // gate cleanly refused and named the band.
        //
        // Found by the review train's security specialist, proven against the committed
        // blobs rather than reasoned: `["a-scalar-band", {hero}]` is a list, so the page
        // reads healthy, and a band-scoped edit to the hero threw
        // `Cannot access offset of type string on string` instead of refusing.
        //
        // So the rule the scope gate follows is not "per-item rules narrow". It is:
        // rules the WRITER depends on stay unconditional, everything else narrows.
        if (!isset($item['component'])) {
            $errors[] = _pp_composition_item_error($i,
                'invalid_composition',
                sprintf('%s is missing the "component" key.', _pp_band_index_label($i, $items))
            );
            continue;
        }

        // A corrupt or raw-written row can carry an array/object here. Casting it would
        // emit "Array to string conversion" and then report a component literally named
        // "Array". restore's findings (#233) run these rules over arbitrary history-ring
        // snapshots, so malformed shapes reach this line — name the real problem instead.
        if (!is_scalar($item['component'])) {
            $errors[] = _pp_composition_item_error($i,
                'invalid_composition',
                sprintf('%s has a non-scalar "component" key.', _pp_band_index_label($i, $items))
            );
            continue;
        }

        // THE PER-ITEM SCOPE GATE (#1007). Strict comparison, and deliberately not
        // `array_key_exists` on a pre-filtered array: filtering would renumber the
        // offsets and every locator below would then name a band that does not exist.
        // An $only_index matching no key narrows to nothing, which is the safe
        // direction — the structural checks above and the cross-item passes below run
        // either way.
        if ($only_index !== null && $i !== $only_index) {
            continue;
        }

        $name = (string) $item['component'];

        if (!isset($registered[$name])) {
            // THE NAME IS REFLECTED VERBATIM HERE, AND THAT IS A RECORDED OUTCOME
            // rather than an oversight (#864). This branch fires precisely BECAUSE the
            // name is not in the registry, so it is arbitrary caller or stored text.
            //
            // Every route it takes to a human cleans it AT THE SINK, which is the theme's
            // rule — WITH ONE NAMED EXCEPTION. Cleaned: the editor's save, preview and
            // publish responses (lib/admin.php), the chat's execute and preview payloads
            // (lib/ai-chat.php, since v1.17.8), and the terminal through
            // _pp_cli_printable() (lib/cli.php). NOT cleaned: the `findings` channel,
            // which copies this message verbatim (_pp_composition_findings(),
            // lib/actions.php), rides every accepted write, and reaches the chat card with
            // its bytes intact — the client renders it through textContent and bounds its
            // LENGTH (ppChatBoundReflectedText), but deliberately does not strip, because
            // stripping is single-owned on the server. Say that plainly rather than
            // claiming a coverage this code does not have.
            //
            // WHY NOT CLEAN IT HERE AND CLOSE THAT ROUTE TOO. A guard at the source would
            // reach the `findings` channel and the terminal at once, and
            // _pp_bounded_findings() (lib/actions.php) names findings as a DIFFERENT
            // ruling: it records that a stored component NAME is still reflected verbatim,
            // which is what keeps a `duplicate_component_id` message O(N) in the band
            // count. Cleaning one of that channel's messages would half-land someone
            // else's ruling and make that note half-false. #864 converted the AJAX/editor
            // channel it was scoped to; the findings channel keeps its own owner and its
            // own issue.
            $errors[] = _pp_composition_item_error($i,
                'invalid_composition',
                sprintf('Unknown component: "%s".', $name)
            );
            continue;
        }

        // Site chrome is template-owned. Rejecting here covers every action-layer
        // write (create_page, update_composition, add_component, update_component)
        // and the editor save, which routes through update_composition.
        //
        // It is NOT the only path that can persist a composition: pp_update_composition()
        // is a thin, non-validating writer, so a raw update_post_meta() still writes
        // unchecked bytes. Chrome arriving that way is caught after the fact by
        // pp_validate_composition_smells() and pp_post_apply_validate().
        //
        // restore_composition (#233) is the deliberate exception: it never blocks on
        // these rules (undo must not fail), and instead reports them as `findings` via
        // _pp_composition_findings(), which reads this function.
        //
        // Distinct error code so the action layer can tell "that name is chrome"
        // apart from "that name doesn't exist" (issue #223).
        if (pp_is_template_owned_component($name)) {
            $errors[] = _pp_composition_item_error($i,
                'template_owned_component',
                pp_template_owned_component_message($name)
            );
            continue;
        }

        // NO ALIAS RESOLUTION HERE (#604). A transient canonical view used to be
        // built at this exact point (#495), which is what let a retired prop name
        // satisfy the required-prop loop below and slip past the unknown-prop gate
        // further down. Removing it is the functional heart of the alias removal:
        // every prop name a schema does not declare — including all 13 retired
        // ones — is now judged on the name the caller actually wrote. Props reach
        // the checks below exactly as stored or submitted.
        $schema = $registered[$name];
        if (!empty($schema['props'])) {
            // Built at most once per item, on first use (#621). The hint below depends on
            // the ITEM, not on which required prop is missing, and exhaustive reporting
            // means the loop can now fire many times for one item — a corrupt `props`
            // bag trips EVERY required prop at once. Rebuilding the same string (a full
            // props scan plus up to ten preg_replace/mb_* calls) per missing prop would
            // make the commonest malformed shape the most expensive one to report.
            $undeclared_hint = null;
            foreach ($schema['props'] as $prop_name => $prop_def) {
                // `is_array($item['props'])` is load-bearing, not defensive noise (#622).
                // This engine runs over compositions that never passed write-time
                // validation — raw meta writes and every history-ring snapshot — and #622
                // routes it into `wp pp check page` / `wp pp validate site`, which must
                // REPORT a corrupt row rather than die on it (the #144 contract). A stored
                // `props: "oops"` used to reach array_key_exists() and fatal with a
                // TypeError; it is now what it always meant: the required prop is absent.
                if (
                    !empty($prop_def['required']) &&
                    (!isset($item['props']) || !is_array($item['props']) || !array_key_exists($prop_name, $item['props']))
                ) {
                    // Name the undeclared keys this item DOES carry (#622). The
                    // unknown-prop gate further down names them one by one since #621,
                    // but this clause stays load-bearing on the WRITE path, which is
                    // still first-error-wins: pp_validate_composition() returns this
                    // message alone, so without the hint the caller is told a canonical
                    // name is missing and never told that a value is sitting under an
                    // unrecognized key right next to it. Derived from the schema — the current contract
                    // is the only source consulted. There is no retired-name lookup
                    // here and there must never be one (#603/#604/#605/#606 removed
                    // every alias map; a "formerly known as" hint list would be that
                    // machinery again under a different name).
                    if ($undeclared_hint === null) {
                        $undeclared      = _pp_undeclared_prop_keys($item, $schema);
                        $undeclared_hint = $undeclared === []
                            ? ''
                            : sprintf(
                                ' This item also carries prop key(s) "%s" does not declare: %s. Available props: %s.',
                                $name,
                                _pp_render_undeclared_prop_keys($undeclared),
                                implode(', ', array_keys($schema['props']))
                            );
                    }
                    $message = sprintf('Component "%s" is missing required prop "%s".', $name, $prop_name)
                        . $undeclared_hint;
                    // Exhaustive since #621: an item missing BOTH button_text and
                    // button_url names both, so one repair pass fixes the band. The
                    // claim is on the prop that is absent, which no later rule can
                    // report anyway (they all read the value through array_key_exists).
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i, 'invalid_composition', $message);
                    }
                    continue;
                }
            }
        }

        // Content requirement (issue 488). A component MAY declare a schema-level
        // `content_requirement.any_of`: the write is rejected unless AT LEAST ONE
        // listed content source is present-and-non-empty. This replaces a blunt
        // `body.required` on section so a body_items-only "trust strip" or a
        // panel-only band is authorable through the same write path, while a
        // fully-empty section is still rejected honestly. Generic + schema-driven
        // (the same shape as the issue 379/380/475 rules below — no per-component
        // branch, no second validator); restore_composition (#233) reports it via
        // _pp_composition_findings() but never blocks on it, same as every rule here.
        //
        // "Present-and-non-empty" is deliberately LOOSE (trimmed non-empty string,
        // or non-empty array): it answers "did the author put content here?", not
        // "is this prop well-typed?". A malformed-but-present content prop (e.g. a
        // non-array body_items) therefore SATISFIES the content gate and falls
        // through to its dedicated type check below, so the precise error wins
        // rather than being masked by a generic "no content" message.
        if (!empty($schema['content_requirement']['any_of'])
            && is_array($schema['content_requirement']['any_of'])
        ) {
            $content_props = (isset($item['props']) && is_array($item['props'])) ? $item['props'] : [];
            $has_content   = false;
            foreach ($schema['content_requirement']['any_of'] as $content_prop) {
                if (!array_key_exists($content_prop, $content_props)) {
                    continue;
                }
                $value = $content_props[$content_prop];
                if (is_string($value)) {
                    if (trim($value) !== '') {
                        $has_content = true;
                        break;
                    }
                } elseif (is_array($value)) {
                    if ($value !== []) {
                        $has_content = true;
                        break;
                    }
                } elseif ($value !== null && $value !== false) {
                    $has_content = true;
                    break;
                }
            }
            if (!$has_content) {
                $message = isset($schema['content_requirement']['message'])
                    ? (string) $schema['content_requirement']['message']
                    : 'has no renderable content';
                if (_pp_claim_item_finding($sink, 'content')) {
                    $errors[] = _pp_composition_item_error($i,
                        'invalid_composition',
                        sprintf('Component "%s" %s.', $name, $message)
                    );
                }
                // No `continue` since #621: "this band has no content" and "this band
                // also declares a dead style slot" are independent repairs, and an
                // empty band is exactly the case where the operator wants both at once.
            }
        }

        // Props that are declared, well-typed, stored — and paint nothing in the
        // configuration the band is actually in (#1006, invariant I35).
        //
        // THE CASE THIS CLOSES. A hero written as {"layout": "cover", "image_url": "..."}
        // validated, stored, reported ok:true and painted no background, on no channel.
        // Neither prop was removed in the v2 rebuild — only the render branch that made
        // the pair mean something — so nothing refused it and nothing reported it. A
        // FRESH author, the only audience 2.0.0-alpha.1 has, could write both straight
        // off the live schema and see nothing. That is the reported-success-without-effect
        // class the whole sprint exists to eliminate, reachable on day one.
        //
        // A REFUSAL, NOT AN ADVISORY, and the asymmetry with `inert_slot` is deliberate:
        // an inert SLOT is a value that does nothing on a band that is otherwise doing
        // what the author asked, while this pair is the author asking for a background
        // and getting no background. The right answer is to decline and say where
        // backgrounds live now, the same posture `retired_option` takes for a key that
        // moved.
        //
        // NOT A REFUSAL OF THE LAYOUT. `cover` is still a live layout — a tall centred
        // band — and refusing it would break pages that use it correctly. Only the PAIR
        // is dead, which is why the rule keys on a condition plus a prop set rather than
        // on either alone.
        //
        // GENERIC AND SCHEMA-DRIVEN, the same shape as `content_requirement` above: no
        // per-component branch here, and the condition reuses the `applies_when` clause
        // grammar (pp_applies_when_clause_met) rather than inventing a second predicate
        // language. restore_composition (#233) reports it without blocking, like every
        // rule in this loop, so a page already holding the pair still restores.
        if (!empty($schema['refuse_props_when']) && is_array($schema['refuse_props_when'])) {
            $authored  = (isset($item['props']) && is_array($item['props'])) ? $item['props'] : [];
            $prop_defs = isset($schema['props']) && is_array($schema['props']) ? $schema['props'] : [];
            $style_map = (isset($item['style']) && is_array($item['style'])) ? $item['style'] : [];

            foreach ($schema['refuse_props_when'] as $rule) {
                if (!is_array($rule) || empty($rule['props']) || !is_array($rule['props'])) {
                    continue;
                }
                // TWO OPPOSITE SAFETY DIRECTIONS IN ONE BLOCK, both deliberate, because
                // "fail open" means different things to the two consumers of this grammar.
                //
                // pp_applies_when_clause_met() was written for the `inert_slot` ADVISORY,
                // where "met" means WARN and every ambiguity therefore resolves to true
                // (staying silent is the safe direction for a warning). Here "met" means
                // REFUSE A WRITE, so the same true is the UNSAFE direction: a `layout`
                // stored as an array is a shape the evaluator cannot reason about, and
                // taking its "met" at face value would refuse `image_url` with a message
                // about backgrounds while the real defect — the array `layout` — goes
                // unmentioned by the rule that owns it two screens down.
                //
                // So this consumer decides the ambiguity for itself and DECLINES to refuse:
                //   - an unevaluable subject (non-scalar authored value) skips the rule;
                //   - a missing or non-array `when` skips the rule (an empty condition
                //     would otherwise match every band and refuse unconditionally).
                // Both directions are fail-open for the REFUSAL, which is the posture a new
                // blocking rule should take, and both are pinned registry-wide by
                // SchemaValidationTest::testEveryShippedRefusePropsWhenRuleIsWellFormed.
                $when = (isset($rule['when']) && is_array($rule['when'])) ? $rule['when'] : [];
                $met  = $when !== [];
                foreach ($when as $clause) {
                    if (!_pp_refuse_clause_is_decidable($clause, $authored, $prop_defs)) {
                        $met = false;
                        break;
                    }
                    if (!pp_applies_when_clause_met($clause, $authored, $prop_defs, $style_map)) {
                        $met = false;
                        break;
                    }
                }
                if (!$met) {
                    continue;
                }
                foreach ($rule['props'] as $dead_prop) {
                    $dead_prop = (string) $dead_prop;
                    // PRESENCE, judged the way the rest of this engine judges it: an unset
                    // prop and an empty one are the author NOT asking for the thing, and
                    // refusing those would make `"image_url": ""` unwritable on a cover
                    // band that is merely carrying the key.
                    if (!pp_applies_when_clause_met(
                        ['prop' => $dead_prop, 'present' => true], $authored, [], $style_map
                    )) {
                        continue;
                    }
                    if (_pp_claim_item_finding($sink, 'prop', $dead_prop)) {
                        $errors[] = _pp_composition_item_error($i,
                            'inert_prop',
                            sprintf(
                                'Component "%s" prop "%s" %s',
                                $name,
                                $dead_prop,
                                isset($rule['message']) ? (string) $rule['message'] : 'has no effect as configured.'
                            )
                        );
                    }
                }
            }
        }

        // Reject unknown prop keys (issue 147). The action layer shallow-merges
        // caller-supplied props and writes: update_component / add_component /
        // update_composition / create_page all validate through here, so a single
        // gate at this choke point closes the "phantom field" hole for every write
        // path (issue 120 only fixed the pp patch <selector> CLI entry point).
        // Without this, an unknown key persists, the action reports ok:true, and the
        // renderer silently ignores it — the reported-success-without-effect class.
        //
        // Source of truth is the component's schema.json `props` (the full prop
        // contract), NOT pp_get_component_fields() (the curated CLI-patch editability
        // subset), which omits real props like grid.items / section.panel_items and
        // would false-reject them.
        //
        // Runs after the required-props loop so a missing required prop still wins
        // first-error document order. restore_composition (issue 233) reports this
        // through _pp_composition_findings() but never blocks on it — same as every
        // other rule here.
        //
        // THE `continue 2` THAT STARTED #621 WAS HERE. It abandoned every later rule for
        // the item, so a band carrying a retired prop name reported that name and hid its
        // dead style slot, its out-of-range value and its broken link until the first was
        // repaired. Post-#604 that mattered more, not less: 13 more names route through
        // this gate, and a restore preview is where an operator sees them. Each unknown
        // key is now its own finding, and the rules below still run.
        if (isset($item['props']) && is_array($item['props'])) {
            $declared = isset($schema['props']) && is_array($schema['props'])
                ? $schema['props']
                : [];
            // Loop-invariant, and hoisted for the same reason as the required-prop hint
            // above (#621): the gate no longer stops at the first unknown key, so leaving
            // this inside the loop would rebuild one identical 200-character list per
            // unknown key on an item that carries many.
            $available = implode(', ', array_keys($declared)) ?: '(none)';
            $retired = function_exists('pp_component_retired_props')
                ? pp_component_retired_props($name)
                : [];
            foreach ($item['props'] as $prop_name => $prop_value) {
                if (!array_key_exists($prop_name, $declared)) {
                    // A RETIRED KEY GETS ITS OWN CODE AND A ROUTE (#1007), exactly as
                    // `retired_option` does for the six chrome options. The gate below
                    // answers "you typo'd"; this answers "this moved, and here is where",
                    // and a caller that cannot tell the two apart has to string-match
                    // prose to know whether to re-read the schema or rewrite the value.
                    //
                    // The CURE is stated because it is not guessable: `null` through
                    // update_component is a DELETE (_pp_merge_component_props), and it is
                    // the only route that clears a key the schema no longer declares.
                    if (isset($retired[(string) $prop_name])
                        && _pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'retired_prop',
                            sprintf(
                                'Component "%s" no longer has a prop "%s": it was retired when %s moved to '
                                . 'the v2 styling system. The replacement is %s. To clear the stored key, '
                                . 'send it as null — update_component with {"%s": null} removes it, and '
                                . 'this band can be repaired on its own. Available props: %s',
                                $name,
                                _pp_render_undeclared_prop_keys([(string) $prop_name]),
                                $name,
                                $retired[(string) $prop_name],
                                (string) $prop_name,
                                $available
                            )
                        );
                        continue;
                    }
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'unknown_prop',
                            sprintf(
                                'Component "%s" has no prop "%s". Available props: %s',
                                $name,
                                // THE KEY IS BOUNDED, BY THE SAME RENDERER THE NESTED TWIN USES
                                // (#647, harmonizing the asymmetry #643's RULE 5 recorded).
                                // This gate echoed `$prop_name` RAW while the items[] rule one
                                // level down passed the identical species of key through
                                // _pp_render_undeclared_prop_keys() — and RULE 5's own docblock
                                // named that "a known gap in the WIDER surface, not a standard
                                // to level down to, so harmonizing the two means bounding #147,
                                // never unbounding this". This is that bounding.
                                //
                                // The renderer emits the key BARE, so the format string keeps
                                // its literal quotes and a well-formed key is BYTE-IDENTICAL —
                                // `has no prop "subtitle"` reads exactly as it always did. Its
                                // docblock already committed to staying safe for a one-key list,
                                // which is how RULE 5 calls it too.
                                //
                                // TWO ROWS ARE NOT BYTE-IDENTICAL, named here rather than only
                                // in the suite: a key past PP_UNDECLARED_KEY_MAX_LENGTH (64) is
                                // cut and marked, and an EMPTY key now reads
                                // `has no prop "(unprintable key)"` instead of `has no prop ""`.
                                // The empty spelling is RULE 5's existing answer, adopted rather
                                // than re-decided — inventing a third spelling for the top-level
                                // case is the split this harmonization exists to end, and an
                                // empty string is not a legitimate prop key on any authoring
                                // path. Pinned in tests/ReflectedTextInventoryTest.php.
                                _pp_render_undeclared_prop_keys([(string) $prop_name]),
                                $available
                            )
                        );
                    }
                    continue;
                }
            }
        }

        // Bounded numeric props (issue 379). A prop whose schema declares integer
        // `min`/`max` bounds (today only grid.columns) must, when a value is
        // supplied, be an integer within [min, max] — otherwise the write is
        // rejected with the standard envelope instead of the renderer silently
        // coercing an out-of-range value (the reported-success-without-effect
        // class). Generic + schema-driven: only props that declare bounds are
        // checked, so existing untyped/enum props are untouched. "Unset" is the
        // key being absent, null, or the empty string — that preserves the
        // prop's default behavior (grid.columns unset => auto-by-count). Runs in
        // the shared validator (no second validator); restore_composition (#233)
        // reports it via _pp_composition_findings() but never blocks on it.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (!isset($prop_def['min']) || !isset($prop_def['max'])) {
                    continue;
                }
                if (!array_key_exists($prop_name, $item['props'])) {
                    continue;
                }
                $value = $item['props'][$prop_name];
                if ($value === null || $value === '') {
                    continue; // unset sentinel — keeps the prop's default behavior
                }
                $is_integer = is_int($value)
                    || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
                $min = (int) $prop_def['min'];
                $max = (int) $prop_def['max'];
                if (!$is_integer || (int) $value < $min || (int) $value > $max) {
                    // Claims the prop (#621), which is what keeps the #507 generic type
                    // pass from reporting the SAME value a second time as "must be a
                    // number" — this message is the more precise of the two.
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" must be an integer between %d and %d; got %s.',
                                $name,
                                $prop_name,
                                $min,
                                $max,
                                // #647: the bare cast this used to carry was the LAST rejection
                                // path in this function reflecting an author value without the
                                // shared bound. It quotes for itself, so the literal quotes came
                                // off with it — `got "13"` is byte-identical, `got false` is the
                                // row the helper's docblock exists to make honest.
                                _pp_schema_value_for_message($value)
                            )
                        );
                    }
                    continue;
                }
            }
        }

        // Strict enum props (issue 380, made universal by issue #579 A-32). Every
        // TOP-LEVEL enum prop declares `strict: true` now. A supplied value must be
        // one of the declared `values` — otherwise the write is rejected with the
        // standard envelope instead of the renderer silently coercing an unknown
        // value to the default (the reported-success-without-effect class, same
        // rationale as the issue 379 numeric-bounds check above).
        //
        // WHY UNIVERSAL NOW. `strict` shipped in #380 as opt-in, and exactly one
        // prop ever opted in, so twenty-eight enums stayed accept-at-write /
        // coerce-at-render: `theme: "muted "` (trailing space), `layout: "split "`,
        // `button_variant: "primary-outline"` all returned ok:true and rendered the
        // default. The mechanism was never the missing piece; the declarations were.
        // Render output is unchanged BY CONSTRUCTION — the renderer already coerced
        // every one of these — so this moves the write path from silent coercion to
        // a named error and changes no pixel.
        //
        // THE ADVERTISED SET IS THE ACCEPTED SET (#606). The membership test used to
        // union `values` with a prop's declared legacy `aliases` (#575's field, wired
        // here by #579). Every entry was retired (#603/#604/#605) and the field itself
        // is now retired too, so there is exactly one accepted set and the catalog
        // advertises all of it. Nothing rendered or written changed when the arm went:
        // the union was already over an empty list on every shipped prop.
        //
        // What that costs, stated rather than inferred, and NARROWED AT #1007: the
        // block runs inside pp_validate_composition_errors()'s per-item loop. That
        // loop used to run for every band on every action, so one untouched band
        // carrying a retired value blocked an edit to a DIFFERENT band on the same
        // page — recorded here for a long time as the accepted stale-data breakage.
        // It was not acceptable: the documented cure runs through update_component,
        // so a page with retired values on two bands could never be repaired at all.
        // update_component now validates only the band it targets, and the stale
        // band is reported on the accepted envelope instead of refusing the write.
        // `create_page` and `update_composition` still judge the whole page.
        //
        // None of that is a reason to re-add an alias: backward compatibility is an
        // explicit non-goal, and nothing here is migrated, coerced or healed.
        //
        // BOTH OF #579's EVIDENCE LEGS ARE RETIRED (#604, then #605). `dark` used to
        // be MANUFACTURED at read time by pp_migrate_legacy_variant_keys() from a
        // stored `variant: "dark"`, so it materialised on pages where the string
        // never appeared in storage; #604 deleted that migration. The remaining leg
        // — untouched bands that really do store `dark` — was the last thing keeping
        // the alias, and #605 removed the alias rather than keeping it: a stored
        // `dark` now reaches this gate as an ordinary unadvertised value and is
        // rejected. Nothing here is waiting on a further decision.
        //
        // SCOPE, stated so nobody reads more into it than is true: this block walks
        // $schema['props'], i.e. TOP-LEVEL props only, exactly as the #379/#475
        // families beside it do. NESTED item-field enums are no longer a hole in the
        // rule, but they are not closed HERE — #600 enforces them in the nested
        // items[] walk further down, over the same traversal #579 A-27 built, and
        // both depths share the membership predicate below so they cannot drift
        // apart. The CI tripwire (testEveryEnumDeclarationDeclaresStrict) covers
        // both depths for the same reason.
        //
        // Generic + schema-driven: no per-component branch, no second validator.
        // "Unset" is the key being absent, null, or the empty string — that
        // preserves the prop's default behavior (image_treatment unset => banner);
        // the sentinel and the membership test both live in
        // _pp_schema_enum_value_is_valid(), so an ABSENT key is the only part of
        // "unset" this loop still decides for itself. Runs in the shared validator;
        // restore_composition (#233) reports it via _pp_composition_findings() but
        // never blocks on it.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (!array_key_exists($prop_name, $item['props'])) {
                    continue;
                }
                $value = $item['props'][$prop_name];
                // Accepted set = advertised values, and nothing else (#606). What the
                // error names is exactly what the catalog advertises and exactly what
                // the gate accepts — one vocabulary, no unadvertised tier.
                if (!_pp_schema_enum_value_is_valid($prop_def, $value)) {
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" must be one of: %s; got %s.',
                                $name,
                                $prop_name,
                                implode(', ', $prop_def['values']),
                                // #647, same conversion as the numeric-bounds path above: one
                                // bound and one sanitization rule on every rejection path, so a
                                // strict-enum refusal cannot be the one that echoes 200 KB of
                                // author bytes into an envelope the nested rules already bound.
                                _pp_schema_value_for_message($value)
                            )
                        );
                    }
                    continue;
                }
            }
        }

        // Bounded string-array props (issue 475). A prop whose schema declares
        // `item_type: "string"` MAY also declare `max_items` and/or `item_max_length`
        // bounds. When it does, a supplied value must be an array of plain strings,
        // at most `max_items` of them, each at most `item_max_length` characters —
        // otherwise the write is rejected with the standard envelope instead of the
        // renderer silently dropping the offending entries (the reported-success-
        // without-effect class, same rationale as the issue 379 numeric-bounds and
        // issue 380 strict-enum checks above). Generic + schema-driven: only props
        // declaring `item_type: "string"` are checked, so the object-or-string
        // panel_items array (no item_type) is untouched. "Unset" is the key being
        // absent, null, the empty string, or an empty array — that preserves the
        // prop's default (an empty row renders nothing). Runs in the shared validator;
        // restore_composition (#233) reports it via _pp_composition_findings() but
        // never blocks on it.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (($prop_def['type'] ?? null) !== 'array'
                    || ($prop_def['item_type'] ?? null) !== 'string'
                ) {
                    continue;
                }
                if (!array_key_exists($prop_name, $item['props'])) {
                    continue;
                }
                $value = $item['props'][$prop_name];
                if ($value === null || $value === '' || $value === []) {
                    continue; // unset sentinel — keeps the prop's default (empty row)
                }
                // GRANULARITY, #621: every message in this family names the PROP and no
                // entry index, so the whole prop is one authored location and reports
                // once. Three bad bullets are one "items must be strings" finding, not
                // three identical lines the operator cannot tell apart. When a rule here
                // gains an entry locator, widen the claim segments with it.
                if (!is_array($value)) {
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" must be an array of strings; got %s.',
                                $name,
                                $prop_name,
                                gettype($value)
                            )
                        );
                    }
                    continue;
                }
                if (isset($prop_def['max_items']) && count($value) > (int) $prop_def['max_items']) {
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" accepts at most %d items; got %d.',
                                $name,
                                $prop_name,
                                (int) $prop_def['max_items'],
                                count($value)
                            )
                        );
                    }
                    continue;
                }
                $item_max_length = isset($prop_def['item_max_length']) ? (int) $prop_def['item_max_length'] : null;
                foreach ($value as $entry) {
                    if (!is_string($entry)) {
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" items must be strings; got %s.',
                                    $name,
                                    $prop_name,
                                    gettype($entry)
                                )
                            );
                        }
                        continue 2;
                    }
                    if ($item_max_length !== null && mb_strlen($entry) > $item_max_length) {
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" items must be at most %d characters; got %d.',
                                    $name,
                                    $prop_name,
                                    $item_max_length,
                                    mb_strlen($entry)
                                )
                            );
                        }
                        continue 2;
                    }
                }
            }
        }

        // Generic schema-typed prop enforcement (issue 507). The three opt-in
        // families above (#379 numeric bounds, #380 strict enum, #475 string-array
        // bounds) each guard ONE prop shape; this pass closes the remaining generic
        // gap so EVERY prop is checked against its declared `type`, not only the
        // props that opted into a bounded family. Without it, `title: []`,
        // `logo_id: "abc"`, or a scalar where an items-array belongs all validated
        // and persisted, and the renderer emitted silent-wrong output ("Array" as
        // text, PHP warnings) with ok:true — the reported-success-without-effect
        // trust class. Schema-driven, no per-component branch: a new prop is enforced
        // the moment its schema declares a `type`. Deliberately runs AFTER the three
        // bounded families so their precise messages still win first-error order for
        // the props they cover (e.g. grid.columns keeps the #379 "integer between
        // 1 and 12" message; body_items keeps the #475 "array of strings" message);
        // this pass only fires for shapes those families leave unchecked. Each check
        // has a per-type "unset" sentinel that preserves the prop's default. Runs in
        // the shared validator; restore_composition (#233) reports it via
        // _pp_composition_findings() but never blocks on it, same as every rule here.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (!is_array($prop_def) || !array_key_exists($prop_name, $item['props'])) {
                    continue;
                }
                $declared_type = $prop_def['type'] ?? null;
                $value         = $item['props'][$prop_name];

                if ($declared_type === 'string') {
                    // "The stored shape matches the declaration" (#507, narrowed by
                    // #707): a `type: "string"` prop takes a PHP string and nothing
                    // else. Until #707 this rule only rejected non-SCALARS, so an
                    // authoring agent could write `image_url: 42` through the
                    // sanctioned action, be told ok:true, and get `<img src="42">`
                    // with no finding anywhere behind it. null is the unset sentinel;
                    // the empty string is a valid string value. The predicate is
                    // shared with the nested items[] pass (#614) so the two depths
                    // cannot drift on what "string" accepts — #707 narrowed BOTH by
                    // changing that one predicate, which is why it had to be shared.
                    if (!_pp_schema_scalar_value_is_valid('string', $value)) {
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" must be a string; got %s.',
                                    $name,
                                    $prop_name,
                                    gettype($value)
                                )
                            );
                        }
                        continue;
                    }
                } elseif ($declared_type === 'number') {
                    // Reject non-numerics. Numeric strings are accepted (a JSON/CLI
                    // write sends "3"; the #379 bounds family already accepts them
                    // for grid.columns, so this stays consistent). null/'' are the
                    // unset sentinel (keeps the prop default, e.g. logo_id => 0).
                    // Shared with the nested items[] pass (#614), same as `string`.
                    if (!_pp_schema_scalar_value_is_valid('number', $value)) {
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" must be a number; got %s.',
                                    $name,
                                    $prop_name,
                                    _pp_schema_value_for_message($value)
                                )
                            );
                        }
                        continue;
                    }
                } elseif ($declared_type === 'array') {
                    // Reject scalars where an array belongs. null/''/[] are the unset
                    // sentinel (an empty row renders nothing). A present scalar is the
                    // silent-wrong case the renderer's is_array() guards swallow.
                    //
                    // The test moved into _pp_schema_container_value_is_valid() in #744
                    // and is otherwise unchanged (the old `$value !== []` clause was
                    // redundant with `!is_array($value)`). It moved for the reason #614
                    // moved the scalar test: the NESTED items[] pass needed the same
                    // answer one level down, and a second copy is exactly how two depths
                    // start disagreeing about whether `bullets: "text"` is a list.
                    if (!_pp_schema_container_value_is_valid($declared_type, $value)) {
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" must be an array; got %s.',
                                    $name,
                                    $prop_name,
                                    gettype($value)
                                )
                            );
                        }
                        // The nested walks below all guard on is_array(), so the scalar
                        // is reported once, here, and skipped everywhere underneath.
                        continue;
                    }
                    // A DECLARED LIST MUST BE A LIST (#738), and this sits immediately
                    // after the container test because it finishes that test's job: the
                    // predicate above decides "container or scalar?", which a JSON OBJECT
                    // passes, so `items: {"first": {...}}` was accepted, stored raw, and
                    // then took the PUBLIC page down at components/grid/grid.php's
                    // `(string) ($index + 1)` on a string key. The order matters for the
                    // MESSAGE as much as the logic: a scalar still reads "must be an
                    // array; got string", byte-identical to every version since #507,
                    // because it never reaches this line.
                    //
                    // IT ADDS A FINDING AND STOPS NOTHING — no `continue`, unlike the
                    // scalar arm above it, and the asymmetry is the load-bearing part.
                    // A scalar container ends the walk because nothing underneath it can
                    // be judged: there are no entries. A MAP has real entries, and every
                    // rule beneath this one can judge them honestly, because
                    // _pp_item_index_label() renders an object key as `key "first"` —
                    // which is exactly what #634/#650/#652 built and #649 bounded. So the
                    // entry loops below, the nested item-field pass, the per-item style
                    // engine and the link_url walk all keep running and keep naming real
                    // per-card defects at their honest keys.
                    //
                    // Suppressing them would have been the tidier-looking NO CASCADE move
                    // and it would have been wrong twice over: it would leave that whole
                    // locator family production-unreachable, and it would turn
                    // `wp pp check page` on an aged stored map into a two-round repair
                    // (fix the container, then discover the per-card defects) — the shape
                    // #621's exhaustive reporting exists to prevent. These are separate
                    // defects, not one defect in two vocabularies.
                    //
                    // THE ROLE SEGMENT IS `list-shape`, NOT `prop`, and that is a real bug
                    // fix rather than tidiness. The #475 bounded-string family above
                    // (`item_type: "string"`, plus its max_items and item_max_length
                    // siblings) claims `prop/<prop>` and runs BEFORE this rule, so sharing
                    // the namespace made the coarser defect invisible on exactly the two
                    // props that family covers. Measured: `section.body_items: {"a": 1}`
                    // reported only `items must be strings; got integer` — the operator
                    // fixes the entry, re-sends, and only THEN learns the container is a
                    // JSON object. A guaranteed two-round repair, which is #621's
                    // misleading-repair-loop class.
                    //
                    // The per-item STYLE engine below already set this precedent for the
                    // same reason (`item-style`), and its comment anticipated exactly this:
                    // a suppressed diagnostic is the one failure mode the claim set must
                    // never cause. Role segments are rule-owned literals, so they cannot
                    // collide with an authored prop name.
                    //
                    // REORDERING WAS THE OTHER OPTION AND WAS DECLINED. This rule asks a
                    // strictly coarser question than the #475 family and could have run
                    // first, but _pp_claim_item_finding()'s docblock records that rule
                    // ORDER is load-bearing — it is what makes the bounded families' more
                    // precise messages win — so moving a block is a behaviour change for
                    // every prop those rules cover, not a local fix. A distinct claim key
                    // changes nothing except that both findings now survive.
                    //
                    // The WRITE path still reports whichever rule ran FIRST (budget 1,
                    // first-error-wins): for a plain keyed object this rule, and for one
                    // that ALSO holds a bad entry the bounded family's message. Both are
                    // true, the write is refused either way, and the collect-all callers
                    // (`wp pp check page`, restore findings, the rollback report) now name
                    // both defects in one pass.
                    if (!_pp_schema_list_value_is_valid($declared_type, $value)) {
                        if (_pp_claim_item_finding($sink, 'list-shape', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" %s',
                                    $name,
                                    $prop_name,
                                    _pp_schema_list_shape_message($value)
                                )
                            );
                        }
                    }
                    // Object-item arrays opt in with `item_type: "object"` (mirrors
                    // the #475 `item_type: "string"` convention). Every entry must be
                    // an object (a JSON object decodes to an associative array); a
                    // scalar entry, or a populated JSON list where an object was
                    // expected, is rejected — the renderer reads $item['field'] and a
                    // non-object entry throws / renders nothing. panel_items is
                    // deliberately NOT annotated: it accepts mixed string+object
                    // entries, so it stays out of this check.
                    if (($prop_def['item_type'] ?? null) === 'object' && is_array($value)) {
                        foreach ($value as $entry_index => $entry) {
                            // Shared with #643's unknown-field rule since that rule has to
                            // STAY SILENT on exactly the shape this one REJECTS.
                            if (!_pp_entry_is_object_shape($entry)) {
                                // Per ENTRY since #621 — the message names the entry, so
                                // a grid whose cards 0 and 2 are both scalars names both.
                                if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index)) {
                                    $errors[] = _pp_composition_item_error($i,
                                        'invalid_prop_value',
                                        sprintf(
                                            'Component "%s" prop "%s" item %s must be an object; got %s.',
                                            $name,
                                            $prop_name,
                                            _pp_item_index_label($entry_index, $value),
                                            gettype($entry)
                                        )
                                    );
                                }
                                continue;
                            }
                        }
                    }
                    // Array-item arrays opt in with `item_type: "array"` (issue #579,
                    // A-27 — today only table.rows). Every entry must itself be an
                    // array. The defect: table.php renders `foreach ((array) $row as
                    // $cell)`, so a scalar row is CAST and silently becomes a
                    // one-cell row — a write that reports ok:true and produces a
                    // visibly broken table. This is the MECHANICAL half only: what a
                    // row's internal shape should be (flat scalars vs cell objects,
                    // short-row padding, a max column count) is deliberately NOT
                    // decided here and stays a needs-own-design child.
                    if (($prop_def['item_type'] ?? null) === 'array' && is_array($value)) {
                        foreach ($value as $entry_index => $entry) {
                            if (!is_array($entry)) {
                                if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index)) {
                                    $errors[] = _pp_composition_item_error($i,
                                        'invalid_prop_value',
                                        sprintf(
                                            'Component "%s" prop "%s" item %s must be an array; got %s.',
                                            $name,
                                            $prop_name,
                                            _pp_item_index_label($entry_index, $value),
                                            gettype($entry)
                                        )
                                    );
                                }
                                continue;
                            }
                        }
                    }
                } elseif ($declared_type === 'object') {
                    // Reject scalars where an object belongs (#744), through the same
                    // predicate and with the same sentinels as the `array` arm above —
                    // the two types have one answer because a JSON list and a JSON
                    // object both decode to a PHP array, so what is actually enforced
                    // is "container, not scalar". What a container may HOLD is not
                    // decided here; see the predicate's docblock.
                    //
                    // NO SHIPPED SCHEMA DECLARES A TOP-LEVEL `object` PROP TODAY. Both
                    // `object` declarations the registry USED to carry (grid.items[].style, retired #1101, and
                    // section.panel_items[].style) are NESTED fields, handled by RULE 6
                    // below. This arm is deliberately built anyway, and #744 is the
                    // argument for building it: that issue exists because one depth
                    // enforced a declared type and the other did not, and the cheap
                    // moment to close a fence is before something lands on it, not
                    // after. It is pinned by a SYNTHETIC component fixture rather than
                    // by a shipped schema — see ContainerPropWriteEnforcementTest —
                    // because an unreachable arm that no test can enter is how a fence
                    // silently stops being one.
                    if (!_pp_schema_container_value_is_valid($declared_type, $value)) {
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" must be an object; got %s.',
                                    $name,
                                    $prop_name,
                                    gettype($value)
                                )
                            );
                        }
                        continue;
                    }
                    // A DECLARED OBJECT MUST BE A MAP (#883), the mirror of the #738 rule
                    // in the `array` arm above and placed for the same reason: immediately
                    // after the container test, which decides "container or scalar?" and
                    // which a JSON LIST passes. The order is what keeps a scalar's message
                    // byte-identical to every version since #507 ("must be an object; got
                    // string") while a list gets a message about the shape it actually is.
                    //
                    // NO SHIPPED SCHEMA DECLARES A TOP-LEVEL `object` PROP TODAY, so this
                    // arm is entered only by the synthetic fixture in
                    // ObjectShapedPropWriteEnforcementTest — deliberately, and for the same
                    // reason the container arm above it is built and pinned that way: an
                    // unreachable arm that no test can enter is how a fence silently stops
                    // being one. Both shipped `object` declarations are the NESTED per-item
                    // style maps handled by RULE 6c below.
                    //
                    // A DISTINCT CLAIM ROLE (`object-shape`), matching the `list-shape`
                    // role its sibling takes one arm up, and taken for SYMMETRY rather than
                    // to resolve a live collision. Be exact about that, because the sibling
                    // comment's version of this paragraph describes a hazard that is real at
                    // ITS depth and is not yet real here: the container rule claims
                    // `prop/<prop>`, but it `continue`s on failure, so no value reaches this
                    // line already claimed, and no shipped schema declares a top-level
                    // `object` prop for a second rule to collide over. The role is therefore
                    // unmeasurable today — mutating it to `prop` leaves the suite green — and
                    // it is kept anyway so that the first real top-level `object` prop does
                    // not inherit a shared key by default. Role segments are rule-owned
                    // literals, so they cannot collide with an authored prop name.
                    //
                    // NO `continue`, and it is not an oversight: this is the last statement
                    // in the `object` arm, so control reaches the next prop either way.
                    // Writing one would assert a skip that skips nothing.
                    if (!_pp_schema_object_value_is_valid($declared_type, $value)) {
                        if (_pp_claim_item_finding($sink, 'object-shape', $prop_name)) {
                            $errors[] = _pp_composition_item_error($i,
                                'invalid_prop_value',
                                sprintf(
                                    'Component "%s" prop "%s" %s',
                                    $name,
                                    $prop_name,
                                    _pp_schema_object_shape_message($value)
                                )
                            );
                        }
                    }
                }
            }
        }

        // NESTED item-field contracts (issue #579 A-27, extended by #614, #600, #643 and #744).
        // The families above walk TOP-LEVEL props only, so FIVE schema annotations
        // one level down were DECLARED and enforced by NOTHING. Rules 1 and 2 came
        // with #579; RULE 3 is #614, RULE 6 is #744 and sits between RULE 3 and RULE 4
        // because it finishes RULE 3's job, and RULE 4 is #600 — all are marked inline
        // where they sit, between them. RULE 5 (#643) is the one rule here that is
        // NOT a declaration going unenforced: it walks the other direction — the
        // entry's OWN keys against the declared set — and closes the last silent
        // no-op at this depth, a key the schema never declared at all.
        //
        //   1. `required: true` on an items[] field — declared on SEVEN fields today:
        //      logos.items[].image_url / image_alt, stats.items[].number / label,
        //      testimonials.items[].quote and faq.items[].question / answer.
        //      (grid.items[].number is NOT among them — it ships `required: false`
        //      and is only conditionally required by prose, "when layout is steps";
        //      making it a real declaration would reject every ordinary card grid,
        //      so it stays out until a conditional-required contract exists.)
        //      The required-prop loop near the top of this function walks
        //      $schema['props'] only, so every one of these was
        //      decoration. The sharpest consequence: a logos entry carrying a `label`
        //      and no `image_url` validates, persists, returns ok:true, and renders
        //      NOTHING — and the `empty_section` smell stays silent because it fires
        //      only when NO entry has an image, so a strip of four logos that lost one
        //      URL warns about nothing at all.
        //   2. `item_type: "string"` on a NESTED array field (grid.items[].bullets).
        //      The #475 bounded-string-array family is top-level-only, so a bullets
        //      array of objects/numbers reached the renderer, which escapes each entry
        //      and prints "Array".
        //   3. the field's own scalar `type` — `string` or `number` (#614). See the
        //      RULE 3 comment inline below for the defect it closes.
        //   4. a nested `enum` field's STRICT membership (#600) — declared on
        //      grid.items[].text_role, the LAST nested enum in the shipped schemas, retired at #1101 (the rule is prospective now)
        //      today. Same rule the top-level block above applies, sharing the same
        //      predicate; see the RULE 4 comment inline below.
        //   5. a key the field map does NOT declare (#643) — the #147 top-level
        //      `unknown_prop` gate's semantics one level down. See the RULE 5 comment
        //      inline below for the defect it closes and for its two guards.
        //   6. the field's own CONTAINER `type` — `array` or `object` (#744), the
        //      other half of the declared-type job RULE 3 does for scalars, which is
        //      why it sits beside RULE 3 rather than at the end. Declared on THREE
        //      fields today: grid.items[].bullets (`array`); the `object` instances, grid.items[].style and
        //      section.panel_items[].style (`object`). Until #744 a scalar in any of
        //      them was accepted by all five rules above — rule 2 walks a bullets
        //      array's ENTRIES and a scalar never enters the loop, and rule 3's fence
        //      was scalar types only — so the two DEPTHS disagreed about what
        //      `type: "array"` means, exactly the asymmetry #614 closed for `string`
        //      and `number`. The predicate is shared with the top-level pass for that
        //      reason. See the RULE 6 comment inline below.
        //
        // Enforced HERE, in the shared validator — no second validator, no
        // per-component branch. Walked at the SAME one-items-level depth as the #154
        // media-URL and #507 link_url families.
        //
        // EVERY RULE HERE READS A FIELD MAP, never the JSON-Schema-ish scalar `items`
        // form (`bullets.items => {"type": "string"}`) — the is_array($field_def)
        // guard below is what separates them, so an `items` declaration that is a
        // value grammar rather than a map of fields is untouched by every rule here.
        // Every rule but 5 applies that guard per field as it iterates the declarations;
        // rule 5 iterates the ENTRY instead, so it reads the same predicate applied
        // to the whole map ($declared_fields, hoisted below) — one definition of
        // "is this a field map?", never two.
        //
        // `required` semantics MIRROR the top-level rule exactly: the key being ABSENT
        // is the violation. A present-but-empty string is not treated as missing,
        // because the top-level rule does not treat it that way either and because
        // over-rejecting here is not a local inconvenience — every action validates
        // the WHOLE composition, so a newly-rejected stored shape blocks edits to
        // unrelated bands on the same page. restore_composition (#233) reports these
        // via _pp_composition_findings() but never blocks on them.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (!is_array($prop_def)
                    || ($prop_def['type'] ?? null) !== 'array'
                    || !isset($prop_def['items'])
                    || !is_array($prop_def['items'])
                ) {
                    continue;
                }
                $entries = $item['props'][$prop_name] ?? null;
                if (!is_array($entries)) {
                    continue; // absent / scalar — the type pass above owns that error
                }
                // A NON-LIST CONTAINER IS NOT GATED OUT HERE, and that is a decision
                // rather than an omission (#738). The container itself is now refused by
                // the list rule in the type pass above, so the obvious move is this
                // file's NO CASCADE convention: report the parent once, skip the rules
                // beneath it. It is the wrong move HERE, and the reason is that the two
                // depths differ in whether an honest answer exists.
                //
                // #724 could suppress at the BAND depth because inside a container that
                // is not a composition there is no honest answer to "which band?" — the
                // error payload has no key form, so _pp_composition_item_error() records
                // `index: null` and a locator would have to be fabricated. At THIS depth
                // the honest answer exists and was built on purpose: #634/#650/#652 made
                // _pp_item_index_label() render an object key as `key "aa"` precisely so
                // a map's entries could be named truthfully, and #649 bounded that key.
                // Suppressing here would quietly revoke that ruling and leave its whole
                // family production-unreachable.
                //
                // It is also worse for the operator. `wp pp check page` on an aged stored
                // map is a REPORTING caller with no budget: under suppression it says
                // "items must be a list" and nothing else, so repairing the container
                // surfaces a second round of per-card defects — the two-round repair
                // #621's exhaustive reporting exists to prevent. The per-entry findings
                // are not a second vocabulary for the container defect; they are true,
                // separately actionable statements about real cards, each honestly
                // located. The WRITE path is unaffected either way: it is first-error-wins,
                // and the list rule sits above every rule this gate governs, so a rejected
                // write reports the container defect rather than one of these. (The bounded
                // families higher up the per-item body can still legitimately win the
                // message for the props they cover — see the list rule's own comment.)
                // THE EFFECTIVE DECLARED-FIELD SET, hoisted per prop for RULE 5 (#643).
                // Rules 1-4 and 6 iterate the DECLARATIONS, so each can ask `is_array($field_def)`
                // one field at a time — the guard that separates a FIELD MAP from the
                // JSON-Schema-ish scalar form. Rule 5 iterates the ENTRY's own keys instead,
                // so it needs the whole set up front: both to decide whether this `items` is
                // a field map at all, and to name the available fields in its message.
                //
                // THE PREDICATE IS TIGHTER THAN `is_array` ALONE, and deliberately so. A
                // field definition is a JSON OBJECT (`{"type": "string", ...}`); the
                // array-valued keys a definition may otherwise carry are all JSON LISTS —
                // `values` (enum members), `applies_when` (clauses), and an array `default`
                // are the three in the closed definition-key set (pp_prop_definition_keys()).
                // Under a bare `is_array` test a future scalar-form declaration such as
                // `{"type": "string", "values": ["a","b"], "strict": true}` would read as a
                // field map holding one field named `values`, and rule 5 would then report
                // every REAL key of every entry as undeclared against `Available fields:
                // values` — confidently wrong, and worse than silence. Rules 1-4 and 6 survive
                // that shape by accident (they index `$field_def['required']` / `['type']`
                // on a list, get null, and no-op), so the loose predicate only becomes
                // consequential once a rule reads the map as a whole. No shipped schema hits
                // this today, and none can: every top-level array prop that declares an
                // `items` key declares a real field map, and the array props that do not
                // (`section.body_items`, `table.headers`, `table.rows`) carry no `items` key
                // at all, so the outer `!isset($prop_def['items'])` guard dropped them long
                // before here. The value grammar exists one level DOWN instead
                // (`grid.items[].bullets`), where it is a nested field and never reaches
                // $prop_def. So this fence guards a shape no schema has yet — which is the
                // cheap moment to build it, not after one lands on it.
                //
                // Hoisted for the same reason #621 hoisted $available and $undeclared_hint
                // above — one build per prop, not one per entry.
                // THE MAP ITSELF IS TESTED BEFORE ITS MEMBERS ARE, and the two tests are
                // deliberately NOT the same predicate — they answer different questions,
                // and the empty array is exactly where they diverge.
                //
                //   the MAP: is it a JSON object?  _pp_entry_is_object_shape(), which
                //     admits `{}`. The JSON-Schema LIST form (`"items": [{"type":
                //     "string"}]`) is not an object, and without this outer test it
                //     survives as `[0 => {...}]` — non-empty, so the guard below would not
                //     fire — and every real key of every entry gets rejected against a
                //     phantom field named `0`.
                //
                //   a MEMBER: is it a field DEFINITION? A definition is a NON-EMPTY object,
                //     because the array-valued schema keywords a declaration may carry are
                //     lists — `values`, `applies_when`, and an array `default`, which is
                //     `[]`. Reusing the map's predicate here would readmit `default: []` as
                //     a field named `default`; the test above proves it, so keep them apart.
                $declared_fields = _pp_entry_is_object_shape($prop_def['items'])
                    ? array_filter(
                        $prop_def['items'],
                        static function ($field_def) {
                            return is_array($field_def) && !pp_is_list($field_def);
                        }
                    )
                    : [];
                $available_fields = implode(', ', array_keys($declared_fields));
                // Which prop, if any, carries this component's item grain — resolved
                // once per prop rather than once per entry, for the same reason
                // $available_fields is hoisted. Empty string when the component
                // declares none, which can never equal a real prop name.
                $item_grain_declaration = pp_udc_item_roles($name);
                $item_grain_prop        = $item_grain_declaration === null
                    ? ''
                    : $item_grain_declaration['prop'];
                foreach ($entries as $entry_index => $entry) {
                    if (!is_array($entry)) {
                        continue; // non-object entry — item_type: "object" owns that error
                    }
                    // A JSON LIST is an array, so is_array() alone lets `items: [["/a.png",
                    // "Alt"]]` — one of the commonest shapes an authoring agent gets wrong —
                    // through to the field rules, which then report every required field as
                    // missing on a value whose real problem is that it is not an object.
                    // Before #621 the item_type:"object" rule's `continue 3` ended the item
                    // and hid that; exhaustive reporting exposed it as three findings for
                    // one defect, two of them misleading enough to send an agent into a
                    // repair loop (adding keys to a list still fails the shape rule). The
                    // rule that OWNS the entry's shape has already claimed it, so ask.
                    if (_pp_item_finding_claimed($sink, 'prop', $prop_name, $entry_index)) {
                        continue;
                    }
                    // Built at most once per ENTRY, on first use — the same shape and the
                    // same reason as the top-level hint #621 hoisted above: the hint depends
                    // on the ENTRY, not on which required field is missing, and one entry can
                    // trip several required fields at once (a logos card missing both
                    // image_url and image_alt names both). See RULE 1 below for what it is.
                    $undeclared_field_hint = null;
                    foreach ($prop_def['items'] as $field_name => $field_def) {
                        // The `items` key carries two shapes across the shipped
                        // schemas: a FIELD MAP (grid.items => {title: {...}, ...})
                        // and the JSON-Schema-ish scalar form
                        // (bullets.items => {"type": "string"}). Only a field map's
                        // values are definition arrays, so this guard is what keeps
                        // the scalar form from being read as a field called "type".
                        if (!is_array($field_def)) {
                            continue;
                        }
                        if (!empty($field_def['required'])
                            && !array_key_exists($field_name, $entry)
                        ) {
                            // NAME THE UNDECLARED FIELDS THE ENTRY DOES CARRY (#643,
                            // mirroring #622 one level down). RULE 5 below rejects an
                            // undeclared field, but it runs AFTER this loop and
                            // pp_validate_composition() is first-error-wins, so on the write
                            // path a RENAMED required field reported only the canonical name
                            // that is missing and never the key sitting next to it. That is a
                            // guaranteed two-round repair: round one says "add image_url",
                            // the author adds it and keeps `imageUrl`, round two then says
                            // "has no field imageUrl". The asymmetry was sharper than it
                            // looks — a misspelled OPTIONAL field surfaces immediately
                            // through RULE 5, so the gate lost its voice on exactly the
                            // REQUIRED fields, which are the ones that matter most.
                            //
                            // Same helper, same message grammar and same "Available X:" tail
                            // as the top-level hint, so the two depths cannot drift: the key
                            // list is bounded by _pp_render_undeclared_prop_keys() (#622/#633)
                            // because it is caller data, and the available list is
                            // schema-derived and printed plainly.
                            //
                            // Gated on the SAME two carve-outs as RULE 5. Without a field map
                            // there is nothing to call undeclared, and on a populated JSON
                            // list the hint would name positions ("0", "1") instead of a
                            // field — the misleading-repair-loop class this clause exists to
                            // prevent, not to reproduce.
                            if ($undeclared_field_hint === null) {
                                $undeclared_field_hint = '';
                                if ($declared_fields !== [] && _pp_entry_is_object_shape($entry)) {
                                    $undeclared = [];
                                    foreach ($entry as $entry_key => $ignored) {
                                        if (!array_key_exists($entry_key, $declared_fields)) {
                                            $undeclared[] = (string) $entry_key;
                                        }
                                    }
                                    if ($undeclared !== []) {
                                        $undeclared_field_hint = sprintf(
                                            ' This item also carries field(s) "%s" entries do not declare: %s. Available fields: %s.',
                                            $prop_name,
                                            _pp_render_undeclared_prop_keys($undeclared),
                                            $available_fields
                                        );
                                    }
                                }
                            }
                            // Per FIELD since #621: a logos entry missing both
                            // image_url and image_alt names both, and the sibling
                            // entries are still walked.
                            if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                $errors[] = _pp_composition_item_error($i,
                                    'invalid_composition',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s is missing required field "%s".',
                                        $name,
                                        $prop_name,
                                        _pp_item_index_label($entry_index, $entries),
                                        $field_name
                                    ) . $undeclared_field_hint
                                );
                            }
                            continue;
                        }
                        // RULE 3 — the field's own declared SCALAR type (#614).
                        // `required` and `item_type` above said WHETHER a field is
                        // there and what a nested ARRAY holds; nothing said what a
                        // `string` or `number` field may BE, so the write path took
                        // any JSON value. That matters because PHP's cast is not a
                        // rejection: `(int) ['attachment_id' => 42]` and `(int) true`
                        // are both 1, so a renderer reading `(int) ($item['image_id']
                        // ?? 0)` resolved attachment ID 1 — usually the site's first
                        // upload — and discarded the author's image_url. The page
                        // rendered a confidently wrong image behind an ok:true.
                        //
                        // The predicate is SHARED with the #507 top-level pass, so
                        // "42" is a number at both depths and cannot drift apart —
                        // and since #707 a `string` field means a PHP string at both
                        // depths too, so a nested `image_url: 42` is refused exactly
                        // where a top-level one is. Sharing the predicate is what made
                        // that a one-line narrowing instead of two rules to keep in
                        // step. Enum fields are RULE 4's business (#600, below) and the
                        // container types are RULE 6's since #744 — that rule says a
                        // declared `array`/`object` field may not hold a SCALAR, and
                        // still says nothing about what one may CONTAIN (no decision
                        // exists on what an item `style` object may hold).
                        // _pp_schema_scalar_value_is_valid() returns true for every
                        // type but these two, so they fall through this rule untouched
                        // and are judged by the rules that own them.
                        //
                        // Same cost the required rule above carries, narrowed at
                        // #1007: `create_page` and `update_composition` validate the
                        // WHOLE composition, so a stored value this rejects blocks
                        // those two from any band. `update_component` now judges only
                        // the band it targets and reports the rest on the accepted
                        // envelope. That is the v1.13.0 no-compat posture working as
                        // intended, not a regression — restore_composition still
                        // reports and restores rather than blocking (#233).
                        $field_type = $field_def['type'] ?? null;
                        if (($field_type === 'string' || $field_type === 'number')
                            && array_key_exists($field_name, $entry)
                            && !_pp_schema_scalar_value_is_valid($field_type, $entry[$field_name])
                        ) {
                            if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                // WHAT "got" NAMES DIFFERS BY LEG, and the split is #707's.
                                // `number` echoes the VALUE, because that is the repair
                                // signal there: a rejected "abc" is fixed by looking at
                                // "abc". `string` names the SHAPE, because after #707 the
                                // rejected values are scalars, and echoing one reads
                                // `must be a string; got "42"` — which tells an authoring
                                // agent that the value it was just refused already looks
                                // like a string, and sends it to re-read a value that was
                                // never the problem. The problem is the TYPE. It is also
                                // what the top-level pass says (gettype, `got integer`),
                                // and #614 shared the predicate between these two depths
                                // precisely so they could not answer differently; a shared
                                // verdict rendered in two vocabularies is the same drift
                                // one layer up. Before #707 this leg only ever fired for
                                // containers, where the helper degrades to a bare type
                                // name anyway, so the two depths agreed by accident.
                                $shown = $field_type === 'string'
                                    ? gettype($entry[$field_name])
                                    : _pp_schema_value_for_message($entry[$field_name]);
                                $errors[] = _pp_composition_item_error($i,
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s field "%s" must be a %s; got %s.',
                                        $name,
                                        $prop_name,
                                        _pp_item_index_label($entry_index, $entries),
                                        $field_name,
                                        $field_type,
                                        $shown
                                    )
                                );
                            }
                            // Depth accounting for every `continue` in this block:
                            //   1 foreach ($prop_def['items'] …)   fields of one entry
                            //   2 foreach ($entries …)             entries of one prop
                            //   3 foreach ($schema['props'] …)     props of one component
                            //   4 the per-component loop           => next component
                            // Since #621 the rules here advance to the NEXT FIELD (bare
                            // `continue`, depth 1) instead of ending the component item:
                            // one finding per authored field, every field walked. The
                            // claim above is what keeps RULE 4 below from re-reporting
                            // this same field. Recount these if the nesting changes.
                            continue;
                        }
                        // RULE 6 — the field's own declared CONTAINER type (#744).
                        // The other half of RULE 3's job, and it sits here rather than
                        // at the end of the loop because it answers the same question
                        // about the same declaration: does the value match the type the
                        // schema declares? RULE 3 answers it for `string`/`number`;
                        // this answers it for `array`/`object`, through the sibling
                        // predicate, SHARED with the top-level pass for exactly the
                        // reason #614 shared the scalar one — the top level has
                        // rejected a scalar where an array belongs since #507, this
                        // depth accepted one until now, and two depths enforcing one
                        // declaration differently is the defect, not the fix.
                        //
                        // What it caught, measured on main before the fix:
                        // `grid.items[].bullets: "not an array"` validated, persisted
                        // RAW and rendered NOTHING — components/grid/grid.php reads it
                        // as `is_array($item['bullets'] ?? null) ? ... : []`. So did a
                        // scalar `style` on a grid card or a section panel row: the
                        // per-item slot engine below skips a non-array `style` map, and
                        // both renderers read it through the same is_array() guard. An
                        // author asked for a checklist or a one-card colour override,
                        // was told ok:true, and got neither.
                        //
                        // ORDER AND CLAIMS. It runs BEFORE RULE 2 (the item_type:
                        // "string" bullets loop at the end of this body), which is
                        // gated on is_array() and so could never see a scalar anyway —
                        // but the claim taken here is what guarantees ONE finding per
                        // field rather than a second rule reporting the same defect in
                        // a different vocabulary. The claim key is the same
                        // `prop/<prop>/<entry>/<field>` shape RULES 1/3/4 use; the
                        // per-item STYLE engine below deliberately claims a different
                        // role segment (`item-style`), which is what keeps a rejected
                        // scalar `style` from swallowing a slot finding — see the
                        // comment there, which anticipated exactly this rule.
                        //
                        // Same accepted cost as every rule in this block: whole-
                        // composition validation means a stored scalar blocks edits to
                        // unrelated bands until the item is repaired through the
                        // ordinary authoring surface. v1.13.0 no-compat posture, not a
                        // regression — no coercion (a scalar is never wrapped in a
                        // one-element array), no migration, and restore_composition
                        // still reports rather than blocks (#233).
                        if (($field_type === 'array' || $field_type === 'object')
                            && array_key_exists($field_name, $entry)
                            && !_pp_schema_container_value_is_valid($field_type, $entry[$field_name])
                        ) {
                            if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                // Names the SHAPE (gettype), never the value — the same
                                // choice #707 made for the `string` leg and for the same
                                // reason: the rejected values here are scalars, and
                                // echoing one reads `must be an array; got "text"`,
                                // which sends an authoring agent to re-read a value that
                                // was never the problem. The problem is the TYPE. It is
                                // also verbatim what the top-level arm says for the same
                                // defect one level up, so the two depths cannot answer
                                // in two vocabularies.
                                $errors[] = _pp_composition_item_error($i,
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s field "%s" must be %s; got %s.',
                                        $name,
                                        $prop_name,
                                        _pp_item_index_label($entry_index, $entries),
                                        $field_name,
                                        $field_type === 'array' ? 'an array' : 'an object',
                                        gettype($entry[$field_name])
                                    )
                                );
                            }
                            // Same depth accounting as RULE 3 above — a bare `continue`
                            // is the next FIELD of this entry (#621).
                            continue;
                        }
                        // RULE 6b — a nested declared LIST must be a list (#738), the
                        // second of RULE 6's three stages, exactly as it is one level up.
                        // (Container first, then this for `array`, then RULE 6c for
                        // `object`.) Same
                        // predicate, same order (after the container test, so a scalar
                        // field keeps its byte-identical "must be an array; got string"),
                        // same claim key, same depth accounting.
                        //
                        // Only ONE shipped field is in its scope today — `grid.items[]
                        // .bullets` is the single nested `type: "array"` declaration in
                        // the registry. The `object` fields that used to sit beside it (grid.items[].style,
                        // section.panel_items[].style) are NOT this rule's: since #883
                        // they are owned by RULE 6c below, which asks the mirror question
                        // through the mirror predicate. That is not an
                        // argument for skipping the depth: the whole defect #744 closed
                        // was one depth enforcing a declaration the other did not, and
                        // re-opening it for a rule this file adds in the same breath
                        // would be the same mistake wearing a newer number. Pinned by the
                        // schema-driven inventory sweep in
                        // tests/ListShapedPropWriteEnforcementTest, so a nested list
                        // declaration landing tomorrow is covered the day it lands.
                        //
                        // IT DOES `continue` WHERE THE TOP-LEVEL RULE DELIBERATELY DOES
                        // NOT, and the asymmetry is real rather than an oversight. Up there
                        // a `continue` would have skipped every rule BENEATH the container —
                        // the entry loops, the nested field pass, the style engine, the
                        // link_url walk — and silenced honest per-entry findings. Here the
                        // bare `continue` is RULE 3's depth accounting: it advances to the
                        // next FIELD of this entry (#621), so the entry's other fields, the
                        // other entries, and RULE 2's bullets-entry loop after this one all
                        // still run. Same principle, not the same statement: report this
                        // field once, judge nothing less.
                        if ($field_type === 'array'
                            && array_key_exists($field_name, $entry)
                            && !_pp_schema_list_value_is_valid($field_type, $entry[$field_name])
                        ) {
                            if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                $errors[] = _pp_composition_item_error($i,
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s field "%s" %s',
                                        $name,
                                        $prop_name,
                                        _pp_item_index_label($entry_index, $entries),
                                        $field_name,
                                        _pp_schema_list_shape_message($entry[$field_name])
                                    )
                                );
                            }
                            continue;
                        }
                        // RULE 6c — a nested declared OBJECT must be a map (#883), the
                        // third of RULE 6's stages and the exact mirror of RULE 6b above.
                        // Same predicate family, same order (after the container test, so a
                        // scalar field keeps its byte-identical "must be an object; got
                        // string"), same claim key, same depth accounting — the bare
                        // `continue` advances to the next FIELD of this entry (#621).
                        //
                        // THIS IS THE HALF WITH SHIPPED CALLERS, which inverts RULE 6b's
                        // situation: `grid.items[].style` and `section.panel_items[].style`
                        // are the registry's only two `object` declarations, and both are
                        // reached here. The top-level arm is the synthetic one.
                        //
                        // WHAT IT TAKES OVER FROM AN ACCIDENT. Before this rule a list
                        // `style` was refused a few rules later by the shared style-slot
                        // engine, which reads a list's integer keys as slot names and
                        // answers `item 0 has no style slot "0". Available slots: ...`.
                        // That message is TRUE and it is also the wrong rule answering: it
                        // describes a naming mistake the author did not make, and it held
                        // only because both shipped `object` fields happen to route to the
                        // slot engine. A field declaring `object` for anything else had no
                        // local rejection at all (#883). This rule owns the shape; the slot
                        // engine keeps owning slot names.
                        //
                        // BOTH FINDINGS SURVIVE ON A COLLECT-ALL SURFACE, deliberately, and
                        // the claim roles are what makes that true: this rule claims
                        // `prop/<prop>/<entry>/<field>` and the per-item style engine claims
                        // `item-style/<prop>/<entry>`. `wp pp check page`, restore findings
                        // and the rollback report therefore name both the shape defect and
                        // the slot defect for one list-shaped `style`. Two true sentences
                        // about one value is the posture #621 and #738 both chose over
                        // suppression, and the write path is unaffected: it runs budget 1,
                        // first-error-wins, and this rule runs first, so an authoring agent
                        // gets exactly one message and it is the one about the shape.
                        if ($field_type === 'object'
                            && array_key_exists($field_name, $entry)
                            && !_pp_schema_object_value_is_valid($field_type, $entry[$field_name])
                        ) {
                            if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                $errors[] = _pp_composition_item_error($i,
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s field "%s" %s',
                                        $name,
                                        $prop_name,
                                        _pp_item_index_label($entry_index, $entries),
                                        $field_name,
                                        _pp_schema_object_shape_message($entry[$field_name])
                                    )
                                );
                            }
                            continue;
                        }
                        // RULE 4 — the field's own STRICT enum membership (#600).
                        // The last accept-at-write / coerce-at-render surface in the
                        // composition grammar. `strict` shipped in #380 and #579 made
                        // every TOP-LEVEL enum declare it, but the gate that reads the
                        // flag walked $schema['props'] only, so declaring `strict` on
                        // a nested enum was a silent no-op and an out-of-set
                        // grid.items[].text_role returned ok:true, persisted, and
                        // rendered as ordinary body text — the card the author asked
                        // to mark as code/caption/eyebrow simply was not marked.
                        //
                        // The predicate is SHARED with the top-level block above, so
                        // there is ONE definition of enum membership and one unset
                        // sentinel at both depths. Its declaration guard is what makes
                        // the rule schema-driven rather than a text_role branch: any
                        // future nested enum is enforced the moment its schema says
                        // `strict`, and testEveryEnumDeclarationDeclaresStrict fails a
                        // nested enum that ships without it.
                        //
                        // RENDER OUTPUT IS UNCHANGED BY CONSTRUCTION, exactly as in
                        // #579: grid.php already coerced an unknown role to no class,
                        // and it still does. That allowlist is not redundant with this
                        // gate — a raw database write or a restore_composition (#233,
                        // which restores verbatim and never blocks) can still put an
                        // arbitrary string in front of it, and it is what keeps that
                        // string out of a class attribute. What changes is only that
                        // the WRITE path now says so instead of reporting success.
                        //
                        // Same accepted cost as the rules above: whole-composition
                        // validation means a stored out-of-set role blocks edits to
                        // unrelated bands on that page until the item is repaired
                        // through the ordinary authoring surface. That is the v1.13.0
                        // no-compat posture, not a regression — no alias, no coercion,
                        // no migration.
                        if (array_key_exists($field_name, $entry)
                            && !_pp_schema_enum_value_is_valid($field_def, $entry[$field_name])
                        ) {
                            if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                $errors[] = _pp_composition_item_error($i,
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s field "%s" must be one of: %s; got %s.',
                                        $name,
                                        $prop_name,
                                        _pp_item_index_label($entry_index, $entries),
                                        $field_name,
                                        implode(', ', $field_def['values']),
                                        _pp_schema_value_for_message($entry[$field_name])
                                    )
                                );
                            }
                            // Same depth accounting as RULE 3 above — a bare `continue`
                            // is the next FIELD of this entry (#621).
                            continue;
                        }
                        if ($field_type === 'array'
                            && ($field_def['item_type'] ?? null) === 'string'
                            && array_key_exists($field_name, $entry)
                            && is_array($entry[$field_name])
                        ) {
                            foreach ($entry[$field_name] as $bullet) {
                                if (!is_string($bullet)) {
                                    // The message names the FIELD, not the bullet, so
                                    // the field is the location: two bad bullets in one
                                    // list are one finding (#621).
                                    if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field_name)) {
                                        $errors[] = _pp_composition_item_error($i,
                                            'invalid_prop_value',
                                            sprintf(
                                                'Component "%s" prop "%s" item %s field "%s" items must be strings; got %s.',
                                                $name,
                                                $prop_name,
                                                _pp_item_index_label($entry_index, $entries),
                                                $field_name,
                                                gettype($bullet)
                                            )
                                        );
                                    }
                                    continue 2; // next FIELD of this entry
                                }
                            }
                        }
                    }
                    // RULE 5 — the entry's own UNDECLARED fields (#643).
                    // Rules 1-4 and 6 walk the DECLARATIONS, so they can only ever judge a field
                    // the schema names. Nothing walked the other direction, and the gap was
                    // the nearest neighbour to the defect #614 closed: #614 stops
                    // `image_id: {attachment_id: 42}` from resolving the wrong attachment,
                    // but `imageId: 42` — camelCase, the shape a model reaching for JS
                    // conventions produces — still validated, persisted, returned ok:true
                    // and rendered nothing. One keystroke away, same trust class.
                    //
                    // This is the #147 TOP-LEVEL gate's semantics, one level down, in the
                    // same shared validator (no second surface): an undeclared key is
                    // rejected with `unknown_prop` and the message names what IS available.
                    // Its rationale transfers verbatim — "an unknown key persists, the
                    // action reports ok:true, and the renderer silently ignores it".
                    //
                    // TWO GUARDS, both load-bearing:
                    //
                    //   $declared_fields === []   this prop's `items` is a VALUE grammar,
                    //                             not a field map, so there is no field
                    //                             contract for an entry to be measured
                    //                             against and nothing here can be
                    //                             "undeclared". NO SHIPPED SCHEMA REACHES
                    //                             THIS BRANCH — the props a reader will
                    //                             think of are excluded earlier and for a
                    //                             different reason: `section.body_items`,
                    //                             `table.headers` and `table.rows` declare
                    //                             no `items` key at all and are dropped by
                    //                             the `!isset($prop_def['items'])` guard at
                    //                             the top of this block, and
                    //                             `grid.items[].bullets` is a nested FIELD
                    //                             that is never bound to $prop_def. The
                    //                             guard is forward-looking, and the hoist
                    //                             comment above says what it is looking at.
                    //
                    //   a populated LIST entry    a SHAPE defect, and the rule that owns an
                    //                             entry's shape is `item_type: "object"`
                    //                             above — which claims the entry, so this
                    //                             pass never sees one on an annotated prop.
                    //                             `section.panel_items` is deliberately
                    //                             UNannotated (it accepts mixed
                    //                             string+object entries), so a list entry
                    //                             there is owned by nothing and does reach
                    //                             here: without this guard it would report
                    //                             `has no field "0"` and `has no field "1"`
                    //                             — two findings naming positions instead
                    //                             of the real defect, the exact
                    //                             misleading-repair-loop class #621
                    //                             documents at the entry-shape guard above.
                    //                             _pp_entry_is_object_shape() is SHARED with
                    //                             that rule, so "is this an object?" has one
                    //                             definition rather than two copies.
                    //
                    // REFLECTION IS BOUNDED ON BOTH SIDES SINCE #647/#649. The KEY is
                    // caller/stored data, so it goes through the bounded renderer #622/#633
                    // built for exactly that (control/format characters stripped, 64-char
                    // cap, `(unprintable key)` for a non-UTF-8 key). This rule was STRICTER
                    // THAN #147's for two releases — the top-level gate echoed its
                    // `$prop_name` RAW — and the asymmetry was recorded here as "a known gap
                    // in the WIDER surface, not a standard to level down to, so harmonizing
                    // the two means bounding #147, never unbounding this". #647 harmonized it
                    // in that direction: the top-level gate now calls the SAME renderer. The
                    // AVAILABLE list is schema-derived and is emitted plainly, matching the
                    // top-level gate; it needs no `(none)` fallback because the empty case
                    // is the first guard above. The LOCATOR is _pp_item_index_label(), which
                    // #649 routed through _pp_schema_value_for_message().
                    //
                    // SO THIS MESSAGE NOW CARRIES ONE CHARACTER-CLASS RULE AND TWO CAPS, which
                    // is worth stating precisely rather than rounding off to "one rule". The
                    // KEY and the LOCATOR both strip exactly \p{Cc}\p{Cf}, so no span this
                    // message reflects can carry a control or format character. They do NOT
                    // share a budget or a degenerate spelling: a key is cut at
                    // PP_UNDECLARED_KEY_MAX_LENGTH (64) and reads `(unprintable key)` when it
                    // cleans to nothing, while a locator is cut at
                    // PP_REFLECTED_VALUE_MAX_LENGTH (100), quotes itself, and REPAIRS invalid
                    // UTF-8 rather than degrading. The split is deliberate — a key is an
                    // identifier, a locator stands in for arbitrary content — and each half
                    // keeps the answer the sibling rule that already owned it gives, which is
                    // what stops this message inventing a third one.
                    //
                    // ORDER IS DELIBERATE. It runs AFTER the declared-field loop so a
                    // missing required field still wins first-error document order, exactly
                    // as the top-level gate runs after the required-prop loop. Since #621
                    // the claim set makes that ordering load-bearing: moving this pass
                    // ABOVE the loop would change which message an operator sees, and is a
                    // behavior change, not a refactor.
                    //
                    // Depth accounting, numbered from each `continue`'s own innermost
                    // enclosing loop, the way RULE 3 does it above. This key loop and the
                    // field loop are SIBLINGS — both direct children of `foreach ($entries)`:
                    //   both guards below   bare `continue` in foreach ($entries …) => next ENTRY
                    //   the report loop     bare `continue` in foreach ($entry …)   => next KEY
                    // Reports are exhaustive per key, one finding per undeclared key (#621).
                    //
                    // Same accepted cost as every rule in this block, stated precisely:
                    // the THREE actions that validate the whole composition — create_page,
                    // update_composition, update_component — refuse a page carrying a stored
                    // undeclared key, including edits to bands that have nothing to do with
                    // it, until the item is repaired through the ordinary authoring surface.
                    // add_component validates only the item it adds, and remove_component /
                    // reorder_components / style_component validate no props, so those four
                    // still succeed on a stale page (AI_CONTEXT.md documents that unevenness). That is the v1.13.0 no-compat posture, not a
                    // regression — `wp pp check page` / `wp pp validate site` still REPORT
                    // it (#622) and restore_composition still restores and reports rather
                    // than blocking (#233).
                    // Guard order is cost-ordered: the SCHEMA-side test is a comparison
                    // against an already-built set, while the ENTRY-side one inspects the
                    // entry container through pp_is_list(). Since #715 that inspection is
                    // O(1) on the packed arrays json_decode produces rather than the two
                    // temporary arrays the old shim allocated, so the gap is narrower than
                    // it was — but the schema-side question is still the free one, and
                    // asking it first means a prop with no field map never pays for the
                    // shape test at all, on every entry of every band of every write and
                    // every `wp pp validate site` traversal.
                    if ($declared_fields === []) {
                        continue; // not a field map — nothing here can be "undeclared"
                    }
                    if (!_pp_entry_is_object_shape($entry)) {
                        continue; // a populated list is a SHAPE defect, not an unknown key
                    }
                    // THE TWO ENGINE-OWNED KEYS (BUILD-SPEC Addendum B), computed
                    // once per prop rather than per entry.
                    //
                    // NOT DECLARED AS FIELDS, DELIBERATELY. `id` and `udc` on an
                    // entry are the same kind of thing as `id` and `udc` on a BAND:
                    // engine-owned keys that sit beside the author's content and are
                    // not props. Declaring them in `props.items.items` would make
                    // them content — they would appear in the AI catalog's entry-field
                    // line, inviting a model to mint its own ids, which is exactly
                    // what B2's mint-on-write rule exists to keep the engine's job.
                    //
                    // The capability still reaches the model, but through the
                    // `item_roles` declaration rather than through a field list, for
                    // the reason T10 settled: declaration is the source of truth, and
                    // a roster derived from it cannot drift.
                    //
                    // GATED ON THE COMPONENT DECLARING ITEM GRAIN, so a component that
                    // declares none rejects both keys exactly as it does today.
                    $engine_owned = $item_grain_prop === $prop_name
                        ? [PP_UDC_ITEM_ID_KEY => true, PP_UDC_ITEM_MAP_KEY => true]
                        : [];
                    // A RETIRED ITEM FIELD GETS A ROUTE TOO (#1101), the band-prop rule
                    // one level down. `retired_props` addresses an item-level key as
                    // `<prop>[].<field>` — grid declares `items[].style` and
                    // `items[].text_role`, the first component whose retirements reach
                    // inside an entry — and without this lookup both fell through to the
                    // typo gate below and answered a deliberate v1 authoring shape with a
                    // list of live field names.
                    //
                    // THAT IS THE ONE THING §3.1 SAYS A REFUSAL MUST NOT DO: "every
                    // retirement lands in `retired_props` naming the v2 surface that
                    // replaced it, so a refusal offers a route instead of a list of live
                    // prop names". It also made the model-facing prompt untrue in the same
                    // change that wrote it — lib/ai-context.php tells the model a stale
                    // `style` or `text_role` on ONE card "refuses the band like any other
                    // retired key", and it did not.
                    //
                    // Measured before the fix, through the real write path:
                    //   items[].style     -> unknown_prop, "has no field \"style\""
                    //   items[].text_role -> unknown_prop, "has no field \"text_role\""
                    // Both are keys an author had on every pre-rebuild page, and the
                    // replacement for one of them is the headline capability of this task.
                    //
                    // SAME CODE, SAME CURE SENTENCE as the band-prop arm, because it is
                    // the same fact at a different depth: a caller that cannot tell
                    // "you typo'd" from "this moved, and here is where" has to
                    // string-match prose to know whether to re-read the schema or rewrite
                    // the value.
                    // Looked up here rather than reused from the band-prop arm: that
                    // local is bound in a different branch of this function, and a
                    // by-accident reach across it would break the moment either moved.
                    $retired_fields = function_exists('pp_component_retired_props')
                        ? pp_component_retired_props($name)
                        : [];
                    foreach ($entry as $entry_key => $ignored) {
                        if (array_key_exists($entry_key, $declared_fields)
                            || isset($engine_owned[$entry_key])) {
                            continue;
                        }
                        $retired_field = $retired_fields[$prop_name . '[].' . (string) $entry_key] ?? null;
                        if ($retired_field !== null
                            && _pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $entry_key)) {
                            $errors[] = _pp_composition_item_error($i,
                                'retired_prop',
                                sprintf(
                                    'Component "%s" prop "%s" item %s no longer has a field "%s": it was '
                                    . 'retired when %s moved to the v2 styling system. The replacement is '
                                    . '%s. To clear the stored key, re-send this item without it — '
                                    . 'update_component replaces the "%s" array, and every other field on '
                                    . 'the entry is kept as you send it. Available fields: %s',
                                    $name,
                                    $prop_name,
                                    _pp_item_index_label($entry_index, $entries),
                                    _pp_render_undeclared_prop_keys([(string) $entry_key]),
                                    $name,
                                    $retired_field,
                                    $prop_name,
                                    $available_fields
                                )
                            );
                            continue;
                        }
                        if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $entry_key)) {
                            $errors[] = _pp_composition_item_error($i,
                                'unknown_prop',
                                sprintf(
                                    'Component "%s" prop "%s" item %s has no field "%s". Available fields: %s',
                                    $name,
                                    $prop_name,
                                    _pp_item_index_label($entry_index, $entries),
                                    _pp_render_undeclared_prop_keys([(string) $entry_key]),
                                    $available_fields
                                )
                            );
                        }
                    }
                }
            }
        }

        // Link-URL format family (issue 507). A prop MAY declare `format: "link_url"`
        // (the #154 media-URL annotation pattern, applied to the destination-URL
        // props: cta.button_url/button2_url, hero.button_url/button2_url, section.panel_cta_url,
        // grid.items[].link_url). The renderer runs esc_url() on these, which
        // SILENTLY neuters a disallowed-protocol value (javascript:, data:, ...) into
        // an empty href — a dead button — while still reporting ok:true. This rejects
        // that class at write time so an accepted write renders as authored. The bar
        // is "what survives esc_url renders as authored": intentional non-http values
        // that render fine (#anchor, /relative, //protocol-relative, mailto:, tel:,
        // and every other wp_allowed_protocols scheme) stay accepted; only a value
        // carrying a scheme OUTSIDE the allowed set is rejected. Schema-driven and
        // walked at the same two depths as #154 (top-level props + one items[] level);
        // restore_composition (#233) reports it via _pp_composition_findings() but
        // never blocks on it, same as every rule here.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (!is_array($prop_def)) {
                    continue;
                }
                // Top-level link_url prop.
                if (($prop_def['format'] ?? null) === 'link_url'
                    && array_key_exists($prop_name, $item['props'])
                    && !_pp_link_url_is_valid($item['props'][$prop_name])
                ) {
                    if (_pp_claim_item_finding($sink, 'prop', $prop_name)) {
                        $errors[] = _pp_composition_item_error($i,
                            'invalid_prop_value',
                            // No items[] key on this arm, so no container to judge one against.
                            _pp_link_url_error_message($name, $prop_name, null, null, $item['props'][$prop_name], null)
                        );
                    }
                    continue;
                }
                // Nested link_url on the items[] of an array prop (grid.items[].link_url).
                if (($prop_def['type'] ?? null) === 'array'
                    && isset($prop_def['items'])
                    && is_array($prop_def['items'])
                ) {
                    $link_fields = [];
                    foreach ($prop_def['items'] as $item_prop_name => $item_prop_def) {
                        if (is_array($item_prop_def) && ($item_prop_def['format'] ?? null) === 'link_url') {
                            $link_fields[] = $item_prop_name;
                        }
                    }
                    if ($link_fields !== [] && is_array($item['props'][$prop_name] ?? null)) {
                        foreach ($item['props'][$prop_name] as $entry_index => $entry) {
                            if (!is_array($entry)) {
                                continue; // non-object entries are caught by the type pass above
                            }
                            foreach ($link_fields as $field) {
                                if (array_key_exists($field, $entry) && !_pp_link_url_is_valid($entry[$field])) {
                                    // The nested arm already advanced to the next PROP
                                    // rather than ending the item (a `continue 3` from
                                    // three loops in), while the top-level arm above it
                                    // was a `continue 2` to the next item — so a dead card
                                    // link did not stop a LATER array prop's link check.
                                    // That was the one place the old "one error per item"
                                    // docblock was already untrue. It now advances to the
                                    // next FIELD, so two dead links on one card, and dead
                                    // links on different cards, are all named (#621).
                                    if (_pp_claim_item_finding($sink, 'prop', $prop_name, $entry_index, $field)) {
                                        $errors[] = _pp_composition_item_error($i,
                                            'invalid_prop_value',
                                            _pp_link_url_error_message($name, $prop_name, $entry_index, $field, $entry[$field], $item['props'][$prop_name])
                                        );
                                    }
                                    continue;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Validate optional style key against schema-declared style slots.
        $available_slots = $schema['styling']['style_slots'] ?? [];
        if (isset($item['style']) && is_array($item['style']) && !empty($item['style'])) {
            $style_error = _pp_validate_style_slot_map($item['style'], $available_slots, $name, null);
            if (is_wp_error($style_error)) {
                // Built by the shared slot engine, which has no view of the composition
                // offset — restamp it here so this error carries the same locator as
                // every sibling in this loop (#622).
                //
                // ONE FINDING PER STYLE MAP, and that is the residual granularity limit
                // of #621: _pp_validate_style_slot_map() returns the FIRST bad slot in the
                // map, so a band declaring two dead slots reports one. Making that engine
                // collect-all reaches the style_component write path, which wants one
                // actionable message, so it is a separate change. What #621 fixes here is
                // the masking: this rule used to be unreachable for any item that tripped
                // an earlier one, which is the case the issue reports.
                if (_pp_claim_item_finding($sink, 'style')) {
                    $errors[] = _pp_composition_item_error($i, $style_error->get_error_code(), $style_error->get_error_message());
                }
            }
        }

        // Validate optional PER-ITEM style overrides (issue 306). A prop declared
        // as type:array whose item sub-schema declares a `style` field (today: the
        // grid's `items`) may carry a per-element `style` map. Each element's style
        // is validated against the SAME component style_slots through the SAME shared
        // engine as grid-level styles — no second validator, no new slot grammar.
        // Unknown item-level slot names and invalid values are rejected exactly like
        // grid-level ones. restore_composition (issue 233) reports this via
        // _pp_composition_findings() but never blocks on it, same as every rule here.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                $accepts_item_style = ($prop_def['type'] ?? null) === 'array'
                    && isset($prop_def['items']['style']);
                if (!$accepts_item_style) {
                    continue;
                }
                $prop_value = $item['props'][$prop_name] ?? null;
                if (!is_array($prop_value)) {
                    continue;
                }
                foreach ($prop_value as $elem_index => $element) {
                    if (!is_array($element)
                        || !isset($element['style'])
                        || !is_array($element['style'])
                        || empty($element['style'])
                    ) {
                        continue;
                    }
                    $style_error = _pp_validate_style_slot_map(
                        $element['style'],
                        $available_slots,
                        $name,
                        $elem_index,
                        $prop_value
                    );
                    if (is_wp_error($style_error)) {
                        // Same restamp as the component-level style map above (#622):
                        // the shared slot engine names the card, this adds the band.
                        // Per CARD since #621 — the message names the card, so a grid
                        // with dead slots on cards 0 and 2 reports both.
                        //
                        // The ROLE segment is `item-style`, not `prop`, because `style` is
                        // a real declared items[] FIELD (grid.items, section.panel_items)
                        // and the nested-field rules claim `prop / <prop> / <entry> /
                        // <field>`. THAT HAZARD IS NO LONGER HYPOTHETICAL: since #744,
                        // RULE 6 above claims exactly `prop / <prop> / <entry> / style`
                        // when a card's `style` is a SCALAR. Sharing one namespace would
                        // have let that rejection swallow this card's slot finding — a
                        // suppressed diagnostic is the one failure mode the claim set must
                        // never cause — and the separate role is what keeps a scalar
                        // `style` on card 0 and a dead slot on card 1 both reported. Role
                        // segments are rule-owned literals, so they cannot collide with an
                        // authored name.
                        if (_pp_claim_item_finding($sink, 'item-style', $prop_name, $elem_index)) {
                            $errors[] = _pp_composition_item_error($i, $style_error->get_error_code(), $style_error->get_error_message());
                        }
                        continue;
                    }
                }
            }
        }

        // ── v2: the band identity and the `udc` map ─────────────────────────
        //
        // Appended AFTER every existing rule ON PURPOSE. Rule order is
        // load-bearing here (see _pp_finding_location): the first rule in
        // traversal order wins a contested location, so adding these at the end
        // keeps every pre-existing errors[0] byte-identical — which is exactly
        // what testFirstCollectedErrorIsExactlyWhatValidateReturns() and
        // testTheWritePathReturnsTheSameErrorItWouldHaveWithoutABudget() pin.
        //
        // AND THIS GATE HAS TO EXIST AT ALL, which is easy to miss: nothing in
        // this validator ever iterated an item's own top-level keys, so before
        // v2 an item carrying `udc` was accepted, stored and ignored. A
        // misspelled role would have returned ok:true and rendered nothing —
        // the reported-success-without-effect class the #147 and #643 gates
        // close one level down, arriving one level up.
        if (array_key_exists('id', $item)) {
            $band_id = $item['id'];
            if (!is_scalar($band_id) || !pp_udc_valid_band_id((string) $band_id)) {
                if (_pp_claim_item_finding($sink, 'udc', 'id')) {
                    $errors[] = _pp_composition_item_error($i, 'invalid_composition', sprintf(
                        'Component "%s" band id must be 1-64 characters of letters, digits, hyphen or underscore; got %s. '
                        . 'The id scopes this band\'s styling rules, so the character set is what keeps it a selector and nothing else.',
                        $name,
                        _pp_schema_value_for_message($band_id)
                    ));
                    continue;
                }
            }
        }

        if (array_key_exists('udc', $item)) {
            // ITEM MAPS ARE PASSED IN so the band's own reserved-name gate can
            // recognise a token the ENGINE minted for an item. Without them
            // _pp_udc_name_is_the_engines_own_mint() searches the band map
            // alone, answers false for a name it wrote itself, and the band is
            // refused permanently. See that function's docblock.
            $udc_error = pp_udc_validate_map($item['udc'], $name, pp_udc_item_maps($item));
            if ($udc_error !== null) {
                if (_pp_claim_item_finding($sink, 'udc')) {
                    $errors[] = _pp_composition_item_error($i, $udc_error->get_error_code(), $udc_error->get_error_message());
                    continue;
                }
            }
        }

        // ── ITEM-GRAIN MAPS (BUILD-SPEC Addendum B) ─────────────────────────
        //
        // VALIDATED HERE RATHER THAN BESIDE THE BAND MAP ABOVE, because an item
        // map does not live beside `udc` — it lives INSIDE `props`, which is
        // what makes it reachable by `update_component` and therefore what
        // makes validating it non-optional. A band map is a sibling of `props`
        // and no action can write one at all (#1088); an item map rides in on
        // an ordinary prop patch, so the surface that accepts it has to be the
        // surface that checks it.
        //
        // A DUPLICATE ID INSIDE ONE BAND REFUSES, mirroring
        // `duplicate_component_id` one level down. Two entries sharing an id
        // share a selector, so one card's design paints on the other — the same
        // cross-apply failure, and the reason B2 scopes uniqueness to the band
        // rather than leaving it to chance.
        $item_declaration = pp_udc_item_roles($name);
        if ($item_declaration !== null) {
            $entries = $item['props'][$item_declaration['prop']] ?? null;
            if (is_array($entries)) {
                $band_tokens = [];
                if (isset($item['udc']['_tokens']) && is_array($item['udc']['_tokens'])) {
                    $band_tokens = $item['udc']['_tokens'];
                }
                $seen_item_ids = [];
                // THE LOCATOR ROUTES THROUGH THE SHARED RENDERER, and each
                // message spells `item %s` itself rather than interpolating a
                // pre-built string. Both halves matter: the renderer is what
                // makes an object-keyed container report its real key instead of
                // `item 0` (#634/#652), and the literal fragment is what keeps
                // every depth naming an entry in one set of words. The pairing
                // is drift-guarded in DiagnosticReachTest, which is how the
                // first cut of this block — a precomputed `$where` that
                // sidestepped both — was caught.
                foreach ($entries as $k => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    if (array_key_exists(PP_UDC_ITEM_ID_KEY, $entry)) {
                        $raw_id = $entry[PP_UDC_ITEM_ID_KEY];
                        $id     = is_scalar($raw_id) ? (string) $raw_id : '';
                        if ($id === '' || !pp_udc_valid_item_id($id)) {
                            if (_pp_claim_item_finding($sink, 'udc')) {
                                $errors[] = _pp_composition_item_error($i, 'invalid_prop_value', sprintf(
                                    'Component "%s" item %s: "%s" must be an id this engine minted — "it-" '
                                    . 'followed by eight lowercase hex digits; got %s. Leave it out and one '
                                    . 'is minted for you.',
                                    $name,
                                    _pp_item_index_label($k, $entries),
                                    PP_UDC_ITEM_ID_KEY,
                                    _pp_schema_value_for_message($raw_id)
                                ));
                            }
                            continue;
                        }
                        if (isset($seen_item_ids[$id])) {
                            if (_pp_claim_item_finding($sink, 'udc')) {
                                $errors[] = _pp_composition_item_error($i, 'duplicate_component_id', sprintf(
                                    'Component "%s": item %s and item %s both claim the id "%s" in "%s". '
                                    . 'An item id scopes that item\'s styling rules, so sharing one would '
                                    . 'paint each design on both.',
                                    $name,
                                    _pp_item_index_label($seen_item_ids[$id], $entries),
                                    _pp_item_index_label($k, $entries),
                                    $id,
                                    $item_declaration['prop']
                                ));
                            }
                            continue;
                        }
                        $seen_item_ids[$id] = $k;
                    }

                    if (array_key_exists(PP_UDC_ITEM_MAP_KEY, $entry)) {
                        $item_error = pp_udc_validate_item_map(
                            $entry[PP_UDC_ITEM_MAP_KEY],
                            $name,
                            $item_declaration,
                            $band_tokens,
                            sprintf('item %s', _pp_item_index_label($k, $entries))
                        );
                        if ($item_error !== null && _pp_claim_item_finding($sink, 'udc')) {
                            $errors[] = _pp_composition_item_error(
                                $i, $item_error->get_error_code(), $item_error->get_error_message()
                            );
                        }
                    }
                }
            }
        }
    }

    // Duplicate BAND ids (v2, BUILD-SPEC §3.1/§3.4). A band id scopes that
    // band's emitted CSS block, so two bands sharing one id would paint each
    // other's design — and, unlike the props.id collision below, no targeting
    // command is involved at all. Its own code: overloading
    // `duplicate_component_id`, whose message explains itself in terms of
    // update/remove/style targeting, would make the diagnostic name the wrong
    // namespace. Carries no `index` — it belongs to no single band.
    foreach (($sink['budget'] !== null && $errors !== []) ? [] : _pp_find_duplicate_band_ids($items) as $dupe) {
        $errors[] = new WP_Error(
            'duplicate_band_id',
            sprintf(
                // WHY THIS ONE STILL BLOCKS A BAND-SCOPED WRITE (#1007). Since the
                // per-item rules narrowed to the targeted band, a reader who has just
                // learned that an unrelated band's problem no longer blocks them will
                // reasonably ask why this one does. The answer is in the message, because
                // it is the difference between a per-item defect and a page-level one.
                'Duplicate band id "%s" on items %s. Band ids must be unique within a composition because each one scopes that band\'s styling rules. This refuses an edit to ANY band, including bands that are not colliding, because a write re-serializes the whole composition and would store the collision again — repair the ids through update_composition.',
                $dupe['id'],
                implode(', ', array_map(
                    static fn ($key) => _pp_item_index_label($key, $items),
                    $dupe['indices']
                ))
            )
        );
    }

    // Duplicate authored component ids (issue 238). Two components sharing a
    // non-empty props.id make id-based targeting (update_component /
    // remove_component / style_component) silently resolve to the first match in
    // pp_resolve_component_target(). Reject at write time so wrong-targetable state
    // is never persisted; the resolver stays defensive for state written through
    // raw, non-validating paths. Cross-item, so it runs as a pass after the
    // per-item loop above — appending here keeps pp_validate_composition()'s
    // first-error-wins document order (a per-item error on an earlier item still
    // wins). The shared detector also backs the advisory smell, so
    // _pp_composition_findings() (check page / validate site / restore) reports the
    // same collision.
    //
    // Skipped once the budget is spent (#621): this pass appends LAST, so when any
    // per-item error exists it can never be errors[0], and the only caller that sets a
    // budget reads nothing else. When no per-item error was found it still runs, because
    // then a collision IS errors[0].
    foreach (($sink['budget'] !== null && $errors !== []) ? [] : pp_find_duplicate_component_ids($items) as $dupe) {
        $errors[] = new WP_Error(
            'duplicate_component_id',
            sprintf(
                // Same clause as duplicate_band_id above, and for the same reason: a
                // cross-item defect is a property of the PAGE, so narrowing the per-item
                // rules to the targeted band (#1007) did not and could not narrow this.
                'Duplicate component id "%s" on items %s. Component ids must be unique within a composition so update/remove/style can target one component. This refuses an edit to ANY band, including bands that are not colliding, because a write re-serializes the whole composition and would store the collision again — repair the ids through update_composition.',
                $dupe['id'],
                // The colliding COMPOSITION keys, rendered through the one shared renderer
                // like every other locator (#650/#652, per the #687 addendum). These are
                // foreach keys from pp_find_duplicate_component_ids(), so on an object-shaped
                // composition they were bare numbers that read as positions — the same
                // ambiguity, in the one message that names several bands at once. This error
                // still carries NO `index`: it belongs to no single band, and the honest-
                // locator contract is unchanged by naming its keys more precisely.
                implode(', ', array_map(
                    static fn ($key) => _pp_item_index_label($key, $items),
                    $dupe['indices']
                ))
            )
        );
    }

    return $errors;
}

/**
 * Names the offending BAND in a write-path rejection message (#642).
 *
 * Every rule inside pp_validate_composition_errors() names the component TYPE
 * ("Component \"logos\" prop ..."), never WHICH band on the page it is. Because the
 * composition-mutating actions all validated the WHOLE composition, a page with two
 * `logos` bands that both store a bad value produced two BYTE-IDENTICAL rejections: an
 * agent that "fixed" its own payload got the same string back, forever, because the
 * blocking value sat in a band it never touched. Since #1007 `update_component` judges
 * only the band it targets — so it reaches this renderer with a band the caller DID name,
 * and pp_validate_composition_band() is its entry point — while `create_page` and
 * `update_composition` still judge the whole page and still need the locator most. The offset was computed all along — #622 stamps
 * it as WP_Error data — and then discarded one layer up.
 *
 * WHY THIS RENDERS AT THE WRITE BOUNDARY RATHER THAN AT MESSAGE-BUILD TIME. The same
 * WP_Error message is read by two surfaces with different locator conventions. The
 * REPORTING surfaces (`wp pp check page`, `validate site`, restore/rollback findings)
 * carry the offset as a SEPARATE field and render it themselves — `_pp_cli_finding_line()`
 * prints "[type] index 1: <message>" — so naming the band inside the message too would
 * print the locator twice there. The WRITE surface has no second field to render into a
 * human message. So the band is added HERE, on the one path that needs it, and the
 * findings vocabulary #687 ratified stays where it was built — one level down, in
 * _pp_band_index_label() and _pp_item_index_label(). Since #650/#652 this function READS
 * that vocabulary rather than re-spelling it: the no-stutter gate below compares against
 * _pp_band_index_label()'s own output, so an object-shaped composition whose band label is
 * `Item key "1"` is recognised as already-banded exactly like a list's `Item 1`.
 *
 * THE MESSAGE IS REWRITTEN, NEVER RE-DERIVED. The component name comes from the very
 * item the stamped offset points at (the foreach key IS the array key, so this reads the
 * band that failed, sparse and out-of-order keys included), and the leading label is
 * swapped only when it matches that name EXACTLY. A rule whose message does not open
 * with the label is prefixed instead, so a reworded rule can lose its parenthesised form
 * but never its band. testWriteRejectionsNameTheirBand() in tests/WriteRejectionLocatorTest.php
 * walks one case per rule family and is the tripwire for that coupling: reword a message
 * and the test tells you the locator moved.
 *
 *   message opens with                     rendered as
 *   Component "logos" prop "items" ...     Component 1 ("logos") prop "items" ...
 *   Item 1 is missing the "component" ...  (unchanged — it already names the band)
 *   Unknown component: "nope".             Component 1: Unknown component: "nope".
 *   "nav" is site chrome ...               Component 1: "nav" is site chrome ...
 *
 * The two prefixed families already name the component in their own text, so the
 * parenthesised form would stutter ("Component 1 (\"nav\"): \"nav\" is site chrome");
 * they get the offset, which is the part that was missing.
 *
 * An error with no offset (duplicate_component_id, which belongs to no single band and
 * already names every colliding index) is returned untouched — the honest-locator
 * contract: a locator is real or absent, never fabricated.
 *
 * @param  WP_Error $error  The first-error-wins rejection.
 * @param  array    $items  The composition it was validated against.
 * @return WP_Error         Same code and data; message names the band when one owns it.
 */
function _pp_band_named_composition_error(WP_Error $error, array $items): WP_Error {
    $index = pp_composition_error_index($error);
    if ($index === null) {
        return $error;
    }

    $message = $error->get_error_message();
    $name    = (isset($items[$index]['component']) && is_scalar($items[$index]['component']))
        ? (string) $items[$index]['component']
        : null;

    // Cheap gate before the copy. An unvalidated composition can carry a megabyte-long
    // `component` value, and this path exists to survive exactly that data, so the
    // families whose message cannot start with the label (site chrome, unknown
    // component) are ruled out on a fixed 11-byte prefix rather than by building a full
    // copy of the name to lose a comparison with.
    $label = ($name !== null && str_starts_with($message, 'Component "'))
        ? sprintf('Component "%s"', $name)
        : null;
    // ONE renderer for the key form, at every depth and in every noun (#650/#652, per the
    // #687 addendum). This prefix used to spell `%d` independently, so an object-shaped
    // composition printed `Component 1` for the band stored under KEY 1 — which is the FIRST
    // band a reader counts to, not the second. Same lie as the `Item 0` this issue removed,
    // one message family over; naming it `Component key "1"` is what makes the prefix agree
    // with the `index` payload beside it, which is a key lookup and always was.
    //
    // SINCE #724 THIS ALWAYS RENDERS THE LIST FORM in production: the only caller is
    // pp_validate_composition(), which returns early above when `index` is null, and a
    // non-list composition now produces exactly one container error carrying no index —
    // so `$items` reaching this line is always a list. The key branch is kept as the one
    // shared renderer (deleting it would re-split the spelling that #650/#652 unified, and
    // the `items[]` depth still uses it), and is unit-pinned rather than left as prose.
    $locator = _pp_item_index_label($index, $items);
    if ($label !== null && str_starts_with($message, $label)) {
        $message = sprintf('Component %s ("%s")', $locator, $name) . substr($message, strlen($label));
    } elseif (!str_starts_with($message, _pp_band_index_label($index, $items) . ' ')) {
        // Not the structural family, which spells its own band prefix for exactly this
        // offset (a non-int key never reaches here — it stamps no offset at all). The
        // prefix is read from _pp_band_index_label(), the same function that WRITES it
        // in pp_validate_composition_errors(), so the two cannot drift into disagreeing
        // about the label's spelling — which is how the fabricated `Item 0` #650 closed
        // survived a rewording once already.
        $message = sprintf('Component %s: %s', $locator, $message);
    }

    // A NEW WP_Error, because WP_Error has no message setter. The original DATA rides
    // along untouched rather than being re-stamped as ['index' => N]: a producer may
    // have attached context of its own (the rejected-slot context #626 stamps is the
    // live example), and a rendering step has no business dropping it.
    return new WP_Error($error->get_error_code(), $message, $error->get_error_data());
}

/**
 * Drops the composition offset from a rejection built against a SYNTHETIC array (#642).
 *
 * add_component validates `[$new_item]` — a one-item array that is not the page's
 * composition — so the offset 0 inside it names the caller's own payload, not band 0 of
 * the page. Reporting it would send an agent to repair an unrelated stored band, the
 * fabricated-locator failure this issue exists to end. The message is unchanged: it
 * describes the payload the caller just submitted, which needs no band to be actionable.
 *
 * @param  WP_Error $error
 * @return WP_Error  Same code and message, no offset.
 */
function _pp_unlocated_composition_error(WP_Error $error): WP_Error {
    if (pp_composition_error_index($error) === null) {
        return $error;
    }
    // Only the offset is cleared. Everything else a producer stamped rides along, for
    // the same reason the band renderer above carries data forward.
    $data          = $error->get_error_data();
    $data          = is_array($data) ? $data : [];
    $data['index'] = null;

    return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
}

/**
 * Validates a decoded composition array against the component registry.
 *
 * First-error-wins: returns the first violation in document order, exactly as it always
 * has. Every write-time caller (create_page, update_composition, add_component,
 * update_component, the editor save) depends on this shape. Use
 * pp_validate_composition_errors() when you need the complete set instead.
 *
 * The budget of 1 is why this path stayed cheap when findings became exhaustive (#621).
 * The engine walks the same rules either way — that traversal is what a VALID composition
 * has always paid — but it stops BUILDING findings after the first, so a caller that
 * posts 200KB of malformed items cannot make a write allocate hundreds of megabytes of
 * error objects this function then discards. The returned error is byte-identical with
 * or without the budget: rule order is untouched and a budget can only suppress findings
 * AFTER the first one.
 *
 * The returned MESSAGE names the band (#642) — see _pp_band_named_composition_error().
 * Which rule fires, with which code, on which value is untouched: this function accepts
 * and rejects exactly what it always did, and only the wording of a rejection changed.
 *
 * Validate ONE item that is not part of a page's composition with
 * pp_validate_composition_item() instead — same rules, no band locator.
 *
 * @param  array            $items  Decoded composition array.
 * @return true|WP_Error
 */
function pp_validate_composition(array $items) {
    $errors = pp_validate_composition_errors($items, 1);

    return $errors === []
        ? true
        : _pp_band_named_composition_error($errors[0], $items);
}

/**
 * Validates a composition for a write that only touches ONE band (#1007).
 *
 * The third sibling of pp_validate_composition() and pp_validate_composition_item(),
 * for the case neither covered: the band IS part of the page, so its locator is real and
 * must be kept, but the write does not touch the other bands and has no business
 * refusing on their behalf.
 *
 * WHAT IT REFUSES, AND WHY THAT SET IS THE HONEST ONE:
 *
 *   - the targeted band's own problems, because the caller is writing that band;
 *   - every CROSS-ITEM problem, because those are properties of the page and the writer
 *     re-serializes the whole composition on a single-band update — accepting a duplicate
 *     id here would re-persist it, so it is this write's business after all;
 *   - nothing else. A retired prop on an untouched band no longer refuses an edit to a
 *     band beside it, which is the page-wide lockout #1007 filed.
 *
 * THE PAGE'S OTHER PROBLEMS ARE STILL REPORTED, on the accepted envelope, at severity
 * `error`, by the report pp_execute_action() already attaches to every accepted
 * composition write. Narrowing the refusal does not narrow the disclosure — this is the
 * "refuse narrowly, advise page-wide" contract #233 established for restore_composition
 * and #687 widened to every accepted write, applied to the one action still outside it.
 *
 * @param  array $items  The full composition, with the caller's change already merged.
 * @param  int   $index  Offset of the band this write touches.
 * @return true|WP_Error
 */
function pp_validate_composition_band(array $items, int $index) {
    // THE BUDGET AND THE CROSS-ITEM PASSES INTERACT, and the interaction is benign in
    // exactly one direction, so it is written down rather than rediscovered. The
    // cross-item passes are skipped when the budget is set AND a finding already exists
    // (#621's ratified ordering, unchanged by this function). Under a band scope that
    // means: targeted band dirty -> cross-item skipped, but the write is already being
    // refused by the targeted band's own error, so nothing is persisted; targeted band
    // clean -> no findings yet -> cross-item passes RUN, which is the case that matters
    // and is pinned by testADuplicateComponentIdStillRefusesABandScopedWrite.
    //
    // What it costs: when both are wrong, the message names the targeted band and stays
    // silent about the collision. The operator repairs one, retries, and meets the other.
    // Two round trips, never a silent accept.
    $errors = pp_validate_composition_errors($items, 1, $index);

    return $errors === []
        ? true
        : _pp_band_named_composition_error($errors[0], $items);
}

/**
 * Validates ONE composition item that is not (yet) part of a page (#642).
 *
 * add_component judges only the item it adds, so it wraps that item in a one-element
 * array and runs the shared engine over it — no second validator, same rules. But that
 * array is SYNTHETIC: its offset 0 is not the page's band 0, and reporting it would send
 * an agent to repair a stored band that has nothing to do with the rejection. So the
 * locator is dropped rather than fabricated, and this function says so by name instead
 * of asking every caller of pp_validate_composition() to read a boolean.
 *
 * The message is unchanged either way: it describes the payload the caller just
 * submitted, which needs no band to be actionable.
 *
 * @param  array $item  One composition item, as submitted.
 * @return true|WP_Error
 */
function pp_validate_composition_item(array $item) {
    $result = pp_validate_composition_errors([$item], 1);

    return $result === []
        ? true
        : _pp_unlocated_composition_error($result[0]);
}

// ── Composition Page Discriminator ───────────────────────────────────────────

/**
 * Determines whether a post should use the composition editor.
 *
 * Site-level rule: all standard pages on a PromptingPress site use composition
 * editing by default. The only exception is pages explicitly assigned to a
 * third-party template — those belong to another system and are left alone.
 *
 * The composition.php template is an internal rendering mechanism, not the
 * discriminator. This function is the single gate for all routing decisions
 * and can be updated in one place if the data model changes.
 *
 * @param  int  $post_id
 * @return bool
 */
function pp_is_composition_page(int $post_id): bool {
    if (get_post_type($post_id) !== 'page') {
        return false;
    }
    $template = get_page_template_slug($post_id);
    // A non-empty template that is not composition.php means another system
    // explicitly owns this page. Treat that as an interoperability exception.
    // Empty string, 'default', and 'composition.php' are all PromptingPress pages.
    return $template === '' || $template === 'default' || $template === 'composition.php';
}

// ── Post Meta Registration ───────────────────────────────────────────────────

add_action('init', function () {
    register_post_meta('page', '_pp_composition', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'default'           => '',
        'sanitize_callback' => function ($value) {
            if ($value === '') return '';
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return '';
            }
            return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        },
    ]);
});

// ── Admin Routing ─────────────────────────────────────────────────────────────

/**
 * Intercept new page creation and existing page edits, routing both to the
 * composition editor. This is the entry point for the site-level authoring model.
 *
 * Two cases handled:
 *   post-new.php?post_type=page  — create a draft page and redirect immediately
 *   post.php?action=edit&post=N  — redirect to composition editor for pp pages
 */
add_action('admin_init', function (): void {
    global $pagenow;

    // New page: create an auto-draft, assign composition template, open the
    // editor. Uses 'auto-draft' (not 'draft') so a GET with no subsequent
    // save — back button, prefetch, double-click — leaves WordPress core's
    // own hidden, ~7-day-GC'd placeholder instead of a permanent, visible
    // "(no title)" draft (#121). Promoted to a real 'draft' on first
    // meaningful save — see wp_ajax_pp_save_composition / wp_ajax_pp_save_title.
    if ($pagenow === 'post-new.php' &&
        isset($_GET['post_type']) && $_GET['post_type'] === 'page') {
        if (!current_user_can('edit_pages')) {
            return;
        }
        $post_id = wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'auto-draft',
            'post_title'  => '',
        ]);
        if (!$post_id || is_wp_error($post_id)) {
            return;
        }
        update_post_meta($post_id, '_wp_page_template', 'composition.php');
        wp_safe_redirect(admin_url('admin.php?page=pp-composition&post=' . $post_id));
        exit;
    }

    // Existing page edit: redirect composition pages to the composition editor.
    if ($pagenow === 'post.php' &&
        isset($_GET['action']) && $_GET['action'] === 'edit' &&
        isset($_GET['post'])) {
        $post_id = (int) $_GET['post'];
        if (!$post_id) {
            return;
        }
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!pp_is_composition_page($post_id)) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=pp-composition&post=' . $post_id));
        exit;
    }
});

/**
 * Rewrite edit links for composition pages so that all WP-generated "Edit"
 * URLs — Pages list row actions, admin bar, Gutenberg edit button — point
 * to the composition editor rather than post.php.
 */
add_filter('get_edit_post_link', function ($url, $post_id, $context) {
    if (!$post_id || !pp_is_composition_page((int) $post_id)) {
        return $url;
    }
    return admin_url('admin.php?page=pp-composition&post=' . (int) $post_id);
}, 10, 3);

/**
 * Template normalization — separate concern from routing.
 *
 * Ensures composition pages have the correct rendering template on the
 * front-end. This is a data-hygiene operation: when a page is saved and
 * its template is still unset, it gets composition.php assigned so the
 * front-end renders correctly. Explicit third-party templates are left alone.
 */
add_action('save_post_page', function (int $post_id, WP_Post $post, bool $update): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }
    $template = get_page_template_slug($post_id);
    if ($template === '' || $template === 'default') {
        update_post_meta($post_id, '_wp_page_template', 'composition.php');
    }
}, 10, 3);

// ── AJAX Save ─────────────────────────────────────────────────────────────────

/**
 * The editor's structured rejection payload, built once for the three sinks that ship it.
 *
 * ONE SPELLING OF ONE RULE (#864). The save endpoint and both arms of the publish endpoint
 * all answer a refused write with `{message, code}` — the editor keys on the machine-readable
 * `code` (composition_conflict -> reload prompt) rather than parsing the human message (#13).
 * Wrapping the message at each sink turned one line into four at each of the three, which is
 * the same "two literal copies of one refusal spelling" drift #650/#652 spent an iteration
 * undoing. Written here once instead.
 *
 * THE MESSAGE IS CLEANED, THE CODE IS NOT. `error` is a composed validator message that
 * quotes stored composition data back — a component name, a style slot key — so it is
 * reflected text and gets the one owner at this boundary, exactly as the chat's two payloads
 * have since v1.17.8. `error_code` is a theme-authored literal from _pp_action_error() /
 * _pp_action_validation_error_envelope(); guarding it would claim a doubt that does not
 * exist, and the editor only ever compares it, never renders it. Meaning is unchanged for
 * every well-formed message; only bytes that were never legible are.
 *
 * @param  array $result  A REFUSED action envelope (ok === false).
 * @return array          ['message' => string, 'code' => string].
 */
function _pp_editor_error_payload(array $result): array {
    return [
        'message' => _pp_clean_reflected_text((string) $result['error'], PP_REFLECTED_ERROR_MAX),
        'code'    => $result['error_code'] ?? '',
    ];
}

/**
 * Core logic for the editor-save AJAX handler, extracted from the
 * wp_ajax_pp_save_composition closure so it is directly unit-testable (#864).
 *
 * THE EXTRACTION IS THE POINT, not a tidy-up. add_action() is a no-op in the test
 * bootstrap, so a closure body is unreachable from PHPUnit — which is how this
 * handler's error payload stayed the one editor sink no test could observe. It is the
 * same shape lib/ai-chat.php already uses for its four handlers
 * (_pp_ai_execute_response() and neighbours), and the same reason: the #387 lesson is
 * to pin the real handler path, not a helper-only slice underneath it. The closure
 * below is now a thin adapter that translates ['ok' => bool, 'data' => mixed] into
 * wp_send_json_success()/wp_send_json_error().
 *
 * `data` is a STRING for the three pre-flight refusals (all theme-authored literals)
 * and an ARRAY for a rejected write, because the editor keys on the structured `code`
 * (composition_conflict → reload prompt) rather than parsing the human message (#13).
 *
 * @param  array $post  $_POST-shaped input: ['post_id', 'nonce', 'composition',
 *                       'expected_version'].
 * @return array        ['ok' => bool, 'data' => mixed].
 */
function _pp_save_composition_response(array $post): array {
    $post_id = isset($post['post_id']) ? (int) $post['post_id'] : 0;

    if (!$post_id || !isset($post['nonce']) ||
        !wp_verify_nonce($post['nonce'], 'pp_composition_' . $post_id)) {
        return ['ok' => false, 'data' => 'Invalid nonce.'];
    }

    if (!current_user_can('edit_post', $post_id)) {
        return ['ok' => false, 'data' => 'Insufficient permissions.'];
    }

    $raw     = isset($post['composition']) ? stripslashes($post['composition']) : '';
    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return ['ok' => false, 'data' => 'Invalid JSON.'];
    }

    // Optimistic-locking baseline (#13): the version the editor loaded. Threaded into the
    // action for an atomic compare-and-swap so a save that would clobber an interleaved
    // write (the AI chat, a CLI action, another tab) is rejected with composition_conflict.
    // Absent/empty → null → the write skips the CAS (documented back-compat).
    $params = ['post_id' => $post_id, 'composition' => $decoded];
    $expected_version = _pp_expected_version_from_request($post);
    if ($expected_version !== null) {
        $params['expected_version'] = $expected_version;
    }

    $result = pp_execute_action('update_composition', $params);

    if (!$result['ok']) {
        // Structured payload so the editor can key on the code (composition_conflict →
        // reload prompt) rather than parsing the human message (#13). Built by the shared
        // helper, which is where the cleaning rule for this shape is written down (#864).
        return ['ok' => false, 'data' => _pp_editor_error_payload($result)];
    }

    // Auto-draft → draft promotion happens inside pp_execute_action() itself
    // (lib/actions.php) — one place, covering AJAX/CLI/operate.php alike.

    return ['ok' => true, 'data' => [
        'composition' => pp_get_composition($post_id),
        // Return the new baseline so the editor advances currentVersion and a follow-up
        // save doesn't false-conflict against its own prior write (#13).
        'version'     => pp_get_composition_marker($post_id)['version'],
    ]];
}

add_action('wp_ajax_pp_save_composition', function () {
    $resp = _pp_save_composition_response($_POST);

    if ($resp['ok']) {
        wp_send_json_success($resp['data']);
    } else {
        wp_send_json_error($resp['data']);
    }
});

// ── Admin Page Registration ───────────────────────────────────────────────────

add_action('admin_menu', function () {
    $hook = add_submenu_page(
        null,                          // hidden — no parent menu
        'Edit Composition',
        'Edit Composition',
        'edit_posts',
        'pp-composition',
        'pp_composition_workspace_page'
    );
    // Redirect a stale/GC'd-post URL BEFORE the page renders (#160). The
    // load-{hook} action fires in wp-admin/admin.php before admin-header.php
    // emits any output, so wp_safe_redirect() works here — a redirect from the
    // render callback below would hit "headers already sent" and silently fail.
    if ($hook) {
        add_action('load-' . $hook, 'pp_composition_workspace_load');
    }
});

/**
 * Decides where a composition-editor request should be redirected, if anywhere,
 * BEFORE the page renders (#160). Pure so it is unit-testable: no output, no
 * exit, no redirect side effect.
 *
 * Returns the Pages-list URL when the requested post is missing (e.g. an
 * 'auto-draft' hard-deleted by WordPress's ~7-day auto-draft GC — a stale
 * bookmark) or is not a page, so a bookmarked editor URL lands somewhere useful
 * instead of a dead end. Returns null when the request should proceed to the
 * normal render callback (a real page, or no post specified — the callback
 * reports "No page specified." for the latter).
 *
 * @param int $post_id Requested post id (0 when absent).
 * @return string|null Redirect URL, or null to proceed.
 */
function pp_composition_missing_post_redirect_url(int $post_id): ?string {
    if (!$post_id) {
        return null; // no post specified — the render callback handles this case
    }
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'page') {
        return admin_url('edit.php?post_type=page');
    }
    return null;
}

/**
 * load-{hook} handler for the composition editor: friendly redirect for a
 * missing/GC'd post before any output (#160). The thin, untestable glue
 * (wp_safe_redirect + exit) around the pure decision above.
 */
function pp_composition_workspace_load(): void {
    $post_id  = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    $redirect = pp_composition_missing_post_redirect_url($post_id);
    if ($redirect !== null) {
        wp_safe_redirect($redirect);
        exit;
    }
}

// Add body class for full-width CSS overrides
add_filter('admin_body_class', function (string $classes): string {
    if (isset($_GET['page']) && $_GET['page'] === 'pp-composition') {
        $classes .= ' pp-workspace-page';
    }
    return $classes;
});

/**
 * The text the composition editor's JSON pane loads, for ANY stored value (#750).
 *
 * The pane has always shown "the STORED bytes, re-encoded" (#604) — that is the honest view
 * and this keeps it. What it did not do was survive the stored values that are not strings,
 * and the editor is the ONE repair surface #767 verified as working, so the shapes it fell
 * over on were exactly the ones that need repairing:
 *
 *   stored value                          class              BEFORE            NOW
 *   -----------------------------------   ----------------   ---------------   --------------
 *   '' / null / false                     absent (blank)     '' blank pane     '' blank pane
 *   valid JSON list                       readable           pretty-printed    pretty-printed
 *   JSON object                           unexpected_shape   pretty-printed    pretty-printed
 *   undecodable JSON                      decode_error       raw, verbatim     raw, verbatim
 *   '0'                                   unexpected_shape   '' — pane LIED    '0', verbatim
 *   '0.0'                                 unexpected_shape   '0.0'             '0.0'
 *   int 0                                 unexpected_shape   '' — pane LIED    '0'
 *   int 5 / float / true                  unexpected_shape   FATAL TypeError   JSON scalar
 *   PHP list array (importer-written)     readable           FATAL TypeError   pretty-printed
 *   PHP map array (importer-written)      unexpected_shape   FATAL TypeError   pretty-printed
 *
 * TWO REAL BUGS, both in the falsy/non-string columns, both pre-existing and both fatal to
 * this issue's own acceptance criterion (a corrupt page must be PRESENTED as corrupt):
 *
 *   `if ($raw)` skipped the re-encode for the FALSY-but-present values — the string '0' and
 *   the int 0 — and `esc_textarea($raw ?: '')` then turned them into ''. A page storing the
 *   JSON scalar `0` opened as a BLANK pane: the pristine-blank lie this issue exists to
 *   remove, in its purest form. ('0.0' escaped it only by being a truthy string.)
 *
 *   get_post_meta() unserializes on the way out, so a row written as a PHP array by an
 *   importer or `wp post meta update ... --format=json` (the shape
 *   pp_get_composition_result_authoritative()'s docblock documents as reachable) came back
 *   as an ARRAY. json_decode(array) is a TypeError on PHP 8: the editor page died with a
 *   fatal before rendering anything at all.
 *
 * ONLY STRINGS ARE DECODED, and everything else is re-encoded to JSON rather than cast.
 * `(string) $value` would render true as "1" and an array as "Array"; wp_json_encode()
 * renders what the value IS, which is what an operator has to see to fix it. The pane is
 * still a rendering of storage, not a proposed repair — the notice beside it names the
 * classification, and nothing is written until the operator saves.
 *
 * @param  mixed $raw  The stored `_pp_composition` value, exactly as get_post_meta returned it.
 * @return string      Text for the editor pane.
 */
function pp_composition_editor_text($raw): string {
    return _pp_composition_editor_displayable(_pp_composition_editor_raw_text($raw));
}

/**
 * Whatever pp_composition_editor_text() produced, in a form esc_textarea() can print (#750).
 *
 * THE THIRD WAY TO SHOW A BLANK PANE FOR A NON-BLANK ROW, and it hides in the escaper.
 * esc_textarea() is htmlspecialchars($text, ENT_QUOTES, blog_charset) — explicit flags, so
 * PHP 8's ENT_SUBSTITUTE default does not apply — and htmlspecialchars returns the EMPTY
 * STRING when its input is not valid UTF-8. Truncated or binary-garbage bytes are a real
 * `decode_error` shape, so without this the pane renders empty for exactly the corruption
 * that is hardest to reason about, right beside a notice saying the row is corrupt.
 *
 * TRANSCRIBED, NOT SUBSTITUTED, and the difference is the whole safety argument. The obvious
 * implementation — mb_convert_encoding($text, 'UTF-8', 'UTF-8'), which swaps each bad byte
 * for `?` — is worse than the blank pane it replaces:
 *
 *   stored   [{"component":"hero","props":{"title":"caf\xE9"}}]   decode_error, latin-1 title
 *   swapped  [{"component":"hero","props":{"title":"caf?"}}]      VALID JSON, a valid list
 *
 * That pane parses. It passes the client validator, passes the server's decode, writes
 * through `update_composition`, and the notice clears reporting "Composition repaired" — so
 * the one action the notice tells the operator to take silently replaces a character of
 * their content with a question mark and calls it a repair. The pane's documented contract
 * since #604 is "the STORED bytes, re-encoded"; a swap quietly makes that false while
 * looking more correct than before.
 *
 * Escaping each invalid byte as `\xNN` keeps the promise instead. It is not a valid JSON
 * escape, so the transcription cannot parse: the client validator refuses the save, the
 * operator has to type a real composition, and what they see identifies the offending byte
 * exactly. Valid multibyte runs pass through untouched, so a mixed row still reads.
 *
 * The exact bytes are on the history ring either way (#818) — `wp pp operate
 * composition-history --post_id=N`, where `raw_base64` is the copy and `raw_sha256` verifies
 * it. This function's job is only to make sure the pane never claims to be them.
 */
function _pp_composition_editor_displayable(string $text): string {
    if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }

    $out = '';
    $len = strlen($text);
    for ($i = 0; $i < $len;) {
        // The shortest byte run starting here that IS valid UTF-8 (1 for ASCII, up to 4 for
        // a multibyte character). A prefix of a multibyte sequence fails the check, so this
        // never splits a legitimate character.
        $kept = false;
        for ($n = 1; $n <= 4 && $i + $n <= $len; $n++) {
            $chunk = substr($text, $i, $n);
            if (mb_check_encoding($chunk, 'UTF-8')) {
                $out .= $chunk;
                $i   += $n;
                $kept = true;
                break;
            }
        }
        if (!$kept) {
            $out .= sprintf('\\x%02X', ord($text[$i]));
            $i++;
        }
    }

    return $out;
}

/** The per-shape decision itself; see pp_composition_editor_text()'s table. */
function _pp_composition_editor_raw_text($raw): string {
    // Genuine absence only, and ASKED of the classifier's own predicate rather than
    // re-spelled here: the pane is blank on exactly the values every other surface calls a
    // blank page, and stays that way if that definition ever moves. '0' is not one of them.
    if (pp_composition_value_is_absent($raw)) {
        return '';
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Pretty-print stored JSON so the editor shows readable multi-line content.
            // The editor shows the STORED bytes, re-encoded (#604). It used to migrate a
            // legacy `variant` key out of the decoded view first (#69/#388 read path); that
            // migration is gone, so a pre-rename page now surfaces `variant` verbatim in the
            // editor exactly as it sits in the database. That is the honest view: `variant`
            // is rejected on every write path, so showing the operator a `layout`/`theme`
            // shape the stored document does not actually have was the editor telling a
            // small lie about storage. Saving such a page fails with `unknown_prop` and
            // names the offending key — the intended, loud outcome.
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $raw;
    }

    // JSON_INVALID_UTF8_SUBSTITUTE, because wp_json_encode returns FALSE on a value it
    // cannot encode and `(string) false` is '' — a blank pane for a row that is anything
    // but blank. A stored value carrying invalid UTF-8 (an importer writing raw bytes, a
    // truncated serialized payload) is exactly the case, and it is the case that most needs
    // to be visible.
    return (string) wp_json_encode(
        $raw,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/**
 * What the composition editor tells the operator about a page it cannot read (#750), or null.
 *
 * THE EDITOR'S HALF OF RULING R-C. The boot gate that catches a corrupt page already exists
 * — #745's serialization-invariant check refuses the accordion and routes to JSON-only mode
 * — but the notice it posts is the STRUCTURAL-DRIFT story ("opening this composition in the
 * accordion editor would change its structure"), told about a DATA-CORRUPTION state. It
 * never names the classification, never says the page is corrupted rather than empty, never
 * names the repair, and offers a "Copy as GitHub Issue" button that invites a bug report
 * against the theme when what is actually wrong is the row. Same wrong conclusion as the
 * chat's, dressed as a different one.
 *
 * WHY THE CLASSIFICATION IS COMPUTED IN PHP AND SHIPPED TO THE CLIENT, rather than derived
 * in JS from the buffer: a second implementation of the decision is a second spelling of it,
 * and #650/#652 is the whole reason both the diagnosis and the route are single-owned
 * functions. The JS asks nobody — it renders what the one classifier said.
 *
 * The MESSAGE is pp_composition_integrity_message()'s and the ROUTE is
 * pp_corrupt_repair_route_message()'s, with only the lead-in local, matching
 * _pp_batch_unreadable_target_error() (lib/actions.php) and pp_inspect_composition()
 * (lib/operate.php). The shared route names WP-CLI and "the dashboard composition editor";
 * on THIS surface the second half is where the operator already is, which is why the local
 * lead-in says so plainly instead of leaving them to work it out. #767's measurement
 * confirms this surface works: the editor save reaches pp_execute_action('update_composition')
 * with no run token, so no preflight coverage gate applies, and the incoming replacement is
 * still fully validated (ruling D-1, condition 3).
 *
 * Reads the CACHED classifier for the same reason pp_ai_page_context() does: this decides
 * what a NOTICE says, not whether a gate opens.
 *
 * `$can_write_here` EXISTS BECAUSE THE PROMISE HAS TO BE TRUE FOR THE READER, not for the
 * surface in general. With WordPress's per-user "Disable syntax highlighting while editing"
 * profile option there is no CodeMirror instance, and both `doSaveDraft()` and
 * `doPublishOrUpdate()` (assets/js/pp-admin-editor.js) open with `if (!cm) return;` — so
 * Save and Publish are silent no-ops for that author on every page, corrupt or not (filed
 * separately). Telling them to "fix it here and save" would be this change putting a fresh
 * lie where it just removed one, so that author is pointed at the routes that do run. The
 * inertness is pre-existing; the promise would have been new.
 *
 * @param  int  $post_id         The page being opened in the editor.
 * @param  bool $can_write_here  Can THIS editor session actually perform the write?
 * @return array{error: string, message: string, repair: string}|null  Null when readable.
 */
function pp_composition_editor_integrity(int $post_id, bool $can_write_here = true): ?array {
    $stored = pp_get_composition_result($post_id);
    if ($stored['ok']) {
        return null;
    }

    $lead_in = $can_write_here
        ? 'This editor is one of the repair surfaces, so you can fix it here: replace the'
            . ' JSON below with a valid composition array and save. That save is one'
            . ' whole-composition write, and it is validated in full before anything is'
            . ' stored. '
        : 'Saving from this editor is disabled while syntax highlighting is turned off in'
            . ' your WordPress profile, so the repair has to come from one of the routes'
            . ' below. ';

    return [
        'error'   => (string) $stored['error'],
        'message' => pp_composition_integrity_message($post_id, (string) $stored['error']),
        'repair'  => $lead_in . pp_corrupt_repair_route_message($post_id),
    ];
}

// ── Workspace Page Callback ───────────────────────────────────────────────────

function pp_composition_workspace_page(): void {
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

    if (!$post_id) {
        wp_die('No page specified.');
    }

    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'page') {
        // Unreachable in the normal flow: pp_composition_workspace_load()
        // (load-{hook}) already redirected a missing/GC'd post to the Pages
        // list before output (#160). Kept as a defensive net for the case the
        // load hook never ran; a redirect here would fail (headers already sent).
        wp_die('Page not found.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_die('You do not have permission to edit this page.');
    }

    // The pane text for any stored value, including the corrupt ones (#750) — see
    // pp_composition_editor_text(), which owns the per-shape decision and why.
    $raw        = pp_composition_editor_text(get_post_meta($post_id, '_pp_composition', true));
    $components = pp_get_registered_components();

    // Back always goes to the Pages list — not get_edit_post_link(), which
    // now returns the composition editor URL and would create a loop.
    $back_url = admin_url('edit.php?post_type=page');

    $view_url   = $post->post_status === 'publish'
        ? get_permalink($post_id)
        : get_preview_post_link($post_id);
    $view_label = $post->post_status === 'publish' ? 'View' : 'Preview';

    // Build component list for sidebar
    $component_list = [];
    foreach ($components as $name => $schema) {
        $props_summary = [];
        if (!empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $def) {
                $type     = $def['type'] ?? 'string';
                $required = !empty($def['required']);
                if ($type === 'enum' && !empty($def['values'])) {
                    $type = '"' . implode('" | "', $def['values']) . '"';
                }
                $props_summary[] = [
                    'name'     => $prop_name,
                    'type'     => $type,
                    'required' => $required,
                ];
            }
        }
        $component_list[] = [
            'name'          => $name,
            'description'   => $schema['description'] ?? '',
            'props_summary' => $props_summary,
            'schema'        => $schema,
        ];
    }

    ?>
    <div class="pp-workspace" id="pp-workspace">

        <!-- ── Toolbar ───────────────────────────────────────────────── -->
        <div class="pp-toolbar">
            <div class="pp-toolbar-left">
                <a href="<?php echo esc_url($back_url); ?>" class="pp-back-btn" title="All Pages">
                    &#8592;
                </a>
                <input
                    type="text"
                    id="pp-page-title"
                    class="pp-page-title-input"
                    value="<?php echo esc_attr($post->post_title); ?>"
                    placeholder="Page title"
                    autocomplete="off"
                    spellcheck="false"
                />
                <?php if ($post->post_status !== 'publish') : ?>
                <span class="pp-status-badge" id="pp-status-badge">Draft</span>
                <?php endif; ?>
            </div>
            <div class="pp-toolbar-center">
                <span class="pp-save-status" id="pp-save-status"></span>
            </div>
            <div class="pp-toolbar-right">
                <button id="pp-view-toggle" class="pp-toolbar-btn" data-view="accordion">JSON</button>
                <a href="<?php echo esc_url($view_url); ?>" target="_blank"
                   rel="noopener" class="pp-view-link" id="pp-view-link">
                    <?php echo esc_html($view_label); ?> &#8599;
                </a>
                <?php if ($post->post_status !== 'publish') : ?>
                <button id="pp-save-btn" class="pp-toolbar-btn" title="Save draft (Ctrl+S)">
                    Save Draft
                </button>
                <?php endif; ?>
                <button id="pp-publish-btn" class="pp-toolbar-btn pp-toolbar-btn--primary"
                        data-status="<?php echo esc_attr($post->post_status); ?>">
                    <?php echo $post->post_status === 'publish' ? 'Update' : 'Publish'; ?>
                </button>
            </div>
        </div>

        <!-- ── Validation bar ────────────────────────────────────────── -->
        <div class="pp-error-bar" id="pp-error-bar"></div>

        <!-- ── Three panes ───────────────────────────────────────────── -->
        <div class="pp-panes">

            <!-- Editor pane -->
            <div class="pp-pane pp-pane--editor">
                <div class="pp-pane-header">Composition</div>
                <div class="pp-pane-body">
                    <!-- Accordion view (default) -->
                    <div id="pp-accordion-view" class="pp-accordion"></div>
                    <!-- JSON view (hidden by default) -->
                    <div id="pp-json-view" style="display:none;">
                        <textarea
                            id="pp-composition-editor"
                            name="pp_composition"
                            style="display:none;"
                        ><?php echo esc_textarea($raw); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Resize handle: editor | preview -->
            <div class="pp-resize-handle" data-left="editor" data-right="preview"></div>

            <!-- ARIA live region for accordion announcements -->
            <div id="pp-accordion-live" class="sr-only" aria-live="polite" aria-atomic="true"></div>

            <!-- Preview pane -->
            <div class="pp-pane pp-pane--preview">
                <div class="pp-pane-header">
                    Live Preview
                    <span class="pp-preview-status" id="pp-preview-status">Loading&hellip;</span>
                </div>
                <div class="pp-pane-body pp-pane-body--preview">
                    <iframe
                        id="pp-preview-frame"
                        class="pp-preview-frame"
                        sandbox="allow-same-origin allow-scripts"
                        title="Composition preview"
                    ></iframe>
                </div>
            </div>

        </div><!-- /.pp-panes -->
    </div><!-- /.pp-workspace -->
    <?php
}

// ── AJAX Preview ──────────────────────────────────────────────────────────────

add_action('wp_ajax_pp_preview_composition', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if (!$post_id || !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'pp_composition_' . $post_id)) {
        wp_send_json_error('Invalid nonce.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions.');
    }

    $raw         = isset($_POST['composition']) ? stripslashes($_POST['composition']) : '[]';
    $composition = json_decode($raw, true);

    if (!is_array($composition)) {
        wp_send_json_error('Invalid JSON.');
    }

    $result = pp_validate_composition($composition);
    if (is_wp_error($result)) {
        // Cleaned at the sink (#864), like every other composed validator message the
        // editor renders. The preview endpoint validates the composition the EDITOR
        // submitted, so the names this message quotes back are caller text outright.
        wp_send_json_error(_pp_clean_reflected_text($result->get_error_message(), PP_REFLECTED_ERROR_MAX));
    }

    $dir_uri = get_template_directory_uri();

    ob_start();
    try {
        pp_get_component('nav', ['location' => 'primary']);
        echo '<main id="main">';
        foreach ($composition as $item) {
            $name  = isset($item['component']) ? (string) $item['component'] : '';
            $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
            // THE `items[].style` -> `__pp_style` PROMOTION STOOD HERE AND WENT AT #1101.
            // It lifted a band's stored v1 slot map into the props array so the component
            // template could render it. MEASURED DEAD before deleting: `__pp_style` has zero
            // READ sites in the tree — every component render file carries only a comment
            // where the read used to be — so this wrote a key nothing consumed. Removing it
            // from all four band loops changed no rendered byte and broke exactly one test,
            // a SOURCE SCAN asserting the promotion existed.
            //
            // An aged band's stored `style` map is not lost by this: it is still in the
            // composition, still refuses every edit to its band until cleared, and is still
            // named in the refusal (_pp_validate_style_slot_map, lib/admin.php).
            $props = pp_udc_promote_band_identity($item, $props);
            if ($name !== '') {
                pp_get_component($name, $props);
            }
        }
        echo '</main>';
        pp_get_component('footer', ['location' => 'footer']);
    } catch (Throwable $e) {
        ob_end_clean();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // The third of #864's \Throwable rows, and the only one that is a scalar
            // sink — the other two land inside a validation report and are cleaned by
            // _pp_clean_reflected_report(). Not theme-authored text and not the same
            // threat model as stored site data, but a component render throwing on
            // stored props can quote those props back, and this arm ships it straight
            // into the editor's error banner. Cleaned for the same reason as its
            // neighbours: the sink decides, and every sink here decides the same way.
            wp_send_json_error(_pp_clean_reflected_text('Render failed: ' . $e->getMessage(), PP_REFLECTED_ERROR_MAX));
        }
        wp_send_json_error('Render failed.');
    }

    $body = ob_get_clean();

    $html = '<!DOCTYPE html><html><head>'
        . pp_preview_document_head($composition, $dir_uri)
        . '</head><body>' . $body . '</body></html>';

    wp_send_json_success(['html' => $html]);
});

/**
 * The preview iframe's <head>: the theme stylesheets plus the v2 UDC layers,
 * each at the position the front end puts it.
 *
 * A NAMED FUNCTION BECAUSE THE ORDER IS THE CONTRACT. The preview builds its own
 * head and never calls wp_head(), so nothing about the front end's emission
 * reaches it automatically — it has to be restated, and a restatement that is
 * not pinned is a copy waiting to drift. It lived inside the AJAX closure, where
 * no test could reach it, and it drifted immediately.
 *
 * WHAT IT DRIFTED INTO, and why it mattered: the preview emitted
 * pp_udc_page_css() — both tiers concatenated — in ONE block after all three
 * stylesheets. Both tiers are zero-or-low specificity by construction, so their
 * ranking IS their position and nothing else; printing the defaults tier after
 * components.css inverted it. In the preview a component's own role default beat
 * the shared design-system rules; on the front end it loses to them. The
 * operator was shown a page the site will never render, and the divergence is
 * invisible until two v2 components are on one page.
 *
 * The positions below mirror functions.php's two stylesheet handles. Only the
 * INLINE blocks are the cascade tiers; the three stylesheets are the structural
 * CSS they rank against.
 *
 *     front end (wp_head)                      preview (this function)
 *     ───────────────────────────────────      ──────────────────────────────
 *     <link> per enqueued webfont              <link> per enqueued webfont
 *     <link base.css>                          <link base.css>
 *     inline on `pp-base`: :root overrides  ┐
 *     inline on `pp-base`      <- DEFAULTS  ├─ <style id="pp-udc-defaults">
 *     inline on `pp-base`      <- CHROME    ┘
 *     <link components.css>                    <link components.css>
 *     <link utilities.css>                     <link utilities.css>
 *     inline on `pp-utilities` <- AUTHORED  ┐─ <style id="pp-udc-authored">
 *     inline on `pp-utilities` <- CHROME    ┘
 *
 * THE BRACES ARE NOT AN APPROXIMATION. WordPress concatenates every inline style
 * attached to one handle into ONE <style> element, so the front end's three
 * `pp-base` blocks are already a single element in source order, and so are its
 * two `pp-utilities` blocks. Emitting them as one block each here reproduces the
 * front end byte-for-byte rather than merely resembling it — functions.php says
 * the same thing at its token-override block, which relies on exactly this.
 *
 * VALUE PARITY, NOT STRUCTURAL PARITY — say which, because I15 ("preview promises
 * exactly what execute delivers") is easy to over-claim. What is emitted here is
 * every source that decides what a declaration COMPUTES TO: webfonts, design-token
 * overrides, both UDC tiers, both chrome tiers. What is still absent is everything
 * structural — this function never calls wp_head(), so SEO/OG meta, pp-main.js and
 * anything a plugin hooks are not here, and the stylesheet links carry no `?ver=`.
 * Those remain on #963; they change what the page IS, not what its CSS resolves to.
 *
 * The overrides go through pp_token_overrides_inline_css() rather than a raw read
 * of `pp_token_overrides`, so the T1.5 render boundary applies identically: a row
 * the front end DROPS is dropped here too. A preview that printed a row the page
 * refuses would promise a value the site will never paint.
 *
 * Keep this in step with the enqueue callback in functions.php; the ordering is
 * pinned from both ends (PreviewCascadeParityTest) so a one-sided edit fails.
 */
function pp_preview_document_head(array $composition, string $dir_uri): string {
    $link = static fn(string $file): string =>
        '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/' . $file . '">';

    // Same emitters, same output, same functions the front end calls: a preview
    // that computed its CSS a second way would diverge from the live page exactly
    // where it matters.
    $overrides = pp_get_token_overrides();
    $token_css = $overrides ? pp_token_overrides_inline_css($overrides, pp_design_tokens()) : '';

    $defaults        = pp_udc_page_defaults_css($composition);
    $authored        = pp_udc_page_authored_css($composition);
    $chrome_defaults = pp_udc_chrome_defaults_css();
    $chrome_authored = pp_udc_chrome_authored_css();

    // CHROME IS NOT GATED ON THE COMPOSITION, here or on the front end. The preview
    // renders nav and footer markup (both carry data-pp-chrome), so a site with a
    // styled header previewed a stock one above its own page until this landed.
    $base_block      = $token_css . $defaults . $chrome_defaults;
    $utilities_block = $authored . $chrome_authored;

    // A NON-STRING ROW IS DROPPED, NOT FATAL. pp_get_font_urls() guarantees an
    // array and says nothing about its elements, and esc_url() has no scalar type
    // declaration — it reaches ltrim(), which is a TypeError on an array in PHP 8.
    // This runs AFTER ob_get_clean() has closed the AJAX handler's try/catch, so
    // one malformed row in the option would 500 the preview with no diagnosis
    // instead of degrading. Same posture the token block two blocks up takes: drop
    // the bad row, emit the healthy ones.
    $fonts = '';
    foreach (pp_get_font_urls() as $font_url) {
        if (!is_string($font_url) || $font_url === '') {
            continue;
        }
        $fonts .= '<link rel="stylesheet" href="' . esc_url($font_url) . '">';
    }

    return '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        // THE LAYER ORDER, AHEAD OF EVERYTHING (#986). base.css carries the same
        // statement, but the links below have no cache-busting query, so a browser
        // holding a pre-#986 base.css would build this document with no order
        // established at all — base.css unlayered and therefore strongest, and the
        // band-root defaults tier sorted below its reset. Re-declaring an order that
        // already exists is a no-op, so this costs nothing on a fresh load and rescues
        // a stale one. One owner: pp_css_layer_order().
        . '<style id="pp-layer-order">' . pp_css_layer_order() . '</style>'
        . $fonts
        . $link('base.css')
        . ($base_block !== '' ? '<style id="pp-udc-defaults">' . $base_block . '</style>' : '')
        . $link('components.css')
        . $link('utilities.css')
        . ($utilities_block !== '' ? '<style id="pp-udc-authored">' . $utilities_block . '</style>' : '');
}

// ── AJAX: Save Title ──────────────────────────────────────────────────────────

add_action('wp_ajax_pp_save_title', function (): void {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if (!$post_id || !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'pp_composition_' . $post_id)) {
        wp_send_json_error('Invalid nonce.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions.');
    }

    $title  = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $result = pp_execute_action('update_page_title', [
        'post_id' => $post_id,
        'title'   => $title,
    ]);

    if (!$result['ok']) {
        // Cleaned at the sink (#864). update_page_title's rejections quote the caller's
        // own title back, and sanitize_text_field() above is a WordPress input filter,
        // not a reflected-text guard — it strips tags and invalid UTF-8 but leaves the
        // bidi and zero-width set intact.
        wp_send_json_error(_pp_clean_reflected_text((string) $result['error'], PP_REFLECTED_ERROR_MAX));
    }

    // Auto-draft → draft promotion (with the empty-title-blur exclusion)
    // happens inside pp_execute_action() itself (lib/actions.php).

    wp_send_json_success(['title' => $title]);
});

// ── AJAX: Publish / Update ────────────────────────────────────────────────────

add_action('wp_ajax_pp_publish_page', function (): void {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if (!$post_id || !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'pp_composition_' . $post_id)) {
        wp_send_json_error('Invalid nonce.');
    }

    if (!current_user_can('edit_post', $post_id) || !current_user_can('publish_pages')) {
        wp_send_json_error('Insufficient permissions.');
    }

    // Save composition first (short-circuit: if save fails, publish never fires). The CAS
    // (#13) rides on this save step: a composition_conflict here returns before publish_page
    // runs, so a stale editor can't publish over an interleaved write.
    $raw = isset($_POST['composition']) ? stripslashes($_POST['composition']) : '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            wp_send_json_error('Invalid JSON.');
        }
        $save_params = ['post_id' => $post_id, 'composition' => $decoded];
        $expected_version = _pp_expected_version_from_request($_POST);
        if ($expected_version !== null) {
            $save_params['expected_version'] = $expected_version;
        }
        $save_result = pp_execute_action('update_composition', $save_params);
        if (!$save_result['ok']) {
            // The same shape, the same rule, one spelling (#864).
            wp_send_json_error(_pp_editor_error_payload($save_result));
        }
    }

    // Publish the page.
    $pub_result = pp_execute_action('publish_page', ['post_id' => $post_id]);
    if (!$pub_result['ok']) {
        wp_send_json_error(_pp_editor_error_payload($pub_result));
    }

    $saved = pp_get_composition($post_id);
    wp_send_json_success([
        'status'       => 'publish',
        'post_link'    => (string) (get_permalink($post_id) ?: ''),
        'preview_link' => (string) (get_preview_post_link($post_id) ?: ''),
        'composition'  => $saved,
        'version'      => pp_get_composition_marker($post_id)['version'],
    ]);
});

// ── Admin Assets ──────────────────────────────────────────────────────────────

add_action('admin_enqueue_scripts', function (string $hook) {
    // Match the composition workspace by both hook name and page parameter
    if (!isset($_GET['page']) || $_GET['page'] !== 'pp-composition') {
        return;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!$post_id) {
        return;
    }

    $cm_settings = wp_enqueue_code_editor(['type' => 'application/json']);
    $dir_uri     = get_template_directory_uri();

    wp_enqueue_style(
        'pp-admin-editor',
        $dir_uri . '/assets/css/pp-admin-editor.css',
        [],
        PP_VERSION
    );

    // CodeMirror disabled in user profile — still load JS for save/preview,
    // but signal the editor to show the raw textarea instead.
    $cm_deps = $cm_settings ? ['jquery', 'wp-codemirror'] : ['jquery'];

    wp_enqueue_script(
        'pp-editor-logic',
        $dir_uri . '/assets/js/pp-editor-logic.js',
        [],
        PP_VERSION,
        true
    );

    wp_enqueue_script(
        'pp-admin-editor',
        $dir_uri . '/assets/js/pp-admin-editor.js',
        array_merge($cm_deps, ['pp-editor-logic']),
        PP_VERSION,
        true
    );

    $components    = pp_get_registered_components();
    $js_components = [];
    foreach ($components as $name => $schema) {
        // Chrome stays in the registry (the preview renders it) but is tagged so
        // autocomplete hides it and the client validator can name it as chrome
        // rather than reporting "Unknown component" (issue #223). The message is
        // authored once, in PHP, and shipped to the client.
        $owned = pp_is_template_owned_component($name);
        $js_components[] = [
            'name'          => $name,
            'schema'        => $schema,
            'templateOwned' => $owned,
            'ownedMessage'  => $owned ? pp_template_owned_component_message($name) : '',
        ];
    }

    wp_localize_script('pp-admin-editor', 'ppAdminEditor', [
        'components'         => $js_components,
        'codeEditorSettings' => $cm_settings ?: new stdClass(),
        'cmDisabled'         => !$cm_settings,
        'ajaxUrl'            => admin_url('admin-ajax.php'),
        'nonce'              => wp_create_nonce('pp_composition_' . $post_id),
        'postId'             => $post_id,
        'postStatus'         => get_post_field('post_status', $post_id),
        'postLink'           => (string) (get_permalink($post_id) ?: ''),
        'previewLink'        => (string) (get_preview_post_link($post_id) ?: ''),
        // Optimistic-locking baseline (#13): the composition version this editor is loading.
        // Sent back as expected_version on save/publish so a concurrent write is caught.
        'compositionVersion' => pp_get_composition_marker($post_id)['version'],
        // Stored-composition classification (#750): null on a readable page, otherwise the
        // classification plus the sentences the operator is shown. The client renders this;
        // it never re-derives the decision. `$cm_settings` is falsy exactly when this author
        // turned syntax highlighting off, which is also when the editor's Save is inert —
        // see pp_composition_editor_integrity().
        'compositionIntegrity' => pp_composition_editor_integrity($post_id, (bool) $cm_settings),
    ]);
});

