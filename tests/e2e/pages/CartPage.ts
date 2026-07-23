import { BasePage } from './BasePage';

export class CartPage extends BasePage {
  get table() {
    return this.page.locator('.cart-table');
  }

  async goToCheckout(): Promise<void> {
    await this.page.locator(`a[href="/${this.lang}/checkout"]`).click();
  }
}
