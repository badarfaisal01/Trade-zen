# 55AK Trade 4.3.1 — QA

- PHP syntax: all project PHP files linted successfully.
- Application JS syntax: `node --check` passed for assets/js and service worker.
- CSS parse: account/auth/original-auth-bridge styles parsed with zero stylesheet errors.
- Captured React trade room: 74/74 files byte-for-byte identical to v4.3.0.
- Demo account interceptor: document-level pointer/mouse/touch/click capture present; no `#root` requirement.
- Legacy `/en/sign-up/modal/`: authenticated safety redirect present.
- Trade fallback: runs only after normal `orders/open` socket emission fails to appear.
- 5s/10s/15s/30s/60s/120s/300s duration support remains present.
- Password recovery: local route, hashed token table, one-time/expiry checks, CSRF forms and reset route present.
- Logout: POST+CSRF primary path plus same-origin legacy fallback present.
- Withdrawal: premium overview, status summary, destination protection and fee-aware quick amounts present.

A live MySQL/session/payment-mail test still depends on the deployed cPanel environment and should be verified after upload with `diagnostics.php` and a test user.
