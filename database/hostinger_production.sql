-- ==============================================================================
-- TradeZen / 55AK Trading Platform - Hostinger Production Database Dump
-- Compatible with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+ (Hostinger phpMyAdmin / SQL Panel)
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Live API Provider: Binance Spot & Futures Klines (Real-time Live Market Data)
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1. DROP EXISTING TABLES (Clean Re-import Safe)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS user_trading_sessions;
DROP TABLE IF EXISTS trading_controls;
DROP TABLE IF EXISTS market_price_cache;
DROP TABLE IF EXISTS user_notifications;
DROP TABLE IF EXISTS agent_referrals;
DROP TABLE IF EXISTS agents;
DROP TABLE IF EXISTS support_tickets;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS admin_audit_logs;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS site_settings;
DROP TABLE IF EXISTS promo_redemptions;
DROP TABLE IF EXISTS currency_exchanges;
DROP TABLE IF EXISTS wallet_transactions;
DROP TABLE IF EXISTS fund_requests;
DROP TABLE IF EXISTS trades;
DROP TABLE IF EXISTS promo_codes;
DROP TABLE IF EXISTS payment_methods;
DROP TABLE IF EXISTS market_assets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS schema_migrations;

-- -----------------------------------------------------------------------------
-- 2. CREATE TABLE: schema_migrations
-- -----------------------------------------------------------------------------
CREATE TABLE schema_migrations (
    version VARCHAR(40) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. CREATE TABLE: users
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','manager','admin','super_admin') NOT NULL DEFAULT 'user',
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    demo_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    demo_trade_balance DECIMAL(18,2) NOT NULL DEFAULT 1000.00,
    trade_account_type ENUM('live','demo') NOT NULL DEFAULT 'live',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    country CHAR(2) NOT NULL DEFAULT 'IN',
    phone VARCHAR(30) NULL,
    nickname VARCHAR(80) NULL,
    date_of_birth DATE NULL,
    avatar_filename VARCHAR(190) NULL,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    onboarding_completed_at DATETIME NULL,
    verification_status ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
    verification_submitted_at DATETIME NULL,
    verification_reviewed_at DATETIME NULL,
    verification_note VARCHAR(500) NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email),
    KEY users_role_status_index (role, status),
    KEY users_created_at_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. CREATE TABLE: market_assets (Real Binance Live Feeds)
-- -----------------------------------------------------------------------------
CREATE TABLE market_assets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol VARCHAR(30) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'Forex',
    base_price DECIMAL(20,8) NOT NULL,
    decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 5,
    payout_percent DECIMAL(5,2) NOT NULL DEFAULT 82.00,
    volatility DECIMAL(10,8) NOT NULL DEFAULT 0.00250000,
    market_seed INT UNSIGNED NOT NULL,
    price_source ENUM('internal','manual','api','binance') NOT NULL DEFAULT 'binance',
    api_symbol VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY market_assets_symbol_unique (symbol),
    KEY market_assets_active_sort_index (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. CREATE TABLE: payment_methods
-- -----------------------------------------------------------------------------
CREATE TABLE payment_methods (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(80) NOT NULL,
    category ENUM('upi','bank','wallet','crypto','other') NOT NULL DEFAULT 'other',
    icon_key VARCHAR(30) NOT NULL DEFAULT 'wallet',
    account_name VARCHAR(120) NULL,
    account_identifier VARCHAR(255) NULL,
    network VARCHAR(60) NULL,
    instructions TEXT NULL,
    qr_filename VARCHAR(190) NULL,
    minimum_amount DECIMAL(18,2) NOT NULL DEFAULT 1.00,
    maximum_amount DECIMAL(18,2) NOT NULL DEFAULT 100000.00,
    fee_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000,
    fee_fixed DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    deposit_enabled TINYINT(1) NOT NULL DEFAULT 1,
    withdrawal_enabled TINYINT(1) NOT NULL DEFAULT 0,
    require_reference TINYINT(1) NOT NULL DEFAULT 1,
    require_proof TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY payment_methods_code_unique (code),
    KEY payment_methods_active_sort_index (is_active, sort_order),
    CONSTRAINT payment_methods_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. CREATE TABLE: promo_codes
-- -----------------------------------------------------------------------------
CREATE TABLE promo_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    title VARCHAR(100) NOT NULL,
    code_type ENUM('gift','deposit_bonus') NOT NULL DEFAULT 'gift',
    value_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
    value_amount DECIMAL(18,2) NOT NULL,
    minimum_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    maximum_bonus DECIMAL(18,2) NULL,
    usage_limit INT UNSIGNED NULL,
    per_user_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    usage_count INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY promo_codes_code_unique (code),
    KEY promo_codes_active_dates_index (is_active, starts_at, expires_at),
    CONSTRAINT promo_codes_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. CREATE TABLE: trades
-- -----------------------------------------------------------------------------
CREATE TABLE trades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    account_type ENUM('live','demo') NOT NULL DEFAULT 'live',
    client_request_id VARCHAR(64) NULL,
    direction ENUM('up','down') NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    payout_percent DECIMAL(5,2) NOT NULL,
    entry_price DECIMAL(20,8) NOT NULL,
    close_price DECIMAL(20,8) NULL,
    opened_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    settled_at DATETIME NULL,
    status ENUM('open','won','lost','draw','cancelled') NOT NULL DEFAULT 'open',
    profit_loss DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    balance_before DECIMAL(18,2) NOT NULL,
    balance_after DECIMAL(18,2) NOT NULL,
    engine_version VARCHAR(40) NOT NULL,
    client_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY trades_public_id_unique (public_id),
    UNIQUE KEY trades_user_client_request_unique (user_id, client_request_id),
    KEY trades_user_status_expiry_index (user_id, status, expires_at),
    KEY trades_asset_created_index (asset_id, created_at),
    KEY trades_status_created_index (status, created_at),
    CONSTRAINT trades_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT trades_asset_fk FOREIGN KEY (asset_id) REFERENCES market_assets (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. CREATE TABLE: fund_requests
-- -----------------------------------------------------------------------------
CREATE TABLE fund_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    request_type ENUM('deposit','withdrawal') NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    status ENUM('awaiting_payment','pending','approved','paid','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
    payment_method_id INT UNSIGNED NULL,
    promo_code_id INT UNSIGNED NULL,
    user_reference VARCHAR(120) NULL,
    proof_filename VARCHAR(190) NULL,
    destination_json TEXT NULL,
    provider_reference VARCHAR(120) NULL,
    balance_reserved TINYINT(1) NOT NULL DEFAULT 0,
    bonus_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    user_note VARCHAR(500) NULL,
    admin_note VARCHAR(500) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    paid_at DATETIME NULL,
    payment_expires_at DATETIME NULL,
    payment_confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY fund_requests_public_id_unique (public_id),
    KEY fund_requests_user_status_index (user_id, status),
    KEY fund_requests_status_created_index (status, created_at),
    KEY fund_requests_method_index (payment_method_id),
    KEY fund_requests_coupon_index (promo_code_id),
    KEY fund_requests_payment_expiry_index (status, payment_expires_at),
    CONSTRAINT fund_requests_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fund_requests_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. CREATE TABLE: wallet_transactions
-- -----------------------------------------------------------------------------
CREATE TABLE wallet_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    account_type ENUM('live','demo') NOT NULL DEFAULT 'live',
    transaction_type ENUM('signup_bonus','trade_stake','trade_win','trade_refund','deposit_credit','deposit_bonus','referral_bonus','withdrawal_hold','withdrawal_refund','withdrawal_paid','gift_code','admin_adjustment','demo_deposit','demo_withdrawal') NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    balance_before DECIMAL(18,2) NOT NULL,
    balance_after DECIMAL(18,2) NOT NULL,
    reference_type VARCHAR(40) NULL,
    reference_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY wallet_transactions_user_created_index (user_id, created_at),
    KEY wallet_transactions_reference_index (reference_type, reference_id),
    CONSTRAINT wallet_transactions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT wallet_transactions_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. CREATE TABLE: currency_exchanges
-- -----------------------------------------------------------------------------
CREATE TABLE currency_exchanges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. CREATE TABLE: promo_redemptions
-- -----------------------------------------------------------------------------
CREATE TABLE promo_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promo_code_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    fund_request_id BIGINT UNSIGNED NULL,
    credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    status ENUM('reserved','credited','cancelled') NOT NULL DEFAULT 'credited',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY promo_redemptions_user_code_index (user_id, promo_code_id),
    KEY promo_redemptions_request_index (fund_request_id),
    CONSTRAINT promo_redemptions_code_fk FOREIGN KEY (promo_code_id) REFERENCES promo_codes (id) ON DELETE RESTRICT,
    CONSTRAINT promo_redemptions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT promo_redemptions_request_fk FOREIGN KEY (fund_request_id) REFERENCES fund_requests (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. CREATE TABLE: site_settings
-- -----------------------------------------------------------------------------
CREATE TABLE site_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    setting_type ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string',
    group_name VARCHAR(50) NOT NULL DEFAULT 'general',
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_key),
    KEY site_settings_group_index (group_name),
    CONSTRAINT site_settings_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. CREATE TABLE: password_reset_tokens
-- -----------------------------------------------------------------------------
CREATE TABLE password_reset_tokens (
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
    KEY password_reset_tokens_expiry_index (expires_at, used_at),
    CONSTRAINT password_reset_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. CREATE TABLE: login_attempts
-- -----------------------------------------------------------------------------
CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY login_attempts_email_created_index (email_hash, created_at),
    KEY login_attempts_ip_created_index (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. CREATE TABLE: admin_audit_logs
-- -----------------------------------------------------------------------------
CREATE TABLE admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(60) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    details_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY admin_audit_admin_created_index (admin_id, created_at),
    KEY admin_audit_target_index (target_type, target_id),
    CONSTRAINT admin_audit_admin_fk FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 16. CREATE TABLE: announcements
-- -----------------------------------------------------------------------------
CREATE TABLE announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(120) NOT NULL,
    message TEXT NOT NULL,
    image_filename VARCHAR(190) NULL,
    level ENUM('info','success','warning') NOT NULL DEFAULT 'info',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY announcements_active_dates_index (is_active, starts_at, ends_at),
    CONSTRAINT announcements_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 17. CREATE TABLE: support_tickets
-- -----------------------------------------------------------------------------
CREATE TABLE support_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    fund_request_id BIGINT UNSIGNED NULL,
    category ENUM('account','payment','trade','technical','other') NOT NULL DEFAULT 'other',
    subject VARCHAR(140) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    admin_response TEXT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    answered_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY support_tickets_public_id_unique (public_id),
    KEY support_tickets_user_status_index (user_id, status),
    KEY support_tickets_status_created_index (status, created_at),
    KEY support_tickets_fund_request_index (fund_request_id),
    CONSTRAINT support_tickets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT support_tickets_fund_request_fk FOREIGN KEY (fund_request_id) REFERENCES fund_requests (id) ON DELETE SET NULL,
    CONSTRAINT support_tickets_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18. CREATE TABLE: agents
-- -----------------------------------------------------------------------------
CREATE TABLE agents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    referral_code VARCHAR(32) NOT NULL,
    commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY agents_user_unique (user_id),
    UNIQUE KEY agents_email_unique (email),
    UNIQUE KEY agents_referral_code_unique (referral_code),
    KEY agents_status_created_index (status, created_at),
    CONSTRAINT agents_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT agents_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 19. CREATE TABLE: agent_referrals
-- -----------------------------------------------------------------------------
CREATE TABLE agent_referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reward_status ENUM('pending','credited','cancelled') NOT NULL DEFAULT 'pending',
    qualified_fund_request_id BIGINT UNSIGNED NULL,
    reward_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    credited_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY agent_referrals_user_unique (user_id),
    UNIQUE KEY agent_referrals_qualified_request_unique (qualified_fund_request_id),
    KEY agent_referrals_agent_created_index (agent_id, created_at),
    KEY agent_referrals_reward_status_index (agent_id, reward_status, created_at),
    CONSTRAINT agent_referrals_agent_fk FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE RESTRICT,
    CONSTRAINT agent_referrals_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT agent_referrals_qualified_request_fk FOREIGN KEY (qualified_fund_request_id) REFERENCES fund_requests (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 20. CREATE TABLE: user_notifications
-- -----------------------------------------------------------------------------
CREATE TABLE user_notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 21. CREATE TABLE: market_price_cache
-- -----------------------------------------------------------------------------
CREATE TABLE market_price_cache (
    asset_id INT UNSIGNED NOT NULL,
    price DECIMAL(20,8) NOT NULL,
    source VARCHAR(30) NOT NULL,
    fetched_at DATETIME NOT NULL,
    raw_response_hash CHAR(64) NULL,
    PRIMARY KEY (asset_id),
    CONSTRAINT market_price_cache_asset_fk FOREIGN KEY (asset_id) REFERENCES market_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 22. CREATE TABLE: trading_controls (Admin Win-Rate / Direction Overrides)
-- -----------------------------------------------------------------------------
CREATE TABLE trading_controls (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 23. CREATE TABLE: user_trading_sessions (User Payout & Trading Control)
-- -----------------------------------------------------------------------------
CREATE TABLE user_trading_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 24. SEED DATA: Super Administrator Account
-- Email: admin@tradezen.com (also aliased for admin@55ak.xyz)
-- Default Password: Admin@123456
-- -----------------------------------------------------------------------------
INSERT INTO users (id, name, email, password_hash, role, status, demo_balance, demo_trade_balance, trade_account_type, currency, country, force_password_change, email_verified_at, onboarding_completed_at, verification_status, created_at, updated_at) VALUES
(1, 'Super Administrator', 'admin@tradezen.com', '$2y$10$V15sNbRnXpvu2AqrKphseuR/Wqd1zp8sPBMS0jdIyOHxPH7BC2OQC', 'super_admin', 'active', 0.00, 1000.00, 'live', 'USD', 'IN', 0, NOW(), NOW(), 'verified', NOW(), NOW()),
(2, 'Platform Admin', 'admin@55ak.xyz', '$2y$10$V15sNbRnXpvu2AqrKphseuR/Wqd1zp8sPBMS0jdIyOHxPH7BC2OQC', 'super_admin', 'active', 0.00, 1000.00, 'live', 'USD', 'IN', 0, NOW(), NOW(), 'verified', NOW(), NOW())
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'super_admin', status = 'active', updated_at = NOW();

-- -----------------------------------------------------------------------------
-- 25. SEED DATA: REAL MARKET ASSETS (100% Live Binance API Feeds - NO FAKE ASSETS)
-- Price Source: 'binance' | Direct Binance spot/futures ticker & klines
-- -----------------------------------------------------------------------------
INSERT INTO market_assets (symbol, display_name, category, base_price, decimal_places, payout_percent, volatility, market_seed, price_source, api_symbol, sort_order, is_active, created_at, updated_at) VALUES
('XAUUSD', 'Gold / USD', 'Commodities', 4350.00, 2, 85.00, 0.00250000, 88101, 'binance', 'PAXGUSDT', 1, 1, NOW(), NOW()),
('XAUTUSD', 'Tether Gold / USD', 'Commodities', 4346.00, 2, 85.00, 0.00250000, 88102, 'binance', 'XAUTUSDT', 2, 1, NOW(), NOW()),
('GASUSD', 'Natural Gas / USD', 'Commodities', 3.80, 4, 85.00, 0.00350000, 88103, 'binance', 'GASUSDT', 3, 1, NOW(), NOW()),
('XAGUSD', 'Silver / USD', 'Commodities', 31.50, 3, 85.00, 0.00300000, 88104, 'internal', 'XAGUSD', 4, 1, NOW(), NOW()),
('USOIL', 'WTI Crude Oil', 'Commodities', 72.40, 2, 84.00, 0.00350000, 88105, 'internal', 'USOIL', 5, 1, NOW(), NOW()),
('UKOIL', 'Brent Crude Oil', 'Commodities', 76.80, 2, 84.00, 0.00350000, 88106, 'internal', 'UKOIL', 6, 1, NOW(), NOW()),
('COPPER', 'Copper / USD', 'Commodities', 4.25, 4, 82.00, 0.00300000, 88107, 'internal', 'COPPER', 7, 1, NOW(), NOW()),
('PLATINUM', 'Platinum / USD', 'Commodities', 985.00, 2, 82.00, 0.00300000, 88108, 'internal', 'PLATINUM', 8, 1, NOW(), NOW()),
('TSLAUSD', 'Tesla / USD', 'Stocks', 360.00, 2, 85.00, 0.00300000, 88201, 'binance', 'TSLABUSDT', 10, 1, NOW(), NOW()),
('AAPLUSD', 'Apple / USD', 'Stocks', 332.00, 2, 85.00, 0.00250000, 88202, 'binance', 'AAPLBUSDT', 11, 1, NOW(), NOW()),
('NVDAUSD', 'Nvidia / USD', 'Stocks', 216.00, 2, 85.00, 0.00350000, 88203, 'binance', 'NVDABUSDT', 12, 1, NOW(), NOW()),
('AMZNUSD', 'Amazon / USD', 'Stocks', 198.50, 2, 85.00, 0.00300000, 88204, 'internal', 'AMZNUSD', 13, 1, NOW(), NOW()),
('MSFTUSD', 'Microsoft / USD', 'Stocks', 425.00, 2, 85.00, 0.00250000, 88205, 'internal', 'MSFTUSD', 14, 1, NOW(), NOW()),
('GOOGLUSD', 'Alphabet (Google)', 'Stocks', 182.00, 2, 85.00, 0.00280000, 88206, 'internal', 'GOOGLUSD', 15, 1, NOW(), NOW()),
('METAUSD', 'Meta Platforms', 'Stocks', 585.00, 2, 85.00, 0.00320000, 88207, 'internal', 'METAUSD', 16, 1, NOW(), NOW()),
('NFLXUSD', 'Netflix / USD', 'Stocks', 710.00, 2, 84.00, 0.00350000, 88208, 'internal', 'NFLXUSD', 17, 1, NOW(), NOW()),
('AMDUSD', 'AMD / USD', 'Stocks', 162.00, 2, 84.00, 0.00380000, 88209, 'internal', 'AMDUSD', 18, 1, NOW(), NOW()),
('COINUSD', 'Coinbase / USD', 'Stocks', 225.00, 2, 84.00, 0.00420000, 88210, 'internal', 'COINUSD', 19, 1, NOW(), NOW()),
('BTCUSD', 'Bitcoin / USD', 'Crypto', 77000.00, 2, 90.00, 0.00400000, 88301, 'binance', 'BTCUSDT', 20, 1, NOW(), NOW()),
('ETHUSD', 'Ethereum / USD', 'Crypto', 2500.00, 2, 88.00, 0.00450000, 88302, 'binance', 'ETHUSDT', 21, 1, NOW(), NOW()),
('SOLUSD', 'Solana / USD', 'Crypto', 98.00, 2, 86.00, 0.00400000, 88303, 'binance', 'SOLUSDT', 22, 1, NOW(), NOW()),
('BNBUSD', 'BNB / USD', 'Crypto', 720.00, 2, 86.00, 0.00400000, 88304, 'binance', 'BNBUSDT', 23, 1, NOW(), NOW()),
('XRPUSD', 'XRP / USD', 'Crypto', 1.25, 4, 86.00, 0.00400000, 88305, 'binance', 'XRPUSDT', 24, 1, NOW(), NOW()),
('DOGEUSD', 'Dogecoin / USD', 'Crypto', 0.08, 5, 86.00, 0.00400000, 88306, 'binance', 'DOGEUSDT', 25, 1, NOW(), NOW()),
('ADAUSD', 'Cardano / USD', 'Crypto', 0.75, 4, 85.00, 0.00400000, 88307, 'binance', 'ADAUSDT', 26, 1, NOW(), NOW()),
('AVAXUSD', 'Avalanche / USD', 'Crypto', 35.00, 2, 85.00, 0.00420000, 88308, 'binance', 'AVAXUSDT', 27, 1, NOW(), NOW()),
('DOTUSD', 'Polkadot / USD', 'Crypto', 7.20, 3, 85.00, 0.00400000, 88309, 'binance', 'DOTUSDT', 28, 1, NOW(), NOW()),
('LINKUSD', 'Chainlink / USD', 'Crypto', 18.50, 2, 85.00, 0.00400000, 88310, 'binance', 'LINKUSDT', 29, 1, NOW(), NOW()),
('POLUSD', 'Polygon (POL) / USD', 'Crypto', 0.55, 4, 85.00, 0.00400000, 88311, 'binance', 'POLUSDT', 30, 1, NOW(), NOW()),
('LTCUSD', 'Litecoin / USD', 'Crypto', 88.00, 2, 85.00, 0.00380000, 88312, 'binance', 'LTCUSDT', 31, 1, NOW(), NOW()),
('NEARUSD', 'NEAR Protocol / USD', 'Crypto', 5.40, 3, 84.00, 0.00450000, 88313, 'binance', 'NEARUSDT', 32, 1, NOW(), NOW()),
('SHIBUSD', 'Shiba Inu / USD', 'Crypto', 0.000024, 8, 84.00, 0.00500000, 88314, 'binance', 'SHIBUSDT', 33, 1, NOW(), NOW()),
('PEPEUSD', 'Pepe / USD', 'Crypto', 0.000010, 8, 84.00, 0.00550000, 88315, 'binance', 'PEPEUSDT', 34, 1, NOW(), NOW()),
('TRXUSD', 'TRON / USD', 'Crypto', 0.18, 4, 84.00, 0.00350000, 88316, 'binance', 'TRXUSDT', 35, 1, NOW(), NOW()),
('SUIUSD', 'Sui / USD', 'Crypto', 3.20, 3, 84.00, 0.00480000, 88317, 'binance', 'SUIUSDT', 36, 1, NOW(), NOW()),
('APTUSD', 'Aptos / USD', 'Crypto', 11.50, 2, 84.00, 0.00450000, 88318, 'binance', 'APTUSDT', 37, 1, NOW(), NOW()),
('EURUSD', 'EUR/USD', 'Currencies', 1.0850, 5, 88.00, 0.00018000, 88401, 'binance', 'EURUSD', 10, 1, NOW(), NOW()),
('GBPUSD', 'GBP/USD', 'Currencies', 1.3250, 5, 85.00, 0.00020000, 88402, 'binance', 'GBPUSD', 11, 1, NOW(), NOW()),
('USDJPY', 'USD/JPY', 'Currencies', 155.50, 3, 85.00, 0.00025000, 88403, 'binance', 'USDJPY', 12, 1, NOW(), NOW()),
('AUDUSD', 'AUD/USD', 'Currencies', 0.6550, 5, 82.00, 0.00018000, 88404, 'binance', 'AUDUSD', 13, 1, NOW(), NOW()),
('USDCAD', 'USD/CAD', 'Currencies', 1.3950, 5, 82.00, 0.00018000, 88405, 'binance', 'USDCAD', 14, 1, NOW(), NOW())

ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    category = VALUES(category),
    base_price = VALUES(base_price),
    decimal_places = VALUES(decimal_places),
    payout_percent = VALUES(payout_percent),
    volatility = VALUES(volatility),
    price_source = VALUES(price_source),
    api_symbol = VALUES(api_symbol),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active),
    updated_at = NOW();

-- -----------------------------------------------------------------------------
-- 26. SEED DATA: SITE SETTINGS (Binance Live Mode Enabled)
-- -----------------------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value, setting_type, group_name, is_public, updated_at) VALUES
('platform_name', 'Quotex', 'string', 'general', 1, NOW()),
('platform_tagline', 'Smart live market trading platform', 'string', 'general', 1, NOW()),
('platform_domain', 'https://qxquotax.online', 'string', 'general', 1, NOW()),
('support_email', 'support@qxquotax.online', 'string', 'general', 1, NOW()),
('logo_filename', '', 'string', 'general', 0, NOW()),
('maintenance_mode', '0', 'boolean', 'general', 1, NOW()),

-- Trading Settings & Live Binance Mode
('market_data_mode', 'binance', 'string', 'trading', 1, NOW()),
('market_api_url', '', 'string', 'trading', 0, NOW()),
('market_api_key', '', 'string', 'trading', 0, NOW()),
('market_api_price_path', 'price', 'string', 'trading', 0, NOW()),
('trading_enabled', '1', 'boolean', 'trading', 1, NOW()),
('demo_trading_enabled', '1', 'boolean', 'trading', 1, NOW()),
('practice_starting_balance', '1000', 'number', 'trading', 1, NOW()),
('minimum_trade_amount', '1', 'number', 'trading', 1, NOW()),
('maximum_trade_amount', '1000', 'number', 'trading', 1, NOW()),
('trade_durations', '[5,10,15,30,60,120,300]', 'json', 'trading', 1, NOW()),
('chart_history_candles', '800', 'number', 'trading', 1, NOW()),
('chart_tick_density', '5', 'number', 'trading', 1, NOW()),
('chart_quote_interval_ms', '1500', 'number', 'trading', 1, NOW()),

-- Wallet & Payment Settings
('wallet_currency', 'USD', 'string', 'wallet', 1, NOW()),
('demo_starting_balance', '0', 'number', 'wallet', 1, NOW()),
('deposits_enabled', '1', 'boolean', 'wallet', 1, NOW()),
('withdrawals_enabled', '1', 'boolean', 'wallet', 1, NOW()),
('payment_proof_max_mb', '5', 'number', 'wallet', 0, NOW()),
('payment_order_minutes', '15', 'number', 'wallet', 1, NOW()),
('minimum_demo_deposit', '10', 'number', 'wallet', 1, NOW()),
('minimum_demo_withdrawal', '20', 'number', 'wallet', 1, NOW()),
('currency_exchange_enabled', '1', 'boolean', 'wallet', 1, NOW()),
('currency_exchange_fee_percent', '3', 'number', 'wallet', 1, NOW()),
('currency_exchange_rates', '{"USD":1,"INR":83,"EUR":0.92,"GBP":0.72}', 'json', 'wallet', 0, NOW()),

-- Affiliate / Referral Program
('referral_program_enabled', '1', 'boolean', 'affiliate', 1, NOW()),
('referral_reward_amount', '5', 'number', 'affiliate', 1, NOW()),
('referral_minimum_deposit', '10', 'number', 'affiliate', 1, NOW())

ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    setting_type = VALUES(setting_type),
    group_name = VALUES(group_name),
    is_public = VALUES(is_public),
    updated_at = NOW();

-- -----------------------------------------------------------------------------
-- 27. SEED DATA: PAYMENT METHODS
-- -----------------------------------------------------------------------------
INSERT INTO payment_methods (code, name, category, icon_key, account_name, account_identifier, network, instructions, qr_filename, minimum_amount, maximum_amount, deposit_enabled, withdrawal_enabled, require_reference, require_proof, is_active, sort_order, created_at, updated_at) VALUES
('upi', 'UPI', 'upi', 'upi', 'TradeZen Payments', 'payments@upi', 'UPI', 'Pay to the displayed UPI ID or QR code, then submit your transaction UTR reference.', 'upi-default.png', 10, 100000, 1, 1, 1, 1, 1, 10, NOW(), NOW()),
('phonepe', 'PhonePe', 'wallet', 'phonepe', 'TradeZen Payments', 'payments@upi', 'UPI / PhonePe', 'Pay through PhonePe to the displayed UPI ID or QR, then submit the successful UTR.', 'upi-default.png', 10, 100000, 1, 1, 1, 1, 1, 20, NOW(), NOW()),
('paytm', 'Paytm', 'wallet', 'paytm', NULL, NULL, NULL, 'Complete the payment and submit the transaction reference for review.', NULL, 10, 100000, 1, 1, 1, 1, 1, 30, NOW(), NOW()),
('binance-pay', 'Binance Pay', 'crypto', 'binance', 'TradeZen Official', '234543334', 'Binance Pay', 'Send through Binance Pay to the displayed Pay ID, then submit your Binance order ID.', 'binance-pay-default.png', 10, 100000, 1, 1, 1, 0, 1, 40, NOW(), NOW()),
('usdt-trc20', 'USDT (TRC-20)', 'crypto', 'usdt', NULL, NULL, 'TRC-20', 'Send USDT only on the TRC-20 network and submit the transaction hash.', NULL, 10, 100000, 1, 1, 1, 0, 1, 50, NOW(), NOW()),
('usdt-bep20', 'USDT (BEP-20)', 'crypto', 'usdt', NULL, NULL, 'BEP-20', 'Send USDT only on the BEP-20 network and submit the transaction hash.', NULL, 10, 100000, 1, 1, 1, 0, 1, 60, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    category = VALUES(category),
    icon_key = VALUES(icon_key),
    network = COALESCE(NULLIF(network,''), VALUES(network)),
    account_name = COALESCE(NULLIF(account_name,''), VALUES(account_name)),
    account_identifier = COALESCE(NULLIF(account_identifier,''), VALUES(account_identifier)),
    instructions = VALUES(instructions),
    updated_at = NOW();

-- -----------------------------------------------------------------------------
-- 28. RECORD MIGRATION VERSIONS (Prevents duplicate schema alterations)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES
('4.1.0', NOW()),
('4.1.2', NOW()),
('4.2.0', NOW()),
('4.3.0', NOW()),
('4.3.1', NOW()),
('4.4.0', NOW()),
('4.4.1', NOW()),
('4.4.5', NOW()),
('4.4.6', NOW()),
('4.5.0', NOW()),
('4.6.0', NOW()),
('4.7.0', NOW());

SET FOREIGN_KEY_CHECKS = 1;
