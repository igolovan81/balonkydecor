import { Page } from '@playwright/test';

export class AdminOrderListPage {
  constructor(private readonly page: Page) {}

  async goto(status?: string): Promise<void> {
    await this.page.goto(status ? `/admin/orders?status=${status}` : '/admin/orders');
  }

  get statusFilter() {
    return this.page.locator('form select[name="status"]');
  }

  // Each order row renders two <a href="/admin/orders/{number}"> links (the
  // number cell and the explicit "detail" link) — .first() picks either.
  linkFor(orderNumber: string) {
    return this.page.locator(`a[href="/admin/orders/${orderNumber}"]`).first();
  }
}
