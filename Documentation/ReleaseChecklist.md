# Release Checklist

## Status

- [ ] Story 5.1 quality gates complete
- [ ] Story 5.2 documentation suite complete
- [ ] Story 5.3 extraction complete
- [ ] Story 5.4 incubation complete
- [ ] Story 5.5 publication complete

## Quality Gates

- [ ] `composer ci:composer:normalize`
- [ ] `composer ci:fractor:process`
- [ ] `composer ci:rector:process`
- [ ] `composer ci:docs:validate`
- [ ] `composer ci:phpstan`
- [ ] `composer ci:phpunit`
- [ ] `composer ci:phpunit:functional`
- [ ] `composer ci:all`

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
Use [MaintainerGuide.md](MaintainerGuide.md) for branch-protection required checks policy.
Keep host monorepo incubation evidence separate from standalone `composer ci:all` evidence until Story 5.5 publication cutover.
