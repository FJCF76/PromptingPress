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

**The second half did not, and the difference is the point** — as measured AT #1101; the
next section records what changed since. Deleting the whole fixture would have taken down
test methods whose subject is NOT the slot engine and which have no
shipped component to run against. Measured before the sweep began: stripping the
fixture's slots broke 157 methods, deleting the fixture outright broke 181, and that
twenty-four-method delta is the set with no other home. That is the #1038 class — a test
deleted with its subject that was actually carrying a live claim — and it is the single
risk this sweep was run to avoid. So the fixture survives in a STRIPPED form, and
the rule it lives under is no longer a date.

## Why it exists NOW — and why that reason is weaker than the old one

**The claim it was kept for is gone.** Until PR-3 of #1145 it hosted **#705's raw-value guard
before a typed escaper**: a prop carrying a URL as TEXT (`background_image`), reaching
`pp_esc_image_src()`. No shipped component has declared that shape since #1066 — every v2
band background is an attachment ID on the `_band` role's `background.image`, which the
engine resolves — so the owner ruled on #1108 (2026-09-24) to **retire the prop-grain guard
by decision.** The three `ComponentPropsTest` methods that pinned it on this fixture were
deleted, the fixture's `background_image` prop and guard went with them, and the live
escaper stays pinned where it is live: `UdcBackgroundImageTest` on the v2 `_band` ->
`background.image` path. The coverage was given up deliberately, not lost; a component that
takes a text-URL image prop again trips
`InvariantTest::testNoShippedComponentReadsTheRetiredBackgroundImageProp`, which says the
guard and its tests come back with the prop.

Its `theme` prop and the `pp_theme_class()` call went at #1111, when the owner retired the
whole `--dark` / `--inverted` output-name vocabulary: nothing ships that vocabulary any more,
so a fixture emitting it would pin a contract no component keeps.

**What is left is FILLER.** Measured at PR-3 of #1145 by deleting this directory in a scratch
copy of the shipped state: 24 methods still fail, and none of them is about this component.
They need *a* registered, composable band with a required prop and do not care which one —
`WriteEnvelopeFindingsTest` (10), `CompositionFindingsBoundsTest` (8),
`StoredCompositionAliasRenderTest` (2), `FixtureThemeSeamTest` (2, the seam itself) and
`StyleSlotContractTest` (2, pinning that this schema declares no slots or recipes). So the
#1101 section's "no shipped component to run against" no longer holds for any of them. That is
the "third thing" the section below forbids *new* claims from doing; these arrived before the
rule and have not been re-homed.

## When it is deleted — a condition, not a date

**`ppfixture` and `tests/Support/FixtureTheme.php` are deleted when the filler suites above
are re-homed onto a shipped component** (the seam and slot-contract methods go with the
fixture). That re-homing is tracked in #1164, filed from #1145's PR-3.

**What must NOT happen is a new claim moving in here because it is convenient.** This
directory is not a general-purpose component; before adding anything here, check whether a
shipped component can carry it. Ten can.

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

- **It does not carry retired surfaces.** No `theme` prop and no `--dark`/`--inverted`
  classes (#1111), no `background_image` prop or #705 guard (#1108), no slot map (#1101).
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
