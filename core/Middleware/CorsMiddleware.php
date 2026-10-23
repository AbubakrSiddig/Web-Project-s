<?php
namespace Core\Middleware;

use Core\Request;
use Core\Response;

/**
 * وسيطة تمكين CORS لطلبات الويب وتطبيقات سطح المكتب Electron
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if ($request->getMethod() === 'OPTIONS') {
            Response::sendCorsHeaders();
        }

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');

        return $next($request);
    }
}
