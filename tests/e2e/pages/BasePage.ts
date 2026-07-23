import { Page } from '@playwright/test';

// Shared nav elements present in templates/layout/base.twig on every public page.
export class BasePage {
  constructor(protected readonly page: Page, protected readonly lang = 'cs') {}

  get langSwitcher() {
    return this.page.locator('.lang-switcher');
  }

  get cartLink() {
    return this.page.locator('a.cart-link');
  }

  async switchLanguage(label: string): Promise<void> {
    await this.langSwitcher.locator('a', { hasText: label }).click();
  }
}
