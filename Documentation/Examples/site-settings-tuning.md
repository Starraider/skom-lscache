# Site Settings Tuning

Scenario: enable LSCache integration for a public site, set a 30-minute default
TTL, namespace tags for the site, and configure loopback safety limits.

`config/sites/main/settings.yaml`:

```yaml
lscache:
  enabled: true
  defaultTtl: 1800
  tagNamespace: "main"
  loopback:
    secret: "%env(LSCACHE_LOOPBACK_SECRET)%"
    rateLimit: 120
    allowedRemoteAddrs:
      - "127.0.0.1"
      - "::1"
  vary:
    cookieAllowlist:
      - "fe_typo_user"
```

Keep the loopback secret environment-driven; do not commit secret values into
site configuration.
