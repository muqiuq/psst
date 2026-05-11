<?php

declare(strict_types=1);

require_once __DIR__ . '/models.php';

function psst_start_session(): void
{
    $config = psst_config();

    if (session_status() === PHP_SESSION_NONE) {
        session_name($config['session_name']);
        $lifetime = (int) $config['session_lifetime_seconds'];
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_start([
            'cookie_lifetime' => $lifetime,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
        ]);
    }
}

function psst_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function psst_request_json(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        psst_json(['error' => 'Invalid JSON request body.'], 400);
    }

    return $data;
}

function psst_request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        return psst_request_json();
    }

    return $_POST;
}

function psst_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function psst_is_logged_in(): bool
{
    psst_start_session();

    return !empty($_SESSION['psst_logged_in']);
}

function psst_require_login(): void
{
    if (!psst_is_logged_in()) {
        psst_json(['error' => 'Authentication required.'], 401);
    }
}

function psst_login(string $username, string $password): bool
{
    $config = psst_config();
    $valid = hash_equals($config['admin_username'], $username)
        && hash_equals($config['admin_password'], $password);

    if ($valid) {
        psst_start_session();
        session_regenerate_id(true);
        $_SESSION['psst_logged_in'] = true;
        $_SESSION['psst_login_time'] = time();
    }

    return $valid;
}

function psst_login_is_blocked(): bool
{
    $config = psst_config();
    $since = time() - (int) $config['login_block_seconds'];

    return psst_count_events(psst_client_ip(), 'failed_login', $since) >= (int) $config['max_failed_login_attempts'];
}

function psst_login_record_failure(): void
{
    psst_record_event(psst_client_ip(), 'failed_login');
}

function psst_login_delay(): void
{
    $delay = max(0, (int) psst_config()['login_delay_seconds']);
    if ($delay > 0) {
        sleep($delay);
    }
}

function psst_verify_htp(string $value): bool
{
    if (!preg_match('/^-?\d+$/', trim($value))) {
        return false;
    }

    $submitted = (int) trim($value);
    $secret = (int) psst_config()['htp_secret_number'];
    $now = time();

    foreach ([-60, 0, 60] as $offset) {
        $timestamp = $now + $offset;
        $minute = (int) date('i', $timestamp);
        $day = (int) date('j', $timestamp);
        if ($submitted === ($secret * $minute * 2 + $day)) {
            return true;
        }
    }

    return false;
}

function psst_logout(): void
{
    psst_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function psst_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function psst_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function psst_base_url(): string
{
    return rtrim((string) psst_config()['base_url'], '/');
}

function psst_share_url(string $uuid): string
{
    return psst_base_url() . '/index.php?id=' . rawurlencode($uuid);
}

function psst_share_download_url(string $uuid): string
{
    return psst_share_url($uuid) . '&download=1';
}

function psst_iti_validation_url(string $uuid, string $itiSecretCode): string
{
    return psst_share_url($uuid)
        . '&_format=application/validador-iti%2Bjson'
        . '&_secretCode=' . rawurlencode($itiSecretCode);
}

function psst_hash_secret(string $secret): string
{
    return password_hash($secret, PASSWORD_DEFAULT);
}

function psst_verify_secret(string $secret, ?string $hash): bool
{
    return is_string($hash) && $hash !== '' && password_verify($secret, $hash);
}

function psst_generate_iti_secret_code(): string
{
    return strtoupper(bin2hex(random_bytes(6)));
}

function psst_sanitize_filename(string $filename): string
{
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'file';
    $filename = trim($filename, '._');

    return $filename !== '' ? $filename : 'file';
}

function psst_validate_uuid(string $uuid): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
}

function psst_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (is_string($value)) {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

function psst_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function psst_normalize_meta(mixed $meta): ?string
{
    if ($meta === null || $meta === '') {
        return null;
    }

    if (is_array($meta)) {
        return json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if (!is_string($meta)) {
        return null;
    }

    json_decode($meta, true);
    return json_last_error() === JSON_ERROR_NONE ? $meta : json_encode(['raw' => $meta]);
}

function psst_stored_file_path(string $storedFilename): string
{
    $path = psst_config()['files_dir'] . '/' . basename($storedFilename);
    $base = realpath(psst_config()['files_dir']);
    $real = realpath(dirname($path));

    if ($base === false || $real === false || $base !== $real) {
        throw new RuntimeException('Invalid file storage path.');
    }

    return $path;
}

function psst_public_share(array $share): array
{
    return [
        'uuid' => $share['uuid'],
        'url' => psst_share_url($share['uuid']),
        'download_url' => psst_share_download_url($share['uuid']),
        'type' => $share['type'],
        'title' => $share['title'],
        'content' => $share['type'] === 'file' ? null : $share['content'],
        'original_filename' => $share['original_filename'],
        'mime_type' => $share['mime_type'],
        'file_size' => $share['file_size'] !== null ? (int) $share['file_size'] : null,
        'is_encrypted' => (bool) $share['is_encrypted'],
        'encryption_meta' => $share['encryption_meta'] ? json_decode($share['encryption_meta'], true) : null,
        'requires_secret_code' => !empty($share['secret_code_hash']) || psst_global_secret_enabled(),
        'has_iti_secret_code' => !empty($share['iti_secret_code']),
        'created_at' => $share['created_at'],
        'updated_at' => $share['updated_at'],
    ];
}

function psst_manager_share(array $share): array
{
    $public = psst_public_share($share);
    $public['iti_secret_code'] = $share['iti_secret_code'] ?? '';
    $public['iti_url'] = !empty($share['iti_secret_code'])
        ? psst_iti_validation_url($share['uuid'], $share['iti_secret_code'])
        : null;

    return $public;
}

function psst_global_secret_enabled(): bool
{
    return trim((string) psst_config()['server_secret_code']) !== '';
}

function psst_verify_global_secret(string $secret): bool
{
    $configured = trim((string) psst_config()['server_secret_code']);
    return $configured === '' || hash_equals($configured, $secret);
}

function psst_rate_limit_check(string $eventType, int $limit): bool
{
    $config = psst_config();
    $windowStart = time() - (int) $config['rate_limit_window_seconds'];
    $ipAddress = psst_client_ip();

    psst_prune_old_events($windowStart - 60);

    return psst_count_events($ipAddress, $eventType, $windowStart) < $limit;
}

function psst_rate_limit_record(string $eventType): void
{
    psst_record_event(psst_client_ip(), $eventType);
}

function psst_enforce_rate_limit(string $eventType, int $limit): void
{
    if (!psst_rate_limit_check($eventType, $limit)) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Too many requests.';
        exit;
    }

    psst_rate_limit_record($eventType);
}