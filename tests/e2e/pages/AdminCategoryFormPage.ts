import { Page } from '@playwright/test';

export class AdminCategoryFormPage {
  constructor(private readonly page: Page) {}

  async gotoNew(): Promise<void> {
    await this.page.goto('/admin/categories/new');
  }

  async goto(categoryId: number): Promise<void> {
    await this.page.goto(`/admin/categories/${categoryId}/edit`);
  }

  get slugInput() {
    return this.page.locator('input[name="slug"]');
  }

  nameInput(lang: string) {
    return this.page.locator(`input[name="t[${lang}][name]"]`);
  }

  // Tabs are pure client-side (no reload) — a language's fields aren't
  // fillable until its tab is clicked, since the inactive panel is hidden.
  async switchToLang(lang: string): Promise<void> {
    await this.page.locator(`button.lang-tab[data-lang="${lang}"]`).click();
  }

  async fillName(lang: string, name: string): Promise<void> {
    await this.switchToLang(lang);
    await this.nameInput(lang).fill(name);
  }

  async save(): Promise<void> {
    await this.page.locator('button[type="submit"]').click();
  }
}
