#!/usr/bin/env bash
# scripts/package.sh — Build a distributable ZIP for PromptingPress
#
# Usage: bash scripts/package.sh
#   or:  npm run package
#
# Produces: promptingpress-{version}.zip in the project root.

set -euo pipefail

THEME_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$THEME_DIR"

# ── 1. Extract version from style.css ────────────────────────────────────
VERSION=$(grep -m1 '^Version:' style.css | sed 's/Version:[[:space:]]*//' | tr -d '[:space:]')
if [[ -z "$VERSION" ]]; then
    echo "ERROR: Could not extract Version from style.css" >&2
    exit 1
fi
echo "Version: $VERSION"

# ── 2. Version consistency check ─────────────────────────────────────────
# style.css must match functions.php PP_VERSION, package.json version, the
# README.md version badge, and the readme.txt Stable tag. This single gate
# runs at package time, so it also guards `npm run package`, the push CI job
# (package.test.js runs this script), and the release workflow (release.yml
# runs this script before uploading the ZIP) — no duplicated check elsewhere.

PP_VERSION=$(grep -m1 "define('PP_VERSION'" functions.php | grep -oP "'[0-9]+\.[0-9]+\.[0-9]+'" | tr -d "'")
PKG_VERSION=$(grep -m1 '"version"' package.json | grep -oP '[0-9]+\.[0-9]+\.[0-9]+')
README_VERSION=$(grep -m1 -oP 'badge/version-\K[0-9]+\.[0-9]+\.[0-9]+' README.md)
READMETXT_VERSION=$(grep -m1 -oP '^Stable tag:[[:space:]]*\K[0-9]+\.[0-9]+\.[0-9]+' readme.txt)

MISMATCH=0
if [[ "$VERSION" != "$PP_VERSION" ]]; then
    echo "ERROR: style.css Version ($VERSION) != functions.php PP_VERSION ($PP_VERSION)" >&2
    MISMATCH=1
fi
if [[ "$VERSION" != "$PKG_VERSION" ]]; then
    echo "ERROR: style.css Version ($VERSION) != package.json version ($PKG_VERSION)" >&2
    MISMATCH=1
fi
if [[ "$VERSION" != "$README_VERSION" ]]; then
    echo "ERROR: style.css Version ($VERSION) != README.md badge version ($README_VERSION)" >&2
    MISMATCH=1
fi
if [[ "$VERSION" != "$READMETXT_VERSION" ]]; then
    echo "ERROR: style.css Version ($VERSION) != readme.txt Stable tag ($READMETXT_VERSION)" >&2
    MISMATCH=1
fi
if [[ "$MISMATCH" -eq 1 ]]; then
    exit 1
fi
echo "Version consistency: OK"

# ── 3. Composer production dependency guard ──────────────────────────────
# PromptingPress must not ship Composer production deps. If composer.json
# has a non-empty "require" section, something changed and we must stop.
if grep -qP '"require"\s*:\s*\{' composer.json 2>/dev/null; then
    # Check it's not just "require-dev"
    if python3 -c "
import json, sys
with open('composer.json') as f:
    data = json.load(f)
if 'require' in data and data['require']:
    sys.exit(1)
" 2>/dev/null; then
        : # No production deps — OK
    else
        echo "ERROR: composer.json has production dependencies (require section)." >&2
        echo "       PromptingPress must not ship vendor/. Remove the dependency or" >&2
        echo "       bundle the code directly." >&2
        exit 1
    fi
fi
echo "Composer guard: OK"

# ── 4. Build the ZIP ─────────────────────────────────────────────────────
STAGING=$(mktemp -d)
trap 'rm -rf "$STAGING"' EXIT
DEST="$STAGING/promptingpress"
ZIP_NAME="promptingpress-${VERSION}.zip"

# rsync with .distignore exclusions
rsync -a --exclude-from=.distignore ./ "$DEST/"

# Remove any leftover hidden files the catch-all might miss
find "$DEST" -name '.*' -not -name '.' -not -name '..' -exec rm -rf {} + 2>/dev/null || true

# ── 4b. Generate integrity manifest ─────────────────────────────────
# Hash every file in the staged package directory BEFORE writing the
# manifest, so the manifest itself is not included in the hash set.
MANIFEST="$DEST/integrity-manifest.json"
{
    echo '{'
    echo "  \"version\": \"$VERSION\","
    echo "  \"generated\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo '  "file_hashes": {'
    FIRST=1
    while IFS= read -r -d '' file; do
        relative="${file#"$DEST/"}"
        hash=$(md5sum "$file" | cut -d' ' -f1)
        if [ "$FIRST" -eq 1 ]; then
            FIRST=0
        else
            echo ','
        fi
        printf '    "%s": "%s"' "$relative" "$hash"
    done < <(find "$DEST" -type f -not -name 'integrity-manifest.json' -print0 | sort -z)
    echo ''
    echo '  }'
    echo '}'
} > "$MANIFEST"
echo "Integrity manifest: $(grep -c '": "' "$MANIFEST") files hashed"

# Create the ZIP
(cd "$STAGING" && zip -qr "$THEME_DIR/$ZIP_NAME" promptingpress/)

# ── 5. Validate ──────────────────────────────────────────────────────────
# style.css must be in the ZIP. Delegated so the archive-read failure and the
# missing-file failure report distinctly, and so both are testable (#260).
bash "$THEME_DIR/scripts/validate-zip.sh" "$ZIP_NAME"

# Must have a single top-level directory
TOP_DIRS=$(unzip -l "$ZIP_NAME" | awk '/\/$/ && NF>=4 {print $4}' | grep -c '^[^/]*/$' || true)
if [[ "$TOP_DIRS" -ne 1 ]]; then
    echo "ERROR: ZIP does not have a single top-level directory" >&2
    exit 1
fi

# Size check (< 5MB)
SIZE=$(stat -c%s "$ZIP_NAME" 2>/dev/null || stat -f%z "$ZIP_NAME" 2>/dev/null)
SIZE_KB=$((SIZE / 1024))
if [[ "$SIZE" -gt 5242880 ]]; then
    echo "ERROR: ZIP is ${SIZE_KB}KB — exceeds 5MB limit" >&2
    exit 1
fi

FILE_COUNT=$(unzip -l "$ZIP_NAME" | tail -1 | awk '{print $2}')

# ── 6. Report ────────────────────────────────────────────────────────────
# Cleanup handled by EXIT trap

echo ""
echo "✓ Built $ZIP_NAME"
echo "  Size:  ${SIZE_KB}KB"
echo "  Files: ${FILE_COUNT}"
