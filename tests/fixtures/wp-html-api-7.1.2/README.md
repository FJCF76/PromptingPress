# WordPress 7.1.2 HTML API (test fixture)

Verbatim copies of `wp-includes/html-api/*.php` and `wp-includes/class-wp-token-map.php`
from WordPress 7.1.2 (`$wp_version = '7.1.2'`, the version production runs; downloaded from
github.com/WordPress/WordPress tag 7.1.2).
GPL-2.0-or-later, the same licence as the theme.

Test-only (`tests/` is excluded from the release zip by `.distignore`). `tests/bootstrap.php`
loads these classes so `lib/content.php` (the Layer 3A content predicate, #1242 T3a) runs
against the real HTML5 tree builder in PHPUnit. In production the theme uses the classes
WordPress itself ships (`Requires at least: 7.0`).

When `.wp-env.json` moves to a new WordPress version, refresh these files from that
version and re-read the P-16 bail-set pin (`tests/fixtures/content/p16-bail-set-wp-<version>.json`);
`tests/ContentTableDriftTest.php` fails until both agree.
