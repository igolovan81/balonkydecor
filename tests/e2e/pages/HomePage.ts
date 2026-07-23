import { BasePage } from './BasePage';

export class HomePage extends BasePage {
  async goto(): Promise<void> {
    await this.page.goto(`/${this.lang}/`);
  }
}
