<?php

declare(strict_types=1);

// Storage endpoint for site pages. A site runs in a sandbox with an anonymous origin and
// cannot send cookies, so access is decided only by the signed token PSST put into the
// page (see sites.php). The owner uses manage-api.php or api.php instead.

require_once __DIR__ . '/functions.php';

psst_configure_error_output();
header('Cache-Control: no-store');
// Requests come from the anonymous origin "null"; they carry no cookies, only the token.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 600');

if (psst_method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    psst_db();

    $claims = psst_site_token_verify(psst_request_bearer_token());
    if ($claims === null) {
        psst_json(['error' => 'The access token is missing, invalid or expired. Reload the page.'], 401);
    }

    $share = psst_share_find_by_uuid((string) ($claims['u'] ?? ''));
    if (!$share || $share['type'] !== 'site' || psst_share_is_expired($share)) {
        psst_json(['error' => 'This site is no longer available.'], 404);
    }

    $access = psst_site_access($share, $claims);
    if ($access === null) {
        psst_json(['error' => 'Storage is not available for this page. Reload the page.'], 403);
    }

    psst_site_page_action((string) ($_GET['action'] ?? ''), $share, $access);
} catch (Throwable $exception) {
    // Details go to the server log only; they can contain paths and SQL.
    error_log('PSST storage: ' . $exception);
    psst_json(['error' => 'Server error.'], 500);
}
