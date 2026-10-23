<?php
use Core\Request;
use Core\Response;
use Core\Router;

/** @var Router $router */

// الصفحة الرئيسية للمنظومة
$router->get('/', function (Request $request) {
    $indexPath = dirname(__DIR__) . '/public/index.html';
    if (file_exists($indexPath)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($indexPath);
        exit;
    }
    
    Response::success('مرحباً بك في نظام بوكر فارما لإدارة وتوزيع الأدوية', [
        'health_check_url' => '/api/health',
        'documentation'    => '/docs/PHASE_1_2_DESIGN.md'
    ]);
});

// صفحة تسجيل الدخول
$router->get('/login', function (Request $request) {
    $loginPath = dirname(__DIR__) . '/public/login.html';
    if (file_exists($loginPath)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($loginPath);
        exit;
    }
});
