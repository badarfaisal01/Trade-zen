# 55AK Trade v4.4.6 — Full A-to-Z QA

## Verified fixes

- Live and Demo controllers select one canonical account route without a refresh loop; new Demo wallets start at `$1,000.00`.
- Trade renders from the supplied compiled bundle and authorizes immediately from signed server bootstrap data.
- The Trade Deposit gesture blocks the captured blank modal, restores the local PHP session, and opens the signed-in Deposit page.
- One user click, local fallback, and late socket order share one request ID. Server-side cents, wallet locking, and the unique user/request key make a `$1.00` stake exactly one debit.
- Deposit is split into order/price, method-specific Pay, user confirmation, and administrator review. Unconfirmed orders expire on the configured timer.
- UPI and PhonePe use the configured UPI destination/QR; Binance Pay uses its configured ID/QR. Methods without a destination or QR stay out of the user Deposit list.
- Affiliate access is automatic. Registration creates a personal code, sign-up accepts a visible optional code, and link/code copy, share, QR, invited users, and bonus state are available without approval.
- Affiliate bonus and minimum qualifying approved deposit are administrator settings. A reward moves from pending to credited only once and is written to the Live wallet ledger.
- Every signed account page uses the same supplied Trade sprite and labelled desktop/mobile navigation.
- Administrator forms and filters use the existing same-origin AJAX document-swap controller with CSRF and capability checks.

## Deployment defaults

The installer contains the deployment database defaults requested by the operator. They stay in a server-denied PHP configuration file and are never printed by diagnostics. Rotate deployment credentials after installation when practical. Administrator login credentials are not stored in the release.

## Release checks

See `QA_REPORT.md` and `QA_4.4.6.md` for the final executable-test results. The compiled `trade-room/` directory is byte-for-byte compared with the supplied baseline before packaging.

## Deployment order

1. Back up the database, `config/database.local.php`, and `storage/`.
2. Extract the ZIP contents directly into the domain document root, with no extra version folder.
3. Restore `config/database.local.php` and persistent uploads for an existing installation, or open `/install/` once for a new installation.
4. Open `/cache-reset.php` once, then `/diagnostics.php` and confirm the document-root, asset, PDO, and database checks.
5. Sign in and test Live Trade, Demo Trade, Deposit, Pay, Affiliate, Account, and Admin once.
6. Remove or rename `diagnostics.php` and lock the installer after successful setup.

## Scope

Payment and withdrawal records are administrator-reviewed platform workflows. The application does not itself execute an external bank, UPI, Binance, blockchain, or broker transfer.

