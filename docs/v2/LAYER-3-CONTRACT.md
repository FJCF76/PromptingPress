<!--
  docs/v2/LAYER-3-CONTRACT.md

  The Layer-3 contract that BUILD-SPEC-sprint0.md §7 points at ("Layer 3 (sanitizer model:
  kses delta, no event attrs/iframes/JS URLs; content islands) ... with its own contract
  review"). Written under issue #1167 as a CONTRACT REVIEW ONLY: Sprint 3 builds nothing
  from it (owner ruling marker, 2026-09-24, BUILD-SPEC §7) unless the reconstruction
  trigger in §9 fires during T4.

  STATUS: RATIFIABLE DRAFT. NOT IMPLEMENTED. Decisions open — see §12.

  Read LAYER-2-CONTRACT.md first (§0′, §2′, §6.0 and §8.R are its current text). This
  contract is Layer 2's content-side sibling and reuses its vocabulary, its gates and its
  shape: broad by default, exclusions named and argued, gates byte-specified, interactions
  with the lower layers stated rather than discovered.
-->

# PromptingPress v2 — the LAYER 3 contract (issue #1167)

> ## STATUS — READ THIS FIRST (2026-09-26)
>
> **Draft for ratification. Nothing here is implemented, and Sprint 3 builds none of it.**
> Implementation is post-2.0.0 and purely additive. The one exception is §9: if the
> brand-site reconstruction (Sprint 3 T4) authors content that the current sanitizer
> refuses, that specific construct re-enters this sprint as a ruled fix, built to the
> clause of this contract that covers it.
>
> **Open decisions are in §12.** They come in two kinds. Owner-posture questions (P-1 to P-8)
> decide how broad content freedom is. Mechanics questions (M-1 to M-14) decide how the
> gates work. Where the text below depends on an open decision it says so and names it.
>
> **Two rules decide how to read this document.**
>
> - **The current surface (§1) is measured.** Every claim cites a file:line or a probe
>   transcript.
> - **Everything from §2 on is proposed contract.** Where it says "the engine does X", read
>   "the implementation must do X".

---

## 0 — What Layer 3 is

### 0.1 The frame: a standing CONTENT-freedom guarantee

The owner framed Layer 2 as a **standing freedom guarantee**: raw CSS reachable somewhere,
broad by default, never gated on demand, *"so that v2 never rebuilds v1's ceiling"*
(LAYER-2-CONTRACT.md §8.R, R0). Layer 3 is the same guarantee on the content side:

> **Any content the web platform can express without running script is expressible in a
> PromptingPress band.** Admission is broad by default. The exclusions are few, and each
> one is named with its reason. Nothing is admitted or refused because of how often someone
> has asked for it.

**This overrides a gate in the approved design doc, and it says so.** The design doc
(`wfroot-n5i9y-main-design-20260910-224118.md`, *"Layer 3 — The custom band"*) says Layer 3
is *"gated on Layer-2 escape telemetry demonstrating structural (not styling) demand"*. That
is a demand gate. It is the same drift the owner corrected on styling when he re-ruled Layer 2
(R2 → R2′). Under the freedom frame, telemetry can tell us **where to build first**. It never
decides **whether** a construct may exist. **P-1** asks the owner to confirm this reading.
Until he does, it is the draft's working assumption and nothing more.

**Scheduling is not gating.** "Post-2.0.0 unless §9 fires" is a **build order**. It is not a
test the content has to pass. When a construct re-enters under §9, the reason is that the
reconstruction needs it now, not that demand earned it the right to exist.

### 0.2 The three parts

| part | what it is | today |
|---|---|---|
| **3A — the content sanitizer model** | one predicate that decides what authored markup a content prop may carry. Its admission set is the kses base plus the **delta** (§3), minus the **hard exclusions** (§4). It runs at write, where it refuses, and again at render, where it re-sanitizes. | core `wp_kses_post()` / `pp_kses_inline()` / `esc_html()` at render only. Nothing runs at write, and nothing tells the author what was lost (§1). |
| **3B — the scoped sheet** | CSS **with selectors**, confined to one band, stored inside `udc` beside Layer 2's `_css`. Its declarations go through Layer 2's gates, unchanged. | absent. Layer 2 is selector-free by definition (its §6.1). |
| **3C — the custom band and content islands** | a component whose markup the author writes, carrying 3A content and a 3B sheet. **Islands** are the named regions of that markup that a human edits afterwards without touching its structure. | absent |

Parts 3A and 3B are useful without 3C, and 3A is useful without the other two. §12 **P-3**
decides whether 3B is reachable from every band or only from the custom band.

### 0.3 What this contract is NOT

- **Not an allowlist of "approved" content.** The admission set is broad by default (§3). The
  exclusions (§4) carry the whole burden of justification, the way Layer 2's §6.0 does.
- **Not a script channel.** No authored byte ever executes: no event attributes, no script,
  no `javascript:` URLs. No iframe is ever written by an author. These stay hard under every
  answer to §12.
- **Not the embed component.** `embed` expands shortcodes, which is a plugin trust boundary.
  This contract states that boundary honestly (§7.5); it does not dissolve it.
- **Not a demand ledger.** The pressure recorded in §1.6 is **context**. It explains why
  3B is shaped the way it is. It gates nothing.

---

## 1 — The current surface, measured

Measured on 2026-09-26 against `origin/main` 75e3463 (`2.0.0-alpha.2` working tree),
WordPress 7.0 on wp-env. There are two kinds of source, and the table cites both:

- **static** — a file:line read in the repo;
- **probe** — a runtime transcript. Probe transcripts live outside the repo, in the T3 evidence
  set: `probe-00` (core allowlist dump), `probe-01` (sink matrix, 32 inputs × 4 sinks),
  `probe-02` (an authoring-path write and render in Chromium), `probe-03`/`probe-04`
  (`safecss_filter_attr`), `probe-06` (shortcodes), `probe-07` (the §9 detector's
  self-test) and `probe-08` (`WP_HTML_Processor` on a foreign-content breakout).

### 1.1 Three content contracts, and where each is enforced

Every content prop falls into one of three contracts. Each is enforced **at render**, by the
component template (`ai-instructions/composition.md:60-70` documents them to the model):

| contract | sink | props |
|---|---|---|
| **RICH** | core `wp_kses_post()` | `section.body` (`components/section/section.php:328/351/418`), `faq.items[].answer` (`faq.php:186`), `table.rows[][]` (`table.php:132`), `embed.content` (`embed.php:100`, then `do_shortcode`), `hero.proof` (`hero.php:294/300`) |
| **INLINE** | `pp_kses_inline()` (`lib/helpers.php:133-150`): `a[href,title]`, `strong`, `em`, `br`; nothing else | `cta.body` (`cta.php:235`), `grid.items[].text` (`grid.php:396`), `testimonials.items[].quote` (`testimonials.php:143`) |
| **PLAIN** | `esc_html()` (URLs: `esc_url()` / `pp_esc_image_src()`) | every title, eyebrow, subheading, label, button text, `section.panel_body`, `section.panel_items[]`, `grid.items[].bullets[]`, and all chrome (footer) text |

The theme never filters `wp_kses_allowed_html`, `safe_style_css` or `kses_allowed_protocols`.
The RICH contract is therefore **exactly core's `post` context**, whatever the installed
WordPress version says it is.

### 1.2 The write gate admits any string, and nothing reports what render removes

- **At write, a content prop is type-checked and nothing else.**
  `_pp_schema_scalar_value_is_valid('string', …)` is `is_string()` (`lib/admin.php:1600-1608`).
  Every ingress ends in `pp_update_composition()` (`lib/wp.php:6660`), which transforms no prop
  string. The `_pp_composition` meta `sanitize_callback` is a JSON round-trip
  (`lib/admin.php:5093-5100`).
- **Probe-02 confirms it on the authoring path.** An `update_composition` carrying event
  attributes, a `javascript:` link, an `<iframe>`, a `<script>`, a fixed-position style and
  forged engine attributes returned `ok: true` with **zero findings**.
- **Only one content-side check refuses anything at write:** the `format: "link_url"` scheme
  check (`_pp_link_url_is_valid`, `lib/admin.php:2150-2172`). It covers URL props only, never
  links inside RICH or INLINE markup.
- **No length or byte bound applies to any content prop.** The one exception is
  `section.body_items` (8 × 80).
- **Render removes what the contract does not admit, silently.** No finding, diff or warning
  anywhere compares the authored bytes with what kses emitted. The static map ran four
  searches for one and found none.

**What this means for the invariants.** The content path breaks the rules this program holds
the styling path to:

- **I34** (*reject, never coerce*): the content is coerced, at render.
- **I35** (*no declared authoring input is silently ignored*): the author is never told.
- **The #570 convergence rule** (*write-accept = render-emit, or the envelope discloses*,
  LAYER-2-CONTRACT.md R1′): the write accepts bytes the render drops.

§2 closes all three. This is the single largest correctness argument for Layer 3, and it holds
whatever the owner rules on breadth.

### 1.3 What RICH admits today (measured, WordPress 7.0)

- **124 elements** (probe-00). The core `post` context includes the MathML set, `details`,
  `summary`, `dialog`, `button`, `textarea`, `audio`, `video`, `track`, `object` (its `data`
  attribute survives only for a same-install PDF, via core's `_wp_kses_allow_pdf_objects`),
  `figure` and `table`.
- **Not admitted:** `svg` and every SVG element, `picture`, `source`, `iframe`, `form`, `input`,
  `select`, `script`, `style`.
- **Global attributes on every element:** `class`, `id`, `style`, `data-*`, `role`, `aria-*`
  (nine names), `dir`, `lang`, `hidden`, `title`.
- **URL attributes** pass `wp_allowed_protocols()`: `http`, `https`, `ftp`, `ftps`, `mailto`,
  `news`, `irc`, `irc6`, `ircs`, `gopher`, `nntp`, `feed`, `telnet`, `mms`, `rtsp`, `sms`,
  `svn`, `tel`, `fax`, `xmpp`, `webcal`, `urn`.
- **A refused scheme is not removed; it is rewritten into a relative URL.**
  `href="javascript:alert(1)"` becomes `href="alert(1)"` (probe-01 P02/P03). The link survives
  and points somewhere else.
- **Refused elements lose their tags but keep their text.** `<script>var a=1;</script>` renders
  `var a=1;` as visible page text (probe-01 P06; probe-02, on the authoring path). `<style>`
  does the same.

**The `style` attribute is a second, independent CSS channel.** Its values pass core
`safecss_filter_attr()`, which admits **137 properties** (probe-03). Against Layer 2's `_css`
it is **narrower in properties and broader in values**:

| construct | content `style` attribute today | Layer 2 `_css` |
|---|---|---|
| `transform`, `filter: blur()`, `text-shadow`, `transition`, `animation`, `inset`, `outline`, `clip-path`, `mix-blend-mode`, `isolation`, `place-items`, `text-wrap`, `list-style`, `pointer-events` | **dropped** | admitted |
| `rgb()`, `rgba()`, `hsl()`, `hsla()`, `oklch()`, `color-mix()` colour values | **dropped** as declaration values (hex and keywords survive; `rgb()`/`rgba()` inside a gradient survive) | admitted (typed) |
| `grid-template-areas: "a b"`; any quoted string once HTML-encoded (`font-family:&quot;X&quot;`) | **dropped / mangled**: the declaration splits on the entity's `;` and leaves a fragment behind (probe-04) | admitted |
| `url(https://other.host/…)` | **admitted**, and it paints: Chromium fetched it (probe-02) | refused (ruling A2 + `_pp_forbidden_css_construct`) |
| `!important` | **admitted** | refused (LAYER-2 §6.14) |
| custom properties (`--x`) | **admitted** | refused (§6.0) |
| `position: fixed` | **admitted**, and it escaped the band in Chromium (probe-02) | admitted, with a disclosure |

**The drops are silent.** The loss is not an edge case either. The owner's live pages carry
**85** `style` attributes inside RICH content (`section.body` and `hero.proof`; 5 public pages
parsed, probe-05). Every one of them is inside the 137-property set. Those pages were
rendered by this same sanitizer, so a declaration authored outside the set would have
vanished without trace. The public HTML cannot show what the stored input held, which is
why §9 reads the **stored** bytes.

### 1.4 Content can wear the engine's identity

kses admits `data-*`, `id` and `class`, so authored content can carry the attributes the
engine uses to scope design. Measured on the authoring path: a `section.body` carrying
`<div data-pp-band="<another band's id>">` was accepted with zero findings. In Chromium it
painted **the other band's `_band` background inside the section** (probe-02e).

The same door is open for every engine marker the static map lists:

| marker | what forging it does |
|---|---|
| `data-pp-component` | pulls in a component's defaults tier |
| `data-pp-item` | pulls in a grid card's item design, and moves role presence to that card (`lib/udc.php:7273-7288`) |
| `data-pp-chrome` | pulls in nav/footer design |
| `data-pp-band-overlay` | turns on the scrim focus ring |
| band-anchor ids | shadow the band's anchor |

This is the band-identity class the promote step closes for **props**
(`pp_udc_promote_band_identity`, `lib/udc.php:11696`; #1040/#1073/#1075), reached here
through **content**, where the promote step cannot see it.

Two related behaviours are **not** forging:

- **Theme classes are borrowable by design.** `<a class="btn">` in content renders the full
  button, a documented behaviour (`components/embed/README.md:65`). Its cross-effects are
  recorded in #545 and #1071.
- **Measurement paths read authored markup as if it were the template's.** The role-presence
  probe counts a role class in content as the role being present (`lib/udc.php:7136-7144`).
  The post-apply validator reads content `img`, `href` and `background-image`
  (`lib/post-apply-validate.php:156-235`).

### 1.5 `embed` expands shortcodes after kses

`components/embed/embed.php:100` is `do_shortcode(wp_kses_post($content))`. Shortcode
**output** is never sanitized by the theme.

- **On a core-only install** the registered set is `wp_caption, caption, gallery, playlist,
  audio, video, embed`. `[video]` emits a `<source>` element that kses would strip
  (probe-06).
- **With plugins installed**, plugin output enters the same way.

This is the one place authored bytes select markup that no theme gate inspects. §7.5 states
the contract for it.

### 1.6 The pressure on record (CONTEXT — not a gate)

This is recorded because it shapes 3B, and for no other reason:

- **The descendant-of-rich-text defects:** #1049, #1068, #1069 (a list or link inside authored
  rich text that no role reaches).
- **The borrowed-button cross-effect:** #1071.
- **The four capability deletions on `::before`/`::after` among the 223 retired v1 slots:** the
  pseudo-element dimension of LAYER-2 §0.5f. `components/testimonials/README.md:141` records
  one of them.

All of this is **selector** pressure. It is what Layer 2 names at its §6.15 as *"Layer-3 /
role-taxonomy work"*.

### 1.7 The three invariant sites the brief named

- **The #730 `pre_kses` rule** (`components/section/section.php:35-57`): guard a RICH value to a
  string **before** `wp_kses_post()`, never with try/catch. `wp_pre_kses_block_attributes()`
  unhooks itself from `pre_kses`, filters, and re-hooks, so a caught throw would leave
  block-attribute KSES off for the rest of the request. The guard is present on four of the
  five RICH sinks. `hero.proof` is cast, not guarded (`hero.php:208`; the #721 class). The
  measurement mirror at `lib/udc.php:7084-7090` re-hooks after a caught render. Any Layer-3
  predicate built on kses inherits this rule verbatim (§2.4).
- **The promote step** (`pp_udc_promote_band_identity`, `lib/udc.php:11696-11726`): the
  engine-owned band flags are discarded from props and re-derived. §1.4 is its content-side
  gap, and §5.3 closes it.
- **The raw-unicode sinks**:
  - `_pp_cli_emit_json` escapes all non-ASCII except under `$raw_unicode`, whose one caller
    (`wp pp schema`) prints theme files only (`lib/cli.php:466-535`).
  - The reflected-text owners (`_pp_clean_reflected_text`, `lib/wp.php:480`; `_pp_udc_reflect`,
    `lib/udc.php:6035`) clean **messages**, never content.
  - Stored content reaches non-HTML sinks **without** a cleaner: the assistant's context
    (`lib/ai-context.php:1296`) and chat success envelopes (`changes[].from/to`). §8 states the
    rule Layer 3 must meet there.

---

## 2 — THE MODEL: one predicate, two call sites, no silent loss

### 2.1 One owner

```
pp_content_sanitize(string $bytes, string $contract): array{html: string, losses: list<Loss>}
```

- **One function in `lib/`** owns every content contract (`rich`, `inline`, `plain`, `custom`).
  No template calls `wp_kses_post()` or `pp_kses_inline()` directly any more. The templates
  call this. The "one shared engine" architecture rule (Section 1 of the pipeline, *"never add
  a surface-specific second validator"*) applies to content exactly as it does to styling.
- **Verify after sanitizing, with a spec-conformant parser.** kses is not an HTML5 parser, and
  a browser can re-parse its output differently. So the predicate's last step re-parses the
  sanitized output with core's `WP_HTML_Processor` (present in WordPress 7.0; it follows the
  HTML5 tree-building rules) and re-runs the §4 exclusion check over **that** token stream. A
  hit there is a `Loss` like any other. Measured: `WP_HTML_Processor` sees an `<img>` inside
  `<svg><style>` escape into HTML content, exactly as a browser does (probe-08). That is the
  mutation-XSS shape T-9 exists for, and the reason the check reads the re-parse, not kses's
  view of its own output.
- **A `Loss` is a fact, not advice:** `{construct, where, clause}`.
  - `construct` is the element, attribute or declaration that did not survive.
  - `where` is its position (the element path within the prop).
  - `clause` is the §4 exclusion or §3 gate that removed it.

### 2.2 Write: refuse, never coerce

`pp_validate_composition*` calls the predicate on every content prop of every band it
validates. **A write whose content produces any `Loss` is refused.**

- **The code** is `content_construct_excluded`. The message names the prop, the construct, and
  the clause, the way LAYER-2's §2.7 refusals name the exclusion.
- **Accepted bytes are stored exactly as authored.** No sanitized copy is stored, because
  I34's reject-never-coerce means a write either lands as written or does not land.

**Normalisation is not a loss.** Normalisation means entity spelling, attribute quote style,
attribute order, implied end tags, and whitespace inside tags. The predicate compares
**parsed structure**: elements, attribute names, decoded attribute values, and CSS declarations
as a list. It does not compare bytes. §9.2's detector is the reference implementation of this
comparison, run in evidence on WordPress 7.0 (probe-07).

### 2.3 Render: re-sanitize stored bytes, and disclose

- **Every render runs the same predicate over the stored bytes.** Stored bytes can predate
  the gate: a `restore_composition` (which reports and never blocks, #233), a raw meta write,
  or a site upgraded across a contract change.
- **A render-time `Loss` strips the construct and records a finding,**
  `content_stripped_at_render`. The finding states facts only: which band, which prop, which
  construct, which clause.
- **Where the finding surfaces:** everywhere findings already do. That is `wp pp check page`,
  the post-write envelope's `findings`, and the chat's validation report.
- **The template's own escaping still runs after the predicate.** This is defence in depth,
  not a second contract.

### 2.4 The #730 rule is inherited

The predicate sits on top of `wp_kses()` for its base (§3.1), so it inherits the `pre_kses`
constraint verbatim:

- guard to a string before the call;
- never catch a throw from inside it;
- measure one process per case.

A predicate that catches would turn a visible failure into a request-long sanitization hole.
Anything the predicate adds (the SVG pass, the style-attribute gate) runs **after** kses on
kses's output, and it may throw only before kses has been entered.

### 2.5 Convergence, from rendered truth

**Whatever the write gate accepts, the render emits,** equal after normalisation. The
post-2.0.0 test plan pins this against the **rendered page** (§10 T-2). It is never pinned
against a second computation of what the render "should" do: findings and pins derive from
compiled and rendered truth, never re-derived (the T1/T2 doctrine).

### 2.6 Which stored bands a write validates is **M-2**

`update_composition` validates the whole composition, and every read surface re-validates
what is stored. So a band whose stored content fails a gate added later would block edits to
unrelated bands. This is the #1007 class, which LAYOUT-GROUP-CONTRACT.md §3.2 names for group
additions.

This draft proposes that the write gate refuse a content `Loss` only in **bands the write
changes**. A stored band's losses surface as the §2.3 finding and never block. §12 M-2 asks for
the ruling.

---

## 3 — ADMISSION: broad by default (the kses delta, byte-specified)

### 3.1 The base

**RICH admits core's `post` context as measured on WordPress 7.0:** 124 elements with their
attributes, `wp_allowed_protocols()` for URLs. The full dump is probe-00.

**The base becomes a PP-owned table, derived from core once and pinned against core** (§12
**M-4**). Today PP's content contract silently changes whenever WordPress changes its list,
and that is the drift I43 forbids for every other contract PP publishes. The pin fails the
suite when core's `post` list and PP's table diverge, so the difference is read and ruled on,
never absorbed.

### 3.2 The delta: what RICH (and `custom`) admits beyond the base

Each row is a gate. "Gate" gives the exact acceptance rule, and anything the gate does not
name is refused, never passed through.

**Δ1 — inline SVG, static subset.**

- **Elements:** `svg`, `g`, `defs`, `symbol`, `use`, `title`, `desc`, `path`, `rect`,
  `circle`, `ellipse`, `line`, `polyline`, `polygon`, `text`, `tspan`, `linearGradient`,
  `radialGradient`, `stop`, `clipPath`, `mask`, `pattern`, `marker`, `a`.
- **Filter primitives:** `filter`, `feBlend`, `feColorMatrix`, `feComponentTransfer`,
  `feComposite`, `feDropShadow`, `feFlood`, `feFuncR`, `feFuncG`, `feFuncB`, `feFuncA`,
  `feGaussianBlur`, `feMerge`, `feMergeNode`, `feMorphology`, `feOffset`, `feTurbulence`,
  `feDisplacementMap`.
- **Attributes:**
  - geometry and presentation: `d`, `points`, `x`, `y`, `x1`, `y1`, `x2`, `y2`, `cx`, `cy`,
    `r`, `rx`, `ry`, `width`, `height`, `viewBox`, `preserveAspectRatio`, `transform`,
    `pathLength`, `fill`, `fill-opacity`, `fill-rule`, `stroke`, `stroke-width`,
    `stroke-linecap`, `stroke-linejoin`, `stroke-miterlimit`, `stroke-dasharray`,
    `stroke-dashoffset`, `stroke-opacity`, `opacity`, `clip-rule`, `color`, `vector-effect`,
    `shape-rendering`, `paint-order`, `visibility`, `display`;
  - text: `font-family`, `font-size`, `font-weight`, `font-style`, `letter-spacing`,
    `text-anchor`, `dominant-baseline`;
  - gradients, clips, masks, patterns, markers: `offset`, `stop-color`, `stop-opacity`,
    `gradientUnits`, `gradientTransform`, `spreadMethod`, `fx`, `fy`, `clipPathUnits`,
    `maskUnits`, `maskContentUnits`, `patternUnits`, `patternContentUnits`,
    `patternTransform`, `markerWidth`, `markerHeight`, `refX`, `refY`, `orient`,
    `markerUnits`;
  - filters: `filterUnits`, `primitiveUnits`, `in`, `in2`, `result`, `stdDeviation`, `dx`,
    `dy`, `flood-color`, `flood-opacity`, `operator`, `k1`, `k2`, `k3`, `k4`, `type`,
    `values`, `tableValues`, `slope`, `intercept`, `amplitude`, `exponent`, `mode`,
    `baseFrequency`, `numOctaves`, `seed`, `scale`, `xChannelSelector`, `yChannelSelector`,
    `radius`;
  - plus the base's global attributes.
- **Gate:** any attribute whose value may reference something (`fill`, `stroke`, `clip-path`,
  `mask`, `filter`, `marker-start`, `marker-mid`, `marker-end`) accepts a **fragment
  reference only**, as `^url\(#[A-Za-z][A-Za-z0-9_-]{0,63}\)\z`. It otherwise takes its
  ordinary literal (colour, `none`, and so on) under the Δ3 value gates. `use` and `a` take
  `href` (and the legacy `xlink:href`):
  - on `use`: `^#[A-Za-z][A-Za-z0-9_-]{0,63}\z`, a same-document fragment only;
  - on `a`: the E2 URL gate.
- **Reason:** icons and diagrams are ordinary web content. The static subset executes
  nothing. Everything that can execute or fetch in SVG is named in §4 (E1, E2, E3, E7).
- **Parse note:** browsers repair SVG attribute case in foreign content, so `viewbox` becomes
  `viewBox`. The predicate compares attribute names case-insensitively and emits the
  canonical case.

**Δ2 — responsive images and media sources.**

- `picture` (global attributes only).
- `source` inside `picture`, `video` or `audio`: `srcset`, `sizes`, `media`, `type`, `width`,
  `height`, `src`.
- On `img`, add `srcset`, `sizes`, `decoding`, `fetchpriority`.
- **Gate:** every URL in `src` and in each `srcset` candidate passes E2. Hosts are governed by
  **P-4**.
- **Reason:** the platform's own responsive-image mechanism. Without it, content images are
  one-resolution-fits-all.

**Δ3 — the `style` attribute runs the program's CSS gates, not core's list.**

- **Replaces** `safecss_filter_attr()` for PP content sinks.
- **Order of operations:** decode HTML entities first, then split into declarations on `;`
  **outside** quotes and parentheses.
- **Each declaration** is `<property>:<value>`:
  - the **property** passes LAYER-2 §2′.1's charset `^-?[a-z][a-z0-9-]{0,63}\z`, and must not
    be a LAYER-2 §6.0 exclusion (custom properties, `all`, `content`, `behavior`,
    `-moz-binding`, `-pp-background-overlay`);
  - the **value** passes the same **security** gates `_css` values pass:
    `_pp_forbidden_css_construct()` (no `url()`, `image-set()`, `image()`, `src()`,
    `expression()`, `@import`, `{ } ; < >`, backslash, control characters, comment
    delimiters), `_pp_udc_delimiters_balanced()`, and no `!important`.
- **Typed grammars do not run here** (**M-3**). Browsers already drop an invalid declaration in
  an attribute harmlessly. A typed grammar would re-open the #1007 class through content, the
  first time a group claimed a new property (§5.2).
- **Effect against today:** admits every property in §1.3's first row, the modern colour
  functions and quoted strings, which removes the entity-split mangling. Refuses `url()`,
  `!important` and custom properties, where the content channel is broader than the styling
  channel today.
- **None of the three refusals appears in the owner's measured content**: `url(` and `!important` occur in 0 of the public pages' `style` attributes (probe-09), and custom properties occur only in engine-emitted v1 slot styles, never inside a content container (probe-05). The
  refusals exist for the styling channel's reasons: ruling A2, LAYER-2 §6.14, and §6.0.
- **Reason:** one CSS value gate across the program. §5.2 states the interaction.

**Δ4 — `target="_blank"` carries `rel="noopener"`.**

- **Rule:** the predicate adds `noopener` to `rel` when `target` is `_blank` and `rel` lacks
  it. The author's other `rel` tokens are kept.
- **This is the one place the predicate adds bytes.** It is disclosed as normalisation, not as
  a loss (**M-10**).

**Δ5 — forms, if P-5 admits them.**

- `form` (`action`, `method` ∈ `get|post`, `name`, `autocomplete`, `novalidate`).
- `input` (`type` in a closed set that **excludes** `file`, and every `type` a submit
  button can reach, gated as below).
- `select`, `option`, `optgroup`, `label`, `fieldset`, `legend`, `output`.
- The base's `textarea` and `button`.
- **Gate:** `action` passes E2. `formaction`, `formtarget`, `formmethod` and `formenctype` are
  refused (E2/E9), so the destination is always the form's own reviewed `action`.
- **Reason:** see P-5. This row is **inert until the owner rules**.

### 3.3 INLINE and PLAIN

INLINE and PLAIN keep their contracts in this draft. Their narrowness is the **meaning** of
the prop. A card's supporting line is inline copy, and a label is text. The ceiling they
imply is lifted **elsewhere**: RICH props, the scoped sheet, and the custom band carry
everything they do not.

**P-2** asks whether the owner wants INLINE to widen as well. The obvious candidate is
`span` with `class` and `style` for accent runs inside a card line.

The predicate still runs over INLINE and PLAIN at write:

- **INLINE:** a `Loss` of a non-inline element is refused, where today it is silently
  stripped.
- **PLAIN:** markup in a plain prop is refused with a message saying the prop is plain text,
  where today it renders as literal `<b>` text (probe-02). This closes I35 on the two
  narrower contracts too.

---

## 4 — THE HARD EXCLUSIONS: named, argued, each with its future test shape

Broad by default means these rows carry the whole burden. Each is here because it **executes,
fetches, escapes or forges**, not because it looked risky. Each one is refused **at write**
with `content_construct_excluded`, and stripped **at render** from stored bytes with the §2.3
finding.

| # | excluded | why | the test shape the implementation owes (§10) |
|---|---|---|---|
| **E1** | **Event-handler attributes:** any attribute whose name matches `^on` (ASCII case-insensitive), on every element and namespace. That includes SVG/MathML handlers such as `onbegin`, `onload`, `onfocusin`. | script execution. Owner-named. | a name matrix: mixed case, every `on*` in the HTML, SVG and MathML specs, `on` followed by non-letters; each refused at write, absent from the **browser-parsed** render |
| **E2** | **Script-bearing and off-list URLs** in every URL-valued attribute: `href`, `src`, `srcset` (each candidate), `poster`, `cite`, `action`, `data`, `xlink:href`, `background`, `longdesc`, `usemap`. The scheme, after entity decoding and stripping of C0/whitespace (the URL parser's own preprocessing), must be in `wp_allowed_protocols()`, or the URL must be relative or `#fragment`. `javascript:`, `vbscript:` and `data:` are refused. Unlike today (§1.3), the refusal happens **at write**; nothing is rewritten into a relative URL. | script execution through navigation. Owner-named (*"no JS URLs"*). | an obfuscation matrix: entity-encoded, tab/newline-split, mixed-case and leading-space schemes, protocol-relative URLs, each `srcset` candidate independently; plus the rewrite-to-relative behaviour pinned **absent** |
| **E3** | **Nested browsing contexts written by the author:** `iframe`, `frame`, `frameset`, `object`, `embed`, `applet`, `portal`, `fencedframe`; and the `srcdoc` attribute anywhere. | third-party execution context, clickjacking, and content no gate can inspect. Owner-named (*"no iframes"*). An **engine-built** embed is a separate question (**P-6**). | each element refused; `srcdoc` refused on every element; `<object data>` pinned refused even with a same-install URL |
| **E4** | **Script, style and document-level elements:** `script`, `style`, `noscript`, `template`, `base`, `meta`, `link`, `title`, `html`, `head`, `body`, `slot`. | executes (`script`); unscoped page-global CSS that bypasses 3B's scoping (`style`); parse differentials that feed mutation-XSS (`noscript`, `template`); and document state (`base`, `meta` refresh, `link`). **CSS belongs in the scoped sheet (3B), which is scoped and gated.** | each refused at write; at render the **content** of a refused `script`/`style` is removed with it, never left as visible text (the §1.3 leak pinned closed) |
| **E5** | **SVG active and cross-namespace content:** `animate`, `animateMotion`, `animateTransform`, `set`, `discard`, `foreignObject`, `image`, `feImage`, `script`, `style` (inside SVG), and any `href`/`xlink:href` on SVG elements other than Δ1's `use` and `a` rules. | `animate`/`set` can rewrite an attribute such as `href` to a script URL after sanitization. `foreignObject` re-enters the HTML namespace (a mutation-XSS source). `image`/`feImage` fetch. | the classic animation-rewrites-`href` shapes; a `foreignObject` round trip; parse-serialize-reparse idempotence (T-9) |
| **E6** | **The engine-owned namespace:** any attribute named `data-pp-*`; any `id` matching the band-id or item-id grammars the engine mints (`^pp-[0-9a-f]{8}\z`, `^it-[0-9a-f]{8}\z`); the page-reserved ids `main` and `pp-nav-menu`; and any `id` equal to a band anchor (`props.id`) in the same composition. | forging the engine's identity (§1.4, measured). The content-side twin of the promote step's props rule (§5.3). | one row per marker in §1.4's table, asserting the forged scope does **not** paint (computed style from the rendered page, not the stored map) |
| **E7** | **Style-attribute exclusions** (Δ3): LAYER-2 §6.0's set; any value the security gates refuse, including `url()` of every kind (A2); and `!important`. | the same reasons LAYER-2 gives, with one addition: **the no-external-resource rule is load-bearing for 3B's attribute selectors** (§6.7). | the LAYER-2 §6.0 matrix, re-run through a `style` attribute; the `image-set()` bare-string case that LAYER-2 §6.0 records as once missed |
| **E8** | **Custom elements and unknown elements** (any tag not in §3.1 + §3.2). | without script a custom element is an inert span that validates green and paints nothing special: the I19 shape. Listed so that freedom-first does not read as "anything with angle brackets". | an unknown-tag matrix refused, with a message naming the element |
| **E9** | **Submission and navigation redirectors:** `formaction`, `formtarget`, `formmethod`, `formenctype`, `ping`, and `http-equiv`. | they move where a click or submit goes, or where a request is sent, outside the reviewed `action`/`href`. | each refused on every element |

**What is NOT on this list, deliberately:**

- `position: fixed` and the rest of the escape-the-box family, in `style` attributes. These are
  admitted, as LAYER-2 R2′ admits them in `_css`. The author owns the outcome, and a finding
  says so (§5.1).
- `class`, `id` outside E6, `role` and `aria-*`. These are ordinary web authoring.
- **External media hosts.** This is **P-4**, the owner's call, and deliberately not self-ruled
  here.

---

## 5 — LAYER-1 / LAYER-2 INTERACTION RULES

### 5.1 A content `style` attribute outranks every band rule on its own element

- **The rank.** An inline declaration beats every selector in the band's unlayered block,
  because `!important` is refused on both sides (Δ3, LAYER-2 §6.14). So on the element that
  carries it, a content `style` attribute **wins** over role defaults, band UDC values, `_css`
  and the scoped sheet.
- **It does not reach role elements.** Content elements are descendants of a role's element
  (e.g. inside `.section__content`), never the role element itself. A content style can only
  shadow what those descendants **inherit**.
- **The rung is new, and it is disclosed.** The finding `content_inline_style` reports, per
  band, how many declarations content carries and which properties they set. It is a fact
  about what paints, in the shape LAYER-2's `udc_css_unchecked_property` set. It is not a
  per-declaration warning. **M-9** fixes the exact shape.

### 5.2 One CSS value gate across the program: "claiming" does not reach content

LAYOUT-GROUP-CONTRACT.md §3.2 established that **claiming a property types its `_css`
writes**. A group that takes a property narrows what `_css` accepted for it.

Content `style` attributes are deliberately **outside** that rule. They run the security
gates only (Δ3), never a typed grammar. So a later group that claims a property never makes
stored content newly invalid. Content is the one surface where an unrelated band's edit must
never be blocked by a grammar change. The #1007 class stays closed through content by
construction, not by care.

**The cost is named.** A mistyped value in a content style (`width: 12 px`) is accepted and
dropped by the browser. That is the author's outcome through a channel documented as
security-checked only, which is the LAYER-2 R1′ reconciliation applied to content (*"honest
scope-of-check"*).

### 5.3 The engine namespace is reserved against content (E6)

The promote step discards engine-owned **props** and re-derives them. E6 is the same rule on
the other door. An author cannot write the attributes and ids the engine uses to decide which
band, component, item or chrome region an element belongs to.

**Reading** those attributes is fine. A 3B selector such as `[data-pp-item="it-…"] .grid__title`
targets an engine marker without forging one.

### 5.4 Theme classes in content: admitted, not a contract

`class` is admitted. An author may borrow `btn`, `section__content` or any theme class, and the
content then renders with that class's styling. That is useful and documented (§1.4).

Two consequences are stated rather than prevented:

- **Theme class names are not a public API.** Only the roles a schema declares are. A class
  rename between releases is not a breaking change **for content that borrowed it** (I36
  governs declared surfaces). The AI surface says so (§11).
- **Borrowed role classes read as role presence** to the measurement paths (§1.4). The
  implementation must scope the presence probe to **template-rendered** elements, by marking
  the content container and excluding its descendants, or disclose the ambiguity. This is
  **M-11**. The marker is an engine attribute the **template** emits on the container
  (for example `data-pp-content`). E6 already reserves the `data-pp-*` namespace, so content
  cannot forge it.

### 5.5 The scoped sheet against roles and `_css`

A 3B rule and a role declaration are **different selectors**, so the ordinary cascade decides
between them by specificity, then order. The engine emits the scoped sheet **after** the
band's role and `_css` declarations, in the same unlayered block, so a tie goes to the scoped
sheet.

The engine does **not** promise that a scoped rule wins against a more specific role
selector. Writing CSS with selectors means owning specificity; that is the price of the
selector. The AI surface teaches the one reliable lever: raise the selector's own specificity.
The engine never emits `!important`.

---

## 6 — THE SCOPED SHEET (3B): selectors, confined to one band

### 6.1 Storage

`udc._scoped`, beside the role maps and `_band`, inside `udc`. The reason is LAYER-2's R3: an
address outside `udc` is accepted, stored and ignored by construction.

It is a list of **rules**:

```json
"_scoped": [
  {"selector": ".section__content ul", "css": {"list-style": "disc", "padding-left": {"d": "1.5rem", "p": "1rem"}}},
  {"selector": ".faq__answer a:hover", "css": {"color": "@accent"}},
  {"selector": "::before", "css": {"content": "\"\"", "background": "#FF5C2E"}}
]
```

Rule **order is significant**, and preserved: it is the cascade's tie-breaker. So the sheet is
a list, not a map.

**P-3** decides where `_scoped` is accepted:

- on **every** band (this draft's recommendation);
- on the custom band only.

### 6.2 The selector gate (byte-specified)

A `selector` is a string of at most 256 bytes. It splits into a **selector list** of at most 16
entries, on commas outside quotes, brackets and parentheses. Each entry must satisfy every rule
below.

1. **Charset.** Every byte is in `[A-Za-z0-9 _\-.#\[\]()>+~:=^$*|"',]`. Nothing outside it
   reaches the sheet: not `{`, `}`, `;`, `@`, `\`, `/`, `<`, `&`, `%`, or control
   characters. It is an allowlist of bytes, as in LAYER-2 §2′.1.
2. **Quotes and brackets.** Quotes appear only inside an attribute selector's value, and are
   balanced. `[`/`]` and `(`/`)` are balanced, as walked by `_pp_udc_delimiters_balanced()`'s
   string-aware walk.
3. **`|`.** Appears only as the `|=` operator. Namespace prefixes are refused.
4. **Pseudo-classes.** Only these are admitted:
   - `:hover`, `:focus`, `:focus-visible`, `:focus-within`, `:active`, `:link`, `:visited`,
     `:target`;
   - `:first-child`, `:last-child`, `:only-child`, `:first-of-type`, `:last-of-type`,
     `:only-of-type`, `:empty`;
   - `:nth-child()`, `:nth-last-child()`, `:nth-of-type()`, `:nth-last-of-type()`. Their
     argument matches `^(odd|even|[+-]?\d{0,3}n?([+-]\d{1,3})?)\z` optionally followed by
     ` of <selector>`, where the inner selector is gated recursively;
   - `:not()`, `:is()`, `:where()`, `:has()`, with arguments gated recursively, to a depth of 3;
   - `:lang()`, `:dir(ltr|rtl)`, `:open`, `:checked`, `:disabled`, `:enabled`,
     `:placeholder-shown`.
   `:root`, `:host`, `:host()`, `:scope`, `:defined` and every vendor-prefixed pseudo-class are
   refused.
5. **Pseudo-elements.** At most one per entry, and last. Only these are admitted: `::before`,
   `::after`, `::marker`, `::first-line`, `::first-letter`, `::placeholder`, `::selection`,
   `::backdrop`, `::file-selector-button`. `::part()`, `::slotted()` and vendor-prefixed ones
   are refused.
6. **Type selectors.** The names `html`, `head` and `body` are refused. Inside a band they
   match nothing, which would validate green and paint nothing (I19).
7. **A leading combinator.** Only `>` may lead an entry. `+` and `~` may not, because they
   would select the band root's **siblings**, outside the band.

**Emission.** The engine emits each entry `E` as follows:

| entry `E` | emitted as | meaning |
|---|---|---|
| begins with `:` or `::` | `[data-pp-band="<id>"]E` | compound on the band root, e.g. the root's `::before` |
| begins with `>` | `[data-pp-band="<id>"] E` | children of the root |
| anything else | `[data-pp-band="<id>"] E` | descendants of the root |

A leading pseudo-class is therefore always a condition **on the root**: `:hover .x` means
"`.x` inside the band while the band is hovered". To put a pseudo-class on a descendant
instead, begin the entry with `*` (`*:is(.a, .b)`), which the descendant row then emits.

**The subject of every emitted selector is therefore the band root or one of its
descendants,** whatever `:is()`, `:has()` or `:not()` contain. A condition may **read**
outside the band (`:is(.dark *)`), but a rule can only **paint** inside it. Scoping is a
property of the emitted form, not a filter applied afterwards.

**The emission form is M-5** (prefix, as above, or `@scope`).

### 6.3 Declarations: exactly Layer 2

A rule's `css` map is **a LAYER-2 `_css` map**, unchanged:

- the property charset and the §6.0 exclusions;
- typed where known and verbatim where not, with `udc_css_unchecked_property`;
- `@references` require a declared type;
- breakpoint-keyed values, with the engine emitting every `@media`.

The one difference: **states live in the selector**, as `:hover` and friends. A rule's `css`
map rejects LAYER-2's state sub-map keys, because a second way to say `:hover` is the I36
aliasing shape.

### 6.4 `content` inside the scoped sheet (**M-7**)

LAYER-2 excludes `content` because it puts author text on the page outside the truth
machinery. That reasoning holds for **text**. Styling a `::before` or `::after` needs
`content` to create the box.

This draft admits `content` in a scoped rule **only** in these forms:

- `""` (the empty string);
- `none` or `normal`;
- `counter(<ident>)` or `counters(<ident>, "<string of at most 8 bytes, no letters or digits>")`.

`attr()` is refused, and so is any string carrying a letter or digit. That removes the
design doc's named `content: attr()` vector and keeps authored **text** in content props,
where 3A sees it.

The four retired `::before`/`::after` colour capabilities (§1.6) need only the empty-string
form. The theme's own pseudo-elements already carry `content`.

### 6.5 At-rules

None may be authored. The engine emits `@media` from `pp_udc_breakpoints()`, exactly as
LAYER-2 §6.2 does.

`@keyframes` stays excluded: an author-defined animation name would be a page-global
identifier, able to collide with the theme's own keyframes. A band-namespaced keyframe
mechanism is future work, named in §11.

### 6.6 Parsing without a dependency (**M-6**)

The design doc expected *"a real CSS parser dependency"* here. With §6.2's byte-level gate and
§6.3's reuse of Layer 2's value gates, the grammar is small and closed. This draft recommends
a bounded in-house tokenizer:

- split the selector list;
- walk each compound;
- check each pseudo-class against the allowlist;
- recurse into functional pseudo-classes to depth 3.

Every accepted byte is named. A dependency would add a supply-chain surface and a second
grammar owner for no admitted construct it alone could parse.

### 6.7 The dependency on "no external resource"

Attribute selectors are admitted. They are safe **because** no declaration value, in `_css`,
in the scoped sheet or in a content `style` attribute, can name an external resource
(`url()`, `image-set()`, `image()`, `src()`, `@import` are all refused ahead of every other
check).

**Any future relaxation of that rule, anywhere in the program, must re-review 3B's attribute
selectors in the same change.** This clause exists so the dependency cannot be forgotten.

---

## 7 — THE CUSTOM BAND AND CONTENT ISLANDS (3C)

### 7.1 The component

This draft names it `custom`; the name is **M-12**. It is one new component, with:

- **`markup`** (string, contract `custom`): the author's HTML. It is 3A's RICH admission set
  with the full delta, plus the island attributes of §7.2.
- **`islands`** (object): island name → content string. Each value passes the contract its
  island declares.
- **`id`**: as every band.

Its `udc` carries `_band` (every group the schema permits), `_css`, and `_scoped` (§6).
It declares **no roles** other than `_band`: the author's markup has no stable role
selectors, so the scoped sheet is how its insides are styled.

### 7.2 Islands

**An island** is an element in `markup` that carries `data-pp-island="<name>"`.

- The attribute is the **one** `data-pp-*` name E6 lets authors write. The predicate owns it.
- **Name gate:** `^[a-z][a-z0-9_-]{0,63}\z`, unique within the band, and at most 64 islands
  per band.
- **The island's contract** comes from `data-pp-island-kind`, which is `plain` (the default),
  `inline` or `rich`.
- **The element must be empty in `markup`.** Its content lives in `islands.<name>`, and the
  engine renders it into the element through that contract's sink.

**Why content lives outside the markup.** The design doc's case against a single free-HTML
band (its *Approach B*) was that it loses *"content semantics and field-level diff truth"*.
Separating structure (`markup`) from editable content (`islands`) keeps both:

- The composition diff, history ring and preview/approve show `islands.hero-quote: "A" → "B"`,
  not a changed 4 KB markup blob.
- CAS freshness and undo operate per write, as for every prop.
- A human editing text never touches the structure.

**Write rules.**

| situation | result |
|---|---|
| an island named in `markup` with no `islands` entry | renders empty, with a finding (`custom_island_empty`) |
| an `islands` entry with no element in `markup` | refused (`custom_island_unknown`): the I35 class |
| a non-empty island element in `markup` | refused: content belongs in `islands`, and two places for one piece of text is the I36 shape |

**Editing surfaces.**

- An island is addressed by the existing prop path `islands.<name>`, through `update_component`
  and `wp pp operate patch`. No new action is needed.
- The accordion editor (post-2.0.0, BUILD-SPEC §7) lists islands as fields.
- **P-7** decides whether structure (`markup`) is editable by the human editor surface or only
  through structural writes (the AI and the JSON editor).

### 7.3 Islands are a custom-band construct

Existing components' content props already **are** islands, in effect: named, typed, diffed
fields. They gain nothing from `data-pp-island`, and the attribute is refused outside
`custom.markup` (E6).

### 7.4 What the engine can and cannot verify inside custom markup

The readability, presence and overlay findings reason about **declared roles**. Custom markup
declares none. So those findings run on `_band` only, and the band carries one disclosure,
`custom_band_unverified`. It states that the insides were checked for **safety** (§3–§4) and
not for **readability**.

This is the design doc's *"reduced verifiability disclosed via the same findings check
code"*, made specific.

### 7.5 `embed` and shortcodes: the plugin boundary, stated

`embed.content` keeps its order: kses, then `do_shortcode`. **Shortcode output is the
installed plugins' and core's trust, not the author's bytes**, and the theme does not sanitize
it. The hard exclusions (§4) govern **authored** bytes, and the authored bytes of an embed band
pass the full predicate.

Four obligations follow. Each is part of this contract:

1. **The AI surface states the boundary exactly.** Today the model is told that iframes,
   handlers and `javascript:` are "always stripped, whoever authored" the content
   (`ai-instructions/composition.md:72-74`). That is true of the kses pass and not of shortcode
   output. The corrected sentence says which is which.
2. **The band carries a disclosure.** A band whose content expands at least one shortcode
   carries `content_plugin_output`, naming the shortcode tags.
3. **The measurement paths treat shortcode output as opaque.** The presence probe already
   neutralizes shortcodes (`lib/udc.php:7070-7072`). The post-apply validator must do the same,
   or disclose that it did not.
4. **The preview isolates it** (§8.3).

**P-8** asks the owner whether plugin output should instead pass the predicate. That would
break any plugin that emits script, which is most interactive ones.

---

## 8 — CONTENT BYTES OUTSIDE HTML (I37)

Layer 3 widens the bytes a content prop can carry. Every non-HTML sink those bytes reach
must meet I37: bounded, escaped at its sink through the shared owner, or recorded as a
deliberate exclusion.

### 8.1 CLI and chat envelopes

`_pp_cli_emit_json` already escapes all non-ASCII outside `wp pp schema` (§1.7), and that
holds. The chat client renders `changes[].from/to` with `textContent`. That is safe today, and
it becomes an obligation: **no chat surface ever renders a content value as HTML.**

### 8.2 The assistant's context

Stored content reaches the model's context verbatim (§1.7). Before Layer 3 widens it, that
context must meet two conditions:

- **Content is framed as quoted data,** never interpolated as instruction-bearing prose.
  `props.title` is interpolated raw today, at `lib/ai-context.php:984`.
- **Invisible format characters** (`\p{Cf}`) are neutralized at that sink. The exceptions are
  the directional marks legitimate right-to-left text needs: U+200E, U+200F and U+061C.

Content itself may carry any character. The rule binds the **sink**. The ai-ready
prompt-regression harness gains a case for each condition (§10 T-12).

### 8.3 The editor preview

Rendered content is shown in the editor preview. **Layer 3 requires that preview to render
content in an opaque origin:** a sandbox that never combines `allow-scripts` with
`allow-same-origin`, or an equivalent isolated document. The reason: content, and the plugin
output §7.5 describes, must never share an origin with the admin session that previews it.

This is a **precondition** of widening content. It is not a Layer-3 feature.

**What the precondition costs, stated so it is not discovered late.** The editor refreshes the
preview by reading and writing the frame's document directly: it swaps
`frame.contentDocument.body.innerHTML` to keep the scroll position
(`assets/js/pp-admin-editor.js:185-192`). An opaque-origin frame refuses that access. The
refresh therefore has to move to a message the frame answers (`postMessage`), or to a full
reload with the scroll position passed in. The implementation owns that change, and it is
part of the precondition, not optional polish.

---

## 9 — THE RECONSTRUCTION TRIGGER

The owner's 2026-09-24 ruling: Layer 3 implementation is post-2.0.0 *"unless the brand-site
reconstruction hits content the current sanitizer refuses, in which case that specific need
re-enters as a ruled fix."* This section defines *"hits content the current sanitizer
refuses"* so that it is an **observation**, not a judgement.

### 9.1 The trigger fires when ALL FOUR hold

1. **Source.** A content string `S` that T4 authors into a content sink of §1.1. The string is
   either carried over from the owner's **stored** compositions or written for the
   reconstruction.
2. **Loss.** The current sink's output `R` loses a construct of `S`, as §2.2 defines a loss.
   Normalisation is not a loss. There are three shapes:
   - an element, attribute, decoded attribute value or style declaration present in `S`'s
     parsed structure is absent from `R`'s;
   - the visible text changes (for example, a `<script>` body leaking as text);
   - a PLAIN sink renders markup as literal text.
3. **Not by design.** The lost construct is **not** in §4's hard-exclusion set. A lost `onclick`
   is the future contract working as designed, and never fires.
4. **Visible against the reference.** The loss produces a difference against the
   reconstruction's reference. The reference is the live page, or the owner's design source
   where the reconstruction departs from the live page. The difference must show at 375 px or
   1280 px, or in the accessibility tree: a missing element, a missing style effect, changed
   text, or a changed accessible name or role.

Condition 4 is **not a demand test**. It separates losses that change the page from losses
that change nothing: an unused `data-*` attribute, or a declaration that repeats an inherited
value. It does not ask whether the construct is "needed enough".

### 9.2 Procedure

1. **Read stored bytes, never rendered ones.** The public page is already post-sanitizer
   (§1.3), so it cannot show a loss. T4 reads each carried-over band's stored props through the
   read surfaces (`wp pp operate inspect-composition`). For prod content, that needs whatever
   read-only export the orchestrator authorizes (**M-13**). T4 also records every new content
   string it authors.
2. **Run the detector** over every content string. The reference implementation is
   `t4-content-loss-detector.php` in the T3 evidence set (self-test: probe-07). It is a
   read-only `wp eval-file` script, not theme code. It reports each loss as **EXCLUDED** (it
   matches §4) or **CANDIDATE**.
3. **For each CANDIDATE, judge condition 4** with a screenshot pair at 375 and 1280 px:
   reference vs rendered. Where the loss is semantic rather than visual, add an
   accessibility-tree comparison.
4. **Fire.** A CANDIDATE that meets condition 4 **fires the trigger.** T4 stops that construct's
   work and hands back a **re-entry record**:
   - sink, band and prop;
   - `S`, `R` and the lost construct;
   - the screenshot pair;
   - the clause of this contract that would admit the construct (a §3.2 Δ row, §3.1, or
     "none: §12 needs a ruling first").

### 9.3 What re-enters

**Exactly the clause that admits the lost construct, implemented to this contract,** with that
clause's rows of the §10 test plan. Examples:

- a dropped `transform` in a `style` attribute re-enters **Δ3**, which means the whole
  style-attribute gate, because Δ3 is one gate and half of it would be a second contract;
- a dropped inline SVG icon re-enters **Δ1**.

The re-entry is a **ruled fix**. The orchestrator rules which clause, and routes any §12
question that clause depends on to the owner first. The rest of Layer 3 stays post-2.0.0.

**Order within a re-entry is fixed.** If the admitted construct widens content, then
§2.2 (write-time refusal), §8.2 and §8.3 land **with** it or **before** it. Widening content
before the author can be told what was lost, or before the preview is isolated, would build on
a silent path.

### 9.4 What never fires the trigger

- An EXCLUDED loss (§4).
- Normalisation (§2.2).
- A **styling** gap that no content construct would close. That is Layer 1/2 territory, with
  its own routes.
- Chrome text (the footer and nav options). These are PLAIN by design, and **P-2** asks
  whether that stays.
- A loss invisible under condition 4.

### 9.5 Where it is most likely to fire (a prediction, not a gate)

The owner's live content is inline-style-heavy (85 `style` attributes, §1.3). So the likeliest
firings are:

- a `style` declaration outside core's 137-property set;
- a functional colour value (`rgb()`, `hsl()`);
- a quoted string mangled by the entity split.

All three are **Δ3**. Next likeliest are inline SVG icons (**Δ1**) and responsive images
(**Δ2**).

---

## 10 — TEST PLAN (for the post-2.0.0 implementation; NOT created now)

The rules every pin follows:

- **Authoring-path first** (Section 14.1): through `create_page` / `update_component` /
  `update_composition`, never raw meta, except for the stored-bytes pins that exist to test
  raw meta.
- **Rendered truth, not re-derivation:** where a pin asserts what paints, it reads the
  **browser-parsed rendered page** (Playwright, computed style or DOM). It never recomputes
  the expected output from the predicate it is testing.
- **Every admission row and every exclusion row is pinned in both directions,** and a planted
  defect in the gate must turn a pin red.

| id | pins | shape |
|---|---|---|
| T-1 | §3.1 base ownership | the PP-owned table equals core's `post` context on the pinned WordPress version, and diverges loudly on drift |
| T-2 | §2.5 convergence | for every §3 admission row: write accepted, stored verbatim, and the rendered DOM contains the construct (normalised) |
| T-3 | §2.2 write refusal | each §4 row refused with `content_construct_excluded`, naming the construct and the clause; nothing stored |
| T-4 | §2.3 render strip + finding | raw-meta and restore paths: the construct is absent from the rendered DOM, and `content_stripped_at_render` carries the facts |
| T-5 | E1 / E2 matrices | §4's name and obfuscation matrices; rewrite-to-relative pinned absent |
| T-6 | E6 forging | one row per §1.4 marker: the forged scope does not paint (computed style); the promote step and E6 agree |
| T-7 | Δ3 style gate | LAYER-2's §6.0 matrix through a `style` attribute; §1.3's first-row properties admitted; the entity-split case round-trips; every property the owner's 85 measured `style` attributes use is admitted (a fixture built from probe-05's property census) |
| T-8 | Δ1 / E5 SVG | the static subset renders; fragment-only references; each active element refused; `use` with an external `href` refused |
| T-9 | mutation-XSS idempotence | `sanitize(browser_parse(serialize(sanitize(x)))) == sanitize(x)`, and the browser DOM contains no E-row construct, over a corpus of known parse-differential shapes (namespace confusion, `noscript`/`template`/`style` inside foreign content, comment and CDATA edge cases). Run in Chromium. |
| T-10 | §6.2 selector gate | the byte matrix; each pseudo-class allowed and refused; leading `+`/`~` refused; emitted-form pins proving every subject is inside the band (a sibling band's computed style is unchanged) |
| T-11 | §6.4 `content` | the five admitted forms paint a pseudo-element box; `attr()` and text strings are refused |
| T-12 | §8 sinks | prompt-regression cases (ai-ready harness): content carrying `\p{Cf}` and instruction-shaped text reaches the model framed and neutralized. A preview isolation pin: the preview document's origin is opaque. |
| T-13 | §7 islands | name gate; empty-island and unknown-island rules; a patch to `islands.<name>` diffs as one field; CAS and undo per island write |
| T-14 | §2.6 / M-2 | a stored band with a now-refused construct does not block an edit to another band |
| T-15 | §5.1 rank | a content `style` beats a role value on its own element (computed style); `content_inline_style` states the count and properties |
| T-16 | AI surface derived | the exclusion list in the prompt is built from the predicate's own tables (I43), as LAYER-2 §7′ requires for `_css` |
| T-17 | performance | the predicate on a maximal RICH prop (the M-8 bound) and a maximal custom band stays within a stated budget per render. The render path is the hottest in the theme, so the cost is measured and not assumed. Two costs are named in advance: the §2.1 re-parse roughly doubles the per-prop work, and `:has()` in a scoped rule is the one selector whose **browser** cost grows with the band's size. If the budget fails, the first lever is a render cache keyed on the content bytes' hash plus the predicate's table version, so an unchanged prop is sanitized once. |

---

## 11 — What already exists, what is out of scope, and the failure modes

**Reused, not rebuilt:**

- core `wp_kses()` as the parser and base (§2.4 governs how);
- `_pp_forbidden_css_construct()` and `_pp_udc_delimiters_balanced()` (Δ3, §6.2);
- `_pp_svg_content_is_safe()` (`lib/wp.php:3398`), the existing SVG sanitizer for `data:image/svg` sources. Its adversarial-review precedent (`xml:base` rejection, `animateColor`, a `url(` scan after `_pp_css_unescape`) is carried into Δ1/E5, or the difference is argued;
- LAYER-2's property charset and exclusion set;
- the `_css` map validator (§6.3);
- `wp_allowed_protocols()` (E2);
- the findings channel and the drop ledger's facts-only message discipline;
- `_pp_clean_reflected_text` for every message that quotes content;
- the band-identity promote step (§5.3's twin).

**Out of scope, named so that nobody reads this contract as covering it:**

- script of any kind, including engine-provided behaviours;
- author-defined `@keyframes` (§6.5);
- engine-built third-party embeds (**P-6**);
- an accordion-editor UI for islands (BUILD-SPEC §7: post-2.0.0);
- changes to chrome text contracts;
- sanitizing shortcode output (**P-8**).

**The AI surface, when this ships** (the #1059 lesson: only `lib/ai-context.php` reaches the
model). One paragraph, derived from the predicate's tables, in this order:

1. structured first;
2. content markup freely, within the exclusions;
3. the scoped sheet for what roles cannot reach;
4. theme classes are borrowable but are not an API;
5. you still own contrast.

The `ai-instructions/composition.md` text-content table is regenerated from the same tables.

**Failure modes, one per new codepath:**

| codepath | realistic failure | what catches it |
|---|---|---|
| write predicate | an admitted construct refused because normalisation was mistaken for a loss | T-2 over the full admission table |
| render predicate | a throw inside kses caught by a new wrapper, leaving `pre_kses` unhooked | §2.4 rule; a pin that the filter is still hooked after a hostile render |
| Δ3 gate | a quoted value split mid-entity (the §1.3 core behaviour, re-created) | T-7 entity-split case |
| SVG | an animation element rewriting a sanitized `href` | T-8 and T-9 |
| scoped sheet | a sibling combinator painting the next band | T-10 sibling-band computed style |
| islands | an island edit overwriting a concurrent structural edit | CAS per write (T-13) |
| E6 | a legitimate author `id` refused for colliding with a minted band id | message names the collision and the band; the id grammar is narrow (`pp-` + 8 hex) |

---

## 12 — DECISION LIST

### Owner-posture questions (routed to the owner, one at a time; never self-ruled)

**P-1. The frame.** Is Layer 3 a standing content-freedom guarantee: broad by default, under
the named exclusions of §4, never gated on demand? That reading supersedes the design doc's
*"gated on Layer-2 escape telemetry"*.

- *Recommendation:* **yes**. It is R0's frame applied to content. Without it, Layer 3
  re-creates on content the ceiling Layer 2 was ruled to prevent on styling.

**P-2. Breadth per prop class.** Do INLINE props (`cta.body`, `grid.items[].text`,
`testimonials.items[].quote`) and PLAIN props keep their narrow contracts?

- A: keep all three contracts as they are. The ceiling is lifted by RICH, 3B and 3C.
- B: widen INLINE to admit `span` with `class`/`style`, for accent runs.
- C: collapse INLINE into RICH.
- *Recommendation:* **A**, with B as the one widening worth taking if the owner has used
  accent runs in card lines.

**P-3. Scoped-sheet reach.** Is `udc._scoped` accepted on **every** band, or only on the
custom band?

- *Recommendation:* **every band.** The measured escape pressure is selector-shaped and sits
  in ordinary bands (§1.6). A custom-band-only sheet would push content **out** of structured
  components just to get a selector, which is the design doc's own objection to its
  Approach B.

**P-4. External media in content.** Where may `<img>`, `<video>`, `<audio>` and `<source>`
load from?

- A: any `http(s)` host, as today, with a `content_external_resource` disclosure.
- B: this install only.
- C: attachment ids only (the ruling-A2 pattern).
- `url()` in style attributes stays refused under every option (E7, §6.7).
- *Recommendation:* **A**. Hotlinked media is ordinary web content, and B or C would break
  content that renders today. The disclosure keeps it visible.

**P-5. Forms.** Does content admit `form` and its controls (Δ5), with `action` gated by E2 and
no `formaction` family?

- *Recommendation:* **admit.** Refusing them sends forms to plugin shortcodes, the one path
  the theme cannot inspect (§7.5). A reviewed `action` is safer than an unreviewed plugin.

**P-6. Engine-built embeds.** Author-written iframes stay hard-excluded (E3). Should the
**engine** build iframes, for a named provider allowlist (video, maps), from a URL the author
writes? That is the ruling-A2 pattern: the author supplies data, and the engine builds the
dangerous construct with `sandbox` and `allow` set by the engine.

- *Recommendation:* **yes in principle, post-2.0.0,** as its own contract. The spec's *"no
  iframes"* is read in this draft as *"no author-written iframes"*, and this question exists
  so that reading is the owner's, not mine.

**P-7. Who edits structure.** In the custom band, is `markup` editable only through structural
writes (the AI and the JSON editor), with islands as the human-editable surface?

- *Recommendation:* **yes.**

**P-8. Plugin output.** Does `embed`'s shortcode output stay the plugin trust boundary
(unsanitized, disclosed, preview-isolated), or pass the Layer-3 predicate?

- *Recommendation:* **stay the plugin boundary, with the four obligations of §7.5.**
  Sanitizing it breaks most interactive plugins, and the owner is the one who installs them.

### Mechanics and security questions (the orchestrator rules)

| # | question | recommendation |
|---|---|---|
| M-1 | Where the predicate runs, and how it verifies itself | write (refuse) **and** render (re-sanitize + `content_stripped_at_render`), one function (§2); its last step re-parses the output with `WP_HTML_Processor` and re-checks §4 on that token stream (§2.1) |
| M-2 | Which stored bands a write validates for content (#1007 class) | refuse losses only in bands the write changes; stored bands report via the §2.3 finding |
| M-3 | Style-attribute value gate | LAYER-2 security gates, **untyped**. Refuse an uppercase property (I34), naming the lowercase form. |
| M-4 | Base allowlist ownership | a PP-owned table derived from core `post` on the pinned WP version, with a drift pin (T-1) |
| M-5 | Scoped-sheet emission form | attribute-prefix emission (§6.2), which is universally supported; `@scope` as a later internal swap with byte-identical rendering |
| M-6 | CSS parsing | in-house bounded tokenizer; no parser dependency (§6.6) |
| M-7 | `content` in scoped rules | only `""`, `none`, `normal`, `counter()`, `counters()` with a separator of at most 8 non-alphanumeric bytes (§6.4) |
| M-8 | Byte bounds | 64 KiB per RICH/INLINE prop; 128 KiB for `custom.markup`; 16 KiB per island; 64 islands; 128 scoped rules per band. Measured against the owner's largest stored band before ratifying the numbers. |
| M-9 | Finding codes and shapes | `content_construct_excluded` (refusal), `content_stripped_at_render`, `content_inline_style`, `content_external_resource` (if P-4 = A), `content_plugin_output`, `custom_band_unverified`, `custom_island_empty`, `custom_island_unknown`. All facts-only. |
| M-10 | `rel="noopener"` on `target="_blank"` | add it, disclosed as normalisation (Δ4) |
| M-11 | Presence probe vs borrowed role classes | scope the probe to template-rendered elements (exclude content-container descendants) rather than disclose ambiguity |
| M-12 | The custom component's name | `custom` |
| M-13 | T4's access to stored prod content for §9.2 | a read-only export authorized by the orchestrator; T4 never writes prod |
| M-14 | Close §1.4's engine-namespace forging early, before 2.0.0 (strip `data-pp-*` and reserved ids at the five RICH sinks) | the orchestrator's scheduling call; it is independent of the rest of Layer 3 and small |

---

## 13 — Review trail

*(Filled by the review passes that ran on this draft: /plan-eng-review, then the
contract-boundary adversarial passes, run in series. Each pass is recorded with what it
changed.)*
