<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

try {
	psst_enforce_rate_limit('request', (int) psst_config()['max_requests_per_window']);

	$uuid = (string) ($_GET['id'] ?? '');
	if (!psst_validate_uuid($uuid)) {
		psst_render_message('Share not found', 'The requested share link is invalid.', 404);
	}

	$share = psst_share_find_by_uuid($uuid);
	if (!$share) {
		psst_render_message('Share not found', 'The requested share does not exist.', 404);
	}

	$responseFormat = str_replace(' ', '+', (string) ($_GET['_format'] ?? ''));
	if ($responseFormat === 'application/validador-iti+json') {
		psst_render_iti_response($share);
	}

	psst_start_session();
	if (!psst_public_secret_allowed($share)) {
		psst_render_secret_prompt($share);
	}

	if ((bool) $share['is_encrypted'] && $share['type'] === 'file' && ($_GET['raw'] ?? '') === '1') {
		psst_send_file($share, false);
	}

	if ((bool) $share['is_encrypted']) {
		psst_render_encrypted_share($share);
	}

	psst_render_plain_share($share);
} catch (Throwable $exception) {
	psst_render_message('Server error', $exception->getMessage(), 500);
}

function psst_public_secret_allowed(array $share): bool
{
	$sessionKey = 'psst_access_' . $share['uuid'];
	$requiresGlobal = psst_global_secret_enabled();
	$requiresShare = !empty($share['secret_code_hash']);

	if (!$requiresGlobal && !$requiresShare) {
		return true;
	}

	if (!empty($_SESSION[$sessionKey])) {
		return true;
	}

	if (array_key_exists('_secretCode', $_GET)) {
		return psst_public_secret_matches($share, (string) $_GET['_secretCode'], true);
	}

	if (psst_method() !== 'POST') {
		return false;
	}

	if (!psst_rate_limit_check('failed_secret', (int) psst_config()['max_failed_secret_attempts_per_window'])) {
		psst_render_message('Too many attempts', 'Too many failed secret-code attempts. Try again later.', 429);
	}

	if (psst_public_secret_matches($share, (string) ($_POST['secret_code'] ?? ''), false)) {
		$_SESSION[$sessionKey] = true;
		return true;
	}

	psst_rate_limit_record('failed_secret');
	return false;
}

function psst_public_secret_matches(array $share, string $secretCode, bool $recordFailure): bool
{
	$requiresGlobal = psst_global_secret_enabled();
	$requiresShare = !empty($share['secret_code_hash']);
	$globalOk = !$requiresGlobal || psst_verify_global_secret($secretCode);
	$shareOk = !$requiresShare || psst_verify_secret($secretCode, $share['secret_code_hash']);

	if ($globalOk && $shareOk) {
		return true;
	}

	if ($recordFailure) {
		if (!psst_rate_limit_check('failed_secret', (int) psst_config()['max_failed_secret_attempts_per_window'])) {
			psst_render_message('Too many attempts', 'Too many failed secret-code attempts. Try again later.', 429);
		}

		psst_rate_limit_record('failed_secret');
	}

	return false;
}

function psst_render_iti_response(array $share): never
{
	$secretCode = (string) ($_GET['_secretCode'] ?? '');
	$valid = !empty($share['iti_secret_code']) && hash_equals((string) $share['iti_secret_code'], $secretCode);

	if (!$valid) {
		if (!psst_rate_limit_check('failed_secret', (int) psst_config()['max_failed_secret_attempts_per_window'])) {
			http_response_code(429);
			header('Content-Type: application/validador-iti+json; charset=utf-8');
			echo json_encode(['error' => 'Too many failed secret-code attempts.'], JSON_UNESCAPED_SLASHES);
			exit;
		}

		psst_rate_limit_record('failed_secret');
		http_response_code(403);
		header('Content-Type: application/validador-iti+json; charset=utf-8');
		echo json_encode(['error' => 'Invalid secret code.'], JSON_UNESCAPED_SLASHES);
		exit;
	}

	header('Content-Type: application/validador-iti+json; charset=utf-8');
	echo json_encode([
		'version' => '1.0.0',
		'prescription' => [
			'signatureFiles' => [
				['url' => psst_share_download_url($share['uuid'])],
			],
		],
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function psst_render_plain_share(array $share): never
{
	if ($share['type'] === 'link') {
		header('Location: ' . $share['content'], true, 302);
		exit;
	}

	if ($share['type'] === 'file') {
		psst_send_file($share, ($_GET['download'] ?? '') === '1');
	}

	header('Content-Type: text/plain; charset=utf-8');
	echo (string) $share['content'];
	exit;
}

function psst_send_file(array $share, bool $asAttachment): never
{
	if (empty($share['stored_filename'])) {
		psst_render_message('File not found', 'This share has no stored file.', 404);
	}

	$path = psst_stored_file_path($share['stored_filename']);
	if (!is_file($path)) {
		psst_render_message('File not found', 'The stored file is missing.', 404);
	}

	$filename = $share['original_filename'] ?: 'share-file';
	header('Content-Type: ' . ($share['mime_type'] ?: 'application/octet-stream'));
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline') . '; filename="' . addcslashes($filename, '"\\') . '"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;
}

function psst_render_secret_prompt(array $share): never
{
	$hasError = psst_method() === 'POST';
	$title = $share['title'] !== '' ? $share['title'] : 'Protected Share';
	http_response_code($hasError ? 403 : 200);
	header('Content-Type: text/html; charset=utf-8');
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= psst_html($title) ?></title>
	<link rel="stylesheet" href="css/bootstrap.min.css">
</head>
<body class="bg-light min-vh-100 d-flex align-items-center">
	<main class="container">
		<form class="mx-auto bg-white border rounded p-4" style="max-width: 420px" method="post">
			<h1 class="h4 mb-3"><?= psst_html($title) ?></h1>
			<?php if ($hasError): ?>
				<div class="alert alert-danger py-2">Invalid secret code.</div>
			<?php endif; ?>
			<label class="form-label" for="secret_code">Secret code</label>
			<input class="form-control mb-3" id="secret_code" name="secret_code" type="password" autocomplete="current-password" autofocus required>
			<button class="btn btn-primary w-100" type="submit">Open</button>
		</form>
	</main>
</body>
</html>
	<?php
	exit;
}

function psst_render_encrypted_share(array $share): never
{
	$publicShare = psst_public_share($share);
	header('Content-Type: text/html; charset=utf-8');
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= psst_html($share['title'] ?: 'Encrypted Share') ?></title>
	<link rel="stylesheet" href="css/bootstrap.min.css">
	<link rel="stylesheet" href="css/psst.css">
</head>
<body class="bg-light min-vh-100 d-flex align-items-center">
	<main class="container">
		<section id="encrypted-share" class="mx-auto bg-white border rounded p-4" style="max-width: 560px" data-share='<?= psst_html(json_encode($publicShare, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
			<h1 class="h4 mb-3"><?= psst_html($share['title'] ?: 'Encrypted Share') ?></h1>
			<label class="form-label" for="password">Password</label>
			<input class="form-control mb-3" id="password" type="password" minlength="8" autocomplete="current-password" required>
			<button class="btn btn-primary" id="decrypt-button" type="button">Decrypt</button>
			<div id="decrypt-alert" class="alert alert-danger mt-3 mb-0 d-none"></div>
			<div id="decrypted-output" class="mt-3 d-none"></div>
		</section>
	</main>
	<script src="js/bootstrap.bundle.min.js"></script>
	<script src="js/psst-crypto.js"></script>
	<script src="js/share-view.js"></script>
</body>
</html>
	<?php
	exit;
}

function psst_render_message(string $title, string $message, int $statusCode): never
{
	http_response_code($statusCode);
	header('Content-Type: text/html; charset=utf-8');
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= psst_html($title) ?></title>
	<link rel="stylesheet" href="css/bootstrap.min.css">
</head>
<body class="bg-light min-vh-100 d-flex align-items-center">
	<main class="container text-center">
		<h1 class="h3"><?= psst_html($title) ?></h1>
		<p class="text-muted mb-0"><?= psst_html($message) ?></p>
	</main>
</body>
</html>
	<?php
	exit;
}