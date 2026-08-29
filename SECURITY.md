# Security notes

The supplied browser capture originally contained third-party session state, account metadata, telemetry, and remote network calls. This build removes those runtime dependencies: authentication, session state, trade placement, settlement, wallet mutations, support, and administration use the local PHP/MySQL application. Original compiled UI assets remain local and are connected through a same-origin bridge.

## Implemented controls

- Passwords use `password_hash()` and `password_verify()`.
- Successful login rotates the session ID. Cookies use `HttpOnly`, `SameSite=Lax`, and `Secure` when HTTPS is active.
- State-changing forms and JSON endpoints require a CSRF token.
- Login and high-frequency trading/request actions are rate limited.
- User-controlled SQL values use PDO prepared statements.
- Wallet and trade mutations use transactions and row locks.
- Withdrawal balances are reserved on submission and refunded transactionally on rejection/cancellation.
- Uploaded QR, proof, and avatar images are type/dimension/size checked, randomly named, stored outside public paths, and served through authorization checks.
- Trade entry and expiry prices are calculated on the server. Market-source changes are global and administrator-audited; there is no per-user outcome override.
- HTTPS market API requests reject private/reserved destinations, redirects, alternate ports, and URL credentials; verify TLS; pin the validated DNS result; and limit time and response size.
- Administrator access is role/capability based. Sensitive actions and settings changes are recorded in `admin_audit_logs`.
- Newly created administrators must replace their temporary password before opening the control center.
- Apache blocks the application, configuration, SQL, cron, storage, raw authentication templates, logs, backups, and common secret filenames.
- Captured telemetry endpoints are disabled and remote broker links are routed locally or removed.

## Production checklist

- Enforce HTTPS and HSTS at the hosting or CDN layer after confirming every subdomain supports HTTPS.
- Use a unique database user limited to this database and a unique 16+ character super-administrator password.
- Keep PHP, MySQL/MariaDB, Apache, and cPanel supported and patched.
- Protect `config/database.local.php`, the database backup, API credentials, and all files below `storage/`.
- Back up the database and protected uploads together, encrypt off-server copies, and test restoration.
- Review administrator accounts, request decisions, balance adjustments, API changes, and audit logs regularly.
- Configure retention and deletion rules for payment screenshots, wallet destinations, support content, IP addresses, and audit data.
- Verify payment references with the external provider; never approve a deposit from screenshot appearance alone.
- Complete withdrawals externally before marking them paid. The application does not transfer funds by itself.
- Never create administrator logic that chooses an individual user’s win/loss result or rewrites settled trade records.
- If external funds are involved, complete the required legal, licensing, KYC/AML, sanctions, custody, privacy, tax, dispute, risk-disclosure, and provider-security work before launch.

The generic market API key is stored in the database because shared hosting may not expose a secret manager. Keep database access restricted, never make the setting public, and rotate the key after any suspected exposure.

If Cloudflare proxies the domain and the origin accepts traffic only from verified Cloudflare ranges, set `TRUST_CLOUDFLARE_IP_HEADER=1` so audit and throttle records use the visitor address. Leave it disabled on directly reachable origins because an untrusted request could spoof that header.

## User-safety language

Do not ask a user to send a password, OTP, UPI PIN, card PIN, remote-access code, wallet seed phrase, private key, session cookie, or API token. Keep payment and support instructions inside the signed-in platform. Results are not guaranteed, and internal/manual pricing must be described accurately to users.
