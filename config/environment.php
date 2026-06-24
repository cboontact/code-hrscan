<?php

function isLocalEnvironment()
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

    if ($host === '') {
        return PHP_SAPI === 'cli';
    }

    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);

    return in_array($hostWithoutPort, ['localhost', '127.0.0.1', '::1'], true)
        || substr($hostWithoutPort, -6) === '.local';
}

function getEnvironmentDefaults()
{
    if (isLocalEnvironment()) {
        return [
            'db_host' => 'localhost',
            'db_name' => 'jomthong_attendance',
            'db_user' => 'root',
            'db_pass' => '',
            'db_charset' => 'utf8mb4',
        ];
    }

    return [
        'db_host' => '',
        'db_name' => '',
        'db_user' => '',
        'db_pass' => '',
        'db_charset' => 'utf8mb4',
    ];
}
