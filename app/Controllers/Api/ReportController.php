<?php
namespace App\Controllers\Api;

use App\Services\ReportService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم التقارير الدوائية والمالية (Report Controller)
 */
class ReportController extends Controller
{
    /** @var ReportService */
    protected $reportService;

    public function __construct(ReportService $reportService = null)
    {
        $this->reportService = $reportService ?: new ReportService();
    }

    public function sales(Request $request)
    {
        $filters = [
            'date_from'    => $request->query('date_from'),
            'date_to'      => $request->query('date_to'),
            'customer_id'  => $request->query('customer_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'payment_type' => $request->query('payment_type'),
        ];
        $report = $this->reportService->getSalesReport($filters);
        return $this->success('تم استخراج تقرير المبيعات بنجاح', $report);
    }

    public function purchases(Request $request)
    {
        $filters = [
            'date_from'    => $request->query('date_from'),
            'date_to'      => $request->query('date_to'),
            'supplier_id'  => $request->query('supplier_id'),
            'warehouse_id' => $request->query('warehouse_id'),
        ];
        $report = $this->reportService->getPurchasesReport($filters);
        return $this->success('تم استخراج تقرير المشتريات بنجاح', $report);
    }

    public function inventory(Request $request)
    {
        $warehouseId = $request->query('warehouse_id');
        $format = $request->query('format');
        $report = $this->reportService->getInventoryValuationReport($warehouseId);

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="inventory_valuation_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            $out = fopen('php://output', 'w');
            fputcsv($out, ['كود الصنف', 'الاسم التجاري', 'الاسم العلمي', 'الوحدة', 'المخزن', 'رقم التشغيلة', 'تاريخ الانتهاء', 'الكمية', 'سعر التكلفة', 'سعر البيع', 'إجمالي التكلفة', 'إجمالي البيع']);
            foreach ($report['items'] as $item) {
                fputcsv($out, [
                    $item['code'],
                    $item['trade_name'],
                    $item['generic_name'],
                    $item['main_unit'],
                    $item['warehouse_name'],
                    $item['batch_number'],
                    $item['expiry_date'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['unit_selling_price'],
                    $item['total_cost'],
                    $item['total_selling'],
                ]);
            }
            fclose($out);
            exit;
        }

        return $this->success('تم استخراج تقرير تقييم المخزون بنجاح', $report);
    }

    public function expiry(Request $request)
    {
        $days = (int)$request->query('days', 180);
        $warehouseId = $request->query('warehouse_id');
        $report = $this->reportService->getExpiryReport($days, $warehouseId);
        return $this->success('تم استخراج تقرير تواريخ الصلاحية بنجاح', $report);
    }

    public function profitability(Request $request)
    {
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to'   => $request->query('date_to'),
        ];
        $report = $this->reportService->getProfitabilityReport($filters);
        return $this->success('تم استخراج تقرير الأرباح والمصروفات بنجاح', $report);
    }

    public function customerBalances()
    {
        $report = $this->reportService->getCustomerBalancesReport();
        return $this->success('تم استخراج تقرير أرصدة العملاء بنجاح', $report);
    }

    public function supplierBalances()
    {
        $report = $this->reportService->getSupplierBalancesReport();
        return $this->success('تم استخراج تقرير مستحقات الموردين بنجاح', $report);
    }
}
