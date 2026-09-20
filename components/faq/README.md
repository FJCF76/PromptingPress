# Component: faq

FAQ accordion using native HTML `<details>`/`<summary>` elements. No JavaScript required. Fully accessible: keyboard-navigable and screen-reader friendly out of the box.

**This is a v2 component.** It declares no style slots. Every designable value — colour, type, spacing, border, shadow, size, motion — is set through the `udc` map on the band, per role. See `docs/v2/BUILD-SPEC-sprint0.md` §3 and `docs/explanation-cascade-layers.md`.

## Props

| Prop           | Type   | Required | Default                          | Description |
|----------------|--------|----------|----------------------------------|-------------|
| `id`           | string | No       | `''`                             | HTML id for anchor linking; also becomes the stable component id |
| `title`        | string | No       | `'Frequently Asked Questions'`   | Section heading |
| `title_accent` | string | No       | `''`                             | Exact substring of `title` to render in an accent color |
| `eyebrow`      | string | No       | `''`                             | Short kicker/label rendered as a pill above the title |
| `items`        | array  | Yes      | —                                | Array of `{ question, answer }` objects |

Each item in `items`:

| Key        | Type   | Required | Description |
|------------|--------|----------|-------------|
| `question` | string | Yes      | The question text shown in the summary/toggle |
| `answer`   | string | Yes      | The answer HTML shown when expanded |

### One prop that used to exist

**`theme`** is retired (#1046). It was a bundle of band values, which is what the `_band`
role expresses directly — but the route needs three groups rather than one, because all
three of its settings were measured before it went and only one of them was a background:

| v1 `theme` | what it rendered | v2 |
|---|---|---|
| `default` | `@color-surface`, no border on any edge | the `_band` role's defaults — nothing to write |
| `muted` | the same fill **plus a 1px solid `@color-border` rule top and bottom** | `_band` → `border.width-top` / `width-bottom` = `"1px"`, `style-top` / `style-bottom` = `"solid"`, `color` = `"@color-border"` |
| `inverted` | `@color-bg-inverted` fill, heading re-coloured to `@color-bg` | `_band` → `background.fill`, plus `typography.color` — the `heading` role follows it through `currentColor`, but `heading-accent` does NOT (it pins `@color-accent`, landing at 3.23:1 on a dark band: still clear of the 3:1 large-text floor at the heading's 28px minimum, but an 80% cut in margin beside a 17.54:1 heading) |

`dark` was never an accepted input (removed at #605) and is not part of the route.

**Why the question, the answer and the items are not in that table:** v1's inverted band
left all three untouched, because the accordion items keep their own light fill. That is
still the default, and it is why those roles pin their colours rather than following the
band — see `item` below.

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, radius, shadow, and the band ink the heading follows |
| `eyebrow` | `.faq__eyebrow` | the kicker pill: background, border, radius, casing, ink |
| `heading` | `.faq__heading` | the `<h2>`: size, weight, leading, measure, rhythm, ink |
| `heading-accent` | `.faq__heading-accent` | the accented substring's ink |
| `list` | `.faq__list` | the gap between accordion items |
| `item` | `.faq__item` | one `<details>` box: border, radius, fill |
| `question` | `.faq__question` | the `<summary>` row **at rest** — ink, type, padding, gap |
| `question-open` | `.faq__item[open] > .faq__question` | the `<summary>` row **when its item is open** |
| `answer` | `.faq__answer` | the answer body: ink, type, padding |
| `answer-link` | `.faq__answer a` | links inside the answer — **no defaults**, and the only address that reaches them |
| `empty` | `.faq__empty` | the "No questions yet." line |

**Which roles carry `layout` (#1084):** `list`, `question`. Exposure is a box fact — a role carries the group when its own structural CSS makes the box a flex or grid container — and this list is checked against a derivation from the stylesheet (tests/js/css-lint.test.js), in both directions, so it cannot drift from the schema or the CSS. `align-self` is NOT in this group: a box placing ITSELF is `sizing.align-self`, available on any role with `sizing`.

### The two roles that are one control

`question` and `question-open` are **positional twins**, and the second outranks the first:
its selector carries one more compound, so an authored resting colour reverts the moment a
reader opens the item. Set both or neither.

```json
"udc": {
  "question":      { "typography": { "color": "#1f2937" } },
  "question-open": { "typography": { "color": "#f2622a" } }
}
```

The same ranking applies to STATES, which is easier to miss: a `:hover` or
`:focus-visible` map on `question` reaches a closed row and not an open one. Set it on
`question-open` too when it has to survive opening.

### `question-open` is the theme's only ancestor-ATTRIBUTE role

Its selector is not a state in the engine's state dimension — ruling A3 defers ancestor
states — so it is a role of its own, the same shape `nav`'s `link-current` takes for the
current page. The role-selector charset was widened by two characters at #1046 so it could
be spelled; that widening admits attribute PRESENCE terms only, never value matches.

### Why darkening an item is a two-part write

`item` → `background.fill` is `@color-bg`: the accordion panels stay light even on a dark
band, which is what v1 did and what grid still does with its cards. So `question` and
`answer` pin their ink rather than following the band. **If you darken the item, set
`question`, `question-open`, `answer` AND `answer-link` in the same write** — otherwise dark
text stays on a dark panel.

**`answer-link` is the fourth write, and it is new (#1069).** Before it, this README and the
schema both said an authored colour on `answer` covered the links inside it. That was false:
`answer`'s selector is the container, so it reaches an `<a>` only by inheritance, and
base.css's `a { color: … }` matches the anchor *directly* — a direct declaration beats an
inherited one whatever the cascade layer. Measured at 1280, the darkened-item write these
docs prescribe left a link at **3.21:1** while the prose around it reached 14.33:1, reported
accepted with no findings. Set `answer-link` → `typography.color` and its `:hover` too.

`answer-link` declares **no defaults**, on purpose: a default would restate base.css's anchor
values as an unlayered declaration and outrank the premium button rules, repainting an
author-written `<a class="btn">` inside an answer (the #545 defect through a role selector).
Empty defaults emit nothing at rest, so an unauthored link keeps the global treatment.

A dark BAND on its own is one write (`_band` → `typography.color`), because
the heading follows it through `currentColor`.

`question-open` is the one people leave out, and it fails in the state nobody screenshots:
the band reads correctly closed, and the first click drops the row back to the default
accent — measured at **3.21:1** against a `#111827` panel.

## Usage

```php
pp_get_component('faq', [
    'title' => 'Common Questions',
    'items' => [
        [
            'question' => 'Does this require ACF?',
            'answer'   => 'No. pp_field() returns null when ACF is not installed.',
        ],
    ],
]);
```

## Accessibility

- Uses `<details>`/`<summary>` — browser-native accessibility. No ARIA attributes needed.
- Keyboard: `Enter` or `Space` toggles open/closed. `Tab` navigates between items.
- The summary reserves a 44px touch target (WCAG 2.5.5) in the stylesheet — but an
  authored `question` -> `sizing.min-height` emits unlayered and OUTRANKS it (measured:
  `12px` validates, emits, and renders). Treat 44px as an obligation you must not go
  under, not a floor the component enforces. The comparison is not clean either way, measured: only hero's
  `cta-secondary` and nav's `logo` carry a 44px `sizing.min-height` ROLE DEFAULT, while
  hero's PRIMARY `cta` role declares no defaults and takes its target from the shared
  `.btn` rule in `pp-v1`, and nav's `.nav__toggle` keeps its 44x44 in the stylesheet — the
  same placement as faq's, and reachable the same way, since the `toggle` role declares
  `sizing` too. Which pattern the theme wants is filed as an open question.
- The obligation binds BOTH twins: `question-open` declares `sizing` too and outranks
  `question`, so 44px on `question` alone still permits a 12px open row.
- Over a background image with a scrim, the focus ring routes to the on-overlay accent —
  emitted by the engine (`data-pp-band-overlay`), never something an author must switch on.
- Empty state shows a friendly message rather than an empty section.

## Structured data

Emits a `FAQPage` JSON-LD `<script>` block as the **last child inside the
component's own `<section>`**, not after it — zero-config, no toggle. An item whose
question or answer is empty is skipped, and since #742 so is one whose value is
DAMAGED — a non-scalar question/answer, or an entry that is not an array — which
degrades to empty and is therefore skipped by that same rule rather than fataling the
page or reaching the payload as the literal word `Array`. When no item survives, no
`<script>` is emitted at all. A `<script>` is
metadata content, valid anywhere in the body flow, and Google reads `ld+json` from
anywhere in the DOM, so SEO is unaffected. The placement is load-bearing for layout,
not for SEO: emitted as a trailing *sibling* of `</section>` the script became the
previous element sibling of the next band, so the `main > [data-pp-component] + .band`
adjacency selector missed that band and it fell back to its own larger top padding
(#432). `question`/`answer` are stripped of any HTML before encoding (Google's FAQPage
schema expects plain text). Items missing a question or answer are skipped; nothing is
emitted if no complete items exist. See `pp_render_faq_schema()` in `lib/wp.php`.

## What no role reaches

- **The disclosure chevron's box and stroke.** It is a `::after` pseudo-element and ruling
  A3 defers those. Its INK needs no role: it is drawn with currentColor borders, so it
  follows `question` and `question-open` for free.
- **The open animation's keyframes.** The motion group carries `transition-duration` and
  `timing-function` by ruling A3; an animation's keyframes are neither.
- **Lists inside an answer.** The shared prose mechanisms are scoped to section's content;
  an authored `<ul>` in an answer renders with the global reset, as it did on v1.

## Stated defaults (and what would reopen them)

Every default is what v1 RENDERED, read in Chromium at 375 / 768 / 1280 before it was
written into the schema — not what the stylesheet declared. The two differ whenever an
ancestor constrains a value, a media query scopes it, or a fallback is an inherited
keyword, and all three happened here.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| The disclosure chevron: `10px` box, `2px` stroke | The chevron is a **control affordance, not a style surface**, and its ink is authorable for free through the two question roles. Its box sits inside the ruled 44px touch target. | An operator needs a different disclosure **glyph** (plus/minus, caret) — a glyph question, not a size one. Ruling A3's pseudo-element deferral is the gate. |
| Question type `1rem` / `560` / `1.45` and answer type `1rem` / `430` / `1.68` at 768px and up | These are the `question` and `answer` roles' defaults, and they are **authorable** now — what is stated is the DEFAULT, not a limit. The pair distinguishes question from answer **at identical size**, using weight (560 vs 430) and leading alone; a question that is bigger as well as bolder reads as a heading, which an accordion row is not. | A measured report that the composed-page scale should change for every band, which is a theme-wide type decision rather than an faq one. |
| Mobile question `0.98rem` / `1.42` at 767px and below | The roughly 2% trim is what keeps a long question to two lines inside the 44px touch target on a 375px screen. It is the `p` tier of the role's breakpoint maps. | As above. |
| The open animation `faq-open 150ms ease` | Duration is not authorable and there is no recorded incident asking for it to be. `base.css` already collapses animation and transition durations globally under `prefers-reduced-motion`, so the accessibility case is covered without a per-component control. | A named incident where one band's disclosure timing must differ. |
| `empty` carries a colour default and the markup carries no utility class | The v1 markup put `text-muted` on this line, which hardcodes `--color-muted` from `pp-v1`. It would beat an INHERITED `_band` colour (an inherited value is used only when no declaration matches the element, so the layer never enters) but not a write aimed at `empty` itself (unlayered, matches directly). The `_band` route is the one it stranded. footer's rebuild met the same case at #994 and dropped the class so the role governs. | Nothing — this is the rule, not an exception. |

The bar for adding a control is a NAMED INCIDENT, not a hypothetical: a report where the
default produced a wrong render that no authored value could fix.

## Retired style slots

All 21 `--faq-*` slots are retired — not renamed and not deprecated. Every one is recorded
slot by slot in `SLOT_RENAME_MIGRATION_NOTES` in `tests/SchemaValidationTest.php`, each
naming the role and parameter that owns the value today. **Those per-slot routes are a test
fixture, not a runtime message:** `wp pp check page` reports a stored slot as
`invalid_style_slot` with the generic v2 clause, which says faq declares no style slots and
lists its ten roles. Read a role's real parameters from `schema.json`, or with
`wp pp schema faq`.

Three do not map one-to-one:

- `--faq-heading-color` → `heading` → `typography.color`, but the DEFAULT changed from a
  pinned token to `currentColor`, so an unauthored heading follows its band instead of
  pinning against it. Identical on every band v1 could express.
- `--faq-body-measure` → `answer` → `sizing.max-width`, which declares no default, because
  v1 declared `none` and rendered `none`.
- `--faq-question-open-color` → the `question-open` ROLE, which did not exist before this
  slot needed it.

## CSS

Structural CSS in `assets/css/components.css` under `/* === COMPONENT: faq === */`.
**Structural only** (BUILD-SPEC §2): layout scaffolding and accessibility affordances. No
colour, type, size, spacing, border or shadow value may be added there —
`tests/js/css-lint.test.js` enforces it on exactly that block.

## What NOT to change

- Do not replace details/summary with a JavaScript accordion. The native element is more accessible and requires no JS maintenance.
- Do not add design values to the stylesheet block. Every one belongs to a role.
