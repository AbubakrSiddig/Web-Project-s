<?php
$data = json_encode(['username' => 'admin', 'password' => 'Admin@123456']);
$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $data,
        'ignore_errors' => true,
    ]
];
$context = stream_context_create($opts);
$res = file_get_contents('http://127.0.0.1:8000/api/auth/login', false, $context);
echo $res;
