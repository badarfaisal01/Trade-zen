# 55AK Trade 4.4.5

This patch preserves the supplied premium Trade frontend and adds the final account/payment fixes requested for the current deployment.

## Fixed

- Trade's Deposit, Withdrawal, Support, Settings, Analytics, and Affiliate actions restore the authenticated PHP session before leaving the captured React router, preventing the blank/login destination seen after a valid Trade login.
- Stakes use integer-cent comparison and subtraction. A `$1.00` Up/Down order debits exactly `$1.00`, with the balance update, trade insert, and wallet ledger entry kept in one locked transaction.
- An underfunded Live order shows the insufficient-balance notice and opens Deposit; Demo remains a separate `$1,000.00` practice balance.
- Deposit is now a three-state flow: create time-limited order, pay on a separate method-specific page, then confirm for administrator review.
- The initial Deposit page no longer exposes a QR, payment destination, UTR/reference, proof upload, or confirmation control.
- The Pay page shows order/price, UPI/PhonePe or Binance/crypto details, optional QR, transaction reference/proof requirements, and a server-backed countdown.
- An unconfirmed order expires automatically. Only a user-confirmed `pending` deposit can be approved by an administrator.
- Support can link any of the user's deposit/withdrawal orders, and the administrator sees that link in the support queue.
- Affiliate/referral has a working user page based on the existing administrator-controlled Agent system.
- Cabinet/mobile navigation is consistent while the original Trade icons, chart, layout, and compiled bundle remain unchanged.

## Database update

The automatic `4.4.5` migration adds deposit payment deadline/confirmation timestamps, the `awaiting_payment` and `expired` statuses, a support-to-fund-order link, and the configurable `payment_order_minutes` setting (5–60 minutes, 15 by default).
