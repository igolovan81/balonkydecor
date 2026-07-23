import { BasePage } from './BasePage';

export class RegisterPage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/register`);
  }

  async register(email: string, password: string): Promise<void> {
    await this.page.locator('input[name="email"]').fill(email);
    await this.page.locator('input[name="password"]').fill(password);
    await this.page.locator('input[name="password_confirm"]').fill(password);
    await this.page.locator('form.contact-form button[type="submit"]').click();
  }
}
