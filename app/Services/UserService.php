<?php
namespace App\Services;

use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\MySQL\MySQLUserRepository;
use Core\Helpers\Security;
use Core\Service;
use Exception;

/**
 * خدمة إدارة المستخدمين (User Service)
 */
class UserService extends Service
{
    /** @var UserRepositoryInterface */
    protected $userRepo;

    public function __construct(UserRepositoryInterface $userRepo = null)
    {
        $this->userRepo = $userRepo ?: new MySQLUserRepository();
    }

    public function getUsers($filters = [], $page = 1, $perPage = 25)
    {
        return $this->userRepo->getUsersWithRoles($page, $perPage, $filters);
    }

    public function getUserById($id)
    {
        $user = $this->userRepo->find($id);
        if ($user) {
            unset($user['password_hash']);
        }
        return $user;
    }

    public function createUser(array $data, $creatorId = null)
    {
        // التحقق من عدم تكرار اسم المستخدم
        $existing = $this->userRepo->findBy('username', $data['username']);
        if ($existing) {
            throw new Exception("اسم المستخدم [{$data['username']}] مسجل مسبقاً، يرجى اختيار اسم آخر.");
        }

        if (!empty($data['email'])) {
            $existingEmail = $this->userRepo->findBy('email', $data['email']);
            if ($existingEmail) {
                throw new Exception("البريد الإلكتروني [{$data['email']}] مسجل مسبقاً.");
            }
        }

        $password = $data['password'] ?? 'User@123456';
        $userData = [
            'role_id'       => (int)$data['role_id'],
            'username'      => trim($data['username']),
            'full_name'     => trim($data['full_name']),
            'email'         => !empty($data['email']) ? trim($data['email']) : null,
            'phone'         => !empty($data['phone']) ? trim($data['phone']) : null,
            'password_hash' => Security::hashPassword($password),
            'is_active'     => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        $userId = $this->userRepo->create($userData);

        $this->logAudit(
            $creatorId,
            'create',
            'users',
            $userId,
            null,
            ['username' => $userData['username'], 'full_name' => $userData['full_name'], 'role_id' => $userData['role_id']]
        );

        return $this->getUserById($userId);
    }

    public function updateUser($id, array $data, $updaterId = null)
    {
        $user = $this->userRepo->find($id);
        if (!$user) {
            throw new Exception("المستخدم المطلوب غير موجود.");
        }

        // فحص تكرار اسم المستخدم مع استثناء الحالي
        if (!empty($data['username']) && $data['username'] !== $user['username']) {
            $existing = $this->userRepo->findBy('username', $data['username']);
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("اسم المستخدم [{$data['username']}] مسجل مسبقاً.");
            }
        }

        // فحص تكرار البريد
        if (!empty($data['email']) && $data['email'] !== $user['email']) {
            $existing = $this->userRepo->findBy('email', $data['email']);
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("البريد الإلكتروني [{$data['email']}] مسجل مسبقاً.");
            }
        }

        $updateData = [];
        if (isset($data['role_id'])) $updateData['role_id'] = (int)$data['role_id'];
        if (isset($data['username'])) $updateData['username'] = trim($data['username']);
        if (isset($data['full_name'])) $updateData['full_name'] = trim($data['full_name']);
        if (array_key_exists('email', $data)) $updateData['email'] = !empty($data['email']) ? trim($data['email']) : null;
        if (array_key_exists('phone', $data)) $updateData['phone'] = !empty($data['phone']) ? trim($data['phone']) : null;
        if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];

        if (!empty($updateData)) {
            $this->userRepo->update($id, $updateData);
            $this->logAudit(
                $updaterId,
                'update',
                'users',
                $id,
                ['username' => $user['username'], 'full_name' => $user['full_name']],
                $updateData
            );
        }

        return $this->getUserById($id);
    }

    public function changePassword($id, $newPassword, $updaterId = null)
    {
        $user = $this->userRepo->find($id);
        if (!$user) {
            throw new Exception("المستخدم المطلوب غير موجود.");
        }

        if (strlen($newPassword) < 6) {
            throw new Exception("كلمة المرور يجب ألا تقل عن 6 خانات.");
        }

        $this->userRepo->update($id, [
            'password_hash' => Security::hashPassword($newPassword)
        ]);

        $this->logAudit(
            $updaterId,
            'change_password',
            'users',
            $id,
            null,
            ['action' => 'password_reset_by_admin']
        );

        return true;
    }

    public function toggleStatus($id, $updaterId = null)
    {
        $user = $this->userRepo->find($id);
        if (!$user) {
            throw new Exception("المستخدم المطلوب غير موجود.");
        }

        if ((int)$id === 1) {
            throw new Exception("لا يمكن تعطيل حساب مدير النظام الرئيسي.");
        }

        $newStatus = (int)$user['is_active'] === 1 ? 0 : 1;
        $this->userRepo->update($id, ['is_active' => $newStatus]);

        $this->logAudit(
            $updaterId,
            'toggle_status',
            'users',
            $id,
            ['is_active' => $user['is_active']],
            ['is_active' => $newStatus]
        );

        return $newStatus;
    }
}
