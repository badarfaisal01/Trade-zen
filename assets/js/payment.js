(() => {
  'use strict';

  document.querySelectorAll('[data-payment-copy]').forEach(button => {
    button.addEventListener('click', async () => {
      const value = button.dataset.paymentCopy || '';
      try {
        await navigator.clipboard.writeText(value);
        const original = button.textContent;
        button.textContent = 'Copied';
        window.setTimeout(() => { button.textContent = original; }, 1400);
      } catch (error) {
        button.textContent = 'Select & copy';
      }
    });
  });

  const order = document.querySelector('[data-payment-order]');
  if (!order) return;
  const countdown = order.querySelector('[data-payment-countdown]');
  const form = order.querySelector('[data-payment-confirm-form]');
  const expiresAt = Number(order.dataset.paymentExpiresAt) || 0;
  let reloadScheduled = false;

  const updateCountdown = () => {
    const remaining = Math.max(0, expiresAt - Date.now());
    const seconds = Math.ceil(remaining / 1000);
    const minutes = Math.floor(seconds / 60);
    const tail = seconds % 60;
    if (countdown) countdown.textContent = `${String(minutes).padStart(2, '0')}:${String(tail).padStart(2, '0')}`;
    if (remaining > 0) return;
    order.classList.add('is-expired');
    if (form) {
      form.querySelectorAll('input, textarea, button').forEach(control => { control.disabled = true; });
      const submit = form.querySelector('button[type="submit"]');
      if (submit) submit.textContent = 'Order expired';
    }
    if (!reloadScheduled) {
      reloadScheduled = true;
      window.setTimeout(() => window.location.reload(), 900);
    }
  };

  updateCountdown();
  window.setInterval(updateCountdown, 1000);

  const cancelForm = order.querySelector('[data-cancel-payment-order]');
  if (cancelForm) cancelForm.addEventListener('submit', event => {
    if (!window.confirm('Cancel this payment order?')) event.preventDefault();
  });

  if (form) form.addEventListener('submit', event => {
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    const submit = form.querySelector('button[type="submit"]');
    if (submit) {
      submit.disabled = true;
      submit.textContent = 'Sending confirmation…';
    }
  });
})();
