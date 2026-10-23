<?php
namespace App\Controllers\Api;

use App\Services\AuditLogService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم سجل التدقيق الأمني (Audit Log Controller)
 */
class AuditLogController extends Controller
{
    /** @var AuditLogService */
    protected $auditLogService;

    public function __construct(AuditLogService $auditLogService = null)
    {
        $this->auditLogService = $auditLogService ?: new AuditLogService();
    }

    public function index(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 50);
        $filters = [
            'user_id'    => $request->query('user_id'),
            'action'     => $request->query('action'),
            'table_name' => $request->query('table_name'),
            'date_from'  => $request->query('date_from'),
            'date_to'    => $request->query('date_to'),
        ];

        $logs = $this->auditLogService->getLogs($filters, $page, $perPage);
        return $this->success('تم استرجاع سجل التدقيق بنجاح', $logs);
    }

    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $log = $this->auditLogService->getLogById($id);
            return $this->success('تم استرجاع تفاصيل سجل التدقيق بنجاح', $log);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }
}
