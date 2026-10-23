<?php
namespace Core\Helpers;

use Core\Database;

/**
 * فاحص ومحقق البيانات والمدخلات (Data Validator)
 */
class Validator
{
    protected $data = [];
    protected $rules = [];
    protected $errors = [];
    protected $validated = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * تنفيذ التحقق
     *
     * @return bool
     */
    public function validate()
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rulesList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);

            $isRequired = in_array('required', $rulesList);

            if (($value === null || $value === '') && !$isRequired) {
                // الحقل اختياري وغير ممرر
                continue;
            }

            foreach ($rulesList as $ruleItem) {
                $params = [];
                if (strpos($ruleItem, ':') !== false) {
                    list($ruleName, $paramStr) = explode(':', $ruleItem, 2);
                    $params = explode(',', $paramStr);
                } else {
                    $ruleName = $ruleItem;
                }

                $this->applyRule($field, $value, $ruleName, $params);
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }

        return empty($this->errors);
    }

    protected function applyRule($field, $value, $rule, array $params)
    {
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    $this->addError($field, "حقل [{$field}] مطلوب ولا يمكن تركه فارغاً.");
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, "حقل [{$field}] يجب أن يكون قيمة رقمية.");
                }
                break;

            case 'integer':
                if (!filter_var($value, FILTER_VALIDATE_INT) && $value !== 0 && $value !== '0') {
                    $this->addError($field, "حقل [{$field}] يجب أن يكون عدداً صحيحاً.");
                }
                break;

            case 'positive':
                if (!is_numeric($value) || $value < 0) {
                    $this->addError($field, "حقل [{$field}] يجب أن يكون صفراً أو قيمة موجبة.");
                }
                break;

            case 'greater_than_zero':
                if (!is_numeric($value) || $value <= 0) {
                    $this->addError($field, "حقل [{$field}] يجب أن يكون أكبر من الصفر.");
                }
                break;

            case 'min':
                $min = (float)$params[0];
                if (is_numeric($value) && $value < $min) {
                    $this->addError($field, "قيمة حقل [{$field}] يجب ألا تقل عن {$min}.");
                } elseif (is_string($value) && mb_strlen($value) < $min) {
                    $this->addError($field, "طول حقل [{$field}] يجب ألا يقل عن {$min} أحرف.");
                }
                break;

            case 'max':
                $max = (float)$params[0];
                if (is_numeric($value) && $value > $max) {
                    $this->addError($field, "قيمة حقل [{$field}] يجب ألا تتجاوز {$max}.");
                } elseif (is_string($value) && mb_strlen($value) > $max) {
                    $this->addError($field, "طول حقل [{$field}] يجب ألا يتجاوز {$max} حرفاً.");
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "حقل [{$field}] يجب أن يكون بريداً إلكترونياً صحيحاً.");
                }
                break;

            case 'date':
                if (!strtotime($value)) {
                    $this->addError($field, "حقل [{$field}] يجب أن يكون تاريخاً صالحاً (YYYY-MM-DD).");
                }
                break;

            case 'in':
                if (!in_array($value, $params)) {
                    $options = implode(', ', $params);
                    $this->addError($field, "قيمة حقل [{$field}] يجب أن تكون إحدى القيم التالية: ({$options}).");
                }
                break;

            case 'unique':
                $table = $params[0] ?? '';
                $column = $params[1] ?? $field;
                $exceptId = $params[2] ?? null;
                $idColumn = $params[3] ?? 'id';

                if ($table && $column) {
                    $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?";
                    $bindings = [$value];

                    if ($exceptId !== null && $exceptId !== '') {
                        $sql .= " AND `{$idColumn}` != ?";
                        $bindings[] = $exceptId;
                    }

                    $stmt = Database::pdo()->prepare($sql);
                    $stmt->execute($bindings);
                    if ($stmt->fetchColumn() > 0) {
                        $this->addError($field, "قيمة حقل [{$field}] مسجلة مسبقاً وغير مكررة.");
                    }
                }
                break;
        }
    }

    protected function addError($field, $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    public function getErrors() { return $this->errors; }
    public function getValidatedData() { return $this->validated; }
}
