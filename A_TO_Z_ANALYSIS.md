# 55AK Trade v4.2.5 — A-to-Z analysis

## Root cause proven from the browser screenshot
The browser Network panel shows HTTP 404 for the main React assets under `/trade-room/react/`, including `vendor.ae7d8f8a004fd2feac4d.js`. When these files return 404, the React application cannot start and the page stays blank/dark.

## ZIP comparison
- Working source: `55AK-Trade-Original-React-PHP-v4.1.2.zip`
- Previous generated build: `55AK-Trade-v4.2.3-Stable.zip`
- Shared files: 196
- Additional v4.2.3 files: 55
- Changed shared files: 13
- Entire `trade-room/` tree in v4.2.3 is byte-for-byte identical to v4.1.2 (74 files).
- The nine boot-critical React JS/CSS files are byte-for-byte identical between v4.1.2 and v4.2.3.
- The webpack runtime maps 28 dynamic JavaScript chunks and 22 dynamic CSS chunks; all mapped chunk files are present in this package.

Therefore the current blank page is not caused by a missing bundle inside the ZIP. The files exist in the package but the live web server cannot find them at the URL path it is serving. The most common cause is extracting an archive one directory too deep or not copying the `trade-room` folder into the actual domain document root.

## Packaging fix in v4.2.5
This archive is DOCROOT-ready: `.htaccess`, `index.php`, `trade-room/`, `assets/`, `profile/`, `en/`, `app/`, etc. are at the ZIP root. There is no enclosing `55AK-Trade-v.../` directory. Extracting the ZIP directly in the domain document root therefore creates `/trade-room/react/...` at the exact URL expected by the React runtime.

## Trade source policy
The complete `trade-room/` directory and original trade renderer/bridge/style were re-copied from the known-working v4.1.2 source. Deposit, withdrawal, profile and local PHP/MySQL improvements remain from the later build.

## Database setup
The requested cPanel database credentials are stored server-side in `config/database.defaults.php`. The installer uses them when its database-password field is left blank. They are not printed by `diagnostics.php`.

## Server diagnostics
Open `/diagnostics.php` after extraction. It checks:
- whether the application root equals the domain document root;
- whether every boot-critical trade file exists and is readable;
- the real HTTP status for every critical asset in the browser;
- PDO MySQL availability;
- database connectivity and essential database tables.

Do not proceed to React debugging until all critical asset HTTP rows are 200. A 404 means a server/deployment-path problem, not a React logic problem.
