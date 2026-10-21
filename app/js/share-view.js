(function () {
    'use strict';

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
            throw new Error('Copy failed.');
        }
    }

    async function copyValue(value) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return;
            } catch (error) {
                // fall through to the selection copy
            }
        }
        fallbackCopy(value);
    }

    async function copyWithFeedback(button, value) {
        const label = button.textContent;
        try {
            await copyValue(value);
            button.textContent = 'Copied';
        } catch (error) {
            button.textContent = 'Copy failed';
        }
        window.setTimeout(() => {
            button.textContent = label;
        }, 1500);
    }

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.copyTarget);
            if (target) {
                copyWithFeedback(button, 'value' in target ? target.value : target.textContent);
            }
        });
    });

    // Saves the page's own copy of the source, so downloading does not use up another view.
    document.querySelectorAll('[data-download-source]').forEach((button) => {
        button.addEventListener('click', () => {
            const source = document.getElementById(button.dataset.downloadSource);
            if (!source) {
                return;
            }
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([source.value], { type: 'text/markdown;charset=utf-8' }));
            link.download = button.dataset.filename || 'document.md';
            document.body.appendChild(link);
            link.click();
            window.setTimeout(() => {
                URL.revokeObjectURL(link.href);
                link.remove();
            }, 1000);
        });
    });

    const form = document.getElementById('encrypted-share');
    if (!form) {
        return;
    }

    const share = JSON.parse(form.dataset.share || '{}');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('toggle-password');
    const decryptButton = document.getElementById('decrypt-button');
    const alertBox = document.getElementById('decrypt-alert');
    const output = document.getElementById('decrypted-output');

    function showError(message) {
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
    }

    function clearError() {
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
    }

    function formatBytes(bytes) {
        if (!bytes || bytes < 0) {
            return '';
        }
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let index = 0;
        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index += 1;
        }
        return (value >= 10 || index === 0) ? `${Math.round(value)} ${units[index]}` : `${value.toFixed(1)} ${units[index]}`;
    }

    function copyButton(getValue) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary';
        button.textContent = 'Copy';
        button.addEventListener('click', () => copyWithFeedback(button, getValue()));
        return button;
    }

    function showText(text) {
        output.innerHTML = '';
        const pre = document.createElement('pre');
        pre.className = 'decrypted-text border rounded p-3 bg-light mb-2';
        pre.textContent = text;
        output.appendChild(pre);
        output.appendChild(copyButton(() => text));
    }

    function showLink(url) {
        output.innerHTML = '';
        const target = document.createElement('div');
        target.className = 'decrypted-text border rounded p-2 bg-light small mb-2';
        target.textContent = url;
        output.appendChild(target);

        const actions = document.createElement('div');
        actions.className = 'd-flex gap-2';
        // Only real web links become clickable; anything else is shown as text.
        if (/^https?:\/\//i.test(url)) {
            const link = document.createElement('a');
            link.className = 'btn btn-primary';
            link.href = url;
            link.rel = 'noopener noreferrer';
            link.textContent = 'Open link';
            actions.appendChild(link);
        }
        actions.appendChild(copyButton(() => url));
        output.appendChild(actions);
    }

    function showFile(bytes, meta) {
        output.innerHTML = '';
        const name = meta.originalName || 'decrypted-file';
        const blob = new Blob([bytes], { type: meta.originalMime || 'application/octet-stream' });
        const link = document.createElement('a');
        link.className = 'btn btn-primary';
        link.href = URL.createObjectURL(blob);
        link.download = name;
        link.textContent = 'Download again';

        const info = document.createElement('p');
        info.className = 'mb-2 text-break';
        info.textContent = `${name} ${formatBytes(bytes.length) ? `(${formatBytes(bytes.length)})` : ''}`.trim();

        output.appendChild(info);
        output.appendChild(link);
        link.click();
    }

    // The ciphertext is fetched once per page: fetching counts as a view, so retrying a
    // mistyped password must not use up another one.
    let payload = null;

    function rawUrl() {
        // Relative to the current page, so a secret code passed in the URL is kept.
        const url = new URL(window.location.href);
        url.searchParams.set('raw', '1');
        url.searchParams.delete('download');
        return url.toString();
    }

    function loadPayload() {
        if (!payload) {
            payload = fetch(rawUrl(), { credentials: 'same-origin', cache: 'no-store' })
                .then(async (response) => {
                    if (response.status === 404 || response.status === 410) {
                        throw new Error('This share is no longer available.');
                    }
                    if (!response.ok) {
                        throw new Error('Unable to load the encrypted share. Try again.');
                    }
                    return share.type === 'file'
                        ? new Uint8Array(await response.arrayBuffer())
                        : (await response.text()).trim();
                })
                .catch((error) => {
                    payload = null;
                    throw error;
                });
        }
        return payload;
    }

    togglePassword.addEventListener('click', () => {
        const hidden = passwordInput.type === 'password';
        passwordInput.type = hidden ? 'text' : 'password';
        togglePassword.textContent = hidden ? 'Hide' : 'Show';
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        output.classList.add('d-none');
        const password = passwordInput.value;

        if (password.length < 8) {
            showError('Password must be at least 8 characters.');
            return;
        }

        decryptButton.disabled = true;
        decryptButton.textContent = 'Decrypting…';
        try {
            const meta = share.encryption_meta || {};
            const encrypted = await loadPayload();
            let decrypted;
            try {
                decrypted = await window.PsstCrypto.decryptBytes(encrypted, password, meta.crypto);
            } catch (error) {
                throw new Error(error.message === 'Unsupported encrypted share format.'
                    ? error.message
                    : 'Wrong password, or the share is damaged.');
            }

            if (share.type === 'file') {
                showFile(decrypted, meta);
            } else {
                const text = new TextDecoder().decode(decrypted);
                if (share.type === 'link') {
                    showLink(text);
                } else {
                    showText(text);
                }
            }
            output.classList.remove('d-none');
        } catch (error) {
            showError(error.message || 'Unable to decrypt this share.');
        } finally {
            decryptButton.disabled = false;
            decryptButton.textContent = 'Decrypt';
        }
    });
}());
