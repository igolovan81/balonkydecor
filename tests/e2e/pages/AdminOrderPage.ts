import { Page } from '@playwright/test';

export class AdminOrderPage {
  constructor(private readonly page: Page) {}

  get statusSelect() {
    return this.page.locator('select[name="status"]');
  }

  async goto(orderNumber: string): Promise<void> {
    await this.page.goto(`/admin/orders/${orderNumber}`);
  }

  async setStatus(status: string): Promise<void> {
    await this.statusSelect.selectOption(status);
    await this.page.locator('form[action$="/status"] button[type="submit"]').click();
  }
}
