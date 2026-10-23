<?php
namespace Core\Helpers;

/**
 * فئة إدارة معدل الطلبات وحماية تسجيل الدخول من هجمات التخمين (Brute-Force Protection)
 */
class RateLimiter
{
    /** @var string */
    protected static $storageDir;

    /**
     * مسار مجلد تخزين بيانات الحصر المؤقتة
     */
    protected static function getStorageDir()
    {
        if (!self::$storageDir) {
            $dir = dirname(__DIR__, 2) . '/tmp/rate_limits';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_writable($dir)) {
                $dir = sys_get_temp_dir() . '/poker_pharma_rate_limits';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
            }
            self::$storageDir = $dir;
        }
        return self::$storageDir;
    }

    /**
     * الحصول على اسم ملف المفتاح
     */
    protected static function getFilePath($key)
    {
        return self::getStorageDir() . '/' . md5($key) . '.json';
    }

    /**
     * فحص هل تجاوز المفتاح الحد الأقصى للمحاولات
     *
     * @param string $key
     * @param int $maxAttempts
     * @return bool
     */
    public static function tooManyAttempts($key, $maxAttempts = 5)
    {
        $file = self::getFilePath($key);
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['expires_at'])) {
            return false;
        }

        // إذا انتهت فترة الحظر
        if (time() >= $data['expires_at']) {
            @unlink($file);
            return false;
        }

        return (int)($data['attempts'] ?? 0) >= $maxAttempts;
    }

    /**
     * فحص هل المفتاح محظور (اسم بديل متوافق)
     */
    public static function isLimited($key, $maxAttempts = 5, $decaySeconds = 900, $action = '')
    {
        $compositeKey = $action ? "{$action}:{$key}" : $key;
        return self::tooManyAttempts($compositeKey, $maxAttempts);
    }

    /**
     * تسجيل محاولة فاشلة
     *
     * @param string $key
     * @param int $decaySeconds مدة الحظر بالثواني (افتراضياً 900 ثانية = 15 دقيقة)
     * @param string $action
     * @return int عدد المحاولات الحالي
     */
    public static function hit($key, $decaySeconds = 900, $action = '')
    {
        $compositeKey = $action ? "{$action}:{$key}" : $key;
        $file = self::getFilePath($compositeKey);
        $attempts = 0;
        $expiresAt = time() + $decaySeconds;

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['expires_at']) && time() < $data['expires_at']) {
                $attempts = (int)($data['attempts'] ?? 0);
                $expiresAt = $data['expires_at'];
            }
        }

        $attempts++;

        $payload = [
            'attempts'   => $attempts,
            'expires_at' => $expiresAt,
            'last_hit'   => time(),
        ];

        file_put_contents($file, json_encode($payload), LOCK_EX);

        return $attempts;
    }

    /**
     * الوقت المتبقي لانتهاء الحظر بالثواني
     *
     * @param string $key
     * @param string $action
     * @return int
     */
    public static function availableIn($key, $action = '')
    {
        $compositeKey = $action ? "{$action}:{$key}" : $key;
        $file = self::getFilePath($compositeKey);
        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['expires_at'])) {
            return 0;
        }

        return max(0, $data['expires_at'] - time());
    }

    /**
     * تصفير المحاولات عند تسجيل الدخول بنجاح
     *
     * @param string $key
     * @param string $action
     */
    public static function clear($key, $action = '')
    {
        $compositeKey = $action ? "{$action}:{$key}" : $key;
        $file = self::getFilePath($compositeKey);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
