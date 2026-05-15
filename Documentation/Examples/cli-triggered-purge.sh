#!/usr/bin/env sh
set -eu

TYPO3_BIN="${TYPO3_BIN:-vendor/bin/typo3}"
SITE_IDENTIFIER="${SITE_IDENTIFIER:-main}"
TAGS="${TAGS:-main_page_42,main_record_tt_content_77}"

# Dry-run selected tags before issuing the real purge.
"${TYPO3_BIN}" lscache:purge --site="${SITE_IDENTIFIER}" --tags="${TAGS}" --dry-run

# Trigger a full-site purge when an operational runbook calls for it.
"${TYPO3_BIN}" lscache:purge --site="${SITE_IDENTIFIER}" --all

# Inspect per-site status after purge execution.
"${TYPO3_BIN}" lscache:status --site="${SITE_IDENTIFIER}"
