# 55AK Trade 4.4.1 — QA

- PHP 8.3 token parser and tree-sitter parser pass on every PHP file.
- Auth templates render with CSRF fields, local actions/routes, and no remote scripts.
- Browser-DOM trade tests verify immediate authorization before delayed APIs, signed recovery headers, a $1,000 Demo balance, one account-switch request, one funded Live trade request, and the insufficient-balance popup.
- Signed API-token runtime test verifies valid session restoration and rejects a modified signature.
- Authored JavaScript and SQL parse cleanly.
- All supplied compiled `trade-room/` files remain byte-for-byte identical to the uploaded source archive.
- The final ZIP is extracted and checksum-tested before handoff.

A final signed-in MySQL/browser test must be run on the deployed domain after restoring `config/database.local.php` and `storage/`.
