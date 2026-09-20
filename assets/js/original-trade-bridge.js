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

  // Patch Highcharts Y-axis price scale to force step distance of 2 and close label spacing
  (function configureHighchartsYAxis() {
    function dynamicPriceTickPositioner(min, max) {
      if (min === undefined || max === undefined || min === max || isNaN(min) || isNaN(max)) {
        return;
      }
      if (this) {
        this.tickAmount = undefined;
        if (this.options) {
          this.options.tickAmount = undefined;
        }
      }

      var range = Math.abs(max - min);
      if (range <= 0) return;

      var step;

      // Enforce ultra-dense step distance of 2 (or 0.0002, 0.002, 0.02, 0.2, 2, 5) for maximum price density
      if (range <= 0.01) {
        step = 0.0002;
      } else if (range <= 0.05) {
        step = 0.0005;
      } else if (range <= 0.2) {
        step = 0.002;
      } else if (range <= 1) {
        step = 0.01;
      } else if (range <= 5) {
        step = 0.05;
      } else if (range <= 25) {
        step = 0.2;
      } else if (range <= 100) {
        step = 1;
      } else if (range <= 350) {
        step = 2; // Strict step distance of 2 for BTC/Crypto standard range (~300)!
      } else if (range <= 800) {
        step = 5;
      } else {
        step = 10;
      }

      if (step <= 0 || !isFinite(step)) step = 2;

      var ticks = [];
      var start = Math.floor(min / step) * step;
      var end = Math.ceil(max / step) * step;

      var count = 0;
      for (var p = start; p <= end + step * 0.0001 && count < 300; p += step) {
        count++;
        var cleanP = Number(p.toFixed(8));
        ticks.push(cleanP);
      }
      return ticks.length >= 2 ? ticks : undefined;
    }

    function overrideYAxisTicks(axis, minVal, maxVal) {
      if (!axis || axis.isXAxis) return null;
      var actualMin = minVal !== undefined ? minVal : axis.min;
      var actualMax = maxVal !== undefined ? maxVal : axis.max;
      var ticks = dynamicPriceTickPositioner.call(axis, actualMin, actualMax);
      if (ticks && ticks.length >= 2) {
        var step = ticks[1] - ticks[0];
        axis.tickInterval = step;
        if (axis.options) {
          axis.options.tickInterval = step;
          axis.options.tickPixelInterval = 8;
          axis.options.minorTickInterval = null;
          axis.options.endOnTick = false;
          axis.options.startOnTick = false;
        }
        return ticks;
      }
      return null;
    }

    function tuneYAxisOptions(options) {
      if (!options) return;
      var isMobile = window.innerWidth <= 800;
      var targetTickPixelInterval = isMobile ? 12 : 16;

      if (options.yAxis) {
        var axes = Array.isArray(options.yAxis) ? options.yAxis : [options.yAxis];
        axes.forEach(function (y) {
          if (!y) return;
          y.alignTicks = false;
          y.endOnTick = false;
          y.startOnTick = false;
          y.tickPositioner = dynamicPriceTickPositioner;
          y.tickPixelInterval = targetTickPixelInterval;
          y.minorTickInterval = null;
          if (!y.labels) y.labels = {};
          y.labels.align = 'right';
          y.labels.x = isMobile ? 38 : 10;
          y.labels.reserveSpace = true;
          y.labels.step = 1;
        });
      }
    }

    function applyHighchartsPatch(hc) {
      if (!hc || hc._tradeScalePatchedV7) return;
      hc._tradeScalePatchedV7 = true;

      try {
        var isMobile = window.innerWidth <= 800;

        if (hc.setOptions) {
          hc.setOptions({
            chart: {
              alignTicks: false
            },
            yAxis: {
              alignTicks: false,
              endOnTick: false,
              startOnTick: false,
              tickPositioner: dynamicPriceTickPositioner,
              tickPixelInterval: isMobile ? 12 : 16,
              minorTickInterval: null,
              labels: {
                align: 'right',
                x: isMobile ? 38 : 10,
                reserveSpace: true,
                step: 1
              }
            }
          });
        }

        if (hc.Chart && hc.Chart.prototype) {
          if (hc.Chart.prototype.hideOverlappingLabels) {
            var origHideOverlapping = hc.Chart.prototype.hideOverlappingLabels;
            hc.Chart.prototype.hideOverlappingLabels = function (labels) {
              if (Array.isArray(labels)) {
                labels = labels.filter(function (lbl) {
                  if (lbl && lbl.element && lbl.element.parentNode) {
                    var cls = String(lbl.element.parentNode.getAttribute('class') || '');
                    if (cls.indexOf('highcharts-yaxis-labels') !== -1) {
                      if (lbl.element.style) {
                        lbl.element.style.opacity = '1';
                        lbl.element.style.visibility = 'visible';
                      }
                      return false;
                    }
                  }
                  return true;
                });
              }
              return origHideOverlapping.apply(this, [labels]);
            };
          }

          if (hc.Chart.prototype.init) {
            var origInit = hc.Chart.prototype.init;
            hc.Chart.prototype.init = function (userOptions, callback) {
              if (userOptions && userOptions.chart) {
                userOptions.chart.alignTicks = false;
              }
              tuneYAxisOptions(userOptions);
              var chart = origInit.apply(this, arguments);
              if (chart) {
                chart.alignTicks = false;
                if (chart.yAxis) {
                  chart.yAxis.forEach(function (y) {
                    if (y) {
                      y.options.alignTicks = false;
                      y.options.endOnTick = false;
                      y.options.startOnTick = false;
                      y.options.tickPositioner = dynamicPriceTickPositioner;
                      y.tickPositioner = dynamicPriceTickPositioner;
                      y.tickAmount = undefined;
                    }
                  });
                }
              }
              return chart;
            };
          }
        }

        if (hc.Axis && hc.Axis.prototype) {
          if (hc.Axis.prototype.adjustTickAmount) {
            var origAdjustTickAmount = hc.Axis.prototype.adjustTickAmount;
            hc.Axis.prototype.adjustTickAmount = function () {
              if (!this.isXAxis) {
                return;
              }
              return origAdjustTickAmount.apply(this, arguments);
            };
          }

          if (hc.Axis.prototype.getLinearTickPositions) {
            var origGetLinearTickPositions = hc.Axis.prototype.getLinearTickPositions;
            hc.Axis.prototype.getLinearTickPositions = function (tickInterval, min, max) {
              if (!this.isXAxis) {
                var customTicks = overrideYAxisTicks(this, min, max);
                if (customTicks && customTicks.length >= 2) {
                  return customTicks;
                }
              }
              return origGetLinearTickPositions.apply(this, arguments);
            };
          }

          if (hc.Axis.prototype.getTickPositions) {
            var origGetTickPositions = hc.Axis.prototype.getTickPositions;
            hc.Axis.prototype.getTickPositions = function () {
              if (!this.isXAxis) {
                this.tickAmount = undefined;
                var customTicks = overrideYAxisTicks(this, this.min, this.max);
                if (customTicks && customTicks.length >= 2) {
                  return customTicks;
                }
              }
              return origGetTickPositions.apply(this, arguments);
            };
          }

          if (hc.Axis.prototype.setTickPositions) {
            var origSetTickPositions = hc.Axis.prototype.setTickPositions;
            hc.Axis.prototype.setTickPositions = function () {
              if (!this.isXAxis) {
                this.options.alignTicks = false;
                this.options.endOnTick = false;
                this.options.startOnTick = false;
                this.options.tickPositioner = dynamicPriceTickPositioner;
                this.tickPositioner = dynamicPriceTickPositioner;
                this.tickAmount = undefined;
                if (this.options) this.options.tickAmount = undefined;

                var customTicks = overrideYAxisTicks(this, this.min, this.max);
                if (customTicks && customTicks.length >= 2) {
                  this.tickPositions = customTicks;
                  this.paddedTicks = customTicks.slice(0);
                  return;
                }
              }
              return origSetTickPositions.apply(this, arguments);
            };
          }
        }
      } catch (e) {}
    }

    setInterval(function () {
      if (window.Highcharts && window.Highcharts.charts) {
        window.Highcharts.charts.forEach(function (chart) {
          if (chart && chart.yAxis) {
            chart.yAxis.forEach(function (y) {
              if (y && !y.isXAxis && y.tickPositions && y.tickPositions.length <= 6) {
                var ticks = overrideYAxisTicks(y, y.min, y.max);
                if (ticks && ticks.length >= 2) {
                  y.tickPositions = ticks;
                  y.paddedTicks = ticks.slice(0);
                  if (y.redraw) y.redraw();
                  else if (chart.redraw) chart.redraw(false);
                }
              }
            });
          }
        });
      }
    }, 300);

    if (window.Highcharts) {
      applyHighchartsPatch(window.Highcharts);
    }
    
    var _hcStore = window.Highcharts;
    try {
      Object.defineProperty(window, 'Highcharts', {
        configurable: true,
        enumerable: true,
        get: function () {
          return _hcStore;
        },
        set: function (val) {
          _hcStore = val;
          if (val) {
            applyHighchartsPatch(val);
          }
        }
      });
    } catch (e) {}
  })();

  var nativeFetch = window.fetch.bind(window);
  var NativeWebSocket = window.WebSocket;
  var encoder = window.TextEncoder ? new TextEncoder() : null;
  var sockets = [];
  var marketSnapshot = null;
  var tradeState = null;
  var activeSymbol = 'EURUSD';
  var activeSocketSymbol = 'EURUSD_otc';
  var activePeriod = 60;
  var allowedTradeDurations = parseDurationList(meta('trade-allowed-durations', '5,10,15,30,60,120,300'));
  var selectedTradeDuration = nearestDuration(number(meta('trade-default-duration', '60'), 60));
  var chartHistoryCount = Math.max(60, Math.min(3000, Math.floor(number(meta('trade-chart-history-count', '800'), 800))));
  var marketPollIntervalMs = Math.max(1000, Math.min(5000, Math.floor(number(meta('trade-quote-poll-ms', '1500'), 1500))));
  var marketRequestRunning = false;
  var stateRequestRunning = false;
  var initializationPromise = null;
  var marketTimer = null;
  var stateTimer = null;
  var clockTimer = null;
  var quoteStreamTimer = null;
  var binanceWs = null;
  var serverTimeOffset = 0;
  var lastEmittedTimeSeconds = 0;
  var lastEmittedQuoteSec = 0;
  var currentLivePrice = 0;
  var tickPriceOffset = 0;
  var tickMomentum = 0;
  var tickNumber = 1;
  var firstState = true;
  var previousOpenIds = {};
  var lastStateSignature = '';
  var lastHistoryBroadcastAt = 0;

  function findAsset(symbol) {
    if (!symbol) return null;
    var clean = plainSymbol(symbol).toUpperCase();
    if (marketSnapshot && marketSnapshot.asset && plainSymbol(marketSnapshot.asset.symbol).toUpperCase() === clean) {
      return marketSnapshot.asset;
    }
    if (marketSnapshot && Array.isArray(marketSnapshot.assets)) {
      for (var i = 0; i < marketSnapshot.assets.length; i++) {
        if (plainSymbol(marketSnapshot.assets[i].symbol).toUpperCase() === clean) {
          return marketSnapshot.assets[i];
        }
      }
    }
    return null;
  }

  function isAssetActive(asset) {
    if (!asset) return true;
    if (asset.is_open === false || asset.is_active === 0 || asset.active === false || asset.is_active === false) {
      return false;
    }
    return true;
  }

  function getBinanceStreamSymbol(sym) {
    var clean = String(sym || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    clean = clean.replace(/OTC$/, '');
    var map = {
      // Crypto
      'BTCUSD': 'btcusdt',
      'BTCUSDT': 'btcusdt',
      'ETHUSD': 'ethusdt',
      'ETHUSDT': 'ethusdt',
      'SOLUSD': 'solusdt',
      'SOLUSDT': 'solusdt',
      'BNBUSD': 'bnbusdt',
      'BNBUSDT': 'bnbusdt',
      'XRPUSD': 'xrpusdt',
      'XRPUSDT': 'xrpusdt',
      'DOGEUSD': 'dogeusdt',
      'DOGEUSDT': 'dogeusdt',
      'ADAUSD': 'adausdt',
      'ADAUSDT': 'adausdt',
      'AVAXUSD': 'avaxusdt',
      'AVAXUSDT': 'avaxusdt',
      'DOTUSD': 'dotusdt',
      'DOTUSDT': 'dotusdt',
      'LINKUSD': 'linkusdt',
      'LINKUSDT': 'linkusdt',
      'POLUSD': 'polusdt',
      'POLUSDT': 'polusdt',
      'MATICUSD': 'polusdt',
      'LTCUSD': 'ltcusdt',
      'LTCUSDT': 'ltcusdt',
      'NEARUSD': 'nearusdt',
      'NEARUSDT': 'nearusdt',
      'SHIBUSD': 'shibusdt',
      'SHIBUSDT': 'shibusdt',
      'PEPEUSD': 'pepeusdt',
      'PEPEUSDT': 'pepeusdt',
      'TRXUSD': 'trxusdt',
      'TRXUSDT': 'trxusdt',
      'SUIUSD': 'suiusdt',
      'SUIUSDT': 'suiusdt',
      'APTUSD': 'aptusdt',
      'APTUSDT': 'aptusdt',

      // Commodities
      'XAUUSD': 'paxgusdt',
      'GOLDUSD': 'paxgusdt',
      'XAUTUSD': 'xautusdt',
      'XAUTUSDT': 'xautusdt',
      'GASUSD': 'gasusdt',
      'GASUSDT': 'gasusdt',

      // Stocks
      'TSLAUSD': 'tslabusdt',
      'TSLA': 'tslabusdt',
      'TSLABUSDT': 'tslabusdt',
      'AAPLUSD': 'aaplbusdt',
      'AAPL': 'aaplbusdt',
      'AAPLBUSDT': 'aaplbusdt',
      'NVDAUSD': 'nvdabusdt',
      'NVDA': 'nvdabusdt',
      'NVDABUSDT': 'nvdabusdt',

      // Currencies
      'EURUSD': 'eurusdt',
      'GBPUSD': 'gbpusdt',
      'AUDUSD': 'audusdt'
    };
    return map[clean] || '';
  }

  var currentBinanceStream = '';
  function connectBinanceWs(streamSym) {
    var expectedAsset = findAsset(activeSymbol);
    if (!isAssetActive(expectedAsset)) {
      if (binanceWs) {
        try { binanceWs.close(); } catch (e) {}
        binanceWs = null;
      }
      currentBinanceStream = '';
      return;
    }
    if (!streamSym || !NativeWebSocket) {
      if (binanceWs) {
        try { binanceWs.close(); } catch (e) {}
        binanceWs = null;
      }
      currentBinanceStream = '';
      return;
    }
    if (binanceWs && currentBinanceStream === streamSym && (binanceWs.readyState === 0 || binanceWs.readyState === 1)) {
      return;
    }
    if (binanceWs) {
      try { binanceWs.close(); } catch (e) {}
      binanceWs = null;
    }
    currentBinanceStream = streamSym;
    try {
      var ws = new NativeWebSocket('wss://stream.binance.com:9443/stream?streams=' + streamSym + '@aggTrade');
      binanceWs = ws;
      ws.onmessage = function (ev) {
        try {
          if (binanceWs !== ws || streamSym !== getBinanceStreamSymbol(activeSymbol)) {
            try { ws.close(); } catch (e) {}
            return;
          }
          var msg = JSON.parse(ev.data);
          var data = msg.data || msg;
          if (data && data.p) {
            var rawPrice = parseFloat(data.p);
            if (rawPrice > 0) {
              var currAssetObj = findAsset(activeSymbol);
              if (!isAssetActive(currAssetObj)) {
                return;
              }
              var expectedBase = currAssetObj ? number(currAssetObj.price, 0) : 0;
              if (expectedBase > 0) {
                var scaleRatio = rawPrice / expectedBase;
                if (scaleRatio < 0.1 || scaleRatio > 10.0) {
                  return; // Mismatched stream tick, discard immediately
                }
              }
              var decimals = currAssetObj ? number(currAssetObj.decimals, 2) : 2;
              currentLivePrice = Number(rawPrice.toFixed(decimals));
              tickPriceOffset = 0;
              emitTick(currentLivePrice, activeSocketSymbol);
            }
          }
        } catch (e) {}
      };
      ws.onerror = function () {};
      ws.onclose = function () {
        if (binanceWs === ws) {
          binanceWs = null;
          currentBinanceStream = '';
          window.setTimeout(function () {
            var currStream = getBinanceStreamSymbol(activeSymbol);
            if (currStream) connectBinanceWs(currStream);
          }, 2500);
        }
      };
    } catch (e) {}
  }

  function syncServerTime(serverMs) {
    if (!isFinite(serverMs) || serverMs <= 0) return;
    var targetOffset = serverMs - Date.now();
    if (!serverTimeOffset) {
      serverTimeOffset = targetOffset;
    } else {
      serverTimeOffset = Math.round(serverTimeOffset * 0.85 + targetOffset * 0.15);
    }
  }

  function currentServerSeconds() {
    return Math.floor((Date.now() + serverTimeOffset) / 1000);
  }

  function emitClockTick() {
    var sec = currentServerSeconds();
    if (sec > 0 && sec !== lastEmittedTimeSeconds) {
      lastEmittedTimeSeconds = sec;
      broadcastText('time', sec);
    }
  }

  function emitTick(price, targetSocketSym) {
    var targetSocket = targetSocketSym || activeSocketSymbol;
    if (!price || !targetSocket) return;
    var sym = plainSymbol(targetSocket);
    var expectedAsset = findAsset(sym);
    if (!isAssetActive(expectedAsset)) {
      // Market is closed: do not emit fake moving ticks
      return;
    }
    var expectedBase = expectedAsset ? number(expectedAsset.price, 0) : 0;
    if (expectedBase > 0) {
      var ratio = price / expectedBase;
      if (ratio < 0.1 || ratio > 10.0) {
        return; // Reject price if outside expected asset range
      }
    }
    var decimals = expectedAsset ? number(expectedAsset.decimals, 2) : 2;
    var currentSec = (Date.now() + serverTimeOffset) / 1000;
    if (currentSec <= lastEmittedQuoteSec) {
      currentSec = lastEmittedQuoteSec + 0.001;
    }
    lastEmittedQuoteSec = Number(currentSec.toFixed(3));
    var tickPrice = Number(price.toFixed(decimals));
    var quote = [[targetSocket, lastEmittedQuoteSec, tickPrice, ++tickNumber]];
    broadcastBinary('quotes/stream', quote);
  }

  function emitSmoothQuote() {
    if (!currentLivePrice || !activeSocketSymbol) return;
    var expectedAsset = findAsset(activeSymbol);
    if (!isAssetActive(expectedAsset)) {
      // Market is closed: freeze quote completely at close price
      return;
    }
    var expectedBase = expectedAsset ? number(expectedAsset.price, 0) : 0;
    if (expectedBase > 0) {
      var ratio = currentLivePrice / expectedBase;
      if (ratio < 0.1 || ratio > 10.0) {
        currentLivePrice = expectedBase;
        tickPriceOffset = 0;
        tickMomentum = 0;
      }
    }
    var decimals = expectedAsset ? number(expectedAsset.decimals, 2) : 2;
    var minStep = Math.pow(10, -decimals);

    // Dynamic micro-movement: subtle random walk with momentum
    var randomPulse = (Math.random() - 0.495) * minStep * 1.8;
    tickMomentum = (tickMomentum * 0.80) + randomPulse;
    tickPriceOffset += tickMomentum;

    var maxDrift = minStep * 10;
    if (tickPriceOffset > maxDrift) {
      tickPriceOffset = maxDrift;
      tickMomentum = -Math.abs(tickMomentum) * 0.5;
    } else if (tickPriceOffset < -maxDrift) {
      tickPriceOffset = -maxDrift;
      tickMomentum = Math.abs(tickMomentum) * 0.5;
    }

    var tickPrice = Number((currentLivePrice + tickPriceOffset).toFixed(decimals));
    emitTick(tickPrice, activeSocketSymbol);
  }
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
  var platformName = meta('trade-platform-name', 'Quotex') || 'Quotex';
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

  function parseInitialSnapshot() {
    var encoded = String(meta('trade-initial-snapshot', '') || '');
    if (!encoded || !/^[A-Za-z0-9_-]+$/.test(encoded)) return null;
    try {
      while (encoded.length % 4) encoded += '=';
      var binary = window.atob(encoded.replace(/-/g, '+').replace(/_/g, '/'));
      var bytes = new Uint8Array(binary.length);
      for (var index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
      var json = window.TextDecoder
        ? new TextDecoder('utf-8').decode(bytes)
        : decodeURIComponent(Array.prototype.map.call(bytes, function (byte) { return '%' + byte.toString(16).padStart(2, '0'); }).join(''));
      var snap = JSON.parse(json);
      return snap && snap.asset && Array.isArray(snap.candles) && snap.candles.length ? snap : null;
    } catch (ignored) {
      return null;
    }
  }

  var bootstrapAssets = parseBootstrapAssets();
  var defaultSymbolMeta = meta('trade-default-symbol', '');
  var initialOpenAsset = null;
  if (bootstrapAssets.length) {
    var openAssets = bootstrapAssets.filter(function (a) { return isAssetActive(a); });
    initialOpenAsset = openAssets.length ? openAssets[0] : bootstrapAssets[0];
  }
  if (initialOpenAsset) {
    activeSymbol = plainSymbol(initialOpenAsset.symbol);
    activeSocketSymbol = socketSymbol(activeSymbol);
    if (number(initialOpenAsset.price, 0) > 0) {
      currentLivePrice = number(initialOpenAsset.price, 0);
    }
  } else if (defaultSymbolMeta) {
    activeSymbol = plainSymbol(defaultSymbolMeta);
    activeSocketSymbol = socketSymbol(activeSymbol);
  }

  var snapshotCache = {};
  var embeddedSnapshot = parseInitialSnapshot();
  if (embeddedSnapshot) {
    marketSnapshot = embeddedSnapshot;
    var embAsset = embeddedSnapshot.asset || {};
    if (embAsset.symbol) {
      activeSymbol = plainSymbol(embAsset.symbol);
      activeSocketSymbol = socketSymbol(activeSymbol);
      snapshotCache[activeSymbol + '_' + activePeriod] = embeddedSnapshot;
    }
    if (number(embAsset.price, 0) > 0) {
      currentLivePrice = number(embAsset.price, 0);
    }
  }
  if (!currentLivePrice || currentLivePrice <= 0) {
    currentLivePrice = 1.16130;
  }

  function normalizeModalCategory(category) {
    var key = String(category || 'Currencies').trim();
    var lowered = key.toLowerCase();
    if (lowered === 'forex') return 'currencies';
    if (lowered === 'currency' || lowered === 'currencies') return 'currencies';
    if (lowered === 'crypto' || lowered === 'cryptocurrency') return 'crypto';
    if (lowered === 'commodity' || lowered === 'commodities') return 'commodities';
    if (lowered === 'stock' || lowered === 'stocks') return 'stocks';
    if (lowered === 'indices' || lowered === 'index') return 'indices';
    return lowered;
  }

  function isAssetActive(asset) {
    if (!asset) return false;
    if (asset.active === false || asset.active === 0 || asset.active === '0') return false;
    if (asset.is_active === false || asset.is_active === 0 || asset.is_active === '0') return false;
    if (asset.is_open === false || asset.is_open === 0 || asset.is_open === '0') return false;
    return true;
  }

  function buildModalsFromAssets() {
    var grouped = {};
    bootstrapAssets.forEach(function (asset) {
      var category = String(asset && asset.category ? asset.category : 'Currencies');
      var key = normalizeModalCategory(category);
      if (!grouped[key]) {
        grouped[key] = {id: key, title: category, type: 'market_list', items: []};
      }
      var active = isAssetActive(asset);
      grouped[key].items.push({
        symbol: String(asset.symbol || ''),
        name: String(asset.name || asset.symbol || ''),
        category: category,
        payout: active ? number(asset.payout, 0) : 0,
        volatility: number(asset.volatility, 0),
        active: active,
        is_open: active
      });
    });

    var order = ['currencies', 'crypto', 'commodities', 'indices', 'stocks'];
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
    var s = String(symbol || 'EURUSD').trim().toUpperCase();
    s = s.replace(/(_OTC|-OTC|\s*\(OTC\)|OTC)$/i, '');
    s = s.replace(/[^A-Z0-9]/g, '');
    return s || 'EURUSD';
  }

  function socketSymbol(symbol) {
    return plainSymbol(symbol) + '_otc';
  }

  function findAsset(sym, list) {
    var target = plainSymbol(sym);
    var arr = Array.isArray(list) && list.length ? list : bootstrapAssets;
    for (var i = 0; i < arr.length; i++) {
      if (plainSymbol(arr[i].symbol) === target) return arr[i];
    }
    return arr.length ? arr[0] : null;
  }

  function extractPeriod(payload, fallback) {
    if (!payload) return Math.max(1, Math.min(300, number(fallback, 60)));
    var settings = payload.settings || {};
    // PRIORITY: Look specifically for candle timeframe properties.
    // NEVER fallback to timePeriod (which is trade expiration duration, e.g. 5, 10, 30, 60)!
    var val = payload.chartPeriod || payload.timeframe || payload.period || payload.interval ||
              settings.chartPeriod || settings.timeframe || settings.period || settings.interval;
    if (!val && typeof payload === 'number' && payload > 0) {
      val = payload;
    }
    return Math.max(1, Math.min(300, number(val, fallback)));
  }

  function extractSymbol(payload, fallback) {
    if (!payload) return plainSymbol(fallback);
    var settings = payload.settings || {};
    var sym = payload.asset || payload.symbol || settings.asset || settings.symbol || (settings.currentAsset && settings.currentAsset.symbol);
    return plainSymbol(sym || fallback);
  }

  function bootstrapMarketSnapshot(targetSymbol) {
    var sym = plainSymbol(targetSymbol || activeSymbol);
    var pool = (marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length) ? marketSnapshot.assets : bootstrapAssets;
    var selected = findAsset(sym, pool);
    if (!selected || !isAssetActive(selected)) {
      var firstActive = pool.filter(isAssetActive)[0];
      if (firstActive) {
        selected = firstActive;
        sym = plainSymbol(selected.symbol);
      }
    }
    if (!selected) {
      selected = {
        symbol: sym, name: sym, category: 'Currencies', price: 1.159,
        decimals: 5, payout: 87, changePercent: 0, active: true
      };
    }
    activeSymbol = plainSymbol(selected.symbol);
    activeSocketSymbol = socketSymbol(activeSymbol);
    var nowMs = Date.now() + serverTimeOffset;
    var basePrice = number(selected.price, 0);
    if (basePrice <= 0) basePrice = (activeSymbol.indexOf('BTC') !== -1 ? 80000 : (activeSymbol.indexOf('ETH') !== -1 ? 2500 : 1.159));

    var priceScaleRatio = (currentLivePrice > 0 && basePrice > 0) ? (currentLivePrice / basePrice) : 1;
    if (priceScaleRatio < 0.2 || priceScaleRatio > 5.0) {
      currentLivePrice = basePrice;
      tickPriceOffset = 0;
      tickMomentum = 0;
    }

    var dec = number(selected.decimals, 5);
    var step = Math.pow(10, -dec);
    var candles = [];
    var interval = Math.max(1, Math.min(300, number(activePeriod, 60)));
    var intervalMs = interval * 1000;
    var currBucket = Math.floor(nowMs / intervalMs) * intervalMs;
    var startBucket = currBucket - (chartHistoryCount * intervalMs);
    var runningPrice = basePrice;

    for (var t = startBucket; t <= currBucket; t += intervalMs) {
      var isLast = t === currBucket;
      var cEnd = isLast ? nowMs : (t + intervalMs - 1);
      var subDur = Math.max(100, cEnd - t);
      var cOpen = runningPrice;
      var jitter = (Math.random() - 0.5) * step * 4;
      var cClose = isLast ? currentLivePrice : Number((cOpen + jitter).toFixed(dec));
      var bodySize = Math.abs(cClose - cOpen);
      var wickUp = Math.max(step * (1 + Math.floor(Math.random() * 3)), bodySize * (0.25 + Math.random() * 0.35));
      var wickLow = Math.max(step * (1 + Math.floor(Math.random() * 3)), bodySize * (0.25 + Math.random() * 0.35));
      var cHigh = Number((Math.max(cOpen, cClose) + wickUp).toFixed(dec));
      var cLow = Number((Math.max(step, Math.min(cOpen, cClose) - wickLow)).toFixed(dec));
      var p1 = cClose >= cOpen ? cLow : cHigh;
      var p2 = cClose >= cOpen ? cHigh : cLow;
      candles.push({
        time: t,
        open: cOpen,
        high: cHigh,
        low: cLow,
        close: cClose,
        lastTickTime: cEnd,
        complete: !isLast,
        ticks: [
          [t, cOpen],
          [t + Math.round(subDur * 0.33), p1],
          [t + Math.round(subDur * 0.67), p2],
          [cEnd, cClose]
        ]
      });
      runningPrice = cClose;
    }

    var assets = bootstrapAssets.length ? bootstrapAssets.slice() : [selected];
    return {serverTime: nowMs, asset: selected, assets: assets, candles: candles, engine: 'server-bootstrap'};
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
    if (key.indexOf('indic') !== -1) return 'indices';
    return 'currency';
  }

  function instrumentPayload(asset, index) {
    var active = isAssetActive(asset);
    var payout = active ? number(asset.payout, 0) : 0;
    var timeframes = [5, 10, 15, 30, 60, 120, 180, 300].map(function (time) {
      return {time: time};
    });
    var display = String(asset.name || asset.symbol || 'Market');
    var isOtc = (asset.symbol && asset.symbol.toLowerCase().indexOf('otc') !== -1) || (display.toLowerCase().indexOf('(otc)') !== -1);
    if (isOtc && display.toLowerCase().indexOf('(otc)') === -1) {
      display += ' (OTC)';
    }

    var dec = typeof asset.decimals === 'number' ? asset.decimals : 2;
    var closePrice = (asset.closed_price !== undefined && asset.closed_price !== null && Number(asset.closed_price) > 0)
      ? Number(asset.closed_price).toFixed(dec)
      : (asset.price ? Number(asset.price).toFixed(dec) : '');

    if (!active) {
      if (closePrice) {
        display += ' (Closed: ' + closePrice + ')';
      } else {
        display += ' (Closed)';
      }
    }

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
    var openedAt = number(trade.openedAt, Date.now() + serverTimeOffset);
    var duration = Math.max(1, number(trade.duration, Math.round((number(trade.expiresAt, openedAt + 60000) - openedAt) / 1000)));
    var expiresAt = number(trade.expiresAt, openedAt + (duration * 1000));
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
      duration: duration,
      percentProfit: payout,
      percentLoss: 100,
      payout: payout,
      profit: resultReturn,
      profitLoss: profitLoss,
      accountBalance: stateBalance,
      currency: user.currency,
      currencyCode: user.currency,
      isDemo: String(trade.accountType || user.accountType) === 'demo' ? 1 : 0,
      isClosed: closed,
      status: status,
      resultReturn: resultReturn,
      userBalance: stateBalance,
      refundReason: trade.refundReason || null,
      optionType: 1,
      createdAt: socketTime(openedAt),
      settledAt: trade.settledAt ? socketTime(trade.settledAt) : null
    };
  }

  function openDeals() {
    var list = tradeState && Array.isArray(tradeState.openTrades) ? tradeState.openTrades : [];
    if (!list.length && tradeState && Array.isArray(tradeState.trades)) {
      list = tradeState.trades.filter(function (trade) { return trade.status === 'open'; });
    }
    return list.map(function (trade) { return mapTrade(trade); });
  }

  function closedDeals() {
    var list = tradeState && Array.isArray(tradeState.closedTrades) ? tradeState.closedTrades : [];
    if (!list.length && tradeState && Array.isArray(tradeState.trades)) {
      list = tradeState.trades.filter(function (trade) { return trade.status !== 'open'; });
    }
    return list.map(function (trade) { return mapTrade(trade); });
  }

  function emitTradeLists(targetSocket) {
    var opened = openDeals();
    var closed = closedDeals();
    if (targetSocket) {
      targetSocket.binaryEvent('orders/opened/list', opened);
      targetSocket.binaryEvent('orders/closed/list', closed);
      return;
    }
    broadcastBinary('orders/opened/list', opened);
    broadcastBinary('orders/closed/list', closed);
  }

  function historyPayload(snapshot, requestedSymbol, period) {
    var history = [];
    var count = 0;
    var candles = snapshot && Array.isArray(snapshot.candles) ? snapshot.candles : [];
    var safePeriod = Math.max(1, Math.min(300, number(period, 60)));
    var nowSec = (Date.now() + serverTimeOffset) / 1000;
    var currentAssetObj = snapshot && snapshot.asset ? snapshot.asset : findAsset(requestedSymbol);
    var isClosed = !isAssetActive(currentAssetObj);
    var serverSeconds = number(snapshot && snapshot.serverTime, 0) / 1000;
    if (!serverSeconds || serverSeconds <= 0) serverSeconds = nowSec;
    var maxHistorySec = Math.min(serverSeconds, nowSec);
    if (isClosed && currentAssetObj && currentAssetObj.closed_at) {
      maxHistorySec = Math.min(maxHistorySec, number(currentAssetObj.closed_at, 0) / 1000);
    }
    var previousStamp = -Infinity;
    var totalCandles = candles.length;

    candles.forEach(function (candle, cIndex) {
      var denseTicks = Array.isArray(candle.ticks) ? candle.ticks : [];

      if (denseTicks.length) {
        denseTicks.forEach(function (tick) {
          if (!Array.isArray(tick) || tick.length < 2) return;
          var stamp = Math.min(maxHistorySec, number(tick[0], 0) / 1000);
          var price = number(tick[1], NaN);
          if (!isFinite(stamp) || !isFinite(price)) return;
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
        maxHistorySec
      );
      var availableDuration = Math.max(0.003, availableEnd - start);
      var offsets = [0, availableDuration * 0.28, availableDuration * 0.67, availableDuration];
      var rising = number(candle.close, 0) >= number(candle.open, 0);
      var points = rising
        ? [candle.open, candle.low, candle.high, candle.close]
        : [candle.open, candle.high, candle.low, candle.close];
      points.forEach(function (price, index) {
        var stamp = Math.min(maxHistorySec, start + offsets[index]);
        if (stamp <= previousStamp) stamp = previousStamp + 0.001;
        previousStamp = Number(stamp.toFixed(3));
        history.push([previousStamp, number(price, 0), ++count]);
      });
    });

    // Seamless connection: ensure the last history point touches current live price ONLY if this is an OPEN market
    var isLiveStream = (!isClosed) && ((nowSec - previousStamp) <= (safePeriod * 2));
    if (isLiveStream && history.length > 0 && currentLivePrice > 0) {
      var lastHistPrice = history[history.length - 1][1];
      var scaleRatio = lastHistPrice > 0 ? (currentLivePrice / lastHistPrice) : 1;
      // Completely prevent cross-symbol vertical spikes (e.g. 1.15 to 81,000)
      if (scaleRatio >= 0.5 && scaleRatio <= 2.0) {
        if (previousStamp < nowSec) {
          var finalStamp = Number(nowSec.toFixed(3));
          if (finalStamp <= previousStamp) finalStamp = previousStamp + 0.001;
          history.push([finalStamp, currentLivePrice, ++count]);
          previousStamp = finalStamp;
        }
      }
    }

    if (isClosed && history.length > 0 && currentAssetObj) {
      var officialClosedP = number((currentAssetObj.closed_price !== undefined && currentAssetObj.closed_price !== null) ? currentAssetObj.closed_price : currentAssetObj.price, 0);
      if (officialClosedP > 0) {
        history[history.length - 1][1] = officialClosedP;
      }
    }

    if (previousStamp > 0 && isFinite(previousStamp)) {
      lastEmittedQuoteSec = Math.max(lastEmittedQuoteSec, previousStamp);
    }

    return {
      asset: requestedSymbol,
      period: safePeriod,
      history: history,
      candles: []
    };
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

  function refreshMarket(sendHistory, socket, customCount) {
    if (marketRequestRunning) {
      if (!sendHistory) return Promise.resolve(marketSnapshot);
      return new Promise(function (resolve) {
        window.setTimeout(function () { resolve(refreshMarket(true, socket, customCount)); }, 180);
      });
    }
    marketRequestRunning = true;
    var symbolAtStart = activeSymbol;
    var periodAtStart = Math.max(1, Math.min(300, number(activePeriod, 60)));
    var countToFetch = Math.max(60, Math.min(10000, number(customCount, chartHistoryCount)));
    var endpoint = '/en/api/market.php?symbol=' + encodeURIComponent(symbolAtStart) +
      '&interval=' + encodeURIComponent(String(periodAtStart)) +
      '&count=' + encodeURIComponent(String(countToFetch)) +
      '&history=' + (sendHistory ? '1' : '0');

    return jsonRequest(endpoint).then(function (snapshot) {
      if (snapshot && snapshot.asset && Array.isArray(snapshot.candles) && snapshot.candles.length) {
        var cacheKey = plainSymbol(snapshot.asset.symbol) + '_' + periodAtStart;
        snapshotCache[cacheKey] = snapshot;
      }
      marketSnapshot = snapshot;
      if (symbolAtStart !== activeSymbol) return snapshot;
      var asset = snapshot.asset || {};
      var serverTime = number(snapshot.serverTime, Date.now());
      syncServerTime(serverTime);
      var price = number(asset.price, 0);
      if (price > 0) currentLivePrice = price;

      var apiCandles = snapshot && Array.isArray(snapshot.candles) ? snapshot.candles : [];
      snapshot.candles = apiCandles;

      if (sendHistory) {
        var hPayload = historyPayload(snapshot, activeSocketSymbol, periodAtStart);
        if (socket && typeof socket.binaryEvent === 'function') {
          socket.binaryEvent('history/list/v2', hPayload);
        } else {
          broadcastBinary('history/list/v2', hPayload);
        }
        lastHistoryBroadcastAt = Date.now();
      }
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
      var trades = Array.isArray(state.trades) ? state.trades : (Array.isArray(state.openTrades) ? state.openTrades.concat(state.closedTrades || []) : []);
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

  var prewarmTimer = null;
  function prewarmSnapshots() {
    if (prewarmTimer) return;
    prewarmTimer = window.setTimeout(function () {
      var topSymbols = ['BTCUSD', 'SOLUSD', 'ETHUSD', 'BNBUSD', 'EURUSD', 'GBPUSD', 'AAPLUSD', 'US30', 'XAUUSD'];
      topSymbols.forEach(function (sym, idx) {
        var s = plainSymbol(sym);
        if (s !== activeSymbol && !snapshotCache[s + '_' + activePeriod]) {
          window.setTimeout(function () {
            jsonRequest('/en/api/market.php?symbol=' + encodeURIComponent(s) + '&interval=' + encodeURIComponent(String(activePeriod)) + '&count=' + encodeURIComponent(String(chartHistoryCount)) + '&history=1').then(function (snap) {
              if (snap && snap.asset && Array.isArray(snap.candles) && snap.candles.length) {
                snapshotCache[plainSymbol(snap.asset.symbol) + '_' + activePeriod] = snap;
              }
            }).catch(function () {});
          }, idx * 250);
        }
      });
    }, 800);
  }

  function startPolling() {
    if (!clockTimer) {
      clockTimer = window.setInterval(emitClockTick, 250);
    }
    if (!quoteStreamTimer) {
      quoteStreamTimer = window.setInterval(emitSmoothQuote, 100);
    }
    if (!marketTimer) {
      marketTimer = window.setInterval(function () { refreshMarket(false, null); }, marketPollIntervalMs);
    }
    if (!stateTimer) {
      stateTimer = window.setInterval(function () { refreshState(false); }, 1800);
    }
    prewarmSnapshots();
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

  function parseTimeStringToSeconds(str) {
    if (!str || typeof str !== 'string') return NaN;
    var trimmed = str.trim();
    var hms = trimmed.match(/^(\d{1,2}):(\d{2}):(\d{2})$/);
    if (hms) {
      return (parseInt(hms[1], 10) * 3600) + (parseInt(hms[2], 10) * 60) + parseInt(hms[3], 10);
    }
    var ms = trimmed.match(/^(\d{1,2}):(\d{2})$/);
    if (ms) {
      return (parseInt(ms[1], 10) * 60) + parseInt(ms[2], 10);
    }
    var unit = trimmed.match(/^(\d+)\s*(s|sec|m|min)?$/i);
    if (unit) {
      var num = parseInt(unit[1], 10);
      if (unit[2] && /^m/i.test(unit[2])) return num * 60;
      return num;
    }
    return NaN;
  }

  function readTradeDurationFromDom() {
    var root = document.getElementById('root') || document.body;

    // 1. Check if a confirmation modal / popup is visible (e.g. "Time: 00:01:00" or "in 00:01:00")
    var modal = document.querySelector('.modal, .popup, .dialog, [role="dialog"], [class*="modal"], [class*="confirm"]');
    if (modal) {
      var modalText = normalizedText(modal);
      var timeMatch = modalText.match(/(?:time|in)\s*[:\s]\s*(\d{1,2}:\d{2}(?::\d{2})?)/i) ||
                      modalText.match(/in\s+[0-9.,]+\s*[$₹€£]\s+in\s+(\d{1,2}:\d{2}(?::\d{2})?)/i) ||
                      modalText.match(/(\d{1,2}:\d{2}:\d{2})/);
      if (timeMatch) {
        var secFromModal = parseTimeStringToSeconds(timeMatch[1]);
        if (isFinite(secFromModal) && secFromModal >= 5 && secFromModal <= 86400) {
          return secFromModal;
        }
      }
    }

    // 2. Check input elements in deal panel (right sidebar)
    var inputs = root.querySelectorAll ? root.querySelectorAll('input') : [];
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      if (input.disabled || input.type === 'hidden' || input.type === 'search') continue;
      var val = String(input.value || '').trim();
      var parsedSec = parseTimeStringToSeconds(val);
      if (isFinite(parsedSec) && parsedSec >= 5 && parsedSec <= 86400) {
        var node = input;
        var context = '';
        for (var d = 0; node && d < 4; d++, node = node.parentElement) {
          context += ' ' + (node.className || '') + ' ' + normalizedText(node);
        }
        if (/time|timer|duration|expiration|expiry|period/i.test(context + ' ' + input.name + ' ' + input.id) || /^\d{1,2}:\d{2}(?::\d{2})?$/.test(val)) {
          return parsedSec;
        }
      }
    }

    // 3. Check active / selected timer chips
    var activeTimerBtn = root.querySelector ? root.querySelector('.is-active[class*="time"], .active[class*="time"], [class*="time"][class*="selected"], [class*="expiration"][class*="active"]') : null;
    if (activeTimerBtn) {
      var btnText = normalizedText(activeTimerBtn);
      var secFromBtn = parseTimeStringToSeconds(btnText);
      if (isFinite(secFromBtn) && secFromBtn >= 5 && secFromBtn <= 86400) {
        return secFromBtn;
      }
    }

    return NaN;
  }

  function openLocalTrade(socket, request) {
    if (accountSwitchPromise) {
      return accountSwitchPromise.then(function () { return openLocalTrade(socket, request); });
    }
    var nowSeconds = currentServerSeconds();
    var rawTime = number(request.time, 0);
    var domDuration = readTradeDurationFromDom();
    var duration = 60;
    var targetCloseTime = null;

    if (isFinite(domDuration) && domDuration >= 5 && domDuration <= 86400) {
      duration = Math.round(domDuration);
      selectedTradeDuration = duration;
      targetCloseTime = null;
    } else if (rawTime >= 5 && rawTime <= 86400) {
      duration = Math.round(rawTime);
      selectedTradeDuration = duration;
      targetCloseTime = null;
    } else if (rawTime > 100000000000) {
      rawTime = Math.floor(rawTime / 1000);
      var diff = rawTime - nowSeconds;
      if (diff >= 5 && diff <= 86400) {
        duration = diff;
      } else {
        duration = selectedTradeDuration || 60;
      }
      targetCloseTime = null;
    } else if (rawTime > 100000000) {
      var diff2 = rawTime - nowSeconds;
      if (diff2 >= 5 && diff2 <= 86400) {
        duration = diff2;
      } else {
        duration = selectedTradeDuration || 60;
      }
      targetCloseTime = null;
    } else {
      duration = allowedTradeDurations.indexOf(selectedTradeDuration) !== -1 ? selectedTradeDuration : 60;
      targetCloseTime = null;
    }

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
      targetCloseTime: targetCloseTime,
      account: user.accountType,
      requestId: clientRequestId
    };

    var currentAssetObj = findAsset(data.symbol || activeSymbol);
    if (!isAssetActive(currentAssetObj)) {
      var assetTitle = currentAssetObj && (currentAssetObj.name || currentAssetObj.symbol) || data.symbol || activeSymbol;
      showNotice('Market is closed for ' + assetTitle + '. Trading is currently unavailable.', 'warning', 4000);
      return Promise.resolve(null);
    }

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
    socket.event('time', currentServerSeconds() || Math.floor(Date.now() / 1000));
    socket.binaryEvent('s_balance/list', balancePayload());
    socket.binaryEvent('instruments/list', instrumentsList());
    socket.binaryEvent('settings/list', graphSettings());
    socket.binaryEvent('history/list/v2', historyPayload(marketSnapshot || bootstrapMarketSnapshot(), activeSocketSymbol, activePeriod));
    emitTradeLists(socket);
    emitClockTick();
    emitSmoothQuote();
  }

  function initializeSocket(socket) {
    if (!marketSnapshot) marketSnapshot = bootstrapMarketSnapshot();
    if (!tradeState) tradeState = bootstrapTradeState();

    // Start clock and quote streaming immediately on first authorization
    startPolling();

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
      var serverTime = number(marketSnapshot.serverTime, Date.now());
      syncServerTime(serverTime);
      var selected = marketSnapshot.asset || {};
      var assetPrice = number(selected.price, 0);
      if (assetPrice > 0) currentLivePrice = assetPrice;
      activeSymbol = plainSymbol(selected.symbol || 'EURUSD');
      activeSocketSymbol = socketSymbol(activeSymbol);
      connectBinanceWs(getBinanceStreamSymbol(activeSymbol));

      emitClockTick();
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
      if (savedSettings.timePeriod || payload.timePeriod) {
        selectedTradeDuration = nearestDuration(number(savedSettings.timePeriod || payload.timePeriod, 60));
      }
      if (savedSettings.currentExpirationTime || payload.currentExpirationTime) {
        selectedTradeDuration = nearestDuration(number(savedSettings.currentExpirationTime || payload.currentExpirationTime, selectedTradeDuration));
      }
      var reqSymStore = extractSymbol(payload, activeSymbol);
      var hasChartPeriod = Boolean(payload && (payload.chartPeriod || payload.timeframe || payload.period || payload.interval ||
                                  (payload.settings && (payload.settings.chartPeriod || payload.settings.timeframe || payload.settings.period || payload.settings.interval))));
      var reqPerStore = hasChartPeriod ? extractPeriod(payload, activePeriod) : activePeriod;
      var storeChanged = reqSymStore !== activeSymbol || (hasChartPeriod && reqPerStore !== activePeriod);
      activeSymbol = reqSymStore;
      activeSocketSymbol = socketSymbol(activeSymbol);
      if (hasChartPeriod) {
        activePeriod = reqPerStore;
      }

      if (storeChanged) {
        var storeAsset = findAsset(activeSymbol, marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length ? marketSnapshot.assets : bootstrapAssets);
        if (storeAsset && number(storeAsset.price, 0) > 0) {
          currentLivePrice = number(storeAsset.price, 0);
          tickPriceOffset = 0;
          tickMomentum = 0;
        }
        connectBinanceWs(getBinanceStreamSymbol(activeSymbol));
      }

      if (!marketSnapshot || storeChanged) {
        refreshMarket(true, socket, chartHistoryCount);
      }
      return;
    }
    if (event === 'instruments/update') {
      var reqSym = extractSymbol(payload, activeSymbol);
      var hasInstPeriod = Boolean(payload && (payload.chartPeriod || payload.timeframe || payload.period || payload.interval ||
                                  (payload.settings && (payload.settings.chartPeriod || payload.settings.timeframe || payload.settings.period || payload.settings.interval))));
      var reqPer = hasInstPeriod ? extractPeriod(payload, activePeriod) : activePeriod;
      var instChanged = reqSym !== activeSymbol || (hasInstPeriod && reqPer !== activePeriod);
      activeSymbol = reqSym;
      activeSocketSymbol = socketSymbol(activeSymbol);
      if (hasInstPeriod) {
        activePeriod = reqPer;
      }

      if (instChanged) {
        var instAsset = findAsset(activeSymbol, marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length ? marketSnapshot.assets : bootstrapAssets);
        if (instAsset && number(instAsset.price, 0) > 0) {
          currentLivePrice = number(instAsset.price, 0);
          tickPriceOffset = 0;
          tickMomentum = 0;
        }
        connectBinanceWs(getBinanceStreamSymbol(activeSymbol));
      }

      if (!marketSnapshot || instChanged) {
        refreshMarket(true, socket, chartHistoryCount);
      }
      return;
    }
    if (event === 'history/list/v2' || event === 'history/list' || event === 'chart/history' || (typeof event === 'string' && event.indexOf('history/') === 0)) {
      var reqSymbol = extractSymbol(payload, activeSymbol);
      var hasHistPeriod = Boolean(payload && (payload.chartPeriod || payload.timeframe || payload.period || payload.interval ||
                                  (payload.settings && (payload.settings.chartPeriod || payload.settings.timeframe || payload.settings.period || payload.settings.interval))));
      var reqPeriod = hasHistPeriod ? extractPeriod(payload, activePeriod) : activePeriod;
      var symbolOrPeriodChanged = reqSymbol !== activeSymbol || (hasHistPeriod && reqPeriod !== activePeriod);

      if (symbolOrPeriodChanged) {
        activeSymbol = reqSymbol;
        activeSocketSymbol = socketSymbol(activeSymbol);
        if (hasHistPeriod) {
          activePeriod = reqPeriod;
        }

        var nextAsset = findAsset(activeSymbol, marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length ? marketSnapshot.assets : bootstrapAssets);
        if (nextAsset && number(nextAsset.price, 0) > 0) {
          currentLivePrice = number(nextAsset.price, 0);
          tickPriceOffset = 0;
          tickMomentum = 0;
        }

        var cachedSnap = snapshotCache[activeSymbol + '_' + activePeriod];
        if (cachedSnap) {
          marketSnapshot = cachedSnap;
          var cAsset = cachedSnap.asset || {};
          if (number(cAsset.price, 0) > 0) currentLivePrice = number(cAsset.price, 0);
          socket.binaryEvent('history/list/v2', historyPayload(marketSnapshot, activeSocketSymbol, activePeriod));
        }

        connectBinanceWs(getBinanceStreamSymbol(activeSymbol));
        refreshMarket(true, socket, chartHistoryCount);
        emitClockTick();
        emitSmoothQuote();
        return;
      }

      var beforeTimeSec = payload && (payload.time || payload.end || payload.before) ? number(payload.time || payload.end || payload.before, 0) : 0;
      if (beforeTimeSec > 0 && (event === 'history/load' || event === 'history/load/v2' || event.indexOf('load') !== -1)) {
        var beforeMs = Math.round(beforeTimeSec * 1000);
        jsonRequest('/en/api/market.php?symbol=' + encodeURIComponent(activeSymbol) + '&interval=' + encodeURIComponent(String(activePeriod)) + '&count=' + encodeURIComponent(String(chartHistoryCount)) + '&history=1&endMs=' + beforeMs).then(function (olderSnapshot) {
          var olderPayload = historyPayload(olderSnapshot, activeSocketSymbol, activePeriod);
          if (payload && payload.index !== undefined) {
            olderPayload.index = payload.index;
          }
          socket.binaryEvent(event, olderPayload);
        }).catch(function () {
          // ignore
        });
        return;
      }

      var currentSnapSymbol = marketSnapshot && marketSnapshot.asset ? plainSymbol(marketSnapshot.asset.symbol) : '';
      if (!marketSnapshot || currentSnapSymbol !== activeSymbol) {
        refreshMarket(true, socket, chartHistoryCount);
      } else {
        socket.binaryEvent('history/list/v2', historyPayload(marketSnapshot, activeSocketSymbol, activePeriod));
        if (event !== 'history/list/v2' && typeof socket.binaryEvent === 'function') {
          socket.binaryEvent(event, historyPayload(marketSnapshot, activeSocketSymbol, activePeriod));
        }
      }
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
      var count = 0;
      while (socket._outboundQueue.length && count < 32) {
        var frame = socket._outboundQueue.shift();
        count++;
        try {
          socket.message(frame);
        } catch (dispatchError) {
          console.error('Socket frame dispatch error:', dispatchError);
        }
      }
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
    if (typeof data !== 'string') return;
    var match = data.match(/^42(\d*)([\s\S]*)$/);
    if (!match) return;
    var rawBody = match[2];
    var packet;
    try {
      packet = JSON.parse(rawBody);
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
    var currentAssetObj = findAsset(activeSymbol);
    if (!isAssetActive(currentAssetObj)) {
      var assetTitle = currentAssetObj && (currentAssetObj.name || currentAssetObj.symbol) || activeSymbol;
      showNotice('Market is closed for ' + assetTitle + '. Trading is currently unavailable.', 'warning', 4000);
      return;
    }
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
    var domDuration = readTradeDurationFromDom();
    var fallbackDuration = (isFinite(domDuration) && domDuration >= 5) ? domDuration : (selectedTradeDuration || 60);
    openLocalTrade(socket, {
      asset: activeSocketSymbol,
      action: direction === 'down' ? 'put' : 'call',
      amount: amount,
      time: fallbackDuration,
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
    var currentAssetObj = findAsset(activeSymbol);
    if (!isAssetActive(currentAssetObj)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      var assetTitle = currentAssetObj && (currentAssetObj.name || currentAssetObj.symbol) || activeSymbol;
      showNotice('Market is closed for ' + assetTitle + '. Trading is currently unavailable.', 'warning', 4000);
      return;
    }
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

  // Global capture click listener so any logout button, link, or text triggers immediate logout
  document.addEventListener('click', function (e) {
    var target = e.target;
    if (!target) return;
    var logoutElem = target.closest('[data-local-logout], a[href*="logout"], a[href="#logout"], button[class*="logout"], .original-header-logout-btn, .original-mobile-logout-btn');
    if (!logoutElem && target.textContent && /^(?:logout|sign\s*out)$/i.test(target.textContent.trim())) {
      logoutElem = target;
    }
    if (logoutElem) {
      e.preventDefault();
      e.stopPropagation();
      logout();
    }
  }, true);

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
      if (/tradezen|55ak trade/i.test(node.nodeValue || '')) {
        node.nodeValue = node.nodeValue.replace(/tradezen|55ak trade/gi, 'Quotex');
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

  function ensureHeaderLogoutButton() {
    var header = document.querySelector('.rgx9T, .TJbx1, header, .app__header, div[class*="rgx9T"]');
    if (!header) return;
    if (header.querySelector('.original-header-logout-btn')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'original-header-logout-btn';
    btn.title = 'Sign out / Logout';
    btn.setAttribute('aria-label', 'Sign out');
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout</span>';
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      logout();
    });
    header.appendChild(btn);
  }

  function ensureMobileLogoutButton() {
    var isMobile = window.innerWidth <= 800;
    if (!isMobile) return;

    var popups = document.querySelectorAll('div[role="dialog"], div[class*="modal"], div[class*="dialog"], div[class*="popover"], div[class*="dropdown"], div[class*="account"], div[class*="cabinet"], div[class*="drawer"], div[class*="menu"], div[class*="sheet"], div[class*="panel"], div[class*="sidebar"], nav[class*="sidebar"], .app__sidebar, [class*="listMenu"]');
    popups.forEach(function (popup) {
      if (popup.querySelector('.original-mobile-logout-btn')) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'original-mobile-logout-btn';
      btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg><span>Logout / Sign out</span>';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        logout();
      });
      popup.appendChild(btn);
    });
  }

  function refreshDom() {
    domRefreshQueued = false;
    document.title = platformName + ' — Trading platform';
    var root = document.getElementById('root');
    rebrandText(root);
    ensureAffiliateShortcut();
    ensureHeaderLogoutButton();
    ensureMobileLogoutButton();

    var headerLogos = document.querySelectorAll('.TJbx1 .V8tvg, header a[class*="V8tvg"], header a[class*="logo"], div[class*="TJbx1"] a');
    headerLogos.forEach(function (headerLogo) {
      if (!headerLogo) return;
      headerLogo.classList.add('is-local-branded');
      headerLogo.removeAttribute('data-platform-name');
      headerLogo.removeAttribute('data-platform-mark');
      var logoSrc = customLogoUrl || '/site/img/quotex_logo-white.svg';
      var logoImage = headerLogo.querySelector('.original-custom-logo');
      if (!logoImage) {
        logoImage = document.createElement('img');
        logoImage.className = 'original-custom-logo';
        headerLogo.appendChild(logoImage);
      }
      if (logoImage.getAttribute('src') !== logoSrc) {
        logoImage.src = logoSrc;
      }
      logoImage.alt = 'QUOTEX';

      var oldTextSpan = headerLogo.querySelector('.original-logo-text');
      if (oldTextSpan) {
        oldTextSpan.parentNode.removeChild(oldTextSpan);
      }

      headerLogo.classList.add('has-custom-logo');

      // Hide legacy text nodes or extra elements except custom logo image
      Array.prototype.forEach.call(headerLogo.childNodes, function (child) {
        if (child !== logoImage) {
          if (child.nodeType === 3) {
            child.nodeValue = '';
          } else if (child.nodeType === 1 && !child.classList.contains('original-custom-logo')) {
            child.style.display = 'none';
          }
        }
      });
    });

    var headerSubTexts = document.querySelectorAll('.TJbx1 .WQMTk, .TJbx1 [class*="WQMTk"], .TJbx1 [class*="PHfPO"]');
    headerSubTexts.forEach(function (sub) {
      sub.style.display = 'none';
      sub.textContent = '';
    });

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

    document.querySelectorAll('tr, [role="button"], button, [class*="item"]').forEach(function (row) {
      var text = (row.innerText || row.textContent || '');
      if (text.indexOf('(Closed') !== -1 || text.indexOf('Market Closed') !== -1 || text.indexOf('Closed:') !== -1) {
        row.setAttribute('data-closed', '1');
        row.setAttribute('title', 'Market is currently closed');
      }
    });

    var curAsset = findAsset(activeSymbol);
    var isClosed = !isAssetActive(curAsset);
    if (isClosed) {
      document.body.classList.add('is-market-closed-active');
      var existingBanner = document.getElementById('originalMarketClosedBanner');
      if (!existingBanner) {
        existingBanner = document.createElement('div');
        existingBanner.id = 'originalMarketClosedBanner';
        existingBanner.className = 'original-market-closed-banner';
      }
      var closePriceVal = (curAsset && curAsset.price !== undefined && curAsset.price !== null && Number(curAsset.price) > 0) ? curAsset.price : ((curAsset && curAsset.closed_price !== undefined && curAsset.closed_price !== null) ? curAsset.closed_price : null);
      var dec = (curAsset && typeof curAsset.decimals === 'number') ? curAsset.decimals : 2;
      var priceDisplay = (closePriceVal !== null && closePriceVal !== undefined && Number(closePriceVal) > 0) ? Number(closePriceVal).toFixed(dec) : '';
      var statusMsg = (curAsset && curAsset.status_text) ? curAsset.status_text : 'Market is Closed';
      
      existingBanner.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" style="flex-shrink:0;margin-top:2px;"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>' +
        '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;text-align:center;width:100%;">' +
          '<div style="font-weight:700;font-size:12px;letter-spacing:0.02em;">' + statusMsg + '</div>' +
          (priceDisplay ? '<div style="font-size:11px;opacity:0.9;font-weight:600;">Closed at point: <strong style="color:#fff;">' + priceDisplay + '</strong></div>' : '') +
        '</div>';
      
      // Target the trade options menu on the right (below the Down/Put trade button)
      var downBtn = null;
      var allButtons = document.querySelectorAll('button');
      for (var bi = 0; bi < allButtons.length; bi++) {
        var btnText = normalizedText(allButtons[bi]).toLowerCase();
        if (btnText === 'down' || btnText === 'put' || allButtons[bi].getAttribute('data-text') === 'Down') {
          downBtn = allButtons[bi];
          break;
        }
      }

      if (downBtn && downBtn.parentElement) {
        var host = downBtn.parentElement;
        if (existingBanner.parentElement !== host || host.lastChild !== existingBanner) {
          host.appendChild(existingBanner);
        }
      } else {
        var dealContainer = document.querySelector('.section-deal') || document.querySelector('.deal-form') || document.querySelector('[class*="section-deal"]') || document.querySelector('[class*="deal-form"]');
        if (dealContainer && existingBanner.parentElement !== dealContainer) {
          dealContainer.appendChild(existingBanner);
        }
      }
    } else {
      document.body.classList.remove('is-market-closed-active');
      var bannerToRemove = document.getElementById('originalMarketClosedBanner');
      if (bannerToRemove) bannerToRemove.remove();
    }
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

    // Prevent selecting closed market pairs ONLY inside the "Select trade pair" modal / dropdown
    document.addEventListener('click', function (e) {
      var target = e.target;
      if (!target) return;

      // NEVER block tab close / cancel buttons (e.g. the "x" on tabs) or anything in the tab bar
      if (target.closest('button[class*="close"], [class*="tab__close"], [class*="close"], [aria-label*="close" i], svg, path')) {
        var isModalDialog = target.closest('[role="dialog"], [class*="modal"], [class*="dialog"]');
        if (!isModalDialog) {
          return; // Allow closing tabs freely!
        }
      }

      // ONLY apply to the trade pair selection modal dialog
      var modal = target.closest('[role="dialog"], [class*="modal"], [class*="dialog"]');
      if (!modal) {
        return; // Allow clicking tabs, header, charts, controls freely!
      }

      // Inside the modal, check if user is clicking on a closed trade pair row/item
      var row = target.closest('tr, [role="button"], button, [class*="item"], [class*="row"]');
      if (!row) return;

      var text = (row.innerText || row.textContent || '').trim();
      if (/closed/i.test(text)) {
        var pool = (marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length) ? marketSnapshot.assets : bootstrapAssets;
        var foundClosed = pool.filter(function (a) {
          if (isAssetActive(a)) return false;
          var s = plainSymbol(a.symbol);
          var n = String(a.name || a.symbol);
          return text.indexOf(s) !== -1 || text.indexOf(n) !== -1 || (a.closed_price && text.indexOf(String(a.closed_price)) !== -1);
        })[0];

        if (foundClosed || /market\s+closed/i.test(text) || /\(closed/i.test(text)) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();
          var closedName = foundClosed ? (foundClosed.name || foundClosed.symbol) : 'Selected market';
          var dec = foundClosed && typeof foundClosed.decimals === 'number' ? foundClosed.decimals : 2;
          var closedPr = foundClosed && foundClosed.closed_price ? Number(foundClosed.closed_price).toFixed(dec) : '';
          var msg = 'Market is closed for ' + closedName + (closedPr ? ' (at ' + closedPr + ')' : '') + '. Please choose an active market.';
          showNotice(msg, 'warning', 3500);
          return false;
        }
      }
    }, true);

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
