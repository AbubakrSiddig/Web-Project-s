<?php
namespace App\Controllers\Api;

use App\Services\UserService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم إدارة المستخدمين (User Controller)
 */
class UserController extends Controller
{
    /** @var UserService */
    protected $userService;

    public function __construct(UserService $userService = null)
    {
        $this->userService = $userService ?: new UserService();
    }

    /**
     * قائمة المستخدمين
     * GET /api/users
     */
    public function index(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'search'    => $request->query('search'),
            'role_id'   => $request->query('role_id'),
            'is_active' => $request->query('is_active'),
        ];

        $users = $this->userService->getUsers($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة المستخدمين بنجاح', $users);
    }

    /**
     * عرض مستخدم محدد
     * GET /api/users/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $user = $this->userService->getUserById($id);

        if (!$user) {
            return $this->error('المستخدم المطلوب غير موجود', null, 404);
        }

        return $this->success('تم استرجاع بيانات المستخدم بنجاح', $user);
    }

    /**
     * إضافة مستخدم جديد
     * POST /api/users
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'role_id'   => 'required|integer|positive',
            'username'  => 'required|min:3|max:50',
            'full_name' => 'required|min:3|max:150',
            'password'  => 'required|min:6',
            'email'     => 'email',
        ]);

        try {
            $currentUser = $request->getUser();
            $newUser = $this->userService->createUser($data, $currentUser['id'] ?? null);
            return $this->success('تم إنشاء المستخدم الجديد بنجاح', $newUser, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل بيانات مستخدم
     * PUT /api/users/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $data = $this->validate($request->all(), [
            'role_id'   => 'integer|positive',
            'username'  => 'min:3|max:50',
            'full_name' => 'min:3|max:150',
            'email'     => 'email',
        ]);

        try {
            $currentUser = $request->getUser();
            $updated = $this->userService->updateUser($id, $data, $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات المستخدم بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تغيير كلمة مرور مستخدم
     * PUT /api/users/{id}/password
     */
    public function changePassword(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $data = $this->validate($request->all(), [
            'new_password' => 'required|min:6',
        ]);

        try {
            $currentUser = $request->getUser();
            $this->userService->changePassword($id, $data['new_password'], $currentUser['id'] ?? null);
            return $this->success('تم تغيير كلمة المرور بنجاح');
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تبديل حالة المستخدم (تفعيل / تعطيل)
     * PUT /api/users/{id}/toggle-status
     */
    public function toggleStatus(Request $request)
    {
        $id = (int)$request->routeParam('id');

        try {
            $currentUser = $request->getUser();
            $newStatus = $this->userService->toggleStatus($id, $currentUser['id'] ?? null);
            $msg = $newStatus === 1 ? 'تم تفعيل حساب المستخدم بنجاح' : 'تم تعطيل حساب المستخدم بنجاح';
            return $this->success($msg, ['is_active' => $newStatus]);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
