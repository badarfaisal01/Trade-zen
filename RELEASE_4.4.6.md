# 55AK Trade 4.4.6

This patch preserves the supplied compiled Trade frontend and completes the navigation, exact-debit, payment, and automatic Affiliate changes.

## Fixed and added

- The Trade header Deposit gesture is intercepted before the captured React modal and opens `/en/balance/?deposit=true&from=trade` after a same-origin session check.
- One Up/Down click receives one client request ID. A delayed socket event now reuses an already-started fallback request instead of creating a second debit.
- The server normalizes stakes to integer cents, locks the user wallet, and uses the unique `(user_id, client_request_id)` trade key. A `$1.00` order therefore subtracts exactly `$1.00` once.
- Every new and existing user receives an active personal Affiliate code automatically; no approval request is required.
- Sign-up contains a visible optional Referral code input and rejects invalid, inactive, and self-referral codes.
- Affiliate includes copy-link, copy-code, Web Share fallback, a locally generated downloadable QR, invited-user counts/history, qualification state, and earned-bonus totals.
- Admin → Agents & referrals configures the fixed inviter bonus and minimum qualifying approved deposit, permits attribution corrections, and shows referral/reward history through the existing AJAX control center.
- A reward is credited to the inviter's Live wallet once, after an invited user's qualifying approved deposit, with a dedicated idempotent ledger record.
- UPI and PhonePe are preconfigured for the supplied UPI destination and local QR; Binance Pay is preconfigured for the supplied Pay ID and a separate local QR. Unconfigured deposit methods are hidden until an administrator adds an ID/address or QR.
- Signed account pages use the supplied Trade-room sprite, labelled desktop rail, and matching five-item mobile navigation.

## Database update

The automatic `4.4.6` migration adds the user-to-Affiliate link, referral reward state/request/amount/time fields, the `referral_bonus` wallet-ledger type, Affiliate reward settings, automatic user-code backfill, and non-destructive factory payment defaults. Existing non-empty payment destinations and QR filenames remain unchanged.

