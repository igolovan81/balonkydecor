# Order Status Change — Customer Notification Design

## Purpose

Email the customer whenever an admin manually changes an order's status from the
admin order-detail page (`/admin/orders/{number}`), in the customer's own preferred
language.

## Constraints

- Only admin-driven status changes trigger this — not the automatic `pending → paid`
  transition from checkout/GoPay (the customer already sees that live on their order
  confirmation page). Confirmed with the business owner.
- Language is resolved from a new `customers.notification_lang` column, not from the
  order or a fixed language — customer accounts already exist, so this is the natural
  place for a persistent preference (mirrors `users.lang`, the admin's own UI-language
  preference, which `.claude/rules/database.md` already carries as the one exception
  to `lang_code`-only naming for translation tables — `notification_lang` is a
  preference column too, same category).
- Guest orders (no linked `customers` row) have no such preference to read — default
  to Czech.
- No new services — reuses `I18n`, `Mailer`, `Seo::canonicalUrl()`, and the
  `fetchEmail()` pattern established earlier today for the order-paid notification.
- Reuse existing translation keys wherever an exact match exists (`order.title`,
  `order.status.*`) rather than duplicating them under the new `email.*` group.

## Data model

New migration `database/migrations/V027__customer_notification_lang.sql`:
```sql
ALTER TABLE `customers`
  ADD COLUMN `notification_lang` VARCHAR(5) NOT NULL DEFAULT 'cs' AFTER `phone`;
```

## Architecture & data flow

### `CustomerModel` changes

```php
public static function create(string $email, string $passwordHash, string $notificationLang = 'cs'): int
{
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare('INSERT INTO customers (email, password_hash, notification_lang) VALUES (?, ?, ?)');
    $stmt->execute([$email, $passwordHash, $notificationLang]);
    return (int) $pdo->lastInsertId();
}
```
```php
public static function updateProfile(int $id, string $name, string $phone, string $notificationLang): void
{
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, notification_lang = ? WHERE id = ?');
    $stmt->execute([$name, $phone, $notificationLang, $id]);
}
```
`findById()`/`findByEmail()` are unchanged — both already `SELECT *`, so
`notification_lang` comes back automatically.

### `AccountController` changes

- `registerSubmit()`: `CustomerModel::create($email, password_hash(...), $lang)` — the
  registration route's `$lang` attribute is always one of the 5 supported codes
  (`LangMiddleware` guarantees this), used as the customer's initial preference.
- `update()` (Customer Info page save): reads a new `notification_lang` POST field,
  validated against `Seo::LANGUAGES` (`['cs','sk','en','uk','ru']`, already a public
  constant — reused here instead of a new literal); falls back to the customer's
  existing value if missing/invalid. Passed through to `CustomerModel::updateProfile()`
  and included in the `account` array on every re-render (including error paths), so
  the form redisplays the selection consistently with how `name`/`phone`/`email`
  already behave.

### UI

`templates/public/account/customer-info.twig` — new field between phone and current
password:
```twig
<div class="form-group">
    <label for="notification_lang">{{ t('account.notification_lang') }}</label>
    <select id="notification_lang" name="notification_lang">
        {% for code, label in {'cs': 'CZ', 'sk': 'SK', 'en': 'EN', 'uk': 'UA', 'ru': 'RU'} %}
        <option value="{{ code }}" {% if (account.notification_lang ?? 'cs') == code %}selected{% endif %}>{{ label }}</option>
        {% endfor %}
    </select>
</div>
```
(Same code→label mapping `layout/base.twig`'s header language switcher already uses.)

### `AdminBaseController` changes

Extracts the same `fetchEmail()`/`ensureI18nExtension()` pair `BaseController` already
has (added earlier today to fix the Twig extension-lock bug), scoped to the
`admin_i18n` request attribute instead of the public `i18n` one:
```php
protected function fetchEmail(Request $request, string $template, array $data = []): string
{
    $this->ensureI18nExtension($request);
    return $this->twig->fetch($template, $data);
}

private function ensureI18nExtension(Request $request): void
{
    $env = $this->twig->getEnvironment();
    if (!$env->hasExtension(\App\Twig\I18nExtension::class)) {
        $i18n = $request->getAttribute('admin_i18n');
        if ($i18n) {
            $env->addExtension(new \App\Twig\I18nExtension($i18n));
        }
    }
}
```
`renderAdmin()`'s existing inline extension-registration block is replaced with a call
to `$this->ensureI18nExtension($request);`. Not strictly required by
`updateStatus()` today (it redirects rather than rendering afterward, so there's no
same-request Twig re-render to conflict with) — added anyway for consistency with the
public-side fix and because a future change to render inline instead of redirecting
would otherwise silently reintroduce the exact bug fixed earlier today.

### `Admin/OrderController::updateStatus()`

```php
public function updateStatus(Request $request, Response $response, array $args): Response
{
    $body   = (array) $request->getParsedBody();
    $status = $body['status'] ?? '';
    if (in_array($status, self::STATUSES, true)) {
        $order = OrderModel::findByNumber($args['number']);
        if ($order && $order['status'] !== $status) {
            OrderModel::updateStatus($args['number'], $status);
            $this->notifyStatusChanged($request, $order, $status);
        }
        $this->flash('success', 'orders.flash.status_changed');
    }
    return $this->redirect($response, '/admin/orders/' . $args['number']);
}

private function notifyStatusChanged(Request $request, array $order, string $newStatus): void
{
    $notificationLang = 'cs';
    if (!empty($order['customer_id'])) {
        $customer = CustomerModel::findById((int) $order['customer_id']);
        if ($customer && !empty($customer['notification_lang'])) {
            $notificationLang = $customer['notification_lang'];
        }
    }

    $i18n = new I18n($notificationLang, __DIR__ . '/../../../lang');
    $html = $this->fetchEmail($request, 'emails/order-status-changed.twig', [
        't' => [
            'intro'  => $i18n->t('email.order_status_changed.intro'),
            'order'  => $i18n->t('order.title'),
            'status' => $i18n->t('email.order_status_changed.status'),
        ],
        'order'        => $order,
        'status_label' => $i18n->t('order.status.' . $newStatus),
        'order_url'    => Seo::canonicalUrl($notificationLang, '/order/' . $order['order_number']),
    ]);
    $subject = $i18n->t('email.order_status_changed.subject', ['number' => $order['order_number']]);

    Mailer::send($order['customer_email'], $subject, $html);
}
```
The dedup guard (`$order['status'] !== $status`) prevents a no-op re-save (admin
selects the already-current status and clicks Save) from sending a redundant email —
same pattern as the order-paid notification's guard against GoPay's return+IPN racing.

New imports: `use App\Models\CustomerModel;`, `use App\Services\I18n;`,
`use App\Services\Mailer;`, `use App\Services\Seo;`.

### Email template

`templates/emails/order-status-changed.twig`:
```twig
<p>{{ t.intro }}</p>
<p><strong>{{ t.order }}:</strong> {{ order.order_number }}</p>
<p><strong>{{ t.status }}:</strong> {{ status_label }}</p>
<p><a href="{{ order_url }}">{{ order_url }}</a></p>
```

## Translations

Reused existing keys (no change): `order.title`, `order.status.pending`,
`order.status.paid`, `order.status.ready`, `order.status.completed`,
`order.status.cancelled`.

New keys added to all five `lang/{cs,en,ru,uk,sk}.json` files:
| Key | cs | en | ru | uk | sk |
|---|---|---|---|---|---|
| `account.notification_lang` | Preferovaný jazyk e-mailových upozornění | Preferred language for email notifications | Предпочитаемый язык уведомлений по эл. почте | Бажана мова сповіщень електронною поштою | Preferovaný jazyk e-mailových upozornení |
| `email.order_status_changed.subject` (`{number}`) | Změna stavu objednávky {number} | Order {number} status update | Изменение статуса заказа {number} | Зміна статусу замовлення {number} | Zmena stavu objednávky {number} |
| `email.order_status_changed.intro` | Stav vaší objednávky byl aktualizován: | Your order status has been updated: | Статус вашего заказа обновлён: | Статус вашого замовлення оновлено: | Stav vašej objednávky bol aktualizovaný: |
| `email.order_status_changed.status` | Stav | Status | Статус | Статус | Stav |

## Error handling

- `updateStatus()` with an order number that doesn't resolve (shouldn't happen via
  normal admin navigation) → `OrderModel::findByNumber()` returns null, the `if`
  guard skips both the update and the notification; behavior falls through to the
  existing flash+redirect exactly as before this change.
- `notifyStatusChanged()` has no early-return-on-missing-setting path like the
  order-paid notification does (`contact_email` is optional there) — the recipient
  here is always `order.customer_email`, which is `NOT NULL` on every order
  (`.claude/rules/database.md`/schema — captured at checkout regardless of login
  state), so there's always a destination.

## Testing

No new PHPUnit tests beyond `CustomerModel` — `.claude/rules/unit-testing.md`'s
"controllers are untested" convention covers `AccountController`/`Admin/OrderController`
as before, but `CustomerModel::create()`/`updateProfile()` are model methods with real
DB coverage already in `tests/Unit/Models/CustomerModelTest.php` (per the earlier
customer-account design) — extend the existing `create`/`updateProfile` tests to also
assert the new `notification_lang` column round-trips correctly (TDD: write the failing
assertions first, watch them fail against the current 2-arg signatures, then implement).

Manual verification via `/start` + browser + `tmp/mail.log`:
1. Register a new customer from `/ru/register` → confirm (via a quick DB check)
   `notification_lang = 'ru'`.
2. On `/en/account`, change "Preferred language for email notifications" to Slovak,
   save, reload the page → confirm the select still shows Slovak selected.
3. Place an order as that customer, then in `/admin/orders/{number}` change status to
   "Ready for pickup" → `tmp/mail.log` shows a new entry addressed to the customer's
   email with Slovak subject/intro/status text and a working `/sk/order/{number}` link.
4. Re-save the same status without changing it → confirm no new log entry appears
   (dedup guard).
5. Place a guest order (no login), change its status in admin → confirm the resulting
   email is in Czech.
6. Run `php -d memory_limit=512M vendor/bin/phpunit --testdox` — full suite green.

## Out of scope

- Notifying on the automatic `pending → paid` transition (explicitly excluded, see
  Constraints).
- A "notify customer" toggle/checkbox in the admin UI — always fires on any admin
  status change (subject to the dedup guard).
- Fixing the pre-existing hardcoded (untranslated) `"Status:"` label in
  `templates/public/order/status.twig` — noticed while researching this feature but
  unrelated to it; flagged separately to the user rather than bundled in here.
