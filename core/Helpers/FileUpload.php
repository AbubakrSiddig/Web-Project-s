<?php
namespace Core\Helpers;

use Exception;

/**
 * فئة التحقق الآمن من الملفات المرفوعة وحمايتها (Secure File Upload Handler)
 */
class FileUpload
{
    /** @var array */
    protected static $allowedImageMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** @var array */
    protected static $allowedCsvMimes = [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'text/x-csv',
    ];

    /**
     * التحقق من سلامة صورة مرفوعة
     *
     * @param array $file
     * @param int $maxSizeBytes
     * @return true|string true عند النجاح أو رسالة الخطأ
     */
    public static function validateImage(array $file, $maxSizeBytes = 2097152)
    {
        if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return "فشل رفع الملف أو لم يتم تحديد ملف صحيح.";
        }

        if ($file['size'] > $maxSizeBytes) {
            return "حجم الصورة يتجاوز الحد الأقصى المسموح به.";
        }

        $originalExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($originalExt, ['jpg', 'jpeg', 'png', 'webp'])) {
            return "امتداد الملف غير مسموح.";
        }

        if (function_exists('finfo_open') && !empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!array_key_exists($mime, self::$allowedImageMimes)) {
                return "نوع ملف الصورة الحقيقي غير مدعوم أو غير آمن.";
            }
        }

        return true;
    }

    /**
     * التحقق من سلامة وأمان صورة مرفوعة وحفظها باسم عشوائي آمن
     *
     * @param array $file مصفوفة $_FILES['image']
     * @param string $destinationDir مسار المجلد الوجهة
     * @param int $maxSizeBytes الحد الأقصى للحجم (افتراضياً 2 ميجابايت)
     * @return string المسار النسبي للملف المحفوظ
     * @throws Exception
     */
    public static function saveSecureImage(array $file, $destinationDir, $maxSizeBytes = 2097152)
    {
        if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("فشل رفع الملف أو حدث خطأ أثناء النقل (كود: " . ($file['error'] ?? 'مجهول') . ").");
        }

        if ($file['size'] > $maxSizeBytes) {
            $maxMb = round($maxSizeBytes / 1048576, 1);
            throw new Exception("حجم الصورة يتجاوز الحد الأقصى المسموح به ({$maxMb} ميجابايت).");
        }

        // فحص MIME Type الحقيقي عبر finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mime, self::$allowedImageMimes)) {
            throw new Exception("نوع ملف الصورة غير مدعوم أو غير آمن. الأنواع المسموحة فقط: JPG, PNG, WEBP.");
        }

        // فحص الامتداد
        $originalExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeExt = self::$allowedImageMimes[$mime];

        if (!in_array($originalExt, ['jpg', 'jpeg', 'png', 'webp'])) {
            throw new Exception("امتداد الصورة غير مصرح به.");
        }

        self::ensureSecureDirectory($destinationDir);

        // توليد اسم عشوائي آمن ومشفر 32 حرفاً
        $randomName = 'prod_' . bin2hex(random_bytes(16)) . '.' . $safeExt;
        $targetPath = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR . $randomName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception("تعذر حفظ الصورة على الخادم. يرجى مراجعة أذونات المجلد.");
        }

        // إرجاع المسار النسبي للاستخدام في الواجهة
        return 'uploads/products/' . $randomName;
    }

    /**
     * التحقق من سلامة ملف CSV
     *
     * @param array $file
     * @param int $maxSizeBytes الحد الأقصى للحجم (افتراضياً 5 ميجابايت)
     * @return string محتوى الملف بعد التحقق
     * @throws Exception
     */
    public static function validateAndReadCsv(array $file, $maxSizeBytes = 5242880)
    {
        if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("فشل رفع ملف CSV.");
        }

        if ($file['size'] > $maxSizeBytes) {
            throw new Exception("حجم ملف الاستيراد يتجاوز الحد الأقصى (5 ميجابايت).");
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            throw new Exception("يجب أن يكون الملف بصيغة CSV صالحة.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        // التحقق من نوع المحتوى النصي
        if (!in_array($mime, self::$allowedCsvMimes) && strpos($mime, 'text/') !== 0) {
            throw new Exception("الملف المرفوع لا يحتوي على بيانات نصية أو CSV صالحة.");
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || strlen(trim($content)) === 0) {
            throw new Exception("الملف المرفوع فارغ تماماً.");
        }

        return $content;
    }

    /**
     * تأمين مجلد الرفع وحظر تنفيذ ملفات PHP أو السكربتات داخله
     */
    public static function ensureSecureDirectory($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // حماية المجلد بملف .htaccess يمنع تشغيل السكربتات تماماً
        $htaccessPath = rtrim($dir, '/\\') . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "# منع تنفيذ أي كود برمجي داخل مجلد المرفوعات نهائياً\n";
            $htaccessContent .= "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps|phar|inc|cgi|pl|exe|sh|bat)$\">\n";
            $htaccessContent .= "    Order Deny,Allow\n";
            $htaccessContent .= "    Deny from all\n";
            $htaccessContent .= "</FilesMatch>\n";
            $htaccessContent .= "Options -ExecCGI -Indexes\n";
            $htaccessContent .= "SetHandler default-handler\n";
            @file_put_contents($htaccessPath, $htaccessContent);
        }
    }
}
