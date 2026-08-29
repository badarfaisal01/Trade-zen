# 55AK Trade 4.5.0 DOCROOT

55AK Trade is a self-hosted PHP/MySQL trading platform for `https://trading.55ak.xyz`. This build preserves the supplied responsive React trade room, compiled chart, styles, icons, fonts, and authentication layout, while replacing the captured third-party session and network layer with authenticated same-origin PHP APIs.

The platform includes a wallet request workflow, administrator-controlled UPI/wallet/crypto methods and QR images, payment proof uploads, deposit coupons, gift codes, support tickets, profile settings, analytics, activity history, role-based administration, audit logs, maintenance mode, and global internal/manual/HTTPS-API market sources. Version 4.5.0 keeps the supplied compiled `trade-room/` bundle intact, routes Trade's Deposit shortcut to the signed-in local payment flow before the captured modal can open, and binds every UI click/fallback/socket order to one idempotency key. Integer-cent wallet logic makes a `$1.00` stake exactly one `$1.00` debit. Demo users start with `$1,000.00`, chart drawings remain independently removable, and the same premium Trade frontend is preserved.

Version 4.5.0 adds dense chronological candle history with no future ticks, administrator-controlled chart history/tick/refresh settings, persistent first-login onboarding, account identity submission and administrator review, an audited whole-account currency exchange with configurable rates and fee, and a screenshot-matched Market workspace for gift and deposit-bonus codes. The account, exchange, verification, Market, and Deposit surfaces retain the existing cabinet layout and responsive styling.

Affiliate access is automatic for every user: registration creates a personal referral code without administrator approval, sign-up exposes an optional referral-code field, and the Affiliate page provides link/code copy, Web Share, a locally generated QR, invited-user status, and earned-bonus totals. Administrators configure the fixed inviter bonus and qualifying minimum approved deposit. The supplied UPI, PhonePe, and Binance Pay factory records include the requested identifiers and local QR assets; administrators can replace them at any time.

Payment requests are operational records: approving a deposit credits the platform wallet, withdrawal submission reserves the required wallet amount, rejection/cancellation refunds that reserve, and an approved withdrawal can be marked paid only with an external provider reference. The application does not itself send a bank, UPI, Binance, or blockchain transfer.

## Requirements

- Apache 2.4 with `.htaccess` and `mod_rewrite`
- PHP 7.4–8.3
- PHP extensions: PDO, `pdo_mysql`, JSON, OpenSSL; cURL for HTTPS market API feeds
- MySQL 5.7+ or MariaDB 10.3+
- HTTPS certificate for the production domain

No Composer or Node.js installation is required on the server.

## Installation

1. Upload the contents of this folder to the domain document root.
2. In cPanel, create a MySQL database and user, attach them, and grant that user all privileges on this database.
3. Enable PHP PDO and `pdo_mysql` (and cURL if API pricing will be used).
4. Make `config/` and `storage/` writable by the PHP process during installation, normally `0755` or `0775` depending on ownership.
5. Open `https://trading.55ak.xyz/install/`.
6. Enter the database credentials and create the first super-administrator.
7. Sign in at `/admin/`, then configure payment destinations, QR images, platform settings, and markets.
8. Return `config/database.local.php`, `storage/installed.lock`, and protected upload directories to the most restrictive permissions supported by the host (commonly `0640` for files and `0750` for directories).

The installer imports the schema and seed data, writes the local database configuration, and locks itself. The seed keeps the historical baseline marker and the first application boot automatically applies migrations through `4.5.0`. Existing installations also receive onboarding/verification fields, the currency-exchange audit table, dense-chart controls, payment-order fields, separate `$1,000.00` Demo wallet, trade idempotency support, automatic Affiliate profiles, referral reward ledger fields/settings, and configured factory payment QR references automatically. Existing non-empty administrator payment details are preserved. Take a database backup before upgrading. Startup migrations use a cPanel-compatible server file lock with a database-lock fallback, so simultaneous requests cannot race and hosts that restrict `GET_LOCK()` continue to work.

## Main routes

- `/en/sign-up/` — registration
- `/en/sign-in/` — account login
- `/en/trade/` — React trading room
- `/en/demo-trade` — Demo-mode React trading room (internally served, never redirect-looped)
- `/en/balance/` — deposit entry point
- `/en/pay/?order=...` — separate order payment, timer, QR/details, and confirmation
- `/en/withdrawal/` — withdrawal entry point
- `/en/wallet/` — shared local request engine, payment methods, gift codes, and wallet ledger
- `/en/analytics/` — performance overview
- `/en/history/` — detailed trade history
- `/en/settings/` — profile, verification, account-currency exchange, and password
- `/en/market/` — gift codes, deposit bonuses, and redemption history
- `/en/support/` — user support requests
- `/en/affiliate/` — automatic referral link/code, share/QR, invited users, qualification, and bonus overview
- `/en/about/`, `/en/blog/`, `/en/faq/`, `/en/contact/` — public information and help
- `/admin/` — administrator control center
- `/install/` — one-time installer

The Demo account uses `/en/demo-trade` because that is the exact pathname compiled into the supplied React room. Apache serves the local Demo controller internally without adding a trailing slash. The legacy `/en/demo-trade.html` address redirects one way to the canonical route, preventing the former Demo/Live refresh loop.

## Finance workflow

1. An administrator creates or edits a payment method under **Admin → Payment methods** and can configure its UPI/payment ID, network, QR image, instructions, limits, fees, proof/reference requirements, supported flows, active state, and sort order.
2. A deposit user first selects a method and amount. The initial page shows only the order/price step; it does not expose the QR, destination, UTR, or proof fields.
3. **Pay** creates an `awaiting_payment` order and opens its separate payment page. UPI/PhonePe or Binance/crypto details, QR, exact amount, and countdown are specific to that order.
4. The user submits the transaction reference/proof on the Pay page. Only that confirmation changes the order to `pending` and sends it to the administrator queue. An unconfirmed order becomes `expired` after the configured 5–60 minute payment window (15 minutes by default).
5. Withdrawal requests include a destination and immediately reserve `amount + configured fee`. Cancellation or rejection returns the full reserve exactly once.
6. An authorized administrator reviews only pending requests. Deposit approval credits the Live wallet; an approved withdrawal remains reserved until it is marked paid with the actual external provider reference.
7. Every wallet mutation and administrator decision is retained in the ledger or audit log, and users can attach any deposit/withdrawal order to a support ticket.

Uploaded proof, QR, and avatar files are stored below `storage/` and streamed only through authenticated PHP endpoints; they are not public static files.

## Market controls

Each market has one global price-source mode:

- **Internal feed:** deterministic server-side price movement around the configured reference price.
- **Manual reference:** the administrator controls the global reference price used by the same server-side engine.
- **HTTPS API cache:** an administrator fetches a global price from the configured JSON API and the server uses the cached reference.

API requests require HTTPS on port 443, disallow redirects and URL credentials, resolve only to public addresses, verify TLS, enforce time and response-size limits, and are pinned to the validated address for that request. Trade entry and expiry prices are calculated on the server. Administrators cannot force an individual user’s result.

## Administrator roles

- **Manager:** dashboards, trades, fund-request review, support, and audit visibility.
- **Admin:** manager access plus users, wallet adjustments, payment methods, gift/coupon management, markets, agents/referrals, user notifications, branding, announcements, and platform settings.
- **Super administrator:** admin access plus administrator-account management.

New administrator accounts use a temporary password and must change it before entering the control center.

## Settlement cron

The signed-in UI settles due trades when account state refreshes. Add this cPanel cron once per minute so settlement also runs when users close their browser:

```text
* * * * * /usr/local/bin/php /home/CPANEL_USER/public_html/cron/settle.php >/dev/null 2>&1
```

Replace the PHP executable and project path with the values for your hosting account. The cron entry point is CLI-only and blocked from HTTP access.

## Backups and operation

Back up the MySQL database, `config/database.local.php`, and `storage/` together. The database contains user accounts, password hashes, trades, wallet records, payment requests, coupon redemptions, support tickets, settings, and administrator audit events. Never publish the local database configuration or an API key.

If this platform is connected to real money or external financial providers, the operator is responsible for applicable licensing, disclosures, KYC/AML, sanctions screening, consumer protection, accounting, custody, privacy, retention, dispute handling, and provider security requirements.

## v4.5.0 DOCROOT deployment note
This ZIP intentionally has no enclosing project directory. Extract it directly into the document root for `trading.55ak.xyz`. After extraction, the document root itself must contain `.htaccess`, `index.php`, `trade-room/`, `assets/`, `profile/`, `en/`, `app/`, `config/`, and `database/`.

Before opening Trade, open `/diagnostics.php`. Every critical asset must show a local disk PASS and an HTTP 200. If the document-root check fails, move the package contents up to the domain document root. After setup succeeds, delete or rename `diagnostics.php`.
