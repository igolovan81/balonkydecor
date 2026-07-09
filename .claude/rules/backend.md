---
description: Backend conventions — Slim 4 route registration order, controller render/flash/redirect patterns, static PDO models, services with dev fallbacks, sessions, config.
globs: ["src/**/*.php", "config/**/*.php"]
alwaysApply: false
---

# Backend Implementation Conventions

Applies to `src/` (Slim 4, PHP 8, PSR-7). Bootstrap is `src/app.php`; all routes live
in `src/routes.php`.

## Routing

- **Order is critical:** admin static routes (`/admin/*`) must be registered before
  any `/{lang}/*` variable routes or FastRoute throws `BadRouteException`. Keep the
  existing order: auth routes → `/admin` group → `/` redirect → lang-less endpoints
  (`/payment/notify`, `/robots.txt`, `/sitemap.xml`) → `/{lang}/*` public routes.
- New public routes go under `/{lang}/...` and are handled by `LangMiddleware`
  automatically. New admin routes go inside the `$app->group('/admin', ...)` block so
  `AuthMiddleware` + `AdminLangMiddleware` apply.
- Route handlers are declared as `Controller::class . ':method'`.

## Controllers

- Public controllers extend `BaseController` and return
  `$this->render($request, $response, 'public/x.twig', $data)` — it injects `lang`,
  `current_path`, SEO variables, and social settings into every template.
- Admin controllers extend `AdminBaseController`:
  `$this->renderAdmin(...)`, `$this->flash('success'|'error', 'translation.key')`
  (flash messages are translation keys, not literal text), and
  `$this->redirect($response, '/admin/x')` after successful POSTs (POST-redirect-GET,
  always).
- Missing entity → `return $response->withStatus(404);` — no exceptions for flow
  control.
- Read input via `$request->getQueryParams()` / `(array) $request->getParsedBody()`;
  cast scalars explicitly (`(int)`, trim strings). Never read `$_GET`/`$_POST`.

## Models & services

- Models are static classes over `Database::getConnection()` (PDO singleton,
  `FETCH_ASSOC`). **Always prepared statements with bound parameters** — the only
  string interpolation allowed in SQL is column/table names from hardcoded constants.
- Public-facing model methods take `$lang` and join the `*_t` translation table with
  `COALESCE(t.field, fallback)`; see `.claude/rules/database.md`.
- Cross-cutting logic (mail, payments, uploads, i18n, SEO) lives in `src/Services/`,
  not in controllers. Follow the existing dev-fallback pattern: services degrade
  gracefully without prod credentials (`GoPay::fromSettings()` returns `null` →
  payment bypass; `Mailer` logs to `tmp/mail.log`).
- Site-wide options come from the `settings` key/value table; admin-editable keys are
  whitelisted in `SettingsController::KEYS` — add new keys there **and** seed them via
  a migration.

## Sessions & state

- Session storage lives in `session/` (outside web root). Cart is `$_SESSION['cart']`
  via the `Cart` service; admin auth is `$_SESSION['admin_user']`; pending checkout is
  `$_SESSION['pending_order']`. Don't invent new superglobal access — go through the
  existing services where one exists.

## Errors & config

- `config/settings.php` for dev; `config/settings.prod.php` (server-only, gitignored)
  overrides in prod and must never be committed or deployed automatically.
- Before deploying: `displayErrorDetails => false`. Never echo/var_dump debugging into
  responses; the mail log `tmp/mail.log` is the dev inspection point for email.
