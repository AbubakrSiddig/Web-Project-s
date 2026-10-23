<?php
namespace Core\Middleware;

use App\Repositories\MySQL\MySQLPermissionRepository;
use Core\Request;
use Core\Response;

/**
 * وسيطة التحقق من صلاحيات المستخدم (Permission Middleware)
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /** @var string|array */
    protected $requiredPermission;

    public function __construct($requiredPermission)
    {
        $this->requiredPermission = $requiredPermission;
    }

    public function handle(Request $request, callable $next)
    {
        $user = $request->getUser();
        if (!$user) {
            return Response::unauthorized();
        }

        // مدير النظام (Admin) يمتلك كافة الصلاحيات تلقائياً
        if ((int)$user['role_id'] === 1 || ($user['role_name'] ?? '') === 'admin') {
            return $next($request);
        }

        $permRepo = new MySQLPermissionRepository();
        $userPermissions = $permRepo->getUserPermissionCodes($user['id']);

        $required = (array)$this->requiredPermission;
        $hasPermission = false;

        foreach ($required as $code) {
            if (in_array($code, $userPermissions)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $permStr = implode(', ', $required);
            return Response::forbidden("ليس لديك الصلاحية الكافية [{$permStr}] لتنفيذ هذا الإجراء.");
        }

        return $next($request);
    }
}
