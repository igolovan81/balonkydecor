import { BasePage } from './BasePage';

export class AccountPage extends BasePage {
  async logout(): Promise<void> {
    await this.page.goto(`/${this.lang}/logout`);
  }

  async goToDelete(): Promise<void> {
    await this.page.locator(`a[href="/${this.lang}/account/delete"]`).click();
  }

  // The delete form's confirm dialog must be accepted before submitting.
  async confirmDelete(currentPassword: string): Promise<void> {
    this.page.once('dialog', dialog => dialog.accept());
    await this.page.locator('input[name="current_password"]').fill(currentPassword);
    await this.page.locator('form.contact-form button[type="submit"]').click();
  }
}
