<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function psst_share_find_by_uuid(string $uuid): ?array
{
    $statement = psst_db()->prepare('SELECT * FROM shares WHERE uuid = :uuid');
    $statement->execute(['uuid' => $uuid]);
    $share = $statement->fetch();

    return $share ?: null;
}

function psst_share_list(): array
{
    return psst_db()
        ->query('SELECT * FROM shares ORDER BY created_at DESC, id DESC')
        ->fetchAll();
}

function psst_share_create(array $share): array
{
    $sql = 'INSERT INTO shares (
        uuid, type, title, content, original_filename, stored_filename, mime_type,
        file_size, is_encrypted, encryption_meta, secret_code_hash, iti_secret_code_hash, iti_secret_code, upload_token,
        expires_at, max_views, upload_quota_bytes
    ) VALUES (
        :uuid, :type, :title, :content, :original_filename, :stored_filename, :mime_type,
        :file_size, :is_encrypted, :encryption_meta, :secret_code_hash, :iti_secret_code_hash, :iti_secret_code, :upload_token,
        :expires_at, :max_views, :upload_quota_bytes
    )';

    $statement = psst_db()->prepare($sql);
    $statement->execute([
        'uuid' => $share['uuid'],
        'type' => $share['type'],
        'title' => $share['title'] ?? '',
        'content' => $share['content'] ?? null,
        'original_filename' => $share['original_filename'] ?? null,
        'stored_filename' => $share['stored_filename'] ?? null,
        'mime_type' => $share['mime_type'] ?? null,
        'file_size' => $share['file_size'] ?? null,
        'is_encrypted' => !empty($share['is_encrypted']) ? 1 : 0,
        'encryption_meta' => $share['encryption_meta'] ?? null,
        'secret_code_hash' => $share['secret_code_hash'] ?? null,
        'iti_secret_code_hash' => $share['iti_secret_code_hash'] ?? null,
        'iti_secret_code' => $share['iti_secret_code'] ?? null,
        'upload_token' => $share['upload_token'] ?? null,
        'expires_at' => $share['expires_at'] ?? null,
        'max_views' => $share['max_views'] ?? null,
        'upload_quota_bytes' => $share['upload_quota_bytes'] ?? null,
    ]);

    return psst_share_find_by_uuid($share['uuid']);
}

function psst_share_update(string $uuid, array $share): ?array
{
    $sql = 'UPDATE shares SET
        type = :type,
        title = :title,
        content = :content,
        original_filename = :original_filename,
        stored_filename = :stored_filename,
        mime_type = :mime_type,
        file_size = :file_size,
        is_encrypted = :is_encrypted,
        encryption_meta = :encryption_meta,
        secret_code_hash = :secret_code_hash,
        iti_secret_code_hash = :iti_secret_code_hash,
        iti_secret_code = :iti_secret_code,
        upload_token = :upload_token,
        expires_at = :expires_at,
        max_views = :max_views,
        upload_quota_bytes = :upload_quota_bytes,
        updated_at = CURRENT_TIMESTAMP
    WHERE uuid = :uuid';

    $statement = psst_db()->prepare($sql);
    $statement->execute([
        'uuid' => $uuid,
        'type' => $share['type'],
        'title' => $share['title'] ?? '',
        'content' => $share['content'] ?? null,
        'original_filename' => $share['original_filename'] ?? null,
        'stored_filename' => $share['stored_filename'] ?? null,
        'mime_type' => $share['mime_type'] ?? null,
        'file_size' => $share['file_size'] ?? null,
        'is_encrypted' => !empty($share['is_encrypted']) ? 1 : 0,
        'encryption_meta' => $share['encryption_meta'] ?? null,
        'secret_code_hash' => $share['secret_code_hash'] ?? null,
        'iti_secret_code_hash' => $share['iti_secret_code_hash'] ?? null,
        'iti_secret_code' => $share['iti_secret_code'] ?? null,
        'upload_token' => $share['upload_token'] ?? null,
        'expires_at' => $share['expires_at'] ?? null,
        'max_views' => $share['max_views'] ?? null,
        'upload_quota_bytes' => $share['upload_quota_bytes'] ?? null,
    ]);

    return psst_share_find_by_uuid($uuid);
}

function psst_share_delete(string $uuid): bool
{
    $share = psst_share_find_by_uuid($uuid);
    if (!$share) {
        return false;
    }

    foreach (psst_upload_files_for_share((int) $share['id']) as $uploadFile) {
        psst_stored_file_unlink($uploadFile['stored_filename'] ?? null);
    }
    psst_stored_file_unlink($share['stored_filename'] ?? null);

    $statement = psst_db()->prepare('DELETE FROM shares WHERE uuid = :uuid');
    $statement->execute(['uuid' => $uuid]);

    return $statement->rowCount() > 0;
}

/**
 * Deletes shares that expired or used up their views. Drop boxes are only closed by their
 * expiry, never deleted, so received files stay until the owner removes them.
 */
function psst_share_cleanup_unavailable(): void
{
    $statement = psst_db()->prepare(
        'SELECT uuid FROM shares WHERE type != \'upload\' AND (
            (expires_at IS NOT NULL AND expires_at <= :now)
            OR (max_views IS NOT NULL AND view_count >= max_views)
        )'
    );
    $statement->execute(['now' => time()]);

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
        psst_share_delete((string) $uuid);
    }
}

/**
 * Counts one view. Fails when the share expired or has no views left, which also
 * settles concurrent requests for the last view.
 */
function psst_share_consume_view(int $shareId): bool
{
    $statement = psst_db()->prepare(
        'UPDATE shares SET view_count = view_count + 1
         WHERE id = :id
           AND (max_views IS NULL OR view_count < max_views)
           AND (expires_at IS NULL OR expires_at > :now)'
    );
    $statement->execute(['id' => $shareId, 'now' => time()]);

    return $statement->rowCount() === 1;
}

function psst_upload_used_bytes(int $shareId): int
{
    $statement = psst_db()->prepare('SELECT COALESCE(SUM(file_size), 0) FROM upload_files WHERE share_id = :share_id');
    $statement->execute(['share_id' => $shareId]);

    return (int) $statement->fetchColumn();
}

function psst_upload_files_for_share(int $shareId): array
{
    $statement = psst_db()->prepare(
        'SELECT upload_files.*, shares.uuid AS share_uuid FROM upload_files
         INNER JOIN shares ON shares.id = upload_files.share_id
         WHERE share_id = :share_id ORDER BY upload_files.created_at DESC, upload_files.id DESC'
    );
    $statement->execute(['share_id' => $shareId]);

    return $statement->fetchAll();
}

function psst_upload_file_find(string $shareUuid, string $fileUuid): ?array
{
    $statement = psst_db()->prepare(
        'SELECT upload_files.* FROM upload_files
         INNER JOIN shares ON shares.id = upload_files.share_id
         WHERE shares.uuid = :share_uuid AND upload_files.uuid = :file_uuid'
    );
    $statement->execute(['share_uuid' => $shareUuid, 'file_uuid' => $fileUuid]);
    $file = $statement->fetch();

    return $file ?: null;
}

function psst_upload_file_create(array $file): array
{
    $statement = psst_db()->prepare(
        'INSERT INTO upload_files (
            uuid, share_id, original_filename, stored_filename, mime_type, file_size, remote_ip
        ) VALUES (
            :uuid, :share_id, :original_filename, :stored_filename, :mime_type, :file_size, :remote_ip
        )'
    );
    $statement->execute([
        'uuid' => $file['uuid'],
        'share_id' => $file['share_id'],
        'original_filename' => $file['original_filename'],
        'stored_filename' => $file['stored_filename'],
        'mime_type' => $file['mime_type'] ?? null,
        'file_size' => $file['file_size'] ?? null,
        'remote_ip' => $file['remote_ip'] ?? null,
    ]);

    $statement = psst_db()->prepare('SELECT * FROM upload_files WHERE uuid = :uuid');
    $statement->execute(['uuid' => $file['uuid']]);

    return $statement->fetch();
}

function psst_upload_file_delete(array $uploadFile): bool
{
    psst_stored_file_unlink($uploadFile['stored_filename'] ?? null);

    $statement = psst_db()->prepare('DELETE FROM upload_files WHERE id = :id');
    $statement->execute(['id' => $uploadFile['id']]);

    return $statement->rowCount() > 0;
}

function psst_stored_file_unlink(?string $storedFilename): void
{
    if ($storedFilename === null || $storedFilename === '') {
        return;
    }

    try {
        $path = psst_stored_file_path($storedFilename);
    } catch (Throwable $exception) {
        return;
    }

    if (is_file($path)) {
        unlink($path);
    }
}

function psst_htp_is_used(int $value): bool
{
    $statement = psst_db()->prepare('SELECT 1 FROM htp_used WHERE value = :value');
    $statement->execute(['value' => $value]);

    return $statement->fetchColumn() !== false;
}

/**
 * Marks an HTP number as used. Returns false if it already was, so two parallel logins
 * cannot both use the same number.
 */
function psst_htp_mark_used(int $value): bool
{
    $statement = psst_db()->prepare('INSERT OR IGNORE INTO htp_used (value, used_at) VALUES (:value, :used_at)');
    $statement->execute(['value' => $value, 'used_at' => time()]);

    return $statement->rowCount() === 1;
}

function psst_record_event(string $ipAddress, string $eventType): void
{
    $statement = psst_db()->prepare(
        'INSERT INTO request_events (ip_address, event_type, created_at) VALUES (:ip_address, :event_type, :created_at)'
    );
    $statement->execute([
        'ip_address' => $ipAddress,
        'event_type' => $eventType,
        'created_at' => time(),
    ]);
}

function psst_count_events(string $ipAddress, string $eventType, int $since): int
{
    $statement = psst_db()->prepare(
        'SELECT COUNT(*) FROM request_events WHERE ip_address = :ip_address AND event_type = :event_type AND created_at >= :since'
    );
    $statement->execute([
        'ip_address' => $ipAddress,
        'event_type' => $eventType,
        'since' => $since,
    ]);

    return (int) $statement->fetchColumn();
}

/**
 * Failed logins have their own, longer window (login_block_seconds); pruning them with
 * the request window would shorten the login block to that window.
 */
function psst_prune_old_events(int $requestsBefore, int $failedLoginsBefore): void
{
    $statement = psst_db()->prepare(
        'DELETE FROM request_events
         WHERE (event_type = \'failed_login\' AND created_at < :failed_logins_before)
            OR (event_type != \'failed_login\' AND created_at < :requests_before)'
    );
    $statement->execute([
        'requests_before' => $requestsBefore,
        'failed_logins_before' => $failedLoginsBefore,
    ]);
}