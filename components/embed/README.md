# Component: embed

A generic content embed block. Renders an optional heading and passes `content` through `do_shortcode()`. Use it for WP plugin shortcodes — contact forms, calendars, booking widgets — that belong to WordPress rather than to the PromptingPress composition model.

`content` is the only way to introduce arbitrary HTML into a composition. That is intentional and explicit, not a workaround.

**This is a v2 component.** It declares no style slots. Every designable value — colour, type, spacing, border, shadow, size, motion — is set through the `udc` map on the band, per role. See `docs/v2/BUILD-SPEC-sprint0.md` §3 and `docs/explanation-cascade-layers.md`.

**Its CSS block is empty, and that is the end state the whole migration is aiming at.** embed is the first component in the theme with no structural CSS at all: every rule it had was a value a role owns now. There is no layout to scaffold, no wrapper geometry beyond the shared `.container`, and no accessibility affordance of its own.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `id`      | string | No       | `''`    | HTML id for anchor linking; also becomes the stable component id |
| `title`   | string | No       | `''`    | Optional section heading above the embedded content |
| `content` | string | Yes      | —       | Shortcode string or pre-rendered HTML. Rich HTML (`wp_kses_post`), then passed through `do_shortcode()` |

### One prop that used to exist

**`theme`** is retired (#1066). It was a bundle of band values, which is what the `_band` role expresses directly — but the route needs three groups rather than one, because all three of its settings were measured before it went and only one was a background:

| v1 `theme` | what it rendered | v2 |
|---|---|---|
| `default` | **nothing** — `rgba(0, 0, 0, 0)`, no border on any edge | the `_band` role's defaults declare no fill at all; silence is the faithful port |
| `muted` | `@color-surface` **plus a `1px solid var(--color-border)` rule top and bottom** | `_band` → `background.fill`, plus `border.width-top` / `width-bottom` = `"1px"`, `style-top` / `style-bottom` = `"solid"`, `color` = `"@color-border"` |
| `inverted` | `@color-bg-inverted` fill, heading re-coloured to `@color-bg`, **content ink re-coloured to the same**, and links re-routed to `@color-accent-on-inverted` | `_band` → `background.fill` + `typography.color` (the `heading` role follows through `currentColor`, `content` receives it by inheritance), plus `content-link` → `typography.color` **and its `:hover`** for the links |

`dark` was never an accepted input (removed at #605) and is not part of the route.

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, radius, shadow, and the band ink the heading follows |
| `heading` | `.embed__heading` | the `<h2>`: size, measure, rhythm, ink |
| `content` | `.embed__content` | the embedded content column: its measure, and any ink the embedded markup inherits |
| `content-link` | `.embed__content a` | a link inside the embedded content. **Declares nothing** — see below |

### A dark embed band reaches less than you expect

This is the one thing to carry away from this component, and it follows from what `content` *is*: arbitrary author HTML sanitized by `wp_kses_post()`.

An inherited colour is used **only where nothing else declares one**. So a `_band` → `typography.color` write reaches:

- the `heading` role, through `currentColor` — yes
- a bare `<p>`, a `<strong>`, plain text inside `content` — yes, by inheritance

and does **not** reach:

- an author-written `<h2>`–`<h6>`: `base.css` pins those to `@color-text`, which measures **1.006:1** on an inverted band — invisible
- a `<blockquote>`: `base.css` pins `@color-muted`
- a link: `base.css` pins `@color-accent`, and that is what `content-link` exists for

> **The fill and the ink are two writes.** `currentColor` on the `heading` role follows the band's
> `typography.color`, not its `background.fill`. A band given a dark fill and no ink keeps the inherited
> `@color-text` and renders its heading at about **1.04:1**. Write both, always.

Each of those is a **direct declaration on the element**, and a direct declaration always beats an inherited value regardless of cascade layer. So a dark embed band costs the `_band` write **plus one per element the embedded content actually uses** — and the two that have no role (`<h2>`–`<h6>` and `<blockquote>`) have to be handled in the embedded markup itself.

### `content-link` declares nothing, and that is the role working

A link inside the content renders `@color-accent` underlined with an `@color-accent-hover` hover — byte-identical to what `base.css` gives every anchor on the page. A role block is emitted **unlayered**, so restating those values as a *default* would outrank the shared premium button rules for an author-written `<a class="btn">`: the #545 defect, reintroduced through a role selector.

**That exposure is real on this component rather than theoretical.** `content` goes through `wp_kses_post()`, which admits `class`, and a measured `<a class="btn">` inside an embed renders the full premium treatment — gradient fill, light label, 4px radius, 4px focus offset.

The rule is that **rule 2 forbids a default, not a role.** With no default, `content-link` emits nothing at rest — zero cascade movement — while still giving you an address.

**This role is what replaces `.embed--inverted a` AND `.embed--inverted a:hover`**, the #437 PAIR that routed a dark band's link ink through `@color-accent-on-inverted` / `@color-accent-on-inverted-hover` and that retires with the `theme` class. Write both states in one map, and note what a resting-only write actually does — it is not what it sounds like. The authored value is emitted **unlayered**, so it beats `base.css`'s `a:hover` too: measured, the link holds `@color-accent-on-inverted` in **both** states and stops responding to hover at all. A lost affordance rather than a contrast failure. (The contrast failure belongs to a different case: a dark band with no `content-link` write leaves the link at `@color-accent`, **3.23:1**.) A role's states move with its resting value. Without it, the capability would simply be deleted: `content` → `typography.color` reaches a link only by inheritance, and inheritance cannot beat `base.css`'s own `a` rule (measured on faq, where the absence of such a role leaves a documented dark-panel write shipping a 3.21:1 link — #1069).

## Usage

```json
{
  "component": "embed",
  "id": "pp-a1b2c3d4",
  "props": {
    "title": "Book a call",
    "content": "[contact-form-7 id=\"123\"]"
  },
  "udc": {
    "_band":        { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "content-link": {
      "typography": {
        "color": "@color-accent-on-inverted",
        ":hover": { "color": "@color-accent-on-inverted-hover" }
      }
    }
  }
}
```

## Accessibility

- The band emits `data-pp-band-overlay` when a scrim is painted, which routes the focus ring on an author-written `.btn` inside the content through `@color-accent-on-overlay`. A plain link keeps the bare accent ring — widening the ring to every focusable is a separate blast radius and a recorded out-of-scope decision.
- Whatever the shortcode renders is the plugin's accessibility responsibility, not the theme's. `embed` is an escape hatch, and the theme cannot vouch for what comes through it.

## Stated defaults (and what would reopen them)

- **`content` keeps its own `40rem` measure rather than routing `@measure-heading`.** It is a *body* measure, and #578 separated the two deliberately so that retuning band heading measure never re-flows embedded content. Both resolve to 640px today; that is a coincidence, not a shared source. **What would reopen it:** a decision that band heading measure and body measure should move together, which would be a design-system call rather than a component one.
- **`content` declares no colour**, and this is the fourth condition resolving to *silence* rather than to `currentColor`. v1 declared `color: var(--embed-body-color, inherit)` — an explicit `inherit`, which **is** a declaration and is not the same as declaring nothing. What decides the port is whether any rule *matches* the element: cta's heading needed `currentColor` because `base.css`'s `h1`–`h6` rule matches an `<h2>` directly and would have beaten an inherited band colour. This is a `<div>`, and no rule in the theme declares `color` on a bare div, so an inherited `_band` value already lands. `inherit` and silence are byte-identical here and silence is the smaller declaration. The role still declares `typography`, so an author can set it. **What would reopen it:** a base.css rule that starts matching a bare div for colour, which would make silence stop being equivalent.
- **`content` declares no size or leading.** Measured 16px / 1.6 at every tier, both from `base.css`'s body rule — a global value, and restating it would move it to the unlayered tier.
- **`heading` declares no weight, leading, tracking, family or `text-wrap`.** All five render from `base.css`'s shared `h1`–`h6` rule. (faq's heading *does* declare weight and leading, and that is not an inconsistency: faq's came from rules inside media queries, and embed has none.)
- **`_band` declares no fill and no border.** v1's unthemed band painted nothing. Declaring either would claim a decision v1 never made.
- **The `muted` framing was `1px solid var(--color-border)` on the top and bottom edges only**, never on the sides, and that asymmetry is the whole reason the retirement route names `border.width-top` / `width-bottom` rather than the `width` shorthand. The shorthand emits all four edges, which on a full-bleed band draws hairlines down both viewport edges — the defect #1023's red-team pass measured on three starter bands. **What would reopen it:** a band design that genuinely wants a boxed frame, which is an author's write rather than a default.

## Retired style slots

All eight, at #1066. A `style_component` write naming any of them is refused with `no_style_slots`, and the refusal lists the component's roles. The retired `theme` PROP is the one that returns `retired_prop`, and that refusal does name its route — the two codes are different refusals for different surfaces.

| v1 slot | v2 address |
|---|---|
| `--embed-padding-top` | `_band` → `spacing.padding-top` |
| `--embed-padding-bottom` | `_band` → `spacing.padding-bottom` |
| `--embed-heading-size` | `heading` → `typography.size` (still `@pp-band-heading-size`) |
| `--embed-heading-color` | `heading` → `typography.color` — **the default changed**, from a pinned `@color-text` to `currentColor` |
| `--embed-heading-measure` | `heading` → `sizing.max-width` (still `@measure-heading`) |
| `--embed-heading-margin-bottom` | `heading` → `spacing.margin-bottom` (still `@space-lg`) |
| `--embed-body-measure` | `content` → `sizing.max-width`, keeping its own `40rem` literal |
| `--embed-body-color` | **nothing** — see the stated default above; silence is byte-identical and the role still permits an authored value |

The heading-colour change is the only *default* change. The only dark embed band v1 could render was `theme: "inverted"`, which is retired, so the pinned literal is identical to `currentColor` on every band v1 could express and diverges only on an authored `_band.background.fill`, where it lands at 1.006:1.

Both padding slots also take embed's two per-component adjacent-sibling rules with them.

## CSS

`assets/css/components.css`, the `COMPONENT: embed` block — **empty**, and deliberately so. The banner explains what each departed rule became. `tests/js/css-lint.test.js` pins the emptiness in both directions: a re-added rule fails, and so does a slicer that stops finding the block.

`.embed` still appears in the shared `scroll-margin-top` list near the top of that file. That rule spans ten components and belongs to none of them.

## What NOT to change

- Do not add any rule to the CSS block. Every value belongs to a role.
- Do not give `content-link` a default. The empty block is the point, and a default reintroduces #545 for an author-written `.btn`.
- Do not fold `content`'s measure into `@measure-heading`. They agree today by coincidence.
- Do not assume a `_band` ink write darkens the embedded content's headings or blockquotes. It does not.
