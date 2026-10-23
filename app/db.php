<?php

declare(strict_types=1);

function psst_config(): array
{
    static $config = null;

    if ($config === null) {
        // Defaults keep older config.php files working when new options are added.
        $config = array_replace(psst_default_config(), require __DIR__ . '/config.php');

        if ($config['timezone'] !== '' && !@date_default_timezone_set((string) $config['timezone'])) {
            error_log('PSST: unknown timezone in config.php: ' . $config['timezone']);
        }
    }

    return $config;
}

function psst_default_config(): array
{
    return [
        'app_name' => 'PSST',
        'timezone' => '',
        'data_dir' => __DIR__ . '/data',
        'files_dir' => __DIR__ . '/data/files',
        'database_path' => __DIR__ . '/data/psst.sqlite',
        'server_secret_code' => '',
        'max_upload_bytes' => 104857600,
        'default_upload_quota_bytes' => 1073741824,
        'max_site_bytes' => 5242880,
        'default_site_storage_bytes' => 1048576,
        'default_visitor_storage_bytes' => 262144,
        'default_site_visitor_total_bytes' => 20971520,
        'default_visitor_lifetime_days' => 90,
        'max_site_writes_per_minute' => 60,
        'trusted_proxies' => [],
        'rate_limit_window_seconds' => 3600,
        'max_requests_per_window' => 50,
        'max_failed_secret_attempts_per_window' => 10,
        'max_failed_api_auth_per_window' => 10,
        'login_delay_seconds' => 3,
        'max_failed_login_attempts' => 10,
        'login_block_seconds' => 86400,
        'session_lifetime_seconds' => 7776000,
        'session_name' => 'psst_session',
    ];
}

function psst_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = psst_config();
    psst_ensure_data_directories($config);

    $pdo = new PDO('sqlite:' . $config['database_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    psst_run_migrations($pdo);

    return $pdo;
}

function psst_ensure_data_directories(array $config): void
{
    foreach ([$config['data_dir'], $config['files_dir']] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }
}

function psst_run_migrations(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $migrations = [
        1 => static function (PDO $pdo): void {
            $pdo->exec(
                'CREATE TABLE shares (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    uuid TEXT NOT NULL UNIQUE,
                    type TEXT NOT NULL CHECK (type IN (\'text\', \'link\', \'file\')),
                    title TEXT NOT NULL DEFAULT \'\',
                    content TEXT,
                    original_filename TEXT,
                    stored_filename TEXT,
                    mime_type TEXT,
                    file_size INTEGER,
                    is_encrypted INTEGER NOT NULL DEFAULT 0,
                    encryption_meta TEXT,
                    secret_code_hash TEXT,
                    iti_secret_code_hash TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );

            $pdo->exec('CREATE INDEX idx_shares_uuid ON shares (uuid)');
        },
        2 => static function (PDO $pdo): void {
            $pdo->exec(
                'CREATE TABLE request_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    event_type TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )'
            );

            $pdo->exec('CREATE INDEX idx_request_events_lookup ON request_events (ip_address, event_type, created_at)');
        },
        3 => static function (PDO $pdo): void {
            $columns = $pdo->query('PRAGMA table_info(shares)')->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_column($columns, 'name');

            if (!in_array('iti_secret_code', $columnNames, true)) {
                $pdo->exec('ALTER TABLE shares ADD COLUMN iti_secret_code TEXT');
            }

            $statement = $pdo->query('SELECT uuid FROM shares WHERE iti_secret_code IS NULL OR iti_secret_code = \'\'');
            $update = $pdo->prepare('UPDATE shares SET iti_secret_code = :iti_secret_code WHERE uuid = :uuid');

            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
                $update->execute([
                    'uuid' => $uuid,
                    'iti_secret_code' => psst_db_generate_iti_secret_code(),
                ]);
            }
        },
        4 => static function (PDO $pdo): void {
            $statement = $pdo->query('SELECT uuid, iti_secret_code FROM shares');
            $update = $pdo->prepare('UPDATE shares SET iti_secret_code = :iti_secret_code WHERE uuid = :uuid');

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $share) {
                $code = strtoupper(trim((string) ($share['iti_secret_code'] ?? '')));
                if (preg_match('/^[A-Z0-9]{4}$/', $code) === 1) {
                    continue;
                }

                $update->execute([
                    'uuid' => $share['uuid'],
                    'iti_secret_code' => psst_db_generate_iti_secret_code(),
                ]);
            }
        },
        5 => static function (PDO $pdo): void {
            // Rebuild the shares table to drop the type CHECK constraint (SQLite cannot
            // alter a CHECK in place) and add the upload_token column. Type validation
            // now lives in PHP so new types like 'upload' can be added freely.
            $pdo->exec(
                'CREATE TABLE shares_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    uuid TEXT NOT NULL UNIQUE,
                    type TEXT NOT NULL,
                    title TEXT NOT NULL DEFAULT \'\',
                    content TEXT,
                    original_filename TEXT,
                    stored_filename TEXT,
                    mime_type TEXT,
                    file_size INTEGER,
                    is_encrypted INTEGER NOT NULL DEFAULT 0,
                    encryption_meta TEXT,
                    secret_code_hash TEXT,
                    iti_secret_code_hash TEXT,
                    iti_secret_code TEXT,
                    upload_token TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );

            $pdo->exec(
                'INSERT INTO shares_new (
                    id, uuid, type, title, content, original_filename, stored_filename,
                    mime_type, file_size, is_encrypted, encryption_meta, secret_code_hash,
                    iti_secret_code_hash, iti_secret_code, created_at, updated_at
                )
                SELECT
                    id, uuid, type, title, content, original_filename, stored_filename,
                    mime_type, file_size, is_encrypted, encryption_meta, secret_code_hash,
                    iti_secret_code_hash, iti_secret_code, created_at, updated_at
                FROM shares'
            );

            $pdo->exec('DROP TABLE shares');
            $pdo->exec('ALTER TABLE shares_new RENAME TO shares');
            $pdo->exec('CREATE INDEX idx_shares_uuid ON shares (uuid)');
        },
        6 => static function (PDO $pdo): void {
            $pdo->exec(
                'CREATE TABLE upload_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    uuid TEXT NOT NULL UNIQUE,
                    share_id INTEGER NOT NULL,
                    original_filename TEXT NOT NULL,
                    stored_filename TEXT NOT NULL,
                    mime_type TEXT,
                    file_size INTEGER,
                    remote_ip TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (share_id) REFERENCES shares (id) ON DELETE CASCADE
                )'
            );

            $pdo->exec('CREATE INDEX idx_upload_files_share ON upload_files (share_id)');
        },
        7 => static function (PDO $pdo): void {
            // Every HTP number may only be used once. INTEGER PRIMARY KEY is the rowid, so
            // the lookup needs no extra index and each row stays a few bytes.
            $pdo->exec(
                'CREATE TABLE htp_used (
                    value INTEGER PRIMARY KEY,
                    used_at INTEGER NOT NULL
                )'
            );
        },
        8 => static function (PDO $pdo): void {
            $pdo->exec('ALTER TABLE shares ADD COLUMN expires_at INTEGER');
            $pdo->exec('ALTER TABLE shares ADD COLUMN max_views INTEGER');
            $pdo->exec('ALTER TABLE shares ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('CREATE INDEX idx_shares_expires_at ON shares (expires_at)');
        },
        9 => static function (PDO $pdo): void {
            $pdo->exec('ALTER TABLE shares ADD COLUMN upload_quota_bytes INTEGER');

            // Existing drop boxes were unlimited; give them the default quota.
            $statement = $pdo->prepare('UPDATE shares SET upload_quota_bytes = :quota WHERE type = \'upload\'');
            $statement->execute(['quota' => (int) psst_config()['default_upload_quota_bytes'] ?: null]);
        },
        10 => static function (PDO $pdo): void {
            // Keys for api.php. Only a SHA-256 hash of each key is stored; revoked keys
            // stay so shares can still show which application created them.
            $pdo->exec(
                'CREATE TABLE api_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    token_prefix TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at INTEGER,
                    revoked_at INTEGER
                )'
            );
            $pdo->exec('ALTER TABLE shares ADD COLUMN api_key_id INTEGER');
            $pdo->exec('CREATE INDEX idx_shares_api_key ON shares (api_key_id)');
        },
        11 => static function (PDO $pdo): void {
            // Sites: single HTML files with optional key/value storage, shared or per visitor.
            foreach ([
                'storage_mode TEXT',
                'storage_quota_bytes INTEGER',
                'visitor_quota_bytes INTEGER',
                'visitor_total_bytes INTEGER',
                'visitor_lifetime_days INTEGER',
                'editor_key TEXT',
            ] as $column) {
                $pdo->exec('ALTER TABLE shares ADD COLUMN ' . $column);
            }

            // visitor_id is empty for the store all visitors share.
            $pdo->exec(
                'CREATE TABLE site_storage (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    share_id INTEGER NOT NULL,
                    visitor_id TEXT NOT NULL DEFAULT \'\',
                    item_key TEXT NOT NULL,
                    value TEXT NOT NULL,
                    version INTEGER NOT NULL DEFAULT 1,
                    size INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    updated_ip TEXT,
                    UNIQUE (share_id, visitor_id, item_key),
                    FOREIGN KEY (share_id) REFERENCES shares (id) ON DELETE CASCADE
                )'
            );
            $pdo->exec(
                'CREATE TABLE site_visitors (
                    share_id INTEGER NOT NULL,
                    visitor_id TEXT NOT NULL,
                    first_seen INTEGER NOT NULL,
                    last_seen INTEGER NOT NULL,
                    PRIMARY KEY (share_id, visitor_id),
                    FOREIGN KEY (share_id) REFERENCES shares (id) ON DELETE CASCADE
                )'
            );
            $pdo->exec('CREATE INDEX idx_site_visitors_last_seen ON site_visitors (share_id, last_seen)');

            // Key for signing site access tokens and deriving visitor IDs; never leaves the server.
            $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $statement = $pdo->prepare('INSERT INTO settings (name, value) VALUES (\'site_token_key\', :value)');
            $statement->execute(['value' => bin2hex(random_bytes(32))]);
        },
    ];

    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_map('intval', $applied);

    foreach ($migrations as $version => $migration) {
        if (in_array($version, $applied, true)) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $migration($pdo);
            $statement = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
            $statement->execute(['version' => $version]);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}

function psst_db_generate_iti_secret_code(): string
{
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';

    for ($index = 0; $index < 4; $index += 1) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}