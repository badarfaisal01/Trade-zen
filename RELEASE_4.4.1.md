# 55AK Trade 4.4.1

This patch preserves every supplied `trade-room/` file byte-for-byte and keeps the existing frontend layout.

## Fixed

- Demo accounts now start at $1,000 for new users. The migration normalises only the former $10,000 factory value and empty pre-patch Demo wallets; administrator-custom values are preserved.
- The React room receives an immediate server-rendered market/account snapshot, preventing slow hosting from falling into the captured maintenance or endless skeleton screen.
- Same-origin APIs can securely restore the signed-in trade session with a short-lived, user-bound signed token when a browser omits the session cookie on an asynchronous request.
- A 401 can redirect to Sign in only once within the guard window, preventing Trade/Sign-in bounce loops.
- Demo/Live selection remains a single API request followed by one matching pathname load.
- A Real trade with insufficient funds shows a bottom error popup long enough to read, then opens Deposit.

## Upgrade

Back up the database, `config/database.local.php`, and `storage/`. Extract the package directly into the document root, restore the backed-up local configuration/storage, open the site once to apply migration 4.4.1, run `/diagnostics.php`, then open `/cache-reset.php` once.
