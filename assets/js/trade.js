(() => {
  'use strict';

  const app = document.getElementById('tradeApp');
  if (!app) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const canvas = document.getElementById('marketChart');
  const ctx = canvas.getContext('2d');
  const chartWrap = document.getElementById('chartWrap');
  const chartLoading = document.getElementById('chartLoading');
  const livePrice = document.getElementById('livePrice');
  const priceChange = document.getElementById('priceChange');
  const priceMarker = document.getElementById('priceMarker');
  const chartTooltip = document.getElementById('chartTooltip');
  const balanceDisplay = document.getElementById('balanceDisplay');
  const amountInput = document.getElementById('amountInput');
  const durationInput = document.getElementById('durationInput');
  const potentialReturn = document.getElementById('potentialReturn');
  const potentialProfit = document.getElementById('potentialProfit');
  const ticketPayout = document.getElementById('ticketPayout');
  const tradeList = document.getElementById('tradeList');
  const openCount = document.getElementById('openCount');
  const marketSidebar = document.getElementById('marketSidebar');
  const overlay = document.getElementById('screenOverlay');
  const accountMenu = document.getElementById('accountMenu');
  const accountTrigger = document.getElementById('accountTrigger');
  const toastStack = document.getElementById('toastStack');
  const offlineBanner = document.getElementById('offlineBanner');
  const connectionState = document.getElementById('connectionState');
  const expiryLine = document.getElementById('expiryLine');

  const state = {
    symbol: app.dataset.symbol || 'EURUSD',
    currency: app.dataset.currency || 'USD',
    payout: 0,
    decimals: 5,
    assets: [],
    candles: [],
    trades: [],
    chartStyle: 'candles',
    interval: 5,
    serverOffset: 0,
    loadingTrade: false,
    tradeTab: 'open',
    knownStatuses: new Map(),
    category: 'all',
    marketBusy: false,
    stopped: false
  };

  const money = value => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: state.currency, minimumFractionDigits: 2, maximumFractionDigits: 2
  }).format(Number(value) || 0);

  const number = (value, decimals = state.decimals) => Number(value || 0).toFixed(decimals);

  const escapeHtml = value => String(value).replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  const icon = direction => direction === 'up'
    ? '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 15 6-6 6 6"/></svg>'
    : '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';

  async function api(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.body ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf} : {}),
        ...(options.headers || {})
      }
    });
    const payload = await response.json().catch(() => ({ok: false, message: 'Invalid server response.'}));
    if (response.status === 401) {
      window.location.href = '/en/sign-in/';
      throw new Error('Session expired.');
    }
    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Request failed.');
    return payload.data;
  }

  function showToast(type, title, message) {
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `<span>${type === 'success' ? '✓' : '!'}</span><div><strong>${escapeHtml(title)}</strong><small>${escapeHtml(message)}</small></div>`;
    toastStack.appendChild(toast);
    window.setTimeout(() => toast.remove(), 4200);
  }

  function setOnline(online) {
    offlineBanner.hidden = online;
    connectionState.classList.toggle('offline', !online);
    connectionState.lastChild.textContent = online ? ' Online' : ' Offline';
  }

  async function loadMarket(initial = false) {
    if (state.marketBusy || state.stopped) return;
    state.marketBusy = true;
    try {
      const data = await api(`/en/api/market.php?symbol=${encodeURIComponent(state.symbol)}&interval=${state.interval}&count=90`);
      state.serverOffset = data.serverTime - Date.now();
      state.assets = data.assets;
      state.candles = data.candles;
      state.payout = Number(data.asset.payout);
      state.decimals = Number(data.asset.decimals);
      updateMarketLabels(data.asset);
      updateAssetRows();
      updatePayout();
      drawChart();
      chartLoading.classList.add('hidden');
      setOnline(true);
      if (initial) renderTrades();
    } catch (error) {
      setOnline(false);
      if (initial) showToast('error', 'Market unavailable', error.message);
    } finally {
      state.marketBusy = false;
    }
  }

  function updateMarketLabels(asset) {
    const ids = ['headerMarketName', 'chartMarketName', 'mobileMarketName'];
    ids.forEach(id => { const node = document.getElementById(id); if (node) node.textContent = asset.name; });
    const payoutIds = ['headerPayout', 'mobilePayout'];
    payoutIds.forEach(id => { const node = document.getElementById(id); if (node) node.textContent = `${Math.round(asset.payout)}%`; });
    livePrice.textContent = number(asset.price, asset.decimals);
    priceMarker.textContent = number(asset.price, asset.decimals);
    priceChange.textContent = `${asset.changePercent >= 0 ? '+' : ''}${Number(asset.changePercent).toFixed(2)}%`;
    priceChange.classList.toggle('negative', Number(asset.changePercent) < 0);
    document.querySelectorAll('.chart-title .market-symbol-icon, #mobileSymbolIcon').forEach(node => { node.textContent = asset.name.charAt(0); });
  }

  function updateAssetRows() {
    const map = new Map(state.assets.map(asset => [asset.symbol, asset]));
    document.querySelectorAll('.market-item').forEach(row => {
      const asset = map.get(row.dataset.symbol);
      if (!asset) return;
      const price = row.querySelector('.js-market-price');
      if (price) price.textContent = number(asset.price, asset.decimals);
      row.classList.toggle('active', asset.symbol === state.symbol);
    });
  }

  function selectMarket(symbol) {
    if (!symbol || symbol === state.symbol) {
      closeSidebar();
      return;
    }
    state.symbol = symbol;
    chartLoading.classList.remove('hidden');
    closeSidebar();
    loadMarket(true);
  }

  function updatePayout() {
    const amount = Math.max(0, Number(amountInput.value) || 0);
    const profit = amount * state.payout / 100;
    potentialReturn.textContent = money(amount + profit);
    potentialProfit.textContent = `+${money(profit)}`;
    ticketPayout.textContent = `${Math.round(state.payout)}%`;
    document.querySelectorAll('.quick-amounts [data-amount]').forEach(button => {
      button.classList.toggle('active', Number(button.dataset.amount) === amount);
    });
  }

  async function placeTrade(direction) {
    if (state.loadingTrade) return;
    const amount = Number(amountInput.value);
    const duration = Number(durationInput.value);
    if (!Number.isFinite(amount) || amount <= 0) {
      showToast('error', 'Invalid amount', 'Enter a valid investment amount.');
      amountInput.focus();
      return;
    }
    state.loadingTrade = true;
    document.getElementById('tradeUp').classList.add('loading');
    document.getElementById('tradeDown').classList.add('loading');
    try {
      const trade = await api('/en/api/trade.php', {
        method: 'POST', body: JSON.stringify({symbol: state.symbol, direction, amount, duration})
      });
      balanceDisplay.textContent = money(trade.balance);
      state.trades.unshift(trade);
      state.knownStatuses.set(trade.id, 'open');
      state.tradeTab = 'open';
      syncTradeTabs();
      renderTrades();
      showToast('success', `${trade.name} ${direction.toUpperCase()}`, `${money(trade.amount)} trade opened at ${number(trade.entryPrice)}.`);
    } catch (error) {
      showToast('error', 'Trade not opened', error.message);
    } finally {
      state.loadingTrade = false;
      document.getElementById('tradeUp').classList.remove('loading');
      document.getElementById('tradeDown').classList.remove('loading');
    }
  }

  async function loadState(initial = false) {
    if (state.stopped) return;
    try {
      const data = await api('/en/api/state.php');
      state.serverOffset = data.serverTime - Date.now();
      balanceDisplay.textContent = money(data.balance);
      data.trades.forEach(trade => {
        const old = state.knownStatuses.get(trade.id);
        if (old === 'open' && trade.status !== 'open') {
          const positive = trade.status === 'won' || trade.status === 'draw';
          const label = trade.status === 'won' ? `Won ${money(trade.profitLoss)}` : trade.status === 'draw' ? 'Trade refunded' : `Lost ${money(Math.abs(trade.profitLoss))}`;
          showToast(positive ? 'success' : 'error', `${trade.name} ${trade.status}`, label);
        }
        state.knownStatuses.set(trade.id, trade.status);
      });
      state.trades = data.trades;
      renderTrades();
      setOnline(true);
    } catch (error) {
      setOnline(false);
      if (initial) showToast('error', 'Account sync failed', error.message);
    }
  }

  function syncTradeTabs() {
    document.querySelectorAll('[data-trade-tab]').forEach(button => button.classList.toggle('active', button.dataset.tradeTab === state.tradeTab));
  }

  function renderTrades() {
    const now = Date.now() + state.serverOffset;
    const openTrades = state.trades.filter(trade => trade.status === 'open');
    openCount.textContent = String(openTrades.length);
    expiryLine.hidden = !openTrades.some(trade => trade.symbol === state.symbol);
    const items = state.tradeTab === 'open' ? openTrades : state.trades.filter(trade => trade.status !== 'open');
    if (!items.length) {
      const copy = state.tradeTab === 'open'
        ? ['No open trades', 'Choose a direction to place a trade.']
        : ['No settled trades', 'Completed trades will appear here.'];
      tradeList.innerHTML = `<div class="empty-trades"><span>${icon('up')}</span><strong>${copy[0]}</strong><small>${copy[1]}</small></div>`;
      return;
    }
    tradeList.innerHTML = items.map(trade => {
      const remaining = Math.max(0, Math.ceil((trade.expiresAt - now) / 1000));
      const resultClass = ['won', 'lost', 'draw'].includes(trade.status) ? trade.status : '';
      const result = trade.status === 'open'
        ? `<time data-expiry="${trade.expiresAt}">${formatDuration(remaining)}</time>`
        : `<b class="${resultClass}">${trade.profitLoss > 0 ? '+' : ''}${money(trade.profitLoss)}</b><small>${escapeHtml(trade.status.toUpperCase())}</small>`;
      return `<article class="trade-card trade-card--${escapeHtml(trade.direction)}"><span class="trade-card-icon">${icon(trade.direction)}</span><strong>${escapeHtml(trade.name)}</strong><small>${money(trade.amount)} · ${number(trade.entryPrice, trade.decimals)}</small><span class="trade-card-result">${result}</span></article>`;
    }).join('');
  }

  function formatDuration(seconds) {
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
  }

  function updateClocks() {
    const now = new Date(Date.now() + state.serverOffset);
    const clock = document.getElementById('serverClock');
    if (clock) clock.textContent = now.toLocaleTimeString('en-GB', {hour12: false});
    document.querySelectorAll('[data-expiry]').forEach(node => {
      const remaining = Math.max(0, Math.ceil((Number(node.dataset.expiry) - now.getTime()) / 1000));
      node.textContent = formatDuration(remaining);
    });
  }

  function chartMetrics() {
    const rect = chartWrap.getBoundingClientRect();
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const width = Math.max(1, Math.floor(rect.width));
    const height = Math.max(1, Math.floor(rect.height));
    if (canvas.width !== Math.floor(width * dpr) || canvas.height !== Math.floor(height * dpr)) {
      canvas.width = Math.floor(width * dpr);
      canvas.height = Math.floor(height * dpr);
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return {width, height, dpr, left: 8, right: 68, top: 10, bottom: 24};
  }

  function drawChart() {
    if (!state.candles.length) return;
    const m = chartMetrics();
    const innerW = Math.max(10, m.width - m.left - m.right);
    const innerH = Math.max(10, m.height - m.top - m.bottom);
    const low = Math.min(...state.candles.map(c => Number(c.low)));
    const high = Math.max(...state.candles.map(c => Number(c.high)));
    const range = Math.max(Math.pow(10, -state.decimals), high - low);
    const paddedLow = low - range * .08;
    const paddedHigh = high + range * .08;
    const paddedRange = paddedHigh - paddedLow;
    const y = price => m.top + ((paddedHigh - Number(price)) / paddedRange) * innerH;
    const step = innerW / state.candles.length;

    ctx.clearRect(0, 0, m.width, m.height);
    ctx.lineWidth = 1;
    ctx.font = '8px Inter, system-ui, sans-serif';
    ctx.textBaseline = 'middle';

    for (let i = 0; i <= 5; i++) {
      const gy = m.top + innerH * i / 5;
      const value = paddedHigh - paddedRange * i / 5;
      ctx.beginPath(); ctx.strokeStyle = 'rgba(255,255,255,.045)'; ctx.moveTo(m.left, gy); ctx.lineTo(m.width - m.right + 8, gy); ctx.stroke();
      ctx.fillStyle = 'rgba(143,155,174,.7)'; ctx.fillText(number(value), m.width - m.right + 13, gy);
    }
    for (let i = 0; i <= 6; i++) {
      const gx = m.left + innerW * i / 6;
      ctx.beginPath(); ctx.strokeStyle = 'rgba(255,255,255,.035)'; ctx.moveTo(gx, m.top); ctx.lineTo(gx, m.height - m.bottom); ctx.stroke();
      if (i < 6) {
        const index = Math.min(state.candles.length - 1, Math.floor(i * state.candles.length / 6));
        const label = new Date(state.candles[index].time).toLocaleTimeString('en-GB', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
        ctx.fillStyle = 'rgba(143,155,174,.62)'; ctx.textBaseline = 'bottom'; ctx.fillText(label, gx + 2, m.height - 4); ctx.textBaseline = 'middle';
      }
    }

    if (state.chartStyle === 'line') {
      const gradient = ctx.createLinearGradient(0, m.top, 0, m.height - m.bottom);
      gradient.addColorStop(0, 'rgba(24,197,138,.22)'); gradient.addColorStop(1, 'rgba(24,197,138,0)');
      ctx.beginPath();
      state.candles.forEach((candle, index) => {
        const px = m.left + step * (index + .5); const py = y(candle.close);
        if (index === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
      });
      ctx.lineTo(m.left + innerW, m.height - m.bottom); ctx.lineTo(m.left, m.height - m.bottom); ctx.closePath(); ctx.fillStyle = gradient; ctx.fill();
      ctx.beginPath();
      state.candles.forEach((candle, index) => { const px = m.left + step * (index + .5); const py = y(candle.close); index ? ctx.lineTo(px, py) : ctx.moveTo(px, py); });
      ctx.strokeStyle = '#35e6aa'; ctx.lineWidth = 1.7; ctx.stroke();
    } else {
      const candleWidth = Math.max(2, Math.min(8, step * .58));
      state.candles.forEach((candle, index) => {
        const px = m.left + step * (index + .5);
        const rising = Number(candle.close) >= Number(candle.open);
        const color = rising ? '#18c58a' : '#ff5e67';
        ctx.beginPath(); ctx.strokeStyle = color; ctx.lineWidth = 1; ctx.moveTo(px, y(candle.high)); ctx.lineTo(px, y(candle.low)); ctx.stroke();
        const top = Math.min(y(candle.open), y(candle.close));
        const body = Math.max(1.4, Math.abs(y(candle.close) - y(candle.open)));
        ctx.fillStyle = color; ctx.fillRect(px - candleWidth / 2, top, candleWidth, body);
      });
    }

    const last = Number(state.candles[state.candles.length - 1].close);
    const py = Math.max(m.top, Math.min(m.height - m.bottom, y(last)));
    ctx.setLineDash([3, 4]); ctx.beginPath(); ctx.strokeStyle = 'rgba(24,197,138,.42)'; ctx.moveTo(m.left, py); ctx.lineTo(m.width - m.right + 8, py); ctx.stroke(); ctx.setLineDash([]);
    priceMarker.style.top = `${py}px`;
    priceMarker.textContent = number(last);
    canvas._chartGeometry = {m, innerW, innerH, step, paddedLow, paddedHigh, paddedRange, y};
  }

  function showChartTooltip(event) {
    const geometry = canvas._chartGeometry;
    if (!geometry || !state.candles.length) return;
    const rect = canvas.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const index = Math.max(0, Math.min(state.candles.length - 1, Math.floor((x - geometry.m.left) / geometry.step)));
    const candle = state.candles[index];
    chartTooltip.hidden = false;
    chartTooltip.innerHTML = `<strong>${new Date(candle.time).toLocaleTimeString('en-GB')}</strong><br>O ${number(candle.open)} · H ${number(candle.high)}<br>L ${number(candle.low)} · C ${number(candle.close)}`;
    const left = Math.min(rect.width - 145, Math.max(5, x + 12));
    const top = Math.min(rect.height - 62, Math.max(5, event.clientY - rect.top + 10));
    chartTooltip.style.left = `${left}px`; chartTooltip.style.top = `${top}px`;
  }

  function openSidebar() { marketSidebar.classList.add('open'); overlay.classList.add('open'); }
  function closeSidebar() { marketSidebar.classList.remove('open'); overlay.classList.remove('open'); }

  function filterMarkets() {
    const query = (document.getElementById('marketSearch').value || '').trim().toLowerCase();
    document.querySelectorAll('.market-item').forEach(row => {
      const categoryMatch = state.category === 'all' || row.dataset.category === state.category;
      const queryMatch = !query || `${row.dataset.symbol} ${row.dataset.name}`.toLowerCase().includes(query);
      row.hidden = !(categoryMatch && queryMatch);
    });
  }

  document.querySelectorAll('.market-item').forEach(button => button.addEventListener('click', () => selectMarket(button.dataset.symbol)));
  document.querySelectorAll('.market-tabs button').forEach(button => button.addEventListener('click', () => {
    state.category = button.dataset.category;
    document.querySelectorAll('.market-tabs button').forEach(item => item.classList.toggle('active', item === button));
    filterMarkets();
  }));
  document.getElementById('marketSearch').addEventListener('input', filterMarkets);
  ['marketTrigger', 'mobileMarketButton'].forEach(id => document.getElementById(id)?.addEventListener('click', openSidebar));
  document.getElementById('marketClose').addEventListener('click', closeSidebar);
  overlay.addEventListener('click', () => { closeSidebar(); accountMenu.classList.remove('open'); });
  accountTrigger.addEventListener('click', event => { event.stopPropagation(); accountMenu.classList.toggle('open'); accountTrigger.classList.toggle('active', accountMenu.classList.contains('open')); });
  document.getElementById('mobileAccountButton').addEventListener('click', () => accountMenu.classList.toggle('open'));
  document.addEventListener('click', event => { if (!accountMenu.contains(event.target) && !accountTrigger.contains(event.target)) { accountMenu.classList.remove('open'); accountTrigger.classList.remove('active'); } });
  document.querySelector('.trade-announcement button')?.addEventListener('click', event => event.currentTarget.closest('.trade-announcement').remove());
  document.getElementById('amountMinus').addEventListener('click', () => { amountInput.value = Math.max(1, (Number(amountInput.value) || 1) - 1); updatePayout(); });
  document.getElementById('amountPlus').addEventListener('click', () => { amountInput.value = Math.min(1000, (Number(amountInput.value) || 0) + 1); updatePayout(); });
  amountInput.addEventListener('input', updatePayout);
  document.querySelectorAll('.quick-amounts [data-amount]').forEach(button => button.addEventListener('click', () => { amountInput.value = button.dataset.amount; updatePayout(); }));
  document.getElementById('tradeUp').addEventListener('click', () => placeTrade('up'));
  document.getElementById('tradeDown').addEventListener('click', () => placeTrade('down'));
  document.querySelectorAll('[data-trade-tab]').forEach(button => button.addEventListener('click', () => { state.tradeTab = button.dataset.tradeTab; syncTradeTabs(); renderTrades(); }));
  document.querySelectorAll('[data-chart-style]').forEach(button => button.addEventListener('click', () => { state.chartStyle = button.dataset.chartStyle; document.querySelectorAll('[data-chart-style]').forEach(item => item.classList.toggle('active', item === button)); drawChart(); }));
  document.getElementById('intervalSelect').addEventListener('change', event => { state.interval = Number(event.target.value); loadMarket(true); });
  document.getElementById('fullscreenButton').addEventListener('click', () => { if (!document.fullscreenElement) app.requestFullscreen?.(); else document.exitFullscreen?.(); });
  canvas.addEventListener('mousemove', showChartTooltip);
  canvas.addEventListener('mouseleave', () => { chartTooltip.hidden = true; });
  window.addEventListener('online', () => { setOnline(true); loadMarket(); loadState(); });
  window.addEventListener('offline', () => setOnline(false));
  window.addEventListener('resize', drawChart, {passive: true});
  document.addEventListener('visibilitychange', () => { state.stopped = document.hidden; if (!state.stopped) { loadMarket(); loadState(); } });

  if ('ResizeObserver' in window) {
    let resizeFrame = 0;
    new ResizeObserver(() => {
      window.cancelAnimationFrame(resizeFrame);
      resizeFrame = window.requestAnimationFrame(drawChart);
    }).observe(chartWrap);
  }
  if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js').catch(() => {}));

  updatePayout();
  loadMarket(true);
  loadState(true);
  window.setInterval(() => { if (!document.hidden) loadMarket(); }, 1600);
  window.setInterval(() => { if (!document.hidden) loadState(); }, 2500);
  window.setInterval(updateClocks, 250);
})();
