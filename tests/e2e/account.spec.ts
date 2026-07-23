import { test, expect } from '@playwright/test';
import { RegisterPage } from './pages/RegisterPage';
import { LoginPage } from './pages/LoginPage';
import { AccountPage } from './pages/AccountPage';

// Local-only: NOT tagged @smoke. Creates and permanently deletes a real
// customer row — must never run against production (npm run test:e2e:prod
// only selects @smoke tests, but this comment makes the intent explicit
// for anyone editing the grep filter later).

test('register, log in, log out, log back in, and delete the account', async ({ page }) => {
  const email = `e2e-account-${Date.now()}@example.com`;
  const password = 'Playwright123!';

  const register = new RegisterPage(page);
  await register.goto();
  await register.register(email, password);
  await expect(page).toHaveURL(/\/cs\/account$/);

  const account = new AccountPage(page);
  await account.logout();
  await expect(page).toHaveURL(/\/cs\/login$/);

  const login = new LoginPage(page);
  await login.login(email, password);
  await expect(page).toHaveURL(/\/cs\/account$/);

  await account.goToDelete();
  await expect(page).toHaveURL(/\/cs\/account\/delete$/);

  await account.confirmDelete(password);
  await expect(page).toHaveURL(/\/cs\/$/);

  await login.goto();
  await login.login(email, password);
  await expect(login.error).toBeVisible();
});
