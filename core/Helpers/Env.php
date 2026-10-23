<?php
namespace Core\Helpers;

/**
 * محمل متغيرات البيئة من ملف .env
 */
class Env
{
    /** @var array */
    protected static $loaded = [];

    /**
     * تحميل ملف .env إن وجد
     *
     * @param string|null $path
     */
    public static function load($path = null)
    {
        $file = $path ?: dirname(__DIR__, 2) . '/.env';
        if (!file_exists($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // إزالة علامات الاقتباس إن وجدت
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }

                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
                self::$loaded[$name] = $value;
            }
        }
    }

    /**
     * قراءة متغير بيئة بقيمة افتراضية
     */
    public static function get($key, $default = null)
    {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        if ($val === 'true' || $val === '(true)') return true;
        if ($val === 'false' || $val === '(false)') return false;
        if ($val === 'null' || $val === '(null)') return null;
        if ($val === 'empty' || $val === '(empty)') return '';

        return $val;
    }
}
