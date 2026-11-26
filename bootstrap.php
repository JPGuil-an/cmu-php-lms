<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Manila');
session_name($config['session_name']);
session_start();
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['port'], $config['db']['database']);
    $db = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(503);
    exit('<h1>Database unavailable</h1><p>Start MySQL and verify the LMS database settings.</p>');
}
require __DIR__ . '/functions.php';
