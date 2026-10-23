<?php
/**
 * سكربت فحص المرتجعات والحسابات المالية وسندات القبض والصرف والتحويل (Phase 11 & 12 Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار المرتجعات والخزائن والحسابات والسندات   \n";
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

$salesRetService = new \App\Services\SalesReturnService();
$purRetService = new \App\Services\PurchaseReturnService();
$accService = new \App\Services\AccountingService();
$custService = new \App\Services\CustomerService();
$supService = new \App\Services\SupplierService();
$prodService = new \App\Services\ProductService();
$batchService = new \App\Services\BatchService();
$whRepo = new \App\Repositories\MySQL\MySQLWarehouseRepository();

// 1. إنشاء بيانات تجريبية: صيدلية، مورد، صنف، تشغيلة
$customer = $custService->createCustomer([
    'name'           => 'صيدلية النجاة ' . time(),
    'pharmacy_name'  => 'صيدلية النجاة الحديثة',
    'phone'          => '0911223344',
    'credit_limit'   => 100000.00,
    'balance'        => 20000.00, // مديونية سابقة
], 1);

$supplier = $supService->createSupplier([
    'name'         => 'مؤسسة الرواد الطبية ' . time(),
    'phone'        => '0922334455',
    'balance'      => 30000.00, // مستحقات سابقة
], 1);

$product = $prodService->createProduct([
    'category_id'    => 1,
    'trade_name'     => 'إيبوبروفين 400 ' . time(),
    'generic_name'   => 'Ibuprofen 400mg',
    'main_unit'      => 'شريط',
    'purchase_price' => 200.00,
    'selling_price'  => 350.00,
], 1);

$batch = $batchService->createBatch([
    'product_id'       => $product['id'],
    'batch_number'     => 'IBU-RET-' . time(),
    'production_date'  => date('Y-m-d', strtotime('-50 days')),
    'expiry_date'      => date('Y-m-d', strtotime('+300 days')),
    'warehouse_id'     => 1,
    'initial_quantity' => 100,
], 1);

// 2. مرتجع مبيعات سليم (Return to Valid Stock)
// استلام 10 أشرطة مرتجعة من الصيدلية بسعر 350 = 3,500 ج.س
$stockBefore = $whRepo->getBatchStock(1, $batch['id']);
$salesRet = $salesRetService->createReturn([
    'customer_id'            => $customer['id'],
    'warehouse_id'           => 1,
    'refund_status'          => 'deduct_from_balance',
    'return_to_stock_status' => 'valid_stock',
    'notes'                  => 'مرتجع فائض طلبية صيدلية النجاة',
    'items'                  => [
        [
            'product_id'    => $product['id'],
            'batch_id'      => $batch['id'],
            'quantity'      => 10,
            'unit_price'    => 350.00,
            'return_reason' => 'زيادة في الكمية المطلوبة',
        ]
    ]
], 1);
assertTest("إنشاء مرتجع مبيعات سليم بقيمة 3,500 ج.س", !empty($salesRet['id']) && (float)$salesRet['total_amount'] == 3500.00);

// التحقق من زيادة رصيد المخزن للتشغيلة بمقدار 10
$stockAfter = $whRepo->getBatchStock(1, $batch['id']);
assertTest("إرجاع الكمية (10) لرصيد المخزن بنجاح", (float)$stockAfter['quantity'] == ((float)$stockBefore['quantity'] + 10));

// التحقق من تخفيض مديونية الصيدلية (20,000 - 3,500 = 16,500)
$custAfter = $custService->getCustomerById($customer['id']);
assertTest("تخفيض مديونية الصيدلية لتصبح 16,500 ج.س", (float)$custAfter['balance'] == 16500.00);

// 3. مرتجع مشتريات إلى المورد (Purchase Return)
// إعادة 5 أشرطة للمورد بسعر 200 = 1,000 ج.س
$purRet = $purRetService->createReturn([
    'supplier_id'   => $supplier['id'],
    'warehouse_id'  => 1,
    'refund_status' => 'deduct_from_balance',
    'notes'         => 'مرتجع للمورد لوجود خطأ في الشحنة',
    'items'         => [
        [
            'product_id'    => $product['id'],
            'batch_id'      => $batch['id'],
            'quantity'      => 5,
            'unit_price'    => 200.00,
            'return_reason' => 'خطأ في التوريد',
        ]
    ]
], 1);
assertTest("إنشاء مرتجع مشتريات للمورد بقيمة 1,000 ج.س", !empty($purRet['id']) && (float)$purRet['total_amount'] == 1000.00);

// التحقق من خصم مستحقات المورد (30,000 - 1,000 = 29,000)
$supAfter = $supService->getSupplierById($supplier['id']);
assertTest("تخفيض مستحقات المورد لتصبح 29,000 ج.س", (float)$supAfter['balance'] == 29000.00);

// 4. الحسابات المالية: إنشاء خزينة وبنك
$cashBox = $accService->createCashAccount([
    'name'            => 'خزينة الإدارة المالية ' . time(),
    'account_type'    => 'cash',
    'current_balance' => 50000.00,
], 1);
assertTest("إنشاء خزينة نقدية برصيد ابتدائي 50,000 ج.س", !empty($cashBox['id']));

$bankAcc = $accService->createCashAccount([
    'name'            => 'حساب بنك الخرطوم ' . time(),
    'account_type'    => 'bank',
    'account_number'  => 'ACC-12345678',
    'current_balance' => 100000.00,
], 1);
assertTest("إنشاء حساب بنكي برصيد ابتدائي 100,000 ج.س", !empty($bankAcc['id']));

// 5. سند قبض من الصيدلية (Receipt Voucher)
// قبض 6,500 ج.س نقداً في الخزينة
$rcv = $accService->createReceiptVoucher([
    'cash_account_id' => $cashBox['id'],
    'contact_type'    => 'customer',
    'customer_id'     => $customer['id'],
    'amount'          => 6500.00,
    'notes'           => 'سداد دفعة نقدية من المديونية',
], 1);
assertTest("إصدار سند قبض نقدي بقيمة 6,500 ج.س", !empty($rcv['id']) && (float)$rcv['amount'] == 6500.00);

// رصيد الخزينة يرتفع (50,000 + 6,500 = 56,500)
$cashBoxAfterRcv = $accService->getCashAccountById($cashBox['id']);
assertTest("زيادة رصيد الخزينة ليصبح 56,500 ج.س", (float)$cashBoxAfterRcv['current_balance'] == 56500.00);

// رصيد الصيدلية ينخفض (16,500 - 6,500 = 10,000)
$custAfterRcv = $custService->getCustomerById($customer['id']);
assertTest("تخفيض رصيد الصيدلية في كشف الحساب ليصبح 10,000 ج.س", (float)$custAfterRcv['balance'] == 10000.00);

// 6. سند صرف للمورد (Payment Voucher)
// صرف 9,000 ج.س للمورد من حساب بنك الخرطوم
$pay = $accService->createPaymentVoucher([
    'cash_account_id' => $bankAcc['id'],
    'contact_type'    => 'supplier',
    'supplier_id'     => $supplier['id'],
    'amount'          => 9000.00,
    'notes'           => 'تحويل بنكي لسداد جزء من مستحقات المورد',
], 1);
assertTest("إصدار سند صرف بنكي بقيمة 9,000 ج.س", !empty($pay['id']) && (float)$pay['amount'] == 9000.00);

// رصيد البنك ينخفض (100,000 - 9,000 = 91,000)
$bankAfterPay = $accService->getCashAccountById($bankAcc['id']);
assertTest("خصم المبلغ من الحساب البنكي ليصبح 91,000 ج.س", (float)$bankAfterPay['current_balance'] == 91000.00);

// مستحقات المورد تنخفض (29,000 - 9,000 = 20,000)
$supAfterPay = $supService->getSupplierById($supplier['id']);
assertTest("تخفيض مستحقات المورد لتصبح 20,000 ج.س", (float)$supAfterPay['balance'] == 20000.00);

// 7. تحويل مالي بين الحسابات (Transfer Voucher)
// تحويل 10,000 ج.س من الخزينة إلى بنك الخرطوم للإيداع
$transferVch = $accService->createTransferVoucher([
    'cash_account_id'    => $cashBox['id'],
    'to_cash_account_id' => $bankAcc['id'],
    'amount'             => 10000.00,
    'notes'              => 'توريد نقدية من الخزينة إلى الحساب البنكي',
], 1);
assertTest("إصدار سند تحويل مالي بين الخزينة والبنك بقيمة 10,000 ج.س", !empty($transferVch['id']));

$cashBoxFinal = $accService->getCashAccountById($cashBox['id']);
$bankFinal = $accService->getCashAccountById($bankAcc['id']);
assertTest("خصم 10,000 من الخزينة (الرصيد: 46,500)", (float)$cashBoxFinal['current_balance'] == 46500.00);
assertTest("إضافة 10,000 للبنك (الرصيد: 101,000)", (float)$bankFinal['current_balance'] == 101000.00);

echo "\n-----------------------------------------------------\n";
echo "النتيجة النهائية لاختبار المرتجعات والحسابات والسندات: نجاح [{$passCount}] | فشل [{$failCount}]\n";
echo "=====================================================\n";
if ($failCount > 0) exit(1);
