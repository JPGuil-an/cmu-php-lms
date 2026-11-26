<?php
$config = [
    'app_name' => getenv('APP_NAME') ?: 'CMU LMS PHP',
    'session_name' => getenv('SESSION_NAME') ?: 'cmu_lms_php',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'cmu_lms',
        'username' => getenv('DB_USERNAME') ?: 'cmu_lms',
        'password' => getenv('DB_PASSWORD') ?: 'cmu_lms',
    ],
];
$hostConfig = __DIR__.'/config.host.php';
if (is_file($hostConfig)) {
    $config = array_replace_recursive($config, require $hostConfig);
}
$localConfig = __DIR__.'/config.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}
return $config;
