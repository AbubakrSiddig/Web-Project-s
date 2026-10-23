<?php
namespace App\Services;

use App\Repositories\Interfaces\PermissionRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\MySQL\MySQLPermissionRepository;
use App\Repositories\MySQL\MySQLUserRepository;
use Core\Helpers\Jwt;
use Core\Helpers\Security;
use Core\Service;
use Exception;

/**
 * خدمة المصادقة وتسجيل الدخول والصلاحيات (Auth Service)
 */
class AuthService extends Service
{
    /** @var UserRepositoryInterface */
    protected $userRepo;

    /** @var PermissionRepositoryInterface */
    protected $permissionRepo;

    public function __construct(
        UserRepositoryInterface $userRepo = null,
        PermissionRepositoryInterface $permissionRepo = null
    ) {
        $this->userRepo = $userRepo ?: new MySQLUserRepository();
        $this->permissionRepo = $permissionRepo ?: new MySQLPermissionRepository();
    }

    /**
     * تسجيل الدخول والتحقق من كلمة المرور
     *
     * @param string $username
     * @param string $password
     * @param string|null $ip
     * @param string|null $userAgent
     * @return array
     * @throws Exception
     */
    public function login($username, $password, $ip = null, $userAgent = null)
    {
        $username = trim($username);
        $user = $this->userRepo->findByUsernameWithRole($username);

        if (!$user) {
            $this->logAudit(null, 'failed_login', 'auth', null, null, [
                'username' => $username,
                'reason'   => 'user_not_found',
            ]);
            throw new Exception("اسم المستخدم أو كلمة المرور غير صحيحة.");
        }

        if ((int)$user['is_active'] !== 1) {
            $this->logAudit($user['id'], 'failed_login', 'auth', $user['id'], null, [
                'username' => $username,
                'reason'   => 'account_inactive',
            ]);
            throw new Exception("هذا الحساب معطل حالياً. يرجى مراجعة مدير النظام.");
        }

        if (!Security::verifyPassword($password, $user['password_hash'])) {
            $this->logAudit($user['id'], 'failed_login', 'auth', $user['id'], null, [
                'username' => $username,
                'reason'   => 'invalid_password',
            ]);
            throw new Exception("اسم المستخدم أو كلمة المرور غير صحيحة.");
        }

        // تحديث وقت آخر تسجيل دخول
        $this->userRepo->updateLastLogin($user['id']);

        // استرجاع قائمة الصلاحيات
        $permissions = $this->permissionRepo->getUserPermissionCodes($user['id']);

        // توليد رمز JWT آمن
        $tokenPayload = [
            'user_id'   => $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role_id'   => $user['role_id'],
            'role_name' => $user['role_name'],
        ];
        $token = Jwt::encode($tokenPayload, 86400); // 24 ساعة

        // تسجيل العملية في سجل التدقيق
        $this->logAudit(
            $user['id'],
            'login',
            'auth',
            $user['id'],
            null,
            ['username' => $username, 'status' => 'success']
        );

        return [
            'token'       => $token,
            'token_type'  => 'Bearer',
            'expires_in'  => 86400,
            'user'        => [
                'id'                => (int)$user['id'],
                'username'          => $user['username'],
                'full_name'         => $user['full_name'],
                'email'             => $user['email'],
                'phone'             => $user['phone'],
                'role_id'           => (int)$user['role_id'],
                'role_name'         => $user['role_name'],
                'role_display_name' => $user['role_display_name'],
                'last_login_at'     => $user['last_login_at'],
            ],
            'permissions' => $permissions,
        ];
    }

    /**
     * استرجاع بيانات المستخدم الحالي وصلاحياته
     *
     * @param int $userId
     * @return array|null
     */
    public function getCurrentUser($userId)
    {
        $user = $this->userRepo->find($userId);
        if (!$user || (int)$user['is_active'] !== 1) {
            return null;
        }

        $userWithRole = $this->userRepo->findByUsernameWithRole($user['username']);
        $permissions = $this->permissionRepo->getUserPermissionCodes($userId);

        unset($userWithRole['password_hash']);
        $userWithRole['permissions'] = $permissions;

        return $userWithRole;
    }

    /**
     * تسجيل الخروج
     */
    public function logout($userId)
    {
        $this->logAudit($userId, 'logout', 'auth', $userId, null, ['status' => 'logged_out']);
        return true;
    }
}
