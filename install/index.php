<?php
declare(strict_types=1);

$basePath = dirname(__DIR__);
$lockPath = $basePath . '/storage/installed.lock';
$configPath = $basePath . '/config/database.local.php';
$defaultsPath = $basePath . '/config/database.defaults.php';
$dbDefaults = is_file($defaultsPath) ? (array)(require $defaultsPath) : [];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; frame-ancestors 'none'");

session_name('TRADE55AK_INSTALL');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();

if (empty($_SESSION['_install_csrf'])) {
    $_SESSION['_install_csrf'] = bin2hex(random_bytes(32));
}

$installed = is_file($lockPath) && is_file($configPath);
$errors = [];
$success = false;
$values = [
    'db_host' => (string)($dbDefaults['host'] ?? 'localhost'),
    'db_port' => (string)($dbDefaults['port'] ?? '3306'),
    'db_name' => (string)($dbDefaults['database'] ?? ''),
    'db_user' => (string)($dbDefaults['username'] ?? ''),
    'site_url' => 'https://trading.55ak.xyz',
    'admin_name' => '55AK Administrator',
    'admin_email' => 'admin@55ak.xyz',
];

if (!$installed && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    foreach (array_keys($values) as $key) {
        $values[$key] = trim((string)($_POST[$key] ?? $values[$key]));
    }
    $password = (string)($_POST['db_password'] ?? '');
    if ($password === '') {
        $password = (string)($dbDefaults['password'] ?? '');
    }
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $token = (string)($_POST['_csrf'] ?? '');

    if (!hash_equals((string)$_SESSION['_install_csrf'], $token)) {
        $errors[] = 'Installer security token expired. Refresh and try again.';
    }
    if ($values['db_host'] === '' || $values['db_name'] === '' || $values['db_user'] === '') {
        $errors[] = 'Database host, name, and user are required.';
    }
    if (!ctype_digit($values['db_port']) || (int)$values['db_port'] < 1 || (int)$values['db_port'] > 65535) {
        $errors[] = 'Database port is invalid.';
    }
    if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $values['db_name'])) {
        $errors[] = 'Database name contains unsupported characters.';
    }
    if (!filter_var($values['site_url'], FILTER_VALIDATE_URL) || strtolower((string)parse_url($values['site_url'], PHP_URL_SCHEME)) !== 'https') {
        $errors[] = 'Site URL must be a valid HTTPS URL.';
    }
    if (strlen($values['admin_name']) < 2 || strlen($values['admin_name']) > 80) {
        $errors[] = 'Administrator name must be between 2 and 80 characters.';
    }
    if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Administrator email is invalid.';
    }
    if (strlen($adminPassword) < 12 || !preg_match('/[A-Za-z]/', $adminPassword) || !preg_match('/\d/', $adminPassword) || !preg_match('/[^A-Za-z0-9]/', $adminPassword)) {
        $errors[] = 'Administrator password needs at least 12 characters, a letter, a number, and a symbol.';
    }
    if (!is_writable($basePath . '/config') || !is_writable($basePath . '/storage')) {
        $errors[] = 'The config and storage directories must be writable during installation.';
    }

    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $values['db_host'], (int)$values['db_port'], $values['db_name']);
            $pdo = new PDO($dsn, $values['db_user'], $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $runSqlFile = static function (PDO $connection, string $path): void {
                $sql = (string)file_get_contents($path);
                $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if ($statement !== '') {
                        $connection->exec($statement);
                    }
                }
            };

            $runSqlFile($pdo, $basePath . '/database/schema.sql');
            $runSqlFile($pdo, $basePath . '/database/seed.sql');

            $pdo->beginTransaction();
            $find = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
            $find->execute([strtolower($values['admin_email'])]);
            $adminId = (int)$find->fetchColumn();
            $startingBalance = 0.00;
            if ($adminId > 0) {
                $update = $pdo->prepare("UPDATE users SET name = ?, password_hash = ?, role = 'super_admin', status = 'active', force_password_change = 0, updated_at = NOW() WHERE id = ?");
                $update->execute([$values['admin_name'], password_hash($adminPassword, PASSWORD_DEFAULT), $adminId]);
            } else {
                $insert = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status, demo_balance, currency, country, force_password_change, created_at, updated_at) VALUES (?, ?, ?, 'super_admin', 'active', ?, 'USD', 'IN', 0, NOW(), NOW())");
                $insert->execute([$values['admin_name'], strtolower($values['admin_email']), password_hash($adminPassword, PASSWORD_DEFAULT), $startingBalance]);
                $adminId = (int)$pdo->lastInsertId();
            }
            $domain = $pdo->prepare("UPDATE site_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = 'platform_domain'");
            $domain->execute([rtrim($values['site_url'], '/'), $adminId]);
            $pdo->commit();

            $config = [
                'host' => $values['db_host'],
                'port' => (int)$values['db_port'],
                'database' => $values['db_name'],
                'username' => $values['db_user'],
                'password' => $password,
                'charset' => 'utf8mb4',
            ];
            $configPhp = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            $temporary = $configPath . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, $configPhp, LOCK_EX) === false || !rename($temporary, $configPath)) {
                throw new RuntimeException('Could not write database.local.php.');
            }
            @chmod($configPath, 0640);

            $lock = json_encode(['installed_at' => date(DATE_ATOM), 'version' => '4.4.6'], JSON_UNESCAPED_SLASHES);
            if (file_put_contents($lockPath, $lock, LOCK_EX) === false) {
                throw new RuntimeException('Could not create installation lock.');
            }
            @chmod($lockPath, 0640);
            $_SESSION['_install_csrf'] = bin2hex(random_bytes(32));
            $success = true;
            $installed = true;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Installation failed: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Install 55AK Trade</title>
    <link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body class="auth-page installer-page">
<main class="auth-shell installer-shell">
    <section class="auth-brand-panel">
        <a class="auth-logo" href="/"><span class="brand"><span class="brand-mark"><img src="/assets/img/logo.svg" alt=""></span><span class="brand-word">55AK <b>Trade</b></span></span></a>
        <div class="auth-brand-copy"><span class="eyebrow">Secure setup</span><h1>Your private trading platform.</h1><p>Connect MySQL, create the first administrator, and prepare the complete database in one step.</p></div>
        <ul class="auth-proof"><li>PHP 7.4+ compatible</li><li>Server-authoritative trade settlement</li><li>CSRF, secure sessions, and audit logs</li></ul>
    </section>
    <section class="auth-form-panel">
        <div class="auth-form-wrap installer-form-wrap">
            <?php if ($success): ?>
                <span class="success-mark">✓</span><span class="eyebrow">Installation complete</span><h2>55AK Trade is ready</h2><p class="form-intro">The installer is now locked. Sign in with the administrator email and password you just created.</p><a class="button button--primary button--wide" href="/admin/">Open admin panel</a><a class="button button--ghost button--wide" href="/en/trade/">Open trading platform</a>
            <?php elseif ($installed): ?>
                <span class="success-mark">✓</span><span class="eyebrow">Already installed</span><h2>Setup is locked</h2><p class="form-intro">This protects the database configuration from being overwritten.</p><a class="button button--primary button--wide" href="/admin/">Open admin panel</a>
            <?php else: ?>
                <span class="eyebrow">Step 1 of 1</span><h2>Install the platform</h2><p class="form-intro">Use the MySQL database and user created in cPanel.</p>
                <?php if ($errors): ?><div class="form-errors"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['_install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="field-grid field-grid--3"><label class="field"><span>DB host</span><input name="db_host" value="<?= htmlspecialchars($values['db_host'], ENT_QUOTES, 'UTF-8') ?>" required></label><label class="field"><span>Port</span><input name="db_port" inputmode="numeric" value="<?= htmlspecialchars($values['db_port'], ENT_QUOTES, 'UTF-8') ?>" required></label><label class="field field--wide"><span>Database name</span><input name="db_name" value="<?= htmlspecialchars($values['db_name'], ENT_QUOTES, 'UTF-8') ?>" required></label></div>
                    <div class="field-grid"><label class="field"><span>DB username</span><input name="db_user" value="<?= htmlspecialchars($values['db_user'], ENT_QUOTES, 'UTF-8') ?>" required></label><label class="field"><span>DB password</span><input type="password" name="db_password" autocomplete="new-password" placeholder="Preconfigured — leave blank to use saved deployment password"></label></div>
                    <label class="field"><span>Platform URL</span><input type="url" name="site_url" value="<?= htmlspecialchars($values['site_url'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                    <hr class="form-rule">
                    <div class="field-grid"><label class="field"><span>Admin name</span><input name="admin_name" value="<?= htmlspecialchars($values['admin_name'], ENT_QUOTES, 'UTF-8') ?>" required></label><label class="field"><span>Admin email</span><input type="email" name="admin_email" value="<?= htmlspecialchars($values['admin_email'], ENT_QUOTES, 'UTF-8') ?>" required></label></div>
                    <label class="field"><span>Admin password</span><input type="password" name="admin_password" autocomplete="new-password" minlength="12" required><small>12+ characters with a letter, number, and symbol.</small></label>
                    <button class="button button--primary button--wide" type="submit">Install 55AK Trade</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
