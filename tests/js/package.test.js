import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { execSync, spawnSync } from 'child_process';
import { existsSync, unlinkSync, readFileSync, writeFileSync, mkdtempSync, mkdirSync, rmSync } from 'fs';
import { resolve, join } from 'path';
import { tmpdir } from 'os';

const ROOT = resolve(import.meta.dirname, '../..');

describe('version consistency', () => {
  const read = (rel) => readFileSync(resolve(ROOT, rel), 'utf-8');
  const SEMVER = /(\d+\.\d+\.\d+)/;

  const styleVersion = read('style.css').match(/^Version:\s*(\d+\.\d+\.\d+)/m)?.[1];

  it('style.css declares a version', () => {
    expect(styleVersion).toMatch(SEMVER);
  });

  // Authoritative gate lives in scripts/package.sh; this mirrors the 5-file
  // invariant at the unit-test layer so drift fails fast with a clear message
  // on every push (package.test.js runs in the CI `tests` job).
  const sources = {
    'functions.php PP_VERSION': () =>
      read('functions.php').match(/define\('PP_VERSION',\s*'(\d+\.\d+\.\d+)'/)?.[1],
    'package.json version': () => read('package.json').match(/"version":\s*"(\d+\.\d+\.\d+)"/)?.[1],
    'README.md badge': () => read('README.md').match(/badge\/version-(\d+\.\d+\.\d+)/)?.[1],
    'readme.txt Stable tag': () => read('readme.txt').match(/^Stable tag:\s*(\d+\.\d+\.\d+)/m)?.[1],
  };

  for (const [label, get] of Object.entries(sources)) {
    it(`${label} matches style.css (${styleVersion})`, () => {
      expect(get()).toBe(styleVersion);
    });
  }
});

describe('package.sh smoke test', () => {
  let zipName;

  beforeAll(() => {
    // Run package.sh from the project root
    const output = execSync('bash scripts/package.sh', {
      cwd: ROOT,
      encoding: 'utf-8',
      timeout: 30_000,
    });
    // Extract ZIP filename from output
    const match = output.match(/Built (promptingpress-[\d.]+\.zip)/);
    expect(match).toBeTruthy();
    zipName = match[1];
  }, 60_000);

  afterAll(() => {
    if (zipName) {
      const zipPath = resolve(ROOT, zipName);
      if (existsSync(zipPath)) {
        unlinkSync(zipPath);
      }
    }
  });

  it('produces a ZIP file', () => {
    expect(existsSync(resolve(ROOT, zipName))).toBe(true);
  });

  it('has a single top-level promptingpress/ directory', () => {
    const listing = execSync(`unzip -l "${zipName}"`, {
      cwd: ROOT,
      encoding: 'utf-8',
    });
    // Every file path should start with promptingpress/
    const filePaths = listing
      .split('\n')
      .filter(line => line.match(/^\s+\d+/))
      .map(line => line.trim().split(/\s+/).pop())
      .filter(p => p && !p.match(/^\d+\s+files?$/) && p !== 'files');
    for (const p of filePaths) {
      expect(p).toMatch(/^promptingpress\//);
    }
  });

  it('includes required files', () => {
    const listing = execSync(`unzip -l "${zipName}"`, {
      cwd: ROOT,
      encoding: 'utf-8',
    });
    const requiredFiles = [
      'promptingpress/style.css',
      'promptingpress/index.php',
      'promptingpress/functions.php',
      'promptingpress/readme.txt',
      'promptingpress/LICENSE',
      'promptingpress/comments.php',
    ];
    for (const file of requiredFiles) {
      expect(listing).toContain(file);
    }
  });

  it('excludes dev artifacts', () => {
    const listing = execSync(`unzip -l "${zipName}"`, {
      cwd: ROOT,
      encoding: 'utf-8',
    });
    const excludedPatterns = [
      'node_modules/',
      '.git/',
      '.github/',
      'tests/',
      'vendor/',
      'composer.json',
      'package.json',
      'scripts/',
      '.distignore',
      '.gitignore',
      '.wp-env.json',
      'CLAUDE.md',
      'phpunit.xml',
      'vitest.config.js',
    ];
    for (const pattern of excludedPatterns) {
      expect(listing).not.toContain(`promptingpress/${pattern}`);
    }
  });

  it('does not contain hidden files', () => {
    const listing = execSync(`unzip -l "${zipName}"`, {
      cwd: ROOT,
      encoding: 'utf-8',
    });
    const filePaths = listing
      .split('\n')
      .filter(line => line.match(/^\s+\d+/))
      .map(line => line.trim().split(/\s+/).pop())
      .filter(p => p && !p.match(/^\d+ files?$/));
    const hidden = filePaths.filter(p => {
      const parts = p.split('/');
      // Check segments after "promptingpress/" for hidden files/dirs
      return parts.slice(1).some(seg => seg.startsWith('.') && seg !== '');
    });
    expect(hidden).toEqual([]);
  });
});

// The ZIP-validation step used to report an unreadable archive and a genuinely
// missing style.css with the same message, and printed nothing about what the
// archive held — which produced a wrong root-cause diagnosis on a real CI
// failure (#260). package.sh can't be made to emit either kind of bad archive,
// so the check lives in scripts/validate-zip.sh and is exercised directly here.
describe('validate-zip.sh', () => {
  let workDir;

  const validator = resolve(ROOT, 'scripts/validate-zip.sh');

  const run = (...args) => {
    const r = spawnSync('bash', [validator, ...args], { encoding: 'utf-8' });
    return { status: r.status, stderr: r.stderr };
  };

  // Build a ZIP whose entries are exactly `entries` (path → contents).
  const makeZip = (name, entries) => {
    const staging = join(workDir, `${name}-staging`);
    mkdirSync(staging, { recursive: true });
    for (const [path, contents] of Object.entries(entries)) {
      const full = join(staging, path);
      mkdirSync(resolve(full, '..'), { recursive: true });
      writeFileSync(full, contents);
    }
    const zipPath = join(workDir, `${name}.zip`);
    const z = spawnSync('zip', ['-qr', zipPath, '.'], { cwd: staging });
    expect(z.status).toBe(0);
    return zipPath;
  };

  beforeAll(() => {
    workDir = mkdtempSync(join(tmpdir(), 'pp-validate-zip-'));
  });

  afterAll(() => {
    rmSync(workDir, { recursive: true, force: true });
  });

  it('passes a ZIP containing promptingpress/style.css', () => {
    const zip = makeZip('good', {
      'promptingpress/style.css': '/* theme */',
      'promptingpress/index.php': '<?php',
    });
    const { status, stderr } = run(zip);
    expect(status).toBe(0);
    expect(stderr).toBe('');
  });

  it('reports an unreadable archive distinctly from a missing style.css', () => {
    const corrupt = join(workDir, 'corrupt.zip');
    writeFileSync(corrupt, 'this is not a zip archive');
    const { status, stderr } = run(corrupt);
    expect(status).toBe(1);
    expect(stderr).toMatch(/could not read/);
    // The whole point of #260: an archive-read failure must NOT masquerade
    // as a missing file, and there is no listing to show.
    expect(stderr).not.toMatch(/style\.css missing/);
    expect(stderr).not.toContain('--- archive contents ---');
  });

  it('reports a nonexistent archive as unreadable, not as a missing style.css', () => {
    const { status, stderr } = run(join(workDir, 'does-not-exist.zip'));
    expect(status).toBe(1);
    expect(stderr).toMatch(/could not read/);
    expect(stderr).not.toMatch(/style\.css missing/);
    expect(stderr).not.toContain('--- archive contents ---');
  });

  // unzip globs its archive argument and retries it with a .zip suffix, so a
  // path that does not exist can otherwise resolve to a DIFFERENT archive and
  // report success on a build nobody examined.
  it('does not resolve a nonexistent path to another archive by glob or suffix', () => {
    makeZip('decoy', { 'promptingpress/style.css': '/* decoy */' });
    for (const arg of ['deco?.zip', 'decoy']) {
      const r = spawnSync('bash', [validator, arg], { cwd: workDir, encoding: 'utf-8' });
      expect(r.status).toBe(1);
      expect(r.stderr).toMatch(/could not read/);
    }
  });

  it('dumps the archive contents when style.css is genuinely missing', () => {
    const zip = makeZip('no-style', {
      'promptingpress/index.php': '<?php',
      'promptingpress/functions.php': '<?php',
    });
    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/style\.css missing/);
    expect(stderr).not.toMatch(/could not read/);
    // Without the listing, the last occurrence cost hours of guessing.
    expect(stderr).toContain('promptingpress/index.php');
    expect(stderr).toContain('promptingpress/functions.php');
  });

  // An empty archive is readable and has no style.css, so it is a missing-file
  // failure. unzip exits 1 ("Empty zipfile.") on it, and treating that exit as
  // a read failure would be #260's conflation running the other way.
  it('treats an empty archive as a missing style.css, not an unreadable one', () => {
    const empty = join(workDir, 'empty.zip');
    // End-of-central-directory record with zero entries: a valid, empty ZIP.
    writeFileSync(empty, Buffer.from('504b0506' + '00'.repeat(18), 'hex'));
    const { status, stderr } = run(empty);
    expect(status).toBe(1);
    expect(stderr).toMatch(/style\.css missing/);
    expect(stderr).not.toMatch(/could not read/);
    expect(stderr).toContain('--- archive contents ---');
  });

  it('does not accept a look-alike suffix (style.css.map) as style.css', () => {
    const zip = makeZip('suffix', {
      'promptingpress/style.css.map': '{}',
    });
    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/style\.css missing/);
  });

  it('does not accept style.css nested under another top-level directory', () => {
    const zip = makeZip('nested', {
      'foo/promptingpress/style.css': '/* wrong place */',
    });
    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/style\.css missing/);
  });

  it('fails with usage when the archive path is missing or ambiguous', () => {
    for (const args of [[], ['a.zip', 'b.zip']]) {
      const r = spawnSync('bash', [validator, ...args], { encoding: 'utf-8' });
      expect(r.status).toBe(1);
      expect(r.stderr).toMatch(/usage/);
    }
  });

  it('is the check package.sh actually runs', () => {
    const pkg = readFileSync(resolve(ROOT, 'scripts/package.sh'), 'utf-8');
    // Pin the real invocation, not a mention: a comment naming the file, or a
    // deleted validation step, must not satisfy this.
    expect(pkg).toMatch(/^\s*bash "\$THEME_DIR\/scripts\/validate-zip\.sh" "\$ZIP_NAME"\s*$/m);
    // The conflated pipeline this replaced must not creep back in.
    expect(pkg).not.toMatch(/unzip -l .* \| *grep -q .*style\.css/);
  });
});
