import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * The content write tier under a real WP-CLI run on live core, with and without
 * DISALLOW_UNFILTERED_HTML (tests/e2e/fixtures/tier-probe.php).
 *
 * The constant is defined for ONE run only, through WP-CLI's global `--exec` (which runs
 * before WordPress loads, as a wp-config.php line would), so the rest of the suite's
 * install is untouched.
 *
 *   hardened  core denies unfiltered_html to the administrator (map_meta_cap()), and the
 *             CLI write gets the same core parity: the widened construct is refused by
 *             name, the core one lands, and nothing is vouched for the widened set.
 *   default   no constant: the CLI write tier is full, as before.
 */

type Probe = {
  wp_cli: boolean;
  disallow: boolean;
  user_can: boolean;
  tier: string;
  widened_ok: boolean;
  widened_unfiltered_html: boolean;
  widened_vouched: boolean;
  parity_ok: boolean;
  parity_vouched: boolean;
};

function probe(hardened: boolean): Probe {
  const exec = hardened ? `--exec='define("DISALLOW_UNFILTERED_HTML", true);' ` : '';
  const php = 'require get_template_directory() . "/tests/e2e/fixtures/tier-probe.php"; echo pp_tier_probe();';
  const out = execSync(`npx wp-env run cli wp ${exec}eval '${php}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  }).trim();
  return JSON.parse(out.slice(out.indexOf('{'), out.lastIndexOf('}') + 1));
}

test.describe('Content write tier under WP-CLI', () => {
  test('DISALLOW_UNFILTERED_HTML: a CLI write gets core parity, like every user', () => {
    expect(probe(true)).toEqual({
      wp_cli: true,
      disallow: true,
      user_can: false,
      tier: 'core',
      widened_ok: false,
      widened_unfiltered_html: true,
      widened_vouched: false,
      parity_ok: true,
      parity_vouched: false,
    });
  });

  test('without the constant a CLI write keeps the full tier', () => {
    expect(probe(false)).toEqual({
      wp_cli: true,
      disallow: false,
      user_can: true,
      tier: 'full',
      widened_ok: true,
      widened_unfiltered_html: false,
      widened_vouched: true,
      parity_ok: true,
      parity_vouched: true,
    });
  });
});
