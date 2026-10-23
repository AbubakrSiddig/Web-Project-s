<?php
namespace App\Services;

use App\Repositories\Interfaces\CustomerRepositoryInterface;
use App\Repositories\MySQL\MySQLCustomerRepository;
use Core\Service;
use Exception;

/**
 * خدمة إدارة العملاء والصيدليات (Customer Service)
 */
class CustomerService extends Service
{
    /** @var CustomerRepositoryInterface */
    protected $customerRepo;

    public function __construct(CustomerRepositoryInterface $customerRepo = null)
    {
        $this->customerRepo = $customerRepo ?: new MySQLCustomerRepository();
    }

    public function getCustomers(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->customerRepo->getCustomersWithStats($filters, $page, $perPage);
    }

    public function getCustomerById($id)
    {
        $customer = $this->customerRepo->find($id);
        if (!$customer) {
            throw new Exception("العميل / الصيدلية غير موجود.");
        }
        return $customer;
    }

    public function createCustomer(array $data, $userId = null)
    {
        if (empty($data['name'])) {
            throw new Exception("اسم العميل أو الصيدلي المسؤول مطلوب.");
        }
        if (empty($data['phone'])) {
            throw new Exception("رقم هاتف العميل مطلوب.");
        }

        $name = trim($data['name']);
        $phone = trim($data['phone']);

        $customerId = $this->customerRepo->create([
            'name'           => $name,
            'pharmacy_name'  => !empty($data['pharmacy_name']) ? trim($data['pharmacy_name']) : null,
            'license_number' => !empty($data['license_number']) ? trim($data['license_number']) : null,
            'phone'          => $phone,
            'alt_phone'      => !empty($data['alt_phone']) ? trim($data['alt_phone']) : null,
            'email'          => !empty($data['email']) ? trim($data['email']) : null,
            'address'        => !empty($data['address']) ? trim($data['address']) : null,
            'city'           => !empty($data['city']) ? trim($data['city']) : null,
            'credit_limit'   => isset($data['credit_limit']) ? (float)$data['credit_limit'] : 0.00,
            'balance'        => isset($data['balance']) ? (float)$data['balance'] : 0.00,
            'is_active'      => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit($userId, 'create', 'customers', $customerId, null, ['name' => $name, 'phone' => $phone]);

        return $this->getCustomerById($customerId);
    }

    public function updateCustomer($id, array $data, $userId = null)
    {
        $customer = $this->getCustomerById($id);

        $updateData = [];
        $fields = ['name', 'pharmacy_name', 'license_number', 'phone', 'alt_phone', 'email', 'address', 'city', 'credit_limit', 'is_active'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $updateData[$f] = $data[$f] !== '' ? (is_numeric($data[$f]) && $f === 'credit_limit' ? (float)$data[$f] : trim($data[$f])) : null;
            }
        }

        if (!empty($updateData)) {
            $this->customerRepo->update($id, $updateData);
            $this->logAudit($userId, 'update', 'customers', $id, ['name' => $customer['name']], $updateData);
        }

        return $this->getCustomerById($id);
    }

    public function getCustomerLedger($customerId, array $filters = [], $page = 1, $perPage = 50)
    {
        $this->getCustomerById($customerId);
        return $this->customerRepo->getLedger($customerId, $filters, $page, $perPage);
    }

    public function adjustBalance($customerId, $amountChange, $type, $referenceId, $notes = '', $userId = null)
    {
        $customer = $this->getCustomerById($customerId);
        $newBalance = $this->customerRepo->updateBalance($customerId, $amountChange);

        $debit = 0.00;
        $credit = 0.00;
        if ($amountChange > 0) {
            $debit = abs($amountChange);
        } else {
            $credit = abs($amountChange);
        }

        $this->customerRepo->recordLedgerTransaction([
            'customer_id'      => $customerId,
            'transaction_type' => $type,
            'reference_id'     => $referenceId,
            'debit'            => $debit,
            'credit'           => $credit,
            'balance_after'    => $newBalance,
            'transaction_date' => date('Y-m-d'),
            'notes'            => $notes,
        ]);

        $this->logAudit($userId, 'balance_adjustment', 'customers', $customerId, [
            'old_balance' => $customer['balance'],
        ], [
            'new_balance' => $newBalance,
            'change'      => $amountChange,
            'type'        => $type,
        ]);

        return $newBalance;
    }
}
