import { Page } from '@playwright/test';

export class AdminUserFormPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/users/new');
  }

  async create(email: string, password: string, role: 'admin' | 'editor'): Promise<void> {
    await this.page.locator('input[name="email"]').fill(email);
    await this.page.locator('input[name="password"]').fill(password);
    await this.page.locator('select[name="role"]').selectOption(role);
    await this.page.locator('button[type="submit"]').click();
  }
}
