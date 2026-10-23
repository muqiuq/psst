<?php

declare(strict_types=1);

/**
 * Sites: single HTML files served in a browser sandbox, with optional key/value storage
 * that all visitors share or that each visitor gets for themselves.
 *
 * A sandboxed page runs under an anonymous origin: it cannot use cookies or localStorage
 * and cannot reach the manager. PSST therefore adds a small script with a signed access
 * token when it serves the page, and storage.php decides access by that token alone.
 * The owner reaches the same data through manage-api.php and api.php.
 */

const PSST_SITE_STORAGE_MODES = ['off', 'read', 'shared', 'visitor'];
const PSST_SITE_MAX_VALUE_BYTES = 262144;
const PSST_SITE_MAX_KEYS = 1000;
const PSST_SITE_MAX_KEY_LENGTH = 200;
const PSST_SITE_TOKEN_LIFETIME = 43200;

// Scripts, forms, dialogs, popups and downloads work, but not as this origin: no access to
// PSST's cookies, the manager, or localStorage of other apps on the same host.
const PSST_SITE_SANDBOX = 'sandbox allow-scripts allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox allow-downloads';

final class PsstStorageException extends RuntimeException
{
    /** @var array extra fields for the JSON error response */
    public $extra;

    public function __construct(string $message, int $status, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->extra = $extra;
    }
}

function psst_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function psst_site_secret(): string
{
    static $secret = null;

    if ($secret === null) {
        $secret = (string) psst_setting('site_token_key');
        if ($secret === '') {
            throw new RuntimeException('The site token key is missing from the settings table.');
        }
    }

    return $secret;
}

function psst_site_token_create(array $claims): string
{
    $payload = psst_base64url((string) json_encode($claims));

    return $payload . '.' . psst_base64url(hash_hmac('sha256', $payload, psst_site_secret(), true));
}

function psst_site_token_verify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    $signature = psst_base64url(hash_hmac('sha256', $parts[0], psst_site_secret(), true));
    if (!hash_equals($signature, $parts[1])) {
        return null;
    }

    $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
    $claims = $json === false ? null : json_decode($json, true);

    return is_array($claims) && (int) ($claims['e'] ?? 0) >= time() ? $claims : null;
}

function psst_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $trusted = (array) psst_config()['trusted_proxies'];

    return $trusted !== []
        && psst_ip_in_list((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted)
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * URL path of the PSST folder, for cookies and for the storage endpoint.
 */
function psst_app_path(): string
{
    $path = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));

    return rtrim($path, '/') . '/';
}

/**
 * Recognizes the visitor of a per-visitor site by a cookie on PSST's own address; the
 * sandboxed page cannot read it. Returns this visitor's ID for this site.
 */
function psst_site_visitor_id(array $share): string
{
    $name = 'psst_v_' . substr(hash('sha256', $share['uuid']), 0, 12);
    $secret = (string) ($_COOKIE[$name] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $secret) !== 1) {
        $secret = bin2hex(random_bytes(32));
    }

    // Sent on every visit, so a remembered visitor keeps the store for the full period
    // after the last visit. 0 days: a session cookie that ends with the browser.
    $days = (int) $share['visitor_lifetime_days'];
    setcookie($name, $secret, [
        'expires' => $days > 0 ? time() + $days * 86400 : 0,
        'path' => psst_app_path(),
        'secure' => psst_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Derived per site, so stores of one browser on different sites cannot be linked.
    $visitorId = substr(hash_hmac('sha256', $secret . '|' . $share['uuid'], psst_site_secret()), 0, 32);
    psst_site_visitor_touch((int) $share['id'], $visitorId);

    return $visitorId;
}

function psst_site_expire_visitors(array $share): void
{
    // The end of a browser session cannot be observed; a day without a visit ends such a store.
    $days = (int) $share['visitor_lifetime_days'];
    psst_site_visitors_expire((int) $share['id'], time() - ($days > 0 ? $days * 86400 : 86400));
}

/**
 * What a page token may do under the site's current mode. Evaluated on every request, so
 * a changed mode also applies to pages that are already open.
 *
 * @return array{scope: string, visitor: string, write: bool}|null null when storage is off
 */
function psst_site_access(array $share, array $claims): ?array
{
    switch ((string) $share['storage_mode']) {
        case 'read':
            return ['scope' => 'site', 'visitor' => '', 'write' => !empty($claims['ed'])];
        case 'shared':
            return ['scope' => 'site', 'visitor' => '', 'write' => true];
        case 'visitor':
            $visitorId = (string) ($claims['v'] ?? '');
            return $visitorId === '' ? null : ['scope' => 'visitor', 'visitor' => $visitorId, 'write' => true];
        default:
            return null;
    }
}

/**
 * The page as served: the site's HTML with the storage script added when storage is on.
 */
function psst_site_page(array $share, string $html): string
{
    $mode = (string) $share['storage_mode'];
    if (!in_array($mode, ['read', 'shared', 'visitor'], true)) {
        return $html;
    }

    psst_site_expire_visitors($share);

    $editKey = (string) ($_GET['edit'] ?? '');
    $claims = [
        'u' => $share['uuid'],
        'v' => $mode === 'visitor' ? psst_site_visitor_id($share) : '',
        'ed' => $mode === 'read' && $editKey !== '' && hash_equals((string) $share['editor_key'], $editKey) ? 1 : 0,
        'e' => time() + PSST_SITE_TOKEN_LIFETIME,
    ];
    $access = psst_site_access($share, $claims);

    $config = json_encode([
        'endpoint' => psst_app_path() . 'storage.php',
        'token' => psst_site_token_create($claims),
        'scope' => $access['scope'],
        'canWrite' => $access['write'],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return psst_site_insert_script($html, '<script>' . str_replace('__PSST_CONFIG__', (string) $config, PSST_SITE_CLIENT_SCRIPT) . '</script>');
}

function psst_site_insert_script(string $html, string $script): string
{
    // As early as possible, so the site's own scripts can already use window.psst.
    foreach (['/<head\b[^>]*>/i', '/<html\b[^>]*>/i', '/<!doctype\b[^>]*>/i'] as $pattern) {
        if (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $position = $match[0][1] + strlen($match[0][0]);
            return substr($html, 0, $position) . $script . substr($html, $position);
        }
    }

    return $script . $html;
}

// window.psst.storage for site pages. Documented in docs/sites.md.
const PSST_SITE_CLIENT_SCRIPT = <<<'JS'
(function () {
  'use strict';
  var config = __PSST_CONFIG__;

  async function call(action, params, body) {
    var url = new URL(config.endpoint, location.href);
    url.searchParams.set('action', action);
    Object.keys(params || {}).forEach(function (name) { url.searchParams.set(name, params[name]); });
    var headers = { Authorization: 'Bearer ' + config.token };
    if (body !== undefined) { headers['Content-Type'] = 'application/json'; }
    var response = await fetch(url.toString(), {
      method: body === undefined ? 'GET' : 'POST',
      headers: headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'omit',
      cache: 'no-store'
    });
    var data = null;
    try { data = await response.json(); } catch (error) { data = null; }
    if (!response.ok) {
      var failure = new Error((data && data.error) || 'PSST storage request failed (HTTP ' + response.status + ').');
      failure.status = response.status;
      failure.data = data;
      throw failure;
    }
    return data;
  }

  function withVersion(body, options) {
    if (options && options.version !== undefined) { body.version = options.version; }
    return body;
  }

  var storage = {
    scope: config.scope,
    canWrite: config.canWrite,
    get: async function (key) { return (await call('get', { key: key })).value; },
    getEntry: async function (key) {
      var data = await call('get', { key: key });
      return { value: data.value, version: data.version };
    },
    set: async function (key, value, options) {
      return (await call('set', {}, withVersion({ key: key, value: value }, options))).version;
    },
    remove: async function (key, options) {
      return (await call('remove', {}, withVersion({ key: key }, options))).removed;
    },
    list: async function () { return (await call('list')).items; },
    append: async function (key, item) {
      var data = await call('append', {}, { key: key, item: item });
      return { length: data.length, version: data.version };
    },
    increment: async function (key, by) {
      return (await call('increment', {}, { key: key, by: by === undefined ? 1 : by })).value;
    }
  };

  window.psst = Object.freeze({ storage: Object.freeze(storage) });
}());
JS;

function psst_site_validate_key($key): string
{
    if (!is_string($key) || $key === '' || strlen($key) > PSST_SITE_MAX_KEY_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
        throw new PsstStorageException(sprintf('key must be text of 1 to %d characters without control characters.', PSST_SITE_MAX_KEY_LENGTH), 400);
    }

    return $key;
}

function psst_site_encode_value($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        throw new PsstStorageException('The value cannot be stored as JSON.', 400);
    }
    if (strlen($json) > PSST_SITE_MAX_VALUE_BYTES) {
        throw new PsstStorageException(sprintf('A value may be at most %s.', psst_format_bytes(PSST_SITE_MAX_VALUE_BYTES)), 413);
    }

    return $json;
}

/**
 * Values come back as stored: JSON objects stay objects, also when empty.
 */
function psst_site_decode_value(string $json)
{
    return json_decode($json);
}

/**
 * The request body as a JSON object (stdClass), so that {} is not turned into [].
 */
function psst_request_object(): stdClass
{
    $raw = (string) file_get_contents('php://input');
    if (trim($raw) === '') {
        return new stdClass();
    }

    $data = json_decode($raw);
    if (!$data instanceof stdClass) {
        psst_json(['error' => 'Send a JSON object as the request body.'], 400);
    }

    return $data;
}

function psst_site_transaction(callable $work)
{
    $pdo = psst_db();

    // IMMEDIATE takes the write lock up front, so read-modify-write steps (append,
    // increment, version checks, quotas) cannot interleave with other writers.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $work();
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $exception) {
        $pdo->exec('ROLLBACK');
        throw $exception;
    }
}

/**
 * Runs a change and rolls it back when it makes the store exceed its limits. A change
 * that does not grow the store always succeeds, even after a quota was lowered.
 */
function psst_site_write(array $share, string $visitorId, callable $change, bool $touchVisitor)
{
    return psst_site_transaction(static function () use ($share, $visitorId, $change, $touchVisitor) {
        $shareId = (int) $share['id'];
        $before = psst_site_store_usage($shareId, $visitorId);
        $result = $change();
        $after = psst_site_store_usage($shareId, $visitorId);

        if ($after['keys'] > PSST_SITE_MAX_KEYS && $after['keys'] > $before['keys']) {
            throw new PsstStorageException(sprintf('A store can hold at most %d keys.', PSST_SITE_MAX_KEYS), 413);
        }

        if ($after['bytes'] > $before['bytes']) {
            $quota = $visitorId === '' ? $share['storage_quota_bytes'] : $share['visitor_quota_bytes'];
            if ($quota !== null && $after['bytes'] > (int) $quota) {
                throw new PsstStorageException(sprintf('Not enough storage left: the limit is %s.', psst_format_bytes((int) $quota)), 413);
            }
            if ($visitorId !== '' && $share['visitor_total_bytes'] !== null
                && psst_site_usage($shareId)['visitor_bytes'] > (int) $share['visitor_total_bytes']) {
                throw new PsstStorageException('The storage for all visitors of this site is full.', 413);
            }
        }

        if ($touchVisitor && $visitorId !== '') {
            psst_site_visitor_touch($shareId, $visitorId);
        }

        return $result;
    });
}

function psst_site_check_version(?array $item, ?int $expected): void
{
    $current = $item ? (int) $item['version'] : 0;
    if ($expected !== null && $expected !== $current) {
        throw new PsstStorageException('The value was changed in the meantime. Load it again and retry.', 409, ['version' => $current]);
    }
}

function psst_site_expected_version(stdClass $body): ?int
{
    if (!property_exists($body, 'version') || $body->version === null) {
        return null;
    }
    if (!is_int($body->version) || $body->version < 0) {
        throw new PsstStorageException('version must be a whole number.', 400);
    }

    return $body->version;
}

function psst_site_get(array $share, string $visitorId, string $key): array
{
    $item = psst_site_item((int) $share['id'], $visitorId, $key);

    return [
        'key' => $key,
        'value' => $item ? psst_site_decode_value($item['value']) : null,
        'version' => $item ? (int) $item['version'] : 0,
        'exists' => $item !== null,
    ];
}

function psst_site_list(array $share, string $visitorId): array
{
    return array_map(static function (array $item): array {
        return [
            'key' => $item['item_key'],
            'size' => (int) $item['size'],
            'version' => (int) $item['version'],
            'updated_at' => psst_iso_timestamp($item['updated_at']),
        ];
    }, psst_site_items((int) $share['id'], $visitorId));
}

/**
 * The operations a page and the owner share: get, list, set, remove, append, increment.
 * Keys come from the query (reads) or the JSON body (writes).
 */
function psst_site_operation(string $operation, array $share, string $visitorId, stdClass $body, bool $touchVisitor): array
{
    $shareId = (int) $share['id'];
    $key = static function () use ($body): string {
        return psst_site_validate_key(array_key_exists('key', $_GET) ? (string) $_GET['key'] : ($body->key ?? null));
    };

    switch ($operation) {
        case 'list':
            return ['items' => psst_site_list($share, $visitorId)];

        case 'get':
            return psst_site_get($share, $visitorId, $key());

        case 'set':
            if (!property_exists($body, 'value')) {
                throw new PsstStorageException('value is required.', 400);
            }
            $name = $key();
            $json = psst_site_encode_value($body->value);
            $expected = psst_site_expected_version($body);
            return psst_site_write($share, $visitorId, static function () use ($shareId, $visitorId, $name, $json, $expected): array {
                psst_site_check_version(psst_site_item($shareId, $visitorId, $name), $expected);
                return ['key' => $name, 'version' => psst_site_item_put($shareId, $visitorId, $name, $json, psst_client_ip())];
            }, $touchVisitor);

        case 'remove':
            $name = $key();
            $expected = psst_site_expected_version($body);
            return psst_site_write($share, $visitorId, static function () use ($shareId, $visitorId, $name, $expected): array {
                psst_site_check_version(psst_site_item($shareId, $visitorId, $name), $expected);
                return ['key' => $name, 'removed' => psst_site_item_delete($shareId, $visitorId, $name)];
            }, $touchVisitor);

        case 'append':
            if (!property_exists($body, 'item')) {
                throw new PsstStorageException('item is required.', 400);
            }
            $name = $key();
            $entry = $body->item;
            return psst_site_write($share, $visitorId, static function () use ($shareId, $visitorId, $name, $entry): array {
                $item = psst_site_item($shareId, $visitorId, $name);
                $list = $item ? psst_site_decode_value($item['value']) : [];
                if (!is_array($list)) {
                    throw new PsstStorageException(sprintf('The value of "%s" is not a list.', $name), 409);
                }
                $list[] = $entry;
                $version = psst_site_item_put($shareId, $visitorId, $name, psst_site_encode_value($list), psst_client_ip());
                return ['key' => $name, 'version' => $version, 'length' => count($list)];
            }, $touchVisitor);

        case 'increment':
            $name = $key();
            $by = property_exists($body, 'by') ? $body->by : 1;
            if (!is_int($by) && !is_float($by)) {
                throw new PsstStorageException('by must be a number.', 400);
            }
            return psst_site_write($share, $visitorId, static function () use ($shareId, $visitorId, $name, $by): array {
                $item = psst_site_item($shareId, $visitorId, $name);
                $current = $item ? psst_site_decode_value($item['value']) : 0;
                if (!is_int($current) && !is_float($current)) {
                    throw new PsstStorageException(sprintf('The value of "%s" is not a number.', $name), 409);
                }
                $value = $current + $by;
                $version = psst_site_item_put($shareId, $visitorId, $name, psst_site_encode_value($value), psst_client_ip());
                return ['key' => $name, 'value' => $value, 'version' => $version];
            }, $touchVisitor);
    }

    throw new PsstStorageException('Unknown action.', 404);
}

function psst_site_enforce_write_limit(array $share): void
{
    $limit = (int) psst_config()['max_site_writes_per_minute'];
    $type = 'site_write:' . (int) $share['id'];

    if ($limit > 0 && psst_count_events(psst_client_ip(), $type, time() - 60) >= $limit) {
        psst_json(['error' => 'Too many changes. Wait a minute and try again.'], 429);
    }

    psst_record_event(psst_client_ip(), $type);
}

/**
 * storage.php: what a site page may do with its token.
 */
function psst_site_page_action(string $action, array $share, array $access): void
{
    $isWrite = in_array($action, ['set', 'remove', 'append', 'increment'], true);
    if (!$isWrite && !in_array($action, ['get', 'list'], true)) {
        psst_json(['error' => 'Unknown action.'], 404);
    }
    if (psst_method() !== ($isWrite ? 'POST' : 'GET')) {
        header('Allow: ' . ($isWrite ? 'POST' : 'GET'));
        psst_json(['error' => 'Method not allowed.'], 405);
    }
    if ($isWrite && !$access['write']) {
        psst_json(['error' => 'This page may only read the storage.'], 403);
    }
    if ($isWrite) {
        psst_site_enforce_write_limit($share);
    }

    try {
        $body = $isWrite ? psst_request_object() : new stdClass();
        psst_json(psst_site_operation($action, $share, $access['visitor'], $body, true));
    } catch (PsstStorageException $exception) {
        psst_json(['error' => $exception->getMessage()] + $exception->extra, $exception->getCode());
    }
}

function psst_site_export(array $share): array
{
    $shareId = (int) $share['id'];
    $items = static function (string $visitorId) use ($shareId): stdClass {
        $map = new stdClass();
        foreach (psst_site_items($shareId, $visitorId) as $item) {
            $map->{$item['item_key']} = [
                'value' => psst_site_decode_value($item['value']),
                'version' => (int) $item['version'],
                'updated_at' => psst_iso_timestamp($item['updated_at']),
            ];
        }
        return $map;
    };

    $visitors = new stdClass();
    foreach (psst_site_visitor_list($shareId) as $visitor) {
        $visitors->{$visitor['visitor_id']} = [
            'first_seen' => psst_iso_timestamp($visitor['first_seen']),
            'last_seen' => psst_iso_timestamp($visitor['last_seen']),
            'items' => $items($visitor['visitor_id']),
        ];
    }

    return [
        'site' => $share['uuid'],
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'shared' => $items(''),
        'visitors' => $visitors,
    ];
}

/**
 * Imports an export document. Merges by default (same keys are overwritten); with
 * $replace, all existing data is removed first. Fails as a whole when a quota would be
 * exceeded.
 */
function psst_site_import(array $share, stdClass $document, bool $replace): array
{
    $shared = $document->shared ?? new stdClass();
    $visitors = $document->visitors ?? new stdClass();
    if (!$shared instanceof stdClass || !$visitors instanceof stdClass) {
        throw new PsstStorageException('Send an export document: {"shared": {...}, "visitors": {...}}.', 400);
    }

    return psst_site_transaction(static function () use ($share, $shared, $visitors, $replace): array {
        $shareId = (int) $share['id'];
        if ($replace) {
            psst_site_storage_delete_all($shareId);
        }

        $count = 0;
        $put = static function (string $visitorId, $key, $entry) use ($shareId, &$count): void {
            if (!$entry instanceof stdClass || !property_exists($entry, 'value')) {
                throw new PsstStorageException('Each item needs a "value".', 400);
            }
            psst_site_item_put($shareId, $visitorId, psst_site_validate_key((string) $key), psst_site_encode_value($entry->value), psst_client_ip());
            $count += 1;
        };

        foreach (get_object_vars($shared) as $key => $entry) {
            $put('', $key, $entry);
        }

        foreach (get_object_vars($visitors) as $visitorId => $visitor) {
            $visitorId = (string) $visitorId;
            $items = $visitor instanceof stdClass ? ($visitor->items ?? new stdClass()) : null;
            if (preg_match('/^[a-f0-9]{32}$/', $visitorId) !== 1 || !$items instanceof stdClass) {
                throw new PsstStorageException('Visitors need a 32-character hex ID and an "items" object.', 400);
            }
            if (!psst_site_visitor_find($shareId, $visitorId)) {
                psst_site_visitor_touch($shareId, $visitorId);
            }
            foreach (get_object_vars($items) as $key => $entry) {
                $put($visitorId, $key, $entry);
            }
        }

        psst_site_check_all_limits($share);

        return ['imported' => $count];
    });
}

function psst_site_check_all_limits(array $share): void
{
    $shareId = (int) $share['id'];
    $stores = array_merge([''], array_column(psst_site_visitor_list($shareId), 'visitor_id'));

    foreach ($stores as $visitorId) {
        $usage = psst_site_store_usage($shareId, (string) $visitorId);
        $quota = $visitorId === '' ? $share['storage_quota_bytes'] : $share['visitor_quota_bytes'];
        if ($usage['keys'] > PSST_SITE_MAX_KEYS || ($quota !== null && $usage['bytes'] > (int) $quota)) {
            throw new PsstStorageException('The import does not fit into the storage limits of this site.', 413);
        }
    }

    if ($share['visitor_total_bytes'] !== null && psst_site_usage($shareId)['visitor_bytes'] > (int) $share['visitor_total_bytes']) {
        throw new PsstStorageException('The import does not fit into the storage limit for all visitors.', 413);
    }
}

function psst_site_visitors(array $share): array
{
    return array_map(static function (array $visitor): array {
        return [
            'id' => $visitor['visitor_id'],
            'first_seen' => psst_iso_timestamp($visitor['first_seen']),
            'last_seen' => psst_iso_timestamp($visitor['last_seen']),
            'size' => (int) $visitor['size'],
            'keys' => (int) $visitor['key_count'],
        ];
    }, psst_site_visitor_list((int) $share['id']));
}

/**
 * Storage actions for the site owner, shared by the manager and api.php. The owner may
 * read and write every store whatever the mode; quotas still apply. Without a visitor
 * parameter the actions use the shared store.
 */
function psst_site_owner_action(string $action, array $share): void
{
    if ($share['type'] !== 'site') {
        psst_json(['error' => 'Storage actions only work for sites.'], 400);
    }

    $reads = ['storage_list', 'storage_get', 'storage_export', 'visitors'];
    $writes = ['storage_set', 'storage_remove', 'storage_append', 'storage_increment', 'storage_import', 'storage_clear'];
    $isWrite = in_array($action, $writes, true);
    if (!$isWrite && !in_array($action, $reads, true)) {
        psst_json(['error' => 'Unknown action.'], 404);
    }
    if (psst_method() !== ($isWrite ? 'POST' : 'GET')) {
        header('Allow: ' . ($isWrite ? 'POST' : 'GET'));
        psst_json(['error' => 'Method not allowed.'], 405);
    }

    psst_site_expire_visitors($share);
    $body = $isWrite ? psst_request_object() : new stdClass();

    $requested = array_key_exists('visitor', $_GET) ? (string) $_GET['visitor'] : (isset($body->visitor) && is_string($body->visitor) ? $body->visitor : '');
    if ($requested !== '' && (preg_match('/^[a-f0-9]{32}$/', $requested) !== 1 || !psst_site_visitor_find((int) $share['id'], $requested))) {
        psst_json(['error' => 'Visitor not found.'], 404);
    }

    try {
        switch ($action) {
            case 'storage_export':
                psst_json(psst_site_export($share));
                break;
            case 'storage_import':
                psst_json(psst_site_import($share, $body, psst_bool($_GET['replace'] ?? false)));
                break;
            case 'storage_clear':
                psst_site_storage_delete_all((int) $share['id'], $requested !== '' ? $requested : null);
                psst_json(['cleared' => true]);
                break;
            case 'visitors':
                psst_json(['visitors' => psst_site_visitors($share)]);
                break;
            default:
                psst_json(psst_site_operation(substr($action, strlen('storage_')), $share, $requested, $body, false));
        }
    } catch (PsstStorageException $exception) {
        psst_json(['error' => $exception->getMessage()] + $exception->extra, $exception->getCode());
    }
}

/**
 * Site details for the manager and the API.
 */
function psst_site_info(array $share): array
{
    $usage = psst_site_usage((int) $share['id']);
    $optionalInt = static function ($value): ?int {
        return $value === null ? null : (int) $value;
    };

    return [
        'file_name' => $share['original_filename'],
        'storage_mode' => (string) ($share['storage_mode'] ?: 'off'),
        'editor_url' => $share['editor_key'] ? psst_share_url($share['uuid']) . '&edit=' . rawurlencode((string) $share['editor_key']) : null,
        'storage_quota_bytes' => $optionalInt($share['storage_quota_bytes']),
        'visitor_quota_bytes' => $optionalInt($share['visitor_quota_bytes']),
        'visitor_total_bytes' => $optionalInt($share['visitor_total_bytes']),
        'visitor_lifetime_days' => (int) $share['visitor_lifetime_days'],
        'storage_used_bytes' => $usage['shared_bytes'],
        'visitor_used_bytes' => $usage['visitor_bytes'],
        'visitor_count' => $usage['visitors'],
    ];
}
