# Account Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a self-service "delete my account" action to the customer account area, and a local-only Playwright e2e spec that exercises register → login → logout → login → delete-account end to end.

**Architecture:** Hard-delete the `customers` row (no new migration — `orders.customer_id` already has `ON DELETE SET NULL` and `orders` keeps its own name/email/phone snapshot columns, so past orders survive unlinked). A new `AccountController::deleteAccount()` verifies the current password, calls `CustomerModel::delete()`, clears the session, and redirects home. The form lives as a new "danger zone" section at the bottom of the existing `customer-info.twig` page.

**Tech Stack:** PHP 8.1 / Slim 4 / PDO (backend), Twig 3 (template), plain CSS (`www/assets/css/style.css`), PHPUnit 11 against real Docker MySQL (unit test), Playwright + TypeScript (e2e test).

## Global Constraints

- Hard delete only — no soft delete/anonymization, no new migration (spec: `docs/superpowers/specs/2026-07-23-account-delete-design.md`).
- Deletion requires re-entering the current password (`password_verify()` against `password_hash`), plus a JS `confirm()` prompt on submit.
- No email sent on deletion.
- Reuse the existing `account.error_current_password` translation key for a wrong/empty password on the delete form — do **not** add a separate `account.error_delete_password` key (this plan deviates from the spec here: identical English text made a second key pure duplication, so it was dropped during planning to stay DRY across all 5 language files).
- New Playwright spec must NOT be tagged `@smoke` — it creates and destroys a real customer row and must never run via `npm run test:e2e:prod`.
- All 5 language files (`lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`) must gain identical new keys.
- Every model method uses a prepared statement with bound parameters (project convention, `.claude/rules/database.md`).
- TDD: write the failing PHPUnit test before `CustomerModel::delete()` exists.

---

### Task 1: `CustomerModel::delete()` (TDD)

**Files:**
- Modify: `src/Models/CustomerModel.php` (append after `updateEmail()`, currently the last method, ending at line 67 with the class closing brace on line 68)
- Test: `tests/Unit/Models/CustomerModelTest.php` (append before the class closing brace on line 117)

**Interfaces:**
- Produces: `CustomerModel::delete(int $id): void` — used by Task 2's `AccountController::deleteAccount()`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Unit/Models/CustomerModelTest.php`, just before the final closing `}` on line 117:

```php
    public function test_delete_removes_customer(): void
    {
        $email = 'delete-test-' . uniqid() . '@example.com';
        $id    = CustomerModel::create($email, self::$hash);

        CustomerModel::delete($id);

        $this->assertNull(CustomerModel::findById($id));
    }
```

Note: this creates its own throwaway customer via `uniqid()` rather than touching `self::$customerId` — that shared fixture is read by other tests in this class (`test_findById_returns_created_customer`, `test_updateEmail_updates_email`, etc.) and must survive the whole test run.

- [ ] **Step 2: Run test to verify it fails**

Requires Docker MySQL running first:
```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
php vendor/bin/phpunit --filter test_delete_removes_customer tests/Unit/Models/CustomerModelTest.php
```
Expected: FAIL — `Call to undefined method App\Models\CustomerModel::delete()`

- [ ] **Step 3: Write minimal implementation**

Add this method to `src/Models/CustomerModel.php`, after `updateEmail()` (before the class's closing `}` on line 68):

```php
    public static function delete(int $id): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->execute([$id]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php vendor/bin/phpunit --filter test_delete_removes_customer tests/Unit/Models/CustomerModelTest.php
```
Expected: `OK (1 test, 1 assertion)`

- [ ] **Step 5: Run the full unit suite to confirm no regressions**

```bash
php vendor/bin/phpunit --testdox
```
Expected: all tests pass (no failures), same count as before plus this one new test.

- [ ] **Step 6: Commit**

```bash
git add src/Models/CustomerModel.php tests/Unit/Models/CustomerModelTest.php
git commit -m "feat: add CustomerModel::delete()"
```

---

### Task 2: Route + `AccountController::deleteAccount()`

**Files:**
- Modify: `src/routes.php:176` (add new route directly after the existing `POST /{lang}/account/password` line)
- Modify: `src/Controllers/AccountController.php` (add new public method after `passwordSubmit()`, which currently ends right before the `private function requireLogin` block)

**Interfaces:**
- Consumes: `CustomerModel::delete(int $id): void` (Task 1); `AccountController::requireLogin(Request $request): ?array` (existing private method, already used by every other logged-in action in this controller); `BaseController::flash(string $type, string $message, array $params = []): void` (existing).
- Produces: `POST /{lang}/account/delete` route, handled by `AccountController::deleteAccount()`. On wrong/empty password it re-renders `public/account/customer-info.twig` with a `delete_error` template variable (Task 3 reads this).

- [ ] **Step 1: Add the route**

In `src/routes.php`, immediately after this existing line (currently line 176):
```php
$app->post('/{lang}/account/password', AccountController::class . ':passwordSubmit');
```
add:
```php
$app->post('/{lang}/account/delete',   AccountController::class . ':deleteAccount');
```

- [ ] **Step 2: Add the controller method**

In `src/Controllers/AccountController.php`, insert this method immediately after `passwordSubmit()` (i.e., directly before the `private function requireLogin` method):

```php
    public function deleteAccount(Request $request, Response $response, array $args): Response
    {
        $customer = $this->requireLogin($request);
        $lang     = $request->getAttribute('lang');
        if (!$customer) {
            return $response->withHeader('Location', "/{$lang}/login")->withStatus(302);
        }

        $body            = (array) $request->getParsedBody();
        $currentPassword = $body['current_password'] ?? '';

        if ($currentPassword === '' || !password_verify($currentPassword, $customer['password_hash'])) {
            return $this->render($request, $response, 'public/account/customer-info.twig', [
                'account'      => $customer,
                'delete_error' => 'account.error_current_password',
            ]);
        }

        CustomerModel::delete((int) $customer['id']);
        unset($_SESSION['customer']);
        $this->flash('success', 'account.delete_success');

        return $response->withHeader('Location', "/{$lang}/")->withStatus(302);
    }
```

`CustomerModel` is already imported at the top of this file (used by every other method here), so no new `use` statement is needed.

- [ ] **Step 3: Manual smoke check (controllers are untested per project convention)**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "Unauthenticated delete: %{http_code}\n" -X POST http://localhost:8080/cs/account/delete
kill $SERVER_PID
```
Expected: `Unauthenticated delete: 302` (redirected to `/cs/login` since there's no session cookie).

- [ ] **Step 4: Commit**

```bash
git add src/routes.php src/Controllers/AccountController.php
git commit -m "feat: add POST /{lang}/account/delete route and controller action"
```

---

### Task 3: UI — danger zone section + `.btn-danger` style

**Files:**
- Modify: `templates/public/account/customer-info.twig` (insert new block between the existing form's closing `</form>` on line 45 and the closing `</div>` on line 46)
- Modify: `www/assets/css/style.css` (insert two new rules directly after `.btn-primary:hover` on line 111)

**Interfaces:**
- Consumes: `delete_error` template variable (Task 2, `AccountController::deleteAccount()`); `account` template variable (already passed by `index()`); translation keys from Task 4.
- Produces: nothing consumed by later tasks — this is UI only.

- [ ] **Step 1: Add the danger-zone HTML**

In `templates/public/account/customer-info.twig`, the current end of the file (lines 44-48) is:

```twig
            <button type="submit" class="btn btn-primary">{{ t('account.update_submit') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

Replace it with:

```twig
            <button type="submit" class="btn btn-primary">{{ t('account.update_submit') }}</button>
        </form>

        <hr>
        <h2>{{ t('account.delete_account') }}</h2>
        <p>{{ t('account.delete_warning') }}</p>
        {% if delete_error %}
        <p class="form-error">{{ t(delete_error) }}</p>
        {% endif %}
        <form action="/{{ lang }}/account/delete" method="POST" class="contact-form"
              onsubmit="return confirm('{{ t('account.delete_confirm') }}')">
            <div class="form-group">
                <label for="delete_current_password">{{ t('account.current_password') }}</label>
                <input type="password" id="delete_current_password" name="current_password" required>
            </div>
            <button type="submit" class="btn btn-danger">{{ t('account.delete_account') }}</button>
        </form>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 2: Add the `.btn-danger` style**

In `www/assets/css/style.css`, the current lines 109-111 are:

```css
.btn { display: inline-block; padding: .75rem 2rem; border-radius: 2px; font-family: var(--ui-font); font-size: .9rem; letter-spacing: .08em; text-decoration: none; border: none; cursor: pointer; }
.btn-primary { background: var(--accent); color: var(--text-inverse); }
.btn-primary:hover { background: var(--accent-dark); }
```

Add two new lines directly after them:

```css
.btn-danger { background: #c0392b; color: var(--text-inverse); }
.btn-danger:hover { background: #a5311f; }
```

(One-off color per `.claude/rules/css-styling.md` — used only for this single button, so it stays a literal rather than a new `:root` token.)

- [ ] **Step 3: Manual visual check**

```bash
docker compose up -d
php -S localhost:8080 -t www &
SERVER_PID=$!
sleep 1
curl -s http://localhost:8080/cs/account | grep -o 'btn-danger' | head -1
kill $SERVER_PID
```
Expected output: `btn-danger` (confirms the template renders — full visual/behavior check happens in Task 5's manual verification once translations exist, since `t()` on a missing key would otherwise render an empty string, not break the page).

- [ ] **Step 4: Commit**

```bash
git add templates/public/account/customer-info.twig www/assets/css/style.css
git commit -m "feat: add delete-account danger zone UI"
```

---

### Task 4: Translations (all 5 languages)

**Files:**
- Modify: `lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`

**Interfaces:**
- Produces: `account.delete_account`, `account.delete_warning`, `account.delete_confirm`, `account.delete_success` translation keys, consumed by Task 3's template. Reuses the existing `account.error_current_password` key (no new key for that).

- [ ] **Step 1: Add keys to `lang/cs.json`**

Current lines 2-3 are:
```json
  "account.current_password": "Aktuální heslo (jen při změně e-mailu)",
  "account.email": "E-mail",
```

Insert between them (alphabetically, `delete_*` sorts before `email`):
```json
  "account.current_password": "Aktuální heslo (jen při změně e-mailu)",
  "account.delete_account": "Smazat účet",
  "account.delete_confirm": "Opravdu chcete trvale smazat svůj účet? Tuto akci nelze vrátit zpět.",
  "account.delete_success": "Váš účet byl úspěšně smazán.",
  "account.delete_warning": "Smazání účtu je trvalé a nelze jej vrátit zpět. Vaše dřívější objednávky zůstanou zachovány pro naše záznamy, ale již nebudou propojeny s vaším účtem.",
  "account.email": "E-mail",
```

- [ ] **Step 2: Add keys to `lang/en.json`**

Same insertion point (between `account.current_password` and `account.email`):
```json
  "account.current_password": "Current password (only needed to change email)",
  "account.delete_account": "Delete Account",
  "account.delete_confirm": "Are you sure you want to permanently delete your account? This cannot be undone.",
  "account.delete_success": "Your account has been deleted.",
  "account.delete_warning": "Deleting your account is permanent and cannot be undone. Your past orders are kept for our records, but will no longer be linked to your account.",
  "account.email": "Email",
```

- [ ] **Step 3: Add keys to `lang/ru.json`**

Same insertion point:
```json
  "account.current_password": "Текущий пароль (только при смене e-mail)",
  "account.delete_account": "Удалить аккаунт",
  "account.delete_confirm": "Вы уверены, что хотите навсегда удалить свой аккаунт? Это действие нельзя отменить.",
  "account.delete_success": "Ваш аккаунт был удалён.",
  "account.delete_warning": "Удаление аккаунта необратимо. Ваши прошлые заказы будут сохранены для наших записей, но больше не будут связаны с вашим аккаунтом.",
  "account.email": "Эл. почта",
```

- [ ] **Step 4: Add keys to `lang/uk.json`**

Same insertion point:
```json
  "account.current_password": "Поточний пароль (лише при зміні e-mail)",
  "account.delete_account": "Видалити обліковий запис",
  "account.delete_confirm": "Ви впевнені, що хочете остаточно видалити ваш обліковий запис? Цю дію не можна скасувати.",
  "account.delete_success": "Ваш обліковий запис видалено.",
  "account.delete_warning": "Видалення облікового запису є остаточним і його не можна скасувати. Ваші попередні замовлення залишаться в наших записах, але більше не будуть пов'язані з вашим обліковим записом.",
  "account.email": "Ел. пошта",
```

- [ ] **Step 5: Add keys to `lang/sk.json`**

Same insertion point:
```json
  "account.current_password": "Aktuálne heslo (len pri zmene e-mailu)",
  "account.delete_account": "Zmazať účet",
  "account.delete_confirm": "Naozaj chcete natrvalo zmazať svoj účet? Túto akciu nemožno vrátiť späť.",
  "account.delete_success": "Váš účet bol úspešne zmazaný.",
  "account.delete_warning": "Zmazanie účtu je trvalé a nedá sa vrátiť späť. Vaše doterajšie objednávky zostanú zachované pre naše záznamy, no už nebudú prepojené s vaším účtom.",
  "account.email": "E-mail",
```

Note: `account.delete_confirm` in every language deliberately avoids apostrophes — it's interpolated inside a single-quoted JS string in the `onsubmit` attribute (`confirm('{{ t('account.delete_confirm') }}')`), so an apostrophe in the translated text would break out of the JS string literal.

- [ ] **Step 6: Verify all 5 files still parse as valid JSON and have identical key sets**

```bash
for f in lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json; do
  php -r "json_decode(file_get_contents('$f'), true, 512, JSON_THROW_ON_ERROR); echo '$f OK\n';"
done
php -r '
$files = ["lang/cs.json","lang/en.json","lang/ru.json","lang/uk.json","lang/sk.json"];
$sets = array_map(fn($f) => array_keys(json_decode(file_get_contents($f), true)), $files);
$base = $sets[0];
sort($base);
foreach ($sets as $i => $s) {
    sort($s);
    if ($s !== $base) { echo $files[$i] . " key set MISMATCH\n"; exit(1); }
}
echo "All 5 files have identical key sets\n";
'
```
Expected: `<file> OK` five times, then `All 5 files have identical key sets`.

- [ ] **Step 7: Commit**

```bash
git add lang/cs.json lang/en.json lang/ru.json lang/uk.json lang/sk.json
git commit -m "feat: add delete-account translations to all 5 languages"
```

---

### Task 5: Playwright e2e spec + manual verification

**Files:**
- Create: `tests/e2e/account.spec.ts`

**Interfaces:**
- Consumes: `/cs/register`, `/cs/login`, `/cs/logout`, `/cs/account`, `/cs/account/delete` routes and their form field names (`email`, `password`, `password_confirm`, `current_password`) — all already existing or added in Tasks 2-3.
- Produces: nothing consumed by later tasks — this is the last task.

- [ ] **Step 1: Write the e2e spec**

Create `tests/e2e/account.spec.ts`:

```typescript
import { test, expect } from '@playwright/test';

// Local-only: NOT tagged @smoke. Creates and permanently deletes a real
// customer row — must never run against production (npm run test:e2e:prod
// only selects @smoke tests, but this comment makes the intent explicit
// for anyone editing the grep filter later).

test('register, log in, log out, log back in, and delete the account', async ({ page }) => {
  const email = `e2e-account-${Date.now()}@example.com`;
  const password = 'Playwright123!';

  await page.goto('/cs/register');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('input[name="password_confirm"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/account$/);

  await page.goto('/cs/logout');
  await expect(page).toHaveURL(/\/cs\/login$/);

  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/account$/);

  page.once('dialog', dialog => dialog.accept());
  await page.locator('form[action="/cs/account/delete"] input[name="current_password"]').fill(password);
  await page.locator('form[action="/cs/account/delete"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/cs\/$/);

  await page.goto('/cs/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('form.contact-form button[type="submit"]').click();
  await expect(page.locator('.form-error')).toBeVisible();
});
```

- [ ] **Step 2: Run it**

```bash
docker compose up -d
until docker compose exec -T db mysqladmin ping -ubalonky -pbalonky --silent 2>/dev/null; do sleep 1; done
npx playwright test account.spec.ts
```
Expected: `1 passed`. (Playwright's `webServer` config in `playwright.config.ts` starts/reuses `php -S localhost:8080 -t www` automatically.)

If it fails, common causes to check:
- `Timeout waiting for locator` on the delete form — confirm Task 3's HTML landed with `action="/cs/account/delete"` exactly (the test's CSS attribute selector depends on that literal string).
- Confirm dialog not auto-accepted — the `page.once('dialog', ...)` listener must be registered *before* the `.click()` that triggers it, which it is in the code above; if this still fails, check no earlier `page.goto()`/action in the test already consumed a `once` dialog listener.

- [ ] **Step 3: Run the whole local e2e suite to confirm no regressions**

```bash
npm run test:e2e
```
Expected: all tests pass, including the 3 pre-existing files plus this new one (6 total).

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/account.spec.ts
git commit -m "test: add e2e workflow for register/login/logout/delete-account"
```

---

## Post-plan manual verification (browser)

Per `.claude/rules/unit-testing.md`, Twig/CSS aren't unit-tested — verify by hand:

1. `/start` (or `docker compose up -d && php -S localhost:8080 -t www`)
2. Visit `http://localhost:8080/cs/register`, create an account
3. On `/cs/account`, confirm the new "Smazat účet" section renders below the profile form, styled as a red button
4. Try submitting the delete form with a wrong password → confirm the `confirm()` dialog still appears, and after accepting it, the page re-renders with "Nesprávné aktuální heslo." and you're still logged in
5. Submit again with the correct password, accept the `confirm()` dialog → confirm you land on the homepage, logged out (nav shows "Přihlásit se" / "Registrovat se" again)
6. Try logging in again with the deleted account's credentials → confirm it fails with "Nesprávný e-mail nebo heslo."
7. Repeat steps 2-6 in at least one other language (e.g. `/en/register`) to confirm the translations render correctly
