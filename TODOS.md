# TODOs

## P3

- **Configurable backup directory** — Allow `PP_BACKUP_DIR` constant or WP option to override the hardcoded `wp-content/pp-backups` path. Operators on restrictive hosting may not be able to write there. See `_pp_backup_dir()` in `lib/apply.php`.

- **`wp pp target set` for multi-environment** — Manual override of auto-detected target config. Currently `wp pp target show` derives target info from live WP state (`get_option('siteurl')`, `get_template_directory()`, `ABSPATH`) without persisting anything. A `set` command would store an explicit override and enable staging/production disambiguation for operators managing multiple environments. Depends on a concrete workflow that auto-derived target discovery can't serve (see GitHub issue #49).
