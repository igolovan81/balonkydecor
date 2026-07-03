# Nav dropdowns: Services submenu + new Info menu

## Goal

Restructure the public header nav (`templates/layout/base.twig`) so that:
- **Services** becomes a dropdown trigger (non-navigating) with two submenu items: **Our Services** (`/{lang}/services`) and **Completed Projects** (`/{lang}/services/archive`, existing gallery link).
- A new top-level **Info** dropdown trigger (non-navigating) is added, with two submenu items: **Contact** (`/{lang}/contact`, moved from its current top-level position) and **Shipping and payment** (`/{lang}/shipping-payment`, new static page).

Final nav order: Home | Shop | Services▾ | Blog | Info▾ | Cart.

## Existing pattern to reuse

`templates/layout/base.twig` already implements one dropdown (Services → gallery link) using:
```html
<div class="nav-item-dropdown">
    <a href="/{{ lang }}/services">{{ t('nav.services') }}</a>
    <div class="nav-dropdown-menu">
        <a href="/{{ lang }}/services/archive">{{ t('nav.gallery') }}</a>
    </div>
</div>
```
with CSS in `www/assets/css/style.css`:
- `.nav-item-dropdown { position: relative; }`
- `.nav-dropdown-menu { display: none; ... }` shown via `.nav-item-dropdown:hover .nav-dropdown-menu { display: block; }`
- Mobile (`@media max-width: 768px`): `.nav-dropdown-menu { position: static; display: block; ... }` — always expanded under the hamburger menu, no JS needed.

Both new/changed dropdowns reuse this pattern as-is. No changes to `www/assets/js/nav.js`.

## Nav markup changes

Trigger elements (Services, Info) become `<span class="nav-dropdown-trigger">` instead of `<a>`, since they should not navigate anywhere themselves (confirmed with user). A small `▾` caret is added via CSS `::after` on `.nav-dropdown-trigger` for visual affordance.

```html
<nav class="main-nav">
    <a href="/{{ lang }}/">{{ t('nav.home') }}</a>
    <a href="/{{ lang }}/shop">{{ t('nav.shop') }}</a>
    <div class="nav-item-dropdown">
        <span class="nav-dropdown-trigger">{{ t('nav.services') }}</span>
        <div class="nav-dropdown-menu">
            <a href="/{{ lang }}/services">{{ t('nav.services_our') }}</a>
            <a href="/{{ lang }}/services/archive">{{ t('nav.gallery') }}</a>
        </div>
    </div>
    <a href="/{{ lang }}/blog">{{ t('nav.blog') }}</a>
    <div class="nav-item-dropdown">
        <span class="nav-dropdown-trigger">{{ t('nav.info') }}</span>
        <div class="nav-dropdown-menu">
            <a href="/{{ lang }}/contact">{{ t('nav.contact') }}</a>
            <a href="/{{ lang }}/shipping-payment">{{ t('nav.shipping') }}</a>
        </div>
    </div>
</nav>
```

## CSS changes

In `www/assets/css/style.css`, near the existing `.nav-item-dropdown` rules:
- `.nav-dropdown-trigger { cursor: default; color: var(--text); font-family: var(--ui-font); font-size: .9rem; letter-spacing: .03em; }` (mirrors `.main-nav a` styling since it's no longer an anchor)
- `.nav-dropdown-trigger::after { content: '▾'; margin-left: .3rem; font-size: .7em; }`
- Mobile block: `.nav-dropdown-trigger { padding: .85rem 0; border-top: 1px solid var(--border); display: block; font-size: 1rem; }` so it matches the existing `.main-nav a` mobile spacing (since `.main-nav a` mobile rules no longer apply to a `<span>`).

No changes to positioning/hover/mobile-expand logic — only styling for the trigger element itself.

## Translation keys

Existing keys unchanged in meaning/usage: `nav.services` (still used as trigger label and in breadcrumb JSON-LD in `templates/public/gallery/album.twig`), `nav.gallery` (becomes the Completed Projects submenu item), `nav.contact` (becomes the Contact submenu item).

New keys added to all 5 files (`lang/cs.json`, `lang/en.json`, `lang/ru.json`, `lang/uk.json`, `lang/sk.json`):
- `nav.services_our` — "Our Services" submenu link label
- `nav.info` — "Info" trigger label
- `nav.shipping` — "Shipping and payment" submenu link label
- `shipping.title` — page `<h1>` / title for the new static page
- `shipping.body` — placeholder body paragraph for the new static page

Translations follow the existing tone/style already used for `nav.*` and `services.*` keys in each file (formal address, matching terminology already used for "Services"/"Shipping"/"Payment" concepts where such words already appear in the corpus).

## New static page: /{lang}/shipping-payment

Per user decision: static placeholder content, no DB/`PageModel`, no admin editing UI. Content lives entirely in the lang JSON files and can be hand-edited later.

- **Route** (`src/routes.php`): `$app->get('/{lang}/shipping-payment', PageController::class . ':shippingPayment');` — added alongside the other `/{lang}/...` public routes (after the existing `services` route).
- **Controller** (`src/Controllers/PageController.php`): new method `shippingPayment(Request $request, Response $response, array $args)` that reads `lang` from the request attribute and renders `public/shipping.twig` with no DB lookup (unlike `services()`, which reads `PageModel::find()`).
- **Template** (`templates/public/shipping.twig`): new file modeled on `templates/public/services.twig`'s hero + content-page structure, but using `t('shipping.title')` / `t('shipping.body')` directly instead of a `page` variable:
```twig
{% extends "layout/base.twig" %}
{% block title %}{{ t('shipping.title') }} — {{ t('site.name') }}{% endblock %}
{% block content %}
<section class="page-hero">
    <div class="container"><h1>{{ t('shipping.title') }}</h1></div>
</section>
<div class="container content-page">
    <p>{{ t('shipping.body') }}</p>
</div>
{% endblock %}
```

## Out of scope

- No admin UI for managing the shipping/payment page content (static text only, per user decision).
- No change to `www/assets/js/nav.js` — existing hamburger toggle logic is untouched and already handles the expanded dropdown correctly on mobile.
- No new automated tests — this is a template/nav/i18n change with no model or business logic; existing PHPUnit suite (model-focused) is unaffected. Manual verification: load the site locally, exercise both dropdowns on desktop (hover) and mobile (hamburger open), and hit `/{lang}/shipping-payment` directly in each of the 5 languages.
