# Unit Testing Conventions

PHPUnit 11, tests in `tests/Unit/`, mirroring `src/` (`Models/`, `Services/`,
`Middleware/`). Bootstrap is just Composer autoload.

## Ground rules

- **TDD:** write the failing test first, watch it fail, then implement. No production
  code without a failing test that motivated it.
- Model tests run against the **real Docker MySQL** (`docker compose up -d`), not
  mocks. Service tests that don't need the DB stay pure.
- Run before every commit: `php vendor/bin/phpunit` — the whole suite, not just the
  file you touched. It must be fully green before commit or deploy.
- Single class: `php vendor/bin/phpunit tests/Unit/Models/GalleryModelTest.php`

## Test data in the shared DB

The dev database persists between runs and is shared with local browsing — tests must
tolerate leftover rows from previous runs:

- **Unique fixtures:** generate unique identifiers with `uniqid()` for anything with a
  uniqueness constraint or that the test must isolate (order gopay IDs, slugs, SKUs) —
  e.g. `'cover-test-' . uniqid()`.
- **Shared fixtures:** stable rows created in `setUpBeforeClass()` use `INSERT IGNORE`
  with a fixed slug (e.g. `test-album`) so re-runs don't collide.
- Never assert on global counts (`COUNT(*)` of a whole table) or on "the first row" of
  an unfiltered query — other tests' leftovers will break it. Find your own fixture by
  its unique slug/ID and assert on that.
- Clean up rows a test creates only when leaving them would break *other* tests;
  otherwise leftovers are accepted (this is the existing convention).

## Style

- Test methods: `test_snake_case_describing_behavior()` — one behavior per test; the
  name states the expected outcome (`test_albums_explicit_cover_wins`).
- Arrange with private helpers inside the test class (`makeAlbum()`, `albumId()`)
  rather than shared abstract base classes.
- Assert exact values (`assertSame`) over loose ones; cast DB values explicitly when
  the driver may return strings (`(int) $row['flag']`).

## What not to test

- Twig templates and CSS — verify those by rendering the page locally (`php -S
  localhost:8080 -t www` + curl/screenshot), not with unit tests.
- Controllers are currently untested; if controller logic grows complex, extract it
  into a testable service/model method instead of building an HTTP test harness.
