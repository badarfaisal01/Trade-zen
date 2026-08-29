(() => {
  'use strict';

  const copyText = async value => {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    const copied = document.execCommand('copy');
    input.remove();
    if (!copied) throw new Error('Copy is unavailable');
  };

  const feedback = (button, message) => {
    const original = button.dataset.originalLabel || button.textContent;
    button.dataset.originalLabel = original;
    button.textContent = message;
    window.clearTimeout(button.feedbackTimer);
    button.feedbackTimer = window.setTimeout(() => { button.textContent = original; }, 1600);
  };

  document.querySelectorAll('[data-affiliate-copy]').forEach(button => {
    button.addEventListener('click', async () => {
      try {
        await copyText(button.dataset.affiliateCopy || '');
        feedback(button, 'Copied');
      } catch (error) {
        feedback(button, 'Copy failed');
      }
    });
  });

  document.querySelectorAll('[data-affiliate-share]').forEach(button => {
    button.addEventListener('click', async () => {
      const url = button.dataset.shareUrl || '';
      const code = button.dataset.shareCode || '';
      try {
        if (navigator.share) {
          await navigator.share({
            title: 'Join 55AK Trade',
            text: `Use my referral code ${code}`,
            url,
          });
          feedback(button, 'Shared');
        } else {
          await copyText(url);
          feedback(button, 'Link copied');
        }
      } catch (error) {
        if (error?.name !== 'AbortError') feedback(button, 'Share failed');
      }
    });
  });

  const qrHost = document.querySelector('[data-affiliate-qr]');
  let qrCanvas = null;
  if (qrHost && window.QRCodeMatrix) {
    try {
      const matrix = window.QRCodeMatrix.create(qrHost.dataset.qrValue || '', 'M');
      const quiet = 4;
      const scale = 7;
      const size = (matrix.length + quiet * 2) * scale;
      const canvas = document.createElement('canvas');
      canvas.width = size;
      canvas.height = size;
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', qrHost.getAttribute('aria-label') || 'Affiliate referral QR code');
      const context = canvas.getContext('2d');
      context.imageSmoothingEnabled = false;
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, size, size);
      context.fillStyle = '#101521';
      matrix.forEach((row, y) => row.forEach((dark, x) => {
        if (dark) context.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale);
      }));
      qrHost.replaceChildren(canvas);
      qrCanvas = canvas;
    } catch (error) {
      qrHost.textContent = 'QR unavailable';
    }
  }

  document.querySelectorAll('[data-affiliate-qr-download]').forEach(button => {
    button.addEventListener('click', () => {
      if (!qrCanvas) {
        feedback(button, 'QR unavailable');
        return;
      }
      const link = document.createElement('a');
      link.download = '55ak-affiliate-qr.png';
      link.href = qrCanvas.toDataURL('image/png');
      link.click();
      feedback(button, 'QR saved');
    });
  });
})();
