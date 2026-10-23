<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة المستودع الأساسي (Base Repository Interface)
 * لعزل كل وصول للبيانات وتمكين التبديل بين MySQL و Realm DB أو SQLite مستقبلاً
 */
interface BaseRepositoryInterface
{
    /**
     * استرجاع كافة السجلات
     *
     * @param array $columns
     * @return array
     */
    public function all(array $columns = ['*']);

    /**
     * البحث عن سجل بواسطة المعرف (ID)
     *
     * @param int|string $id
     * @param array $columns
     * @return array|null
     */
    public function find($id, array $columns = ['*']);

    /**
     * البحث عن أول سجل يطابق حقلاً وقيمة محددة
     *
     * @param string $field
     * @param mixed $value
     * @param array $columns
     * @return array|null
     */
    public function findBy($field, $value, array $columns = ['*']);

    /**
     * البحث عن كافة السجلات التي تطابق حقلاً معيناً
     *
     * @param string $field
     * @param mixed $value
     * @param array $columns
     * @return array
     */
    public function findAllBy($field, $value, array $columns = ['*']);

    /**
     * إنشاء سجل جديد
     *
     * @param array $data
     * @return int|string معرف السجل المنشأ
     */
    public function create(array $data);

    /**
     * تحديث سجل موجود
     *
     * @param int|string $id
     * @param array $data
     * @return bool
     */
    public function update($id, array $data);

    /**
     * حذف سجل
     *
     * @param int|string $id
     * @return bool
     */
    public function delete($id);

    /**
     * استرجاع السجلات مع ترقيم الصفحات والفرز والتصفية
     *
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @param string $orderBy
     * @param string $orderDir
     * @return array ['items' => [...], 'total' => int, 'page' => int, 'per_page' => int, 'last_page' => int]
     */
    public function paginate($page = 1, $perPage = 25, array $filters = [], $orderBy = 'id', $orderDir = 'DESC');

    /**
     * حساب إجمالي السجلات
     *
     * @param array $filters
     * @return int
     */
    public function count(array $filters = []);
}
