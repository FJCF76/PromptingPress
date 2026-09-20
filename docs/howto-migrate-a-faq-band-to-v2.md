# How to migrate a `faq` band to the design contract

You will take a page whose FAQ accordion was written against the v1 style-slot system and
get it validating and rendering again on v2. The end result: the band writes cleanly,
`wp pp check page` reports nothing at error severity, and the design you had is expressed
as `udc` role maps.

There is **no automatic migration and there will not be one** — v2 is a fresh-build system
by design. What there is instead: every retired name refuses with a message naming the
surface that replaced it, so the page tells you what to write.

**Read [the section how-to](howto-migrate-a-section-band-to-v2.md) first if you have not
migrated a band before.** The mechanics are identical and are not repeated here; this
document covers what is DIFFERENT about faq, which is the part that will surprise you.

## Prerequisites

- PromptingPress 2.0.0-alpha.2 or later.
- A run token and a completed preflight — see
  [the tutorial's step 1](tutorial-style-a-band-on-the-design-contract.md#step-1-get-a-run-token-and-open-the-gate).
  Below, `$RID` is that token and `$PID` the page.
- Familiarity with [faq's roles](../components/faq/README.md).

## Step 1: Find out what is actually broken

```bash
wp pp check page --post_id=$PID
```

| Code | Means |
|---|---|
| `retired_prop` | the one prop faq used to declare: `theme`. The message names its v2 route. |
| `invalid_style_slot` | a `--faq-*` slot. All 21 are gone. |
| `unknown_prop` | a typo, or a name that never existed. Not a migration item. |

Nothing here blocks reading the page, and none of it blocks `restore_composition` — undo
always works, by rule.

## Step 2: The mapping, slot by slot

Most of it is mechanical: find the element the slot named, find the role whose selector is
that element, put the value in the group that owns the property.

| v1 slot | v2 |
|---|---|
| `--faq-padding-top` / `-bottom` | `_band` → `spacing.padding-top` / `padding-bottom` |
| `--faq-bg` | `_band` → `background.fill` |
| `--faq-item-bg` | `item` → `background.fill` |
| `--faq-item-border-color` | `item` → `border.color` |
| `--faq-item-radius` | `item` → `border.radius` |
| `--faq-eyebrow-color` / `-bg` | `eyebrow` → `typography.color` / `background.fill` |
| `--faq-eyebrow-radius` | `eyebrow` → `border.radius` |
| `--faq-eyebrow-border-width` / `-color` | `eyebrow` → `border.width` / `border.color` |
| `--faq-eyebrow-text-transform` | `eyebrow` → `typography.transform` |
| `--faq-heading-size` | `heading` → `typography.size` |
| `--faq-heading-color` | `heading` → `typography.color` **(see step 4)** |
| `--faq-heading-measure` | `heading` → `sizing.max-width` |
| `--faq-heading-margin-bottom` | `heading` → `spacing.margin-bottom` |
| `--faq-heading-accent-color` | `heading-accent` → `typography.color` |
| `--faq-question-color` | `question` → `typography.color` |
| `--faq-question-open-color` | `question-open` → `typography.color` **(see step 3)** |
| `--faq-answer-color` | `answer` → `typography.color` |
| `--faq-body-measure` | `answer` → `sizing.max-width` |

A worked example — a band that set a cream surface, tinted panels and an outlined orange
eyebrow, which is close to what the promptingpress.com FAQ bands do:

```json
"udc": {
  "_band":          { "background": { "fill": "#F2EEE5" },
                      "spacing": { "padding-top": "5.5rem", "padding-bottom": "5.5rem" } },
  "heading":        { "typography": { "size": "clamp(2rem, 3vw, 3rem)" } },
  "heading-accent": { "typography": { "color": "#FF5C2E" } },
  "eyebrow":        { "typography": { "color": "#FF5C2E" },
                      "background": { "fill": "#F2EEE5" },
                      "border": { "width": "1px", "color": "#0A0A12", "radius": "2px" } },
  "item":           { "background": { "fill": "#FBF8F1" },
                      "border": { "color": "#0A0A12", "radius": "2px" } }
}
```

## Step 3: The open state is a role, and it outranks the closed one

This is the part with no v1 analogue in shape, only in behaviour.

`--faq-question-color` and `--faq-question-open-color` were positional twins: set one and
not the other, and your colour reverted the moment a reader opened the item. That is still
true, and it is now structural rather than a convention — `question-open` selects
`.faq__item[open] > .faq__question`, one compound heavier than `question`, so it wins
whenever an item is open.

```json
"udc": {
  "question":      { "typography": { "color": "#1f2937" } },
  "question-open": { "typography": { "color": "#f2622a" } }
}
```

**The same ranking applies to states, and this is the one that catches people.** A
`:hover` or `:focus-visible` map on `question` reaches a closed row and NOT an open one:

```json
"udc": {
  "question":      { "typography": { ":hover": { "color": "#000" } } },
  "question-open": { "typography": { ":hover": { "color": "#000" } } }
}
```

You do not need a chevron value. The chevron is drawn with currentColor borders, so it
follows whatever these two roles resolve to.

## Step 4: `theme` takes three groups, not one

The refusal message names all of them, but it is worth understanding why, because a
background-only migration silently drops a border:

| your old `theme` | what it drew | write instead |
|---|---|---|
| `default` | `@color-surface`, no border | nothing — those are the defaults |
| `muted` | the same fill **plus a 1px rule top and bottom** | `_band` → `border.width-top` / `width-bottom` = `"1px"`, `style-top` / `style-bottom` = `"solid"`, `color` = `"@color-border"` |
| `inverted` | dark fill, light heading | `_band` → `background.fill` **and** `typography.color` |

On `inverted`, setting `_band` → `typography.color` is enough for the HEADING: it defaults
to `currentColor`, so it follows the band. It is deliberately NOT enough for the question
and the answer — see step 5 — and it is not enough for the empty state either, which
step 5 closes at the end.

## Step 5: If you darken the panels, you own the ink inside them

v1 kept the accordion items light even on an inverted band, the same choice grid makes for
its cards. That is still the default, and it is why `question` and `answer` pin their
colours instead of following the band.

So a dark BAND with items is one write:

```json
"udc": { "_band": { "background": { "fill": "#0f172a" }, "typography": { "color": "#fcfdff" } } }
```

but dark PANELS are five — and the last two are the ones that are easy to miss:

```json
"udc": {
  "item":          { "background": { "fill": "#111827" }, "border": { "color": "#374151" } },
  "question":      { "typography": { "color": "#f9fafb" } },
  "question-open": { "typography": { "color": "#a5b4fc" } },
  "answer":        { "typography": { "color": "#d1d5db" } },
  "answer-link":   { "typography": { "color": "#fcd34d", ":hover": { "color": "#ffffff" } } }
}
```

Miss the ink and you get dark text on a dark panel. Miss `question-open` specifically and
the band looks right until a reader OPENS an item, at which point the row reverts to the
default accent — **measured at 3.21:1 against a `#111827` panel**, which is a real contrast
failure that only exists in the open state. That number is not hypothetical: it is what the
three-role version of this example rendered when it was checked at 375 and 1280.

**`answer-link` is the fifth write, and it is new (#1069).** This example shipped with four
roles and a link in any answer stranded at **the same 3.21:1** — the number above, on a
different element, from the same map. `answer`'s selector is the container, so its colour
reaches an `<a>` only by inheritance, and the stylesheet's own `a` rule matches the anchor
directly; a direct declaration beats an inherited one whatever the cascade layer. Set the
`:hover` too: every anchor also gets an accent hover, so re-inking only the rest state flips
the link back under the cursor. If your answers carry no links, the write is harmless — the
role emits nothing until something matches it.

### The empty state is the one a dark band leaves behind

Everything above assumes the band HAS items. A faq band with none renders one line —
`.faq__empty` — and that line follows neither the band nor the panels:

```json
"udc": {
  "_band": { "background": { "fill": "#0f172a" }, "typography": { "color": "#fcfdff" } },
  "empty": { "typography": { "color": "#d1d5db" } }
}
```

Without the second key the line keeps its default `@color-muted`. That is not the band
colour failing to inherit by accident — a role default emits as a DIRECT declaration
(`[data-pp-component="faq"] .faq__empty{color:var(--color-muted);}`, unlayered), and a
direct declaration always beats an inherited one, whatever the band says. Measured, the
line resolves to `#5e6677` on `#0f172a`: **3.10:1**, under the 4.5:1 AA floor for body
text.

It is easy to miss because an authored band usually has items, so the failing line is
invisible until the day someone empties it — a band awaiting content. Set `empty` whenever
you set a dark `_band` fill.

Note the narrower bound, measured: a band whose items are all SKIPPED as damaged does not
reach this role at all. `faq.php` gates the empty state on `!empty($items)` and skips per
item on `if (!$question) continue;`, so a non-empty array of damaged items renders an empty
`.faq__list` with no message — the `empty` role cannot paint there, and neither can a
colour you set on it. Pre-existing render behaviour, recorded on #1051.

The band will validate, write and report `ok` in every one of these cases. The engine does
not interpret colours, so this is the one check that is yours rather than the system's.

## Clearing the stored slot map — send every key in ONE call

A pre-#1046 band stores two retired things, and the repair has to clear BOTH. Nulling the
prop alone is refused:

```json
{"props": {"theme": null}}
```

is rejected with `invalid_style_slot`, because the band still stores `--faq-*` keys — and
**the refusal names one slot at a time**. Measured on a band carrying six of them, following
each refusal literally takes seven round trips; a fully styled band carrying all 21 takes
twenty-two. Nothing persists until the last one.

Send the whole repair at once instead:

```json
{"props": {"theme": null},
 "style": {"--faq-bg": null, "--faq-item-bg": null, "--faq-question-color": null,
           "--faq-answer-color": null, "--faq-heading-size": null, "--faq-padding-top": null}}
```

List every `--faq-*` key the band actually stores — which is the set you need, not the
full 21. **Read the band back first with `wp pp operate inspect`** and null exactly the keys
it reports; guessing from a list is both slower and wrong, because a band typically stores a
handful. (The 21 retired names are not published in one operator-facing place; the mapping
table in step 2 above covers the ones you are likely to meet, and the stored map is
authoritative for your band.)

The one-at-a-time refusal is shared engine behaviour, not faq's — a legacy `cta` band
refuses identically — and the `retired_prop` message's "this band can be repaired on its
own" is true of the prop but not of a band that also stores a slot. Both are recorded on
#1064.

## Step 6: Verify

```bash
wp pp check page --post_id=$PID
wp pp operate inspect-composition --post_id=$PID --run_id=$RID
```

Then look at the page at 375 and at 1280, and **open an item**. Three things to check that
a validator cannot:

1. the summary's ink changes when the item opens (step 3),
2. the answer text is readable against the panel (step 5),
3. the accordion still opens at 375 — authored values must not disturb the native
   `<details>` disclosure, which is the browser's behaviour and not the theme's.

## What you cannot express, and what to do instead

- **The disclosure chevron's box or stroke.** It is a `::before`/`::after` pseudo-element
  and no role can address one yet (ruling A3). Its INK is authorable through the two
  question roles.
- **The open animation's timing.** `motion` carries `transition-duration` and
  `timing-function`; an animation's keyframes are neither.
- **A different disclosure glyph.** Same deferral as the chevron.
- **Lists inside an answer.** Authored `<ul>`/`<ol>` render with the global reset, as they
  did on v1.

## Related

- **[faq's roles](../components/faq/README.md)** — every role, its selector and its defaults.
- **[How to migrate a `cta` band](howto-migrate-a-cta-band-to-v2.md)** — the same mechanics
  with a different surprise (its buttons).
- **[How to migrate a `section` band](howto-migrate-a-section-band-to-v2.md)** — the
  mechanics in full.
- **[Style a band on the design contract](tutorial-style-a-band-on-the-design-contract.md)**
  — if you are writing a NEW band rather than migrating one.
