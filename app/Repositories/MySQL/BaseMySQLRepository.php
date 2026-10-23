<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\BaseRepositoryInterface;
use Core\Database;
use PDO;

/**
 * التنفيذ الأساسي لمستودع البيانات عبر MySQL / PDO
 */
abstract class BaseMySQLRepository implements BaseRepositoryInterface
{
    /** @var string اسم الجدول بقاعدة البيانات */
    protected $table;

    /** @var string اسم حقل المفتاح الأساسي */
    protected $primaryKey = 'id';

    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * استرجاع كافة السجلات
     */
    public function all(array $columns = ['*'])
    {
        $cols = implode(', ', $columns);
        $stmt = $this->db->query("SELECT {$cols} FROM `{$this->table}` ORDER BY `{$this->primaryKey}` ASC");
        return $stmt->fetchAll();
    }

    /**
     * البحث بواسطة المعرف
     */
    public function find($id, array $columns = ['*'])
    {
        $cols = implode(', ', $columns);
        $stmt = $this->db->prepare("SELECT {$cols} FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * البحث عن أول سجل بحقل وقيمة
     */
    public function findBy($field, $value, array $columns = ['*'])
    {
        $cols = implode(', ', $columns);
        $stmt = $this->db->prepare("SELECT {$cols} FROM `{$this->table}` WHERE `{$field}` = ? LIMIT 1");
        $stmt->execute([$value]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * البحث عن كافة السجلات بحقل وقيمة
     */
    public function findAllBy($field, $value, array $columns = ['*'])
    {
        $cols = implode(', ', $columns);
        $stmt = $this->db->prepare("SELECT {$cols} FROM `{$this->table}` WHERE `{$field}` = ? ORDER BY `{$this->primaryKey}` DESC");
        $stmt->execute([$value]);
        return $stmt->fetchAll();
    }

    /**
     * إنشاء سجل جديد
     */
    public function create(array $data)
    {
        $fields = array_keys($data);
        $escapedFields = array_map(function ($f) { return "`{$f}`"; }, $fields);
        $placeholders = array_fill(0, count($fields), '?');

        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $this->table,
            implode(', ', $escapedFields),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));

        return $this->db->lastInsertId();
    }

    /**
     * تحديث سجل
     */
    public function update($id, array $data)
    {
        $fields = array_keys($data);
        $setClauses = array_map(function ($f) { return "`{$f}` = ?"; }, $fields);

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE `%s` = ?",
            $this->table,
            implode(', ', $setClauses),
            $this->primaryKey
        );

        $values = array_values($data);
        $values[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * حذف سجل
     */
    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ترقيم الصفحات والفرز
     */
    public function paginate($page = 1, $perPage = 25, array $filters = [], $orderBy = 'id', $orderDir = 'DESC')
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(200, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        foreach ($filters as $field => $val) {
            if ($val !== null && $val !== '') {
                $where[] = "`{$field}` = ?";
                $bindings[] = $val;
            }
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // حساب الإجمالي
        $countSql = "SELECT COUNT(*) FROM `{$this->table}` {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        // استرجاع السجلات
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
        $dataSql = "SELECT * FROM `{$this->table}` {$whereClause} ORDER BY `{$orderBy}` {$orderDir} LIMIT {$perPage} OFFSET {$offset}";
        $dataStmt = $this->db->prepare($dataSql);
        $dataStmt->execute($bindings);
        $items = $dataStmt->fetchAll();

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'last_page'   => (int)ceil($total / $perPage),
        ];
    }

    /**
     * حساب إجمالي السجلات
     */
    public function count(array $filters = [])
    {
        $where = [];
        $bindings = [];

        foreach ($filters as $field => $val) {
            if ($val !== null && $val !== '') {
                $where[] = "`{$field}` = ?";
                $bindings[] = $val;
            }
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) FROM `{$this->table}` {$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        return (int)$stmt->fetchColumn();
    }
}
