# Screenshot capture setup (`PP_BROWSER_CMD`)

PromptingPress's operating loop wants visual proof before a change is marked
`VERIFIED`. Native capture (`wp pp screenshot capture`) does not bundle a browser —
it delegates to a browser command you configure, so you stay in control of which
tool runs and how. This page explains how to wire it up and how to diagnose it.

## The contract

PromptingPress calls your adapter with exactly this shape:

```
PP_BROWSER_CMD <url> --width=<px> --height=<px> --output=<path>
```

Your adapter must:

- load `<url>`,
- render at the given `--width` / `--height`,
- write a PNG to `--output`,
- exit `0` on success (non-zero is treated as a capture failure).

Any tool that can be wrapped to honor that shape works (Playwright, Chromium
headless, a script around your own service). PromptingPress does not bless or
require any specific tool.

## Configuring it

Resolution order is **constant first, then environment variable**:

1. **PHP constant** in `wp-config.php` (visible to both web PHP and WP-CLI):

   ```php
   define( 'PP_BROWSER_CMD', '/usr/local/bin/pp-shot' );
   ```

2. **Environment variable** visible to the context that runs the capture:

   ```bash
   export PP_BROWSER_CMD="/usr/local/bin/pp-shot"
   ```

> Context matters. `wp` on the CLI and web PHP (PHP-FPM) often have different
> environments. If you set the env var for your shell but run capture through the
> web server, the web context may not see it. When in doubt, use the `wp-config.php`
> constant — it is visible to both. `wp pp screenshot doctor` reports which context
> it tested.

## Diagnosing it

```bash
wp pp screenshot doctor          # capability check: does PP_BROWSER_CMD resolve, and from where?
wp pp screenshot doctor --probe  # also runs a real minimal capture to confirm the adapter works
```

`doctor` is read-only and never mutates the site. It reports `ready`, the `source`
(`constant` / `env`), the `context` (`cli` / `web`), and remediation when not ready.
It exits `1` when not ready, so you can gate scripts on it.

## What happens when it is not configured

- **Preflight** (`wp pp apply preflight`) surfaces a **non-blocking warning**:
  screenshot readiness is advisory. A missing browser does **not** block typed
  content/composition mutations — browser availability is an environment capability,
  not a prerequisite for a safe typed change.
- **Capture** (`wp pp screenshot capture`) returns an explicit status, matching the
  operating model (`ai-instructions/operating-loop.md`):
  - **`NEEDS_VISUAL_VERIFICATION`** when `PP_BROWSER_CMD` is not configured (capture was
    never attempted, `no_browser` detail included), and
  - **`SCREENSHOT_FAILED`** when the browser is configured but the capture itself fails.

  Either way the run must **not** be reported as natively `VERIFIED`, and the outcome is
  never silently downgraded — it carries an explicit status.

You may still gather visual evidence by other means (manual, CI, external tooling),
but PromptingPress does not assume or require any particular alternate tool.
