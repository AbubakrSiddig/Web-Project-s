<?php
/**
 * إعدادات المنظومة العامة — بوكر فارما
 */

return [
    'name'        => 'نظام إدارة أعمال بوكر فارما',
    'company'     => 'شركة بوكر فارما للتجارة والاستثمار المحدودة',
    'version'     => '0.1.0-mvp',
    'env'         => getenv('APP_ENV') ?: 'development', // 'development' أو 'production'
    'debug'       => (bool)(getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : true),
    'timezone'    => 'Africa/Khartoum',
    'locale'      => 'ar',
    'api_prefix'  => '/api',
    'cors'        => [
        'allowed_origins' => ['*'], // مهيأ لتطبيق Electron وتطوير الويب المحلي
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
    ],
    'session'     => [
        'name'     => 'poker_pharma_sess',
        'lifetime' => 86400, // 24 ساعة
    ],
];
