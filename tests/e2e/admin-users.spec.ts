import { test, expect } from '@playwright/test';
import { createTempAdmin, createTempEditor, deleteTempAdmin, deleteTempEditor } from './helpers/admin-fixture';
import { LoginFlow } from './workflows/LoginFlow';
import { AdminLoginPage } from './pages/AdminLoginPage';
import { AdminUserListPage } from './pages/AdminUserListPage';
import { AdminUserFormPage } from './pages/AdminUserFormPage';

// Local-only: NOT tagged @smoke. Creates real (throwaway) admin/editor user
// rows via fixture helpers — must never run against production (npm run
// test:e2e:prod only selects @smoke tests).
//
// UserController::index/createForm/createSubmit/changePassword/delete all
// call requireRole($response, 'admin') first, so every test here needs an
// admin-role session, not the editor-role createTempEditor() used elsewhere.

test('admin creates a new user, who can then log in', async ({ page }) => {
  const admin = createTempAdmin();
  const newUserEmail = `e2e-created-user-${Date.now()}@example.com`;
  const newUserPassword = 'PlaywrightNewUser123!';
  try {
    await new LoginFlow(page).login(admin.email, admin.password);

    const form = new AdminUserFormPage(page);
    await form.goto();
    await form.create(newUserEmail, newUserPassword, 'editor');
    await expect(page).toHaveURL(/\/admin\/users$/);

    const list = new AdminUserListPage(page);
    await expect(list.roleCellForEmail(newUserEmail)).toHaveText('editor');

    const login = new AdminLoginPage(page);
    await login.logout(); // AuthController::loginForm() skips the form entirely while a session is active
    await login.goto();
    await login.login(newUserEmail, newUserPassword);
    await expect(page).toHaveURL(/\/admin\/?$/);
  } finally {
    deleteTempEditor(newUserEmail);
    deleteTempAdmin(admin.email);
  }
});

test("admin changes another user's password; the old password stops working and the new one succeeds", async ({ page }) => {
  const admin = createTempAdmin();
  const target = createTempEditor();
  const newPassword = 'PlaywrightChangedPw123!';
  try {
    await new LoginFlow(page).login(admin.email, admin.password);

    const list = new AdminUserListPage(page);
    await list.goto();
    await list.changePassword(target.id, newPassword);
    await expect(page).toHaveURL(/\/admin\/users$/);

    const login = new AdminLoginPage(page);
    await login.logout(); // AuthController::loginForm() skips the form entirely while a session is active
    await login.goto();
    await login.login(target.email, target.password);
    await expect(page).toHaveURL(/\/admin\/login$/);

    await login.goto();
    await login.login(target.email, newPassword);
    await expect(page).toHaveURL(/\/admin\/?$/);
  } finally {
    deleteTempEditor(target.email);
    deleteTempAdmin(admin.email);
  }
});

test('an admin cannot delete their own account', async ({ page }) => {
  const admin = createTempAdmin();
  try {
    await new LoginFlow(page).login(admin.email, admin.password);

    const list = new AdminUserListPage(page);
    await list.goto();
    await list.deleteUser(admin.id);
    await expect(page).toHaveURL(/\/admin\/users$/);

    // Still present — UserController::delete() refuses to delete the
    // currently logged-in admin's own row.
    await expect(list.rowFor(admin.id)).toHaveCount(1);
  } finally {
    deleteTempAdmin(admin.email);
  }
});

test('an editor session is forbidden from the users admin area', async ({ page }) => {
  const editor = createTempEditor();
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    await page.goto('/admin/users');
    await expect(page).toHaveURL(/\/admin\/?$/);
  } finally {
    deleteTempEditor(editor.email);
  }
});

test('a tampered role value outside admin/editor defaults to admin', async ({ page }) => {
  const admin = createTempAdmin();
  const newUserEmail = `e2e-tampered-role-${Date.now()}@example.com`;
  try {
    await new LoginFlow(page).login(admin.email, admin.password);

    // Bypasses the <select>'s fixed option list to simulate a tampered
    // request — UserController::createSubmit() falls back to 'admin' for
    // any role value outside ['admin', 'editor'], not 'editor'.
    await page.request.post('/admin/users/new', {
      form: { email: newUserEmail, password: 'PlaywrightTampered123!', role: 'superadmin' },
    });

    const list = new AdminUserListPage(page);
    await list.goto();
    await expect(list.roleCellForEmail(newUserEmail)).toHaveText('admin');
  } finally {
    deleteTempAdmin(newUserEmail);
    deleteTempAdmin(admin.email);
  }
});
