#!/usr/bin/env sh
set -eu

if ! command -v wp >/dev/null 2>&1; then
  echo "WP-CLI is required. Install WP-CLI and the wp-cli/i18n-command package first." >&2
  exit 1
fi

if ! wp help i18n make-pot >/dev/null 2>&1; then
  echo "The WP-CLI i18n command is missing. Run: wp package install wp-cli/i18n-command" >&2
  exit 1
fi

wp i18n make-pot . languages/opentrip-engine.pot --domain=opentrip-engine --exclude=vendor,tests,dist
