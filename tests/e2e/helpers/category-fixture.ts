import { execFileSync } from 'child_process';

export interface TempCategory {
  id: number;
  slug: string;
}

function mysql(sql: string): string {
  return execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-N', '-e', sql,
  ]).toString().trim();
}

// Test-side only, mirroring product-fixture.ts: inserts a throwaway category
// directly via SQL rather than the admin create form, since createSubmit()
// calls Translator::autoFill() for every blank language on create (would hit
// the real MyMemory API). Only a 'cs' translation row is created — the admin
// list/edit views fall back to the slug for any other language, so this is
// enough for specs that just need a category to exist.
export function createTempCategory(): TempCategory {
  const slug = `e2e-category-${Date.now()}-${Math.random().toString(36).slice(2)}`;

  mysql(`INSERT INTO categories (slug, sort_order) VALUES ('${slug}', 999)`);
  const id = parseInt(mysql(`SELECT id FROM categories WHERE slug = '${slug}'`), 10);
  mysql(`INSERT INTO category_t (category_id, lang_code, name) VALUES (${id}, 'cs', 'E2E Test Category')`);

  return { id, slug };
}

// categories.id cascades to category_t (ON DELETE CASCADE — see
// database/migrations/V001), so a single delete is enough cleanup.
// products.category_id has no ON DELETE clause (InnoDB default RESTRICT) —
// this fails if a temp product still references the category; delete that
// product first.
export function deleteTempCategory(id: number): void {
  execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-e',
    `DELETE FROM categories WHERE id = ${id}`,
  ]);
}
