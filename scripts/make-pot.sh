#!/usr/bin/env sh
set -eu

if ! command -v wp >/dev/null 2>&1; then
  echo "WP-CLI is required. Install WP-CLI before generating the translation template." >&2
  exit 1
fi

if ! wp help i18n make-pot >/dev/null 2>&1; then
  echo "Install the WP-CLI i18n command with: wp package install wp-cli/i18n-command" >&2
  exit 1
fi

mkdir -p languages
wp i18n make-pot . languages/opentrip-engine.pot --domain=opentrip-engine --exclude=vendor,tests,dist
