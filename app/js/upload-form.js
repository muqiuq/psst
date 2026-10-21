(function () {
    'use strict';

    const form = document.getElementById('upload-form');
    if (!form) {
        return;
    }

    const input = document.getElementById('file');
    const button = document.getElementById('upload-button');
    const status = document.getElementById('upload-status');
    const progress = document.getElementById('upload-progress');
    const progressBar = document.getElementById('upload-progress-bar');
    const space = document.getElementById('upload-space');
    const maxBytes = Number(form.dataset.maxBytes || 0);
    // Empty means the drop box has no storage limit.
    let remainingBytes = form.dataset.remainingBytes === '' ? null : Number(form.dataset.remainingBytes);

    function formatBytes(bytes) {
        if (!bytes || bytes < 0) {
            return '0 B';
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

    function setStatus(message, type) {
        status.textContent = message;
        status.className = `alert alert-${type} mt-3 mb-0`;
    }

    function clearStatus() {
        status.textContent = '';
        status.className = 'alert mt-3 mb-0 d-none';
    }

    function setProgress(percent) {
        progress.classList.remove('d-none');
        const value = Math.max(0, Math.min(100, Math.round(percent)));
        progressBar.style.width = `${value}%`;
        progressBar.textContent = `${value}%`;
        progress.setAttribute('aria-valuenow', String(value));
    }

    function hideProgress() {
        progress.classList.add('d-none');
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
    }

    function oversizedFile(files) {
        if (!maxBytes) {
            return null;
        }
        for (let index = 0; index < files.length; index += 1) {
            if (files[index].size > maxBytes) {
                return files[index];
            }
        }
        return null;
    }

    function totalSize(files) {
        let total = 0;
        for (let index = 0; index < files.length; index += 1) {
            total += files[index].size;
        }
        return total;
    }

    function selectionProblem(files) {
        const tooBig = oversizedFile(files);
        if (tooBig) {
            return `"${tooBig.name}" is ${formatBytes(tooBig.size)}, which exceeds the ${formatBytes(maxBytes)} limit.`;
        }
        if (remainingBytes !== null && totalSize(files) > remainingBytes) {
            return remainingBytes > 0
                ? `Not enough space left in this drop box: ${formatBytes(remainingBytes)} remaining.`
                : 'This drop box is full.';
        }
        return null;
    }

    function updateSpace(bytes) {
        if (bytes === null || bytes === undefined || !space) {
            return;
        }
        remainingBytes = Number(bytes);
        space.textContent = `Space left: ${formatBytes(remainingBytes)}.`;
        if (remainingBytes <= 0) {
            input.disabled = true;
            button.disabled = true;
        }
    }

    function describeError(xhr) {
        // Prefer the server message; a proxy or PHP limit may answer with HTML instead.
        const raw = (xhr.responseText || '').trim();
        if (raw.charAt(0) === '{') {
            try {
                const data = JSON.parse(raw);
                if (data && (data.error || data.message)) {
                    return data.error || data.message;
                }
            } catch (error) {
                // fall through to the generic handling below
            }
        }

        if (xhr.status === 0) {
            return 'The connection was lost during the upload. Check your network and try again.';
        }
        if (xhr.status === 413) {
            return maxBytes
                ? `The file is too large. The maximum accepted size is ${formatBytes(maxBytes)}.`
                : 'The file is too large for this server.';
        }
        if (xhr.status === 403) {
            return 'This drop box requires a valid upload token.';
        }
        if (xhr.status === 429) {
            return 'Too many requests. Wait a moment and try again.';
        }
        if (raw !== '' && raw.charAt(0) !== '<' && raw.length < 300) {
            return raw;
        }

        return `Upload failed (HTTP ${xhr.status}).`;
    }

    function upload(files) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            for (let index = 0; index < files.length; index += 1) {
                // Android pickers can hand over an empty name; provide a usable fallback.
                const file = files[index];
                const name = file.name && file.name !== '' ? file.name : `upload-${Date.now()}-${index}.bin`;
                formData.append('file[]', file, name);
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'), true);
            xhr.responseType = 'text';
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            // Mobile radios can stall; give large transfers plenty of room.
            xhr.timeout = 0;

            if (xhr.upload) {
                xhr.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        setProgress((event.loaded / event.total) * 100);
                    }
                });
            }

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    let data = null;
                    try {
                        data = JSON.parse(xhr.responseText || 'null');
                    } catch (error) {
                        data = null;
                    }
                    resolve(data);
                    return;
                }
                reject(new Error(describeError(xhr)));
            });
            xhr.addEventListener('error', () => reject(new Error(describeError(xhr))));
            xhr.addEventListener('abort', () => reject(new Error('The upload was cancelled.')));
            xhr.addEventListener('timeout', () => reject(new Error('The upload timed out. Try again on a stronger connection.')));

            xhr.send(formData);
        });
    }

    input.addEventListener('change', () => {
        clearStatus();
        hideProgress();
        const problem = selectionProblem(input.files || []);
        if (problem) {
            setStatus(problem, 'danger');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const files = input.files || [];
        if (!files.length) {
            setStatus('Choose a file to upload.', 'danger');
            return;
        }

        const problem = selectionProblem(files);
        if (problem) {
            setStatus(problem, 'danger');
            return;
        }

        button.disabled = true;
        clearStatus();
        setProgress(0);

        try {
            const result = await upload(files);
            hideProgress();
            setStatus(files.length === 1 ? 'Upload complete.' : `${files.length} files uploaded.`, 'success');
            form.reset();
            button.disabled = false;
            updateSpace(result ? result.remaining_bytes : null);
        } catch (error) {
            hideProgress();
            setStatus(error.message || 'Upload failed.', 'danger');
            button.disabled = false;
        }
    });
}());
