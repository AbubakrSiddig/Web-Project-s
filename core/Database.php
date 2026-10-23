<?php
namespace Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * مدير الاتصال بقاعدة البيانات (Singleton Pattern)
 */
class Database
{
    /** @var Database|null */
    private static $instance = null;

    /** @var PDO */
    private $pdo;

    /**
     * منع الإنشاء المباشر
     */
    private function __construct()
    {
        $configFile = dirname(__DIR__) . '/config/database.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException("ملف إعدادات قاعدة البيانات غير موجود.");
        }

        $config = require $configFile;

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );
        } catch (PDOException $e) {
            throw new RuntimeException("فشل الاتصال بقاعدة بيانات بوكر فارما: " . $e->getMessage());
        }
    }

    /**
     * الحصول على كائن Singleton
     *
     * @return Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * الحصول على كائن PDO المباشر
     *
     * @return PDO
     */
    public function getConnection()
    {
        return $this->pdo;
    }

    /**
     * اختصار سريع للحصول على PDO
     *
     * @return PDO
     */
    public static function pdo()
    {
        return self::getInstance()->getConnection();
    }

    /**
     * بدء معاملة (Transaction)
     */
    public static function beginTransaction()
    {
        return self::pdo()->beginTransaction();
    }

    /**
     * اعتماد المعاملة (Commit)
     */
    public static function commit()
    {
        return self::pdo()->commit();
    }

    /**
     * التراجع عن المعاملة (Rollback)
     */
    public static function rollBack()
    {
        if (self::pdo()->inTransaction()) {
            return self::pdo()->rollBack();
        }
        return false;
    }

    /**
     * تنفيذ دالة داخل Transaction تلقائياً
     *
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    public static function transaction(callable $callback)
    {
        self::beginTransaction();
        try {
            $result = $callback(self::pdo());
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException("لا يمكن إلغاء تسلسل Singleton."); }
}
