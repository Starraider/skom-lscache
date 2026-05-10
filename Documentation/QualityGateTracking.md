# Quality Gate Tracking

Date: 2026-05-10
Owner: Murat (Test Architect)

## Current Gate Snapshot

| Gate | Status | Evidence |
|---|---|---|
| `ci:composer:normalize` | green | normalized |
| `ci:fractor:process` | green | dry-run clean |
| `ci:rector:process` | pending | must be rechecked after safe migration and skip guard |
| `ci:phpstan` | pending | must be rechecked after backend user attribute typing fix |
| `ci:phpunit` | green | passing |
| `ci:phpunit:functional` | green with noise | warnings + deprecations + PHPUnit notices |

## Functional Suite Noise Routing

Goal: warnings, deprecations, and notices are either removed or tracked with accepted rationale before release readiness signoff.

### Track A: Warnings

- Owner: Murat
- Route: collect each warning with source test and class; classify as framework-level vs extension-level
- Exit criteria: no extension-origin warnings remain untracked

### Track B: Deprecations

- Owner: Murat
- Route: map each deprecation to file and TYPO3/PHP API; create fix task or explicit defer decision
- Exit criteria: each deprecation has a decision record and owner

### Track C: PHPUnit Notices

- Owner: Murat
- Route: identify noisy assertions/bootstrap notices; convert avoidable notices into deterministic assertions
- Exit criteria: no unexplained PHPUnit notice remains in CI output

## Reporting Cadence

- Update this file whenever functional-suite output changes materially.
- Mirror resolved items into retrospective and release checklist closure notes.
