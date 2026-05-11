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
        file_size, is_encrypted, encryption_meta, secret_code_hash, iti_secret_code_hash, iti_secret_code
    ) VALUES (
        :uuid, :type, :title, :content, :original_filename, :stored_filename, :mime_type,
        :file_size, :is_encrypted, :encryption_meta, :secret_code_hash, :iti_secret_code_hash, :iti_secret_code
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
    ]);

    return psst_share_find_by_uuid($uuid);
}

function psst_share_delete(string $uuid): bool
{
    $share = psst_share_find_by_uuid($uuid);
    if (!$share) {
        return false;
    }

    $statement = psst_db()->prepare('DELETE FROM shares WHERE uuid = :uuid');
    $statement->execute(['uuid' => $uuid]);

    return $statement->rowCount() > 0;
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