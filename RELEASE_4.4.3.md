# 55AK Trade 4.4.3

This patch fixes the remaining full-page `LOADING`/refresh loop while preserving every supplied compiled frontend and stylesheet file.

## Root cause

The saved profile could be `demo` while `/en/trade/` rendered as the Live route. The supplied React bundle detects that mismatch, renders only its full-screen `LOADING` component, and performs a hard navigation. Re-entering mismatched account/route state caused repeated page navigation.

## Fixed

- `/en/trade/` now selects Live before rendering, while `/en/demo-trade` selects Demo. Each URL renders its matching account in the same response without a redirect.
- The bridge independently canonicalises a stale cached Live/Demo page before React starts.
- Account-route navigation uses replacement history, so Back cannot re-enter the mismatched loading page.
- The existing asynchronous authorization, $1,000 Demo balance, funded Real trade, and insufficient Live balance fixes remain active.
- Service-worker cache version is advanced so browsers stop reusing the previous bridge.

## Upgrade

Back up the database, `config/database.local.php`, and `storage/`. Extract the ZIP directly into the domain document root, restore the preserved local configuration/storage, open `/cache-reset.php` once, and then sign in again. Do not place the files inside an extra nested version folder.
