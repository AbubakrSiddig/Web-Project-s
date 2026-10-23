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
        list($page, $perPage) = $this->getPaginationParams($request, 25, 100);
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
     * استيراد أصناف من ملف CSV مع التحقق الأمني التام
     * POST /api/products/import
     */
    public function import(Request $request)
    {
        $csvContent = '';

        try {
            if (!empty($_FILES['csv_file']['tmp_name'])) {
                $csvContent = \Core\Helpers\FileUpload::validateAndReadCsv($_FILES['csv_file']);
            } elseif ($request->input('csv_content')) {
                $csvContent = (string)$request->input('csv_content');
            } else {
                return $this->error('يرجى رفع ملف CSV صالح أو تمرير محتوى نصي للاستيراد.');
            }

            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $result = $this->productService->importProductsCsv($csvContent, $userId);
            return $this->success('تمت معالجة ملف الاستيراد بنجاح', $result);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * رفع صورة الصنف الدوائي وحفظها بأمان
     * POST /api/products/{id}/image
     */
    public function uploadImage(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $product = $this->productService->getProductById($id);
            if (empty($_FILES['image'])) {
                return $this->error('يرجى اختيار ملف الصورة لرفعه.');
            }

            $uploadDir = dirname(__DIR__, 3) . '/public/uploads/products';
            $relativePath = \Core\Helpers\FileUpload::saveSecureImage($_FILES['image'], $uploadDir);

            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $updated = $this->productService->updateProduct($id, ['image_url' => $relativePath], $userId);

            return $this->success('تم رفع وحفظ صورة الصنف بنجاح', [
                'product_id' => $id,
                'image_url'  => $relativePath,
                'product'    => $updated
            ]);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
