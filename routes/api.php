<?php
use Core\Middleware\AuthMiddleware;
use Core\Middleware\PermissionMiddleware;
use Core\Router;

/** @var Router $router */

$router->group(['prefix' => '/api'], function (Router $api) {

    // 1. فحص سلامة النواة
    $api->get('/health', 'App\Controllers\Api\HealthCheckController@index');

    // 2. مسارات المصادقة العامة
    $api->post('/auth/login', 'App\Controllers\Api\AuthController@login');

    // 3. مسارات المصادقة المحمية
    $api->get('/auth/me', 'App\Controllers\Api\AuthController@me', [AuthMiddleware::class]);
    $api->post('/auth/logout', 'App\Controllers\Api\AuthController@logout', [AuthMiddleware::class]);

    // 4. مسارات لوحة التحكم (Dashboard)
    $api->group(['prefix' => '/dashboard', 'middleware' => [AuthMiddleware::class]], function (Router $dash) {
        $dash->get('/stats', 'App\Controllers\Api\DashboardController@stats', [new PermissionMiddleware('dashboard.view')]);
        $dash->get('/charts', 'App\Controllers\Api\DashboardController@charts', [new PermissionMiddleware('dashboard.view')]);
    });

    // 5. مسارات إدارة المستخدمين (Users Management)
    $api->group(['prefix' => '/users', 'middleware' => [AuthMiddleware::class]], function (Router $users) {
        $users->get('', 'App\Controllers\Api\UserController@index', [new PermissionMiddleware('users.view')]);
        $users->get('/{id}', 'App\Controllers\Api\UserController@show', [new PermissionMiddleware('users.view')]);
        $users->post('', 'App\Controllers\Api\UserController@store', [new PermissionMiddleware('users.create')]);
        $users->put('/{id}', 'App\Controllers\Api\UserController@update', [new PermissionMiddleware('users.edit')]);
        $users->put('/{id}/password', 'App\Controllers\Api\UserController@changePassword', [new PermissionMiddleware('users.edit')]);
        $users->put('/{id}/toggle-status', 'App\Controllers\Api\UserController@toggleStatus', [new PermissionMiddleware('users.delete')]);
    });

    // 6. مسارات إدارة الأدوار والصلاحيات (Roles & Permissions)
    $api->group(['prefix' => '', 'middleware' => [AuthMiddleware::class]], function (Router $rbac) {
        $rbac->get('/roles', 'App\Controllers\Api\RoleController@index', [new PermissionMiddleware('roles.manage')]);
        $rbac->get('/roles/{id}', 'App\Controllers\Api\RoleController@show', [new PermissionMiddleware('roles.manage')]);
        $rbac->put('/roles/{id}/permissions', 'App\Controllers\Api\RoleController@syncPermissions', [new PermissionMiddleware('roles.manage')]);
        $rbac->get('/permissions', 'App\Controllers\Api\RoleController@permissions', [new PermissionMiddleware('roles.manage')]);
    });

    // 7. مسارات الفئات الدوائية (Categories)
    $api->group(['prefix' => '/categories', 'middleware' => [AuthMiddleware::class]], function (Router $cats) {
        $cats->get('', 'App\Controllers\Api\CategoryController@index', [new PermissionMiddleware('products.view')]);
        $cats->get('/{id}', 'App\Controllers\Api\CategoryController@show', [new PermissionMiddleware('products.view')]);
        $cats->post('', 'App\Controllers\Api\CategoryController@store', [new PermissionMiddleware('products.create')]);
        $cats->put('/{id}', 'App\Controllers\Api\CategoryController@update', [new PermissionMiddleware('products.edit')]);
        $cats->delete('/{id}', 'App\Controllers\Api\CategoryController@destroy', [new PermissionMiddleware('products.delete')]);
    });

    // 8. مسارات الأصناف والأدوية (Products)
    $api->group(['prefix' => '/products', 'middleware' => [AuthMiddleware::class]], function (Router $prods) {
        $prods->get('/export', 'App\Controllers\Api\ProductController@export', [new PermissionMiddleware('products.view')]);
        $prods->post('/import', 'App\Controllers\Api\ProductController@import', [new PermissionMiddleware('products.create')]);
        $prods->get('', 'App\Controllers\Api\ProductController@index', [new PermissionMiddleware('products.view')]);
        $prods->get('/{id}', 'App\Controllers\Api\ProductController@show', [new PermissionMiddleware('products.view')]);
        $prods->post('', 'App\Controllers\Api\ProductController@store', [new PermissionMiddleware('products.create')]);
        $prods->put('/{id}', 'App\Controllers\Api\ProductController@update', [new PermissionMiddleware('products.edit')]);
        $prods->post('/{id}/image', 'App\Controllers\Api\ProductController@uploadImage', [new PermissionMiddleware('products.edit')]);
        $prods->put('/{id}/toggle-status', 'App\Controllers\Api\ProductController@toggleStatus', [new PermissionMiddleware('products.delete')]);
    });

    // 9. مسارات التشغيلات والصلاحيات (Batches & Expiry)
    $api->group(['prefix' => '/batches', 'middleware' => [AuthMiddleware::class]], function (Router $batches) {
        $batches->get('/near-expiry', 'App\Controllers\Api\BatchController@nearExpiry', [new PermissionMiddleware('batches.view')]);
        $batches->get('/expired', 'App\Controllers\Api\BatchController@expired', [new PermissionMiddleware('batches.view')]);
        $batches->get('/policy', 'App\Controllers\Api\BatchController@getPolicy', [new PermissionMiddleware('batches.view')]);
        $batches->put('/policy', 'App\Controllers\Api\BatchController@setPolicy', [new PermissionMiddleware('settings.manage')]);
        $batches->post('/suggest-dispatch', 'App\Controllers\Api\BatchController@suggestDispatch', [new PermissionMiddleware('batches.view')]);
        $batches->post('/mark-expired', 'App\Controllers\Api\BatchController@markExpired', [new PermissionMiddleware('batches.edit')]);
        $batches->get('', 'App\Controllers\Api\BatchController@index', [new PermissionMiddleware('batches.view')]);
        $batches->get('/{id}', 'App\Controllers\Api\BatchController@show', [new PermissionMiddleware('batches.view')]);
        $batches->post('', 'App\Controllers\Api\BatchController@store', [new PermissionMiddleware('batches.create')]);
        $batches->put('/{id}', 'App\Controllers\Api\BatchController@update', [new PermissionMiddleware('batches.edit')]);
    });

    // 10. مسارات المخازن والمستودعات (Warehouses)
    $api->group(['prefix' => '/warehouses', 'middleware' => [AuthMiddleware::class]], function (Router $wh) {
        $wh->get('', 'App\Controllers\Api\WarehouseController@index', [new PermissionMiddleware('warehouses.view')]);
        $wh->get('/{id}', 'App\Controllers\Api\WarehouseController@show', [new PermissionMiddleware('warehouses.view')]);
        $wh->post('', 'App\Controllers\Api\WarehouseController@store', [new PermissionMiddleware('warehouses.manage')]);
        $wh->put('/{id}', 'App\Controllers\Api\WarehouseController@update', [new PermissionMiddleware('warehouses.manage')]);
        $wh->get('/{id}/inventory', 'App\Controllers\Api\WarehouseController@inventory', [new PermissionMiddleware('stock.view')]);
    });

    // 11. مسارات الحركات المخزنية والتحويلات والجرد (Stock)
    $api->group(['prefix' => '/stock', 'middleware' => [AuthMiddleware::class]], function (Router $stk) {
        $stk->get('/transactions', 'App\Controllers\Api\StockController@transactions', [new PermissionMiddleware('stock.view')]);
        $stk->get('/transfers', 'App\Controllers\Api\StockController@transfersIndex', [new PermissionMiddleware('stock.transfer')]);
        $stk->get('/transfers/{id}', 'App\Controllers\Api\StockController@transfersShow', [new PermissionMiddleware('stock.transfer')]);
        $stk->post('/transfers', 'App\Controllers\Api\StockController@transfersStore', [new PermissionMiddleware('stock.transfer')]);
        $stk->put('/transfers/{id}/status', 'App\Controllers\Api\StockController@transfersUpdateStatus', [new PermissionMiddleware('stock.transfer')]);
        $stk->get('/adjustments', 'App\Controllers\Api\StockController@adjustmentsIndex', [new PermissionMiddleware('stock.adjust')]);
        $stk->get('/adjustments/{id}', 'App\Controllers\Api\StockController@adjustmentsShow', [new PermissionMiddleware('stock.adjust')]);
        $stk->post('/adjustments', 'App\Controllers\Api\StockController@adjustmentsStore', [new PermissionMiddleware('stock.adjust')]);
        $stk->put('/adjustments/{id}/status', 'App\Controllers\Api\StockController@adjustmentsUpdateStatus', [new PermissionMiddleware('stock.adjust')]);
    });

    // 12. مسارات الموردين (Suppliers)
    $api->group(['prefix' => '/suppliers', 'middleware' => [AuthMiddleware::class]], function (Router $sup) {
        $sup->get('', 'App\Controllers\Api\SupplierController@index', [new PermissionMiddleware('suppliers.view')]);
        $sup->get('/{id}', 'App\Controllers\Api\SupplierController@show', [new PermissionMiddleware('suppliers.view')]);
        $sup->post('', 'App\Controllers\Api\SupplierController@store', [new PermissionMiddleware('suppliers.create')]);
        $sup->put('/{id}', 'App\Controllers\Api\SupplierController@update', [new PermissionMiddleware('suppliers.edit')]);
        $sup->get('/{id}/ledger', 'App\Controllers\Api\SupplierController@ledger', [new PermissionMiddleware('ledger.view')]);
    });

    // 13. مسارات فواتير المشتريات (Purchases)
    $api->group(['prefix' => '/purchases', 'middleware' => [AuthMiddleware::class]], function (Router $pur) {
        $pur->get('', 'App\Controllers\Api\PurchaseController@index', [new PermissionMiddleware('purchases.view')]);
        $pur->get('/{id}', 'App\Controllers\Api\PurchaseController@show', [new PermissionMiddleware('purchases.view')]);
        $pur->post('', 'App\Controllers\Api\PurchaseController@store', [new PermissionMiddleware('purchases.create')]);
    });

    // 14. مسارات العملاء والصيدليات (Customers)
    $api->group(['prefix' => '/customers', 'middleware' => [AuthMiddleware::class]], function (Router $cust) {
        $cust->get('', 'App\Controllers\Api\CustomerController@index', [new PermissionMiddleware('customers.view')]);
        $cust->get('/{id}', 'App\Controllers\Api\CustomerController@show', [new PermissionMiddleware('customers.view')]);
        $cust->post('', 'App\Controllers\Api\CustomerController@store', [new PermissionMiddleware('customers.create')]);
        $cust->put('/{id}', 'App\Controllers\Api\CustomerController@update', [new PermissionMiddleware('customers.edit')]);
        $cust->get('/{id}/ledger', 'App\Controllers\Api\CustomerController@ledger', [new PermissionMiddleware('ledger.view')]);
        $cust->post('/{id}/adjust-balance', 'App\Controllers\Api\CustomerController@adjustBalance', [new PermissionMiddleware('customers.edit')]);
    });

    // 15. مسارات فواتير المبيعات (Sales)
    $api->group(['prefix' => '/sales', 'middleware' => [AuthMiddleware::class]], function (Router $sales) {
        $sales->get('', 'App\Controllers\Api\SalesController@index', [new PermissionMiddleware('sales.view')]);
        $sales->get('/{id}', 'App\Controllers\Api\SalesController@show', [new PermissionMiddleware('sales.view')]);
        $sales->post('', 'App\Controllers\Api\SalesController@store', [new PermissionMiddleware('sales.create')]);
        $sales->post('/{id}/cancel', 'App\Controllers\Api\SalesController@cancel', [new PermissionMiddleware('sales.cancel')]);
        $sales->get('/{id}/print', 'App\Controllers\Api\SalesController@printInvoice', [new PermissionMiddleware('sales.view')]);
    });

    // 16. مسارات المرتجعات (Returns)
    $api->group(['prefix' => '/returns', 'middleware' => [AuthMiddleware::class]], function (Router $ret) {
        $ret->get('/sales', 'App\Controllers\Api\ReturnController@salesIndex', [new PermissionMiddleware('sales.return')]);
        $ret->get('/sales/{id}', 'App\Controllers\Api\ReturnController@salesShow', [new PermissionMiddleware('sales.return')]);
        $ret->post('/sales', 'App\Controllers\Api\ReturnController@salesStore', [new PermissionMiddleware('sales.return')]);
        $ret->get('/purchases', 'App\Controllers\Api\ReturnController@purchasesIndex', [new PermissionMiddleware('purchases.return')]);
        $ret->get('/purchases/{id}', 'App\Controllers\Api\ReturnController@purchasesShow', [new PermissionMiddleware('purchases.return')]);
        $ret->post('/purchases', 'App\Controllers\Api\ReturnController@purchasesStore', [new PermissionMiddleware('purchases.return')]);
    });

    // 17. مسارات الحسابات المالية وسندات القبض والصرف (Accounting)
    $api->group(['prefix' => '/accounting', 'middleware' => [AuthMiddleware::class]], function (Router $acc) {
        $acc->get('/accounts', 'App\Controllers\Api\AccountingController@accountsIndex', [new PermissionMiddleware('cash.view')]);
        $acc->post('/accounts', 'App\Controllers\Api\AccountingController@accountsStore', [new PermissionMiddleware('cash.view')]);
        $acc->put('/accounts/{id}', 'App\Controllers\Api\AccountingController@accountsUpdate', [new PermissionMiddleware('cash.view')]);
        $acc->get('/expense-categories', 'App\Controllers\Api\AccountingController@expenseCategoriesIndex', [new PermissionMiddleware('cash.view')]);
        $acc->post('/expense-categories', 'App\Controllers\Api\AccountingController@expenseCategoriesStore', [new PermissionMiddleware('cash.view')]);
        $acc->get('/vouchers', 'App\Controllers\Api\AccountingController@vouchersIndex', [new PermissionMiddleware('vouchers.view')]);
        $acc->get('/vouchers/{id}', 'App\Controllers\Api\AccountingController@vouchersShow', [new PermissionMiddleware('vouchers.view')]);
        $acc->post('/receipts', 'App\Controllers\Api\AccountingController@createReceipt', [new PermissionMiddleware('vouchers.create')]);
        $acc->post('/payments', 'App\Controllers\Api\AccountingController@createPayment', [new PermissionMiddleware('vouchers.create')]);
        $acc->post('/transfers', 'App\Controllers\Api\AccountingController@createTransfer', [new PermissionMiddleware('vouchers.create')]);
        $acc->get('/journal-entries', 'App\Controllers\Api\AccountingController@journalEntriesIndex', [new PermissionMiddleware('ledger.view')]);
        $acc->get('/journal-entries/{id}', 'App\Controllers\Api\AccountingController@journalEntriesShow', [new PermissionMiddleware('ledger.view')]);
        $acc->post('/journal-entries', 'App\Controllers\Api\AccountingController@journalEntriesStore', [new PermissionMiddleware('vouchers.create')]);
    });

    // 18. مسارات التقارير الشاملة (Reports)
    $api->group(['prefix' => '/reports', 'middleware' => [AuthMiddleware::class]], function (Router $rep) {
        $rep->get('/sales', 'App\Controllers\Api\ReportController@sales', [new PermissionMiddleware('reports.view')]);
        $rep->get('/purchases', 'App\Controllers\Api\ReportController@purchases', [new PermissionMiddleware('reports.view')]);
        $rep->get('/inventory', 'App\Controllers\Api\ReportController@inventory', [new PermissionMiddleware('reports.view')]);
        $rep->get('/expiry', 'App\Controllers\Api\ReportController@expiry', [new PermissionMiddleware('reports.view')]);
        $rep->get('/profitability', 'App\Controllers\Api\ReportController@profitability', [new PermissionMiddleware('reports.view')]);
        $rep->get('/customer-balances', 'App\Controllers\Api\ReportController@customerBalances', [new PermissionMiddleware('reports.view')]);
        $rep->get('/supplier-balances', 'App\Controllers\Api\ReportController@supplierBalances', [new PermissionMiddleware('reports.view')]);
    });

    // 19. مسارات سجل التدقيق الأمني (Audit Logs)
    $api->group(['prefix' => '/audit-logs', 'middleware' => [AuthMiddleware::class]], function (Router $aud) {
        $aud->get('', 'App\Controllers\Api\AuditLogController@index', [new PermissionMiddleware('audit.view')]);
        $aud->get('/{id}', 'App\Controllers\Api\AuditLogController@show', [new PermissionMiddleware('audit.view')]);
    });

});
