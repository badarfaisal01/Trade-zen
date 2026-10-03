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

  (function patchCanvas2D() {
    if (typeof HTMLCanvasElement !== 'undefined' && HTMLCanvasElement.prototype.getContext) {
      var origGetContext = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function (type, options) {
        if (type === '2d') {
          options = Object.assign({willReadFrequently: true}, options || {});
        }
        return origGetContext.call(this, type, options);
      };
    }
  })();

  // Prevent browser native PWA install prompt popup and dismiss app banner in trade room
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
  });
  try {
    localStorage.setItem('app_banner', String(Date.now()));
  } catch (ignored) {}

  // Patch Highcharts Y-axis price scale to force step distance of 2 and close label spacing
  (function configureHighchartsYAxis() {
    function dynamicPriceTickPositioner(min, max) {
      if (min === undefined || max === undefined || isNaN(min) || isNaN(max)) {
        return;
      }
      if (this) {
        this.tickAmount = undefined;
        if (this.options) {
          this.options.tickAmount = undefined;
        }
      }

      var range = Math.abs(max - min);
      if (range <= 0) {
        range = Math.max(Math.abs(min) * 0.00001, 0.00001);
        min -= range;
        max += range;
      }

      var step;

      // Enforce ultra-dense step distance of 2 (or 0.0002, 0.002, 0.02, 0.2, 2, 5) for maximum price density
      if (range <= 0.003) {
        step = 0.0001;
      } else if (range <= 0.01) {
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
      if (options.chart) {
        options.chart.spacingRight = isMobile ? 34 : 54;
      }
      if (options.xAxis) {
        var xAxes = Array.isArray(options.xAxis) ? options.xAxis : [options.xAxis];
        xAxes.forEach(function (x) {
          if (!x) return;
          x.ordinal = true;
          x.maxPadding = 0;
          x.endOnTick = false;
        });
      }
      var targetTickPixelInterval = isMobile ? 12 : 16;

      if (options.yAxis) {
        var axes = Array.isArray(options.yAxis) ? options.yAxis : [options.yAxis];
        axes.forEach(function (y) {
          if (!y) return;
          y.alignTicks = false;
          y.opposite = true;
          y.endOnTick = false;
          y.startOnTick = false;
          y.tickPositioner = dynamicPriceTickPositioner;
          y.tickPixelInterval = targetTickPixelInterval;
          y.minorTickInterval = null;
          if (!y.labels) y.labels = {};
          y.labels.enabled = true;
          y.labels.align = 'right';
          y.labels.x = 0;
          y.labels.style = Object.assign({}, y.labels.style || {}, {
            color: '#d7deef',
            fontSize: isMobile ? '10px' : '11px',
            textOutline: 'none'
          });
          y.labels.reserveSpace = true;
          y.labels.step = 1;
        });
      }
    }

    function applyHighchartsPatch(hc) {
      if (!hc || hc._tradeScalePatchedV7) return;
      hc._tradeScalePatchedV7 = true;

      try {
        if (hc.seriesTypes && hc.seriesTypes.candlestick && hc.seriesTypes.candlestick.prototype) {
          var origCandlestickDrawPoints = hc.seriesTypes.candlestick.prototype.drawPoints;
          hc.seriesTypes.candlestick.prototype.drawPoints = function () {
            var series = this;
            if (series && series.points && Array.isArray(series.points)) {
              series.points.forEach(function (r) {
                if (r && void 0 !== r.plotY && r.plotOpen !== undefined && r.plotClose !== undefined) {
                  var diff = Math.abs(r.plotOpen - r.plotClose);
                  if (diff < 3.0) {
                    var mid = (r.plotOpen + r.plotClose) / 2;
                    if (r.plotOpen >= r.plotClose) {
                      r.plotOpen = mid + 1.5;
                      r.plotClose = mid - 1.5;
                    } else {
                      r.plotOpen = mid - 1.5;
                      r.plotClose = mid + 1.5;
                    }
                  }
                }
              });
            }
            return origCandlestickDrawPoints.apply(this, arguments);
          };
        }

        var isMobile = window.innerWidth <= 800;

        if (hc.setOptions) {
          hc.setOptions({
            chart: {
              alignTicks: false,
              spacingRight: isMobile ? 34 : 54
            },
            xAxis: {
              ordinal: true,
              maxPadding: 0,
              endOnTick: false
            },
            yAxis: {
              alignTicks: false,
              opposite: true,
              endOnTick: false,
              startOnTick: false,
              tickPositioner: dynamicPriceTickPositioner,
              tickPixelInterval: isMobile ? 12 : 16,
              minorTickInterval: null,
              labels: {
                enabled: true,
                align: 'right',
                x: 0,
                style: {
                  color: '#d7deef',
                  fontSize: isMobile ? '10px' : '11px',
                  textOutline: 'none'
                },
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
                      y.options.opposite = true;
                      y.options.endOnTick = false;
                      y.options.startOnTick = false;
                      y.options.tickPositioner = dynamicPriceTickPositioner;
                      y.options.labels = y.options.labels || {};
                      y.options.labels.enabled = true;
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
            renderMainPriceScale(chart);
          }
        });
      }
    }, 300);

    function renderMainPriceScale(chart) {
      if (!chart || !chart.container || !chart.yAxis || chart.plotWidth < 500 || chart.chartHeight < 300) return;
      var axis = chart.yAxis.find(function (item) { return item && !item.isXAxis && item.visible !== false; });
      if (!axis || !Array.isArray(axis.tickPositions) || axis.tickPositions.length < 2 || typeof axis.toPixels !== 'function') return;

      var scale = chart.container.querySelector('.trade-price-scale');
      if (!scale) {
        scale = document.createElement('div');
        scale.className = 'trade-price-scale';
        scale.style.position = 'absolute';
        scale.style.pointerEvents = 'none';
        scale.style.zIndex = '8';
        chart.container.style.position = 'relative';
        chart.container.appendChild(scale);
      }
      scale.innerHTML = '';

      var range = Math.abs(Number(axis.max) - Number(axis.min));
      var decimals = range < 10 ? 5 : 2;
      axis.tickPositions.forEach(function (tick) {
        var y = axis.toPixels(tick, false);
        if (!isFinite(y) || y < chart.plotTop - 4 || y > chart.plotTop + chart.plotHeight + 4) return;
        var label = document.createElement('span');
        label.textContent = Number(tick).toFixed(decimals);
        label.style.position = 'absolute';
        label.style.left = (chart.plotLeft + chart.plotWidth + 8) + 'px';
        label.style.top = (y - 8) + 'px';
        label.style.color = '#d7deef';
        label.style.font = '11px Arial, sans-serif';
        label.style.whiteSpace = 'nowrap';
        label.style.textShadow = '0 1px 2px #141824';
        scale.appendChild(label);
      });
    }

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
  var initialPeriod = 60;
  try {
    var storedP = parseInt(sessionStorage.getItem('55ak_last_timeframe') || localStorage.getItem('55ak_last_timeframe'), 10);
    if (!isNaN(storedP) && storedP >= 5 && storedP <= 86400) initialPeriod = storedP;
  } catch (e) {}
  var activePeriod = initialPeriod;
  var allowedTradeDurations = parseDurationList(meta('trade-allowed-durations', '5,10,15,30,60,120,300'));
  var selectedTradeDuration = nearestDuration(number(meta('trade-default-duration', '60'), 60));
  var chartHistoryCount = Math.max(400, Math.min(3000, Math.floor(number(meta('trade-chart-history-count', '800'), 800))));
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
  var naturalLivePrice = 0;
  var tickPriceOffset = 0;
  var tickMomentum = 0;
  var tickNumber = 1;

  var chartLoaderActive = false;
  var chartLoaderTimer = null;
  function showChartLoader(force) {
    try {
      var cached = snapshotCache[activeSymbol + '_' + activePeriod];
      var hasCandles = (cached && Array.isArray(cached.candles) && cached.candles.length > 0) ||
                       (marketSnapshot && marketSnapshot.asset && plainSymbol(marketSnapshot.asset.symbol) === activeSymbol && Array.isArray(marketSnapshot.candles) && marketSnapshot.candles.length > 0);
      if (hasCandles && !force) {
        hideChartLoader();
        return;
      }
      chartLoaderActive = true;
      var loader = document.getElementById('tradeChartLoader');
      if (!loader) {
        loader = document.createElement('div');
        loader.id = 'tradeChartLoader';
        loader.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;z-index:99999;background:rgba(15,18,25,0.78);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);display:flex;flex-direction:column;align-items:center;justify-content:center;color:#e1e7f5;font-family:sans-serif;font-size:13px;pointer-events:none;transition:opacity 0.25s ease, visibility 0.25s ease;';
        loader.innerHTML = '<div style="width:36px;height:36px;border:3px solid rgba(255,255,255,0.12);border-top-color:#00e5ff;border-radius:50%;animation:spinTradeLoader 0.7s linear infinite;margin-bottom:12px;"></div>' +
                           '<span style="letter-spacing:0.5px;color:#cbd5e1;font-weight:500;">Loading market chart...</span>' +
                           '<style>@keyframes spinTradeLoader { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>';
      }
      var parent = document.querySelector('.section-main__chart, .trading-chart, [class*="chart-container"], #graph') || document.body;
      if (parent && loader.parentElement !== parent) {
        var compStyle = window.getComputedStyle(parent);
        if (compStyle.position === 'static') {
          parent.style.position = 'relative';
        }
        parent.appendChild(loader);
      }
      loader.style.display = 'flex';
      loader.style.visibility = 'visible';
      loader.style.opacity = '1';

      if (chartLoaderTimer) clearTimeout(chartLoaderTimer);
      chartLoaderTimer = setTimeout(hideChartLoader, 3000);
    } catch (e) {}
  }

  function hideChartLoader() {
    try {
      chartLoaderActive = false;
      if (chartLoaderTimer) {
        clearTimeout(chartLoaderTimer);
        chartLoaderTimer = null;
      }
      var loader = document.getElementById('tradeChartLoader');
      if (loader) {
        loader.style.opacity = '0';
        setTimeout(function () {
          if (loader && loader.style.opacity === '0') {
            loader.style.display = 'none';
            loader.style.visibility = 'hidden';
          }
        }, 250);
      }
    } catch (e) {}
  }
  var firstState = true;
  var previousOpenIds = {};
  var settledNotifiedIds = {};
  var lastStateSignature = '';
  var lastHistoryBroadcastAt = 0;

  function findAsset(symbol, list) {
    if (!symbol) return null;
    var clean = plainSymbol(symbol).toUpperCase();
    var arr = Array.isArray(list) && list.length ? list : ((marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length) ? marketSnapshot.assets : bootstrapAssets);
    if (marketSnapshot && marketSnapshot.asset && plainSymbol(marketSnapshot.asset.symbol).toUpperCase() === clean) {
      return marketSnapshot.asset;
    }
    if (Array.isArray(arr)) {
      for (var i = 0; i < arr.length; i++) {
        if (arr[i] && arr[i].symbol && plainSymbol(arr[i].symbol).toUpperCase() === clean) {
          return arr[i];
        }
      }
    }
    return null;
  }


  function getActiveOrRecentTradeLivePrice(rawPrice, decimals) {
    if (!tradeState || !Array.isArray(tradeState.trades)) return rawPrice;
    var target = plainSymbol(activeSymbol);
    var now = Date.now();
    var soonestActiveTrade = null;
    var steeringActiveTrade = null;
    var recentSettledTrade = null;

    // Filter matching open/recent trades
    var matching = [];
    for (var i = 0; i < tradeState.trades.length; i++) {
      var t = tradeState.trades[i];
      if (t && plainSymbol(t.symbol) === target) {
        matching.push(t);
      }
    }
    // Sort by expiresAt ASC so soonest trade is first
    matching.sort(function (a, b) {
      return number(a.expiresAt, 0) - number(b.expiresAt, 0);
    });

    for (var i = 0; i < matching.length; i++) {
      var t = matching[i];
      var exMs = number(t.expiresAt, 0);
      var opMs = number(t.openedAt, exMs - 60000);
      var durMs = Math.max(1000, exMs - opMs);
      var steerWinMs = Math.min(12000, Math.max(6000, durMs * 0.25));

      if (t.status === 'open' && !settledNotifiedIds[String(t.id)]) {
        var remMs = exMs - now;
        if (remMs <= steerWinMs && remMs >= 0 && !steeringActiveTrade) {
          steeringActiveTrade = t;
        }
        if (!soonestActiveTrade) {
          soonestActiveTrade = t;
        }
      } else {
        var settledMs = t.settledAt ? number(t.settledAt, 0) : exMs;
        if (settledMs > 0 && (now - settledMs) < 6000) {
          if (!recentSettledTrade || settledMs > (recentSettledTrade.settledAt || recentSettledTrade.expiresAt || 0)) {
            recentSettledTrade = t;
          }
        }
      }
    }

    // Trade Priority:
    // 1. Trade in final 10-12s steering countdown (soonest to expire)
    // 2. Soonest active trade in queue
    var currentTrade = steeringActiveTrade || soonestActiveTrade;

    if (!currentTrade && !recentSettledTrade) {
      return rawPrice;
    }

    var minStep = Math.pow(10, -decimals);

    if (currentTrade) {
      var opMs = number(currentTrade.openedAt, now - 60000);
      var exMs = number(currentTrade.expiresAt, now + 60000);
      var durMs = Math.max(1000, exMs - opMs);
      var steerWinMs = Math.min(12000, Math.max(6000, durMs * 0.25));
      var remMs = Math.max(0, exMs - now);
      var entryPrice = number(currentTrade.entryPrice || currentTrade.entry_price, rawPrice);
      var tightMargin = Math.max(minStep * 2.0, entryPrice * 0.00015);

      if (remMs > steerWinMs) {
        // Early/Mid trade (before final 10-12s): pure natural live price without artificial oscillations
        return rawPrice;
      }

      // Final 10-12s steering countdown:
      // Capture the exact starting price when entering the steering window so transition is 100% continuous
      if (typeof currentTrade._steerStartPrice === 'undefined' || currentTrade._steerStartPrice === null) {
        currentTrade._steerStartPrice = rawPrice;
      }
      var startPrice = number(currentTrade._steerStartPrice, rawPrice);

      var steerElapsed = steerWinMs - remMs;
      var steerNorm = Math.min(1.0, Math.max(0.0, steerElapsed / steerWinMs));
      var smoothFactor = steerNorm * steerNorm * (3.0 - 2.0 * steerNorm); // 0.0 -> 1.0

      var targetClose = number(currentTrade.targetClosePrice, 0);
      if (targetClose <= 0) {
        if (targetLivePrice > 0) {
          targetClose = targetLivePrice;
        } else {
          var isUp = currentTrade.direction === 'up';
          targetClose = isUp ? (entryPrice - tightMargin) : (entryPrice + tightMargin);
        }
      }

      var sec = now / 1000.0;
      var dynamicOsc = (Math.sin(sec / 1.6) * 0.7 + Math.sin(sec / 3.2) * 0.3) * (tightMargin * 0.08 * (1.0 - smoothFactor));
      var livePrice = startPrice + ((targetClose - startPrice) * smoothFactor) + dynamicOsc;
      return Number(livePrice.toFixed(decimals));
    }

    if (recentSettledTrade) {
      var settledTime = recentSettledTrade.settledAt ? number(recentSettledTrade.settledAt, 0) : number(recentSettledTrade.expiresAt, 0);
      var elapsedPost = Math.max(0, now - settledTime);
      var settledClose = number(recentSettledTrade.closePrice || recentSettledTrade.targetClosePrice || targetLivePrice, rawPrice);

      if (elapsedPost <= 3000) {
        // Phase 1 (0 to 3.0s): Hold at the exact losing position where the trade closed with organic micro-oscillation
        var sec = now / 1000.0;
        var microOsc = Math.sin(sec * 4.0) * (minStep * 0.15);
        var livePrice = settledClose + microOsc;
        return Number(livePrice.toFixed(decimals));
      } else if (elapsedPost < 5500) {
        // Phase 2 (3.0s to 5.5s): After 3 seconds, smoothly ease from the losing position back to the real live market price
        var norm = (elapsedPost - 3000) / 2500.0;
        var smoothDecay = norm * norm * (3.0 - 2.0 * norm); // 0.0 -> 1.0 (Hermite smoothstep)
        var livePrice = settledClose + ((rawPrice - settledClose) * smoothDecay);
        return Number(livePrice.toFixed(decimals));
      }
    }

    return rawPrice;
  }

  function isAssetActive(asset) {
    if (!asset) return true;
    if (asset.is_open === false || asset.is_active === 0 || asset.active === false || asset.is_active === false) {
      return false;
    }
    return true;
  }

  function isCurrencyAsset(asset) {
    return !!asset && String(asset.category || '').trim().toLowerCase() === 'currencies';
  }

  function getBinanceStreamSymbol(sym) {
    var clean = String(sym || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    clean = clean.replace(/OTC$/, '');

    var assetObj = findAsset(clean);
    var category = assetObj && assetObj.category ? String(assetObj.category).toLowerCase() : '';
    // IMPORTANT: Currencies use the Free Forex API engine and should NEVER connect to Binance crypto streams (eurusdt, etc.)
    if (category === 'currencies' || isCurrencyAsset(assetObj)) return '';
    var isForexSym = /^(EUR|GBP|USD|AUD|NZD|CAD|CHF|JPY){2}$/.test(clean);
    if (isForexSym) return '';

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
      'NVDABUSDT': 'nvdabusdt'
    };

    if (map[clean]) return map[clean];

    var assetObj = findAsset(clean);
    var category = assetObj && assetObj.category ? String(assetObj.category).toLowerCase() : '';
    if (category === 'currencies') return '';
    if (category === 'crypto' || clean.indexOf('USDT') !== -1 || (clean.length > 3 && clean.endsWith('USD'))) {
      if (clean.endsWith('USDT')) {
        return clean.toLowerCase();
      }
      if (clean.endsWith('USD')) {
        return clean.slice(0, -3).toLowerCase() + 'usdt';
      }
    }
    return '';
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
              naturalLivePrice = rawPrice;
              lastBinanceTickMs = Date.now();
              if (chartLoaderActive) {
                return;
              }
              var currAssetObj = findAsset(activeSymbol);
              if (!isAssetActive(currAssetObj)) {
                return;
              }
              var decimals = currAssetObj ? number(currAssetObj.decimals || currAssetObj.decimal_places, 2) : 2;
              currentLivePrice = getActiveOrRecentTradeLivePrice(rawPrice, decimals);

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

  var lastBinanceTickMs = 0;

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

  var lastEmittedTickPrices = {};
  function emitTick(price, targetSocketSym) {
    var targetSocket = targetSocketSym || activeSocketSymbol;
    if (!price || !targetSocket) return;
    var sym = plainSymbol(targetSocket);
    var expectedAsset = findAsset(sym);
    if (!isAssetActive(expectedAsset)) {
      // Market is closed: do not emit fake moving ticks
      return;
    }
    var decimals = expectedAsset ? number(expectedAsset.decimals || expectedAsset.decimal_places, 2) : 2;
    var minStep = Math.pow(10, -decimals);
    var currentSec = (Date.now() + serverTimeOffset) / 1000;

    // Safety: prevent quote timestamp from lagging or drifting into future
    if (lastEmittedQuoteSec > 0 && Math.abs(lastEmittedQuoteSec - currentSec) > 2.0) {
      lastEmittedQuoteSec = currentSec;
    }

    if (currentSec <= lastEmittedQuoteSec) {
      currentSec = lastEmittedQuoteSec + 0.05;
    }
    lastEmittedQuoteSec = Number(currentSec.toFixed(3));
    var tickPrice = Number(price.toFixed(decimals));

    // Reject cross-contamination from a different symbol tab (e.g. Apple 213 vs EURUSD 1.13)
    var expectedRefPrice = number(expectedAsset.price || expectedAsset.base_price, 0);
    if (expectedRefPrice > 0 && Math.abs(tickPrice - expectedRefPrice) / expectedRefPrice > 0.45) {
      return;
    }

    // Smooth Rate-of-Change Limiter: prevent abnormal single-tick spikes or drops (max 0.1% or 10 pips per tick)
    var prevTick = lastEmittedTickPrices[targetSocket];
    if (prevTick > 0) {
      var maxTickJump = Math.max(minStep * 10, prevTick * 0.001);
      if (Math.abs(tickPrice - prevTick) > maxTickJump) {
        tickPrice = Number((prevTick + Math.sign(tickPrice - prevTick) * maxTickJump).toFixed(decimals));
      }
    }
    lastEmittedTickPrices[targetSocket] = tickPrice;

    var quote = [[targetSocket, lastEmittedQuoteSec, tickPrice, ++tickNumber]];
    broadcastBinary('quotes/stream', quote);
  }

  var targetLivePrice = 0;
  var restDriftOffset = 0;
  var lastRestDriftTime = 0;

  function emitSmoothQuote() {
    checkTradeExpirations();
    if (!currentLivePrice || !activeSocketSymbol) return;
    if (chartLoaderActive) return;
    var expectedAsset = findAsset(activeSymbol);
    if (!isAssetActive(expectedAsset)) {
      // Market is closed: freeze quote completely at close price
      return;
    }

    var hasActiveOrRecentTrade = false;
    if (tradeState && Array.isArray(tradeState.trades)) {
      var target = plainSymbol(activeSymbol);
      var now = Date.now();
      for (var i = 0; i < tradeState.trades.length; i++) {
        var tr = tradeState.trades[i];
        if (!tr || plainSymbol(tr.symbol) !== target) continue;
        if (tr.status === 'open') {
          hasActiveOrRecentTrade = true;
          break;
        }
        var sMs = tr.settledAt ? number(tr.settledAt, 0) : (tr.expiresAt ? number(tr.expiresAt, 0) : 0);
        if (sMs > 0 && (now - sMs) < 6000) {
          hasActiveOrRecentTrade = true;
          break;
        }
      }
    }

    // If real WebSocket ticks are actively streaming from Binance (for crypto/commodities/stocks) and no active trade is being steered, avoid synthetic quotes
    if (!hasActiveOrRecentTrade && binanceWs && binanceWs.readyState === 1 && (Date.now() - lastBinanceTickMs) < 3000) {
      restDriftOffset = 0;
      return;
    }

    var dec = expectedAsset ? number(expectedAsset.decimals || expectedAsset.decimal_places, 2) : 2;
    var minStep = Math.pow(10, -dec);
    var nowMs = Date.now();

    // Natural sub-pip micro-walk with mean reversion so REST-backed currencies
    // form organic candles without drawing flat straight horizontal lines or jumping wildly.
    if (nowMs - lastRestDriftTime >= 150) {
      lastRestDriftTime = nowMs;
      var maxDrift = dec >= 4 ? (minStep * 1.5) : (minStep * 0.8);
      var step = (Math.random() - 0.49) * (minStep * 0.35);
      var pull = -restDriftOffset * 0.12;
      restDriftOffset = Math.max(-maxDrift, Math.min(maxDrift, restDriftOffset + step + pull));
    }

    // Smooth step towards targetLivePrice when no active trade is governing
    if (!hasActiveOrRecentTrade && targetLivePrice > 0 && Math.abs(currentLivePrice - targetLivePrice) > (minStep * 0.05)) {
      var priceDiff = targetLivePrice - currentLivePrice;
      var maxStep = minStep * 1.5;
      var stepAmount = Math.sign(priceDiff) * Math.min(Math.abs(priceDiff) * 0.25, maxStep);
      currentLivePrice = currentLivePrice + stepAmount;
    }

    var baseRaw = naturalLivePrice > 0 ? naturalLivePrice : currentLivePrice;
    var rawPrice = Number((baseRaw + restDriftOffset).toFixed(dec));

    // ALWAYS apply active/recent trade calculation to the emitted price
    var livePriceToEmit = getActiveOrRecentTradeLivePrice(rawPrice, dec);
    currentLivePrice = livePriceToEmit;
    emitTick(livePriceToEmit, activeSocketSymbol);
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
  var expiryWatchTimer = null;
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
      naturalLivePrice = currentLivePrice;
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
    if (number(embAsset.natural_price, 0) > 0) {
      naturalLivePrice = number(embAsset.natural_price, 0);
    }
  }
  if (!currentLivePrice || currentLivePrice <= 0) {
    currentLivePrice = 1.16130;
  }
  if (!naturalLivePrice || naturalLivePrice <= 0) {
    naturalLivePrice = currentLivePrice;
  }

  function normalizeModalCategory(category) {
    var key = String(category || 'Currencies').trim();
    var lowered = key.toLowerCase();
    if (lowered === 'forex') return 'currencies';
    if (lowered === 'currency' || lowered === 'currencies') return 'currencies';
    if (lowered === 'crypto' || lowered === 'cryptocurrency') return 'crypto';
    if (lowered === 'commodity' || lowered === 'commodities' || lowered === 'metal' || lowered === 'metals' || lowered === 'gold') return 'commodities';
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
      return response.text().then(function (rawText) {
        var cleanText = String(rawText || '').replace(/^\uFEFF/, '').trim();
        var firstBrace = cleanText.indexOf('{');
        var lastBrace = cleanText.lastIndexOf('}');
        if (firstBrace !== -1 && lastBrace !== -1 && lastBrace >= firstBrace) {
          cleanText = cleanText.slice(firstBrace, lastBrace + 1);
        }
        var payload = null;
        try {
          payload = JSON.parse(cleanText);
        } catch (e) {
          console.warn('[TradeBridge] Non-JSON response from ' + url + ' (HTTP ' + response.status + '):', rawText);
          if (response.status === 401) {
            scheduleSignInOnce();
            throw new Error('Sign in is required.');
          }
          if (response.status === 419) {
            throw new Error('Security token expired. Please refresh the page.');
          }
          throw new Error('The server returned an invalid response (HTTP ' + response.status + ').');
        }
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
    if (!sym) return null;
    var target = plainSymbol(sym).toUpperCase();
    var arr = Array.isArray(list) && list.length ? list : ((marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length) ? marketSnapshot.assets : bootstrapAssets);
    if (Array.isArray(arr)) {
      for (var i = 0; i < arr.length; i++) {
        if (arr[i] && arr[i].symbol && plainSymbol(arr[i].symbol).toUpperCase() === target) {
          return arr[i];
        }
      }
    }
    return null;
  }

  function extractPeriod(payload, fallback) {
    if (payload === undefined || payload === null) {
      return Math.max(1, Math.min(86400, number(fallback, 60)));
    }
    var settings = (typeof payload === 'object' && payload && payload.settings) ? payload.settings : {};
    
    // 1. First check explicit Quotex enum fields (chartPeriod, chartPeriodRaw)
    var enumVal = undefined;
    if (payload.chartPeriod !== undefined && payload.chartPeriod !== null) enumVal = payload.chartPeriod;
    else if (payload.chartPeriodRaw !== undefined && payload.chartPeriodRaw !== null) enumVal = payload.chartPeriodRaw;
    else if (settings.chartPeriod !== undefined && settings.chartPeriod !== null) enumVal = settings.chartPeriod;
    else if (settings.chartPeriodRaw !== undefined && settings.chartPeriodRaw !== null) enumVal = settings.chartPeriodRaw;

    if (enumVal !== undefined && enumVal !== null && enumVal !== '') {
      if (typeof enumVal === 'string') {
        var sEnum = enumVal.trim();
        var enumMatch = sEnum.match(/^Period_(\d+)$/i);
        if (enumMatch) {
          var numEnum = parseInt(enumMatch[1], 10);
          if (!isNaN(numEnum) && numEnum > 0) return Math.max(1, Math.min(86400, numEnum));
        }
        var parsedEnum = parseInt(sEnum, 10);
        if (!isNaN(parsedEnum)) enumVal = parsedEnum;
      }
      if (typeof enumVal === 'number' && isFinite(enumVal)) {
        var enumToSec = {
          0: 5,      // Period_5
          1: 10,     // Period_10
          2: 15,     // Period_15
          3: 30,     // Period_30
          4: 60,     // Period_60 (1m)
          6: 300,    // Period_300 (5m)
          7: 600,    // Period_600 (10m)
          8: 900,    // Period_900 (15m)
          9: 1800,   // Period_1800 (30m)
          10: 3600,  // Period_3600 (1h)
          11: 3600,  // Period_14400 (4h - fallback to 1h)
          12: 3600,  // Period_86400 (1d - fallback to 1h)
          13: 120,   // Period_120 (2m)
          14: 180    // Period_180 (3m)
        };
        if (enumVal in enumToSec) {
          return enumToSec[enumVal];
        }
      }
    }

    // 2. Check explicit timeframe/interval/period in seconds
    var secVal = undefined;
    if (payload.timeframe !== undefined && payload.timeframe !== null) secVal = payload.timeframe;
    else if (payload.period !== undefined && payload.period !== null) secVal = payload.period;
    else if (payload.interval !== undefined && payload.interval !== null) secVal = payload.interval;
    else if (settings.timeframe !== undefined && settings.timeframe !== null) secVal = settings.timeframe;
    else if (settings.period !== undefined && settings.period !== null) secVal = settings.period;
    else if (settings.interval !== undefined && settings.interval !== null) secVal = settings.interval;
    else if (typeof payload === 'number' || typeof payload === 'string') secVal = payload;

    if (secVal !== undefined && secVal !== null && secVal !== '') {
      if (typeof secVal === 'string') {
        var s = secVal.trim();
        var matchEnum = s.match(/^Period_(\d+)$/i);
        if (matchEnum) {
          var n = parseInt(matchEnum[1], 10);
          if (!isNaN(n) && n > 0) return Math.max(1, Math.min(86400, n));
        }
        if (/^\d+[smhd]$/i.test(s)) {
          var unit = s.slice(-1).toLowerCase();
          var numVal = parseInt(s.slice(0, -1), 10);
          if (unit === 's') return Math.max(1, Math.min(86400, numVal));
          if (unit === 'm') return Math.max(1, Math.min(86400, numVal * 60));
          if (unit === 'h') return Math.max(1, Math.min(86400, numVal * 3600));
          if (unit === 'd') return Math.max(1, Math.min(86400, numVal * 86400));
        }
        var parsed = parseInt(s, 10);
        if (!isNaN(parsed)) secVal = parsed;
      }
      if (typeof secVal === 'number' && isFinite(secVal) && secVal > 0) {
        return Math.max(1, Math.min(86400, secVal));
      }
    }

    return Math.max(1, Math.min(86400, number(fallback, 60)));
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
    var dec = number(selected.decimals || selected.decimal_places, 5);
    var assets = bootstrapAssets.length ? bootstrapAssets.slice() : [selected];
    return {serverTime: nowMs, asset: selected, assets: assets, candles: [], engine: 'server-bootstrap-unavailable'};
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
    if (key.indexOf('commod') !== -1 || key.indexOf('metal') !== -1 || key.indexOf('gold') !== -1) return 'commodity';
    if (key.indexOf('stock') !== -1) return 'stock';
    if (key.indexOf('indic') !== -1) return 'indices';
    return 'currency';
  }

  function instrumentPayload(asset, index) {
    var active = isAssetActive(asset);
    var payout = active ? number(asset.payout, 0) : 0;
    var timeframes = [5, 10, 15, 30, 60, 120, 180, 300, 600, 900, 1800, 3600].map(function (time) {
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

    var dayChange = number(asset.changePercent !== undefined ? asset.changePercent : (asset.day_change !== undefined ? asset.day_change : 0.12), 0.12);

    return [
      index + 1,
      socketSymbol(asset.symbol),
      display,
      categoryName(asset.category),
      number(asset.decimals, 5),
      payout,
      1,
      0,
      60,
      isOtc ? 1 : 0,
      0,
      0,
      true,
      null,
      active ? true : false,
      timeframes,
      null,
      dayChange,
      payout,
      payout,
      false,
      0.05, 0.12, 0.25, 1.2, 0.8, 0.12
    ];
  }

  function instrumentsList() {
    var assets = (marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length)
      ? marketSnapshot.assets
      : bootstrapAssets;
    return assets.map(instrumentPayload);
  }

  function secToChartPeriodEnum(sec) {
    var s = number(sec, 60);
    if (s <= 5) return 0;
    if (s <= 10) return 1;
    if (s <= 15) return 2;
    if (s <= 30) return 3;
    if (s <= 60) return 4;
    if (s <= 120) return 13;
    if (s <= 180) return 14;
    if (s <= 300) return 6;
    if (s <= 600) return 7;
    if (s <= 900) return 8;
    if (s <= 1800) return 9;
    return 10;
  }

  function defaultZoomObject() {
    return {
      0: { count: 0, value: 0, secondWidth: 3.5 },
      1: { count: 0, value: 0, secondWidth: 3.5 },
      2: { count: 0, value: 0, secondWidth: 3.5 },
      3: { count: 0, value: 0, secondWidth: 3.5 }
    };
  }

  function graphSettings() {
    var asset = marketSnapshot && marketSnapshot.asset ? marketSnapshot.asset : {symbol: activeSymbol, price: 0};
    var startingAmount = Math.max(user.minAmount, Math.min(user.maxAmount, 10));
    var periodEnum = secToChartPeriodEnum(activePeriod);
    return [{
      chart_id: 'graph',
      settings: JSON.stringify({
        isVisible: true,
        isFastOption: true,
        isPendingTradeOption: false,
        isFastAmountOption: false,
        isIndicatorsMinimized: false,
        isIndicatorsShowing: true,
        chartPeriod: periodEnum,
        chartPeriodRaw: periodEnum,
        timeframe: activePeriod,
        period: activePeriod,
        currentExpirationTime: 0,
        currentAsset: {symbol: socketSymbol(asset.symbol), price: number(asset.price, 0), obj: {}},
        isAutoScrolling: 1,
        isOneClickTrade: false,
        dealValue: startingAmount,
        dealPercentValue: 1,
        dealPercentValueStr: '1%',
        gridOpacity: 8,
        zoom: defaultZoomObject()
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
    list = list.filter(function (trade) { return trade.status === 'open' && !settledNotifiedIds[String(trade.id)]; });
    return list.map(function (trade) { return mapTrade(trade); });
  }

  function closedDeals() {
    var list = tradeState && Array.isArray(tradeState.closedTrades) ? tradeState.closedTrades : [];
    if (!list.length && tradeState && Array.isArray(tradeState.trades)) {
      list = tradeState.trades.filter(function (trade) { return trade.status !== 'open' || settledNotifiedIds[String(trade.id)]; });
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
    var safePeriod = Math.max(1, Math.min(86400, number(period, 60)));
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
    var previousPrice = NaN;

    candles.forEach(function (candle) {
      var denseTicks = Array.isArray(candle.ticks) ? candle.ticks : [];

      if (denseTicks.length) {
        denseTicks.forEach(function (tick) {
          if (!Array.isArray(tick) || tick.length < 2) return;
          var rawStamp = number(tick[0], 0) / 1000;
          var stamp = isClosed ? Math.min(maxHistorySec, rawStamp) : rawStamp;
          var price = number(tick[1], NaN);
          if (!isFinite(stamp) || !isFinite(price)) return;
          if (stamp <= previousStamp) stamp = previousStamp + 0.001;
          previousStamp = Number(stamp.toFixed(3));
          previousPrice = price;
          history.push([previousStamp, price, ++count]);
        });
        return;
      }

      var start = number(candle.time, Date.now()) / 1000;
      var candleEndSec = number(candle.lastTickTime, number(candle.time, Date.now()) + (safePeriod * 1000) - 1) / 1000;
      var availableEnd = isClosed ? Math.min(candleEndSec, maxHistorySec) : candleEndSec;
      var availableDuration = Math.max(0.003, availableEnd - start);
      var offsets = [0, availableDuration * 0.33, availableDuration * 0.67, availableDuration];
      var rising = number(candle.close, 0) >= number(candle.open, 0);
      var points = rising
        ? [candle.open, candle.low, candle.high, candle.close]
        : [candle.open, candle.high, candle.low, candle.close];
      points.forEach(function (price, index) {
        var rawPointStamp = start + offsets[index];
        var stamp = isClosed ? Math.min(maxHistorySec, rawPointStamp) : rawPointStamp;
        if (stamp <= previousStamp) stamp = previousStamp + 0.001;
        previousStamp = Number(stamp.toFixed(3));
        previousPrice = number(price, 0);
        history.push([previousStamp, previousPrice, ++count]);
      });
    });

    // Anchor currentLivePrice strictly to the last history point to prevent any vertical cliff or spike
    if (history.length > 0) {
      var lastHistPrice = history[history.length - 1][1];
      if (lastHistPrice > 0) {
        currentLivePrice = lastHistPrice;
        targetLivePrice = lastHistPrice;
      }
    }

    if (isClosed && history.length > 0 && currentAssetObj) {
      var officialClosedP = number((currentAssetObj.closed_price !== undefined && currentAssetObj.closed_price !== null) ? currentAssetObj.closed_price : currentAssetObj.price, 0);
      if (officialClosedP > 0) {
        history[history.length - 1][1] = officialClosedP;
      }
    }

    if (previousStamp > 0 && isFinite(previousStamp)) {
      lastEmittedQuoteSec = previousStamp;
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

  var currentMarketRequestId = 0;

  function refreshMarket(sendHistory, socket, customCount) {
    if (marketRequestRunning) {
      if (!sendHistory) return Promise.resolve(marketSnapshot);
      return new Promise(function (resolve) {
        window.setTimeout(function () { resolve(refreshMarket(true, socket, customCount)); }, 80);
      });
    }
    var symbolAtStart = activeSymbol;
    var periodAtStart = Math.max(1, Math.min(86400, number(activePeriod, 60)));
    var cached = snapshotCache[symbolAtStart + '_' + periodAtStart];
    var hasLoadedCandles = (cached && Array.isArray(cached.candles) && cached.candles.length > 0) ||
                           (marketSnapshot && marketSnapshot.asset && plainSymbol(marketSnapshot.asset.symbol) === symbolAtStart && number(marketSnapshot.period, 0) === periodAtStart && Array.isArray(marketSnapshot.candles) && marketSnapshot.candles.length > 0);
    if (sendHistory && !hasLoadedCandles) {
      showChartLoader(true);
    }
    marketRequestRunning = true;
    var thisRequestId = ++currentMarketRequestId;
    var targetCount = 200;
    if (periodAtStart <= 30) targetCount = 600;       // 1s - 30s (Smooth micro-scalp)
    else if (periodAtStart <= 60) targetCount = 800;  // 1m -> Exact 800 candles (13.3 hrs)
    else if (periodAtStart <= 120) targetCount = 600; // 2m -> 600 candles (20 hrs)
    else if (periodAtStart <= 180) targetCount = 500; // 3m -> 500 candles (25 hrs)
    else if (periodAtStart <= 300) targetCount = 400; // 5m -> 400 candles (~1.4 days)
    else if (periodAtStart <= 600) targetCount = 350; // 10m -> 350 candles (~2.4 days)
    else if (periodAtStart <= 900) targetCount = 300; // 15m -> 300 candles (~3.1 days)
    else if (periodAtStart <= 1800) targetCount = 250;// 30m -> 250 candles (~5.2 days)
    else if (periodAtStart <= 3600) targetCount = 200;// 1h -> 200 candles (~8.3 days)
    else targetCount = 150;
    var countToFetch = customCount !== undefined && customCount !== null ? number(customCount, targetCount) : targetCount;
    var endpoint = '/en/api/market.php?symbol=' + encodeURIComponent(symbolAtStart) +
      '&interval=' + encodeURIComponent(String(periodAtStart)) +
      '&count=' + encodeURIComponent(String(countToFetch)) +
      '&history=' + (sendHistory ? '1' : '0');

    return jsonRequest(endpoint).then(function (snapshot) {
      if (thisRequestId !== currentMarketRequestId && (symbolAtStart !== activeSymbol || periodAtStart !== activePeriod)) {
        return snapshot;
      }
      var apiCandles = snapshot && Array.isArray(snapshot.candles) ? snapshot.candles : [];
      if (snapshot) {
        snapshot.period = periodAtStart;
      }
      if (snapshot && snapshot.asset && apiCandles.length > 0) {
        var cacheKey = plainSymbol(snapshot.asset.symbol) + '_' + periodAtStart;
        snapshotCache[cacheKey] = snapshot;
      }
      
      // If background quote poll (sendHistory=false) and candles not included, preserve existing candles only if period matches
      if (!sendHistory && marketSnapshot && number(marketSnapshot.period, 0) === periodAtStart && Array.isArray(marketSnapshot.candles) && marketSnapshot.candles.length > 0 && apiCandles.length === 0) {
        snapshot.candles = marketSnapshot.candles;
      }
      
      if (symbolAtStart !== activeSymbol || periodAtStart !== activePeriod) return snapshot;
      marketSnapshot = snapshot;
      
      var asset = snapshot.asset || {};
      var serverTime = number(snapshot.serverTime, Date.now());
      syncServerTime(serverTime);
      var price = number(asset.price, 0);
      snapshot.candles = apiCandles.length > 0 ? apiCandles : (snapshot.candles || []);
      if (snapshot.candles.length > 0) {
        var lastC = number(snapshot.candles[snapshot.candles.length - 1].close, 0);
        if (lastC > 0) {
          currentLivePrice = lastC;
          targetLivePrice = lastC;
        }
      } else if (price > 0) {
        currentLivePrice = price;
        targetLivePrice = price;
      }
      var naturalPrice = number(asset.natural_price, 0);
      if (naturalPrice > 0) {
        naturalLivePrice = naturalPrice;
      } else if (!naturalLivePrice && price > 0) {
        naturalLivePrice = price;
      }

      if (sendHistory) {
        var hPayload = historyPayload(snapshot, activeSocketSymbol, periodAtStart);
        broadcastBinary('settings/list', graphSettings());
        broadcastBinary('history/list/v2', hPayload);
        lastHistoryBroadcastAt = Date.now();
        hideChartLoader();
        emitSmoothQuote();
      }
      return snapshot;
    }).catch(function (error) {
      showNotice(error.message, true);
      return marketSnapshot;
    }).then(function (snapshot) {
      marketRequestRunning = false;
      if (sendHistory) {
        hideChartLoader();
      }
      return snapshot;
    }, function (error) {
      marketRequestRunning = false;
      if (sendHistory) {
        hideChartLoader();
      }
      throw error;
    });
  }

  function checkTradeExpirations() {
    if (!tradeState || !Array.isArray(tradeState.trades)) return;
    var now = Date.now();
    var hasSettledAny = false;

    for (var i = 0; i < tradeState.trades.length; i++) {
      var trade = tradeState.trades[i];
      if (!trade) continue;
      var tradeId = String(trade.id);
      var exMs = number(trade.expiresAt, 0);

      if (trade.status === 'open' && exMs > 0 && now >= exMs && !settledNotifiedIds[tradeId]) {
        settledNotifiedIds[tradeId] = true;
        delete previousOpenIds[tradeId];

        var entryPrice = number(trade.entryPrice || trade.entry_price, 0);
        var targetClose = number(trade.targetClosePrice, 0);
        if (targetClose <= 0) {
          targetClose = currentLivePrice > 0 ? currentLivePrice : entryPrice;
        }
        var direction = String(trade.direction || '').toLowerCase();
        var isWin = false;
        var isDraw = false;
        if (direction === 'up' || direction === 'call') {
          isWin = targetClose > entryPrice;
          isDraw = targetClose === entryPrice;
        } else if (direction === 'down' || direction === 'put') {
          isWin = targetClose < entryPrice;
          isDraw = targetClose === entryPrice;
        }

        var amount = number(trade.amount, 0);
        var payout = number(trade.payout || 85, 85);
        var winProfit = amount * (payout / 100);

        trade.status = isWin ? 'won' : (isDraw ? 'draw' : 'lost');
        trade.closePrice = targetClose;
        trade.profitLoss = isWin ? winProfit : (isDraw ? 0 : -amount);
        trade.profit = isWin ? (amount + winProfit) : (isDraw ? amount : 0);
        trade.settledAt = exMs;

        var isDemo = String(trade.accountType || user.accountType) === 'demo';
        if (isWin) {
          user.balance += (amount + winProfit);
          if (isDemo) user.demoBalance += (amount + winProfit); else user.liveBalance += (amount + winProfit);
        } else if (isDraw) {
          user.balance += amount;
          if (isDemo) user.demoBalance += amount; else user.liveBalance += amount;
        }
        window.settings.liveBalance = user.liveBalance.toFixed(8);
        window.settings.demoBalance = user.demoBalance.toFixed(8);

        if (Array.isArray(tradeState.openTrades)) {
          tradeState.openTrades = tradeState.openTrades.filter(function (t) { return String(t.id) !== tradeId; });
        }
        if (Array.isArray(tradeState.closedTrades)) {
          var alreadyInClosed = tradeState.closedTrades.some(function (t) { return String(t.id) === tradeId; });
          if (!alreadyInClosed) {
            tradeState.closedTrades.unshift(trade);
          }
        }

        var deal = mapTrade(trade);
        broadcastBinary('s_orders/close', {deals: [deal]});

        var sym = trade.name || trade.symbol || (deal.asset ? deal.asset.replace('#', '') : 'Trade');
        var curr = currencySymbol(user.currency);
        if (isWin) {
          showNotice('Trade Won! +' + curr + winProfit.toFixed(2) + ' profit on ' + sym, 'success', 5000);
        } else if (isDraw) {
          showNotice('Trade Draw. Stake refunded (' + curr + amount.toFixed(2) + ') on ' + sym, 'info', 4000);
        } else {
          showNotice('Trade Lost. -' + curr + amount.toFixed(2) + ' on ' + sym, 'error', 4500);
        }

        hasSettledAny = true;
      }
    }

    if (hasSettledAny) {
      broadcastBinary('orders/opened/list', openDeals());
      broadcastBinary('orders/closed/list', closedDeals());
      broadcastBinary('s_balance/list', balancePayload());
      refreshState(true);
      refreshMarket(false, null);
    }
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
        var tradeId = String(trade.id);
        if (trade.status === 'open' && settledNotifiedIds[tradeId]) {
          trade.status = trade.profitLoss > 0 ? 'won' : (trade.profitLoss === 0 ? 'draw' : 'lost');
        }
        if (trade.status === 'open') currentOpen[tradeId] = true;
      });

      if (!firstState) {
        trades.forEach(function (trade) {
          var tradeId = String(trade.id);
          if (trade.status !== 'open' && (previousOpenIds[tradeId] || !settledNotifiedIds[tradeId])) {
            settledNotifiedIds[tradeId] = true;
            delete previousOpenIds[tradeId];

            var deal = mapTrade(trade);
            broadcastBinary('s_orders/close', {deals: [deal]});

            var sym = trade.name || trade.symbol || (deal.asset ? deal.asset.replace('#', '') : 'Trade');
            var isWin = trade.status === 'won';
            var isDraw = trade.status === 'draw';
            var profitLoss = number(trade.profitLoss !== undefined ? trade.profitLoss : trade.profit, 0);
            var amount = number(trade.amount, 0);
            var curr = currencySymbol(user.currency);

            if (isWin) {
              var winAmount = profitLoss > 0 ? profitLoss : (amount * number(trade.payout || 85, 85) / 100);
              showNotice('Trade Won! +' + curr + winAmount.toFixed(2) + ' profit on ' + sym, 'success', 5000);
            } else if (isDraw) {
              showNotice('Trade Draw. Stake refunded (' + curr + amount.toFixed(2) + ') on ' + sym, 'info', 4000);
            } else {
              showNotice('Trade Lost. -' + curr + amount.toFixed(2) + ' on ' + sym, 'error', 4500);
            }
          }
        });
      } else {
        trades.forEach(function (trade) {
          if (trade.status !== 'open') {
            settledNotifiedIds[String(trade.id)] = true;
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
      var assets = (marketSnapshot && Array.isArray(marketSnapshot.assets) && marketSnapshot.assets.length)
        ? marketSnapshot.assets
        : bootstrapAssets;
      var symbolsToWarm = assets.slice(0, 4).map(function (a) { return plainSymbol(a.symbol); });
      if (!symbolsToWarm.length) {
        symbolsToWarm = ['BTCUSD', 'EURUSD', 'ETHUSD', 'SOLUSD'];
      }
      symbolsToWarm.forEach(function (sym, idx) {
        var s = plainSymbol(sym);
        if (s !== activeSymbol && !snapshotCache[s + '_' + activePeriod]) {
          window.setTimeout(function () {
            jsonRequest('/en/api/market.php?symbol=' + encodeURIComponent(s) + '&interval=' + encodeURIComponent(String(activePeriod)) + '&count=' + encodeURIComponent(String(chartHistoryCount)) + '&history=1').then(function (snap) {
              if (snap && snap.asset && Array.isArray(snap.candles) && snap.candles.length) {
                snapshotCache[plainSymbol(snap.asset.symbol) + '_' + activePeriod] = snap;
              }
            }).catch(function () {});
          }, (idx + 1) * 800);
        }
      });
    }, 1500);
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
    if (!expiryWatchTimer) {
      expiryWatchTimer = window.setInterval(function () {
        checkTradeExpirations();
      }, 100);
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
      requestId: clientRequestId,
      entryPrice: currentLivePrice,
      price: currentLivePrice,
      _csrf: user.csrf
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
      previousOpenIds[String(trade.id)] = true;
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
      refreshMarket(false, null);

      var exMs = number(trade.expiresAt, Date.now() + 60000);
      var delayMs = Math.max(10, exMs - Date.now());
      window.setTimeout(function () {
        checkTradeExpirations();
        refreshState(true);
        refreshMarket(false, null);
      }, delayMs);

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
      if (assetPrice > 0) {
        currentLivePrice = assetPrice;
        targetLivePrice = assetPrice;
      }
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
      if (!marketSnapshot || !Array.isArray(marketSnapshot.candles) || !marketSnapshot.candles.length) {
        window.setTimeout(function () { refreshMarket(true, socket); }, 1200);
      }
    }).catch(function (error) {
      showNotice('Connection is slow. The trading screen is ready and live data will retry automatically.', 'warning', 5000);
      window.setTimeout(function () {
        refreshMarket(true, socket);
        refreshState(true);
      }, 1500);
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
      var hasChartPeriod = Boolean(payload && (payload.chartPeriod !== undefined || payload.timeframe !== undefined || payload.period !== undefined || payload.interval !== undefined ||
                                  (payload.settings && (payload.settings.chartPeriod !== undefined || payload.settings.timeframe !== undefined || payload.settings.period !== undefined || payload.settings.interval !== undefined))));
      var reqPerStore = hasChartPeriod ? extractPeriod(payload, activePeriod) : activePeriod;
      var storeChanged = reqSymStore !== activeSymbol || (hasChartPeriod && reqPerStore !== activePeriod);
      activeSymbol = reqSymStore;
      activeSocketSymbol = socketSymbol(activeSymbol);
      if (hasChartPeriod) {
        activePeriod = reqPerStore;
        try { sessionStorage.setItem('55ak_last_timeframe', String(activePeriod)); localStorage.setItem('55ak_last_timeframe', String(activePeriod)); } catch (e) {}
      }

      if (storeChanged) {
        lastEmittedTickPrices = {};
        restDriftOffset = 0;
        lastEmittedQuoteSec = 0;
        showChartLoader(true);
        marketSnapshot = null;
        snapshotCache = {};
        currentLivePrice = 0;
        naturalLivePrice = 0;
        targetLivePrice = 0;
        connectBinanceWs(getBinanceStreamSymbol(activeSymbol));
      }

      if (!marketSnapshot || storeChanged) {
        refreshMarket(true, socket);
      }
      return;
    }
    if (event === 'instruments/update') {
      var reqSym = extractSymbol(payload, activeSymbol);
      var hasInstPeriod = Boolean(payload && (payload.chartPeriod !== undefined || payload.timeframe !== undefined || payload.period !== undefined || payload.interval !== undefined ||
                                  (payload.settings && (payload.settings.chartPeriod !== undefined || payload.settings.timeframe !== undefined || payload.settings.period !== undefined || payload.settings.interval !== undefined))));
      var reqPer = hasInstPeriod ? extractPeriod(payload, activePeriod) : activePeriod;
      var instChanged = reqSym !== activeSymbol || (hasInstPeriod && reqPer !== activePeriod);
      activeSymbol = reqSym;
      activeSocketSymbol = socketSymbol(activeSymbol);
      if (hasInstPeriod) {
        activePeriod = reqPer;
        try { sessionStorage.setItem('55ak_last_timeframe', String(activePeriod)); localStorage.setItem('55ak_last_timeframe', String(activePeriod)); } catch (e) {}
      }

      if (instChanged) {
        lastEmittedTickPrices = {};
        restDriftOffset = 0;
        lastEmittedQuoteSec = 0;
        showChartLoader(true);
        marketSnapshot = null;
        snapshotCache = {};
        currentLivePrice = 0;
        naturalLivePrice = 0;
        targetLivePrice = 0;
        connectBinanceWs(getBinanceStreamSymbol(activeSymbol));
      }

      if (!marketSnapshot || instChanged) {
        refreshMarket(true, socket);
      }
      return;
    }
    if (event === 'history/list/v2' || event === 'history/list' || event === 'chart/history' || (typeof event === 'string' && event.indexOf('history/') === 0)) {
      var reqSymbol = extractSymbol(payload, activeSymbol);
      var hasHistPeriod = Boolean(payload && (payload.chartPeriod !== undefined || payload.timeframe !== undefined || payload.period !== undefined || payload.interval !== undefined ||
                                  (payload.settings && (payload.settings.chartPeriod !== undefined || payload.settings.timeframe !== undefined || payload.settings.period !== undefined || payload.settings.interval !== undefined))));
      var reqPeriod = hasHistPeriod ? extractPeriod(payload, activePeriod) : activePeriod;
      var symbolOrPeriodChanged = reqSymbol !== activeSymbol || (hasHistPeriod && reqPeriod !== activePeriod);

      if (symbolOrPeriodChanged) {
        activeSymbol = reqSymbol;
        activeSocketSymbol = socketSymbol(activeSymbol);
        if (hasHistPeriod) {
          activePeriod = reqPeriod;
          try { sessionStorage.setItem('55ak_last_timeframe', String(activePeriod)); localStorage.setItem('55ak_last_timeframe', String(activePeriod)); } catch (e) {}
        }
        lastEmittedTickPrices = {};
        restDriftOffset = 0;
        lastEmittedQuoteSec = 0;
        showChartLoader(true);
        marketSnapshot = null;
        snapshotCache = {};
        currentLivePrice = 0;
        naturalLivePrice = 0;
        targetLivePrice = 0;

        connectBinanceWs(getBinanceStreamSymbol(activeSymbol));
        refreshMarket(true, socket);
        emitClockTick();
        return;
      }

      var beforeTimeSec = payload && (payload.time || payload.end || payload.before) ? number(payload.time || payload.end || payload.before, 0) : 0;
      if (beforeTimeSec > 0 && (event === 'history/load' || event === 'history/load/v2' || event.indexOf('load') !== -1)) {
        var beforeMs = Math.round(beforeTimeSec * 1000);
        jsonRequest('/en/api/market.php?symbol=' + encodeURIComponent(activeSymbol) + '&interval=' + encodeURIComponent(String(activePeriod)) + '&count=120&history=1&endMs=' + beforeMs).then(function (olderSnapshot) {
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
      var isMatchingSymbol = currentSnapSymbol === activeSymbol;
      var isMatchingPeriod = marketSnapshot && number(marketSnapshot.period, 0) === activePeriod;
      var hasCandles = marketSnapshot && Array.isArray(marketSnapshot.candles) && marketSnapshot.candles.length > 0;

      if (hasCandles && isMatchingSymbol && isMatchingPeriod) {
        socket.binaryEvent('settings/list', graphSettings());
        socket.binaryEvent('history/list/v2', historyPayload(marketSnapshot, activeSocketSymbol, activePeriod));
        if (event !== 'history/list/v2' && typeof socket.binaryEvent === 'function') {
          socket.binaryEvent(event, historyPayload(marketSnapshot, activeSocketSymbol, activePeriod));
        }
      }

      var minRequiredCandles = activePeriod <= 30 ? 60 : (activePeriod <= 180 ? 35 : 20);
      var hasFullCandles = hasCandles && marketSnapshot.candles.length >= minRequiredCandles;
      if (!marketSnapshot || !isMatchingSymbol || !isMatchingPeriod || !hasFullCandles) {
        refreshMarket(!hasCandles || !isMatchingSymbol || !isMatchingPeriod, socket);
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
    function closeOrSellTrade(targetSocket, reqEvent, reqPayload) {
      var tradeId = String((reqPayload && (reqPayload.id || reqPayload.orderId || reqPayload.dealId || reqPayload.tradeId || reqPayload.public_id)) || '').trim();
      if (!tradeId) {
        showNotice('Trade ID is missing.', true);
        return;
      }

      jsonRequest('/en/api/trade.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'sell', tradeId: tradeId })
      }).then(function () {
        showNotice('Trade sold successfully!', 'success');
        refreshState(true).then(function () {
          emitTradeLists(targetSocket);
          if (targetSocket && typeof targetSocket.binaryEvent === 'function') {
            targetSocket.binaryEvent('s_balance/list', balancePayload());
          }
        });
      }).catch(function (err) {
        showNotice(err && err.message ? err.message : 'Could not sell trade.', true);
      });
    }

    if (event === 'orders/cancel' || event === 'orders/close' || event === 'orders/sell' || event === 'deals/cancel' || event === 'deals/close' || event === 'deals/sell' || (typeof event === 'string' && (event.indexOf('cancel') !== -1 || event.indexOf('sell') !== -1))) {
      closeOrSellTrade(socket, event, payload);
      return;
    }
    if (event.indexOf('double') !== -1) {
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
    var logoutElem = target.closest('[data-local-logout], a[href*="logout"], a[href="#logout"], button[class*="logout"]');
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

  function refreshDom() {
    domRefreshQueued = false;
    document.title = platformName + ' — Trading platform';
    var root = document.getElementById('root');
    rebrandText(root);
    ensureAffiliateShortcut();
    var injectedLogoutBtns = document.querySelectorAll('.original-header-logout-btn, .original-mobile-logout-btn');
    injectedLogoutBtns.forEach(function (btn) {
      btn.remove();
    });

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

    // Remove 4h and 1d timeframe options from UI
    document.querySelectorAll('button, li, [role="button"], [role="option"], [class*="period"], [class*="timeframe"], [class*="item"], [class*="select"]').forEach(function (el) {
      var txt = normalizedText(el).toLowerCase().trim();
      if (txt === '4h' || txt === '1d' || txt === '4 h' || txt === '1 d' || txt === '4 hours' || txt === '1 day' || txt === '4hour' || txt === '1day' || el.getAttribute('data-value') === '11' || el.getAttribute('data-value') === '12' || el.getAttribute('data-period') === '14400' || el.getAttribute('data-period') === '86400') {
        el.style.setProperty('display', 'none', 'important');
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

  function handleMobileResume() {
    var nowSec = (Date.now() + serverTimeOffset) / 1000;
    if (lastEmittedQuoteSec > 0 && Math.abs(nowSec - lastEmittedQuoteSec) > 2.0) {
      lastEmittedQuoteSec = Number(nowSec.toFixed(3));
    }
    var stream = getBinanceStreamSymbol(activeSymbol);
    if (stream && (!binanceWs || binanceWs.readyState > 1)) {
      connectBinanceWs(stream);
    }
    refreshMarket(true, null);
    refreshState(false);
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
        handleMobileResume();
      }
    });
  }
  if (typeof window !== 'undefined') {
    window.addEventListener('focus', handleMobileResume);
    window.addEventListener('pageshow', handleMobileResume);
  }
}());

