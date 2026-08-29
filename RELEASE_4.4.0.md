# 55AK Trade 4.4.0

This release preserves all 74 supplied `trade-room/` files byte-for-byte.

## Trade and account reliability

- Persists Live/Demo selection per user and session.
- Serves the Demo pathname internally, removing the former redirect/refresh loop.
- Uses a per-click request identifier and database unique key so socket/fallback retries cannot debit a stake twice.
- Waits for an in-progress account switch before opening a trade.
- Improves investment-field detection and the funded-button fallback.
- Returns a structured insufficient-balance response and routes underfunded Live users to Deposit after a visible notice.

## Administration

- AJAX navigation, filters, forms, uploads, history, flashes, and live operational refresh without full-page reloads.
- Finance ledger and wallet totals.
- Agents, referral codes, sign-up attribution, assignments, and referred Live volume.
- Broadcast/direct-user trade-room notifications with scheduling and enable/disable controls.
- Site-logo upload/removal and platform-name branding.
- Existing users, Live/Demo balance controls, bans, trades, fund requests, payment methods/UPI/QR/channels, coupons/gifts, support, global market controls, administrator accounts, maintenance/settings, and audit pages remain available.

## Public routes

About, Blog, FAQ, Contact, Terms, Privacy, and Risk routes are local and working. Authentication navigation is kept visually unchanged while dead/external actions are routed to a valid local destination.

## Upgrade

Back up the database and `storage/`, upload the DOCROOT contents, and open the site once. The locked automatic migration adds the 4.4.0 columns, unique index, tables, storage directory, and setting without deleting existing history.
