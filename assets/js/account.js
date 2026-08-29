(() => {
  'use strict';

  const menuToggle = document.querySelector('[data-account-menu-toggle]');
  const accountMenu = document.querySelector('[data-account-menu]');
  if (menuToggle && accountMenu) {
    const closeMenu = () => {
      accountMenu.hidden = true;
      menuToggle.setAttribute('aria-expanded', 'false');
    };
    menuToggle.addEventListener('click', event => {
      event.stopPropagation();
      const opening = accountMenu.hidden;
      accountMenu.hidden = !opening;
      menuToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });
    document.addEventListener('click', event => {
      if (!accountMenu.hidden && !accountMenu.contains(event.target) && !menuToggle.contains(event.target)) closeMenu();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeMenu();
    });
  }

  document.querySelectorAll('[data-dismissible-banner]').forEach((banner, index) => {
    const key = `55ak-banner:${window.location.pathname}:${index}`;
    try {
      if (window.sessionStorage.getItem(key) === 'dismissed') banner.classList.add('is-dismissed');
    } catch (error) {
      // The banner still works when browser storage is unavailable.
    }
    const dismiss = banner.querySelector('[data-dismiss-banner]');
    if (!dismiss) return;
    dismiss.addEventListener('click', () => {
      banner.classList.add('is-dismissed');
      try {
        window.sessionStorage.setItem(key, 'dismissed');
      } catch (error) {
        // No persistence is required for the request workflow.
      }
    });
  });

  const avatarInput = document.querySelector('[data-avatar-input]');
  const avatarPreview = document.querySelector('[data-avatar-preview]');
  if (avatarInput && avatarPreview) {
    avatarInput.addEventListener('change', () => {
      const file = avatarInput.files && avatarInput.files[0];
      if (!file || !file.type.startsWith('image/')) return;
      if (!window.URL || typeof window.URL.createObjectURL !== 'function') return;
      const image = document.createElement('img');
      image.alt = 'Selected profile photo';
      image.src = window.URL.createObjectURL(file);
      image.addEventListener('load', () => window.URL.revokeObjectURL(image.src), {once: true});
      avatarPreview.replaceChildren(image);
    });
  }

  const verificationModal = document.querySelector('[data-verification-modal]');
  if (verificationModal) {
    const verificationKey = '55ak-verification-later';
    try {
      if (window.sessionStorage.getItem(verificationKey) === '1') verificationModal.remove();
    } catch (error) {
      // The prompt can still be dismissed for the current page.
    }
    verificationModal.querySelectorAll('[data-verification-later]').forEach(button => button.addEventListener('click', () => {
      verificationModal.remove();
      try { window.sessionStorage.setItem(verificationKey, '1'); } catch (error) {}
    }));
    const startVerification = verificationModal.querySelector('[data-start-verification]');
    if (startVerification) startVerification.addEventListener('click', () => {
      const profileForm = document.getElementById('profileSettingsForm');
      const submitFlag = profileForm && profileForm.querySelector('[data-submit-verification]');
      if (!profileForm || !submitFlag) return;
      submitFlag.value = '1';
      profileForm.classList.add('is-verification-mode');
      const submit = profileForm.querySelector('button[type="submit"], .qx-profile-save');
      if (submit) submit.textContent = 'Submit for verification';
      verificationModal.remove();
      profileForm.scrollIntoView({behavior: 'smooth', block: 'center'});
      window.setTimeout(() => {
        const firstMissing = profileForm.querySelector('input[name="phone"]:placeholder-shown, input[name="date_of_birth"]:invalid');
        if (firstMissing) firstMissing.focus();
      }, 450);
    });
  }

  const currencyConsole = document.querySelector('[data-currency-console]');
  const exchangeDialog = document.getElementById('currencyExchangeDialog');
  if (currencyConsole && exchangeDialog) {
    const target = exchangeDialog.querySelector('[data-target-currency]');
    const exchangeForm = exchangeDialog.querySelector('[data-exchange-form]');
    let rates = {};
    try { rates = JSON.parse(currencyConsole.dataset.exchangeRates || '{}'); } catch (error) { rates = {}; }
    const current = String(currencyConsole.dataset.currentCurrency || 'USD').toUpperCase();
    const liveBalance = Math.max(0, Number(currencyConsole.dataset.liveBalance) || 0);
    const feePercent = Math.max(0, Math.min(25, Number(currencyConsole.dataset.exchangeFee) || 0));
    const symbols = {USD: '$', INR: '₹', EUR: '€', GBP: '£'};

    const updateExchangePreview = () => {
      const next = String(target && target.value || '').toUpperCase();
      const sourceRate = Number(rates[current]);
      const targetRate = Number(rates[next]);
      if (!next || !Number.isFinite(sourceRate) || sourceRate <= 0 || !Number.isFinite(targetRate) || targetRate <= 0) return;
      const ratio = targetRate / sourceRate;
      const received = Math.max(0, Math.round((liveBalance * ratio * (1 - feePercent / 100)) * 100) / 100);
      const from = exchangeDialog.querySelector('[data-exchange-from]');
      const to = exchangeDialog.querySelector('[data-exchange-to]');
      const rate = exchangeDialog.querySelector('[data-exchange-rate]');
      const sourceSymbol = exchangeDialog.querySelector('[data-exchange-source-symbol]');
      const targetSymbol = exchangeDialog.querySelector('[data-exchange-target-symbol]');
      if (from) from.textContent = `${liveBalance.toFixed(2)} ${current}`;
      if (to) to.textContent = `${received.toFixed(2)} ${next}`;
      if (rate) rate.textContent = `Operator rate: 1 ${current} = ${ratio.toFixed(6)} ${next} · Exchange fee ${feePercent.toFixed(2)}%`;
      if (sourceSymbol) sourceSymbol.textContent = symbols[current] || current;
      if (targetSymbol) targetSymbol.textContent = symbols[next] || next;
    };
    if (target) target.addEventListener('change', updateExchangePreview);
    updateExchangePreview();

    document.querySelectorAll('[data-open-exchange]').forEach(button => button.addEventListener('click', () => {
      updateExchangePreview();
      if (typeof exchangeDialog.showModal === 'function') exchangeDialog.showModal();
      else exchangeDialog.setAttribute('open', '');
    }));
    exchangeDialog.querySelectorAll('[data-close-exchange]').forEach(button => button.addEventListener('click', () => {
      if (typeof exchangeDialog.close === 'function') exchangeDialog.close();
      else exchangeDialog.removeAttribute('open');
    }));
    exchangeDialog.addEventListener('click', event => {
      if (event.target === exchangeDialog && typeof exchangeDialog.close === 'function') exchangeDialog.close();
    });
    if (exchangeForm) exchangeForm.addEventListener('submit', event => {
      if (!target || !target.value || exchangeForm.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      exchangeForm.dataset.submitting = '1';
      const submit = exchangeForm.querySelector('button[type="submit"]');
      if (submit) {
        submit.disabled = true;
        submit.textContent = 'Exchanging…';
      }
    });
  }

  const promoDialog = document.querySelector('[data-promo-dialog]');
  if (promoDialog) {
    document.querySelectorAll('[data-open-promo-code]').forEach(button => button.addEventListener('click', () => {
      if (typeof promoDialog.showModal === 'function') promoDialog.showModal();
      else promoDialog.setAttribute('open', '');
    }));
    promoDialog.querySelectorAll('[data-close-promo]').forEach(button => button.addEventListener('click', () => {
      if (typeof promoDialog.close === 'function') promoDialog.close();
      else promoDialog.removeAttribute('open');
    }));
    promoDialog.addEventListener('click', event => {
      if (event.target === promoDialog && typeof promoDialog.close === 'function') promoDialog.close();
    });
    document.querySelectorAll('[data-show-promo-list]').forEach(button => button.addEventListener('click', () => {
      const card = button.closest('.qx-promo-card');
      if (!card) return;
      card.classList.toggle('show-all');
      button.lastChild.textContent = card.classList.contains('show-all') ? ' Hide' : ' Show all';
    }));
  }

  const page = document.querySelector('.wallet-page');
  const form = document.getElementById('fundRequestForm');
  if (!page || !form) return;

  const typeInput = document.getElementById('requestType');
  const walletBalance = Math.max(0, Number(page.dataset.walletBalance) || 0);
  const amountInput = form.querySelector('input[name="amount"]');
  const submit = document.getElementById('requestSubmit');
  const referenceInput = form.querySelector('input[name="user_reference"]');
  const proofInput = form.querySelector('input[name="proof_image"]');
  const destinationInput = form.querySelector('input[name="account_identifier"]');
  const destinationLabel = document.getElementById('withdrawDestinationLabel');
  const proofRequirement = document.getElementById('proofRequirement');
  const feeSummary = document.getElementById('methodFeeSummary');
  const selectedMethodSummary = document.getElementById('selectedMethodSummary');
  const flowTitle = document.getElementById('paymentFlowTitle');
  const methodCaption = document.querySelector('[data-method-caption]');
  const typeButtons = Array.from(document.querySelectorAll('[data-request-type]'));
  const filterButtons = Array.from(document.querySelectorAll('[data-method-filter]'));
  let activeFilter = 'popular';

  const selectedMethod = () => form.querySelector(`input[name="payment_method_id"][data-flow="${typeInput.value}"]:checked`);

  const updateFee = () => {
    const selected = selectedMethod();
    const amount = Math.max(0, Number(amountInput.value) || 0);
    const percent = selected ? Number(selected.dataset.feePercent) || 0 : 0;
    const fixed = selected ? Number(selected.dataset.feeFixed) || 0 : 0;
    const fee = Math.round(((amount * percent / 100) + fixed) * 100) / 100;
    feeSummary.hidden = !selected || fee <= 0;
    if (fee <= 0) return;
    const total = Math.round((amount + fee) * 100) / 100;
    feeSummary.textContent = typeInput.value === 'withdrawal'
      ? `Method fee ${fee.toFixed(2)} · wallet debit ${total.toFixed(2)} · destination amount ${amount.toFixed(2)}`
      : `Method fee ${fee.toFixed(2)} · payment total ${total.toFixed(2)} · wallet credit ${amount.toFixed(2)}`;
  };

  const applyFilter = (filter, selectVisible = false) => {
    activeFilter = ['popular', 'epay', 'crypto'].includes(filter) ? filter : 'popular';
    filterButtons.forEach(button => {
      const active = button.dataset.methodFilter === activeFilter;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    if (methodCaption) methodCaption.textContent = activeFilter === 'popular' ? 'Popular in your region' : (activeFilter === 'crypto' ? 'Crypto' : 'E-pay');

    const group = document.querySelector(`[data-method-flow="${typeInput.value}"]`);
    if (!group) return;
    const rows = Array.from(group.querySelectorAll('[data-method-card]'));
    let visibleRows = 0;
    rows.forEach(row => {
      const visible = activeFilter === 'popular'
        ? row.dataset.methodPopular === '1'
        : row.dataset.methodCategory === activeFilter;
      row.hidden = !visible;
      if (visible) visibleRows += 1;
    });
    if (visibleRows === 0 && rows.length && activeFilter !== 'popular') {
      applyFilter('popular', selectVisible);
      return;
    }
    const empty = group.querySelector('[data-method-filter-empty]');
    if (empty) empty.hidden = visibleRows > 0;

    const selected = selectedMethod();
    const selectedRow = selected ? selected.closest('[data-method-card]') : null;
    if (selectVisible && rows.length && (!selectedRow || selectedRow.hidden)) {
      const firstVisible = rows.find(row => !row.hidden);
      const input = firstVisible ? firstVisible.querySelector('input[name="payment_method_id"]') : null;
      if (input) input.checked = true;
    }
  };

  const showMethod = () => {
    const selected = selectedMethod();
    document.querySelectorAll('[data-method-detail]').forEach(detail => {
      detail.hidden = !selected || detail.dataset.methodDetail !== selected.value;
    });
    document.querySelectorAll('[data-method-card]').forEach(card => {
      const input = card.querySelector('input[type="radio"]');
      card.classList.toggle('selected', Boolean(input && input.checked));
    });

    const deposit = typeInput.value === 'deposit';
    const referenceRequired = Boolean(selected && selected.dataset.requireReference === '1' && deposit);
    const proofRequired = Boolean(selected && selected.dataset.requireProof === '1' && deposit);
    if (referenceInput) referenceInput.required = referenceRequired;
    if (proofInput) proofInput.required = proofRequired;
    if (destinationInput) destinationInput.required = !deposit;
    if (proofRequirement) {
      proofRequirement.textContent = proofRequired
        ? 'Required for the selected payment method.'
        : 'Optional for this payment method, but useful for faster verification.';
    }

    const network = selected ? selected.dataset.network : '';
    destinationLabel.textContent = network
      ? `${network} destination / wallet address`
      : 'UPI ID, bank account, payment ID, or wallet address';

    if (selected) {
      const minimum = Number(selected.dataset.minimum) || 0.01;
      const methodMaximum = Number(selected.dataset.maximum) || 100000000;
      const feePercent = Math.max(0, Number(selected.dataset.feePercent) || 0);
      const feeFixed = Math.max(0, Number(selected.dataset.feeFixed) || 0);
      // For withdrawals the server reserves amount + fee. Calculate the
      // largest request amount that can actually fit inside the wallet, so
      // browser validation and server validation agree even for fee methods.
      const walletAmountLimit = Math.max(0, (walletBalance - feeFixed) / (1 + (feePercent / 100)));
      const maximum = deposit ? methodMaximum : Math.min(methodMaximum, Math.floor(walletAmountLimit * 100) / 100);
      if (minimum > 0) amountInput.min = String(minimum);
      amountInput.max = String(Math.max(0, maximum));
      const insufficientForWithdrawal = !deposit && maximum + 0.000001 < minimum;
      submit.disabled = insufficientForWithdrawal;
      submit.textContent = insufficientForWithdrawal
        ? 'Insufficient balance for withdrawal'
        : (deposit ? 'Pay' : 'Submit withdrawal request');
      if (selectedMethodSummary) {
        selectedMethodSummary.textContent = insufficientForWithdrawal
          ? `Minimum ${minimum.toFixed(2)} · available ${walletBalance.toFixed(2)}`
          : (deposit
            ? `Order via ${selected.dataset.methodName || 'selected method'} · price ${Math.max(0, Number(amountInput.value) || 0).toFixed(2)}`
            : `${selected.dataset.methodName || 'Selected method'} · withdrawal details`);
      }
    } else {
      submit.disabled = true;
      submit.textContent = `No ${typeInput.value} method available`;
    }
    updateFee();
  };

  const setType = type => {
    typeInput.value = type === 'withdrawal' ? 'withdrawal' : 'deposit';
    typeButtons.forEach(button => button.classList.toggle('active', button.dataset.requestType === typeInput.value));
    document.querySelectorAll('[data-method-flow]').forEach(group => {
      group.hidden = group.dataset.methodFlow !== typeInput.value;
    });
    document.querySelectorAll('[data-flow-fields]').forEach(group => {
      group.hidden = group.dataset.flowFields !== typeInput.value;
    });
    let selected = selectedMethod();
    if (!selected) {
      selected = form.querySelector(`input[name="payment_method_id"][data-flow="${typeInput.value}"]`);
      if (selected) selected.checked = true;
    }
    applyFilter(activeFilter, true);
    if (flowTitle) flowTitle.textContent = typeInput.value === 'withdrawal' ? 'Withdrawal' : 'Deposit';
    showMethod();
  };

  typeButtons.forEach(button => button.addEventListener('click', () => setType(button.dataset.requestType)));
  filterButtons.forEach(button => button.addEventListener('click', () => {
    applyFilter(button.dataset.methodFilter, true);
    showMethod();
  }));
  form.querySelectorAll('input[name="payment_method_id"]').forEach(input => input.addEventListener('change', showMethod));
  form.querySelectorAll('[data-amount], [data-wallet-percent]').forEach(button => button.addEventListener('click', () => {
    if (button.dataset.walletPercent) {
      const selected = selectedMethod();
      const percent = Math.max(0.01, Math.min(1, Number(button.dataset.walletPercent) || 1));
      const feePercent = selected ? Math.max(0, Number(selected.dataset.feePercent) || 0) : 0;
      const feeFixed = selected ? Math.max(0, Number(selected.dataset.feeFixed) || 0) : 0;
      const methodMaximum = selected ? Math.max(0, Number(selected.dataset.maximum) || 100000000) : 100000000;
      const walletAmountLimit = Math.max(0, (walletBalance - feeFixed) / (1 + (feePercent / 100)));
      const usableMaximum = Math.min(walletAmountLimit, methodMaximum);
      amountInput.value = (Math.floor((usableMaximum * percent) * 100) / 100).toFixed(2);
    } else {
      amountInput.value = button.dataset.amount;
    }
    amountInput.focus();
    updateFee();
  }));
  amountInput.addEventListener('input', () => {
    updateFee();
    showMethod();
  });
  document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(button.dataset.copy || '');
      const old = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(() => { button.textContent = old; }, 1200);
    } catch (error) {
      button.textContent = 'Select & copy';
    }
  }));
  document.querySelectorAll('[data-apply-promo]').forEach(button => button.addEventListener('click', () => {
    setType('deposit');
    const promo = form.querySelector('input[name="promo_code"]');
    if (!promo) return;
    promo.value = button.dataset.applyPromo || '';
    promo.focus();
  }));
  form.addEventListener('submit', event => {
    const selected = selectedMethod();
    const amount = Number(amountInput.value) || 0;
    if (!selected) {
      event.preventDefault();
      return;
    }
    if (typeInput.value === 'withdrawal') {
      const percent = Math.max(0, Number(selected.dataset.feePercent) || 0);
      const fixed = Math.max(0, Number(selected.dataset.feeFixed) || 0);
      const debit = Math.round((amount + (amount * percent / 100) + fixed) * 100) / 100;
      if (debit > walletBalance + 0.000001) {
        event.preventDefault();
        amountInput.focus();
        if (selectedMethodSummary) selectedMethodSummary.textContent = `Wallet debit ${debit.toFixed(2)} exceeds available balance ${walletBalance.toFixed(2)}.`;
      }
    }
    if (event.defaultPrevented) return;
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    submit.disabled = true;
    submit.textContent = typeInput.value === 'deposit' ? 'Creating order…' : 'Submitting request…';
  });
  document.querySelectorAll('.cancel-request-form').forEach(cancelForm => cancelForm.addEventListener('submit', event => {
    if (!window.confirm('Cancel this pending request?')) event.preventDefault();
  }));

  setType(page.dataset.initialFlow || 'deposit');
})();
