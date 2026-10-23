<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\AccountingRepositoryInterface;
use Core\Database;
use PDO;

/**
 * تنفيذ مستودع الحسابات وسندات القبض والصرف عبر MySQL
 */
class MySQLAccountingRepository implements AccountingRepositoryInterface
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function getCashAccounts(array $filters = [])
    {
        $where = [];
        $bindings = [];

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "is_active = ?";
            $bindings[] = $filters['is_active'];
        }
        if (!empty($filters['account_type'])) {
            $where[] = "account_type = ?";
            $bindings[] = $filters['account_type'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM `cash_accounts` {$whereClause} ORDER BY `id` ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function findCashAccountById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM `cash_accounts` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch() ?: null;
    }

    public function createCashAccount(array $data)
    {
        $sql = "
            INSERT INTO `cash_accounts` (`name`, `account_type`, `account_number`, `currency`, `current_balance`, `branch_id`, `is_active`, `created_at`)
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['name'],
            $data['account_type'] ?? 'cash',
            $data['account_number'] ?? null,
            $data['currency'] ?? 'SDG',
            $data['current_balance'] ?? 0.00,
            $data['is_active'] ?? 1,
        ]);
        return $this->db->lastInsertId();
    }

    public function updateCashAccount($id, array $data)
    {
        $fields = [];
        $params = [];
        foreach (['name', 'account_type', 'account_number', 'currency', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = NOW()";
        $params[] = (int)$id;

        $sql = "UPDATE `cash_accounts` SET " . implode(', ', $fields) . " WHERE `id` = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateCashAccountBalance($id, $amountChange)
    {
        $stmt = $this->db->prepare("UPDATE `cash_accounts` SET `current_balance` = `current_balance` + ?, `updated_at` = NOW() WHERE `id` = ?");
        $stmt->execute([(float)$amountChange, (int)$id]);

        $balStmt = $this->db->prepare("SELECT `current_balance` FROM `cash_accounts` WHERE `id` = ?");
        $balStmt->execute([(int)$id]);
        return (float)$balStmt->fetchColumn();
    }

    public function getExpenseCategories()
    {
        $sql = "
            SELECT ec.*,
                   (SELECT COUNT(*) FROM `payment_vouchers` pv WHERE pv.expense_category_id = ec.id) as vouchers_count,
                   (SELECT COALESCE(SUM(pv.amount), 0) FROM `payment_vouchers` pv WHERE pv.expense_category_id = ec.id) as total_expenses
            FROM `expense_categories` ec
            ORDER BY ec.name ASC
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function findExpenseCategoryById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM `expense_categories` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch() ?: null;
    }

    public function createExpenseCategory(array $data)
    {
        $sql = "INSERT INTO `expense_categories` (`name`, `description`, `is_active`) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['is_active'] ?? 1,
        ]);
        return $this->db->lastInsertId();
    }

    public function createVoucher(array $data)
    {
        $sql = "
            INSERT INTO `payment_vouchers`
            (`voucher_number`, `voucher_type`, `cash_account_id`, `to_cash_account_id`, `contact_type`,
             `customer_id`, `supplier_id`, `expense_category_id`, `reference_invoice_type`, `reference_invoice_id`,
             `amount`, `voucher_date`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['voucher_number'],
            $data['voucher_type'],
            $data['cash_account_id'],
            $data['to_cash_account_id'] ?? null,
            $data['contact_type'] ?? null,
            $data['customer_id'] ?? null,
            $data['supplier_id'] ?? null,
            $data['expense_category_id'] ?? null,
            $data['reference_invoice_type'] ?? 'none',
            $data['reference_invoice_id'] ?? null,
            $data['amount'],
            $data['voucher_date'],
            $data['notes'] ?? null,
            $data['created_by'],
        ]);
        return $this->db->lastInsertId();
    }

    public function findVoucherById($id)
    {
        $sql = "
            SELECT pv.*,
                   ca.name as cash_account_name,
                   ca.account_type as cash_account_type,
                   toca.name as to_cash_account_name,
                   c.name as customer_name,
                   c.pharmacy_name,
                   s.name as supplier_name,
                   s.company_name,
                   ec.name as expense_category_name,
                   u.full_name as created_by_name
            FROM `payment_vouchers` pv
            JOIN `cash_accounts` ca ON ca.id = pv.cash_account_id
            LEFT JOIN `cash_accounts` toca ON toca.id = pv.to_cash_account_id
            LEFT JOIN `customers` c ON c.id = pv.customer_id
            LEFT JOIN `suppliers` s ON s.id = pv.supplier_id
            LEFT JOIN `expense_categories` ec ON ec.id = pv.expense_category_id
            JOIN `users` u ON u.id = pv.created_by
            WHERE pv.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$id]);
        return $stmt->fetch() ?: null;
    }

    public function getVouchers(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(pv.voucher_number LIKE ? OR pv.notes LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (!empty($filters['voucher_type'])) {
            $where[] = "pv.voucher_type = ?";
            $bindings[] = $filters['voucher_type'];
        }

        if (!empty($filters['cash_account_id'])) {
            $where[] = "(pv.cash_account_id = ? OR pv.to_cash_account_id = ?)";
            $bindings[] = $filters['cash_account_id'];
            $bindings[] = $filters['cash_account_id'];
        }

        if (!empty($filters['contact_type'])) {
            $where[] = "pv.contact_type = ?";
            $bindings[] = $filters['contact_type'];
        }

        if (!empty($filters['customer_id'])) {
            $where[] = "pv.customer_id = ?";
            $bindings[] = $filters['customer_id'];
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = "pv.supplier_id = ?";
            $bindings[] = $filters['supplier_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "pv.voucher_date >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "pv.voucher_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `payment_vouchers` pv {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT pv.*,
                   ca.name as cash_account_name,
                   toca.name as to_cash_account_name,
                   c.name as customer_name,
                   c.pharmacy_name,
                   s.name as supplier_name,
                   ec.name as expense_category_name,
                   u.full_name as created_by_name
            FROM `payment_vouchers` pv
            JOIN `cash_accounts` ca ON ca.id = pv.cash_account_id
            LEFT JOIN `cash_accounts` toca ON toca.id = pv.to_cash_account_id
            LEFT JOIN `customers` c ON c.id = pv.customer_id
            LEFT JOIN `suppliers` s ON s.id = pv.supplier_id
            LEFT JOIN `expense_categories` ec ON ec.id = pv.expense_category_id
            JOIN `users` u ON u.id = pv.created_by
            {$whereClause}
            ORDER BY pv.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }

    public function generateVoucherNumber($type)
    {
        $prefixes = [
            'receipt'  => 'RCV',
            'payment'  => 'PAY',
            'transfer' => 'TRF',
        ];
        $prefix = ($prefixes[$type] ?? 'VCH') . '-' . date('Ym') . '-';
        $sql = "SELECT `voucher_number` FROM `payment_vouchers` WHERE `voucher_number` LIKE ? ORDER BY `id` DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        $seq = $lastNumber ? ((int)substr($lastNumber, strlen($prefix)) + 1) : 1;
        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    public function generateJournalEntryNumber()
    {
        $prefix = 'JRN-' . date('Ym') . '-';
        $sql = "SELECT `entry_number` FROM `journal_entries` WHERE `entry_number` LIKE ? ORDER BY `id` DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        $seq = $lastNumber ? ((int)substr($lastNumber, strlen($prefix)) + 1) : 1;
        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    public function createJournalEntry(array $data, array $lines)
    {
        $sql = "
            INSERT INTO `journal_entries`
            (`entry_number`, `entry_date`, `description`, `reference_type`, `reference_id`,
             `total_debit`, `total_credit`, `is_posted`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['entry_number'],
            $data['entry_date'],
            $data['description'],
            $data['reference_type'] ?? 'manual',
            $data['reference_id'] ?? null,
            $data['total_debit'],
            $data['total_credit'],
            $data['is_posted'] ?? 1,
            $data['created_by'] ?? null,
        ]);
        $entryId = $this->db->lastInsertId();

        $lineStmt = $this->db->prepare("
            INSERT INTO `journal_entry_lines`
            (`journal_entry_id`, `account_name`, `account_type`, `debit`, `credit`, `notes`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($lines as $line) {
            $lineStmt->execute([
                $entryId,
                $line['account_name'],
                $line['account_type'] ?? 'asset',
                $line['debit'] ?? 0.00,
                $line['credit'] ?? 0.00,
                $line['notes'] ?? null,
            ]);
        }

        return $entryId;
    }

    public function findJournalEntryById($id)
    {
        $sql = "
            SELECT je.*, u.full_name as created_by_name
            FROM `journal_entries` je
            LEFT JOIN `users` u ON u.id = je.created_by
            WHERE je.id = ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $entry = $stmt->fetch();
        if (!$entry) {
            return null;
        }

        $lineStmt = $this->db->prepare("
            SELECT * FROM `journal_entry_lines`
            WHERE `journal_entry_id` = ?
            ORDER BY `id` ASC
        ");
        $lineStmt->execute([$id]);
        $entry['lines'] = $lineStmt->fetchAll();

        return $entry;
    }

    public function getJournalEntries(array $filters = [], $page = 1, $perPage = 25)
    {
        $where = ['1=1'];
        $bindings = [];

        if (!empty($filters['search'])) {
            $where[] = "(`je`.`entry_number` LIKE ? OR `je`.`description` LIKE ?)";
            $s = '%' . trim($filters['search']) . '%';
            $bindings[] = $s;
            $bindings[] = $s;
        }

        if (!empty($filters['date_from'])) {
            $where[] = "`je`.`entry_date` >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "`je`.`entry_date` <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM `journal_entries` `je` {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($bindings);
        $total = (int)$stmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT je.*, u.full_name as created_by_name
            FROM `journal_entries` je
            LEFT JOIN `users` u ON u.id = je.created_by
            {$whereClause}
            ORDER BY je.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }
}
