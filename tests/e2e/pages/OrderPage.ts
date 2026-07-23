import { BasePage } from './BasePage';

export class OrderPage extends BasePage {
  get status() {
    return this.page.locator('.order-status');
  }

  // Order numbers are opaque tokens minted by OrderModel::create(), not
  // guessable — the only way a test learns one is by parsing the redirect URL.
  static numberFromUrl(url: string): string {
    const match = url.match(/\/order\/([^/?#]+)/);
    if (!match) throw new Error(`Could not extract order number from URL: ${url}`);
    return match[1];
  }
}
