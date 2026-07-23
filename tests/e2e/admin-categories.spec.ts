import { test, expect } from '@playwright/test';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';
import { createTempCategory, deleteTempCategory } from './helpers/category-fixture';
import { createTempProduct, deleteTempProduct } from './helpers/product-fixture';
import { LoginFlow } from './workflows/LoginFlow';
import { AdminCategoryListPage } from './pages/AdminCategoryListPage';
import { AdminCategoryFormPage } from './pages/AdminCategoryFormPage';

// Local-only: NOT tagged @smoke. Creates real (throwaway) editor/category/
// product rows via fixture helpers — must never run against production
// (npm run test:e2e:prod only selects @smoke tests).
//
// CategoryController has no requireRole() call — unlike Users, an
// editor-role session has full category CRUD access, so createTempEditor()
// (not createTempAdmin()) is enough for every test here.

const LANGS = ['cs', 'sk', 'en', 'uk', 'ru'];

test('editor creates a category through the admin form; it appears in the list', async ({ page }) => {
  const editor = createTempEditor();
  const uniqueSuffix = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  let categoryId: number | undefined;
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const form = new AdminCategoryFormPage(page);
    await form.gotoNew();
    // Fill every language's name so Translator::autoFill() has nothing left
    // blank to translate — it only calls the real MyMemory API for a
    // language whose name field is empty (see src/Services/Translator.php).
    // The slug field is readonly on create; leave it alone and let the
    // page's own JS derive it from the English name instead of unlocking it.
    for (const lang of LANGS) {
      await form.fillName(lang, `E2E Category ${lang} ${uniqueSuffix}`);
    }
    const slug = await form.slugInput.inputValue();
    await form.save();
    await expect(page).toHaveURL(/\/admin\/categories$/);

    const list = new AdminCategoryListPage(page);
    categoryId = await list.idForSlug(slug);
    await expect(list.nameCellFor(categoryId)).toHaveText(`E2E Category cs ${uniqueSuffix}`);
  } finally {
    if (categoryId) deleteTempCategory(categoryId);
    deleteTempEditor(editor.email);
  }
});

test("editor edits a category's name; the change is reflected in the list", async ({ page }) => {
  const editor = createTempEditor();
  const category = createTempCategory();
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const form = new AdminCategoryFormPage(page);
    await form.goto(category.id);
    await form.fillName('cs', 'E2E Renamed Category');
    await form.save();
    await expect(page).toHaveURL(/\/admin\/categories$/);

    const list = new AdminCategoryListPage(page);
    await expect(list.nameCellFor(category.id)).toHaveText('E2E Renamed Category');
  } finally {
    deleteTempEditor(editor.email);
    deleteTempCategory(category.id);
  }
});

test('deleting a category with no products succeeds', async ({ page }) => {
  const editor = createTempEditor();
  const category = createTempCategory();
  let deleted = false;
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const list = new AdminCategoryListPage(page);
    await list.goto();
    await list.deleteCategory(category.id);
    await expect(page).toHaveURL(/\/admin\/categories$/);
    await expect(list.rowFor(category.id)).toHaveCount(0);
    deleted = true;
  } finally {
    deleteTempEditor(editor.email);
    if (!deleted) deleteTempCategory(category.id);
  }
});

test('deleting a category that still has products is blocked', async ({ page }) => {
  const editor = createTempEditor();
  const category = createTempCategory();
  const product = createTempProduct(category.id);
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    const list = new AdminCategoryListPage(page);
    await list.goto();
    await list.deleteCategory(category.id);
    await expect(page).toHaveURL(/\/admin\/categories$/);

    // CategoryModel::hasProducts() blocks the delete before it ever reaches
    // the DB — the row is still there (products.category_id has no ON
    // DELETE clause, so an unguarded delete would otherwise hit a raw FK
    // constraint violation instead of this friendly no-op).
    await expect(list.rowFor(category.id)).toHaveCount(1);
  } finally {
    deleteTempProduct(product.id);
    deleteTempCategory(category.id);
    deleteTempEditor(editor.email);
  }
});
