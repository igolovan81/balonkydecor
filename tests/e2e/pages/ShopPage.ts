import { Response } from '@playwright/test';
import { BasePage } from './BasePage';

export class ShopPage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/shop`);
  }

  async gotoProduct(sku: string): Promise<Response | null> {
    return this.page.goto(`/${this.lang}/shop/${sku}`);
  }
}
