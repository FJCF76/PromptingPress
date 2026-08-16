# Component: embed

A generic content embed block. Passes the `content` prop through `do_shortcode()` and `wp_kses_post()`. Use for WP plugin shortcodes (contact forms, calendars, payment widgets) that belong to WP rather than to the PromptingPress composition model.

This is the only sanctioned way to introduce arbitrary plugin-rendered content into a composition. It is explicit and deliberate — not a workaround.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `id`      | string | No       | `''`    | HTML id for anchor linking |
| `title`   | string | No       | `''`    | Optional heading above the embedded content |
| `content` | string | Yes      | —       | WP shortcode or pre-rendered HTML |
| `theme`   | enum   | No       | `default` | Background color/tone: `default` (page background), `muted` (light tinted surface band with borders), `inverted` (inverted dark background for strong contrast) |

## Usage

```php
pp_get_component('embed', [
    'title'   => 'Send your CV',
    'content' => '[contact-form-7 id="123" title="CV Form"]',
]);
```

## Notes

- `content` is passed through `wp_kses_post()` before `do_shortcode()`, so it strips disallowed HTML tags while preserving shortcode brackets.
- If the shortcode plugin is not active, the content renders as plain text or is silently empty.

## Style slots

8 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition --post_id=<id>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--embed-padding-top` · `--embed-padding-bottom` |
| Heading | `--embed-heading-size` ◦ · `--embed-heading-color` ◦ · `--embed-heading-measure` ◦ · `--embed-heading-margin-bottom` ◦ |
| Content | `--embed-body-measure` · `--embed-body-color` |

`--embed-body-measure` (default `40rem`) caps the embedded content's column, and
`--embed-body-color` sets its inherited ink. Neither reaches inside a plugin's own
markup any further than inheritance does — a shortcode that sets its own colours wins.

**There is no `--embed-bg`** — see the deferred band-background gate below.

## Stated defaults (and what would reopen them)

| Default | Why it is a default | What would reopen it |
|---|---|---|
| `.embed--dark` framing borders — `1px solid var(--color-border)` top and bottom | The colour already routes `var(--color-border)`, so a site-wide rule retune reaches it with one `update_design_token` write; and the treatment is deliberately **consistent across every muted variant** — `logos` and `stats` use the same 1px pair, so the muted bands frame identically down a page. | **Already scheduled, not speculative:** bound to the deferred band-background gate below. |

### The deferred band-background gate

`--embed-bg` **does not exist**, and neither do `--logos-bg` or `--table-bg`. This
component's own `muted` variant paints `--color-surface` directly. (`table` is the odd one
out: it declares no `theme` prop and no variant classes at all, so it has no band tone to
paint in the first place.)

Its **entry criterion**, for the day the deferred gate opens: if `--embed-bg` is ever
shipped, these framing borders must route a slot **in the same change**. An author who paints the band a new colour and gets a
frame that no longer matches it is worse off than one who cannot paint it at all. The
borders do not stay independently open after that gate — they join it.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: embed === */`.
