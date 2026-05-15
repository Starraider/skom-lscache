# Documentation Index

This folder contains release-facing artifacts for `skom/lscache`.

## Sections

- [Examples](Examples/)
- [Release checklist](ReleaseChecklist.md)
- [Quality gate tracking](QualityGateTracking.md)
- [Maintainer guide](MaintainerGuide.md)
- [Incident playbook](IncidentPlaybook.md)

## Documentation Validation

Run documentation checks from the repository root during monorepo incubation:

```bash
ddev exec composer ci:docs:validate
```

The gate validates required example files, shell examples with ShellCheck, PHP
examples with `php -l`, YAML examples with yamllint, and README/settings key
parity.

## Scope

The monorepo remains the source of truth during incubation. Story 5.3 copies this extension source to a standalone repository and expands release automation there.
