# `ppfixture` — a test-only component, and the condition of its own death

**This component is never registered in production.** It exists only inside the fixture
theme root that `tests/Support/FixtureTheme.php` builds, and only for suites that opt in.

## What changed at #1101, and why this file was rewritten rather than deleted

This fixture was created at #1025 to end a treadmill. The slot-engine suites —
`pp_render_style_vars()`, `style_component`, `invalid_style_slot`, the friendly-error slot
context, the envelope findings — were about the ENGINE, not about any particular
component, but each of them had to name a real component as its fixture. So **every v2
rebuild re-homed the whole set to whichever component had not been rebuilt yet**: hero's
rebuild (#986) moved them `hero` → `section`; section's (#1023) moved them `section` →
`stats` and measured the cost (**~274 of the 283 failures that rebuild produced were this
class**, across ~35 files); stats' (#1066) moved them here.

The old version of this file said what came next, and it was a reasonable prediction
rather than a rule: **"`ppfixture` and every suite that targets it are deleted TOGETHER
when `grid` is rebuilt."** `grid` was rebuilt at #1101 and the slot engine was retired with
it, so the first half happened exactly as written — the slot map, the recipes and every
suite that targeted them are gone from this directory and from the repo.

**The second half did not, and the difference is the point.** Deleting the whole fixture
would have taken down test methods whose subject is NOT the slot engine and which have no
shipped component to run against. Measured before the sweep began: stripping the
fixture's slots broke 157 methods, deleting the fixture outright broke 181, and that
twenty-four-method delta is the set with no other home. That is the #1038 class — a test
deleted with its subject that was actually carrying a live claim — and it is the single
risk this sweep was run to avoid. So the fixture survives in a STRIPPED form, and
the rule it lives under is no longer a date.

## Why it exists NOW

**To host a claim whose subject the shipped registry happens not to declare.** Not "to
stand in for a component that has not been rebuilt yet" — there are none left. The
distinction matters, because the old reason had an expiry date and this one does not: a
guard can be correct, load-bearing and permanent while every component that ships today
happens to avoid the shape it guards.

The claim it hosts today is **#705's raw-value guard before a typed escaper**. The shape:
a prop that carries a URL as TEXT, reaching `pp_esc_image_src()`. A non-empty ARRAY is
truthy in PHP, so a gate written as `if ($background_image)` passes on one and the typed
call fatals the whole page — and the guard's three gates (the inline declaration, the
modifier class and the overlay element) have to move together, or the band paints a dark
scrim over nothing while wearing the light ink the modifier selects.

No shipped component declares that shape any more. Every v2 band background is an
attachment ID on the `_band` role's `background.image`, which the engine resolves; the
last text-URL band background left `section` at #1023, `cta` at #1026 and `stats` at
#1066. The guard is still right, the escaper is still shared, and a future component that
takes a URL-shaped image prop would meet it — so the eight methods of
`tests/StoredBackgroundImageRenderGuardTest.php` that pin it kept a host instead of being
deleted with a subject that never died.

Whether they should retire BY DECISION rather than by losing their subject is a separate
question, filed as its own issue. They survive on this fixture until that is ruled.

## When it is deleted — a condition, not a date

**`ppfixture` and `tests/Support/FixtureTheme.php` are deleted when the LAST claim hosted
here either finds a real subject or is retired by decision.**

Two ways that happens, and both are good outcomes:

1. **A shipped component grows the shape.** If a component declares a text-URL image prop,
   the #705 guard has a real subject: move those methods onto it and delete this
   directory. A claim with a real subject is always worth more than the same claim on a
   fixture, because the fixture can drift out of agreement with what ships and nothing
   will notice.
2. **The claim is retired deliberately.** If the ruling is that a guard with no shipped
   subject should not be maintained, delete the methods AND this directory in the same
   change — and say in the commit that the coverage was given up on purpose, so a later
   reader does not reconstruct it as an accident.

**What must NOT happen is a third thing: a new claim moving in here because it is
convenient.** This directory is not a general-purpose component; it is a host of last
resort for a claim that has nowhere else to run. Before adding anything here, check
whether a shipped component can carry it. If one can, it should.

That rule is the same one the old file stated as "do not keep the fixture just in case" —
what has changed is only that "just in case" was the wrong test for a fixture that was
genuinely carrying something. The right test is whether the claim has a real subject
available, and the answer is written down above for each claim rather than assumed.

## It does NOT work for Playwright, and that is structural

**PHPUnit only.** The seam works by repointing `get_template_directory()` through a stub in
`tests/bootstrap.php`, which exists only inside the PHP test process. The e2e suite drives a
REAL WordPress install (wp-env serves the repo directory as the active theme), so nothing in
that process ever reads the fixture root and `ppfixture` is not a registered component there.

An e2e test that needs a specific component shape must therefore use a SHIPPED component.
That used to be a problem — `validation.spec.ts`'s broken-media test, whose own comment says
the signal it tests is component-agnostic, had to be re-homed every rebuild and was hosted on
`grid` as the last slot-bearing component. Since #1101 there is no slot-bearing component to
host on and the test hosts on an ordinary prop instead, which is where it should have been:
the e2e treadmill ended with the slot engine rather than needing a fixture theme of its own.

## What it deliberately does NOT do

- **It does not fake the engine.** `ppfixture.php` runs #705's raw-value guard verbatim and
  emits its theme class through `pp_theme_class()` (#570 DG-4). A fixture that shortcut
  those would prove nothing about the code under test.
- **It does not appear in a production registry read.** A registry read WITHOUT the opt-in
  must not contain `ppfixture`, and that invisibility is pinned in
  `tests/FixtureThemeSeamTest.php` rather than assumed — including against the
  registry-iterating tests, which would otherwise start asserting about a component that
  does not ship.
- **It declares no `roles` and no `style_slots`.** Not one, then the other: it carries no
  styling surface at all. A `roles` block would make it a UDC host, and every UDC claim has
  ten shipped components to run against — so a role here would be coverage invented for a
  component nobody uses, which is the shape this file exists to refuse.
- **It declares no `item_fields`.** That key was read by ZERO engine code and by no shipped
  schema; it was inert vocabulary that only this fixture spoke, and it went with the rest of
  the sweep at #1101.
- **It ships no recipes.** They moved here from `grid` earlier in #1101 so the recipe engine
  would not lose its last end-to-end coverage, and they were deleted later in the same issue
  once the engine itself went. That round trip is recorded rather than tidied away, because
  the reasoning that moved them in was sound on the information available at the time and
  the reasoning that deleted them is the measurement that followed.
