<?php
namespace App\Services;

use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\MySQL\MySQLSupplierRepository;
use Core\Service;
use Exception;

/**
 * خدمة إدارة الموردين (Supplier Service)
 */
class SupplierService extends Service
{
    /** @var SupplierRepositoryInterface */
    protected $supplierRepo;

    public function __construct(SupplierRepositoryInterface $supplierRepo = null)
    {
        $this->supplierRepo = $supplierRepo ?: new MySQLSupplierRepository();
    }

    public function getSuppliers(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->supplierRepo->getSuppliersWithStats($filters, $page, $perPage);
    }

    public function getSupplierById($id)
    {
        $supplier = $this->supplierRepo->find($id);
        if (!$supplier) {
            throw new Exception("المورد المطلوب غير موجود.");
        }
        return $supplier;
    }

    public function createSupplier(array $data, $userId = null)
    {
        $name = trim($data['name']);
        $phone = trim($data['phone']);

        $supplierId = $this->supplierRepo->create([
            'name'         => $name,
            'company_name' => !empty($data['company_name']) ? trim($data['company_name']) : null,
            'phone'        => $phone,
            'alt_phone'    => !empty($data['alt_phone']) ? trim($data['alt_phone']) : null,
            'email'        => !empty($data['email']) ? trim($data['email']) : null,
            'address'      => !empty($data['address']) ? trim($data['address']) : null,
            'tax_number'   => !empty($data['tax_number']) ? trim($data['tax_number']) : null,
            'balance'      => isset($data['balance']) ? (float)$data['balance'] : 0.00,
            'is_active'    => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit($userId, 'create', 'suppliers', $supplierId, null, ['name' => $name, 'phone' => $phone]);

        return $this->getSupplierById($supplierId);
    }

    public function updateSupplier($id, array $data, $userId = null)
    {
        $supplier = $this->getSupplierById($id);

        $updateData = [];
        $fields = ['name', 'company_name', 'phone', 'alt_phone', 'email', 'address', 'tax_number', 'is_active'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $updateData[$f] = $data[$f] !== '' ? trim($data[$f]) : null;
            }
        }

        if (!empty($updateData)) {
            $this->supplierRepo->update($id, $updateData);
            $this->logAudit($userId, 'update', 'suppliers', $id, ['name' => $supplier['name']], $updateData);
        }

        return $this->getSupplierById($id);
    }

    public function getSupplierLedger($supplierId, array $filters = [], $page = 1, $perPage = 50)
    {
        $this->getSupplierById($supplierId);
        return $this->supplierRepo->getLedger($supplierId, $filters, $page, $perPage);
    }
}
