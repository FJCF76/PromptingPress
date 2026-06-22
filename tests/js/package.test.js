import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { execSync } from 'child_process';
import { existsSync, unlinkSync, readFileSync } from 'fs';
import { resolve } from 'path';

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
    'README.md badge': () => read('README.md').match(/version-(\d+\.\d+\.\d+)/)?.[1],
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
