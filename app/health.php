<?php

declare(strict_types=1);

// Reports whether this server can run PSST. Answers 503 when a check fails, so deploy
// scripts and monitors notice a missing PHP extension (such as pdo_sqlite) right away.

$checks = [
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'database' => false,
    'data_writable' => false,
    'files_writable' => false,
];
$version = null;

try {
    require_once __DIR__ . '/functions.php';
    psst_configure_error_output();
    $version = psst_version();

    if ($checks['pdo_sqlite']) {
        psst_db()->query('SELECT 1');
        $checks['database'] = true;
    }

    $config = psst_config();
    $checks['data_writable'] = is_dir($config['data_dir']) && is_writable($config['data_dir']);
    $checks['files_writable'] = is_dir($config['files_dir']) && is_writable($config['files_dir']);
} catch (Throwable $exception) {
    error_log('PSST health check: ' . $exception->getMessage());
}

$healthy = !in_array(false, $checks, true);

http_response_code($healthy ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'status' => $healthy ? 'ok' : 'error',
    'version' => $version,
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES);
