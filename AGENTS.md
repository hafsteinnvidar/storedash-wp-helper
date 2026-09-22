# AGENTS.md — how to make and ship changes to this repo

Read this before committing, pushing, or releasing. `CLAUDE.md` covers the *code* (architecture,
conventions); this file covers the *workflow*: where the code lives, where changes go, and how a change
reaches merchants' WordPress sites.

## Where things live

| What | Where |
|---|---|
| Canonical repo (**public**) | `https://github.com/hafsteinnvidar/storedash-wp-helper` — git remote `origin` |
| Old private repo | `https://github.com/hafsteinnvidar/storedash-helper` — git remote `private-origin`. **Do not push here.** Kept only for history; will be archived. |
| Local checkout | `~/Documents/GitHub/storedash-helper` (folder name is still the old one; that's fine — woo-dash expects it as sibling `../storedash-helper`) |
| Branch | `main`. Small changes commit straight to `main`; larger/risky work on a feature branch + PR to `main`. |
| Releases (what merchants download) | GitHub Releases on `origin`: https://github.com/hafsteinnvidar/storedash-wp-helper/releases |
| CI | `.github/workflows/ci.yml` on every push/PR; `.github/workflows/release.yml` on `v*` tags |

**The repo is public.** Never commit secrets, API keys, store URLs with credentials, customer data, or
`.env` files — the full history is visible to everyone. Consumer keys live in the WordPress DB, never here.

## Making a change

1. Work on `main` (or a branch for bigger work). Follow `CLAUDE.md` for code conventions.
2. Before committing, all three must pass:
   ```bash
   composer phpcs && composer test && composer build-zip
   ```
   `build-zip` also validates that `Version:` (storedash.php) matches `Stable tag:` (readme.txt).
3. Commit with a message that says *why*, not just what. Do not bump the version for a normal commit —
   version bumps happen only when releasing (below).
4. Push to **`origin main`** (`git push origin main`). Check CI is green:
   `gh run list --repo hafsteinnvidar/storedash-wp-helper --limit 3`.

Pushing to `main` does **not** ship anything to merchants. Only a tagged release does.

## Releasing (this is what ships to merchants)

Installed plugins poll the latest GitHub Release for updates (`inc/core/class-storedash-updater.php`),
so publishing a release is what makes "Update available" appear on every site.

1. Decide the version (semver: bugfix → patch, feature → minor).
2. Bump in **all** of these — they must match exactly or the release workflow refuses the tag:
   - `storedash.php`: the `Version:` header **and** `define( 'STOREDASH_VERSION', ... )`
   - `readme.txt`: `Stable tag:`
   - `readme.txt`: add a `= x.y.z =` entry at the top of `== Changelog ==` (this becomes the GitHub
     release notes and the changelog merchants see in "View details" — write it for a merchant, not a
     developer; see existing entries for tone)
   - If the DB schema changed: also bump the constant in `StoreDash_Bootstrap::maybe_create_tables()`
3. `composer phpcs && composer test && composer build-zip`, then commit (`Release x.y.z` is a fine message).
4. Tag and push both:
   ```bash
   git push origin main
   git tag vX.Y.Z
   git push origin vX.Y.Z
   ```
5. Watch the `Release` workflow: `gh run list --repo hafsteinnvidar/storedash-wp-helper --workflow=release.yml`.
   It lints, tests, builds `dist/storedash.zip` + `dist/storedash.json`, and publishes the GitHub Release
   with both attached. Confirm at https://github.com/hafsteinnvidar/storedash-wp-helper/releases.
6. Merchants see the update within ~12 hours (or immediately via Dashboard → Updates → "Check again").

Notes:
- If the tag push does not start the workflow (it happens occasionally), delete and re-push the tag:
  `git push --delete origin vX.Y.Z && git push origin vX.Y.Z`.
- To fix a bad release: fix forward with a new patch version. Do **not** re-tag an existing version —
  sites cache the manifest and the zip hash is pinned per release.
- A tag with a suffix (`v1.20.0-beta.1`) publishes a **pre-release**, which merchants never see. Use it
  to test a build on a specific site by installing that release's zip by hand.
- Do not change the `Update URI` header in `storedash.php` or `MANIFEST_URL` in the updater class
  without a redirect in place — both are baked into every installed copy.
- woo-dash still serves `public/storedash-plugin.zip` for **first installs**. After a release, copy
  `dist/storedash.zip` there and commit in woo-dash if new installs should get the new version.

## Quick reference

```bash
composer install                 # dev deps (phpcs, phpunit)
composer phpcs                   # lint (WordPress coding standards); warnings don't fail, errors do
composer phpcbf                  # auto-fix lint
composer test                    # PHPUnit (standalone, no WordPress boot)
composer build-zip               # dist/storedash.zip + dist/storedash.json
gh run list --repo hafsteinnvidar/storedash-wp-helper --limit 5
gh release list --repo hafsteinnvidar/storedash-wp-helper
```

## Don'ts

- Don't push to `private-origin`.
- Don't commit `dist/` (gitignored) or edit `vendor/` by hand (it's committed for distribution; change
  `composer.json` and run `composer install` instead).
- Don't add unauthenticated REST routes or rename any `woodash_*` stored keys — see `CLAUDE.md`.
- Don't tag without bumping the versions first; the workflow will reject it and you'll have to re-tag.
