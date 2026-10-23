<?php
namespace App\Controllers\Api;

use Core\Controller;
use Core\Database;
use Core\Request;
use PDO;

/**
 * متحكم فحص سلامة النظام وتوافر قاعدة البيانات (Health Check)
 */
class HealthCheckController extends Controller
{
    public function index(Request $request)
    {
        $dbConnected = false;
        $dbVersion = 'Unknown';
        $tableCount = 0;
        $error = null;

        try {
            $pdo = Database::pdo();
            $dbConnected = true;
            $dbVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            $stmt = $pdo->query("SHOW TABLES");
            $tableCount = count($stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $appConfig = require dirname(__DIR__, 3) . '/config/app.php';

        return $this->success('نظام بوكر فارما يعمل بكفاءة وجاهزية تامة', [
            'status'      => $dbConnected ? 'UP' : 'DEGRADED',
            'app_name'    => $appConfig['name'],
            'company'     => $appConfig['company'],
            'version'     => $appConfig['version'],
            'environment' => $appConfig['env'],
            'database'    => [
                'connected'    => $dbConnected,
                'engine'       => 'MySQL / MariaDB (InnoDB)',
                'version'      => $dbVersion,
                'tables_count' => $tableCount,
                'error'        => $error,
            ],
            'server'      => [
                'php_version' => PHP_VERSION,
                'os'          => PHP_OS,
                'timezone'    => date_default_timezone_get(),
                'server_time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}
