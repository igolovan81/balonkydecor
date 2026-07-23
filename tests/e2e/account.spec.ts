import { test, expect } from '@playwright/test';

// Local-only: NOT tagged @smoke. Creates and permanently deletes a real
// customer row — must never run against production (npm run test:e2e:prod
// only selects @smoke tests, but this comment makes the intent explicit
// for anyone editing the grep filter later).

test('register, log in, log out, log back in, and delete the account', async ({ page }) => {
  const email = `e2e-account-${Date.now()}@example.com`;
  const password = 'Playwright123!';

  await page.goto('/cs/register');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password_confirm"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/account$/);

  await page.goto('/cs/logout');
  await expect(page).toHaveURL(/\/cs\/login$/);

  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/account$/);

  await page.locator('a[href="/cs/account/delete"]').click();
  await expect(page).toHaveURL(/\/cs\/account\/delete$/);

  page.once('dialog', dialog => dialog.accept());
  await page.locator('input[name="current_password"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/$/);

  await page.goto('/cs/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page.locator('.form-error')).toBeVisible();
});
