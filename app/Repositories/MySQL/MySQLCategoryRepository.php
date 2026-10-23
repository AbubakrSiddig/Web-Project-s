<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\CategoryRepositoryInterface;

/**
 * تنفيذ مستودع الفئات عبر MySQL
 */
class MySQLCategoryRepository extends BaseMySQLRepository implements CategoryRepositoryInterface
{
    protected $table = 'categories';

    public function getCategoriesWithProductCount()
    {
        $sql = "
            SELECT c.*, COUNT(p.id) as products_count
            FROM `categories` c
            LEFT JOIN `products` p ON c.id = p.category_id
            GROUP BY c.id
            ORDER BY c.id ASC
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
}
