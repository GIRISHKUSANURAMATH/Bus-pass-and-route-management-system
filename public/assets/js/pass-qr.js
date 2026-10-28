/* Renders the bus-pass verification QR locally (no external service). */
(function () {
  'use strict';
  document.querySelectorAll('[data-qr]').forEach((el) => {
    const qr = qrcode(0, 'M');           // auto version, medium error correction
    qr.addData(el.dataset.qr);
    qr.make();
    const cells = qr.getModuleCount(), scale = 6, margin = 4 * scale;
    const size = cells * scale + margin * 2;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000';
    for (let r = 0; r < cells; r++) for (let c = 0; c < cells; c++) {
      if (qr.isDark(r, c)) ctx.fillRect(margin + c * scale, margin + r * scale, scale, scale);
    }
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', 'Bus pass verification QR code');
    el.appendChild(canvas);
    const dl = document.getElementById('downloadQr');
    if (dl) dl.href = canvas.toDataURL('image/png');
  });
})();
