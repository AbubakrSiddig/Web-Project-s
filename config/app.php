<?php
/**
 * إعدادات المنظومة العامة — بوكر فارما
 */

return [
    'name'        => 'نظام إدارة أعمال بوكر فارما',
    'company'     => 'شركة بوكر فارما للتجارة والاستثمار المحدودة',
    'version'     => '0.1.0-mvp',
    'env'         => getenv('APP_ENV') ?: 'production', // 'development' أو 'production'
    'app_key'     => getenv('APP_KEY') ?: '',
    'debug'       => (bool)(getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === '1'),
    'timezone'    => 'Africa/Khartoum',
    'locale'      => 'ar',
    'api_prefix'  => '/api',
    'cors'        => [
        'allowed_origins' => array_filter(array_map('trim', explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: ''))),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
    ],
    'session'     => [
        'name'     => 'poker_pharma_sess',
        'lifetime' => 86400, // 24 ساعة
    ],
];
