<?php
namespace App\Services;

use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\MySQL\MySQLCategoryRepository;
use App\Repositories\MySQL\MySQLProductRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة إدارة الأصناف والأدوية والمستلزمات (Product Service)
 */
class ProductService extends Service
{
    /** @var ProductRepositoryInterface */
    protected $productRepo;

    /** @var CategoryRepositoryInterface */
    protected $categoryRepo;

    public function __construct(
        ProductRepositoryInterface $productRepo = null,
        CategoryRepositoryInterface $categoryRepo = null
    ) {
        $this->productRepo = $productRepo ?: new MySQLProductRepository();
        $this->categoryRepo = $categoryRepo ?: new MySQLCategoryRepository();
    }

    public function getProducts(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->productRepo->getProductsWithCategory($filters, $page, $perPage);
    }

    public function getProductById($id)
    {
        $product = $this->productRepo->find($id);
        if (!$product) {
            throw new Exception("الصنف المطلوب غير موجود.");
        }
        $category = $this->categoryRepo->find($product['category_id']);
        $product['category_name'] = $category['name'] ?? null;
        return $product;
    }

    public function createProduct(array $data, $userId = null)
    {
        // توليد كود الصنف تلقائياً إن لم يُمرر
        if (empty($data['item_code'])) {
            $count = $this->productRepo->count();
            $data['item_code'] = 'PRD-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
        } else {
            $data['item_code'] = trim($data['item_code']);
        }

        // فحص تكرار كود الصنف
        $existingCode = $this->productRepo->findBy('item_code', $data['item_code']);
        if ($existingCode) {
            throw new Exception("كود الصنف [{$data['item_code']}] مسجل مسبقاً.");
        }

        // فحص تكرار الباركود
        if (!empty($data['barcode'])) {
            $existingBarcode = $this->productRepo->findBy('barcode', trim($data['barcode']));
            if ($existingBarcode) {
                throw new Exception("الباركود [{$data['barcode']}] مسجل لصنف آخر مسبقاً.");
            }
        }

        // التحقق من صحة الفئة الدوائية
        $category = $this->categoryRepo->find($data['category_id']);
        if (!$category) {
            throw new Exception("الفئة الدوائية المحددة غير موجودة.");
        }

        // ضبط الأسعار
        $purchasePrice = isset($data['purchase_price']) ? (float)$data['purchase_price'] : 0.00;
        $sellingPrice = isset($data['selling_price']) ? (float)$data['selling_price'] : 0.00;
        $wholesalePrice = isset($data['wholesale_price']) ? (float)$data['wholesale_price'] : ($sellingPrice > 0 ? round($sellingPrice * 0.9, 2) : 0.00);
        $retailPrice = isset($data['retail_price']) ? (float)$data['retail_price'] : $sellingPrice;
        $minSellingPrice = isset($data['min_selling_price']) ? (float)$data['min_selling_price'] : 0.00;

        if ($minSellingPrice > $sellingPrice && $sellingPrice > 0) {
            throw new Exception("الحد الأدنى لسعر البيع ({$minSellingPrice}) لا يمكن أن يتجاوز سعر البيع المعتمد ({$sellingPrice}).");
        }

        $productData = [
            'category_id'       => (int)$data['category_id'],
            'item_code'         => $data['item_code'],
            'trade_name'        => trim($data['trade_name']),
            'generic_name'      => !empty($data['generic_name']) ? trim($data['generic_name']) : null,
            'dosage_form'       => !empty($data['dosage_form']) ? trim($data['dosage_form']) : null,
            'strength'          => !empty($data['strength']) ? trim($data['strength']) : null,
            'manufacturer'      => !empty($data['manufacturer']) ? trim($data['manufacturer']) : null,
            'origin_country'    => !empty($data['origin_country']) ? trim($data['origin_country']) : null,
            'barcode'           => !empty($data['barcode']) ? trim($data['barcode']) : null,
            'main_unit'         => !empty($data['main_unit']) ? trim($data['main_unit']) : 'عبوة',
            'purchase_price'    => $purchasePrice,
            'selling_price'     => $sellingPrice,
            'wholesale_price'   => $wholesalePrice,
            'retail_price'      => $retailPrice,
            'min_selling_price' => $minSellingPrice,
            'min_stock_alert'   => isset($data['min_stock_alert']) ? (int)$data['min_stock_alert'] : 10,
            'image_url'         => !empty($data['image_url']) ? trim($data['image_url']) : null,
            'is_active'         => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at'        => date('Y-m-d H:i:s'),
        ];

        $productId = $this->productRepo->create($productData);

        $this->logAudit(
            $userId,
            'create',
            'products',
            $productId,
            null,
            ['item_code' => $productData['item_code'], 'trade_name' => $productData['trade_name'], 'selling_price' => $productData['selling_price']]
        );

        return $this->getProductById($productId);
    }

    public function updateProduct($id, array $data, $userId = null)
    {
        $product = $this->getProductById($id);

        if (!empty($data['item_code']) && $data['item_code'] !== $product['item_code']) {
            $existing = $this->productRepo->findBy('item_code', trim($data['item_code']));
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("كود الصنف [{$data['item_code']}] مسجل لصنف آخر.");
            }
        }

        if (!empty($data['barcode']) && $data['barcode'] !== $product['barcode']) {
            $existing = $this->productRepo->findBy('barcode', trim($data['barcode']));
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("الباركود [{$data['barcode']}] مسجل لصنف آخر.");
            }
        }

        if (!empty($data['category_id'])) {
            $category = $this->categoryRepo->find($data['category_id']);
            if (!$category) {
                throw new Exception("الفئة المحددة غير موجودة.");
            }
        }

        $updateData = [];
        $fields = [
            'category_id', 'item_code', 'trade_name', 'generic_name',
            'dosage_form', 'strength', 'manufacturer', 'origin_country',
            'barcode', 'main_unit', 'purchase_price', 'selling_price',
            'wholesale_price', 'retail_price',
            'min_selling_price', 'min_stock_alert', 'image_url', 'is_active'
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if (in_array($field, ['category_id', 'min_stock_alert', 'is_active'])) {
                    $val = (int)$val;
                } elseif (in_array($field, ['purchase_price', 'selling_price', 'wholesale_price', 'retail_price', 'min_selling_price'])) {
                    $val = (float)$val;
                } else {
                    $val = $val !== '' ? trim($val) : null;
                }
                $updateData[$field] = $val;
            }
        }

        // فحص تناسق الأسعار إن عُدلت
        $newSell = $updateData['selling_price'] ?? (float)$product['selling_price'];
        $newMinSell = $updateData['min_selling_price'] ?? (float)$product['min_selling_price'];
        if ($newMinSell > $newSell && $newSell > 0) {
            throw new Exception("الحد الأدنى لسعر البيع ({$newMinSell}) لا يمكن أن يتجاوز سعر البيع ({$newSell}).");
        }

        if (!empty($updateData)) {
            $this->productRepo->update($id, $updateData);

            $this->logAudit(
                $userId,
                'update',
                'products',
                $id,
                ['trade_name' => $product['trade_name'], 'selling_price' => $product['selling_price']],
                $updateData
            );
        }

        return $this->getProductById($id);
    }

    public function toggleProductStatus($id, $userId = null)
    {
        $product = $this->getProductById($id);
        $newStatus = (int)$product['is_active'] === 1 ? 0 : 1;
        $this->productRepo->update($id, ['is_active' => $newStatus]);

        $this->logAudit(
            $userId,
            'toggle_status',
            'products',
            $id,
            ['is_active' => $product['is_active']],
            ['is_active' => $newStatus]
        );

        return $newStatus;
    }

    /**
     * تصدير الأصناف كملف CSV مهيأ بالكامل للغة العربية في Excel (BOM UTF-8)
     */
    public function exportProductsCsv(array $filters = [])
    {
        $products = $this->productRepo->getAllForExport($filters);

        $output = fopen('php://temp', 'r+');
        // إضافة UTF-8 BOM لفتح العربي بشكل سليم في إكسل
        fputs($output, "\xEF\xBB\xBF");

        // رؤوس الأعمدة
        $headers = [
            'كود الصنف',
            'الاسم التجاري',
            'الاسم العلمي',
            'الفئة الدوائية',
            'الشكل الدوائي',
            'التركيز',
            'الشركة المصنعة',
            'بلد المنشأ',
            'الباركود',
            'الوحدة',
            'سعر الشراء الافتراضي',
            'سعر البيع',
            'سعر الجملة',
            'سعر القطاعي',
            'الحد الأدنى لسعر البيع',
            'حد إعادة الطلب',
            'الرصيد المتوفر حالياً',
            'الحالة'
        ];
        fputcsv($output, $headers);

        foreach ($products as $p) {
            fputcsv($output, [
                $p['item_code'],
                $p['trade_name'],
                $p['generic_name'],
                $p['category_name'],
                $p['dosage_form'],
                $p['strength'],
                $p['manufacturer'],
                $p['origin_country'],
                $p['barcode'],
                $p['main_unit'],
                number_format((float)$p['purchase_price'], 2, '.', ''),
                number_format((float)$p['selling_price'], 2, '.', ''),
                number_format((float)($p['wholesale_price'] ?? ($p['selling_price'] * 0.9)), 2, '.', ''),
                number_format((float)($p['retail_price'] ?? $p['selling_price']), 2, '.', ''),
                number_format((float)$p['min_selling_price'], 2, '.', ''),
                $p['min_stock_alert'],
                number_format((float)$p['current_stock'], 2, '.', ''),
                (int)$p['is_active'] === 1 ? 'نشط' : 'معطل'
            ]);
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * استيراد الأصناف من ملف CSV
     */
    public function importProductsCsv($csvContent, $userId = null)
    {
        // إزالة BOM إن وجد
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent);
        
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (count($lines) < 2) {
            throw new Exception("الملف فارغ أو لا يحتوي على بيانات صحيحة.");
        }

        $headerLine = array_shift($lines);
        $header = str_getcsv($headerLine);

        // تنظيف الرؤوس
        $header = array_map(function($h) { return trim($h); }, $header);

        // تجهيز الفئات المتاحة للربط السريع بالاسم
        $categories = $this->categoryRepo->all();
        $categoryMap = [];
        foreach ($categories as $c) {
            $categoryMap[trim($c['name'])] = (int)$c['id'];
        }
        $defaultCategoryId = $categories[0]['id'] ?? 1;

        $imported = 0;
        $updated = 0;
        $errors = [];

        Database::beginTransaction();
        try {
            foreach ($lines as $lineIndex => $line) {
                if (empty(trim($line))) continue;

                $row = str_getcsv($line);
                if (count($row) < 2) continue;

                $rowAssoc = [];
                foreach ($header as $idx => $colName) {
                    $rowAssoc[$colName] = $row[$idx] ?? '';
                }

                $tradeName = $rowAssoc['الاسم التجاري'] ?? $rowAssoc['trade_name'] ?? '';
                if (empty($tradeName)) {
                    $errors[] = "السطر " . ($lineIndex + 2) . ": الاسم التجاري مفقود.";
                    continue;
                }

                $itemCode = $rowAssoc['كود الصنف'] ?? $rowAssoc['item_code'] ?? '';
                $genericName = $rowAssoc['الاسم العلمي'] ?? $rowAssoc['generic_name'] ?? null;
                $catName = $rowAssoc['الفئة الدوائية'] ?? $rowAssoc['category_name'] ?? '';
                $catId = $categoryMap[$catName] ?? $defaultCategoryId;

                $wholesalePrice = isset($rowAssoc['سعر الجملة']) || isset($rowAssoc['wholesale_price'])
                    ? (float)($rowAssoc['سعر الجملة'] ?? $rowAssoc['wholesale_price'])
                    : round((float)($rowAssoc['سعر البيع'] ?? $rowAssoc['selling_price'] ?? 0) * 0.9, 2);

                $retailPrice = isset($rowAssoc['سعر القطاعي']) || isset($rowAssoc['retail_price'])
                    ? (float)($rowAssoc['سعر القطاعي'] ?? $rowAssoc['retail_price'])
                    : (float)($rowAssoc['سعر البيع'] ?? $rowAssoc['selling_price'] ?? 0);

                $productData = [
                    'trade_name'        => $tradeName,
                    'generic_name'      => $genericName,
                    'category_id'       => $catId,
                    'dosage_form'       => $rowAssoc['الشكل الدوائي'] ?? $rowAssoc['dosage_form'] ?? null,
                    'strength'          => $rowAssoc['التركيز'] ?? $rowAssoc['strength'] ?? null,
                    'manufacturer'      => $rowAssoc['الشركة المصنعة'] ?? $rowAssoc['manufacturer'] ?? null,
                    'origin_country'    => $rowAssoc['بلد المنشأ'] ?? $rowAssoc['origin_country'] ?? null,
                    'barcode'           => $rowAssoc['الباركود'] ?? $rowAssoc['barcode'] ?? null,
                    'main_unit'         => $rowAssoc['الوحدة'] ?? $rowAssoc['main_unit'] ?? 'عبوة',
                    'purchase_price'    => (float)($rowAssoc['سعر الشراء الافتراضي'] ?? $rowAssoc['purchase_price'] ?? 0),
                    'selling_price'     => (float)($rowAssoc['سعر البيع'] ?? $rowAssoc['selling_price'] ?? 0),
                    'wholesale_price'   => $wholesalePrice,
                    'retail_price'      => $retailPrice,
                    'min_selling_price' => (float)($rowAssoc['الحد الأدنى لسعر البيع'] ?? $rowAssoc['min_selling_price'] ?? 0),
                    'min_stock_alert'   => (int)($rowAssoc['حد إعادة الطلب'] ?? $rowAssoc['min_stock_alert'] ?? 10),
                    'is_active'         => 1,
                ];

                try {
                    // فحص هل الصنف موجود مسبقاً للتحديث أم للإضافة
                    $existing = null;
                    if (!empty($itemCode)) {
                        $existing = $this->productRepo->findBy('item_code', $itemCode);
                    }
                    if (!$existing && !empty($productData['barcode'])) {
                        $existing = $this->productRepo->findBy('barcode', $productData['barcode']);
                    }

                    if ($existing) {
                        $this->updateProduct($existing['id'], $productData, $userId);
                        $updated++;
                    } else {
                        if (!empty($itemCode)) {
                            $productData['item_code'] = $itemCode;
                        }
                        $this->createProduct($productData, $userId);
                        $imported++;
                    }
                } catch (Exception $e) {
                    $errors[] = "السطر " . ($lineIndex + 2) . ": " . $e->getMessage();
                }
            }

            Database::commit();
        } catch (\Throwable $t) {
            Database::rollBack();
            throw new Exception("فشل استيراد الأصناف وتراجعت المنظومة عن التغييرات: " . $t->getMessage());
        }

        $this->logAudit($userId, 'import_csv', 'products', null, null, [
            'imported' => $imported,
            'updated'  => $updated,
            'errors'   => count($errors)
        ]);

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'errors'   => $errors,
        ];
    }
}
