# Example 5: Bypass Rules

`config/sites/main/settings.yaml`:

```yaml
lscache:
  bypass:
    paths:
      - "/preview/*"
      - "/api/private/*"
    pluginSignatures:
      - "tx_news_pi1"
    ctypes:
      - "shortcut"
      - "form_formframework"
```

This keeps sensitive or dynamic responses out of LSCache while preserving cacheability for normal public pages.
