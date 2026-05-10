# Example 2: Site Settings Tuning

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
