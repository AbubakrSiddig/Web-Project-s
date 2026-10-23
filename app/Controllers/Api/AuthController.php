<?php
namespace App\Controllers\Api;

use App\Services\AuthService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم المصادقة وتسجيل الدخول (Auth Controller)
 */
class AuthController extends Controller
{
    /** @var AuthService */
    protected $authService;

    public function __construct(AuthService $authService = null)
    {
        $this->authService = $authService ?: new AuthService();
    }

    /**
     * تسجيل الدخول
     * POST /api/auth/login
     */
    public function login(Request $request)
    {
        $data = $this->validate($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        try {
            $result = $this->authService->login($data['username'], $data['password']);
            return $this->success('تم تسجيل الدخول بنجاح', $result);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 401);
        }
    }

    /**
     * الحصول على بيانات المستخدم الحالي وصلاحياته
     * GET /api/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->getUser();
        if (!$user) {
            return $this->error('غير مصرح', null, 401);
        }

        $profile = $this->authService->getCurrentUser($user['id']);
        if (!$profile) {
            return $this->error('المستخدم غير موجود أو تم تعطيله', null, 401);
        }

        return $this->success('تم استرجاع بيانات الحساب بنجاح', $profile);
    }

    /**
     * تسجيل الخروج
     * POST /api/auth/logout
     */
    public function logout(Request $request)
    {
        $user = $request->getUser();
        if ($user) {
            $this->authService->logout($user['id']);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return $this->success('تم تسجيل الخروج بنجاح');
    }
}
