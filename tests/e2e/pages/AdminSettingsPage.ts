import { Page } from '@playwright/test';

export class AdminSettingsPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/settings');
  }

  field(key: string) {
    return this.page.locator(`[name="${key}"]`);
  }

  async save(): Promise<void> {
    await this.page.locator('button[type="submit"]').click();
  }
}
