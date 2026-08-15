<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

psst_configure_error_output();

try {
	if (psst_is_iti_cors_request() && psst_method() === 'OPTIONS') {
		psst_send_iti_cors_headers();
		header('Content-Length: 0');
		http_response_code(204);
		exit;
	}

	psst_enforce_rate_limit('request', (int) psst_config()['max_requests_per_window']);

	$uuid = psst_request_share_uuid();
	if (!psst_validate_uuid($uuid)) {
		psst_empty_response(404);
	}

	$share = psst_share_find_by_uuid($uuid);
	if (!$share) {
		psst_empty_response(404);
	}

	$responseFormat = str_replace(' ', '+', (string) ($_GET['_format'] ?? ''));
	if ($responseFormat === 'application/validador-iti+json') {
		psst_render_iti_response($share);
	}

	if ($share['type'] === 'upload') {
		if (psst_method() === 'POST' || psst_method() === 'PUT') {
			psst_handle_upload($share);
		}

		psst_render_upload_page($share);
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

function psst_request_share_uuid(): string
{
	$uuid = (string) ($_GET['id'] ?? '');
	if ($uuid !== '') {
		return $uuid;
	}

	$pathInfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
	$parts = explode('/', $pathInfo);

	return (string) ($parts[0] ?? '');
}

function psst_is_iti_cors_request()
{
	$responseFormat = str_replace(' ', '+', (string) ($_GET['_format'] ?? ''));

	return $responseFormat === 'application/validador-iti+json'
		|| ($_GET['type'] ?? '') === 'prescricao'
		|| ($_GET['iti_pdf'] ?? '') === '1';
}

function psst_send_iti_cors_headers()
{
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
	header('Access-Control-Allow-Headers: Accept, Content-Type');
	header('Access-Control-Expose-Headers: Content-Disposition, Content-Length, Content-Type');
	header('Access-Control-Max-Age: 600');
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

function psst_render_iti_response(array $share)
{
	psst_send_iti_cors_headers();

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
		http_response_code(401);
		header('Content-Type: application/validador-iti+json; charset=utf-8');
		echo json_encode(['error' => 'Invalid secret code.'], JSON_UNESCAPED_SLASHES);
		exit;
	}

	header('Content-Type: application/validador-iti+json; charset=utf-8');
	echo json_encode([
		'version' => '1.0.0',
		'prescription' => [
			'signatureFiles' => [
				['url' => psst_iti_pdf_url($share['uuid'])],
			],
		],
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function psst_render_plain_share(array $share)
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

function psst_send_file(array $share, bool $asAttachment)
{
	$isItiPdfDownload = ($_GET['iti_pdf'] ?? '') === '1';

	if (empty($share['stored_filename'])) {
		psst_render_message('File not found', 'This share has no stored file.', 404);
	}

	$path = psst_stored_file_path($share['stored_filename']);
	if (!is_file($path)) {
		psst_render_message('File not found', 'The stored file is missing.', 404);
	}

	if ($isItiPdfDownload) {
		psst_send_iti_cors_headers();
	}

	$filename = $isItiPdfDownload
		? $share['uuid'] . '.pdf'
		: ($share['original_filename'] ?: 'share-file');
	header('Content-Type: ' . ($share['mime_type'] ?: 'application/octet-stream'));
	header('Content-Length: ' . filesize($path));
	header('Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline') . '; filename="' . addcslashes($filename, '"\\') . '"');
	header('X-Content-Type-Options: nosniff');
	readfile($path);
	exit;
}

function psst_upload_respond(int $statusCode, string $message, array $extra = []): void
{
	psst_discard_output_buffers();

	if (!headers_sent()) {
		http_response_code($statusCode);
	}

	// The browser drop box posts with fetch() and expects JSON; curl/RouterOS get plain text.
	if (psst_wants_json()) {
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}

		$payload = array_merge(
			['ok' => $statusCode >= 200 && $statusCode < 300, 'message' => $message],
			$extra
		);

		if ($statusCode >= 400) {
			$payload['error'] = $message;
		}

		echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	if (!headers_sent()) {
		header('Content-Type: text/plain; charset=utf-8');
	}

	echo $message . "\n";
	exit;
}

function psst_handle_upload(array $share): void
{
	$requiredToken = (string) ($share['upload_token'] ?? '');
	if ($requiredToken !== '') {
		$providedToken = (string) ($_GET['t'] ?? ($_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? ''));
		if (!hash_equals($requiredToken, $providedToken)) {
			psst_upload_respond(403, 'Invalid or missing upload token.');
		}
	}

	$maxBytes = psst_max_upload_bytes();
	$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

	// Only pre-check raw bodies against the per-file limit. A multipart body carries
	// boundary overhead and may hold several files, so it is checked per file below.
	if ($maxBytes > 0 && $contentLength > $maxBytes && !psst_request_is_form_post()) {
		psst_upload_respond(413, psst_upload_limit_message(), ['max_upload_bytes' => $maxBytes]);
	}

	// PHP silently drops the whole body once post_max_size is exceeded, which used to
	// surface as a confusing "No file was received" / generic request failure.
	if (psst_post_body_too_large()) {
		psst_upload_respond(413, psst_upload_limit_message(), ['max_upload_bytes' => $maxBytes]);
	}

	$stored = [];
	$multipart = [];

	if (!empty($_FILES)) {
		foreach ($_FILES as $field) {
			$names = $field['name'];
			if (is_array($names)) {
				foreach (array_keys($names) as $index) {
					$multipart[] = [
						'name' => (string) $field['name'][$index],
						'type' => (string) $field['type'][$index],
						'tmp_name' => (string) $field['tmp_name'][$index],
						'error' => (int) $field['error'][$index],
						'size' => (int) $field['size'][$index],
					];
				}
			} else {
				$multipart[] = [
					'name' => (string) $field['name'],
					'type' => (string) $field['type'],
					'tmp_name' => (string) $field['tmp_name'],
					'error' => (int) $field['error'],
					'size' => (int) $field['size'],
				];
			}
		}
	}

	if ($multipart !== []) {
		foreach ($multipart as $file) {
			if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if ($file['error'] !== UPLOAD_ERR_OK) {
				$status = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
				psst_upload_respond($status, psst_upload_error_message((int) $file['error']));
			}
			if ($maxBytes > 0 && (int) $file['size'] > $maxBytes) {
				psst_upload_respond(413, psst_upload_limit_message(), ['max_upload_bytes' => $maxBytes]);
			}

			$originalName = psst_sanitize_filename($file['name'] !== '' ? $file['name'] : psst_upload_fallback_name());
			$record = psst_store_upload_file(
				$share,
				$file['tmp_name'],
				true,
				$originalName,
				$file['type'] !== '' ? $file['type'] : null
			);
			$stored[] = $record;
		}

		if ($stored === []) {
			psst_upload_respond(400, 'No file was received. Choose a file and try again.');
		}
	} else {
		$tmpPath = tempnam(sys_get_temp_dir(), 'psst_upload_');
		if ($tmpPath === false) {
			psst_upload_respond(500, 'Unable to buffer the upload.');
		}

		$input = fopen('php://input', 'rb');
		if ($input === false) {
			@unlink($tmpPath);
			psst_upload_respond(400, 'No request body was received.');
		}

		$out = fopen($tmpPath, 'wb');
		if ($out === false) {
			fclose($input);
			@unlink($tmpPath);
			psst_upload_respond(500, 'Unable to buffer the upload.');
		}

		$written = 0;
		while (!feof($input)) {
			$chunk = fread($input, 1 << 16);
			if ($chunk === false) {
				break;
			}
			$written += strlen($chunk);
			if ($maxBytes > 0 && $written > $maxBytes) {
				fclose($input);
				fclose($out);
				@unlink($tmpPath);
				psst_upload_respond(413, 'Upload exceeds the maximum allowed size.');
			}
			fwrite($out, $chunk);
		}
		fclose($input);
		fclose($out);

		if ($written === 0) {
			@unlink($tmpPath);
			// PHP consumes the request body into $_POST for urlencoded/multipart content
			// types, leaving php://input empty. Hint at the required header so clients
			// (e.g. MikroTik fetch) send the body as raw bytes instead.
			if (!empty($_POST)) {
				psst_upload_respond(400, 'Empty request body. Send raw data with header "Content-Type: application/octet-stream".');
			}
			if ($contentLength > 0) {
				psst_upload_respond(413, psst_upload_limit_message(), ['max_upload_bytes' => $maxBytes]);
			}
			psst_upload_respond(400, 'Empty request body.');
		}

		$originalName = psst_sanitize_filename(psst_upload_fallback_name());
		$mime = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
		$mime = $mime !== '' ? trim(explode(';', $mime)[0]) : null;
		$record = psst_store_upload_file($share, $tmpPath, false, $originalName, $mime);
		@unlink($tmpPath);
		$stored[] = $record;
	}

	$summary = [];
	$files = [];
	foreach ($stored as $file) {
		$summary[] = sprintf(
			'%s (%d bytes)',
			$file['original_filename'],
			(int) ($file['file_size'] ?? 0)
		);
		$files[] = [
			'name' => $file['original_filename'],
			'size' => (int) ($file['file_size'] ?? 0),
		];
	}

	psst_upload_respond(201, 'Upload received: ' . implode(', ', $summary), ['files' => $files]);
}

function psst_upload_fallback_name(): string
{
	$provided = (string) ($_GET['filename'] ?? '');
	if ($provided === '' && isset($_SERVER['HTTP_CONTENT_DISPOSITION'])) {
		if (preg_match('/filename="?([^";]+)"?/i', (string) $_SERVER['HTTP_CONTENT_DISPOSITION'], $matches) === 1) {
			$provided = $matches[1];
		}
	}

	if ($provided !== '') {
		return $provided;
	}

	return 'upload-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bin';
}

function psst_store_upload_file(array $share, string $sourcePath, bool $isUploadedFile, string $originalName, ?string $mimeType): array
{
	$fileUuid = psst_uuid();
	$storedFilename = $share['uuid'] . '-' . bin2hex(random_bytes(8)) . '-' . $originalName;
	$target = psst_stored_file_path($storedFilename);

	$moved = $isUploadedFile
		? move_uploaded_file($sourcePath, $target)
		: copy($sourcePath, $target);

	if (!$moved) {
		psst_upload_respond(500, 'Unable to store the uploaded file.');
	}

	return psst_upload_file_create([
		'uuid' => $fileUuid,
		'share_id' => (int) $share['id'],
		'original_filename' => $originalName,
		'stored_filename' => $storedFilename,
		'mime_type' => $mimeType ?: 'application/octet-stream',
		'file_size' => filesize($target) ?: 0,
		'remote_ip' => psst_client_ip(),
	]);
}

function psst_render_upload_page(array $share): void
{
	$title = $share['title'] !== '' ? $share['title'] : 'Upload';
	$token = (string) ($share['upload_token'] ?? '');
	$providedToken = (string) ($_GET['t'] ?? '');
	$tokenForUrl = $token !== '' ? ($providedToken !== '' ? $providedToken : null) : null;
	$uploadUrl = psst_upload_url($share['uuid'], $tokenForUrl);
	// Post back to the host the visitor actually used, not the configured base_url.
	$formAction = psst_upload_form_action($share['uuid'], $tokenForUrl);
	$requiresToken = $token !== '';
	$maxBytes = psst_max_upload_bytes();

	$curlMultipart = sprintf('curl -F "file=@/path/to/file" "%s"', $uploadUrl);
	$curlRaw = sprintf('curl --data-binary @/path/to/file "%s&filename=file"', $uploadUrl);
	$mikrotik = sprintf(
		'/export file=psst; :delay 2s; /tool fetch http-method=post http-header-field="Content-Type: application/octet-stream" url="%s&filename=psst.rsc" http-data=[/file get psst.rsc contents]',
		$uploadUrl
	);

	header('Content-Type: text/html; charset=utf-8');
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= psst_html($title) ?></title>
	<link rel="stylesheet" href="css/bootstrap.min.css">
	<link rel="stylesheet" href="<?= psst_html(psst_asset('css/psst.css')) ?>">
</head>
<body class="bg-light min-vh-100 py-4">
	<main class="container" style="max-width: 720px">
		<section class="bg-white border rounded p-3 p-sm-4">
			<h1 class="h4 mb-3"><?= psst_html($title) ?></h1>
			<p class="text-muted">Upload a file to this drop box. You can use the form below or one of the command-line snippets.</p>
			<?php if ($requiresToken && $providedToken === ''): ?>
				<div class="alert alert-warning py-2">This drop box requires an upload token. Append <code>&amp;t=YOUR_TOKEN</code> to the URL.</div>
			<?php endif; ?>

			<form id="upload-form" method="post" enctype="multipart/form-data" action="<?= psst_html($formAction) ?>"
				class="mb-4" data-max-bytes="<?= (int) $maxBytes ?>">
				<label class="form-label" for="file">File</label>
				<input class="form-control mb-2" id="file" name="file" type="file" multiple required>
				<div class="form-text mb-3" id="upload-hint">Maximum size: <?= psst_html(psst_format_bytes($maxBytes)) ?> per file.</div>
				<div class="progress mb-3 d-none" id="upload-progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100">
					<div class="progress-bar progress-bar-striped progress-bar-animated" id="upload-progress-bar" style="width: 0%">0%</div>
				</div>
				<button class="btn btn-primary w-100 w-sm-auto" id="upload-button" type="submit">Upload</button>
				<div class="alert mt-3 mb-0 d-none" id="upload-status" role="status"></div>
			</form>

			<h2 class="h6">Linux (multipart)</h2>
			<pre class="bg-light border rounded p-2 small"><code><?= psst_html($curlMultipart) ?></code></pre>

			<h2 class="h6">Linux (raw body)</h2>
			<pre class="bg-light border rounded p-2 small"><code><?= psst_html($curlRaw) ?></code></pre>

			<h2 class="h6">MikroTik RouterOS</h2>
			<pre class="bg-light border rounded p-2 small"><code><?= psst_html($mikrotik) ?></code></pre>
		</section>

		<?php psst_render_footer('mt-3'); ?>
	</main>
	<script src="<?= psst_html(psst_asset('js/upload-form.js')) ?>"></script>
</body>
</html>
	<?php
	exit;
}


function psst_render_secret_prompt(array $share)
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
	<link rel="stylesheet" href="<?= psst_html(psst_asset('css/psst.css')) ?>">
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

		<?php psst_render_footer('mt-3'); ?>
	</main>
</body>
</html>
	<?php
	exit;
}

function psst_render_encrypted_share(array $share)
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
	<link rel="stylesheet" href="<?= psst_html(psst_asset('css/psst.css')) ?>">
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

		<?php psst_render_footer('mt-3'); ?>
	</main>
	<script src="js/bootstrap.bundle.min.js"></script>
	<script src="<?= psst_html(psst_asset('js/psst-crypto.js')) ?>"></script>
	<script src="<?= psst_html(psst_asset('js/share-view.js')) ?>"></script>
</body>
</html>
	<?php
	exit;
}

function psst_render_message(string $title, string $message, int $statusCode)
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
	<link rel="stylesheet" href="<?= psst_html(psst_asset('css/psst.css')) ?>">
</head>
<body class="bg-light min-vh-100 d-flex align-items-center">
	<main class="container text-center">
		<h1 class="h3"><?= psst_html($title) ?></h1>
		<p class="text-muted"><?= psst_html($message) ?></p>

		<?php psst_render_footer(); ?>
	</main>
</body>
</html>
	<?php
	exit;
}