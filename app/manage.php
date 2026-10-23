<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>PSST</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= psst_html(psst_asset('css/psst.css')) ?>">
</head>
<body class="bg-light" data-base-url="<?= psst_html(psst_base_url()) ?>">
    <div id="app" class="container-fluid py-3 py-lg-4">
        <section id="login-view" class="auth-shell mx-auto bg-white border rounded p-4">
            <h1 class="h4 mb-3">PSST</h1>
            <form id="login-form">
                <label class="form-label" for="login-username">Username</label>
                <input class="form-control mb-3" id="login-username" name="username" autocomplete="username" required>
                <label class="form-label" for="login-password">Password</label>
                <input class="form-control mb-3" id="login-password" name="password" type="password" autocomplete="current-password" required>
                <label class="form-label" for="login-htp">HTP</label>
                <input class="form-control" id="login-htp" name="htp" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="6 digits" autocomplete="off" required>
                <div class="form-text mb-3">Each HTP number works only once.</div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
                <div id="login-error" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
            </form>
        </section>

        <main id="manage-view" class="d-none">
            <header class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <div>
                    <h1 class="h3 mb-0">PSST</h1>
                    <div class="text-muted small" id="share-count">0 shares</div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" id="api-button" type="button">API</button>
                    <button class="btn btn-outline-secondary" id="refresh-button" type="button">Refresh</button>
                    <button class="btn btn-outline-danger" id="logout-button" type="button">Sign out</button>
                </div>
            </header>

            <div id="app-alert" class="alert d-none" role="status"></div>

            <div id="share-result" class="alert alert-success d-none" role="status">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div class="fw-semibold" id="share-result-title">Share created</div>
                    <button type="button" class="btn-close" id="share-result-close" aria-label="Close"></button>
                </div>
                <div class="input-group">
                    <input class="form-control" id="share-result-url" readonly aria-label="Share link">
                    <button class="btn btn-outline-secondary" id="share-result-copy" type="button">Copy</button>
                    <button class="btn btn-outline-secondary" id="share-result-qr" type="button">QR</button>
                </div>
                <div class="input-group mt-2 d-none" id="share-result-editor">
                    <span class="input-group-text">Editor link</span>
                    <input class="form-control" id="share-result-editor-url" readonly aria-label="Editor link">
                    <button class="btn btn-outline-secondary" data-copy-target="share-result-editor-url" type="button">Copy</button>
                </div>
                <div class="small mt-2 d-none" id="share-result-note"></div>
            </div>

            <div class="row g-3">
                <section class="col-12 col-xl-4">
                    <form id="share-form" class="bg-white border rounded p-3" enctype="multipart/form-data" novalidate>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h2 class="h5 mb-0" id="form-title">New share</h2>
                            <button class="btn btn-sm btn-outline-secondary d-none" id="cancel-edit-button" type="button">Cancel</button>
                        </div>
                        <input type="hidden" id="share-uuid">

                        <label class="form-label" for="share-type">Type</label>
                        <select class="form-select mb-3" id="share-type" name="type">
                            <option value="text">Text</option>
                            <option value="markdown">Markdown</option>
                            <option value="link">Link</option>
                            <option value="file">File</option>
                            <option value="upload">Upload (drop box)</option>
                            <option value="site">Site (HTML page)</option>
                        </select>

                        <label class="form-label" for="share-title">Title</label>
                        <input class="form-control mb-3" id="share-title" name="title" maxlength="160" placeholder="Optional">

                        <div id="text-fields" class="mb-3">
                            <label class="form-label" for="share-text" id="share-text-label">Text</label>
                            <textarea class="form-control" id="share-text" rows="7"></textarea>
                            <div class="form-text d-none" id="markdown-hint">Shown as a formatted document. Raw HTML is displayed as text; Markdown shares cannot be encrypted.</div>
                        </div>

                        <div id="link-fields" class="d-none">
                            <label class="form-label" for="share-link">URL</label>
                            <input class="form-control mb-3" id="share-link" type="url" placeholder="https://example.com">
                        </div>

                        <div id="file-fields" class="d-none">
                            <label class="form-label" for="share-file">File</label>
                            <input class="form-control" id="share-file" type="file">
                            <div class="form-text mb-3" id="share-file-hint"></div>
                        </div>

                        <div id="upload-fields" class="d-none">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" id="share-upload-token" type="checkbox" checked>
                                <label class="form-check-label" for="share-upload-token">Require an upload token</label>
                            </div>
                            <label class="form-label" for="share-upload-quota">Storage limit (MB)</label>
                            <input class="form-control" id="share-upload-quota" type="number" min="1" step="1" inputmode="numeric" placeholder="No limit">
                            <div class="form-text mb-3">Total size of all received files. Leave empty for no limit.</div>
                            <div id="upload-info" class="d-none">
                                <label class="form-label">Storage used</label>
                                <div class="progress mb-1" role="progressbar" aria-label="Storage used" aria-valuemin="0" aria-valuemax="100" id="upload-usage">
                                    <div class="progress-bar" id="upload-usage-bar" style="width: 0%"></div>
                                </div>
                                <div class="small text-muted mb-3" id="upload-usage-text"></div>
                                <label class="form-label" for="upload-url">Upload URL</label>
                                <div class="input-group mb-3">
                                    <input class="form-control" id="upload-url" readonly>
                                    <button class="btn btn-outline-secondary" id="copy-upload-url" type="button">Copy</button>
                                </div>
                                <details class="mb-3">
                                    <summary class="form-label">Command-line one-liners</summary>
                                    <div class="mt-2 mb-2">
                                        <div class="small text-muted">Linux (multipart)</div>
                                        <div class="input-group">
                                            <input class="form-control font-monospace small" id="cmd-curl-multipart" readonly>
                                            <button class="btn btn-outline-secondary" data-copy-target="cmd-curl-multipart" type="button">Copy</button>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <div class="small text-muted">Linux (raw body)</div>
                                        <div class="input-group">
                                            <input class="form-control font-monospace small" id="cmd-curl-raw" readonly>
                                            <button class="btn btn-outline-secondary" data-copy-target="cmd-curl-raw" type="button">Copy</button>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-muted">MikroTik RouterOS</div>
                                        <div class="input-group">
                                            <input class="form-control font-monospace small" id="cmd-mikrotik" readonly>
                                            <button class="btn btn-outline-secondary" data-copy-target="cmd-mikrotik" type="button">Copy</button>
                                        </div>
                                    </div>
                                </details>
                                <label class="form-label">Received files (<span id="upload-file-count">0</span>)</label>
                                <ul class="list-group mb-3" id="upload-file-list">
                                    <li class="list-group-item text-muted small">No files received yet.</li>
                                </ul>
                            </div>
                        </div>

                        <div id="site-fields" class="d-none">
                            <label class="form-label" for="share-site-file">HTML file</label>
                            <input class="form-control" id="share-site-file" type="file" accept=".html,.htm,text/html">
                            <div class="form-text" id="site-file-hint">One self-contained .html file.</div>
                            <details class="mb-3" id="site-html-details">
                                <summary class="small">Or paste the HTML</summary>
                                <textarea class="form-control font-monospace small mt-2" id="share-site-html" rows="6" spellcheck="false"></textarea>
                            </details>

                            <label class="form-label" for="share-storage-mode">Storage</label>
                            <select class="form-select" id="share-storage-mode">
                                <option value="off">Off</option>
                                <option value="read">Read-only: visitors read, the editor link writes</option>
                                <option value="shared">Shared: every visitor reads and writes</option>
                                <option value="visitor">Per visitor: everyone gets their own storage</option>
                            </select>
                            <div class="form-text mb-2" id="storage-mode-hint"></div>

                            <div class="row g-2 mb-2" id="storage-limit-fields">
                                <div class="col-6" id="storage-quota-field">
                                    <label class="form-label small mb-1" for="share-storage-quota">Storage limit (KB)</label>
                                    <input class="form-control form-control-sm" id="share-storage-quota" type="number" min="1" step="1" inputmode="numeric" placeholder="No limit">
                                </div>
                                <div class="col-6 visitor-field">
                                    <label class="form-label small mb-1" for="share-visitor-quota">Per visitor (KB)</label>
                                    <input class="form-control form-control-sm" id="share-visitor-quota" type="number" min="1" step="1" inputmode="numeric" placeholder="No limit">
                                </div>
                                <div class="col-6 visitor-field">
                                    <label class="form-label small mb-1" for="share-visitor-total">All visitors (MB)</label>
                                    <input class="form-control form-control-sm" id="share-visitor-total" type="number" min="1" step="1" inputmode="numeric" placeholder="No limit">
                                </div>
                                <div class="col-6 visitor-field">
                                    <label class="form-label small mb-1" for="share-visitor-days">Remember visitors (days)</label>
                                    <input class="form-control form-control-sm" id="share-visitor-days" type="number" min="0" max="3650" step="1" inputmode="numeric">
                                </div>
                            </div>
                            <div class="form-text mb-3" id="storage-note">
                                Storage is not encrypted. If a site stores personal data, tell its visitors.
                                <a href="api-docs.php?doc=sites" target="_blank" rel="noopener">How sites use storage</a>
                            </div>

                            <div id="site-info" class="d-none mb-3">
                                <label class="form-label" for="site-editor-url">Editor link</label>
                                <div class="input-group mb-1">
                                    <input class="form-control" id="site-editor-url" readonly>
                                    <button class="btn btn-outline-secondary" data-copy-target="site-editor-url" type="button">Copy</button>
                                </div>
                                <div class="form-text">Opens the site with write access in read-only mode. Keep it to yourself.</div>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3" id="encrypt-switch-row">
                            <input class="form-check-input" id="share-encrypted" type="checkbox">
                            <label class="form-check-label" for="share-encrypted">Encrypt in browser</label>
                        </div>

                        <div id="password-fields" class="d-none">
                            <label class="form-label" for="share-password">Encryption password</label>
                            <div class="input-group">
                                <input class="form-control" id="share-password" type="password" minlength="8" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" id="toggle-share-password" type="button">Show</button>
                                <button class="btn btn-outline-secondary" id="generate-share-password" type="button">Generate</button>
                            </div>
                            <div class="form-text mb-3" id="password-hint">At least 8 characters. Send it to the recipient separately; the server never sees it.</div>
                        </div>

                        <div id="secret-fields" class="mb-3">
                            <label class="form-label" for="secret-code">Secret code</label>
                            <input class="form-control" id="secret-code" autocomplete="off" placeholder="None">
                            <div class="form-text" id="secret-code-hint">Visitors must enter this code before the share opens.</div>
                            <div class="form-check mt-1 d-none" id="remove-secret-row">
                                <input class="form-check-input" id="remove-secret-code" type="checkbox">
                                <label class="form-check-label" for="remove-secret-code">Remove the current secret code</label>
                            </div>
                        </div>

                        <div class="row g-2 mb-1">
                            <div class="col-12 col-sm-6 col-xl-12 col-xxl-6">
                                <label class="form-label" for="share-expiry" id="share-expiry-label">Expires</label>
                                <select class="form-select" id="share-expiry">
                                    <option value="never">Never</option>
                                    <option value="3600">In 1 hour</option>
                                    <option value="86400">In 1 day</option>
                                    <option value="604800">In 7 days</option>
                                    <option value="2592000">In 30 days</option>
                                    <option value="custom">Pick a date…</option>
                                </select>
                                <input class="form-control mt-2 d-none" id="share-expiry-custom" type="datetime-local" aria-label="Expiry date">
                            </div>
                            <div class="col-12 col-sm-6 col-xl-12 col-xxl-6" id="max-views-field">
                                <label class="form-label" for="share-max-views">View limit</label>
                                <input class="form-control" id="share-max-views" type="number" min="1" step="1" inputmode="numeric" placeholder="Unlimited">
                            </div>
                        </div>
                        <div class="form-text mb-3" id="limit-hint"></div>

                        <button class="btn btn-primary w-100" id="save-button" type="submit">Create share</button>
                    </form>
                </section>

                <section class="col-12 col-xl-8">
                    <div class="bg-white border rounded overflow-hidden">
                        <div class="p-2 border-bottom">
                            <input class="form-control form-control-sm" id="share-filter" type="search" placeholder="Filter by title, link or type" aria-label="Filter shares">
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Share</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="shares-table">
                                    <tr><td colspan="4" class="text-muted text-center py-4">No shares yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <div class="modal fade" id="qr-modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5">QR code</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="btn-group w-100 mb-3" role="group" aria-label="QR mode">
                                <input type="radio" class="btn-check" name="qr-mode" id="qr-standard" value="standard" autocomplete="off" checked>
                                <label class="btn btn-outline-primary" for="qr-standard">Standard</label>
                                <input type="radio" class="btn-check" name="qr-mode" id="qr-iti" value="iti" autocomplete="off">
                                <label class="btn btn-outline-primary" for="qr-iti">ITI</label>
                            </div>
                            <div id="qr-target" class="qr-target border rounded p-3 mb-3"></div>
                            <input class="form-control" id="qr-url" readonly>
                            <div id="qr-iti-code" class="small text-muted mt-2 d-none"></div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" id="copy-qr-url-button" type="button">Copy URL</button>
                            <button class="btn btn-outline-secondary" id="download-qr-pdf-button" type="button">Download PDF</button>
                            <button class="btn btn-primary" id="download-qr-button" type="button">Download SVG</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="storage-modal" tabindex="-1" aria-hidden="true" aria-labelledby="storage-modal-title">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5 text-break" id="storage-modal-title">Storage</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="storage-error" class="alert alert-danger d-none" role="alert"></div>
                            <div class="small text-muted mb-3" id="storage-usage"></div>
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-lg-5">
                                    <label class="form-label" for="storage-store">Store</label>
                                    <select class="form-select" id="storage-store"></select>
                                </div>
                                <div class="col-lg-7 d-flex flex-wrap gap-2 justify-content-lg-end">
                                    <button class="btn btn-outline-secondary" id="storage-refresh" type="button">Refresh</button>
                                    <button class="btn btn-outline-secondary" id="storage-export" type="button">Export JSON</button>
                                    <label class="btn btn-outline-secondary mb-0">
                                        Import JSON<input id="storage-import" type="file" accept=".json,application/json" hidden>
                                    </label>
                                    <button class="btn btn-outline-danger d-none" id="storage-delete-visitor" type="button">Delete visitor</button>
                                    <button class="btn btn-outline-danger" id="storage-clear" type="button">Delete all data</button>
                                </div>
                            </div>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Key</th>
                                            <th>Size</th>
                                            <th>Version</th>
                                            <th>Changed</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="storage-items"></tbody>
                                </table>
                            </div>
                            <form id="storage-editor" class="border rounded p-2 bg-light" novalidate>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1" for="storage-key">Key</label>
                                        <input class="form-control form-control-sm font-monospace" id="storage-key" maxlength="200">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label small mb-1" for="storage-value">Value (JSON)</label>
                                        <textarea class="form-control form-control-sm font-monospace" id="storage-value" rows="6" spellcheck="false"></textarea>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2 mt-2">
                                    <button class="btn btn-sm btn-outline-secondary" id="storage-editor-new" type="button">New key</button>
                                    <button class="btn btn-sm btn-primary" type="submit">Save value</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="api-modal" tabindex="-1" aria-hidden="true" aria-labelledby="api-modal-title">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="api-modal-title">API access</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted">
                                Other applications, for example PLM, create shares through <code>api.php</code> with an API key.
                                Each key only sees the shares it created.
                                <a href="api-docs.php" target="_blank" rel="noopener">Open the API documentation</a>
                            </p>
                            <div id="api-error" class="alert alert-danger d-none" role="alert"></div>
                            <div id="api-new-key" class="alert alert-warning d-none" role="status">
                                <div class="fw-semibold">Key for <span id="api-new-key-name"></span> created</div>
                                <div class="small mb-2">Copy it now. It is shown only this once.</div>
                                <div class="input-group">
                                    <input class="form-control font-monospace" id="api-new-key-token" readonly aria-label="New API key">
                                    <button class="btn btn-outline-secondary" id="api-new-key-copy" type="button">Copy</button>
                                </div>
                            </div>
                            <form id="api-key-form" class="input-group mb-3">
                                <input class="form-control" id="api-key-name" maxlength="80" placeholder="Name, e.g. PLM" aria-label="Key name" required>
                                <button class="btn btn-primary" type="submit">Create key</button>
                            </form>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Key</th>
                                            <th>Shares</th>
                                            <th>Last used</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="api-keys-table"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php psst_render_footer('mt-4'); ?>
    </div>
    <script src="js/bootstrap.bundle.min.js"></script>
    <script src="<?= psst_html(psst_asset('js/psst-crypto.js')) ?>"></script>
    <script src="js/qrcode-generator.js"></script>
    <script src="js/qr-lite.js"></script>
    <script src="<?= psst_html(psst_asset('js/manage.js')) ?>"></script>
</body>
</html>
