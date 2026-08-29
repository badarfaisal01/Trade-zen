# 55AK Trade 4.3.1

This patch keeps the supplied `trade-room/` bundle byte-for-byte unchanged and fixes the local integration around it.

## Fixes
- Demo/Live account selection is intercepted at document capture phase, including React portal dropdown rows outside `#root`, so it no longer falls into `/en/sign-up/modal/`.
- Authenticated requests that still reach the legacy sign-up modal route are redirected straight to Trade as a server-side safety fallback.
- Funded Up/Down controls have a delayed same-origin trade fallback only when the normal `orders/open` socket event never arrives; the normal captured trade path remains first priority.
- Added trade diagnostics for active account/balance, last socket order, last local trade API attempt, and last trade error.
- Logout now keeps POST+CSRF as the primary path and supports guarded same-origin legacy GET links.
- Forgot-password now routes locally and uses one-time hashed reset tokens with a 30-minute expiry. PHP mail is used for delivery when configured by the hosting environment.
- Withdrawal received a separate premium summary, progress workflow, destination-security panel, fee-aware percentage quick amounts, and responsive styling.

## Database
Auto-migration target is `4.3.1`. It preserves the 4.3.0 Demo/Live schema repair and adds `password_reset_tokens`. Existing rows and trade history are not deleted.
