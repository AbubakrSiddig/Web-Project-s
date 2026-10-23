<?php
namespace Core;

/**
 * فئة الرد الموحد (HTTP / JSON Response)
 */
class Response
{
    /**
     * إرسال ترويسات الأمان العامة وCORS المقيد
     */
    public static function applySecurityAndCorsHeaders()
    {
        // 1. ترويسات الحماية الأمنية القياسية
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: https: blob:; font-src 'self' https: data:;");

        // 2. إعدادات CORS المقيدة والآمنة
        $appConfig = require dirname(__DIR__) . '/config/app.php';
        $allowedOrigins = $appConfig['cors']['allowed_origins'] ?? [];
        $isDev = ($appConfig['env'] ?? 'production') === 'development';
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!empty($requestOrigin)) {
            $isAllowed = false;

            if (in_array('*', $allowedOrigins) && $isDev) {
                $isAllowed = true;
            } elseif (in_array($requestOrigin, $allowedOrigins)) {
                $isAllowed = true;
            } elseif ($isDev && (preg_match('#^http://(localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+)(:\d+)?$#', $requestOrigin) || $requestOrigin === 'null')) {
                // في التطوير المحلي نسمح بعناوين المطور وتطبيق Electron
                $isAllowed = true;
            }

            if ($isAllowed) {
                header("Access-Control-Allow-Origin: {$requestOrigin}");
                header('Access-Control-Allow-Credentials: true');
            }
        }

        $allowedMethods = implode(', ', $appConfig['cors']['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
        $allowedHeaders = implode(', ', $appConfig['cors']['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token']);

        header("Access-Control-Allow-Methods: {$allowedMethods}");
        header("Access-Control-Allow-Headers: {$allowedHeaders}");
    }

    /**
     * إرسال رد JSON موحد
     *
     * @param array $payload
     * @param int $statusCode
     */
    public static function json($payload, $statusCode = 200)
    {
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');

        self::applySecurityAndCorsHeaders();

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * رد نجاح موحد (Success Response)
     *
     * @param string $message
     * @param mixed $data
     * @param int $statusCode
     */
    public static function success($message = 'تمت العملية بنجاح', $data = null, $statusCode = 200)
    {
        return self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
            'code'    => 'SUCCESS',
            'meta'    => [
                'timestamp' => date('c'),
                'version'   => '0.1.0-mvp',
            ],
        ], $statusCode);
    }

    /**
     * رد فشل / خطأ موحد (Error Response)
     *
     * @param string $message
     * @param array|null $errors
     * @param int $statusCode
     * @param string $code
     */
    public static function error($message = 'حدث خطأ أثناء معالجة الطلب', $errors = null, $statusCode = 400, $code = 'BAD_REQUEST')
    {
        return self::json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
            'code'    => $code,
            'meta'    => [
                'timestamp' => date('c'),
                'version'   => '0.1.0-mvp',
            ],
        ], $statusCode);
    }

    /**
     * خطأ عدم العثور على العنصر أو المسار (404 Not Found)
     */
    public static function notFound($message = 'العنصر أو المسار المطلوب غير موجود')
    {
        return self::error($message, null, 404, 'NOT_FOUND');
    }

    /**
     * خطأ غير مصرح (401 Unauthorized)
     */
    public static function unauthorized($message = 'يجب تسجيل الدخول أولاً للوصول إلى هذا المورد')
    {
        return self::error($message, null, 401, 'UNAUTHORIZED');
    }

    /**
     * خطأ ممنوع / غير مسموح بالصلاحية (403 Forbidden)
     */
    public static function forbidden($message = 'ليس لديك الصلاحية الكافية لتنفيذ هذا الإجراء')
    {
        return self::error($message, null, 403, 'FORBIDDEN');
    }

    /**
     * خطأ في التحقق من المدخلات (422 Unprocessable Entity)
     */
    public static function validationError(array $errors, $message = 'بيانات الإدخال غير صالحة')
    {
        return self::error($message, $errors, 422, 'VALIDATION_ERROR');
    }

    /**
     * تجاوز معدل الطلبات المسموح (429 Too Many Requests)
     */
    public static function rateLimited($message = 'تجاوزت الحد الأقصى للمحاولات، يرجى الانتظار والمحاولة لاحقاً', $retryAfter = 900)
    {
        header("Retry-After: {$retryAfter}");
        return self::error($message, ['retry_after_seconds' => $retryAfter], 429, 'RATE_LIMITED');
    }

    /**
     * معالجة طلبات OPTIONS الخاصة بـ Preflight في CORS
     */
    public static function sendCorsHeaders()
    {
        self::applySecurityAndCorsHeaders();
        header('Access-Control-Max-Age: 86400');
        http_response_code(200);
        exit;
    }
}
