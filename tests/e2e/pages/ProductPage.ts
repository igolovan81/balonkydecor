import { BasePage } from './BasePage';

export class ProductPage extends BasePage {
  get heading() {
    return this.page.locator('h1');
  }

  async goto(sku: string): Promise<void> {
    await this.page.goto(`/${this.lang}/shop/${sku}`);
  }

  async addToCart(): Promise<void> {
    await this.page.locator('.add-to-cart-form button[type="submit"]').click();
  }
}
