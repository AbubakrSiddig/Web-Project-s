<?php
namespace App\Services;

use Core\Database;
use Core\Service;
use PDO;

/**
 * خدمة لوحة القيادة والمؤشرات التحليلية (Dashboard Service)
 */
class DashboardService extends Service
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * المؤشرات الرقمية السريعة (KPIs)
     */
    public function getStats()
    {
        $today = date('Y-m-d');

        // 1. مبيعات اليوم
        $salesStmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(paid_amount), 0) as paid
            FROM `sales_invoices`
            WHERE `invoice_date` = ? AND `status` = 'confirmed'
        ");
        $salesStmt->execute([$today]);
        $todaySales = $salesStmt->fetch();

        // 2. مشتريات اليوم
        $purchStmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
            FROM `purchase_invoices`
            WHERE `invoice_date` = ? AND `status` = 'confirmed'
        ");
        $purchStmt->execute([$today]);
        $todayPurchases = $purchStmt->fetch();

        // 3. إجمالي الأرصدة النقدية في الخزائن والبنوك
        $cashStmt = $this->db->query("SELECT COALESCE(SUM(current_balance), 0) as total_cash FROM `cash_accounts` WHERE `is_active` = 1");
        $totalCash = (float)$cashStmt->fetchColumn();

        // 4. ديون العملاء الإجمالية (ذمم مدينة)
        $custStmt = $this->db->query("SELECT COALESCE(SUM(balance), 0) as total_receivable FROM `customers` WHERE `balance` > 0");
        $totalReceivable = (float)$custStmt->fetchColumn();

        // 5. مستحقات الموردين الإجمالية (ذمم دائنة)
        $supStmt = $this->db->query("SELECT COALESCE(SUM(balance), 0) as total_payable FROM `suppliers` WHERE `balance` > 0");
        $totalPayable = (float)$supStmt->fetchColumn();

        // 6. عدد التشغيلات قريبة الانتهاء (أقل من 6 أشهر) والمنتهية
        $targetDate = date('Y-m-d', strtotime('+180 days'));
        $nearExpiryStmt = $this->db->prepare("
            SELECT COUNT(DISTINCT wb.batch_id)
            FROM `warehouse_batches` wb
            JOIN `batches` b ON b.id = wb.batch_id
            WHERE wb.quantity > 0 AND b.expiry_date > ? AND b.expiry_date <= ?
        ");
        $nearExpiryStmt->execute([$today, $targetDate]);
        $nearExpiryCount = (int)$nearExpiryStmt->fetchColumn();

        $expiredStmt = $this->db->prepare("
            SELECT COUNT(DISTINCT wb.batch_id)
            FROM `warehouse_batches` wb
            JOIN `batches` b ON b.id = wb.batch_id
            WHERE wb.quantity > 0 AND b.expiry_date <= ?
        ");
        $expiredStmt->execute([$today]);
        $expiredCount = (int)$expiredStmt->fetchColumn();

        // 7. الأصناف التي وصلت حد إعادة الطلب أو نفدت (Low Stock)
        $lowStockStmt = $this->db->query("
            SELECT COUNT(*) FROM (
                SELECT p.id, p.min_stock_alert, COALESCE(SUM(wb.quantity), 0) as current_stock
                FROM `products` p
                LEFT JOIN `warehouse_batches` wb ON wb.product_id = p.id
                WHERE p.is_active = 1
                GROUP BY p.id, p.min_stock_alert
                HAVING current_stock <= p.min_stock_alert
            ) as low_stock_items
        ");
        $lowStockCount = (int)$lowStockStmt->fetchColumn();

        return [
            'today_sales' => [
                'count' => (int)$todaySales['count'],
                'total' => (float)$todaySales['total'],
                'paid'  => (float)$todaySales['paid'],
            ],
            'today_purchases' => [
                'count' => (int)$todayPurchases['count'],
                'total' => (float)$todayPurchases['total'],
            ],
            'total_cash_balance'  => $totalCash,
            'total_receivable'    => $totalReceivable,
            'total_payable'       => $totalPayable,
            'near_expiry_count'   => $nearExpiryCount,
            'expired_count'       => $expiredCount,
            'low_stock_count'     => $lowStockCount,
        ];
    }

    /**
     * بيانات الرسوم البيانية للوحة التحكم (Charts Data)
     */
    public function getChartsData($days = 7)
    {
        $days = max(7, min(90, (int)$days));
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        // 1. حركة المبيعات والمشتريات اليومية (Line / Bar)
        $salesByDate = [];
        $purchByDate = [];

        $salesSql = "
            SELECT `invoice_date`, COALESCE(SUM(total_amount), 0) as total
            FROM `sales_invoices`
            WHERE `invoice_date` >= ? AND `status` = 'confirmed'
            GROUP BY `invoice_date`
            ORDER BY `invoice_date` ASC
        ";
        $stmt = $this->db->prepare($salesSql);
        $stmt->execute([$startDate]);
        foreach ($stmt->fetchAll() as $row) {
            $salesByDate[$row['invoice_date']] = (float)$row['total'];
        }

        $purchSql = "
            SELECT `invoice_date`, COALESCE(SUM(total_amount), 0) as total
            FROM `purchase_invoices`
            WHERE `invoice_date` >= ? AND `status` = 'confirmed'
            GROUP BY `invoice_date`
            ORDER BY `invoice_date` ASC
        ";
        $stmt = $this->db->prepare($purchSql);
        $stmt->execute([$startDate]);
        foreach ($stmt->fetchAll() as $row) {
            $purchByDate[$row['invoice_date']] = (float)$row['total'];
        }

        $dates = [];
        $salesSeries = [];
        $purchasesSeries = [];

        for ($i = $days; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $dates[] = $d;
            $salesSeries[] = $salesByDate[$d] ?? 0.00;
            $purchasesSeries[] = $purchByDate[$d] ?? 0.00;
        }

        // 2. الأصناف الأكثر مبيعاً
        $topProductsSql = "
            SELECT p.trade_name, SUM(sii.quantity) as total_qty, SUM(sii.total_price) as total_amount
            FROM `sales_invoice_items` sii
            JOIN `sales_invoices` si ON si.id = sii.sales_invoice_id
            JOIN `products` p ON p.id = sii.product_id
            WHERE si.status = 'confirmed' AND si.invoice_date >= ?
            GROUP BY p.id, p.trade_name
            ORDER BY total_amount DESC
            LIMIT 5
        ";
        $topStmt = $this->db->prepare($topProductsSql);
        $topStmt->execute([$startDate]);
        $topProducts = $topStmt->fetchAll();

        // 3. توزيع المخزون بين المخازن
        $warehouseDistSql = "
            SELECT w.name, COALESCE(SUM(wb.quantity), 0) as total_units
            FROM `warehouses` w
            LEFT JOIN `warehouse_batches` wb ON wb.warehouse_id = w.id
            GROUP BY w.id, w.name
        ";
        $distStmt = $this->db->query($warehouseDistSql);
        $warehouseDistribution = $distStmt->fetchAll();

        return [
            'timeline' => [
                'labels'    => $dates,
                'sales'     => $salesSeries,
                'purchases' => $purchasesSeries,
            ],
            'top_products'           => $topProducts,
            'warehouse_distribution' => $warehouseDistribution,
        ];
    }
}
