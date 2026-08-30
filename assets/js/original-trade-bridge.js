/*
 * Same-origin adapter for the supplied React trade room.
 *
 * The captured frontend speaks the Socket.IO protocol used by its former
 * remote broker. This small in-browser socket implements only the messages
 * needed by the UI and translates them to 55AK's authenticated PHP APIs.
 * No request is sent to the captured broker or to any third-party host.
 */
(function () {
  'use strict';

  var nativeFetch = window.fetch.bind(window);
  var encoder = window.TextEncoder ? new TextEncoder() : null;
  var sockets = [];
  var marketSnapshot = null;
  var tradeState = null;
  var activeSymbol = 'EURUSD';
  var activeSocketSymbol = 'EURUSD_otc';
  var activePeriod = 60;
  var allowedTradeDurations = parseDurationList(meta('trade-allowed-durations', '5,10,15,30,60,120,300'));
  var selectedTradeDuration = nearestDuration(number(meta('trade-default-duration', '60'), 60));
  var chartHistoryCount = Math.max(60, Math.min(180, Math.floor(number(meta('trade-chart-history-count', '140'), 140))));
  var marketPollIntervalMs = Math.max(250, Math.min(2000, Math.floor(number(meta('trade-quote-poll-ms', '650'), 650))));
  var marketRequestRunning = false;
  var stateRequestRunning = false;
  var initializationPromise = null;
  var marketTimer = null;
  var stateTimer = null;
  var tickNumber = 1;
  var firstState = true;
  var previousOpenIds = {};
  var lastStateSignature = '';
  var domRefreshQueued = false;
  var accountSwitchTarget = '';
  var accountSwitchPromise = null;
  var accountNavigationScheduled = false;
  var lastAccountSwitchTarget = '';
  var suppressAccountClickUntil = 0;
  var lastSocketOrderAt = 0;
  var lastOrderApiAt = 0;
  var lastTradeError = '';
  var lastDrawingDeleteId = '';
  var fallbackTradeTimer = null;
  var pendingTradeIntent = null;
  var recentTradeIntent = null;
  var tradeRequestsInFlight = {};
  var announcedClientRequests = {};
  var insufficientRedirectScheduled = false;
  var authRedirectScheduled = false;
  var authRedirectTimer = null;
  var authenticatedNavigationPromise = null;
  var platformName = meta('trade-platform-name', '55AK Trade') || '55AK Trade';
  var customLogoUrl = meta('trade-custom-logo-url', '');
  var shouldShowOnboarding = meta('trade-show-onboarding', '0') === '1';

  function meta(name, fallback) {
    var node = document.querySelector('meta[name="' + name + '"]');
    return node ? node.getAttribute('content') : fallback;
  }

  function number(value, fallback) {
    var parsed = Number(value);
    return isFinite(parsed) ? parsed : fallback;
  }

  function parseDurationList(raw) {
    var seen = {};
    var values = String(raw || '').split(/[\s,]+/).map(function (value) {
      return Number(value);
    }).filter(function (value) {
      return isFinite(value) && value >= 5 && value <= 86400 && !seen[value] && (seen[value] = true);
    }).sort(function (left, right) {
      return left - right;
    });
    return values.length ? values : [5, 10, 15, 30, 60, 120, 300];
  }

  function parseBootstrapAssets() {
    var encoded = String(meta('trade-bootstrap-assets', '') || '');
    if (!encoded || !/^[A-Za-z0-9_-]+$/.test(encoded)) return [];
    try {
      while (encoded.length % 4) encoded += '=';
      var binary = window.atob(encoded.replace(/-/g, '+').replace(/_/g, '/'));
      var bytes = new Uint8Array(binary.length);
      for (var index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
      var json = window.TextDecoder
        ? new TextDecoder('utf-8').decode(bytes)
        : decodeURIComponent(Array.prototype.map.call(bytes, function (byte) { return '%' + byte.toString(16).padStart(2, '0'); }).join(''));
      var assets = JSON.parse(json);
      return Array.isArray(assets) ? assets.filter(function (asset) { return asset && asset.symbol; }) : [];
    } catch (ignored) {
      return [];
    }
  }

  var bootstrapAssets = parseBootstrapAssets();

  function normalizeModalCategory(category) {
    var key = String(category || 'Currencies').trim();
    var lowered = key.toLowerCase();
    if (lowered === 'forex') return 'currencies';
    if (lowered === 'currency' || lowered === 'currencies') return 'currencies';
    if (lowered === 'crypto' || lowered === 'cryptocurrency') return 'crypto';
    if (lowered === 'commodity' || lowered === 'commodities') return 'commodities';
    if (lowered === 'stock' || lowered === 'stocks') return 'stocks';
    return lowered;
  }

  function buildModalsFromAssets() {
    var grouped = {};
    bootstrapAssets.forEach(function (asset) {
      var category = String(asset && asset.category ? asset.category : 'Currencies');
      var key = normalizeModalCategory(category);
      if (!grouped[key]) {
        grouped[key] = {id: key, title: category, type: 'market_list', items: []};
      }
      grouped[key].items.push({
        symbol: String(asset.symbol || ''),
        name: String(asset.name || asset.symbol || ''),
        category: category,
        payout: number(asset.payout, 0),
        volatility: number(asset.volatility, 0)
      });
    });

    var order = ['currencies', 'crypto', 'commodities', 'stocks'];
    return order.filter(function (key) { return grouped[key]; }).map(function (key) {
      return grouped[key];
    }).concat(Object.keys(grouped).filter(function (key) { return order.indexOf(key) === -1; }).map(function (key) {
      return grouped[key];
    }));
  }

  function currencySymbol(code) {
    var symbols = {USD: '$', INR: '₹', EUR: '€', GBP: '£'};
    return symbols[code] || code;
  }

  function detectTradeDirection(text, hint) {
    var source = String(text || '') + ' ' + String(hint || '');
    source = source.toLowerCase().replace(/\s+/g, ' ').trim();
    if (!source) return '';
    if (/(^|\b)(up|call|higher|buy)(\b|$)/i.test(source)) return 'up';
    if (/(^|\b)(down|put|lower|sell)(\b|$)/i.test(source)) return 'down';
    return '';
  }

  function navigate(path) {
    if (typeof window.__55AK_NAVIGATE__ === 'function') {
      window.__55AK_NAVIGATE__(path);
      return;
    }
    window.location.assign(path);
  }

  var user = {
    id: meta('trade-user-id', '1'),
    name: meta('trade-user-name', '55AK User'),
    nickname: meta('trade-user-nickname', meta('trade-user-name', '55AK User')),
    avatar: meta('trade-user-avatar', '/profile/images/avatar.svg'),
    email: meta('trade-user-email', ''),
    balance: number(meta('trade-user-balance', '0'), 0),
    liveBalance: number(meta('trade-live-balance', meta('trade-user-balance', '0')), 0),
    demoBalance: number(meta('trade-demo-balance', '1000'), 1000),
    accountType: meta('trade-account-type', 'live') === 'demo' ? 'demo' : 'live',
    currency: meta('trade-user-currency', 'USD'),
    country: meta('trade-user-country', 'IN'),
    csrf: meta('trade-csrf', ''),
    apiToken: meta('trade-api-token', ''),
    isAdmin: meta('trade-is-admin', '0') === '1',
    minAmount: number(meta('trade-min-amount', '1'), 1),
    maxAmount: number(meta('trade-max-amount', '1000'), 1000)
  };

  // Defence in depth for stale proxy/browser HTML: the captured React build
  // hard-navigates while displaying only `LOADING` when the saved account and
  // pathname disagree. Canonicalise before React starts so it cannot create a
  // second navigation or a visible refresh loop. The PHP controllers perform
  // the same check before rendering on a correctly deployed server.
  var currentAccountPath = window.location.pathname.replace(/\/+$/, '');
  var expectedAccountPath = user.accountType === 'demo' ? '/en/demo-trade' : '/en/trade';
  if ((currentAccountPath === '/en/trade' || currentAccountPath === '/en/demo-trade') && currentAccountPath !== expectedAccountPath) {
    window.location.replace(user.accountType === 'demo' ? '/en/demo-trade' : '/en/trade/');
    return;
  }

  window.settings = {
    fpjsDomain: null,
    liveBalance: user.liveBalance.toFixed(8),
    demoBalance: user.demoBalance.toFixed(8),
    tournamentsBalances: [],
    bonus: [],
    possibleBonus: null,
    activePromocodes: [],
    activeTournamentProfiles: [],
    tournamentsMeta: {current: 0, coming: 0, completed: 0},
    isDemo: user.accountType === 'demo' ? 1 : 0,
    isDemoProfile: user.accountType === 'demo',
    tournamentId: 0,
    id: user.id,
    token: 'LOCAL_PLATFORM_SESSION',
    csrf: user.csrf,
    nickname: user.nickname,
    email: user.email,
    avatar: user.avatar,
    country: user.country,
    countryName: user.country === 'IN' ? 'India' : 'Other',
    countryIp: user.country,
    url: window.location.origin + '/en/',
    lang: 'en',
    langs: {en: {code: 'gb', url: '/en/trade/', name: 'English', active: true}},
    tzlist: [{value: '19800', time: '+05:30', label: '(UTC+05:30)'}],
    timeOffset: '19800',
    currencyCode: user.currency,
    currencySymbol: currencySymbol(user.currency),
    currencies: [user.currency],
    profileLevel: 'standart',
    rawRates: (function () { var value = {}; value[user.currency] = 1; return value; }()),
    exchangeRates: (function () { var value = {}; value[user.currency] = 1; return value; }()),
    countUnreadedMessages: 0,
    countUnreadedNotifications: 0,
    hasProfileNotifications: false,
    minDealAmount: user.minAmount,
    maxDealAmount: user.maxAmount,
    minDealAmountUsd: user.minAmount,
    maxDealAmountUsd: user.maxAmount,
    minDepositAmount: 10,
    recommendedDepositAmount: 100,
    allowedTradeDurations: allowedTradeDurations.slice(),
    defaultError: 'An error occurred',
    requiredFieldError: 'This field is required',
    levelsList: [0, 5000, 10000],
    onlyFastAllowed: false,
    tgLink: '',
    background: {file: null, opacity: null},
    maxDemoBalance: 100000000,
    isHideTradingUI: false,
    isHideTournaments: true,
    countNonActivatedPromocodes: 0,
    is_email_verified: true,
    is_verified_profile: true,
    day_limit: 0,
    day_balance: 0,
    isAiTradingEnabled: false,
    showAccountBlockedModal: false,
    activeTournaments: {data: []},
    currencyFlags: (function () { var value = {}; value[user.currency] = 'us'; return value; }()),
    modals: buildModalsFromAssets()
  };

  window.tJSON = window.tJSON || {};
  window.tJSON.day = 'day';
  window.tJSON.days2 = 'days';
  window.tJSON.days5 = 'days';

  if (navigator.serviceWorker && typeof navigator.serviceWorker.getRegistrations === 'function') {
    navigator.serviceWorker.getRegistrations().then(function (registrations) {
      registrations.forEach(function (registration) { registration.unregister(); });
    }).catch(function () {});
  }

  /* The original bundle tries to play broker-hosted sounds. Keep this build
   * silent and local; visual trade feedback remains intact. */
  if (typeof window.Audio === 'function') {
    var NativeAudio = window.Audio;
    var LocalDemoAudio = function (source) {
      var audio = new NativeAudio();
      if (source && String(source).indexOf('/sounds/') === -1) audio.src = source;
      if (source && String(source).indexOf('/sounds/') !== -1) {
        audio.play = function () { return Promise.resolve(); };
      }
      return audio;
    };
    LocalDemoAudio.prototype = NativeAudio.prototype;
    window.Audio = LocalDemoAudio;
  }

  /* Keep the monitoring code compiled into the supplied vendor bundle from
   * contacting its former telemetry service. Requests are answered locally. */
  if (window.XMLHttpRequest && window.XMLHttpRequest.prototype) {
    var nativeXhrOpen = window.XMLHttpRequest.prototype.open;
    window.XMLHttpRequest.prototype.open = function (method, url) {
      var args = Array.prototype.slice.call(arguments);
      if (/^https:\/\/(?:sessions|notify)\.bugsnag\.com(?:\/|$)/i.test(String(url || ''))) {
        args[0] = 'GET';
        args[1] = '/assets/disabled-telemetry.json';
      }
      return nativeXhrOpen.apply(this, args);
    };
  }

  function scheduleSignInOnce() {
    if (authRedirectScheduled) return;
    var now = Date.now();
    var previous = 0;
    try { previous = Number(sessionStorage.getItem('55ak-auth-redirect-at') || 0); } catch (ignored) {}
    showNotice('Your secure session could not be restored. Opening Sign in once…', 'warning', 5200);
    // Never bounce between Trade and Sign in. A second 401 within 20 seconds
    // leaves the local room visible with the warning instead of redirecting.
    if (previous > 0 && now - previous < 20000) return;
    authRedirectScheduled = true;
    try { sessionStorage.setItem('55ak-auth-redirect-at', String(now)); } catch (ignored) {}
    authRedirectTimer = window.setTimeout(function () {
      var next = /\/en\/demo-trade\/?$/i.test(window.location.pathname) ? '/en/demo-trade' : '/en/trade/';
      navigate('/en/sign-in/?next=' + encodeURIComponent(next));
    }, 1800);
  }

  function clearAuthRedirectGuard() {
    if (authRedirectTimer) window.clearTimeout(authRedirectTimer);
    authRedirectTimer = null;
    authRedirectScheduled = false;
    try { sessionStorage.removeItem('55ak-auth-redirect-at'); } catch (ignored) {}
  }

  function jsonRequest(url, options) {
    var requestOptions = options || {};
    var controller = !requestOptions.signal && window.AbortController ? new AbortController() : null;
    var timeout = controller ? window.setTimeout(function () { controller.abort(); }, 25000) : null;
    if (controller) requestOptions.signal = controller.signal;
    requestOptions.credentials = 'same-origin';
    requestOptions.cache = 'no-store';
    requestOptions.headers = Object.assign({
      Accept: 'application/json',
      'X-CSRF-TOKEN': user.csrf,
      'X-TRADE-AUTH': user.apiToken
    }, requestOptions.headers || {});

    return nativeFetch(url, requestOptions).then(function (response) {
      return response.json().catch(function () {
        throw new Error('The server returned an invalid response.');
      }).then(function (payload) {
        if (response.status === 401) scheduleSignInOnce();
        if (!response.ok || !payload || payload.ok === false) {
          var requestError = new Error(payload && payload.message ? payload.message : 'The request failed.');
          requestError.payload = payload || {};
          requestError.status = response.status;
          throw requestError;
        }
        clearAuthRedirectGuard();
        return payload.data;
      });
    }).then(function (data) {
      if (timeout) window.clearTimeout(timeout);
      return data;
    }, function (error) {
      if (timeout) window.clearTimeout(timeout);
      throw error;
    });
  }

  function navigateAuthenticated(path) {
    if (authenticatedNavigationPromise) return authenticatedNavigationPromise;
    var finished = false;
    var fallback = window.setTimeout(function () {
      if (finished) return;
      finished = true;
      navigate(path);
    }, 8000);
    authenticatedNavigationPromise = jsonRequest('/en/api/session.php').catch(function () {
      // A genuinely expired login is handled by the destination's normal
      // sign-in redirect. The important part is never leaving a valid trade
      // session trapped inside the captured React router.
      return null;
    }).then(function () {
      if (finished) return;
      finished = true;
      window.clearTimeout(fallback);
      navigate(path);
    });
    return authenticatedNavigationPromise;
  }

  function plainSymbol(symbol) {
    return String(symbol || 'EURUSD').replace(/_otc$/i, '').toUpperCase();
  }

  function socketSymbol(symbol) {
    return plainSymbol(symbol) + '_otc';
  }

  function bootstrapMarketSnapshot() {
    var assets = bootstrapAssets.length ? bootstrapAssets.slice() : [{
      symbol: 'EURUSD', name: 'EUR/USD', category: 'Forex', price: 1.08642,
      decimals: 5, payout: 82, changePercent: 0, active: true
    }];
    var selected = assets.filter(function (asset) { return plainSymbol(asset.symbol) === activeSymbol; })[0] || assets[0];
    activeSymbol = plainSymbol(selected.symbol);
    activeSocketSymbol = socketSymbol(activeSymbol);
    return {serverTime: Date.now(), asset: selected, assets: assets, candles: [], engine: 'server-bootstrap'};
  }

  function bootstrapTradeState() {
    return {
      balance: user.accountType === 'demo' ? user.demoBalance : user.liveBalance,
      liveBalance: user.liveBalance,
      demoBalance: user.demoBalance,
      accountType: user.accountType,
      isDemo: user.accountType === 'demo',
      currency: user.currency,
      trades: [],
      serverTime: Date.now()
    };
  }

  function categoryName(category) {
    var key = String(category || '').toLowerCase();
    if (key.indexOf('crypto') !== -1) return 'cryptocurrency';
    if (key.indexOf('commod') !== -1) return 'commodity';
    if (key.indexOf('stock') !== -1) return 'stock';
    return 'currency';
  }

  function instrumentPayload(asset, index) {
    var payout = number(asset.payout, 0);
    var timeframes = [5, 10, 15, 30, 60, 120, 180, 300].map(function (time) {
      return {time: time};
    });
    var display = String(asset.name || asset.symbol || 'Market');
    if (display.toLowerCase().indexOf('(otc)') === -1) display += ' (OTC)';

    return [
      index + 1,
      socketSymbol(asset.symbol),
      display,
      categoryName(asset.category),
      number(asset.decimals, 5),
      payout,
      1, 0, 60, 1, 1, 1, true, null, true,
      timeframes,
      null, 0.12, payout, payout, false,
      0.05, 0.12, 0.25, 1.2, 0.8, 0.12
    ];
  }

  function instrumentsList() {
    var assets = marketSnapshot && Array.isArray(marketSnapshot.assets) ? marketSnapshot.assets : [];
    return assets.map(instrumentPayload);
  }

  function graphSettings() {
    var asset = marketSnapshot && marketSnapshot.asset ? marketSnapshot.asset : {symbol: activeSymbol, price: 0};
    var startingAmount = Math.max(user.minAmount, Math.min(user.maxAmount, 10));
    return [{
      chart_id: 'graph',
      settings: JSON.stringify({
        isVisible: true,
        isFastOption: true,
        isPendingTradeOption: false,
        isFastAmountOption: false,
        isIndicatorsMinimized: false,
        isIndicatorsShowing: true,
        currentExpirationTime: 0,
        currentAsset: {symbol: socketSymbol(asset.symbol), price: number(asset.price, 0), obj: {}},
        isAutoScrolling: 1,
        isOneClickTrade: false,
        dealValue: startingAmount,
        dealPercentValue: 1,
        dealPercentValueStr: '1%',
        gridOpacity: 8
      })
    }];
  }

  function balancePayload() {
    var live = tradeState ? number(tradeState.liveBalance, user.liveBalance) : user.liveBalance;
    var demo = tradeState ? number(tradeState.demoBalance, user.demoBalance) : user.demoBalance;
    return {
      liveBalance: live,
      demoBalance: demo,
      tournamentsBalances: [],
      dayBalance: 0,
      dayLimit: 0
    };
  }

  function socketTime(milliseconds) {
    var date = new Date(number(milliseconds, Date.now()));
    return date.toISOString().replace('T', ' ').replace('Z', '');
  }

  function mapTrade(trade, requestId) {
    var amount = number(trade.amount, 0);
    var payout = number(trade.payout, 0);
    var profitLoss = number(trade.profitLoss, 0);
    var status = String(trade.status || 'open');
    var openedAt = number(trade.openedAt, Date.now());
    var expiresAt = number(trade.expiresAt, openedAt + 60000);
    var closed = status !== 'open';
    var resultReturn = closed ? profitLoss : 0;
    var stateBalance = tradeState ? number(tradeState.balance, user.balance) : number(trade.balance, user.balance);

    return {
      id: String(trade.id || ('LOCAL-' + Date.now())),
      requestId: requestId || trade.requestId || 0,
      uid: number(user.id, 1),
      asset: socketSymbol(trade.symbol),
      amount: amount,
      command: String(trade.direction).toLowerCase() === 'down' ? 1 : 0,
      action: String(trade.direction).toLowerCase() === 'down' ? 'put' : 'call',
      openPrice: number(trade.entryPrice, 0),
      closePrice: trade.closePrice === null || typeof trade.closePrice === 'undefined' ? 0 : number(trade.closePrice, 0),
      price: trade.closePrice === null || typeof trade.closePrice === 'undefined' ? number(trade.entryPrice, 0) : number(trade.closePrice, 0),
      openTime: socketTime(openedAt).slice(0, 19),
      openTimeMillis: String(new Date(openedAt).getUTCMilliseconds()).padStart(3, '0'),
      openTimeMilliseconds: String(new Date(openedAt).getUTCMilliseconds()).padStart(3, '0'),
      openTimestamp: Number((openedAt / 1000).toFixed(3)),
      closeTime: socketTime(expiresAt).slice(0, 19),
      closeTimestamp: Math.floor(expiresAt / 1000),
      duration: Math.max(1, Math.floor((expiresAt - openedAt) / 1000)),
      percentProfit: payout,
      payout: payout,
      profit: resultReturn,
      profitLoss: profitLoss,
      accountBalance: stateBalance,
      currency: user.currency,
      currencyCode: user.currency,
      isDemo: String(trade.accountType || user.accountType) === 'demo' ? 1 : 0,
      isClosed: closed,
      status: status,
      optionType: 1,
      createdAt: socketTime(openedAt),
      settledAt: trade.settledAt ? socketTime(trade.settledAt) : null
    };
  }

  function openDeals() {
    if (!tradeState || !Array.isArray(tradeState.trades)) return [];
    return tradeState.trades.filter(function (trade) {
      return trade.status === 'open';
    }).map(function (trade) { return mapTrade(trade); });
  }

  function closedDeals() {
    if (!tradeState || !Array.isArray(tradeState.trades)) return [];
    return tradeState.trades.filter(function (trade) {
      return trade.status !== 'open';
    }).map(function (trade) { return mapTrade(trade); });
  }

  function historyPayload(snapshot, requestedSymbol, period) {
    var history = [];
    var count = 0;
    var candles = snapshot && Array.isArray(snapshot.candles) ? snapshot.candles : [];
    var safePeriod = Math.max(1, Math.min(300, number(period, 60)));
    var serverSeconds = number(snapshot && snapshot.serverTime, Date.now()) / 1000;
    var previousStamp = -Infinity;
    candles.forEach(function (candle) {
      var denseTicks = Array.isArray(candle.ticks) ? candle.ticks : [];
      if (denseTicks.length) {
        denseTicks.forEach(function (tick) {
          if (!Array.isArray(tick) || tick.length < 2) return;
          var stamp = number(tick[0], 0) / 1000;
          var price = number(tick[1], NaN);
          if (!isFinite(stamp) || !isFinite(price) || stamp > serverSeconds + 0.001) return;
          // Socket quotes support millisecond fractions. Keep stamps strictly
          // increasing so the compiled reducer never replaces two points that
          // happened inside the same second.
          if (stamp <= previousStamp) stamp = previousStamp + 0.001;
          previousStamp = Number(stamp.toFixed(3));
          history.push([previousStamp, price, ++count]);
        });
        return;
      }

      // Backward-compatible fallback for a cached/older API response.
      var start = number(candle.time, Date.now()) / 1000;
      var availableEnd = Math.min(
        number(candle.lastTickTime, number(candle.time, Date.now()) + (safePeriod * 1000) - 1) / 1000,
        serverSeconds
      );
      var availableDuration = Math.max(0.003, availableEnd - start);
      var offsets;
      offsets = [0, availableDuration * 0.28, availableDuration * 0.67, availableDuration];
      var rising = number(candle.close, 0) >= number(candle.open, 0);
      var points = rising
        ? [candle.open, candle.low, candle.high, candle.close]
        : [candle.open, candle.high, candle.low, candle.close];
      points.forEach(function (price, index) {
        var stamp = Math.min(serverSeconds, start + offsets[index]);
        if (stamp <= previousStamp) stamp = previousStamp + 0.001;
        previousStamp = Number(stamp.toFixed(3));
        history.push([previousStamp, number(price, 0), ++count]);
      });
    });
    return {asset: requestedSymbol, period: safePeriod, history: history, candles: []};
  }

  function broadcastText(event, payload) {
    sockets.slice().forEach(function (socket) {
      if (socket.readyState === LocalTradeSocket.OPEN) socket.event(event, payload);
    });
  }

  function broadcastBinary(event, payload) {
    sockets.slice().forEach(function (socket) {
      if (socket.readyState === LocalTradeSocket.OPEN) socket.binaryEvent(event, payload);
    });
  }

  function emitTradeLists(socket) {
    socket.binaryEvent('orders/opened/list', openDeals());
    socket.binaryEvent('orders/closed/list', closedDeals());
  }

  function refreshMarket(sendHistory, socket) {
    if (marketRequestRunning) {
      if (!sendHistory) return Promise.resolve(marketSnapshot);
      return new Promise(function (resolve) {
        window.setTimeout(function () { resolve(refreshMarket(true, socket)); }, 180);
      });
    }
    marketRequestRunning = true;
    var symbolAtStart = activeSymbol;
    var periodAtStart = Math.max(1, Math.min(300, number(activePeriod, 60)));
    var endpoint = '/en/api/market.php?symbol=' + encodeURIComponent(symbolAtStart) +
      '&interval=' + encodeURIComponent(String(periodAtStart)) +
      '&count=' + encodeURIComponent(String(chartHistoryCount)) +
      '&history=' + (sendHistory ? '1' : '0');

    return jsonRequest(endpoint).then(function (snapshot) {
      marketSnapshot = snapshot;
      if (symbolAtStart !== activeSymbol) return snapshot;
      var asset = snapshot.asset || {};
      var serverTime = number(snapshot.serverTime, Date.now());
      var quote = [[activeSocketSymbol, serverTime / 1000, number(asset.price, 0), ++tickNumber]];
      if (sendHistory && socket) {
        socket.binaryEvent('history/list/v2', historyPayload(snapshot, activeSocketSymbol, periodAtStart));
      }
      broadcastBinary('quotes/stream', quote);
      broadcastText('time', Math.floor(serverTime / 1000));
      return snapshot;
    }).catch(function (error) {
      showNotice(error.message, true);
      return marketSnapshot;
    }).then(function (snapshot) {
      marketRequestRunning = false;
      return snapshot;
    }, function (error) {
      marketRequestRunning = false;
      throw error;
    });
  }

  function refreshState(forceLists) {
    if (stateRequestRunning) return Promise.resolve(tradeState);
    stateRequestRunning = true;

    return jsonRequest('/en/api/state.php').then(function (state) {
      tradeState = state;
      user.liveBalance = number(state.liveBalance, user.liveBalance);
      user.demoBalance = number(state.demoBalance, user.demoBalance);
      user.accountType = state.accountType === 'demo' ? 'demo' : 'live';
      user.balance = number(state.balance, user.accountType === 'demo' ? user.demoBalance : user.liveBalance);
      window.settings.liveBalance = user.liveBalance.toFixed(8);
      window.settings.demoBalance = user.demoBalance.toFixed(8);
      window.settings.isDemo = user.accountType === 'demo' ? 1 : 0;
      window.settings.isDemoProfile = user.accountType === 'demo';
      var currentOpen = {};
      var trades = Array.isArray(state.trades) ? state.trades : [];
      trades.forEach(function (trade) {
        if (trade.status === 'open') currentOpen[String(trade.id)] = true;
      });

      if (!firstState) {
        trades.forEach(function (trade) {
          if (trade.status !== 'open' && previousOpenIds[String(trade.id)]) {
            broadcastBinary('s_orders/close', {deals: [mapTrade(trade)]});
          }
        });
      }

      var signature = JSON.stringify({
        balance: state.balance,
        trades: trades.map(function (trade) {
          return [trade.id, trade.status, trade.profitLoss, trade.closePrice];
        })
      });
      broadcastBinary('s_balance/list', balancePayload());
      if (forceLists || signature !== lastStateSignature) {
        broadcastBinary('orders/opened/list', openDeals());
        broadcastBinary('orders/closed/list', closedDeals());
        lastStateSignature = signature;
      }
      previousOpenIds = currentOpen;
      firstState = false;
      return state;
    }).catch(function (error) {
      showNotice(error.message, true);
      return tradeState;
    }).then(function (state) {
      stateRequestRunning = false;
      return state;
    }, function (error) {
      stateRequestRunning = false;
      throw error;
    });
  }

  function startPolling() {
    if (!marketTimer) {
      marketTimer = window.setInterval(function () { refreshMarket(false, null); }, marketPollIntervalMs);
    }
    if (!stateTimer) {
      stateTimer = window.setInterval(function () { refreshState(false); }, 1800);
    }
  }

  function nearestDuration(requestedSeconds) {
    var durations = allowedTradeDurations && allowedTradeDurations.length ? allowedTradeDurations : [5, 10, 15, 30, 60, 120, 300];
    var target = Math.max(1, number(requestedSeconds, 60));
    var selected = durations[0];
    durations.forEach(function (duration) {
      if (Math.abs(duration - target) < Math.abs(selected - target)) selected = duration;
    });
    return selected;
  }

  function showInsufficientLiveBalance(amount, redirectPath) {
    showNotice('Insufficient balance. Please deposit funds to place a Real trade. Redirecting to Deposit…', 'error', 4200);
    if (insufficientRedirectScheduled) return;
    insufficientRedirectScheduled = true;
    var target = redirectPath || '/en/balance/?deposit=true&from=trade';
    if (!redirectPath && isFinite(amount) && amount > 0) {
      target += '&amount=' + encodeURIComponent(String(amount));
    }
    window.setTimeout(function () { navigateAuthenticated(target); }, 1600);
  }

  function openLocalTrade(socket, request) {
    if (accountSwitchPromise) {
      return accountSwitchPromise.then(function () { return openLocalTrade(socket, request); });
    }
    var nowSeconds = Math.floor(Date.now() / 1000);
    var requestedClose = number(request.time, nowSeconds + 60);
    var requestedDuration = nearestDuration(requestedClose - nowSeconds);
    var duration = allowedTradeDurations.indexOf(selectedTradeDuration) !== -1
      ? selectedTradeDuration
      : requestedDuration;
    var direction = detectTradeDirection(request.action || '', request.direction || '') || (request.action === 'put' ? 'down' : 'up');
    var clientRequestId = String(request.clientRequestId || 'trade-api-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10));
    if (!/^[A-Za-z0-9._:-]{8,64}$/.test(clientRequestId)) {
      clientRequestId = 'trade-api-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }
    var data = {
      symbol: plainSymbol(request.asset || activeSocketSymbol),
      direction: direction,
      amount: number(request.amount, 0),
      duration: duration,
      account: user.accountType,
      requestId: clientRequestId
    };

    // Keep the original trade UI, but send a user with insufficient funds to
    // the dedicated deposit page instead of leaving the trade button in a
    // confusing error state.
    if (data.amount > user.balance + 0.000001) {
      if (user.accountType === 'demo') {
        showNotice('Demo balance is too low. Switch to Live Account or ask an administrator to top up Demo.', 'warning', 3600);
        return Promise.resolve(null);
      }
      showInsufficientLiveBalance(data.amount);
      return Promise.resolve(null);
    }

    if (tradeRequestsInFlight[clientRequestId]) {
      return tradeRequestsInFlight[clientRequestId];
    }
    lastTradeError = '';
    lastOrderApiAt = Date.now();
    var requestPromise = jsonRequest('/en/api/trade.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': user.csrf},
      body: JSON.stringify(data)
    }).then(function (trade) {
      user.accountType = trade.accountType === 'demo' ? 'demo' : 'live';
      user.balance = number(trade.balance, user.balance);
      if (user.accountType === 'demo') user.demoBalance = user.balance; else user.liveBalance = user.balance;
      if (!tradeState) tradeState = {balance: user.balance, liveBalance: user.liveBalance, demoBalance: user.demoBalance, accountType: user.accountType, currency: user.currency, trades: [], serverTime: Date.now()};
      tradeState.balance = user.balance;
      tradeState.liveBalance = user.liveBalance;
      tradeState.demoBalance = user.demoBalance;
      tradeState.accountType = user.accountType;
      tradeState.trades = (tradeState.trades || []).filter(function (item) { return item.id !== trade.id; });
      tradeState.trades.unshift(trade);
      var deal = mapTrade(trade, request.requestId);
      if (trade.status === 'open' && !announcedClientRequests[clientRequestId]) {
        announcedClientRequests[clientRequestId] = Date.now();
        socket.binaryEvent('s_orders/open', deal);
      } else if (trade.status !== 'open') {
        broadcastBinary('s_orders/close', {deals: [deal]});
      }
      broadcastBinary('s_balance/list', balancePayload());
      broadcastBinary('orders/opened/list', openDeals());
      showNotice(trade.duplicate ? 'This trade was already recorded.' : 'Trade opened successfully.', trade.duplicate ? 'info' : 'success');
      window.setTimeout(function () { refreshState(true); }, 250);
      return trade;
    }).catch(function (error) {
      lastTradeError = String(error && error.message || 'Trade request failed.');
      var payload = error && error.payload ? error.payload : {};
      socket.event('error', {
        error: payload.code || 'local_trade_error',
        message: error.message,
        requestId: request.requestId || 0
      });
      if (payload.code === 'INSUFFICIENT_DEMO_BALANCE') {
        showNotice(error.message || 'Demo balance is too low.', 'warning', 3600);
        return;
      }
      if (payload.code === 'INSUFFICIENT_BALANCE' || /insufficient (?:wallet )?balance/i.test(String(error.message || ''))) {
        showInsufficientLiveBalance(data.amount, payload.redirect || '');
        return;
      }
      showNotice(error.message, true);
    });
    tradeRequestsInFlight[clientRequestId] = requestPromise;
    requestPromise.then(function () {
      window.setTimeout(function () {
        if (tradeRequestsInFlight[clientRequestId] === requestPromise) delete tradeRequestsInFlight[clientRequestId];
      }, 3500);
    }, function () {
      window.setTimeout(function () {
        if (tradeRequestsInFlight[clientRequestId] === requestPromise) delete tradeRequestsInFlight[clientRequestId];
      }, 3500);
    });
    return requestPromise;
  }

  function primeSocket(socket) {
    socket.event('s_authorization', {});
    socket.event('time', Math.floor(number(marketSnapshot && marketSnapshot.serverTime, Date.now()) / 1000));
    socket.binaryEvent('s_balance/list', balancePayload());
    socket.binaryEvent('instruments/list', instrumentsList());
    socket.binaryEvent('settings/list', graphSettings());
    emitTradeLists(socket);
  }

  function initializeSocket(socket) {
    if (!marketSnapshot) marketSnapshot = bootstrapMarketSnapshot();
    if (!tradeState) tradeState = bootstrapTradeState();

    // Authorise the supplied room immediately from server-rendered data. Live
    // API data replaces this snapshot as soon as it arrives. This prevents the
    // captured frontend from timing out into its maintenance/skeleton screen
    // on slower shared hosting.
    primeSocket(socket);
    scheduleDomRefresh();
    if (initializationPromise) return;
    marketRequestRunning = true;
    stateRequestRunning = true;

    initializationPromise = Promise.all([
      jsonRequest('/en/api/market.php?symbol=' + encodeURIComponent(activeSymbol) + '&interval=' + encodeURIComponent(String(activePeriod)) + '&count=' + encodeURIComponent(String(chartHistoryCount)) + '&history=1'),
      jsonRequest('/en/api/state.php')
    ]).then(function (results) {
      marketSnapshot = results[0];
      tradeState = results[1];
      user.liveBalance = number(tradeState.liveBalance, user.liveBalance);
      user.demoBalance = number(tradeState.demoBalance, user.demoBalance);
      user.accountType = tradeState.accountType === 'demo' ? 'demo' : 'live';
      user.balance = number(tradeState.balance, user.accountType === 'demo' ? user.demoBalance : user.liveBalance);
      window.settings.liveBalance = user.liveBalance.toFixed(8);
      window.settings.demoBalance = user.demoBalance.toFixed(8);
      window.settings.isDemo = user.accountType === 'demo' ? 1 : 0;
      window.settings.isDemoProfile = user.accountType === 'demo';
      var selected = marketSnapshot.asset || {};
      activeSymbol = plainSymbol(selected.symbol || 'EURUSD');
      activeSocketSymbol = socketSymbol(activeSymbol);

      broadcastText('time', Math.floor(number(marketSnapshot.serverTime, Date.now()) / 1000));
      broadcastBinary('s_balance/list', balancePayload());
      broadcastBinary('instruments/list', instrumentsList());
      broadcastBinary('settings/list', graphSettings());
      broadcastBinary('history/list/v2', historyPayload(marketSnapshot, activeSocketSymbol, activePeriod));
      broadcastBinary('orders/opened/list', openDeals());
      broadcastBinary('orders/closed/list', closedDeals());
      previousOpenIds = {};
      openDeals().forEach(function (deal) { previousOpenIds[String(deal.id)] = true; });
      firstState = false;
      lastStateSignature = '';
      scheduleDomRefresh();
    }).catch(function (error) {
      showNotice('Connection is slow. The trading screen is ready and live data will retry automatically.', 'warning', 5000);
      return error;
    }).then(function (result) {
      marketRequestRunning = false;
      stateRequestRunning = false;
      initializationPromise = null;
      startPolling();
      return result;
    }, function (error) {
      marketRequestRunning = false;
      stateRequestRunning = false;
      initializationPromise = null;
      startPolling();
      throw error;
    });
  }

  function handleSocketEvent(socket, packet) {
    var event = packet[0];
    var payload = packet[1] || {};

    if (event === 'authorization') {
      initializeSocket(socket);
      return;
    }
    if (event === 'balance/list') {
      refreshState(false).then(function () { socket.binaryEvent('s_balance/list', balancePayload()); });
      return;
    }
    if (event === 'instruments/list') {
      socket.binaryEvent('instruments/list', instrumentsList());
      return;
    }
    if (event === 'settings/list') {
      socket.binaryEvent('settings/list', graphSettings());
      return;
    }
    if (event === 'settings/store') {
      var savedSettings = payload && payload.settings ? payload.settings : {};
      if (savedSettings.timePeriod) {
        selectedTradeDuration = nearestDuration(number(savedSettings.timePeriod, 60));
      }
      var chartPeriod = savedSettings.period || savedSettings.chartPeriod || savedSettings.interval;
      if (chartPeriod) {
        activePeriod = Math.max(1, Math.min(300, number(chartPeriod, activePeriod)));
        refreshMarket(true, socket);
      }
      return;
    }
    if (event === 'instruments/update') {
      activeSymbol = plainSymbol(payload.asset || activeSymbol);
      activeSocketSymbol = socketSymbol(activeSymbol);
      activePeriod = Math.max(1, Math.min(300, number(payload.period, activePeriod)));
      refreshMarket(true, socket);
      return;
    }
    if (event === 'history/list/v2' || event === 'history/list' || event === 'chart/history') {
      activeSymbol = plainSymbol(payload.asset || payload.symbol || activeSymbol);
      activeSocketSymbol = socketSymbol(activeSymbol);
      activePeriod = Math.max(1, Math.min(300, number(payload.period || payload.interval || payload.timePeriod, activePeriod)));
      refreshMarket(true, socket);
      return;
    }
    if (event === 'orders/open') {
      var socketOrderAt = Date.now();
      lastSocketOrderAt = socketOrderAt;
      if (fallbackTradeTimer) {
        window.clearTimeout(fallbackTradeTimer);
        fallbackTradeTimer = null;
      }
      var socketDirection = detectTradeDirection(payload.action || '', payload.direction || '') || (payload.action === 'put' ? 'down' : 'up');
      if (pendingTradeIntent && socketOrderAt - pendingTradeIntent.at < 2500 && pendingTradeIntent.direction === socketDirection) {
        payload.clientRequestId = pendingTradeIntent.id;
        recentTradeIntent = pendingTradeIntent;
        pendingTradeIntent = null;
      } else if (!payload.clientRequestId && recentTradeIntent && socketOrderAt - recentTradeIntent.at < 3000 && recentTradeIntent.direction === socketDirection) {
        // A slow captured UI can emit orders/open after the local fallback has
        // already submitted. Reuse that click's id so the database returns the
        // existing trade instead of debiting the stake a second time.
        payload.clientRequestId = recentTradeIntent.id;
      }
      openLocalTrade(socket, payload);
      return;
    }
    if (event === 'orders/opened/list') {
      refreshState(false).then(function () { socket.binaryEvent('orders/opened/list', openDeals()); });
      return;
    }
    if (event === 'orders/closed/list') {
      refreshState(false).then(function () { socket.binaryEvent('orders/closed/list', closedDeals()); });
      return;
    }
    if (event === 'pending/list') {
      socket.binaryEvent('pending/list', []);
      return;
    }
    if (event === 'indicator/list') {
      socket.binaryEvent('s_indicator/list', {favorites: [], list: []});
      return;
    }
    if (event === 'drawing/delete') {
      var drawingId = String(payload.drawingId || payload.id || '').trim();
      if (!drawingId) return;
      lastDrawingDeleteId = drawingId;
      // The captured reducer sends `{id, requestId}` locally but removes a
      // drawing only after the socket acknowledgement supplies the scalar id.
      // Return both common field names for compatibility with every supplied
      // compiled room build; only the selected drawing is acknowledged.
      socket.binaryEvent('s_drawing/delete', {
        id: drawingId,
        drawingId: drawingId,
        chartId: payload.chartId || 'graph'
      });
      return;
    }
    if (event === 'drawing/load') {
      socket.binaryEvent('s_drawing/load', {list: []});
      return;
    }
    if (event === 'chart_notification/get') {
      socket.binaryEvent('s_chart_notification/get', {list: []});
      return;
    }
    if (event.indexOf('double') !== -1 || event.indexOf('sell') !== -1 || event === 'orders/close') {
      socket.event('error', {error: 'action_unavailable', message: 'This action is currently unavailable.'});
      showNotice('This action is currently unavailable.', true);
    }
  }

  function utf8Bytes(value) {
    if (encoder) return encoder.encode(value);
    var encoded = unescape(encodeURIComponent(value));
    var bytes = new Uint8Array(encoded.length);
    for (var index = 0; index < encoded.length; index++) bytes[index] = encoded.charCodeAt(index);
    return bytes;
  }

  function LocalTradeSocket(url) {
    this._listeners = {};
    this._outboundQueue = [];
    this._outboundTimer = null;
    this.url = String(url || 'local://55ak-trade');
    this.readyState = LocalTradeSocket.CONNECTING;
    this.protocol = '';
    this.extensions = '';
    this.bufferedAmount = 0;
    this.binaryType = 'blob';
    this.onopen = null;
    this.onclose = null;
    this.onerror = null;
    this.onmessage = null;
    sockets.push(this);
    var socket = this;
    window.setTimeout(function () {
      if (socket.readyState !== LocalTradeSocket.CONNECTING) return;
      socket.readyState = LocalTradeSocket.OPEN;
      socket.dispatch('open', new Event('open'));
      socket.message('0' + JSON.stringify({
        sid: '55ak-local-' + Date.now(),
        upgrades: [],
        pingInterval: 25000,
        pingTimeout: 60000
      }));
      window.setTimeout(function () { socket.message('40'); }, 15);
    }, 15);
  }

  LocalTradeSocket.prototype.addEventListener = function (name, callback) {
    if (typeof callback !== 'function') return;
    if (!this._listeners[name]) this._listeners[name] = [];
    if (this._listeners[name].indexOf(callback) === -1) this._listeners[name].push(callback);
  };

  LocalTradeSocket.prototype.removeEventListener = function (name, callback) {
    if (!this._listeners[name]) return;
    this._listeners[name] = this._listeners[name].filter(function (listener) {
      return listener !== callback;
    });
  };

  LocalTradeSocket.prototype.dispatchEvent = function (event) {
    var listeners = (this._listeners[event.type] || []).slice();
    var socket = this;
    listeners.forEach(function (listener) { listener.call(socket, event); });
    return !event.defaultPrevented;
  };

  LocalTradeSocket.prototype.constructor = LocalTradeSocket;
  LocalTradeSocket.CONNECTING = 0;
  LocalTradeSocket.OPEN = 1;
  LocalTradeSocket.CLOSING = 2;
  LocalTradeSocket.CLOSED = 3;

  LocalTradeSocket.prototype.dispatch = function (name, event) {
    this.dispatchEvent(event);
    var handler = this['on' + name];
    if (typeof handler === 'function') handler.call(this, event);
  };

  LocalTradeSocket.prototype.message = function (data) {
    this.dispatch('message', new MessageEvent('message', {data: data}));
  };

  /* A real WebSocket never delivers a server frame re-entrantly from inside
   * WebSocket.send(). Queue each frame on its own task so the captured
   * Socket.IO client can finish its outgoing packet before it handles the
   * authorization response. Synchronous delivery left isAuthorized=false
   * and permanently trapped the React room on its skeleton screen. */
  LocalTradeSocket.prototype.queueMessage = function (data) {
    this._outboundQueue.push(data);
    if (this._outboundTimer) return;
    var socket = this;
    this._outboundTimer = window.setTimeout(function flushOutboundFrame() {
      socket._outboundTimer = null;
      if (socket.readyState !== LocalTradeSocket.OPEN || !socket._outboundQueue.length) return;
      socket.message(socket._outboundQueue.shift());
      if (socket._outboundQueue.length) {
        socket._outboundTimer = window.setTimeout(flushOutboundFrame, 0);
      }
    }, 0);
  };

  LocalTradeSocket.prototype.event = function (name, payload) {
    this.queueMessage('42' + JSON.stringify([name, payload]));
  };

  LocalTradeSocket.prototype.binaryEvent = function (name, payload) {
    this.queueMessage('451-' + JSON.stringify([name, {_placeholder: true, num: 0}]));
    var body = utf8Bytes(JSON.stringify(payload));
    var data = new Uint8Array(body.length + 1);
    data[0] = 4;
    data.set(body, 1);
    this.queueMessage(data.buffer);
  };

  LocalTradeSocket.prototype.send = function (data) {
    if (this.readyState !== LocalTradeSocket.OPEN) {
      throw new DOMException('The socket is not open.', 'InvalidStateError');
    }
    if (data === '2') {
      var socket = this;
      window.setTimeout(function () { socket.message('3'); }, 1);
      return;
    }
    if (typeof data !== 'string' || data.slice(0, 2) !== '42') return;
    var packet;
    try {
      packet = JSON.parse(data.slice(2));
    } catch (ignored) {
      return;
    }
    if (Array.isArray(packet)) handleSocketEvent(this, packet);
  };

  LocalTradeSocket.prototype.close = function (code, reason) {
    if (this.readyState === LocalTradeSocket.CLOSED) return;
    this.readyState = LocalTradeSocket.CLOSING;
    this.readyState = LocalTradeSocket.CLOSED;
    if (this._outboundTimer) window.clearTimeout(this._outboundTimer);
    this._outboundTimer = null;
    this._outboundQueue = [];
    sockets = sockets.filter(function (socket) { return socket !== this; }, this);
    var event;
    try {
      event = new CloseEvent('close', {code: code || 1000, reason: reason || '', wasClean: true});
    } catch (ignored) {
      event = new Event('close');
    }
    this.dispatch('close', event);
  };

  window.WebSocket = LocalTradeSocket;

  function normalizedText(element) {
    return String(element && element.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function accountTypeFromText(text) {
    var source = String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (!source || source.length > 140) return '';
    var demo = /(demo|practice)\s*account\b/.test(source) || /(^|\b)(demo|practice)(\b|$)/.test(source);
    var live = /(live|real)\s*account\b/.test(source) || /(^|\b)(live|real)(\b|$)/.test(source);
    // A dropdown trigger can contain both account rows. It is not a choice and
    // must never start a switch merely because one hidden row says "Demo".
    if (demo === live) return '';
    return demo ? 'demo' : 'live';
  }

  function findAccountChoice(start) {
    var node = start && start.nodeType === 1 ? start : start && start.parentElement;
    if (!node) return '';
    if (node.closest) {
      node = node.closest('button, a, [role="button"], [role="menuitem"], li, [data-account], [data-account-type]') || node;
    }
    for (var depth = 0; node && node !== document.documentElement && depth < 4; depth++, node = node.parentElement) {
      var explicit = node.getAttribute && (node.getAttribute('data-account-type') || node.getAttribute('data-account'));
      var explicitType = accountTypeFromText(explicit);
      if (explicitType) return explicitType;
      var choice = accountTypeFromText(normalizedText(node));
      if (choice) return choice;
      if (node.id === 'root') break;
    }
    return '';
  }

  function interceptAccountChoice(event) {
    var target = findAccountChoice(event.target);
    if (!target) return false;
    var recentlySwitched = target === lastAccountSwitchTarget && Date.now() < suppressAccountClickUntil;
    var shouldSwitch = target !== user.accountType;
    var switching = accountSwitchTarget === target;
    if (!shouldSwitch && !switching && !recentlySwitched) return false;
    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    if (shouldSwitch && !switching) switchTradeAccount(target);
    return true;
  }

  function parseMoneyValue(value) {
    var source = String(value == null ? '' : value).replace(/,/g, '');
    var match = source.match(/-?\d+(?:\.\d+)?/);
    if (!match) return NaN;
    var amount = Number(match[0]);
    return isFinite(amount) ? amount : NaN;
  }

  function readInvestmentAmount() {
    var root = document.getElementById('root') || document.body;
    var inputs = root.querySelectorAll ? root.querySelectorAll('input') : [];
    var best = null;
    for (var index = 0; index < inputs.length; index++) {
      var input = inputs[index];
      if (input.disabled || input.type === 'hidden' || input.type === 'search') continue;
      var node = input;
      var context = '';
      for (var depth = 0; node && depth < 6; depth++, node = node.parentElement) {
        context += ' ' + normalizedText(node);
      }
      var identity = [input.name, input.id, input.className, input.getAttribute('aria-label'), input.getAttribute('placeholder')].join(' ');
      var amountContext = /investment|amount|stake|deal/i.test(identity + ' ' + context);
      var excludedContext = /duration|expiry|time period|search|payout|profit/i.test(identity);
      if (excludedContext) continue;
      var value = parseMoneyValue(input.value);
      if (!isFinite(value) || value <= 0) continue;
      var score = (amountContext ? 10 : 0) + (/investment|amount|stake/i.test(identity) ? 10 : 0);
      if (value >= user.minAmount && value <= user.maxAmount) score += 2;
      if (!best || score > best.score) best = {value: value, score: score};
    }
    if (best && best.score >= 2) return best.value;
    var text = normalizedText(root);
    var match = text.match(/investment[\s\S]{0,100}?(?:[$₹€£]|USD\b|INR\b|EUR\b|GBP\b)?\s*([0-9][0-9,]*(?:\.\d+)?)/i);
    if (match) {
      var amount = parseMoneyValue(match[1]);
      if (isFinite(amount) && amount > 0) return amount;
    }
    return NaN;
  }

  function openFallbackTrade(direction, socketEventTime) {
    fallbackTradeTimer = null;
    if (lastSocketOrderAt >= socketEventTime) return;
    var socket = sockets.filter(function (item) { return item.readyState === LocalTradeSocket.OPEN; })[0];
    if (!socket) {
      showNotice('Trade engine is still loading. Try again in a moment.', 'warning', 2200);
      return;
    }
    var amount = readInvestmentAmount();
    if (!isFinite(amount) || amount <= 0) {
      showNotice('Enter a valid investment amount and try again.', 'warning', 2600);
      return;
    }
    if (amount < user.minAmount - 0.000001) {
      showNotice('Minimum investment is ' + currencySymbol(user.currency) + user.minAmount.toFixed(2) + '.', 'warning', 2800);
      return;
    }
    if (amount > user.maxAmount + 0.000001) {
      showNotice('Maximum investment is ' + currencySymbol(user.currency) + user.maxAmount.toFixed(2) + '.', 'warning', 2800);
      return;
    }
    var intent = pendingTradeIntent && pendingTradeIntent.at === socketEventTime ? pendingTradeIntent : null;
    var fallbackRequestId = intent ? intent.id : 'trade-fallback-' + socketEventTime;
    recentTradeIntent = {id: fallbackRequestId, direction: direction, at: socketEventTime};
    openLocalTrade(socket, {
      asset: activeSocketSymbol,
      action: direction === 'down' ? 'put' : 'call',
      amount: amount,
      time: Math.floor(Date.now() / 1000) + selectedTradeDuration,
      requestId: 'fallback-' + Date.now(),
      clientRequestId: fallbackRequestId,
      optionType: 1
    });
    pendingTradeIntent = null;
  }

  function scheduleFallbackTrade(event) {
    var root = document.getElementById('root');
    var action = event.target && event.target.closest ? event.target.closest('button, [role="button"]') : null;
    if (!root || !action || !root.contains(action)) return;
    var text = normalizedText(action).toLowerCase();
    var hint = [
      action.getAttribute('data-text') || '',
      action.getAttribute('aria-label') || '',
      action.getAttribute('title') || '',
      action.className && typeof action.className === 'string' ? action.className : '',
      text
    ].join(' ');
    var direction = detectTradeDirection(text, hint);
    if (!direction) return;
    if (user.balance + 0.000001 < user.minAmount) return;
    var socketEventTime = Date.now();
    pendingTradeIntent = {id: 'trade-click-' + socketEventTime + '-' + Math.random().toString(36).slice(2, 10), direction: direction, at: socketEventTime};
    if (fallbackTradeTimer) window.clearTimeout(fallbackTradeTimer);
    // A healthy captured UI emits orders/open synchronously from its click.
    // The fallback fires only when that event never arrives (disabled/stale UI),
    // preventing duplicate trades while making funded Up/Down buttons reliable.
    fallbackTradeTimer = window.setTimeout(function () { openFallbackTrade(direction, socketEventTime); }, 360);
  }

  function showNotice(message, tone, duration) {
    var notice = document.getElementById('originalBridgeNotice');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'originalBridgeNotice';
      notice.setAttribute('role', 'status');
      document.body.appendChild(notice);
    }
    var noticeTone = tone === true ? 'error' : String(tone || 'success').toLowerCase();
    if (['success', 'info', 'warning', 'error'].indexOf(noticeTone) === -1) noticeTone = 'info';
    notice.className = 'original-bridge-notice is-' + noticeTone;
    notice.textContent = String(message || '');
    notice.classList.add('is-visible');
    window.clearTimeout(showNotice.timer);
    showNotice.timer = window.setTimeout(function () { notice.classList.remove('is-visible'); }, number(duration, 3600));
  }

  function switchTradeAccount(type) {
    var target = type === 'demo' ? 'demo' : 'live';
    if (accountSwitchTarget === target && accountSwitchPromise) return accountSwitchPromise;
    if (target === user.accountType) return Promise.resolve(tradeState);
    accountSwitchTarget = target;
    lastAccountSwitchTarget = target;
    suppressAccountClickUntil = Date.now() + 1800;
    showNotice(target === 'demo' ? 'Switching to Demo Account…' : 'Switching to Live Account…', 'info', 2200);
    accountSwitchPromise = jsonRequest('/en/api/account.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': user.csrf},
      body: JSON.stringify({account: target})
    }).then(function (state) {
      tradeState = state;
      user.accountType = state.accountType === 'demo' ? 'demo' : 'live';
      user.liveBalance = number(state.liveBalance, user.liveBalance);
      user.demoBalance = number(state.demoBalance, user.demoBalance);
      user.balance = number(state.balance, user.accountType === 'demo' ? user.demoBalance : user.liveBalance);
      window.settings.liveBalance = user.liveBalance.toFixed(8);
      window.settings.demoBalance = user.demoBalance.toFixed(8);
      window.settings.isDemo = user.accountType === 'demo' ? 1 : 0;
      window.settings.isDemoProfile = user.accountType === 'demo';
      broadcastBinary('s_balance/list', balancePayload());
      broadcastBinary('orders/opened/list', openDeals());
      broadcastBinary('orders/closed/list', closedDeals());
      showNotice(user.accountType === 'demo' ? 'Demo Account selected.' : 'Live Account selected.', 'success', 1800);
      // The captured room derives its active-wallet reducer from the URL at
      // boot. Reload exactly once onto the matching same-origin route so its
      // label, balance and button state all agree. The server internally serves
      // Demo at this canonical URL (no redirect), which removes the old
      // refresh loop and matches the route compiled into the React room.
      window.dispatchEvent(new CustomEvent('55ak:account-changed', {detail: {accountType: user.accountType}}));
      if (!accountNavigationScheduled) {
        accountNavigationScheduled = true;
        window.setTimeout(function () {
          window.location.replace(user.accountType === 'demo' ? '/en/demo-trade' : '/en/trade/');
        }, 180);
      }
      return state;
    }).catch(function (error) {
      showNotice(error.message, true);
      return tradeState;
    }).then(function (state) {
      accountSwitchTarget = '';
      accountSwitchPromise = null;
      return state;
    }, function (error) {
      accountSwitchTarget = '';
      accountSwitchPromise = null;
      throw error;
    });
    return accountSwitchPromise;
  }

  function showAnnouncementBanner(title, message, tone, imageUrl) {
    var existing = document.getElementById('originalAnnouncementBanner');
    if (existing) existing.remove();
    var banner = document.createElement('aside');
    banner.id = 'originalAnnouncementBanner';
    banner.className = 'original-announcement-banner is-' + (['info','success','warning'].indexOf(String(tone)) !== -1 ? String(tone) : 'info');
    var image = imageUrl ? '<img src="' + String(imageUrl).replace(/"/g, '&quot;') + '" alt="">' : '';
    banner.innerHTML = image + '<div><strong></strong><p></p></div><button type="button" aria-label="Close announcement">×</button>';
    banner.querySelector('strong').textContent = String(title || 'Platform notice');
    banner.querySelector('p').textContent = String(message || '');
    banner.querySelector('button').addEventListener('click', function () { banner.remove(); });
    document.body.appendChild(banner);
  }

  function logout() {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = '/en/logout/';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_csrf';
    input.value = user.csrf;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
  }

  function localRoute(pathname) {
    var path = String(pathname || '').replace(/\/+$/, '');
    if (/\/(?:logout)$/i.test(path)) return 'logout';
    if (/\/(?:deposit|balance|payments|transactions)$/i.test(path)) return '/en/balance/';
    if (/\/(?:withdrawal)$/i.test(path)) return '/en/withdrawal/';
    if (/\/(?:market|promocodes?|bonuses)$/i.test(path)) return '/en/market/';
    if (/\/(?:settings|profile|account|my-account)$/i.test(path)) return '/en/settings/';
    if (/\/(?:history|trades)$/i.test(path)) return '/en/history/';
    if (/\/(?:analytics)$/i.test(path)) return '/en/analytics/';
    if (/\/(?:support|help|faq)$/i.test(path)) return '/en/support/';
    if (/\/(?:affiliate|referral|refer)$/i.test(path)) return '/en/affiliate/';
    if (/\/(?:admin)$/i.test(path)) return user.isAdmin ? '/admin/' : '/en/trade/';
    if (/\/demo-trade(?:\.html)?$/i.test(path)) return '/en/demo-trade';
    if (/\/trade$/i.test(path) || path === '/en' || path === '') return '/en/trade/';
    if (path.indexOf('/en/') === 0) return '/en/trade/';
    return '/en/trade/';
  }

  function rebrandText(root) {
    if (!root || !document.createTreeWalker) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var node;
    while ((node = walker.nextNode())) {
      if (/quotex|qxbroker/i.test(node.nodeValue || '')) {
        node.nodeValue = node.nodeValue.replace(/quotex|qxbroker/gi, platformName);
      }
      if (/\.undefined\b/.test(node.nodeValue || '')) {
        node.nodeValue = node.nodeValue.replace(/\.undefined\b/g, '.000');
      }
    }
  }

  function ensureAffiliateShortcut() {
    var sidebarNav = document.querySelector('.app__sidebar > nav');
    if (!sidebarNav) return;
    var existing = Array.prototype.find.call(sidebarNav.querySelectorAll('a, button'), function (item) {
      return /\b(affiliate|referral|refer and earn)\b/i.test(normalizedText(item));
    });
    if (existing) {
      existing.setAttribute('data-local-affiliate', '1');
      if (existing.tagName === 'A') existing.setAttribute('href', '/en/affiliate/');
      return;
    }

    var shortcut = document.createElement('a');
    shortcut.className = 'Nw7me Dj2fS';
    shortcut.href = '/en/affiliate/';
    shortcut.setAttribute('data-text', 'Affiliate');
    shortcut.setAttribute('data-local-affiliate', '1');
    shortcut.innerHTML = '<div class="BQxBK"><svg class="icon-profile"><use xlink:href="/profile/images/spritemap.svg#icon-profile"></use></svg><span>Affiliate</span></div>';
    var more = Array.prototype.find.call(sidebarNav.querySelectorAll('button, a'), function (item) {
      return normalizedText(item).toLowerCase() === 'more';
    });
    sidebarNav.insertBefore(shortcut, more || null);
  }

  function refreshDom() {
    domRefreshQueued = false;
    document.title = platformName + ' — Trading platform';
    var root = document.getElementById('root');
    rebrandText(root);
    ensureAffiliateShortcut();

    var headerLogo = document.querySelector('.TJbx1 .V8tvg');
    if (headerLogo) {
      headerLogo.classList.add('is-local-branded');
      headerLogo.setAttribute('data-platform-name', platformName);
      headerLogo.setAttribute('data-platform-mark', platformName.replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || '55');
      if (customLogoUrl && !headerLogo.querySelector('.original-custom-logo')) {
        var logoImage = document.createElement('img');
        logoImage.className = 'original-custom-logo';
        logoImage.src = customLogoUrl;
        logoImage.alt = '';
        headerLogo.prepend(logoImage);
        headerLogo.classList.add('has-custom-logo');
      }
    }

    document.querySelectorAll('a[href]').forEach(function (anchor) {
      var raw = anchor.getAttribute('href');
      if (!raw || raw.charAt(0) === '#') return;
      var parsed;
      try { parsed = new URL(raw, window.location.origin); } catch (ignored) { return; }
      var route = localRoute(parsed.pathname);
      if (route === 'logout') {
        anchor.setAttribute('href', '#logout');
        anchor.setAttribute('data-local-logout', '1');
      } else {
        anchor.setAttribute('href', route);
      }
    });

    document.querySelectorAll('button, a').forEach(function (element) {
      var text = normalizedText(element).toLowerCase();
      if (text === 'double up' || text === 'sell now' || text === 'join us' ||
          text === 'tournaments' || text === 'tourna-ments') {
        element.classList.add('original-hidden');
      }
    });
  }

  function scheduleDomRefresh() {
    if (domRefreshQueued) return;
    domRefreshQueued = true;
    window.setTimeout(refreshDom, 0);
  }

  function interceptDepositShortcut(event) {
    var action = event.target && event.target.closest ? event.target.closest('button, a, [role="button"]') : null;
    if (!action) return false;
    var text = normalizedText(action).toLowerCase();
    var semanticHint = [
      action.getAttribute('data-text') || '',
      action.getAttribute('aria-label') || '',
      action.getAttribute('title') || '',
      action.getAttribute('href') || '',
      text
    ].join(' ').toLowerCase();
    var iconHint = Array.prototype.map.call(action.querySelectorAll('use'), function (node) {
      return node.getAttribute('href') || node.getAttribute('xlink:href') || '';
    }).join(' ').toLowerCase();
    if (detectTradeDirection(text, semanticHint + ' ' + iconHint)) return false;
    if (!(/\b(deposit|add funds|balance|payments?)\b/.test(semanticHint) || /#icon-(?:deposit|balance|payments?)(?:\b|$)/.test(iconHint)) || /withdraw/.test(semanticHint)) return false;
    event.preventDefault();
    event.stopImmediatePropagation();
    navigateAuthenticated('/en/balance/?deposit=true&from=trade');
    return true;
  }

  // The captured React room opens its former remote Deposit modal from the
  // earliest pointer event. Stop it before React sees the gesture, then restore
  // the same PHP session and open the working local payment page.
  ['pointerdown', 'mousedown', 'touchstart', 'click'].forEach(function (eventName) {
    document.addEventListener(eventName, interceptDepositShortcut, {capture: true, passive: false});
  });

  // Disabled HTML buttons do not emit a normal click event. The captured
  // React UI can disable Up/Down at a zero balance, so catch pointer-down in
  // capture phase and route the user to Deposit before React swallows it.
  document.addEventListener('pointerdown', function (event) {
    if (user.balance + 0.000001 >= user.minAmount) return;
    var root = document.getElementById('root');
    var action = event.target && event.target.closest ? event.target.closest('button, [role="button"]') : null;
    if (!root || !action || !root.contains(action)) return;
    var text = normalizedText(action);
    if (!detectTradeDirection(text, [action.getAttribute('aria-label') || '', action.getAttribute('title') || '', action.className || ''].join(' '))) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (user.accountType === 'demo') {
      showNotice('Demo balance is too low. Switch to Live Account or ask an administrator to top it up.', 'warning', 3600);
      return;
    }
    showInsufficientLiveBalance(NaN);
  }, true);

  // React renders the account dropdown in a portal outside #root on some
  // builds. Capture the earliest pointer/mouse/touch event at document level so
  // its original /sign-up/modal handler never runs for Demo/Live switching.
  ['pointerdown', 'click'].forEach(function (eventName) {
    document.addEventListener(eventName, function (event) {
      interceptAccountChoice(event);
    }, true);
  });

  // If the captured Up/Down control has stale disabled state, recover the action
  // only when its normal orders/open socket event fails to arrive.
  document.addEventListener('pointerdown', scheduleFallbackTrade, true);

  document.addEventListener('click', function (event) {
    var accountChoice = findAccountChoice(event.target);
    if (accountChoice) {
      if (interceptAccountChoice(event)) return;
      // Clicking the already-selected account opens/closes the captured dropdown;
      // do not let the broad generic "account" navigation rule hijack it.
      return;
    }
    var action = event.target && event.target.closest ? event.target.closest('button, a, [role="button"]') : null;
    if (!action) return;
    var text = normalizedText(action).toLowerCase();
    var semanticHint = [
      action.getAttribute('data-text') || '',
      action.getAttribute('aria-label') || '',
      action.getAttribute('title') || '',
      action.getAttribute('href') || '',
      text
    ].join(' ').toLowerCase();
    var classHint = action.className && typeof action.className === 'string' ? action.className.toLowerCase() : '';
    var iconHint = Array.prototype.map.call(action.querySelectorAll('use'), function (node) {
      return node.getAttribute('href') || node.getAttribute('xlink:href') || '';
    }).join(' ').toLowerCase();
    var hint = [semanticHint, classHint, iconHint].join(' ');
    var tradeDirection = detectTradeDirection(text, hint);
    if (action.getAttribute('data-local-logout') === '1' || text === 'logout' || text === 'sign out') {
      event.preventDefault();
      event.stopImmediatePropagation();
      logout();
      return;
    }
    // Some builds of the original UI stop before emitting an order when the
    // wallet is below the minimum. Handle that front-end path too, so a zero
    // balance Up/Down click always reaches the dedicated Deposit page.
    if (user.balance + 0.000001 < user.minAmount && document.getElementById('root') && document.getElementById('root').contains(action) && tradeDirection) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (user.accountType === 'demo') {
        showNotice('Demo balance is too low. Switch to Live Account or ask an administrator to top it up.', 'warning', 3600);
        return;
      }
      showInsufficientLiveBalance(NaN);
      return;
    }
    // Every supplied SVG sprite URL starts with `/profile/images/`. Matching
    // that raw path as navigation used to send Up/Down and other icon buttons
    // to Settings before React could submit a trade. A direction control is
    // owned by React (with the pointerdown fallback above), so leave it alone.
    if (tradeDirection) return;
    if ((/\b(deposit|balance|payments?|transactions?)\b/.test(semanticHint) || /#icon-(?:deposit|balance|payments?|transactions?)(?:\b|$)/.test(iconHint)) && !/withdraw/.test(semanticHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/balance/?deposit=true&from=trade');
      return;
    }
    if (/\bwithdraw(?:al)?\b/.test(semanticHint) || /#icon-withdraw(?:al)?(?:\b|$)/.test(iconHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/withdrawal/');
      return;
    }
    if (/\b(profile|settings|account|my-account)\b/.test(semanticHint) || /#icon-profile(?:\b|$)/.test(iconHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/settings/');
      return;
    }
    if (/\banalytics\b/.test(semanticHint) || /#icon-analytics(?:\b|$)/.test(iconHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/analytics/');
      return;
    }
    if (/\b(market|promo codes?|bonuses)\b/.test(semanticHint) || /#icon-market(?:\b|$)/.test(iconHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/market/');
      return;
    }
    if (/\b(support|help|faq)\b/.test(semanticHint) || /#icon-help(?:\b|$)/.test(iconHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/support/');
      return;
    }
    if (/\b(affiliate|referral|refer and earn)\b/.test(semanticHint) || /#icon-(?:affiliate|referral)(?:\b|$)/.test(iconHint)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      navigateAuthenticated('/en/affiliate/');
      return;
    }
    if (text === 'double up' || text === 'sell now') {
      event.preventDefault();
      event.stopImmediatePropagation();
      showNotice('This action is currently unavailable.', true);
    }
  }, true);

  window.__55AK_DIAGNOSTICS__ = {
    get accountType() { return user.accountType; },
    get activePeriod() { return activePeriod; },
    get selectedTradeDuration() { return selectedTradeDuration; },
    get activeSymbol() { return activeSymbol; },
    get liveBalance() { return user.liveBalance; },
    get demoBalance() { return user.demoBalance; },
    get activeBalance() { return user.balance; },
    get accountSwitchTarget() { return accountSwitchTarget; },
    get lastSocketOrderAt() { return lastSocketOrderAt; },
    get lastOrderApiAt() { return lastOrderApiAt; },
    get recentTradeRequestId() { return recentTradeIntent ? recentTradeIntent.id : ''; },
    get lastTradeError() { return lastTradeError; },
    get lastDrawingDeleteId() { return lastDrawingDeleteId; }
  };

  function saveOnboarding(action) {
    return jsonRequest('/en/api/onboarding.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': user.csrf},
      body: JSON.stringify({action: action === 'skip' ? 'skip' : 'complete'})
    }).then(function () {
      shouldShowOnboarding = false;
      var overlay = document.getElementById('originalOnboarding');
      if (overlay) overlay.remove();
    }).catch(function (error) {
      showNotice(error.message || 'Could not save onboarding progress.', 'error', 3600);
    });
  }

  function mountOnboarding() {
    if (!shouldShowOnboarding || document.getElementById('originalOnboarding')) return;
    var overlay = document.createElement('section');
    overlay.id = 'originalOnboarding';
    overlay.className = 'original-onboarding';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Trading platform onboarding');
    document.body.appendChild(overlay);

    var tourSteps = [
      {
        eyebrow: 'Step 1 of 3',
        title: 'Choose Demo or Live Account',
        copy: 'Practice safely with virtual Demo funds. Switch to Live only when you are ready and have added balance.',
        art: '<div class="onboarding-account-art"><span>Demo Account</span><b>$10,000 virtual</b><i></i><span>Live Account</span><b>Your wallet balance</b></div>'
      },
      {
        eyebrow: 'Step 2 of 3',
        title: 'Set time and investment',
        copy: 'Choose a supported trade duration and enter an amount inside the administrator limits. The payout preview updates in the trade panel.',
        art: '<div class="onboarding-control-art"><span><small>Time</small><b>00:01:00</b></span><span><small>Investment</small><b>' + currencySymbol(user.currency) + Math.max(user.minAmount, 10).toFixed(2) + '</b></span></div>'
      },
      {
        eyebrow: 'Step 3 of 3',
        title: 'Pick a direction',
        copy: 'Choose Up if you expect the expiry price to be higher, or Down if you expect it to be lower. Every accepted trade appears in the Trades panel.',
        art: '<div class="onboarding-direction-art"><span class="is-up">Up <b>↑</b></span><span class="is-down">Down <b>↓</b></span></div>'
      }
    ];

    function renderWelcome() {
      overlay.innerHTML = '<div class="original-onboarding-card is-welcome">' +
        '<button class="original-onboarding-close" type="button" data-onboarding="later" aria-label="Close">×</button>' +
        '<div class="original-onboarding-hero"><span></span><b></b><i></i></div>' +
        '<h2>Welcome to ' + platformName.replace(/[&<>"']/g, '') + '</h2>' +
        '<p>You are now on a <strong>Demo Account with virtual funds.</strong> Practice risk-free and get comfortable before using real money.</p>' +
        '<div class="original-onboarding-tip">● &nbsp; A two-minute tour helps new traders get started.</div>' +
        '<button class="original-onboarding-primary" type="button" data-onboarding="start">Start tutorial · 2 min</button>' +
        '<button class="original-onboarding-link" type="button" data-onboarding="later">Later</button>' +
      '</div>';
    }

    function renderSkipConfirmation() {
      overlay.innerHTML = '<div class="original-onboarding-card is-confirm">' +
        '<button class="original-onboarding-close" type="button" data-onboarding="welcome" aria-label="Close">×</button>' +
        '<h2>Skip the onboarding?</h2>' +
        '<p>In just <strong>2 minutes</strong> you will learn the essentials that help you trade more confidently.</p>' +
        '<button class="original-onboarding-primary" type="button" data-onboarding="start">Take the tour</button>' +
        '<button class="original-onboarding-link" type="button" data-onboarding="skip">Continue without tutorial</button>' +
      '</div>';
    }

    function renderTour(index) {
      var step = tourSteps[index];
      overlay.innerHTML = '<div class="original-onboarding-card is-tour">' +
        '<button class="original-onboarding-close" type="button" data-onboarding="later" aria-label="Close">×</button>' +
        '<span class="original-onboarding-eyebrow">' + step.eyebrow + '</span>' +
        step.art + '<h2>' + step.title + '</h2><p>' + step.copy + '</p>' +
        '<div class="original-onboarding-progress"><i style="width:' + (((index + 1) / tourSteps.length) * 100) + '%"></i></div>' +
        '<div class="original-onboarding-actions">' +
          (index > 0 ? '<button class="original-onboarding-secondary" type="button" data-onboarding="back" data-step="' + index + '">Back</button>' : '<span></span>') +
          '<button class="original-onboarding-primary" type="button" data-onboarding="next" data-step="' + index + '">' + (index === tourSteps.length - 1 ? 'Finish tutorial' : 'Next') + '</button>' +
        '</div>' +
      '</div>';
    }

    overlay.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('[data-onboarding]') : null;
      if (!button) return;
      var action = button.getAttribute('data-onboarding');
      if (action === 'start') renderTour(0);
      else if (action === 'later') renderSkipConfirmation();
      else if (action === 'welcome') renderWelcome();
      else if (action === 'skip') saveOnboarding('skip');
      else if (action === 'back') renderTour(Math.max(0, number(button.getAttribute('data-step'), 0) - 1));
      else if (action === 'next') {
        var index = number(button.getAttribute('data-step'), 0);
        if (index >= tourSteps.length - 1) saveOnboarding('complete');
        else renderTour(index + 1);
      }
    });
    renderWelcome();
    window.setTimeout(function () {
      var focusTarget = overlay.querySelector('.original-onboarding-primary');
      if (focusTarget) focusTarget.focus();
    }, 50);
  }

  document.addEventListener('DOMContentLoaded', function () {
    scheduleDomRefresh();
    mountOnboarding();
    var announcement = meta('trade-announcement-message', '');
    if (announcement) {
      var announcementTitle = meta('trade-announcement-title', 'Platform notice');
      var announcementLevel = meta('trade-announcement-level', 'info');
      var announcementImage = meta('trade-announcement-image', '');
      if (announcementImage) showAnnouncementBanner(announcementTitle, announcement, announcementLevel, announcementImage);
      else showNotice(announcementTitle + ' — ' + announcement, announcementLevel, 8000);
    }
    var observer = new MutationObserver(scheduleDomRefresh);
    observer.observe(document.getElementById('root') || document.body, {childList: true, subtree: true});
  });
}());
