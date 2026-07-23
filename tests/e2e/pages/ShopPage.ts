import { Locator } from '@playwright/test';
import { BasePage } from './BasePage';

export class ShopPage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/shop`);
  }

  async search(term: string): Promise<void> {
    await this.page.fill('.nav-search input[name="q"]', term);
    await this.page.click('.nav-search button[type="submit"]');
  }

  productCard(sku: string): Locator {
    return this.page.locator('.product-card-wrap', {
      has: this.page.locator(`a[href="/${this.lang}/shop/${sku}"]`),
    });
  }
}
