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
>
> Implementation is post-2.0.0. It is **additive in admission, and narrower than today in named
> places**: Δ3 refuses `url()`, `!important` and custom properties that a content `style`
> attribute passes today; E3 refuses the same-install PDF `<object>` (P-11); E10 refuses stray
> closers; and "unsupported markup" is refused (P-16). And where kses silently drops one
> attribute today, §2.2 refuses the write instead (P-18). The one exception to post-2.0.0 is §9: if the
> brand-site reconstruction (Sprint 3 T4) authors content that the current sanitizer
> refuses, that specific construct re-enters this sprint as a ruled fix, built to the
> clause of this contract that covers it.
>
> **Open decisions are in §12.** They come in two kinds. Owner-posture questions (P-1 to P-26)
> decide how broad content freedom is. Mechanics questions (M-1 to M-21) decide how the
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
- **Not a script channel.** No authored byte is ever executed **by the browser or the theme**:
  no event attributes, no script, no `javascript:` URLs, no `data-wp-*` directives (core's own
  Interactivity API gadget, E1). No iframe is ever written by an author. These stay hard under
  every answer to §12. What this contract cannot promise is what an **installed plugin's**
  script does with attributes or classes an author writes (htmx `data-hx-*`, framework
  `data-*` hooks, a plugin's `customElements.define`). That is the plugin boundary, stated in
  §7.5 and put to the owner as **P-9**.
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
  (`safecss_filter_attr`), `probe-05` (the public brand-site pages' `style`-attribute parse and
  property census), `probe-06` (shortcodes), `probe-07` (the §9 detector's self-test; `07b`
  and `07c` after the review passes), `probe-08` (`WP_HTML_Processor` on a foreign-content
  breakout) and `probe-09` (the `url(`/`!important` census).

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

**The editor preview is not isolated today (measured, static).** The preview frame is
`sandbox="allow-same-origin allow-scripts"` (`lib/admin.php:5730`), filled through `srcdoc` and
later through `body.innerHTML` (`assets/js/pp-admin-editor.js:190-196`). That pairing gives the
frame the admin origin. So shortcode output rendered in the preview runs with the admin's
session **now**. §8.3 states what Layer 3 requires instead.

### 1.6 The pressure on record (CONTEXT — not a gate)

This is recorded because it shapes 3B, and for no other reason:

- **The descendant-of-rich-text defects:** #1049, #1068, #1069 (a list or link inside authored
  rich text that no role reaches).
- **The borrowed-button cross-effect:** #1071.
- **The four capability deletions on `::before`/`::after` among the 223 retired v1 slots:** the
  pseudo-element dimension of LAYER-2 §0.5f (a pre-ruling section that ruling R0 keeps as
  context). `components/testimonials/README.md:141` records
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
pp_content_sanitize(string $bytes, Sink $sink, CompositionContext $ctx): array{html: string, losses: list<Loss>}
```

`Sink` names the contract **and** the step-5 wrapper chain (`.section__content`, the
`table > tbody > tr > td` chain, an island's host, the band root), because "rich" alone does
not say where the prop will be parsed. `CompositionContext` carries what the cross-band rules
need (the page's anchor set, for E6). Any cache (T-17) keys on the bytes' hash **plus** the sink
identity, a hash of the anchor set, and the predicate's table version, so a result verified in
one context is never served in another; or it caches only the per-prop steps 1-4.

- **One function in `lib/`** owns every content contract (`rich`, `inline`, `plain`, `custom`).
  No template calls `wp_kses_post()` or `pp_kses_inline()` directly any more. The templates
  call this. The "one shared engine" architecture rule (Section 1 of the pipeline, *"never add
  a surface-specific second validator"*) applies to content exactly as it does to styling.
- **The pipeline, in the order it can actually run.** kses owns the element and attribute
  allowlist and cannot be run "later" on something it already removed. Measured: core kses
  deletes an `<svg>` whole, and rewrites every `style` attribute through
  `safecss_filter_attr()` inside `wp_kses_attr_check()` before returning. So a pass placed
  **after** kses never sees an SVG element or an un-filtered style value. The predicate
  therefore runs five steps, in this order:
  1. **Guard.** The #730 guard: a non-string is refused before anything else runs.
  2. **Lift styles (pre-kses, pure).** A `WP_HTML_Tag_Processor` pass reads every element's
     `style` attribute. `get_attribute()` returns it entity-decoded, which is what removes the
     §1.3 entity-split mangling. The pass runs Δ3 on the decoded value, records any `Loss`,
     and replaces the attribute with an internal marker `data-pp-style-slot="<n>"`, keeping
     the admitted value in a side table. The same pass removes a refused `script`/`style`
     element **with its body** (E4), which kses alone would leave behind as visible text.
     **This pass cannot see everything:** `WP_HTML_Tag_Processor` reads the contents of
     raw-text and RCDATA elements (`title`, `textarea`, `xmp`, `noembed`, …) as text, while kses
     and the browser can read them as markup (SVG `title` hands its children back to HTML
     parsing). So step 2 is never the only enforcement point for anything: step 4 re-checks,
     and step 5 checks what the browser will build.
  3. **kses with the PP-owned table.** `wp_kses($bytes, <M-4 table>)`. The table is the base
     (§3.1) plus the Δ1/Δ2 (and, if P-5 admits them, Δ5) elements and attributes, minus every
     §4 exclusion. It does **not** list `style`; it lists `data-pp-style-slot` on every
     element. kses therefore never sees a style value, and core's style filter never runs on
     PP content. **kses tables are keyed by tag name, with no namespace,** so step 3 cannot
     express "HTML `title` refused, SVG `title` admitted" or "Δ1 elements only inside
     `<svg>`". Those are named namespace checks in step 5.
  4. **Post-kses value gates (pure; remove-only except two named additions: the Δ4 `rel`
     token and the restore of step 2's admitted styles).** A second `WP_HTML_Tag_Processor` pass
     applies the value gates kses has no concept of:
     - Δ1's SVG attribute values;
     - **E2 for every URL attribute kses does not protocol-check.** kses checks only
       `wp_kses_uri_attributes()` (`action`, `archive`, `background`, `cite`, `classid`,
       `codebase`, `data`, `formaction`, `href`, `icon`, `longdesc`, `manifest`, `poster`,
       `profile`, `src`, `usemap`, `xmlns`). Step 4 owns `srcset` (every candidate, split by the
       HTML spec's algorithm) and `xlink:href`, and any URL attribute a later delta adds;
     - **E6 in full:** kses's `data-*` wildcard admits `data-pp-*`, so the namespace rule is
       enforced here, not only in step 2; plus the reserved ids;
     - Δ4's `rel` addition.

     It then restores each `data-pp-style-slot` to a `style` attribute carrying the admitted
     value from step 2 (`set_attribute()` re-encodes it). **Markers are matched one-to-one
     against step 2's side table:** a marker whose index step 2 did not issue, or one that
     appears twice, is a `Loss`. That closes the channel where a forged marker reaches this
     step inside markup step 2 read as text. **This pass may only remove and report; it never
     re-admits a construct kses removed.**
  5. **Verify** (next bullet).
- **Verify after sanitizing, with a spec-conformant parser.** kses is not an HTML5 parser, and
  a browser can re-parse its output differently. So the predicate's last step re-parses the
  sanitized output with core's `WP_HTML_Processor` (present in WordPress 7.0; it follows the
  HTML5 tree-building rules) and re-runs the §4 exclusion check over **that** token stream. A
  hit there is a `Loss` like any other. Measured: `WP_HTML_Processor` sees an `<img>` inside
  `<svg><style>` escape into HTML content, exactly as a browser does (probe-08). That is the
  mutation-XSS shape T-9 exists for, and the reason the check reads the re-parse, not kses's
  view of its own output.
  **The verification parses the prop in the page it will live in, not as a fragment.**
  `WP_HTML_Processor::create_fragment()` accepts only the `<body>` context in WordPress 7.0,
  and it silently drops stray closers (measured: `x</div></section><p>o</p>` gives
  `#text P #text /P`). So step 5 uses `WP_HTML_Processor::create_full_parser()` over a
  **per-sink wrapper**:

  ```
  <!DOCTYPE html><html><body><main>
    <section data-pp-band="W">{the template's open tags down to the sink container}
      {the sanitized prop}
    {the template's close tags}</section>
    <p id="pp-sentinel">s</p>
  </main></body></html>
  ```

  The open-tag chain is the sink's real one: `.section__content` for `section.body`, the
  `table > tbody > tr > td` chain for a table cell, the island's host element for an island,
  the band root for custom markup. Over that parse, step 5 checks:
  - **containment (E10):** every node the prop produced sits under the sink container; the
    sentinel is a sibling of the band, intact, with its text; and no formatting element the
    prop opened is still on the stack of active formatting elements when the container closes;
  - the §4 exclusions on the token stream the browser would build, including the namespace
    checks step 3 cannot make.

  **A composed custom band (markup with its islands rendered in) is verified once more, as a
  whole, the same way.** When `WP_HTML_Processor` bails (`get_last_error()` non-null), that is
  a `Loss` with the clause **"unsupported markup"**, and the predicate fails closed: the author
  gets a named refusal, never a silent one. Misnested ordinary HTML (`<b><p>x</b>y</p>`) lands
  here today. P-16 asks whether that breadth cost is acceptable.
- **A `Loss` is a fact, not advice:** `{construct, where, clause}`.
  - `construct` is the element, attribute or declaration that did not survive.
  - `where` is its position (the element path within the prop).
  - `clause` is the §4 exclusion or §3 gate that removed it.

### 2.2 Write: refuse, never coerce

`pp_validate_composition*` calls the predicate on every content prop of every band it
validates. **A write whose content produces any `Loss` is refused.**

- **The code** is `content_construct_excluded`. The message names the prop, the construct, and
  the clause, the way LAYER-2's `_css` refusals name the §6.0 exclusion they hit (LAYER-2 §2′ and
  §6.0, its current text).
- **Accepted bytes are stored exactly as authored.** No sanitized copy is stored, because
  I34's reject-never-coerce means a write either lands as written or does not land.

**Normalisation is not a loss.** Normalisation means entity spelling, attribute quote style,
attribute order, URL scheme case (kses lowercases `HTTPS:`), end tags the parser implies for
elements the prop itself opened **provided the containment check of §2.1 step 5 passes**, and
whitespace inside tags. **Anything that fails containment is NOT normalisation:** a stray
closer, an unclosed `textarea`, an unclosed `<a>`/`<b>` the browser would reconstruct in the
next band, a start tag that closes the host. That is E10. The predicate compares
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
In the §2.1 order that means:

- **no `try`/`catch` encloses step 3** (the `wp_kses()` call), at any depth of the call stack;
- steps 2 and 4 are pure `WP_HTML_Tag_Processor` passes over a string the guard already
  proved is a string. They cannot unhook `pre_kses`, because they never enter kses. A failure
  in either is refused as a `Loss` by returning, never by throwing through step 3;
- the measurement mirror at `lib/udc.php:7084-7090` (re-hook after a caught render) stays, and
  gains a pin that the filter is still hooked after a hostile render of every §4 row.

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

- **`title` and `desc` are text-only.** An SVG `title` or `desc` with any element child is a
  `Loss`: SVG `title` hands its children back to HTML parsing, which is how a forged marker
  or an unchecked `srcset` hid from step 2 (§2.1).
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
  - reference-bearing presentation attributes: `clip-path`, `mask`, `filter`,
    `marker-start`, `marker-mid`, `marker-end` (they carry fragment references, gated below);
  - document attributes exported icons carry: `xmlns` (value exactly
    `http://www.w3.org/2000/svg`), `xmlns:xlink` (exactly `http://www.w3.org/1999/xlink`),
    `version`, `focusable`, `xml:space` (`default` or `preserve`). Fixed values, because
    kses treats `xmlns` as a URI attribute;
  - plus the base's global attributes.
- **Gate — SVG presentation ATTRIBUTES are Δ1's own gate, not Δ3's.** (A `style` attribute on
  an SVG element is still Δ3.)
  - **Reference-bearing attributes** (`fill`, `stroke`, `clip-path`, `mask`, `filter`,
    `marker-start`, `marker-mid`, `marker-end`) accept a **same-document fragment reference**,
    as `^url\(\s*(["']?)#[A-Za-z_][A-Za-z0-9_.-]{0,63}\1\s*\)\z` (quoted and unquoted, and
    ids that begin with `_`, which design tools export). This branch **bypasses**
    `_pp_forbidden_css_construct()`, which refuses every `url(` (measured:
    `_pp_forbidden_css_construct('url(#g)')` refuses it as an external resource). A fragment
    names nothing outside the document, so §6.7's premise holds. Any non-fragment `url(` is a
    `Loss`. Otherwise the attribute takes its literal under the rule below.
  - **Every other admitted attribute value** passes: no control characters; no `<`, `>`,
    `{`, `}`, `;`, `\`; no comment delimiters; the text `url(`, `expression(` and `javascript:`
    refused (ASCII case-insensitive, after entity decoding); at most 4 096 bytes (open: **M-15** recommends dropping this cap) (a path's `d`
    is long).
  - **Precedent carried over from `_pp_svg_content_is_safe()`** (`lib/wp.php:3398`): `xml:base`
    is refused, `animateColor` joins E5, and the `url(` scan runs after CSS unescaping.
  - `use` and `a` take `href` (and the legacy `xlink:href`):
  - on `use`: `^#[A-Za-z][A-Za-z0-9_-]{0,63}\z`, a same-document fragment only;
  - on `a`: the E2 URL gate.
- **Reason:** icons and diagrams are ordinary web content. The static subset executes
  nothing. Everything that can execute or fetch in SVG is named in §4 (E1, E2, E5), and markup that escapes its container in E10.
- **Parse note:** browsers repair SVG attribute case in foreign content, so `viewbox` becomes
  `viewBox`. The predicate compares attribute names case-insensitively and emits the
  canonical case.
- **Fragment ids are document-wide.** `url(#g)` or `<use href="#icon">` in one band resolves
  against every id on the page, and the first `id="g"` in document order wins. Two bands that
  both author `id="g"` collide silently. The contract does not rewrite author ids (that would
  be the I36 aliasing shape). The AI surface teaches band-prefixed ids, and a finding
  (`content_duplicate_id`) names any id that appears in more than one band.

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

- **Replaces** `safecss_filter_attr()` for PP content sinks, by the §2.1 step-2 lift: core's
  filter never sees the value.
- **Order of operations:** decode HTML entities first (`get_attribute()` does); replace every
  tab, CR and LF with a space; split into declarations on `;` **outside** quotes and
  parentheses; drop empty declarations; trim ASCII whitespace around each property and value.
- **Text-bearing string values are refused** in every Layer-3 CSS channel (the `style`
  attribute here, and the scoped sheet): a quoted string as the value of `quotes`,
  `list-style-type`, `list-style`, `text-emphasis-style`, `text-emphasis`,
  `hyphenate-character` or `text-overflow`. Each of these renders its string as text on the
  page, which is exactly why `content` is excluded; `_pp_forbidden_css_construct` does not
  catch them (measured: `'"BUY NOW" ""'` passes). The keyword forms stay admitted. The same gap
  in shipped Layer 2 (`_css`) is #1168, filed in hardening language and not changed here.
- **Refused as written, and named so an author is not surprised:** a CSS escape (any
  backslash, e.g. `"\201C"`); an apostrophe inside a double-quoted value (`"O'Reilly Sans"`),
  which the balance pre-check counts as an unpaired quote.
- **Each declaration** is `<property>:<value>`:
  - the **property** passes LAYER-2 §2′.1's charset `^-?[a-z][a-z0-9-]{0,63}\z`, and must not
    be a LAYER-2 §6.0 exclusion (custom properties (open: **P-20**), `all`, `content`, `behavior`,
    `-moz-binding`, `-pp-background-overlay`);
  - the **value** passes the same **security** gates `_css` values pass:
    `_pp_forbidden_css_construct()` (no `url()`, `image-set()`, `image()`, `src()`,
    `expression()`, `@import`, `{ } ; < >`, backslash, control characters, comment
    delimiters), `_pp_udc_delimiters_balanced()`, and no `!important`.
- **Typed grammars do not run here** (**M-3**). Browsers already drop an invalid declaration in
  an attribute harmlessly. A typed grammar would re-open the #1007 class through content, the
  first time a group claimed a new property (§5.2).
- **Effect against today:** admits every property in §1.3's first row, the modern colour
  functions (measured through the gates: `rgb(0 0 0 / .5)`, `%`, commas) and double-quoted
  strings (`"Inter", sans-serif`; `"a b" "c d"`), which removes the entity-split mangling. Refuses `url()`,
  `!important` and custom properties, where the content channel is broader than the styling
  channel today.
- **Context, not the argument for the refusal:** none of the three refusals appears in the owner's measured content: `url(` and `!important` occur in 0 of the public pages' `style` attributes (probe-09), and custom properties occur only in engine-emitted v1 slot styles, never inside a content container (probe-05). The
  refusals exist for the styling channel's reasons: ruling A2, LAYER-2 §6.14, and §6.0.
- **Reason:** one CSS value gate across the program. §5.2 states the interaction.

**Δ4 — a link that opens another browsing context carries `rel="noopener"`.**

- **Rule:** on `a`, `area`, SVG `a` and (if P-5 admits it) `form`, the predicate adds
  `noopener` to `rel` whenever `target` is anything other than `_self`, `_parent` or `_top`
  and `rel` lacks it. Browsers imply `noopener` only for `_blank`; a named target
  (`target="x"`) keeps `window.opener`. The author's other `rel` tokens are kept.
- **This is the one place the predicate adds bytes the author did not write** (step 4's style
  restore puts back the author's own admitted value). It is disclosed as normalisation, not as
  a loss (**M-10**).

**Δ5 — forms, if P-5 admits them.**

- `form` (`action`, `method` ∈ `get|post`, `name`, `autocomplete`, `novalidate`).
- `input` with `type` one of `text`, `email`, `tel`, `url`, `number`, `search`, `password`,
  `date`, `time`, `datetime-local`, `month`, `week`, `color`, `range`, `checkbox`, `radio`,
  `hidden`, `submit`, `reset`, `button`. `file` is excluded in this draft (P-24 asks whether
  that stays).
- `select`, `option`, `optgroup`, `label`, `fieldset`, `legend`, `output`.
- On `input` and `textarea`: `placeholder`, `required`, `pattern`, `min`, `max`, `step`,
  `minlength`, `maxlength`, `autocomplete`, `name`, `value`.
- The base's `textarea` and `button`.
- **Gate:** `action` passes E2. `formaction`, `formtarget`, `formmethod` and `formenctype` are
  refused (E2/E9), so the destination is always the form's own reviewed `action`.
- **Reason:** see P-5. This row is **inert until the owner rules**.

### 3.3 INLINE and PLAIN

INLINE and PLAIN keep their contracts in this draft. Their narrowness is the **meaning** of
the prop. A card's supporting line is inline copy, and a label is text. The ceiling they
imply is lifted **elsewhere**: RICH props, the scoped sheet, and the custom band carry
everything they do not.

**P-2** asks the owner whether INLINE widens (the candidate is `span` with `class` and `style`,
plus `sup`, `sub`, `small`, `mark`, `code`) and whether titles, headings and chrome text stay
PLAIN. The recommendation widens INLINE and admits the same inline set in titles and headings.

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
| **E1** | **Event-handler attributes:** any attribute whose name matches `^on` (ASCII case-insensitive), on every element and namespace. That includes SVG/MathML handlers such as `onbegin`, `onload`, `onfocusin`. **And core's own directive gadget:** any attribute named `data-wp-*` (the Interactivity API binds behaviour to them; they pass `wp_kses_post` unchanged, measured). | script execution. Owner-named. `data-wp-*` is the same outcome through a core script that is on the page whenever a block or plugin enqueues it. Other plugins' attribute gadgets are the §0.3/§7.5 boundary and **P-9**. | a name matrix: mixed case, every `on*` in the HTML, SVG and MathML specs, `on` followed by non-letters; each refused at write, absent from the **browser-parsed** render |
| **E2** | **Script-bearing and off-list URLs** in every URL-valued attribute: `href`, `src`, `srcset` (each candidate), `poster`, `cite`, `action`, `data`, `xlink:href`, `background`, `longdesc`, `usemap`. The scheme, after entity decoding and stripping of C0/whitespace (the URL parser's own preprocessing), must be in `wp_allowed_protocols()`, or the URL must be relative or `#fragment`. `javascript:`, `vbscript:` and `data:` are refused. Unlike today (§1.3), the refusal happens **at write**; nothing is rewritten into a relative URL. | script execution through navigation. Owner-named (*"no JS URLs"*). | an obfuscation matrix: entity-encoded, tab/newline-split, mixed-case and leading-space schemes, protocol-relative URLs, each `srcset` candidate independently; plus the rewrite-to-relative behaviour pinned **absent** |
| **E3** | **Nested browsing contexts written by the author:** `iframe`, `frame`, `frameset`, `object`, `embed`, `applet`, `portal`, `fencedframe`; and the `srcdoc` attribute anywhere. | third-party execution context, clickjacking, and content no gate can inspect. Owner-named (*"no iframes"*). An **engine-built** embed is a separate question (**P-6**). | each element refused; `srcdoc` refused on every element; `<object data>` pinned refused even with a same-install URL |
| **E4** | **Script, style and document-level elements:** `script`, `style`, `noscript`, `template`, `base`, `meta`, `link`, `title`, `html`, `head`, `body`, `slot`, and the legacy raw-text elements `xmp`, `noembed`, `noframes`, `plaintext`, `listing`. | executes (`script`); unscoped page-global CSS that bypasses 3B's scoping (`style`); parse differentials that feed mutation-XSS (`noscript`, `template`, and the raw-text elements, whose contents one parser reads as text and another as markup); document state (`base`, `meta` refresh, `link`, the HTML `title`, which sets the page title); an in-body `html`/`body` start tag merges its attributes onto the page's real element; `slot` is inert without shadow DOM, the I19 shape. **CSS belongs in the scoped sheet (3B), which is scoped and gated.** | each refused at write; at render the **content** of a refused `script`/`style` is removed with it, never left as visible text (the §1.3 leak pinned closed) |
| **E5** | **SVG and MathML active and cross-namespace content:** `animate`, `animateColor`, `animateMotion`, `animateTransform`, `set`, `discard`, MathML `annotation-xml`, `mglyph`, `malignmark`, `maction`, `foreignObject`, `image`, `feImage`, `script`, `style` (inside SVG), and any `href`/`xlink:href` on SVG elements other than Δ1's `use` and `a` rules. | `animate`/`set` can rewrite an attribute such as `href` to a script URL after sanitization. `foreignObject` re-enters the HTML namespace (a mutation-XSS source). `image`/`feImage` fetch. | the classic animation-rewrites-`href` shapes; a `foreignObject` round trip; parse-serialize-reparse idempotence (T-9) |
| **E6** | **The engine-owned namespace:** any attribute named `data-pp-*`, with **one carve-out**: `data-pp-island` and `data-pp-island-kind` are admitted **only** in `custom.markup` (§7.2), and refused everywhere else, including inside island content; any `id` of the form the engine mints (`^pp-[0-9a-f]{8}\z`, the band-id and anchor mint, `lib/wp.php:6567`; `^it-[0-9a-f]{8}\z`, the item id); the page-reserved ids `main` and `pp-nav-menu`; and any `id` equal to a band anchor (`props.id`) in the same composition. Band ids themselves are emitted only as `data-pp-band` (so the `data-pp-*` rule covers them); the id reservation protects the **anchors**. When a write adds a `props.id` that equals an `id` already inside another band's stored content, **the write that adds the anchor is the one refused**, naming the band that holds the collision (the #1007 rule of §2.6 applied: the refusal lands on the band being changed). `add_component` validates only the new item today (`lib/actions.php:5266`), so it must run the cross-band E6 checks against the stored page with the new item merged in, as `pp_validate_composition_band` does for cross-item rules; T-6 carries an `add_component` row. | forging the engine's identity (§1.4, measured). The content-side twin of the promote step's props rule (§5.3). | one row per marker in §1.4's table, asserting the forged scope does **not** paint (computed style from the rendered page, not the stored map) |
| **E7** | **Style-attribute exclusions** (Δ3): LAYER-2 §6.0's set; any value the security gates refuse, including `url()` of every kind (A2); and `!important`. | the same reasons LAYER-2 gives, with one addition: **the no-external-resource rule is load-bearing for 3B's attribute selectors** (§6.7). | the LAYER-2 §6.0 matrix, re-run through a `style` attribute; the `image-set()` bare-string case that LAYER-2 §6.0 records as once missed |
| **E8** | **Custom elements and unknown elements** (any tag not in §3.1 + §3.2). | without script a custom element is an inert span that validates green and paints nothing special: the I19 shape. And where a page script **does** define it (a plugin's `customElements.define`), it becomes a script-bearing element the theme never reviewed. Listed so that freedom-first does not read as "anything with angle brackets". | an unknown-tag matrix refused, with a message naming the element |
| **E9** | **Submission and navigation redirectors:** `formaction`, `formtarget`, `formmethod`, `formenctype`, `ping`, `http-equiv`, and the `form` attribute (`form="<id>"`, which enrolls a control in any form on the page: theme, plugin or comments). | they move where a click or submit goes, or where a request is sent, outside the reviewed `action`/`href`. | each refused on every element |
| **E10** | **Markup that escapes its container**, defined by **containment** (§2.1 step 5), not by a list of tags: the prop fails E10 when, parsed inside its real template wrapper, any node it produced lands outside the sink container, the next band's sentinel is not intact, or a formatting element it opened is still active when the container closes. Known shapes: a stray `</div>`/`</section>`/`</p>`; `</td>`, `</tr>`, `</table>` in a cell; an unclosed `<textarea>` (swallows the next band); an unclosed `<a>`/`<b>` (rebuilt inside the next band); a start tag that closes the host (`<td>` in a cell, `<li>` in an `li` host, `<a>` in an `a` host). | in the page the browser uses the stray closer to close the **template's** container, and in a table cell the table itself, so everything after it renders outside the band: outside `data-pp-band` scoping, the scoped sheet and every finding. A fragment parse ignores the closer, which is why the predicate must parse in context. Measured: `wp_kses_post('a</div></section><p>outside</p>')` passes the closers verbatim, and none of the five RICH sinks balances tags. Refused, never balanced: rendering a re-balanced tree would make the stored bytes stop being what renders (I36), and I34 is reject-never-coerce. | the known-shape matrix above, per sink wrapper (RICH, `<td>`, each island host kind); the assertion is **the next band's sentinel is intact, outside this band, with its own text and no inherited formatting**, read from the rendered page in Chromium. A "band contains every content node" check alone passes vacuously for a swallowed or re-wrapped next band. |

**Precedence:** where the base (§3.1) or a delta row admits something a row here excludes,
**the exclusion wins.** Core's `post` context admits `object` and `title`, and E3/E4 exclude
them; the M-4 table is the base **minus** §4. E4's `title` is the HTML `title` element; SVG's
`title` (Δ1) is a different element in a different namespace and stays admitted. The base's
`textarea` and `button` stay admitted whatever P-5 rules; P-5 decides only the Δ5 additions.

**What is NOT on this list, deliberately:**

- `position: fixed` and the rest of the escape-the-box family, in `style` attributes. These are
  admitted, as LAYER-2 R2′ admits them in `_css`. The author owns the outcome, and a finding
  says so (§5.1).
- **The top layer.** Core already admits `popover` and `button[popovertarget]` (probe-00), which
  open a top-layer box with no script, and §6.2 admits `::backdrop`, which paints the whole
  viewport. Admitted and disclosed like `position: fixed`: the author owns the outcome, and
  the content finding (§5.1) names the popover.
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
  per-declaration warning. It also names any `popover` element the content carries, the
  top-layer admission of §4. **M-9** fixes the exact shape.

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
  cannot forge it. **Known limit:** both measurement paths parse with libxml's HTML4
  `DOMDocument` (`lib/udc.php:7111`, `lib/post-apply-validate.php:143`), whose tree differs from
  the HTML5 tree step 5 and the browser build (no adoption agency, no foreign-content rules,
  different foster parenting). The implementation either moves M-11's scoping onto
  `WP_HTML_Processor` or discloses the divergence; and the post-apply media check extends to
  `srcset` candidates and `<source>` when Δ2 lands.

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
entries, on commas outside quotes, brackets and parentheses. **Each entry is trimmed of ASCII
whitespace before any rule runs**, and each is emitted as **its own rule**, so one refused or
invalid entry can never take the others down with it. Each entry must satisfy every rule
below.

1. **Charset.** Outside quoted attribute values, every byte is in
   `[A-Za-z0-9 _\-.#\[\]()>+~:=^$*|"',]`. Inside a quoted attribute value, every printable
   ASCII byte except `\`, `"` (in a double-quoted value), `'` (in a single-quoted value), `<`,
   `{`, `}` and `;` is admitted, so `[href*="/pricing"]` and `[href*="%20"]` work. Nothing
   outside those sets reaches the sheet: no control characters or `\` anywhere, and no `@` or `&`
   outside quoted attribute values (inside one, `[title="@x"]` and `[href*="a&b"]` are admitted). It is
   an allowlist of bytes, as in LAYER-2 §2′.1.
2. **Quotes and brackets.** Quotes appear only inside an attribute selector's value, balanced
   by a string-aware walk that ends a string only at its own opening quote character (so
   `[title="it's"]` is admitted). `[`/`]` and `(`/`)` are balanced.
3. **Operator bytes.** `^`, `$`, `*` (as an operator), `~` (as `~=`), `|` and `=` appear only
   inside `[…]` as one of the operators `=`, `~=`, `|=`, `^=`, `$=`, `*=`. Outside brackets `*`
   is the universal selector and `~` a combinator. `||` and namespace prefixes are refused, so
   `a$b` is refused rather than admitted to paint nothing.
4. **Pseudo-classes.** Only these are admitted:
   - `:hover`, `:focus`, `:focus-visible`, `:focus-within`, `:active`, `:link`, `:visited`,
     `:target`;
   - `:first-child`, `:last-child`, `:only-child`, `:first-of-type`, `:last-of-type`,
     `:only-of-type`, `:empty`;
   - `:nth-child()`, `:nth-last-child()`, `:nth-of-type()`, `:nth-last-of-type()`. Their
     argument, after collapsing whitespace around `+`/`-`, matches
     `^(odd|even|[+-]?\d{1,3}|[+-]?\d{0,3}n([+-]\d{1,3})?)\z` (so `2n + 1` is admitted and
     the empty string, `+`, `5+3` and `+-1` are refused), optionally followed by ` of <selector>`
     on the `-child` forms only, where the inner selector is gated recursively;
   - `:not()`, `:is()`, `:where()`, `:has()`, with arguments gated recursively, to a depth of 3.
     `:has()` inside `:has()` is refused (invalid CSS);
   - `:lang()` with a BCP 47-shaped argument `^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*\z` (quoted or
     not), `:dir(ltr|rtl)`, `:open`, `:checked`, `:disabled`, `:enabled`,
     `:placeholder-shown`.
   `:root`, `:host`, `:host()`, `:scope`, `:defined` and every vendor-prefixed pseudo-class are
   refused.
5. **Pseudo-elements.** At most one per entry, and last. Only these are admitted: `::before`,
   `::after`, `::marker`, `::first-line`, `::first-letter`, `::placeholder`, `::selection`,
   `::backdrop`. `::placeholder` and `:placeholder-shown` have a host only if P-5 admits Δ5's
   `placeholder` attribute; until then they are admitted and paint nothing, and that is
   disclosed, not refused (the gate does not track P-5). `::file-selector-button` is treated the
   same way: admitted and disclosed while no `type=file` host exists, which P-24 decides. `::part()`, `::slotted()` and vendor-prefixed ones
   are refused.
6. **Type selectors.** The names `html`, `head` and `body` are refused. Inside a band they
   match nothing, which would validate green and paint nothing (I19).
7. **Combinators that leave the band.** Only `>` may lead an entry; `+` and `~` may not,
   because they would select the band root's **siblings**. And an entry that **begins on the
   root** (it starts with `:` or `::`, the compound row below) may not contain `+` or `~` at
   depth 0 of the entry at all: `:hover + section` would emit
   `[data-pp-band="<id>"]:hover + section`, whose subject is the **next band**. (Inside
   `:is()`/`:has()`/`:not()` a combinator only reads; it is the depth-0 combinator that chooses
   the subject.) Such an entry is refused with a message saying to begin it with `*` or a
   descendant selector instead.

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
outside the band (`:is(.dark *)`). The claim is about the **subject**, not about where pixels
land: a subject inside the band can still paint outside its box (`position: fixed`, the top
layer, `::backdrop`), and those are disclosed admissions (§4). Scoping of the subject is a
property of the emitted form, not a filter applied afterwards.

**The emission form is M-5.** This draft specifies the prefix form. `@scope` is **not** a
byte-identical swap: inside `@scope` a selector without `:scope` is implicitly a descendant, so
`:hover .x` and `::before` change meaning, and scope proximity enters the cascade before order,
which changes §5.5's tie rule. An `@scope` form would need its own per-row mapping
(`:scope`-prefixed) and its own review.

### 6.3 Declarations: exactly Layer 2, except §6.4

A rule's `css` map is **a LAYER-2 `_css` map**, unchanged except for the one carve-out §6.4
names (`content`, on its own code path with its own closed grammar):

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
- `counter(<ident>)` or `counters(<ident>, "<separator>")`, where the separator is at most
  8 bytes of ASCII punctuation and spaces (no letters, digits, backslash or quotes).

`attr()` is refused, and so is any string carrying text. That removes the design doc's named
`content: attr()` vector and keeps authored **text** in content props, where 3A sees it.

**The glyph question is open, not settled here.** Three of the retired `::before`/`::after`
capabilities (§1.6) need only the empty-string form. The fourth does not: the testimonials
decorative opening quote was a `"` glyph rendered via `::before`
(`components/testimonials/README.md:141`), which needs a one-character string, and
`_pp_forbidden_css_construct` refuses the CSS-escaped spelling (`"\201C"`) for its backslash.
Whether to admit a small, named set of typographic glyphs (and how "text" is defined beyond
ASCII) is **M-7**.

### 6.5 At-rules

None may be authored. The engine emits `@media` from `pp_udc_breakpoints()`, exactly as
LAYER-2 §6.2 does.

`@keyframes` stays excluded: keyframe names are page-global and the **last** definition wins,
so an authored `faq-open` would silently override the theme's own animation
(`assets/css/components.css:1409`). (Other page-global names reachable through admitted
declarations, such as `anchor-name` and `view-transition-name`, do not override theme
definitions; they are admitted and the author owns collisions.) Test shape: an authored
`@keyframes` is refused at write; T-10's at-rule matrix. A band-namespaced keyframe
mechanism is future work, named in §11 (P-26 asks whether it is a guaranteed destination).

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

**A second fetch channel exists without `url()`, and it is tied to P-4.** A scoped rule can
toggle `display` on an admitted `<img loading="lazy">` whose `src` is external: a lazy image
held at `display: none` never loads, so an attribute-selector condition decides whether a
request reaches a third-party host. Under P-4 option A (external media admitted) this is an
exfiltration-shaped channel over whatever the condition can read (in-band attribute values,
including plugin output in an embed band). The contract names it here so that P-4 is ruled
with it in view; T-10 pins that no scoped rule's condition changes which external requests a
page makes (a network-log assertion across condition states).

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

- `data-pp-island` and `data-pp-island-kind` are the **two** `data-pp-*` names E6 lets authors
  write, and only in `custom.markup`. The predicate owns both.
- **Name gate:** `^[a-z][a-z0-9_-]{0,63}\z`, unique within the band, and at most 64 islands
  per band.
- **The island's contract** comes from `data-pp-island-kind`, which is `plain` (the default),
  `inline` or `rich`.
- **The element must be empty in `markup`.** Its content lives in `islands.<name>`, and the
  engine renders it into the element through that contract's sink.
- **Host elements are allowlisted per kind,** because the host decides how the browser parses
  what is rendered into it:
  - `rich` → a flow container: `div`, `section`, `article`, `aside`, `header`, `footer`,
    `main`, `figure`, `figcaption`, `blockquote`, `li`, `dd`, `td`, `th`, `details`;
  - `inline` → a phrasing container (never `a`: inline content may carry a link, and a link
    inside a link closes its host): `span`, `p`, `h1`–`h6`, `strong`, `em`, `small`,
    `mark`, `label`, `dt`, `summary`, `caption`, `figcaption`, `legend`, `cite`, `q`;
  - `plain` → any `rich` or `inline` host.
  Never a void element, an RCDATA element (`textarea`, HTML `title`), a table-structure
  element other than a cell, or anything in the SVG or MathML namespace. A wrong host is
  refused (`custom_island_host`).
- **The composed band is verified as a whole** (§2.1): markup and islands are sanitized
  separately, then the rendered composition is re-parsed once, in the band-root context, and
  the §4 check runs on that.

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
5. **Authored shortcode attributes are authored bytes.** A core shortcode writes the author's
   attribute text into its own output (measured: `[caption id="main"]` emits `<figure id="main">`,
   and `id="pp-3f9a1c2e"` emits a minted-anchor-shaped id). So the E6 id checks and
   `content_duplicate_id` run over the **post-`do_shortcode` output** of an embed band, and E2
   runs over URL-valued attribute values of core's registered shortcodes. T-6 carries a
   `[caption id="<anchor>"]` row.
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
- **Invisible format characters** (`\p{Cf}`) are neutralized at that sink (open: **P-15**
  recommends a narrower set that keeps ZWNJ and ZWJ). The exceptions are
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
part of the precondition, not optional polish. **The message check is on the sender, never
the origin:** an opaque frame reports its origin as `"null"`, which any sandboxed plugin frame
can also send, so the editor accepts a message only when `event.source ===
frame.contentWindow`. T-12 pins it with a second sandboxed frame posting the same message.

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
   is the future contract working as designed, and never fires. **Exception:** a loss matching
   a §4 row that **any open §12 P-question bears on** is classed
   **CANDIDATE-PENDING**, not EXCLUDED (§9.2).
4. **Visible against the author's intent.** The loss produces a difference between the
   **sanitized render** and the **intent render**:
   - for a **carried-over** string, the intent render is `S` itself, rendered **unsanitized**
     in the isolated sandbox below. The live page cannot be the reference: prod renders the same
     core sanitizer, so a loss in the stored bytes is already in the live page, and comparing
     two identically sanitized renders can never show it;
   - for a string **written for the reconstruction**, the intent render is the owner's design
     source.

   The difference is observed in every one of these channels: widths 375, 768 and 1280 px; a
   device-pixel ratio of 2 (a lost `srcset` is invisible at 1); the hover and focus-visible
   states of every interactive element in the band; and the accessibility tree. It counts when
   it is a missing element, a missing style effect, changed text, or a changed accessible name
   or role.

**Hard constraint on the intent render.** Unsanitized authored bytes **never** touch a live
page context. The intent render happens only in an isolated, script-free document: a
headless-browser page loaded from a local file or `about:blank` with JavaScript disabled, no
network access, and no WordPress session or cookie. It is never the dev or prod site, never the
editor preview, and never a page any other user can load. The bytes are evidence of intent,
not content.

Condition 4 is **not a demand test**. It separates losses that change the page from losses
that change nothing: an unused `data-*` attribute, or a declaration that repeats an inherited
value. It does not ask whether the construct is "needed enough".

### 9.1a The selector-gap route (3B)

A reconstruction effect that no content construct produces, and that only a **scoped-sheet
selector** (§6) can reach, is not dropped between the two contracts. LAYER-2 §6.15 sends the
selector-shaped gaps (#1049, #1068, #1069: lists and links inside rich text) to Layer 3, and
this route receives them. When T4's reference shows such an effect:

- T4 records it: the band, the effect, the selector shape that would reach it, and the
  screenshot pair;
- the orchestrator rules, case by case, either a **3B re-entry** of §6 for that selector shape
  (the §9.3 rules apply), or a **named 2.0.0 fidelity gap** recorded in the release notes;
- **the outcome is always recorded, never silent.** Neither answer is pre-decided here.

### 9.2 Procedure

1. **Read stored bytes, never rendered ones.** The public page is already post-sanitizer
   (§1.3), so it cannot show a loss. T4 reads each carried-over page's **raw stored composition**
   (the `_pp_composition` JSON, or the composition-read action's full result), never
   `wp pp operate inspect-composition`: that surface emits only schema-scalar props and
   `items[].<field>`, so `table.rows[][]` (a RICH sink), `section.panel_items[]` and grid bullets
   never appear, and the trigger could not fire for them. The detector enumerates **every** §1.1
   sink path, table cells included (probe-07c carries a cell fixture). For prod content, that needs whatever
   read-only export the orchestrator authorizes (**M-13**). T4 also records every new content
   string it authors.
2. **Run the detector** over every content string. The reference implementation is
   `t4-content-loss-detector.php` in the T3 evidence set (self-test: probe-07c, which carries
   one fixture per class and is re-run whenever §4 or the §12 P-list changes). It is a
   read-only `wp eval-file` script, not theme code. It reports each loss as one of:
   - **EXCLUDED** — it matches a §4 row with no open P-question;
   - **CANDIDATE-PENDING** — it matches a §4 row an open P-question bears on. It is recorded
     with that P-number, and it **re-fires as a CANDIDATE if the question is ruled "admit"**;
   - **CANDIDATE** — anything else.
3. **For each CANDIDATE, judge condition 4** across the channels of §9.1, sanitized render vs
   intent render (the intent render under the hard constraint above).
4. **Fire.** A CANDIDATE that meets condition 4 **fires the trigger.** T4 stops that construct's
   work and hands back a **re-entry record**:
   - sink, band and prop;
   - `S`, `R` and the lost construct;
   - the render pairs;
   - the clause of this contract that would admit the construct (a §3.2 Δ row, §3.1, or
     "none: §12 needs a ruling first").

   CANDIDATE-PENDING rows are listed in the T4 handoff with their P-numbers, so the evidence
   reaches the owner with the question it bears on.

### 9.3 What re-enters

**Exactly the clause that admits the lost construct, implemented to this contract,** with that
clause's rows of the §10 test plan. Examples:

- a dropped `transform` in a `style` attribute re-enters **Δ3**, which means the whole
  style-attribute gate, because Δ3 is one gate and half of it would be a second contract;
- a dropped inline SVG icon re-enters **Δ1**;
- a selector gap ruled for re-entry under §9.1a re-enters §6 for that selector shape.

The re-entry is a **ruled fix**. The orchestrator rules which clause, and routes any §12
question that clause depends on to the owner first. The rest of Layer 3 stays post-2.0.0.

**Order within a re-entry is fixed.** If the admitted construct widens content, then
§2.2 (write-time refusal), §8.2 and §8.3 land **with** it or **before** it. Widening content
before the author can be told what was lost, or before the preview is isolated, would build on
a silent path.

### 9.4 What never fires the trigger

- An EXCLUDED loss (§4). A CANDIDATE-PENDING loss is recorded, and fires only if its P-question
  is ruled "admit".
- Normalisation (§2.2).
- A **styling** gap that neither a content construct nor a scoped-sheet selector would close.
  That is Layer 1/2 territory, with its own routes. (A selector-shaped gap takes §9.1a.)
- Chrome text (the footer and nav options). These are PLAIN by design today, and **P-2**
  asks whether titles, headings and chrome text stay PLAIN.
- A loss invisible in every channel of condition 4.

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
| T-1 | §3.1 base ownership | the PP-owned table equals **derive(core `post` on the pinned WordPress version) − §4 + Δ**, and a stored snapshot of core `post` equals live core `post` (the clause that fails on drift; never the table compared to itself). A divergence fails loudly |
| T-2 | §2.5 convergence | for every §3 admission row: write accepted, stored verbatim, and the rendered DOM contains the construct (normalised). Plus Δ4: `target="_blank"` and a named target each get `noopener` on the rendered DOM; `_self` does not. |
| T-3 | §2.2 write refusal | each §4 row refused with `content_construct_excluded`, naming the construct and the clause; nothing stored. **Each §4 row is asserted by that row's own §4 test-shape column,** not by a generic absence check. Also: §3.3's INLINE non-inline element (`<div>` in `cta.body`) and PLAIN markup (`<b>` in a title) refused at write. |
| T-4 | §2.3 render strip + finding | raw-meta and restore paths: the construct is absent from the rendered DOM, and `content_stripped_at_render` carries the facts. "Absent from the rendered DOM" is never the whole assertion: E10 asserts the next band's sentinel (a stray closer is never a DOM node), and E4 asserts that no byte of a refused `script`/`style` body appears in the band's text. The finding is asserted on all three surfaces: `wp pp check page`, the post-write envelope, and the chat report. |
| T-5 | E1 / E2 matrices | §4's name and obfuscation matrices, including `xlink:href` and every `srcset` candidate; `data-wp-*`; protocol-relative `//host` URLs asserted to follow P-4's host rule; rewrite-to-relative pinned absent |
| T-6 | E6 forging | one row per §1.4 marker: the forged scope does not paint (computed style); the promote step and E6 agree. Every forged-marker row is paired with a **positive control**: the genuine engine-emitted marker paints a distinct computed value with the same fixture styles. Extended to the rest of E6: minted `it-` ids, the reserved ids `main` and `pp-nav-menu`, `data-pp-island*` refused outside `custom.markup` and admitted inside it, and a `props.id` collision refused on the write that adds the anchor. |
| T-7 | Δ3 style gate | LAYER-2's §6.0 matrix through a `style` attribute; §1.3's first-row properties admitted; the entity-split case round-trips; every property the owner's 85 measured `style` attributes use is admitted (a fixture built from probe-05's property census). Plus the Δ3 rules outside LAYER-2's matrix: the text-bearing string properties (`quotes`, `list-style-type`, `text-overflow`, …) refused with a string value and admitted with a keyword; a CSS escape and an apostrophe inside a double-quoted value refused as written. |
| T-8 | Δ1 / E5 SVG | the static subset renders; fragment-only references; each active element refused; `use` with an external `href` refused. Plus: an SVG `title` with an element child refused; `url(` hidden behind a CSS escape refused; `xml:base` refused; `viewbox` emitted as `viewBox`; one `id` in two bands raises `content_duplicate_id`. |
| T-9 | mutation-XSS idempotence | `sanitize(browser_parse(serialize(sanitize(x)))) == sanitize(x)`, and the browser DOM contains no E-row construct, over a corpus of known parse-differential shapes (namespace confusion, `noscript`/`template`/`style` inside foreign content, comment and CDATA edge cases, stray end tags per sink context, table-cell context, each island host kind, the composed custom band, and a `WP_HTML_Processor` bail pinned as fail-closed). Run in Chromium. Each corpus entry names its **expected survivor** (the admitted text or element around the hostile shape), asserted present in the browser DOM, so a sanitizer that over-strips or returns empty cannot pass. Plus the #730 inheritance: `pre_kses` is still hooked after a hostile render of every §4 row, and a static scan finds no `try`/`catch` around the `wp_kses()` call; and the style-slot markers: a forged index, a duplicated marker, and one hidden in SVG `title` text are each a `Loss`. |
| T-10 | §6.2 selector gate | **the probe `:hover + section` verbatim** (refused at write; and, with the **gate bypassed but the emitter unchanged**, a Playwright hover actually activates the condition and the next band's computed style is asserted unchanged, next to a positive control where the same declaration on an in-band subject **does** change under the same hover), plus `:not(.x) ~ *`, `:first-child ~ [data-pp-band]` and `a, + .x`; the byte matrix; each pseudo-class allowed and refused; leading `+`/`~` refused; emitted-form pins proving every subject is inside the band (a sibling band's computed style is unchanged). Each condition is **activated** in the fixture: the band is placed as a first child for `:first-child ~ …`, and the §6.7 network-log assertion runs with an external `<img loading="lazy">` present and every condition state entered. Plus the limit and edge matrix (256 bytes, 16 entries, depth 3, `:has()` inside `:has()`, the `html`/`head`/`body` type refusal, one pseudo-element and last, the nth-argument grammar); the at-rule matrix (`@keyframes`, `@import`, `@media` each refused); state sub-map keys refused in a `_scoped` rule's `css`; an equal-specificity tie won by the scoped rule; two same-specificity rules in swapped order flipping the computed value. |
| T-11 | §6.4 `content` | `""` and the two counter forms paint a pseudo-element box, and `none`/`normal` suppress it (both pinned); `attr()` and text strings are refused |
| T-12 | §8 sinks | prompt-regression cases (ai-ready harness): content carrying `\p{Cf}` and instruction-shaped text reaches the model framed and neutralized. A preview isolation pin: the preview document's origin is opaque. Plus the **spoofed sender**: a message from a second sandboxed frame is ignored (`event.source` check), not only the opaque-origin check; a chat pin that a content value carrying `<img onerror>` renders as text in `changes[].from/to`; the neutralized set follows the P-15 ruling, and the preserved code points are listed and asserted to survive. |
| T-13 | §7 islands | name gate; empty-island and unknown-island rules; a patch to `islands.<name>` diffs as one field; CAS and undo per island write. Plus: the host matrix per kind (`custom_island_host`, `a` refused as an inline host, void/RCDATA/SVG hosts refused, cells the only table-structure hosts); a non-empty island element refused; a duplicate island name refused; a 65th island refused; presence pins for `custom_band_unverified` and for `content_plugin_output` naming the shortcode tags; a docs pin that the AI surface no longer says shortcode output is stripped. |
| T-14 | §2.6 / M-2 | a stored band with a now-refused construct does not block an edit to another band |
| T-15 | §5.1 rank | a content `style` beats a role value on its own element (computed style); `content_inline_style` states the count and properties. Plus: `content_inline_style` names a `popover` element; a fixture group claiming a new property does not make stored content using it invalid (§5.2); a borrowed `.section__content` class inside content does not register role presence (§5.4/M-11). |
| T-16 | AI surface derived | the exclusion list in the prompt is built from the predicate's own tables (I43), as LAYER-2 §7′ requires for `_css` |
| T-17 | performance | the predicate on a maximal RICH prop (the M-8 bound) and a maximal custom band stays within a stated budget per render. The render path is the hottest in the theme, so the cost is measured and not assumed. Two costs are named in advance: the §2.1 re-parse roughly doubles the per-prop work, and `:has()` in a scoped rule is the one selector whose **browser** cost grows with the band's size. If the budget fails, the first lever is a render cache keyed on the content bytes' hash plus the predicate's table version, so an unchanged prop is sanitized once. |
| T-18 | open set (no silent narrowing) | an unlisted but valid construct in each open rule passes: an unlisted CSS property through Δ3 emits verbatim; if P-18 is ruled spec-derived, an unlisted static SVG attribute passes. T-2 alone proves only listed rows and cannot catch a narrowing. |

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
- author-defined `@keyframes` (§6.5); band-namespaced keyframes are the named future work;
- engine-built third-party embeds (**P-6**);
- an accordion-editor UI for islands (BUILD-SPEC §7: post-2.0.0);
- changes to chrome text contracts (pending P-2, which asks whether they stay PLAIN);
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
`testimonials.items[].quote`) and PLAIN props (every title, heading, eyebrow, label, and the
chrome text options) keep their narrow contracts? Answered on what each prop means and on the
coherence cost, not on whether a given site has used richer markup there. A narrow INLINE
pushes content out of grid, cta and testimonials just as a custom-band-only sheet would
(P-3); PLAIN titles make `<br>`, `<sup>®</sup>` and a second accent word inexpressible.

- A: keep both narrow contracts as they are. The ceiling is lifted by RICH, 3B and 3C.
- B: widen INLINE to admit `span` with `class`/`style`, for accent runs.
- C: collapse INLINE into RICH.
- *Recommendation:* **B for INLINE** (`span` with `class`/`style`, plus `sup`, `sub`,
  `small`, `mark`, `code`), and admit the same inline set in PLAIN **titles and headings**,
  where it is ordinary typography. Keep labels, button text and URLs PLAIN, where markup has
  no meaning. Revised in review: this recommendation first read "if the owner has used accent
  runs", which is a demand condition and was withdrawn in the freedom-posture pass (§13.5).

**P-3. Scoped-sheet reach.** Is `udc._scoped` accepted on **every** band, or only on the
custom band?

- *Recommendation:* **every band.** A custom-band-only sheet would push content **out** of
  structured components just to get a selector, which is the design doc's own objection to
  its Approach B. (Context: the measured escape pressure is selector-shaped and sits in
  ordinary bands, §1.6.)

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

**Added by the contract-boundary review (pass 2).** Each one is a place where a gate refuses
something harmless, or where freedom and a residual risk trade directly. They are the owner's
calls, not fixes.

**P-9. Plugin script gadgets.** Core's `data-wp-*` is refused (E1). Installed plugins can bind
behaviour to other attributes or classes an author writes (htmx `data-hx-on`, framework
`data-*` hooks, a plugin's custom element). Refuse a named list of known gadget prefixes, or
state the boundary and disclose?

- *Recommendation:* **state the boundary (§0.3, §7.5) and disclose, no blanket refusal.** A
  prefix list is always one plugin behind, and refusing `data-*` broadly would take away an
  ordinary authoring tool. The owner chooses the plugins, as with P-8.

**P-10. Non-executing app links and inline raster images.** E2 refuses every scheme outside
`wp_allowed_protocols()`, including app links that execute nothing in the page (`sip:`,
`whatsapp:`, `geo:`), and refuses `data:` images in `img src`.

- *Recommendation:* **admit a named list of app schemes** (`sip`, `whatsapp`, `geo`, `maps`,
  `signal`, `facetime`), never a pattern, because some OS protocol handlers are themselves
  attack surfaces (the `ms-msdt:`/`search-ms:` class). **Admit `data:image/png|jpeg|gif|webp|avif`
  in `img src` only,** under `pp_esc_image_src()`'s existing size cap. Never `data:image/svg`.

**P-11. The same-install PDF `<object>`.** Core renders it today (§1.3). E3 would refuse it,
While P-11 is open, §9 records the loss as CANDIDATE-PENDING; it becomes EXCLUDED only if
the owner rules to refuse it.

- *Recommendation:* **admit `<object type="application/pdf">` whose `data` resolves to this
  install's uploads,** as a named exception row in Δ2. It is a static document from the
  site's own media library, not a third-party context.

**P-12. Static SVG references and `textPath`.** E5 refuses same-document `href` on gradients,
patterns and filters (gradient inheritance) and refuses `textPath`.

- *Recommendation:* **admit them with fragment-only `href`,** the gate Δ1 already applies to
  `use`. They fetch and execute nothing.

**P-13. `url(#fragment)` in a `style` attribute.** Δ1 admits a fragment reference in an SVG
attribute. E7 refuses the same reference written as `style="fill:url(#g)"`.

- *Recommendation:* **admit the fragment-only form in style attributes too,** with the Δ1
  regex. It names nothing outside the document, so §6.7 still holds.

**P-14. `:popover-open` and `:modal`.** `popover` is admitted, but the selectors that style
its open state are not.

- *Recommendation:* **admit both.** Without them the admitted popover cannot be styled open.

**P-15. ⚠ Invisible joiners in non-Latin text and emoji (real user harm).** §8.2 neutralizes
`\p{Cf}` in the assistant's context. That category includes ZWNJ (U+200C) and ZWJ (U+200D).
Persian and the Indic scripts need ZWNJ to spell words correctly, and every multi-person or
skin-tone emoji is a ZWJ sequence. A site in those languages would have its own text
corrupted in what the assistant reads and proposes back.

- *Recommendation:* **neutralize only the bidi overrides and isolates (U+202A–U+202E,
  U+2066–U+2069) and the tag block (U+E0000–U+E007F);** keep ZWNJ, ZWJ and the directional
  marks (U+200E, U+200F, U+061C). T-12 pins a Persian ZWNJ word and a ZWJ emoji surviving the
  sink unchanged.

**P-16. Misnested ordinary HTML.** Fail-closed verification refuses markup the parser does
not support, for example `<b><p>x</b>y</p>`. Browsers render it, and authors paste it.

- *Recommendation:* **keep the refusal, named "unsupported markup", with a message that says
  which element to close first.** Admitting what the verifier cannot parse means admitting
  what nobody checked. The cost is real, and it belongs to the owner. Stated precisely
  (probe, WP 7.0 `create_full_parser`): `<b><p>x</b>y</p>`, `<p><i>a<b>b</i>c</b></p>`,
  `<p><em>a</p><p>b</em></p>` and foster-parented table text bail; `<p>a<p>b`, implied `<li>`
  closes, `<a><div>` and SVG/MathML do not. This is the only refusal whose boundary is set by
  a third-party parser version, so the bail set gets a T-1-style drift pin: a WordPress upgrade
  that changes it fails a test and is read, never absorbed.

**Added by the contract-boundary review (pass 3, freedom posture).** Each is a place where a
closed list, an inherited exclusion or a missing destination narrows content freedom without
an executes/fetches/escapes/forges reason. They are the owner's calls.

**P-17. The base is WordPress's editorial list, not the web platform's.** Core `post` omits
`tabindex`, `translate`, `inert`, microdata (`itemscope`, `itemprop`, `itemtype`), most of
ARIA 1.2 (`aria-pressed`, `aria-level`, `aria-invalid`, …), and the elements `bdi` and
`datalist`. Today kses drops them silently; under §2.2 the whole prop is refused.

- *Recommendation:* **the base is the full HTML and ARIA 1.2 global set and every static
  element, minus §4,** with `autofocus`, `contenteditable`, `nonce` and `is` argued one by one.
  When core widens its list, the default is **admit unless §4**, not rule-per-item.

**P-18. Closed lists meet refuse-never-coerce.** Every Δ list is printed as the admission
boundary. So one omitted attribute in a real export (Figma's `color-interpolation-filters`,
`textLength`, `metadata`, the lighting filter primitives, editor namespace declarations)
refuses the whole prop, where kses drops only that attribute today.

- *Recommendation:* **each Δ list is spec-derived** ("every static element and presentation
  attribute of SVG 2, minus §4 and the value gates"), and the printed list documents the rule
  rather than bounding it. Editor namespace attributes (`xmlns:inkscape` and the like) are
  admitted as inert. T-18 adds an open-set pin: an unlisted valid construct passes.

**P-19. Background images below the band root.** The scoped sheet and content `style` refuse
every `url()`, and A2's attachment-id path exists only on `_band`. A custom band cannot give an
inner element a background image, not even from its own media library.

- *Recommendation:* **admit an A2-style attachment-id background in scoped rules,** with the
  engine building the same-install URL exactly as `_band.background.image` does. External
  hosts stay refused (§6.7).

**P-20. Custom properties in content `style`.** Δ3 refuses every `--x`. The `_tokens`-bypass
reason covers the engine's own names, not an author's.

- *Recommendation:* **refuse only `--pp-*` and the engine's minted token names;** admit other
  custom properties in content `style` and in scoped rules.

**P-21. At-rules in the scoped sheet.** All are refused. The I36 argument covers width
breakpoints only. It does not cover `prefers-reduced-motion`, which is an accessibility
obligation once `animation` and `transition` are admitted, nor `prefers-color-scheme`,
`hover`/`pointer`, `print`, `@supports` or `@container`.

- *Recommendation:* **admit non-width media features, `@supports` and `@container`** in scoped
  rules. Width breakpoints stay engine-owned.

**P-22. Pseudo-class and pseudo-element lists.** Rules 4 and 5 are closed lists. They omit, with
no reason given, `:any-link`, the form-validation states (`:required`, `:valid`, `:invalid`,
`:user-invalid`, `:in-range`, …), `:read-only`, `:indeterminate`, `:default`, `:playing`,
`:paused`, `::cue`, `::target-text` and `::details-content`.

- *Recommendation:* **every pseudo-class and pseudo-element in a pinned Selectors list, minus
  named exclusions.** Unknown names stay refused (I19).

**P-23. Custom and unknown elements (E8).** A styled custom element paints through its class,
`style` and the scoped sheet, so E8's "inert" reason is only half true, and the plugin-defined
case is the same boundary P-9 recommends disclosing rather than refusing.

- *Recommendation:* **admit hyphenated custom element names** (the valid custom-element
  grammar), disclose them with the plugin boundary, and keep refusing names that shadow HTML,
  SVG or MathML elements.

**P-24. Forms: file inputs and `method="dialog"`.** Δ5 excludes `type=file` without a §4
reason, and omits `method="dialog"`, which closes an admitted `dialog` with no script.

- *Recommendation:* **admit `method="dialog"`.** On `type=file`, admit it with `enctype`
  gated to the three standard values, under the same E2-gated `action`: an upload to a
  reviewed destination executes nothing in the page.

**P-25. What a human can edit in a custom band.** Plain islands exclude `a` and `button` hosts,
so a link or button label cannot be edited without a structural write. There are no attribute
islands (`href`, `src`, `alt`) and no repeatable islands, and the cap is 64.

- *Recommendation:* **admit `a`, `button`, `time`, `code`, `abbr`, `sub` and `sup` as plain
  hosts now; name attribute islands and repeatable islands as guaranteed destinations** of
  this contract (P-26).

**P-26. Scheduling must have a destination.** "Scheduling is not gating" (§0.1) holds only if
post-2.0.0 has a binding. Nothing commits the §3 admissions, engine-built embeds (P-6),
band-namespaced keyframes, or attribute/repeatable islands to a release.

- *Recommendation:* **ratification commits every §3 admission to a named release (2.1.0), and
  names each §11 item as a guaranteed destination with its owning contract,** whether or not §9
  fires.

### Mechanics and security questions (the orchestrator rules)

| # | question | recommendation |
|---|---|---|
| M-1 | Where the predicate runs, and how it verifies itself | write (refuse) **and** render (re-sanitize + `content_stripped_at_render`), one function (§2); its last step parses the output with `WP_HTML_Processor::create_full_parser()` inside the per-sink template wrapper, checks containment (E10) with a next-band sentinel, and re-checks §4 on that token stream; a parser bail is a `Loss` named "unsupported markup" (§2.1) |
| M-2 | Which stored bands a write validates for content (#1007 class) | refuse losses only in bands the write changes; stored bands report via the §2.3 finding |
| M-3 | Style-attribute value gate | LAYER-2 security gates, **untyped**. Refuse an uppercase property (I34), naming the lowercase form. |
| M-4 | Base allowlist ownership | a PP-owned table derived from core `post` on the pinned WP version, minus §4, plus Δ1/Δ2(/Δ5), without `style` and with the internal `data-pp-style-slot`; it is the `allowed_html` passed to `wp_kses` (§2.1 step 3); drift pin (T-1) |
| M-5 | Scoped-sheet emission form | attribute-prefix emission (§6.2), which is universally supported. `@scope` is NOT a byte-identical swap (§6.2); any move to it is its own reviewed change |
| M-6 | CSS parsing | in-house bounded tokenizer; no parser dependency (§6.6) |
| M-7 | `content` in scoped rules | only `""`, `none`, `normal`, `counter()`, `counters()` with a separator of at most 8 bytes of ASCII punctuation/space (§6.4). **Open sub-question, routed not ruled:** admit a small named set of typographic glyphs (e.g. the curly quotes the retired testimonials quote mark used) as one-character strings, and define "text" beyond ASCII. Recommendation: admit an explicit list of punctuation code points (quotes, dashes, bullets, arrows) and refuse every letter or digit in any script (Unicode `L*`/`N*`). **Pass 3 adds:** admit `open-quote`, `close-quote`, `no-open-quote`, `no-close-quote` (they carry no author text; the browser supplies locale glyphs, which restores the retired testimonials quote mark with no text channel), and the `counter(<ident>, <counter-style>)` second argument; and apply whatever glyph set M-7 admits **uniformly** to every text-bearing CSS string Δ3 refuses (`list-style-type: "✓"`, `text-overflow: "…"`, `quotes`), so custom bullet glyphs are not a separate ceiling |
| M-8 | Byte bounds | 64 KiB per RICH/INLINE prop; 128 KiB for `custom.markup`; 16 KiB per island; 64 islands; 128 scoped rules per band. How the numbers are derived is M-18. |
| M-9 | Finding codes and shapes | `content_construct_excluded` (refusal), `content_stripped_at_render`, `content_inline_style`, `content_external_resource` (if P-4 = A), `content_plugin_output`, `custom_band_unverified`, `custom_island_empty`, `custom_island_unknown`, `custom_island_host`, `content_duplicate_id`. Refusals without their own code are `content_construct_excluded` with the clause named: a non-empty island element (§7.2), a duplicate island name, a 65th island, PLAIN markup and INLINE non-inline elements (§3.3), and M-3's uppercase property. The E6 anchor-collision refusal is a **props** write, so it uses the existing `invalid_prop_value` envelope, naming the band whose content holds the id. All facts-only. |
| M-10 | `rel="noopener"` on `target="_blank"` | add it, disclosed as normalisation (Δ4) |
| M-11 | Presence probe vs borrowed role classes | scope the probe to template-rendered elements (exclude content-container descendants) rather than disclose ambiguity |
| M-12 | The custom component's name | `custom` |
| M-13 | T4's access to stored prod content for §9.2 | a read-only export authorized by the orchestrator; T4 never writes prod |
| M-14 | Close §1.4's engine-namespace forging early, before 2.0.0 | the orchestrator's scheduling call; it is independent of the rest of Layer 3 and small. Done per I34: **refuse** a `data-pp-*` attribute or reserved id at write, and strip it at render with a disclosed finding (§2.2/§2.3), never a silent strip |
| M-15 | Δ1's per-attribute byte cap (4 096) | drop it; M-8's per-prop bound carries the DoS argument, and real path data exceeds 4 KiB |
| M-16 | A mitigation for the §6.7 lazy-image channel that keeps P-4 option A | refuse attribute-selector conditions in scoped rules whose compound reads inside an embed band's plugin output, and pin the network-log assertion (T-10) |
| M-17 | Non-ASCII in selectors | admit UTF-8 letters inside quoted attribute values and class/id names, so content authored in non-Latin scripts is selectable; the byte gate stays an allowlist (Unicode `L*`/`N*` plus the current set) |
| M-18 | M-8 bounds derivation | the numbers derive from T-17's performance budget **and** the repo's existing availability gates: the 1 MiB write-findings gate (`PP_WRITE_FINDINGS_MAX_STORED_BYTES`, `lib/actions.php:5516`; above it an accepted write builds no findings), the 512 KiB presence bound (`PP_UDC_PRESENCE_MARKUP_BAND`, `lib/udc.php:151`), and the history ring (10 full snapshots in one meta row, rewritten under the lock on every write, `lib/wp.php:4956`). A maximal page stays under those gates, or the contract says which disclosures degrade to "not checked: size". A per-composition byte bound and a T-17 history-ring case are added. The owner's largest stored band is a floor check, not the source |
| M-19 | *(advisory from /review, simplification)* One `WP_HTML_Processor::create_full_parser()` walk instead of steps 2-4 | step 5 already fails closed when that parser bails, so every accepted prop is one it can walk; on WP 7.0 it exposes `get_namespace()`, `get_attribute()` (decoded), `remove_attribute()` and `serialize_token()`. One walk inside the per-sink wrapper could enforce the M-4 table, the namespace checks, Δ3, E2, E6, Δ4 and E10, and emit the admitted tokens. kses and its `pre_kses` hazard (§2.4), the style-slot markers and the step-2 raw-text blind spot would then all disappear, with every §4 row and T-row kept. **Recommendation: prototype it (rule 14.3) at implementation time and adopt it if T-9 and T-17 pass;** the §2.1 pipeline stays the contract until then. It supersedes the Q-A1 mechanism only by ruling. |
| M-20 | *(advisory)* Comma lists in a `_scoped` rule's `selector` | each entry is already emitted as its own rule, so `"a, b"` equals two rules: a second spelling of one thing (the I36 shape §6.3 cites). **Recommendation: refuse a top-level comma** (commas inside `:is()`/`:where()`/`:not()`/`:has()` stay); the 16-entry cap and the split go away |
| M-21 | *(advisory)* One refusal-code convention | report `custom_island_unknown` and `custom_island_host` as `content_construct_excluded` naming the §7.2 clause, and keep dedicated codes for findings only. **Recommendation: adopt** |

---

## 13 — Review trail

Every pass below ran on this document, in series. Each records what it found, what changed, and
who ruled. Superseded text is described here rather than kept inline. The one recommendation
that was withdrawn is named.

### 13.1 Measurement (before drafting)

Read-only, on wp-env (WordPress 7.0) and from the code:

- probes 00-09: the core allowlist, a 32-input × 4-sink matrix, an authoring-path write rendered
  in Chromium, `safecss_filter_attr` twice, the public-page `style` census, the shortcodes, the
  detector self-test, the `WP_HTML_Processor` breakout, and a `url(`/`!important` census;
- a static map of every content sink;
- five public brand-site pages.

The probe page was deleted afterwards. The findings that could be read as security chains went
to a private brief. The public text of this document states only the hardening rules.

A server reboot interrupted the first run of pass 1 (below). No evidence file was cut off. The
environment was restarted, and one earlier probe re-run byte-identically before any new
measurement was trusted.

### 13.2 /plan-eng-review

Seven findings, all applied (the first two below were each two findings: the verify-after-sanitize
step and its M-1 row, and the preview cost and its §8.3 statement):

- verify-after-sanitize with `WP_HTML_Processor` (§2.1);
- the preview-isolation cost to the editor's refresh (§8.3);
- the leading-pseudo-class meaning (§6.2);
- a template-emitted container marker for the presence probe (§5.4);
- T-7 wording;
- the performance costs of re-parsing and `:has()` (T-17).

The outside voice (Codex) was unavailable (model not supported by the installed CLI), and that
is recorded as missing coverage, not as a clean pass.

### 13.3 Contract-boundary pass 1: admissions vs gates

Findings: 3 P1, 7 P2, 13 P3. The P1s were contract-core and were handed back; the orchestrator
ruled all three as proposed (Q-A1 = A).

- **A stray end tag in content closed the band.** This became E10.
- **The SVG and style gates were placed after kses,** which had already removed what they were
  meant to gate. The pipeline was rewritten in the order it can run: styles lifted before kses,
  kses run with the PP-owned table, remove-only gates after it.
- **A root-leading entry with a sibling combinator selected the next band** (the
  `:hover + section` case). A new rule 7 refuses it.

The P2s and P3s were fixed in the same batch:

- the subject-only scoping claim, with the top layer disclosed;
- the §6.3 carve-out for §6.4;
- the island carve-out and host allowlists;
- the Δ1 attribute list and its `url(#fragment)` gate;
- the `@scope` claim withdrawn;
- tighter selector grammar;
- the precedence rule;
- the E6 id correction;
- fragment-id collisions disclosed.

Citation and measured-fact corrections were committed separately. The glyph question was routed
to M-7, not settled.

### 13.4 Contract-boundary pass 2: exclusions

Every exclusion was checked for a true reason, a test shape that cannot pass vacuously, and an
enforcement step that can actually see it. The mechanism defects were handed back and ruled as
proposed (Q-A2 = A).

- **E10 is defined by containment, not by a list of tags.** The prop is parsed inside a
  per-sink template wrapper, and a sentinel marks the next band. `create_full_parser` is used
  because `create_fragment` accepts only `<body>` context in WordPress 7.0.
- **Step 4 does more:**
  - it owns E2 for the URL attributes kses does not check;
  - it enforces E6 in full;
  - it matches style markers one-to-one.
- **SVG `title` and `desc` are text-only.** This closes a forged-marker channel through
  markup that the step-2 parser reads as text.
- **Smaller additions:**
  - `data-wp-*` joins E1, and `form=` joins E9;
  - `noopener` covers every new-context target;
  - text-bearing CSS strings are refused in the Layer-3 channels. The Layer-2 half is #1168,
    filed separately in hardening language, because a change to shipped Layer 2 does not ride a
    docs PR.
- **The preview's current lack of isolation is recorded as measured,** and the
  `event.source` rule is added.
- **The posture observations** became owner questions P-9 to P-16. P-15 is flagged for real
  harm to Persian and Indic text and emoji.
- **The detector's classifier** was brought in line with §4.

**Process disclosure.** The pass-2 specialist copied a probe script into the repository root
and deleted it seconds later without running it there. The tree was verified clean afterwards,
and nothing reached a commit. Later specialist prompts carry an explicit
"scratch only, never the repository tree, even transiently" line.

### 13.5 Contract-boundary pass 3: freedom posture

This pass was judged against the owner's standing freedom guarantee. It found places where
freedom was gated by demand and closed lists that re-created a ceiling.

- **Owner questions.** The posture findings became P-17 to P-26, with M-7 extended and M-15 to
  M-18 added. None was fixed silently.
- **A withdrawn recommendation.** This document's own P-2 recommendation first read *"if the
  owner has used accent runs in card lines"*. That is a demand condition. It was withdrawn and
  rewritten, and P-2 now also covers titles, headings and chrome text.
- **Wording fixes:**
  - the STATUS line no longer says "purely additive"; it lists where the contract is narrower
    than today;
  - context and argument are separated in Δ3 and P-3;
  - §9.4, §11 and P-2 agree;
  - Δ5's input types are enumerated;
  - the byte bounds derive from the performance budget.
- **Trigger semantics (contract-core), handed back and ruled as proposed (Q-A3 = A):**
  - The reference for carried-over content is the author's **stored intent**, rendered only in
    an isolated, script-free sandbox. It had been the live page, which is already sanitized by
    the same core code and so could never show a loss.
  - The observation channels now include 768 px, DPR 2, and hover and focus.
  - Selector-shaped gaps get a route (§9.1a), ruled per case and always recorded.
  - A loss matching a §4 row that an open P-question bears on is **CANDIDATE-PENDING**, not
    EXCLUDED. The detector gained the class and a self-test case (probe-07c).

### 13.6 What this trail does not claim

- **None of the 26 owner-posture questions is answered.** None blocks T4. CANDIDATE-PENDING and
  the trigger were built so that evidence on an open question is recorded instead of decided.
- **Nothing here is implemented or tested.** §10 is a plan.
