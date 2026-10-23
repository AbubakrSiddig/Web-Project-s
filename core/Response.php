<?php
namespace Core;

/**
 * فئة الرد الموحد (HTTP / JSON Response)
 */
class Response
{
    /**
     * إرسال رد JSON موحد
     *
     * @param array $payload
     * @param int $statusCode
     */
    public static function json($payload, $statusCode = 200)
    {
        // تنظيف أي مخرجات سابقة غير مقصودة
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');

        // الترويسات الداعمة لـ CORS ولتطبيق Electron
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');

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
     */
    public static function error($message = 'حدث خطأ أثناء معالجة الطلب', $errors = null, $statusCode = 400)
    {
        return self::json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
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
        return self::error($message, null, 404);
    }

    /**
     * خطأ غير مصرح (401 Unauthorized)
     */
    public static function unauthorized($message = 'يجب تسجيل الدخول أولاً للوصول إلى هذا المورد')
    {
        return self::error($message, null, 401);
    }

    /**
     * خطأ ممنوع / غير مسموح بالصلاحية (403 Forbidden)
     */
    public static function forbidden($message = 'ليس لديك الصلاحية الكافية لتنفيذ هذا الإجراء')
    {
        return self::error($message, null, 403);
    }

    /**
     * خطأ في التحقق من المدخلات (422 Unprocessable Entity)
     */
    public static function validationError(array $errors, $message = 'بيانات الإدخال غير صالحة')
    {
        return self::error($message, $errors, 422);
    }

    /**
     * معالجة طلبات OPTIONS الخاصة بـ Preflight في CORS
     */
    public static function sendCorsHeaders()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
        http_response_code(200);
        exit;
    }
}
