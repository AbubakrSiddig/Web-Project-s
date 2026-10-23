<?php
namespace Core\Middleware;

use Core\Request;

/**
 * واجهة وسيطة الطلبات (Middleware Interface)
 */
interface MiddlewareInterface
{
    /**
     * معالجة الطلب
     *
     * @param Request $request
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, callable $next);
}
