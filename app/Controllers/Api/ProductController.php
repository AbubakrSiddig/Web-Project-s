<?php
namespace App\Controllers\Api;

use App\Services\ProductService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم الأصناف والأدوية (Product Controller)
 */
class ProductController extends Controller
{
    /** @var ProductService */
    protected $productService;

    public function __construct(ProductService $productService = null)
    {
        $this->productService = $productService ?: new ProductService();
    }

    /**
     * قائمة الأصناف مع الفلترة والبحث المتقدم
     * GET /api/products
     */
    public function index(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'search'      => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'is_active'   => $request->query('is_active'),
        ];

        $products = $this->productService->getProducts($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة الأصناف بنجاح', $products);
    }

    /**
     * عرض صنف محدد
     * GET /api/products/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $product = $this->productService->getProductById($id);
            return $this->success('تم استرجاع بيانات الصنف بنجاح', $product);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إضافة صنف جديد
     * POST /api/products
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'category_id'       => 'required|integer|positive',
            'trade_name'        => 'required|min:2|max:200',
            'purchase_price'    => 'numeric|positive',
            'selling_price'     => 'numeric|positive',
            'min_selling_price' => 'numeric|positive',
            'min_stock_alert'   => 'integer|positive',
        ]);

        try {
            $currentUser = $request->getUser();
            $newProduct = $this->productService->createProduct($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إضافة الصنف الدوائي بنجاح', $newProduct, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل بيانات صنف
     * PUT /api/products/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $data = $this->validate($request->all(), [
            'category_id'       => 'integer|positive',
            'trade_name'        => 'min:2|max:200',
            'purchase_price'    => 'numeric|positive',
            'selling_price'     => 'numeric|positive',
            'min_selling_price' => 'numeric|positive',
            'min_stock_alert'   => 'integer|positive',
        ]);

        try {
            $currentUser = $request->getUser();
            $updated = $this->productService->updateProduct($id, $request->all(), $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات الصنف بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تبديل حالة الصنف (تفعيل / تعطيل)
     * PUT /api/products/{id}/toggle-status
     */
    public function toggleStatus(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $newStatus = $this->productService->toggleProductStatus($id, $currentUser['id'] ?? null);
            $msg = $newStatus === 1 ? 'تم تفعيل الصنف بنجاح' : 'تم تعطيل الصنف بنجاح';
            return $this->success($msg, ['is_active' => $newStatus]);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تصدير الأصناف كملف CSV للإكسل
     * GET /api/products/export
     */
    public function export(Request $request)
    {
        $filters = [
            'category_id' => $request->query('category_id'),
            'is_active'   => $request->query('is_active'),
        ];

        $csv = $this->productService->exportProductsCsv($filters);
        $filename = 'poker_pharma_products_' . date('Y-m-d_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $csv;
        exit;
    }

    /**
     * استيراد أصناف من ملف CSV
     * POST /api/products/import
     */
    public function import(Request $request)
    {
        $csvContent = '';

        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif ($request->input('csv_content')) {
            $csvContent = $request->input('csv_content');
        } else {
            return $this->error('يرجى رفع ملف CSV أو تمرير محتوى نصي للاستيراد.');
        }

        try {
            $currentUser = $request->getUser();
            $result = $this->productService->importProductsCsv($csvContent, $currentUser['id'] ?? null);
            return $this->success('تمت معالجة ملف الاستيراد بنجاح', $result);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
