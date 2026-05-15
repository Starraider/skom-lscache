# Quality Gate Tracking

Date: 2026-05-15
Owner: Maintainers

## Current Gate Snapshot

| Gate | Status | Evidence |
|---|---|---|
| `ci:composer:normalize` | green | host-level script passes in DDEV |
| `ci:fractor:process` | green | dry-run clean |
| `ci:rector:process` | green | dry-run clean |
| `ci:docs:validate` | green | validates README TOC/settings parity plus shell, PHP, and YAML examples |
| `ci:phpstan` | green | root `phpstan.neon` includes extension Classes/Tests |
| `ci:phpunit` | green | host-level unit suite passes |
| `ci:phpunit:functional` | green | Story 5.1 scenario classes present and green |

## Ownership and Closure Criteria

- CI workflow owner: Maintainers
- Test-data and fixture owner: Extension maintainers
- Static analysis ownership: Extension maintainers

Closure criteria:

- All seven host-level gates pass sequentially from repository root.
- All nine CI matrix cells pass in `.github/workflows/ci.yml`.
- Documentation examples pass `composer ci:docs:validate` with ShellCheck, `php -l`, yamllint, and README/settings parity checks.
- Story 5.1 scenario classes stay green without quarantine/skips.
- Any newly introduced warning/deprecation is linked to a follow-up owner before merge.

## Notes

- Runtime target remains <= 60 seconds locally for the seven gate sequence in DDEV.
- Slow tests are marked with `@group slow` and can be isolated when diagnosing runtime regressions.
