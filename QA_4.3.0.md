# 55AK Trade 4.3.0 — QA notes

Checks performed on the packaged source before delivery:

- Compiled `trade-room/` comparison against the uploaded v4.2.5 source: 74/74 files unchanged, 0 missing, 0 extra: PASS.
- PHP syntax lint across every `.php` file: PASS.
- JavaScript syntax for the local trade bridge, account UI, and admin UI: PASS.
- CSS parsing for `original-trade.css`, `account.css`, and `admin.css`: PASS.
- Unexpected control-character scan across editable text assets: PASS.
- Database schema static check: one `wallet_transactions` definition, required 4.3.0 columns present: PASS.
- Pure PHP market-engine test for 20 x 5-second candles with exact 5000 ms spacing and valid OHLC bounds: PASS.
- Bridge history conversion test for 5-second candles: four distinct, monotonic ticks per candle: PASS.
- Local PHP HTTP document-root smoke test: diagnostics, compiled React runtime/vendor/app bundles, local bridge, and admin/account/trade CSS returned HTTP 200: PASS.

A full authenticated browser/database workflow still requires a MySQL/MariaDB deployment because this build workspace does not contain the production database configuration. After deployment, use `/diagnostics.php`, then sign in and verify Live/Demo switching, one 5s chart cycle, Deposit, Withdrawal, and the admin queues before removing diagnostics.
