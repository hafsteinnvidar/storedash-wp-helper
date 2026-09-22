#!/usr/bin/env bash
#
# Build a distributable WordPress plugin archive: dist/storedash.zip
#
# The archive contains a single top-level `storedash/` directory (the plugin
# slug / Text Domain), so it installs cleanly via Plugins → Add New → Upload.
#
# Only *runtime* code ships. Development files are stripped so the zip is
# WordPress.org-submittable and lean:
#   - VCS/tooling dotfiles (.git, .github, .gitignore, .phpunit.cache, …)
#   - dev/build dirs (tests/, docs/, scripts/, dist/, node_modules/)
#   - linter/test/composer manifests (phpcs.xml, phpunit.xml.dist,
#     composer.json, composer.lock)
#   - Markdown docs (README.md, CLAUDE.md, per-module *.md)
#   - dev-only Composer packages (phpcs, phpunit, wpcs, vendor/bin) — pruned by
#     re-running `composer install --no-dev` in the staging dir, leaving only the
#     production autoloader (this plugin has no runtime package deps).
#
# This mirrors the artifact woo-dash previously produced via its
# scripts/build-plugin-zip.ts (same `storedash/` prefix).
#
# Also writes dist/storedash.json — the update manifest (see scripts/build-manifest.php).
#
# Usage:  ./scripts/build-zip.sh   (or  composer build-zip)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="storedash"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

# Stage the plugin source under dist/storedash/, excluding VCS/dev/dotfiles.
# composer.json/lock and vendor/ are copied in so the --no-dev prune below can
# regenerate a production-only autoloader; they are removed again before zipping.
rsync -a \
  --exclude='.*' \
  --exclude='dist/' \
  --exclude='scripts/' \
  --exclude='tests/' \
  --exclude='docs/' \
  --exclude='node_modules/' \
  --exclude='phpcs.xml' \
  --exclude='phpunit.xml.dist' \
  --exclude='*.md' \
  "$ROOT"/ "$STAGE"/

# Prune dev-only Composer packages, leaving only production deps + an optimized
# autoloader. This plugin declares no runtime package dependencies, so the result
# is the bare `vendor/autoload.php` + `vendor/composer/` loader.
(
  cd "$STAGE"
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
)

# Drop the Composer manifests — they are build inputs, not runtime files.
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

( cd "$DIST" && zip -rq "$SLUG.zip" "$SLUG" )
rm -rf "$STAGE"

SIZE="$(du -h "$ZIP" | cut -f1 | tr -d ' ')"
echo "[build-zip] Created $ZIP ($SIZE)"

# Release manifest polled by installed plugins for updates (dist/storedash.json).
# Derived from the plugin headers, readme.txt and the zip's SHA-256 so it can
# never describe a different build than the one next to it.
php "$ROOT/scripts/build-manifest.php" "$ZIP" "$DIST/$SLUG.json"
