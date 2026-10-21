<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

psst_configure_error_output();
// Shares can be secret or single-use; browsers and proxies must not keep a copy.
header('Cache-Control: no-store');

try {
	if (psst_is_iti_cors_request() && psst_method() === 'OPTIONS') {
		psst_send_iti_cors_headers();
		header('Content-Length: 0');
		http_response_code(204);
		exit;
	}

	psst_enforce_rate_limit('request', (int) psst_config()['max_requests_per_window']);
	psst_share_cleanup_unavailable();

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
		if (psst_share_is_expired($share)) {
			psst_render_upload_closed();
		}

		if (psst_method() === 'POST' || psst_method() === 'PUT') {
			psst_handle_upload($share);
		}

		psst_render_upload_page($share);
	}

	psst_start_session();
	if (!psst_public_secret_allowed($share)) {
		psst_render_secret_prompt($share);
	}

	if ((bool) $share['is_encrypted']) {
		if (($_GET['raw'] ?? '') === '1') {
			psst_send_encrypted_payload($share);
		}

		psst_render_encrypted_share($share);
	}

	// View-limited shares open only on an explicit click (POST), so link previews and
	// scanners that fetch the URL do not use up a view. The ITI validator cannot click.
	$isItiPdf = ($_GET['iti_pdf'] ?? '') === '1';
	if ($share['max_views'] !== null && psst_method() !== 'POST' && !$isItiPdf) {
		psst_render_reveal_page($share);
	}

	psst_render_plain_share($share);
} catch (Throwable $exception) {
	// Details go to the server log only; they can contain paths and SQL.
	error_log('PSST index: ' . $exception);
	psst_render_message('Server error', 'Something went wrong. Try again later.', 500);
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

/**
 * Counts a view before content is sent; HEAD requests only probe and are not counted.
 * When this is the last allowed view, the share is deleted once the response is out.
 */
function psst_register_view(array $share): bool
{
	if (psst_method() === 'HEAD') {
		return true;
	}

	if (!psst_share_consume_view((int) $share['id'])) {
		return false;
	}

	if ($share['max_views'] !== null && (int) $share['view_count'] + 1 >= (int) $share['max_views']) {
		register_shutdown_function('psst_share_delete', (string) $share['uuid']);
	}

	return true;
}

function psst_render_plain_share(array $share)
{
	if (!psst_register_view($share)) {
		psst_render_gone();
	}

	if ($share['type'] === 'link') {
		// 303 turns the reveal POST into a plain GET of the target.
		header('Location: ' . $share['content'], true, psst_method() === 'POST' ? 303 : 302);
		exit;
	}

	if ($share['type'] === 'file') {
		// A view-limited file is downloaded: an inline view would be lost on reload.
		$asAttachment = ($_GET['download'] ?? '') === '1' || $share['max_views'] !== null;
		psst_send_file($share, $asAttachment);
	}

	if ($share['type'] === 'markdown') {
		if (($_GET['download'] ?? '') === '1') {
			$content = (string) $share['content'];
			psst_send_file_headers('text/markdown', psst_markdown_filename($share), strlen($content), false);
			echo $content;
			exit;
		}

		psst_render_markdown_share($share);
	}

	if (psst_method() === 'POST') {
		psst_render_revealed_text($share);
	}

	header('Content-Type: text/plain; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
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
	psst_send_file_headers($share['mime_type'], $filename, (int) filesize($path), !$asAttachment);
	readfile($path);
	exit;
}

/**
 * Ciphertext for the decrypt page. Fetching it is what counts as a view, so the page
 * itself can be reloaded or previewed without using one up.
 */
function psst_send_encrypted_payload(array $share)
{
	if (!psst_register_view($share)) {
		http_response_code(410);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'This share is no longer available.';
		exit;
	}

	if ($share['type'] === 'file') {
		$path = $share['stored_filename'] ? psst_stored_file_path($share['stored_filename']) : '';
		if ($path === '' || !is_file($path)) {
			psst_empty_response(404);
		}

		psst_send_file_headers('application/octet-stream', $share['uuid'] . '.psst', (int) filesize($path), false);
		readfile($path);
		exit;
	}

	header('Content-Type: text/plain; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	echo (string) $share['content'];
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

function psst_upload_quota_message(int $remaining): string
{
	return $remaining > 0
		? sprintf('Not enough space left in this drop box. %s remaining.', psst_format_bytes($remaining))
		: 'This drop box is full.';
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
	$remaining = psst_upload_remaining_bytes($share);
	$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

	if ($remaining === 0) {
		psst_upload_respond(413, psst_upload_quota_message(0), ['remaining_bytes' => 0]);
	}

	// Only pre-check raw bodies against the per-file limit. A multipart body carries
	// boundary overhead and may hold several files, so it is checked per file below.
	if ($maxBytes > 0 && $contentLength > $maxBytes && !psst_request_is_form_post()) {
		psst_upload_respond(413, psst_upload_limit_message(), ['max_upload_bytes' => $maxBytes]);
	}

	if ($remaining !== null && $contentLength > $remaining && !psst_request_is_form_post()) {
		psst_upload_respond(413, psst_upload_quota_message($remaining), ['remaining_bytes' => $remaining]);
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
		$accepted = [];
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

			$accepted[] = $file;
		}

		if ($accepted === []) {
			psst_upload_respond(400, 'No file was received. Choose a file and try again.');
		}

		// All or nothing: a batch that does not fit is rejected before anything is stored.
		$total = array_sum(array_column($accepted, 'size'));
		if ($remaining !== null && $total > $remaining) {
			psst_upload_respond(413, psst_upload_quota_message($remaining), ['remaining_bytes' => $remaining]);
		}

		foreach ($accepted as $file) {
			$originalName = psst_sanitize_filename($file['name'] !== '' ? $file['name'] : psst_upload_fallback_name());
			$stored[] = psst_store_upload_file(
				$share,
				$file['tmp_name'],
				true,
				$originalName,
				$file['type'] !== '' ? $file['type'] : null
			);
		}
	} else {
		$limit = $maxBytes;
		$quotaLimited = $remaining !== null && ($maxBytes === 0 || $remaining < $maxBytes);
		if ($quotaLimited) {
			$limit = $remaining;
		}

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
			if ($limit > 0 && $written > $limit) {
				fclose($input);
				fclose($out);
				@unlink($tmpPath);
				if ($quotaLimited) {
					psst_upload_respond(413, psst_upload_quota_message((int) $remaining), ['remaining_bytes' => $remaining]);
				}
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

	psst_upload_respond(201, 'Upload received: ' . implode(', ', $summary), [
		'files' => $files,
		'remaining_bytes' => psst_upload_remaining_bytes($share),
	]);
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
		'mime_type' => psst_safe_mime($mimeType),
		'file_size' => filesize($target) ?: 0,
		'remote_ip' => psst_client_ip(),
	]);
}

function psst_format_local_time(int $timestamp): string
{
	return date('j M Y, H:i T', $timestamp);
}

function psst_views_phrase(int $views): string
{
	return $views === 1 ? 'one more time' : $views . ' more times';
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
	$remaining = psst_upload_remaining_bytes($share);
	$isFull = $remaining === 0;

	$curlMultipart = sprintf('curl -F "file=@/path/to/file" "%s"', $uploadUrl);
	$curlRaw = sprintf('curl --data-binary @/path/to/file "%s&filename=file"', $uploadUrl);
	$mikrotik = sprintf(
		'/export file=psst; :delay 2s; /tool fetch http-method=post http-header-field="Content-Type: application/octet-stream" url="%s&filename=psst.rsc" http-data=[/file get psst.rsc contents]',
		$uploadUrl
	);

	psst_page_start($title, 'bg-light min-vh-100 py-4');
	?>
	<main class="container" style="max-width: 720px">
		<section class="bg-white border rounded p-3 p-sm-4">
			<h1 class="h4 mb-3"><?= psst_html($title) ?></h1>
			<p class="text-muted">Upload a file to this drop box. You can use the form below or one of the command-line snippets.</p>
			<?php if ($share['expires_at'] !== null): ?>
				<p class="small text-muted">This drop box accepts files until <?= psst_html(psst_format_local_time((int) $share['expires_at'])) ?>.</p>
			<?php endif; ?>
			<?php if ($requiresToken && $providedToken === ''): ?>
				<div class="alert alert-warning py-2">This drop box requires an upload token. Append <code>&amp;t=YOUR_TOKEN</code> to the URL.</div>
			<?php endif; ?>
			<?php if ($isFull): ?>
				<div class="alert alert-warning py-2">This drop box is full and cannot accept more files.</div>
			<?php endif; ?>

			<form id="upload-form" method="post" enctype="multipart/form-data" action="<?= psst_html($formAction) ?>"
				class="mb-4" data-max-bytes="<?= (int) $maxBytes ?>" data-remaining-bytes="<?= $remaining === null ? '' : (int) $remaining ?>">
				<label class="form-label" for="file">File</label>
				<input class="form-control mb-2" id="file" name="file" type="file" multiple required<?= $isFull ? ' disabled' : '' ?>>
				<div class="form-text mb-3" id="upload-hint">
					Maximum size: <?= psst_html(psst_format_bytes($maxBytes)) ?> per file.
					<?php if ($remaining !== null): ?>
						<span id="upload-space">Space left: <?= psst_html($remaining > 0 ? psst_format_bytes($remaining) : '0 B') ?>.</span>
					<?php endif; ?>
				</div>
				<div class="progress mb-3 d-none" id="upload-progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100">
					<div class="progress-bar progress-bar-striped progress-bar-animated" id="upload-progress-bar" style="width: 0%">0%</div>
				</div>
				<button class="btn btn-primary w-100 w-sm-auto" id="upload-button" type="submit"<?= $isFull ? ' disabled' : '' ?>>Upload</button>
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
	<?php
	psst_page_end(['js/upload-form.js']);
}

function psst_render_upload_closed()
{
	if (psst_method() === 'POST' || psst_method() === 'PUT') {
		psst_upload_respond(410, 'This drop box is closed and no longer accepts files.');
	}

	psst_render_message('Drop box closed', 'This drop box no longer accepts files.', 410);
}

function psst_render_secret_prompt(array $share)
{
	$hasError = psst_method() === 'POST';
	$title = $share['title'] !== '' ? $share['title'] : 'Protected Share';
	http_response_code($hasError ? 403 : 200);
	psst_page_start($title);
	?>
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
	<?php
	psst_page_end();
}

/**
 * Landing page for view-limited shares: the content is only sent after a click.
 */
function psst_render_reveal_page(array $share)
{
	$labels = ['text' => 'Show text', 'markdown' => 'Show document', 'link' => 'Open link', 'file' => 'Download file'];
	$title = $share['title'] !== '' ? $share['title'] : ($share['type'] === 'markdown' ? 'Shared document' : 'Shared ' . $share['type']);
	$remaining = (int) psst_share_views_remaining($share);

	psst_page_start($title);
	?>
	<main class="container">
		<form class="mx-auto bg-white border rounded p-4" style="max-width: 480px" method="post">
			<h1 class="h4 mb-3"><?= psst_html($title) ?></h1>
			<?php if ($share['type'] === 'file'): ?>
				<p class="mb-2 text-break"><span class="fw-semibold"><?= psst_html((string) ($share['original_filename'] ?: 'File')) ?></span>
					<span class="text-muted">(<?= psst_html(psst_format_bytes((int) $share['file_size'])) ?>)</span></p>
			<?php endif; ?>
			<p class="text-muted small mb-3">
				This share can be opened <?= psst_html(psst_views_phrase($remaining)) ?>. After that it is deleted.
				<?php if ($share['expires_at'] !== null): ?>
					It expires on <?= psst_html(psst_format_local_time((int) $share['expires_at'])) ?>.
				<?php endif; ?>
			</p>
			<input type="hidden" name="reveal" value="1">
			<button class="btn btn-primary w-100" type="submit"><?= psst_html($labels[$share['type']] ?? 'Open') ?></button>
		</form>

		<?php psst_render_footer('mt-3'); ?>
	</main>
	<?php
	psst_page_end();
}

function psst_render_revealed_text(array $share)
{
	$title = $share['title'] !== '' ? $share['title'] : 'Shared text';
	$remaining = psst_share_views_remaining($share);
	$remainingAfter = $remaining === null ? null : max(0, $remaining - 1);

	psst_page_start($title, 'bg-light min-vh-100 py-4');
	?>
	<main class="container" style="max-width: 720px">
		<section class="bg-white border rounded p-3 p-sm-4">
			<div class="d-flex align-items-center justify-content-between gap-2 mb-3">
				<h1 class="h4 mb-0 text-break"><?= psst_html($title) ?></h1>
				<button class="btn btn-sm btn-outline-secondary flex-shrink-0" type="button" data-copy-target="shared-text">Copy</button>
			</div>
			<pre id="shared-text" class="decrypted-text border rounded p-3 bg-light mb-0"><?= psst_html((string) $share['content']) ?></pre>
			<?php if ($remainingAfter === 0): ?>
				<div class="alert alert-warning mt-3 mb-0">This was the last view. The text has been deleted from the server, so copy it now if you need it.</div>
			<?php elseif ($remainingAfter !== null): ?>
				<p class="small text-muted mt-3 mb-0">This share can be opened <?= psst_html(psst_views_phrase($remainingAfter)) ?>.</p>
			<?php endif; ?>
		</section>

		<?php psst_render_footer('mt-3'); ?>
	</main>
	<?php
	psst_page_end(['js/share-view.js']);
}

function psst_render_markdown_share(array $share)
{
	$title = $share['title'] !== '' ? $share['title'] : 'Shared document';
	$remaining = psst_share_views_remaining($share);
	$remainingAfter = $remaining === null ? null : max(0, $remaining - 1);

	psst_send_markdown_page_headers();
	psst_page_start($title, 'bg-light min-vh-100 py-4');
	?>
	<main class="container psst-page-wide">
		<?php /* The source comes first, so a heading id in the document can never shadow it. */ ?>
		<textarea id="psst-markdown-source" class="d-none" readonly aria-hidden="true"><?= psst_html((string) $share['content']) ?></textarea>
		<section class="bg-white border rounded p-3 p-sm-4">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom">
				<h1 class="h5 mb-0 text-break"><?= psst_html($title) ?></h1>
				<div class="d-flex gap-2 flex-shrink-0">
					<button class="btn btn-sm btn-outline-secondary" type="button" data-copy-target="psst-markdown-source">Copy Markdown</button>
					<button class="btn btn-sm btn-outline-secondary" type="button" data-download-source="psst-markdown-source" data-filename="<?= psst_html(psst_markdown_filename($share)) ?>">Download .md</button>
				</div>
			</div>
			<article class="psst-markdown"><?= psst_markdown((string) $share['content']) ?></article>
			<?php if ($remainingAfter === 0): ?>
				<div class="alert alert-warning mt-3 mb-0">This was the last view. The document has been deleted from the server, so download it now if you need it.</div>
			<?php elseif ($remainingAfter !== null): ?>
				<p class="small text-muted mt-3 mb-0">This share can be opened <?= psst_html(psst_views_phrase($remainingAfter)) ?>.</p>
			<?php endif; ?>
		</section>

		<?php psst_render_footer('mt-3'); ?>
	</main>
	<?php
	psst_page_end(['js/share-view.js']);
}

function psst_render_encrypted_share(array $share)
{
	$publicShare = psst_public_share($share);
	$title = $share['title'] ?: 'Encrypted Share';
	$remaining = $publicShare['views_remaining'];

	psst_page_start($title);
	?>
	<main class="container">
		<form id="encrypted-share" class="mx-auto bg-white border rounded p-4" style="max-width: 560px" data-share='<?= psst_html((string) json_encode($publicShare, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
			<h1 class="h4 mb-3 text-break"><?= psst_html($title) ?></h1>
			<p class="small text-muted">This share is encrypted. Enter the password you received from the sender.</p>
			<?php if ($remaining !== null): ?>
				<div class="alert alert-warning small py-2">
					Decrypting counts as a view. This share can be opened <?= psst_html(psst_views_phrase((int) $remaining)) ?>; keep this page open if you need to retry the password.
				</div>
			<?php endif; ?>
			<label class="form-label" for="password">Password</label>
			<div class="input-group mb-3">
				<input class="form-control" id="password" type="password" minlength="8" autocomplete="off" autofocus required>
				<button class="btn btn-outline-secondary" id="toggle-password" type="button">Show</button>
			</div>
			<button class="btn btn-primary w-100" id="decrypt-button" type="submit">Decrypt</button>
			<div id="decrypt-alert" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
			<div id="decrypted-output" class="mt-3 d-none"></div>
		</form>

		<?php psst_render_footer('mt-3'); ?>
	</main>
	<?php
	psst_page_end(['js/psst-crypto.js', 'js/share-view.js']);
}

function psst_render_gone()
{
	psst_render_message('Share unavailable', 'This share has expired or was already opened as often as allowed.', 410);
}

function psst_render_message(string $title, string $message, int $statusCode)
{
	http_response_code($statusCode);
	psst_page_start($title);
	?>
	<main class="container text-center">
		<h1 class="h3"><?= psst_html($title) ?></h1>
		<p class="text-muted"><?= psst_html($message) ?></p>

		<?php psst_render_footer(); ?>
	</main>
	<?php
	psst_page_end();
}
