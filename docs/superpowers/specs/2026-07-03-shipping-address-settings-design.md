# Warehouse address on the Shipping page, configurable via settings

## Goal

Show the warehouse/pickup address and a Google Maps link on the public `/{lang}/shipping-payment` page. Both values are stored as rows in the existing `settings` table and editable through the admin Settings page, following the same pattern as `contact_email`/`contact_phone`/`site_name`.

## Decisions (confirmed with user)

- **Target page:** `/{lang}/shipping-payment` only (not the Contact page).
- **Map display:** a plain text link ("View on Google Maps" / localized equivalent) that opens the configured URL in a new tab — no embedded iframe. The user's link (`https://maps.app.goo.gl/LfyD3DbX6TnMpBsd6?g_st=a`) is a short share-link, not a Maps Embed API URL, so it can't be used directly as an iframe `src` without either an API key or a separately-generated embed URL — out of scope.
- **Seed values:** the two settings rows are seeded via a new migration with the real address/link the user provided (`skladový areál Kamýcká 234 a 235, 160 00 Praha 6` / the maps.app.goo.gl link above), so the page shows real content immediately after this deploys — not left blank pending manual admin data entry. Both remain editable afterward via the admin Settings UI.

## Settings table changes

No schema change — `settings` is already a generic `key`/`value` table (`database/migrations/V001__schema.sql:190-193`). Two new rows, seeded via `database/migrations/V010__shipping_settings.sql`:

```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('shipping_address',  'skladový areál Kamýcká 234 a 235, 160 00 Praha 6'),
  ('shipping_map_url',  'https://maps.app.goo.gl/LfyD3DbX6TnMpBsd6?g_st=a');
```

`INSERT IGNORE` matches the existing precedent for adding a new settings key post-launch (`database/migrations/V005__deepl_api_key_setting.sql`) — safe to run even if the keys somehow already exist (e.g. re-run scenarios), and doesn't clobber a value an admin may have already edited before this migration runs (unlikely here since the keys are new, but consistent with the established pattern).

## Admin

`src/Controllers/Admin/SettingsController.php`:
- Add `'shipping_address'` and `'shipping_map_url'` to the `KEYS` whitelist (currently lines 10-13), so `save()` picks them up automatically — no other controller logic changes needed (the existing upsert loop handles any key in `KEYS`).

`templates/admin/settings/index.twig`:
- New fieldset between the existing "Web" (`site_name`/`contact_email`/`contact_phone`) and "SMTP" sections:
```twig
<h3>{{ t('settings.shipping') }}</h3>
<div class="form-group">
    <label>{{ t('settings.shipping_address') }}</label>
    <input type="text" name="shipping_address" value="{{ settings.shipping_address ?? '' }}">
</div>
<div class="form-group">
    <label>{{ t('settings.shipping_map_url') }}</label>
    <input type="text" name="shipping_map_url" value="{{ settings.shipping_map_url ?? '' }}">
</div>
```
Plain `<input type="text">` for both, matching the existing convention (`site_name`, `contact_phone` use the same control type) — no new form-control types introduced.

New admin translation keys added to all 5 `lang/admin/*.json` files, in alphabetical order among the existing `settings.*` block: `settings.save` < `settings.shipping` < `settings.shipping_address` < `settings.shipping_map_url` < `settings.site_name` (the three new keys land together, right after `settings.save` and before `settings.site_name`).

## Public page

`src/Controllers/PageController.php` — `shippingPayment()`:
```php
public function shippingPayment(Request $request, Response $response, array $args): Response
{
    $pdo  = \App\Models\Database::getConnection();
    $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('shipping_address', 'shipping_map_url')");
    $stmt->execute();
    $settings = array_column($stmt->fetchAll(), 'value', 'key');

    return $this->render($request, $response, 'public/shipping.twig', [
        'shipping_address' => $settings['shipping_address'] ?? '',
        'shipping_map_url' => $settings['shipping_map_url'] ?? '',
    ]);
}
```
This follows the same ad-hoc raw-query pattern `ContactController::send()` already uses for reading a single settings value (`src/Controllers/ContactController.php:41`) — no new shared settings-reading abstraction introduced, consistent with "don't introduce abstractions beyond what the task requires."

`templates/public/shipping.twig` — append after the existing placeholder paragraph:
```twig
<div class="container content-page">
    <p>{{ t('shipping.body') }}</p>
    {% if shipping_address %}
    <p>{{ shipping_address }}
        {% if shipping_map_url %}
        <br><a href="{{ shipping_map_url }}" target="_blank" rel="noopener">{{ t('shipping.map_link') }}</a>
        {% endif %}
    </p>
    {% endif %}
</div>
```
The address block only renders if `shipping_address` is non-empty (avoids a blank paragraph if an admin ever clears the setting); the map link only renders if `shipping_map_url` is also set, independent of whether the address renders.

New public translation key `shipping.map_link` (e.g. "View on Google Maps" / "Zobrazit na mapě") added to all 5 `lang/*.json` files, alphabetically positioned between `shipping.body` and `shipping.title`.

## Out of scope

- No embedded interactive map (per user decision).
- No address shown on the Contact page (per user decision — Shipping page only).
- No admin-side validation of the map URL format (free-text field, same trust level as every other settings field in this form).
