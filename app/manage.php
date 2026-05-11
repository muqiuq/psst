<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PSST</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/psst.css">
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
                <input class="form-control mb-3" id="login-htp" name="htp" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
                <div id="login-error" class="alert alert-danger mt-3 mb-0 d-none"></div>
            </form>
        </section>

        <main id="manage-view" class="d-none">
            <header class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <div>
                    <h1 class="h3 mb-0">PSST</h1>
                    <div class="text-muted small" id="share-count">0 shares</div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" id="refresh-button" type="button">Refresh</button>
                    <button class="btn btn-outline-danger" id="logout-button" type="button">Sign out</button>
                </div>
            </header>

            <div id="app-alert" class="alert d-none" role="status"></div>

            <div class="row g-3">
                <section class="col-12 col-xl-4">
                    <form id="share-form" class="bg-white border rounded p-3" enctype="multipart/form-data">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h2 class="h5 mb-0" id="form-title">New share</h2>
                            <button class="btn btn-sm btn-outline-secondary d-none" id="cancel-edit-button" type="button">Cancel</button>
                        </div>
                        <input type="hidden" id="share-uuid">

                        <label class="form-label" for="share-type">Type</label>
                        <select class="form-select mb-3" id="share-type" name="type">
                            <option value="text">Text</option>
                            <option value="link">Link</option>
                            <option value="file">File</option>
                        </select>

                        <label class="form-label" for="share-title">Title</label>
                        <input class="form-control mb-3" id="share-title" name="title" maxlength="160">

                        <div id="text-fields">
                            <label class="form-label" for="share-text">Text</label>
                            <textarea class="form-control mb-3" id="share-text" rows="7"></textarea>
                        </div>

                        <div id="link-fields" class="d-none">
                            <label class="form-label" for="share-link">URL</label>
                            <input class="form-control mb-3" id="share-link" type="url" placeholder="https://example.com">
                        </div>

                        <div id="file-fields" class="d-none">
                            <label class="form-label" for="share-file">File</label>
                            <input class="form-control mb-3" id="share-file" type="file">
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" id="share-encrypted" type="checkbox">
                            <label class="form-check-label" for="share-encrypted">Encrypt in browser</label>
                        </div>

                        <div id="password-fields" class="d-none">
                            <label class="form-label" for="share-password">Encryption password</label>
                            <input class="form-control mb-3" id="share-password" type="password" minlength="8" autocomplete="new-password">
                        </div>

                        <label class="form-label" for="secret-code">Secret code</label>
                        <input class="form-control mb-3" id="secret-code" autocomplete="off">

                        <button class="btn btn-primary w-100" id="save-button" type="submit">Create share</button>
                    </form>
                </section>

                <section class="col-12 col-xl-8">
                    <div class="bg-white border rounded overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Share</th>
                                        <th>Type</th>
                                        <th>Security</th>
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
        </main>
    </div>
    <script src="js/bootstrap.bundle.min.js"></script>
    <script src="js/psst-crypto.js"></script>
    <script src="js/qrcode-generator.js"></script>
    <script src="js/qr-lite.js"></script>
    <script src="js/manage.js"></script>
</body>
</html>