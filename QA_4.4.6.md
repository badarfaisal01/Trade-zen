# 55AK Trade 4.4.6 — QA

The release is checked with PHP linting, JavaScript syntax checks, PHP-WASM render tests, browser-DOM interaction tests, QR matrix/image validation, administrator AJAX tests, static financial/database invariants, checksum verification, and a byte comparison against the supplied compiled Trade frontend.

Key invariants checked:

- a slow local fallback followed by a late `orders/open` socket event creates one trade request with one request ID;
- the `$1.00` test moves the displayed balance from `$10.00` to `$9.00`, and server code converts the stake to 100 cents under a locked wallet transaction;
- the Trade Deposit pointer event blocks the captured modal handler, restores the local session once, and opens the local Deposit route;
- Demo bootstrap starts at `$1,000.00` and Live zero balance emits the insufficient-balance Deposit message without creating a trade;
- sign-up renders the Referral code as a visible text input;
- desktop and mobile account navigation contain Trade, Support, Account, Affiliate, and More, and every referenced sprite symbol exists locally;
- Affiliate QR generation matches the reference QR encoder; the 640×640 UPI and Binance PNG module maps match their configured payloads;
- UPI/PhonePe and Binance factory destinations are configured, while user-facing Deposit lists omit active methods that have no usable destination or QR;
- the supplied `trade-room/` file set and bytes remain unchanged.

