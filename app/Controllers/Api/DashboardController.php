<?php
namespace App\Controllers\Api;

use App\Services\DashboardService;
use Core\Controller;
use Core\Request;

/**
 * متحكم لوحة القيادة والمؤشرات (Dashboard Controller)
 */
class DashboardController extends Controller
{
    /** @var DashboardService */
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?: new DashboardService();
    }

    public function stats()
    {
        $stats = $this->dashboardService->getStats();
        return $this->success('تم استرجاع إحصائيات لوحة التحكم بنجاح', $stats);
    }

    public function charts(Request $request)
    {
        $days = (int)$request->query('days', 7);
        $charts = $this->dashboardService->getChartsData($days);
        return $this->success('تم استرجاع بيانات الرسوم البيانية بنجاح', $charts);
    }
}
