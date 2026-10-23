<?php
namespace App\Services;

use Core\Database;
use Core\Service;
use PDO;

/**
 * خدمة التقارير الدوائية والمالية الشاملة (Report Service)
 */
class ReportService extends Service
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * تقرير المبيعات التفصيلي والإجمالي
     */
    public function getSalesReport(array $filters = [])
    {
        $where = ["si.status = 'confirmed'"];
        $bindings = [];

        if (!empty($filters['date_from'])) {
            $where[] = "si.invoice_date >= ?";
            $bindings[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "si.invoice_date <= ?";
            $bindings[] = $filters['date_to'];
        }
        if (!empty($filters['customer_id'])) {
            $where[] = "si.customer_id = ?";
            $bindings[] = $filters['customer_id'];
        }
        if (!empty($filters['warehouse_id'])) {
            $where[] = "si.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }
        if (!empty($filters['payment_type'])) {
            $where[] = "si.payment_type = ?";
            $bindings[] = $filters['payment_type'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // الإجماليات
        $summarySql = "
            SELECT 
                COUNT(*) as total_invoices,
                COALESCE(SUM(si.subtotal), 0) as total_subtotal,
                COALESCE(SUM(si.discount_amount), 0) as total_discount,
                COALESCE(SUM(si.tax_amount), 0) as total_tax,
                COALESCE(SUM(si.total_amount), 0) as net_sales,
                COALESCE(SUM(si.paid_amount), 0) as total_collected,
                COALESCE(SUM(si.remaining_amount), 0) as total_receivable
            FROM `sales_invoices` si
            {$whereClause}
        ";
        $stmt = $this->db->prepare($summarySql);
        $stmt->execute($bindings);
        $summary = $stmt->fetch();

        // التوزيع حسب طريقة الدفع
        $paySql = "
            SELECT si.payment_type, COUNT(*) as count, SUM(si.total_amount) as total
            FROM `sales_invoices` si
            {$whereClause}
            GROUP BY si.payment_type
        ";
        $payStmt = $this->db->prepare($paySql);
        $payStmt->execute($bindings);
        $paymentBreakdown = $payStmt->fetchAll();

        // الأصناف الأكثر مبيعاً في الفترة
        $topSql = "
            SELECT p.id, p.trade_name, p.generic_name, p.item_code as code, p.main_unit,
                   SUM(sii.quantity) as total_quantity_sold,
                   SUM(sii.total_price) as total_revenue
            FROM `sales_invoice_items` sii
            JOIN `sales_invoices` si ON si.id = sii.sales_invoice_id
            JOIN `products` p ON p.id = sii.product_id
            {$whereClause}
            GROUP BY p.id, p.trade_name, p.generic_name, p.item_code, p.main_unit
            ORDER BY total_revenue DESC
            LIMIT 15
        ";
        $topStmt = $this->db->prepare($topSql);
        $topStmt->execute($bindings);
        $topProducts = $topStmt->fetchAll();

        return [
            'summary'           => $summary,
            'payment_breakdown' => $paymentBreakdown,
            'top_products'      => $topProducts,
        ];
    }

    /**
     * تقرير المشتريات والتوريدات
     */
    public function getPurchasesReport(array $filters = [])
    {
        $where = ["pi.status = 'confirmed'"];
        $bindings = [];

        if (!empty($filters['date_from'])) {
            $where[] = "pi.invoice_date >= ?";
            $bindings[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "pi.invoice_date <= ?";
            $bindings[] = $filters['date_to'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = "pi.supplier_id = ?";
            $bindings[] = $filters['supplier_id'];
        }
        if (!empty($filters['warehouse_id'])) {
            $where[] = "pi.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $summarySql = "
            SELECT 
                COUNT(*) as total_invoices,
                COALESCE(SUM(pi.subtotal), 0) as total_subtotal,
                COALESCE(SUM(pi.discount_amount), 0) as total_discount,
                COALESCE(SUM(pi.tax_amount), 0) as total_tax,
                COALESCE(SUM(pi.total_amount), 0) as net_purchases,
                COALESCE(SUM(pi.paid_amount), 0) as total_paid,
                COALESCE(SUM(pi.remaining_amount), 0) as total_payable
            FROM `purchase_invoices` pi
            {$whereClause}
        ";
        $stmt = $this->db->prepare($summarySql);
        $stmt->execute($bindings);
        $summary = $stmt->fetch();

        // التوزيع حسب الموردين
        $supSql = "
            SELECT s.name as supplier_name, s.company_name, COUNT(*) as invoice_count, SUM(pi.total_amount) as total_purchased
            FROM `purchase_invoices` pi
            JOIN `suppliers` s ON s.id = pi.supplier_id
            {$whereClause}
            GROUP BY s.id, s.name, s.company_name
            ORDER BY total_purchased DESC
            LIMIT 15
        ";
        $supStmt = $this->db->prepare($supSql);
        $supStmt->execute($bindings);
        $bySupplier = $supStmt->fetchAll();

        return [
            'summary'     => $summary,
            'by_supplier' => $bySupplier,
        ];
    }

    /**
     * تقرير تقييم المخزون الإجمالي وبالتكلفة وسعر البيع
     */
    public function getInventoryValuationReport($warehouseId = null)
    {
        $where = ["wb.quantity > 0"];
        $bindings = [];

        if (!empty($warehouseId)) {
            $where[] = "wb.warehouse_id = ?";
            $bindings[] = (int)$warehouseId;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $summarySql = "
            SELECT 
                COUNT(DISTINCT wb.product_id) as total_unique_products,
                COUNT(DISTINCT wb.batch_id) as total_active_batches,
                COALESCE(SUM(wb.quantity), 0) as total_units_in_stock,
                COALESCE(SUM(wb.quantity * COALESCE(b.purchase_price, p.purchase_price)), 0) as total_cost_value,
                COALESCE(SUM(wb.quantity * COALESCE(b.selling_price, p.selling_price)), 0) as total_market_value
            FROM `warehouse_batches` wb
            JOIN `batches` b ON b.id = wb.batch_id
            JOIN `products` p ON p.id = wb.product_id
            {$whereClause}
        ";
        $stmt = $this->db->prepare($summarySql);
        $stmt->execute($bindings);
        $summary = $stmt->fetch();

        $costVal = (float)$summary['total_cost_value'];
        $marketVal = (float)$summary['total_market_value'];
        $expectedMargin = $marketVal - $costVal;
        $marginPercent = $marketVal > 0 ? round(($expectedMargin / $marketVal) * 100, 2) : 0;

        $summary['expected_gross_profit'] = $expectedMargin;
        $summary['expected_margin_percent'] = $marginPercent;

        // تفاصيل الأصناف والكميات
        $detailsSql = "
            SELECT 
                p.id as product_id,
                p.trade_name,
                p.generic_name,
                p.item_code as code,
                p.main_unit,
                c.name as category_name,
                w.name as warehouse_name,
                b.batch_number,
                b.expiry_date,
                wb.quantity,
                COALESCE(b.purchase_price, p.purchase_price) as unit_cost,
                COALESCE(b.selling_price, p.selling_price) as unit_selling_price,
                (wb.quantity * COALESCE(b.purchase_price, p.purchase_price)) as total_cost,
                (wb.quantity * COALESCE(b.selling_price, p.selling_price)) as total_selling
            FROM `warehouse_batches` wb
            JOIN `warehouses` w ON w.id = wb.warehouse_id
            JOIN `batches` b ON b.id = wb.batch_id
            JOIN `products` p ON p.id = wb.product_id
            JOIN `categories` c ON c.id = p.category_id
            {$whereClause}
            ORDER BY total_cost DESC
            LIMIT 200
        ";
        $stmt = $this->db->prepare($detailsSql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll();

        return [
            'summary' => $summary,
            'items'   => $items,
        ];
    }

    /**
     * تقرير الصلاحيات والرواكد والأدوية المنتهية وقريبة الانتهاء
     */
    public function getExpiryReport($daysThreshold = 180, $warehouseId = null)
    {
        $days = max(1, (int)$daysThreshold);
        $today = date('Y-m-d');
        $targetDate = date('Y-m-d', strtotime("+{$days} days"));

        $whereNear = ["wb.quantity > 0", "b.expiry_date > ?", "b.expiry_date <= ?"];
        $bindingsNear = [$today, $targetDate];

        $whereExpired = ["wb.quantity > 0", "b.expiry_date <= ?"];
        $bindingsExpired = [$today];

        if (!empty($warehouseId)) {
            $whereNear[] = "wb.warehouse_id = ?";
            $bindingsNear[] = (int)$warehouseId;

            $whereExpired[] = "wb.warehouse_id = ?";
            $bindingsExpired[] = (int)$warehouseId;
        }

        // قريبة الانتهاء
        $sqlNear = "
            SELECT 
                wb.warehouse_id,
                w.name as warehouse_name,
                p.id as product_id,
                p.trade_name,
                p.generic_name,
                p.main_unit,
                b.id as batch_id,
                b.batch_number,
                b.expiry_date,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) as days_remaining,
                wb.quantity,
                b.purchase_price,
                (wb.quantity * b.purchase_price) as total_capital_tied
            FROM `warehouse_batches` wb
            JOIN `warehouses` w ON w.id = wb.warehouse_id
            JOIN `batches` b ON b.id = wb.batch_id
            JOIN `products` p ON p.id = wb.product_id
            WHERE " . implode(' AND ', $whereNear) . "
            ORDER BY b.expiry_date ASC
        ";
        $stmt = $this->db->prepare($sqlNear);
        $stmt->execute($bindingsNear);
        $nearExpiry = $stmt->fetchAll();

        // منتهية الصلاحية تماماً
        $sqlExpired = "
            SELECT 
                wb.warehouse_id,
                w.name as warehouse_name,
                p.id as product_id,
                p.trade_name,
                p.generic_name,
                p.main_unit,
                b.id as batch_id,
                b.batch_number,
                b.expiry_date,
                DATEDIFF(CURRENT_DATE(), b.expiry_date) as days_expired,
                wb.quantity,
                b.purchase_price,
                (wb.quantity * b.purchase_price) as total_loss_value
            FROM `warehouse_batches` wb
            JOIN `warehouses` w ON w.id = wb.warehouse_id
            JOIN `batches` b ON b.id = wb.batch_id
            JOIN `products` p ON p.id = wb.product_id
            WHERE " . implode(' AND ', $whereExpired) . "
            ORDER BY b.expiry_date ASC
        ";
        $stmt = $this->db->prepare($sqlExpired);
        $stmt->execute($bindingsExpired);
        $expired = $stmt->fetchAll();

        $nearCost = array_sum(array_column($nearExpiry, 'total_capital_tied'));
        $expiredCost = array_sum(array_column($expired, 'total_loss_value'));

        return [
            'threshold_days'          => $days,
            'near_expiry_count'       => count($nearExpiry),
            'near_expiry_capital'     => (float)$nearCost,
            'near_expiry_items'       => $nearExpiry,
            'expired_count'           => count($expired),
            'expired_loss_value'      => (float)$expiredCost,
            'expired_items'           => $expired,
        ];
    }

    /**
     * تقرير أرباح المنظومة (Profit & Loss / Profitability)
     */
    public function getProfitabilityReport(array $filters = [])
    {
        $where = ["si.status = 'confirmed'"];
        $bindings = [];

        if (!empty($filters['date_from'])) {
            $where[] = "si.invoice_date >= ?";
            $bindings[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "si.invoice_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // حساب إيرادات المبيعات وتكلفة البضاعة المباعة (COGS)
        $sql = "
            SELECT 
                COALESCE(SUM(sii.total_price), 0) as gross_revenue,
                COALESCE(SUM(sii.quantity * COALESCE(b.purchase_price, p.purchase_price)), 0) as total_cogs
            FROM `sales_invoice_items` sii
            JOIN `sales_invoices` si ON si.id = sii.sales_invoice_id
            JOIN `batches` b ON b.id = sii.batch_id
            JOIN `products` p ON p.id = sii.product_id
            {$whereClause}
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $salesMetrics = $stmt->fetch();

        // إجمالي المصروفات التشغيلية في نفس الفترة
        $expWhere = ["pv.voucher_type = 'payment'", "pv.contact_type = 'expense'"];
        $expBindings = [];
        if (!empty($filters['date_from'])) {
            $expWhere[] = "pv.voucher_date >= ?";
            $expBindings[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $expWhere[] = "pv.voucher_date <= ?";
            $expBindings[] = $filters['date_to'];
        }
        $expSql = "
            SELECT COALESCE(SUM(pv.amount), 0) as total_expenses
            FROM `payment_vouchers` pv
            WHERE " . implode(' AND ', $expWhere) . "
        ";
        $expStmt = $this->db->prepare($expSql);
        $expStmt->execute($expBindings);
        $expenses = (float)$expStmt->fetchColumn();

        $revenue = (float)$salesMetrics['gross_revenue'];
        $cogs = (float)$salesMetrics['total_cogs'];
        $grossProfit = $revenue - $cogs;
        $netProfit = $grossProfit - $expenses;
        $grossMargin = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0;
        $netMargin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0;

        return [
            'gross_revenue'   => $revenue,
            'cogs'            => $cogs,
            'gross_profit'    => $grossProfit,
            'gross_margin'    => $grossMargin,
            'total_expenses'  => $expenses,
            'net_profit'      => $netProfit,
            'net_margin'      => $netMargin,
        ];
    }

    /**
     * تقرير كشف أرصدة العملاء والديون
     */
    public function getCustomerBalancesReport()
    {
        $sql = "
            SELECT 
                c.id, c.name, c.pharmacy_name, c.phone, c.credit_limit, c.balance,
                (SELECT COUNT(*) FROM `sales_invoices` si WHERE si.customer_id = c.id AND si.status = 'confirmed') as total_invoices,
                (SELECT COALESCE(SUM(si.total_amount), 0) FROM `sales_invoices` si WHERE si.customer_id = c.id AND si.status = 'confirmed') as lifetime_sales
            FROM `customers` c
            WHERE c.balance != 0
            ORDER BY c.balance DESC
        ";
        $stmt = $this->db->query($sql);
        $customers = $stmt->fetchAll();

        $totalReceivable = array_sum(array_column($customers, 'balance'));

        return [
            'total_receivable' => (float)$totalReceivable,
            'customers_count'  => count($customers),
            'customers'        => $customers,
        ];
    }

    /**
     * تقرير كشف مستحقات الموردين
     */
    public function getSupplierBalancesReport()
    {
        $sql = "
            SELECT 
                s.id, s.name, s.company_name, s.phone, s.balance,
                (SELECT COUNT(*) FROM `purchase_invoices` pi WHERE pi.supplier_id = s.id AND pi.status = 'confirmed') as total_invoices,
                (SELECT COALESCE(SUM(pi.total_amount), 0) FROM `purchase_invoices` pi WHERE pi.supplier_id = s.id AND pi.status = 'confirmed') as lifetime_purchases
            FROM `suppliers` s
            WHERE s.balance != 0
            ORDER BY s.balance DESC
        ";
        $stmt = $this->db->query($sql);
        $suppliers = $stmt->fetchAll();

        $totalPayable = array_sum(array_column($suppliers, 'balance'));

        return [
            'total_payable'   => (float)$totalPayable,
            'suppliers_count' => count($suppliers),
            'suppliers'       => $suppliers,
        ];
    }
}
