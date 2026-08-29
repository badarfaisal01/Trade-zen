# QA Report — v4.4.6

Final checks completed after the one-debit Trade, local Deposit routing, automatic Affiliate, payment configuration, and shared Trade navigation patch.

- PHP: 69 files, 0 syntax failures.
- JavaScript: 45 files, 0 syntax failures.
- Supplied compiled `trade-room/`: 74 baseline files and 74 release files; 0 missing, 0 extra, 0 changed.
- Compiled Trade bootstrap: authorized, socket initialized, one asset/quote loaded, no skeleton, no maintenance page, and no non-telemetry runtime errors.
- Compiled Demo Up: exactly one trade request and balance `$1,000.00 → $990.00` in the UI harness.
- Compiled funded Live Down: exactly one trade request and balance `$500.00 → $490.00` in the UI harness.
- Slow fallback + late socket `$1.00` case: one trade API request, one shared request ID, and expected balance `$10.00 → $9.00`.
- Server financial invariants: amount/balance are converted to integer cents; user wallet is locked; `(user_id, client_request_id)` is unique; stake, trade, and ledger mutation share one database transaction.
- Zero-balance Live: zero trade API requests and a visible insufficient-balance Deposit notice.
- Trade Deposit pointer: captured React modal handler blocked, one authenticated session request, destination `/en/balance/?deposit=true&from=trade`.
- Demo/Live canonical routes, signed trade-token restore, and tampered-token rejection pass.
- Sign-up authentication render: visible Referral code text input, CSRF present, local-only resources, and no dead anchor action.
- Affiliate static flow: automatic profiles, attribution, link/code copy, share, local QR, invited-user table, one-time reward ledger, and administrator reward/minimum settings present.
- QR validation: Affiliate matrix matches the reference encoder; configured UPI and Binance PNGs are 640×640 and every sampled module matches its payload.
- Payment flow: order/price entry is separate from QR/reference/proof confirmation; server countdown/state machine and administrator pending-only review boundary pass.
- Account navigation render: desktop/mobile Trade, Support, Account, Affiliate, and More; all referenced Trade sprite symbols exist locally.
- Administrator AJAX: POST JSON, same-origin partial refresh, and success feedback pass.
- Core and Trade-critical SHA-256 manifests: all entries pass.

Use `diagnostics.php` after deployment to validate document-root placement, PHP/PDO/database support, and same-origin HTTP access to the compiled assets on the actual host.

