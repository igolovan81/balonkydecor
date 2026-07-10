# Site versioning — design

Date: 2026-07-10

## Problem

There's no way to tell which deployed commit is currently live on
`balonkydecor.cz`. The app has no build step and no CI/CD (`.claude/rules/backend.md`,
`CLAUDE.md` — deploys are a local `scripts/deploy.sh` FTP mirror), so any versioning
scheme has to be generated at deploy time on the developer's machine and shipped as a
static artifact, not computed at runtime on WEDOS shared hosting (no git available
there, and `.git` is excluded from the FTP mirror).

## Version scheme

Auto-generated from git — no manual bumping. Format: `YYYY-MM-DD (shorthash)`, e.g.
`2026-07-10 (da5ce04)`. Always reflects the exact commit that was actually deployed,
with zero developer discipline required (unlike hand-maintained semver, which drifts
the moment someone forgets to bump it).

## Generation — `scripts/deploy.sh`

Immediately before the `lftp mirror` block, compute and write a root-level `VERSION`
file:

```bash
GIT_HASH=$(git rev-parse --short HEAD)
echo "$(date +%Y-%m-%d) (${GIT_HASH})" > "$LOCAL_DIR/VERSION"
```

`VERSION` is **not** committed to git (added to `.gitignore`) — it's a deploy-time
artifact regenerated fresh on every deploy from whatever `HEAD` is being shipped,
committing it would make it stale the instant the next commit lands. It *is* included
in the FTP mirror (root-level files aren't in `deploy.sh`'s exclude list), so it ships
to the server alongside the code it describes.

## Reading it — `src/Services/Version.php`

New service, one static method:

```php
Version::current(?string $rootDir = null): string
```

- Reads `{$rootDir}/VERSION` (default `$rootDir` is the real project root,
  `__DIR__ . '/../..'`) and returns its trimmed contents if the file exists and is
  non-empty.
- Dev fallback (file missing — e.g. local dev before any deploy has run): shell out to
  `git rev-parse --short HEAD` from `$rootDir` and format it as `{today} ({hash})`,
  matching the deployed format exactly.
- If git itself isn't available/fails (rare — no `.git`, no git binary), returns the
  literal string `'dev'`.
- The optional `$rootDir` parameter exists purely so unit tests can point at a temp
  directory instead of the real project root — mirrors no other service's signature
  exactly but follows the same "services degrade gracefully" convention as
  `GoPay::fromSettings()` / `Mailer`.

## Exposing it — `src/app.php`

Register a `site_version()` Twig function next to the existing `asset_v()`
registration:

```php
$twig->getEnvironment()->addFunction(new \Twig\TwigFunction('site_version', function () {
    return \App\Services\Version::current();
}));
```

## Display

- **Public footer** (`templates/layout/base.twig`): appended to the existing
  copyright line:
  ```twig
  <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }} <span class="site-version">v{{ site_version() }}</span></p>
  ```
  `.site-footer` is already muted/small (`color: var(--muted); font-size: .85rem`), so
  `.site-version` only needs a small additional style (slightly smaller, e.g.
  `font-size: .75rem`) in `www/assets/css/style.css` next to the existing
  `.site-footer` rule — no new design tokens needed.

- **Admin sidebar** (`templates/layout/admin-base.twig`): a small line appended at the
  bottom of `.admin-sidebar-footer`, right after the `.admin-logout` link:
  ```twig
  <div class="admin-version">v{{ site_version() }}</div>
  ```
  New `.admin-version` rule in `www/assets/css/admin.css` near `.admin-logout`,
  matching the sidebar's existing muted-purple tone (`#8a8ac0`-ish) and small size
  (`~0.68rem`) — consistent with the sidebar's existing hardcoded dark-theme palette
  (admin.css doesn't use the public site's CSS custom properties).

## Testing

`tests/Unit/Services/VersionTest.php` (pure, no DB — same category as `SeoTest`/
`SitemapTest`):
- `Version::current($tmpDirWithVersionFile)` returns the file's trimmed contents.
- `Version::current($tmpDirWithoutVersionFile)` falls back to the git-based format
  (assert it matches the `YYYY-MM-DD (hash)` pattern via regex, since the exact hash
  depends on the test-run commit).

Templates and CSS are verified by rendering the public homepage and an admin page
locally (`php -S localhost:8080 -t www`), per this repo's existing convention — not
with unit tests.

## Out of scope

- No changelog, no version history page, no admin UI to browse past versions — just
  "what's live right now."
- No semantic versioning / release process — this tracks deploys, not product
  releases.
- No git tags — the short commit hash is sufficient to cross-reference `git log`
  locally if deeper investigation is ever needed.
