<?php
namespace App\Controllers\Api;

use App\Services\RoleService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم الأدوار والصلاحيات (Role Controller)
 */
class RoleController extends Controller
{
    /** @var RoleService */
    protected $roleService;

    public function __construct(RoleService $roleService = null)
    {
        $this->roleService = $roleService ?: new RoleService();
    }

    /**
     * قائمة الأدوار
     * GET /api/roles
     */
    public function index(Request $request)
    {
        $roles = $this->roleService->getAllRoles();
        return $this->success('تم استرجاع قائمة الأدوار بنجاح', $roles);
    }

    /**
     * تفاصيل دور مع صلاحياته
     * GET /api/roles/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $role = $this->roleService->getRoleDetails($id);
            return $this->success('تم استرجاع تفاصيل الدور بنجاح', $role);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * كافة الصلاحيات مجمعة حسب الوحدة
     * GET /api/permissions
     */
    public function permissions(Request $request)
    {
        $perms = $this->roleService->getAllPermissionsGrouped();
        return $this->success('تم استرجاع قائمة الصلاحيات بنجاح', $perms);
    }

    /**
     * تعيين وحفظ صلاحيات دور
     * PUT /api/roles/{id}/permissions
     */
    public function syncPermissions(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $permissionIds = $request->input('permission_ids', []);

        if (!is_array($permissionIds)) {
            return $this->error('يجب تمرير مصفوفة من أرقام الصلاحيات permission_ids.');
        }

        try {
            $currentUser = $request->getUser();
            $updatedRole = $this->roleService->syncPermissions($id, $permissionIds, $currentUser['id'] ?? null);
            return $this->success('تم تحديث صلاحيات الدور بنجاح', $updatedRole);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
