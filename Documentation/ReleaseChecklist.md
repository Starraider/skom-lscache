# Release Checklist

## Status

- [ ] Story 5.1 quality gates complete
- [ ] Story 5.2 documentation suite complete
- [ ] Story 5.3 extraction complete
- [ ] Story 5.4 incubation complete
- [ ] Story 5.5 publication complete

## Quality Gates

- [ ] `ddev exec composer ci:composer:normalize`
- [ ] `ddev exec composer ci:fractor:process`
- [ ] `ddev exec composer ci:rector:process`
- [ ] `ddev exec composer ci:phpstan`
- [ ] `ddev exec composer ci:phpunit`
- [ ] `ddev exec composer ci:phpunit:functional`

## Supply Chain

- [ ] `composer audit` is clean
- [ ] dependency license review completed
- [ ] TER validation dry-run complete

## Publication

- [ ] Git tag created
- [ ] Packagist package resolvable
- [ ] TER listing published and verified

## Notes

Use [QualityGateTracking.md](QualityGateTracking.md) for open gate details and noise ownership.
