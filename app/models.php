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
        file_size, is_encrypted, encryption_meta, secret_code_hash, iti_secret_code_hash, iti_secret_code, upload_token
    ) VALUES (
        :uuid, :type, :title, :content, :original_filename, :stored_filename, :mime_type,
        :file_size, :is_encrypted, :encryption_meta, :secret_code_hash, :iti_secret_code_hash, :iti_secret_code, :upload_token
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
        psst_upload_file_unlink($uploadFile);
    }

    $statement = psst_db()->prepare('DELETE FROM shares WHERE uuid = :uuid');
    $statement->execute(['uuid' => $uuid]);

    return $statement->rowCount() > 0;
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
    psst_upload_file_unlink($uploadFile);

    $statement = psst_db()->prepare('DELETE FROM upload_files WHERE id = :id');
    $statement->execute(['id' => $uploadFile['id']]);

    return $statement->rowCount() > 0;
}

function psst_upload_file_unlink(array $uploadFile): void
{
    if (empty($uploadFile['stored_filename'])) {
        return;
    }

    try {
        $path = psst_stored_file_path((string) $uploadFile['stored_filename']);
    } catch (Throwable $exception) {
        return;
    }

    if (is_file($path)) {
        unlink($path);
    }
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

function psst_prune_old_events(int $before): void
{
    $statement = psst_db()->prepare('DELETE FROM request_events WHERE created_at < :before');
    $statement->execute(['before' => $before]);
}