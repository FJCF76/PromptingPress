# Build a Complete Landing Page

End-to-end recipe for a new landing page: one composition, written once, styled through
the bands' own `udc` maps.

**A landing page is not a template file.** Earlier versions of this guide had you create
`templates/landing-page.php`, a root loader, an ACF field group, and a `Page Attributes`
selection — a whole parallel authoring system. None of that is how a page is built now,
and none of it is needed. A page is a **composition**: a JSON array of bands stored on the
page, created and edited through typed actions. The only template involved is
`composition.php`, which `create_page` assigns for you.

Read `ai-instructions/composition.md` for the composition format and
`ai-instructions/style-component.md` for the `udc` map. This page is the worked example
that puts them together.

---

## Step 1 — Open a run

Every mutating command needs a run token, and the token must be the one `inspect` minted:

```bash
wp pp operate inspect                       # capture `run_id` from the JSON output
wp pp apply preflight --run-id=<uuid>       # site-scoped: no --post_id, the page does not exist yet
```

A self-generated UUID passes format validation and then fails at EDIT, because only the
token `inspect` records carries run state. It expires two hours after `inspect`; re-run
`inspect` if it does. Full contract: `docs/reference-apply-cli.md`.

---

## Step 2 — Plan the bands

A landing page is a sequence of bands, each one component. A five-band spine that works:

| Band | Component | Job |
|---|---|---|
| 1 | `hero` | The headline and the primary action |
| 2 | `section` | What it is, in prose, with an image |
| 3 | `grid` | The real features, as cards |
| 4 | `faq` | The questions that stop people converting |
| 5 | `cta` | The closing action |

Pick from the ten composable components — `hero`, `section`, `grid`, `cta`, `faq`,
`stats`, `table`, `embed`, `logos`, `testimonials`. **`nav` and `footer` are not on that
list**: they are site chrome the template renders on every page, and composing either is
rejected with `template_owned_component`.

Give every band you might want to target later an authored `id`. Ids you do not author
are generated as `pp-<hex8>`, which is stable in place but regenerates on a full
re-apply — and a `udc` map is scoped to the band, so a durable id is what makes a durable
style.

---

## Step 3 — Write the composition

Content is `props`; appearance is `udc`. The two never mix: there is no `theme` prop on
any of the nine v2 components, and no style slots on any of them either.

```json
{
  "title": "Product Launch",
  "slug": "launch",
  "composition": [
    {
      "component": "hero",
      "props": {
        "id": "lp-hero",
        "title": "Ship your site in an afternoon",
        "subheading": "One sentence that explains what you do and who it is for.",
        "button_text": "Get Started",
        "button_url": "#closing",
        "layout": "centered"
      },
      "udc": {
        "_band": { "background": { "fill": "@color-surface" } }
      }
    },
    {
      "component": "section",
      "props": {
        "id": "lp-what",
        "title": "What makes this different",
        "body": "<p>Describe the core problem and how the product solves it. Be specific.</p>",
        "layout": "image-right",
        "image_url": "/wp-content/uploads/product.png",
        "image_alt": "The editor with a composition open"
      },
      "udc": {
        "heading-accent": { "typography": { "color": "@color-accent" } }
      }
    },
    {
      "component": "grid",
      "props": {
        "id": "lp-features",
        "title": "Key features",
        "items": [
          { "title": "Feature one", "text": "Describe it with enough specificity that a prospect understands what it does." },
          { "title": "Feature two", "text": "Another real feature." },
          { "title": "Feature three", "text": "Three is a good number for a landing-page grid." }
        ]
      }
    },
    {
      "component": "faq",
      "props": {
        "id": "lp-faq",
        "title": "Common questions",
        "items": [
          { "question": "How does this work?", "answer": "Explain the mechanism clearly." },
          { "question": "What does it cost?", "answer": "Be direct. Vague pricing reduces conversions." }
        ]
      },
      "udc": {
        "_band": { "background": { "fill": "@color-surface" } }
      }
    },
    {
      "component": "cta",
      "props": {
        "id": "closing",
        "title": "Ready to get started?",
        "body": "One sentence reinforcing the value.",
        "button_text": "Start now",
        "button_url": "/signup",
        "layout": "full-width"
      },
      "udc": {
        "_band": { "background": { "fill": "@color-bg-inverted" } },
        "heading": { "typography": { "color": "@color-bg" } },
        "body": { "typography": { "color": "@color-bg" } },
        "button": {
          "background": { "fill": "@color-accent-on-inverted" },
          "typography": { "color": "@color-bg-inverted" }
        }
      }
    }
  ]
}
```

**Read the closing band carefully — it is four writes, not one, and that is the single
most common v2 mistake.** A dark band is a `_band` `background.fill` AND a
`typography.color` on every text role over it. Nothing infers "this band is dark": the
old `theme: "inverted"` did the recolouring as a bundle and it is retired, so a fill set
alone leaves the heading at the inherited `@color-text` and renders near-black on
near-black. The button is its own pair again — a fill and an ink — because a role's
colours do not follow the band's.

**And write the role that OWNS the text, not the one that contains it.** cta has both a
`text` role (`.cta__text`, the wrapper around eyebrow + heading + body) and a `body` role
(`.cta__body`, the supporting line itself). Colouring the wrapper looks right and does
nothing, because `body` declares its own `typography.color` default
(`@color-text-secondary`) and a role default is emitted as a direct declaration — which
beats an inherited value at any specificity. I made exactly this mistake writing this
page: the first version of the example set `text`, and the rendered `<p>` measured
`rgb(45, 54, 72)` on `rgb(15, 23, 42)`, about 1.4:1 and unreadable, while every other
write on the band landed. **The general rule: when a value does not seem to apply, check
whether the role you targeted merely contains the element, and whether the role that owns
it ships a default for that parameter.** `wp pp schema <component>` prints both the
selector and the defaults.

`@color-bg` as INK on an inverted band is deliberate, not a typo: it is the semantic
opposite of `@color-bg-inverted`, so the pair stays correct through a retheme.
`@color-accent-on-inverted` exists because the plain `@color-accent` drops to about
3.2:1 on the default inverted background and fails AA.

Note that `grid` carries no `udc` — it is the one component not on the design contract.
Style it with `style_component` and its style slots instead — read them with `wp pp schema grid`.

---

## Step 4 — Create the page

One call creates the page, assigns `composition.php`, validates every band and mints the
band ids:

```bash
wp pp action execute create_page --run-id=<uuid> --params='{"title":"Product Launch", ... }'
```

`--params` takes the JSON **inline**. There is no `--params@file` form: the value is read
straight out of the argument and `json_decode`d (`pp_cli_parse_params()`), so an `@path`
is rejected as an unknown parameter.

**The single-quoted form cannot carry an apostrophe, and landing-page copy is exactly
where apostrophes live.** One `'` in a headline closes the shell's string mid-payload:
`--params='{"title":"Don't wait"}'` is a syntax error (`unexpected EOF while looking for
matching`), and everything between that apostrophe and the next one is unquoted shell
text, where `$`, a backtick, `;`, `|` and `&` are live rather than literal. So the file
form below is not only for length — **it is the form to use whenever the copy contains an
apostrophe**, which is most real copy:

```bash
wp pp action execute create_page --run-id=<uuid> --params="$(cat composition.json)"
```

If it is REFUSED, normally no page was left behind and no slug was reserved (#719) —
re-run the same command rather than hunting for a half-made page. If a page WAS left, the
message says so and names it.

To make it the homepage: **Settings → Reading → A static page → Front page**.

---

## Step 5 — Verify non-English content (if applicable)

If the site content is in a non-English language, do a dedicated orthography pass after
generating the composition JSON and before applying it.

Agents generating content inside JSON / CLI-heavy workflows are more likely to drop
diacritics and language-specific characters. This is not a pipeline bug — Unicode is
preserved end to end — but the authored content can be wrong.

Explicitly verify:
- **Diacritics / accent marks** — `tecnología` not `tecnologia`, `diseño` not `diseno`
- **Language-specific punctuation** — `¿`, `¡`, `ñ`, `ç`, `ü`
- **Headings and CTA text** — highest visibility, highest impact if wrong
- **Common high-frequency words** in the target language that degrade in code contexts

Do not skip this step. Orthographic errors in a non-English site are immediately visible
to native speakers and undermine the credibility of the whole page.

---

## Step 6 — Write real copy

Replace every placeholder above with specific, concrete content. Avoid:

- "Welcome to [Site]"
- "Your all-in-one solution for..."
- "Unlock the power of..."
- "Discover the difference"

Use customer-specific language, concrete feature descriptions, and real pricing.

---

## Step 7 — Check it

```bash
wp pp check page --post_id=<id>        # composition validity, styling, smells
wp pp validate page --post_id=<id>     # the rendered HTML
```

`check page` is the inspector and never changes its exit code; `wp pp validate site` is
the gate. Read the `udc` advisories — `udc_preset_value_shadowed_by_role_default` and
`udc_css_overrides_group_value` both describe a value you wrote that is not painting.
See `ai-instructions/validate-site.md`.

---

## Step 8 — Revise

- **Reorder bands:** `reorder_components`
- **Change content:** `update_component` (patch semantics — only the props you pass change)
- **Change appearance:** edit the band's `udc` map and send the whole array back with
  `update_composition`. `update_component` declares no `udc` parameter, so this is
  necessarily a read-modify-write: read with `wp post meta get <id> _pp_composition` (the only
  surface that returns the `udc` maps — `inspect-composition` carries none), edit the
  one band, write it all back. See `ai-instructions/playbook-revise-section.md`
- **Add a band:** `add_component`
- **Retheme the whole site:** `update_design_token` — overrides are stored in the database
  and survive theme updates. Do not edit `assets/css/base.css` for a site; that is
  release-level and is overwritten on update. See `ai-instructions/retheme.md`
