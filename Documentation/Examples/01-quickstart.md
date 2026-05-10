# Example 1: Quickstart Install

```bash
composer require skom/lscache
vendor/bin/typo3 list | grep lscache
```

`config/sites/main/config.yaml`:

```yaml
dependencies:
  - skom/lscache/default
```
