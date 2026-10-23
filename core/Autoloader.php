<?php
namespace Core;

/**
 * محمل الفئات التلقائي (PSR-4 Autoloader)
 */
class Autoloader
{
    /**
     * خريطة البادئات للمجلدات
     * @var array
     */
    protected static $prefixes = [];

    /**
     * تسجيل المحمل التلقائي في بيئة PHP
     */
    public static function register()
    {
        spl_autoload_register([__CLASS__, 'loadClass']);

        $baseDir = dirname(__DIR__);
        self::addNamespace('Core\\', $baseDir . '/core/');
        self::addNamespace('App\\', $baseDir . '/app/');
    }

    /**
     * إضافة بادئة مساحة أسماء ومجلدها
     *
     * @param string $prefix
     * @param string $baseDir
     */
    public static function addNamespace($prefix, $baseDir)
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . '/';
        self::$prefixes[$prefix] = $baseDir;
    }

    /**
     * تحميل ملف الفئة
     *
     * @param string $class
     * @return bool
     */
    public static function loadClass($class)
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                continue;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        return false;
    }
}
