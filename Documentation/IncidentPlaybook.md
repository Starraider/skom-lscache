# Incident Playbook

## Scope

This is the v1 seed playbook for release and runtime incidents around `skom/lscache`.

## 1. Yank a broken Packagist version

1. Mark affected version as abandoned or superseded in release communication.
2. Tag and publish a corrective patch version.
3. Update release notes and notify integrators to pin the fixed version.

## 2. Roll back a problematic TER release

1. Publish a follow-up TER version with corrective changes.
2. Update TER changelog and release summary with rollback context.
3. Validate install/upgrade path on TYPO3 14 before closing incident.

## 3. Rotate `lscache.loopback.secret`

1. Generate a new secret per environment.
2. Update site settings in each active site.
3. Verify CLI purge and cache-flush mirror paths post-rotation.
4. Invalidate stale automation or deployment variables that carried the old secret.

## 4. Stale content reported after editor save

1. Check backend audit log for purge event and trigger reference.
2. Verify loopback dispatch and source for the affected site.
3. Run `lscache:purge --site=<id> --all` as controlled mitigation.
4. Record root cause and add coverage/tests if behavior gap is confirmed.
