<?php

declare(strict_types=1);

// API for other applications (for example PLM). Authenticated with an API key created
// in the manager; each key only sees the shares it created itself. The documentation
// is at api-docs.php, which requires a manager login.

require_once __DIR__ . '/functions.php';

psst_configure_error_output();
header('Cache-Control: no-store');

const PSST_API_SHARE_TYPES = ['markdown', 'text', 'link', 'file', 'site'];
const PSST_API_STORAGE_ACTIONS = [
    'storage_list', 'storage_get', 'storage_set', 'storage_remove', 'storage_append', 'storage_increment',
    'storage_export', 'storage_import', 'storage_clear', 'visitors',
];

try {
    psst_db();
    $apiKey = psst_integration_authenticate();

    $action = (string) ($_GET['action'] ?? '');

    // Site storage, with the same owner rights as in the manager.
    if (in_array($action, PSST_API_STORAGE_ACTIONS, true)) {
        psst_site_owner_action($action, psst_integration_own_share($apiKey));
    }

    switch ($action) {
        case 'status':
            psst_integration_status($apiKey);
            break;
        case 'create':
            psst_integration_create($apiKey);
            break;
        case 'update':
            psst_integration_update($apiKey);
            break;
        case 'share':
            psst_integration_get($apiKey);
            break;
        case 'delete':
            psst_integration_delete($apiKey);
            break;
        default:
            psst_json(['error' => 'Unknown action.'], 404);
    }
} catch (Throwable $exception) {
    // Details go to the server log only; they can contain paths and SQL.
    error_log('PSST api: ' . $exception);
    psst_json(['error' => 'Server error.'], 500);
}

/**
 * The key is accepted from the Authorization header (Bearer) or X-API-Key, never from
 * the URL, where it would end up in access logs and browser histories.
 */
function psst_integration_token(): string
{
    $token = psst_request_bearer_token();

    return $token !== '' ? $token : trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function psst_integration_authenticate(): array
{
    // Checked before the key, so guessing stays blocked even with a valid-looking key.
    if (!psst_rate_limit_check('failed_api_auth', (int) psst_config()['max_failed_api_auth_per_window'])) {
        psst_json(['error' => 'Too many failed authentication attempts. Try again later.'], 429);
    }

    $token = psst_integration_token();
    $apiKey = $token !== '' ? psst_api_key_find_active_by_token($token) : null;

    if (!$apiKey) {
        psst_rate_limit_record('failed_api_auth');
        header('WWW-Authenticate: Bearer realm="PSST"');
        psst_json(['error' => $token === '' ? 'Missing API key.' : 'Invalid or revoked API key.'], 401);
    }

    psst_api_key_touch((int) $apiKey['id']);

    return $apiKey;
}

function psst_integration_share(array $share): array
{
    $hasDownload = in_array($share['type'], ['file', 'markdown'], true);
    $site = null;
    if ($share['type'] === 'site') {
        psst_site_expire_visitors($share);
        $site = psst_site_info($share);
    }

    return [
        'uuid' => $share['uuid'],
        'type' => $share['type'],
        'title' => $share['title'],
        'url' => psst_share_url($share['uuid']),
        'download_url' => $hasDownload ? psst_share_download_url($share['uuid']) : null,
        'file_name' => $share['type'] === 'file' ? $share['original_filename'] : null,
        'size' => $share['file_size'] !== null ? (int) $share['file_size'] : null,
        'mime_type' => $share['mime_type'],
        'has_secret_code' => !empty($share['secret_code_hash']),
        'requires_secret_code' => !empty($share['secret_code_hash']) || psst_global_secret_enabled(),
        'expires_at' => psst_iso_timestamp($share['expires_at']),
        'max_views' => $share['max_views'] !== null ? (int) $share['max_views'] : null,
        'view_count' => (int) $share['view_count'],
        'views_remaining' => psst_share_views_remaining($share),
        'created_at' => psst_iso_sqlite_time($share['created_at']),
        'site' => $site,
    ];
}

function psst_integration_require_method(array $methods): void
{
    if (!in_array(psst_method(), $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        psst_json(['error' => 'Method not allowed.'], 405);
    }
}

/**
 * Shares of other keys or of the manager answer exactly like missing ones.
 */
function psst_integration_own_share(array $apiKey): array
{
    $uuid = (string) ($_GET['uuid'] ?? '');
    if (!psst_validate_uuid($uuid)) {
        psst_json(['error' => 'Invalid share UUID.'], 400);
    }

    psst_share_cleanup_unavailable();
    $share = psst_share_find_by_uuid($uuid);

    if (!$share || (int) ($share['api_key_id'] ?? 0) !== (int) $apiKey['id']) {
        psst_json(['error' => 'Share not found.'], 404);
    }

    return $share;
}

function psst_integration_status(array $apiKey)
{
    psst_integration_require_method(['GET']);

    psst_json([
        'ok' => true,
        'key' => $apiKey['name'],
        'app_version' => psst_version(),
        'max_upload_bytes' => psst_max_upload_bytes(),
        'share_types' => PSST_API_SHARE_TYPES,
    ]);
}

function psst_integration_create(array $apiKey)
{
    psst_integration_require_method(['POST']);

    if (psst_post_body_too_large()) {
        psst_json(['error' => psst_upload_limit_message(), 'max_upload_bytes' => psst_max_upload_bytes()], 413);
    }

    $data = psst_request_data();
    $type = trim((string) ($data['type'] ?? ''));
    if (!in_array($type, PSST_API_SHARE_TYPES, true)) {
        psst_json(['error' => 'type must be one of: ' . implode(', ', PSST_API_SHARE_TYPES) . '.'], 400);
    }

    // Browser-side encryption needs the PSST page to encrypt; the API stores plain content.
    if (psst_bool($data['is_encrypted'] ?? false)) {
        psst_json(['error' => 'Encrypted shares cannot be created through the API.'], 400);
    }

    $share = psst_build_share_payload(null);
    $share['api_key_id'] = (int) $apiKey['id'];

    psst_json(['share' => psst_integration_share(psst_share_create($share))], 201);
}

/**
 * Changes a share; fields that are not sent stay as they are. For sites, new HTML keeps
 * the link and the stored data.
 */
function psst_integration_update(array $apiKey)
{
    psst_integration_require_method(['POST']);

    if (psst_post_body_too_large()) {
        psst_json(['error' => psst_upload_limit_message(), 'max_upload_bytes' => psst_max_upload_bytes()], 413);
    }

    $existing = psst_integration_own_share($apiKey);
    $data = psst_request_data();
    $type = trim((string) ($data['type'] ?? $existing['type']));
    if (!in_array($type, PSST_API_SHARE_TYPES, true)) {
        psst_json(['error' => 'type must be one of: ' . implode(', ', PSST_API_SHARE_TYPES) . '.'], 400);
    }
    if (psst_bool($data['is_encrypted'] ?? false)) {
        psst_json(['error' => 'Encrypted shares cannot be created through the API.'], 400);
    }

    $share = psst_build_share_payload($existing);

    psst_json(['share' => psst_integration_share(psst_share_apply_update($existing, $share))]);
}

function psst_integration_get(array $apiKey)
{
    psst_integration_require_method(['GET']);

    psst_json(['share' => psst_integration_share(psst_integration_own_share($apiKey))]);
}

function psst_integration_delete(array $apiKey)
{
    psst_integration_require_method(['POST', 'DELETE']);

    $share = psst_integration_own_share($apiKey);
    psst_share_delete($share['uuid']);

    psst_json(['deleted' => true]);
}
