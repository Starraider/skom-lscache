# Quickstart Install

Scenario: install `skom/lscache`, include its Site Set, and verify the CLI
commands are registered in a TYPO3 14 project.

Run from the TYPO3 project root:

```bash
composer require skom/lscache
vendor/bin/typo3 list | grep lscache
```

Add the Site Set dependency in `config/sites/main/config.yaml`:

```yaml
dependencies:
  - skom/lscache/default
```

If the host project uses DDEV, use the same commands through DDEV:

```bash
ddev exec composer require skom/lscache
ddev exec vendor/bin/typo3 list | grep lscache
```
