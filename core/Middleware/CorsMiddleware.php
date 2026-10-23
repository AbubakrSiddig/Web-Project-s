<?php
namespace Core\Middleware;

use Core\Request;
use Core\Response;

/**
 * وسيطة تمكين CORS وترويسات الأمان لطلبات الويب وتطبيقات سطح المكتب
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        Response::applySecurityAndCorsHeaders();

        if ($request->getMethod() === 'OPTIONS') {
            Response::sendCorsHeaders();
        }

        return $next($request);
    }
}
