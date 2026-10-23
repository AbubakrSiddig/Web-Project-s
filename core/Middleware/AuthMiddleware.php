<?php
namespace Core\Middleware;

use App\Repositories\MySQL\MySQLUserRepository;
use Core\Helpers\Jwt;
use Core\Request;
use Core\Response;

/**
 * وسيطة التحقق من المصادقة وتسجيل الدخول (Auth Middleware)
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        $token = $request->getBearerToken();
        $userData = null;

        if ($token) {
            $payload = Jwt::decode($token);
            if ($payload && isset($payload['user_id'])) {
                $userRepo = new MySQLUserRepository();
                $dbUser = $userRepo->find($payload['user_id']);
                if ($dbUser && (int)$dbUser['is_active'] === 1) {
                    $userData = $dbUser;
                    $userData['role_name'] = $payload['role_name'] ?? null;
                }
            }
        } elseif (!empty($_SESSION['user_id'])) {
            $userRepo = new MySQLUserRepository();
            $dbUser = $userRepo->find($_SESSION['user_id']);
            if ($dbUser && (int)$dbUser['is_active'] === 1) {
                $userData = $dbUser;
            }
        }

        if (!$userData) {
            return Response::unauthorized('يجب تسجيل الدخول أولاً للوصول إلى هذا المورد.');
        }

        $request->setUser($userData);

        return $next($request);
    }
}
