# Follow Us Footer Section

## Goal
Add a "Follow us" section to the public site footer linking to the business's
Facebook page (`https://www.facebook.com/search/top?q=balonkytop%20cz`), with
the URL managed as an admin-configurable setting rather than hardcoded.

## Storage
Add a new `settings` row: key `facebook_url`, seeded via migration
`V011__facebook_setting.sql` with the given URL. Follows the existing pattern
used for `shipping_address` / `shipping_map_url` (V010).

## Public site
`BaseController::render()` already runs one query fetching a small set of
settings (`contact_phone`, `contact_email`) for every public page render.
Extend that query to also select `facebook_url` and pass it to all templates
as a top-level `facebook_url` variable.

In `templates/layout/base.twig`, add a line to the footer, shown only when
`facebook_url` is non-empty:

```twig
{% if facebook_url %}
<p class="footer-social">{{ t('footer.follow_us') }}: <a href="{{ facebook_url }}" target="_blank" rel="noopener">Facebook</a></p>
{% endif %}
```

New translation key `footer.follow_us` added to all 5 `lang/*.json` files
(cs, en, ru, uk, sk). The link text "Facebook" is a brand name and is not
translated.

## Admin
- Add `facebook_url` to `SettingsController::KEYS`.
- Add a new "Social" section (`<h3>{{ t('settings.social') }}</h3>`) with a
  single text input for `facebook_url` in
  `templates/admin/settings/index.twig`, placed after the "Web" section.
- Add `settings.social` and `settings.facebook_url` keys to all 5
  `lang/admin/*.json` files.

## Testing
No new model/business logic — the settings table already has a generic
key/value get-and-save path exercised by existing tests. Verification is
manual: confirm the footer link renders when the setting has a value and is
hidden when empty, and confirm the admin form saves the value.

## Out of scope
- Other social networks (Instagram, etc.) — only Facebook requested.
- Icons/graphics for the link — plain text link only.
