(function () {
    'use strict';

    const textEncoder = new TextEncoder();
    const textDecoder = new TextDecoder();
    const iterations = 250000;

    function bytesToBase64(bytes) {
        let binary = '';
        for (let index = 0; index < bytes.length; index += 1) {
            binary += String.fromCharCode(bytes[index]);
        }
        return btoa(binary);
    }

    function base64ToBytes(value) {
        const binary = atob(value);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }
        return bytes;
    }

    async function deriveKey(password, salt, kdfIterations) {
        const keyMaterial = await crypto.subtle.importKey(
            'raw',
            textEncoder.encode(password),
            'PBKDF2',
            false,
            ['deriveKey']
        );

        return crypto.subtle.deriveKey(
            { name: 'PBKDF2', hash: 'SHA-256', salt, iterations: kdfIterations },
            keyMaterial,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );
    }

    async function encryptBytes(bytes, password) {
        if (password.length < 8) {
            throw new Error('Encryption password must be at least 8 characters.');
        }

        const salt = crypto.getRandomValues(new Uint8Array(16));
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const key = await deriveKey(password, salt, iterations);
        const encrypted = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, bytes));

        return {
            bytes: encrypted,
            base64: bytesToBase64(encrypted),
            meta: {
                algorithm: 'AES-GCM',
                kdf: 'PBKDF2-SHA-256',
                iterations,
                salt: bytesToBase64(salt),
                iv: bytesToBase64(iv),
            },
        };
    }

    async function decryptBytes(payload, password, meta) {
        if (!meta || meta.algorithm !== 'AES-GCM') {
            throw new Error('Unsupported encrypted share format.');
        }

        const encrypted = typeof payload === 'string' ? base64ToBytes(payload) : new Uint8Array(payload);
        const salt = base64ToBytes(meta.salt);
        const iv = base64ToBytes(meta.iv);
        const key = await deriveKey(password, salt, Number(meta.iterations || iterations));
        return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, encrypted));
    }

    async function encryptText(value, password) {
        return encryptBytes(textEncoder.encode(value), password);
    }

    async function decryptText(value, password, meta) {
        return textDecoder.decode(await decryptBytes(value, password, meta));
    }

    window.PsstCrypto = {
        bytesToBase64,
        base64ToBytes,
        encryptBytes,
        decryptBytes,
        encryptText,
        decryptText,
    };
}());