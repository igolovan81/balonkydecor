# Instagram link + icon-styled social links in the footer

## Goal

Add an `instagram_url` setting (mirroring the existing `facebook_url` pattern exactly) and restyle the footer's "Follow us" section from plain text links into circular colored icon badges for both platforms, matching the visual style of the reference image the user provided.

## Decisions (confirmed with user)

- **Scope: Facebook + Instagram only.** No placeholder settings for Twitter/RSS/YouTube — the reference image showed 5 platforms but that was purely to communicate the desired *visual style* (colored circular icons), not a request for those other platforms.
- **Icon source: hand-written inline SVG**, embedded directly in `templates/layout/base.twig`. The project has no icon library, icon font, or CDN dependency anywhere (plain CSS, no build step per `CLAUDE.md`), and this is the smallest footprint way to add icons without introducing one — no extra HTTP request, no external dependency, works offline.
- **Instagram URL value:** `https://www.instagram.com/balonky_praha1?igsh=MTE2Y3luMWJoc3Zycg%3D%3D` (seeded via migration, exactly as provided).

## Backend — mirrors the existing `facebook_url` pattern

**Migration** `database/migrations/V012__instagram_setting.sql`:
```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('instagram_url', 'https://www.instagram.com/balonky_praha1?igsh=MTE2Y3luMWJoc3Zycg%3D%3D');
```

**`src/Controllers/Admin/SettingsController.php`** — add `'instagram_url'` to the `KEYS` whitelist, immediately after `'facebook_url'` (currently `src/Controllers/Admin/SettingsController.php:13`).

**`templates/admin/settings/index.twig`** — add a second field to the existing "Social" (`settings.social`) fieldset, immediately after the Facebook field (currently lines 20-24):
```twig
<div class="form-group">
    <label>{{ t('settings.instagram_url') }}</label>
    <input type="text" name="instagram_url" value="{{ settings.instagram_url ?? '' }}">
</div>
```

New admin translation key `settings.instagram_url` added to all 5 `lang/admin/*.json` files, alphabetically adjacent to the existing `settings.facebook_url` key.

**`src/Controllers/BaseController.php`** — the settings query (currently line 35) already fetches `facebook_url` alongside `contact_phone`/`contact_email`; add `instagram_url` to the same `IN (...)` list, and pass it to every template the same way `facebook_url` already is (currently line 50):
```php
'instagram_url' => $settingsMap['instagram_url'] ?? '',
```

## Public footer — icon rendering

`templates/layout/base.twig`'s footer currently (lines 53-60) renders:
```twig
<footer class="site-footer">
    <div class="container">
        <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
        {% if facebook_url %}
        <p class="footer-social">{{ t('footer.follow_us') }}: <a href="{{ facebook_url }}" target="_blank" rel="noopener">Facebook</a></p>
        {% endif %}
    </div>
</footer>
```

Replaced with a block that renders both platforms as circular icon links, wrapped in a single condition so the whole "Follow us" row disappears if neither URL is configured:
```twig
<footer class="site-footer">
    <div class="container">
        <p>&copy; {{ "now"|date("Y") }} {{ t('site.name') }}</p>
        {% if facebook_url or instagram_url %}
        <div class="footer-social">
            <span class="footer-social-label">{{ t('footer.follow_us') }}</span>
            <div class="footer-social-icons">
                {% if facebook_url %}
                <a href="{{ facebook_url }}" target="_blank" rel="noopener" class="social-icon social-icon-facebook" aria-label="Facebook">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12.06C22 6.48 17.52 2 11.94 2 6.36 2 1.88 6.48 1.88 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.42V9.87c0-2.39 1.42-3.71 3.6-3.71 1.04 0 2.13.19 2.13.19v2.34h-1.2c-1.18 0-1.55.73-1.55 1.48v1.78h2.64l-.42 2.91h-2.22V22c4.78-.76 8.44-4.92 8.44-9.94z"/></svg>
                </a>
                {% endif %}
                {% if instagram_url %}
                <a href="{{ instagram_url }}" target="_blank" rel="noopener" class="social-icon social-icon-instagram" aria-label="Instagram">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg>
                </a>
                {% endif %}
            </div>
        </div>
        {% endif %}
    </div>
</footer>
```
(Facebook's icon uses `fill` on the `<path>`; Instagram's uses `stroke="currentColor"` on the outline shapes — the CSS below sets `color`/`fill` consistently so both render white against their colored circle backgrounds.)

No new public-facing translation keys beyond the existing `footer.follow_us` (unchanged) — the `aria-label` values are hardcoded platform names, matching how the current code already hardcodes the visible text "Facebook" (proper nouns, not translated content).

## CSS

New rules in `www/assets/css/style.css`, near the existing `.site-footer` rule:
```css
.footer-social { margin-top: 1rem; }
.footer-social-label { display: block; margin-bottom: .5rem; font-family: var(--ui-font); font-size: .8rem; }
.footer-social-icons { display: flex; justify-content: center; gap: .75rem; }
.social-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: #fff; fill: #fff; transition: opacity .2s; }
.social-icon:hover { opacity: .85; }
.social-icon svg { width: 18px; height: 18px; }
.social-icon-facebook { background: #1877F2; }
.social-icon-instagram { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285aeb 90%); }
```
Facebook blue (`#1877F2`) and the Instagram radial gradient are the platforms' standard, widely-recognized brand colors — matching the reference image's colored-circle style.

## Out of scope

- No Twitter/RSS/YouTube settings or icons (per user decision).
- No icon library/font/CDN dependency introduced.
- No change to `footer.follow_us` translation values (label text stays as-is in all 5 languages).
