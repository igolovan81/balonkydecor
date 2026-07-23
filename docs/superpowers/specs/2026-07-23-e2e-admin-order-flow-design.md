# E2E test: customer order → editor status flow

## Problem

There's no e2e coverage of the admin side of the order lifecycle — existing specs
(`checkout.spec.ts`) stop once the customer lands on their order-status page. Nothing
exercises an editor/admin actually working an order through to `ready`, then to
`completed` or `cancelled`.

## Gap found during design

No admin/editor account with known credentials exists anywhere in this project (no
seed migration, no fixture), and `/admin/setup` only works when the `users` table is
empty — which it isn't in local dev. So today there is no way for an e2e test to
authenticate as staff at all.

## Approach

New file: `tests/e2e/admin-order-flow.spec.ts`. Local-only (not tagged `@smoke`) —
creates a real order and a real (throwaway) `users` row, so it must never run via
`npm run test:e2e:prod`.

**Admin fixture:** a small helper (inline in the spec, or a `tests/e2e/helpers/` file if
it grows) shells out to `docker compose exec -T db mysql -ubalonky -pbalonky
balonkydecor` to `INSERT` a `users` row with role `editor`, a `uniqid()`-style unique
email, and a pre-computed bcrypt hash for a fixed test password — mirroring the
`uniqid()`/`INSERT IGNORE` fixture convention `.claude/rules/unit-testing.md` already
establishes for PHPUnit. `test.afterAll` (or the test's own cleanup) deletes that row via
the same mechanism. No new application code, no new npm dependency, no changes to
`/admin/setup`'s empty-table guard.

**Test 1 — completed flow:**
1. Customer adds 1 product (`NAR-SADA-KLASIK`) to cart via `/cs/shop/{sku}` →
   add-to-cart form (same pattern as `cart.spec.ts`).
2. Checks out via the existing checkout form → GoPay dev bypass auto-marks the order
   `paid` → redirect to `/cs/order/{number}`.
3. Test extracts `{number}` from `page.url()`.
4. Logs in at `/admin/login` as the seeded editor.
5. Visits `/admin/orders/{number}`, submits the status `<select>` set to `ready`,
   asserts the status badge/select reflects `ready`.
6. Submits status `completed`, asserts it reflects `completed`.

**Test 2 — cancelled flow:** identical shape, but the customer adds 3 products
(`NAR-SADA-KLASIK`, `NAR-SADA-PREMIUM`, `SVA-OBLOUK-BILY`) — one `add-to-cart` round trip
per SKU, cart accumulates across page visits in the same browsing context — and after
`ready`, submits `cancelled` instead of `completed`.

Both tests use their own seeded editor fixture (via `test.beforeAll`/`afterAll` in a
shared or per-test scope) and their own order — no shared mutable state between the two
tests, consistent with `fullyParallel: true` in `playwright.config.ts`.

## Out of scope

- Testing the `pending → paid` transition explicitly (it's already covered by
  `checkout.spec.ts`'s existing assertion that the dev bypass lands on the order-status
  page with a paid-looking order).
- Testing the customer-visible order-status page's copy for each status — this test
  only asserts on the *admin* side rendering the updated status.
- Testing the order-status-changed customer notification email (`Mailer`/`tmp/mail.log`)
  — out of scope for this admin-flow test; could be its own future test.
