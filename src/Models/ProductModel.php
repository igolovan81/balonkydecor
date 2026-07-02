<?php
namespace App\Models;

class ProductModel
{
    public static function allActive(string $lang, ?int $categoryId = null): array
    {
        $pdo    = Database::getConnection();
        $sql    = '
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description,
                   i.filename AS primary_image
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            LEFT JOIN product_images i ON i.product_id = p.id AND i.is_primary = 1
            WHERE p.is_active = 1
        ';
        $params = ['lang' => $lang];
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        $sql .= ' ORDER BY p.sort_order, p.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function findBySku(string $sku, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description, t.meta_title, t.meta_desc
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            WHERE p.sku = :sku AND p.is_active = 1
        ');
        $stmt->execute(['sku' => $sku, 'lang' => $lang]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $imgs = $pdo->prepare('SELECT filename FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$product['id']]);
        $product['images'] = $imgs->fetchAll(\PDO::FETCH_COLUMN);
        return $product;
    }

    public static function all(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT p.*,
                    (SELECT filename FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image,
                    ct.name AS category_name
             FROM products p
             LEFT JOIN category_t ct ON ct.category_id = p.category_id AND ct.lang_code = \'cs\'
             ORDER BY p.id DESC'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) return null;
        $imgs = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $product['images'] = $imgs->fetchAll();
        return $product;
    }

    public static function create(array $data): int
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, price, category_id, is_active, stock_type, stock_qty, sort_order)
             VALUES (:sku, :price, :category_id, :is_active, :stock_type, :stock_qty, 0)'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'UPDATE products SET sku = :sku, price = :price, category_id = :category_id, is_active = :is_active,
                                  stock_type = :stock_type, stock_qty = :stock_qty WHERE id = :id'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
            'id'          => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc FROM product_t WHERE product_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang_code, name, description, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }

    public static function addImage(int $productId, string $filename, bool $isPrimary = false): void
    {
        $pdo = Database::getConnection();
        if (!$isPrimary) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
            $stmt->execute([$productId]);
            $isPrimary = ((int) $stmt->fetchColumn()) === 0;
        }
        if ($isPrimary) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        }
        $pdo->prepare('INSERT INTO product_images (product_id, filename, is_primary, sort_order) VALUES (?, ?, ?, 0)')
            ->execute([$productId, $filename, $isPrimary ? 1 : 0]);
    }

    public static function deleteImage(int $imageId): ?string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT filename FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;
        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
        return $row['filename'];
    }
}
