import { Page, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/AdminLoginPage';

// Workflows orchestrate one or more page objects plus assertions for a
// multi-step action shared across specs (here: admin/editor login) — page
// objects themselves stay assertion-free per .claude/rules/e2e-testing.md.
export class LoginFlow {
  constructor(private readonly page: Page) {}

  async loginAsEditor(email: string, password: string): Promise<void> {
    const adminLogin = new AdminLoginPage(this.page);
    await adminLogin.goto();
    await adminLogin.login(email, password);
    await expect(this.page).toHaveURL(/\/admin\/?$/);
  }
}
