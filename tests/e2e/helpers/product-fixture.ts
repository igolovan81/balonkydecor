import { execFileSync } from 'child_process';

export interface TempProduct {
  id: number;
  sku: string;
}

// Category 1 is the seeded default every product falls back to when none is
// selected (see CLAUDE.md: "products.category_id is NOT NULL — always supply
// a valid category ID, default to 1 if none selected") — safe to assume here.
const CATEGORY_ID = 1;

function mysql(sql: string): string {
  return execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-N', '-e', sql,
  ]).toString().trim();
}

// Test-side only, mirroring the createTempEditor() fixture pattern in
// admin-fixture.ts: shells directly into the same Docker MySQL container to
// insert a throwaway product, avoiding the createSubmit() controller path
// (and its Translator::autoFill() call, which would otherwise hit the real
// MyMemory API for every language left blank).
export function createTempProduct(categoryId: number = CATEGORY_ID): TempProduct {
  const sku = `e2e-clone-test-${Date.now()}-${Math.random().toString(36).slice(2)}`;

  mysql(
    `INSERT INTO products (sku, price, category_id, is_active, stock_type, stock_qty)
     VALUES ('${sku}', '199.00', ${categoryId}, 1, 'unlimited', 0)`
  );
  const id = parseInt(mysql(`SELECT id FROM products WHERE sku = '${sku}'`), 10);
  mysql(`INSERT INTO product_t (product_id, lang_code, name) VALUES (${id}, 'cs', 'E2E Clone Test Product')`);

  return { id, sku };
}

// products.id cascades to product_t/product_images/product_subtypes/
// product_specs (all ON DELETE CASCADE — see database/migrations/V001,
// V021, V022), so a single delete cleans up everything the test created,
// including whatever a clone/split action minted from this product.
export function deleteTempProduct(id: number): void {
  execFileSync('docker', [
    'compose', 'exec', '-T', 'db', 'mysql', '-ubalonky', '-pbalonky', 'balonkydecor', '-e',
    `DELETE FROM products WHERE id = ${id}`,
  ]);
}

export function productExistsWithSku(sku: string): boolean {
  return parseInt(mysql(`SELECT COUNT(*) FROM products WHERE sku = '${sku}'`), 10) > 0;
}
