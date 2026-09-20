# Tutorial — style a band on the design contract

You will build a published page with two `section` bands: one on nothing but its defaults,
and one you darken, re-space and recolour yourself. By the end you will have written a
`udc` map, seen the CSS it generates, and understood the three things that make v2 styling
different from editing a stylesheet.

Every command below was run against a clean install while writing this page. Copy them.

## What you'll need

- PromptingPress 2.0.0-alpha.2 or later, activated.
- WP-CLI, with `wp pp` responding (`wp pp` prints a usage list).
- A shell on the install. Everything here is CLI; no admin UI required.

A note on the ceremony: every mutating command needs a **run token** and a completed
**preflight**. That is not this tutorial being careful — it is the theme refusing to write
without a recorded way to undo it. You get the token once and reuse it throughout.

---

## Step 1: Get a run token and open the gate

```bash
wp pp operate inspect
```

That prints a JSON document about the site. The field you want is the last one:

```json
"run_id": "67174637-8eaa-48a9-a807-c47295383152"
```

Keep it in a shell variable, because every command below uses it:

```bash
RID=67174637-8eaa-48a9-a807-c47295383152
```

Now complete a site-scoped preflight, which is what actually unlocks writing:

```bash
wp pp apply preflight --run-id=$RID
```

If you skip this, the next command fails with a message telling you to come back and run
it. That refusal is the gate working.

## Step 2: Create a page and put a band on it

```bash
wp pp action execute create_page --run-id=$RID --params='{"title":"Design contract demo"}'
```

Read the `post_id` out of the response and keep it too:

```bash
PID=20767
```

The page needs its own preflight before you can write to it — a site preflight does not
cover a page mutation:

```bash
wp pp apply preflight --run-id=$RID --post_id=$PID
```

Now write a composition holding one `section` band, with **no styling at all**:

```bash
wp pp action execute update_composition --run-id=$RID --params='{
  "post_id": '$PID',
  "composition": [
    {"component": "section",
     "props": {"title": "Design contract",
               "body": "<p>A band with no udc map renders role defaults.</p>"}}
  ]}'
```

Publish it so you can look at it:

```bash
wp pp action execute publish_page --run-id=$RID --params='{"post_id":'$PID'}'
```

**Open the page.** You have a real band: a heading at the component's heading scale and
weight, body copy capped at a readable measure, and the band's own vertical padding. You
wrote none of that. Those are **role defaults**, and they are the product — an author who
specifies nothing still gets a designed band.

## Step 3: Style it — write your first `udc` map

Here is the whole idea. A v2 component declares **roles**: named jobs, like `heading` or
`body` or the band itself. You style a role by writing a `udc` map on the band. You never
write CSS and you never name a class.

Replace the composition with this:

```bash
wp pp action execute update_composition --run-id=$RID --params='{
  "post_id": '$PID',
  "composition": [
    {"component": "section",
     "props": {"title": "Design contract",
               "body": "<p>A dark band, authored.</p>"},
     "udc": {
       "_band":   {"background": {"fill": "@color-bg-inverted"},
                   "spacing": {"padding-top": "@space-2xl",
                               "padding-bottom": "@space-2xl"}},
       "heading": {"typography": {"color": "@color-bg"}},
       "body":    {"typography": {"color": "@color-bg"}}
     }}
  ]}'
```

**Reload the page.** The band is dark, roomier, and its text is legible on the new
background.

Three things just happened that are worth naming:

**`_band` is the band itself.** It is the one role spelled with an underscore, because it
is the root rather than something inside it. Backgrounds and band padding live there.

**`@color-bg-inverted` is a reference, not CSS.** The `@` prefix means "the design token by
this name". The engine looks it up, checks the token exists, checks the token's value fits
this parameter's grammar, and only then writes a `var()` of its own construction. You never
hand CSS text to the stylesheet, which is why no value you can type here can break out of
it.

**You coloured the text yourself, and you had to.** Setting a band's background does *not*
recolour the words on it. There is no dark/light switch in v2 — the old `theme: "inverted"`
prop did this as a bundle and is retired. The trade is that you can now build bands the
bundle could not express, and the cost is that contrast is yours to own. If you forget,
your dark band keeps its dark text.

## Step 4: Look at the CSS the engine wrote

This is the step that makes the model concrete.

```bash
curl -s "$(wp option get siteurl)/?page_id=$PID" | grep -o '\[data-pp-band[^}]*}'
```

```
[data-pp-band="pp-300e9e4c"]{padding-top:var(--space-2xl);padding-bottom:var(--space-2xl);background:var(--color-bg-inverted);}
[data-pp-band="pp-300e9e4c"] .section__title{color:var(--color-bg);}
[data-pp-band="pp-300e9e4c"] .section__content{color:var(--color-bg);}
```

Read it against what you wrote:

- **`pp-300e9e4c` is a band id**, minted for you on write. It scopes your design to *this*
  band — the same component on the next page is untouched. (Minting happens on the write
  path. A raw `wp post meta update` of `_pp_composition` mints nothing, so a `udc` map
  written that way scopes to nothing and paints nothing. Use the action surface.)
- **Each role became one selector.** `heading` is `.section__title`, `body` is
  `.section__content`. You addressed a job; the component owns which element that is.
- **Your references became `var()`**, so a later site-wide retune of `--color-bg-inverted`
  moves this band with it. Had you written `#0f172a` literally, it would not.

## Step 5: Add a breakpoint and a state

Two capabilities the old slot system had no way to express. Any parameter can take a map
of breakpoints instead of a single value, and any group can nest a state.

```bash
wp pp action execute update_composition --run-id=$RID --params='{
  "post_id": '$PID',
  "composition": [
    {"component": "section",
     "props": {"title": "Design contract",
               "body": "<p>Responsive, and it reacts.</p>",
               "panel_heading": "Try me", "panel_cta_text": "Hover this",
               "panel_cta_url": "/x", "layout": "text-panel"},
     "udc": {
       "_band":     {"background": {"fill": "@color-bg-inverted"},
                     "spacing": {"padding-top": {"d": "@space-2xl", "p": "@space-lg"},
                                 "padding-bottom": {"d": "@space-2xl", "p": "@space-lg"}}},
       "heading":   {"typography": {"color": "@color-bg",
                                    "size": {"d": "3rem", "t": "2.25rem", "p": "1.75rem"}}},
       "body":      {"typography": {"color": "@color-bg"}},
       "panel-cta": {"_preset": "button",
                     "background": {":hover": {"fill": "@color-accent-strong"}}}
     }}
  ]}'
```

The three breakpoint keys are **`d`** (desktop, and the base tier), **`t`** (768–1023px)
and **`p`** (phone, up to 767px). A map that names only `p` emits one media block and
nothing at the other two — that is a legitimate phone-only override, not an omission.

`"_preset": "button"` applies a shipped bundle, and the `":hover"` beside it overrides one
piece of it. States nest **inside** a group, not beside it: `background` → `:hover` →
`fill`.

Run the `curl` from step 4 again and two new things appear. First, your breakpoint map
became a custom property on the band root plus one reference per tier:

```
[data-pp-band="pp-af20a651"]{--pp-heading-typography-size-d:3rem;--pp-heading-typography-size-t:2.25rem;--pp-heading-typography-size-p:1.75rem;}
[data-pp-band="pp-af20a651"] .section__title{font-size:var(--pp-heading-typography-size-d);}
@media (max-width: 767px){[data-pp-band="pp-af20a651"] .section__title{font-size:var(--pp-heading-typography-size-p);}
```

That indirection is why a responsive value stays one declaration per tier instead of
re-stating the whole rule three times. Second, and you did not ask for it:

```
@media (prefers-reduced-motion: reduce){[data-pp-band="pp-af20a651"] .section__panel-cta{transition-duration:0.01ms;}
```

Declare a transition anywhere and the engine writes the reduced-motion guard beside it.
Accessibility affordances are the engine's job, not yours to remember.

## Step 6: When the vocabulary cannot say it — raw CSS

Everything above went through a **group** and a **parameter**: `typography.size`,
`background.fill`. That is the surface you should reach for first, every time. Those values
are type-checked, they show up in the catalog, they can be reviewed and changed by name, and
the engine can tell you when one of them cannot take effect.

Sometimes there is no parameter for what you want. For that there is `_css`, which sits
beside a role's groups and takes CSS properties directly:

```json
{
  "quote": {
    "typography": { "size": "1.25rem" },
    "_css": { "mix-blend-mode": "multiply", "opacity": { "d": "0.85", "p": "1" } }
  }
}
```

Same breakpoint maps, same `":hover"` / `":focus-visible"` / `":active"` states, same engine
writing the media queries. It is available on every role and on `_band`, and no component
has to opt in.

**The rule for choosing is one line: structured first, `_css` for what structure cannot
say.** A raw declaration buys you reach and costs you everything the vocabulary was giving
you, so it is the right answer when — and only when — there is no parameter for the job.

Four things worth knowing before you use it.

**If you set both, the raw one wins.** A `_css` `color` outranks `typography.color` on the
same role. That is deliberate — an escape hatch that lost to the thing it was escaping would
be useless — but it means writing both is always a mistake, and the write envelope tells you
so with a `udc_css_overrides_group_value` finding naming the parameter that lost.

**A property the vocabulary does not know is checked for safety only.** It is screened for
anything that could break out of the declaration, and then emitted exactly as you wrote it.
Nothing verifies that the browser accepts it — if you typo `mixx-blend-mode`, it goes out
verbatim and simply does nothing. You get a `udc_css_unchecked_property` finding saying the
value went out unverified. Read those findings; they are the only signal you will get.

**`@token` references only work on properties the vocabulary knows**, because the engine has
to check that the token's value fits the property before it can hand it over. On any other
property, write the literal — an `@name` there is refused rather than quietly resolved.

**Property names are lowercase** letters, digits and hyphens, optionally starting with one
hyphen for a vendor prefix. `-webkit-line-clamp` is fine. `Color` is refused (write
`color`); `--my-var` is refused (band tokens go in `_tokens`). A handful of properties are
unavailable — `all`, `content` and two long-dead scripting hooks — and the refusal says why
in each case.

What you cannot write here: selectors, `@media` or `@supports` blocks, and pseudo-elements.
`_css` is a declaration **list** on the role you put it on; the engine owns everything
around it. And no value may name an external resource — `url()`, `image-set()`, `image()`
and `src()` are all refused on every property, because the Media Library is the only source
of external assets. A background image is an attachment id on `background.image`.

And one thing the engine cannot do for you: **a raw `background` or `opacity` changes what
your text sits on, and nothing checks the contrast.** That was true of the groups too, but
it bites harder here, because a raw value is the one the engine understands least.

## What you built

A page with a band you designed entirely through data — no CSS file, no class names, no
stylesheet edit — that is scoped to itself, follows your design tokens when they change,
responds at three widths and reacts to the pointer. And a way out when the vocabulary runs
short, so the vocabulary's limits are never your design's limits.

The model in one line: **a component declares roles; you write values onto roles; the
engine writes the CSS** — through a named parameter when one exists, and through `_css`
when none does.

Where to go next:

- **[`components/section/README.md`](../components/section/README.md)** — all 19 of
  section's roles, every default and why it is the default, and the four visual jobs no
  role reaches.
- **[How to migrate a v1 section band to v2](howto-migrate-a-section-band-to-v2.md)** — if
  you have existing pages carrying `--section-*` slots or a `theme` prop.
- **[Why the stylesheet is in a cascade layer](explanation-cascade-layers.md)** — what wins
  when your `udc` map and the theme stylesheet disagree, and why it is never a specificity
  race.
- **[Reference — the `wp pp apply` command family](reference-apply-cli.md)** — the run
  token, preflight and rollback machinery you used in step 1.
