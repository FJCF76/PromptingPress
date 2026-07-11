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

  // #261: `unzip -Z1` (and `unzip -l`) only read the archive's CENTRAL
  // DIRECTORY. A build whose compressed payload is byte-damaged but whose
  // central directory survived listed clean, passed validation, and would be
  // uploaded as a release asset by release.yml. Only `unzip -t` inflates the
  // payload and checks it against its stored CRC.
  //
  // Corrupt the payload of a NAMED entry, in place, leaving every header
  // intact:
  //
  //   [PK\x03\x04][hdr 30B][name][extra][>>> payload — flip these <<<] ...
  //                 ^ compressed size at +18            ^ length from that field
  //
  // Locating the entry by name (not "the first local header", which can be the
  // directory entry) and taking the payload length from the header's own
  // compressed-size field is what keeps the flip provably inside the payload
  // rather than running into the next header or the central directory.
  const makeCorruptPayloadZip = (name, entries, target) => {
    const zipPath = makeZip(name, entries);
    const buf = readFileSync(zipPath);
    const nameBuf = Buffer.from(target, 'utf-8');

    let payloadStart = -1;
    let payloadLen = 0;
    for (let i = 0; i + 30 <= buf.length; i++) {
      if (buf.readUInt32LE(i) !== 0x04034b50) continue; // local file header
      const compressedSize = buf.readUInt32LE(i + 18);
      const nameLen = buf.readUInt16LE(i + 26);
      const extraLen = buf.readUInt16LE(i + 28);
      const entryName = buf.subarray(i + 30, i + 30 + nameLen);
      if (!entryName.equals(nameBuf)) continue;
      payloadStart = i + 30 + nameLen + extraLen;
      payloadLen = compressedSize;
      break;
    }

    // A zero compressed size means the writer streamed this entry and pushed
    // the size into a trailing data descriptor, so the offsets above would be
    // corrupting nothing. Fail loudly instead of producing a fixture that
    // silently isn't corrupt.
    expect(payloadStart, `no local header for ${target}`).toBeGreaterThan(-1);
    expect(payloadLen, `${target} has no in-header payload to corrupt`).toBeGreaterThan(0);
    expect(payloadStart + payloadLen).toBeLessThanOrEqual(buf.length);

    for (let i = payloadStart; i < payloadStart + payloadLen; i++) buf[i] ^= 0xff;
    writeFileSync(zipPath, buf);

    // Guard the fixture itself: if this archive ever stops being corrupt, the
    // tests below would pass for the wrong reason. rc >= 2 is a real
    // integrity failure (rc 1 is only a warning).
    const probe = spawnSync('unzip', ['-tqq', zipPath], { encoding: 'utf-8' });
    expect(probe.status, 'fixture is not actually payload-corrupt').toBeGreaterThanOrEqual(2);

    // ...and the central directory must still read clean, or this would be
    // testing the plain unreadable-archive path that already had coverage.
    const listing = spawnSync('unzip', ['-Z1', zipPath], { encoding: 'utf-8' });
    expect(listing.status, 'central directory should still be readable').toBe(0);
    expect(listing.stdout).toContain(target);

    return zipPath;
  };

  it('rejects an archive whose payload is corrupt but whose central directory is intact', () => {
    const zip = makeCorruptPayloadZip(
      'corrupt-payload',
      {
        'promptingpress/style.css': '/* theme */\n'.repeat(64),
        'promptingpress/index.php': '<?php\n'.repeat(64),
      },
      'promptingpress/style.css',
    );
    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/failed its integrity check/);
    // The whole point of #261: this must NOT be waved through as a good build.
    // Before the integrity branch existed, this exact archive exited 0.
    expect(stderr).not.toMatch(/style\.css missing/);
    expect(stderr).not.toMatch(/could not read/);
  });

  it('reports which entry failed the integrity check', () => {
    const zip = makeCorruptPayloadZip(
      'corrupt-detail',
      { 'promptingpress/style.css': '/* theme */\n'.repeat(64) },
      'promptingpress/style.css',
    );
    const { stderr } = run(zip);
    // The validator must name the damaged entry, not just say "corrupt". A
    // bare error with no diagnosis attached is the failure #260 was about,
    // and `unzip -tqq` produces exactly that on some corruptions — which is
    // why the script uses -tq.
    expect(stderr).toContain('--- unzip -t report ---');
    expect(stderr).toContain('promptingpress/style.css');
  });

  // Corrupt a file's payload while leaving style.css itself intact. This is
  // the realistic shape of a damaged build: every required entry is present
  // and style.css reads fine, so every central-directory check passes and
  // only `unzip -t` can tell that the archive is broken.
  it('rejects an archive whose style.css is fine but another entry is damaged', () => {
    const zip = makeCorruptPayloadZip(
      'corrupt-sibling',
      {
        'promptingpress/style.css': '/* theme */\n'.repeat(64),
        'promptingpress/index.php': '<?php\n'.repeat(64),
      },
      'promptingpress/index.php',
    );
    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/failed its integrity check/);
    expect(stderr).toContain('promptingpress/index.php');
  });

  // Regression pin for the fail-closed threshold (#261).
  //
  // Flip the compression-method field in an entry's local header and unzip
  // SKIPS that entry: it is never inflated, never CRC-checked. `unzip -t`
  // reports rc 1 ("warning"), NOT rc 2 — so a gate keyed on rc >= 2 waves the
  // build through, and the entry extracts as ZERO BYTES. A theme whose
  // style.css is empty is a dead theme, shipped as a good release.
  //
  // rc 1 here means "could not verify", not "verified good". Anything but a
  // clean rc 0 on a non-empty archive must fail.
  const makeUnverifiableZip = (name, entries, target) => {
    const zipPath = makeZip(name, entries);
    const buf = readFileSync(zipPath);
    const nameBuf = Buffer.from(target, 'utf-8');

    let flipped = false;
    for (let i = 0; i + 30 <= buf.length; i++) {
      if (buf.readUInt32LE(i) !== 0x04034b50) continue;
      const nameLen = buf.readUInt16LE(i + 26);
      if (!buf.subarray(i + 30, i + 30 + nameLen).equals(nameBuf)) continue;
      buf.writeUInt16LE(20, i + 8); // compression method unzip cannot handle
      flipped = true;
      break;
    }
    expect(flipped, `no local header for ${target}`).toBe(true);
    writeFileSync(zipPath, buf);

    // The archive must still LOOK fine to every central-directory check, or
    // this would be testing some other failure path.
    const listing = spawnSync('unzip', ['-Z1', zipPath], { encoding: 'utf-8' });
    expect(listing.status).toBe(0);
    expect(listing.stdout).toContain(target);

    // And unzip must report it as a skip (rc 1), not a hard error — that is
    // precisely the case a rc>=2 threshold would let through.
    const probe = spawnSync('unzip', ['-tq', zipPath], { encoding: 'utf-8' });
    expect(probe.status, 'fixture should trip the rc-1 skip path').toBe(1);

    return zipPath;
  };

  it('rejects an archive with an entry unzip cannot verify (skipped, not CRC-checked)', () => {
    const zip = makeUnverifiableZip(
      'unverifiable',
      {
        'promptingpress/style.css': '/* theme */\n'.repeat(64),
        'promptingpress/index.php': '<?php\n'.repeat(64),
      },
      'promptingpress/style.css',
    );

    // Prove the stakes: this "valid-looking" archive yields an EMPTY style.css.
    const extracted = spawnSync('unzip', ['-p', zip, 'promptingpress/style.css'], {
      encoding: 'buffer',
    });
    expect(extracted.stdout.length, 'style.css should extract as 0 bytes').toBe(0);

    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/failed its integrity check/);
    expect(stderr).toContain('promptingpress/style.css');
  });

  // Ordering is load-bearing (see the diagram in validate-zip.sh). Membership
  // runs BEFORE integrity, so an archive that is both corrupt and missing
  // style.css reports the missing file. That ordering is what lets the
  // integrity check fail closed on any nonzero rc: an empty archive returns
  // the same rc 1 as an unverified entry, and membership has already rejected
  // it by then, so integrity never has to tell those two apart.
  it('reports a missing style.css ahead of payload corruption', () => {
    const zip = makeCorruptPayloadZip(
      'corrupt-and-missing',
      { 'promptingpress/index.php': '<?php\n'.repeat(64) },
      'promptingpress/index.php',
    );
    const { status, stderr } = run(zip);
    expect(status).toBe(1);
    expect(stderr).toMatch(/style\.css missing/);
    expect(stderr).not.toMatch(/failed its integrity check/);
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
