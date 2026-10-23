<?php
namespace Core;

use Core\Helpers\Validator;

/**
 * المتحكم الأساسي (Base Controller)
 */
abstract class Controller
{
    /**
     * إرسال رد JSON موحد
     */
    protected function json($payload, $statusCode = 200)
    {
        return Response::json($payload, $statusCode);
    }

    /**
     * إرسال رد نجاح
     */
    protected function success($message = 'تمت العملية بنجاح', $data = null, $statusCode = 200)
    {
        return Response::success($message, $data, $statusCode);
    }

    /**
     * إرسال رد خطأ
     */
    protected function error($message = 'حدث خطأ أثناء معالجة الطلب', $errors = null, $statusCode = 400)
    {
        return Response::error($message, $errors, $statusCode);
    }

    /**
     * التحقق من صحة المدخلات وإرجاع خطأ 422 تلقائياً إذا فشل التحقق
     *
     * @param array $data
     * @param array $rules
     * @return array البيانات المدققة
     */
    protected function validate(array $data, array $rules)
    {
        $validator = new Validator($data, $rules);
        if (!$validator->validate()) {
            Response::validationError($validator->getErrors());
        }
        return $validator->getValidatedData();
    }
}
