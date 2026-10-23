<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Shares carry the name of the API key that created them, for the manager list.
const PSST_SHARE_SELECT = 'SELECT shares.*, api_keys.name AS api_key_name FROM shares
    LEFT JOIN api_keys ON api_keys.id = shares.api_key_id';

function psst_share_find_by_uuid(string $uuid): ?array
{
    $statement = psst_db()->prepare(PSST_SHARE_SELECT . ' WHERE shares.uuid = :uuid');
    $statement->execute(['uuid' => $uuid]);
    $share = $statement->fetch();

    return $share ?: null;
}

function psst_share_list(): array
{
    return psst_db()
        ->query(PSST_SHARE_SELECT . ' ORDER BY shares.created_at DESC, shares.id DESC')
        ->fetchAll();
}

function psst_share_create(array $share): array
{
    $sql = 'INSERT INTO shares (
        uuid, type, title, content, original_filename, stored_filename, mime_type,
        file_size, is_encrypted, encryption_meta, secret_code_hash, iti_secret_code_hash, iti_secret_code, upload_token,
        expires_at, max_views, upload_quota_bytes, api_key_id,
        storage_mode, storage_quota_bytes, visitor_quota_bytes, visitor_total_bytes, visitor_lifetime_days, editor_key
    ) VALUES (
        :uuid, :type, :title, :content, :original_filename, :stored_filename, :mime_type,
        :file_size, :is_encrypted, :encryption_meta, :secret_code_hash, :iti_secret_code_hash, :iti_secret_code, :upload_token,
        :expires_at, :max_views, :upload_quota_bytes, :api_key_id,
        :storage_mode, :storage_quota_bytes, :visitor_quota_bytes, :visitor_total_bytes, :visitor_lifetime_days, :editor_key
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
        'api_key_id' => $share['api_key_id'] ?? null,
        'storage_mode' => $share['storage_mode'] ?? null,
        'storage_quota_bytes' => $share['storage_quota_bytes'] ?? null,
        'visitor_quota_bytes' => $share['visitor_quota_bytes'] ?? null,
        'visitor_total_bytes' => $share['visitor_total_bytes'] ?? null,
        'visitor_lifetime_days' => $share['visitor_lifetime_days'] ?? null,
        'editor_key' => $share['editor_key'] ?? null,
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
        storage_mode = :storage_mode,
        storage_quota_bytes = :storage_quota_bytes,
        visitor_quota_bytes = :visitor_quota_bytes,
        visitor_total_bytes = :visitor_total_bytes,
        visitor_lifetime_days = :visitor_lifetime_days,
        editor_key = :editor_key,
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
        'storage_mode' => $share['storage_mode'] ?? null,
        'storage_quota_bytes' => $share['storage_quota_bytes'] ?? null,
        'visitor_quota_bytes' => $share['visitor_quota_bytes'] ?? null,
        'visitor_total_bytes' => $share['visitor_total_bytes'] ?? null,
        'visitor_lifetime_days' => $share['visitor_lifetime_days'] ?? null,
        'editor_key' => $share['editor_key'] ?? null,
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

function psst_api_key_hash(string $token): string
{
    // Keys are 256-bit random values, so a plain SHA-256 is enough; no slow hash needed.
    return hash('sha256', $token);
}

/**
 * @return array{key: array, token: string} the token is only available here, once
 */
function psst_api_key_create(string $name): array
{
    $token = 'psst_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $statement = psst_db()->prepare(
        'INSERT INTO api_keys (name, token_hash, token_prefix) VALUES (:name, :token_hash, :token_prefix)'
    );
    $statement->execute([
        'name' => $name,
        'token_hash' => psst_api_key_hash($token),
        'token_prefix' => substr($token, 0, 12),
    ]);

    return ['key' => psst_api_key_find((int) psst_db()->lastInsertId()), 'token' => $token];
}

function psst_api_key_find(int $id): ?array
{
    $statement = psst_db()->prepare('SELECT * FROM api_keys WHERE id = :id');
    $statement->execute(['id' => $id]);
    $key = $statement->fetch();

    return $key ?: null;
}

function psst_api_key_find_active_by_token(string $token): ?array
{
    $statement = psst_db()->prepare('SELECT * FROM api_keys WHERE token_hash = :token_hash AND revoked_at IS NULL');
    $statement->execute(['token_hash' => psst_api_key_hash($token)]);
    $key = $statement->fetch();

    return $key ?: null;
}

function psst_api_key_list(): array
{
    return psst_db()
        ->query(
            'SELECT api_keys.*, (SELECT COUNT(*) FROM shares WHERE shares.api_key_id = api_keys.id) AS share_count
             FROM api_keys ORDER BY revoked_at IS NOT NULL, created_at DESC, id DESC'
        )
        ->fetchAll();
}

function psst_api_key_touch(int $id): void
{
    $statement = psst_db()->prepare('UPDATE api_keys SET last_used_at = :now WHERE id = :id');
    $statement->execute(['now' => time(), 'id' => $id]);
}

function psst_api_key_revoke(int $id): bool
{
    $statement = psst_db()->prepare('UPDATE api_keys SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL');
    $statement->execute(['now' => time(), 'id' => $id]);

    return $statement->rowCount() === 1;
}

function psst_setting(string $name): ?string
{
    $statement = psst_db()->prepare('SELECT value FROM settings WHERE name = :name');
    $statement->execute(['name' => $name]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string) $value;
}

function psst_site_item(int $shareId, string $visitorId, string $key): ?array
{
    $statement = psst_db()->prepare(
        'SELECT * FROM site_storage WHERE share_id = :share_id AND visitor_id = :visitor_id AND item_key = :item_key'
    );
    $statement->execute(['share_id' => $shareId, 'visitor_id' => $visitorId, 'item_key' => $key]);
    $item = $statement->fetch();

    return $item ?: null;
}

function psst_site_items(int $shareId, string $visitorId): array
{
    $statement = psst_db()->prepare(
        'SELECT * FROM site_storage WHERE share_id = :share_id AND visitor_id = :visitor_id ORDER BY item_key'
    );
    $statement->execute(['share_id' => $shareId, 'visitor_id' => $visitorId]);

    return $statement->fetchAll();
}

/**
 * Inserts or replaces a value; every change raises the version by one.
 */
function psst_site_item_put(int $shareId, string $visitorId, string $key, string $json, ?string $ip): int
{
    $existing = psst_site_item($shareId, $visitorId, $key);
    $size = strlen($key) + strlen($json);

    if ($existing) {
        $statement = psst_db()->prepare(
            'UPDATE site_storage SET value = :value, version = version + 1, size = :size, updated_at = :now, updated_ip = :ip
             WHERE id = :id'
        );
        $statement->execute(['value' => $json, 'size' => $size, 'now' => time(), 'ip' => $ip, 'id' => $existing['id']]);

        return (int) $existing['version'] + 1;
    }

    $statement = psst_db()->prepare(
        'INSERT INTO site_storage (share_id, visitor_id, item_key, value, version, size, updated_at, updated_ip)
         VALUES (:share_id, :visitor_id, :item_key, :value, 1, :size, :now, :ip)'
    );
    $statement->execute([
        'share_id' => $shareId,
        'visitor_id' => $visitorId,
        'item_key' => $key,
        'value' => $json,
        'size' => $size,
        'now' => time(),
        'ip' => $ip,
    ]);

    return 1;
}

function psst_site_item_delete(int $shareId, string $visitorId, string $key): bool
{
    $statement = psst_db()->prepare(
        'DELETE FROM site_storage WHERE share_id = :share_id AND visitor_id = :visitor_id AND item_key = :item_key'
    );
    $statement->execute(['share_id' => $shareId, 'visitor_id' => $visitorId, 'item_key' => $key]);

    return $statement->rowCount() > 0;
}

/**
 * @return array{bytes: int, keys: int}
 */
function psst_site_store_usage(int $shareId, string $visitorId): array
{
    $statement = psst_db()->prepare(
        'SELECT COALESCE(SUM(size), 0), COUNT(*) FROM site_storage WHERE share_id = :share_id AND visitor_id = :visitor_id'
    );
    $statement->execute(['share_id' => $shareId, 'visitor_id' => $visitorId]);
    $row = $statement->fetch(PDO::FETCH_NUM);

    return ['bytes' => (int) $row[0], 'keys' => (int) $row[1]];
}

/**
 * @return array{shared_bytes: int, visitor_bytes: int, visitors: int}
 */
function psst_site_usage(int $shareId): array
{
    $statement = psst_db()->prepare(
        'SELECT
            (SELECT COALESCE(SUM(size), 0) FROM site_storage WHERE share_id = :a AND visitor_id = \'\'),
            (SELECT COALESCE(SUM(size), 0) FROM site_storage WHERE share_id = :b AND visitor_id != \'\'),
            (SELECT COUNT(*) FROM site_visitors WHERE share_id = :c)'
    );
    $statement->execute(['a' => $shareId, 'b' => $shareId, 'c' => $shareId]);
    $row = $statement->fetch(PDO::FETCH_NUM);

    return ['shared_bytes' => (int) $row[0], 'visitor_bytes' => (int) $row[1], 'visitors' => (int) $row[2]];
}

function psst_site_visitor_find(int $shareId, string $visitorId): ?array
{
    $statement = psst_db()->prepare('SELECT * FROM site_visitors WHERE share_id = :share_id AND visitor_id = :visitor_id');
    $statement->execute(['share_id' => $shareId, 'visitor_id' => $visitorId]);
    $visitor = $statement->fetch();

    return $visitor ?: null;
}

function psst_site_visitor_touch(int $shareId, string $visitorId): void
{
    $statement = psst_db()->prepare(
        'INSERT INTO site_visitors (share_id, visitor_id, first_seen, last_seen) VALUES (:share_id, :visitor_id, :now, :now)
         ON CONFLICT (share_id, visitor_id) DO UPDATE SET last_seen = excluded.last_seen'
    );
    $statement->execute(['share_id' => $shareId, 'visitor_id' => $visitorId, 'now' => time()]);
}

function psst_site_visitor_list(int $shareId): array
{
    $statement = psst_db()->prepare(
        'SELECT v.visitor_id, v.first_seen, v.last_seen,
                COALESCE(SUM(s.size), 0) AS size, COUNT(s.id) AS key_count
         FROM site_visitors v
         LEFT JOIN site_storage s ON s.share_id = v.share_id AND s.visitor_id = v.visitor_id
         WHERE v.share_id = :share_id
         GROUP BY v.visitor_id
         ORDER BY v.last_seen DESC'
    );
    $statement->execute(['share_id' => $shareId]);

    return $statement->fetchAll();
}

/**
 * Deletes stored data; with a visitor ID only that visitor's store and record.
 */
function psst_site_storage_delete_all(int $shareId, ?string $visitorId = null): void
{
    $where = $visitorId === null ? '' : ' AND visitor_id = :visitor_id';
    $params = $visitorId === null ? ['share_id' => $shareId] : ['share_id' => $shareId, 'visitor_id' => $visitorId];

    psst_db()->prepare('DELETE FROM site_storage WHERE share_id = :share_id' . $where)->execute($params);
    psst_db()->prepare('DELETE FROM site_visitors WHERE share_id = :share_id' . $where)->execute($params);
}

/**
 * Removes visitor stores that were not used since $before.
 */
function psst_site_visitors_expire(int $shareId, int $before): void
{
    $params = ['share_id' => $shareId, 'before' => $before];

    psst_db()->prepare(
        'DELETE FROM site_storage WHERE share_id = :share_id AND visitor_id IN (
            SELECT visitor_id FROM site_visitors WHERE share_id = :share_id AND last_seen < :before
        )'
    )->execute($params);
    psst_db()->prepare('DELETE FROM site_visitors WHERE share_id = :share_id AND last_seen < :before')->execute($params);
}
