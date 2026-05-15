# Maintainer Guide

## Branch Protection Policy

Use branch protection on `main` with required status checks for all 9 matrix cells from `.github/workflows/ci.yml`.

Required checks:

- `Quality Gates (PHP 8.2 / TYPO3 14.3.0 / Slot floor)`
- `Quality Gates (PHP 8.2 / TYPO3 14.3.1 / Slot current)`
- `Quality Gates (PHP 8.2 / TYPO3 14.3.1 / Slot rolling)`
- `Quality Gates (PHP 8.3 / TYPO3 14.3.0 / Slot floor)`
- `Quality Gates (PHP 8.3 / TYPO3 14.3.1 / Slot current)`
- `Quality Gates (PHP 8.3 / TYPO3 14.3.1 / Slot rolling)`
- `Quality Gates (PHP 8.4 / TYPO3 14.3.0 / Slot floor)`
- `Quality Gates (PHP 8.4 / TYPO3 14.3.1 / Slot current)`
- `Quality Gates (PHP 8.4 / TYPO3 14.3.1 / Slot rolling)`

## TYPO3 Patch Matrix Note

As of May 15, 2026, TYPO3 14.3.0 and 14.3.1 are published. The third matrix slot (`rolling`) is currently pinned to 14.3.1 to keep a stable 3x3 policy; replace this slot with the next published 14.3.x patch (for example `14.3.2`) as soon as it exists.

## Merge Requirements

- All required matrix checks green.
- No skipped quality-gate step.
- No ad-hoc per-extension tooling files in `packages/*` during monorepo phase.
