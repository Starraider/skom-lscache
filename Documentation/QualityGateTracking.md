# Quality Gate Tracking

Date: 2026-05-15
Owner: Maintainers

## Current Gate Snapshot

| Gate | Status | Evidence |
|---|---|---|
| `ci:composer:normalize` | green | standalone script must pass from repository root |
| `ci:fractor:process` | green | dry-run clean |
| `ci:rector:process` | green | dry-run clean |
| `ci:docs:validate` | green | validates README TOC/settings parity plus shell, PHP, and YAML examples |
| `ci:phpstan` | green | standalone `phpstan.neon` scans `Classes` and `Tests` at level 6 |
| `ci:phpunit` | green | standalone unit suite runs `Tests/Unit` |
| `ci:phpunit:functional` | green | Story 5.1 scenario classes present and green |
| `ci:all` | green | deterministic aggregate of all seven gates |

## Ownership and Closure Criteria

- CI workflow owner: Maintainers
- Test-data and fixture owner: Extension maintainers
- Static analysis ownership: Extension maintainers

Closure criteria:

- All seven standalone gates pass sequentially from repository root.
- `composer ci:all` passes in the standalone repository.
- All nine CI matrix cells pass in `.github/workflows/ci.yml`.
- Documentation examples pass `composer ci:docs:validate` with ShellCheck, `php -l`, yamllint, and README/settings parity checks.
- Story 5.1 scenario classes stay green without quarantine/skips.
- Any newly introduced warning/deprecation is linked to a follow-up owner before merge.

## Notes

- Host monorepo gates remain separate until Story 5.5 Packagist cutover.
- Slow tests are marked with `@group slow` and can be isolated when diagnosing runtime regressions.
