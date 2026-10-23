<?php
namespace App\Controllers\Api;

use App\Services\CategoryService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم فئات الأدوية والمستلزمات (Category Controller)
 */
class CategoryController extends Controller
{
    /** @var CategoryService */
    protected $categoryService;

    public function __construct(CategoryService $categoryService = null)
    {
        $this->categoryService = $categoryService ?: new CategoryService();
    }

    /**
     * قائمة الفئات
     * GET /api/categories
     */
    public function index(Request $request)
    {
        $categories = $this->categoryService->getAllCategories();
        return $this->success('تم استرجاع قائمة الفئات بنجاح', $categories);
    }

    /**
     * عرض فئة
     * GET /api/categories/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $category = $this->categoryService->getCategoryById($id);
            return $this->success('تم استرجاع بيانات الفئة بنجاح', $category);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إضافة فئة جديدة
     * POST /api/categories
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'name' => 'required|min:2|max:100',
        ]);

        try {
            $currentUser = $request->getUser();
            $newCat = $this->categoryService->createCategory($data, $currentUser['id'] ?? null);
            return $this->success('تم إضافة الفئة الدوائية بنجاح', $newCat, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل فئة
     * PUT /api/categories/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $data = $this->validate($request->all(), [
            'name' => 'min:2|max:100',
        ]);

        try {
            $currentUser = $request->getUser();
            $updated = $this->categoryService->updateCategory($id, $data, $currentUser['id'] ?? null);
            return $this->success('تم تحديث الفئة بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * حذف فئة
     * DELETE /api/categories/{id}
     */
    public function destroy(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $this->categoryService->deleteCategory($id, $currentUser['id'] ?? null);
            return $this->success('تم حذف الفئة بنجاح');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
