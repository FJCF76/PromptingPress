#!/usr/bin/env bash
# scripts/validate-zip.sh — Assert a built theme ZIP is intact and contains
# promptingpress/style.css
#
# Usage: bash scripts/validate-zip.sh <zip-path>
#
# Split out of package.sh so the failure modes below can be exercised directly
# by tests/js/package.test.js: a real package.sh build can never be made to
# emit a corrupt archive or one missing style.css.
#
# The single check that lived here previously reported an archive-read failure
# and a genuinely-missing style.css with the same message, and printed nothing
# about what the archive did contain. Under `set -o pipefail`, a nonzero
# `unzip` in `unzip -l "$ZIP" | grep -q ...` made the pipeline nonzero, so `!`
# took the missing-file branch either way. That conflation produced a wrong
# root-cause diagnosis on a real CI failure (#260).
#
#   absent/unreadable  → "could not read" + unzip's own error + exit 1
#   missing file       → "style.css missing" + entry listing + exit 1
#   unverified payload → "failed its integrity check" + unzip -t's report + exit 1
#   intact + present   → silent, exit 0
#
# The checks run cheapest-and-broadest first, and the order is load bearing
# (#261):
#
#   ┌─ does the file EXIST?                  [[ -f ]]   no    → "could not read"
#   ├─ can unzip READ the index at all?      unzip -Z1  rc>=2 → "could not read"
#   ├─ is promptingpress/style.css PRESENT?  grep -Fxq  no    → "style.css missing"
#   └─ does the PAYLOAD verify?              unzip -t   rc!=0 → "integrity check"
#
# (The first two both report "could not read"; only the second has an unzip
# error to attach, because in the first, unzip never ran.)
#
# `unzip -Z1` and `unzip -l` only ever read the archive's CENTRAL DIRECTORY, so
# a build whose compressed payload is byte-damaged but whose central directory
# survived listed clean and shipped as a release asset (release.yml uploads
# whatever package.sh produced). Only `unzip -t` actually decompresses and
# CRC-checks the payload, so it is the only check that can catch that class.
#
# Why integrity runs LAST, not first:
#   - `unzip -t` returns 9 on a file that is not a ZIP at all, so running it
#     before the read check would relabel #260's "could not read" case as an
#     integrity failure and undo that diagnostic split.
#   - An EMPTY archive returns rc 1 from `unzip -t`, the same rc as an entry
#     that unzip skipped without verifying. Letting the membership check reject
#     the empty archive first means integrity never has to tell those apart,
#     and can therefore fail closed on ANY nonzero rc with no carve-outs. See
#     the threshold comment at the check itself.
#
# Membership is an exact match on the entry list (`unzip -Z1` + `grep -Fxq`),
# not a substring search of the human-readable `unzip -l` table: an unanchored
# regex would also accept promptingpress/style.css.map or foo/promptingpress/style.css.
#
# Only the integrity + style.css checks live here. The single-top-level-directory
# and size checks stay in package.sh because they feed values into its final report.

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

# `unzip -Z1` lists one entry path per line, on stdout. Exit 0 is clean and 1
# is a warning (an empty archive is rc 1, and prints "Empty zipfile." on
# stdout, so ENTRIES is that notice rather than a path) — both mean the archive
# was READ. Only rc >= 2 is a genuine read failure. An empty archive is a
# missing-style.css case, not a read failure: routing it here is what the old
# code got wrong, in the other direction.
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

# The index is sound and names style.css. That still says nothing about the
# PAYLOAD: zero out an entry's compressed bytes and `unzip -Z1` lists it just
# as happily. `unzip -t` inflates every entry and checks it against its stored
# CRC, which is the only way to catch a byte-damaged build before it ships
# (#261).
#
# `-tq` (one q, not two): -qq suppresses the per-entry report on some failures,
# which would print an error banner with no diagnosis under it — the silent
# root-cause failure #260 was about. -tq still names the entry that failed.
#
# Capture is 2>&1 because unzip writes the per-entry report to STDOUT; folding
# stderr in too keeps any unzip-level error message with it.
set +e
TEST_OUTPUT=$(unzip -tq "$ZIP" 2>&1)
TRC=$?
set -e

# Fail closed on ANY nonzero, not just rc >= 2 — and note this check runs LAST
# precisely so it can afford to. rc 1 is merely a "warning", but unzip SKIPS an
# entry it cannot handle (unknown compression method, unreadable encryption)
# with rc 1, and a skipped entry is never inflated and never CRC-checked. So rc
# 1 can mean "not verified", which is not the same as "verified good". Flip one
# byte of style.css's compression-method field and you get exactly that: the
# entry lists fine, membership passes, `unzip -t` returns 1, and the file
# extracts as ZERO BYTES. A gate that reads "could not verify" as "fine" is the
# bug, not the fix.
#
# The only other archive that returns rc 1 is an empty one, and the membership
# check above has already rejected it — which is why no rc is carved out here.
if [[ "$TRC" -ne 0 ]]; then
    echo "ERROR: $ZIP failed its integrity check (unzip -t, exit $TRC)" >&2
    echo "The archive index is readable but its payload did not verify — do not ship this build." >&2
    if [[ -n "$TEST_OUTPUT" ]]; then
        echo "--- unzip -t report ---" >&2
        printf '%s\n' "$TEST_OUTPUT" >&2
    fi
    exit 1
fi
