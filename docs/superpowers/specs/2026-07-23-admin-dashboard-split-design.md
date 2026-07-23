# Admin dashboard split: orders / products & categories / customers

## Problem

The current admin dashboard (`DashboardController::index`, `templates/admin/dashboard.twig`)
is a single page with 4 stat cards (orders today/pending/total, active products) and a
10-row recent-orders table, built from raw SQL directly in the controller. It doesn't
surface anything about categories, top-selling products, or the recently-added
`customers` table (registered accounts, added in `V025`–`V027`, currently has zero admin
visibility anywhere).

Split it into three focused dashboards — orders, products/categories, customers — plus a
lightweight overview landing page, and move the raw SQL into model methods.

## Routing

All new routes are registered inside the existing protected `$app->group('/admin', ...)`
block in `src/routes.php`, after the current dashboard lines:

```php
$group->get('',           DashboardController::class . ':index');
$group->get('/dashboard', DashboardController::class . ':index');
$group->get('/dashboard/orders',    OrderDashboardController::class . ':index');
$group->get('/dashboard/products',  ProductDashboardController::class . ':index');
$group->get('/dashboard/customers', CustomerDashboardController::class . ':index');
```

No admin route ordering constraint applies here (that rule is only about `/admin/*` vs.
`/{lang}/*`); order among admin routes doesn't matter to FastRoute.

## Pages

### Overview (`/admin`, `/admin/dashboard` — existing `DashboardController::index`)

Trimmed down to one headline stat per dashboard, each linking into the dashboard:

- Orders today → link to Orders dashboard
- Active products → link to Products dashboard
- Total registered customers → link to Customers dashboard

Replaces the current 4-stat-card + recent-orders-table body. Reuses `.stat-grid` /
`.stat-card` CSS already in `admin.css`; cards become `<a>` wrapping the existing markup
(interactive elements must be real links per `.claude/rules/frontend.md`).

### Orders dashboard (`OrderDashboardController::index`, `admin/dashboard-orders.twig`)

- Stat cards: orders today, orders pending, orders total, GoPay usage rate (% of orders
  with `gopay_payment_id` set vs. null — null covers both the dev bypass and
  not-yet-paid orders)
- Status breakdown: one count per enum value (`pending`, `paid`, `ready`, `completed`,
  `cancelled`) rendered as a small stat row reusing `.badge badge-{status}` classes
- Revenue trend: last 30 days, daily `SUM(total_amount)`, rendered as a CSS bar chart
  (see "Trend chart rendering" below)
- Recent orders table: today's existing widget (last 10), unchanged markup

### Products & categories dashboard (`ProductDashboardController::index`, `admin/dashboard-products.twig`)

- Stat cards: active products, categories count, low-stock count
  (`stock_type = 'limited' AND stock_qty <= 5`; threshold is a constant in
  `ProductModel`, not admin-configurable)
- Category breakdown: table of category name (current admin language) + product count,
  ordered by count descending
- Top sellers: top 10 products by total quantity sold, joined from `order_items`
  (product name via `product_name_snapshot`, so it still resolves for deleted products)
- Recently added products: last 10 by `created_at` — note this is "recently **added**,"
  not "recently edited," since `products` has no `updated_at` column and adding one is
  out of scope for this change

### Customers dashboard (`CustomerDashboardController::index`, `admin/dashboard-customers.twig`)

- Stat cards: total registered customers, new this week (last 7 days), new this month
  (last 30 days)
- Signup trend: last 30 days, daily registration counts, same CSS bar-chart treatment as
  the revenue trend
- Recent registrations table: last 10 customers — email, name (nullable), phone
  (nullable), registered date. This is the first admin-visible listing of the
  `customers` table; no separate customer list/search page is in scope here (explicitly
  deferred — see Out of scope)

## Trend chart rendering

No charting library, no build step (`.claude/rules/frontend.md`). Both trend widgets
render as a row of `<div class="chart-bar">` elements, one per day, with `height: N%`
computed server-side in the controller from the day's value against the max value in
the 30-day window; the numeric value goes in a `title` attribute (native tooltip, no JS)
and an `aria-label` on each bar for accessibility. New CSS goes in `www/assets/css/admin.css`
following the existing flat kebab-case + `:root` token conventions.

## Model changes

Raw SQL currently in `DashboardController::index` moves into models; each new dashboard
controller calls its model, no inline SQL in controllers (matches
`.claude/rules/backend.md`).

- `OrderModel`
  - `dashboardStats(): array` — `orders_today`, `orders_pending`, `orders_total`,
    `gopay_count`, `total_count` (for the usage-rate percentage)
  - `statusBreakdown(): array` — `['pending' => n, 'paid' => n, ...]`, all 5 keys always
    present (0 if none)
  - `revenueByDay(int $days = 30): array` — `[['date' => 'Y-m-d', 'total' => float], ...]`,
    one row per day in range including zero-order days (generated in PHP, not left to
    SQL gaps)
- `ProductModel`
  - `dashboardStats(): array` — `active_count`, `low_stock_count`
  - `topSellers(int $limit = 10): array` — `['name' => ..., 'qty_sold' => ...]`
  - `recentlyAdded(int $limit = 10): array` — id, sku, name (current admin lang, joined
    via `product_t`), `created_at`
- `CategoryModel`
  - `withProductCounts(string $lang): array` — category name (translated) + product count
- `CustomerModel`
  - `dashboardStats(): array` — `total`, `new_this_week`, `new_this_month`
  - `signupsByDay(int $days = 30): array` — same shape as `revenueByDay`
  - `recent(int $limit = 10): array` — id, email, name, phone, `created_at`

All new methods are static, use prepared statements, and follow the existing
`Database::getConnection()` singleton pattern.

## Navigation

`templates/layout/admin-base.twig` sidebar gets a new "Dashboards" group of 4 links,
placed above the existing flat link list (Products, Categories, Orders, etc., which are
unchanged):

```
Overview → /admin/dashboard
Orders dashboard → /admin/dashboard/orders
Products dashboard → /admin/dashboard/products
Customers dashboard → /admin/dashboard/customers
```

New translation keys, added to all 5 files in `lang/admin/` (`cs`, `en`, `ru`, `uk`, `sk`):

- `nav.dashboard_overview`, `nav.dashboard_orders`, `nav.dashboard_products`,
  `nav.dashboard_customers`
- Per-dashboard `dashboard.orders.*`, `dashboard.products.*`, `dashboard.customers.*`
  keys mirroring the existing `dashboard.stats.*` / `dashboard.col.*` naming pattern
  (exact key list finalized during implementation, following the existing style)
- Existing `dashboard.title`, `dashboard.stats.*`, `dashboard.col.*`,
  `dashboard.recent_orders`, `dashboard.no_orders` keys stay (used by the trimmed
  overview page and the orders dashboard's recent-orders table)

## Testing

Per `.claude/rules/unit-testing.md`: TDD, real Docker MySQL, no mocks.

- `tests/Unit/Models/OrderModelTest.php` — add cases for `dashboardStats()`,
  `statusBreakdown()`, `revenueByDay()` using `uniqid()`-suffixed order numbers so
  fixtures don't collide with other tests or leftover rows
- `tests/Unit/Models/ProductModelTest.php` — add cases for `dashboardStats()`,
  `topSellers()`, `recentlyAdded()`
- `tests/Unit/Models/CategoryModelTest.php` — add case for `withProductCounts()`
- `tests/Unit/Models/CustomerModelTest.php` — add cases for `dashboardStats()`,
  `signupsByDay()`, `recent()`
- Controllers stay untested per convention (`.claude/rules/unit-testing.md`); templates
  verified by running locally (`/start` + browser) rather than a test harness
- Full suite (`php vendor/bin/phpunit`) must be green before commit

## Out of scope

- A full customer list/search/detail admin page (only a 10-row recent-registrations
  table is in scope here)
- A `products.updated_at` column / true "recently edited" tracking
- Configurable low-stock threshold (hardcoded constant for now)
- Any change to the public-facing site, checkout flow, or existing `/admin/orders`,
  `/admin/products`, `/admin/categories` CRUD pages
