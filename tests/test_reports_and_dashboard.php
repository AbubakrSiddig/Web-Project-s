<?php
/**
 * سكربت فحص التقارير الدوائية ولوحة التحكم وسجل التدقيق (Phase 13, 14, 15 Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار التقارير ولوحة التحكم وسجل التدقيق    \n";
echo "=====================================================\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $info = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [✓] نجاح: {$name}" . ($info ? " ({$info})" : "") . "\n";
    } else {
        $failCount++;
        echo " [✗] فشل: {$name}" . ($info ? " ({$info})" : "") . "\n";
    }
}

$repService = new \App\Services\ReportService();
$dashService = new \App\Services\DashboardService();
$auditService = new \App\Services\AuditLogService();

// 1. فحص تقرير المبيعات
$salesRep = $repService->getSalesReport();
assertTest("استخراج تقرير المبيعات والإجماليات", isset($salesRep['summary']['net_sales']));
assertTest("توزيع المبيعات حسب طرق الدفع", is_array($salesRep['payment_breakdown']));

// 2. فحص تقرير المشتريات
$purchRep = $repService->getPurchasesReport();
assertTest("استخراج تقرير المشتريات والتوريدات", isset($purchRep['summary']['net_purchases']));

// 3. فحص تقرير تقييم المخزون المالي
$invRep = $repService->getInventoryValuationReport();
assertTest("استخراج تقرير تقييم المخزون بالتكلفة وسعر البيع", isset($invRep['summary']['total_cost_value']) && isset($invRep['summary']['total_market_value']));
assertTest("احتساب هامش الربح المتوقع للمخزون", isset($invRep['summary']['expected_gross_profit']));

// 4. فحص تقرير الصلاحيات والرواكد
$expRep = $repService->getExpiryReport(180);
assertTest("استخراج تقرير الصلاحيات (أقل من 6 أشهر)", isset($expRep['near_expiry_count']));
assertTest("حساب رأس المال المجمد في الأدوية منتهية وقريبة الانتهاء", isset($expRep['near_expiry_capital']));

// 5. فحص تقرير الأرباح والخسائر P&L
$profitRep = $repService->getProfitabilityReport();
assertTest("استخراج تقرير الأرباح (الإيرادات وتكلفة البضاعة المباعة COGS)", isset($profitRep['gross_revenue']) && isset($profitRep['cogs']));
assertTest("احتساب صافي الربح بعد المصروفات التشغيلية", isset($profitRep['net_profit']));

// 6. فحص تقارير الأرصدة والديون
$custBal = $repService->getCustomerBalancesReport();
assertTest("استخراج تقرير ذمم وديون العملاء", isset($custBal['total_receivable']));

$supBal = $repService->getSupplierBalancesReport();
assertTest("استخراج تقرير مستحقات الموردين", isset($supBal['total_payable']));

// 7. فحص مؤشرات لوحة القيادة السريعة (KPIs)
$stats = $dashService->getStats();
assertTest("استرجاع مؤشرات لوحة القيادة اليومية", isset($stats['today_sales']) && isset($stats['total_cash_balance']));
assertTest("رصد عداد الأصناف التي وصلت حد الطلب", isset($stats['low_stock_count']));

// 8. فحص بيانات الرسوم البيانية (Charts)
$charts = $dashService->getChartsData(7);
assertTest("تجهيز السلاسل الزمنية لمخطط المبيعات والمشتريات", !empty($charts['timeline']['labels']) && count($charts['timeline']['labels']) >= 7);
assertTest("تجهيز قائمة الأصناف الخمسة الأكثر مبيعاً للرسم البياني", is_array($charts['top_products']));
assertTest("تجهيز توزيع المخزون بين المخازن للرسم الدائري", is_array($charts['warehouse_distribution']));

// 9. فحص سجل التدقيق الأمني (Audit Logs)
$logs = $auditService->getLogs([], 1, 20);
assertTest("استرجاع سجل التدقيق الأمني للعمليات الحساسة", $logs['total'] >= 1);
assertTest("فك تشفير بيانات الفروقات القديمة والجديدة JSON", is_array($logs['items'][0]['new_values']) || is_null($logs['items'][0]['new_values']));

echo "\n-----------------------------------------------------\n";
echo "النتيجة النهائية لاختبار التقارير ولوحة التحكم والتدقيق: نجاح [{$passCount}] | فشل [{$failCount}]\n";
echo "=====================================================\n";
if ($failCount > 0) exit(1);
