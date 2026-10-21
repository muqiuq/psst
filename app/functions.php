<?php

declare(strict_types=1);

require_once __DIR__ . '/models.php';

/**
 * Application version. Bump this on release; it is shown in the footer and is also
 * used to cache-bust the vendored CSS/JS so browsers pick up new assets after a deploy.
 */
define('PSST_VERSION', '1.2.0');

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

function psst_credentials_valid(string $username, string $password): bool
{
    $config = psst_config();

    // Compare both, so the response time does not reveal which one was wrong.
    $usernameOk = hash_equals((string) $config['admin_username'], $username);
    $passwordOk = hash_equals((string) $config['admin_password'], $password);

    return $usernameOk && $passwordOk;
}

function psst_login_session_start(): void
{
    psst_start_session();
    session_regenerate_id(true);
    $_SESSION['psst_logged_in'] = true;
    $_SESSION['psst_login_time'] = time();
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

/**
 * HTP: a 6-digit number above 100000 and below 999999 that contains the current month
 * (MM) and the current minute (mm, +/- 1 minute) at positions that do not overlap.
 * At 14:34 in October: 103412, 341099 and 510347 are valid.
 * At 14:01 in October: 100155 is valid, 101555 is not ("10" and "01" share a digit).
 * At 14:10 in October: 101055 is valid, 210155 is not ("10" occurs only once).
 *
 * Returns the number when it is valid and unused, null otherwise. The caller marks it
 * used with psst_htp_mark_used() once the login succeeds.
 */
function psst_htp_value(string $value): ?int
{
    $value = trim($value);
    if (preg_match('/^\d{6}$/', $value) !== 1) {
        return null;
    }

    $number = (int) $value;
    if ($number <= 100000 || $number >= 999999) {
        return null;
    }

    $now = time();
    foreach ([-60, 0, 60] as $offset) {
        $timestamp = $now + $offset;
        if (psst_htp_contains_pair($value, date('m', $timestamp), date('i', $timestamp))) {
            return psst_htp_is_used($number) ? null : $number;
        }
    }

    return null;
}

function psst_htp_contains_pair(string $value, string $month, string $minute): bool
{
    $last = strlen($value) - 2;

    for ($monthAt = 0; $monthAt <= $last; $monthAt += 1) {
        if (substr($value, $monthAt, 2) !== $month) {
            continue;
        }

        for ($minuteAt = 0; $minuteAt <= $last; $minuteAt += 1) {
            $overlaps = abs($minuteAt - $monthAt) < 2;
            if (!$overlaps && substr($value, $minuteAt, 2) === $minute) {
                return true;
            }
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

/**
 * Behind a reverse proxy every request comes from the proxy, so all visitors would share
 * one rate-limit bucket. For proxies listed in trusted_proxies, the client is the last
 * X-Forwarded-For entry that is not itself a trusted proxy.
 */
function psst_client_ip(): string
{
    static $clientIp = null;

    if ($clientIp !== null) {
        return $clientIp;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = (array) psst_config()['trusted_proxies'];
    $clientIp = $remote;

    if ($trusted === [] || !psst_ip_in_list($remote, $trusted)) {
        return $clientIp;
    }

    $forwarded = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($forwarded as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            break;
        }

        $clientIp = $candidate;
        if (!psst_ip_in_list($candidate, $trusted)) {
            break;
        }
    }

    return $clientIp;
}

/**
 * @param string[] $ranges IP addresses or CIDR ranges, IPv4 or IPv6
 */
function psst_ip_in_list(string $ip, array $ranges): bool
{
    $address = @inet_pton($ip);
    if ($address === false) {
        return false;
    }

    foreach ($ranges as $range) {
        $parts = explode('/', trim((string) $range), 2);
        $network = @inet_pton($parts[0]);
        if ($network === false || strlen($network) !== strlen($address)) {
            continue;
        }

        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($address) * 8;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            continue;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;
        if ((ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
            return true;
        }
    }

    return false;
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
        // Encrypted content is fetched separately (raw=1) so that only opening it counts as a view.
        'content' => $share['type'] === 'file' || $share['is_encrypted'] ? null : $share['content'],
        'original_filename' => $share['original_filename'],
        'mime_type' => $share['mime_type'],
        'file_size' => $share['file_size'] !== null ? (int) $share['file_size'] : null,
        'is_encrypted' => (bool) $share['is_encrypted'],
        'encryption_meta' => $share['encryption_meta'] ? json_decode($share['encryption_meta'], true) : null,
        'requires_secret_code' => !empty($share['secret_code_hash']) || psst_global_secret_enabled(),
        'has_iti_secret_code' => !empty($share['iti_secret_code']),
        'expires_at' => psst_iso_timestamp($share['expires_at'] ?? null),
        'views_remaining' => psst_share_views_remaining($share),
        'created_at' => psst_iso_sqlite_time($share['created_at']),
        'updated_at' => psst_iso_sqlite_time($share['updated_at']),
    ];
}

function psst_manager_share(array $share): array
{
    $public = psst_public_share($share);
    $public['iti_secret_code'] = $share['iti_secret_code'] ?? '';
    $public['iti_url'] = !empty($share['iti_secret_code'])
        ? psst_iti_qr_url($share['uuid'])
        : null;
    $public['has_secret_code'] = !empty($share['secret_code_hash']);
    $public['max_views'] = $share['max_views'] !== null ? (int) $share['max_views'] : null;
    $public['view_count'] = (int) ($share['view_count'] ?? 0);

    if (($share['type'] ?? '') === 'upload') {
        $token = (string) ($share['upload_token'] ?? '');
        $files = psst_upload_files_for_share((int) $share['id']);
        $public['upload_token'] = $token;
        $public['upload_url'] = psst_upload_url($share['uuid'], $token !== '' ? $token : null);
        $public['upload_quota_bytes'] = $share['upload_quota_bytes'] !== null ? (int) $share['upload_quota_bytes'] : null;
        $public['upload_used_bytes'] = array_sum(array_map(static function (array $file): int {
            return (int) $file['file_size'];
        }, $files));
        $public['upload_closed'] = psst_share_is_expired($share);
        $public['upload_files'] = array_map('psst_manager_upload_file', $files);
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
        'created_at' => psst_iso_sqlite_time($file['created_at']),
        // Relative, so it works whichever host name the manager is opened with.
        'download_url' => 'manage-api.php?action=upload_download&uuid='
            . rawurlencode($file['share_uuid'] ?? '') . '&file=' . rawurlencode($file['uuid']),
    ];
}

function psst_share_is_expired(array $share): bool
{
    return $share['expires_at'] !== null && (int) $share['expires_at'] <= time();
}

function psst_share_views_remaining(array $share): ?int
{
    if ($share['max_views'] === null) {
        return null;
    }

    return max(0, (int) $share['max_views'] - (int) $share['view_count']);
}

/**
 * Upload shares: remaining quota in bytes, or null when the drop box has no limit.
 */
function psst_upload_remaining_bytes(array $share): ?int
{
    if ($share['upload_quota_bytes'] === null) {
        return null;
    }

    return max(0, (int) $share['upload_quota_bytes'] - psst_upload_used_bytes((int) $share['id']));
}

function psst_iso_timestamp($timestamp): ?string
{
    return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
}

/**
 * SQLite CURRENT_TIMESTAMP is UTC without a zone marker; mark it so browsers convert it.
 */
function psst_iso_sqlite_time(?string $value): ?string
{
    return $value === null || $value === '' ? null : str_replace(' ', 'T', $value) . 'Z';
}

/**
 * Only a well-formed type/subtype is passed on; the browser-supplied MIME type of an
 * upload must not be able to smuggle anything into the Content-Type header.
 */
function psst_safe_mime(?string $mime): string
{
    $mime = strtolower(trim(explode(';', (string) $mime)[0]));

    return preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $mime) === 1
        ? $mime
        : 'application/octet-stream';
}

/**
 * Types a browser can show without running script. Everything else, notably HTML, SVG
 * and XML, is downloaded instead: rendered on this origin it could act on the manager.
 */
function psst_inline_safe_mime(string $mime): bool
{
    $safe = ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];

    return in_array($mime, $safe, true) || strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0;
}

function psst_send_file_headers(?string $mime, string $filename, int $length, bool $inline): void
{
    $mime = psst_safe_mime($mime);
    $inline = $inline && psst_inline_safe_mime($mime);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $length);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($filename, '"\\') . '"');
    header('X-Content-Type-Options: nosniff');

    // Chrome refuses to show PDFs in a sandboxed document; its viewer has no access to
    // this origin anyway.
    if ($mime !== 'application/pdf') {
        header('Content-Security-Policy: sandbox');
    }
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

    psst_prune_old_events($windowStart - 60, time() - (int) $config['login_block_seconds'] - 60);

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