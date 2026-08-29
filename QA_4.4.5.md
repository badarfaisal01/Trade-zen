# 55AK Trade 4.4.5 — QA

The release is checked with PHP token parsing, JavaScript syntax checks, static financial/payment-state assertions, browser-DOM bridge tests, administrator AJAX tests, authentication/route policy tests, checksum verification, and a byte comparison against the supplied 74-file compiled Trade frontend.

Key invariants checked:

- one submitted trade request can create at most one trade through its client request ID;
- `$1.00` is converted to 100 cents and subtracted exactly once;
- Live and Demo balances use separate columns, with Demo defaulting to `$1,000.00`;
- Deposit navigation performs the authenticated session restore first;
- the Deposit entry screen has no UTR/reference or proof input;
- a deposit order is `awaiting_payment` before user confirmation, `pending` after confirmation, and `expired` after an unconfirmed deadline;
- administrator approval reads only a locked `pending` request;
- support-order linking validates that the order belongs to the signed-in user;
- the supplied `trade-room/` file set and bytes are unchanged.
