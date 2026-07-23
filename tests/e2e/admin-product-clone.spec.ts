import { test, expect } from '@playwright/test';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';
import { createTempProduct, deleteTempProduct, productExistsWithSku } from './helpers/product-fixture';
import { AdminProductListPage } from './pages/AdminProductListPage';
import { AdminProductFormPage } from './pages/AdminProductFormPage';
import { LoginFlow } from './workflows/LoginFlow';

// Local-only: NOT tagged @smoke. Creates real (throwaway) product and admin
// user rows via fixture helpers — must never run against production
// (npm run test:e2e:prod only selects @smoke tests).

// Minimal valid 1x1 transparent PNG — ImageUploader requires real, decodable
// image bytes (mime_content_type + GD's imagecreatefrompng), not just a
// file with an image-like name.
const PNG_BUFFER = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64'
);

test('editor clones a product into an inactive copy with a new SKU and the same name', async ({ page }) => {
  const editor = createTempEditor();
  const source = createTempProduct();
  let cloneId: number | undefined;
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const list = new AdminProductListPage(page);
    await list.goto();
    await list.cloneButtonFor(source.id).click();

    await expect(page).toHaveURL(/\/admin\/products\/\d+\/edit$/);
    cloneId = AdminProductFormPage.idFromUrl(page.url());
    expect(cloneId).not.toBe(source.id);

    const clonedForm = new AdminProductFormPage(page);
    await expect(clonedForm.skuInput).not.toHaveValue(source.sku);
    await expect(clonedForm.activeCheckbox).not.toBeChecked();
    await expect(page.locator('input[name="t[cs][name]"]')).toHaveValue('E2E Clone Test Product');

    // The source product is untouched by a plain clone.
    await clonedForm.goto(source.id);
    await expect(clonedForm.skuInput).toHaveValue(source.sku);
    await expect(clonedForm.activeCheckbox).toBeChecked();
  } finally {
    if (cloneId) deleteTempProduct(cloneId);
    deleteTempProduct(source.id);
    deleteTempEditor(editor.email);
  }
});

test('editor splits an image off a product; the image moves and the source deactivates once it has none left', async ({ page }) => {
  const editor = createTempEditor();
  const source = createTempProduct();
  let cloneId: number | undefined;
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const form = new AdminProductFormPage(page);
    await form.goto(source.id);
    await form.imageInput.setInputFiles({ name: 'test.png', mimeType: 'image/png', buffer: PNG_BUFFER });
    await form.save();
    await expect(page).toHaveURL(/\/admin\/products$/);

    await form.goto(source.id);
    await expect(form.existingImages).toHaveCount(1);

    page.once('dialog', (dialog) => dialog.accept());
    await form.splitButtonForImage(0).click();

    // The pre-click URL already matches /admin/products/\d+/edit$ (it's the
    // source's own edit page) — wait for it to actually change before
    // reading the "new" id, or this races and silently reads the old one.
    await page.waitForURL((url) => url.pathname !== `/admin/products/${source.id}/edit`);
    await expect(page).toHaveURL(/\/admin\/products\/\d+\/edit$/);
    cloneId = AdminProductFormPage.idFromUrl(page.url());
    expect(cloneId).not.toBe(source.id);
    await expect(form.existingImages).toHaveCount(1);

    // The image moved, it wasn't copied — the source now has none and is
    // deactivated as a result (ProductModel::clone()'s "last image left" rule).
    await form.goto(source.id);
    await expect(form.existingImages).toHaveCount(0);
    await expect(form.activeCheckbox).not.toBeChecked();
  } finally {
    if (cloneId) deleteTempProduct(cloneId);
    deleteTempProduct(source.id);
    deleteTempEditor(editor.email);
  }
});

test('cloning a nonexistent product returns 404', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const response = await page.request.post('/admin/products/999999999/clone');
    expect(response.status()).toBe(404);
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('an unauthenticated clone request is redirected to login and creates nothing', async ({ page }) => {
  const source = createTempProduct();
  try {
    // No login here — this page/context has no admin session yet.
    const response = await page.request.post(`/admin/products/${source.id}/clone`, { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toBe('/admin/login');

    // ProductModel::uniqueSku() would deterministically name a clone
    // "{sku}-2" — checking for its absence avoids asserting on a global
    // COUNT(*), which other specs running in parallel would make flaky.
    expect(productExistsWithSku(`${source.sku}-2`)).toBe(false);
  } finally {
    deleteTempProduct(source.id);
  }
});
