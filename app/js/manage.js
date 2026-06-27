(function () {
    'use strict';

    const state = {
        shares: [],
        editing: null,
        qrShare: null,
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
        shareForm: document.getElementById('share-form'),
        formTitle: document.getElementById('form-title'),
        cancelEditButton: document.getElementById('cancel-edit-button'),
        uuid: document.getElementById('share-uuid'),
        type: document.getElementById('share-type'),
        title: document.getElementById('share-title'),
        text: document.getElementById('share-text'),
        link: document.getElementById('share-link'),
        file: document.getElementById('share-file'),
        encrypted: document.getElementById('share-encrypted'),
        password: document.getElementById('share-password'),
        secretCode: document.getElementById('secret-code'),
        textFields: document.getElementById('text-fields'),
        linkFields: document.getElementById('link-fields'),
        fileFields: document.getElementById('file-fields'),
        uploadFields: document.getElementById('upload-fields'),
        uploadToken: document.getElementById('share-upload-token'),
        uploadInfo: document.getElementById('upload-info'),
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
        table: document.getElementById('shares-table'),
        qrModal: document.getElementById('qr-modal'),
        qrTarget: document.getElementById('qr-target'),
        qrUrl: document.getElementById('qr-url'),
        qrItiCode: document.getElementById('qr-iti-code'),
        copyQrUrlButton: document.getElementById('copy-qr-url-button'),
        downloadQrButton: document.getElementById('download-qr-button'),
        downloadQrPdfButton: document.getElementById('download-qr-pdf-button'),
    };

    const qrModal = new bootstrap.Modal(elements.qrModal);

    async function api(action, options = {}) {
        const response = await fetch(`manage-api.php?action=${encodeURIComponent(action)}${options.query || ''}`, {
            method: options.method || 'GET',
            body: options.body,
            headers: options.headers,
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || 'Request failed.');
        }
        return data;
    }

    function setAlert(message, type = 'success') {
        elements.appAlert.textContent = message;
        elements.appAlert.className = `alert alert-${type}`;
        window.setTimeout(() => elements.appAlert.classList.add('d-none'), 3500);
    }

    function showLogin() {
        elements.loginView.classList.remove('d-none');
        elements.manageView.classList.add('d-none');
    }

    function showManage() {
        elements.loginView.classList.add('d-none');
        elements.manageView.classList.remove('d-none');
    }

    function updateFieldVisibility() {
        const type = elements.type.value;
        const isUpload = type === 'upload';
        elements.textFields.classList.toggle('d-none', type !== 'text');
        elements.linkFields.classList.toggle('d-none', type !== 'link');
        elements.fileFields.classList.toggle('d-none', type !== 'file');
        elements.uploadFields.classList.toggle('d-none', !isUpload);
        elements.encryptSwitchRow.classList.toggle('d-none', isUpload);
        if (isUpload) {
            elements.encrypted.checked = false;
        }
        elements.passwordFields.classList.toggle('d-none', isUpload || !elements.encrypted.checked);
    }

    function buildOneLiners(uploadUrl) {
        const separator = uploadUrl.includes('?') ? '&' : '?';
        return {
            multipart: `curl -F "file=@/path/to/file" "${uploadUrl}"`,
            raw: `curl --data-binary @/path/to/file "${uploadUrl}${separator}filename=file"`,
            mikrotik: `/tool fetch http-method=post url="${uploadUrl}${separator}filename=psst.rsc" http-data=[/export]`,
        };
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
            meta.textContent = `${formatBytes(file.file_size)} · ${file.created_at}`;
            info.appendChild(name);
            info.appendChild(meta);

            const remove = document.createElement('button');
            remove.className = 'btn btn-sm btn-outline-danger flex-shrink-0';
            remove.type = 'button';
            remove.textContent = 'Delete';
            remove.addEventListener('click', () => deleteUploadFile(share, file));

            item.appendChild(info);
            item.appendChild(remove);
            elements.uploadFileList.appendChild(item);
        });
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
        const refreshed = state.shares.find((item) => item.uuid === share.uuid);
        if (refreshed && state.editing && state.editing.uuid === share.uuid) {
            state.editing = refreshed;
            renderUploadInfo(refreshed);
        }
    }

    function resetForm() {
        state.editing = null;
        elements.shareForm.reset();
        elements.uuid.value = '';
        elements.uploadToken.checked = true;
        elements.formTitle.textContent = 'New share';
        elements.saveButton.textContent = 'Create share';
        elements.cancelEditButton.classList.add('d-none');
        renderUploadInfo(null);
        updateFieldVisibility();
    }

    function securityText(share) {
        const flags = [];
        if (share.is_encrypted) {
            flags.push('Encrypted');
        }
        if (share.requires_secret_code) {
            flags.push('Secret / ITI');
        }
        return flags.length ? flags.join(', ') : 'Open';
    }

    function renderShares() {
        elements.shareCount.textContent = `${state.shares.length} ${state.shares.length === 1 ? 'share' : 'shares'}`;

        if (!state.shares.length) {
            elements.table.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-4">No shares yet.</td></tr>';
            return;
        }

        elements.table.innerHTML = '';
        state.shares.forEach((share) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="fw-semibold share-title"></div>
                    <a class="small" href="${share.url}" target="_blank" rel="noopener noreferrer"></a>
                </td>
                <td><span class="badge text-bg-secondary text-uppercase"></span></td>
                <td class="small text-muted"></td>
                <td class="text-end"><div class="action-group"></div></td>
            `;

            row.querySelector('.share-title').textContent = share.title || share.uuid;
            const link = row.querySelector('a');
            link.textContent = share.url;
            row.querySelector('.badge').textContent = share.type;
            row.querySelector('td:nth-child(3)').textContent = securityText(share);

            const actions = row.querySelector('.action-group');
            actions.appendChild(actionButton('Copy', () => copyText(share.url)));
            actions.appendChild(actionButton('QR', () => openQr(share)));
            actions.appendChild(actionButton('Edit', () => editShare(share)));
            actions.appendChild(actionButton('Delete', () => deleteShare(share), 'outline-danger'));

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

    async function copyText(value) {
        await navigator.clipboard.writeText(value);
        setAlert('Copied.');
    }

    function editShare(share) {
        state.editing = share;
        elements.uuid.value = share.uuid;
        elements.type.value = share.type;
        elements.title.value = share.title || '';
        elements.encrypted.checked = share.is_encrypted;
        elements.text.value = share.type === 'text' && !share.is_encrypted ? (share.content || '') : '';
        elements.link.value = share.type === 'link' && !share.is_encrypted ? (share.content || '') : '';
        elements.secretCode.value = '';
        elements.password.value = '';
        elements.formTitle.textContent = 'Edit share';
        elements.saveButton.textContent = 'Save changes';
        elements.cancelEditButton.classList.remove('d-none');
        renderUploadInfo(share);
        updateFieldVisibility();
    }

    async function deleteShare(share) {
        if (!window.confirm(`Delete ${share.title || share.uuid}?`)) {
            return;
        }
        await api('delete', { method: 'POST', query: `&uuid=${encodeURIComponent(share.uuid)}` });
        setAlert('Share deleted.');
        await loadShares();
    }

    async function loadShares() {
        const data = await api('shares');
        state.shares = data.shares || [];
        renderShares();
    }

    function appendCommonFields(formData) {
        formData.set('type', elements.type.value);
        formData.set('title', elements.title.value.trim());
        formData.set('is_encrypted', elements.encrypted.checked ? '1' : '0');
        if (elements.secretCode.value.trim() !== '') {
            formData.set('secret_code', elements.secretCode.value.trim());
        }
    }

    async function buildPayload() {
        const formData = new FormData();
        const type = elements.type.value;
        const encrypted = elements.encrypted.checked;
        appendCommonFields(formData);

        if (type === 'upload') {
            formData.set('require_token', elements.uploadToken.checked ? '1' : '0');
            return formData;
        }

        if (!encrypted) {
            if (type === 'text') {
                formData.set('content', elements.text.value);
            } else if (type === 'link') {
                formData.set('content', elements.link.value);
            } else if (elements.file.files[0]) {
                formData.set('file', elements.file.files[0]);
            }
            return formData;
        }

        const password = elements.password.value;
        if (password.length < 8) {
            throw new Error('Encryption password must be at least 8 characters.');
        }

        if (type === 'text' || type === 'link') {
            const plain = type === 'text' ? elements.text.value : elements.link.value;
            const encryptedText = await window.PsstCrypto.encryptText(plain, password);
            formData.set('content', encryptedText.base64);
            formData.set('encryption_meta', JSON.stringify({ kind: type, crypto: encryptedText.meta }));
            return formData;
        }

        const file = elements.file.files[0];
        if (!file && !state.editing) {
            throw new Error('Choose a file to encrypt.');
        }
        if (!file) {
            return formData;
        }

        const plainBytes = new Uint8Array(await file.arrayBuffer());
        const encryptedFile = await window.PsstCrypto.encryptBytes(plainBytes, password);
        const blob = new Blob([encryptedFile.bytes], { type: 'application/octet-stream' });
        formData.set('file', blob, `${file.name}.psst`);
        formData.set('encryption_meta', JSON.stringify({
            kind: 'file',
            originalName: file.name,
            originalMime: file.type || 'application/octet-stream',
            originalSize: file.size,
            crypto: encryptedFile.meta,
        }));
        return formData;
    }

    async function saveShare(event) {
        event.preventDefault();
        elements.saveButton.disabled = true;
        try {
            const payload = await buildPayload();
            const action = state.editing ? 'update' : 'create';
            const query = state.editing ? `&uuid=${encodeURIComponent(state.editing.uuid)}` : '';
            const result = await api(action, { method: 'POST', query, body: payload });
            setAlert(state.editing ? 'Share updated.' : 'Share created.');
            const savedShare = result && result.share ? result.share : null;
            resetForm();
            await loadShares();
            // Keep upload shares open in edit mode so the owner sees the URL + one-liners.
            if (savedShare && savedShare.type === 'upload') {
                const refreshed = state.shares.find((item) => item.uuid === savedShare.uuid) || savedShare;
                editShare(refreshed);
            }
        } catch (error) {
            setAlert(error.message || 'Unable to save share.', 'danger');
        } finally {
            elements.saveButton.disabled = false;
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

        return mode === 'iti' ? state.qrShare.iti_url : state.qrShare.url;
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
        elements.shareForm.addEventListener('submit', saveShare);
        elements.cancelEditButton.addEventListener('click', resetForm);
        elements.refreshButton.addEventListener('click', loadShares);
        elements.copyQrUrlButton.addEventListener('click', () => copyText(elements.qrUrl.value));
        elements.downloadQrButton.addEventListener('click', downloadQr);
        elements.downloadQrPdfButton.addEventListener('click', downloadQrPdf);
        elements.copyUploadUrl.addEventListener('click', () => copyText(elements.uploadUrl.value));
        document.querySelectorAll('button[data-copy-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.getElementById(button.dataset.copyTarget);
                if (target) {
                    copyText(target.value);
                }
            });
        });
        document.querySelectorAll('input[name="qr-mode"]').forEach((input) => input.addEventListener('change', renderQr));

        elements.loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            elements.loginError.classList.add('d-none');
            const submitButton = elements.loginForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            try {
                await api('login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('login-username').value,
                        password: document.getElementById('login-password').value,
                        htp: document.getElementById('login-htp').value,
                    }),
                });
                showManage();
                await loadShares();
            } catch (error) {
                elements.loginError.textContent = error.message || 'Sign in failed.';
                elements.loginError.classList.remove('d-none');
            } finally {
                submitButton.disabled = false;
            }
        });

        elements.logoutButton.addEventListener('click', async () => {
            await api('logout', { method: 'POST' });
            showLogin();
        });

        updateFieldVisibility();
        const status = await api('status');
        if (status.authenticated) {
            showManage();
            await loadShares();
        } else {
            showLogin();
        }
    }

    init().catch((error) => setAlert(error.message || 'Unable to start PSST.', 'danger'));
}());