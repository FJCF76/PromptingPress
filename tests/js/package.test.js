import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { execSync } from 'child_process';
import { existsSync, unlinkSync } from 'fs';
import { resolve } from 'path';

const ROOT = resolve(import.meta.dirname, '../..');

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
