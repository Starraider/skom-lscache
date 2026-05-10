# skom/lscache — LiteSpeed Cache for TYPO3

LiteSpeed Cache integration for TYPO3 14 that emits per-response LSCache tag
headers and orchestrates tag-targeted purges from TYPO3 DataHandler events,
programmatic APIs, and CLI.

> **Status:** v1.0.0 in development.

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

Install the extension, include its Site Set, and warm one public page.

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
vendor/bin/typo3 list | grep lscache
```

## Site-settings reference

All extension settings are site-scoped under `lscache.*`.

| Key | Type | Default |
|---|---|---|
| `lscache.enabled` | `bool` | `true` |
| `lscache.defaultTtl` | `int` | `3600` |
| `lscache.tagNamespace` | `string` | `""` (resolved by runtime) |
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
| `lscache.loopback.allowedRemoteAddrs` | `stringlist` | `["127.0.0.1","::1"]` |
| `lscache.probe.allowedHosts` | `stringlist` | `[]` |
| `lscache.audit.retentionDays` | `int` | `7` |

Notes:
- Keep `lscache.loopback.secret` non-empty in any environment where CLI purges or cache-flush mirroring are used.
- Leave `lscache.mirror.cacheFlushEvent` off unless you explicitly want TYPO3 core cache flushes mirrored to LSCache edge purge.

## Event reference

Published and consumed events:

- `Skom\Lscache\Purge\Event\PurgeIssuedEvent`
  - Emitted whenever the extension issues a purge intent.
  - Carries site, source, tags/urls, trigger reference, request ID, and timestamp.
- `TYPO3\CMS\Core\Cache\Event\CacheFlushEvent`
  - Consumed by `CacheFlushEventListener` when `lscache.mirror.cacheFlushEvent: true`.
  - Only groups containing `pages` are mirrored.

Example custom listener:

```php
<?php
declare(strict_types=1);

namespace Vendor\Project\EventListener;

use Skom\Lscache\Purge\Event\PurgeIssuedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use Psr\Log\LoggerInterface;

#[AsEventListener(identifier: 'project-lscache-audit')]
final readonly class PurgeIssuedEventListener
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(PurgeIssuedEvent $event): void
    {
        $this->logger->info('custom-lscache-observer', [
            'site' => $event->siteIdentifier,
            'source' => $event->source->value,
            'requestId' => $event->requestId,
        ]);
    }
}
```

## Bypass-rules recipe

Use bypass rules to force selected responses out of LSCache.

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

Recommendation:
- Start with narrow path rules and expand only when required.
- Prefer specific plugin signatures over broad path patterns.

## CLI purge reference

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
vendor/bin/typo3 lscache:purge --site=main --tags=main_page_42 --dry-run

# Full-site purge
vendor/bin/typo3 lscache:purge --site=main --all

# JSON status output is table-oriented; use terminal capture if needed
vendor/bin/typo3 lscache:status --site=main
```

`lscache:purge` exit codes:
- `0` success
- `1` validation or configuration failure
- `2` loopback dispatch failure

## Coming from other LSCache extensions?

No in-place migration is supported from `Code-Egg/lscache-typo3` or `atomicptr/typo3-lscache`.

Recommended migration path:

1. Remove previous LSCache extension from the TYPO3 instance.
2. Install `skom/lscache` and include the Site Set.
3. Recreate site-specific bypass and vary settings under `lscache.*`.
4. Validate purge behavior using `lscache:purge --dry-run` and the backend module.

## DDEV-Apache local-dev limitation warning

Default DDEV `apache-fpm` / `nginx-fpm` setups do not execute OpenLiteSpeed LSCache behavior.

What this means:
- Header generation and purge intent logic can be tested locally.
- Real edge-cache hit/miss and purge invalidation behavior must be verified on an OpenLiteSpeed-backed environment.

For local-only development, treat cache-hit probe and purge-loopback checks as application-path validation, not edge-cache proof.

## TYPO3 / PHP / license version matrix

| Item | Value |
|---|---|
| Extension key | `skom_lscache` |
| Composer package | `skom/lscache` |
| TYPO3 constraint | `^14.3` (`14.3.0-14.99.99` in `ext_emconf.php`) |
| PHP constraint | `^8.2` (`8.2.0-8.4.99` in `ext_emconf.php`) |
| License | `GPL-2.0-or-later` |

See [Documentation index](Documentation/README.md) for release and quality-gate artifacts.
