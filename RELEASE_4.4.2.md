# 55AK Trade 4.4.2

This patch fixes the trading room while preserving every file in the supplied compiled `trade-room/` frontend.

## Fixed

- Local Socket.IO responses are now delivered asynchronously, matching real WebSocket behavior. The compiled React authorization listener receives `s_authorization`, `global.isAuthorized` becomes true, and the room leaves the permanent skeleton screen.
- Demo uses the React bundle's canonical `/en/demo-trade` route. Apache serves that path without `DirectorySlash`, while the legacy `.html` route redirects one way to the canonical URL.
- Demo and Live account switching reloads once onto the correct canonical route without a refresh or Sign-in loop.
- The bridge no longer interprets the shared `/profile/images/spritemap.svg` base path as a Profile click. Up/Down controls now reach React, the confirmation dialog, and the authenticated trade API.
- A new Demo profile retains the configured $1,000 practice balance. A zero/underfunded Live trade shows an insufficient-balance notice and opens Deposit without creating a trade.

## Upgrade

Back up the database, `config/database.local.php`, and `storage/`. Extract the package directly into the domain document root, restore the backed-up local configuration/storage, open the site once to apply the existing 4.4.1 schema migration when required, run `/diagnostics.php`, and then open `/cache-reset.php` once.
