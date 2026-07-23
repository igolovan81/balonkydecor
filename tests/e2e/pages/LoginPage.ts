import { BasePage } from './BasePage';

export class LoginPage extends BasePage {
  get error() {
    return this.page.locator('.form-error');
  }

  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/login`);
  }

  async login(email: string, password: string): Promise<void> {
    await this.page.locator('input[name="email"]').fill(email);
    await this.page.locator('input[name="password"]').fill(password);
    await this.page.locator('form.contact-form button[type="submit"]').click();
  }
}
