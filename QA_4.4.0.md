# 55AK Trade 4.4.0 — QA

- PHP 8.3 token parser: all project PHP files passed.
- JavaScript parser: all authored application/service-worker JavaScript passed `node --check`.
- SQL: schema and seed parsed as MySQL; migration additions are guarded by column/index/table checks.
- Captured React trade room: 74/74 files match the uploaded 4.3.1 archive byte-for-byte.
- Authentication templates: both pages rendered through the real PHP transformer; CSRF, referral preservation, local navigation, and no remote action/source were verified.
- Admin AJAX: a browser-DOM test verified POST submission, JSON handling, partial page replacement, and success feedback without a full reload.
- Trading bridge: browser-DOM tests verified one Demo switch request, one funded Live trade request, and the zero-balance Deposit guard.
- Routes: every explicit `.htaccess` PHP target and all critical trade assets exist in the DOCROOT.
- CSS: authored styles passed balanced block/comment/string checks.
- Archive: final ZIP is tested before handoff.

A live MySQL/session/payment-provider test depends on the deployment environment. After upload, back up first, open `/diagnostics.php`, confirm database/table and HTTP checks, test one Demo switch, one funded Live trade, and one insufficient Live trade, then remove or rename diagnostics.
