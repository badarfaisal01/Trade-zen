(() => {
  'use strict';

  const state = {loading: false, lastInteraction: Date.now()};

  const enhanceSettingsForm = () => {
    const seed = document.getElementById('paymentOrderSettingSeed');
    const proofInput = document.querySelector('.settings-card input[name="payment_proof_max_mb"]');
    const grid = proofInput?.closest('.field-grid');
    if (!seed || !grid || grid.querySelector('input[name="payment_order_minutes"]')) return;
    const label = document.createElement('label');
    label.className = 'field';
    const caption = document.createElement('span');
    caption.textContent = 'Payment window (minutes)';
    const input = document.createElement('input');
    input.type = 'number';
    input.name = 'payment_order_minutes';
    input.min = '5';
    input.max = '60';
    input.step = '1';
    input.value = seed.dataset.paymentOrderMinutes || '15';
    label.append(caption, input);
    grid.append(label);
  };

  const ensureFlashStack = () => {
    let stack = document.querySelector('.admin-main .flash-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'flash-stack';
      const topbar = document.querySelector('.admin-topbar');
      const main = document.querySelector('.admin-main');
      if (main && topbar?.nextSibling) main.insertBefore(stack, topbar.nextSibling);
      else main?.prepend(stack);
    }
    return stack;
  };

  const showFlash = (type, message) => {
    const stack = ensureFlashStack();
    if (!stack) return;
    stack.querySelectorAll('.flash.is-temporary').forEach(node => node.remove());
    const flash = document.createElement('div');
    flash.className = `flash flash--${type} is-temporary`;
    flash.textContent = String(message || '');
    stack.prepend(flash);
    window.setTimeout(() => flash.remove(), 5000);
  };

  const setBusy = (form, busy) => {
    form.dataset.busy = busy ? '1' : '0';
    form.querySelectorAll('button, input[type="submit"]').forEach(button => {
      if (!button.dataset.originalText && 'textContent' in button) button.dataset.originalText = button.textContent;
      button.disabled = busy;
      if (busy && button.type === 'submit') button.textContent = 'Saving…';
      if (!busy && button.dataset.originalText) button.textContent = button.dataset.originalText;
    });
  };

  const isAdminUrl = value => {
    try {
      const url = new URL(value, window.location.href);
      return url.origin === window.location.origin && (/^\/admin\/?$/.test(url.pathname) || url.pathname === '/admin/index.php');
    } catch (_) {
      return false;
    }
  };

  const applyAdminDocument = (html, finalUrl, options = {}) => {
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const nextMain = parsed.querySelector('.admin-main');
    const nextSidebar = parsed.querySelector('.admin-sidebar');
    const currentMain = document.querySelector('.admin-main');
    const currentSidebar = document.querySelector('.admin-sidebar');
    if (!nextMain || !nextSidebar || !currentMain || !currentSidebar) return false;

    const scrollTop = window.scrollY;
    currentSidebar.replaceWith(document.importNode(nextSidebar, true));
    currentMain.replaceWith(document.importNode(nextMain, true));
    enhanceSettingsForm();
    document.body.classList.remove('admin-nav-open');
    document.title = parsed.title || document.title;
    if (options.history === 'push') history.pushState({admin: true}, '', finalUrl);
    if (options.history === 'replace') history.replaceState({admin: true}, '', finalUrl);
    if (options.preserveScroll) window.scrollTo({top: scrollTop, behavior: 'auto'});
    else window.scrollTo({top: 0, behavior: 'auto'});
    return true;
  };

  const loadAdminPage = async (url, options = {}) => {
    if (state.loading) return false;
    state.loading = true;
    document.querySelector('.admin-main')?.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {Accept: 'text/html', 'X-Admin-Navigation': '1'}
      });
      if (response.redirected && !isAdminUrl(response.url)) {
        window.location.assign(response.url);
        return false;
      }
      const html = await response.text();
      if (!response.ok) throw new Error(`Admin page failed (${response.status}).`);
      const finalUrl = response.url || new URL(url, window.location.href).href;
      if (!applyAdminDocument(html, finalUrl, options)) {
        window.location.assign(finalUrl);
        return false;
      }
      if (!options.silent) state.lastInteraction = Date.now();
      return true;
    } catch (error) {
      if (!options.silent) showFlash('error', error.message || 'Could not load the admin page.');
      return false;
    } finally {
      state.loading = false;
      document.querySelector('.admin-main')?.removeAttribute('aria-busy');
    }
  };

  const isAdminPostForm = form => {
    if (!(form instanceof HTMLFormElement)) return false;
    if ((form.method || 'get').toLowerCase() !== 'post') return false;
    if (!isAdminUrl(form.action || window.location.href)) return false;
    if (form.dataset.noAjax === '1') return false;
    return Boolean(form.closest('.admin-main'));
  };

  document.addEventListener('click', event => {
    state.lastInteraction = Date.now();
    const menuToggle = event.target.closest('.admin-menu-toggle');
    if (menuToggle) {
      event.preventDefault();
      document.body.classList.toggle('admin-nav-open');
      return;
    }

    document.querySelectorAll('details.action-menu[open]').forEach(details => {
      if (!details.contains(event.target)) details.removeAttribute('open');
    });

    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.target || link.hasAttribute('download') || link.dataset.noAjax === '1' || !isAdminUrl(link.href)) return;
    event.preventDefault();
    loadAdminPage(link.href, {history: 'push'});
  });

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    state.lastInteraction = Date.now();

    const action = form.querySelector('input[name="action"]')?.value;
    const status = form.querySelector('input[name="status"]')?.value;
    if (action === 'user_status' && status === 'blocked' && !window.confirm('Block this user? They will be signed out on their next request.')) {
      event.preventDefault();
      return;
    }

    if ((form.method || 'get').toLowerCase() === 'get' && isAdminUrl(form.action || window.location.href) && form.dataset.noAjax !== '1') {
      event.preventDefault();
      const url = new URL(form.action || window.location.href, window.location.href);
      url.search = '';
      new FormData(form).forEach((value, key) => {
        if (String(value) !== '') url.searchParams.append(key, String(value));
      });
      loadAdminPage(url.href, {history: 'push'});
      return;
    }

    if (!isAdminPostForm(form) || form.dataset.busy === '1') return;
    event.preventDefault();
    setBusy(form, true);
    try {
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {Accept: 'application/json'}
      });
      if (response.redirected && !isAdminUrl(response.url)) {
        window.location.assign(response.url);
        return;
      }
      const payload = await response.json().catch(() => ({ok: false, message: 'Invalid server response.'}));
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Request failed.');
      const redirect = payload?.data?.redirect || window.location.href;
      const loaded = await loadAdminPage(redirect, {history: 'replace', preserveScroll: true});
      if (loaded) showFlash('success', payload.message || 'Saved successfully.');
      else setBusy(form, false);
    } catch (error) {
      showFlash('error', error.message || 'Request failed.');
      setBusy(form, false);
    }
  });

  document.addEventListener('input', () => { state.lastInteraction = Date.now(); }, true);
  document.addEventListener('change', () => { state.lastInteraction = Date.now(); }, true);
  window.addEventListener('popstate', () => loadAdminPage(window.location.href, {history: false}));

  window.setInterval(() => {
    if (document.hidden || state.loading || Date.now() - state.lastInteraction < 6000) return;
    const page = new URL(window.location.href).searchParams.get('page') || 'dashboard';
    if (!['dashboard', 'trades', 'requests', 'support', 'finance'].includes(page)) return;
    if (document.activeElement?.matches('input,select,textarea')) return;
    loadAdminPage(window.location.href, {history: false, preserveScroll: true, silent: true});
  }, 6000);

  enhanceSettingsForm();
})();
