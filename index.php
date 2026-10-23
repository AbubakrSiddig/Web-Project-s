<?php
/**
 * موجه المجلد الرئيسي (Root Redirector / Entry)
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// إذا كان الطلب موجهاً إلى public بالفعل يتم تركه، وإلا يتم توجيهه إلى public/index.php
require_once __DIR__ . '/public/index.php';
