# skom/lscache - LiteSpeed Cache for TYPO3

[![Packagist Version](https://img.shields.io/badge/Packagist-skom%2Flscache-blue.svg)](https://packagist.org/packages/skom/lscache)
[![TYPO3 Extension Repository](https://img.shields.io/badge/TER-skom__lscache-orange.svg)](https://extensions.typo3.org/extension/skom_lscache)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)

LiteSpeed Cache integration for TYPO3 14 that emits per-response LSCache tag
headers and orchestrates tag-targeted purges from TYPO3 DataHandler events,
programmatic APIs, and CLI.

> **Status:** v1.0.0 in development. Documentation is English-only for the
> 1.0.0 release line.
> **Repository:** https://github.com/Starraider/skom-lscache

## Table of Contents

1. [Quickstart](#quickstart)
2. [Site-settings reference](#site-settings-reference)
3. [Event reference](#event-reference)
4. [Bypass-rules recipe](#bypass-rules-recipe)
5. [CLI purge reference](#cli-purge-reference)
6. [Coming from other LSCache extensions?](#coming-from-other-lscache-extensions)
7. [DDEV-Apache local-dev limitation warning](#ddev-apache-local-dev-limitation-warning)
8. [TYPO3 / PHP / license version matrix](#typo3--php--license-version-matrix)

## Quickstart

Install the extension, include its Site Set, and verify command registration.

```bash
ddev exec composer require skom/lscache
```

If DDEV is not used:

```bash
composer require skom/lscache
```

Add the Site Set dependency in your site config (`config/sites/<site-id>/config.yaml`):

```yaml
dependencies:
  - skom/lscache/default
```

Minimal optional settings override (`config/sites/<site-id>/settings.yaml`):

```yaml
lscache:
  enabled: true
  defaultTtl: 3600
  tagNamespace: "main"
  loopback:
    secret: "%env(LSCACHE_LOOPBACK_SECRET)%"
```

Verify command registration:

```bash
ddev exec vendor/bin/typo3 list | grep lscache
```

Runnable example: [quickstart-install.md](Documentation/Examples/quickstart-install.md).

## Site-settings reference

All extension settings are site-scoped under `lscache.*`; this table is kept in
set-equality with `Configuration/Sets/Lscache/settings.definitions.yaml` by an
automated functional test.

| Key | Type | Default |
|---|---|---|
| `lscache.enabled` | `bool` | `true` |
| `lscache.defaultTtl` | `int` | `3600` |
| `lscache.tagNamespace` | `string` | `""` (resolved by runtime when empty) |
| `lscache.autopurge.dataHandler` | `bool` | `true` |
| `lscache.autopurge.tables` | `stringlist` | `[]` |
| `lscache.mirror.cacheFlushEvent` | `bool` | `false` |
| `lscache.bypass.loggedInFE` | `bool` | `true` |
| `lscache.bypass.setCookie` | `bool` | `true` |
| `lscache.bypass.paths` | `stringlist` | `[]` |
| `lscache.bypass.pluginSignatures` | `stringlist` | `[]` |
| `lscache.bypass.ctypes` | `stringlist` | `[]` |
| `lscache.vary.cookieAllowlist` | `stringlist` | `[]` |
| `lscache.loopback.secret` | `string` | `""` |
| `lscache.loopback.rateLimit` | `int` | `120` |
| `lscache.loopback.allowedRemoteAddrs` | `stringlist` | `[]` |
| `lscache.probe.allowedHosts` | `stringlist` | `[]` |
| `lscache.audit.retentionDays` | `int` | `7` |

Notes:

- Keep `lscache.loopback.secret` non-empty in any environment where CLI purges or cache-flush mirroring are used.
- Leave `lscache.mirror.cacheFlushEvent` off unless TYPO3 core cache flushes should be mirrored to LSCache edge purge.
- Use [site-settings-tuning.md](Documentation/Examples/site-settings-tuning.md) for a runnable tuning example.

## Event reference

The extension publishes purge events and can consume TYPO3 cache flush events.

Published event:

- `Skom\Lscache\Purge\Event\PurgeIssuedEvent`
- Emitted whenever the extension issues a purge intent.
- Carries site, source, tags/URLs, trigger reference, request ID, and timestamp.

Consumed event:

- `TYPO3\CMS\Core\Cache\Event\CacheFlushEvent`
- Consumed by `CacheFlushEventListener` when `lscache.mirror.cacheFlushEvent: true`.
- Only groups containing `pages` are mirrored.

Runnable listener example: [custom-purge-listener.php](Documentation/Examples/custom-purge-listener.php).

## Bypass-rules recipe

Use bypass rules to keep preview, private, or highly dynamic responses out of
LSCache while preserving cacheability for normal public pages.

```yaml
lscache:
  bypass:
    paths:
      - "/preview/*"
      - "/internal/*"
    pluginSignatures:
      - "tx_news_pi1"
    ctypes:
      - "shortcut"
      - "form_formframework"
```

Recommendations:

- Start with narrow path rules and expand only when required.
- Prefer specific plugin signatures over broad path patterns.
- Keep sensitive areas uncached even when they emit otherwise cacheable HTTP headers.

Runnable recipe: [bypass-rule-recipe.yaml](Documentation/Examples/bypass-rule-recipe.yaml).

## CLI purge reference

Use `lscache:purge` for explicit LSCache invalidation and `lscache:status` for
site-level status inspection.

Available commands:

- `lscache:purge`
- `--site=<identifier>` required
- `--tags=<csv>` or `--all`
- `--dry-run`
- `--format=text|json` (default `text`)
- `lscache:status`
- Optional `--site=<identifier>`

Examples:

```bash
# Dry-run tag purge
ddev exec vendor/bin/typo3 lscache:purge --site=main --tags=main_page_42 --dry-run

# Full-site purge
ddev exec vendor/bin/typo3 lscache:purge --site=main --all

# Inspect per-site status
ddev exec vendor/bin/typo3 lscache:status --site=main
```

`lscache:purge` exit codes:

- `0` success
- `1` validation or configuration failure
- `2` loopback dispatch failure

Runnable shell example: [cli-triggered-purge.sh](Documentation/Examples/cli-triggered-purge.sh).

## Coming from other LSCache extensions?

There is no in-place migration from `Code-Egg/lscache-typo3` or
`atomicptr/typo3-lscache`. Treat adoption as a clean reinstall so old extension
configuration cannot leak into the TYPO3 14 setup.

Clean reinstall steps:

1. Remove the previous LSCache extension from the TYPO3 instance.
2. Clear extension-specific TypoScript, scheduler tasks, and site settings owned by the previous extension.
3. Install `skom/lscache` and include the Site Set.
4. Recreate site-specific bypass, vary, and purge settings under `lscache.*`.
5. Validate purge behavior using `lscache:purge --dry-run` and the backend module.
6. Verify real hit/miss behavior on an OpenLiteSpeed-backed environment before release.

## DDEV-Apache local-dev limitation warning

Default DDEV `apache-fpm` and `nginx-fpm` setups do not execute OpenLiteSpeed
LSCache behavior.

What this means:

- Header generation and purge intent logic can be tested locally.
- Real edge-cache hit/miss and purge invalidation behavior must be verified on an OpenLiteSpeed-backed environment.
- Cache-hit probe and purge-loopback checks in local DDEV are application-path validation, not edge-cache proof.

## TYPO3 / PHP / license version matrix

| Item | Value |
|---|---|
| Extension key | `skom_lscache` |
| Composer package | `skom/lscache` |
| TYPO3 constraint | `^14.3` (`14.3.0-14.99.99` in `ext_emconf.php`) |
| PHP constraint | `^8.2` (`8.2.0-8.4.99` in `ext_emconf.php`) |
| License | `GPL-2.0-or-later` |

## Additional Documentation

Standalone quality gates run from this repository root:

```bash
composer ci:composer:normalize
composer ci:fractor:process
composer ci:rector:process
composer ci:docs:validate
composer ci:phpstan
composer ci:phpunit
composer ci:phpunit:functional
```

Run the full deterministic gate sequence:

```bash
composer ci:all
```

See [Documentation index](Documentation/README.md) for release, examples, and
quality-gate artifacts.
