<?php
namespace Core\Helpers;

use Exception;

/**
 * فئة إنشاء والتحقق من رموز المصادقة الموقعة (HMAC-SHA256 Token)
 * خفيفة ومستقلة تماماً بدون مكتبات خارجية، ومثالية للـ API وتطبيق Electron
 */
class Jwt
{
    /**
     * الحصول على المفتاح السري للتوقيع
     */
    protected static function getSecretKey()
    {
        $appConfig = require dirname(__DIR__, 2) . '/config/app.php';
        return getenv('APP_KEY') ?: md5($appConfig['name'] . '_poker_pharma_secret_key_2026');
    }

    /**
     * تشفير Base64Url
     */
    protected static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * فك تشفير Base64Url
     */
    protected static function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * إنشاء رمز مصادقة موقع
     *
     * @param array $payload البيانات المراد تضمينها
     * @param int $expirySeconds مدة الصلاحية بالثواني (افتراضياً 24 ساعة)
     * @return string
     */
    public static function encode(array $payload, $expirySeconds = 86400)
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expirySeconds;

        $encodedHeader = self::base64UrlEncode($header);
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $signature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", self::getSecretKey(), true);
        $encodedSignature = self::base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    /**
     * التحقق من صحة الرمز واستخراج البيانات
     *
     * @param string $token
     * @return array|null البيانات أو null إذا كان الرمز غير صالح أو منتهي
     */
    public static function decode($token)
    {
        if (empty($token) || !is_string($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($encodedHeader, $encodedPayload, $encodedSignature) = $parts;

        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        if (!$header || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", self::getSecretKey(), true)
        );

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            return null; // توقيع غير صالح أو تم التلاعب به
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!$payload) {
            return null;
        }

        // التحقق من تاريخ الانتهاء
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null; // منتهي الصلاحية
        }

        return $payload;
    }
}
