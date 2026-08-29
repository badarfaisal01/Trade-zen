SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS users (
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

CREATE TABLE IF NOT EXISTS market_assets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol VARCHAR(30) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'Forex',
    base_price DECIMAL(20,8) NOT NULL,
    decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 5,
    payout_percent DECIMAL(5,2) NOT NULL DEFAULT 82.00,
    volatility DECIMAL(10,8) NOT NULL DEFAULT 0.00250000,
    market_seed INT UNSIGNED NOT NULL,
    price_source ENUM('internal','manual','api') NOT NULL DEFAULT 'internal',
    api_symbol VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY market_assets_symbol_unique (symbol),
    KEY market_assets_active_sort_index (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
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

CREATE TABLE IF NOT EXISTS promo_codes (
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

CREATE TABLE IF NOT EXISTS trades (
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

CREATE TABLE IF NOT EXISTS fund_requests (
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

CREATE TABLE IF NOT EXISTS wallet_transactions (
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

CREATE TABLE IF NOT EXISTS currency_exchanges (
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

CREATE TABLE IF NOT EXISTS promo_redemptions (
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

CREATE TABLE IF NOT EXISTS site_settings (
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

CREATE TABLE IF NOT EXISTS password_reset_tokens (
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

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY login_attempts_email_created_index (email_hash, created_at),
    KEY login_attempts_ip_created_index (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
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

CREATE TABLE IF NOT EXISTS announcements (
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

CREATE TABLE IF NOT EXISTS support_tickets (
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

CREATE TABLE IF NOT EXISTS agents (
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

CREATE TABLE IF NOT EXISTS agent_referrals (
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

CREATE TABLE IF NOT EXISTS user_notifications (
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

CREATE TABLE IF NOT EXISTS market_price_cache (
    asset_id INT UNSIGNED NOT NULL,
    price DECIMAL(20,8) NOT NULL,
    source VARCHAR(30) NOT NULL,
    fetched_at DATETIME NOT NULL,
    raw_response_hash CHAR(64) NULL,
    PRIMARY KEY (asset_id),
    CONSTRAINT market_price_cache_asset_fk FOREIGN KEY (asset_id) REFERENCES market_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(40) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
