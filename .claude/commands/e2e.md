# Run E2E Tests

Run the Playwright end-to-end suite (`tests/e2e/`), either locally (full suite) or
against production (`@smoke`-tagged tests only).

Usage: `/e2e` or `/e2e local` — full suite, local. `/e2e prod` — smoke subset, production.

## Local run (default, no arg or `local`)

1. Make sure dependencies are installed (skip if `node_modules/` and the Chromium
   browser are already present):
   ```bash
   npm install
   npx playwright install chromium
   ```

2. Make sure the local DB is up — Playwright's `webServer` will start
   `php -S localhost:8080 -t www` itself (or reuse one already running), but it does
   **not** start Docker:
   ```bash
   docker compose up -d
   until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
   ```

3. Run the full suite:
   ```bash
   npm run test:e2e
   ```

4. Report the pass/fail summary. On failure, note that a trace is captured
   (`trace: on-first-retry`) and point to `npx playwright show-report` for the HTML
   report (`playwright-report/`, gitignored).

## Production run (`prod`)

**Only runs tests tagged `@smoke`** — read-only checks (homepage, language switcher,
404 handling). This is deliberate: `cart.spec.ts`, `checkout.spec.ts`,
`account.spec.ts`, and `admin-order-flow.spec.ts` are all excluded because they
mutate real data — checkout creates a real order and, against production's actual
GoPay credentials (no dev bypass there), would submit a real payment request;
`account.spec.ts` registers and deletes a real customer row; `admin-order-flow.spec.ts`
inserts/deletes a real admin user row via the DB fixture. Never widen the `--grep`
filter to include them against prod.

1. Confirm with the user before running against the live site if there's any doubt —
   this hits `https://balonkydecor.cz` over the network.

2. Run the smoke subset:
   ```bash
   npm run test:e2e:prod
   ```
   (equivalent to `E2E_BASE_URL=https://balonkydecor.cz playwright test --grep @smoke`)

3. Report the pass/fail summary. A failure here means something is actually broken on
   the live site — treat it like a `/verify` failure, not a flaky test.

## Notes

- `E2E_BASE_URL` controls the target; when it's unset or points at `localhost`/
  `127.0.0.1`, `playwright.config.ts` manages the local PHP server itself — for any
  other host it does not, since spinning up a local server would be irrelevant.
- Tests live in `tests/e2e/`, config in `playwright.config.ts` — see also the
  "Testing" section in `README.md`.
- Specs drive the UI through page objects in `tests/e2e/pages/` rather than inlining
  locators; DB fixtures (e.g. the throwaway admin/editor used by
  `admin-order-flow.spec.ts`) live in `tests/e2e/helpers/`. Conventions for both are
  in `.claude/rules/e2e-testing.md` — read it before adding a new spec or page object.
