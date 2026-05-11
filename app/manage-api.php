<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

try {
    psst_db();

    $action = $_GET['action'] ?? '';

    match ($action) {
        'status' => psst_api_status(),
        'login' => psst_api_login(),
        'logout' => psst_api_logout(),
        'shares' => psst_api_shares(),
        'share' => psst_api_share(),
        'create' => psst_api_create_share(),
        'update' => psst_api_update_share(),
        'delete' => psst_api_delete_share(),
        default => psst_json(['error' => 'Unknown action.'], 404),
    };
} catch (Throwable $exception) {
    psst_json(['error' => 'Server error.', 'detail' => $exception->getMessage()], 500);
}

function psst_api_status(): never
{
    psst_json([
        'authenticated' => psst_is_logged_in(),
        'app_name' => psst_config()['app_name'],
        'base_url' => psst_base_url(),
    ]);
}

function psst_api_login(): never
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
    $htp = (string) ($data['htp'] ?? '');

    if (psst_verify_htp($htp) && psst_login($username, $password)) {
        psst_json(['authenticated' => true]);
    }

    psst_login_record_failure();
    psst_json(['error' => 'Invalid credentials.'], 401);
}

function psst_api_logout(): never
{
    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    psst_logout();
    psst_json(['authenticated' => false]);
}

function psst_api_shares(): never
{
    psst_require_login();

    if (psst_method() !== 'GET') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    psst_json(['shares' => array_map('psst_manager_share', psst_share_list())]);
}

function psst_api_share(): never
{
    psst_require_login();

    $uuid = psst_api_uuid();
    $share = psst_share_find_by_uuid($uuid);
    if (!$share) {
        psst_json(['error' => 'Share not found.'], 404);
    }

    psst_json(['share' => psst_manager_share($share)]);
}

function psst_api_create_share(): never
{
    psst_require_login();

    if (psst_method() !== 'POST') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $share = psst_build_share_payload(null);
    $created = psst_share_create($share);

    psst_json(['share' => psst_manager_share($created)], 201);
}

function psst_api_update_share(): never
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
        $oldPath = psst_stored_file_path($existing['stored_filename']);
        if (is_file($oldPath)) {
            unlink($oldPath);
        }
    }

    psst_json(['share' => psst_manager_share($updated)]);
}

function psst_api_delete_share(): never
{
    psst_require_login();

    if (psst_method() !== 'POST' && psst_method() !== 'DELETE') {
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    $uuid = psst_api_uuid();
    $share = psst_share_find_by_uuid($uuid);
    if (!$share) {
        psst_json(['error' => 'Share not found.'], 404);
    }

    if (psst_share_delete($uuid) && $share['stored_filename']) {
        $path = psst_stored_file_path($share['stored_filename']);
        if (is_file($path)) {
            unlink($path);
        }
    }

    psst_json(['deleted' => true]);
}

function psst_api_uuid(): string
{
    $uuid = (string) ($_GET['uuid'] ?? '');
    if (!psst_validate_uuid($uuid)) {
        psst_json(['error' => 'Invalid share UUID.'], 400);
    }

    return $uuid;
}

function psst_build_share_payload(?array $existing): array
{
    $data = psst_request_data();
    $uuid = $existing['uuid'] ?? psst_uuid();
    $type = trim((string) ($data['type'] ?? $existing['type'] ?? ''));

    if (!in_array($type, ['text', 'link', 'file'], true)) {
        psst_json(['error' => 'Share type must be text, link, or file.'], 400);
    }

    $isEncrypted = psst_bool($data['is_encrypted'] ?? $existing['is_encrypted'] ?? false);
    $content = array_key_exists('content', $data) ? (string) $data['content'] : ($existing['content'] ?? null);
    $originalFilename = $existing['original_filename'] ?? null;
    $storedFilename = $existing['stored_filename'] ?? null;
    $mimeType = $existing['mime_type'] ?? null;
    $fileSize = $existing['file_size'] ?? null;

    if ($type === 'text') {
        if (!$isEncrypted && trim((string) $content) === '') {
            psst_json(['error' => 'Text shares require content.'], 400);
        }
        $originalFilename = null;
        $storedFilename = null;
        $mimeType = 'text/plain; charset=utf-8';
        $fileSize = $content !== null ? strlen($content) : null;
    }

    if ($type === 'link') {
        if (!$isEncrypted && !filter_var($content, FILTER_VALIDATE_URL)) {
            psst_json(['error' => 'Link shares require a valid URL.'], 400);
        }
        $originalFilename = null;
        $storedFilename = null;
        $mimeType = 'text/uri-list';
        $fileSize = $content !== null ? strlen($content) : null;
    }

    if ($type === 'file') {
        $file = $_FILES['file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $originalFilename = psst_sanitize_filename((string) $file['name']);
            $storedFilename = $uuid . '-' . bin2hex(random_bytes(8)) . '-' . $originalFilename;
            $mimeType = $file['type'] ?: 'application/octet-stream';
            $fileSize = (int) $file['size'];
            $target = psst_stored_file_path($storedFilename);

            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                psst_json(['error' => 'Unable to store uploaded file.'], 500);
            }
        } elseif (!$existing) {
            psst_json(['error' => 'File shares require an uploaded file.'], 400);
        }

        $content = null;
    }

    $secretCodeHash = $existing['secret_code_hash'] ?? null;
    if (array_key_exists('secret_code', $data)) {
        $secretCode = trim((string) $data['secret_code']);
        $secretCodeHash = $secretCode !== '' ? psst_hash_secret($secretCode) : null;
    }

    return [
        'uuid' => $uuid,
        'type' => $type,
        'title' => trim((string) ($data['title'] ?? $existing['title'] ?? '')),
        'content' => $content,
        'original_filename' => $originalFilename,
        'stored_filename' => $storedFilename,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'is_encrypted' => $isEncrypted,
        'encryption_meta' => psst_normalize_meta($data['encryption_meta'] ?? $existing['encryption_meta'] ?? null),
        'secret_code_hash' => $secretCodeHash,
        'iti_secret_code_hash' => null,
        'iti_secret_code' => $existing['iti_secret_code'] ?? psst_generate_iti_secret_code(),
    ];
}