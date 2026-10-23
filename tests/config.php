<?php

declare(strict_types=1);

// Configuration for the test container: no login delay, a high request limit, a small
// default drop-box quota and 127.0.0.1 as trusted proxy. tests/run.sh fills in the base URL.

return [
    'app_name' => 'PSST',
    'base_url' => '__BASE_URL__',
    'admin_username' => 'admin',
    'admin_password' => 'psst',
    'timezone' => 'Europe/Zurich',
    'data_dir' => __DIR__ . '/data',
    'files_dir' => __DIR__ . '/data/files',
    'database_path' => __DIR__ . '/data/psst.sqlite',
    'server_secret_code' => '',
    'max_upload_bytes' => 104857600,
    'default_upload_quota_bytes' => 1048576,
    'trusted_proxies' => ['127.0.0.1'],
    'rate_limit_window_seconds' => 3600,
    'max_requests_per_window' => 100000,
    'max_failed_secret_attempts_per_window' => 10,
    'login_delay_seconds' => 0,
    'max_failed_login_attempts' => 10,
    'login_block_seconds' => 86400,
    'session_lifetime_seconds' => 7776000,
    'session_name' => 'psst_session',
];
