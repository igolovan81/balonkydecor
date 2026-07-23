---
description: E2E testing conventions — Playwright page objects, @smoke vs local-only tagging, prod-safety rules, and the admin DB fixture pattern.
globs: ["tests/e2e/**/*.ts", "playwright.config.ts"]
alwaysApply: false
---

# E2E Testing Conventions

Playwright, TypeScript, no build step (dev tooling only — not deployed). Config is
`playwright.config.ts`; specs live in `tests/e2e/*.spec.ts`; page objects live in
`tests/e2e/pages/`; DB fixtures live in `tests/e2e/helpers/`.

## Page objects

- One class per page/flow in `tests/e2e/pages/`, named after the page
  (`ProductPage`, `CheckoutPage`, `AdminOrderPage`). Public pages extend `BasePage`
  (constructor takes `page` and an optional `lang` defaulting to `'cs'`) to inherit
  the shared nav locators (`langSwitcher`, `cartLink`, `switchLanguage()`) present on
  every `layout/base.twig` page. Admin pages (`AdminLoginPage`, `AdminOrderPage`)
  don't extend `BasePage` — there's no lang prefix or shared nav to inherit on
  `/admin/*`.
- Locators are exposed as getters (`get heading()`, `get statusSelect()`); page
  transitions and form submissions are `async` methods (`goto()`, `addToCart()`,
  `fillCustomerDetails()`). Don't put `expect()` assertions inside a page object —
  assertions belong in the spec file (or in a spec-local helper function that
  orchestrates several page objects, e.g. `setOrderStatus()` in
  `admin-order-flow.spec.ts`), so a page object stays reusable across tests with
  different expectations.
- Adding a new public page or flow: add/extend a page object under `tests/e2e/pages/`
  rather than inlining `page.locator(...)` calls in the spec — this is the whole point
  of the refactor from raw specs to page objects, don't regress it.
- A multi-step action that orchestrates one or more page objects *and* is shared
  across more than one spec file (e.g. logging in as an editor before getting to the
  actual test) belongs in `tests/e2e/workflows/` as its own class (`LoginFlow`), not
  duplicated as a local `async function` in each spec. Unlike page objects, workflow
  classes may contain `expect()` — that's the point of the split: page objects stay
  reusable locator/navigation primitives, workflows are the assertion-bearing
  orchestration reused across specs. A helper used by only one spec file still stays
  local to that file (e.g. `setOrderStatus()` in `admin-order-flow.spec.ts`); promote
  it to `workflows/` only once a second spec needs the same steps.
- Where a value must be parsed out of a URL rather than filled into a form (e.g. the
  order number minted by `OrderModel::create()`), expose it as a static helper on the
  relevant page object (`OrderPage.numberFromUrl()`), not a regex duplicated in every
  spec that needs it.

## `@smoke` vs local-only

- Every spec file opens with a comment classifying it: `@smoke`-tagged tests are
  read-only and safe against production; untagged tests are local-only because they
  mutate real data (create orders, register/delete accounts, insert admin rows) or
  rely on the GoPay dev bypass, which only exists when `gopay_go_id` is unset.
- `npm run test:e2e` (local, full suite) vs `npm run test:e2e:prod` (`--grep @smoke`
  against `https://balonkydecor.cz`). **Never** widen prod's `--grep` filter to catch
  `cart.spec.ts`/`checkout.spec.ts`/`account.spec.ts`/`admin-order-flow.spec.ts` —
  against real GoPay credentials those would submit a real payment or create/delete
  real rows. See `.claude/commands/e2e.md` for the full run procedure (local vs prod)
  before changing scripts or CI-style automation around these tests.
- New tests default to local-only (no tag) unless they are genuinely read-only and
  safe to run against the live site — then tag `@smoke`.

## Admin/editor fixtures

- No seeded admin/editor account exists in any environment, and `/admin/setup` only
  works when the `users` table is empty (it isn't, once real usage starts). Tests
  needing an authenticated editor use `createTempEditor()`/`deleteTempEditor()` from
  `tests/e2e/helpers/admin-fixture.ts`, which shells out directly to the same Docker
  MySQL container (`docker compose exec db mysql ...`) other local workflows use —
  mirroring the `uniqid()`-fixture convention from `.claude/rules/unit-testing.md`.
- That helper uses `execFileSync` (argv array, no shell), not `execSync` — a bcrypt
  hash contains literal `$` characters that a shell would try to expand as variables
  inside a double-quoted `-e` argument. Keep new DB-shelling fixtures on
  `execFileSync` for the same reason.
- Always delete what you create: wrap the test body in `try`/`finally` and call
  `deleteTempEditor(email)` in `finally`, even on assertion failure.

## Selectors & assertions

- Prefer the same selectors the app already uses for structure, not text: form
  submit buttons are `form.contact-form button[type="submit"]`; status changes are
  asserted via the actual form control's value (`toHaveValue()`), not toast/flash
  text, which is a translation key and varies by admin language.
- Assert on URL shape with a regex anchored to the route (`/\/cs\/checkout\/confirm$/`)
  after every navigating action — the existing specs check the URL after nearly every
  step, not just at the end; keep that density when adding new flows so a broken
  redirect fails at the step that broke, not three steps later.

## What not to test here

- Don't unit-test page object methods in isolation — they have no logic beyond
  locators and navigation; their coverage comes from the specs that use them passing.
- Don't add a page object for a page nothing currently visits in a test — build it
  when the first spec needs it, per the project's general "no speculative
  abstraction" rule.
