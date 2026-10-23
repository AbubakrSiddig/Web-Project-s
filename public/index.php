<?php
/**
 * المدخل الرئيسي للتطبيق (Front Controller) — بوكر فارما
 */

require_once dirname(__DIR__) . '/core/App.php';

$app = new \Core\App();
$app->run();
