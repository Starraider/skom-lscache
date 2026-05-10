# Example 4: CLI Purge Usage

```bash
# Dry-run selected tags
vendor/bin/typo3 lscache:purge --site=main --tags=main_page_42,main_record_tt_content_77 --dry-run

# Trigger a full-site purge
vendor/bin/typo3 lscache:purge --site=main --all

# Inspect per-site status
vendor/bin/typo3 lscache:status --site=main
```
