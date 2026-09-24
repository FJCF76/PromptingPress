# How to clear a stored v1 style map off a band

Your band refuses every edit — including one that changes only a title — with a message about
a style slot you never mentioned. This guide clears it. It takes one command.

**Do this before any of the eight `howto-migrate-a-*-band-to-v2.md` guides.** Every one of
them assumes you can edit the band you are migrating. A band still holding its v1 style map
cannot be edited at all, so this step comes first.

## Why it happens

Before the v2 rebuilds, a band's appearance lived in a `style` map of **style slots** — CSS
custom properties like `--section-bg`, written per band. Each component's rebuild retired its
slots, and #1101 retired the engine itself. No component declares a style slot now.

Nothing migrated the stored maps. A page authored before its component was rebuilt still has
one in `_pp_composition`, exactly as written. And because every key in it is now undeclared,
the write validator refuses the band:

```
Component 0 ("section") has no style slot "--section-bg".
                                          ^^^^^^^^^^^^^^
                                          a RETIRED name (section's slots went at #1023).
                                          The refusal quotes what YOUR band stores, so the
                                          name you see will be one of your own.
```

**The refusal is the whole band, not just its styling.** This is the part that surprises
people: it is not "you cannot restyle this band", it is "you cannot edit this band". A
props-only `update_component` — a new title, a new link — meets the same refusal, naming a slot
you were not touching.

At RENDER time the map is simply ignored, so the band paints with the component's role defaults
and loses whatever that map was painting — a band you had stored as dark comes back as an
unthemed light band. The page still renders; it does not fatal and it does not go blank. But it
does not look the same, and nothing warns you until you try to change something. Measured across
components: `section`, `cta`, `stats`, `grid` all behave identically.

## Prerequisites

- WP-CLI with the theme's commands available (`wp pp --help` responds).
- The page id. `wp pp operate inspect --post_id=<id>` lists the bands.
- **A run id**, which every write needs. Get one from `wp pp operate inspect`, copy the
  `run_id`, and keep it in a shell variable — it is valid for two hours:

  ```bash
  RUN=$(wp pp operate inspect | wp eval 'echo json_decode(file_get_contents("php://stdin"))->run_id;')
  ```

- The ordinary write preconditions apply here and nothing is waived, `wp pp apply preflight
  --run-id=$RUN --post_id=42` included.

## Step 1: See exactly what the band stores

```bash
wp pp check page --post_id=42
```

Every stored key is reported as an `invalid_style_slot` finding, one per band, naming the
first offending key. To see the whole map rather than the first key, read the band:

```bash
wp pp operate inspect --post_id=42
```

Look for a `style` object on the band. Write down **every key in it.** You need all of them,
and one of them may not look like a slot — see Step 2.

## Step 2: Note whether the band was styled by a recipe

If the `style` map contains a `__recipe` key, like this:

```
"style": {
  "__recipe": "dark-showcase",     each of these slot names is RETIRED — grid declares zero
  "--grid-gap": "2rem",            style slots, so writing any of them back is refused with
  "--grid-item-bg": "#101014"      no_style_slots. You read them only to know what to null.
}
```

And `__recipe` is one of the keys you must clear. **It is not a slot name**, so it is easy to
miss, and missing it is silent: the clear succeeds, the band becomes editable, and the key
stays in your composition forever.

`__recipe` was written by v1 every time a *recipe* (a named bundle of slot values) was applied.
`grid` shipped three of them until #1101, so recipe-applied bands are common.

## Step 3: Clear it

Two routes. **Prefer the second if you are sweeping a page** — it cannot be got wrong.

### Route A — `update_component`, surgical

Send every stored key with a value of `null`, in ONE call. `props` is optional since #1088 (a
style-only call is accepted); sending `{}` as below is harmless:

```bash
# The slot names here are RETIRED examples, copied from what the band stores.
# Substitute the keys YOUR band has.
wp pp action execute update_component --run-id=$RUN --params='{
  "post_id": 42,
  "component_index": 0,
  "props": {},
  "style": { "__recipe": null, "--grid-gap": null, "--grid-item-bg": null }
}'
```

Every parameter goes inside ONE `--params` JSON document — that is the shape `wp pp action
execute` takes, for every action. For anything longer than a line or two, put the JSON in a
file and pass `--params="$(cat body.json)"`.

**A partial clear is refused.** Leave one key out and the whole call fails, naming the key you
left behind — which reads like a second, unrelated problem. It is not: it is the same problem,
one key later.

### Route B — `update_composition`, whole band

Rewrite the band with no `style` key at all. Nothing to enumerate, nothing to miss:

```bash
wp pp action execute update_composition --run-id=$RUN --params='{
  "post_id": 42,
  "composition": [
    { "component": "grid",
      "props": { "id": "g1", "title": "Our work", "items": [ { "title": "One" } ] } }
  ]
}'
```

This replaces the entire composition, so include every band on the page, and copy each one's
props exactly as `inspect` reported them. That is the cost of the route that cannot go wrong.

## Verification

The `style` key should be gone entirely, not left as an empty object:

```bash
wp pp operate inspect --post_id=42
```

And the edit that was refused should now go through:

```bash
wp pp action execute update_component --run-id=$RUN --params='{
  "post_id": 42, "component_index": 0, "props": { "title": "A new title" }
}'
```

A clean band reports no findings:

```bash
wp pp check page --post_id=42
```

## Troubleshooting

**"has no style slot" again, naming a different key.** You did a partial clear. Go back to
Step 1, list every key, and send them all in one call.

**The refusal names a band you did not touch.** A stale map on band 3 is reported on the
accepted envelope of an edit to band 1 — that is deliberate, so you find out about it without
having to try editing every band. It does not block the band you edited. Clear band 3 when you
get to it.

**`style_component` looks like the tool for this. It is not.** It refuses every component with
`no_style_slots` and always will; it cannot clear anything. The refusal names the `udc` route
instead.

**A duplicate `props.id` across bands refuses every edit to every band.** That is a different
problem with the same symptom of being stuck. The refusal says so. Repair the ids through
`update_composition` first.

## What next

Now that the band is editable, migrate its appearance:

- `docs/tutorial-style-a-band-on-the-design-contract.md` if you have not written a `udc` map
  before.
- Then the guide for your component: `docs/howto-migrate-a-section-band-to-v2.md`,
  `-a-cta-`, `-a-faq-`, `-a-table-`, `-an-embed-`, `-a-stats-`, `-a-logos-` or `-a-grid-`.

## Related

- `docs/reference-apply-cli.md` — the `findings` envelope every accepted write returns.
- `docs/explanation-validation-scope.md` — why a write validates the band it targets rather
  than the whole page, which is what keeps one stale band from locking the others.
- `ai-instructions/validate-site.md` — the same repair, written for an AI agent rather than an
  operator.
