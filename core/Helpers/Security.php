<?php
namespace Core\Helpers;

/**
 * فئة الأمان والتشفير وحماية البيانات (Security Helper)
 */
class Security
{
    /**
     * تشفير كلمة المرور عبر BCRYPT
     */
    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * التحقق من تطابق كلمة المرور
     */
    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /**
     * توليد رمز عشوائي آمن (Secure Random Token)
     */
    public static function generateToken($bytes = 32)
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * تنظيف النصوص من وسوم XSS
     */
    public static function sanitize($input)
    {
        if (is_array($input)) {
            foreach ($input as $k => $v) {
                $input[$k] = self::sanitize($v);
            }
            return $input;
        }

        if (is_string($input)) {
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }

        return $input;
    }

    /**
     * توليد رمز حماية CSRF للجلسة
     */
    public static function generateCsrfToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken(32);
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * التحقق من صحة رمز CSRF
     */
    public static function validateCsrfToken($token)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
