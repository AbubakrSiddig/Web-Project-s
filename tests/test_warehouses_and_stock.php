<?php
/**
 * سكربت فحص المخازن والحركات المخزنية والتحويلات والتسويات (Phase 8 Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار المخازن والتحويلات المخزنية والتسويات   \n";
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

$whService = new \App\Services\WarehouseService();
$stockService = new \App\Services\StockService();
$prodService = new \App\Services\ProductService();
$batchService = new \App\Services\BatchService();
$whRepo = new \App\Repositories\MySQL\MySQLWarehouseRepository();

// 1. اختبار استرجاع المخازن
$warehouses = $whService->getAllWarehouses();
assertTest("استرجاع قائمة المخازن", count($warehouses) >= 3, "عدد المخازن: " . count($warehouses));

// 2. إنشاء صنف دوائي وتشغيلة للتجربة المخزنية
$product = $prodService->createProduct([
    'category_id'    => 1,
    'trade_name'     => 'أموكسيسيلين كبسول ' . time(),
    'generic_name'   => 'Amoxicillin 500mg',
    'main_unit'      => 'شريط',
    'purchase_price' => 500.00,
    'selling_price'  => 850.00,
], 1);
$productId = $product['id'];

// تشغيلة برصيد 100 في المخزن 1
$batch = $batchService->createBatch([
    'product_id'       => $productId,
    'batch_number'     => 'AMX-STK-' . time(),
    'production_date'  => date('Y-m-d', strtotime('-100 days')),
    'expiry_date'      => date('Y-m-d', strtotime('+400 days')),
    'warehouse_id'     => 1,
    'initial_quantity' => 100,
], 1);
$batchId = $batch['id'];

// 3. فحص الرصيد في المخزن 1
$stock1 = $whRepo->getBatchStock(1, $batchId);
assertTest("رصيد التشغيلة في المخزن 1 بعد الإنشاء", $stock1 && (float)$stock1['quantity'] == 100.00);

// 4. إنشاء إذن تحويل مخزني من المخزن 1 إلى المخزن 2 بكمية 30
$transfer = $stockService->createTransfer([
    'from_warehouse_id' => 1,
    'to_warehouse_id'   => 2,
    'transfer_date'     => date('Y-m-d'),
    'notes'             => 'تحويل تجريبي لتغذية المخزن الفرعي',
    'items'             => [
        [
            'product_id' => $productId,
            'batch_id'   => $batchId,
            'quantity'   => 30,
        ]
    ]
], 1);
assertTest("إنشاء إذن التحويل المخزني في حالة الانتظار", !empty($transfer['id']) && $transfer['status'] === 'pending');

// 5. اعتماد التحويل المخزني
$approvedTransfer = $stockService->approveTransfer($transfer['id'], 1);
assertTest("اعتماد إذن التحويل واكتماله", $approvedTransfer['status'] === 'completed');

// فحص الأرصدة بعد التحويل
$stockAfter1 = $whRepo->getBatchStock(1, $batchId);
$stockAfter2 = $whRepo->getBatchStock(2, $batchId);
assertTest("خصم 30 من المخزن المصدر (المتبقي: 70)", (float)$stockAfter1['quantity'] == 70.00);
assertTest("إضافة 30 للمخزن الهدف (الجديد: 30)", (float)$stockAfter2['quantity'] == 30.00);

// 6. تسوية جردية (Stock Adjustment)
$adjustment = $stockService->createAdjustment([
    'warehouse_id'    => 2,
    'adjustment_date' => date('Y-m-d'),
    'notes'           => 'تسوية جرد دوري',
    'items'           => [
        [
            'product_id'        => $productId,
            'batch_id'          => $batchId,
            'physical_quantity' => 35, // زيادة 5 وحدات عما في النظام
            'reason'            => 'فائض غير مسجل عند استلام كرتونة سابقة',
        ]
    ]
], 1);
assertTest("إنشاء مستند التسوية الجردية", !empty($adjustment['id']) && $adjustment['status'] === 'draft');

// اعتماد التسوية
$approvedAdj = $stockService->approveAdjustment($adjustment['id'], 1);
assertTest("اعتماد التسوية الجردية", $approvedAdj['status'] === 'approved');

$stockAdj2 = $whRepo->getBatchStock(2, $batchId);
assertTest("تعديل الرصيد الفعلي في المخزن 2 بعد التسوية ليصبح 35", (float)$stockAdj2['quantity'] == 35.00);

// 7. فحص سجل حركات المخزون (Stock Transactions)
$history = $stockService->getStockTransactions(['product_id' => $productId]);
assertTest("تسجيل العمليات في السجل غير القابل للتعديل stock_transactions", $history['total'] >= 3, "إجمالي الحركات: " . $history['total']);

echo "\n-----------------------------------------------------\n";
echo "النتيجة النهائية لاختبار المخازن والمخزون: نجاح [{$passCount}] | فشل [{$failCount}]\n";
echo "=====================================================\n";
if ($failCount > 0) exit(1);
