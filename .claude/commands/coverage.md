# Test Coverage

Estimate how much of the codebase the test suites actually exercise: PHPUnit
line/method coverage for `src/`, and a static route-coverage diff for the
Playwright e2e suite.

Usage: `/coverage` or `/coverage unit` — PHPUnit coverage report. `/coverage e2e` —
e2e route-coverage diff. `/coverage all` — both.

Background: `.claude/rules/unit-testing.md` (Coverage section) and
`.claude/rules/e2e-testing.md` (Estimating route coverage section).

## PHPUnit coverage (default, `unit`, or part of `all`)

1. Confirm the PCOV extension is loaded (one-time setup if missing):
   ```bash
   php -m | grep -i '^pcov$' || pecl install pcov
   ```

2. Run the suite with coverage:
   ```bash
   ./scripts/coverage.sh
   ```
   This starts Docker if needed and runs with `-d memory_limit=1G` (works around
   the shared dev DB's accumulated leftover fixture rows inflating `SitemapTest`
   past PHP's default 128M — unrelated to coverage itself; if plain `php
   vendor/bin/phpunit` OOMs too, that's this same DB-bloat issue, not new
   breakage from this command).

3. Report the summary from `tmp/coverage.txt` (classes/methods/lines %), and
   point to `tmp/coverage-html/index.html` for the browsable per-file report.
   Remember: controllers are intentionally untested per
   `.claude/rules/unit-testing.md`, so overall % reads lower than "real"
   coverage — most controller logic is only exercised by the e2e suite.

## E2E route coverage (`e2e` or part of `all`)

1. Run the static diff (no Docker/Playwright run needed):
   ```bash
   npm run test:e2e:route-coverage
   ```
   (equivalent to `node scripts/e2e-route-coverage.js`)

2. Report the summary counts and the "not referenced" list, but always include
   the caveat printed at the bottom of its own output: this only sees
   `.goto(...)`/`page.request.<method>(...)` calls in TypeScript source. Routes
   only reached by clicking a form's submit button (`action="..."` lives in the
   Twig template, invisible to this scan — e.g. `POST /{lang}/cart/add`) will
   show as "not referenced" even when a passing spec exercises them via a real
   click. Don't report a line from this list as "untested" without checking the
   actual spec first.

## Notes

- Neither report is enforced as a gate (no minimum % configured anywhere) —
  this command is for visibility when asked, not part of the required
  pre-commit check (that's still the full `php vendor/bin/phpunit` /
  `npm run test:e2e` runs).
- `tmp/coverage.txt` and `tmp/coverage-html/` are gitignored — never commit
  generated coverage output.
