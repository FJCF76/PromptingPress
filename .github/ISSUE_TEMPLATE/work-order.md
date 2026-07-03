---
name: Work order (bug or enhancement)
about: A self-contained issue an AI agent can implement without broad repo context
title: ""
labels: []
---

<!--
Fill EVERY section. The goal is an issue that another agent can implement in isolation.
After filing, a maintainer adds status/area/size labels and a place in #141 (the ordered queue).
Reference exact files/functions/symbols, NOT line numbers (they drift).
-->

## Type
<!-- bug (implementation defect) or enhancement (missing/incomplete functionality) -->

## Current behavior
<!-- What happens today. Cite the exact file + function/symbol. -->

## Expected behavior
<!-- What should happen. -->

## Evidence in code
<!-- File paths + function/component names + the specific lines of logic (quote them). -->

## Root cause hypothesis
<!-- Why it behaves this way. -->

## User / product impact
<!-- Who is affected and how. -->

## Invariants this change must preserve
<!-- e.g. only lib/wp.php calls WordPress functions; the {};<> style-slot injection guard;
     components auto-load by convention; actions follow validate/preview/execute. -->

## Fix plan
<!-- Concrete steps. -->

## depends-on
<!-- List issue numbers that must land first, e.g. "depends-on: #119, #99". Used to derive #141. -->

## Acceptance criteria
<!-- Observable, testable conditions for "done". -->

## Suggested tests
<!-- Unit / integration / E2E, with the file they belong in (PHPUnit tests/, vitest tests/js/, playwright tests/e2e/). -->

## Verify with
<!-- The exact command that proves the fix, e.g. `composer test`, `npm test`, or a `wp pp ...` invocation. -->

## Security surface
<!-- None, or describe the threat (injection, SSRF, XSS, capability bypass) and the mitigation. -->
