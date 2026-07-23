import { Page } from '@playwright/test';

export class AdminLoginPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/login');
  }

  async login(email: string, password: string): Promise<void> {
    await this.page.locator('input[name="email"]').fill(email);
    await this.page.locator('input[name="password"]').fill(password);
    await this.page.locator('button[type="submit"]').click();
  }
}
