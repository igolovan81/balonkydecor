import { BasePage } from './BasePage';

export interface CustomerDetails {
  name: string;
  email: string;
  phone: string;
}

export class CheckoutPage extends BasePage {
  async fillCustomerDetails(details: CustomerDetails): Promise<void> {
    await this.page.locator('input[name="customer_name"]').fill(details.name);
    await this.page.locator('input[name="customer_email"]').fill(details.email);
    await this.page.locator('input[name="customer_phone"]').fill(details.phone);
    await this.page.locator('form.contact-form button[type="submit"]').click();
  }

  // Lands on the /checkout/confirm step; the pay button submits the GoPay
  // dev-bypass flow and redirects straight to the order status page.
  async pay(): Promise<void> {
    await this.page.locator('button', { hasText: /platit|pay/i }).click();
  }
}
