---
description: E2E testing conventions — Playwright page objects/workflows, @smoke vs local-only tagging, prod-safety rules, DB fixtures, and testing server behavior directly via page.request.
globs: ["tests/e2e/**/*.ts", "playwright.config.ts"]
alwaysApply: false
---

# E2E Testing Conventions

Playwright, TypeScript, no build step (dev tooling only — not deployed). Config is
`playwright.config.ts`; specs live in `tests/e2e/*.spec.ts`; page objects live in
`tests/e2e/pages/`; shared assertion-bearing flows live in `tests/e2e/workflows/`;
DB fixtures live in `tests/e2e/helpers/`.

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
  `cart.spec.ts`/`checkout.spec.ts`/`account.spec.ts`/`admin-order-flow.spec.ts`/
  `admin-product-clone.spec.ts` — against real GoPay credentials or real admin data
  those would submit a real payment or create/delete real rows. See
  `.claude/commands/e2e.md` for the full run procedure (local vs prod) before
  changing scripts or CI-style automation around these tests.
- New tests default to local-only (no tag) unless they are genuinely read-only and
  safe to run against the live site — then tag `@smoke`.

## DB fixtures

- No seeded admin/editor account exists in any environment, and `/admin/setup` only
  works when the `users` table is empty (it isn't, once real usage starts). Tests
  needing an authenticated editor use `createTempEditor()`/`deleteTempEditor()` from
  `tests/e2e/helpers/admin-fixture.ts`, which shells out directly to the same Docker
  MySQL container (`docker compose exec db mysql ...`) other local workflows use —
  mirroring the `uniqid()`-fixture convention from `.claude/rules/unit-testing.md`.
- Same pattern for products: `createTempProduct()`/`deleteTempProduct()`/
  `productExistsWithSku()` in `tests/e2e/helpers/product-fixture.ts` insert/remove a
  throwaway row directly via SQL rather than going through the admin
  create-product form. This isn't just convenience — posting through
  `AdminProductController`'s real create path calls `Translator::autoFill()` for
  every blank language, which would hit the real MyMemory API on every test run.
  Prefer a direct-SQL fixture over the real controller path whenever the controller
  action has a side effect like that (email send, external API call, etc.), not
  just for admin/product rows specifically.
- Deleting the parent row is enough when child tables cascade: `products.id` →
  `product_t`/`product_images`/`product_subtypes`/`product_specs` are all `ON
  DELETE CASCADE` (`database/migrations/V001`, `V021`, `V022`), so
  `deleteTempProduct(id)` alone cleans up anything a test action (e.g. a clone/split)
  minted from that product too. Check the migration before adding manual cleanup
  for a new child table — it may already be unnecessary.
- That helper uses `execFileSync` (argv array, no shell), not `execSync` — a bcrypt
  hash contains literal `$` characters that a shell would try to expand as variables
  inside a double-quoted `-e` argument. Keep new DB-shelling fixtures on
  `execFileSync` for the same reason.
- Always delete what you create: wrap the test body in `try`/`finally` and call
  the matching `deleteTemp*()` in `finally`, even on assertion failure.

## Selectors & assertions

- Prefer the same selectors the app already uses for structure, not text: form
  submit buttons are `form.contact-form button[type="submit"]`; status changes are
  asserted via the actual form control's value (`toHaveValue()`), not toast/flash
  text, which is a translation key and varies by admin language.
- Assert on URL shape with a regex anchored to the route (`/\/cs\/checkout\/confirm$/`)
  after every navigating action — the existing specs check the URL after nearly every
  step, not just at the end; keep that density when adding new flows so a broken
  redirect fails at the step that broke, not three steps later.
- To find one row/card among several that share the same generic markup, scope by
  an element that row uniquely contains, via Playwright's `has` locator option
  (e.g. `AdminProductListPage.rowFor()`: `page.locator('tr', { has: page.locator(
  'a[href="/admin/products/${id}/edit"]') })`, or `ShopPage.addToCartForm()` scoping
  by a hidden `sku` input) — not the raw `:has()` CSS pseudo-class, which isn't used
  anywhere else in this codebase.
- When an action navigates from a URL to a *new* URL matching the same regex shape
  (e.g. `/admin/products/{id}/edit` → `/admin/products/{newId}/edit` after a
  clone/split action), don't just `expect(page).toHaveURL(/.../)` — the pre-click
  URL already matches. Use `page.waitForURL(url => url.pathname !== oldPath)` first
  so a subsequent `page.url()` read (e.g. into `AdminProductFormPage.idFromUrl()`)
  can't race and silently return the old id.

## Testing server-side behavior directly with `page.request`

For behavior that isn't reachable (or is awkward to reach) through the rendered
UI — a tampered value outside a `<select>`'s fixed options, an unauthenticated
POST, a nonexistent-entity request — issue the HTTP call directly instead of
routing it through a form:

```ts
const response = await page.request.post(`/admin/orders/${orderNumber}/status`, {
  form: { status: 'shipped-by-drone' },
});
```

- Checking a redirect target without following it: pass `maxRedirects: 0` and read
  `response.headers()['location']` — used for "unauthenticated request → redirected
  to `/admin/login`, and nothing was created/changed" cases in both
  `admin-order-flow.spec.ts` and `admin-product-clone.spec.ts`.
- Checking a 404: `page.goto()` or `page.request.get()` both work — `expect(response
  ?.status()).toBe(404)`; use whichever the rest of that test already needs (a full
  navigation vs. a bare status check).
- After a request-level mutation attempt, still assert the *end state* through a
  page object (e.g. re-`goto()` the admin order page and check
  `statusSelect` still holds the old value) — the point of these tests is proving
  the server rejected the change, not just that the HTTP call returned a particular
  status.

## Estimating route coverage

- `node scripts/e2e-route-coverage.js` (or `npm run test:e2e:route-coverage`)
  statically diffs every route declared in `src/routes.php` against `.goto(...)`/
  `page.request.<method>(...)` calls found anywhere under `tests/e2e/**/*.ts`. It's
  instant (no Docker, no Playwright run) but has one real blind spot: routes only
  reached by clicking a submit button whose `<form action="...">` lives in a Twig
  template — e.g. `POST /{lang}/cart/add` — are invisible to it and will print as
  "not referenced" even when a passing spec exercises them via a real click. Treat
  its output as "definitely not hit via direct navigation/API call," not "untested";
  cross-check anything it flags against the actual spec file before adding coverage
  for it. Getting a true per-route hit count (including form-submitted POSTs) would
  need capturing real network requests during a Playwright run instead of static
  scanning — a heavier option, not what this script does.

## What not to test here

- Don't unit-test page object methods in isolation — they have no logic beyond
  locators and navigation; their coverage comes from the specs that use them passing.
- Don't add a page object for a page nothing currently visits in a test — build it
  when the first spec needs it, per the project's general "no speculative
  abstraction" rule.
