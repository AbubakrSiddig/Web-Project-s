<?php
namespace Core;

/**
 * فئة الخدمات الأساسية (Base Service)
 * تحتوي على المنطق التجاري المحايد عن نوع قاعدة البيانات
 */
abstract class Service
{
    /**
     * تنفيذ دالة داخل معاملة ذرية (Transaction)
     */
    protected function transaction(callable $callback)
    {
        return Database::transaction($callback);
    }

    /**
     * تسجيل عملية في سجل التدقيق (Audit Log)
     *
     * @param int|null $userId
     * @param string $action (create, update, delete, approve, login)
     * @param string $module (products, sales, batches, etc.)
     * @param int|null $recordId
     * @param array|null $oldValues
     * @param array|null $newValues
     */
    public function logAudit($userId, $action, $module, $recordId = null, $oldValues = null, $newValues = null)
    {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare("
                INSERT INTO `audit_logs` 
                (`user_id`, `action`, `module`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            $stmt->execute([
                $userId,
                $action,
                $module,
                $recordId,
                $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                $ip,
                $ua
            ]);
        } catch (\Exception $e) {
            // سجل التدقيق لا يوقف العملية الأساسية إذا حدث خطأ به
            error_log("فشل تسجيل التدقيق: " . $e->getMessage());
        }
    }
}
