<?php
/**
 * lib/admin.php — PromptingPress Admin Composition Editor
 *
 * Responsibilities:
 * - pp_get_registered_components()  scan components/ directory
 * - pp_template_owned_components()  components the base template renders itself
 * - pp_validate_composition()       validate a composition array
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
 * ENFORCEMENT REACH, stated so nobody reads more into it than is true: the closed
 * set is a REPO-CI INVARIANT, not a runtime gate. pp_schema_definition_errors() is
 * driven by SchemaValidationTest over the twelve shipped schemas; nothing calls it
 * on a live request. That is sufficient today because components are discovered
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
        'aliases',              // #575 — legacy VALUES, accepted at write, never advertised (#579 wired the consumer)
        'applies_when',         // #575
        'conditionality_note',  // #575
    ];
}

/**
 * The bounded value set for the slot definition's `role` marker (issue #575).
 *
 * `fill` marks a colour slot as a component's BUTTON/SURFACE FILL, so the warning
 * engine can tell a fill slot from any other colour slot. It is a DECLARED key, not
 * a `-bg` / `-hover-bg` name convention: a naming convention is not machine-readable
 * without a second source of truth, which is the defect this whole contract fixes
 * one layer down. #575 landed the field one gate ahead of its consumer; #579 wired
 * that consumer, the `transparent_fill` composition smell, and the hero/cta button
 * fills plus `--section-panel-cta-bg` are the slots that declare it today.
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
 *   - COMPOSED-PAGE CONTEXT — `--grid-item-bar-*` / `--grid-featured-*` apply only
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
 * this, `{"prop":"card_emphasis","equals":"featured"}` would report every grid that
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
 * The subset of an `applies_when` array that an authored component does NOT satisfy.
 *
 * Clauses are ANDed, so a non-empty return means the declaration is INERT on this
 * component and every returned clause is part of the reason. Callers report ALL of them
 * rather than stopping at the first miss: on a centered hero with no `proof`, both
 * clauses of `--hero-surface-bg` miss, and "applies when layout = "split"" alone would
 * send the author to fix the layout and leave the slot just as dead.
 *
 * @param  array  $clauses    The definition's `applies_when` array.
 * @param  string $component  Component name, for schema defaults.
 * @param  array  $props      The component's authored props.
 * @param  array  $style_map  The component's authored style map, canonical slot names.
 * @return array              The unmet clauses, in declaration order; empty when it applies.
 */
function pp_applies_when_unmet_clauses(array $clauses, string $component, array $props, array $style_map): array {
    if ($clauses === [] || !pp_is_list($clauses)) {
        return [];
    }
    // is_array, not `?? []`: pp_get_registered_components() stores whatever json_decode
    // returned for the whole file with no per-key normalization, so a corrupt schema whose
    // top-level `props` is a scalar would hand a non-array to a declared `array` parameter
    // and FATAL — inside `wp pp check page`, `wp pp validate site`, `operate inspect` and
    // the restore-findings path. Fail open on a registry shape we cannot read, exactly as
    // the evaluator fails open on a clause it cannot read.
    $registered = pp_get_registered_components();
    $prop_defs  = is_array($registered[$component]['props'] ?? null)
        ? $registered[$component]['props']
        : [];

    $unmet = [];
    foreach ($clauses as $clause) {
        if (!pp_applies_when_clause_met($clause, $props, $prop_defs, $style_map)) {
            $unmet[] = $clause;
        }
    }
    return $unmet;
}

/**
 * Validates ONE slot or prop DEFINITION OBJECT from a component `schema.json`.
 *
 * The single shared engine for the definition surface (issue #575) — the schema
 * counterpart of pp_validate_composition_errors(), which validates the documents
 * schemas describe. Both the slot surface and the prop surface run THIS function;
 * there is deliberately no second, surface-specific definition validator.
 *
 *     schema.json
 *        │
 *        ├── styling.style_slots.<name>  ──┐
 *        │                                 ├──►  pp_schema_definition_errors()
 *        └── props.<name>  ────────────────┘        │
 *                                                   ├─ closed key set (rejects unknown keys)
 *                                                   ├─ applies_when → pp_applies_when_clause_errors()
 *                                                   ├─ conditionality_note → bounded string
 *                                                   ├─ role → pp_slot_roles()      (slots only)
 *                                                   └─ aliases → value list        (props only)
 *
 * @param  array  $definition  The decoded definition object.
 * @param  string $kind        'slot' or 'prop'.
 * @param  string $label       Context for error messages, e.g. 'hero --hero-bg'.
 * @return string[]            Human-readable errors; empty when the definition is valid.
 */
function pp_schema_definition_errors(array $definition, string $kind, string $label): array {
    $errors  = [];
    $allowed = $kind === 'slot' ? pp_slot_definition_keys() : pp_prop_definition_keys();

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

    if ($kind === 'slot' && array_key_exists('role', $definition)) {
        if (!is_string($definition['role']) || !in_array($definition['role'], pp_slot_roles(), true)) {
            $errors[] = sprintf(
                '%s: `role` must be one of: %s.',
                $label,
                implode(', ', pp_slot_roles())
            );
        }
    }

    if ($kind === 'prop' && array_key_exists('aliases', $definition)) {
        $aliases = $definition['aliases'];
        if (!is_array($aliases) || $aliases === [] || !pp_is_list($aliases)) {
            $errors[] = "{$label}: `aliases` must be a non-empty ARRAY of accepted legacy values.";
        } else {
            // `aliases` names legacy members of a BOUNDED value set, so it is
            // meaningless on a prop that has no value set to be outside of. A
            // `{"type":"string","aliases":[...]}` declaration would validate clean
            // and emit a catalog line an agent cannot act on.
            if (($definition['type'] ?? null) !== 'enum' || !is_array($definition['values'] ?? null)) {
                $errors[] = "{$label}: `aliases` applies only to an enum prop that declares `values`.";
            }
            // The advertise-but-reject guard that stood here in #575 is GONE, and
            // its removal is the point of #579: the strict-enum check in
            // pp_validate_composition_errors() now consults `aliases`, so declaring
            // both is not a lie any more, it is the whole contract —
            // `theme` is strict AND still accepts the legacy `dark` it never
            // advertises. Any future strict enum may declare aliases the same way.
            $values = $definition['values'] ?? [];
            foreach ($aliases as $alias) {
                if (!is_string($alias) || $alias === '') {
                    $errors[] = "{$label}: every `aliases` member must be a non-empty string.";
                    continue;
                }
                // The AI catalog renders alias members inside double quotes; a value
                // carrying one would forge the quoting an agent parses. Bound the
                // shape at authoring time, not in the emitter.
                if (strpos($alias, '"') !== false) {
                    $errors[] = "{$label}: an `aliases` member must not contain a double quote.";
                }
                // Canonical values stay clean: an alias is accepted at write and
                // NEVER advertised. A value that appears in both lists is not an
                // alias, it is an advertised value with a duplicate declaration.
                if (is_array($values) && in_array($alias, $values, true)) {
                    $errors[] = "{$label}: alias `{$alias}` is also advertised in `values` — an alias is accepted, never advertised.";
                }
            }
        }
    }

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
 * Validates a decoded composition array and returns EVERY error it finds.
 *
 * The collect-all engine behind pp_validate_composition(). Both read the same rules;
 * they differ only in how much they report. Write-time callers want the first error
 * (fail fast, one actionable message); reporting callers — restore_composition's
 * `findings` (#233) — need the complete set, or a caller fixes one violation, retries,
 * and discovers the next one only on the following run.
 *
 * At most ONE error per item: each item stops at its first failing check and moves on.
 * That keeps errors[0] identical to the single error pp_validate_composition() has
 * always returned (same code, same message, same document order), and it stops a
 * malformed item from cascading bogus follow-on errors from the checks below it.
 *
 * @param  array      $items  Decoded composition array.
 * @return WP_Error[]         Empty when the composition is valid.
 */

/**
 * Validates a style-slot override map against a component's declared style slots.
 *
 * The single shared gate for BOTH grid-level component style (`item['style']`) and
 * per-item card style (issue 306, `props.items[].style`). Both surfaces run the
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
 * @param  array    $style           Slot => value overrides to validate.
 * @param  array    $available_slots  The component's declared style_slots.
 * @param  string   $component_name   Component name, for the error message.
 * @param  int|null $item_index       Card index when validating a per-item override,
 *                                     or null for grid-level component style.
 * @return WP_Error|null              A WP_Error on the first bad slot/value, else null.
 */
function _pp_validate_style_slot_map(array $style, array $available_slots, string $component_name, ?int $item_index = null): ?WP_Error {
    $where = $item_index === null
        ? sprintf('Component "%s"', $component_name)
        : sprintf('Component "%s" item %d', $component_name, $item_index);

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
                    '%s has no style slot "%s". Available slots: %s',
                    $where,
                    $slot_name,
                    $available ?: '(none)'
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
 * @param string      $component  Component name.
 * @param string      $prop_name  Top-level prop (e.g. "button_url" or the array prop "items").
 * @param int|null    $item_index Item index when the prop is a nested items[] link, else null.
 * @param string|null $field      Item field name (e.g. "link_url") when nested, else null.
 * @param mixed       $value      The rejected value.
 */
function _pp_link_url_error_message(string $component, string $prop_name, ?int $item_index, ?string $field, $value): string {
    $where = ($item_index === null)
        ? sprintf('prop "%s"', $prop_name)
        : sprintf('prop "%s" item %d field "%s"', $prop_name, $item_index, (string) $field);
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

function pp_validate_composition_errors(array $items): array {
    $registered = pp_get_registered_components();
    $errors     = [];

    foreach ($items as $i => $item) {
        if (!isset($item['component'])) {
            $errors[] = new WP_Error(
                'invalid_composition',
                sprintf('Item %d is missing the "component" key.', $i)
            );
            continue;
        }

        // A corrupt or raw-written row can carry an array/object here. Casting it would
        // emit "Array to string conversion" and then report a component literally named
        // "Array". restore's findings (#233) run these rules over arbitrary history-ring
        // snapshots, so malformed shapes reach this line — name the real problem instead.
        if (!is_scalar($item['component'])) {
            $errors[] = new WP_Error(
                'invalid_composition',
                sprintf('Item %d has a non-scalar "component" key.', $i)
            );
            continue;
        }

        $name = (string) $item['component'];

        if (!isset($registered[$name])) {
            $errors[] = new WP_Error(
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
            $errors[] = new WP_Error(
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
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (
                    !empty($prop_def['required']) &&
                    (!isset($item['props']) || !array_key_exists($prop_name, $item['props']))
                ) {
                    $errors[] = new WP_Error(
                        'invalid_composition',
                        sprintf('Component "%s" is missing required prop "%s".', $name, $prop_name)
                    );
                    continue 2;
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
                $errors[] = new WP_Error(
                    'invalid_composition',
                    sprintf('Component "%s" %s.', $name, $message)
                );
                continue;
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
        // subset), which omits real props like cta.theme / cta.background_image and
        // would false-reject them.
        //
        // Runs after the required-props loop so a missing required prop still wins
        // first-error document order. restore_composition (issue 233) reports this
        // through _pp_composition_findings() but never blocks on it — same as every
        // other rule here.
        if (isset($item['props']) && is_array($item['props'])) {
            $declared = isset($schema['props']) && is_array($schema['props'])
                ? $schema['props']
                : [];
            foreach ($item['props'] as $prop_name => $prop_value) {
                if (!array_key_exists($prop_name, $declared)) {
                    $available = implode(', ', array_keys($declared));
                    $errors[]  = new WP_Error(
                        'unknown_prop',
                        sprintf(
                            'Component "%s" has no prop "%s". Available props: %s',
                            $name,
                            $prop_name,
                            $available ?: '(none)'
                        )
                    );
                    continue 2;
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
                    $errors[] = new WP_Error(
                        'invalid_prop_value',
                        sprintf(
                            'Component "%s" prop "%s" must be an integer between %d and %d; got "%s".',
                            $name,
                            $prop_name,
                            $min,
                            $max,
                            is_scalar($value) ? (string) $value : gettype($value)
                        )
                    );
                    continue 2;
                }
            }
        }

        // Strict enum props (issue 380, made universal by issue #579 A-32). Every
        // TOP-LEVEL enum prop declares `strict: true` now. A supplied value
        // must be one of the declared `values` (or one of the prop's declared
        // legacy `aliases`, below) — otherwise the write is rejected with the
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
        // ALIASES ARE PART OF THE MEMBERSHIP TEST (#579 lands #575's consumer). A
        // prop may declare `aliases`: legacy values accepted at write and NEVER
        // advertised in `values`. `theme` declares `["dark"]`. Without this the gate
        // would be actively destructive rather than merely strict: the block runs
        // inside pp_validate_composition_errors()'s per-item loop, while
        // update_component validates the WHOLE composition (lib/actions.php), so one
        // untouched band still carrying `theme: "dark"` would block an edit to a
        // DIFFERENT band on the same page. `dark` keeps rendering identically
        // (pp_theme_class, lib/helpers.php).
        //
        // ONE OF #579's TWO EVIDENCE LEGS IS RETIRED (#604). `dark` used also to be
        // MANUFACTURED at read time by pp_migrate_legacy_variant_keys() from a
        // stored `variant: "dark"`, so it materialised on pages where the string
        // never appeared in storage. That migration is gone: `dark` now only ever
        // reaches this gate when it is literally the stored/submitted value. The
        // remaining leg — untouched bands that really do store `dark` — is what
        // keeps the alias, and re-evaluating it is the `theme: "dark"` issue's job,
        // not this one's.
        //
        // SCOPE, stated so nobody reads more into it than is true: this walks
        // $schema['props'], i.e. TOP-LEVEL props only, exactly as the #379/#475
        // families beside it do. NESTED item-field enums (today only
        // grid.items[].text_role) are NOT covered and remain accept-and-coerce; they
        // are outside #579's recorded scope, which enumerated the 28 top-level enums.
        // The CI tripwire (SchemaValidationTest::testEveryTopLevelEnumPropDeclaresStrict)
        // is scoped to match, so the invariant it asserts is the one that holds.
        //
        // Generic + schema-driven: no per-component branch, no second validator.
        // "Unset" is the key being absent, null, or the empty string — that
        // preserves the prop's default behavior (image_treatment unset => banner).
        // Runs in the shared validator; restore_composition (#233) reports it via
        // _pp_composition_findings() but never blocks on it.
        if (isset($item['props']) && is_array($item['props']) && !empty($schema['props'])) {
            foreach ($schema['props'] as $prop_name => $prop_def) {
                if (($prop_def['type'] ?? null) !== 'enum'
                    || empty($prop_def['strict'])
                    || empty($prop_def['values'])
                    || !is_array($prop_def['values'])
                ) {
                    continue;
                }
                if (!array_key_exists($prop_name, $item['props'])) {
                    continue;
                }
                $value = $item['props'][$prop_name];
                if ($value === null || $value === '') {
                    continue; // unset sentinel — keeps the prop's default behavior
                }
                // Accepted set = advertised values + declared legacy aliases. The
                // error message names only `values`: an alias is accepted, never
                // advertised, so an agent reading the error is told what to WRITE.
                $accepted = $prop_def['values'];
                if (!empty($prop_def['aliases']) && is_array($prop_def['aliases'])) {
                    $accepted = array_merge($accepted, $prop_def['aliases']);
                }
                if (!in_array($value, $accepted, true)) {
                    $errors[] = new WP_Error(
                        'invalid_prop_value',
                        sprintf(
                            'Component "%s" prop "%s" must be one of: %s; got "%s".',
                            $name,
                            $prop_name,
                            implode(', ', $prop_def['values']),
                            is_scalar($value) ? (string) $value : gettype($value)
                        )
                    );
                    continue 2;
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
                if (!is_array($value)) {
                    $errors[] = new WP_Error(
                        'invalid_prop_value',
                        sprintf(
                            'Component "%s" prop "%s" must be an array of strings; got %s.',
                            $name,
                            $prop_name,
                            gettype($value)
                        )
                    );
                    continue 2;
                }
                if (isset($prop_def['max_items']) && count($value) > (int) $prop_def['max_items']) {
                    $errors[] = new WP_Error(
                        'invalid_prop_value',
                        sprintf(
                            'Component "%s" prop "%s" accepts at most %d items; got %d.',
                            $name,
                            $prop_name,
                            (int) $prop_def['max_items'],
                            count($value)
                        )
                    );
                    continue 2;
                }
                $item_max_length = isset($prop_def['item_max_length']) ? (int) $prop_def['item_max_length'] : null;
                foreach ($value as $entry) {
                    if (!is_string($entry)) {
                        $errors[] = new WP_Error(
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" items must be strings; got %s.',
                                $name,
                                $prop_name,
                                gettype($entry)
                            )
                        );
                        continue 3;
                    }
                    if ($item_max_length !== null && mb_strlen($entry) > $item_max_length) {
                        $errors[] = new WP_Error(
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" items must be at most %d characters; got %d.',
                                $name,
                                $prop_name,
                                $item_max_length,
                                mb_strlen($entry)
                            )
                        );
                        continue 3;
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
                    // "Reject non-scalars" (issue 507): a scalar (string/int/float/
                    // bool) coerces to text and renders as authored; an array/object
                    // renders as "Array" with a PHP warning. null is the unset
                    // sentinel; the empty string is a valid string value (is_scalar).
                    if ($value !== null && !is_scalar($value)) {
                        $errors[] = new WP_Error(
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" must be a string; got %s.',
                                $name,
                                $prop_name,
                                gettype($value)
                            )
                        );
                        continue 2;
                    }
                } elseif ($declared_type === 'number') {
                    // Reject non-numerics. Numeric strings are accepted (a JSON/CLI
                    // write sends "3"; the #379 bounds family already accepts them
                    // for grid.columns, so this stays consistent). null/'' are the
                    // unset sentinel (keeps the prop default, e.g. logo_id => 0).
                    if ($value !== null && $value !== '' && !is_numeric($value)) {
                        $errors[] = new WP_Error(
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" must be a number; got %s.',
                                $name,
                                $prop_name,
                                is_scalar($value) ? '"' . (string) $value . '"' : gettype($value)
                            )
                        );
                        continue 2;
                    }
                } elseif ($declared_type === 'array') {
                    // Reject scalars where an array belongs. null/''/[] are the unset
                    // sentinel (an empty row renders nothing). A present scalar is the
                    // silent-wrong case the renderer's is_array() guards swallow.
                    if ($value !== null && $value !== '' && $value !== [] && !is_array($value)) {
                        $errors[] = new WP_Error(
                            'invalid_prop_value',
                            sprintf(
                                'Component "%s" prop "%s" must be an array; got %s.',
                                $name,
                                $prop_name,
                                gettype($value)
                            )
                        );
                        continue 2;
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
                            $is_object_shape = is_array($entry)
                                && ($entry === [] || !pp_is_list($entry));
                            if (!$is_object_shape) {
                                $errors[] = new WP_Error(
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s must be an object; got %s.',
                                        $name,
                                        $prop_name,
                                        is_scalar($entry_index) ? (string) $entry_index : gettype($entry_index),
                                        gettype($entry)
                                    )
                                );
                                continue 3;
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
                                $errors[] = new WP_Error(
                                    'invalid_prop_value',
                                    sprintf(
                                        'Component "%s" prop "%s" item %s must be an array; got %s.',
                                        $name,
                                        $prop_name,
                                        is_scalar($entry_index) ? (string) $entry_index : gettype($entry_index),
                                        gettype($entry)
                                    )
                                );
                                continue 3;
                            }
                        }
                    }
                }
            }
        }

        // NESTED item-field contracts (issue #579, A-27). The families above walk
        // TOP-LEVEL props only, so two schema annotations one level down were
        // DECLARED and enforced by NOTHING:
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
        //
        // Enforced HERE, in the shared validator — no second validator, no
        // per-component branch. Walked at the SAME one-items-level depth as the #154
        // media-URL and #507 link_url families.
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
                foreach ($entries as $entry_index => $entry) {
                    if (!is_array($entry)) {
                        continue; // non-object entry — item_type: "object" owns that error
                    }
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
                            $errors[] = new WP_Error(
                                'invalid_composition',
                                sprintf(
                                    'Component "%s" prop "%s" item %s is missing required field "%s".',
                                    $name,
                                    $prop_name,
                                    is_scalar($entry_index) ? (string) $entry_index : gettype($entry_index),
                                    $field_name
                                )
                            );
                            continue 4;
                        }
                        if (($field_def['type'] ?? null) === 'array'
                            && ($field_def['item_type'] ?? null) === 'string'
                            && array_key_exists($field_name, $entry)
                            && is_array($entry[$field_name])
                        ) {
                            foreach ($entry[$field_name] as $bullet) {
                                if (!is_string($bullet)) {
                                    $errors[] = new WP_Error(
                                        'invalid_prop_value',
                                        sprintf(
                                            'Component "%s" prop "%s" item %s field "%s" items must be strings; got %s.',
                                            $name,
                                            $prop_name,
                                            is_scalar($entry_index) ? (string) $entry_index : gettype($entry_index),
                                            $field_name,
                                            gettype($bullet)
                                        )
                                    );
                                    continue 5;
                                }
                            }
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
                    $errors[] = new WP_Error(
                        'invalid_prop_value',
                        _pp_link_url_error_message($name, $prop_name, null, null, $item['props'][$prop_name])
                    );
                    continue 2;
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
                                    $errors[] = new WP_Error(
                                        'invalid_prop_value',
                                        _pp_link_url_error_message($name, $prop_name, (int) $entry_index, $field, $entry[$field])
                                    );
                                    continue 3;
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
                $errors[] = $style_error;
                continue;
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
                        (int) $elem_index
                    );
                    if (is_wp_error($style_error)) {
                        $errors[] = $style_error;
                        continue 3;
                    }
                }
            }
        }
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
    foreach (pp_find_duplicate_component_ids($items) as $dupe) {
        $errors[] = new WP_Error(
            'duplicate_component_id',
            sprintf(
                'Duplicate component id "%s" on items %s. Component ids must be unique within a composition so update/remove/style can target one component.',
                $dupe['id'],
                implode(', ', $dupe['indices'])
            )
        );
    }

    return $errors;
}

/**
 * Validates a decoded composition array against the component registry.
 *
 * First-error-wins: returns the first violation in document order, exactly as it always
 * has. Every write-time caller (create_page, update_composition, add_component,
 * update_component, the editor save) depends on this shape. Use
 * pp_validate_composition_errors() when you need the complete set instead.
 *
 * @param  array            $items  Decoded composition array.
 * @return true|WP_Error
 */
function pp_validate_composition(array $items) {
    $errors = pp_validate_composition_errors($items);

    return $errors === [] ? true : $errors[0];
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

add_action('wp_ajax_pp_save_composition', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

    if (!$post_id || !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'pp_composition_' . $post_id)) {
        wp_send_json_error('Invalid nonce.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Insufficient permissions.');
    }

    $raw     = isset($_POST['composition']) ? stripslashes($_POST['composition']) : '';
    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        wp_send_json_error('Invalid JSON.');
    }

    // Optimistic-locking baseline (#13): the version the editor loaded. Threaded into the
    // action for an atomic compare-and-swap so a save that would clobber an interleaved
    // write (the AI chat, a CLI action, another tab) is rejected with composition_conflict.
    // Absent/empty → null → the write skips the CAS (documented back-compat).
    $params = ['post_id' => $post_id, 'composition' => $decoded];
    $expected_version = _pp_expected_version_from_request($_POST);
    if ($expected_version !== null) {
        $params['expected_version'] = $expected_version;
    }

    $result = pp_execute_action('update_composition', $params);

    if (!$result['ok']) {
        // Structured payload so the editor can key on the code (composition_conflict →
        // reload prompt) rather than parsing the human message (#13).
        wp_send_json_error(['message' => $result['error'], 'code' => $result['error_code'] ?? '']);
    }

    // Auto-draft → draft promotion happens inside pp_execute_action() itself
    // (lib/actions.php) — one place, covering AJAX/CLI/operate.php alike.

    $saved = pp_get_composition($post_id);
    wp_send_json_success([
        'composition' => $saved,
        // Return the new baseline so the editor advances currentVersion and a follow-up
        // save doesn't false-conflict against its own prior write (#13).
        'version'     => pp_get_composition_marker($post_id)['version'],
    ]);
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

    $raw        = get_post_meta($post_id, '_pp_composition', true);
    // Pretty-print stored JSON so the editor shows readable multi-line content.
    // The editor shows the STORED bytes, re-encoded (#604). It used to migrate a
    // legacy `variant` key out of the decoded view first (#69/#388 read path); that
    // migration is gone, so a pre-rename page now surfaces `variant` verbatim in the
    // editor exactly as it sits in the database. That is the honest view: `variant`
    // is rejected on every write path, so showing the operator a `layout`/`theme`
    // shape the stored document does not actually have was the editor telling a
    // small lie about storage. Saving such a page fails with `unknown_prop` and
    // names the offending key — the intended, loud outcome.
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
    }
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
                        ><?php echo esc_textarea($raw ?: ''); ?></textarea>
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
        wp_send_json_error($result->get_error_message());
    }

    $dir_uri = get_template_directory_uri();

    ob_start();
    try {
        pp_get_component('nav', ['location' => 'primary']);
        echo '<main id="main">';
        foreach ($composition as $item) {
            $name  = isset($item['component']) ? (string) $item['component'] : '';
            $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
            $style = isset($item['style']) && is_array($item['style']) ? $item['style'] : [];
            if ($style) {
                $props['__pp_style'] = $style;
            }
            if ($name !== '') {
                pp_get_component($name, $props);
            }
        }
        echo '</main>';
        pp_get_component('footer', ['location' => 'footer']);
    } catch (Throwable $e) {
        ob_end_clean();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_send_json_error('Render failed: ' . $e->getMessage());
        }
        wp_send_json_error('Render failed.');
    }

    $body = ob_get_clean();

    $html = '<!DOCTYPE html><html><head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/base.css">'
        . '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/components.css">'
        . '<link rel="stylesheet" href="' . esc_url($dir_uri) . '/assets/css/utilities.css">'
        . '</head><body>' . $body . '</body></html>';

    wp_send_json_success(['html' => $html]);
});

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
        wp_send_json_error($result['error']);
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
            wp_send_json_error(['message' => $save_result['error'], 'code' => $save_result['error_code'] ?? '']);
        }
    }

    // Publish the page.
    $pub_result = pp_execute_action('publish_page', ['post_id' => $post_id]);
    if (!$pub_result['ok']) {
        wp_send_json_error(['message' => $pub_result['error'], 'code' => $pub_result['error_code'] ?? '']);
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
    ]);
});

