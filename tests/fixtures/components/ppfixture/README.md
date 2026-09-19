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
