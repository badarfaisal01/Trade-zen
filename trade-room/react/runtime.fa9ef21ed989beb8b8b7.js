(() => {
    "use strict";
    var e, t, r, a, n = {},
        d = {};

    function o(e) {
        var t = d[e];
        if (void 0 !== t) return t.exports;
        var r = d[e] = {
            id: e,
            loaded: !1,
            exports: {}
        };
        return n[e].call(r.exports, r, r.exports, o), r.loaded = !0, r.exports
    }
    o.m = n, e = [], o.O = (t, r, a, n) => {
        if (!r) {
            var d = 1 / 0;
            for (s = 0; s < e.length; s++) {
                for (var [r, a, n] = e[s], f = !0, c = 0; c < r.length; c++)(!1 & n || d >= n) && Object.keys(o.O).every((e => o.O[e](r[c]))) ? r.splice(c--, 1) : (f = !1, n < d && (d = n));
                if (f) {
                    e.splice(s--, 1);
                    var i = a();
                    void 0 !== i && (t = i)
                }
            }
            return t
        }
        n = n || 0;
        for (var s = e.length; s > 0 && e[s - 1][2] > n; s--) e[s] = e[s - 1];
        e[s] = [r, a, n]
    }, o.F = {}, o.E = e => {
        Object.keys(o.F).map((t => {
            o.F[t](e)
        }))
    }, o.n = e => {
        var t = e && e.__esModule ? () => e.default : () => e;
        return o.d(t, {
            a: t
        }), t
    }, r = Object.getPrototypeOf ? e => Object.getPrototypeOf(e) : e => e.__proto__, o.t = function(e, a) {
        if (1 & a && (e = this(e)), 8 & a) return e;
        if ("object" == typeof e && e) {
            if (4 & a && e.__esModule) return e;
            if (16 & a && "function" == typeof e.then) return e
        }
        var n = Object.create(null);
        o.r(n);
        var d = {};
        t = t || [null, r({}), r([]), r(r)];
        for (var f = 2 & a && e;
            "object" == typeof f && !~t.indexOf(f); f = r(f)) Object.getOwnPropertyNames(f).forEach((t => d[t] = () => e[t]));
        return d.default = () => e, o.d(n, d), n
    }, o.d = (e, t) => {
        for (var r in t) o.o(t, r) && !o.o(e, r) && Object.defineProperty(e, r, {
            enumerable: !0,
            get: t[r]
        })
    }, o.f = {}, o.e = e => Promise.all(Object.keys(o.f).reduce(((t, r) => (o.f[r](e, t), t)), [])), o.u = e => (({
        65: "chart",
        119: "uikit",
        153: "analytics",
        187: "supportSpaViewFile",
        331: "market",
        360: "supportSpaRequests",
        362: "supportSpaFaq",
        377: "pendingTrades",
        469: "supportSpaCreateRequest",
        505: "tournaments",
        510: "tournament",
        552: "transactions",
        644: "account",
        689: "supportSpaRequest",
        930: "trades"
    }[e] || e) + "." + {
        65: "4c121fce51de79c82ca3",
        74: "c6335bb56766867e2396",
        93: "7efde68d206f1d3e7f83",
        119: "944983ce32773e612c15",
        153: "c38343f02c92391bbb1d",
        184: "1e5e4ba076ad912a5899",
        187: "4e9d5691559d49e54f8a",
        296: "267e3bd659d3e843926e",
        320: "7d064b160a0697b9fbf9",
        331: "5fbd82dca5338870f1f0",
        360: "12687a9a2e93df55afa0",
        362: "7ee9671a770132920714",
        377: "1e619e1866352cd3136e",
        451: "8c7d4ef0f30b80a93d56",
        469: "af50c7ae1ab2af9f1c29",
        492: "97e93cd28d756996aa65",
        505: "dacc4ad0eca8742fd5f4",
        510: "760305e0b10a6a73753c",
        552: "546ced69db51bcbf1dc1",
        553: "d01624aae9c3003115b8",
        567: "3c36782b82ef2b2e7f52",
        569: "81858a814ad50a8eb5cc",
        630: "427f3a0d24a3412b81f2",
        644: "5f4908df6a194e90b67b",
        689: "f155b93cb390ede7f63d",
        818: "2a4dc9fe58072d45757f",
        930: "f7a99c9fa1e1ddfd22c4",
        967: "214678cf543df8021e80"
    }[e] + ".js"), o.miniCssF = e => (({
        65: "chart",
        119: "uikit",
        153: "analytics",
        331: "market",
        360: "supportSpaRequests",
        362: "supportSpaFaq",
        377: "pendingTrades",
        469: "supportSpaCreateRequest",
        505: "tournaments",
        510: "tournament",
        552: "transactions",
        644: "account",
        689: "supportSpaRequest",
        930: "trades"
    }[e] || e) + "." + {
        65: "53d74d9e744f7e76e3cc",
        74: "a7391968d8cd5edbafb8",
        119: "3cf3eedf78f32f7d0d9d",
        153: "04bf915069f0d0268890",
        184: "29e00481c7f580f4dff7",
        320: "2fafd96738e61b3b1d5e",
        331: "afe81d4e1fb2f1e399e0",
        360: "c0edd18bc72242199a47",
        362: "1ddcfe6946b3eea49f0e",
        377: "702657d7e4f2b56e8884",
        451: "0fc56cdb2d1e815bd470",
        469: "c5640f401c8d328372dd",
        505: "003b6c0912b196a1384a",
        510: "003b6c0912b196a1384a",
        552: "f81de79e4d37cad8f7ce",
        567: "31fa12917426153d3452",
        569: "0f1be09a000d4e1a8300",
        630: "7740add3aeb4a0195b85",
        644: "b3ce17ff137ec77581b1",
        689: "a1e03414115ae5835b13",
        930: "e28c449321035dc569e0",
        967: "459fefdd3daa360f30ef"
    }[e] + ".css"), o.g = function() {
        if ("object" == typeof globalThis) return globalThis;
        try {
            return this || new Function("return this")()
        } catch (e) {
            if ("object" == typeof window) return window
        }
    }(), o.o = (e, t) => Object.prototype.hasOwnProperty.call(e, t), a = {}, o.l = (e, t, r, n) => {
        if (a[e]) a[e].push(t);
        else {
            var d, f;
            if (void 0 !== r)
                for (var c = document.getElementsByTagName("script"), i = 0; i < c.length; i++) {
                    var s = c[i];
                    if (s.getAttribute("src") == e) {
                        d = s;
                        break
                    }
                }
            d || (f = !0, (d = document.createElement("script")).charset = "utf-8", d.timeout = 120, o.nc && d.setAttribute("nonce", o.nc), d.src = e), a[e] = [t];
            var u = (t, r) => {
                    d.onerror = d.onload = null, clearTimeout(l);
                    var n = a[e];
                    if (delete a[e], d.parentNode && d.parentNode.removeChild(d), n && n.forEach((e => e(r))), t) return t(r)
                },
                l = setTimeout(u.bind(null, void 0, {
                    type: "timeout",
                    target: d
                }), 12e4);
            d.onerror = u.bind(null, d.onerror), d.onload = u.bind(null, d.onload), f && document.head.appendChild(d)
        }
    }, o.r = e => {
        "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(e, Symbol.toStringTag, {
            value: "Module"
        }), Object.defineProperty(e, "__esModule", {
            value: !0
        })
    }, o.nmd = e => (e.paths = [], e.children || (e.children = []), e), o.p = "/trade-room/react/", (() => {
        if ("undefined" != typeof document) {
            var e = e => new Promise(((t, r) => {
                    var a = o.miniCssF(e),
                        n = o.p + a;
                    if (((e, t) => {
                            for (var r = document.getElementsByTagName("link"), a = 0; a < r.length; a++) {
                                var n = (o = r[a]).getAttribute("data-href") || o.getAttribute("href");
                                if ("stylesheet" === o.rel && (n === e || n === t)) return o
                            }
                            var d = document.getElementsByTagName("style");
                            for (a = 0; a < d.length; a++) {
                                var o;
                                if ((n = (o = d[a]).getAttribute("data-href")) === e || n === t) return o
                            }
                        })(a, n)) return t();
                    ((e, t, r, a, n) => {
                        var d = document.createElement("link");
                        d.rel = "stylesheet", d.type = "text/css", o.nc && (d.nonce = o.nc), d.onerror = d.onload = r => {
                            if (d.onerror = d.onload = null, "load" === r.type) a();
                            else {
                                var o = r && r.type,
                                    f = r && r.target && r.target.href || t,
                                    c = new Error("Loading CSS chunk " + e + " failed.\n(" + o + ": " + f + ")");
                                c.name = "ChunkLoadError", c.code = "CSS_CHUNK_LOAD_FAILED", c.type = o, c.request = f, d.parentNode && d.parentNode.removeChild(d), n(c)
                            }
                        }, d.href = t, r ? r.parentNode.insertBefore(d, r.nextSibling) : document.head.appendChild(d)
                    })(e, n, null, t, r)
                })),
                t = {
                    121: 0
                };
            o.f.miniCss = (r, a) => {
                t[r] ? a.push(t[r]) : 0 !== t[r] && {
                    65: 1,
                    74: 1,
                    119: 1,
                    153: 1,
                    184: 1,
                    320: 1,
                    331: 1,
                    360: 1,
                    362: 1,
                    377: 1,
                    451: 1,
                    469: 1,
                    505: 1,
                    510: 1,
                    552: 1,
                    567: 1,
                    569: 1,
                    630: 1,
                    644: 1,
                    689: 1,
                    930: 1,
                    967: 1
                }[r] && a.push(t[r] = e(r).then((() => {
                    t[r] = 0
                }), (e => {
                    throw delete t[r], e
                })))
            }, o.F.miniCss = e => {
                if (!(o.o(t, e) && void 0 !== t[e] || /^(1(21|29|87)|5(02|30|53)|221|296|335|346|492|617|708|804|818|912|93)$/.test(e))) {
                    t[e] = null;
                    var r = document.createElement("link");
                    o.nc && r.setAttribute("nonce", o.nc), r.rel = "prefetch", r.as = "style", r.href = o.p + o.miniCssF(e), document.head.appendChild(r)
                }
            }
        }
    })(), (() => {
        var e = {
            121: 0
        };
        o.f.j = (t, r) => {
            var a = o.o(e, t) ? e[t] : void 0;
            if (0 !== a)
                if (a) r.push(a[2]);
                else if (121 != t) {
                var n = new Promise(((r, n) => a = e[t] = [r, n]));
                r.push(a[2] = n);
                var d = o.p + o.u(t),
                    f = new Error;
                o.l(d, (r => {
                    if (o.o(e, t) && (0 !== (a = e[t]) && (e[t] = void 0), a)) {
                        var n = r && ("load" === r.type ? "missing" : r.type),
                            d = r && r.target && r.target.src;
                        f.message = "Loading chunk " + t + " failed.\n(" + n + ": " + d + ")", f.name = "ChunkLoadError", f.type = n, f.request = d, a[1](f)
                    }
                }), "chunk-" + t, t)
            } else e[t] = 0
        }, o.F.j = t => {
            if ((!o.o(e, t) || void 0 === e[t]) && 121 != t) {
                e[t] = null;
                var r = document.createElement("link");
                o.nc && r.setAttribute("nonce", o.nc), r.rel = "prefetch", r.as = "script", r.href = o.p + o.u(t), document.head.appendChild(r)
            }
        }, o.O.j = t => 0 === e[t];
        var t = (t, r) => {
                var a, n, [d, f, c] = r,
                    i = 0;
                if (d.some((t => 0 !== e[t]))) {
                    for (a in f) o.o(f, a) && (o.m[a] = f[a]);
                    if (c) var s = c(o)
                }
                for (t && t(r); i < d.length; i++) n = d[i], o.o(e, n) && e[n] && e[n][0](), e[n] = 0;
                return o.O(s)
            },
            r = self.webpackChunk = self.webpackChunk || [];
        r.forEach(t.bind(null, 0)), r.push = t.bind(null, r.push.bind(r))
    })(), o.nc = void 0
})();