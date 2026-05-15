# Maintainer Guide

## Branch Protection Policy

Use branch protection on `main` with required status checks for all 9 matrix cells from `.github/workflows/ci.yml`.
The checks below are the standalone workflow names after the first public CI run in
`https://github.com/Starraider/skom-lscache`.

Required checks:

- `Quality Gates (PHP 8.2 / TYPO3 14.3.0 / Slot floor)`
- `Quality Gates (PHP 8.2 / TYPO3 14.3.1 / Slot current)`
- `Quality Gates (PHP 8.2 / TYPO3 ^14.3 / Slot rolling-range)`
- `Quality Gates (PHP 8.3 / TYPO3 14.3.0 / Slot floor)`
- `Quality Gates (PHP 8.3 / TYPO3 14.3.1 / Slot current)`
- `Quality Gates (PHP 8.3 / TYPO3 ^14.3 / Slot rolling-range)`
- `Quality Gates (PHP 8.4 / TYPO3 14.3.0 / Slot floor)`
- `Quality Gates (PHP 8.4 / TYPO3 14.3.1 / Slot current)`
- `Quality Gates (PHP 8.4 / TYPO3 ^14.3 / Slot rolling-range)`

## TYPO3 Patch Matrix Note

As of May 15, 2026, TYPO3 14.3.0 and 14.3.1 are published. The third matrix slot (`rolling-range`) uses `^14.3` so CI continues to exercise the newest compatible 14.3 patch while keeping the explicit floor and current patch cells.

## Merge Requirements

- All required matrix checks green.
- No skipped quality-gate step.
- Local `composer ci:all` green before release tagging.
- Branch protection requires all 9 standalone matrix cells before merge.
