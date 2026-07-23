import { Page } from '@playwright/test';

export class AdminUserListPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/admin/users');
  }

  rowFor(userId: number) {
    return this.page.locator('tr', { has: this.page.locator(`form[action="/admin/users/${userId}/delete"]`) });
  }

  roleCellFor(userId: number) {
    return this.rowFor(userId).locator('td').nth(2);
  }

  // For users created outside a known id (e.g. via a direct page.request.post
  // where the redirect carries no id) — scopes by the email column instead.
  roleCellForEmail(email: string) {
    return this.page.locator('tr', { hasText: email }).locator('td').nth(2);
  }

  async changePassword(userId: number, newPassword: string): Promise<void> {
    const row = this.rowFor(userId);
    await row.locator('input[name="password"]').fill(newPassword);
    await row.locator('form[action$="/password"] button').click();
  }

  async deleteUser(userId: number): Promise<void> {
    this.page.once('dialog', (dialog) => dialog.accept());
    await this.rowFor(userId).locator('form[action$="/delete"] button').click();
  }
}
