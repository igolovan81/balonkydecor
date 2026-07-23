import { Page } from '@playwright/test';

export class AdminProductListPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/products');
  }

  private rowFor(productId: number) {
    return this.page.locator('tr', { has: this.page.locator(`a[href="/admin/products/${productId}/edit"]`) });
  }

  cloneButtonFor(productId: number) {
    return this.rowFor(productId).locator('form[action$="/clone"] button');
  }
}
