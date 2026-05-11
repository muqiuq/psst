<?php

declare(strict_types=1);

function psst_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
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