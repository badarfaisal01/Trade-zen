# cPanel deployment for trading.55ak.xyz

## 1. Point the domain

Set the domain document root to the folder containing this project. Point its DNS A/AAAA record to the host and issue an AutoSSL certificate. Enable HTTPS redirection after the certificate is active.

The included Apache rules allow the standard AutoSSL validation paths under `.well-known` while blocking other hidden paths and all application, configuration, cron, database, and storage directories.

## 2. Upload the build

Upload the ZIP in cPanel File Manager, extract it, and move the contents of `55ak-trading-original-ui/` into the document root. The root must directly contain `index.php`, `.htaccess`, `admin/`, `app/`, `api/`, `assets/`, `config/`, `cron/`, `database/`, `en/`, `profile/`, `site/`, `storage/`, and `trade-room/`.

Enable **Show Hidden Files** so `.htaccess` is uploaded. Do not expose the ZIP inside the public document root after extraction.

## 3. Select PHP

Use PHP 7.4, 8.1, 8.2, or 8.3. Enable PDO, `pdo_mysql`, JSON, and OpenSSL. Enable cURL if the administrator will use an HTTPS market API feed. Set a sensible upload limit above the proof-image limit configured in the admin panel.

## 4. Create MySQL access

Create the database and a dedicated database user, attach the user, and grant it all privileges on this database. cPanel commonly prefixes both names with the account username; enter the complete names in the installer.

## 5. Run the installer

Open `https://trading.55ak.xyz/install/`. The database must already exist; the installer creates the tables within it and creates the first super-administrator.

If configuration cannot be written, temporarily change only `config/` and `storage/` to the least-permissive writable mode supported by the host, commonly `0775`, then restore restrictive permissions after installation. Avoid `0777`.

For an upgrade, back up the database and `storage/` first, replace the application files, and open the site once. The auto-migration adds the 4.1 tables/columns and protected upload directories without deleting existing users, trades, or wallet records.

## 6. Configure the administrator panel

- In **Payment methods**, add the operator-owned UPI IDs, payment IDs, crypto destinations, networks, instructions, fees, limits, and QR images. Disable any method that is not ready.
- In **Gift & coupons**, create only codes whose limits and dates have been reviewed.
- In **Markets**, choose internal, manual, or HTTPS API pricing per market. API mode requires a configured endpoint containing `{symbol}` and the correct JSON price path.
- In **Platform settings**, confirm domain, support email, wallet currency, request toggles, trade limits, durations, maintenance mode, and upload size.
- Create additional administrators only when needed. New administrators must replace their temporary password.

## 7. Add the settlement cron

Add the once-per-minute command from `README.md`. Confirm the PHP CLI executable uses the same PHP version and extensions as the web application.

## 8. Verification checklist

- Register a user and confirm the configured opening balance and currency.
- Open `/en/trade/`, place a minimum-size trade, wait for settlement, and reconcile the trade history and wallet ledger.
- Click the React profile icon and confirm it opens `/en/settings/`, not the trade room.
- Add an administrator-owned UPI method and QR, then verify both desktop and 390 px mobile layouts.
- Submit a deposit with its required reference/proof, approve it once, and confirm one credit and one audit event.
- Submit a withdrawal and confirm `amount + fee` is reserved. Reject one request and verify the exact refund; approve another and mark it paid with the external provider reference.
- Redeem a gift code once and test a deposit coupon through approval.
- Open and answer a support ticket.
- Test maintenance mode as a normal user and as an administrator.
- If API pricing is enabled, refresh each API market from the admin panel and verify its timestamp/reference price.
- Confirm `/config/database.local.php`, `/database/schema.sql`, `/cron/settle.php`, and `/storage/` return 403/404 in a browser.
- Confirm the direct authentication `index.html` templates cannot be opened publicly.

## Important payment limitation

The application records and controls wallet requests but does not call a bank, UPI, Binance, or blockchain payout API. An administrator must verify incoming payment independently and complete an approved withdrawal through the configured external provider before recording its provider reference and marking it paid.
