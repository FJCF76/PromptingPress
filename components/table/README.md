# Component: table

Data or comparison table. The wrapper scrolls horizontally whenever the table is wider than the band — at **any** viewport, not only on mobile.

**This is a v2 component.** It declares no style slots. Every designable value — colour, type, spacing, border, shadow, size, motion — is set through the `udc` map on the band, per role. See `docs/v2/BUILD-SPEC-sprint0.md` §3 and `docs/explanation-cascade-layers.md`.

**It is also the only rebuild in the whole migration that retires nothing.** table never declared a `theme` prop and its `variant_classes` were already empty, so there is no tone bundle to translate and no `retired_props` block. Its entire change is slots to roles — and because v1 gave it only six slots while the table itself has eleven designable elements, the rebuild is mostly *new* surface rather than a migration. The table's own body type, caption ink, header fill, header ink and rule widths had **no authoring surface at all** before #1066.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `id`      | string | No       | `''`    | HTML id for anchor linking; also becomes the stable component id |
| `title`   | string | No       | `''`    | Section heading above the table |
| `headers` | array  | Yes      | —       | Array of column header strings. Plain text — HTML is escaped |
| `rows`    | array  | Yes      | —       | Array of rows; each row is an array of cell values. **Cells take rich HTML** (`wp_kses_post`) — block markup, lists and links, the same contract as `section.body` |
| `caption` | string | No       | `''`    | Accessible `<caption>`, rendered below the table. Plain text |

Note the split contract: **cells are rich, headers and caption are plain.** A `<strong>` in a header renders as visible characters.

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, radius, shadow, and the band ink the heading follows |
| `heading` | `.table-section__heading` | the `<h2>`: size, measure, rhythm, ink |
| `wrap` | `.table-wrap` | the frame around the table: border, radius, shadow |
| `table` | `.table` | the `<table>`: its fill and its body type size |
| `caption` | `.table__caption` | the `<caption>`: ink, size, padding, alignment |
| `head` | `.table__head` | the `<thead>` band's fill |
| `header` | `.table__header` | one `<th>`: ink, weight, alignment, padding, and the rule under the header row |
| `row` | `.table__row` | one `<tr>`: the separator between rows, and the hover tint |
| `cell` | `.table__cell` | one `<td>`: ink and padding |
| `cell-link` | `.table__cell a` | a link inside a cell's rich text. **Declares nothing** — see below |
| `empty` | `.table-section__empty` | the "No data." line a band with no headers or no rows renders |

### The ink split, which is the one thing to get right

**The table paints its own light surface, two layers deep, and that surface does not follow the band.**

```
.table-section              the BAND — transparent by default; you darken THIS
  .table-section__heading   on the BAND fill      -> follows, via currentColor
  .table-wrap               the frame
    .table                  fill @color-bg        LIGHT, stays light
      caption               OUTSIDE the table's background box -> on the BAND fill
      thead .table__head    fill @color-surface   LIGHT, stays light
        th .table__header   on the LIGHT head fill  -> ink PINS
      tbody tr .table__row
        td .table__cell     on the LIGHT table fill -> ink PINS
  .table-section__empty     on the BAND fill
```

So the roles fall into two groups that need **opposite** treatment:

- **On the table's own light surface, and correct as they are:** `header` and `cell` pin `@color-text`. A dark band must **not** re-ink them — that puts light text on a light table.
- **On the band fill, and stranded by a dark write:** `caption` and `empty`, both pinned `@color-muted`.

`caption` is the one that is easy to miss, and the reason is structural rather than visual: a `<caption>` box renders **outside** the table's background box (CSS 2.1 §17.4), so `table` → `background.fill` never paints behind it. It sits on the band fill like a bare paragraph. Measured: `@color-muted` (`#5e6677`) on an authored `@color-bg-inverted` band is about **3.1:1**, under the 4.5:1 floor for its 14px.

> **The fill and the ink are two writes.** `currentColor` on the `heading` role follows the band's
> `typography.color`, not its `background.fill`. A band given a dark fill and no ink keeps the inherited
> `@color-text` and renders its heading at about **1.04:1**. Write both, always.

**A dark table band therefore costs three writes:** `_band` → `background.fill`, `caption` → `typography.color`, `empty` → `typography.color`. If you *also* darken `table` or `head`, you owe `cell` and `header` ink too — and `caption` is not part of that second group, because it was never on those surfaces.

### The row separator moved to the top edge

v1 drew `border-bottom` on every row and then suppressed it with `.table__row:last-child { border-bottom: none }`. That pair cannot survive, and **the reason is the layer model rather than the selector**: the base separator becomes an in-band element role default, which emits **unlayered**, while a `:last-child` suppression would stay in `pp-v1` — and unlayered beats every layer at any specificity, so the suppression would simply stop working.

Moving the separator to the **top** edge reproduces v1 exactly, because `border-collapse: collapse` merges adjacent edges and the wider one wins: row 1's new 1px top edge merges into `header`'s 2px bottom rule and vanishes into it, and the last row simply has no bottom edge. Verified before it shipped — 1, 2 and 3 rows at 375/768/1280, with row boxes, gaps and painted screenshots all byte-identical to v1.

Three consequences of the collapsed model, none of which existed on v1:

- **`border.style-top` is load-bearing, not decoration.** `border-style`'s initial value is `none`, the *lowest* priority in the collapse model, so a width and a colour with no style paint **nothing**. Set them together.
- **`row`'s top rule and `header`'s bottom rule now share one edge.** `header`'s 2px beats `row`'s 1px today. If you set `header` → `border.width-bottom: "1px"` you create a width tie, which CSS 2.1 §17.6.2 breaks by *element* rank — so the `<th>` wins and your `row` → `border.color-top` silently does nothing at that one edge.
- **`row` → `border.style-top: "hidden"` erases `header`'s rule entirely** (`hidden` beats every width), and `border.width-top: "3px"` overrides it.

`head` and `header` also compete for that line at different ranks (`<thead>` is a rowgroup, `<th>` is a cell), so declare the rule on `header` unless you specifically want the weaker claim.

### `cell-link` declares nothing, and that is the role working

A link in a cell renders `@color-accent` underlined — byte-identical to what `base.css` gives every anchor on the page. A role block is emitted **unlayered**, so restating those values as a *default* would outrank the shared premium button rules for an author-written `<a class="btn">` in a cell: the #545 defect, reintroduced through a role selector.

The rule is that **rule 2 forbids a default, not a role.** With no default this role emits nothing at rest — zero cascade movement — while still giving you an **address**. You need that address on a dark surface, because a container role's `typography.color` reaches a link only by *inheritance*, and `base.css`'s `a` rule is a direct declaration on the element; an inherited value can never beat one. Measured on faq, where the absence of this role leaves a documented dark-panel write shipping a 3.21:1 link (#1069).

So: if you darken `table` → `background.fill`, set `cell-link` as well as `cell`.

## Usage

```json
{
  "component": "table",
  "id": "pp-a1b2c3d4",
  "props": {
    "title": "Compare the plans",
    "caption": "Plan comparison, updated quarterly",
    "headers": ["Plan", "Pages", "Support"],
    "rows": [
      ["Starter", "5", "Email"],
      ["Growth", "20", "<a href=\"/contact\">Priority</a>"]
    ]
  },
  "udc": {
    "_band": { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "caption": { "typography": { "color": "@color-bg" } },
    "empty": { "typography": { "color": "@color-bg" } }
  }
}
```

That is the three-write dark band. The table inside it stays light and needs nothing.

## Accessibility

- Headers render as `<th scope="col">`, so screen readers announce each cell's column.
- The `<caption>` is a real caption element, not a styled paragraph — give data tables one.
- `.table-wrap` is the scroll container. Its `overflow-x: auto` carries no media query, so a wide table scrolls at 1440px exactly as it does at 375px.
- A `min-height` below 44px on anything interactive inside a cell fails WCAG 2.5.5. The author owns what the author writes.

## Stated defaults (and what would reopen them)

**These values moved address at #1066 and kept their reasons.** Each was a stylesheet literal with a ratified reason; each is a role default now. A literal with no stated reason is not a product default, it is an unexamined value — so the reasons travel with the values rather than being deleted along with the rules that used to carry them.

| value | address now | why it is what it is |
|---|---|---|
| `font-size: 0.9375rem` (body type) | `table` → `typography.size` | A data table is scanned, not read. 15px fits more columns before the wrapper has to scroll, and the row height stays tight enough that a reader can compare across rows without losing their place. It is deliberately below the 16px body copy everywhere else. **What would reopen it:** a legibility complaint on a real table, or a table used for prose rather than data. |
| `font-size: 0.875rem` (caption size) | `caption` → `typography.size` | A caption is a note *about* the table, not part of it, so it sits a step below the body type and shares the meta-text size the theme uses for timestamps. **What would reopen it:** a caption long enough to read as body copy, which is a content problem first. |
| header `2px` / row `1px` (rule widths) | `header` → `border.width-bottom`, `row` → `border.width-top` | The heavier rule is what separates the header *band* from the data; the lighter one separates one row from the next. Equal widths make the header read as just another row. **What would reopen it:** a design that distinguishes the header by fill alone, at which point the 2px is redundant rather than wrong. |
| `var(--space-sm) var(--space-md)` (cell density) | `header` and `cell` → `spacing.padding-*` | 8px vertical keeps rows scannable; 16px horizontal keeps adjacent columns from reading as one. Both are tokens, so a global density retune reaches them. **What would reopen it:** tables that routinely carry multi-line cells, where the vertical rhythm starts to fight the wrapping. |
| `white-space: nowrap` (headers) | structural, in the CSS block | A wrapped column header changes the table's row-1 height and makes the whole grid jump as data loads. Headers are short by contract; letting the wrapper scroll is the better trade. **What would reopen it:** a header long enough that nowrap forces a scroll on a table that would otherwise fit. |

And the deliberate silences:

- **`cell` declares no `font-size`.** The 15px it renders is *inherited* from the `table` role's own `typography.size`, so pinning it here would break `table` → `typography.size` for every cell.
- **`cell` declares no `line-height`.** The 1.6 it renders is `base.css`'s body rule — a global value, and restating it would move it to the unlayered tier.
- **`heading` declares no weight, leading, tracking, family or `text-wrap`.** All five render from `base.css`'s shared `h1`–`h6` rule. (faq's heading *does* declare weight and leading, and that is not an inconsistency: faq's came from rules inside media queries, and table has none.)
- **`heading` declares no alignment.** Measured `start` with `margin-left: 0` at every tier — the initial value, not a declaration. Do not add auto margins here reaching for stats' centred heading; this heading is left-pinned by design and always was.
- **`_band` declares no fill and no border.** v1's band measured `rgba(0, 0, 0, 0)` with 0px/none on all four edges. Declaring either would claim a decision v1 never made, and a fill would paint a surface onto every table band on every site that upgrades.
- **`header` declares `text-align: left` and `font-weight: 700`, and neither is a redundant reset.** The HTML rendering spec gives `<th>` a centred, bold presentation; these are table's own rules overriding it. Same for `caption`'s left alignment, which overrides a centred `<caption>` default.

## Retired style slots

All six, at #1066. A `style_component` write naming any of them is refused with `no_style_slots`, and the refusal lists the component's roles. (`retired_prop` is the code for a retired PROP, which table has none of — the two are different refusals and it is worth keeping them straight.)

| v1 slot | v2 address |
|---|---|
| `--table-padding-top` | `_band` → `spacing.padding-top` |
| `--table-padding-bottom` | `_band` → `spacing.padding-bottom` |
| `--table-heading-size` | `heading` → `typography.size` (still `@pp-band-heading-size`) |
| `--table-heading-color` | `heading` → `typography.color` — **the default changed**, from a pinned `@color-text` to `currentColor` |
| `--table-heading-measure` | `heading` → `sizing.max-width` (still `@measure-heading`) |
| `--table-heading-margin-bottom` | `heading` → `spacing.margin-bottom` (still `@space-lg`) |

The heading-colour change is the only one that is not a plain move. v1 **could not render a dark table band at all** — no `theme` prop, no variant classes — so `@color-text` is identical to `currentColor` on every band v1 could express. They diverge only on an authored `_band.background.fill`, which v2 newly makes trivial and which the pinned literal would have stranded at 1.006:1. Same ruling shape as faq's at #1046.

Both padding slots also take table's two per-component adjacent-sibling rules with them: those existed only to keep `--table-padding-top` live at that specificity, and with no slot left the zero-specificity baseline gives the same value.

## CSS

`assets/css/components.css`, the `COMPONENT: table` block — **structural only**. Layout scaffolding and accessibility affordances. No colour, type, size, spacing, border or shadow value may be added there; `tests/js/css-lint.test.js` fails CI on one.

Five properties in that block had no classification anywhere in the lint until #1066 — `border-collapse`, `caption-side`, `vertical-align`, `overscroll-behavior-x` and `-webkit-overflow-scrolling` — because table is the first v2 component with table markup. The lint's unlisted-property arm is fail-closed, so an unclassified property can be neither kept in the stylesheet nor authored through a role, which is a capability deletion. All five were claimed into STRUCTURAL with a stated reason; the reasons live on the set itself.

`.table__cell`'s `overflow-wrap: anywhere` is the reason table is the only band component whose stressed render does not scroll the page sideways at 375px. `break-word` (what `base.css` gives the page) breaks a word visually but does not reduce intrinsic min-content size; `anywhere` does. stats and logos have only the former and overflow — see #1067.

## What NOT to change

- Do not add a designable declaration to the CSS block. It belongs to a role.
- Do not put the row separator back on the bottom edge with a `:last-child` suppression. It will render correctly in a screenshot and stop working the moment anyone authors the role.
- Do not give `cell-link` a default. The empty block is the point.
- Do not "tidy away" `header`'s weight and alignment, or `caption`'s alignment, as redundant resets. They override browser defaults.
- Do not re-ink `cell` or `header` for a dark band. The table under them stays light.
