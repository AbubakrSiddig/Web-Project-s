<?php
namespace Core;

use Core\Middleware\CorsMiddleware;
use Throwable;

/**
 * فئة تهيئة وتشغيل المنظومة (Application Bootstrap)
 */
class App
{
    /** @var Router */
    protected $router;

    /** @var Request */
    protected $request;

    public function __construct()
    {
        $this->bootstrap();
        $this->router = new Router();
        $this->request = new Request();
    }

    /**
     * الإعدادات الأولية للنظام
     */
    protected function bootstrap()
    {
        // 1. تسجيل المحمل التلقائي
        require_once __DIR__ . '/Autoloader.php';
        Autoloader::register();

        // تحميل متغيرات البيئة من ملف .env إن وجد
        \Core\Helpers\Env::load();

        // 2. تحميل الإعدادات
        $config = require dirname(__DIR__) . '/config/app.php';

        // 3. ضبط المنطقة الزمنية
        date_default_timezone_set($config['timezone'] ?? 'Africa/Khartoum');

        // 4. ضبط معالجة الأخطاء
        if (!empty($config['debug'])) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            error_reporting(0);
        }

        // 5. تهيئة الجلسة
        if (session_status() === PHP_SESSION_NONE) {
            session_name($config['session']['name'] ?? 'poker_pharma_sess');
            session_start();
        }
    }

    /**
     * تحميل ملفات المسارات
     */
    protected function loadRoutes()
    {
        $router = $this->router;

        // تطبيق وسيطة CORS العامة
        $router->addRoute('OPTIONS', '{any}', function () {
            Response::sendCorsHeaders();
        });

        $routesDir = dirname(__DIR__) . '/routes';
        if (file_exists($routesDir . '/api.php')) {
            require $routesDir . '/api.php';
        }
        if (file_exists($routesDir . '/web.php')) {
            require $routesDir . '/web.php';
        }
    }

    /**
     * تشغيل التطبيق ومعالجة الطلب
     */
    public function run()
    {
        try {
            $this->loadRoutes();
            $this->router->dispatch($this->request);
        } catch (Throwable $e) {
            $debug = (bool)(require dirname(__DIR__) . '/config/app.php')['debug'];
            
            Response::error(
                'حدث خطأ غير متوقع في الخادم: ' . ($debug ? $e->getMessage() : 'يرجى مراجعة سجلات النظام'),
                $debug ? [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ] : null,
                500
            );
        }
    }
}
