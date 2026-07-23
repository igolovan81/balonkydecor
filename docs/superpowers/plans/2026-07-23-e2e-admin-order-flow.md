# E2E Admin Order Flow Test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an e2e test that creates a customer order (1 or 3 products), then has an editor move it through `ready` to either `completed` or `cancelled` in the admin panel.

**Architecture:** A small helper module seeds/deletes a throwaway `editor` user via `docker compose exec db mysql` (no seeded admin credentials exist anywhere in this project, and `/admin/setup` only works on an empty `users` table). Two Playwright tests in one spec file each: add product(s) to cart → checkout via the GoPay dev bypass → extract the order number from the redirect URL → log in as the seeded editor → change status twice via the admin order-detail page's `<select name="status">` form.

**Tech Stack:** Playwright + TypeScript (`tests/e2e/`), Node's built-in `child_process.execSync` (no new npm dependency), the project's local Docker MySQL (`docker compose exec db mysql`), PHP CLI (`php -r`) to generate a bcrypt hash matching `password_hash(..., PASSWORD_BCRYPT)`.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-23-e2e-admin-order-flow-design.md`.
- Local-only test: NOT tagged `@smoke` — creates a real order and a real `users` row, must never run via `npm run test:e2e:prod` (which greps for `@smoke` only).
- No new application code and no change to `/admin/setup`'s empty-table guard — the admin fixture is 100% test-side, shelling out directly to MySQL, mirroring the `uniqid()` fixture convention `.claude/rules/unit-testing.md` establishes for PHPUnit.
- No new npm dependency — use Node's built-in `child_process`.
- Both tests are independent: each creates and tears down its own editor fixture and its own order, consistent with `fullyParallel: true` in `playwright.config.ts` (no shared mutable state between tests in the same file).
- Requires Docker MySQL running (`docker compose up -d`) before the run, same as every other local/PHPUnit workflow in this repo.

---

### Task 1: Admin fixture helper + both e2e tests

**Files:**
- Create: `tests/e2e/helpers/admin-fixture.ts`
- Create: `tests/e2e/admin-order-flow.spec.ts`

**Interfaces:**
- Produces: `createTempEditor(): { email: string; password: string }` and `deleteTempEditor(email: string): void`, exported from the helper and consumed only by `admin-order-flow.spec.ts`.

- [ ] **Step 1: Write the admin fixture helper**

Create `tests/e2e/helpers/admin-fixture.ts`:

```typescript
import { execSync } from 'child_process';

export interface TempEditor {
  email: string;
  password: string;
}

const PASSWORD = 'PlaywrightEditor123!';

// Test-side only: no seeded admin/editor credentials exist anywhere in this
// project, and /admin/setup only works when the users table is empty (it
// isn't, in local dev). This shells out to the same Docker MySQL every other
// local workflow uses, mirroring the uniqid()-fixture convention PHPUnit
// tests already follow (.claude/rules/unit-testing.md).
export function createTempEditor(): TempEditor {
  const email = `e2e-order-flow-editor-${Date.now()}@example.com`;
  const hash = execSync(`php -r "echo password_hash('${PASSWORD}', PASSWORD_BCRYPT);"`)
    .toString()
    .trim();

  execSync(
    `docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e ` +
    `"INSERT INTO users (email, password_hash, role) VALUES ('${email}', '${hash}', 'editor')"`
  );

  return { email, password: PASSWORD };
}

export function deleteTempEditor(email: string): void {
  execSync(
    `docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e ` +
    `"DELETE FROM users WHERE email = '${email}'"`
  );
}
```

- [ ] **Step 2: Write the e2e spec**

Create `tests/e2e/admin-order-flow.spec.ts`:

```typescript
import { test, expect, Page } from '@playwright/test';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';

// Local-only: NOT tagged @smoke. Creates a real order and a real (throwaway)
// admin user row via the fixture helper — must never run against production
// (npm run test:e2e:prod only selects @smoke tests).

async function addProductToCart(page: Page, sku: string): Promise<void> {
  await page.goto(`/cs/shop/${sku}`);
  await page.locator('.add-to-cart-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/cart$/);
}

async function checkout(page: Page): Promise<string> {
  await page.locator('a[href="/cs/checkout"]').click();
  await expect(page).toHaveURL(/\/cs\/checkout$/);

  await page.locator('input[name="customer_name"]').fill('Playwright Order Flow');
  await page.locator('input[name="customer_email"]').fill(`order-flow-${Date.now()}@example.com`);
  await page.locator('input[name="customer_phone"]').fill('+420123456789');
  await page.locator('form.contact-form button[type="submit"]').click();

  await expect(page).toHaveURL(/\/cs\/checkout\/confirm$/);
  await page.locator('button', { hasText: /platit|pay/i }).click();

  await expect(page).toHaveURL(/\/cs\/order\/\S+$/);
  const match = page.url().match(/\/cs\/order\/([^/?#]+)/);
  if (!match) throw new Error(`Could not extract order number from URL: ${page.url()}`);
  return match[1];
}

async function loginAsEditor(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/admin/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/?$/);
}

async function setOrderStatus(page: Page, orderNumber: string, status: string): Promise<void> {
  await page.goto(`/admin/orders/${orderNumber}`);
  await page.locator('select[name="status"]').selectOption(status);
  await page.locator('form[action$="/status"] button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`/admin/orders/${orderNumber}$`));
  await expect(page.locator('select[name="status"]')).toHaveValue(status);
}

test('editor moves a 1-product order from paid to ready to completed', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    const orderNumber = await checkout(page);

    await loginAsEditor(page, editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'ready');
    await setOrderStatus(page, orderNumber, 'completed');
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('editor moves a 3-product order from paid to ready to cancelled', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await addProductToCart(page, 'NAR-SADA-KLASIK');
    await addProductToCart(page, 'NAR-SADA-PREMIUM');
    await addProductToCart(page, 'SVA-OBLOUK-BILY');
    const orderNumber = await checkout(page);

    await loginAsEditor(page, editor.email, editor.password);

    await setOrderStatus(page, orderNumber, 'ready');
    await setOrderStatus(page, orderNumber, 'cancelled');
  } finally {
    deleteTempEditor(editor.email);
  }
});
```

- [ ] **Step 3: Run both tests**

```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
npx playwright test admin-order-flow.spec.ts
```
Expected: `2 passed`. (Playwright's `webServer` config starts/reuses `php -S localhost:8080 -t www` automatically.)

If it fails, common causes to check:
- `docker compose exec` failing with "no such service" — confirm you're running from the repo root (same directory as `docker-compose.yml`), since `execSync` inherits the test runner's cwd.
- Login redirect assertion failing — confirm `createTempEditor()`'s INSERT actually landed (`docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e "SELECT * FROM users WHERE role='editor' ORDER BY id DESC LIMIT 1"`) and that the bcrypt hash round-trips (`php -r "var_dump(password_verify('PlaywrightEditor123!', '<hash>'));"`).
- Status assertion failing — confirm the order actually reached `paid` after checkout (GoPay dev bypass requires `gopay_go_id` to be empty in local `settings`, same precondition `checkout.spec.ts` already relies on).

- [ ] **Step 4: Run the whole local e2e suite to confirm no regressions**

```bash
npm run test:e2e
```
Expected: all tests pass, including the 4 pre-existing files plus this new one (6 total).

- [ ] **Step 5: Verify cleanup — no leftover editor rows**

```bash
docker compose exec -T db mysql -ubalonky -pbalonky balonkydecor -e \
  "SELECT COUNT(*) FROM users WHERE email LIKE 'e2e-order-flow-editor-%'"
```
Expected: `0` (both tests' `finally` blocks delete their own fixture even if an assertion above them failed mid-test — but if a prior manual run crashed before reaching `finally`, this step also serves as a manual sweep; re-run the DELETE by hand if it's non-zero).

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/helpers/admin-fixture.ts tests/e2e/admin-order-flow.spec.ts
git commit -m "test: add e2e coverage for editor-driven order status flow"
```
