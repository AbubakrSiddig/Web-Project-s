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
     *
     * @return string
     * @throws \RuntimeException
     */
    public static function getSecretKey()
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            \Core\Helpers\Env::load($envPath);
        }

        $appConfig = require dirname(__DIR__, 2) . '/config/app.php';
        $key = \Core\Helpers\Env::get('APP_KEY') ?: getenv('APP_KEY') ?: ($appConfig['app_key'] ?? '');

        $isProduction = ($appConfig['env'] ?? 'production') === 'production';

        if (empty($key)) {
            if ($isProduction) {
                throw new \RuntimeException("مفتاح APP_KEY الإنتاجي غير مضبوط. يمنع تشغيل النظام في بيئة الإنتاج بمفتاح فارغ أو افتراضي لأسباب أمنية.");
            }
            // في بيئة التطوير المحلية فقط إذا لم يُعرف مفتاح في .env يتم استخدام مفتاح تطويري مع التحذير
            $key = 'dev_poker_secret_key_change_in_production_min_32_chars_long!';
        }

        if (strlen($key) < 32) {
            if ($isProduction) {
                throw new \RuntimeException("مفتاح APP_KEY ضعيف جداً. يجب أن لا يقل عن 32 محرفاً في بيئة الإنتاج.");
            }
        }

        return $key;
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
        $payload['nbf'] = $now;
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

        // التحقق من تاريخ الانتهاء الإلزامي (exp is strictly mandatory)
        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int)$payload['exp'] < time()) {
            return null; // منتهي الصلاحية أو غير صالح
        }

        // التحقق من تاريخ الصلاحية المستقبلي (Not Before) مع هامش انحراف ساعة 60 ثانية
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int)$payload['nbf'] > (time() + 60)) {
            return null; // الرمز غير صالح قبل هذا الوقت
        }

        // التحقق من تاريخ الإصدار (iat)
        if (!isset($payload['iat']) || !is_numeric($payload['iat']) || (int)$payload['iat'] > (time() + 60)) {
            return null; // تاريخ الإصدار مستقبلي غير صالح
        }

        return $payload;
    }
}
