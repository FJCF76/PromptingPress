# `ppfixture` — a test-only component, and the date of its own death

**This component is never registered in production.** It exists only inside the fixture
theme root that `tests/support/FixtureTheme.php` builds, and only for suites that opt in.

## Why it exists

The slot-engine suites — `pp_render_style_vars()`, `style_component`,
`invalid_style_slot`, the friendly-error slot context, the envelope findings — are about
the ENGINE, not about any particular component. But every one of them has to name a real
component as its fixture, so **every v2 rebuild re-homed the whole set to whichever
component had not been rebuilt yet**:

- hero's rebuild (#986) moved them `hero` → `section` (~541 changed lines in `ActionsTest`
  alone, almost all of it that swap).
- section's rebuild (#1023) moved them `section` → `stats`, and measured the cost:
  **~274 of the 283 failures that rebuild produced were this class**, across ~35 files.
- stats' rebuild (#1066 PR2) is where the treadmill stops. Re-homing once more would have
  meant a fourth move, and then a fifth.

That is #1025. The issue originally said "there is no seam for registering a fixture
component"; that was **wrong**, and the correction is what made this affordable.
`pp_get_registered_components()` caches keyed by theme root (`lib/admin.php`), derives
that root from `get_template_directory()`, and `tests/bootstrap.php` already stubs that
to read `$GLOBALS['_pp_test_template_dir']`. Eight suites already set the cache-buster,
and `PreflightTest` already repoints the root at a temp directory. The seam was in
production use the whole time; what was missing was this fixture and a helper.

## When it is deleted — planned, not discovered

**`ppfixture` and every suite that targets it are deleted TOGETHER when `grid` is rebuilt
on the Universal Design Contract.**

That is not a guess about the future. `grid` is the last component still on style slots:
hero (#986), section (#1023), cta (#1026), faq (#1046), and `table` / `embed` / `stats` /
`logos` (#1066) have all moved. When `grid` moves, the style-slot engine has no shipped
consumer left — `pp_render_style_vars()`, `invalid_style_slot`, the slot advisories and
the friendly-error slot context all become dead code, and a fixture that exists to test
dead code is worse than no fixture: it keeps a retired mechanism looking alive and green.

So the rule for whoever rebuilds `grid`: **delete this directory, delete
`tests/support/FixtureTheme.php`, and delete the suites that opt into it — in the same
change that retires the engine.** Do not re-home them again, and do not keep the fixture
"just in case". If some engine behaviour genuinely outlives `grid`, it has a real
consumer and should be pinned against that consumer instead.

`grid` is gated on Addendum B / #1024, which is why it was never an eligible host for the
re-homing this fixture replaces.

## It does NOT work for Playwright, and that is structural

**PHPUnit only.** The seam works by repointing `get_template_directory()` through a stub in
`tests/bootstrap.php`, which exists only inside the PHP test process. The e2e suite drives a
REAL WordPress install (wp-env serves the repo directory as the active theme), so nothing in
that process ever reads the fixture root and `ppfixture` is not a registered component there.

So an e2e test that needs a slot-bearing host must use **`grid`** — the last shipped
component that declares style slots. That is a smaller treadmill than the one this fixture
ends (one component left, not six), but it is a treadmill: when `grid` rebuilds, any e2e test
still hosting on it has no host at all, and the honest move at that point is to delete it
along with the slot engine rather than invent an e2e fixture theme.

Discovered at #1066 PR2, when `validation.spec.ts`'s broken-media test — whose own comment
says the signal it tests is component-agnostic — failed after stats rebuilt. It hosts on grid
now.

## What it deliberately does NOT do

- It does not fake the engine. `ppfixture.php` renders the `__pp_style` map through the
  same `pp_render_style_vars()` call every shipped v1 component uses, carries #708's
  `is_array` guard verbatim, and emits its theme class through `pp_theme_class()`. A
  fixture that shortcut those would prove nothing about the code under test.
- It does not appear in a production registry read. A registry read WITHOUT the opt-in
  must not contain `ppfixture`, and that invisibility is pinned in
  `tests/FixtureThemeSeamTest.php` rather than assumed — including against the
  registry-iterating tests, which would otherwise start asserting about a component that
  does not ship.
- It carries no `roles` block. It stands in for a **v1** component, because v1 is what the
  slot engine serves.

## Recipes (added #1101)

RECIPES MOVED HERE AT #1101, from grid. The recipe engine — style_component's `recipe` parameter, its expansion into slot values, the `invalid_recipe` refusal and the recipe roster `wp pp operate inspect` reports — is live production code, and grid was its LAST shipped declarer (3 recipes; cta's two retired with its slot map at #1026). Retiring the tests with grid would have left that engine with no end-to-end coverage at all, so the declarations move to the fixture that exists for exactly this, on the #1025 pattern. Three recipes rather than one, because the suites that read them count the roster. They die with this fixture in the v1 machinery sweep, when whether the recipe engine has any consumer left is a measurement rather than an assumption.

They are declared in `schema.json` rather than described here only, because the engine
reads declarations: a recipe documented in prose expands into nothing.

**Why the roster is three and not one.** `ActionsTest::testInspectCompositionShowsAvailableRecipes`
asserts a COUNT, because a roster that silently shrank to one would still look like
coverage. Three is what grid shipped, so the count claim is unchanged by the move.

**Why there is no `_note` key in the recipe map.** The engine treats every key under
`styling.recipes` as a recipe name, so a `_note` key is reported as a fourth recipe and
offered to an author who cannot apply it. That is the accepted-stored-ignored shape this
codebase refuses everywhere else — caught here by the count assertion above, which read 4.
