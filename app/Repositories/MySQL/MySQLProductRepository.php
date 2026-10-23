<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\ProductRepositoryInterface;

/**
 * تنفيذ مستودع الأصناف والأدوية عبر MySQL
 */
class MySQLProductRepository extends BaseMySQLRepository implements ProductRepositoryInterface
{
    protected $table = 'products';

    public function getProductsWithCategory(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        // بحث مرن: الاسم التجاري، الاسم العلمي، كود الصنف، الباركود، أو الشركة المصنعة
        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(p.trade_name LIKE ? OR p.generic_name LIKE ? OR p.item_code LIKE ? OR p.barcode LIKE ? OR p.manufacturer LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        // تصفية بالفئة
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $bindings[] = $filters['category_id'];
        }

        // تصفية بحالة التفعيل
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "p.is_active = ?";
            $bindings[] = $filters['is_active'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // حساب الإجمالي
        $countSql = "SELECT COUNT(*) FROM `products` p {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        // استرجاع السجلات مع إجمالي المخزون الحالي من جدول warehouse_batches
        $sql = "
            SELECT p.*, c.name as category_name,
                   COALESCE((SELECT SUM(wb.quantity) FROM `warehouse_batches` wb WHERE wb.product_id = p.id), 0) as current_stock,
                   (SELECT COUNT(b.id) FROM `batches` b WHERE b.product_id = p.id AND b.status = 'active') as active_batches_count
            FROM `products` p
            INNER JOIN `categories` c ON p.category_id = c.id
            {$whereClause}
            ORDER BY p.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }

    public function findByBarcode($barcode)
    {
        $sql = "
            SELECT p.*, c.name as category_name
            FROM `products` p
            INNER JOIN `categories` c ON p.category_id = c.id
            WHERE p.barcode = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$barcode]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    public function findByItemCode($itemCode)
    {
        $sql = "
            SELECT p.*, c.name as category_name
            FROM `products` p
            INNER JOIN `categories` c ON p.category_id = c.id
            WHERE p.item_code = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$itemCode]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    public function getLowStockProducts()
    {
        $sql = "
            SELECT p.*, c.name as category_name,
                   COALESCE((SELECT SUM(wb.quantity) FROM `warehouse_batches` wb WHERE wb.product_id = p.id), 0) as current_stock
            FROM `products` p
            INNER JOIN `categories` c ON p.category_id = c.id
            WHERE p.is_active = 1
            HAVING current_stock <= p.min_stock_alert
            ORDER BY current_stock ASC
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function getAllForExport(array $filters = [])
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $bindings[] = $filters['category_id'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "p.is_active = ?";
            $bindings[] = $filters['is_active'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT p.item_code, p.trade_name, p.generic_name, c.name as category_name,
                   p.dosage_form, p.strength, p.manufacturer, p.origin_country, p.barcode,
                   p.main_unit, p.purchase_price, p.selling_price, p.min_selling_price,
                   p.min_stock_alert, p.is_active,
                   COALESCE((SELECT SUM(wb.quantity) FROM `warehouse_batches` wb WHERE wb.product_id = p.id), 0) as current_stock
            FROM `products` p
            INNER JOIN `categories` c ON p.category_id = c.id
            {$whereClause}
            ORDER BY p.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }
}
