import { Page, expect } from '@playwright/test';
import { AdminLoginPage } from '../pages/AdminLoginPage';

// Workflows orchestrate one or more page objects plus assertions for a
// multi-step action shared across specs (here: admin/editor login) — page
// objects themselves stay assertion-free per .claude/rules/e2e-testing.md.
export class LoginFlow {
  constructor(private readonly page: Page) {}

  // Role-agnostic despite the class name's origin (extracted from admin/
  // editor order-flow tests) — it only fills AdminLoginPage's two fields and
  // submits, so it works identically for an admin-role session too.
  async login(email: string, password: string): Promise<void> {
    const adminLogin = new AdminLoginPage(this.page);
    await adminLogin.goto();
    await adminLogin.login(email, password);
    await expect(this.page).toHaveURL(/\/admin\/?$/);
  }
}
