# Basic Product Search — Design

## Goal

Let a shopper find products by typing into a search box, without introducing a
new subsystem (no search engine, no FULLTEXT index, no new tables). Search is
scoped to products only for this pass.

## Placement

A search input lives in the global nav (`templates/layout/base.twig`), visible
on every public page. It submits a `GET` to `/{lang}/shop`, reusing the
existing shop listing page rather than a dedicated search page/controller.

## Backend

### `ProductModel::allActive()`

```php
public static function allActive(string $lang, ?int $categoryId = null, ?string $query = null): array
```

- New optional third parameter, `$query`. When non-null, appends:
  ```sql
  AND (t.name LIKE :q OR p.sku LIKE :q)
  ```
  bound to `'%' . $query . '%'`.
- Combines with the existing `$categoryId` filter via `AND` — both clauses can
  be present simultaneously (e.g. category 3 + "led" searches only within that
  category).
- Matches `product_t.name` (current language, already joined) and
  `products.sku`. Descriptions are excluded for this pass (see Out of Scope).
- No collation changes needed: `utf8mb4` tables default to a case- and
  accent-insensitive collation, so `LIKE` already matches regardless of case
  or Czech diacritics as long as the diacritics themselves are typed the same
  way the search box sends them (no transliteration attempted).

### `ShopController::index()`

- Read `q` from `getQueryParams()`, `trim()` it, and treat an empty string the
  same as absent (pass `null` to `allActive()`), so `/shop` with no query
  param or a blank one behaves exactly as it does today.
- Pass `query` (the trimmed string or `null`) to the template.

## Frontend

### Nav search box (`templates/layout/base.twig`)

```twig
<form class="nav-search" action="/{{ lang }}/shop" method="GET">
    <input type="search" name="q" value="{{ query ?? '' }}"
           placeholder="{{ t('nav.search_placeholder') }}"
           aria-label="{{ t('nav.search_placeholder') }}">
    <button type="submit" aria-label="{{ t('nav.search_submit') }}">…</button>
</form>
```

`query` is only populated on `/shop` today; on every other page the input
renders empty, which is correct (there's nothing to prefill).

New translation keys, added to all five `lang/{cs,en,ru,uk,sk}.json` files:
- `nav.search_placeholder`
- `nav.search_submit`

### Shop listing (`templates/public/shop/index.twig`)

- Category filter links preserve an active search: when `query` is set, each
  `/{{ lang }}/shop?category={{ cat.id }}` link becomes
  `/{{ lang }}/shop?category={{ cat.id }}&q={{ query }}` (and the "all
  categories" link becomes `/{{ lang }}/shop?q={{ query }}`), so switching
  category doesn't drop the active search term.
- When `query` is set, show a heading like
  `{{ t('shop.search_results_for', {query: query}) }}` above the grid.
- Empty state: when `query` is set and `products` is empty, show a new key
  `shop.no_results_for_query` (interpolating `query`) instead of the generic
  `shop.no_products`.

New translation keys, added to all five `lang/{cs,en,ru,uk,sk}.json` files:
- `shop.search_results_for` (interpolates `{query}`)
- `shop.no_results_for_query` (interpolates `{query}`)

### CSS (`www/assets/css/style.css`)

- `.nav-search` styled with existing design tokens (`--border`, `--surface`,
  `--ui-font`, etc.) — no new colors.
- Responsive behavior added next to the existing nav rules in the 768px
  breakpoint block (per convention: small `@media` blocks near the component
  they modify, not one giant block at the end).

## Testing

### Unit — `tests/Unit/Models/ProductModelTest.php`

Using the existing `uniqid()`-fixture convention:
- `test_all_active_filters_by_search_query_matching_name()` — a fixture
  product with a unique name substring is found by a partial-name query;
  an unrelated fixture is not.
- `test_all_active_filters_by_search_query_matching_sku()` — same, but the
  query matches the SKU rather than the name.
- `test_all_active_filters_by_search_query_and_category_combined()` — a
  query that matches products in two different categories only returns the
  one in the requested `$categoryId` when both params are passed together.

### E2E

No shop-specific spec file exists yet (`ShopPage` is currently only used from
`home.spec.ts`). Add `tests/e2e/shop.spec.ts`:

- Opens with the standard classification comment. Read-only searches against
  existing/fixture data are safe against prod, so this file is `@smoke`.
- Extend `ShopPage` (`tests/e2e/pages/ShopPage.ts`) with a `search(term)`
  method that fills and submits the nav search form, and a `productCards`
  locator getter (or reuse an existing one if the page object already
  approximates this — check before adding a duplicate).
- `@smoke search filters the product grid by name` — search a term expected
  to match a known product (or use a temp fixture product via
  `createTempProduct()`/`deleteTempProduct()` if no stable fixture name is
  reliable), assert the URL becomes `/{lang}/shop?q=...` and the matching
  product card is visible.
- `@smoke search with no matches shows the empty state` — search a nonsense
  string, assert the `shop.no_results_for_query` text renders and no product
  cards are present.

## Out of scope for this pass

- Matching `product_t.description` (broadens recall but risks noisy matches
  from long free text — deferred until basic name/SKU search is validated).
- Fuzzy/typo-tolerant matching, relevance ranking, MySQL FULLTEXT indexes.
- Searching services, gallery, or static pages — products only.
- Search analytics/tracking of query terms.
