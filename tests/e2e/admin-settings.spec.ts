import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import { createTempEditor, deleteTempEditor } from './helpers/admin-fixture';
import { LoginFlow } from './workflows/LoginFlow';
import { AdminSettingsPage } from './pages/AdminSettingsPage';

// Local-only: NOT tagged @smoke. Creates a real (throwaway) editor user via
// the fixture helper, and temporarily overwrites real site_name/contact_email
// settings rows (restored in `finally`) — must never run against production
// (npm run test:e2e:prod only selects @smoke tests).

function settingValueInDb(key: string): string | null {
  const out = execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-N', '-e',
    `SELECT \`value\` FROM settings WHERE \`key\` = '${key}'`,
  ]).toString().trim();
  return out === '' ? null : out;
}

test('editor updates site name and contact email, and the change persists', async ({ page }) => {
  const editor = createTempEditor();
  const settings = new AdminSettingsPage(page);
  const original = { site_name: '', contact_email: '' };
  try {
    await new LoginFlow(page).login(editor.email, editor.password);

    await settings.goto();
    original.site_name = await settings.field('site_name').inputValue();
    original.contact_email = await settings.field('contact_email').inputValue();

    await settings.field('site_name').fill('E2E Test Site Name');
    await settings.field('contact_email').fill('e2e-settings-test@example.com');
    await settings.save();
    await expect(page).toHaveURL(/\/admin\/settings$/);

    await settings.goto();
    await expect(settings.field('site_name')).toHaveValue('E2E Test Site Name');
    await expect(settings.field('contact_email')).toHaveValue('e2e-settings-test@example.com');
  } finally {
    await settings.goto();
    await settings.field('site_name').fill(original.site_name);
    await settings.field('contact_email').fill(original.contact_email);
    await settings.save();
    deleteTempEditor(editor.email);
  }
});

test('a settings key outside the admin-editable whitelist is silently ignored', async ({ page }) => {
  const editor = createTempEditor();
  const settings = new AdminSettingsPage(page);
  let originalSiteName = '';
  try {
    await new LoginFlow(page).login(editor.email, editor.password);
    await settings.goto();
    originalSiteName = await settings.field('site_name').inputValue();

    // SettingsController::save() only loops over its own KEYS whitelist, so a
    // key outside it (however it got into the POST body) is never written —
    // confirmed directly against the table, since nothing in the UI would
    // otherwise reveal whether an arbitrary key was persisted.
    await page.request.post('/admin/settings', {
      form: { site_name: originalSiteName, not_a_real_setting: 'should never be saved' },
    });

    expect(settingValueInDb('not_a_real_setting')).toBeNull();
  } finally {
    deleteTempEditor(editor.email);
  }
});
