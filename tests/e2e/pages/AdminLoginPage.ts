import { Page } from '@playwright/test';

export class AdminLoginPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/login');
  }

  // AuthController::loginForm() redirects an already-authenticated session
  // straight to /admin without rendering the form — call this first if the
  // page already has an active admin session and the test needs to log in as
  // someone else.
  async logout(): Promise<void> {
    await this.page.goto('/admin/logout');
  }

  async login(email: string, password: string): Promise<void> {
    await this.page.locator('input[name="email"]').fill(email);
    await this.page.locator('input[name="password"]').fill(password);
    await this.page.locator('button[type="submit"]').click();
  }
}
