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
    protected function error($message = 'حدث خطأ أثناء معالجة الطلب', $errors = null, $statusCode = 400, $code = 'BAD_REQUEST')
    {
        return Response::error($message, $errors, $statusCode, $code);
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

    /**
     * استخراج معاملات الترقيم الآمنة وتحديد سقف أعلى لمنع استنزاف الموارد
     *
     * @param Request $request
     * @param int $defaultPerPage
     * @param int $maxPerPage
     * @return array [$page, $perPage]
     */
    protected function getPaginationParams(Request $request, $defaultPerPage = 25, $maxPerPage = 100)
    {
        $page = max(1, (int)$request->query('page', 1));
        $perPage = max(1, min($maxPerPage, (int)$request->query('per_page', $defaultPerPage)));
        return [$page, $perPage];
    }

    /**
     * استخراج المستخدم الموثق الحالي مع رفض صارم لأي fallback
     *
     * @param Request $request
     * @return array
     */
    protected function getAuthUser(Request $request)
    {
        $user = $request->getUser();
        if (!$user || empty($user['id'])) {
            Response::unauthorized('يجب تسجيل الدخول أولاً لتنفيذ هذا الإجراء.');
        }
        return $user;
    }
}
