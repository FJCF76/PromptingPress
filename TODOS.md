# TODOs

## P3

- **Configurable backup directory** — Allow `PP_BACKUP_DIR` constant or WP option to override the hardcoded `wp-content/pp-backups` path. Operators on restrictive hosting may not be able to write there. See `_pp_backup_dir()` in `lib/apply.php`.

- **`wp pp target set` for multi-environment** — Manual override of auto-detected target config. Currently `wp pp target show` derives target info from live WP state (`get_option('siteurl')`, `get_template_directory()`, `ABSPATH`) without persisting anything. A `set` command would store an explicit override and enable staging/production disambiguation for operators managing multiple environments. Depends on a concrete workflow that auto-derived target discovery can't serve (see GitHub issue #49).

- **Evaluate `--measure-centered` value** — After visual validation on dev, test whether centered section body can tighten from `56rem` to a ch-based value (e.g., `75ch` or `80ch`). The `--measure-centered` token in `assets/css/base.css` was set to `56rem` as a safe default (preserves existing layout). A tighter value may produce better reading measure for centered marketing intros, but needs visual validation first. See issue #53.
