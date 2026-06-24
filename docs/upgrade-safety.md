# 🛡️ Upgrade safety — theme updates that can't quietly wipe your work

A WordPress theme update doesn't patch files, it **replaces the whole theme folder**.
So if anyone (you, a plugin, or an over-eager AI agent) edited a theme file by hand,
the next update silently overwrites or deletes it. PromptingPress closes that trap.

It ships a manifest of file hashes and checks the live theme against it. If your files
have drifted from what shipped, an update is **stopped before it can touch a single
file**. A daily check keeps the warning current, and one filter lets you override the
block on purpose when you really mean to.

> 💡 **The one rule that keeps you out of trouble:** customize your site through
> **design tokens, fonts, and compositions** (they live in the database and survive
> every update). Don't hand-edit files under `templates/`, `components/`, or
> `assets/` — those get replaced on update.

**What's inside:** 📖 a reference for the commands and hooks, 🚑 a fix-it guide for
when an update gets blocked, and 🧠 the reasoning behind the design.

---

## 📖 Reference

### 🔍 What gets checked

On a packaged release, `scripts/package.sh` writes `integrity-manifest.json` — a map
of every shipped theme file to its MD5 hash. `pp_check_theme_integrity()` hashes the
live theme directory (applying the same `.distignore` exclusions used at build time)
and compares. The manifest itself, plus dev-only paths (`tests/`, `node_modules/`,
`vendor/`, `.git/`, dotfiles, build ZIPs), are never part of the hash set.

The result is stored in the `pp_theme_integrity` option with one of four statuses:

| Status | Meaning |
|--------|---------|
| `safe` | Every shipped file matches the manifest. |
| `unsafe` | One or more files are **modified**, **missing**, or **extra** (on disk but not in the manifest). |
| `invalid_manifest` | The manifest is missing required keys or is not valid JSON — integrity cannot be verified. |
| `null` (no status) | No manifest is present (a pre-integrity theme build). Nothing to compare. |

### ⌨️ Check it from the command line

```bash
# Run a fresh check (hashes files, updates the stored status, prints JSON).
wp pp integrity check

# Print the stored status only — read-only, does not re-hash.
wp pp integrity status
```

`wp pp integrity check` exit codes:

| Code | Meaning |
|------|---------|
| `0` | `safe` — all files match |
| `1` | `unsafe` — modified, missing, or extra files detected |
| `2` | `invalid_manifest` — JSON parse error or schema failure |
| `3` | no manifest found (pre-integrity theme version) |

When the status is `unsafe`, the command lists the offending files grouped by
**modified**, **missing**, and **extra**.

### 🚧 The update block

`pp_block_unsafe_theme_update()` is hooked to WordPress's `upgrader_pre_install`
filter. Before any file is written during an update or install of the **active**
PromptingPress theme, it runs a fresh integrity check and decides:

| Integrity status | Update result |
|------------------|---------------|
| `safe` | ✅ Allowed |
| `null` (no manifest) | ✅ Allowed — nothing to verify |
| `invalid_manifest` | 🛑 **Blocked** (`WP_Error` code `pp_integrity_unverifiable`) |
| `unsafe` | 🛑 **Blocked** (`WP_Error` code `pp_integrity_unsafe`) unless overridden |

Blocking returns a `WP_Error` from the filter, which aborts the install before the
theme directory is touched. The error message names the affected file counts and how
to recover. This covers single updates, bulk updates, and background auto-updates of
the active theme.

### 🔓 The override filter

Both blocking states honor a single override filter. Add a filter that returns `true`
to let the update proceed anyway:

```php
// In a must-use plugin, a site plugin, or wp-cli eval — NOT in the theme itself.
add_filter( 'pp_allow_unsafe_theme_update', '__return_true' );
```

The filter receives three arguments if you need to scope the override:

```php
add_filter( 'pp_allow_unsafe_theme_update', function ( $allow, $result, $hook_extra ) {
    // $allow      = false by default
    // $result     = the integrity result array (status, modified[], missing[], extra[])
    // $hook_extra = the WP upgrader hook_extra (e.g. ['theme' => 'promptingpress'])
    return true; // proceed with the update
}, 10, 3 );
```

### ⏰ The daily check

On theme activation, an idempotent daily event named `pp_daily_integrity_check` is
scheduled; it runs `pp_check_theme_integrity()` so the admin warning reflects current
drift without waiting for an activation, update, or manual CLI run. Switching away from
the theme clears the event and the stored options.

> ℹ️ WP-Cron is traffic-driven, so on a quiet site or one with `DISABLE_WP_CRON` the
> daily refresh can lag. That's fine: the update block always runs a **fresh** check at
> update time, so your safety never depends on cron firing.

### 🔔 Admin notices

Two persistent notices appear in `wp-admin` when relevant:

- **Theme files modified** — shown while `pp_theme_integrity` is `unsafe` or
  `invalid_manifest`.
- **An update was blocked** — shown when `pp_last_blocked_update` is set (a record
  written each time the guard blocks an update, including silent auto-updates, with the
  timestamp and affected-file counts). This record clears itself once an integrity
  check returns `safe`.

### 💾 Where the state lives

| Option | Contents |
|--------|----------|
| `pp_theme_integrity` | `{ status, checked_at, version, modified[], missing[], extra[], error }` |
| `pp_last_blocked_update` | `{ timestamp, status, modified[], missing[], extra[], trigger }` — present only after a block |

---

## 🚑 Your update got blocked — here's what to do

You tried to update PromptingPress and saw something like *"PromptingPress update
blocked: local theme files have changed (2 modified, 1 extra)."* Good news: nothing was
overwritten. That message means the update **would have** erased changes made directly
to theme files, and stopped instead. Here's how to get unblocked safely.

**You'll need:** WP-CLI access (`wp` on the server), or the ability to add a small
site/must-use plugin.

### 1️⃣ See exactly which files drifted

```bash
wp pp integrity check
```

Read the **modified**, **missing**, and **extra** lists. Each path is relative to the
theme root. This is your list of what was about to be lost.

### 2️⃣ Decide what to do with each change

Pick the path that matches your situation:

**🅰️ The changes were accidental, or you don't need them.**
Restore the affected files from the matching release, then update normally:

```bash
# Download the release that matches your installed version, e.g. v0.11.0
# from https://github.com/FJCF76/PromptingPress/releases
# Replace the drifted files (or the whole theme folder) with the release copy,
# then confirm it's clean:
wp pp integrity check   # should now exit 0 (safe)
```

**🅱️ The changes are real customizations you want to keep.**
Move them off the theme files into update-safe storage, because the next update will
replace the theme folder no matter what:

- 🎨 **Colors, fonts, spacing, radius** → set design tokens via the apply layer, which
  stores overrides in the `pp_token_overrides` database option (survives updates). Use
  the `update_design_token` apply for tokens and `enqueue_font` for fonts. From PHP:

  ```php
  pp_execute_apply( 'update_design_token', [ 'token' => '--color-accent', 'value' => '#b45309' ] );
  ```

  For the exact WP-CLI invocations (they run through the operate loop and take a
  `--run-id` token), see [`ai-instructions/retheme.md`](../ai-instructions/retheme.md).

- 🧩 **Page content and layout** → use compositions (stored in post meta), not template
  edits.

- 🏗️ **A genuinely structural change** that tokens and compositions can't express (a new
  template, a changed component) is a **product/release change**, not a site
  customization. That belongs in a theme release, not a hand-edit — open an issue rather
  than editing the file in place.

Once the change lives in tokens/compositions/content, restore the theme file (path 🅰️)
and update.

**🆑 You understand the risk and want to update anyway.**
Override the block with a filter. Add this to a must-use plugin or run it inline; do
**not** add it to the theme (the theme is what's being replaced):

```bash
wp eval "add_filter('pp_allow_unsafe_theme_update','__return_true');"
```

Or as a one-line must-use plugin at `wp-content/mu-plugins/pp-allow-update.php`:

```php
<?php add_filter( 'pp_allow_unsafe_theme_update', '__return_true' );
```

### ✅ Confirm you're clean

After updating, check the install is clean and the warning is gone:

```bash
wp pp integrity check   # exit 0
wp pp integrity status  # status: safe
```

The "update was blocked" admin notice clears itself once a check returns `safe`.

### 🩹 Troubleshooting

- **"integrity cannot be verified" (`invalid_manifest`)** — the shipped manifest is
  corrupt or unreadable. Restore `integrity-manifest.json` from the matching release,
  then re-run `wp pp integrity check`. Until then, updates are blocked because drift
  can't be ruled out; use the override filter (path 🆑) if you must update.
- **An auto-update silently failed** — check the admin notice or
  `wp option get pp_last_blocked_update`. A blocked background auto-update never shows a
  live error, so this stored record is where the reason lives.
- **`extra` files you didn't create** — editor backups (`*.css~`), `Thumbs.db`, or a
  plugin writing into the theme directory all count as drift, because a theme update
  deletes them. Remove the stray file, or override with path 🆑.

---

## 🧠 Why we block updates on local drift

### The problem

A WordPress theme update **replaces the entire theme directory**. Anything hand-edited
under `templates/`, `components/`, or `assets/` is overwritten, and any file added there
is deleted. Before v0.11.0, PromptingPress could *detect* that files had drifted and
show a warning — but nothing stopped the update from running. The warning told you about
the damage after it was done, which on a background auto-update means no human saw it at
all.

### The approach

PromptingPress treats `templates/`, `components/`, and `assets/` as **release
artifacts**: read them to understand how the site renders, but never edit them to
customize a specific site. Customization flows instead through things that live in the
database and survive updates — design tokens (`pp_token_overrides`), enqueued fonts, and
compositions.

```
Site customization (survives updates)        Release artifacts (replaced on update)
────────────────────────────────────         ──────────────────────────────────────
  update_design_token  ──►  pp_token_overrides     templates/*.php
  enqueue_font         ──►  font option            components/*/*.php
  compositions         ──►  post meta              assets/css/*.css, assets/js/*.js
                                                    └─► hashed into integrity-manifest.json
```

The integrity manifest makes "has a release artifact been edited?" a yes/no question.
The `upgrader_pre_install` guard turns that answer into an actual stop: if the active
theme has drifted, the update is aborted with a `WP_Error` before a single file is
written. The daily cron keeps the passive warning honest between updates, and the
last-blocked record gives a silent auto-update a voice in the admin screen.

The override is deliberately one filter, `pp_allow_unsafe_theme_update`, applied
uniformly to both "files drifted" and "can't verify." That keeps the escape hatch
discoverable and consistent: a corrupt manifest fails closed (blocked) like real drift,
but the same documented override frees you, so a bad manifest can never permanently
strand a site on an old version.

### ⚖️ Trade-offs (we made these on purpose)

- **Blocking on `extra` files can false-positive.** A stray `components.css~` backup or a
  plugin writing into the theme directory will block an update. We accept that, because a
  theme update genuinely *deletes* those files — the block is correct even when the file
  is junk. The override filter is the relief valve.
- **`invalid_manifest` fails closed.** When integrity can't be verified, blocking is
  safer than risking a silent overwrite of real work. The cost: a packaging bug in the
  manifest would block updates until it's restored — mitigated by the override filter.
- **Cron freshness isn't guaranteed.** The passive admin warning can lag on quiet or
  `DISABLE_WP_CRON` sites. The block doesn't depend on it: it always re-checks at update
  time.
- **The guard is theme-level.** It protects the active PromptingPress theme on a single
  site. It doesn't cover paths where the theme code isn't loaded (an inactive theme, some
  multisite network-admin flows, or an upload-overwrite of a theme that isn't active).
  Those are out of scope for v0.11.0.

### 🔭 Out of scope for v0.11.0 (on purpose)

Child-theme or runtime override support, ZIP backup of drifted files before update, and
multisite-aware blocking aren't included yet. The "STOP and escalate" instruction for
structural changes is the v1 answer; a supported override layer is a tracked follow-up.

---

## 🔗 Related

- 📄 [`README.md`](../README.md) — project overview and the full WP-CLI surface.
- 🔒 [`AI_RULES.md`](../AI_RULES.md) — "Parent-theme files are inspect-only for site customization."
- 🧭 [`AI_CONTEXT.md`](../AI_CONTEXT.md) — site map and the file-responsibility table.
- 🎨 [`ai-instructions/retheme.md`](../ai-instructions/retheme.md) — the apply/DB retheme path.
- 📝 [`CHANGELOG.md`](../CHANGELOG.md) — the v0.11.0 release notes.
