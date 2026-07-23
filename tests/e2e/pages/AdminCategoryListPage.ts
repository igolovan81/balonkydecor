import { Page } from '@playwright/test';

export class AdminCategoryListPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/categories');
  }

  rowFor(categoryId: number) {
    return this.page.locator('tr', { has: this.page.locator(`a[href="/admin/categories/${categoryId}/edit"]`) });
  }

  nameCellFor(categoryId: number) {
    return this.rowFor(categoryId).locator('td').nth(1);
  }

  // The create form redirects to this list, not to an edit page carrying the
  // new id in the URL (unlike products' clone flow) — so tests that create a
  // category through the UI read its id back from the visible id column,
  // scoped by the row containing the slug they just set.
  async idForSlug(slug: string): Promise<number> {
    const row = this.page.locator('tr', { hasText: slug });
    const idText = await row.locator('td').first().innerText();
    return parseInt(idText.trim(), 10);
  }

  async deleteCategory(categoryId: number): Promise<void> {
    this.page.once('dialog', (dialog) => dialog.accept());
    await this.rowFor(categoryId).locator('form[action$="/delete"] button').click();
  }
}
