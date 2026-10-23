<?php
namespace App\Services;

use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\MySQL\MySQLCategoryRepository;
use Core\Service;
use Exception;

/**
 * خدمة فئات الأدوية والمستلزمات (Category Service)
 */
class CategoryService extends Service
{
    /** @var CategoryRepositoryInterface */
    protected $categoryRepo;

    public function __construct(CategoryRepositoryInterface $categoryRepo = null)
    {
        $this->categoryRepo = $categoryRepo ?: new MySQLCategoryRepository();
    }

    public function getAllCategories()
    {
        return $this->categoryRepo->getCategoriesWithProductCount();
    }

    public function getCategoryById($id)
    {
        $cat = $this->categoryRepo->find($id);
        if (!$cat) {
            throw new Exception("الفئة المطلوبة غير موجودة.");
        }
        return $cat;
    }

    public function createCategory(array $data, $userId = null)
    {
        $name = trim($data['name']);
        $existing = $this->categoryRepo->findBy('name', $name);
        if ($existing) {
            throw new Exception("الفئة [{$name}] مسجلة مسبقاً.");
        }

        $id = $this->categoryRepo->create([
            'name'        => $name,
            'description' => !empty($data['description']) ? trim($data['description']) : null,
            'is_active'   => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit($userId, 'create', 'categories', $id, null, ['name' => $name]);

        return $this->getCategoryById($id);
    }

    public function updateCategory($id, array $data, $userId = null)
    {
        $category = $this->getCategoryById($id);

        if (!empty($data['name']) && $data['name'] !== $category['name']) {
            $existing = $this->categoryRepo->findBy('name', trim($data['name']));
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("اسم الفئة [{$data['name']}] مسجل مسبقاً.");
            }
        }

        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = trim($data['name']);
        if (array_key_exists('description', $data)) $updateData['description'] = !empty($data['description']) ? trim($data['description']) : null;
        if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];

        if (!empty($updateData)) {
            $this->categoryRepo->update($id, $updateData);
            $this->logAudit($userId, 'update', 'categories', $id, ['name' => $category['name']], $updateData);
        }

        return $this->getCategoryById($id);
    }

    public function deleteCategory($id, $userId = null)
    {
        $category = $this->getCategoryById($id);

        // التحقق من عدم وجود أصناف مرتبطة
        $cats = $this->categoryRepo->getCategoriesWithProductCount();
        foreach ($cats as $c) {
            if ((int)$c['id'] === (int)$id && (int)$c['products_count'] > 0) {
                throw new Exception("لا يمكن حذف هذه الفئة لوجود ({$c['products_count']}) صنف مرتبط بها. يمكنك تعطيل الفئة بدلاً من ذلك.");
            }
        }

        $this->categoryRepo->delete($id);
        $this->logAudit($userId, 'delete', 'categories', $id, ['name' => $category['name']], null);

        return true;
    }
}
