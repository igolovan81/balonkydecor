import { Page } from '@playwright/test';

export class AdminProductFormPage {
  constructor(private readonly page: Page) {}

  async goto(productId: number): Promise<void> {
    await this.page.goto(`/admin/products/${productId}/edit`);
  }

  get skuInput() {
    return this.page.locator('input[name="sku"]');
  }

  get activeCheckbox() {
    return this.page.locator('input[name="is_active"]');
  }

  get imageInput() {
    return this.page.locator('input[name="image"]');
  }

  get saveButton() {
    return this.page.locator('form.admin-form--wide button[type="submit"]');
  }

  get existingImages() {
    return this.page.locator('.delete-image-btn');
  }

  splitButtonForImage(index: number) {
    return this.page.locator('.split-image-btn').nth(index);
  }

  async save(): Promise<void> {
    await this.saveButton.click();
  }

  // Product IDs aren't guessable — the only way a test learns the ID minted
  // by a clone/split action is by parsing the redirect URL (mirrors
  // OrderPage.numberFromUrl()).
  static idFromUrl(url: string): number {
    const match = url.match(/\/admin\/products\/(\d+)\/edit/);
    if (!match) throw new Error(`Could not extract product id from URL: ${url}`);
    return parseInt(match[1], 10);
  }
}
