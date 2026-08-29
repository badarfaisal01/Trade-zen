# Trade debug report — v4.2.5

## Evidence
The current live browser Network panel returns `404 Not Found` for boot-critical URLs under `/trade-room/react/`. A page cannot mount React when `runtime`, `vendor`, `uikit`, `app`, and related chunks cannot be downloaded.

## Code comparison
The complete v4.2.5 `trade-room/` directory was re-copied from known-working v4.1.2. It is byte-for-byte identical: 74/74 files match. `app/original_trade.php`, `assets/js/original-trade-bridge.js`, and `assets/css/original-trade.css` are also restored from v4.1.2.

## Root cause
The failing URLs are absolute paths such as `/trade-room/react/vendor...js`. They require a physical `trade-room` directory directly under the domain document root. Previous archives contained an outer project folder; extracting such an archive directly can create `<document-root>/55AK-Trade-v.../trade-room/...`, while the browser asks for `<document-root>/trade-room/...`, producing exactly the observed 404s.

## Fix
v4.2.5 is a DOCROOT archive: there is no outer directory inside the ZIP. It also adds an existing-file pass-through in `.htaccess` and a live `diagnostics.php` page.
