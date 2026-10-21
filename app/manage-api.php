<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

psst_configure_error_output();
header('Cache-Control: no-store');

try {
    psst_db();

    $action = $_GET['action'] ?? '';

    // Detect a body that PHP already discarded (post_max_size) before any handler runs,
    // otherwise $_POST/$_FILES look empty and the client only sees a vague failure.
    if (in_array($action, ['create', 'update'], true) && psst_post_body_too_large()) {
        psst_json([
            'error' => psst_upload_limit_message(),
            'max_upload_bytes' => psst_max_upload_bytes(),
        ], 413);
    }

    switch ($action) {
        case 'status':
            psst_api_status();
            break;
        case 'login':
            psst_api_login();
            break;
        case 'logout':
            psst_api_logout();
            break;
        case 'shares':
            psst_api_shares();
            break;
        case 'share':
            psst_api_share();
            break;
        case 'create':
            psst_api_create_share();
            break;
        case 'update':
            psst_api_update_share();
            break;
        case 'delete':
            psst_api_delete_share();
            break;
        case 'upload_download':
            psst_api_upload_download();
            break;
        case 'upload_delete':
            psst_api_upload_delete();
            break;
        case 'api_keys':
            psst_api_api_keys();
            break;
        case 'api_key_create':
            psst_api_api_key_create();
            break;
        case 'api_key_revoke':
            psst_api_api_key_revoke();
            break;
        default:
            psst_json(['error' => 'Unknown action.'], 404);
    }
} catch (Throwable $exception) {
    // Details go to the server log only; they can contain paths and SQL.
    error_log('PSST manage-api: ' . $exception);
    psst_json(['error' => 'Server error.'], 500);
}

function psst_api_status()
{
    psst_json([
        'authenticated' => psst_is_logged_in(),
        'app_name' => psst_config()['app_name'],
        'app_version' => psst_version(),
        'base_url' => psst_base_url(),
        'max_upload_bytes' => psst_max_upload_bytes(),
        'default_upload_quota_bytes' => (int) psst_config()['default_upload_quota_bytes'] ?: null,
    ]);
}

function psst_api_login()
{
    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    psst_login_delay();

    if (psst_login_is_blocked()) {
        psst_json(['error' => 'Too many failed login attempts. Try again later.'], 429);
    }

    $data = psst_request_data();
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $htpNumber = psst_htp_value((string) ($data['htp'] ?? ''));

    // The HTP number is used up only by a successful login, and marking it is atomic.
    if ($htpNumber !== null && psst_credentials_valid($username, $password) && psst_htp_mark_used($htpNumber)) {
        psst_login_session_start();
        psst_json(['authenticated' => true]);
    }

    psst_login_record_failure();
    psst_json(['error' => 'Invalid credentials.'], 401);
}

function psst_api_logout()
{
    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    psst_logout();
    psst_json(['authenticated' => false]);
}

function psst_api_shares()
{
    psst_require_login();

    if (psst_method() !== 'GET') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    psst_share_cleanup_unavailable();
    psst_json(['shares' => array_map('psst_manager_share', psst_share_list())]);
}

function psst_api_share()
{
    psst_require_login();

    $uuid = psst_api_uuid();
    $share = psst_share_find_by_uuid($uuid);
    if (!$share) {
        psst_json(['error' => 'Share not found.'], 404);
    }

    psst_json(['share' => psst_manager_share($share)]);
}

function psst_api_create_share()
{
    psst_require_login();

    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $share = psst_build_share_payload(null);
    $created = psst_share_create($share);

    psst_json(['share' => psst_manager_share($created)], 201);
}

function psst_api_update_share()
{
    psst_require_login();

    if (psst_method() !== 'POST' && psst_method() !== 'PUT') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $uuid = psst_api_uuid();
    $existing = psst_share_find_by_uuid($uuid);
    if (!$existing) {
        psst_json(['error' => 'Share not found.'], 404);
    }

    $share = psst_build_share_payload($existing);
    $updated = psst_share_update($uuid, $share);

    if ($updated && $existing['stored_filename'] && $existing['stored_filename'] !== $updated['stored_filename']) {
        psst_stored_file_unlink($existing['stored_filename']);
    }

    psst_json(['share' => psst_manager_share($updated)]);
}

function psst_api_delete_share()
{
    psst_require_login();

    if (psst_method() !== 'POST' && psst_method() !== 'DELETE') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    if (!psst_share_delete(psst_api_uuid())) {
        psst_json(['error' => 'Share not found.'], 404);
    }

    psst_json(['deleted' => true]);
}

function psst_api_upload_download()
{
    psst_require_login();

    if (psst_method() !== 'GET') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $uuid = psst_api_uuid();
    $fileUuid = (string) ($_GET['file'] ?? '');
    if (!psst_validate_uuid($fileUuid)) {
        psst_json(['error' => 'Invalid file UUID.'], 400);
    }

    $uploadFile = psst_upload_file_find($uuid, $fileUuid);
    if (!$uploadFile) {
        psst_json(['error' => 'Uploaded file not found.'], 404);
    }

    $path = psst_stored_file_path((string) $uploadFile['stored_filename']);
    if (!is_file($path)) {
        psst_json(['error' => 'Stored file is missing.'], 404);
    }

    psst_send_file_headers($uploadFile['mime_type'], $uploadFile['original_filename'] ?: 'upload-file', (int) filesize($path), false);
    readfile($path);
    exit;
}

function psst_api_upload_delete()
{
    psst_require_login();

    if (psst_method() !== 'POST' && psst_method() !== 'DELETE') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $uuid = psst_api_uuid();
    $fileUuid = (string) ($_GET['file'] ?? '');
    if (!psst_validate_uuid($fileUuid)) {
        psst_json(['error' => 'Invalid file UUID.'], 400);
    }

    $uploadFile = psst_upload_file_find($uuid, $fileUuid);
    if (!$uploadFile) {
        psst_json(['error' => 'Uploaded file not found.'], 404);
    }

    psst_upload_file_delete($uploadFile);

    psst_json(['deleted' => true]);
}

function psst_api_api_keys()
{
    psst_require_login();

    psst_json(['keys' => array_map('psst_manager_api_key', psst_api_key_list())]);
}

function psst_api_api_key_create()
{
    psst_require_login();

    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $name = trim((string) (psst_request_data()['name'] ?? ''));
    if ($name === '' || psst_limit_chars($name, 80) !== $name) {
        psst_json(['error' => 'Give the key a name of up to 80 characters, for example "PLM".'], 400);
    }

    $created = psst_api_key_create($name);

    psst_json(['key' => psst_manager_api_key($created['key']), 'token' => $created['token']], 201);
}

function psst_api_api_key_revoke()
{
    psst_require_login();

    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    if (!psst_api_key_revoke((int) ($_GET['id'] ?? 0))) {
        psst_json(['error' => 'API key not found or already revoked.'], 404);
    }

    psst_json(['revoked' => true]);
}

function psst_api_uuid(): string
{
    $uuid = (string) ($_GET['uuid'] ?? '');
    if (!psst_validate_uuid($uuid)) {
        psst_json(['error' => 'Invalid share UUID.'], 400);
    }

    return $uuid;
}
