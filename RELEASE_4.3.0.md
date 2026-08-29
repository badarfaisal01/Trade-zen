# 55AK Trade 4.3.0

This release keeps the supplied compiled React trade-room assets intact and updates the local PHP/JS bridge, finance cabinet, database migration layer, and administrator control center.

## User-facing changes

- Separate **Live Account** and **Demo Account** balances. Existing financial history remains Live; the Demo Account has an independent practice balance.
- `/en/demo-trade/` selects Demo Account and returns to the same trading room.
- Account switching updates the correct balance/trade history without converting Demo into a deposit wallet.
- Live insufficient balance sends the user to Deposit; Demo insufficient balance stays in Demo and shows a warning.
- 5-second and 10-second durations are enabled. The chart bridge now emits distinct short-period history ticks so 5s candles are not collapsed by the captured renderer.
- Deposit and Withdrawal use dedicated presentation classes while preserving the existing request/proof/reference/coupon/destination workflow.
- Profile/settings shows both Live and Demo balances and account-specific trading statistics.
- Trade-room announcements can include a protected banner image uploaded by an administrator.

## Administrator changes

- Premium dashboard with notification badge and a seven-day trading activity chart.
- Add/edit/ban users, change user passwords, safely delete only unused blocked users, and adjust Live or Demo balances separately with audit logs.
- Trade logs identify Live vs Demo Account.
- Add/edit payment methods with QR image, limits, fees, deposit/withdraw toggles, proof/reference rules, sorting, and enable/disable controls.
- Add/edit coupons and gift codes.
- Review deposit/withdrawal requests and inspect payment/account details.
- Add markets and select a global **internal**, **manual reference**, or **HTTPS API cache** source.
- Create administrators, with administrator-account management restricted to the super-administrator role.
- Configure Demo starting balance, Demo Account availability, trade durations, global limits, market API settings, and announcement banner images.

## Market-control boundary

Manual and API price controls are global market-source controls. There is no per-user win/loss or forced-result control; trade entry/expiry prices and settlement remain server-side and audit-visible.

## Upgrade notes

Auto-migration target is `4.3.0`. It adds `users.demo_trade_balance`, `trades.account_type`, `wallet_transactions.account_type`, `announcements.image_filename`, and the Demo Account settings. Existing rows default to Live so previous financial records retain their meaning.

Before replacing an existing installation, back up `config/database.local.php`, the database, and `storage/`.
