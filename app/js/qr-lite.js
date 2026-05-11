(function () {
    'use strict';

    function render(target, text) {
        target.innerHTML = '';

        if (typeof qrcode !== 'function') {
            target.textContent = 'QR generator is unavailable.';
            return;
        }

        try {
            const qr = qrcode(0, 'M');
            qr.addData(text, 'Byte');
            qr.make();

            const container = document.createElement('div');
            container.innerHTML = qr.createSvgTag({
                cellSize: 8,
                margin: 24,
                scalable: true,
                title: 'QR code',
                alt: 'QR code',
            });

            const svg = container.querySelector('svg');
            if (!svg) {
                throw new Error('QR SVG could not be created.');
            }

            svg.setAttribute('class', 'psst-qr-svg');
            target.appendChild(svg);
        } catch (error) {
            target.textContent = error.message || 'Unable to render QR code.';
        }
    }

    window.PsstQr = { render };
}());