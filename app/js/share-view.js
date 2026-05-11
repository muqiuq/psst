(function () {
    'use strict';

    const section = document.getElementById('encrypted-share');
    if (!section) {
        return;
    }

    const share = JSON.parse(section.dataset.share || '{}');
    const passwordInput = document.getElementById('password');
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

    function showText(text) {
        output.className = 'mt-3 decrypted-text border rounded p-3 bg-light';
        output.textContent = text;
    }

    function showLink(url) {
        output.className = 'mt-3';
        output.innerHTML = '';
        const link = document.createElement('a');
        link.className = 'btn btn-primary';
        link.href = url;
        link.rel = 'noopener noreferrer';
        link.textContent = 'Open link';
        output.appendChild(link);
    }

    function showFile(bytes, meta) {
        output.className = 'mt-3';
        output.innerHTML = '';
        const blob = new Blob([bytes], { type: meta.originalMime || 'application/octet-stream' });
        const link = document.createElement('a');
        link.className = 'btn btn-primary';
        link.href = URL.createObjectURL(blob);
        link.download = meta.originalName || 'decrypted-file';
        link.textContent = 'Download file';
        output.appendChild(link);
    }

    decryptButton.addEventListener('click', async () => {
        clearError();
        output.classList.add('d-none');
        const password = passwordInput.value;

        if (password.length < 8) {
            showError('Password must be at least 8 characters.');
            return;
        }

        decryptButton.disabled = true;
        try {
            const meta = share.encryption_meta || {};
            if (share.type === 'file') {
                const response = await fetch(`${share.download_url}&raw=1`, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('Unable to load encrypted file.');
                }
                const encryptedBytes = new Uint8Array(await response.arrayBuffer());
                const bytes = await window.PsstCrypto.decryptBytes(encryptedBytes, password, meta.crypto);
                showFile(bytes, meta);
            } else {
                const text = await window.PsstCrypto.decryptText(share.content, password, meta.crypto);
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
        }
    });
}());