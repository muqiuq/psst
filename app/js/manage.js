(function () {
    'use strict';

    const state = {
        shares: [],
        editing: null,
        qrShare: null,
        maxUploadBytes: 0,
        defaultUploadQuotaBytes: 0,
        filter: '',
        resultShare: null,
    };

    const elements = {
        loginView: document.getElementById('login-view'),
        manageView: document.getElementById('manage-view'),
        loginForm: document.getElementById('login-form'),
        loginError: document.getElementById('login-error'),
        logoutButton: document.getElementById('logout-button'),
        refreshButton: document.getElementById('refresh-button'),
        appAlert: document.getElementById('app-alert'),
        shareCount: document.getElementById('share-count'),
        shareResult: document.getElementById('share-result'),
        shareResultTitle: document.getElementById('share-result-title'),
        shareResultClose: document.getElementById('share-result-close'),
        shareResultUrl: document.getElementById('share-result-url'),
        shareResultCopy: document.getElementById('share-result-copy'),
        shareResultQr: document.getElementById('share-result-qr'),
        shareResultNote: document.getElementById('share-result-note'),
        shareForm: document.getElementById('share-form'),
        formTitle: document.getElementById('form-title'),
        cancelEditButton: document.getElementById('cancel-edit-button'),
        uuid: document.getElementById('share-uuid'),
        type: document.getElementById('share-type'),
        title: document.getElementById('share-title'),
        text: document.getElementById('share-text'),
        textLabel: document.getElementById('share-text-label'),
        markdownHint: document.getElementById('markdown-hint'),
        link: document.getElementById('share-link'),
        file: document.getElementById('share-file'),
        fileHint: document.getElementById('share-file-hint'),
        encrypted: document.getElementById('share-encrypted'),
        password: document.getElementById('share-password'),
        togglePassword: document.getElementById('toggle-share-password'),
        generatePassword: document.getElementById('generate-share-password'),
        passwordHint: document.getElementById('password-hint'),
        secretCode: document.getElementById('secret-code'),
        secretHint: document.getElementById('secret-code-hint'),
        removeSecretRow: document.getElementById('remove-secret-row'),
        removeSecret: document.getElementById('remove-secret-code'),
        expiry: document.getElementById('share-expiry'),
        expiryLabel: document.getElementById('share-expiry-label'),
        expiryCustom: document.getElementById('share-expiry-custom'),
        maxViewsField: document.getElementById('max-views-field'),
        maxViews: document.getElementById('share-max-views'),
        limitHint: document.getElementById('limit-hint'),
        textFields: document.getElementById('text-fields'),
        linkFields: document.getElementById('link-fields'),
        fileFields: document.getElementById('file-fields'),
        uploadFields: document.getElementById('upload-fields'),
        uploadToken: document.getElementById('share-upload-token'),
        uploadQuota: document.getElementById('share-upload-quota'),
        uploadInfo: document.getElementById('upload-info'),
        uploadUsage: document.getElementById('upload-usage'),
        uploadUsageBar: document.getElementById('upload-usage-bar'),
        uploadUsageText: document.getElementById('upload-usage-text'),
        uploadUrl: document.getElementById('upload-url'),
        copyUploadUrl: document.getElementById('copy-upload-url'),
        cmdCurlMultipart: document.getElementById('cmd-curl-multipart'),
        cmdCurlRaw: document.getElementById('cmd-curl-raw'),
        cmdMikrotik: document.getElementById('cmd-mikrotik'),
        uploadFileCount: document.getElementById('upload-file-count'),
        uploadFileList: document.getElementById('upload-file-list'),
        encryptSwitchRow: document.getElementById('encrypt-switch-row'),
        passwordFields: document.getElementById('password-fields'),
        saveButton: document.getElementById('save-button'),
        filter: document.getElementById('share-filter'),
        table: document.getElementById('shares-table'),
        apiButton: document.getElementById('api-button'),
        apiModal: document.getElementById('api-modal'),
        apiKeyForm: document.getElementById('api-key-form'),
        apiKeyName: document.getElementById('api-key-name'),
        apiKeysTable: document.getElementById('api-keys-table'),
        apiNewKey: document.getElementById('api-new-key'),
        apiError: document.getElementById('api-error'),
        apiNewKeyName: document.getElementById('api-new-key-name'),
        apiNewKeyToken: document.getElementById('api-new-key-token'),
        apiNewKeyCopy: document.getElementById('api-new-key-copy'),
        qrModal: document.getElementById('qr-modal'),
        qrTarget: document.getElementById('qr-target'),
        qrUrl: document.getElementById('qr-url'),
        qrItiCode: document.getElementById('qr-iti-code'),
        copyQrUrlButton: document.getElementById('copy-qr-url-button'),
        downloadQrButton: document.getElementById('download-qr-button'),
        downloadQrPdfButton: document.getElementById('download-qr-pdf-button'),
    };

    const qrModal = new bootstrap.Modal(elements.qrModal);
    const apiModal = new bootstrap.Modal(elements.apiModal);
    const MEGABYTE = 1024 * 1024;

    function httpErrorMessage(status, data, rawBody) {
        if (data && data.error) {
            return data.error;
        }

        if (status === 0) {
            return 'The connection was lost. Check your network and try again.';
        }
        if (status === 401) {
            return 'Your session expired. Sign in again.';
        }
        if (status === 413) {
            return state.maxUploadBytes
                ? `The file is too large. The maximum accepted size is ${formatBytes(state.maxUploadBytes)}.`
                : 'The file is too large for this server.';
        }
        if (status === 429) {
            return 'Too many requests. Wait a moment and try again.';
        }
        if (status === 502 || status === 503 || status === 504) {
            return 'The server is unavailable right now. Try again shortly.';
        }

        // A proxy or PHP limit may reply with HTML/plain text instead of JSON.
        const text = (rawBody || '').trim();
        if (text !== '' && text.charAt(0) !== '<' && text.length < 300) {
            return text;
        }

        return `Request failed (HTTP ${status}).`;
    }

    async function api(action, options = {}) {
        const url = `manage-api.php?action=${encodeURIComponent(action)}${options.query || ''}`;
        const headers = Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});

        let response;
        try {
            response = await fetch(url, {
                method: options.method || 'GET',
                body: options.body,
                headers,
                credentials: 'same-origin',
            });
        } catch (error) {
            // Network-level failure: offline, dropped mobile connection, aborted request.
            throw new Error('The connection was lost. Check your network and try again.');
        }

        const rawBody = await response.text().catch(() => '');
        let data = null;
        if (rawBody.trim() !== '') {
            try {
                data = JSON.parse(rawBody);
            } catch (error) {
                data = null;
            }
        }

        if (!response.ok) {
            // An expired session anywhere in the app returns to the login form.
            if (response.status === 401 && action !== 'login') {
                showLogin();
            }
            throw new Error(httpErrorMessage(response.status, data, rawBody));
        }

        return data || {};
    }

    let alertTimer = null;

    function setAlert(message, type = 'success') {
        elements.appAlert.textContent = message;
        elements.appAlert.className = `alert alert-${type}`;
        elements.appAlert.scrollIntoView({ block: 'nearest' });

        if (alertTimer !== null) {
            window.clearTimeout(alertTimer);
        }

        // Errors need more reading time, especially on small screens.
        alertTimer = window.setTimeout(() => {
            elements.appAlert.classList.add('d-none');
            alertTimer = null;
        }, type === 'danger' ? 10000 : 3500);
    }

    // Wraps event handlers so a failed request shows a message instead of failing silently.
    function guarded(handler) {
        return (...args) => Promise.resolve()
            .then(() => handler(...args))
            .catch((error) => setAlert(error.message || 'Something went wrong.', 'danger'));
    }

    function showLogin() {
        elements.loginView.classList.remove('d-none');
        elements.manageView.classList.add('d-none');
    }

    function showManage() {
        elements.loginView.classList.add('d-none');
        elements.manageView.classList.remove('d-none');
    }

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined) {
            return 'unknown size';
        }
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        const units = ['KB', 'MB', 'GB'];
        let value = bytes / 1024;
        let unitIndex = 0;
        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }
        return `${value.toFixed(1)} ${units[unitIndex]}`;
    }

    function formatDate(iso) {
        if (!iso) {
            return '';
        }
        return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    function relativeTime(iso) {
        const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
        const steps = [['day', 86400], ['hour', 3600], ['minute', 60]];
        const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
        for (const [unit, size] of steps) {
            if (Math.abs(seconds) >= size) {
                return format.format(Math.round(seconds / size), unit);
            }
        }
        return format.format(seconds, 'second');
    }

    // navigator.clipboard only exists on https and localhost; fall back to a selection copy.
    function fallbackCopy(value) {
        const area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const copied = document.execCommand('copy');
        area.remove();
        if (!copied) {
            throw new Error('Copy failed. Select the text and copy it manually.');
        }
    }

    async function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                setAlert('Copied.');
                return;
            } catch (error) {
                // fall through to the selection copy
            }
        }
        fallbackCopy(value);
        setAlert('Copied.');
    }

    function updateFieldVisibility() {
        const type = elements.type.value;
        const isUpload = type === 'upload';
        const isMarkdown = type === 'markdown';
        // Drop boxes hold no content, and Markdown is rendered on the server.
        const canEncrypt = !isUpload && !isMarkdown;
        const editing = state.editing;
        elements.textFields.classList.toggle('d-none', type !== 'text' && !isMarkdown);
        elements.textLabel.textContent = isMarkdown ? 'Markdown' : 'Text';
        elements.markdownHint.classList.toggle('d-none', !isMarkdown);
        elements.linkFields.classList.toggle('d-none', type !== 'link');
        elements.fileFields.classList.toggle('d-none', type !== 'file');
        elements.uploadFields.classList.toggle('d-none', !isUpload);
        elements.encryptSwitchRow.classList.toggle('d-none', !canEncrypt);
        if (!canEncrypt) {
            elements.encrypted.checked = false;
        }
        elements.passwordFields.classList.toggle('d-none', !canEncrypt || !elements.encrypted.checked);
        elements.maxViewsField.classList.toggle('d-none', isUpload);
        elements.expiryLabel.textContent = isUpload ? 'Accept files until' : 'Expires';

        const keepsEncryptedContent = editing && editing.is_encrypted && editing.type === type && elements.encrypted.checked;
        elements.passwordHint.textContent = keepsEncryptedContent
            ? (type === 'file'
                ? 'Choose a new file and password to replace the encrypted file, or leave both empty to keep it.'
                : 'Enter new content and a password to replace the encrypted content, or leave both empty to keep it.')
            : 'At least 8 characters. Send it to the recipient separately; the server never sees it.';

        let hint = isUpload
            ? 'After this time the drop box stops accepting files. Received files are kept.'
            : 'The share is deleted when it expires or reaches its view limit. A limit of 1 means burn after reading.';
        if (editing && !isUpload && editing.view_count) {
            hint += ` Opened ${editing.view_count} ${editing.view_count === 1 ? 'time' : 'times'} so far.`;
        }
        elements.limitHint.textContent = hint;
    }

    function updateExpiryCustom() {
        const isCustom = elements.expiry.value === 'custom';
        elements.expiryCustom.classList.toggle('d-none', !isCustom);
        if (isCustom) {
            // datetime-local wants local time without a zone.
            const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
            elements.expiryCustom.min = now.toISOString().slice(0, 16);
        }
    }

    function setExpiryOptions(share) {
        const keep = elements.expiry.querySelector('option[value="keep"]');
        if (keep) {
            keep.remove();
        }
        elements.expiryCustom.value = '';

        if (share && share.expires_at) {
            const option = document.createElement('option');
            option.value = 'keep';
            option.textContent = `Keep: ${formatDate(share.expires_at)}`;
            elements.expiry.prepend(option);
            elements.expiry.value = 'keep';
        } else {
            elements.expiry.value = 'never';
        }
        updateExpiryCustom();
    }

    // Returns undefined to leave the current expiry unchanged.
    function expiryValue() {
        const choice = elements.expiry.value;
        if (choice === 'keep') {
            return undefined;
        }
        if (choice === 'never') {
            return '';
        }
        if (choice === 'custom') {
            if (!elements.expiryCustom.value) {
                throw new Error('Pick an expiry date.');
            }
            const date = new Date(elements.expiryCustom.value);
            if (Number.isNaN(date.getTime())) {
                throw new Error('The expiry date is not valid.');
            }
            if (date.getTime() <= Date.now()) {
                throw new Error('The expiry must be in the future.');
            }
            return date.toISOString();
        }
        return new Date(Date.now() + Number(choice) * 1000).toISOString();
    }

    function positiveIntegerValue(input, message) {
        const value = input.value.trim();
        if (value === '') {
            return '';
        }
        const number = Number(value);
        if (!Number.isInteger(number) || number < 1) {
            throw new Error(message);
        }
        return number;
    }

    function buildOneLiners(uploadUrl) {
        const separator = uploadUrl.includes('?') ? '&' : '?';
        return {
            multipart: `curl -F "file=@/path/to/file" "${uploadUrl}"`,
            raw: `curl --data-binary @/path/to/file "${uploadUrl}${separator}filename=file"`,
            mikrotik: `/export file=psst; :delay 2s; /tool fetch http-method=post http-header-field="Content-Type: application/octet-stream" url="${uploadUrl}${separator}filename=psst.rsc" http-data=[/file get psst.rsc contents]`,
        };
    }

    function storageText(share) {
        const used = formatBytes(share.upload_used_bytes || 0);
        return share.upload_quota_bytes
            ? `${used} of ${formatBytes(share.upload_quota_bytes)}`
            : `${used} (no limit)`;
    }

    function renderUploadInfo(share) {
        const isUpload = share && share.type === 'upload';
        elements.uploadInfo.classList.toggle('d-none', !isUpload);
        if (!isUpload) {
            return;
        }

        const uploadUrl = share.upload_url || share.url;
        elements.uploadUrl.value = uploadUrl;
        const cmds = buildOneLiners(uploadUrl);
        elements.cmdCurlMultipart.value = cmds.multipart;
        elements.cmdCurlRaw.value = cmds.raw;
        elements.cmdMikrotik.value = cmds.mikrotik;
        elements.uploadToken.checked = Boolean(share.upload_token);

        const used = share.upload_used_bytes || 0;
        const quota = share.upload_quota_bytes || 0;
        const percent = quota ? Math.min(100, Math.round((used / quota) * 100)) : 0;
        elements.uploadUsage.classList.toggle('d-none', !quota);
        elements.uploadUsageBar.style.width = `${percent}%`;
        elements.uploadUsageBar.className = `progress-bar ${percent >= 90 ? 'bg-danger' : percent >= 70 ? 'bg-warning' : ''}`;
        elements.uploadUsage.setAttribute('aria-valuenow', String(percent));
        elements.uploadUsageText.textContent = share.upload_closed
            ? `${storageText(share)} · closed, no longer accepts files`
            : storageText(share);

        const files = share.upload_files || [];
        elements.uploadFileCount.textContent = String(files.length);
        elements.uploadFileList.innerHTML = '';
        if (!files.length) {
            elements.uploadFileList.innerHTML = '<li class="list-group-item text-muted small">No files received yet.</li>';
            return;
        }

        files.forEach((file) => {
            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';
            const info = document.createElement('div');
            info.className = 'text-truncate';
            const name = document.createElement('a');
            name.href = file.download_url;
            name.target = '_blank';
            name.rel = 'noopener noreferrer';
            name.className = 'fw-semibold d-block text-truncate';
            name.textContent = file.original_filename;
            const meta = document.createElement('div');
            meta.className = 'small text-muted';
            meta.textContent = [formatBytes(file.file_size), formatDate(file.created_at), file.remote_ip].filter(Boolean).join(' · ');
            info.appendChild(name);
            info.appendChild(meta);

            const remove = document.createElement('button');
            remove.className = 'btn btn-sm btn-outline-danger flex-shrink-0';
            remove.type = 'button';
            remove.textContent = 'Delete';
            remove.addEventListener('click', guarded(() => deleteUploadFile(share, file)));

            item.appendChild(info);
            item.appendChild(remove);
            elements.uploadFileList.appendChild(item);
        });
    }

    async function deleteUploadFile(share, file) {
        if (!window.confirm(`Delete ${file.original_filename}?`)) {
            return;
        }
        await api('upload_delete', {
            method: 'POST',
            query: `&uuid=${encodeURIComponent(share.uuid)}&file=${encodeURIComponent(file.uuid)}`,
        });
        setAlert('File deleted.');
        await loadShares();
    }

    function setPasswordVisible(visible) {
        elements.password.type = visible ? 'text' : 'password';
        elements.togglePassword.textContent = visible ? 'Hide' : 'Show';
    }

    function generatePassword() {
        const bytes = crypto.getRandomValues(new Uint8Array(15));
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        // 20 URL-safe characters, 120 bits of entropy.
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_');
    }

    function resetForm() {
        state.editing = null;
        elements.shareForm.reset();
        elements.uuid.value = '';
        elements.uploadToken.checked = true;
        elements.uploadQuota.value = state.defaultUploadQuotaBytes
            ? String(Math.round(state.defaultUploadQuotaBytes / MEGABYTE))
            : '';
        elements.text.placeholder = '';
        elements.link.placeholder = 'https://example.com';
        elements.secretCode.placeholder = 'None';
        elements.secretCode.disabled = false;
        elements.removeSecretRow.classList.add('d-none');
        elements.formTitle.textContent = 'New share';
        elements.saveButton.textContent = 'Create share';
        elements.cancelEditButton.classList.add('d-none');
        setPasswordVisible(false);
        setExpiryOptions(null);
        renderUploadInfo(null);
        updateFieldVisibility();
    }

    function shareBadges(share) {
        const badges = [];
        const isUpload = share.type === 'upload';

        if (share.is_encrypted) {
            badges.push(['Encrypted', 'text-bg-primary']);
        }
        if (share.api_key_name) {
            badges.push([`API: ${share.api_key_name}`, 'text-bg-dark']);
        }
        if (share.has_secret_code) {
            badges.push(['Secret code', 'text-bg-info']);
        } else if (share.requires_secret_code && !isUpload) {
            badges.push(['Server code', 'text-bg-info']);
        }
        if (isUpload) {
            badges.push(share.upload_token ? ['Token', 'text-bg-light border'] : ['No token', 'text-bg-warning']);
            badges.push([storageText(share), 'text-bg-light border']);
            if (share.upload_closed) {
                badges.push(['Closed', 'text-bg-secondary']);
            }
        }
        if (share.expires_at && !(isUpload && share.upload_closed)) {
            badges.push([`${isUpload ? 'Closes' : 'Expires'} ${relativeTime(share.expires_at)}`, 'text-bg-light border']);
        }
        if (!isUpload && share.max_views) {
            badges.push([`${share.view_count} of ${share.max_views} views`, 'text-bg-warning']);
        } else if (!isUpload && share.view_count) {
            badges.push([`${share.view_count} ${share.view_count === 1 ? 'view' : 'views'}`, 'text-bg-light border']);
        }

        return badges;
    }

    function shareMatchesFilter(share) {
        if (!state.filter) {
            return true;
        }
        const haystack = [share.title, share.url, share.type, share.original_filename].join(' ').toLowerCase();
        return haystack.includes(state.filter);
    }

    function emptyRow(message) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 4;
        cell.className = 'text-muted text-center py-4';
        cell.textContent = message;
        row.appendChild(cell);
        return row;
    }

    function renderShares() {
        elements.shareCount.textContent = `${state.shares.length} ${state.shares.length === 1 ? 'share' : 'shares'}`;
        elements.table.innerHTML = '';

        if (!state.shares.length) {
            elements.table.appendChild(emptyRow('No shares yet. Create one with the form.'));
            return;
        }

        const visible = state.shares.filter(shareMatchesFilter);
        if (!visible.length) {
            elements.table.appendChild(emptyRow('No shares match the filter.'));
            return;
        }

        visible.forEach((share) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="fw-semibold share-title"></div>
                    <a class="small share-link" target="_blank" rel="noopener noreferrer"></a>
                    <div class="small text-muted share-created"></div>
                </td>
                <td><span class="badge text-bg-secondary text-uppercase"></span></td>
                <td><div class="badge-group"></div></td>
                <td class="text-end"><div class="action-group"></div></td>
            `;

            row.querySelector('.share-title').textContent = share.title || share.original_filename || share.uuid;
            const link = row.querySelector('.share-link');
            link.href = share.url;
            link.textContent = share.url;
            row.querySelector('.share-created').textContent = `Created ${formatDate(share.created_at)}`;
            row.querySelector('.badge').textContent = share.type;

            const badges = row.querySelector('.badge-group');
            const list = shareBadges(share);
            if (!list.length) {
                list.push(['Open', 'text-bg-light border']);
            }
            list.forEach(([text, style]) => {
                const badge = document.createElement('span');
                badge.className = `badge ${style}`;
                badge.textContent = text;
                badges.appendChild(badge);
            });

            const shareLink = share.type === 'upload' ? (share.upload_url || share.url) : share.url;
            const actions = row.querySelector('.action-group');
            actions.appendChild(actionButton('Copy', guarded(() => copyText(shareLink))));
            actions.appendChild(actionButton('QR', () => openQr(share)));
            actions.appendChild(actionButton('Edit', () => editShare(share)));
            actions.appendChild(actionButton('Delete', guarded(() => deleteShare(share)), 'outline-danger'));

            elements.table.appendChild(row);
        });
    }

    function actionButton(label, handler, style = 'outline-secondary') {
        const button = document.createElement('button');
        button.className = `btn btn-sm btn-${style}`;
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', handler);
        return button;
    }

    function editShare(share) {
        resetForm();
        state.editing = share;
        elements.uuid.value = share.uuid;
        elements.type.value = share.type;
        elements.title.value = share.title || '';
        elements.encrypted.checked = share.is_encrypted;
        elements.text.value = (share.type === 'text' || share.type === 'markdown') && !share.is_encrypted ? (share.content || '') : '';
        elements.link.value = share.type === 'link' && !share.is_encrypted ? (share.content || '') : '';
        elements.text.placeholder = share.type === 'text' && share.is_encrypted ? 'Leave empty to keep the current encrypted text' : '';
        elements.link.placeholder = share.type === 'link' && share.is_encrypted ? 'Leave empty to keep the current encrypted link' : 'https://example.com';
        elements.secretCode.value = '';
        elements.secretCode.placeholder = share.has_secret_code ? 'Leave empty to keep the current code' : 'None';
        elements.removeSecretRow.classList.toggle('d-none', !share.has_secret_code);
        elements.password.value = '';
        elements.maxViews.value = share.max_views ? String(share.max_views) : '';
        elements.uploadQuota.value = share.upload_quota_bytes
            ? String(Math.round(share.upload_quota_bytes / MEGABYTE))
            : '';
        elements.formTitle.textContent = 'Edit share';
        elements.saveButton.textContent = 'Save changes';
        elements.cancelEditButton.classList.remove('d-none');
        setExpiryOptions(share);
        renderUploadInfo(share);
        updateFieldVisibility();
        elements.shareForm.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    async function deleteShare(share) {
        const what = share.type === 'upload' ? ' and all received files' : '';
        if (!window.confirm(`Delete "${share.title || share.uuid}"${what}?`)) {
            return;
        }
        await api('delete', { method: 'POST', query: `&uuid=${encodeURIComponent(share.uuid)}` });
        setAlert('Share deleted.');
        if (state.editing && state.editing.uuid === share.uuid) {
            resetForm();
        }
        await loadShares();
    }

    async function loadShares() {
        const data = await api('shares');
        state.shares = data.shares || [];
        renderShares();

        // Keep an open drop box current (new files, storage) without touching the form fields.
        if (state.editing) {
            const refreshed = state.shares.find((item) => item.uuid === state.editing.uuid);
            if (refreshed) {
                state.editing = refreshed;
                renderUploadInfo(refreshed);
            }
        }
    }

    function showShareResult(share) {
        const isUpload = share.type === 'upload';
        const notes = [];
        if (share.is_encrypted) {
            notes.push('Send the password separately; it is not part of the link.');
        }
        if (share.max_views) {
            notes.push(`The share opens ${share.max_views === 1 ? 'once' : `${share.max_views} times`}, then it is deleted.`);
        }
        if (share.expires_at) {
            notes.push(`${isUpload ? 'Accepts files until' : 'Expires'} ${formatDate(share.expires_at)}.`);
        }
        if (isUpload && share.upload_token) {
            notes.push('The upload token is part of this link.');
        }

        state.resultShare = share;
        elements.shareResultTitle.textContent = isUpload ? 'Drop box created' : 'Share created';
        elements.shareResultUrl.value = isUpload ? (share.upload_url || share.url) : share.url;
        elements.shareResultNote.textContent = notes.join(' ');
        elements.shareResultNote.classList.toggle('d-none', !notes.length);
        elements.shareResult.classList.remove('d-none');
        elements.shareResult.scrollIntoView({ block: 'nearest' });
    }

    function hideShareResult() {
        elements.shareResult.classList.add('d-none');
        state.resultShare = null;
    }

    // Like guarded(), but shows the error inside the API dialog, above its backdrop.
    function guardedInDialog(handler) {
        return (...args) => {
            elements.apiError.classList.add('d-none');
            return Promise.resolve()
                .then(() => handler(...args))
                .catch((error) => {
                    elements.apiError.textContent = error.message || 'Something went wrong.';
                    elements.apiError.classList.remove('d-none');
                });
        };
    }

    function renderApiKeys(keys) {
        elements.apiKeysTable.innerHTML = '';
        if (!keys.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'text-muted text-center py-3';
            cell.textContent = 'No API keys yet.';
            row.appendChild(cell);
            elements.apiKeysTable.appendChild(row);
            return;
        }

        keys.forEach((key) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><span class="fw-semibold key-name"></span></td>
                <td><code class="key-prefix"></code></td>
                <td class="key-shares"></td>
                <td class="small text-muted key-used"></td>
                <td class="text-end key-actions"></td>
            `;
            row.querySelector('.key-name').textContent = key.name;
            row.querySelector('.key-prefix').textContent = `${key.prefix}…`;
            row.querySelector('.key-shares').textContent = String(key.share_count);
            row.querySelector('.key-used').textContent = key.last_used_at ? formatDate(key.last_used_at) : 'Never';

            const actions = row.querySelector('.key-actions');
            if (key.revoked_at) {
                row.classList.add('text-muted');
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-secondary';
                badge.textContent = `Revoked ${formatDate(key.revoked_at)}`;
                actions.appendChild(badge);
            } else {
                actions.appendChild(actionButton('Revoke', guardedInDialog(() => revokeApiKey(key)), 'outline-danger'));
            }
            elements.apiKeysTable.appendChild(row);
        });
    }

    async function loadApiKeys() {
        const data = await api('api_keys');
        renderApiKeys(data.keys || []);
    }

    async function openApiKeys() {
        elements.apiNewKey.classList.add('d-none');
        elements.apiNewKeyToken.value = '';
        apiModal.show();
        await loadApiKeys();
    }

    async function createApiKey() {
        const name = elements.apiKeyName.value.trim();
        if (name === '') {
            throw new Error('Give the key a name, for example "PLM".');
        }

        const data = await api('api_key_create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name }),
        });
        elements.apiKeyName.value = '';
        elements.apiNewKeyName.textContent = data.key.name;
        elements.apiNewKeyToken.value = data.token;
        elements.apiNewKey.classList.remove('d-none');
        elements.apiNewKeyToken.select();
        await loadApiKeys();
    }

    async function revokeApiKey(key) {
        if (!window.confirm(`Revoke the key "${key.name}"? Applications using it lose access right away. Shares it created stay online.`)) {
            return;
        }
        await api('api_key_revoke', { method: 'POST', query: `&id=${encodeURIComponent(key.id)}` });
        await loadApiKeys();
    }

    // Encrypting in the browser needs several copies of the file in memory; keep well
    // below what a mid-range Android device can hold.
    const MAX_ENCRYPT_BYTES = 32 * 1024 * 1024;

    function safeFileName(file) {
        const name = (file && file.name ? String(file.name) : '').split(/[\\/]/).pop();
        return name && name.trim() !== '' ? name : `upload-${Date.now()}.bin`;
    }

    function assertUploadSize(file) {
        if (state.maxUploadBytes && file.size > state.maxUploadBytes) {
            throw new Error(`"${safeFileName(file)}" is ${formatBytes(file.size)}, which exceeds the ${formatBytes(state.maxUploadBytes)} limit.`);
        }
    }

    // Safari/older Android WebViews lack Blob.arrayBuffer().
    function readFileBytes(file) {
        if (typeof file.arrayBuffer === 'function') {
            return file.arrayBuffer();
        }

        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('Unable to read the selected file.'));
            reader.readAsArrayBuffer(file);
        });
    }

    function appendCommonFields(formData) {
        const type = elements.type.value;
        formData.set('type', type);
        formData.set('title', elements.title.value.trim());
        formData.set('is_encrypted', elements.encrypted.checked ? '1' : '0');

        if (elements.removeSecret.checked) {
            formData.set('secret_code', '');
        } else if (elements.secretCode.value.trim() !== '') {
            formData.set('secret_code', elements.secretCode.value.trim());
        }

        const expiry = expiryValue();
        if (expiry !== undefined) {
            formData.set('expires_at', expiry);
        }

        if (type !== 'upload') {
            formData.set('max_views', String(positiveIntegerValue(elements.maxViews, 'The view limit must be a whole number of at least 1.')));
        }
    }

    function requirePassword() {
        const password = elements.password.value;
        if (password.length < 8) {
            throw new Error('Encryption password must be at least 8 characters.');
        }
        return password;
    }

    async function buildPayload() {
        const formData = new FormData();
        const type = elements.type.value;
        const encrypted = elements.encrypted.checked;
        const editing = state.editing;
        const wasEncrypted = Boolean(editing && editing.is_encrypted && editing.type === type);
        appendCommonFields(formData);

        if (type === 'upload') {
            formData.set('require_token', elements.uploadToken.checked ? '1' : '0');
            const quotaMb = positiveIntegerValue(elements.uploadQuota, 'The storage limit must be a whole number of megabytes.');
            formData.set('upload_quota_bytes', quotaMb === '' ? '' : String(quotaMb * MEGABYTE));
            return formData;
        }

        if (!encrypted) {
            if (type === 'text' || type === 'markdown') {
                formData.set('content', elements.text.value);
            } else if (type === 'link') {
                formData.set('content', elements.link.value);
            } else if (elements.file.files[0]) {
                const chosen = elements.file.files[0];
                assertUploadSize(chosen);
                formData.set('file', chosen, safeFileName(chosen));
            } else if (wasEncrypted) {
                throw new Error('Choose the file again to store it without encryption.');
            }
            return formData;
        }

        if (type === 'text' || type === 'link') {
            const plain = type === 'text' ? elements.text.value : elements.link.value;
            // Editing only the title, limits or secret code keeps the encrypted content.
            if (wasEncrypted && plain === '' && elements.password.value === '') {
                return formData;
            }
            const password = requirePassword();
            const encryptedText = await window.PsstCrypto.encryptText(plain, password);
            formData.set('content', encryptedText.base64);
            formData.set('encryption_meta', JSON.stringify({ kind: type, crypto: encryptedText.meta }));
            return formData;
        }

        const file = elements.file.files[0];
        if (!file) {
            if (wasEncrypted) {
                return formData;
            }
            throw new Error(editing ? 'Choose the file again to encrypt it.' : 'Choose a file to encrypt.');
        }

        const password = requirePassword();
        assertUploadSize(file);

        // In-browser encryption holds the plaintext, the ciphertext and the request copy
        // in memory at once. Mobile browsers kill the tab well before desktop ones do.
        if (file.size > MAX_ENCRYPT_BYTES) {
            throw new Error(`In-browser encryption is limited to ${formatBytes(MAX_ENCRYPT_BYTES)} on this device. Upload the file without encryption or use a smaller file.`);
        }

        const plainBytes = new Uint8Array(await readFileBytes(file));
        const encryptedFile = await window.PsstCrypto.encryptBytes(plainBytes, password);
        const blob = new Blob([encryptedFile.bytes], { type: 'application/octet-stream' });
        formData.set('file', blob, `${safeFileName(file)}.psst`);
        formData.set('encryption_meta', JSON.stringify({
            kind: 'file',
            originalName: safeFileName(file),
            originalMime: file.type || 'application/octet-stream',
            originalSize: file.size,
            crypto: encryptedFile.meta,
        }));
        return formData;
    }

    function renderUploadLimitHint() {
        if (!elements.fileHint) {
            return;
        }

        elements.fileHint.textContent = state.maxUploadBytes
            ? `Maximum size: ${formatBytes(state.maxUploadBytes)}.`
            : '';
    }

    async function saveShare(event) {
        event.preventDefault();
        const wasEditing = Boolean(state.editing);
        elements.saveButton.disabled = true;
        try {
            if (elements.encrypted.checked && elements.password.value !== '') {
                elements.saveButton.textContent = 'Encrypting…';
            }
            const payload = await buildPayload();
            const action = wasEditing ? 'update' : 'create';
            const query = wasEditing ? `&uuid=${encodeURIComponent(state.editing.uuid)}` : '';
            if (elements.type.value === 'file' && elements.file.files[0]) {
                elements.saveButton.textContent = 'Uploading…';
            }
            const result = await api(action, { method: 'POST', query, body: payload });
            const savedShare = result && result.share ? result.share : null;
            resetForm();
            await loadShares();
            if (wasEditing) {
                setAlert('Share updated.');
            } else if (savedShare) {
                showShareResult(savedShare);
            }
            // Keep upload shares open in edit mode so the owner sees the URL + one-liners.
            if (savedShare && savedShare.type === 'upload') {
                const refreshed = state.shares.find((item) => item.uuid === savedShare.uuid) || savedShare;
                editShare(refreshed);
            }
        } catch (error) {
            setAlert(error.message || 'Unable to save share.', 'danger');
        } finally {
            elements.saveButton.disabled = false;
            elements.saveButton.textContent = state.editing ? 'Save changes' : 'Create share';
        }
    }

    function openQr(share) {
        state.qrShare = share;
        document.getElementById('qr-standard').checked = true;
        renderQr();
        qrModal.show();
    }

    function qrValue(mode) {
        if (!state.qrShare) {
            return '';
        }

        if (mode === 'iti') {
            return state.qrShare.iti_url;
        }

        // A drop box QR code must carry the upload token, or scanning it only shows a warning.
        return state.qrShare.type === 'upload'
            ? (state.qrShare.upload_url || state.qrShare.url)
            : state.qrShare.url;
    }

    function renderQr() {
        const share = state.qrShare;
        if (!share) {
            return;
        }
        const mode = document.querySelector('input[name="qr-mode"]:checked').value;
        const value = qrValue(mode);
        elements.qrUrl.value = value;
        elements.qrItiCode.classList.toggle('d-none', mode !== 'iti');
        elements.qrItiCode.textContent = mode === 'iti'
            ? `ITI code: ${share.iti_secret_code}`
            : '';

        if (mode === 'iti' && !value) {
            elements.qrTarget.textContent = 'This share does not have an ITI code.';
            return;
        }

        window.PsstQr.render(elements.qrTarget, value);
    }

    function downloadBlob(blob, filename) {
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();

        window.setTimeout(() => {
            URL.revokeObjectURL(url);
            link.remove();
        }, 1000);
    }

    function downloadQr() {
        const svg = elements.qrTarget.querySelector('svg');
        if (!svg) {
            setAlert('Render the QR code before downloading.', 'danger');
            return;
        }
        const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml' });
        downloadBlob(blob, `${state.qrShare.uuid}.svg`);
    }

    function pdfEscape(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
    }

    function wrapPdfText(value, maxLength, maxLines) {
        const chunks = String(value).match(new RegExp(`.{1,${maxLength}}`, 'g')) || [''];
        if (chunks.length <= maxLines) {
            return chunks;
        }

        const lines = chunks.slice(0, maxLines);
        lines[maxLines - 1] = `${lines[maxLines - 1].slice(0, Math.max(0, maxLength - 3))}...`;
        return lines;
    }

    function addPdfText(lines, x, y, size, value) {
        lines.push(`BT /F1 ${size} Tf ${x} ${y} Td (${pdfEscape(value)}) Tj ET`);
    }

    function createPdfObject(parts, object) {
        const offset = parts.join('').length;
        parts.push(object);
        return offset;
    }

    function qrPdfContent(share, mode, value) {
        const qr = qrcode(0, 'M');
        qr.addData(value, 'Byte');
        qr.make();

        const pageHeight = 842;
        const moduleCount = qr.getModuleCount();
        const qrSize = 280;
        const cellSize = qrSize / moduleCount;
        const qrX = 158;
        const qrY = 320;
        const lines = [];

        addPdfText(lines, 72, 770, 22, mode === 'iti' ? 'Validation' : 'PSST QR Code');
        addPdfText(lines, 72, 738, 12, share.title || share.uuid);

        if (mode === 'iti') {
            addPdfText(lines, 72, 710, 16, `ITI secret code: ${share.iti_secret_code || ''}`);
            addPdfText(lines, 72, 682, 11, 'English: This document can be verified at https://validar.iti.gov.br.');
            addPdfText(lines, 72, 666, 11, 'Portugues: Este documento pode ser verificado em https://validar.iti.gov.br.');
        }

        addPdfText(lines, 72, 274, 11, 'Link:');
        wrapPdfText(value, 82, 7).forEach((line, index) => {
            addPdfText(lines, 72, 258 - index * 14, 10, line);
        });

        lines.push('1 1 1 rg');
        lines.push(`${qrX - 18} ${qrY - 18} ${qrSize + 36} ${qrSize + 36} re f`);
        lines.push('0 0 0 rg');

        for (let row = 0; row < moduleCount; row += 1) {
            for (let col = 0; col < moduleCount; col += 1) {
                if (qr.isDark(row, col)) {
                    const x = qrX + col * cellSize;
                    const y = qrY + (moduleCount - row - 1) * cellSize;
                    lines.push(`${x.toFixed(3)} ${y.toFixed(3)} ${cellSize.toFixed(3)} ${cellSize.toFixed(3)} re f`);
                }
            }
        }

        return lines.join('\n');
    }

    function downloadQrPdf() {
        if (!state.qrShare) {
            setAlert('Choose a share before downloading a PDF.', 'danger');
            return;
        }

        const mode = document.querySelector('input[name="qr-mode"]:checked').value;
        const value = qrValue(mode);
        if (!value) {
            setAlert('This QR code has no URL to download.', 'danger');
            return;
        }

        if (typeof qrcode !== 'function') {
            setAlert('QR generator is unavailable.', 'danger');
            return;
        }

        try {
            const content = qrPdfContent(state.qrShare, mode, value);
            const parts = ['%PDF-1.4\n'];
            const offsets = [0];
            offsets.push(createPdfObject(parts, '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n'));
            offsets.push(createPdfObject(parts, '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n'));
            offsets.push(createPdfObject(parts, '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj\n'));
            offsets.push(createPdfObject(parts, '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n'));
            offsets.push(createPdfObject(parts, `5 0 obj << /Length ${content.length} >> stream\n${content}\nendstream endobj\n`));
            const xrefOffset = parts.join('').length;
            parts.push('xref\n0 6\n0000000000 65535 f \n');
            offsets.slice(1).forEach((offset) => parts.push(`${String(offset).padStart(10, '0')} 00000 n \n`));
            parts.push(`trailer << /Size 6 /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF\n`);

            const blob = new Blob(parts, { type: 'application/pdf' });
            downloadBlob(blob, `${state.qrShare.uuid}-${mode}.pdf`);
        } catch (error) {
            setAlert(error.message || 'Unable to create QR PDF.', 'danger');
        }
    }

    async function init() {
        elements.type.addEventListener('change', updateFieldVisibility);
        elements.encrypted.addEventListener('change', updateFieldVisibility);
        elements.expiry.addEventListener('change', updateExpiryCustom);
        elements.shareForm.addEventListener('submit', saveShare);
        elements.cancelEditButton.addEventListener('click', resetForm);
        elements.refreshButton.addEventListener('click', guarded(async () => {
            await loadShares();
            setAlert('Shares refreshed.');
        }));
        elements.filter.addEventListener('input', () => {
            state.filter = elements.filter.value.trim().toLowerCase();
            renderShares();
        });
        elements.togglePassword.addEventListener('click', () => setPasswordVisible(elements.password.type === 'password'));
        elements.generatePassword.addEventListener('click', () => {
            elements.password.value = generatePassword();
            setPasswordVisible(true);
        });
        elements.removeSecret.addEventListener('change', () => {
            elements.secretCode.disabled = elements.removeSecret.checked;
        });
        elements.shareResultClose.addEventListener('click', hideShareResult);
        elements.apiButton.addEventListener('click', guardedInDialog(openApiKeys));
        const submitApiKey = guardedInDialog(createApiKey);
        elements.apiKeyForm.addEventListener('submit', (event) => {
            // Synchronously: the guarded handler runs later, too late to stop the submit.
            event.preventDefault();
            submitApiKey();
        });
        elements.apiNewKeyCopy.addEventListener('click', guardedInDialog(async () => {
            await copyText(elements.apiNewKeyToken.value);
            elements.apiNewKeyCopy.textContent = 'Copied';
            window.setTimeout(() => {
                elements.apiNewKeyCopy.textContent = 'Copy';
            }, 1500);
        }));
        // A key is shown only once; do not leave it in the page after the dialog closes.
        elements.apiModal.addEventListener('hidden.bs.modal', () => {
            elements.apiNewKeyToken.value = '';
            elements.apiNewKey.classList.add('d-none');
        });
        elements.shareResultCopy.addEventListener('click', guarded(() => copyText(elements.shareResultUrl.value)));
        elements.shareResultQr.addEventListener('click', () => {
            if (state.resultShare) {
                openQr(state.resultShare);
            }
        });
        elements.copyQrUrlButton.addEventListener('click', guarded(() => copyText(elements.qrUrl.value)));
        elements.downloadQrButton.addEventListener('click', downloadQr);
        elements.downloadQrPdfButton.addEventListener('click', downloadQrPdf);
        elements.copyUploadUrl.addEventListener('click', guarded(() => copyText(elements.uploadUrl.value)));
        document.querySelectorAll('button[data-copy-target]').forEach((button) => {
            button.addEventListener('click', guarded(() => {
                const target = document.getElementById(button.dataset.copyTarget);
                return target ? copyText(target.value) : undefined;
            }));
        });
        document.querySelectorAll('input[name="qr-mode"]').forEach((input) => input.addEventListener('change', renderQr));

        elements.loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            elements.loginError.classList.add('d-none');
            const submitButton = elements.loginForm.querySelector('button[type="submit"]');
            const htpInput = document.getElementById('login-htp');
            submitButton.disabled = true;
            submitButton.textContent = 'Signing in…';
            try {
                await api('login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('login-username').value,
                        password: document.getElementById('login-password').value,
                        htp: htpInput.value,
                    }),
                });
                document.getElementById('login-password').value = '';
                // Each HTP number works only once; the next sign-in needs a new one.
                htpInput.value = '';
                showManage();
                await loadShares();
            } catch (error) {
                elements.loginError.textContent = error.message || 'Sign in failed.';
                elements.loginError.classList.remove('d-none');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Sign in';
            }
        });

        elements.logoutButton.addEventListener('click', guarded(async () => {
            await api('logout', { method: 'POST' });
            hideShareResult();
            resetForm();
            showLogin();
        }));

        const status = await api('status');
        state.maxUploadBytes = Number(status.max_upload_bytes || 0);
        state.defaultUploadQuotaBytes = Number(status.default_upload_quota_bytes || 0);
        renderUploadLimitHint();
        resetForm();
        if (status.authenticated) {
            showManage();
            await loadShares();
        } else {
            showLogin();
        }
    }

    init().catch((error) => setAlert(error.message || 'Unable to start PSST.', 'danger'));
}());
