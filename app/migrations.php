<?php
declare(strict_types=1);

function migration_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function migration_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!migration_has_column($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

function migration_has_index(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function migration_add_index(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!migration_has_index($pdo, $table, $index)) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition);
    }
}

function migration_acquire_lock(PDO $pdo): array
{
    // A cPanel installation normally runs on one server, so an OS file lock is
    // the most compatible option and needs no special MySQL function access.
    $path = BASE_PATH . '/storage/cache/schema-migration.lock';
    $handle = @fopen($path, 'c');
    if (is_resource($handle)) {
        if (@flock($handle, LOCK_EX)) {
            return ['type' => 'file', 'handle' => $handle];
        }
        @fclose($handle);
    }

    // Fall back to a connection-scoped database lock when storage permissions
    // do not allow a file lock.
    try {
        $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = '55ak_schema_' . substr(hash('sha256', $databaseName), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 15)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() === 1) {
            return ['type' => 'database', 'name' => $lockName];
        }
    } catch (Throwable $ignored) {
    }

    throw new RuntimeException('The database update lock is unavailable. Check storage/cache permissions and refresh.');
}

function migration_release_lock(PDO $pdo, array $lock): void
{
    if (($lock['type'] ?? '') === 'file' && isset($lock['handle']) && is_resource($lock['handle'])) {
        @flock($lock['handle'], LOCK_UN);
        @fclose($lock['handle']);
        return;
    }
    if (($lock['type'] ?? '') === 'database' && !empty($lock['name'])) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([(string)$lock['name']]);
        } catch (Throwable $ignored) {
            // The connection automatically releases its named locks on close.
        }
    }
}

function run_auto_migrations(): void
{
    $targetVersion = '4.9.8';
    $flagFile = BASE_PATH . '/storage/cache/schema-' . $targetVersion . '.flag';
    if (file_exists($flagFile)) {
        return;
    }

    $pdo = db();
    foreach ([BASE_PATH . '/storage/cache', BASE_PATH . '/storage/logs', BASE_PATH . '/storage/payment-proofs', BASE_PATH . '/storage/payment-methods', BASE_PATH . '/storage/avatars', BASE_PATH . '/storage/announcements', BASE_PATH . '/storage/site-assets'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not prepare protected upload storage.');
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(40) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='currencies' OR LOWER(category)='currency' OR category LIKE '%currenc%') AND symbol NOT IN ('EURUSD','USDJPY','AUDUSD','USDCAD')");
    $pdo->exec("UPDATE market_assets SET is_active=0 WHERE symbol='GBPUSD'");
    $pdo->exec("UPDATE market_assets SET is_active=1, price_source='binance', api_symbol='EURUSDT' WHERE symbol='EURUSD'");
    $pdo->exec("UPDATE market_assets SET is_active=1, price_source='binance', api_symbol='AUDUSDT' WHERE symbol='AUDUSD'");
    $pdo->exec("UPDATE market_assets SET is_active=1, price_source='internal', api_symbol='USD/JPY' WHERE symbol='USDJPY'");
    $pdo->exec("UPDATE market_assets SET is_active=1, price_source='internal', api_symbol='USD/CAD' WHERE symbol='USDCAD'");
    $pdo->exec("DELETE FROM market_assets WHERE LOWER(category)='crypto' AND symbol NOT IN ('BTCUSD','ETHUSD','SOLUSD','BNBUSD','XRPUSD','DOGEUSD')");
    $pdo->exec("UPDATE market_assets SET is_active=1, category='Crypto' WHERE symbol IN ('BTCUSD','ETHUSD','SOLUSD','BNBUSD','XRPUSD','DOGEUSD')");
    $pdo->exec("UPDATE market_assets SET price_source='binance' WHERE LOWER(category)='crypto'");
    $pdo->exec("UPDATE market_assets SET api_symbol=CONCAT(REPLACE(symbol, 'USD', ''), 'USDT') WHERE LOWER(category)='crypto' AND (api_symbol IS NULL OR api_symbol='' OR api_symbol=symbol)");
    $pdo->exec("UPDATE market_assets SET is_active=0 WHERE (LOWER(category)='commodities' OR LOWER(category)='commodity' OR category LIKE '%commodit%') AND symbol NOT IN ('XAUUSD','XAUTUSD')");
    $pdo->exec("UPDATE market_assets SET is_active=1 WHERE symbol IN ('XAUUSD','XAUTUSD')");
    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
    $check->execute([$targetVersion]);
    if ((int)$check->fetchColumn() > 0) {
        @file_put_contents($flagFile, date('c'));
        return;
    }

    // Apache can start several PHP requests together immediately after a
    // deployment. Serialize the upgrade so two requests cannot both inspect
    // a missing column and then race to add it.
    $migrationLock = migration_acquire_lock($pdo);

    try {
        // Always check again after taking the lock: the request that held it
        // before us may already have completed the migration.
        $check->execute([$targetVersion]);
        if ((int)$check->fetchColumn() > 0) {
            return;
        }

        $baseCheck = $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version IN ('4.1.0','4.1.2','4.2.0','4.3.0','4.3.1','4.4.0')");
        $baseMigrationComplete = (int)$baseCheck->fetchColumn() > 0;

    // Ensure market_assets has price_source and api_symbol columns and supports binance
    migration_add_column($pdo, 'market_assets', 'price_source', "ENUM('internal','manual','api','binance') NOT NULL DEFAULT 'binance' AFTER `market_seed`");
    migration_add_column($pdo, 'market_assets', 'api_symbol', 'VARCHAR(60) NULL AFTER `price_source`');
    try {
        $pdo->exec("ALTER TABLE market_assets MODIFY price_source ENUM('internal','manual','api','binance') NOT NULL DEFAULT 'binance'");
    } catch (Throwable $e) {}

    migration_add_column($pdo, 'users', 'force_password_change', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `country`');
    migration_add_column($pdo, 'users', 'email_verified_at', 'DATETIME NULL AFTER `force_password_change`');
    migration_add_column($pdo, 'users', 'onboarding_completed_at', 'DATETIME NULL AFTER `email_verified_at`');
    migration_add_column($pdo, 'users', 'verification_status', "ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified' AFTER `onboarding_completed_at`");
    migration_add_column($pdo, 'users', 'verification_submitted_at', 'DATETIME NULL AFTER `verification_status`');
    migration_add_column($pdo, 'users', 'verification_reviewed_at', 'DATETIME NULL AFTER `verification_submitted_at`');
    migration_add_column($pdo, 'users', 'verification_note', 'VARCHAR(500) NULL AFTER `verification_reviewed_at`');
    migration_add_column($pdo, 'users', 'last_login_at', 'DATETIME NULL AFTER `email_verified_at`');
    migration_add_column($pdo, 'users', 'last_login_ip', 'VARCHAR(45) NULL AFTER `last_login_at`');
    migration_add_column($pdo, 'users', 'phone', 'VARCHAR(30) NULL AFTER `country`');
    migration_add_column($pdo, 'users', 'nickname', 'VARCHAR(80) NULL AFTER `phone`');
    migration_add_column($pdo, 'users', 'date_of_birth', 'DATE NULL AFTER `nickname`');
    migration_add_column($pdo, 'users', 'avatar_filename', 'VARCHAR(190) NULL AFTER `date_of_birth`');

    // The full 4.1 migration contains enum/table upgrades. Do not repeat those
    // ALTER statements on an installation that already completed 4.1.0; patch
    // releases only need the safe login/profile repair above.
    if (!$baseMigrationComplete) {
    migration_add_column($pdo, 'market_assets', 'price_source', "ENUM('internal','manual','api','binance') NOT NULL DEFAULT 'binance' AFTER `market_seed`");
    migration_add_column($pdo, 'market_assets', 'api_symbol', 'VARCHAR(60) NULL AFTER `price_source`');

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(50) NOT NULL, name VARCHAR(80) NOT NULL,
        category ENUM('upi','bank','wallet','crypto','other') NOT NULL DEFAULT 'other', icon_key VARCHAR(30) NOT NULL DEFAULT 'wallet',
        account_name VARCHAR(120) NULL, account_identifier VARCHAR(255) NULL, network VARCHAR(60) NULL, instructions TEXT NULL,
        qr_filename VARCHAR(190) NULL, minimum_amount DECIMAL(18,2) NOT NULL DEFAULT 1.00, maximum_amount DECIMAL(18,2) NOT NULL DEFAULT 100000.00,
        fee_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000, fee_fixed DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        deposit_enabled TINYINT(1) NOT NULL DEFAULT 1, withdrawal_enabled TINYINT(1) NOT NULL DEFAULT 0,
        require_reference TINYINT(1) NOT NULL DEFAULT 1, require_proof TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY payment_methods_code_unique (code),
        KEY payment_methods_active_sort_index (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS promo_codes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, code VARCHAR(40) NOT NULL, title VARCHAR(100) NOT NULL,
        code_type ENUM('gift','deposit_bonus') NOT NULL DEFAULT 'gift', value_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
        value_amount DECIMAL(18,2) NOT NULL, minimum_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00, maximum_bonus DECIMAL(18,2) NULL,
        usage_limit INT UNSIGNED NULL, per_user_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1, usage_count INT UNSIGNED NOT NULL DEFAULT 0,
        starts_at DATETIME NULL, expires_at DATETIME NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY promo_codes_code_unique (code),
        KEY promo_codes_active_dates_index (is_active, starts_at, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migration_add_column($pdo, 'fund_requests', 'payment_method_id', 'INT UNSIGNED NULL AFTER `status`');
    migration_add_column($pdo, 'fund_requests', 'promo_code_id', 'INT UNSIGNED NULL AFTER `payment_method_id`');
    migration_add_column($pdo, 'fund_requests', 'user_reference', 'VARCHAR(120) NULL AFTER `promo_code_id`');
    migration_add_column($pdo, 'fund_requests', 'proof_filename', 'VARCHAR(190) NULL AFTER `user_reference`');
    migration_add_column($pdo, 'fund_requests', 'destination_json', 'TEXT NULL AFTER `proof_filename`');
    migration_add_column($pdo, 'fund_requests', 'provider_reference', 'VARCHAR(120) NULL AFTER `destination_json`');
    migration_add_column($pdo, 'fund_requests', 'balance_reserved', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `provider_reference`');
    migration_add_column($pdo, 'fund_requests', 'bonus_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `balance_reserved`');
    migration_add_column($pdo, 'fund_requests', 'fee_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `bonus_amount`');
    migration_add_column($pdo, 'fund_requests', 'paid_at', 'DATETIME NULL AFTER `reviewed_at`');
    $pdo->exec("ALTER TABLE fund_requests MODIFY status ENUM('pending','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE wallet_transactions MODIFY transaction_type ENUM('signup_bonus','trade_stake','trade_win','trade_refund','deposit_credit','deposit_bonus','withdrawal_hold','withdrawal_refund','withdrawal_paid','gift_code','admin_adjustment','demo_deposit','demo_withdrawal') NOT NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS promo_redemptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, promo_code_id INT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
        fund_request_id BIGINT UNSIGNED NULL, credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        status ENUM('reserved','credited','cancelled') NOT NULL DEFAULT 'credited', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (id), KEY promo_redemptions_user_code_index (user_id, promo_code_id), KEY promo_redemptions_request_index (fund_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, public_id VARCHAR(32) NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
        category ENUM('account','payment','trade','technical','other') NOT NULL DEFAULT 'other', subject VARCHAR(140) NOT NULL,
        message TEXT NOT NULL, status ENUM('open','answered','closed') NOT NULL DEFAULT 'open', admin_response TEXT NULL,
        assigned_to BIGINT UNSIGNED NULL, answered_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (id), UNIQUE KEY support_tickets_public_id_unique (public_id), KEY support_tickets_user_status_index (user_id, status),
        KEY support_tickets_status_created_index (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS market_price_cache (
        asset_id INT UNSIGNED NOT NULL, price DECIMAL(20,8) NOT NULL, source VARCHAR(30) NOT NULL, fetched_at DATETIME NOT NULL,
        raw_response_hash CHAR(64) NULL, PRIMARY KEY (asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $settings = [
        ['wallet_currency', 'USD', 'string', 'wallet', 1], ['deposits_enabled', '1', 'boolean', 'wallet', 1],
        ['withdrawals_enabled', '1', 'boolean', 'wallet', 1], ['payment_proof_max_mb', '5', 'number', 'wallet', 0],
        ['market_data_mode', 'internal', 'string', 'trading', 1], ['market_api_url', '', 'string', 'trading', 0],
        ['market_api_key', '', 'string', 'trading', 0], ['market_api_price_path', 'price', 'string', 'trading', 0],
    ];
    $settingStmt = $pdo->prepare('INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, group_name, is_public, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($settings as $row) {
        $settingStmt->execute($row);
    }

    $methods = [
        ['upi','UPI','upi','upi',null,'Pay to the configured UPI ID or QR and enter the UTR/reference number.',1,1,1,1,10],
        ['phonepe','PhonePe','wallet','phonepe',null,'Complete the payment and submit its transaction reference.',1,1,1,1,20],
        ['paytm','Paytm','wallet','paytm',null,'Complete the payment and submit its transaction reference.',1,1,1,1,30],
        ['binance-pay','Binance Pay','crypto','binance','Binance Pay','Send only to the configured Binance Pay ID and submit the order ID.',1,1,1,0,40],
        ['usdt-trc20','USDT (TRC-20)','crypto','usdt','TRC-20','Send USDT only on TRC-20 and submit the transaction hash.',1,1,1,0,50],
        ['usdt-bep20','USDT (BEP-20)','crypto','usdt','BEP-20','Send USDT only on BEP-20 and submit the transaction hash.',1,1,1,0,60],
    ];
    $methodStmt = $pdo->prepare("INSERT IGNORE INTO payment_methods (code,name,category,icon_key,network,instructions,minimum_amount,maximum_amount,deposit_enabled,withdrawal_enabled,require_reference,require_proof,is_active,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,10,100000,?,?,?,?,1,?,NOW(),NOW())");
    foreach ($methods as $method) {
        $methodStmt->execute($method);
    }
    }

        // 4.3.0: split the real wallet from the practice trading account and
        // tag every trade/ledger entry with its source account. Existing data
        // remains Live by default, so an upgrade never changes financial history.
        migration_add_column($pdo, 'users', 'demo_trade_balance', 'DECIMAL(18,2) NOT NULL DEFAULT 1000.00 AFTER `demo_balance`');
        migration_add_column($pdo, 'users', 'trade_account_type', "ENUM('live','demo') NOT NULL DEFAULT 'live' AFTER `demo_trade_balance`");
        migration_add_column($pdo, 'trades', 'account_type', "ENUM('live','demo') NOT NULL DEFAULT 'live' AFTER `asset_id`");
        migration_add_column($pdo, 'trades', 'client_request_id', 'VARCHAR(64) NULL AFTER `account_type`');
        migration_add_index($pdo, 'trades', 'trades_user_client_request_unique', 'UNIQUE KEY `trades_user_client_request_unique` (`user_id`,`client_request_id`)');
        migration_add_column($pdo, 'wallet_transactions', 'account_type', "ENUM('live','demo') NOT NULL DEFAULT 'live' AFTER `user_id`");
        migration_add_column($pdo, 'announcements', 'image_filename', 'VARCHAR(190) NULL AFTER `message`');

        $v43Settings = [
            ['practice_starting_balance', '1000', 'number', 'trading', 1],
            ['demo_trading_enabled', '1', 'boolean', 'trading', 1],
        ];
        $v43SettingStmt = $pdo->prepare('INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, group_name, is_public, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
        foreach ($v43Settings as $row) {
            $v43SettingStmt->execute($row);
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS agents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NOT NULL,
            referral_code VARCHAR(32) NOT NULL,
            commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status ENUM('active','blocked') NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY agents_email_unique (email),
            UNIQUE KEY agents_referral_code_unique (referral_code),
            KEY agents_status_created_index (status, created_at),
            CONSTRAINT agents_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_referrals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY agent_referrals_user_unique (user_id),
            KEY agent_referrals_agent_created_index (agent_id, created_at),
            CONSTRAINT agent_referrals_agent_fk FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE RESTRICT,
            CONSTRAINT agent_referrals_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            title VARCHAR(120) NOT NULL,
            message TEXT NOT NULL,
            level ENUM('info','success','warning') NOT NULL DEFAULT 'info',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_notifications_target_active_index (user_id, is_active, created_at),
            CONSTRAINT user_notifications_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT user_notifications_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $v44Settings = [
            ['logo_filename', '', 'string', 'general', 0],
        ];
        foreach ($v44Settings as $row) {
            $v43SettingStmt->execute($row);
        }

        // 4.4.1: practice accounts start at $1,000. Preserve any administrator
        // custom balance/setting, while normalising only the former factory
        // default and empty practice wallets from pre-patch installs.
        $pdo->exec('ALTER TABLE users MODIFY `demo_trade_balance` DECIMAL(18,2) NOT NULL DEFAULT 1000.00 AFTER `demo_balance`');
        $practiceSetting = $pdo->prepare("UPDATE site_settings SET setting_value='1000',updated_at=NOW() WHERE setting_key='practice_starting_balance' AND CAST(setting_value AS DECIMAL(18,2))=10000.00");
        $practiceSetting->execute();
        $pdo->exec("UPDATE users SET demo_trade_balance=1000.00,updated_at=NOW() WHERE role='user' AND demo_trade_balance IN (0.00,10000.00)");

        // 4.3.1: one-time password reset tokens. Tokens are stored only as a
        // SHA-256 hash, expire after 30 minutes, and are invalidated on use.
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            requested_ip VARCHAR(45) NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY password_reset_tokens_hash_unique (token_hash),
            KEY password_reset_tokens_user_created_index (user_id, created_at),
            KEY password_reset_tokens_ip_created_index (requested_ip, created_at),
            KEY password_reset_tokens_expiry_index (expires_at, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.4.5: deposit requests now begin as time-limited payment orders.
        // Only a user-confirmed payment enters the administrator queue.
        migration_add_column($pdo, 'fund_requests', 'payment_expires_at', 'DATETIME NULL AFTER `paid_at`');
        migration_add_column($pdo, 'fund_requests', 'payment_confirmed_at', 'DATETIME NULL AFTER `payment_expires_at`');
        $pdo->exec("ALTER TABLE fund_requests MODIFY status ENUM('awaiting_payment','pending','approved','paid','rejected','cancelled','expired') NOT NULL DEFAULT 'pending'");
        migration_add_index($pdo, 'fund_requests', 'fund_requests_payment_expiry_index', 'KEY `fund_requests_payment_expiry_index` (`status`,`payment_expires_at`)');

        migration_add_column($pdo, 'support_tickets', 'fund_request_id', 'BIGINT UNSIGNED NULL AFTER `user_id`');
        migration_add_index($pdo, 'support_tickets', 'support_tickets_fund_request_index', 'KEY `support_tickets_fund_request_index` (`fund_request_id`)');

        $paymentOrderSetting = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key,setting_value,setting_type,group_name,is_public,updated_at) VALUES ('payment_order_minutes','15','number','wallet',1,NOW())");
        $paymentOrderSetting->execute();

        // 4.4.6: Affiliate is automatically available to every user. Rewards
        // are credited once, only after the referred user's first qualifying
        // deposit is approved by an administrator.
        migration_add_column($pdo, 'agents', 'user_id', 'BIGINT UNSIGNED NULL AFTER `id`');
        migration_add_index($pdo, 'agents', 'agents_user_unique', 'UNIQUE KEY `agents_user_unique` (`user_id`)');
        migration_add_column($pdo, 'agent_referrals', 'reward_status', "ENUM('pending','credited','cancelled') NOT NULL DEFAULT 'pending' AFTER `user_id`");
        migration_add_column($pdo, 'agent_referrals', 'qualified_fund_request_id', 'BIGINT UNSIGNED NULL AFTER `reward_status`');
        migration_add_column($pdo, 'agent_referrals', 'reward_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `qualified_fund_request_id`');
        migration_add_column($pdo, 'agent_referrals', 'credited_at', 'DATETIME NULL AFTER `reward_amount`');
        migration_add_index($pdo, 'agent_referrals', 'agent_referrals_reward_status_index', 'KEY `agent_referrals_reward_status_index` (`agent_id`,`reward_status`,`created_at`)');
        migration_add_index($pdo, 'agent_referrals', 'agent_referrals_qualified_request_unique', 'UNIQUE KEY `agent_referrals_qualified_request_unique` (`qualified_fund_request_id`)');
        $pdo->exec("ALTER TABLE wallet_transactions MODIFY transaction_type ENUM('signup_bonus','trade_stake','trade_win','trade_refund','deposit_credit','deposit_bonus','referral_bonus','withdrawal_hold','withdrawal_refund','withdrawal_paid','gift_code','admin_adjustment','demo_deposit','demo_withdrawal') NOT NULL");

        $referralSettings = [
            ['referral_program_enabled', '1', 'boolean', 'affiliate', 1],
            ['referral_reward_amount', '5', 'number', 'affiliate', 1],
            ['referral_minimum_deposit', '10', 'number', 'affiliate', 1],
        ];
        foreach ($referralSettings as $row) {
            $v43SettingStmt->execute($row);
        }

        // Link legacy email-based agents, then create an active code for every
        // existing user that does not have one. AK + base36(user id) remains
        // deterministic; an unlikely legacy code collision is repaired lazily
        // by ensure_user_affiliate() on the user's first Affiliate visit.
        $pdo->exec("UPDATE agents a JOIN users u ON LOWER(u.email)=LOWER(a.email) AND u.role='user' SET a.user_id=u.id,a.updated_at=NOW() WHERE a.user_id IS NULL");
        $pdo->exec("INSERT IGNORE INTO agents (user_id,name,email,referral_code,commission_percent,status,created_by,created_at,updated_at) SELECT u.id,u.name,u.email,CONCAT('AK',LPAD(UPPER(CONV(u.id,10,36)),6,'0')),0,'active',NULL,NOW(),NOW() FROM users u LEFT JOIN agents a ON a.user_id=u.id WHERE u.role='user' AND a.id IS NULL");
        $pdo->exec("UPDATE agents a JOIN users u ON LOWER(u.email)=LOWER(a.email) AND u.role='user' SET a.user_id=u.id,a.updated_at=NOW() WHERE a.user_id IS NULL");

        // Configure only empty factory fields. Existing administrator-provided
        // payment IDs and QR images are deliberately preserved on upgrade.
        $pdo->exec("UPDATE payment_methods SET account_name=IF(TRIM(COALESCE(account_name,''))='','55AK Trade',account_name),account_identifier=IF(TRIM(COALESCE(account_identifier,''))='','deepansu@ybl',account_identifier),network=IF(TRIM(COALESCE(network,''))='','UPI',network),qr_filename=IF(TRIM(COALESCE(qr_filename,''))='','upi-deepansu-ybl.png',qr_filename),instructions=IF(TRIM(COALESCE(instructions,''))='','Pay to the displayed UPI ID or QR, then submit the successful UTR. Never share a UPI PIN or OTP.',instructions),updated_at=NOW() WHERE code='upi'");
        $pdo->exec("UPDATE payment_methods SET account_name=IF(TRIM(COALESCE(account_name,''))='','55AK Trade',account_name),account_identifier=IF(TRIM(COALESCE(account_identifier,''))='','deepansu@ybl',account_identifier),network=IF(TRIM(COALESCE(network,''))='','UPI / PhonePe',network),qr_filename=IF(TRIM(COALESCE(qr_filename,''))='','upi-deepansu-ybl.png',qr_filename),instructions=IF(TRIM(COALESCE(instructions,''))='','Pay through PhonePe to the displayed UPI ID or QR, then submit the successful UTR. Never share a UPI PIN or OTP.',instructions),updated_at=NOW() WHERE code='phonepe'");
        $pdo->exec("UPDATE payment_methods SET account_name=IF(TRIM(COALESCE(account_name,''))='','55AK Trade',account_name),account_identifier=IF(TRIM(COALESCE(account_identifier,''))='','234543334',account_identifier),network=IF(TRIM(COALESCE(network,''))='','Binance Pay',network),qr_filename=IF(TRIM(COALESCE(qr_filename,''))='','binance-pay-234543334.png',qr_filename),instructions=IF(TRIM(COALESCE(instructions,''))='','Send through Binance Pay to the displayed Pay ID, then submit the Binance order ID.',instructions),updated_at=NOW() WHERE code='binance-pay'");

        // 4.5.0: persistent onboarding, identity-review state, dense chart
        // controls and audited account-currency exchange.
        $pdo->exec("CREATE TABLE IF NOT EXISTS currency_exchanges (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            from_currency CHAR(3) NOT NULL,
            to_currency CHAR(3) NOT NULL,
            exchange_rate DECIMAL(24,10) NOT NULL,
            fee_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000,
            fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            live_balance_before DECIMAL(18,2) NOT NULL,
            live_balance_after DECIMAL(18,2) NOT NULL,
            demo_balance_before DECIMAL(18,2) NOT NULL,
            demo_balance_after DECIMAL(18,2) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY currency_exchanges_user_created_index (user_id, created_at),
            CONSTRAINT currency_exchanges_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $v45Settings = [
            ['chart_history_candles', '140', 'number', 'trading', 1],
            ['chart_tick_density', '10', 'number', 'trading', 1],
            ['chart_quote_interval_ms', '650', 'number', 'trading', 1],
            ['currency_exchange_enabled', '1', 'boolean', 'wallet', 1],
            ['currency_exchange_fee_percent', '3', 'number', 'wallet', 1],
            ['currency_exchange_rates', '{"USD":1,"INR":83,"EUR":0.92,"GBP":0.72}', 'json', 'wallet', 0],
        ];
        foreach ($v45Settings as $row) {
            $v43SettingStmt->execute($row);
        }

        // 4.6.0: admin trading control — per-asset / per-user direction and win-rate overrides.
        $pdo->exec("CREATE TABLE IF NOT EXISTS trading_controls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_id INT UNSIGNED NULL COMMENT 'NULL = all assets',
            user_id BIGINT UNSIGNED NULL COMMENT 'NULL = all users',
            direction ENUM('up','down','none') NOT NULL DEFAULT 'none' COMMENT 'Forced trade direction; none = natural price',
            win_rate DECIMAL(5,2) NULL COMMENT 'NULL = natural settlement; 0-100 forced win probability',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            label VARCHAR(120) NULL COMMENT 'Optional admin note',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY trading_controls_active_index (is_active, expires_at),
            KEY trading_controls_asset_user_index (asset_id, user_id),
            CONSTRAINT trading_controls_asset_fk FOREIGN KEY (asset_id) REFERENCES market_assets (id) ON DELETE CASCADE,
            CONSTRAINT trading_controls_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT trading_controls_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4.7.0: per-user payout override and trading block sessions.
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_trading_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            asset_id INT UNSIGNED NULL COMMENT 'NULL = all assets',
            payout_override DECIMAL(5,2) NULL COMMENT 'Override payout % for this user (NULL = use asset default)',
            is_blocked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = block this user from placing new trades',
            block_reason VARCHAR(255) NULL,
            expires_at DATETIME NULL COMMENT 'NULL = permanent until manually removed',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY uts_user_asset_index (user_id, asset_id),
            KEY uts_active_index (is_blocked, expires_at),
            CONSTRAINT uts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT uts_asset_fk FOREIGN KEY (asset_id) REFERENCES market_assets (id) ON DELETE CASCADE,
            CONSTRAINT uts_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Self-healing: Ensure only core 6 Crypto assets exist in production DB
        try {
            $pdo->exec("DELETE FROM market_assets WHERE LOWER(category)='crypto' AND symbol NOT IN ('BTCUSD','ETHUSD','SOLUSD','BNBUSD','XRPUSD','DOGEUSD')");
            $pdo->exec("UPDATE market_assets SET is_active=1, category='Crypto' WHERE symbol IN ('BTCUSD','ETHUSD','SOLUSD','BNBUSD','XRPUSD','DOGEUSD')");
        } catch (Throwable $e) {}

        $insert = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES (?, NOW())');
        $insert->execute(['4.6.0']);
        $insert->execute(['4.7.0']);
        $insert->execute(['4.8.0']);
        $insert->execute(['4.9.0']);
        @file_put_contents($flagFile, date('c'));
    } finally {
        migration_release_lock($pdo, $migrationLock);
    }
}
