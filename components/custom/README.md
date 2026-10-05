# Component: custom

A band whose inner structure is your own HTML (`markup`), with named **content islands** (`islands`) for the text a person edits. Use it when no structured component expresses the layout you need. It is Layer 3C of the content contract: `docs/v2/LAYER-3-CONTRACT.md` §7.

**This is a v2 component.** It declares one role, `_band` (the `<section>` around your markup). The markup has no stable role selectors, so its insides are styled through the band's **scoped sheet** (`udc._scoped`, LAYER-3-CONTRACT §6) or through classes the theme already ships.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `id`      | string | No       | `''`    | HTML id for anchor linking; also becomes the stable component id |
| `markup`  | string | Yes      | —       | The band's inner HTML. Checked as rich content plus the two island attributes. **Structural: edited only by a structural write** (P-7) |
| `islands` | object | No       | `{}`    | Island name -> content string. Each value passes the contract its host declares |

## Islands

An island is an **empty** element in `markup` carrying `data-pp-island="<name>"`. Its content lives in `islands.<name>` and is rendered into that element.

```json
{
  "component": "custom",
  "props": {
    "markup": "<div class=\"split\"><h2 data-pp-island=\"title\" data-pp-island-kind=\"inline\"></h2><p data-pp-island=\"lede\"></p><a class=\"btn\" href=\"/start\"><span data-pp-island=\"cta\"></span></a></div>",
    "islands": { "title": "Ship <em>faster</em>", "lede": "One band, your structure.", "cta": "Get started" }
  }
}
```

- **Name:** a lowercase letter, then up to 63 lowercase letters, digits, `_` or `-`; unique in the band; at most **64** islands.
- **Kind** (`data-pp-island-kind`): `plain` (the default: escaped text), `inline` (the INLINE set: `a`, `strong`, `em`, `br`, `span`, `sup`, `sub`, `small`, `mark`, `code`) or `rich` (rich content).
- **Hosts per kind** (the host decides how the browser parses what goes in it):
  - `rich`: `div section article aside header footer main figure figcaption blockquote li dd td th details`
  - `inline`: `span p h1-h6 strong em small mark label dt summary caption figcaption legend cite q` (never `a`: inline content may carry a link)
  - `plain`: any rich or inline host, plus `a button time code abbr sub sup` (so a link or button label is editable without touching the structure)
  - never a void element, `textarea`, a table part other than a cell, or anything inside SVG or MathML.
- An `islands` entry with no element in markup is **refused** (`custom_island_unknown`). An island in markup with no entry **renders empty** and the band carries a `custom_island_empty` finding.
- `data-pp-island` and `data-pp-island-kind` are the only `data-pp-*` names content may carry, and only here (E6). Inside an island's content they are refused like every engine attribute.

### What is checked, and what is not (§7.4)

- **Safety, fully.** The markup, every island and the **composed** band (markup with its islands rendered in) all run the one content predicate. Island content is verified **in its host's full ancestor chain**: a write whose island content would restructure the markup there (a link inside an authored link, a button inside an authored button, a list item in a list-item host, an element left open) is refused, naming the island.
- **Readability, not.** The readability, presence and overlay findings reason about declared roles, and custom markup declares none, so they run on `_band` only. Every custom band carries the `custom_band_unverified` disclosure (information) saying so.
- **Shortcodes are not expanded.** Custom markup is your bytes, not plugin output (§7.5). A shortcode belongs in the `embed` band.
- **Engine identity never reaches the page from content.** The rendered band passes an emission belt that removes every `data-pp-*` attribute and every engine-shaped id from the band's inner bytes (the island attributes included: style an island through a class you give its host).

## Editing

| Surface | `markup` | `islands` |
|---|---|---|
| `update_component` / `update_composition` (the assistant) | yes (full gate) | yes; `update_component` merges by key: a sent island replaces that island, `null` removes it, unsent islands are kept |
| JSON editor | yes (full gate) | yes |
| Accordion editor | shown, not editable | one text box per island |
| `wp pp operate patch` | refused (P-7) | `custom.islands.<name>` or `custom[id="pp-…"].islands.<name>` |

An island edit diffs as one field, `props.islands.<name>`, and is one versioned write (CAS and undo per write).

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, radius, shadow, and the band ink the markup inherits where nothing declares its own |

## Stated defaults (and what would reopen them)

- **`_band` declares the shared band rhythm (`@pp-band-padding` top and bottom) and a centred background position, and nothing else.** Every other value inside the band is the author's markup and scoped sheet. **What would reopen it:** a measured case where the band rhythm fights a custom layout often enough that a different default is the honest one.
- **No `heading`, `content` or link role.** The markup declares no stable selectors, so a role would name an element the template does not render (the silent-skip class). **What would reopen it:** a template-rendered element inside the band, which this component deliberately has none of.
- **No overlay focus-ring marker (`data-pp-band-overlay`).** Its ruling names seven components. **What would reopen it:** a custom band with a background image, an overlay and a `.btn` inside, measured, which is the case the marker exists for.

## Limits

`markup` at most 64 KiB, each island at most 16 KiB, the band's markup and islands together at most 128 KiB (LAYER-3-CONTRACT M-8). Refused whole and named, never truncated.

Attribute islands (`href`, `src`, `alt`) and repeatable islands are not in this release; they are guaranteed destinations of the contract (P-25, P-26).
