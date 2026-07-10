# WhatsApp contact link — design

Date: 2026-07-10

## Problem

The business wants a simple way for site visitors to message them on WhatsApp
(`+420 739 922 277`), matching how Facebook/Instagram links already work — no
WhatsApp Business API, no automated messaging, just a click-to-chat link.

## Data — `settings` key

New key `whatsapp_phone`, stored as typed (e.g. `+420 739 922 277`), same convention
as the existing `contact_phone` key (human-readable, not pre-formatted for a URL).

New migration `database/migrations/V018__whatsapp_setting.sql`:
```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('whatsapp_phone', '+420 739 922 277');
```

`SettingsController::KEYS` gains `whatsapp_phone`, grouped with `facebook_url`/
`instagram_url` under the existing "Social" section.

## Rendering the link — `BaseController::render()`

Add `whatsapp_phone` to the existing settings `SELECT ... WHERE key IN (...)` list.
Compute the wa.me URL server-side (strip everything except digits from the stored
phone, per wa.me's required format) and pass it to every template exactly like
`facebook_url`/`instagram_url` are passed today:

```php
$whatsappPhone = $settingsMap['whatsapp_phone'] ?? '';
$whatsappDigits = preg_replace('/\D+/', '', $whatsappPhone);
...
'whatsapp_url' => $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '',
```

## Footer — `templates/layout/base.twig`

Extend the existing footer condition `{% if facebook_url or instagram_url %}` to
`{% if facebook_url or instagram_url or whatsapp_url %}`, and add a WhatsApp icon
`<a>` alongside the Facebook/Instagram ones, same markup shape (`target="_blank"
rel="noopener"`, `aria-label="WhatsApp"`, inline SVG).

## CSS — `www/assets/css/style.css`

```css
.social-icon-whatsapp { background: #25D366; }
```

Official WhatsApp green, added next to the existing `.social-icon-facebook`/
`.social-icon-instagram` rules.

## Admin — `templates/admin/settings/index.twig`

New field in the existing "Social" (`settings.social`) section:
```twig
<div class="form-group">
    <label>{{ t('settings.whatsapp_phone') }}</label>
    <input type="text" name="whatsapp_phone" value="{{ settings.whatsapp_phone ?? '' }}">
</div>
```

New translation key `settings.whatsapp_phone` in all 5 `lang/admin/*.json` files.

## Testing

No new automated tests — this is Twig/config wiring (settings passthrough + footer
markup), verified by rendering the public page locally, per this repo's convention of
not unit-testing templates/controllers (`.claude/rules/unit-testing.md`).

## Out of scope

- No WhatsApp Business API / automated order notifications over WhatsApp.
- No floating persistent chat button — footer icon only, per explicit decision.
- No pre-filled message text in the wa.me link (a bare `https://wa.me/<digits>` link
  opens a chat with no message; can be added later as `?text=...` if wanted).
