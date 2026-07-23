import { Locator, Response } from '@playwright/test';
import { BasePage } from './BasePage';

export class ShopPage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/shop`);
  }

  async gotoProduct(sku: string): Promise<Response | null> {
    return this.page.goto(`/${this.lang}/shop/${sku}`);
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
