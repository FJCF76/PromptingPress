#!/usr/bin/env bash
# scripts/validate-zip.sh — Assert a built theme ZIP contains promptingpress/style.css
#
# Usage: bash scripts/validate-zip.sh <zip-path>
#
# Split out of package.sh so the two failure modes below can be exercised
# directly by tests/js/package.test.js: a real package.sh build can never be
# made to emit a corrupt archive or one missing style.css.
#
# The single check that lived here previously reported an archive-read failure
# and a genuinely-missing style.css with the same message, and printed nothing
# about what the archive did contain. Under `set -o pipefail`, a nonzero
# `unzip` in `unzip -l "$ZIP" | grep -q ...` made the pipeline nonzero, so `!`
# took the missing-file branch either way. That conflation produced a wrong
# root-cause diagnosis on a real CI failure (#260).
#
#   unreadable    → "could not read" + unzip's own error + exit 1
#   missing file  → "style.css missing" + entry listing + exit 1
#   present       → silent, exit 0
#
# Membership is an exact match on the entry list (`unzip -Z1` + `grep -Fxq`),
# not a substring search of the human-readable `unzip -l` table: an unanchored
# regex would also accept promptingpress/style.css.map or foo/promptingpress/style.css.
#
# Only the style.css check lives here. The single-top-level-directory and size
# checks stay in package.sh because they feed values into its final report.

set -euo pipefail

ZIP="${1:-}"
if [[ $# -ne 1 || -z "$ZIP" ]]; then
    echo "ERROR: usage: validate-zip.sh <zip-path>" >&2
    exit 1
fi

# unzip treats its archive argument as a glob and retries it with a .zip/.ZIP
# suffix, so a path that does not exist can still resolve to some OTHER archive
# and report success. Reject anything that is not literally this file, and keep
# a leading dash from being parsed as an option.
if [[ ! -f "$ZIP" ]]; then
    echo "ERROR: could not read $ZIP (no such file)" >&2
    exit 1
fi
[[ "$ZIP" == -* ]] && ZIP="./$ZIP"

ERR_FILE=$(mktemp)
trap 'rm -f "$ERR_FILE"' EXIT

# `unzip -Z1` lists one entry path per line, stdout only. Exit 0 is clean and 1
# is a warning (an empty archive is rc 1, "Empty zipfile." on stderr) — both
# mean the archive was READ. Only rc >= 2 is a genuine read failure. An empty
# archive is a missing-style.css case, not a read failure: routing it here is
# what the old code got wrong, in the other direction.
set +e
ENTRIES=$(unzip -Z1 "$ZIP" 2>"$ERR_FILE")
RC=$?
set -e

if [[ "$RC" -ge 2 ]]; then
    echo "ERROR: could not read $ZIP (unzip failed, exit $RC)" >&2
    cat "$ERR_FILE" >&2
    exit 1
fi

if ! grep -Fxq -- 'promptingpress/style.css' <<<"$ENTRIES"; then
    echo "ERROR: style.css missing from $ZIP" >&2
    echo "--- archive contents ---" >&2
    printf '%s\n' "$ENTRIES" >&2
    exit 1
fi
