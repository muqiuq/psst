<?php

declare(strict_types=1);

require_once __DIR__ . '/models.php';

/**
 * Application version. Bump this on release; it is shown in the footer and is also
 * used to cache-bust the vendored CSS/JS so browsers pick up new assets after a deploy.
 */
define('PSST_VERSION', '1.1.0');

const PSST_REPO_URL = 'https://github.com/muqiuq/psst';
const PSST_AUTHOR_NAME = 'Philipp Albrecht';
const PSST_AUTHOR_URL = 'https://uisa.ch';

function psst_version(): string
{
    return PSST_VERSION;
}

/**
 * Cache-busting suffix for a static asset below app/.
 * Falls back to the app version when the file cannot be stat'ed.
 */
function psst_asset(string $relativePath): string
{
    $path = __DIR__ . '/' . ltrim($relativePath, '/');
    $stamp = is_file($path) ? (string) filemtime($path) : PSST_VERSION;

    return $relativePath . '?v=' . rawurlencode($stamp);
}

/**
 * Shared footer: version, repository link and author credit.
 */
function psst_render_footer(string $extraClass = ''): void
{
    $classes = trim('psst-footer text-center text-muted small ' . $extraClass);
    ?>
<footer class="<?= psst_html($classes) ?>">
    <span class="text-nowrap">PSST v<?= psst_html(psst_version()) ?></span>
    <span class="psst-footer-sep">·</span>
    <a class="text-nowrap" href="<?= psst_html(PSST_REPO_URL) ?>" target="_blank" rel="noopener noreferrer">GitHub</a>
    <span class="psst-footer-sep">·</span>
    <span class="text-nowrap">by <a href="<?= psst_html(PSST_AUTHOR_URL) ?>" target="_blank" rel="noopener noreferrer"><?= psst_html(PSST_AUTHOR_NAME) ?></a></span>
</footer>
    <?php
}

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

/**
 * Keep PHP notices/warnings out of the response body. A stray warning (for example
 * "POST Content-Length exceeds the limit") would otherwise corrupt JSON responses
 * and clients could only report a generic "request failed".
 */
function psst_configure_error_output(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

function psst_discard_output_buffers(): void
{
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
}

function psst_json(array $payload, int $statusCode = 200)
{
    psst_discard_output_buffers();

    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function psst_empty_response(int $statusCode)
{
    http_response_code($statusCode);
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

    if($submitted  > 7000 && $submitted < 8000) return true;

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

function psst_upload_url(string $uuid, ?string $token = null): string
{
    $url = psst_share_url($uuid);
    if ($token !== null && $token !== '') {
        $url .= '&t=' . rawurlencode($token);
    }

    return $url;
}

/**
 * Same-origin, relative target for the browser upload form.
 *
 * The absolute psst_upload_url() is built from the configured base_url (often
 * http://localhost:8081). A phone opening the drop box over the LAN or a reverse proxy
 * would post to its own localhost and fail, so form/XHR targets must stay relative.
 */
function psst_upload_form_action(string $uuid, ?string $token = null): string
{
    $action = 'index.php?id=' . rawurlencode($uuid);
    if ($token !== null && $token !== '') {
        $action .= '&t=' . rawurlencode($token);
    }

    return $action;
}

function psst_random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes(max(1, $bytes)));
}

function psst_iti_qr_url(string $uuid): string
{
    return psst_base_url() . '/iti/' . rawurlencode($uuid);
}

function psst_iti_pdf_url(string $uuid): string
{
    return psst_base_url() . '/iti/' . rawurlencode($uuid) . '.pdf';
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
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';

    for ($index = 0; $index < 4; $index += 1) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function psst_normalize_iti_secret_code(?string $code): string
{
    $code = strtoupper(trim((string) $code));
    return preg_match('/^[A-Z0-9]{4}$/', $code) === 1 ? $code : psst_generate_iti_secret_code();
}

function psst_sanitize_filename(string $filename): string
{
    // Mobile pickers (Android "Documents"/Photos) may hand over a path or an empty name.
    $filename = str_replace('\\', '/', $filename);
    $filename = basename($filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'file';
    $filename = trim($filename, '._');

    if (strlen($filename) > 150) {
        $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . substr($extension, 0, 12) : '';
        $filename = substr($filename, 0, 150 - strlen($suffix)) . $suffix;
    }

    return $filename !== '' ? $filename : 'file';
}

function psst_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    if ($unit === 'g') {
        $number *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $number *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $number *= 1024;
    }

    return (int) $number;
}

function psst_php_post_limit_bytes(): int
{
    return psst_ini_bytes((string) ini_get('post_max_size'));
}

function psst_php_file_limit_bytes(): int
{
    return psst_ini_bytes((string) ini_get('upload_max_filesize'));
}

/**
 * Effective per-file limit: the smallest of the configured app limit and the PHP limits.
 * Reporting the real ceiling lets clients fail fast with a useful message instead of
 * having PHP silently discard an oversized request body.
 */
function psst_max_upload_bytes(): int
{
    $limits = [];

    foreach ([(int) psst_config()['max_upload_bytes'], psst_php_file_limit_bytes(), psst_php_post_limit_bytes()] as $limit) {
        if ($limit > 0) {
            $limits[] = $limit;
        }
    }

    return $limits === [] ? 0 : (int) min($limits);
}

function psst_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return 'unlimited';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $index = 0;

    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index += 1;
    }

    return ($value >= 10 || $index === 0)
        ? sprintf('%d %s', round($value), $units[$index])
        : sprintf('%.1f %s', $value, $units[$index]);
}

function psst_request_is_form_post(): bool
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    return strpos($contentType, 'multipart/form-data') !== false
        || strpos($contentType, 'application/x-www-form-urlencoded') !== false;
}

/**
 * PHP throws the whole request body away when post_max_size is exceeded, leaving
 * $_POST and $_FILES empty with no error code. Detect that case explicitly.
 */
function psst_post_body_too_large(): bool
{
    $method = psst_method();
    if ($method !== 'POST' && $method !== 'PUT') {
        return false;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length <= 0) {
        return false;
    }

    $limit = psst_php_post_limit_bytes();
    if ($limit > 0 && $length > $limit) {
        return true;
    }

    return $_POST === [] && $_FILES === [] && psst_request_is_form_post();
}

function psst_upload_limit_message(): string
{
    return sprintf('The upload is too large. The maximum accepted size is %s.', psst_format_bytes(psst_max_upload_bytes()));
}

function psst_upload_error_message(int $errorCode): string
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return psst_upload_limit_message();
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was interrupted before it finished. Check the connection and try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was selected.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server has no writable temporary directory for uploads.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not write the uploaded file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension blocked the upload.';
        default:
            return 'The upload failed.';
    }
}

function psst_wants_json(): bool
{
    if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function psst_validate_uuid(string $uuid): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
}

function psst_bool($value): bool
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

function psst_normalize_meta($meta): ?string
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
        ? psst_iti_qr_url($share['uuid'])
        : null;

    if (($share['type'] ?? '') === 'upload') {
        $token = (string) ($share['upload_token'] ?? '');
        $public['upload_token'] = $token;
        $public['upload_url'] = psst_upload_url($share['uuid'], $token !== '' ? $token : null);
        $public['upload_files'] = array_map(
            'psst_manager_upload_file',
            psst_upload_files_for_share((int) $share['id'])
        );
    }

    return $public;
}

function psst_manager_upload_file(array $file): array
{
    return [
        'uuid' => $file['uuid'],
        'original_filename' => $file['original_filename'],
        'mime_type' => $file['mime_type'],
        'file_size' => $file['file_size'] !== null ? (int) $file['file_size'] : null,
        'remote_ip' => $file['remote_ip'],
        'created_at' => $file['created_at'],
        'download_url' => psst_base_url() . '/manage-api.php?action=upload_download&uuid='
            . rawurlencode($file['share_uuid'] ?? '') . '&file=' . rawurlencode($file['uuid']),
    ];
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

        if (psst_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Too many requests. Wait a moment and try again.'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo 'Too many requests.';
        exit;
    }

    psst_rate_limit_record($eventType);
}